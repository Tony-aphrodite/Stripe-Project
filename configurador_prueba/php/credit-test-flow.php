<?php
/**
 * Credit Flow Test — end-to-end validation of the credit decision pipeline:
 *   1. consultar-buro.php (CDC, may fail gracefully)
 *   2. preaprobacion-v3.php (CDC score OR self-scoring fallback)
 *
 * Shows the FINAL decision the customer would see in the configurator.
 * Use this to verify the launch-ready credit flow works WITHOUT relying on CDC.
 *
 * Access: ?key=voltika_cdc_2026
 */

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika_cdc_2026') { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/config.php';
session_start();

$cdcResp = null; $cdcRaw = null; $finalResp = null; $finalRaw = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $base = 'http' . (!empty($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']);

    // Step 1: consultar-buro.php (tries CDC, falls back gracefully)
    $cdcPayload = [
        'primerNombre'    => $_POST['nombre']    ?? '',
        'apellidoPaterno' => $_POST['paterno']   ?? '',
        'apellidoMaterno' => $_POST['materno']   ?? '',
        'fechaNacimiento' => $_POST['fechaNac']  ?? '',
        'CP'              => $_POST['cp']        ?? '',
        'RFC'             => $_POST['rfc']       ?? '',
        'direccion'       => $_POST['direccion'] ?? '',
        'colonia'         => $_POST['colonia']   ?? '',
        'municipio'       => $_POST['municipio'] ?? '',
        'ciudad'          => $_POST['ciudad']    ?? '',
        'estado'          => $_POST['estado']    ?? '',
        'tipo_consulta'   => 'PF',
        'ingreso_nip_ciec' => 'SI', 'respuesta_leyenda' => 'SI', 'aceptacion_tyc' => 'SI',
    ];
    $ch = curl_init($base . '/consultar-buro.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($cdcPayload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $cdcRaw = curl_exec($ch);
    curl_close($ch);
    $cdcResp = json_decode($cdcRaw, true) ?: [];

    // Step 2: preaprobacion-v3.php with the CDC result + extra signals for self-scoring
    $ingreso  = floatval($_POST['ingreso']  ?? 15000);
    $precio   = floatval($_POST['precio']   ?? 100000);
    $pagoSem  = floatval($_POST['pago_sem'] ?? 1500);
    $engPct   = floatval($_POST['eng_pct']  ?? 0.30);
    $plazo    = intval($_POST['plazo']      ?? 12);

    $preaPayload = [
        'ingreso_mensual_est'  => $ingreso,
        'pago_semanal_voltika' => $pagoSem,
        'enganche_pct'         => $engPct,
        'plazo_meses'          => $plazo,
        'precio_contado'       => $precio,
        'modelo'               => 'TEST-MODELO',
        'score'                => $cdcResp['score']             ?? null,
        'pago_mensual_buro'    => $cdcResp['pago_mensual_buro'] ?? 0,
        'dpd90_flag'           => $cdcResp['dpd90_flag']        ?? false,
        'dpd_max'              => $cdcResp['dpd_max']           ?? 0,
        'fecha_nacimiento'     => $_POST['fechaNac'] ?? '',
        'email'                => $_POST['email']    ?? '',
        'cp'                   => $_POST['cp']       ?? '',
        'truora_ok'            => !empty($_POST['truora_ok']),
    ];
    $ch = curl_init($base . '/preaprobacion-v3.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($preaPayload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $finalRaw = curl_exec($ch);
    curl_close($ch);
    $finalResp = json_decode($finalRaw, true) ?: [];
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Credit Flow End-to-End Test</title>
<style>
body{font-family:Arial,sans-serif;max-width:1100px;margin:20px auto;padding:0 20px;color:#333}
h1{color:#039fe1}
form{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin:20px 0}
label{display:block;margin:8px 0 4px;font-weight:600;font-size:13px}
input,select{width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;font-size:14px;box-sizing:border-box}
.row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
.btn{margin-top:16px;padding:12px 24px;background:#039fe1;color:#fff;border:none;border-radius:6px;font-size:15px;font-weight:700;cursor:pointer}
.aprobado{background:#d1fae5;color:#065f46;padding:18px;border-radius:8px;margin:16px 0;border:2px solid #10b981;font-size:16px}
.condicional{background:#fef3c7;color:#78350f;padding:18px;border-radius:8px;margin:16px 0;border:2px solid #d97706;font-size:16px}
.noviable{background:#fee2e2;color:#991b1b;padding:18px;border-radius:8px;margin:16px 0;border:2px solid #C62828;font-size:16px}
.step{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:14px;margin:10px 0}
pre{background:#1a1a1a;color:#0f0;padding:10px;border-radius:6px;font-size:12px;white-space:pre-wrap;word-break:break-all;max-height:300px;overflow-y:auto}
.metric{display:inline-block;background:#e0f2fe;padding:6px 12px;border-radius:4px;margin:4px 4px 4px 0;font-size:13px}
</style></head><body>
<h1>🧪 Credit Flow End-to-End Test</h1>
<p>Prueba la decisión de crédito completa: <code>consultar-buro.php</code> (CDC con fallback) +
<code>preaprobacion-v3.php</code> (self-scoring si CDC falla).</p>

<form method="POST">
<h2>👤 Persona</h2>
<div class="row">
  <div><label>Nombre(s) *</label><input name="nombre" required value="<?=htmlspecialchars($_POST['nombre'] ?? 'JUAN')?>"></div>
  <div><label>Apellido Paterno *</label><input name="paterno" required value="<?=htmlspecialchars($_POST['paterno'] ?? 'GARCIA')?>"></div>
</div>
<div class="row">
  <div><label>Apellido Materno</label><input name="materno" value="<?=htmlspecialchars($_POST['materno'] ?? 'LOPEZ')?>"></div>
  <div><label>Fecha de nacimiento (YYYY-MM-DD) *</label><input name="fechaNac" required value="<?=htmlspecialchars($_POST['fechaNac'] ?? '1985-03-15')?>"></div>
</div>
<div class="row">
  <div><label>Email *</label><input name="email" type="email" required value="<?=htmlspecialchars($_POST['email'] ?? 'test@voltika.mx')?>"></div>
  <div><label>RFC (opcional)</label><input name="rfc" value="<?=htmlspecialchars($_POST['rfc'] ?? '')?>"></div>
</div>
<div class="row">
  <div><label>Direccion *</label><input name="direccion" required value="<?=htmlspecialchars($_POST['direccion'] ?? 'AV REFORMA 100')?>"></div>
  <div><label>Colonia *</label><input name="colonia" required value="<?=htmlspecialchars($_POST['colonia'] ?? 'JUAREZ')?>"></div>
</div>
<div class="row3">
  <div><label>Municipio *</label><input name="municipio" required value="<?=htmlspecialchars($_POST['municipio'] ?? 'CUAUHTEMOC')?>"></div>
  <div><label>Ciudad *</label><input name="ciudad" required value="<?=htmlspecialchars($_POST['ciudad'] ?? 'CIUDAD DE MEXICO')?>"></div>
  <div><label>Estado *</label><input name="estado" required value="<?=htmlspecialchars($_POST['estado'] ?? 'CDMX')?>"></div>
</div>
<div class="row">
  <div><label>CP *</label><input name="cp" required maxlength="5" value="<?=htmlspecialchars($_POST['cp'] ?? '03100')?>"></div>
  <div><label><input type="checkbox" name="truora_ok" value="1" <?=isset($_POST['truora_ok'])?'checked':'checked'?>> Truora pasó identidad</label></div>
</div>

<h2>💰 Datos del crédito</h2>
<div class="row3">
  <div><label>Ingreso mensual ($) *</label><input name="ingreso" type="number" required value="<?=htmlspecialchars($_POST['ingreso'] ?? '15000')?>"></div>
  <div><label>Precio moto ($) *</label><input name="precio" type="number" required value="<?=htmlspecialchars($_POST['precio'] ?? '100000')?>"></div>
  <div><label>Pago semanal ($) *</label><input name="pago_sem" type="number" required value="<?=htmlspecialchars($_POST['pago_sem'] ?? '1500')?>"></div>
</div>
<div class="row">
  <div><label>Enganche % (0.30 = 30%)</label><input name="eng_pct" type="number" step="0.01" value="<?=htmlspecialchars($_POST['eng_pct'] ?? '0.30')?>"></div>
  <div><label>Plazo (meses)</label><input name="plazo" type="number" value="<?=htmlspecialchars($_POST['plazo'] ?? '12')?>"></div>
</div>

<button type="submit" class="btn">🚀 Ejecutar evaluación completa</button>
</form>

<?php if ($finalResp !== null): ?>
<h2>📊 RESULTADO FINAL (lo que el cliente verá)</h2>
<?php
$status = $finalResp['status'] ?? '?';
$css = $status === 'PREAPROBADO' ? 'aprobado' :
       (($status === 'CONDICIONAL') ? 'condicional' : 'noviable');
?>
<div class="<?= $css ?>">
  <strong>Status:</strong> <?= htmlspecialchars($status) ?>
  <div style="margin-top:10px">
    <?php if (isset($finalResp['enganche_requerido_min'])): ?>
      <span class="metric">Enganche requerido: <?= round($finalResp['enganche_requerido_min']*100) ?>%</span>
    <?php endif; ?>
    <?php if (isset($finalResp['plazo_max_meses'])): ?>
      <span class="metric">Plazo máx: <?= $finalResp['plazo_max_meses'] ?> meses</span>
    <?php endif; ?>
    <?php if (isset($finalResp['pti_total'])): ?>
      <span class="metric">PTI: <?= round($finalResp['pti_total']*100, 1) ?>%</span>
    <?php endif; ?>
    <?php if (isset($finalResp['synth_score'])): ?>
      <span class="metric" style="background:#fef3c7">Synthetic score: <?= $finalResp['synth_score'] ?> <small>(simulado, sin CDC)</small></span>
    <?php endif; ?>
    <?php if (isset($finalResp['edad'])): ?>
      <span class="metric">Edad: <?= $finalResp['edad'] ?></span>
    <?php endif; ?>
  </div>
  <?php if (!empty($finalResp['reasons'])): ?>
    <div style="margin-top:10px;font-size:13px"><strong>Razones:</strong> <?= htmlspecialchars(implode(', ', $finalResp['reasons'])) ?></div>
  <?php endif; ?>
</div>

<h2>🔍 Detalles paso a paso</h2>
<div class="step">
  <h3>Step 1: consultar-buro.php (CDC)</h3>
  <?php
  $sinCdc = !empty($cdcResp['sin_cdc']);
  $sinHist = !empty($cdcResp['sin_historial']);
  if ($sinCdc) echo '<div style="color:#d97706"><strong>⚠️ CDC falló — fallback activado</strong> (HTTP ' . htmlspecialchars($cdcResp['cdc_http'] ?? '?') . ')</div>';
  elseif ($sinHist) echo '<div style="color:#039fe1"><strong>ℹ️ CDC respondió: persona sin historial</strong></div>';
  elseif (isset($cdcResp['score'])) echo '<div style="color:#10b981"><strong>✅ CDC respondió con score: ' . htmlspecialchars($cdcResp['score']) . '</strong></div>';
  ?>
  <pre><?= htmlspecialchars(json_encode($cdcResp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
</div>

<div class="step">
  <h3>Step 2: preaprobacion-v3.php (decisión final)</h3>
  <pre><?= htmlspecialchars(json_encode($finalResp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
</div>

<h2>🗄️ Últimas 5 decisiones en BD</h2>
<?php
try {
    $pdo = getDB();
    $rows = $pdo->query("SELECT id, modelo, score, circulo_source, status, enganche_requerido, plazo_max, freg
        FROM preaprobaciones ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        echo '<table border="1" cellpadding="6" style="border-collapse:collapse;width:100%;font-size:13px">';
        echo '<tr><th>ID</th><th>Modelo</th><th>Score</th><th>Source</th><th>Status</th><th>Eng req</th><th>Plazo</th><th>Fecha</th></tr>';
        foreach ($rows as $r) {
            echo '<tr><td>' . $r['id'] . '</td><td>' . htmlspecialchars($r['modelo']) . '</td><td>' . ($r['score'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($r['circulo_source']) . '</td><td><strong>' . htmlspecialchars($r['status']) . '</strong></td>';
            echo '<td>' . ($r['enganche_requerido'] ? round($r['enganche_requerido']*100).'%' : '-') . '</td>';
            echo '<td>' . ($r['plazo_max'] ?? '-') . '</td><td>' . $r['freg'] . '</td></tr>';
        }
        echo '</table>';
    } else echo '<p>Sin decisiones registradas todavía.</p>';
} catch (Throwable $e) { echo '<p>Error consultando BD: ' . htmlspecialchars($e->getMessage()) . '</p>'; }
?>
<?php endif; ?>

</body></html>
