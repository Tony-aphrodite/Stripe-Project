#!/usr/bin/env bash
# 01 — Syntax check (PHP + JS) de TODOS los archivos modificados/creados
#      por el set de correcciones General_Corrections_EN.docx (2026-05-08).
set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
fail=0

php_files=(
  "admin/php/checklists/guardar-origen.php"
  "admin/php/checklists/generar-pdf.php"
  "admin/php/envios/cambiar-estado.php"
  "configurador/php/voltika-notify.php"
  "configurador/php/cincel-webhook.php"
  "clientes/php/entrega/acta-pdf.php"
  "clientes/php/entrega/cincel-firma-acta.php"
  "clientes/php/entrega/cincel-acta-status.php"
  "puntosvoltika/php/checklists/subir-foto.php"
  "puntosvoltika/php/recepcion/recibir.php"
  "puntosvoltika/php/recepcion/envios-pendientes.php"
  "puntosvoltika/php/entrega/checklist.php"
  "puntosvoltika/php/entrega/verificar-rostro.php"
  "puntosvoltika/php/entrega/guardar-paso.php"
  "puntosvoltika/php/entrega/marcar-no-exitosa.php"
  "puntosvoltika/php/cron/expirar-entregas.php"
)

js_files=(
  "admin/js/modules/admin-envios.js"
  "admin/js/modules/admin-checklists.js"
  "clientes/js/modules/entrega.js"
  "puntosvoltika/js/modules/punto-recepcion.js"
  "puntosvoltika/js/modules/punto-checklist-ensamble.js"
  "puntosvoltika/js/modules/punto-entrega.js"
)

echo "── PHP ──"
for f in "${php_files[@]}"; do
  out=$(php -l "$ROOT/$f" 2>&1)
  if echo "$out" | grep -q "No syntax errors"; then
    echo "  [PASS] $f"
  else
    echo "  [FAIL] $f"
    echo "         $out"
    fail=1
  fi
done

echo "── JS ──"
for f in "${js_files[@]}"; do
  if node --check "$ROOT/$f" >/dev/null 2>&1; then
    echo "  [PASS] $f"
  else
    echo "  [FAIL] $f"
    node --check "$ROOT/$f"
    fail=1
  fi
done

exit $fail
