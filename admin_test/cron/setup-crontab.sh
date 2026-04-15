#!/bin/bash
# ============================================================
# Voltika — Automatic Crontab Setup Script
# Run once on server: bash admin/cron/setup-crontab.sh
# ============================================================

TOKEN="a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6"
BASE="https://voltika.mx"

echo "========================================="
echo "  Voltika Crontab Setup"
echo "========================================="
echo ""

# Backup existing crontab
crontab -l > /tmp/voltika_crontab_backup_$(date +%Y%m%d%H%M%S).txt 2>/dev/null
echo "[1/3] Existing crontab backed up"

# Remove existing Voltika entries to prevent duplicates
(crontab -l 2>/dev/null | grep -v "voltika.mx/admin") > /tmp/voltika_cron_clean.txt

# Append new entries
cat >> /tmp/voltika_cron_clean.txt << EOF

# ── Voltika Cron Jobs ──────────────────────────────
# Stripe payment reconciliation (every 15 min)
*/15 * * * * curl -s -H "X-Cron-Token: ${TOKEN}" "${BASE}/admin/php/ventas/verificar-stripe.php?horas=2" > /dev/null 2>&1

# Payment cycle generation (every Monday at 6am)
0 6 * * 1 curl -s -H "X-Cron-Token: ${TOKEN}" "${BASE}/admin/cron/generar-ciclos.php" > /dev/null 2>&1

# Mark overdue payments (daily at 7am)
0 7 * * * curl -s -H "X-Cron-Token: ${TOKEN}" "${BASE}/admin/cron/marcar-vencidos.php" > /dev/null 2>&1

# SMS/email overdue reminders (daily at 9am)
0 9 * * * curl -s -H "X-Cron-Token: ${TOKEN}" "${BASE}/admin/cron/recordatorios.php" > /dev/null 2>&1

# Auto-charge retry on overdue payments (daily at 10am)
0 10 * * * curl -s -H "X-Cron-Token: ${TOKEN}" "${BASE}/admin/cron/auto-cobro.php" > /dev/null 2>&1
# ── End Voltika ────────────────────────────────────
EOF

echo "[2/3] Adding 5 cron jobs..."

# Register crontab
crontab /tmp/voltika_cron_clean.txt

if [ $? -eq 0 ]; then
    echo "[3/3] Crontab registered successfully!"
    echo ""
    echo "Registered Voltika cron jobs:"
    echo "─────────────────────────────────────"
    crontab -l | grep -A1 "voltika.mx"
    echo ""
    echo "========================================="
    echo "  Setup complete! Crons are now active."
    echo "========================================="
else
    echo "[ERROR] Failed to register crontab"
    echo "Try manually: crontab -e"
    exit 1
fi

# Cleanup temp files
rm -f /tmp/voltika_cron_clean.txt
