#!/usr/bin/env bash
# 02 — Static analysis: verifica que cada bug del docx tiene el código
#      promised donde corresponde. NO toca DB ni servidor — solo grep.
set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
fail=0

assert() {
  # assert <message> <file> <pattern>  (pattern is grep -E)
  local msg="$1" file="$2" pat="$3"
  if grep -qE "$pat" "$ROOT/$file" 2>/dev/null; then
    echo "  [PASS] $msg"
  else
    echo "  [FAIL] $msg — pattern not found in $file"
    echo "         pattern: $pat"
    fail=1
  fi
}

assert_not() {
  local msg="$1" file="$2" pat="$3"
  if grep -qE "$pat" "$ROOT/$file" 2>/dev/null; then
    echo "  [FAIL] $msg — pattern STILL present in $file (should have been removed)"
    fail=1
  else
    echo "  [PASS] $msg"
  fi
}

echo "── Bug 1.1 — Engine number validation ──"
assert "guardar-origen carga num_motor de inventario" \
  "admin/php/checklists/guardar-origen.php" \
  "SELECT id, vin, vin_display, modelo, color, anio_modelo, num_motor"
assert "guardar-origen compara oficial vs typed" \
  "admin/php/checklists/guardar-origen.php" \
  'oficial !== \$tipoO'

echo "── Bug 1.2 — PDF firma/timestamps + autosave ──"
assert "guardar-origen migra fecha_inicio" \
  "admin/php/checklists/guardar-origen.php" \
  "ADD COLUMN fecha_inicio DATETIME"
assert "guardar-origen migra fecha_completado" \
  "admin/php/checklists/guardar-origen.php" \
  "ADD COLUMN fecha_completado DATETIME"
assert "PDF muestra Realizado por" \
  "admin/php/checklists/generar-pdf.php" \
  "Realizado por"
assert "admin-checklists tiene autosave 30s" \
  "admin/js/modules/admin-checklists.js" \
  "startOrigenAutosave"
assert "autosave usa 30000ms" \
  "admin/js/modules/admin-checklists.js" \
  "30000"

echo "── Bug 2.1 — Fecha llegada >= fecha envío ──"
assert "cambiar-estado valida etaIn < fenvIn" \
  "admin/php/envios/cambiar-estado.php" \
  'etaIn < \$fenvIn'
assert "modal frontend tiene listener change para fecha_envio" \
  "admin/js/modules/admin-envios.js" \
  "adEnvFechaEnvio.*change"

echo "── Bug 2.2 — Tracking + Carrier en marcar enviada ──"
assert "modal incluye adEnvUpdTracking" \
  "admin/js/modules/admin-envios.js" \
  "adEnvUpdTracking"
assert "modal incluye adEnvUpdCarrier" \
  "admin/js/modules/admin-envios.js" \
  "adEnvUpdCarrier"
assert "cambiar-estado lee tracking_number" \
  "admin/php/envios/cambiar-estado.php" \
  'tracking_number.*\\?\\?'
assert "cambiar-estado lee carrier" \
  "admin/php/envios/cambiar-estado.php" \
  "isset.*'carrier'"

echo "── Bug 3.1 + 3.2 — Reception page status + info ──"
assert "punto-recepcion tiene statusBadge" \
  "puntosvoltika/js/modules/punto-recepcion.js" \
  "function statusBadge"
assert "punto-recepcion muestra Origen certificado" \
  "puntosvoltika/js/modules/punto-recepcion.js" \
  "Origen certificado"
assert "envios-pendientes JOIN checklist_origen" \
  "puntosvoltika/php/recepcion/envios-pendientes.php" \
  "checklist_origen"

echo "── Bug 3.3 — Reception checklist detallado ──"
assert "recibir migra vin_caja" \
  "puntosvoltika/php/recepcion/recibir.php" \
  "vin_caja"
assert "recibir migra sello_numero" \
  "puntosvoltika/php/recepcion/recibir.php" \
  "sello_numero"
assert "recibir guarda foto_sello_url" \
  "puntosvoltika/php/recepcion/recibir.php" \
  "foto_sello_url"
assert "punto-recepcion UI tiene pvCSello" \
  "puntosvoltika/js/modules/punto-recepcion.js" \
  "pvCSello"
assert "punto-recepcion UI tiene dualPhoto helper" \
  "puntosvoltika/js/modules/punto-recepcion.js" \
  "function dualPhoto"

echo "── Bug 3.4 — PENDIENTE DE ASIGNACIÓN ──"
assert "envios-pendientes incluye pendiente_asignacion" \
  "puntosvoltika/php/recepcion/envios-pendientes.php" \
  "pendiente_asignacion"
assert "punto-recepcion UI maneja pendiente_asignacion" \
  "puntosvoltika/js/modules/punto-recepcion.js" \
  "pendiente_asignacion"

echo "── Bug 4.1 — Assembly photos sync ──"
assert "punto-checklist-ensamble tiene fotoCampo en secciones" \
  "puntosvoltika/js/modules/punto-checklist-ensamble.js" \
  "fotoCampo"
assert "punto-checklist-ensamble tiene renderPhotoZone" \
  "puntosvoltika/js/modules/punto-checklist-ensamble.js" \
  "function renderPhotoZone"
assert "punto subir-foto endpoint existe" \
  "puntosvoltika/php/checklists/subir-foto.php" \
  "puntoRequireAuth"
assert "subir-foto valida campo whitelist" \
  "puntosvoltika/php/checklists/subir-foto.php" \
  "validCampos"

echo "── Bug 5.1 — Auto-save delivery + 6h timeout + no-exitosa ──"
assert "guardar-paso endpoint existe" \
  "puntosvoltika/php/entrega/guardar-paso.php" \
  "puntoRequireAuth"
assert "marcar-no-exitosa endpoint existe" \
  "puntosvoltika/php/entrega/marcar-no-exitosa.php" \
  "no_exitosa"
assert "expirar-entregas cron 6h" \
  "puntosvoltika/php/cron/expirar-entregas.php" \
  "INTERVAL 6 HOUR"
assert "punto-entrega tiene autosave helper" \
  "puntosvoltika/js/modules/punto-entrega.js" \
  "function autosave"
assert "punto-entrega tiene noExitosaBtnHtml" \
  "puntosvoltika/js/modules/punto-entrega.js" \
  "noExitosaBtnHtml"

echo "── Bug 5.2 — OTP por SMS no email ──"
assert "voltika-notify omite email para otp_entrega" \
  "configurador/php/voltika-notify.php" \
  "emailSkipTipos"
assert "otp_entrega está en la lista de skip" \
  "configurador/php/voltika-notify.php" \
  "'otp_entrega'.*emailSkipTipos|emailSkipTipos.*'otp_entrega'"

echo "── Bug 5.3 — Cámara + archivo para INE ──"
assert "punto-entrega step3 tiene dualInput helper" \
  "puntosvoltika/js/modules/punto-entrega.js" \
  "function dualInput"
assert "punto-entrega usa pvOpenCam class" \
  "puntosvoltika/js/modules/punto-entrega.js" \
  "pvOpenCam"
assert "punto-entrega usa pvOpenFile class" \
  "puntosvoltika/js/modules/punto-entrega.js" \
  "pvOpenFile"

echo "── Bug 5.4 — Reverso de INE ──"
assert "punto-entrega tiene slot ineFrente" \
  "puntosvoltika/js/modules/punto-entrega.js" \
  "ineFrente"
assert "punto-entrega tiene slot ineReverso" \
  "puntosvoltika/js/modules/punto-entrega.js" \
  "ineReverso"
assert "verificar-rostro acepta foto_ine_reverso" \
  "puntosvoltika/php/entrega/verificar-rostro.php" \
  "foto_ine_reverso"

echo "── Bug 5.6 — Full delivery checklist en PoS ──"
assert "punto-entrega step4 tiene STEP4_PHASES" \
  "puntosvoltika/js/modules/punto-entrega.js" \
  "STEP4_PHASES"
assert "step4 incluye fase F1 — Identidad" \
  "puntosvoltika/js/modules/punto-entrega.js" \
  "F1 — Identidad"
assert "step4 incluye fase F2 — Pago" \
  "puntosvoltika/js/modules/punto-entrega.js" \
  "F2 — Pago"
assert "step4 incluye fase F3 — Unidad" \
  "puntosvoltika/js/modules/punto-entrega.js" \
  "F3 — Unidad"
assert "checklist.php migra columnas fase1/fase2" \
  "puntosvoltika/php/entrega/checklist.php" \
  "ine_presentada"

echo "── Bug 5.7 — Cincel ACTA en customer portal ──"
assert "acta-pdf.php usa FPDF" \
  "clientes/php/entrega/acta-pdf.php" \
  "new FPDF"
assert "cincel-firma-acta usa portalRequireAuth" \
  "clientes/php/entrega/cincel-firma-acta.php" \
  "portalRequireAuth"
assert "cincel-firma-acta llama Cincel auth" \
  "clientes/php/entrega/cincel-firma-acta.php" \
  "auth/login"
assert "cincel-acta-status devuelve signed flag" \
  "clientes/php/entrega/cincel-acta-status.php" \
  "'signed'.*=>"
assert "webhook reconoce ACTA via cincel_acta_document_id" \
  "configurador/php/cincel-webhook.php" \
  "cincel_acta_document_id"
assert "webhook actualiza cliente_acta_firmada para ACTA" \
  "configurador/php/cincel-webhook.php" \
  "cliente_acta_firmada = 1"
assert "entrega.js iframe Cincel" \
  "clientes/js/modules/entrega.js" \
  "showCincelIframe"
assert_not "entrega.js NO muestra checkbox como única firma" \
  "clientes/js/modules/entrega.js" \
  'vkFirmaAcepto.*Firmar ACTA'

echo "── Bug 5.8 — Confirmar recepción button removed ──"
assert_not "entrega.js NO renderiza vkConfirmarRecep button" \
  "clientes/js/modules/entrega.js" \
  "id=\"vkConfirmarRecep\""

echo ""
echo "──────────────────────────────────"
if [ $fail -eq 0 ]; then
  echo "  ✓ Todos los static analysis checks PASARON"
else
  echo "  ✗ Hay fallas — revisar arriba"
fi
exit $fail
