# 🔧 Voltika Transacciones Recovery Guide

**Created**: 2026-04-24
**Recovery target**: 23 rows in the `transacciones` table (2025-08-30 ~ 2026-04-06)
**To preserve**: 9 rows currently in the DB (2026-04-21 ~ 2026-04-22)

---

## 📦 Provided Files

| File | Purpose | Location |
|------|---------|----------|
| `backup_2026-04-06.sql` | Original backup (do not modify) | Project root |
| `recovery_transacciones_2026-04-24.sql` | Converted recovery SQL | Project root |
| `configurador_prueba/php/db-recovery-transacciones.php` | Server-side PHP runner script | PHP folder |
| `generate-recovery-sql.py` | SQL regeneration script (if needed) | Project root |
| `RECOVERY_GUIDE.md` | This document | Project root |

---

## 🛡️ Safeguards (Already Applied)

1. ✅ **Original backup is immutable**: `backup_2026-04-06.sql` is never modified
2. ✅ **Safety copy**: `c:/tmp/voltika-recovery-2026-04-24/backup_2026-04-06.sql.safe-copy`
3. ✅ **Transactional execution**: automatic ROLLBACK on error
4. ✅ **INSERT IGNORE**: duplicates are skipped silently (no failure)
5. ✅ **Avoids ID collisions**: `id` column excluded → new rows get auto-increment values
6. ✅ **Schema-compatible**: the 4 new columns (`referido_id`, `referido_tipo`, `caso`, `folio_contrato`) are filled with NULL

---

## 🚀 How to Run — Choose One of Three Methods

### Method ⭐ A: PHP script (recommended — easiest)

**Advantages**: two-step confirmation (DRY RUN → APPLY), visual result, auto-validation

#### Step 1: Upload files

Upload the following 2 files to the server:

```
[server-root]/configurador_prueba/php/db-recovery-transacciones.php
[server-root]/recovery_transacciones_2026-04-24.sql
```

#### Step 2: DRY RUN (safe preview — no actual changes)

Open this URL in a browser:
```
https://[domain]/configurador_prueba/php/db-recovery-transacciones.php?key=voltika-recovery-2026&dry=1
```

Verify on the page:
- ✅ Current record count shows 9
- ✅ "A restaurar: 23"
- ✅ The 9 existing records appear in the table
- ✅ Preview of the INSERT statements that will run

#### Step 3: APPLY (run the actual recovery)

If everything looks correct, change `&dry=1` in the URL to `&apply=1`:
```
https://[domain]/configurador_prueba/php/db-recovery-transacciones.php?key=voltika-recovery-2026&apply=1
```

On success you'll see:
- ✅ "Recovery aplicado con éxito"
- ✅ Filas insertadas: 23 (or slightly less if some were deduplicated)
- ✅ Total ahora: 32 (9 + 23)
- ✅ Verification table showing 3 key records

#### Step 4: Delete the security file

After a successful recovery, you **must** delete the script from the server:
```bash
rm /path/to/configurador_prueba/php/db-recovery-transacciones.php
```

---

### Method B: phpMyAdmin Import

#### Step 1: Log in to phpMyAdmin

#### Step 2: Select the database
In the left menu, click the `voltika_` database.

#### Step 3: Import tab

Click **"Importar"** (Import) in the top tab bar.

#### Step 4: Choose the file

- **Archivo a importar**: select `recovery_transacciones_2026-04-24.sql`
- **Formato**: SQL (auto-detected)
- Click the **"Continuar"** (Go) button at the bottom

#### Step 5: Verify results

- `count_before_recovery`: **9**
- `count_after_recovery`: **32** (9 + 23)
- Verification output for 3 sample rows

#### Step 6: COMMIT

In the phpMyAdmin **SQL tab**, enter and run:
```sql
COMMIT;
```

---

### Method C: MySQL CLI (requires SSH)

After SSHing into the server:

```bash
# 1. Upload the recovery file to the server
scp recovery_transacciones_2026-04-24.sql user@voltika.mx:~/

# 2. SSH in
ssh user@voltika.mx

# 3. Back up the current state first (safety net)
mysqldump -u voltika -p voltika_ transacciones \
  > backup_before_recovery_$(date +%Y%m%d_%H%M%S).sql

# 4. Run the recovery
mysql -u voltika -p voltika_ < recovery_transacciones_2026-04-24.sql

# 5. Verify the result
mysql -u voltika -p voltika_ -e "SELECT COUNT(*) FROM transacciones;"
# Expected result: 32

# 6. Commit the transaction (COMMIT is commented out inside the script for Method C)
mysql -u voltika -p voltika_ -e "COMMIT;"
```

---

## 🔍 Post-Recovery Verification Checklist

| Check | SQL query | Expected value |
|-------|-----------|----------------|
| Total record count | `SELECT COUNT(*) FROM transacciones;` | **32** (9+23) |
| Recovered older records | `SELECT COUNT(*) FROM transacciones WHERE freg LIKE '%2025%';` | **11** (from 2025) |
| Original April records | `SELECT COUNT(*) FROM transacciones WHERE freg LIKE '2026-04-05%' OR freg LIKE '2026-04-06%';` | **8** |
| Current new records preserved | `SELECT COUNT(*) FROM transacciones WHERE folio_contrato LIKE 'VK-2026042%';` | **9** (unchanged) |
| First customer record | `SELECT nombre, total FROM transacciones WHERE pedido='1756526853';` | `alejandro sanxhez becerril`, `44790` |
| Last backup record | `SELECT nombre, total FROM transacciones WHERE pedido='1775502429';` | `David`, `12065` |

---

## ⚠️ Recovery Procedure If Something Goes Wrong

### Scenario 1: "Duplicate entry" error

**Cause**: should be prevented by `INSERT IGNORE`, but just in case
**Resolution**: the transaction rolls back automatically. Safe as-is.

### Scenario 2: Post-recovery record count is not 32

**Cause**: a duplicated `pedido` or a schema mismatch
**Resolution**:
```sql
-- Find which pedido values are already present
SELECT pedido, COUNT(*) as cnt FROM transacciones GROUP BY pedido HAVING cnt > 1;
```

### Scenario 3: Recovery was wrong → want to undo

**Before the transaction has been committed**:
```sql
ROLLBACK;
```

**If already committed** → selectively delete only the recovered rows:
```sql
-- If Method A or B was used (new auto-increment IDs were assigned)
-- Recovered rows have IDs greater than the previous maximum id
DELETE FROM transacciones WHERE id > 9 AND folio_contrato IS NULL;

-- Verify
SELECT COUNT(*) FROM transacciones;  -- should return 9
```

---

## 📅 Handling the Gap Period (2026-04-07 ~ 2026-04-20)

After recovery there is still a 14-day gap with missing data. How to backfill:

### Extract from the Stripe dashboard

1. `dashboard.stripe.com` → Payments
2. Filter: Date range = **2026-04-07 ~ 2026-04-20**, Status = Succeeded
3. Export → CSV
4. From the CSV, map these fields:
   - `id` → `stripe_pi` column
   - `Customer Email` → `email`
   - `Customer Name` → `nombre`
   - `Amount` → `total`
   - `Created` → `freg`

These rows can then be INSERTed into the `transacciones` table to close the gap. If you share the CSV with me, I can generate the additional recovery SQL.

---

## 🔗 References

- Original requirements / design: [DEVELOPER_HANDOFF.pdf](Voltika Aliados App Developer Handoff.pdf)
- Existing backup tool: [db-backup.php](configurador_prueba/php/db-backup.php)
- SQL generator: [generate-recovery-sql.py](generate-recovery-sql.py)
