<?php
/**
 * Voltika Admin — Verifica que los fixes del 2026-05-13 (round 14) están
 * desplegados en el servidor.
 *
 * Customer brief 2026-05-13: la gente sube archivos al servidor pero
 * no sabe si llegaron correctos o si PHP OPcache sigue sirviendo la
 * versión vieja. Este checker:
 *   1. Lee el contenido de cada archivo modificado
 *   2. Busca un "marcador" único que sólo existe en la versión nueva
 *   3. Reporta deployed=true/false por cada uno
 *   4. Para los endpoints reales, hace una llamada de prueba
 *
 * URL: /admin/php/verificar-deploy.php
 */
require_once __DIR__ . '/bootstrap.php';
$adminId = adminRequireAuth(['admin']);

function _checkFile(string $path, string $marker, string $description): array {
    if (!file_exists($path)) {
        return ['ok' => false, 'desc' => $description, 'path' => $path,
                'reason' => 'archivo no encontrado en el servidor'];
    }
    $content = @file_get_contents($path);
    if ($content === false) {
        return ['ok' => false, 'desc' => $description, 'path' => $path,
                'reason' => 'no se pudo leer (permisos?)'];
    }
    $found = strpos($content, $marker) !== false;
    return [
        'ok' => $found,
        'desc' => $description,
        'path' => $path,
        'marker' => $marker,
        'size_bytes' => strlen($content),
        'mtime' => date('Y-m-d H:i:s', filemtime($path)),
        'reason' => $found ? 'nuevo código presente' : 'marcador no encontrado — versión vieja todavía',
    ];
}

$base = realpath(__DIR__ . '/../..');

$checks = [
    'recover_truora' => _checkFile(
        $base . '/configurador/recover-truora.php',
        '?recovered=1&paso=credito-identidad',
        'Issue 1 — Email recovery link incluye ?paso= explícito'
    ),
    'configurador_js' => _checkFile(
        $base . '/configurador/js/configurador.js',
        "URLSearchParams(window.location.search).get('paso')",
        'Issue 1 — SPA configurador.js maneja ?paso= URL parameter'
    ),
    'compras_php' => _checkFile(
        $base . '/clientes/php/cliente/compras.php',
        'duplicate_attempts',
        'Issue 2 — Mis compras hace dedup de duplicados'
    ),
    'cdc_excel' => _checkFile(
        $base . '/admin/php/buro/exportar.php',
        'NOMBRE_CLIENTE',
        'Issue 3 — CDC Excel: 16 columnas oficiales NIP-CIEC PF'
    ),
    'cdc_excel_order' => _checkFile(
        $base . '/admin/php/buro/exportar.php',
        "'Estado',                      // lowercase 'e'",
        'Issue 3 — CDC Excel: columna Estado con minúscula (template oficial)'
    ),
    // ── Round 15 (2026-05-14) — Contrato DÉCIMA SÉPTIMA ────────────────────
    'r15_name_sanitizer' => _checkFile(
        $base . '/configurador/php/contrato-contado.php',
        'function contratoContadoSanitizeFullName(',
        'Round 15 — Contrato: sanitizer de nombre (no más "Adrian Montoya Diaz Montoya Diaz")'
    ),
    'r15_cincel_fetcher' => _checkFile(
        $base . '/configurador/php/contrato-contado.php',
        'function contratoContadoFetchCincelAudit(',
        'Round 15 — Contrato: fetcher de Cincel / NOM-151 audit'
    ),
    'r15_registro_cincel_row' => _checkFile(
        $base . '/configurador/php/contrato-contado.php',
        'Firma electrónica avanzada (Acta de Entrega)',
        'Round 15 — Contrato REGISTRO: fila Cincel / firma electrónica avanzada visible'
    ),
    'r15_registro_nom151_row' => _checkFile(
        $base . '/configurador/php/contrato-contado.php',
        'Constancia NOM-151-SCFI-2016',
        'Round 15 — Contrato REGISTRO: fila Constancia NOM-151-SCFI-2016'
    ),
    'r15_registro_ip_fallback' => _checkFile(
        $base . '/configurador/php/contrato-contado.php',
        'No capturada por el sistema en el momento de la aceptación',
        'Round 15 — Contrato REGISTRO: fallback explícito para IP / Geo / Dispositivo vacíos'
    ),
    'r15_confirmar_uses_sanitizer' => _checkFile(
        $base . '/configurador/php/confirmar-orden.php',
        'contratoContadoSanitizeFullName(',
        'Round 15 — confirmar-orden.php usa el sanitizer en lugar del implode crudo'
    ),
    'r15_descargar_uses_sanitizer' => _checkFile(
        $base . '/configurador/php/descargar-contrato.php',
        'contratoContadoSanitizeFullName(',
        'Round 15 — descargar-contrato.php regen sanitiza el nombre persistido'
    ),
    // ── Round 16 (2026-05-14) — Dossier de Defensa PDF ─────────────────────
    'r16_dossier_uses_sanitizer' => _checkFile(
        $base . '/configurador/php/dossier-defensa.php',
        'contratoContadoSanitizeFullName(',
        'Round 16 — Dossier PDF: sanitizer aplicado al nombre del cliente'
    ),
    'r16_dossier_modalidad_normalized' => _checkFile(
        $base . '/configurador/php/dossier-defensa.php',
        "'CONTADO'",
        'Round 16 — Dossier PDF: Modalidad de pago "UNICO" normalizada a "CONTADO"'
    ),
    'r16_dossier_ip_fallback' => _checkFile(
        $base . '/configurador/php/dossier-defensa.php',
        'No capturada por el sistema en el momento de la aceptación',
        'Round 16 — Dossier PDF: fallback explícito para IP / Geo / Dispositivo / SHA-256'
    ),
    // ── Round 17 (2026-05-14) — Checklist drill-in con autor + fotos ───────
    'r17_detalle_dealer_enrichment' => _checkFile(
        $base . '/admin/php/checklists/detalle.php',
        '_detalleDealerInfo(',
        'Round 17 — detalle.php expone _dealer_nombre / _dealer_rol / _dealer_punto por fase'
    ),
    'r17_detalle_fotos_decoder' => _checkFile(
        $base . '/admin/php/checklists/detalle.php',
        '_detalleDecodePhotos(',
        'Round 17 — detalle.php expone arrays de URLs de fotos por columna'
    ),
    'r17_ensamble_dealer_snapshot' => _checkFile(
        $base . '/admin/php/checklists/guardar-ensamble.php',
        'dealer_nombre_snapshot',
        'Round 17 — guardar-ensamble.php captura snapshot del nombre del dealer'
    ),
    'r17_entrega_dealer_snapshot' => _checkFile(
        $base . '/admin/php/checklists/guardar-entrega.php',
        'dealer_nombre_snapshot',
        'Round 17 — guardar-entrega.php captura snapshot del nombre del dealer'
    ),
    'r17_ventas_drill_in' => _checkFile(
        $base . '/admin/js/modules/admin-ventas.js',
        'renderChecklistDetail(',
        'Round 17 — admin-ventas.js: drill-in con items + fotos + autor + timestamp'
    ),
    // ── Round 18 (2026-05-14) — Credit: sign BEFORE enganche payment ───────
    'r18_create_pi_gate' => _checkFile(
        $base . '/configurador/php/create-payment-intent.php',
        "'error'     => 'firma_requerida'",
        'Round 18 — create-payment-intent.php bloquea PI de enganche sin firma del contrato'
    ),
    'r18_confirmar_gate' => _checkFile(
        $base . '/configurador/php/confirmar-orden.php',
        "'pendiente_firma'",
        'Round 18 — confirmar-orden.php marca pendiente_firma si crédito sin firma'
    ),
    'r18_generar_updates_tx' => _checkFile(
        $base . '/configurador/php/generar-contrato-pdf.php',
        "AND tpago IN ('credito','enganche','parcial','credito-orfano')",
        'Round 18 — generar-contrato-pdf.php actualiza transacciones.contrato_pdf_path al firmar'
    ),
    'r18_firma_status_endpoint' => _checkFile(
        $base . '/configurador/php/firma-credito-status.php',
        'firma_sha256 IS NOT NULL',
        'Round 18 — firma-credito-status.php nuevo endpoint para chequeo SPA'
    ),
    'r18_enganche_entry_check' => _checkFile(
        $base . '/configurador/js/modules/paso-credito-enganche.js',
        'FIRMA_STATUS_URL',
        'Round 18 — paso-credito-enganche.js verifica firma al entrar y redirige a contrato si falta'
    ),
    'r18_enganche_goto_autopago' => _checkFile(
        $base . '/configurador/js/modules/paso-credito-enganche.js',
        "self.app.irAPaso('credito-autopago')",
        'Round 18 — paso-credito-enganche.js: post-pago va a credito-autopago (firma ya hecha)'
    ),
    'r18_contrato_goto_enganche' => _checkFile(
        $base . '/configurador/js/modules/paso-credito-contrato.js',
        "self.app.irAPaso('credito-enganche')",
        'Round 18 — paso-credito-contrato.js: post-firma va a credito-enganche'
    ),
    'r18_panel_sin_firma_pendiente' => _checkFile(
        $base . '/admin/php/ventas/credito-sin-firma.php',
        "'pendiente_firma'",
        'Round 18 — Panel Sin Firma muestra órdenes en estado pendiente_firma'
    ),
    // ── Round 19 (2026-05-14) — Documentos modal: contract title accuracy ───
    'r19_listar_returns_pdf_path' => _checkFile(
        $base . '/admin/php/ventas/listar.php',
        't.contrato_pdf_path,',
        'Round 19 — listar.php devuelve t.contrato_pdf_path al SPA admin'
    ),
    'r19_ventas_dynamic_contract_title' => _checkFile(
        $base . '/admin/js/modules/admin-ventas.js',
        'contratoFirmado',
        'Round 19 — Documentos modal: título del contrato refleja estado real (firmado vs pendiente de firma)'
    ),
    'r19v3_contado_inclusive_signals' => _checkFile(
        $base . '/admin/js/modules/admin-ventas.js',
        'Round 19 v3',
        'Round 19 v3 — CONTADO acepta tx_pago_estado / moto.pago_estado / stripe_pi / contrato_pdf_path como señal de firmado'
    ),
    // ── Round 20 (2026-05-14) — Identidad section: photos visible ──────────
    'r20_listar_returns_files_saved' => _checkFile(
        $base . '/admin/php/preaprobaciones/listar.php',
        'vi.files_saved       AS truora_files_saved',
        'Round 20 — preaprobaciones/listar.php expone vi.files_saved al SPA admin'
    ),
    'r20_ventas_renders_photos' => _checkFile(
        $base . '/admin/js/modules/admin-ventas.js',
        'truora_files_saved',
        'Round 20 — Documentos modal Identidad: parsea files_saved y muestra thumbnails INE + selfie'
    ),
    // ── Round 21 (2026-05-14) — Sync Truora data on-demand ─────────────────
    'r21_sync_truora_endpoint' => _checkFile(
        $base . '/admin/php/preaprobaciones/sync-truora.php',
        'truoraFindProcessByAccountId',
        'Round 21 — sync-truora.php endpoint: backfill Truora data + descarga fotos'
    ),
    'r21_sync_button' => _checkFile(
        $base . '/admin/js/modules/admin-ventas.js',
        'Sincronizar con Truora',
        'Round 21 — admin-ventas.js: botón "Sincronizar con Truora" en sección Identidad'
    ),
    'r21v2_recursive_search' => _checkFile(
        $base . '/admin/php/preaprobaciones/sync-truora.php',
        '_recursiveFind',
        'Round 21 v2 — sync-truora.php: búsqueda recursiva para name_match / manual_review / approved'
    ),
    'r21v2_attached_documents' => _checkFile(
        $base . '/admin/php/preaprobaciones/sync-truora.php',
        '/attached_documents',
        'Round 21 v2 — sync-truora.php: prueba endpoints /attached_documents y /documents de Truora'
    ),
    'r21v2_admin_rejected_fallback' => _checkFile(
        $base . '/admin/js/modules/admin-ventas.js',
        'no aplicable (rechazado)',
        'Round 21 v2 — admin-ventas.js: campos null muestran "no aplicable (rechazado)" cuando el proceso fue rechazado'
    ),
    'r21v4_result_endpoint' => _checkFile(
        $base . '/admin/php/preaprobaciones/sync-truora.php',
        "'/v1/processes/' . urlencode(\$processId) . '/result'",
        'Round 21 v4 — sync-truora.php: incluye /v1/processes/<id>/result como image source (donde viven front_image/reverse_image)'
    ),
    'r21v4_no_downgrade' => _checkFile(
        $base . '/admin/php/preaprobaciones/sync-truora.php',
        '$wasApproved',
        'Round 21 v4 — sync-truora.php: NUNCA degrada success → failure (preserva la aprobación histórica)'
    ),
    'r21v4_debug_page' => _checkFile(
        $base . '/admin/php/preaprobaciones/truora-payload-debug.php',
        'Truora payload debug',
        'Round 21 v4 — truora-payload-debug.php: visualizador de respuesta cruda y fetch_log'
    ),
    'r21v5_verif_id_targeting' => _checkFile(
        $base . '/admin/php/preaprobaciones/sync-truora.php',
        '$verifId = (int)($body[\'verif_id\'] ?? 0)',
        'Round 21 v5 — sync-truora.php apunta a la row exacta (verif_id) en lugar de la más reciente por teléfono'
    ),
    'r21v5_no_s3_auth_header' => _checkFile(
        $base . '/admin/php/preaprobaciones/sync-truora.php',
        'AWS S3 signed URLs reject extra Authorization headers',
        'Round 21 v5 — sync-truora.php: NO envía Truora-API-Key al bajar de S3 (rompía la firma)'
    ),
    'r21v5_debug_info' => _checkFile(
        $base . '/admin/php/preaprobaciones/sync-truora.php',
        "'_debug'            => \$debugInfo",
        'Round 21 v5 — sync-truora.php devuelve _debug con sources/classified/downloads'
    ),
    'r21v5_listar_verif_id' => _checkFile(
        $base . '/admin/php/preaprobaciones/listar.php',
        'vi.id                AS verif_id',
        'Round 21 v5 — listar.php expone vi.id para que el Sync targetee la row exacta'
    ),
    // ── Round 22 (2026-05-14) — Webhook auto-captures photos for new customers ─
    'r22_webhook_capture' => _checkFile(
        $base . '/configurador/php/truora-webhook.php',
        'truoraCaptureProcessPhotos',
        'Round 22 — Webhook descarga fotos INE+selfie inmediatamente al recibir éxito de Truora (URLs frescas <15min)'
    ),
    'r23_sales_panel_firmado_consistency' => _checkFile(
        $base . '/admin/js/modules/admin-ventas.js',
        '_hasContractPdf',
        'Round 23 — Sales panel: "Firmado" badge requiere contrato_pdf_path (no solo firmas_contratos residual)'
    ),
    'r23v2_credit_only_strict' => _checkFile(
        $base . '/admin/js/modules/admin-ventas.js',
        '_isCreditTipo ? (r.firma_id && _hasContractPdf) : !!r.firma_id',
        'Round 23 v2 — Sales panel: chequeo estricto solo en CREDIT; CONTADO usa solo firma_id (legacy compat)'
    ),
    'r23v3_firmado_requires_paid' => _checkFile(
        $base . '/admin/js/modules/admin-ventas.js',
        '(r.firma_id && _hasContractPdf && _isPaid)',
        'Round 23 v3 — "Firmado" badge requiere pago confirmado (no solo firma_id residual)'
    ),
    'r24_puntos_hide_inactive' => _checkFile(
        $base . '/admin/php/puntos/listar.php',
        'WHERE pv.activo = 1',
        'Round 24 — Admin Puntos list oculta puntos inactivos por defecto (?include_inactive=1 para verlos)'
    ),
    'r25_punto_search_input' => _checkFile(
        $base . '/admin/js/modules/admin-ventas.js',
        'vkAsignPuntoSearch',
        'Round 25 — Modal "Asignar punto de entrega" tiene buscador en vivo (filtra nombre/ciudad/dirección)'
    ),
    'r25v3_moto_search_input' => _checkFile(
        $base . '/admin/js/modules/admin-ventas.js',
        'vtMotosSearch',
        'Round 25 v3 — Modal "Asignar moto" tiene buscador en vivo (filtra VIN/modelo/color/estado/punto)'
    ),
    'r26_origen_notify_moto_recibida' => _checkFile(
        $base . '/admin/php/checklists/guardar-origen.php',
        "voltikaNotify('moto_recibida'",
        'Round 26 — guardar-origen.php dispara WhatsApp+email+SMS "moto_recibida" al completar el checklist'
    ),
    'r27_punto_venta_directa_removed' => _checkFile(
        $base . '/puntosvoltika/js/modules/punto-venta.js',
        'Round 27 (2026-05-14, Óscar): showDirectSaleModal removed',
        'Round 27 — PUNTOVOLTIKA: botón "Venta directa" y modal removidos (ventas solo via configurador)'
    ),
    'r27v2_inicio_code_removed' => _checkFile(
        $base . '/puntosvoltika/js/modules/punto-inicio.js',
        'Round 27 v2 (2026-05-14, Óscar Inicio screenshot)',
        'Round 27 v2 — PUNTOVOLTIKA Inicio: linea "Venta directa en tienda: <código>" removida'
    ),
    'r28_detalle_retencion_meta' => _checkFile(
        $base . '/admin/php/inventario/detalle.php',
        "'retencion' => (strtolower",
        'Round 28 — detalle.php parsea log_estados y expone retencion (quién/cuándo/notas)'
    ),
    'r28_admin_retencion_section' => _checkFile(
        $base . '/admin/js/modules/admin-inventario.js',
        'Retención operativa',
        'Round 28 — admin-inventario.js: sección "Retención operativa" + botón "Liberar moto"'
    ),
    'r28_admin_liberar_handler' => _checkFile(
        $base . '/admin/js/modules/admin-inventario.js',
        'function liberarMoto',
        'Round 28 — admin-inventario.js: handler liberarMoto() llama al endpoint admin-side'
    ),
    'r28v2_admin_liberar_endpoint' => _checkFile(
        $base . '/admin/php/inventario/liberar-moto.php',
        "adminRequireAuth(['admin','cedis'])",
        'Round 28 v2 — endpoint admin/php/inventario/liberar-moto.php (admin session, fix "No autenticado")'
    ),
    'r29_checklists_vin_search' => _checkFile(
        $base . '/admin/js/modules/admin-checklists.js',
        'clVinSearch',
        'Round 29 — Checklists page: buscador VIN con debounce (server-side LIKE)'
    ),
    'r30_recepcion_foto_lightbox' => _checkFile(
        $base . '/puntosvoltika/js/modules/punto-recepcion.js',
        'showFotoLightbox',
        'Round 30 — PUNTOVOLTIKA recepción historial: fotos abren en lightbox modal (no más botones muertos)'
    ),
    'r30v2_recepcion_serve_foto' => _checkFile(
        $base . '/puntosvoltika/php/recepcion/serve-foto.php',
        'puntoRequireAuth',
        'Round 30 v2 — serve-foto.php sirve fotos vía PHP (bypassa .htaccess de Plesk)'
    ),
    'r30v2_historial_rewrite' => _checkFile(
        $base . '/puntosvoltika/php/recepcion/historial.php',
        'rewritePhoto',
        'Round 30 v2 — historial.php reescribe URLs legacy a serve-foto.php'
    ),
    'r30v4_recibir_fallback' => _checkFile(
        $base . '/puntosvoltika/php/recepcion/recibir.php',
        '_puntoResolveUploadDir',
        'Round 30 v4 — recibir.php tiene fallback /tmp para evitar fallos silenciosos cuando Plesk bloquea mkdir'
    ),
    'r30v4_serve_multi_location' => _checkFile(
        $base . '/puntosvoltika/php/recepcion/serve-foto.php',
        'Round 30 v4: recibir.php now tries multiple writable upload locations',
        'Round 30 v4 — serve-foto.php busca fotos en las 3 ubicaciones candidatas'
    ),
    'r30v5_lightbox_user_friendly' => _checkFile(
        $base . '/puntosvoltika/js/modules/punto-recepcion.js',
        '¿Cómo lo arreglo?',
        'Round 30 v5 — Lightbox muestra mensaje amable + pasos para re-subir la foto (detalles técnicos en toggle)'
    ),
    'r31_admin_detalle_recepcion' => _checkFile(
        $base . '/admin/php/inventario/detalle.php',
        "'recepcion' => \$recepcion",
        'Round 31 — detalle.php expone recepcion_punto con fotos rewriteadas a admin serve'
    ),
    'r31_admin_serve_recepcion_foto' => _checkFile(
        $base . '/admin/php/inventario/serve-recepcion-foto.php',
        "adminRequireAuth(['admin','cedis','operador'])",
        'Round 31 — serve-recepcion-foto.php sirve fotos con admin auth + 3 candidate dirs'
    ),
    'r31_admin_inv_recepcion_section' => _checkFile(
        $base . '/admin/js/modules/admin-inventario.js',
        'Recepción en el punto',
        'Round 31 — admin-inventario.js: sección "Recepción en el punto" con checks + fotos + actor'
    ),
    'r32_inv_row_clickable_sticky' => _checkFile(
        $base . '/admin/js/modules/admin-inventario.js',
        'adInvRow',
        'Round 32 — Inventario rows clicables + columna "Ver" sticky right (no se corta en pantallas angostas)'
    ),
    'r31v2_admin_recepcion_missing_photos' => _checkFile(
        $base . '/admin/js/modules/admin-inventario.js',
        'fotos_missing_count',
        'Round 31 v2 — Recepción section: fotos faltantes muestran placeholder amable + instrucciones para re-subir'
    ),
    'r33_admin_upload_recepcion_foto' => _checkFile(
        $base . '/admin/php/inventario/upload-recepcion-foto.php',
        "adminRequireAuth(['admin','cedis'])",
        'Round 33 — endpoint para subir foto de recepción desde admin (reemplaza placeholder)'
    ),
    'r33_admin_clickable_placeholder' => _checkFile(
        $base . '/admin/js/modules/admin-inventario.js',
        'uploadRecepcionFoto',
        'Round 33 — placeholders clicables abren file picker + suben reemplazo'
    ),
    // ── Round 34 (2026-05-14) — Cambiar tarjeta: auto-recovery customer ───
    'r34_cambiar_tarjeta_recovery' => _checkFile(
        $base . '/clientes/php/pagos/cambiar-tarjeta.php',
        '_voltikaCreateStripeCustomer',
        'Round 34 — cambiar-tarjeta.php: si stripe_customer_id está stale (No such customer), crea uno nuevo + persiste + reintenta'
    ),
    'r34_cambiar_tarjeta_retry' => _checkFile(
        $base . '/clientes/php/pagos/cambiar-tarjeta.php',
        '_voltikaCambiarTarjetaCreateSession',
        'Round 34 — cambiar-tarjeta.php: helper de creación de Checkout Session refactorizado para reintento'
    ),
    // ── Round 35 (2026-05-14) — Punto: sesión 2h + manejo amable de 401 ───
    'r35_punto_session_lifetime' => _checkFile(
        $base . '/puntosvoltika/php/bootstrap.php',
        "ini_set('session.gc_maxlifetime', '7200')",
        'Round 35 — bootstrap punto: vida de sesión a 2 horas (evita "No autorizado" al llenar recepción larga)'
    ),
    'r35_punto_recepcion_401_handler' => _checkFile(
        $base . '/puntosvoltika/js/modules/punto-recepcion.js',
        'Tu sesión expiró mientras llenabas el formulario',
        'Round 35 — punto-recepcion.js: 401 muestra mensaje amable + auto-prompt de login (datos del form se preservan)'
    ),
    // ── Round 36 (2026-05-14) — Ensamble: fotos obligatorias al completar ─
    'r36_ensamble_photo_mandatory' => _checkFile(
        $base . '/puntosvoltika/php/checklists/guardar-ensamble.php',
        'Para finalizar el checklist debes subir al menos una foto en cada fase',
        'Round 36 — guardar-ensamble.php: bloquea completado si falta foto en fase 1/2/3 (mid-fase saves no afectados)'
    ),
    // ── Round 37 (2026-05-14) — Truora: dejar de marcar "No coincide" falso ─
    'r37_truora_no_false_mismatch' => _checkFile(
        $base . '/admin/php/preaprobaciones/sync-truora.php',
        '$isMismatchRejection',
        'Round 37 — sync-truora.php: solo coerce match flags a 0 si hay evidencia semántica de mismatch (no para expired/in_progress)'
    ),
    // ── Round 38 (2026-05-14) — Entrega: botón Reenviar OTP ───────────────
    'r38_entrega_resend_otp' => _checkFile(
        $base . '/puntosvoltika/js/modules/punto-entrega.js',
        '¿No le llegó el SMS al cliente? Reenviar código',
        'Round 38 — punto-entrega.js: botón "Reenviar código" en step 2 cuando SMS no llega'
    ),
    // ── Round 39 (2026-05-14) — Cambiar tarjeta: recovery sin email + diagnóstico ─
    'r39_cambiar_tarjeta_diagnostic' => _checkFile(
        $base . '/clientes/php/pagos/cambiar-tarjeta.php',
        "Round 39 (2026-05-14",
        'Round 39 — cambiar-tarjeta.php: recovery acepta clientes sin email (Stripe lo pide en Checkout) + razón de fallo específica al cliente'
    ),
    // ── Round 40 (2026-05-16) — RESUMEN BURO: 3 bugs en display de Truora ───
    'r40a_estado_truora_label' => _checkFile(
        $base . '/admin/js/modules/admin-preaprobaciones.js',
        "dataRow('Estado Truora', truoraStatusBadge(row))",
        'Round 40A — admin-preaprobaciones.js: label "Truora ID" mal usado → ahora dice "Estado Truora" + fila separada con el process_id real'
    ),
    'r40a_curp_incomplete_label' => _checkFile(
        $base . '/admin/js/modules/admin-preaprobaciones.js',
        'Verificación incompleta',
        'Round 40A — admin-preaprobaciones.js: si status=expired/in_progress muestra "Verificación incompleta" en CURP match en vez de "X No coincide"'
    ),
    'r40b_badge_priority' => _checkFile(
        $base . '/admin/js/modules/admin-preaprobaciones.js',
        'Link Truora expiró',
        'Round 40B — truoraStatusBadge: chequea status=expired/in_progress ANTES de curp_match===0 (evita "X CURP no coincide" falso)'
    ),
    'r40c_reverify_button' => _checkFile(
        $base . '/admin/js/modules/admin-ventas.js',
        '🔄 Re-verificar con Truora',
        'Round 40C — admin-ventas.js: dentro del recuadro rojo "Truora rechazó" hay un botón Re-verificar (antes no había salida si admin sospechaba false-positive)'
    ),
    // ── Round 41 (2026-05-16) — Venta por referido: quitar botón Eliminar ──
    'r41_venta_referido_no_delete' => _checkFile(
        $base . '/puntosvoltika/js/modules/punto-venta.js',
        'Round 41 (2026-05-16, Óscar — operator deleted 4 motos by accident)',
        'Round 41 — punto-venta.js: el botón 🗑 Eliminar fue removido de "Motos libres en tu inventario" (deletes por accidente)'
    ),
    'r41_restore_tool' => _checkFile(
        $base . '/admin/php/inventario/restaurar-recientes.php',
        'Voltika Admin — Round 41 recovery tool',
        'Round 41 — herramienta admin one-shot: lista + restaura motos eliminadas en las últimas N horas'
    ),
    // ── Round 42 (2026-05-16) — Firma del contrato: 5 bugs en cadena ──────
    'r42a_firma_null_check' => _checkFile(
        $base . '/configurador/js/modules/paso-credito-contrato.js',
        'Tu firma no se capturó. Dibuja tu firma en el recuadro antes de continuar',
        'Round 42A — paso-credito-contrato.js: bloquea submit si _getSignatureData() devuelve null (cliente veía contratoFirmado=true y luego "firma_requerida" en enganche)'
    ),
    'r42b_canvas_min_width' => _checkFile(
        $base . '/configurador/js/modules/paso-credito-contrato.js',
        'floor: prevents invisible signatures on tiny viewports',
        'Round 42B — paso-credito-contrato.js: canvas mínimo 280px de ancho (móvil estrecho deja canvas en ~1px → firma invisible)'
    ),
    'r42c_firma_error_no_advance' => _checkFile(
        $base . '/configurador/js/modules/paso-credito-contrato.js',
        'we now surface the real failure here and DO NOT advance the wizard',
        'Round 42C — paso-credito-contrato.js: en error AJAX NO marca contratoFirmado=true (antes lo hacía y enganche bloqueaba con "firma_requerida" confuso)'
    ),
    'r42d_firma_strict_decode' => _checkFile(
        $base . '/configurador/php/generar-contrato-pdf.php',
        'strict mode — false on any non-base64 char',
        'Round 42D — generar-contrato-pdf.php: base64_decode estricto + size check + log de write fail (antes generaba PNG 0 bytes silencioso)'
    ),
    'r42e_pdf_aspect_ratio' => _checkFile(
        $base . '/configurador/php/generar-contrato-pdf.php',
        'Passing 0 as the height preserves the',
        'Round 42E — generar-contrato-pdf.php: $pdf->Image(...,60,0) preserva el aspect ratio del canvas (320×120) → firma ya no sale comprimida horizontalmente'
    ),
    // ── Round 43 (2026-05-16) — Entrega: revelación presencial de OTP ──────
    'r43_revelar_otp_endpoint' => _checkFile(
        $base . '/puntosvoltika/php/entrega/revelar-otp.php',
        'entrega_otp_revelaciones',
        'Round 43 — revelar-otp.php: endpoint para revelar OTP en pantalla cuando SMS no llega (cliente físicamente presente). Audit-log obligatorio.'
    ),
    'r43_entrega_reveal_btn' => _checkFile(
        $base . '/puntosvoltika/js/modules/punto-entrega.js',
        '🚨 Cliente en el punto pero no recibe SMS',
        'Round 43 — punto-entrega.js: botón "Verificar con código en pantalla" en step 2 como último recurso para entrega presencial.'
    ),
    // ── Round 44 (2026-05-16) — Punto: heartbeat para sesión larga ─────────
    'r44_heartbeat_endpoint' => _checkFile(
        $base . '/puntosvoltika/php/auth/heartbeat.php',
        'Session keep-alive heartbeat',
        'Round 44 — heartbeat.php: endpoint que refresca el cookie de sesión cada vez que se llama (sliding window)'
    ),
    'r44_heartbeat_js' => _checkFile(
        $base . '/puntosvoltika/js/punto-app.js',
        'startSessionHeartbeat',
        'Round 44 — punto-app.js: JS hace ping cada 3 min a auth/heartbeat.php — el operador no pierde sesión durante Ensamble largo (48 items + fotos)'
    ),
    // ── Round 45 (2026-05-16) — CDC: nueva contraseña ──────────────────────
    'r45_cdc_password' => _checkFile(
        $base . '/configurador/php/config.php',
        "Round 45 (2026-05-16, Óscar via Slack",
        'Round 45 — config.php: nuevo fallback de CDC_PASS = "VoltiK2026#$" (env CDC_PASS sigue teniendo prioridad si está set en Plesk)'
    ),
    // ── Round 47 (2026-05-16) — SMSmasivos: apikey + form (no Bearer/JSON) ─
    'r47_notify_apikey_header' => _checkFile(
        $base . '/configurador/php/voltika-notify.php',
        "'apikey: ' . \$smsKey",
        'Round 47 — voltikaSendSMS(): apikey + form-urlencoded + body.success check (antes mandaba Bearer/JSON → auth_01 401 silencioso)'
    ),
    'r47_iniciar_apikey' => _checkFile(
        $base . '/puntosvoltika/php/entrega/iniciar.php',
        "'apikey: ' . \$smsKey",
        'Round 47 — entrega/iniciar.php: misma corrección de apikey + body.success para evitar false-positive en sms_ok'
    ),
    'r47_admin_bootstrap_apikey' => _checkFile(
        $base . '/admin/php/bootstrap.php',
        "'apikey: ' . \$smsKey",
        'Round 47 — admin/bootstrap: corrección de apikey + form'
    ),
    'r47_verificar_sms_apikey' => _checkFile(
        $base . '/admin/php/verificar-sms.php',
        "'apikey: ' . \$smsKey",
        'Round 47 — verificar-sms.php diagnóstico ahora valida body.success'
    ),
    'r47_diagnostico_sms_apikey' => _checkFile(
        $base . '/admin/php/diagnostico-sms.php',
        "'apikey: ' . \$smsKey",
        'Round 47 — diagnostico-sms.php usa el patrón correcto + reporta auth_01 explícitamente'
    ),
    'r47_test_punto_apikey' => _checkFile(
        $base . '/puntosvoltika_test/php/entrega/iniciar.php',
        "'apikey: ' . \$smsKey",
        'Round 47 — entrega test endpoint también corregido (paridad con producción)'
    ),
    // ── Round 48 (2026-05-16) — Roles: bloqueo de auto-democión ────────────
    'r48_self_demotion_guard' => _checkFile(
        $base . '/admin/php/roles/guardar.php',
        'self_demotion_blocked',
        'Round 48 — roles/guardar.php: un admin NO puede cambiar su propio rol a un valor distinto de "admin" (previene el bloqueo accidental del 2026-05-16)'
    ),
    // ── Round 49 (2026-05-16) — CDC_PASS hardcoded, env-var ignored ────────
    'r52_cdc_pass_env_only' => _checkFile(
        $base . '/configurador/php/config.php',
        "Round 52 (2026-05-17, Óscar): revert to env-var-only pattern",
        'Round 52 — config.php: CDC_PASS leído SOLO de env var (sin hardcoded). Cambiar contraseña ahora requiere solo actualizar Plesk env, no redeploy.'
    ),
];

// Live runtime checks — sanity-test the actual responses
$runtimeChecks = [];

// Check recover-truora.php produces redirect with ?paso=
try {
    $url = 'https://' . $_SERVER['HTTP_HOST'] . '/configurador/recover-truora.php?t=invalid';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // Token is invalid, but the file itself should produce expected error
    $runtimeChecks['recover_truora_reachable'] = [
        'ok' => $http > 0 && strpos((string)$body, 'recover-truora') !== false || $http >= 400,
        'http' => $http,
        'note' => 'Endpoint alcanzable (con token inválido, esperamos 400/403 — solo confirmamos que el archivo responde)',
    ];
} catch (Throwable $e) { $runtimeChecks['recover_truora_reachable'] = ['ok'=>false, 'error'=>$e->getMessage()]; }

// Stat the SPA bundle for cache-bust indicator
$spaJsPath = $base . '/configurador/js/configurador.js';
if (file_exists($spaJsPath)) {
    $runtimeChecks['spa_mtime'] = [
        'ok' => true,
        'mtime' => date('Y-m-d H:i:s', filemtime($spaJsPath)),
        'size_kb' => round(filesize($spaJsPath) / 1024, 1),
        'note' => 'Si esta fecha es nueva, el archivo se subió. Si el navegador no ve el cambio, hace falta Ctrl+Shift+R o purgar CDN.',
    ];
}

// Check if PHP OPcache is enabled — it may be caching old code
$opcacheStatus = function_exists('opcache_get_status') ? opcache_get_status(false) : null;
$runtimeChecks['opcache'] = [
    'enabled'    => $opcacheStatus['opcache_enabled'] ?? false,
    'note'       => ($opcacheStatus['opcache_enabled'] ?? false)
                  ? 'OPcache ACTIVO — si subiste un .php pero no ves el cambio, hay que resetear OPcache o esperar revalidación'
                  : 'OPcache desactivado — cambios .php se aplican inmediato',
];

// Allow one-click OPcache reset (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = adminJsonIn();
    if (($body['action'] ?? '') === 'reset_opcache') {
        if (function_exists('opcache_reset')) {
            $ok = @opcache_reset();
            adminLog('verificar_deploy_opcache_reset', ['ok' => $ok]);
            echo json_encode(['ok' => $ok, 'message' => $ok ? 'OPcache reseteado' : 'No se pudo resetear']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'opcache_reset no disponible']);
        }
        exit;
    }
    echo json_encode(['ok' => false, 'error' => 'acción desconocida']);
    exit;
}

$allOk = true;
foreach ($checks as $c) { if (!$c['ok']) { $allOk = false; break; } }

header('Content-Type: text/html; charset=UTF-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Voltika — Verificación de despliegue</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:980px;margin:0 auto;}
h1{font-size:22px;margin:0 0 4px;}
h2{font-size:14px;color:#475569;margin:24px 0 10px;text-transform:uppercase;letter-spacing:.4px;}
.sub{color:#64748b;font-size:13px;margin-bottom:18px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:12px;}
.row{display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid #f1f5f9;}
.row:last-child{border-bottom:0;}
.status{font-size:24px;line-height:1;width:32px;text-align:center;}
.status.ok{color:#16a34a;} .status.bad{color:#dc2626;}
.desc{flex:1;}
.title{font-weight:700;font-size:14px;color:#0c2340;}
.path{font-size:11px;color:#64748b;font-family:ui-monospace,monospace;margin-top:2px;word-break:break-all;}
.meta{font-size:11px;color:#94a3b8;margin-top:4px;}
.alert{padding:12px 14px;border-radius:8px;font-size:13px;margin-bottom:14px;}
.alert-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.alert-err{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.btn{background:#039fe1;color:#fff;border:0;padding:9px 16px;border-radius:6px;font-weight:600;cursor:pointer;font-size:13px;}
code{background:#1e293b;color:#e2e8f0;padding:1px 6px;border-radius:3px;font-size:11px;}
ul{margin:0;padding-left:18px;font-size:13px;line-height:1.7;}
</style></head><body>

<h1>🚀 Verificación de despliegue — Round 14 → 22 (2026-05-13 / 2026-05-14)</h1>
<div class="sub">Confirma que los archivos modificados llegaron al servidor con la versión correcta.</div>

<?php if ($allOk): ?>
  <div class="alert alert-ok">
    <strong>✅ Todos los archivos están desplegados correctamente.</strong> El servidor ya tiene el código nuevo.
    Si todavía ves el comportamiento viejo en el navegador → es caché del navegador (Ctrl+Shift+R) o CDN.
  </div>
<?php else: ?>
  <div class="alert alert-err">
    <strong>⚠ Algunos archivos no tienen el código nuevo.</strong> Revisa abajo cuál falta — quizás
    no se subió o PHP OPcache sigue sirviendo la versión anterior.
  </div>
<?php endif; ?>

<h2>1. Archivos modificados</h2>
<div class="card">
  <?php foreach ($checks as $key => $c): ?>
    <div class="row">
      <div class="status <?= $c['ok'] ? 'ok' : 'bad' ?>"><?= $c['ok'] ? '✓' : '✗' ?></div>
      <div class="desc">
        <div class="title"><?= htmlspecialchars($c['desc']) ?></div>
        <div class="path"><?= htmlspecialchars($c['path']) ?></div>
        <div class="meta">
          <strong>Estado:</strong> <?= htmlspecialchars($c['reason']) ?>
          <?php if (isset($c['size_bytes'])): ?>
            · <strong>Tamaño:</strong> <?= number_format($c['size_bytes']) ?> bytes
            · <strong>Modificado:</strong> <?= htmlspecialchars($c['mtime']) ?>
          <?php endif; ?>
        </div>
        <?php if (isset($c['marker'])): ?>
          <details style="margin-top:6px;">
            <summary style="cursor:pointer;font-size:11px;color:#94a3b8;">Marcador buscado</summary>
            <code><?= htmlspecialchars($c['marker']) ?></code>
          </details>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<h2>2. Verificación runtime</h2>
<div class="card">
  <div class="row">
    <div class="status <?= ($runtimeChecks['opcache']['enabled'] ?? false) ? 'bad' : 'ok' ?>">
      <?= ($runtimeChecks['opcache']['enabled'] ?? false) ? '⚠' : '✓' ?>
    </div>
    <div class="desc">
      <div class="title">PHP OPcache</div>
      <div class="meta">
        <?= htmlspecialchars($runtimeChecks['opcache']['note'] ?? '') ?>
      </div>
      <?php if ($runtimeChecks['opcache']['enabled'] ?? false): ?>
        <div style="margin-top:8px;">
          <button class="btn" id="btnResetOpcache" style="background:#d97706;">🔄 Resetear OPcache ahora</button>
          <span id="resetResult" style="margin-left:10px;font-size:12px;color:#475569;"></span>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (isset($runtimeChecks['spa_mtime'])): ?>
    <div class="row">
      <div class="status ok">📅</div>
      <div class="desc">
        <div class="title">SPA configurador.js — última modificación</div>
        <div class="meta">
          <strong><?= htmlspecialchars($runtimeChecks['spa_mtime']['mtime']) ?></strong>
          (<?= $runtimeChecks['spa_mtime']['size_kb'] ?> KB) — <?= htmlspecialchars($runtimeChecks['spa_mtime']['note']) ?>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<h2>3. Pruebas manuales — checklist</h2>
<div class="card" style="font-size:13.5px;line-height:1.7;">
  <strong>Issue 1 — Link de Truora del email:</strong>
  <ul>
    <li>Pide al boss que mande otro link a un cliente con preaprobación.</li>
    <li>Cliente abre el email y da click en <strong>"Continuar verificación"</strong>.</li>
    <li>✅ Esperado: aterriza directo en <strong>verificación de identidad de Truora</strong> (foto INE + selfie), no en el selector de modelo.</li>
    <li>URL en la barra debe terminar en <code>?recovered=1&amp;paso=credito-identidad</code></li>
  </ul>

  <strong style="display:block;margin-top:14px;">Issue 2 — Mis compras duplicadas:</strong>
  <ul>
    <li>Pide al cliente con duplicados (la cliente del screenshot anterior) que entre a <code>voltika.mx/clientes/</code></li>
    <li>Click en <strong>Mis compras</strong> en el menú inferior.</li>
    <li>✅ Esperado: <strong>una sola card</strong> de M05 negro crédito (no dos).</li>
    <li>Si necesitas comparar — la API ahora devuelve <code>total_raw: 2</code> y <code>total: 1</code> mostrando que dedupeó.</li>
  </ul>

  <strong style="display:block;margin-top:14px;">Issue 3 — Excel CDC:</strong>
  <ul>
    <li>Login admin → sección <strong>Consultas Buró</strong> → click en <strong>Exportar Excel</strong>.</li>
    <li>✅ Esperado: 16 columnas en orden oficial NIP-CIEC PF (FOLIO_CDC, FECHA_APROBACION_DE_CONSULTA, …, ACEPTACION_TERMINOS_Y_CONDICIONES).</li>
    <li>Columna <strong>I</strong> debe llamarse <code>Estado</code> con <strong>e minúscula</strong> (no <code>ESTADO</code>).</li>
    <li><code>NOMBRE_CLIENTE</code> en formato <code>APELLIDO_PATERNO APELLIDO_MATERNO NOMBRES</code> MAYÚSCULAS.</li>
  </ul>

  <strong style="display:block;margin-top:14px;">Round 21 — Sincronizar con Truora (backfill on-demand):</strong>
  <ul>
    <li>Admin → orden con datos parciales de Truora (ej. <strong>VK-1826-0001 Carlos Ricardo Sánchez</strong>) → "Identidad (INE / PASSPORT)" → desplegar.</li>
    <li>✅ Si faltan campos (status / process_id / name_match / fotos) aparece bloque azul con botón <strong>"🔄 Sincronizar con Truora"</strong>.</li>
    <li>Click → llama a Truora API con el <code>account_id</code> guardado → resuelve process_id → descarga payload completo + intenta bajar fotos INE/selfie.</li>
    <li>Tras éxito: tabla se refresca con datos nuevos (status, name_match, verified_name, verified_curp, manual_review) + thumbnails de fotos descargadas.</li>
    <li>Si Truora no devuelve nada (cliente abandonó el flujo): mensaje claro sin fallar.</li>
    <li>Audit trail: tabla <code>truora_fetch_log</code> registra cada llamada; <code>verificaciones_identidad.raw_truora_payload</code> guarda el JSON crudo.</li>
  </ul>

  <strong style="display:block;margin-top:14px;">Round 20 — Identidad section: fotos INE + selfie visibles:</strong>
  <ul>
    <li>Admin → Ventas → cualquier orden de crédito (ej. <strong>VK-1826-0001</strong>) → "Documentos del pedido" → desplegar <strong>"Identidad (INE / PASSPORT)"</strong>.</li>
    <li>✅ Si Truora capturó las fotos: ahora se muestran <strong>3 thumbnails clicables</strong> (INE frente, INE reverso, Selfie) en grid debajo de la tabla de campos. Click en cualquiera → abre la imagen completa en nueva pestaña.</li>
    <li>✅ Si la verificación está incompleta: se muestra un mensaje explicativo en lugar de quedar la sección vacía.</li>
    <li>Las URLs son <code>/configurador/php/uploads/{filename}</code> derivadas del JSON <code>verificaciones_identidad.files_saved</code>.</li>
  </ul>

  <strong style="display:block;margin-top:14px;">Round 19 — Documentos modal: título del contrato consistente:</strong>
  <ul>
    <li>Admin → Ventas → cualquier orden de crédito SIN firma (ej. <strong>VK-1826-0001 Carlos Ricardo Sánchez</strong>) → "Documentos del pedido".</li>
    <li>✅ La primera tarjeta debe decir <strong>"Contrato de financiamiento (pendiente de firma)"</strong> con icono ⏳ y fondo amarillo — no <strong>"(firmado)"</strong>.</li>
    <li>✅ Pedidos con contrato realmente firmado siguen mostrando <strong>"(firmado)"</strong> en azul.</li>
    <li>Pedidos de contado sin PDF generado muestran <strong>"(pendiente)"</strong> en lugar de <strong>"(firmado)"</strong>.</li>
    <li>Test rápido: comparar la misma orden antes y después: VK-1826-0001 debe pasar de "(firmado)" engañoso a "(pendiente de firma)" honesto.</li>
  </ul>

  <strong style="display:block;margin-top:14px;">Round 18 — Crédito: firma del contrato ANTES del enganche (regla "no se recibe enganche sin firma"):</strong>
  <ul>
    <li><strong>Flujo nuevo:</strong> CDC aprobado → Truora identidad → <strong>firma del contrato</strong> → <strong>pago del enganche</strong> → autopago → resultado.</li>
    <li>El servidor (<code>create-payment-intent.php</code>) bloquea el PaymentIntent del enganche con HTTP 409 <code>firma_requerida</code> si no hay row en <code>firmas_contratos</code>.</li>
    <li>El SPA verifica firma en <code>init()</code> de <code>paso-credito-enganche.js</code> y redirige a <code>credito-contrato</code> si falta.</li>
    <li>Si Stripe cobra una tarjeta sin firma (caso edge), <code>confirmar-orden.php</code> marca <code>pago_estado = 'pendiente_firma'</code> en lugar de <code>'pagada'</code>.</li>
    <li>Al firmar, <code>generar-contrato-pdf.php</code> hace UPDATE en <code>transacciones</code> (<code>contrato_pdf_path</code>, <code>contrato_pdf_hash</code>) + sube <code>pago_estado</code> de <code>pendiente_firma</code> → <code>pagada</code>.</li>
    <li>Tests:
      <ul>
        <li>Hacer una orden de crédito de prueba → al llegar a "Pagar enganche" debe redirigir automáticamente a "Firmar contrato" (no debe mostrar el formulario de tarjeta).</li>
        <li>Firmar el contrato → debe regresar a "Pagar enganche" → ahora sí muestra el formulario de tarjeta.</li>
        <li>Admin → Ventas → <strong>Panel Sin Firma</strong>: órdenes legacy (como Carlos Ricardo VK-1826-0001) siguen visibles para resend de Truora link.</li>
      </ul>
    </li>
  </ul>

  <strong style="display:block;margin-top:14px;">Round 17 — Checklist drill-in (autor + fotos + timestamps):</strong>
  <ul>
    <li>Admin → cualquier orden con checklist (ej. K-2605-0002) → "Documentos del pedido" → desplegar la tarjeta <strong>"Checklist (origen · ensamble · entrega)"</strong>.</li>
    <li>Cada una de las 3 sub-tarjetas (Origen / Ensamble / Entrega) ahora muestra "📷 N fotos" + "por [Nombre]" + flecha <strong>"Ver detalle →"</strong>.</li>
    <li>Click en cualquier sub-tarjeta abre la vista detalle in-line con:
      <ul>
        <li>✅ <strong>Completado por</strong>: nombre del dealer + rol + punto (snapshot inmune a futuras ediciones).</li>
        <li>✅ <strong>Sello de tiempo (sistema)</strong>: freg, fmod, fecha_inicio, fecha_completado, fase1_fecha, fase2_fecha, …</li>
        <li>✅ <strong>Items del checklist</strong> (collapsable): cuántos / cuántos completados + listado individual con ✓ / ○.</li>
        <li>✅ <strong>Fotos</strong>: galería agrupada por categoría con thumbnails clicables (abren en nueva pestaña).</li>
        <li>Botón <strong>"← Volver"</strong> regresa al grid de 3 tarjetas.</li>
      </ul>
    </li>
  </ul>

  <strong style="display:block;margin-top:14px;">Round 16 — Dossier de Defensa (DATOS DE LA OPERACIÓN + ACEPTACIÓN ELECTRÓNICA):</strong>
  <ul>
    <li>Admin → orden K-2605-0002 → "Documentos del pedido" → <strong>Dossier de Defensa (ZIP)</strong> → descargar.</li>
    <li>Abrir el PDF <code>00_INDICE.pdf</code> dentro del ZIP.</li>
    <li>✅ <strong>"Cliente (nombre completo)"</strong>: ahora dice <code>Adrian Montoya Diaz</code> (sin duplicación).</li>
    <li>✅ <strong>"Modalidad de pago"</strong>: dice <code>CONTADO</code> (no <code>UNICO</code>).</li>
    <li>✅ Bloque <strong>"ACEPTACIÓN ELECTRÓNICA DEL CONTRATO"</strong>: IP / Geo / User-Agent / SHA-256 muestran texto explicativo (no <code>--</code>).</li>
    <li>✅ <strong>"Código OTP validado"</strong>: dice <code>Pendiente — modalidad contado (OTP final al momento de la entrega)</code> en lugar de <code>No</code>.</li>
  </ul>

  <strong style="display:block;margin-top:14px;">Round 15 — Contrato DÉCIMA SÉPTIMA (REGISTRO DE ACEPTACIÓN):</strong>
  <ul>
    <li>Cliente con duplicación de nombre ("Adrian Montoya Diaz Montoya Diaz") descarga su contrato de nuevo desde "Mis compras" → <strong>Descargar contrato</strong>.</li>
    <li>✅ Esperado: <strong>"Adrian Montoya Diaz"</strong> (sin duplicación) en la fila "Nombre de EL COMPRADOR".</li>
    <li>Si IP / Geolocalización / Dispositivo estaban vacíos, ahora deben mostrar texto explicativo <strong>(no <code>--</code>)</strong>.</li>
    <li>Nuevas filas en el REGISTRO: <strong>Firma electrónica avanzada (Acta de Entrega)</strong>, <strong>Folio Cincel</strong>, <strong>Estado Cincel</strong>, <strong>Constancia NOM-151-SCFI-2016</strong>, <strong>Valor probatorio</strong>.</li>
    <li>Antes de la firma del Acta: estas filas dicen "Pendiente — Acta de Entrega". Después de la firma: aparece el folio real de Cincel.</li>
  </ul>
</div>

<h2>4. Si algo no está OK</h2>
<div class="card" style="font-size:13.5px;line-height:1.7;">
  <strong>Si un archivo dice ✗ "marcador no encontrado":</strong>
  <ol style="margin:6px 0 0 18px;">
    <li>Verifica que subiste el archivo desde tu local correcto (no una versión vieja por error).</li>
    <li>Verifica permisos del archivo en el servidor (debe ser legible por PHP).</li>
    <li>Si OPcache está activo arriba, dale click a "Resetear OPcache".</li>
    <li>Recarga esta página (Ctrl+Shift+R) — debería detectar el archivo nuevo.</li>
  </ol>

  <strong style="display:block;margin-top:14px;">Si los archivos están OK pero el comportamiento sigue viejo en el navegador:</strong>
  <ol style="margin:6px 0 0 18px;">
    <li>Caché del navegador: Ctrl+Shift+R (Windows/Linux) o Cmd+Shift+R (Mac).</li>
    <li>Si hay CDN (Cloudflare/etc.): hacer "Purge cache" para los archivos cambiados.</li>
    <li>Abrir DevTools → Network → desmarcar "Disable cache" → recargar.</li>
  </ol>
</div>

<script>
var btn = document.getElementById('btnResetOpcache');
if (btn) btn.addEventListener('click', function() {
  if (!confirm('¿Resetear OPcache? Esto fuerza a PHP a re-leer todos los .php desde disco.')) return;
  btn.disabled = true;
  document.getElementById('resetResult').textContent = 'Procesando...';
  fetch('/admin/php/verificar-deploy.php', {
    method:'POST', credentials:'include',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'reset_opcache'})
  }).then(function(r){ return r.json(); }).then(function(j){
    document.getElementById('resetResult').textContent = j.ok ? '✓ ' + (j.message||'OK') : '✗ ' + (j.error||'fallo');
    btn.disabled = false;
    setTimeout(function(){ location.reload(); }, 1500);
  }).catch(function(e){
    document.getElementById('resetResult').textContent = '✗ ' + e.message;
    btn.disabled = false;
  });
});
</script>
</body></html>
