<?php
/**
 * Voltika — recovery landing page for the "send Truora link" admin action.
 *
 * Customer brief 2026-05-02: when the admin sends a manual-review link to
 * a credit applicant who didn't complete Truora, this is where they land.
 * Validates the HMAC token, looks up the preaprobacion row, then bounces
 * the user into the configurador SPA with sessionStorage seeded so the
 * flow lands directly at the Truora identity step (no CDC redo, no
 * form re-entry).
 *
 * URL: /configurador/recover-truora.php?t=<id.expires.hmac>
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
$parts = explode('.', $token);
if (count($parts) !== 3) {
    http_response_code(400);
    echo "Enlace mal formado.";
    exit;
}
[$id, $expires, $sig] = $parts;
$id      = (int)$id;
$expires = (int)$expires;

if ($expires < time()) {
    http_response_code(410);
    echo "Este enlace ha expirado. Por favor solicita uno nuevo a soporte.";
    exit;
}

$recoverSecret = defined('VOLTIKA_RECOVER_SECRET')
    ? VOLTIKA_RECOVER_SECRET
    : (getenv('VOLTIKA_RECOVER_SECRET') ?: 'voltika_recover_2026_default');
$expected = hash_hmac('sha256', $id . '.' . $expires, $recoverSecret);
if (!hash_equals($expected, $sig)) {
    http_response_code(403);
    echo "Enlace inválido (firma).";
    exit;
}

// ── Load preaprobacion data + CURP from consultas_buro ─────────────────────
// CURP is needed by paso-credito-identidad/truora-token. It's stored on
// consultas_buro (the original CDC query persisted it) but NOT on
// preaprobaciones, so we JOIN by the same heuristic listar.php uses
// (nombre + apellido_paterno + cp).
try {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT p.*, cb.curp AS cdc_curp, cb.score AS cdc_score
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

// ── Build state in the EXACT format configurador.js expects ────────────────
// Customer report 2026-05-04: clicking "Continuar verificación" landed the
// user back at the modelo step (paso 1) — i.e. the SPA never restored our
// seeded state. Root cause: we wrote to sessionStorage key 'voltika_state'
// but the SPA's persistence layer (configurador.js _PERSIST_KEY) reads
// 'vk_configurador_state_v1' and unwraps a {ts, state:{...}} envelope.
// On top of that, the restore filter strips ANY key starting with '_',
// so our '_skipCDC' / '_recoveredFromAdmin' fields were silently dropped.
//
// Fix: write to the right key, wrap with the right envelope, set
// pasoActual='credito-identidad' so the SPA jumps straight to Truora,
// and only use plain (non-underscore) field names for everything we
// need to survive restore.
$state = [
    'modeloSeleccionado' => mapModeloIdFromName($row['modelo'] ?? ''),
    'metodoPago'         => 'credito',
    'nombre'             => $row['nombre']           ?? '',
    'apellidoPaterno'    => $row['apellido_paterno'] ?? '',
    'apellidoMaterno'    => $row['apellido_materno'] ?? '',
    'email'              => $row['email']            ?? '',
    'telefono'           => $row['telefono']         ?? '',
    'curp'               => $row['cdc_curp']         ?? '',  // for Truora cross-check
    'fechaNacimiento'    => $row['fecha_nacimiento'] ?? '',
    'codigoPostal'       => $row['cp']               ?? '',
    'cp'                 => $row['cp']               ?? '',  // SPA uses both names
    'ciudad'             => $row['ciudad']           ?? '',
    'estado'             => $row['estado']           ?? '',
    'creditoAprobado'    => true,
    'recoveredFromAdmin' => true,                    // non-underscore so it persists
    'recoveredPreapId'   => (int)$row['id'],
    'modoCondicional'    => in_array($row['status'] ?? '', ['CONDICIONAL', 'CONDICIONAL_ESTIMADO'], true),
    // pasoActual is read by _installStatePersistence() — when present the
    // SPA calls irAPaso(pasoActual) ~50 ms after init, which is exactly
    // the jump-to-Truora behavior we want.
    'pasoActual'         => 'credito-identidad',
];
if ($row['enganche_requerido'] ?? null) {
    $state['enganchePctMin']     = (float)$row['enganche_requerido'];
    $state['enganchePorcentaje'] = max((float)$row['enganche_requerido'], 0.30);
}
if ($row['plazo_max'] ?? null) {
    $state['plazoMesesMax'] = (int)$row['plazo_max'];
    $state['plazoMeses']    = (int)$row['plazo_max'];
}

// Envelope the SPA expects: { ts: <ms>, state: {...} }. Without ts the
// restore TTL check (Date.now() - parsed.ts < 2h) immediately rejects.
$envelope = [
    'ts'    => round(microtime(true) * 1000),
    'state' => $state,
];

/**
 * Best-effort map of the model name stored on the preaprobacion row to the
 * configurador's modeloId slug. Falls back to lowercase of the name. The
 * SPA's own `app.getModelo()` is forgiving with case + whitespace.
 */
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

?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Voltika — Continuando tu verificación</title>
<link rel="icon" type="image/svg+xml" href="img/favicon.svg">
<style>
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#F8FAFC;margin:0;padding:24px;color:#222;}
.box{max-width:480px;margin:60px auto;background:#fff;border-radius:14px;padding:32px;box-shadow:0 4px 20px rgba(0,0,0,.06);text-align:center;}
.spin{width:32px;height:32px;border:3px solid #E5E7EB;border-top-color:#039fe1;border-radius:50%;animation:s 1s linear infinite;margin:0 auto 16px;}
@keyframes s{to{transform:rotate(360deg)}}
.logo{height:40px;margin-bottom:18px;}
h1{font-size:18px;color:#1a3a5c;margin:0 0 8px;}
p{font-size:14px;color:#555;margin:0 0 6px;}
</style>
</head><body>
<div class="box">
  <img src="img/voltika_logo_h_white.svg" alt="Voltika" class="logo" style="background:#1a3a5c;border-radius:6px;padding:6px 10px;">
  <div class="spin"></div>
  <h1>Continuando tu verificación...</h1>
  <p>Estamos llevándote al paso final.</p>
</div>
<script>
(function(){
  // Envelope must match exactly what configurador.js _installStatePersistence
  // expects: { ts: <ms-epoch>, state: {...} }. Wrong key or shape and the
  // SPA silently boots into the modelo screen instead of credito-identidad.
  var ENVELOPE = <?= json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  try {
    sessionStorage.setItem('vk_configurador_state_v1', JSON.stringify(ENVELOPE));
    // Also seed a recovery flag (separate key) for any module that wants
    // to opt-in to "I came in via the admin recovery link" behavior.
    sessionStorage.setItem('voltika_recovered', '1');
  } catch (e) {}
  // Customer brief 2026-05-13 (Óscar, 14th round — "click the button,
  // send to the configuration but the start"): the sessionStorage-based
  // restore is fragile (race condition between Paso1.init and the 50ms
  // restore setTimeout, plus iOS Safari sometimes wipes sessionStorage
  // across the redirect). Add an explicit ?paso= URL parameter so the
  // SPA doesn't have to guess where to land. The new init handler in
  // configurador.js reads ?paso and force-navigates after the regular
  // init finishes, beating any race condition.
  setTimeout(function(){
    window.location.href = '/configurador/?recovered=1&paso=credito-identidad';
  }, 600);
})();
</script>
</body></html>
