#!/usr/bin/env bash
# Voltika — Plan G cron runner
# Llama a verificar-stripe.php cada 15 minutos para reconciliar
# PaymentIntents de Stripe contra la tabla `transacciones`.
# Cualquier PI `succeeded` sin fila en transacciones se registra
# en `transacciones_errores` y aparece en el dashboard admin.
#
# Configuración requerida:
#   1) Definir VOLTIKA_CRON_TOKEN y VOLTIKA_BASE_URL en el entorno
#      (por ejemplo, en /etc/environment o en el crontab).
#   2) Dar permisos de ejecución: chmod +x verificar-stripe-cron.sh
#   3) Añadir al crontab (ver admin/cron/README.md).

set -euo pipefail

# Default: busca las últimas 2 horas (solapamiento amplio vs. intervalo de 15 min)
HORAS="${VOLTIKA_CRON_HORAS:-2}"

: "${VOLTIKA_BASE_URL:?VOLTIKA_BASE_URL no definido (ej: https://voltika.mx)}"
: "${VOLTIKA_CRON_TOKEN:?VOLTIKA_CRON_TOKEN no definido}"

URL="${VOLTIKA_BASE_URL%/}/admin/php/ventas/verificar-stripe.php?horas=${HORAS}"
LOG_DIR="${VOLTIKA_CRON_LOG_DIR:-/var/log/voltika}"
mkdir -p "$LOG_DIR" 2>/dev/null || LOG_DIR="/tmp"
LOG_FILE="$LOG_DIR/verificar-stripe.log"

TS="$(date '+%Y-%m-%d %H:%M:%S')"
RESPONSE="$(curl -sS -m 60 \
    -H "X-Cron-Token: ${VOLTIKA_CRON_TOKEN}" \
    "$URL" 2>&1 || true)"

echo "[${TS}] ${RESPONSE}" >> "$LOG_FILE"

# Alert if orphans > 0 (parse JSON with awk — avoids jq dependency)
ORPHANS="$(echo "$RESPONSE" | awk -F'"orphans":' '{print $2}' | awk -F',' '{print $1}' | tr -d ' ')"
if [ -n "$ORPHANS" ] && [ "$ORPHANS" != "0" ]; then
    echo "[${TS}] ALERT: ${ORPHANS} orphan PI(s) detectados" >> "$LOG_FILE"
fi
