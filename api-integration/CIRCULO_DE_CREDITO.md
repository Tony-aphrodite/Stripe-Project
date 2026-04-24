# Círculo de Crédito API Usage Guide (Voltika Project — Verified)

> This document contains **only content that has been actually verified** from the following sources:
> - Official documentation local HTML (`api-integration/Integration guide _ apihub.html`, `Prueba de seguridad _ apihub.html`, `Voltika aplicación _ apihub.html`)
> - **Working in-project PHP implementation code** (`configurador_prueba/php/consultar-buro.php`, `cdc-security-test.php`, `generar-certificado-cdc.php`, `config.php`)

---

## 1. Overview

Círculo de Crédito (CDC) is a Mexican credit-information bureau. The developer portal is the **apihub** platform (`https://developer.circulodecredito.com.mx`, based on Apigee Edge). All production APIs require **digital signature + Mutual TLS**.

---

## 2. Voltika App Actual Configuration

### Basic app info (Voltika aplicación line 2~735)
| Item | Value |
|------|-------|
| App URL | `https://developer.circulodecredito.com.mx/user/4463/apps/voltika` |
| User ID | 4463 |
| Consumer Key (`x-api-key`) | Hard-coded in config.php line 84 (`CDC_API_KEY`) |
| Issued | ~7 weeks ago |
| Expires | Nunca (never) |

### CDC Folio (consultar-buro.php line 50)
```
0000080008 (Voltika)
```
- `CDC_FOLIO` constant. 10-digit Folio Otorgante ID from the dashboard.

### For Common Name (CN) of the certificate (generar-certificado-cdc.php line 25)
```
RMD004694MGE  (= CDC_USER)
```
- The certificate CN must match the CDC username, so Apigee can map cert → API key.

### Activated API products (Voltika aplicación line 378~730)
40+ Sandbox products + some production — main items:

**Credit queries**: Reporte de Crédito MX Sandbox, Reporte de Crédito Consolidado MX Sandbox, Reporte de Crédito Consolidado con FICO Score v2 MX Sandbox, Reporte de Crédito Consolidado PM v2, FICO Score v2 MX Sandbox
**AML / identity**: PLD Personas Físicas/Morales MX Sandbox, Identity Data Sandbox, SAT-SANDBOX
**Banking / fintech**: Bank Account Verification Sandbox, Loan Amount Estimator MX Sandbox, Fintech Score Sandbox, VantAge v2 Sandbox
**Security**: SecurityTest

---

## 3. Authentication Model (verified in consultar-buro.php line 51~54, 206~213)

HTTP headers on a production call:

| Header | Value | Source |
|--------|-------|--------|
| `Content-Type` | `application/json` | Fixed |
| `Accept` | `application/json` | Fixed |
| `x-api-key` | Consumer Key | Portal "Keys" section |
| `x-signature` | ECDSA-SHA256 signature (HEX encoded) | Computed per request |
| `username` | Issued by CDC (NOT HTTP Basic — **custom header**) | Delivered separately |
| `password` | Issued by CDC (NOT HTTP Basic — **custom header**) | Delivered separately |

**Plus Mutual TLS is required** (consultar-buro.php line 231~239): attach the client certificate (.pem) + private key (.key) to cURL via `CURLOPT_SSLCERT`, `CURLOPT_SSLKEY`. Some CDC v2 products return 503 if this is missing.

---

## 4. Signature Algorithm (verified in consultar-buro.php line 187~201)

```php
$priv = openssl_pkey_get_private($keyPem);
$sig  = '';
openssl_sign($jsonBody, $sig, $priv, OPENSSL_ALGO_SHA256);
$signatureHex = bin2hex($sig);   // ← value placed in the x-signature header
```

- **Curve**: `secp384r1` (ECDSA)
- **Hash**: SHA-256
- **Encoding**: **HEX** (not base64)
- **Signed payload**: the raw bytes of `json_encode($requestBody, JSON_UNESCAPED_UNICODE)`

### ⚠️ Important: ASCII-only normalization (consultar-buro.php line 101~112)
For CDC v2, including non-ASCII characters (**ñ, á, é**, etc.) in the JSON body causes **503 signature mismatch or 400 validation**. All text fields must be uppercased + accent-stripped before signing:

```php
function cdcAscii(string $s): string {
    $s = strtoupper($s);
    $map = ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N', ...];
    $s = strtr($s, $map);
    return preg_replace('/[^\x20-\x7E]/', '', $s);
}
```

---

## 5. Certificate Generation (verified in generar-certificado-cdc.php)

### OpenSSL-based (Integration guide line 650)
```bash
openssl ecparam -name secp384r1 -genkey -out pri_key.pem
openssl req -new -x509 -days 365 -key pri_key.pem -out certificate.pem \
  -subj "/C=MX/ST=CDMX/L=CDMX/O=Voltika MX/OU=Tecnologia/CN=RMD004694MGE/emailAddress=ivan.clavel@voltika.mx"
```

### PHP-based (generar-certificado-cdc.php line 72~116)
```php
// 1. Generate ECDSA key
$privateKey = openssl_pkey_new([
    'curve_name'       => 'secp384r1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
]);
openssl_pkey_export($privateKey, $keyPem);

// 2. X.509 extensions — MUST be end-entity (CA:FALSE), not CA
//    Default PHP openssl.cnf uses v3_ca which Apigee rejects for signing
$opensslCnf = '
[v3_req]
basicConstraints = critical, CA:FALSE
keyUsage         = critical, digitalSignature, nonRepudiation, keyEncipherment
extendedKeyUsage = clientAuth, serverAuth
';

// 3. Self-signed cert (CDC accepts self-signed since it's uploaded to portal)
$csr  = openssl_csr_new($dn, $privateKey, $configArgs);
$cert = openssl_csr_sign($csr, null, $privateKey, 365, $configArgs);
openssl_x509_export($cert, $certPem);
```

### Certificate DN (generar-certificado-cdc.php line 28~36)
```
C  = MX
ST = Ciudad de Mexico
L  = CDMX
O  = Voltika MX
OU = Tecnologia
CN = RMD004694MGE                 ← must match CDC_USER
E  = ivan.clavel@voltika.mx
```

### ⚠️ Note: CA flag
`basicConstraints = critical, CA:FALSE` is required. The default PHP `openssl.cnf` uses `v3_ca` → Apigee's signature-verification policy rejects it.

### Certificate storage locations (consultar-buro.php line 155~172)
Lookup order:
1. `$_SESSION['cdc_key_pem']` / `$_SESSION['cdc_cert_pem']`
2. DB table `cdc_certificates`:
   ```sql
   CREATE TABLE cdc_certificates (
     id           INT AUTO_INCREMENT PRIMARY KEY,
     private_key  TEXT NOT NULL,
     certificate  TEXT NOT NULL,
     fingerprint  VARCHAR(80),
     active       TINYINT(1) NOT NULL DEFAULT 1,
     freg         DATETIME DEFAULT CURRENT_TIMESTAMP
   )
   ```
3. Disk: `configurador_prueba/php/certs/cdc_private.key`, `cdc_certificate.pem`

---

## 6. Endpoint Details

### 6.1. SecurityTest (for connectivity testing)

**Endpoint** (cdc-security-test.php line 90):
```
POST https://services.circulodecredito.com.mx/v1/securitytest
```

**Body**:
```json
{"Peticion": "Esto es un mensaje de prueba"}
```

**Signature encoding**: **Base64** (other production endpoints are HEX, but SecurityTest is base64) — cdc-security-test.php line 80:
```php
$signatureB64 = base64_encode($signature);
$headers[] = 'x-signature: ' . $signatureB64;
```

**Response**: JSON. The signature field name is `x-signature` or `signature`; the message field name is `Peticion` / `mensaje` / `message` (cdc-security-test.php line 176).

### 6.2. Reporte de Crédito Consolidado con FICO Score v2 (production credit query)

**Endpoint** (consultar-buro.php line 48):
```
POST https://services.circulodecredito.com.mx/v2/rccficoscore
```

**Body schema** (consultar-buro.php line 131~148) — FLAT structure:
```json
{
  "primerNombre":    "JUAN",
  "apellidoPaterno": "GARCIA",
  "apellidoMaterno": "LOPEZ",
  "fechaNacimiento": "1985-03-15",
  "nacionalidad":    "MX",
  "domicilio": {
    "direccion":           "AV REFORMA 100",
    "coloniaPoblacion":    "JUAREZ",
    "delegacionMunicipio": "CUAUHTEMOC",
    "ciudad":              "CDMX",
    "estado":              "CDMX",
    "CP":                  "06600"
  },
  "RFC":  "GALJ850315XXX",
  "CURP": "GALJ850315HDFRRR07"
}
```

**Notes**:
- All fields uppercase + ASCII only.
- `nacionalidad`: **must be "MX"**.
- `domicilio` is a **nested object**. Different from the Sandbox `persona` wrapper structure.
- If only a 10-character RFC is available, pad to 13 characters by appending `XXX` (line 122).

**Estado normalization enum** (consultar-buro.php line 525~527):
```
CDMX, AGS, BC, BCS, CAMP, CHIS, CHIH, COAH, COL, DGO, GTO, GRO, HGO,
JAL, MEX, MICH, MOR, NAY, NL, OAX, PUE, QRO, QROO, SLP, SIN, SON,
TAB, TAMS, TLAX, VER, YUC, ZAC
```
(`DF`, `Ciudad de Mexico` → mapped to `CDMX`)

### Response schema (based on the parser at consultar-buro.php line 556~623)

```json
{
  "folioConsulta": "CDC...",
  "scores": [
    { "valor": 720, "nombre": "FICO_SCORE_V2" }
  ],
  "cuentas": [
    {
      "fechaCierreCuenta": "",              // empty => account still open
      "montoPagar":        1500.00,          // monthly payment
      "peorAtraso":        30,               // worst days past due
      "historicoPagos":    "1111121111...",  // 24-month string
                                             //   1=al corriente, 2=30DPD, 3=60DPD,
                                             //   4=90DPD, 5=120DPD, U/R/Y=severe
      "DPD": { "dpd90": 0 }
    }
  ],
  "errores": [  // on failure
    { "codigo": "404.1", "mensaje": "No se encontró a la persona" }
  ]
}
```

**Special case** (consultar-buro.php line 291~311):
- HTTP 404 + `errores[0].codigo = "404.1"` → **normal response** (no credit history, first-time applicant). Treated as score=null.

---

## 7. Integration in 9 Steps (Integration guide line 250~943)

| # | Step | Time | Notes |
|---|------|------|-------|
| 1 | Create developer account | 5 min | `developer.circulodecredito.com.mx` |
| 2 | Register app ("Add Application") | 5 min | Choose Sandbox APIs first |
| 3 | Sandbox testing | 1 day+ | Simulation button + Swagger/Postman |
| 4 | Enable SecurityTest API | 1 day | Download Postman collection |
| 5 | Generate keypair (OpenSSL) | 15 min | ECDSA secp384r1 |
| 6 | Upload certificate | 10 min | Upload `.pem` → download CDC certificate |
| 7 | Request production access | 20 min | `/pase_a_produccion` |
| 8 | Wait for approval | up to 3 days | Email notification |
| 9 | Use client library | 1 day+ | Official GitHub: `APIHub-CdC/security-test-client-php` |

---

## 8. DB Log Schema (consultar-buro.php line 267~276, 362~376)

```sql
CREATE TABLE cdc_query_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  endpoint   VARCHAR(255),
  http_code  INT,
  has_sig    TINYINT(1),
  body_sent  MEDIUMTEXT,
  response   MEDIUMTEXT,
  curl_err   VARCHAR(500),
  freg       DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE consultas_buro (
  id                        INT AUTO_INCREMENT PRIMARY KEY,
  nombre                    VARCHAR(200),
  apellido_paterno          VARCHAR(100),
  apellido_materno          VARCHAR(100),
  fecha_nacimiento          VARCHAR(20),
  cp                        VARCHAR(10),
  score                     INT,
  pago_mensual              DECIMAL(12,2),
  dpd90_flag                TINYINT(1),
  dpd_max                   INT,
  num_cuentas               INT,
  folio_consulta            VARCHAR(100),
  rfc                       VARCHAR(20),
  curp                      VARCHAR(20),
  calle_numero              VARCHAR(200),
  colonia                   VARCHAR(150),
  municipio                 VARCHAR(150),
  ciudad                    VARCHAR(100),
  estado                    VARCHAR(10),
  tipo_consulta             VARCHAR(5)  DEFAULT 'PF',
  fecha_aprobacion_consulta DATE,
  hora_aprobacion_consulta  TIME,
  fecha_consulta            DATE,
  hora_consulta             TIME,
  usuario_api               VARCHAR(100),      -- CDC_FOLIO
  ingreso_nip_ciec          VARCHAR(5)  DEFAULT 'SI',
  respuesta_leyenda         VARCHAR(5)  DEFAULT 'SI',
  aceptacion_tyc            VARCHAR(5)  DEFAULT 'SI',
  freg                      DATETIME    DEFAULT CURRENT_TIMESTAMP
);
```

---

## 9. RFC Auto-Computation (consultar-buro.php line 480~515)

When the user hasn't provided an RFC before NIP-CIEC, a 10-character RFC is auto-generated:

```
Char 1:  First letter of the father's surname
Char 2:  First vowel of the father's surname after the first letter (X if none)
Char 3:  First letter of the mother's surname (X if none)
Char 4:  First letter of the given name (if it starts with JOSE/MARIA/MA/J, use the second word)
Digits 6: YYMMDD
```
→ Padded with `XXX` to reach 13 characters.

---

## 10. Official Client Repositories

- **PHP**: `https://github.com/APIHub-CdC/security-test-client-php`
  - Key files:
    - `lib/Interceptor/KeyHandler.php` — PKCS12 key management
    - `lib/Interceptor/MiddlewareEvents.php` — Guzzle middleware that auto-signs/verifies
    - `lib/Interceptor/key_pair_gen.sh` — keypair generation script
    - `lib/Configuration.php` (line 19) — API host configuration
    - `lib/Api/PruebaDeSeguridadApi.php` — SecurityTest call class
- **Java**: `https://github.com/APIHub-CdC/security-test-client-java`

---

## 11. Support

- Email: `api@circulodecredito.com.mx` (Integration guide line 943)
- Portal: `https://developer.circulodecredito.com.mx`
- Additional docs: `https://developer.circulodecredito.com.mx/prueba_de_seguridad`
