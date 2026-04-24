# 🔧 Voltika Transacciones Recovery Guide

**생성일**: 2026-04-24
**복구 대상**: `transacciones` 테이블의 23건 (2025-08-30 ~ 2026-04-06)
**보존 대상**: 현재 DB의 9건 (2026-04-21 ~ 2026-04-22)

---

## 📦 제공되는 파일

| 파일 | 용도 | 위치 |
|------|------|------|
| `backup_2026-04-06.sql` | 원본 백업 (수정 금지) | 프로젝트 루트 |
| `recovery_transacciones_2026-04-24.sql` | 변환된 복구 SQL | 프로젝트 루트 |
| `configurador_prueba/php/db-recovery-transacciones.php` | 서버 실행 PHP 스크립트 | PHP 폴더 |
| `generate-recovery-sql.py` | SQL 재생성 스크립트 (필요 시) | 프로젝트 루트 |
| `RECOVERY_GUIDE.md` | 이 문서 | 프로젝트 루트 |

---

## 🛡️ 안전 장치 (이미 적용됨)

1. ✅ **원본 백업 불변**: `backup_2026-04-06.sql` 절대 수정 안 됨
2. ✅ **안전 복사본**: `c:/tmp/voltika-recovery-2026-04-24/backup_2026-04-06.sql.safe-copy`
3. ✅ **트랜잭션 처리**: 오류 발생 시 자동 ROLLBACK
4. ✅ **INSERT IGNORE**: 중복 발생 시 실패 없이 스킵
5. ✅ **ID 충돌 방지**: `id` 컬럼 제외 → auto-increment로 자동 번호 부여
6. ✅ **스키마 호환**: 새 컬럼 4개(`referido_id`, `referido_tipo`, `caso`, `folio_contrato`)는 NULL로 채움

---

## 🚀 실행 방법 — 3가지 중 선택

### 방법 ⭐ A: PHP 스크립트 (권장 · 가장 쉬움)

**장점**: 2단계 확인(DRY RUN → APPLY), 시각적 결과, 자동 검증

#### 1단계: 파일 업로드

서버의 다음 위치에 파일 2개를 업로드:

```
[서버 루트]/configurador_prueba/php/db-recovery-transacciones.php
[서버 루트]/recovery_transacciones_2026-04-24.sql
```

#### 2단계: DRY RUN (안전한 미리보기 — 실제 변경 없음)

브라우저에서 다음 URL 접속:
```
https://[도메인]/configurador_prueba/php/db-recovery-transacciones.php?key=voltika-recovery-2026&dry=1
```

화면에 다음 항목을 확인:
- ✅ 현재 레코드 수가 9건으로 표시됨
- ✅ "A restaurar: 23"
- ✅ 현재 9개 레코드가 표에 보임
- ✅ 실행될 INSERT 구문 미리보기

#### 3단계: APPLY (실제 복구 실행)

모든 것이 정상이면 URL의 `&dry=1`을 `&apply=1`로 변경해 접속:
```
https://[도메인]/configurador_prueba/php/db-recovery-transacciones.php?key=voltika-recovery-2026&apply=1
```

성공 시 표시되는 내용:
- ✅ "Recovery aplicado con éxito"
- ✅ Filas insertadas: 23 (또는 중복 스킵 수 만큼 작은 수)
- ✅ Total ahora: 32 (9 + 23)
- ✅ 3개 주요 레코드 검증 테이블

#### 4단계: 보안 파일 삭제

복구 성공 후 **반드시** 서버에서 파일 삭제:
```bash
rm /path/to/configurador_prueba/php/db-recovery-transacciones.php
```

---

### 방법 B: phpMyAdmin Import

#### 1단계: phpMyAdmin 로그인

#### 2단계: 데이터베이스 선택
왼쪽 메뉴에서 `voltika_` 데이터베이스 클릭

#### 3단계: Import 탭

상단 탭에서 **"Importar"** (Import) 클릭

#### 4단계: 파일 선택

- **Archivo a importar**: `recovery_transacciones_2026-04-24.sql` 선택
- **Formato**: SQL (자동 감지됨)
- 하단 **"Continuar"** (Go) 버튼 클릭

#### 5단계: 결과 확인

- `count_before_recovery`: **9**
- `count_after_recovery`: **32** (9 + 23)
- 3건 샘플 검증 결과 표시

#### 6단계: COMMIT 실행

phpMyAdmin의 **SQL 탭**에서 다음 입력 후 실행:
```sql
COMMIT;
```

---

### 방법 C: MySQL CLI (SSH 필요)

서버에 SSH 접속 후:

```bash
# 1. 복구 파일을 서버에 업로드
scp recovery_transacciones_2026-04-24.sql user@voltika.mx:~/

# 2. SSH 접속
ssh user@voltika.mx

# 3. 먼저 현재 상태 백업 (안전장치)
mysqldump -u voltika -p voltika_ transacciones \
  > backup_before_recovery_$(date +%Y%m%d_%H%M%S).sql

# 4. 복구 실행
mysql -u voltika -p voltika_ < recovery_transacciones_2026-04-24.sql

# 5. 결과 확인
mysql -u voltika -p voltika_ -e "SELECT COUNT(*) FROM transacciones;"
# 결과: 32 이 나와야 함

# 6. 트랜잭션 커밋 (방법 C는 스크립트 내 COMMIT 주석 처리되어 있으므로)
mysql -u voltika -p voltika_ -e "COMMIT;"
```

---

## 🔍 복구 후 확인 체크리스트

| 확인 사항 | SQL 쿼리 | 기대 값 |
|----------|---------|--------|
| 전체 레코드 수 | `SELECT COUNT(*) FROM transacciones;` | **32** (9+23) |
| 복구된 오래된 레코드 | `SELECT COUNT(*) FROM transacciones WHERE freg LIKE '%2025%';` | **11** (2025년) |
| 원본 4월 레코드 | `SELECT COUNT(*) FROM transacciones WHERE freg LIKE '2026-04-05%' OR freg LIKE '2026-04-06%';` | **8** |
| 현재 신규 레코드 유지 | `SELECT COUNT(*) FROM transacciones WHERE folio_contrato LIKE 'VK-2026042%';` | **9** (변함없음) |
| 첫 고객 기록 | `SELECT nombre, total FROM transacciones WHERE pedido='1756526853';` | `alejandro sanxhez becerril`, `44790` |
| 마지막 백업 기록 | `SELECT nombre, total FROM transacciones WHERE pedido='1775502429';` | `David`, `12065` |

---

## ⚠️ 문제 발생 시 복구 절차

### 시나리오 1: "Duplicate entry" 에러

**원인**: `INSERT IGNORE` 로 방지되어야 하지만, 혹시 발생 시
**해결**: 트랜잭션 자동 ROLLBACK됨. 그대로 안전.

### 시나리오 2: 복구 후 레코드 수가 32가 아님

**원인**: 중복된 `pedido` 가 있거나 스키마 문제
**해결**:
```sql
-- 어떤 pedido가 이미 있는지 확인
SELECT pedido, COUNT(*) as cnt FROM transacciones GROUP BY pedido HAVING cnt > 1;
```

### 시나리오 3: 잘못 복구함 → 되돌리고 싶음

**트랜잭션을 COMMIT하기 전**이라면:
```sql
ROLLBACK;
```

**이미 COMMIT 후**라면 → 복구된 레코드만 선택적 삭제:
```sql
-- 방법 A 또는 B를 썼다면 (auto-increment로 새 ID 부여됨)
-- 복구된 레코드는 현재 최대 id 이후의 번호
DELETE FROM transacciones WHERE id > 9 AND folio_contrato IS NULL;

-- 확인 후
SELECT COUNT(*) FROM transacciones;  -- 9가 나와야 함
```

---

## 📅 공백 기간 처리 (2026-04-07 ~ 2026-04-20)

복구 후에도 14일간의 데이터가 비어있습니다. 복구 방법:

### Stripe 대시보드에서 추출

1. `dashboard.stripe.com` → Payments
2. 필터: Date range = **2026-04-07 ~ 2026-04-20**, Status = Succeeded
3. Export → CSV
4. CSV에서 다음 정보 추출:
   - `id` → `stripe_pi` 컬럼
   - `Customer Email` → `email`
   - `Customer Name` → `nombre`
   - `Amount` → `total`
   - `Created` → `freg`

이 데이터를 `transacciones` 테이블에 추가 INSERT 해서 공백을 메울 수 있습니다. 필요 시 해당 CSV 파일을 저에게 공유해 주시면 추가 복구 SQL을 생성해 드립니다.

---

## 🔗 참고

- 원본 문의/설계: [DEVELOPER_HANDOFF.pdf](Voltika Aliados App Developer Handoff.pdf)
- 기존 백업 도구: [db-backup.php](configurador_prueba/php/db-backup.php)
- SQL 생성기: [generate-recovery-sql.py](generate-recovery-sql.py)
