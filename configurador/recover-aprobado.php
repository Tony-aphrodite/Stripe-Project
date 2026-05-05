<?php
/**
 * Voltika — Customer-facing landing for the manual-override approval link.
 *
 * Customer brief 2026-05-04: when an admin reviewer overrides the system-
 * suggested enganche/plazo and clicks "Enviar oferta personalizada", the
 * customer receives an email with a 48h link pointing at this page. The
 * landing page:
 *   1. Validates the HMAC-signed token (id.expires.action.hmac)
 *   2. Loads the locked terms from preaprobaciones (enganche_pct, plazo)
 *   3. Renders a "your approval" screen with the exact numbers
 *   4. On Continuar, seeds the SPA's sessionStorage so the configurator
 *      lands at credito-identidad (Truora) with these values bolted in
 *      and skipping CDC re-evaluation
 *
 * The locked terms travel to the SPA via sessionStorage. Downstream the
 * SPA's credit module reads enganchePorcentaje / plazoMeses from state
 * (these are normal, non-underscore fields so they survive restore) —
 * so the customer literally cannot change them on their side.
 *
 * URL: /configurador/recover-aprobado.php?t=<id.expires.aprobado.hmac>
 */

declare(strict_types=1);

require_once __DIR__ . '/php/config.php';

$token = (string)($_GET['t'] ?? '');
if ($token === '') {
    http_response_code(400);
    echo "Enlace inválido.";
    exit;
}

// ── Validate HMAC token ────────────────────────────────────────────────────
// Two valid shapes:
//   • normal  4 parts: id.expires.aprobado.hmac           → reads override from DB
//   • preview 6 parts: id.expires.preview.engX100.plazo.hmac → uses embedded values
$parts = explode('.', $token);
$isPreview = (count($parts) === 6 && $parts[2] === 'preview');

if ($isPreview) {
    [$id, $expires, $action, $engX100, $plazoTok, $sig] = $parts;
    $previewEngPct = ((int)$engX100) / 100;
    $previewPlazo  = (int)$plazoTok;
    $signedPayload = $id . '.' . $expires . '.preview.' . $engX100 . '.' . $plazoTok;
} elseif (count($parts) === 4) {
    [$id, $expires, $action, $sig] = $parts;
    $previewEngPct = null;
    $previewPlazo  = null;
    $signedPayload = $id . '.' . $expires . '.' . $action;
} else {
    http_response_code(400);
    echo "Enlace mal formado.";
    exit;
}

$id      = (int)$id;
$expires = (int)$expires;

if ($action !== 'aprobado' && $action !== 'preview') {
    http_response_code(400);
    echo "Acción no permitida en este enlace.";
    exit;
}
if ($expires < time()) {
    http_response_code(410);
    echo "Este enlace ha expirado. Por favor solicita una nueva evaluación a soporte.";
    exit;
}

$recoverSecret = defined('VOLTIKA_RECOVER_SECRET')
    ? VOLTIKA_RECOVER_SECRET
    : (getenv('VOLTIKA_RECOVER_SECRET') ?: 'voltika_recover_2026_default');
$expected = hash_hmac('sha256', $signedPayload, $recoverSecret);
if (!hash_equals($expected, $sig)) {
    http_response_code(403);
    echo "Enlace inválido (firma).";
    exit;
}

// ── Load preaprobacion ─────────────────────────────────────────────────────
try {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT p.*, cb.curp AS cdc_curp
        FROM preaprobaciones p
        LEFT JOIN consultas_buro cb ON cb.id = (
            SELECT cb2.id FROM consultas_buro cb2
            WHERE (cb2.nombre = p.nombre
                   AND cb2.apellido_paterno = p.apellido_paterno
                   AND COALESCE(cb2.cp,'') = COALESCE(p.cp,''))
            ORDER BY cb2.id DESC LIMIT 1
        )
        WHERE p.id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo "Solicitud no encontrada.";
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo "Error interno.";
    exit;
}

// ── Compute the locked terms for display ───────────────────────────────────
// Preview tokens carry the override embedded so the admin can preview
// without writing the override to DB. Normal tokens read from the
// preaprobaciones row (where enviar-oferta-personalizada.php just saved
// the override).
if ($isPreview) {
    $engPct     = $previewEngPct;
    $plazoMeses = $previewPlazo;
} else {
    $engPct     = (float)($row['enganche_requerido'] ?? $row['enganche_pct'] / 100 ?? 0.30);
    if ($engPct > 1) $engPct = $engPct / 100;  // tolerate rows storing 30 vs 0.30
    $plazoMeses = (int)($row['plazo_max'] ?? $row['plazo_meses'] ?? 12);
}
$precio       = (float)($row['precio_contado'] ?? 0);
$enganche     = $precio * $engPct;
$financiado   = $precio - $enganche;
$mensual      = $plazoMeses > 0 ? $financiado / $plazoMeses : 0;
$semanal      = $mensual / 4.33;

// ── State the SPA needs to honour the locked terms ────────────────────────
// Same persistence format as configurador.js (vk_configurador_state_v1
// envelope with {ts, state}). pasoActual='credito-identidad' makes the
// SPA jump directly to Truora when the customer clicks Continuar.
$state = [
    'modeloSeleccionado'  => mapModeloIdFromName($row['modelo'] ?? ''),
    'metodoPago'          => 'credito',
    'nombre'              => $row['nombre']           ?? '',
    'apellidoPaterno'     => $row['apellido_paterno'] ?? '',
    'apellidoMaterno'     => $row['apellido_materno'] ?? '',
    'email'               => $row['email']            ?? '',
    'telefono'            => $row['telefono']         ?? '',
    'curp'                => $row['cdc_curp']         ?? '',
    'fechaNacimiento'     => $row['fecha_nacimiento'] ?? '',
    'codigoPostal'        => $row['cp']               ?? '',
    'cp'                  => $row['cp']               ?? '',
    'ciudad'              => $row['ciudad']           ?? '',
    'estado'              => $row['estado']           ?? '',
    'creditoAprobado'     => true,
    'recoveredFromAdmin'  => true,
    'recoveredPreapId'    => (int)$row['id'],
    'enganchePorcentaje'  => $engPct,
    'enganchePctMin'      => $engPct,         // hard floor — customer can't lower it
    'enganchePctMax'      => $engPct,         // hard ceiling — customer can't raise it (locks)
    'plazoMeses'          => $plazoMeses,
    'plazoMesesMax'       => $plazoMeses,     // locks the slider too
    'plazoMesesMin'       => $plazoMeses,
    'ofertaPersonalizada' => true,            // SPA flag for "lock UI controls"
    'pasoActual'          => 'credito-identidad',
];

$envelope = [
    'ts'    => round(microtime(true) * 1000),
    'state' => $state,
];

function mapModeloIdFromName(string $name): string {
    $n = strtolower(trim($name));
    $map = [
        'm05'           => 'm05',
        'm03'           => 'm03',
        'pesgo plus'    => 'pesgo-plus',
        'mc10 streetx'  => 'mc10',
        'mc10'          => 'mc10',
        'ukko s+'       => 'ukko-s',
        'mino-b'        => 'mino',
        'mino'          => 'mino',
    ];
    return $map[$n] ?? preg_replace('/\s+/', '-', $n);
}

$nombreCompleto = trim(($row['nombre'] ?? '') . ' ' . ($row['apellido_paterno'] ?? ''));

?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Voltika — Tu solicitud está aprobada</title>
<link rel="icon" type="image/svg+xml" href="img/favicon.svg">
<style>
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#F8FAFC;color:#1f2937;padding:0;min-height:100vh;}
  .header{background:#1a3a5c;color:#fff;padding:20px;text-align:center;}
  .header img{height:36px;}
  .container{max-width:520px;margin:0 auto;padding:20px;}
  .approved-badge{display:inline-flex;align-items:center;gap:8px;background:#d1fae5;color:#065f46;padding:8px 16px;border-radius:999px;font-size:13px;font-weight:700;margin-bottom:14px;}
  .approved-badge svg{width:16px;height:16px;}
  h1{font-size:24px;line-height:1.25;color:#1a3a5c;margin-bottom:8px;}
  .subtitle{font-size:15px;color:#475569;margin-bottom:20px;line-height:1.5;}
  .terms-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:22px 20px;box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:20px;}
  .terms-title{font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#64748b;font-weight:700;margin-bottom:14px;}
  .term-row{display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid #f1f5f9;}
  .term-row:last-child{border-bottom:none;}
  .term-label{font-size:14px;color:#475569;}
  .term-value{font-size:16px;font-weight:800;color:#1f2937;}
  .term-value.primary{color:#039fe1;font-size:18px;}
  .lock-note{background:#fef3c7;border:1px solid #fde68a;color:#78350f;padding:12px 14px;border-radius:8px;font-size:13px;line-height:1.5;margin-bottom:20px;display:flex;gap:10px;}
  .lock-note svg{flex-shrink:0;width:18px;height:18px;margin-top:1px;}
  .cta{display:block;width:100%;padding:16px;background:#039fe1;color:#fff;border:none;border-radius:12px;font-size:16px;font-weight:800;cursor:pointer;text-decoration:none;text-align:center;transition:transform .1s, box-shadow .1s;box-shadow:0 4px 12px rgba(3,159,225,.3);}
  .cta:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(3,159,225,.4);}
  .help{text-align:center;font-size:13px;color:#64748b;margin-top:18px;}
  .help a{color:#039fe1;text-decoration:none;font-weight:700;}
  .expires{text-align:center;font-size:11px;color:#94a3b8;margin-top:12px;}
</style>
</head><body>
<?php if ($isPreview): ?>
<div style="background:#fb923c;color:#fff;padding:10px 16px;font-size:13px;font-weight:700;text-align:center;letter-spacing:.5px;">
  🧪 MODO PRUEBA — vista del admin · <?= round($engPct * 100) ?>% / <?= $plazoMeses ?>m · sin envío al cliente
</div>
<?php endif; ?>
<div class="header">
  <img src="img/voltika_logo_h_white.svg" alt="Voltika">
</div>
<div class="container">
  <span class="approved-badge">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
    Aprobado
  </span>
  <h1>Hola <?= htmlspecialchars($nombreCompleto ?: 'Cliente Voltika') ?>,<br>tu solicitud fue aprobada</h1>
  <p class="subtitle">Tu solicitud de crédito está aprobada con las siguientes condiciones personalizadas:</p>

  <div class="terms-card">
    <div class="terms-title">Condiciones de tu crédito</div>
    <div class="term-row">
      <span class="term-label">Modelo</span>
      <span class="term-value"><?= htmlspecialchars($row['modelo'] ?: '—') ?></span>
    </div>
    <div class="term-row">
      <span class="term-label">Precio total</span>
      <span class="term-value">$<?= number_format($precio, 0, '.', ',') ?></span>
    </div>
    <div class="term-row">
      <span class="term-label">Enganche (<?= round($engPct * 100) ?>%)</span>
      <span class="term-value">$<?= number_format($enganche, 0, '.', ',') ?></span>
    </div>
    <div class="term-row">
      <span class="term-label">Plazo</span>
      <span class="term-value"><?= $plazoMeses ?> meses</span>
    </div>
    <div class="term-row">
      <span class="term-label">Pago semanal</span>
      <span class="term-value primary">$<?= number_format($semanal, 0, '.', ',') ?></span>
    </div>
    <div class="term-row">
      <span class="term-label">Pago mensual</span>
      <span class="term-value primary">$<?= number_format($mensual, 0, '.', ',') ?></span>
    </div>
  </div>

  <div class="lock-note">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    <span>Estas condiciones están <strong>bloqueadas</strong> y son válidas únicamente con este enlace. Solo falta verificar tu identidad para continuar.</span>
  </div>

  <button class="cta" id="continueBtn">Continuar con verificación de identidad</button>

  <div class="help">¿Necesitas ayuda? Escríbenos por <a href="https://wa.me/525513416370">WhatsApp</a></div>
  <div class="expires">Este enlace expira el <?= date('d/m/Y H:i', $expires) ?></div>
</div>

<script>
(function(){
  // Same envelope format as configurador.js _PERSIST_KEY:
  //   { ts: ms-epoch, state: { ... pasoActual: 'credito-identidad' } }
  // Underscore-prefixed keys are filtered on restore — that's why the
  // override values use plain names (enganchePorcentaje, plazoMeses,
  // enganchePctMin/Max for locking).
  var ENVELOPE = <?= json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  document.getElementById('continueBtn').addEventListener('click', function(){
    try {
      sessionStorage.setItem('vk_configurador_state_v1', JSON.stringify(ENVELOPE));
      sessionStorage.setItem('voltika_recovered',         '1');
      sessionStorage.setItem('voltika_oferta_personalizada','1');
    } catch (e) {}
    window.location.href = '/configurador/?recovered=1&oferta=1';
  });
})();
</script>
</body></html>
