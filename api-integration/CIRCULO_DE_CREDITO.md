# Círculo de Crédito API 사용 방법 (Voltika 프로젝트 검증본)

> 본 문서는 다음 출처에서 **실제 확인된 내용만** 포함합니다:
> - 공식 문서 로컬 HTML (`api-integration/Integration guide _ apihub.html`, `Prueba de seguridad _ apihub.html`, `Voltika aplicación _ apihub.html`)
> - **프로젝트 내 동작하는 PHP 구현 코드** (`configurador_prueba/php/consultar-buro.php`, `cdc-security-test.php`, `generar-certificado-cdc.php`, `config.php`)

---

## 1. 개요

Círculo de Crédito(CDC)는 멕시코 신용정보기관. 개발자 포털은 **apihub** 플랫폼(`https://developer.circulodecredito.com.mx`, Apigee Edge 기반). 모든 프로덕션 API는 **전자서명 + Mutual TLS** 필수.

---

## 2. Voltika 앱 실제 설정

### 앱 기본 정보 (Voltika aplicación line 2~735)
| 항목 | 값 |
|------|-----|
| 앱 URL | `https://developer.circulodecredito.com.mx/user/4463/apps/voltika` |
| 사용자 ID | 4463 |
| Consumer Key (`x-api-key`) | config.php line 84 에 하드코딩됨 (`CDC_API_KEY`) |
| 발급 | ~7주 전 |
| 만료 | Nunca (영구) |

### CDC Folio (consultar-buro.php line 50)
```
0000080008 (Voltika)
```
- `CDC_FOLIO` 상수. 대시보드의 Folio Otorgante 10자리 ID.

### Common Name (CN) 인증서용 (generar-certificado-cdc.php line 25)
```
RMD004694MGE  (= CDC_USER)
```
- 인증서 CN을 CDC username과 일치시켜야 Apigee가 cert→API key 매핑 가능.

### 활성화된 API 제품 (Voltika aplicación line 378~730)
40+개 Sandbox 제품 + 일부 프로덕션 — 주요 항목:

**신용조회**: Reporte de Crédito MX Sandbox, Reporte de Crédito Consolidado MX Sandbox, Reporte de Crédito Consolidado con FICO Score v2 MX Sandbox, Reporte de Crédito Consolidado PM v2, FICO Score v2 MX Sandbox
**AML/신원**: PLD Personas Físicas/Morales MX Sandbox, Identity Data Sandbox, SAT-SANDBOX
**은행/핀테크**: Bank Account Verification Sandbox, Loan Amount Estimator MX Sandbox, Fintech Score Sandbox, VantAge v2 Sandbox
**보안**: SecurityTest

---

## 3. 인증 모델 (consultar-buro.php line 51~54, 206~213 에서 확인)

프로덕션 호출 시 HTTP 헤더:

| 헤더 | 값 | 출처 |
|------|-----|-----|
| `Content-Type` | `application/json` | 고정 |
| `Accept` | `application/json` | 고정 |
| `x-api-key` | Consumer Key | 포털 "Keys" 섹션 |
| `x-signature` | ECDSA-SHA256 서명 (HEX 인코딩) | 각 요청마다 계산 |
| `username` | CDC 발급 (HTTP Basic 아님, **커스텀 헤더**) | 별도 전달 |
| `password` | CDC 발급 (HTTP Basic 아님, **커스텀 헤더**) | 별도 전달 |

**추가로 Mutual TLS 필요** (consultar-buro.php line 231~239): 클라이언트 인증서(.pem) + 개인키(.key)를 cURL에 `CURLOPT_SSLCERT`, `CURLOPT_SSLKEY`로 첨부. 없으면 일부 CDC v2 제품이 503 응답.

---

## 4. 서명 알고리즘 (consultar-buro.php line 187~201 에서 확인)

```php
$priv = openssl_pkey_get_private($keyPem);
$sig  = '';
openssl_sign($jsonBody, $sig, $priv, OPENSSL_ALGO_SHA256);
$signatureHex = bin2hex($sig);   // ← x-signature 헤더에 넣는 값
```

- **곡선**: `secp384r1` (ECDSA)
- **해시**: SHA-256
- **인코딩**: **HEX** (base64 아님)
- **서명 대상**: `json_encode($requestBody, JSON_UNESCAPED_UNICODE)` 의 원문 바이트

### ⚠️ 중요: ASCII-only 정규화 (consultar-buro.php line 101~112)
CDC v2는 JSON 바디에 **ñ, á, é 등 비ASCII 포함 시 503 signature mismatch 또는 400 validation** 발생. 모든 텍스트 필드는 대문자 + 악센트 제거 후 서명해야 함:

```php
function cdcAscii(string $s): string {
    $s = strtoupper($s);
    $map = ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N', ...];
    $s = strtr($s, $map);
    return preg_replace('/[^\x20-\x7E]/', '', $s);
}
```

---

## 5. 인증서 생성 (generar-certificado-cdc.php 에서 확인)

### OpenSSL 기반 (Integration guide line 650)
```bash
openssl ecparam -name secp384r1 -genkey -out pri_key.pem
openssl req -new -x509 -days 365 -key pri_key.pem -out certificate.pem \
  -subj "/C=MX/ST=CDMX/L=CDMX/O=Voltika MX/OU=Tecnologia/CN=RMD004694MGE/emailAddress=ivan.clavel@voltika.mx"
```

### PHP 기반 (generar-certificado-cdc.php line 72~116)
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

### 인증서 DN (generar-certificado-cdc.php line 28~36)
```
C  = MX
ST = Ciudad de Mexico
L  = CDMX
O  = Voltika MX
OU = Tecnologia
CN = RMD004694MGE                 ← CDC_USER 와 일치해야 함
E  = ivan.clavel@voltika.mx
```

### ⚠️ 주의: CA 플래그
`basicConstraints = critical, CA:FALSE` 필수. 기본 PHP `openssl.cnf`는 `v3_ca` 사용 → Apigee 서명 검증 정책이 reject.

### 인증서 저장 위치 (consultar-buro.php line 155~172)
우선순위대로 조회:
1. `$_SESSION['cdc_key_pem']` / `$_SESSION['cdc_cert_pem']`
2. DB 테이블 `cdc_certificates`:
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
3. 디스크: `configurador_prueba/php/certs/cdc_private.key`, `cdc_certificate.pem`

---

## 6. 엔드포인트별 상세

### 6.1. SecurityTest (통신 테스트용)

**Endpoint** (cdc-security-test.php line 90):
```
POST https://services.circulodecredito.com.mx/v1/securitytest
```

**Body**:
```json
{"Peticion": "Esto es un mensaje de prueba"}
```

**서명 인코딩**: **Base64** (다른 프로덕션은 HEX이나 SecurityTest는 base64) — cdc-security-test.php line 80:
```php
$signatureB64 = base64_encode($signature);
$headers[] = 'x-signature: ' . $signatureB64;
```

**응답**: JSON, 서명 필드명은 `x-signature` 또는 `signature`, 메시지 필드명은 `Peticion` / `mensaje` / `message` (cdc-security-test.php line 176).

### 6.2. Reporte de Crédito Consolidado con FICO Score v2 (프로덕션 신용조회)

**Endpoint** (consultar-buro.php line 48):
```
POST https://services.circulodecredito.com.mx/v2/rccficoscore
```

**Body 스키마** (consultar-buro.php line 131~148) — FLAT 구조:
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

**주의사항**:
- 모든 필드 대문자 + ASCII only.
- `nacionalidad`: **"MX" 필수**.
- `domicilio`는 **객체로 중첩**. Sandbox의 `persona` wrapper 구조와 다름.
- RFC 10자리만 있을 시 `XXX` 추가해 13자리로 패딩 (line 122).

**Estado 정규화 enum** (consultar-buro.php line 525~527):
```
CDMX, AGS, BC, BCS, CAMP, CHIS, CHIH, COAH, COL, DGO, GTO, GRO, HGO,
JAL, MEX, MICH, MOR, NAY, NL, OAX, PUE, QRO, QROO, SLP, SIN, SON,
TAB, TAMS, TLAX, VER, YUC, ZAC
```
(`DF`, `Ciudad de Mexico` → `CDMX` 로 변환)

### 응답 스키마 (consultar-buro.php line 556~623 의 parser 기준)

```json
{
  "folioConsulta": "CDC...",
  "scores": [
    { "valor": 720, "nombre": "FICO_SCORE_V2" }
  ],
  "cuentas": [
    {
      "fechaCierreCuenta": "",              // 비어있으면 open account
      "montoPagar":        1500.00,          // 월 납입금
      "peorAtraso":        30,               // 최악 연체일
      "historicoPagos":    "1111121111...",  // 24개월 문자열
                                             //   1=al corriente, 2=30DPD, 3=60DPD,
                                             //   4=90DPD, 5=120DPD, U/R/Y=severe
      "DPD": { "dpd90": 0 }
    }
  ],
  "errores": [  // 실패 시
    { "codigo": "404.1", "mensaje": "No se encontró a la persona" }
  ]
}
```

**특수 케이스** (consultar-buro.php line 291~311):
- HTTP 404 + `errores[0].codigo = "404.1"` → **정상 응답** (신용 이력 없음, 첫 신청자). score=null로 처리.

---

## 7. 통합 9단계 (Integration guide line 250~943)

| # | 단계 | 소요 | 비고 |
|---|------|------|-----|
| 1 | 개발자 계정 생성 | 5분 | `developer.circulodecredito.com.mx` |
| 2 | 앱 등록 ("Add Application") | 5분 | Sandbox API 먼저 선택 |
| 3 | Sandbox 테스트 | 1일+ | Simulation 버튼 + Swagger/Postman |
| 4 | SecurityTest API 활성화 | 1일 | Postman 컬렉션 다운로드 |
| 5 | 키쌍 생성 (OpenSSL) | 15분 | ECDSA secp384r1 |
| 6 | 인증서 업로드 | 10분 | `.pem` 업로드 → CDC 인증서 다운로드 |
| 7 | 프로덕션 액세스 신청 | 20분 | `/pase_a_produccion` |
| 8 | 승인 대기 | 최대 3일 | 이메일 통지 |
| 9 | 클라이언트 라이브러리 사용 | 1일+ | 공식 GitHub: `APIHub-CdC/security-test-client-php` |

---

## 8. DB 로그 스키마 (consultar-buro.php line 267~276, 362~376)

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

## 9. RFC 자동 계산 (consultar-buro.php line 480~515)

NIP-CIEC 전에 사용자 입력 RFC가 없을 때 10자리 자동 생성:

```
문자 1: 아빠 성의 첫 글자
문자 2: 아빠 성에서 첫 글자 이후 첫 모음 (없으면 X)
문자 3: 엄마 성의 첫 글자 (없으면 X)
문자 4: 이름의 첫 글자 (JOSE/MARIA/MA/J 시작이면 두 번째 단어 사용)
숫자 6자: YYMMDD
```
→ 13자리 맞추려 `XXX` 패딩.

---

## 10. 공식 클라이언트 레포

- **PHP**: `https://github.com/APIHub-CdC/security-test-client-php`
  - 주요 파일:
    - `lib/Interceptor/KeyHandler.php` — PKCS12 키 관리
    - `lib/Interceptor/MiddlewareEvents.php` — Guzzle middleware로 서명 자동 추가/검증
    - `lib/Interceptor/key_pair_gen.sh` — 키쌍 생성 스크립트
    - `lib/Configuration.php` (line 19) — API host 설정
    - `lib/Api/PruebaDeSeguridadApi.php` — SecurityTest 호출 클래스
- **Java**: `https://github.com/APIHub-CdC/security-test-client-java`

---

## 11. 지원

- 이메일: `api@circulodecredito.com.mx` (Integration guide line 943)
- 포털: `https://developer.circulodecredito.com.mx`
- 추가 문서: `https://developer.circulodecredito.com.mx/prueba_de_seguridad`
