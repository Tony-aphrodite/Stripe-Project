# Truora API Usage Guide (Voltika Project — Verified)

> This document contains **only content that has been actually verified** from the following sources:
> - Official documentation local HTML (`api-integration/Truora - API reference...html`, `Authentication Guide.html`, `Getting started with Digital Identity...html`, `Main Concepts...html`, `Create First Flow...html`, `Create Account ID...html`, `Create a Process Link...html`, `Web Integration Token Guide.html`)
> - **In-project implementation code** (`configurador_prueba/php/truora-diag.php`, `truora-webhook.php`, `config.php`)
> - Official web docs (`dev.truora.com/guides/declined_reasons_details/`, `/guides/webhook_rule/`)

---

## 1. Product Suite (API reference line 178~194)

- **Background Checks** (credit / criminal / corporate background)
- **Digital Identity — Web/Mobile** (identity verification flow)
- **Digital Identity — Validators API** (per-unit validation API)
- **Customer Engagement** (WhatsApp-based)
- **Electronic Signature**

---

## 2. Authentication (Authentication Guide, full)

### Sign-up & API Key creation
1. Sign up: `https://account.truora.com/#/auth/login`
2. Dashboard: `https://app.truora.com/` → sidebar **API keys** → **Create**
3. Enter API Key name + select Version (**V1 recommended**)
4. **JWT token is shown only once** → save immediately

### Request header
```
Truora-API-Key: <JWT>
```
Same across all 4 services (Checks / Accounts / Identity / Validations).

### ⚠️ Voltika current setup (config.php line 74)
Payload of the currently hard-coded JWT (decoded):
```json
{
  "account_id":     "",
  "grant":          "",              // ← empty!
  "key_name":       "prueba",        // ← test key
  "key_type":       "backend",
  "client_id":      "TCI745917864057...",
  "exp":            3349278289       // valid until 2076
}
```

**Diagnostic code** (truora-diag.php line 34): treats the key as production only when `key_name === 'voltikalive'`. Currently `prueba` → test mode.

---

## 3. Four Independent Base URLs (API reference line 2717/50871/89940/169713)

| Section | Base URL |
|---------|----------|
| Checks API | `https://api.checks.truora.com` |
| Shared Accounts (API keys / BRE / Hooks) | `https://api.account.truora.com` |
| Digital Identity | `https://api.identity.truora.com` |
| Main Validator Suite | `https://api.validations.truora.com` |

**Common Content-Type**: `application/x-www-form-urlencoded` (not JSON)

---

## 4. Background Checks API (api.checks.truora.com)

### 4.1. Supported countries (API reference line 2795)
`CL`, `CO`, `MX`, `BR`, `CR`, `PE`, `ALL`

### 4.2. Types (API reference line 2829~2849)
`person | vehicle | company | custom_type_name`

### 4.3. MX person required fields (API reference line 2795 table)

| Case | Required fields |
|------|-----------------|
| National (default) | `national_id*` + `phone_number` |
| Alternative (when no national_id) | `first_name*` + `last_name*` + `state_id*` + `gender*` + `date_of_birth*` |

Always required:
- `type=person`
- `user_authorized=true` (API Key V1 or later)
- `country=MX`

### 4.4. Main endpoints (verified in API reference)

```
POST   /v1/checks                               (line 3833)
GET    /v1/checks                               (line 7342)
GET    /v1/checks/{check_id}                    (line 14630)
GET    /v1/checks/{check_id}/details            (line 21461)
GET    /v1/checks/{check_id}/attachments        (line 18366)
GET    /v1/checks/{check_id}/summarize          (line 21927)
DELETE /v1/checks/{check_id}                    (line 22629)
POST   /v1/checks/{check_id}/pdf                (line 47007)
GET    /v1/checks/{check_id}/pdf                (line 47390)
POST   /v1/webhooks/{webhook_type}              (line 7042)
POST   /v1/continuous-checks                    (line 30640)
GET    /v1/continuous-checks/{id}/history       (line 46503)
POST   /v1/batches                              (line 48048)
GET    /v1/health                               (line 22227)
```

### 4.5. Create Check response example (API reference line 6685~6702)
```json
{
  "check": {
    "check_id":      "CHK198e142cdd582a613bb96ff5748f500d",
    "country":       "CO",
    "creation_date": "2021-03-25T21:24:29Z",
    "score":         -1,
    "status":        "not_started",
    "type":          "person"
  },
  "details": "/v1/checks/CHK.../details",
  "self":    "/v1/checks/CHK..."
}
```

---

## 5. Digital Identity (api.identity.truora.com)

### 5.1. Core concepts (Main Concepts line 2337~2509)

| Term | Definition |
|------|------------|
| **Validator** | Data authentication engine (document / face / phone / email / government DB) |
| **Validation** | The act of running a Validator for a specific user |
| **Flow** | A **reusable template** arranging multiple Validators in order |
| **Process** | Per-user execution instance of a Flow |

### 5.2. Identifier scheme

| ID | Example format | Cardinality |
|----|----------------|-------------|
| `flow_id` | `IPFXXXXXXXXXX` | 1 → N process |
| `account_id` | Your user ID, or random | 1 → N validation |
| `process_id` | `IDPXXXXXXXXXXX` | Flow × Account instance |
| `validation_id` | `VLDXXXXXXXX` | Per validation unit |

### 5.3. Flow creation (Create First Flow line 2369~2508)

**Dashboard-only** (API-based creation not documented in this guide):
1. `app.truora.com` → **Digital Identity Verification** → Open
2. **Create New Flow** → enter name → Web channel → Continue
3. **Blank Template** → Continue
4. Add blocks by drag & drop in the Process tab:
   - **Data Authorization** block is **mandatory** (line 2452)
   - Document validation / Face validation / Phone / Email / Government-database, etc.
5. Configure Settings tab → publish → **flow_id is auto-generated**

### 5.4. Account ID creation (Create Account ID line 2365~2537)

Three approaches:

#### Approach A: Use your own user ID
- Pass your ID in the `account_id` parameter when creating a Web Token / Validation
- **Only regex `[a-zA-Z0-9_.-]+` is allowed**
- Must reuse the same value across all subsequent processes

#### Approach B: Auto-generate
- Omit `account_id` → random alphanumeric is assigned
- Store the assigned ID and reuse it in later requests

#### Approach C: Create Validation Account endpoint
```http
POST https://api.validations.truora.com/v1/accounts
Truora-API-Key: <api_key>
Content-Type: application/x-www-form-urlencoded

account_id=...                # Optional, [a-zA-Z0-9_.-]+
email=...
country=CO                    # ISO 3166 Alpha-2
document_number=...
document_type=national-id | foreign-id | identity-card | passport
document_issue_date=2000-05-24   # RFC3339
first_name=...
last_name=...
phone_number=...
facebook_user=...
twitter_user=...
```

### 5.5. Creating a Web Integration Token (Web Integration Token Guide line 346~383)

```http
POST https://api.account.truora.com/v1/api-keys
Truora-API-Key: <master key>
Content-Type: application/x-www-form-urlencoded

key_type=web
grant=digital-identity
api_key_version=1
country=ALL
redirect_url=https://your-site.com/callback
flow_id=IPFXXXXXXXXXX
account_id=<from 5.4>
phone=+570000000000                           # optional
emails=user@example.com                       # optional
start_variables.metadata.<key>=<value>        # optional, additional data
```

**Response** (Web Integration Token line 429):
```json
{
  "api_key": "eyJhbGc....",
  "message": "API key created successfully"
}
```

### 5.6. User verification URL (Web Integration Token line 460~473)

```
https://identity.truora.com/?token=<api_key>
```
- **Token default lifetime: 2 hours**
- The user performs identity verification via this URL.

### 5.7. Retrieving process results (Web Integration Token line 500~610)

```http
GET https://api.identity.truora.com/v1/processes/{process_id}/result
Truora-API-Key: <master key>
```

- `process_id` is automatically appended to the validation start URL.
- JWT contents can be decoded with `https://jwt.io/`.

**Process JWT payload** (line 549):
```json
{
  "account_id":      "ACCXXXXXXXXXX",
  "additional_data": "{\"country\":\"ALL\",\"flow_id\":\"IPFXXXXXXXXXXX\",\"redirect_url\":\"...\"}",
  "client_id":       "TCI...",
  "exp":             16642119XX,
  "grant":           "digital-identity",
  "iat":             16642047XX,
  "iss":             "iss_url",
  "jti":             "bd54322c-...",
  "key_name":        "xxx",
  "key_type":        "web",
  "username":        "xxx"
}
```

**Main Result response fields** (line 606):
```json
{
  "process_id":    "IDPXXXXXXXXXXX",
  "account_id":    "ACCXXXXXXXXXX",
  "flow_id":       "IPFXXXXXXXXXX",
  "created_via":   "web",
  "flow_version":  2,
  "country":       "CO",
  "status":        "success",            // pending | success | failure
  "validations": [
    {
      "validation_id":    "VLDXXXXXXXXX",
      "type":             "document-validation",
      "validation_status":"success",
      "details": {
        "background_check": {
          "check_id":  "CHKXXXXXXX",
          "check_url": "https://api.checks.truora.com/v1/checks/CHK..."
        },
        "document_details":      { "...": "..." },
        "document_validations":  { "data_consistency": [...], "government_database": [...] },
        "remaining_retries":     2,
        "front_image":           "front_image_url",
        "reverse_image":         "reverse_image_url"
      }
    },
    {
      "validation_id":    "VLDXXXXXXXX",
      "type":             "face-recognition",
      "threshold":        0.65,
      "details": {
        "face_recognition_validations": {
          "similarity_status": "success",
          "confidence_score":  0.9
        }
      }
    }
  ]
}
```

**Statuses**: `pending` / `success` / `failure` (internal error, timeout, validation rejected)

### 5.8. Digital Identity endpoint summary

```
POST   /v1/web-integration/{flow}/process-access-link  (line 90257)
POST   /v1/api-keys                                    (line 91437)
POST   /v1/processes                                   (line 135850)
POST   /v1/processes/{id}                              (line 137655)
GET    /v1/processes/{id}                              (line 144589)
PUT    /v1/processes/{id}                              (line 142293)
DELETE /v1/processes/{id}                              (line 152630)
POST   /v1/processes/{id}/send-link                    (line 134249)
POST   /v1/processes/{id}/back                         (line 139468)
GET    /v1/processes/{id}/result                       (line 147106)
GET    /v1/processes/{id}/pdf                          (line 146551)
GET    /v1/processes/{id}/video-call-recordings        (line 152082)
GET    /v1/processes/{id}/variables                    (line 151655)
GET    /v1/processes                                   (line 149548)
```

---

## 6. Validator Suite (api.validations.truora.com)

```
POST   /v1/accounts                                    (line 170075)
GET    /v1/accounts                                    (line 170854)
GET    /v1/accounts/{id}                               (line 171629)
GET    /v1/accounts/{id}/enrollments                   (line 172451)
GET    /v1/accounts/{id}/validations                   (line 173317)
GET    /v1/accounts/{id}/validations/{vid}             (line 175663, to be deprecated)
POST   /v1/enrollments                                 (line 178142)
GET    /v1/enrollments/{id}                            (line 179803)
DELETE /v1/enrollments/{id}                            (line 180505)
POST   /v1/validations                                 (line 181749)
POST   /v1/validations/{id}                            (line 182341)
POST   /v1/validations/{id}/restore                    (line 184090)
GET    /v1/validations                                 (line 190324)
GET    /v1/document-infographic                        (line 189760)
```

---

## 7. Webhook Handling

### 7.1. Creating a Webhook Rule in the Dashboard (official webhook_rule guide)
1. `app.truora.com` → sidebar **Webhooks/automations**
2. Click **New rule** → enter name / description
3. Select Product (e.g. Background Checks)
4. Sub-product (e.g. Check) + triggering event (e.g. Completed)
5. Optionally add conditions
6. **Webhook action**:
   - action name + email
   - specify endpoint URL (e.g. `https://voltika.mx/configurador/php/truora-webhook.php`)
   - Optionally add auth credentials (stored encrypted)
   - Use the "+" button to reference event variables
7. Save → Save actions → Finalize → confirm popup → activates immediately

### 7.2. Webhook delivery format
- **Per official docs**: Webhook is delivered as a **JWT token**; the receiver must verify the signature (source: dev.truora.com/guides/webhook_rule/)
- **Current project implementation in truora-webhook.php (line 64)**: HMAC-SHA256 (`hash_hmac('sha256', $rawBody, TRUORA_WEBHOOK_SECRET)`)
  - Candidate header names checked: `truora-signature`, `x-truora-signature`, `signature`
  - If `TRUORA_WEBHOOK_SECRET` is empty, signature verification is skipped

**⚠️ Mismatch note**: The official guide says "JWT as webhook auth," while the existing implementation uses HMAC-SHA256. Re-verify based on the method actually selected when creating the Webhook in the dashboard.

### 7.3. Webhook payload (handled in truora-webhook.php line 83~99)
```json
{
  "check_id":                "CHK...",
  "validation_id":           "VLD...",
  "document_validation_id":  "...",
  "face_recognition_id":     "...",
  "event_type":              "...",
  "type":                    "...",
  "event":                   "...",
  "status":                  "...",
  "data": {
    "check_id": "...",
    "status":   "..."
  }
}
```
Depending on the event type, the ID may appear under different keys, so fallbacks across all of them are required.

---

## 8. Declined Reasons (partial, excerpted from official guide)

Failure-response reason codes. Main categories out of 100+ total:

### Data inconsistency
`data_not_match_with_government_database`, `document_has_expired`, `invalid_curp`, `invalid_document_number`, `invalid_issue_date`, `invalid_mrz`, `invalid_postal_code`, `national_registrar_inconsistency`, `missing_accents`

### Potential fraud
`document_is_a_photo_of_photo`, `document_is_a_photocopy`, `document_unregistered`, `identity_belongs_to_dead_person`, `invalid_qr_content`, `missing_security_elements`, `image_face_validation_not_passed`, `image_text_validation_not_passed`, `portrait_photo_is_fake`, `possible_fraud`, `traces_of_tampering`, `face_in_blocklist`, `fraudster_face_match_in_client_collection`, `liveness_verification_not_passed`, `passive_liveness_verification_not_passed`, `photo_of_photo`, `similarity_threshold_not_passed`, `risky_face_detected`, `risk_signal_detected`

### Document is illegible
`blurry_image`, `damaged_document`, `invalid_image_format`, `invalid_or_corrupted_image_file`, `incomplete_document`, `inconsistent_parent_names`, `perforations_illegible`, `portrait_photo_illegible`, `file_format_not_supported`, `invalid_file_format`, `invalid_video_file`

### Missing information
`empty_input_file`, `face_not_detected`, `front_document_not_found`, `reverse_document_not_found`, `missing_*` (date_of_birth, document_number, expiration_date, gender, mrz, names, nationality, postal_code, text, etc.)

### Customer acceptance rules
`age_above_threshold` (>100 years old), `underage` (minor)

### Email/Phone validation
`email_not_valid_verdict`, `wrong_verification_code`, `phone_number_out_of_coverage`

### Potential withdrawal (user drop-off)
`abandoned_without_using_retries`, `canceled`, `geolocation_denied`, `vpn_detected`, `no_document_media_uploaded`, `no_face_media_uploaded`, `not_answered_question`, `process_started_late`, `validation_not_finished`, `validation_expired`, `unwanted_camera_permissions`, `user_process_postponed`, `document_validation_not_started`, `face_validation_not_started`, `email_validation_not_started`, `phone_validation_not_started`, `data_authorization_not_provided`, `electronic_signature_validation_not_started`

(Full list: `https://dev.truora.com/guides/declined_reasons_details/`)

---

## 9. Project DB Schemas

### Log table in truora-diag.php line 336~339
```sql
CREATE TABLE truora_query_log (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  action   VARCHAR(...),
  nombre   VARCHAR(...),
  apellidos VARCHAR(...),
  email    VARCHAR(...),
  http_code INT,
  response TEXT,
  curl_err TEXT,
  freg     DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### verificaciones table in truora-webhook.php line 112~119
```sql
-- columns only (no DDL available):
verificaciones_identidad:
  id, truora_check_id, face_check_id, doc_check_id,
  webhook_payload, webhook_received_at, identity_status
```

### preaprobaciones table in truora-diag.php line 366~367
```sql
-- columns only:
preaprobaciones:
  id, nombre, apellido_paterno, email, status,
  truora_ok, synth_score, freg
```

---

## 10. Current Voltika Integration Status Summary

| Component | Status |
|-----------|--------|
| Truora API Key | ✅ Hard-coded in config.php (key_name=prueba, backend type) |
| Background Check call | ⚠️ Experimental in truora-diag.php; both `api.truora.com` and `api.checks.truora.com` tried. Correct URL is `api.checks.truora.com/v1/checks` |
| Digital Identity Flow | ❌ flow_id not configured (must be created in dashboard) |
| Web Integration Token creation | ❌ Not implemented |
| Webhook receiver | ✅ truora-webhook.php exists (HMAC-SHA256, secret in .env) |
| DB log | ✅ truora_query_log table |

### Type values currently tried in truora-diag.php (line 137~167)
Several incorrect types being tried:
- ❌ `identity`, `background`, `identity_questions`, `identity-validation`, `document`, `document-validation`
- ✅ `person` (official)
- `face-recognition` — tried as a candidate type for `/v1/checks`

**Likely root causes**:
1. The API Key's `grant` is an empty string → Digital Identity features may be restricted
2. URL being called is `api.truora.com` — actual Background Checks host is `api.checks.truora.com`
3. Required field combination for an MX person isn't satisfied without national_id (state_id/gender required)

---

## 11. Official Resource URLs

| Resource | URL |
|----------|-----|
| Dashboard | `https://app.truora.com/` |
| Developer sign-up | `https://account.truora.com/#/auth/login` |
| API Reference | `https://dev.truora.com/docs/` |
| Status page | `https://status.truora.com/` |
| Postman workspace | `https://www.postman.com/truora-api-docs/workspace/truora-api-docs` |
| Authentication guide | `https://dev.truora.com/guides/authentication/` |
| Web Integration Token guide | `https://dev.truora.com/guides/web_integration_token/` |
| Webhook Rule guide | `https://dev.truora.com/guides/webhook_rule/` |
| Declined Reasons | `https://dev.truora.com/guides/declined_reasons_details/` |
