#!/usr/bin/env python3
"""
Voltika Transacciones Recovery Script
Generates a safe INSERT SQL from the old backup (2026-04-06) that:
  - Drops the `id` column (auto-increment handles collision with current id 1-9)
  - Uses explicit column names (compatible with new schema)
  - Leaves new columns (referido_id, referido_tipo, caso, folio_contrato) as NULL
  - Wraps everything in a transaction with safety checks
  - Uses INSERT IGNORE on `pedido` to avoid duplicates (if any)
"""

import re
import sys
from pathlib import Path

BACKUP_FILE = Path(__file__).parent / "backup_2026-04-06.sql"
OUTPUT_FILE = Path(__file__).parent / "recovery_transacciones_2026-04-24.sql"

# Columns in the OLD backup (in the order they appear in VALUES)
OLD_COLUMNS = [
    "id", "pedido", "referido", "nombre", "telefono", "email",
    "razon", "rfc", "direccion", "ciudad", "estado", "cp",
    "e_nombre", "e_telefono", "e_direccion", "e_ciudad", "e_estado", "e_cp",
    "modelo", "color", "tpago", "tenvio",
    "precio", "penvio", "total", "freg", "stripe_pi"
]

# Columns we will INSERT (excludes id to let auto-increment work)
INSERT_COLUMNS = [c for c in OLD_COLUMNS if c != "id"]


def parse_values(content: str) -> list[list[str]]:
    """Extract all rows from the INSERT INTO `transacciones` VALUES (...) block."""
    match = re.search(
        r"INSERT INTO `transacciones` VALUES\s*(.*?);",
        content, re.DOTALL
    )
    if not match:
        print("ERROR: No INSERT INTO `transacciones` block found.", file=sys.stderr)
        sys.exit(1)

    values_block = match.group(1).strip()
    rows = []
    current = []
    buf = ""
    depth = 0
    in_quote = False
    escape = False

    for ch in values_block:
        if escape:
            buf += ch
            escape = False
            continue
        if ch == "\\" and in_quote:
            buf += ch
            escape = True
            continue
        if ch == "'" and not escape:
            in_quote = not in_quote
            buf += ch
            continue
        if in_quote:
            buf += ch
            continue
        if ch == "(":
            if depth == 0:
                buf = ""
                current = []
            else:
                buf += ch
            depth += 1
            continue
        if ch == ")":
            depth -= 1
            if depth == 0:
                current.append(buf.strip())
                rows.append(current)
                buf = ""
                current = []
            else:
                buf += ch
            continue
        if ch == "," and depth == 1:
            current.append(buf.strip())
            buf = ""
            continue
        buf += ch

    return rows


def format_value(v: str) -> str:
    """Return value as-is (already quoted/NULL in source)."""
    v = v.strip()
    if v == "" or v.upper() == "NULL":
        return "NULL"
    return v


def main():
    if not BACKUP_FILE.exists():
        print(f"ERROR: Backup file not found: {BACKUP_FILE}", file=sys.stderr)
        sys.exit(1)

    content = BACKUP_FILE.read_text(encoding="utf-8")
    rows = parse_values(content)

    print(f"Parsed {len(rows)} rows from backup.")

    # Build SQL
    lines = []
    lines.append("-- ════════════════════════════════════════════════════════════════")
    lines.append("-- VOLTIKA TRANSACCIONES RECOVERY")
    lines.append(f"-- Generated: 2026-04-24 · from backup_2026-04-06.sql")
    lines.append(f"-- Records to restore: {len(rows)} (original IDs 1-23)")
    lines.append("-- ")
    lines.append("-- SAFETY MEASURES:")
    lines.append("--   1. Runs inside a TRANSACTION (can ROLLBACK)")
    lines.append("--   2. Omits `id` column → auto-increment continues from current max")
    lines.append("--   3. Current records (id 1-9, April 21-22) are UNTOUCHED")
    lines.append("--   4. Uses INSERT IGNORE on pedido duplicates as extra safety")
    lines.append("--   5. Pre/post count checks to verify")
    lines.append("-- ")
    lines.append("-- HOW TO APPLY:")
    lines.append("--   Option A) phpMyAdmin → Database → Import tab → choose this file")
    lines.append("--   Option B) mysql -u voltika -p voltika_ < recovery_transacciones_2026-04-24.sql")
    lines.append("-- ════════════════════════════════════════════════════════════════")
    lines.append("")
    lines.append("START TRANSACTION;")
    lines.append("")
    lines.append("-- Show count BEFORE recovery")
    lines.append("SELECT COUNT(*) AS `count_before_recovery` FROM `transacciones`;")
    lines.append("")
    lines.append("-- ── Restore 23 records from backup (old schema → new schema) ──")
    lines.append("-- Columns explicitly listed. New columns (referido_id, referido_tipo,")
    lines.append("-- caso, folio_contrato) are omitted and will default to NULL.")
    lines.append("")

    cols = ", ".join(f"`{c}`" for c in INSERT_COLUMNS)
    lines.append(f"INSERT IGNORE INTO `transacciones` ({cols}) VALUES")

    value_strs = []
    for row in rows:
        if len(row) != len(OLD_COLUMNS):
            print(f"WARNING: row has {len(row)} values, expected {len(OLD_COLUMNS)}")
            continue
        # Skip id column
        values = [format_value(v) for v in row[1:]]
        value_strs.append("(" + ", ".join(values) + ")")

    lines.append(",\n".join(value_strs) + ";")
    lines.append("")
    lines.append("-- Show count AFTER recovery")
    lines.append("SELECT COUNT(*) AS `count_after_recovery` FROM `transacciones`;")
    lines.append("")
    lines.append("-- Verify specific restored records (sample checks)")
    lines.append("SELECT `id`, `pedido`, `nombre`, `freg`, `total`, `stripe_pi`")
    lines.append("FROM `transacciones`")
    lines.append("WHERE `pedido` IN ('1756526853', '1775502429', '1775414052')")
    lines.append("ORDER BY `id`;")
    lines.append("")
    lines.append("-- ════════════════════════════════════════════════════════════════")
    lines.append("-- IMPORTANT: Review the SELECT output above BEFORE running COMMIT")
    lines.append("-- If something looks wrong, run ROLLBACK instead of COMMIT")
    lines.append("-- ════════════════════════════════════════════════════════════════")
    lines.append("")
    lines.append("-- Uncomment ONE of the following after verification:")
    lines.append("-- COMMIT;      -- ✓ Applies the recovery permanently")
    lines.append("-- ROLLBACK;    -- ✗ Discards all changes if something's wrong")
    lines.append("")

    OUTPUT_FILE.write_text("\n".join(lines), encoding="utf-8")
    print(f"✓ Written: {OUTPUT_FILE}")
    print(f"  File size: {OUTPUT_FILE.stat().st_size:,} bytes")
    print(f"  Records: {len(value_strs)}")


if __name__ == "__main__":
    main()
