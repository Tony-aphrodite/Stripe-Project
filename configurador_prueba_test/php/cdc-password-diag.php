<?php
/**
 * CDC password character diagnostic.
 *
 * Shows the STRUCTURE of CDC_PASS (length, char codes, special-char flags)
 * WITHOUT revealing the password text itself. Used to verify the support
 * team's hint ("does the password contain special characters?").
 *
 * Access:
 *   https://<domain>/configurador_prueba/php/cdc-password-diag.php?key=<SECRET>
 *
 * The key comes from CDC_DIAG_KEY env var (or DB admin password hash if set).
 * If neither is configured, the page runs in dev-mode with a clear warning.
 */
require_once __DIR__ . '/config.php';

// ── Access control ──────────────────────────────────────────────────────
$expectedKey = getenv('CDC_DIAG_KEY') ?: '';
$providedKey = $_GET['key'] ?? '';
$devMode     = ($expectedKey === '');

if (!$devMode && !hash_equals($expectedKey, $providedKey)) {
    http_response_code(403);
    echo 'Forbidden. Append ?key=... with the diagnostic key from CDC_DIAG_KEY env var.';
    exit;
}

header('Content-Type: text/html; charset=utf-8');

// ── Character classification helpers ────────────────────────────────────
function charType(int $ord): string {
    if ($ord >= 0x30 && $ord <= 0x39) return 'digit';           // 0-9
    if ($ord >= 0x41 && $ord <= 0x5A) return 'upper';           // A-Z
    if ($ord >= 0x61 && $ord <= 0x7A) return 'lower';           // a-z
    if ($ord === 0x20)               return 'space';
    if ($ord === 0x09)               return 'TAB';
    if ($ord === 0x0A)               return 'LF (\\n)';
    if ($ord === 0x0D)               return 'CR (\\r)';
    if ($ord < 0x20 || $ord === 0x7F) return 'control';
    if ($ord >= 0x80)                return 'non-ASCII';        // ñ á é etc.
    return 'special';                                            // !@#$%^&*() : ; = + etc.
}

function charGlyph(int $ord): string {
    if ($ord >= 0x20 && $ord < 0x7F) return '"' . chr($ord) . '"';
    if ($ord === 0x09) return '\\t';
    if ($ord === 0x0A) return '\\n';
    if ($ord === 0x0D) return '\\r';
    return '?';
}

// ── Analyze CDC_PASS ────────────────────────────────────────────────────
$raw   = defined('CDC_PASS') ? (string)CDC_PASS : '';
$byteLen   = strlen($raw);
$mbLen     = function_exists('mb_strlen') ? mb_strlen($raw, 'UTF-8') : $byteLen;
$trimmed   = trim($raw);
$hasTrimmable = $trimmed !== $raw;

$counts = [
    'digit'     => 0,
    'upper'     => 0,
    'lower'     => 0,
    'space'     => 0,
    'TAB'       => 0,
    'LF (\\n)'  => 0,
    'CR (\\r)'  => 0,
    'control'   => 0,
    'non-ASCII' => 0,
    'special'   => 0,
];

$specialChars = [];  // unique special chars found
$rows         = [];  // per-character breakdown

for ($i = 0; $i < $byteLen; $i++) {
    $ord  = ord($raw[$i]);
    $type = charType($ord);
    $counts[$type]++;
    if (in_array($type, ['special', 'space', 'TAB', 'LF (\\n)', 'CR (\\r)', 'control', 'non-ASCII'], true)) {
        if (!in_array($ord, $specialChars, true)) $specialChars[] = $ord;
    }
    $rows[] = [
        'pos'   => $i,
        'ord'   => $ord,
        'hex'   => sprintf('0x%02X', $ord),
        'type'  => $type,
        'glyph' => charGlyph($ord),
    ];
}

// HTTP header safety: RFC 7230 allows visible ASCII (0x21-0x7E) + space in
// header values. Anything else can cause the upstream proxy to reject the
// request — which matches the 100% 503 pattern.
$httpHeaderSafe = true;
$unsafeReason   = null;
if ($hasTrimmable) { $httpHeaderSafe = false; $unsafeReason = 'whitespace at start/end of password'; }
elseif ($counts['LF (\\n)'] || $counts['CR (\\r)'] || $counts['TAB']) {
    $httpHeaderSafe = false;
    $unsafeReason = 'contains CR/LF/TAB — always breaks HTTP header parsing';
} elseif ($counts['control']) {
    $httpHeaderSafe = false;
    $unsafeReason = 'contains control character (< 0x20)';
} elseif ($counts['non-ASCII']) {
    $httpHeaderSafe = false;
    $unsafeReason = 'contains non-ASCII byte (>= 0x80) — RFC 7230 violation';
}

// ── Render ──────────────────────────────────────────────────────────────
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<title>CDC Password Diagnostic</title>
<style>
body{font-family:ui-monospace,Menlo,Consolas,monospace;max-width:900px;margin:20px auto;padding:0 14px;color:#111;}
h1,h2{margin:18px 0 8px;}
.box{border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;margin:10px 0;background:#fff;}
.ok{color:#059669;} .err{color:#b91c1c;} .warn{color:#b45309;}
.big{font-size:22px;font-weight:800;}
table{width:100%;border-collapse:collapse;font-size:13px;margin-top:10px;}
th,td{text-align:left;padding:6px 10px;border-bottom:1px solid #e5e7eb;}
th{background:#f9fafb;}
.pill{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;}
.pill.ok{background:#dcfce7;color:#166534;}
.pill.err{background:#fee2e2;color:#991b1b;}
.pill.warn{background:#fef3c7;color:#92400e;}
.pill.gray{background:#e5e7eb;color:#374151;}
.note{font-size:12px;color:#555;line-height:1.5;}
</style></head><body>

<h1>CDC Password Diagnostic</h1>
<?php if ($devMode): ?>
<div class="box warn">⚠ <strong>DEV MODE</strong> — Sin CDC_DIAG_KEY definida. Configure la variable de entorno antes de producción.</div>
<?php endif; ?>

<?php if ($raw === ''): ?>
<div class="box err">❌ CDC_PASS NO ESTÁ DEFINIDA. Verifique la variable de entorno en el servidor.</div>
<?php else: ?>

<!-- Verdict -->
<div class="box" style="background:<?= $httpHeaderSafe ? '#f0fdf4' : '#fef2f2' ?>;border-color:<?= $httpHeaderSafe ? '#86efac' : '#fca5a5' ?>;">
  <div class="big <?= $httpHeaderSafe ? 'ok' : 'err' ?>">
    <?= $httpHeaderSafe
        ? '✓ La contraseña NO contiene caracteres que rompen HTTP headers'
        : '✗ La contraseña TIENE caracteres problemáticos' ?>
  </div>
  <?php if (!$httpHeaderSafe): ?>
    <div class="err" style="margin-top:8px;"><strong>Motivo:</strong> <?= htmlspecialchars($unsafeReason) ?></div>
    <div class="note" style="margin-top:6px;">Esto explica el 100% de 503 del endpoint /v2/rccficoscore — Apigee rechaza el header <code>password:</code> malformado antes de procesar la petición.</div>
  <?php else: ?>
    <div class="note" style="margin-top:6px;">El header HTTP <code>password: &lt;valor&gt;</code> se envía con caracteres ASCII imprimibles estándar. Si el 503 persiste, la causa está en otro lado.</div>
  <?php endif; ?>
</div>

<!-- Summary -->
<h2>Resumen</h2>
<div class="box">
<table>
<tr><th>Longitud (bytes)</th><td><strong><?= $byteLen ?></strong></td></tr>
<tr><th>Longitud (caracteres UTF-8)</th><td><strong><?= $mbLen ?></strong></td><?= ($byteLen !== $mbLen) ? '<td class="err">← diferente → hay multi-byte</td>' : '' ?></tr>
<tr><th>Espacios al inicio/final</th><td><?= $hasTrimmable ? '<span class="pill err">SÍ — problema</span>' : '<span class="pill ok">NO</span>' ?></td></tr>
<tr><th>Dígitos (0-9)</th><td><?= $counts['digit'] ?></td></tr>
<tr><th>Mayúsculas (A-Z)</th><td><?= $counts['upper'] ?></td></tr>
<tr><th>Minúsculas (a-z)</th><td><?= $counts['lower'] ?></td></tr>
<tr><th>Espacios internos</th><td><?= $counts['space'] ?> <?= $counts['space'] > 0 ? '<span class="pill warn">posible problema</span>' : '' ?></td></tr>
<tr><th>TAB (\\t)</th><td><?= $counts['TAB'] ?> <?= $counts['TAB'] > 0 ? '<span class="pill err">rompe HTTP</span>' : '' ?></td></tr>
<tr><th>LF (\\n)</th><td><?= $counts['LF (\\n)'] ?> <?= $counts['LF (\\n)'] > 0 ? '<span class="pill err">rompe HTTP</span>' : '' ?></td></tr>
<tr><th>CR (\\r)</th><td><?= $counts['CR (\\r)'] ?> <?= $counts['CR (\\r)'] > 0 ? '<span class="pill err">rompe HTTP</span>' : '' ?></td></tr>
<tr><th>Control (<0x20)</th><td><?= $counts['control'] ?> <?= $counts['control'] > 0 ? '<span class="pill err">rompe HTTP</span>' : '' ?></td></tr>
<tr><th>Non-ASCII (≥0x80)</th><td><?= $counts['non-ASCII'] ?> <?= $counts['non-ASCII'] > 0 ? '<span class="pill err">viola RFC 7230</span>' : '' ?></td></tr>
<tr><th>Especiales (puntuación, símbolos)</th><td><?= $counts['special'] ?> <?= $counts['special'] > 0 ? '<span class="pill warn">visibles pero algunos proxies los rechazan</span>' : '<span class="pill ok">ninguno</span>' ?></td></tr>
</table>
</div>

<!-- Special chars detail -->
<?php if (!empty($specialChars)): ?>
<h2>Caracteres no-alfanuméricos detectados</h2>
<div class="box">
<table>
<tr><th>Hex</th><th>Decimal</th><th>Glifo</th><th>Tipo</th><th>¿Problema?</th></tr>
<?php foreach ($specialChars as $ord):
    $type = charType($ord);
    $glyph = charGlyph($ord);
    $breaksHttp = in_array($type, ['TAB','LF (\\n)','CR (\\r)','control','non-ASCII'], true);
    $cls   = $breaksHttp ? 'err' : ($type === 'space' ? 'warn' : 'gray');
?>
<tr>
<td><code>0x<?= sprintf('%02X', $ord) ?></code></td>
<td><?= $ord ?></td>
<td><code><?= htmlspecialchars($glyph) ?></code></td>
<td><?= $type ?></td>
<td><span class="pill <?= $cls ?>"><?= $breaksHttp ? 'ROMPE HTTP' : ($type === 'space' ? 'sospechoso' : 'OK en ASCII imprimible') ?></span></td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>

<!-- Per-char breakdown -->
<h2>Desglose carácter por carácter (sin revelar la contraseña)</h2>
<div class="box">
<div class="note" style="margin-bottom:8px;">Se muestra el <strong>código ASCII</strong> de cada posición. Los caracteres alfanuméricos se representan con <code>#</code> para preservar la confidencialidad. Los especiales se muestran tal cual porque son los que necesitamos identificar.</div>
<table>
<tr><th>Posición</th><th>Hex</th><th>Decimal</th><th>Tipo</th><th>Glifo visible</th></tr>
<?php foreach ($rows as $r):
    $cls = '';
    if (in_array($r['type'], ['TAB','LF (\\n)','CR (\\r)','control','non-ASCII'], true)) $cls = 'err';
    elseif ($r['type'] === 'space') $cls = 'warn';
    $glyph = in_array($r['type'], ['digit','upper','lower'], true) ? '#' : $r['glyph'];
?>
<tr>
<td><?= $r['pos'] ?></td>
<td><code><?= $r['hex'] ?></code></td>
<td><?= $r['ord'] ?></td>
<td class="<?= $cls ?>"><?= $r['type'] ?></td>
<td><code><?= htmlspecialchars($glyph) ?></code></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<!-- Next step advice -->
<h2>Próximo paso</h2>
<div class="box">
<?php if ($httpHeaderSafe && $counts['special'] === 0): ?>
  <div class="ok">✓ La contraseña es solo alfanumérica. El 503 probablemente <strong>no viene de la contraseña</strong>. Pida a CDC que revise los logs del Apigee gateway para el usuario.</div>
<?php elseif ($httpHeaderSafe && $counts['special'] > 0): ?>
  <div class="warn">⚠ La contraseña <strong>tiene símbolos especiales imprimibles</strong> (p.ej. <code>!</code> <code>@</code> <code>#</code>). Aunque técnicamente son válidos en HTTP headers, algunos proxies de Apigee los rechazan. <strong>Recomendación:</strong> cambie la contraseña en el portal CDC a una <strong>solo A-Z/a-z/0-9</strong> y reintente.</div>
<?php else: ?>
  <div class="err">❌ <strong>CONFIRMADO:</strong> La contraseña contiene caracteres que rompen HTTP headers. Esto explica el 100% de 503. <strong>Acción inmediata:</strong> cambie la contraseña en el portal CDC a una sin espacios, sin acentos, sin tab/newlines — use solo A-Z, a-z, 0-9.</div>
<?php endif; ?>
</div>

<?php endif; ?>

</body></html>
