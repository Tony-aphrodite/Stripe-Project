# Truora API 사용 방법 (Voltika 프로젝트 검증본)

> 본 문서는 다음 출처에서 **실제 확인된 내용만** 포함합니다:
> - 공식 문서 로컬 HTML (`api-integration/Truora - API reference...html`, `Authentication Guide.html`, `Getting started with Digital Identity...html`, `Main Concepts...html`, `Create First Flow...html`, `Create Account ID...html`, `Create a Process Link...html`, `Web Integration Token Guide.html`)
> - **프로젝트 내 구현 코드** (`configurador_prueba/php/truora-diag.php`, `truora-webhook.php`, `config.php`)
> - 공식 웹 문서 (`dev.truora.com/guides/declined_reasons_details/`, `/guides/webhook_rule/`)

---

## 1. 제품군 (API reference line 178~194)

- **Background Checks** (신용/범죄/법인 배경조사)
- **Digital Identity — Web/Mobile** (신원확인 플로우)
- **Digital Identity — Validators API** (검증 단위 API)
- **Customer Engagement** (WhatsApp 기반)
- **Electronic Signature**

---

## 2. 인증 (Authentication Guide 전체)

### 가입 & API Key 생성
1. 가입: `https://account.truora.com/#/auth/login`
2. 대시보드: `https://app.truora.com/` → 사이드바 **API keys** → **Create**
3. API Key 이름 입력 + Version 선택 (**V1 권장**)
4. **JWT 토큰 1회만 표시** → 즉시 저장

### 사용 헤더
```
Truora-API-Key: <JWT>
```
4개 서비스(Checks/Accounts/Identity/Validations) 모두 동일.

### ⚠️ Voltika 현재 설정 (config.php line 74)
현재 하드코딩된 JWT의 payload (디코드 결과):
```json
{
  "account_id":     "",
  "grant":          "",              // ← 비어있음!
  "key_name":       "prueba",        // ← 테스트 키
  "key_type":       "backend",
  "client_id":      "TCI745917864057...",
  "exp":            3349278289       // 2076년까지 유효
}
```

**진단 코드** (truora-diag.php line 34): `key_name === 'voltikalive'` 여야 프로덕션으로 간주. 현재 `prueba` → 테스트 모드.

---

## 3. 4개 독립 Base URL (API reference line 2717/50871/89940/169713)

| 섹션 | Base URL |
|------|----------|
| Checks API | `https://api.checks.truora.com` |
| Shared Accounts (API keys / BRE / Hooks) | `https://api.account.truora.com` |
| Digital Identity | `https://api.identity.truora.com` |
| Main Validator Suite | `https://api.validations.truora.com` |

**공통 Content-Type**: `application/x-www-form-urlencoded` (JSON 아님)

---

## 4. Background Checks API (api.checks.truora.com)

### 4.1. 지원 국가 (API reference line 2795)
`CL`, `CO`, `MX`, `BR`, `CR`, `PE`, `ALL`

### 4.2. 타입 (API reference line 2829~2849)
`person | vehicle | company | custom_type_name`

### 4.3. MX person 필수 필드 (API reference line 2795 표)

| 케이스 | 필수 필드 |
|--------|----------|
| National (기본) | `national_id*` + `phone_number` |
| Alternative (national_id 없을 시) | `first_name*` + `last_name*` + `state_id*` + `gender*` + `date_of_birth*` |

공통 필수:
- `type=person`
- `user_authorized=true` (API Key V1 이상)
- `country=MX`

### 4.4. 주요 엔드포인트 (API reference 검증)

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

### 4.5. Create Check 응답 예시 (API reference line 6685~6702)
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

### 5.1. 핵심 개념 (Main Concepts line 2337~2509)

| 용어 | 정의 |
|------|-----|
| **Validator** | 데이터 인증 엔진 (문서/얼굴/전화/이메일/정부DB) |
| **Validation** | 특정 사용자에 대해 Validator를 실행한 행위 |
| **Flow** | 여러 Validator를 순서대로 배치한 **재사용 템플릿** |
| **Process** | Flow의 특정 사용자별 실행 인스턴스 |

### 5.2. 식별자 체계

| ID | 형식 예 | 관계 |
|----|---------|------|
| `flow_id` | `IPFXXXXXXXXXX` | 1 → N process |
| `account_id` | 자사 user ID 또는 랜덤 | 1 → N validation |
| `process_id` | `IDPXXXXXXXXXXX` | Flow × Account 인스턴스 |
| `validation_id` | `VLDXXXXXXXX` | 각 검증 단위 |

### 5.3. Flow 생성 (Create First Flow line 2369~2508)

**대시보드 전용** (이 문서 범위에서는 API 생성법 미기재):
1. `app.truora.com` → **Digital Identity Verification** → Open
2. **Create New Flow** → 이름 입력 → Web 채널 → Continue
3. **Blank Template** → Continue
4. Process 탭에서 드래그&드롭으로 추가:
   - **Data Authorization** 블록 **필수** (line 2452)
   - Document validation / Face validation / Phone / Email / Government-database 등
5. Settings 탭 구성 → 발행 → **flow_id 자동 생성**

### 5.4. Account ID 생성 (Create Account ID line 2365~2537)

3가지 방식:

#### 방법 A: 자사 user ID 사용
- Web Token/Validation 생성 시 `account_id` 파라미터에 자사 ID 전달
- **정규식 `[a-zA-Z0-9_.-]+` 만 허용**
- 모든 후속 프로세스에서 동일 값 사용 필수

#### 방법 B: 자동 생성
- `account_id` 생략 → 랜덤 알파벳숫자로 자동 할당
- 할당된 ID를 저장해 이후 요청에 재사용

#### 방법 C: Create Validation Account 엔드포인트
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

### 5.5. Web Integration Token 생성 (Web Integration Token Guide line 346~383)

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
start_variables.metadata.<key>=<value>        # optional, 추가 데이터
```

**응답** (Web Integration Token line 429):
```json
{
  "api_key": "eyJhbGc....",
  "message": "API key created successfully"
}
```

### 5.6. 사용자 검증 URL (Web Integration Token line 460~473)

```
https://identity.truora.com/?token=<api_key>
```
- **Token 기본 유효시간: 2시간**
- 사용자는 이 URL을 통해 신원확인 프로세스 수행.

### 5.7. Process 결과 조회 (Web Integration Token line 500~610)

```http
GET https://api.identity.truora.com/v1/processes/{process_id}/result
Truora-API-Key: <master key>
```

- `process_id`는 validation 시작 URL 끝에 자동 포함.
- JWT 내용은 `https://jwt.io/` 로 디코드 가능.

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

**Result 응답 주요 필드** (line 606):
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

**상태**: `pending` / `success` / `failure` (내부오류, 타임아웃, 검증거부)

### 5.8. Digital Identity 엔드포인트 요약

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
GET    /v1/accounts/{id}/validations/{vid}             (line 175663, deprecated 예정)
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

## 7. Webhook 처리

### 7.1. Dashboard에서 Webhook Rule 생성 (webhook_rule 공식 가이드)
1. `app.truora.com` → 사이드바 **Webhooks/automations**
2. **New rule** 클릭 → 이름/설명 입력
3. Product 선택 (예: Background Checks)
4. Sub-product (예: Check) + triggering event (예: Completed)
5. 조건(conditions) 추가 선택
6. **Webhook action**:
   - action name + email
   - endpoint URL 지정 (예: `https://voltika.mx/configurador/php/truora-webhook.php`)
   - 필요 시 인증 credentials 추가 (암호화 저장됨)
   - "+" 버튼으로 event variable 참조 가능
7. Save → Save actions → Finalize → 팝업 확인 → 즉시 활성화

### 7.2. Webhook 수신 형식
- **공식 문서 기준**: Webhook은 **JWT 토큰**으로 전달되며 수신 측에서 서명 검증 필요 (출처: dev.truora.com/guides/webhook_rule/)
- **프로젝트 truora-webhook.php 현재 구현 (line 64)**: HMAC-SHA256 (`hash_hmac('sha256', $rawBody, TRUORA_WEBHOOK_SECRET)`)
  - 헤더명 후보 검사: `truora-signature`, `x-truora-signature`, `signature`
  - `TRUORA_WEBHOOK_SECRET`이 비어있으면 서명 검증 생략

**⚠️ 불일치 주의**: 공식 가이드는 "JWT as webhook auth"라 하고, 기존 구현은 HMAC-SHA256 사용. 실제 대시보드에서 Webhook 생성 시 선택한 방식에 따라 재확인 필요.

### 7.3. Webhook payload (truora-webhook.php line 83~99 에서 처리 중)
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
ID는 이벤트 종류에 따라 여러 키 중 하나에 있을 수 있어 모두 fallback 필요.

---

## 8. Declined Reasons (일부, 공식 가이드 발췌)

실패 응답의 이유 코드. 전체 100+ 코드 중 주요 카테고리:

### Data inconsistency
`data_not_match_with_government_database`, `document_has_expired`, `invalid_curp`, `invalid_document_number`, `invalid_issue_date`, `invalid_mrz`, `invalid_postal_code`, `national_registrar_inconsistency`, `missing_accents`

### Potential fraud
`document_is_a_photo_of_photo`, `document_is_a_photocopy`, `document_unregistered`, `identity_belongs_to_dead_person`, `invalid_qr_content`, `missing_security_elements`, `image_face_validation_not_passed`, `image_text_validation_not_passed`, `portrait_photo_is_fake`, `possible_fraud`, `traces_of_tampering`, `face_in_blocklist`, `fraudster_face_match_in_client_collection`, `liveness_verification_not_passed`, `passive_liveness_verification_not_passed`, `photo_of_photo`, `similarity_threshold_not_passed`, `risky_face_detected`, `risk_signal_detected`

### Document is illegible
`blurry_image`, `damaged_document`, `invalid_image_format`, `invalid_or_corrupted_image_file`, `incomplete_document`, `inconsistent_parent_names`, `perforations_illegible`, `portrait_photo_illegible`, `file_format_not_supported`, `invalid_file_format`, `invalid_video_file`

### Missing information
`empty_input_file`, `face_not_detected`, `front_document_not_found`, `reverse_document_not_found`, `missing_*` (date_of_birth, document_number, expiration_date, gender, mrz, names, nationality, postal_code, text 등)

### Customer acceptance rules
`age_above_threshold` (>100세), `underage` (미성년)

### Email/Phone validation
`email_not_valid_verdict`, `wrong_verification_code`, `phone_number_out_of_coverage`

### Potential withdrawal (사용자 이탈)
`abandoned_without_using_retries`, `canceled`, `geolocation_denied`, `vpn_detected`, `no_document_media_uploaded`, `no_face_media_uploaded`, `not_answered_question`, `process_started_late`, `validation_not_finished`, `validation_expired`, `unwanted_camera_permissions`, `user_process_postponed`, `document_validation_not_started`, `face_validation_not_started`, `email_validation_not_started`, `phone_validation_not_started`, `data_authorization_not_provided`, `electronic_signature_validation_not_started`

(전체 리스트: `https://dev.truora.com/guides/declined_reasons_details/`)

---

## 9. 프로젝트 DB 스키마

### truora-diag.php line 336~339 의 로그 테이블
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

### truora-webhook.php line 112~119 의 verificaciones 테이블
```sql
-- 컬럼만 확인됨 (DDL 없음):
verificaciones_identidad:
  id, truora_check_id, face_check_id, doc_check_id,
  webhook_payload, webhook_received_at, identity_status
```

### truora-diag.php line 366~367 의 preaprobaciones 테이블
```sql
-- 컬럼만 확인됨:
preaprobaciones:
  id, nombre, apellido_paterno, email, status,
  truora_ok, synth_score, freg
```

---

## 10. 현재 Voltika 통합 상태 요약

| 구성요소 | 상태 |
|---------|------|
| Truora API Key | ✅ config.php 에 하드코딩 (key_name=prueba, backend 타입) |
| Background Check 호출 | ⚠️ truora-diag.php 에서 실험 중, `api.truora.com` 과 `api.checks.truora.com` 모두 시도. 올바른 URL은 `api.checks.truora.com/v1/checks` |
| Digital Identity Flow | ❌ flow_id 미확인 (대시보드 생성 필요) |
| Web Integration Token 생성 | ❌ 미구현 |
| Webhook 수신 | ✅ truora-webhook.php 있음 (HMAC-SHA256, secret은 .env) |
| DB 로그 | ✅ truora_query_log 테이블 |

### truora-diag.php 에서 시도 중인 type 값 (line 137~167)
현재 맞지 않는 type 여러 개 시도 중:
- ❌ `identity`, `background`, `identity_questions`, `identity-validation`, `document`, `document-validation`
- ✅ `person` (공식)
- `face-recognition` — `/v1/checks` 의 타입 후보로 시도 중

**주요 원인 후보**:
1. API Key의 `grant`가 빈 문자열 → Digital Identity 기능 제한 가능
2. URL을 `api.truora.com` 으로 호출 — 실제 Background Checks는 `api.checks.truora.com`
3. MX 개인의 필수 필드 조합이 national_id 없이 충족되지 않음 (state_id/gender 필요)

---

## 11. 공식 리소스 URL

| 자료 | URL |
|------|-----|
| 대시보드 | `https://app.truora.com/` |
| 개발자 가입 | `https://account.truora.com/#/auth/login` |
| API Reference | `https://dev.truora.com/docs/` |
| 상태 페이지 | `https://status.truora.com/` |
| Postman 워크스페이스 | `https://www.postman.com/truora-api-docs/workspace/truora-api-docs` |
| Authentication 가이드 | `https://dev.truora.com/guides/authentication/` |
| Web Integration Token 가이드 | `https://dev.truora.com/guides/web_integration_token/` |
| Webhook Rule 가이드 | `https://dev.truora.com/guides/webhook_rule/` |
| Declined Reasons | `https://dev.truora.com/guides/declined_reasons_details/` |
