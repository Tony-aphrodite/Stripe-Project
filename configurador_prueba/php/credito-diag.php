<?php
/**
 * Credit decision diagnostic — explains WHY each applicant was
 * PREAPROBADO / CONDICIONAL / NO_VIABLE by joining:
 *   1. preaprobaciones   (final decision + input parameters)
 *   2. consultas_buro    (what CDC returned — score, DPD, pago_mensual)
 *   3. cdc_query_log     (raw body_sent + response from CDC)
 *
 * Use case:
 *   Customer reports "mi CDC dice 750 pero el crédito no pasa" →
 *   open this page, filter by their telefono, see every step and the
 *   exact decision reason (KO_SCORE_LT_MIN, SIN_SCORE_CDC_NO_AUTO_APROBACION,
 *   KO_PTI_EXTREME, etc.).
 *
 * Access: /configurador_prueba/php/credito-diag.php?key=voltika_credito_2026
 *   + optional filters:  &telefono=5512345678  |  &nombre=Juan  |  &email=x@y.com
 */
require_once __DIR__ . '/config.php';

$expectedKey = getenv('CREDITO_DIAG_KEY') ?: 'voltika_credito_2026';
$providedKey = $_GET['key'] ?? '';
if ($providedKey !== $expectedKey) { http_response_code(403); exit('Forbidden'); }

$pdo = getDB();
header('Content-Type: text/html; charset=utf-8');

// Filters
$fNombre   = trim((string)($_GET['nombre']   ?? ''));
$fTelefono = trim((string)($_GET['telefono'] ?? ''));
$fEmail    = trim((string)($_GET['email']    ?? ''));

$telDigits = preg_replace('/\D/', '', $fTelefono);
if (strlen($telDigits) > 10) $telDigits = substr($telDigits, -10);

$where = []; $params = [];
if ($fNombre !== '') {
    $where[] = "(nombre LIKE ? OR apellido_paterno LIKE ? OR apellido_materno LIKE ?)";
    $params[] = '%' . $fNombre . '%';
    $params[] = '%' . $fNombre . '%';
    $params[] = '%' . $fNombre . '%';
}
if ($telDigits !== '') {
    $where[] = "RIGHT(REPLACE(REPLACE(telefono,'+',''),' ',''), 10) = ?";
    $params[] = $telDigits;
}
if ($fEmail !== '') {
    $where[] = "LOWER(email) = ?";
    $params[] = strtolower($fEmail);
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$preaprobs = [];
$dbError = null;
try {
    $sql = "SELECT * FROM preaprobaciones" . $whereSql . " ORDER BY id DESC LIMIT 20";
    $s = $pdo->prepare($sql);
    $s->execute($params);
    $preaprobs = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $dbError = $e->getMessage(); }

// V3 config (mirror from preaprobacion-v3.php so the diag reflects the same
// thresholds the live decision uses).
$V3 = [
    'downPaymentMin' => 0.25,
    'KO' => [
        'scoreMin'   => 420,
        'ptiExtreme' => 1.05,
        'severeDPD'  => true,
    ],
    'PRE' => [
        'scoreMin' => 480,
        'ptiMax'   => 0.90,
    ],
    'CONDITIONAL' => [
        'lowScorePTIGuardrail' => ['scoreMax' => 439, 'ptiMax' => 0.95],
    ],
];

// Human-readable explanations for each 'reasons' code.
$reasonExplanations = [
    'IDENTIDAD_NO_ENCONTRADA_EN_CDC' =>
        'CDC respondió 404.1 (la persona NO existe en RENAPO/INE con los datos enviados). Rechazo duro para prevenir crédito a identidades falsas. Verifica que nombre, fecha de nacimiento y CP coincidan EXACTAMENTE con la INE.',
    'SIN_SCORE_CDC_NO_AUTO_APROBACION' =>
        'CDC respondió pero SIN score (thin file, sin historial, o CDC inalcanzable). Política interna: sin score real → no auto-aprobación. Revisa cdc_query_log para ver si fue timeout/503 o thin file real.',
    'KO_SCORE_LT_MIN' =>
        'El score CDC es menor que ' . $V3['KO']['scoreMin'] . '. Rechazo duro por riesgo.',
    'KO_SEVERE_DPD_90PLUS' =>
        'Mora severa (90+ días de atraso) detectada en historial. Rechazo duro.',
    'KO_PTI_EXTREME' =>
        'PTI total (pagos ÷ ingreso) superior a ' . $V3['KO']['ptiExtreme'] . ' (' . round($V3['KO']['ptiExtreme']*100) . '%). El cliente no puede soportar la cuota.',
    'KO_GUARDRAIL_LOW_SCORE_HIGH_PTI' =>
        'Score bajo (≤' . $V3['CONDITIONAL']['lowScorePTIGuardrail']['scoreMax'] . ') + PTI alto (>' . $V3['CONDITIONAL']['lowScorePTIGuardrail']['ptiMax'] . '). Combinación de alto riesgo.',
];

function parseReasons(?string $raw): array {
    if (!$raw) return [];
    $trimmed = trim($raw);
    if ($trimmed === '') return [];
    $decoded = json_decode($trimmed, true);
    if (is_array($decoded)) return $decoded;
    return array_map('trim', explode(',', $trimmed));
}

function pct($v): string {
    if ($v === null || $v === '') return '—';
    return round(((float)$v) * 100, 1) . '%';
}

function fmtMoney($v): string {
    if ($v === null || $v === '') return '—';
    return '$' . number_format((float)$v, 2);
}

// Find matching CDC query log rows (by nombre + freg within ±60s of preaprobacion)
function findCdcForPreaprob(PDO $pdo, array $row): ?array {
    try {
        $stmt = $pdo->prepare("SELECT http_code, body_sent, response, curl_err, freg
            FROM cdc_query_log
            WHERE ABS(TIMESTAMPDIFF(SECOND, freg, ?)) <= 120
              AND body_sent LIKE ?
            ORDER BY ABS(TIMESTAMPDIFF(SECOND, freg, ?)) ASC
            LIMIT 1");
        $nameNeedle = '%' . strtoupper($row['nombre'] ?? '') . '%';
        $stmt->execute([$row['freg'], $nameNeedle, $row['freg']]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    } catch (Throwable $e) { return null; }
}

// Find matching consultas_buro row (by nombre + freg within ±60s)
function findBuroForPreaprob(PDO $pdo, array $row): ?array {
    try {
        $stmt = $pdo->prepare("SELECT score, pago_mensual, dpd90_flag, dpd_max, num_cuentas, folio_consulta, freg
            FROM consultas_buro
            WHERE ABS(TIMESTAMPDIFF(SECOND, freg, ?)) <= 120
              AND (nombre = ? OR nombre LIKE ?)
            ORDER BY ABS(TIMESTAMPDIFF(SECOND, freg, ?)) ASC
            LIMIT 1");
        $stmt->execute([
            $row['freg'],
            strtoupper($row['nombre'] ?? ''),
            '%' . strtoupper($row['nombre'] ?? '') . '%',
            $row['freg'],
        ]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    } catch (Throwable $e) { return null; }
}

function statusBadge(string $status): string {
    $map = [
        'PREAPROBADO'          => ['#065f46', '#d1fae5', '✅ PREAPROBADO'],
        'CONDICIONAL'          => ['#78350f', '#fef3c7', '⚠ CONDICIONAL'],
        'CONDICIONAL_ESTIMADO' => ['#78350f', '#fef3c7', '⚠ CONDICIONAL (estimado)'],
        'NO_VIABLE'            => ['#7f1d1d', '#fee2e2', '❌ NO VIABLE'],
    ];
    $m = $map[$status] ?? ['#374151', '#e5e7eb', $status];
    return '<span style="background:' . $m[1] . ';color:' . $m[0] . ';padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;">' . $m[2] . '</span>';
}

?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Credit Decision Diagnostic</title>
<style>
body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background:#0f172a; color:#e2e8f0; margin:0; padding:24px; }
.container { max-width: 1200px; margin:0 auto; }
h1 { color:#fbbf24; font-size:24px; margin:0 0 16px; }
h2 { color:#60a5fa; font-size:17px; margin:22px 0 10px; border-bottom:1px solid #334155; padding-bottom:6px; }
.card { background:#1e293b; border:1px solid #334155; border-radius:10px; padding:14px; margin:10px 0; }
.ok { color:#10b981; } .bad { color:#ef4444; } .warn { color:#f59e0b; }
.kv { display:grid; grid-template-columns:180px 1fr; gap:4px 14px; font-size:13px; font-family:Consolas,monospace; }
.kv > div:nth-child(odd) { color:#94a3b8; }
pre { background:#0b1220; border:1px solid #1e293b; border-radius:6px; padding:10px; font-size:11px; overflow:auto; max-height:350px; color:#cbd5e1; }
form { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
input { background:#0b1220; border:1px solid #334155; color:#e2e8f0; padding:8px 12px; border-radius:6px; font-family:Consolas,monospace; font-size:13px; }
button { background:#3b82f6; color:#fff; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-weight:600; }
a { color:#60a5fa; text-decoration:none; }
a:hover { text-decoration:underline; }
table { width:100%; border-collapse:collapse; font-size:12px; }
th, td { text-align:left; padding:8px 10px; border-bottom:1px solid #334155; }
th { color:#94a3b8; font-weight:600; }
.reason-chip { display:inline-block; background:#7f1d1d; color:#fee2e2; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:700; font-family:Consolas,monospace; margin:2px 2px 2px 0; }
.pti-bar { display:inline-block; width:120px; height:14px; background:#0b1220; border:1px solid #334155; border-radius:3px; vertical-align:middle; position:relative; overflow:hidden; }
.pti-fill { position:absolute; top:0; left:0; bottom:0; }
details > summary { cursor:pointer; color:#94a3b8; font-size:12px; margin:4px 0; }
details[open] > summary { color:#e2e8f0; }
</style>
</head>
<body>
<div class="container">
<h1>🧮 Credit Decision Diagnostic</h1>
<p style="color:#94a3b8;font-size:13px;margin-top:-8px;">
    Muestra el razonamiento detrás de cada decisión de crédito (PREAPROBADO / CONDICIONAL / NO_VIABLE).
    Para cada aplicación puedes ver: datos de entrada, respuesta real del Buró de Crédito, PTI, umbrales aplicados y motivo exacto del rechazo.
</p>

<h2>1. Filtrar aplicaciones</h2>
<div class="card">
<form method="GET">
    <input type="hidden" name="key" value="<?= htmlspecialchars($providedKey) ?>">
    <input type="text" name="nombre"   placeholder="Nombre / apellido" value="<?= htmlspecialchars($fNombre) ?>" style="flex:1;min-width:160px;">
    <input type="text" name="telefono" placeholder="Teléfono"          value="<?= htmlspecialchars($fTelefono) ?>" style="flex:1;min-width:140px;">
    <input type="email" name="email"   placeholder="Email"             value="<?= htmlspecialchars($fEmail) ?>" style="flex:1;min-width:160px;">
    <button type="submit">Buscar</button>
    <?php if ($fNombre || $fTelefono || $fEmail): ?>
        <a href="?key=<?= urlencode($providedKey) ?>" style="font-size:12px;color:#94a3b8;">limpiar</a>
    <?php endif; ?>
</form>
</div>

<h2>2. Umbrales V3 aplicados ahora</h2>
<div class="card">
<div class="kv">
    <div>KO: score mínimo</div><div><strong><?= $V3['KO']['scoreMin'] ?></strong> — aplicaciones con score < este valor van a NO_VIABLE</div>
    <div>KO: PTI extremo</div><div><strong><?= pct($V3['KO']['ptiExtreme']) ?></strong> — si pagos + Voltika > ingreso × este %, rechazo</div>
    <div>KO: DPD 90+</div><div><strong><?= $V3['KO']['severeDPD'] ? 'ACTIVO' : 'inactivo' ?></strong> — atraso 90+ días = rechazo automático</div>
    <div>PREAPROBADO</div><div>score ≥ <strong><?= $V3['PRE']['scoreMin'] ?></strong> <em>AND</em> PTI ≤ <strong><?= pct($V3['PRE']['ptiMax']) ?></strong></div>
    <div>Guardrail</div><div>score ≤ <strong><?= $V3['CONDITIONAL']['lowScorePTIGuardrail']['scoreMax'] ?></strong> <em>AND</em> PTI > <strong><?= pct($V3['CONDITIONAL']['lowScorePTIGuardrail']['ptiMax']) ?></strong> → NO_VIABLE</div>
    <div>CONDICIONAL</div><div>caso restante (score aceptable pero PTI alto — enganche mayor o plazo menor)</div>
</div>
</div>

<h2>3. Aplicaciones (<?= count($preaprobs) ?>)<?= ($fNombre || $fTelefono || $fEmail) ? ' — filtrado' : '' ?></h2>
<?php if ($dbError): ?>
<div class="card" style="border-color:#ef4444;"><span class="bad">❌ Error:</span> <code style="color:#fca5a5;"><?= htmlspecialchars($dbError) ?></code></div>
<?php endif; ?>

<?php if (!$preaprobs): ?>
<div class="card"><span class="warn">Sin aplicaciones que coincidan. <?= ($fNombre || $fTelefono || $fEmail) ? 'Prueba filtros más amplios.' : 'La tabla preaprobaciones está vacía.' ?></span></div>
<?php else: ?>

<?php foreach ($preaprobs as $row):
    $reasons = parseReasons($row['reasons'] ?? '');
    $score = $row['score'];
    $pti   = $row['pti_total'];
    $buro  = findBuroForPreaprob($pdo, $row);
    $cdc   = findCdcForPreaprob($pdo, $row);

    // What stage failed (if NO_VIABLE)?
    $firstReason = $reasons[0] ?? null;
    $explain = $firstReason ? ($reasonExplanations[$firstReason] ?? 'Motivo no registrado en el diccionario.') : null;
?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
        <div style="font-size:14px;">
            <strong><?= htmlspecialchars(($row['nombre'] ?? '') . ' ' . ($row['apellido_paterno'] ?? '') . ' ' . ($row['apellido_materno'] ?? '')) ?></strong>
            <span style="color:#94a3b8;margin-left:8px;">· <?= htmlspecialchars($row['telefono'] ?? '') ?> · <?= htmlspecialchars($row['email'] ?? '') ?></span>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
            <?= statusBadge((string)($row['status'] ?? '')) ?>
            <span style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($row['freg'] ?? '') ?></span>
        </div>
    </div>

    <?php if ($reasons): ?>
    <div style="margin-bottom:10px;">
        <?php foreach ($reasons as $r): ?>
            <span class="reason-chip"><?= htmlspecialchars($r) ?></span>
        <?php endforeach; ?>
    </div>
    <?php if ($explain): ?>
        <div style="background:#0b1220;border-left:3px solid #f59e0b;padding:10px 12px;border-radius:4px;margin-bottom:10px;font-size:12.5px;line-height:1.5;">
            💡 <strong>Motivo:</strong> <?= htmlspecialchars($explain) ?>
        </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="kv">
        <div>Score CDC</div><div>
            <?php if ($score === null): ?>
                <span class="warn">null — CDC no devolvió score</span>
            <?php else: ?>
                <strong><?= (int)$score ?></strong>
                <?php
                $color = '#ef4444';
                if ($score >= $V3['PRE']['scoreMin']) $color = '#10b981';
                elseif ($score >= $V3['KO']['scoreMin']) $color = '#f59e0b';
                ?>
                <span style="color:<?= $color ?>;">
                    (umbral PRE ≥ <?= $V3['PRE']['scoreMin'] ?>, KO < <?= $V3['KO']['scoreMin'] ?>)
                </span>
            <?php endif; ?>
        </div>
        <div>Source CDC</div><div><?= htmlspecialchars($row['circulo_source'] ?? '—') ?></div>
        <div>PTI total</div><div>
            <?php
                $ptiVal = (float)$pti;
                $ptiColor = '#10b981';
                if ($ptiVal > $V3['KO']['ptiExtreme'])      $ptiColor = '#ef4444';
                elseif ($ptiVal > $V3['PRE']['ptiMax'])     $ptiColor = '#f59e0b';
                $ptiPct = min(100, $ptiVal * 100);
            ?>
            <strong><?= pct($pti) ?></strong>
            <span class="pti-bar"><span class="pti-fill" style="width:<?= $ptiPct ?>%;background:<?= $ptiColor ?>;"></span></span>
            <span style="color:#94a3b8;font-size:11px;">PRE ≤ <?= pct($V3['PRE']['ptiMax']) ?>, KO > <?= pct($V3['KO']['ptiExtreme']) ?></span>
        </div>
        <div>Ingreso mensual</div><div><?= fmtMoney($row['ingreso_mensual']) ?></div>
        <div>Pago Voltika (mes)</div><div><?= fmtMoney($row['pago_mensual']) ?> · semanal <?= fmtMoney($row['pago_semanal']) ?></div>
        <div>Pago mensual Buró</div><div><?= fmtMoney($row['pago_mensual_buro']) ?></div>
        <div>DPD 90+</div><div><?= (!empty($row['dpd90_flag'])) ? '<span class="bad">SÍ</span>' : 'no' ?> · DPD max: <?= htmlspecialchars((string)($row['dpd_max'] ?? '—')) ?> días</div>
        <div>Modelo</div><div><?= htmlspecialchars($row['modelo'] ?? '') ?> · <?= fmtMoney($row['precio_contado']) ?></div>
        <div>Enganche elegido</div><div><?= pct($row['enganche_pct']) ?></div>
        <div>Enganche requerido</div><div><?= $row['enganche_requerido'] !== null ? pct($row['enganche_requerido']) : '—' ?></div>
        <div>Plazo</div><div><?= (int)($row['plazo_meses'] ?? 0) ?> meses (max <?= (int)($row['plazo_max'] ?? 0) ?>)</div>
    </div>

    <?php if ($buro): ?>
    <details>
        <summary>📊 consultas_buro (folio <?= htmlspecialchars((string)($buro['folio_consulta'] ?? '')) ?>)</summary>
        <div class="kv" style="margin-top:6px;">
            <div>Score devuelto</div><div><?= htmlspecialchars((string)$buro['score']) ?></div>
            <div>Pago mensual</div><div><?= fmtMoney($buro['pago_mensual']) ?></div>
            <div># cuentas</div><div><?= htmlspecialchars((string)$buro['num_cuentas']) ?></div>
            <div>DPD 90+</div><div><?= !empty($buro['dpd90_flag']) ? 'SÍ' : 'no' ?> · max <?= htmlspecialchars((string)$buro['dpd_max']) ?> días</div>
            <div>Fecha consulta</div><div><?= htmlspecialchars((string)$buro['freg']) ?></div>
        </div>
    </details>
    <?php else: ?>
    <div style="color:#f59e0b;font-size:12px;margin-top:6px;">⚠ No se encontró fila correspondiente en <code>consultas_buro</code> — probable que la consulta CDC nunca terminó bien.</div>
    <?php endif; ?>

    <?php if ($cdc): ?>
    <details>
        <summary>🌐 cdc_query_log (HTTP <?= htmlspecialchars((string)$cdc['http_code']) ?>)</summary>
        <div style="margin-top:6px;">
            <div style="font-size:12px;color:#94a3b8;margin-bottom:4px;">Body enviado a CDC:</div>
            <pre><?= htmlspecialchars((string)$cdc['body_sent']) ?></pre>
            <div style="font-size:12px;color:#94a3b8;margin:8px 0 4px;">Respuesta de CDC:</div>
            <pre><?php
                $decoded = json_decode((string)$cdc['response'], true);
                echo htmlspecialchars(
                    is_array($decoded)
                        ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        : (string)$cdc['response']
                );
            ?></pre>
            <?php if (!empty($cdc['curl_err'])): ?>
                <div class="bad" style="font-size:12px;margin-top:4px;">curl: <?= htmlspecialchars($cdc['curl_err']) ?></div>
            <?php endif; ?>
        </div>
    </details>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php endif; // preaprobs ?>

<h2>4. Enlaces relacionados</h2>
<div class="card" style="font-size:13px;">
    <div>→ <a href="truora-diag.php<?= getenv('TRUORA_DIAG_KEY') ? '?key=' . urlencode(getenv('TRUORA_DIAG_KEY')) : '' ?>">Truora diagnostic</a> (verificar identidad)</div>
    <div>→ <a href="cdc-connection-test.php?key=voltika_cdc_2026">CDC connection test</a> (ping directo)</div>
    <div>→ <a href="../../clientes/php/diag/verificar-fix.php?key=voltika_acta_2026">Portal cliente / ACTA</a></div>
</div>

<h2>5. Cómo interpretar los motivos</h2>
<div class="card" style="font-size:13px;line-height:1.6;">
    <?php foreach ($reasonExplanations as $code => $exp): ?>
        <div style="margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid #334155;">
            <span class="reason-chip"><?= htmlspecialchars($code) ?></span>
            <div style="margin-top:4px;color:#cbd5e1;"><?= htmlspecialchars($exp) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div style="text-align:center;color:#64748b;margin-top:40px;font-size:11px;">
    Voltika credit diagnostic · <?= date('Y-m-d H:i:s') ?>
</div>
</div>
</body>
</html>
