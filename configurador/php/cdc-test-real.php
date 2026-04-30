<?php
/**
 * CDC Real Data Test — form page that calls consultar-buro.php directly
 * with REAL customer data so admin can verify CDC integration end-to-end
 * without going through the full configurador UI.
 *
 * Access: ?key=voltika_cdc_2026
 */

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika_cdc_2026') { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/config.php';
session_start();

$result = null; $rawResponse = null; $lastBodySent = null; $lastCdcResp = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Call consultar-buro.php via http loopback with real data from form
    $payload = [
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
        'tipo_consulta'      => 'PF',
        'ingreso_nip_ciec'   => 'SI',
        'respuesta_leyenda'  => 'SI',
        'aceptacion_tyc'     => 'SI',
    ];

    $url = 'http' . (!empty($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/consultar-buro.php';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $rawResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $result = json_decode($rawResponse, true);

    // Get the last CDC log entry from DB
    try {
        $pdo = getDB();
        $row = $pdo->query("SELECT body_sent, response, http_code, freg FROM cdc_query_log ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) { $lastBodySent = $row['body_sent']; $lastCdcResp = $row['response']; }
    } catch (Throwable $e) {}
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>CDC Real Data Test</title>
<style>
body{font-family:Arial,sans-serif;max-width:1000px;margin:20px auto;padding:0 20px;color:#333}
h1{color:#039fe1} h2{margin-top:30px}
form{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin:20px 0}
label{display:block;margin:8px 0 4px;font-weight:600;font-size:13px}
input,select{width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;font-size:14px;box-sizing:border-box}
.row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.btn{margin-top:16px;padding:12px 24px;background:#039fe1;color:#fff;border:none;border-radius:6px;font-size:15px;font-weight:700;cursor:pointer}
.btn:hover{background:#027db0}
.ok{background:#d1fae5;color:#065f46;padding:14px;border-radius:8px;margin:16px 0;border:1px solid #10b981}
.err{background:#fee2e2;color:#991b1b;padding:14px;border-radius:8px;margin:16px 0;border:1px solid #C62828}
.warn{background:#fef3c7;color:#78350f;padding:14px;border-radius:8px;margin:16px 0}
pre{background:#1a1a1a;color:#0f0;padding:12px;border-radius:6px;overflow-x:auto;font-size:12px;white-space:pre-wrap;word-break:break-all;max-height:400px}
table{border-collapse:collapse;width:100%;margin:10px 0}
td,th{border:1px solid #ddd;padding:6px 10px;font-size:13px;text-align:left}
</style></head><body>
<h1>🔬 CDC Real Data Test</h1>
<p>Prueba directa con datos reales. Llama <code>consultar-buro.php</code> en loopback, así que ves exactamente lo que el cliente verá.</p>

<form method="POST">
<h2>Persona</h2>
<div class="row">
  <div><label>Nombre(s) *</label><input name="nombre" required value="<?=htmlspecialchars($_POST['nombre'] ?? '')?>"></div>
  <div><label>Apellido Paterno *</label><input name="paterno" required value="<?=htmlspecialchars($_POST['paterno'] ?? '')?>"></div>
</div>
<div class="row">
  <div><label>Apellido Materno</label><input name="materno" value="<?=htmlspecialchars($_POST['materno'] ?? '')?>"></div>
  <div><label>Fecha de nacimiento (YYYY-MM-DD) *</label><input name="fechaNac" required placeholder="1985-03-15" value="<?=htmlspecialchars($_POST['fechaNac'] ?? '')?>"></div>
</div>
<div class="row">
  <div><label>RFC (opcional — se autocalcula)</label><input name="rfc" maxlength="13" style="text-transform:uppercase" value="<?=htmlspecialchars($_POST['rfc'] ?? '')?>"></div>
  <div><label>CP *</label><input name="cp" required maxlength="5" value="<?=htmlspecialchars($_POST['cp'] ?? '')?>"></div>
</div>

<h2>Domicilio</h2>
<div class="row">
  <div><label>Calle y número *</label><input name="direccion" required value="<?=htmlspecialchars($_POST['direccion'] ?? '')?>"></div>
  <div><label>Colonia *</label><input name="colonia" required value="<?=htmlspecialchars($_POST['colonia'] ?? '')?>"></div>
</div>
<div class="row">
  <div><label>Municipio / Delegación *</label><input name="municipio" required value="<?=htmlspecialchars($_POST['municipio'] ?? '')?>"></div>
  <div><label>Ciudad *</label><input name="ciudad" required value="<?=htmlspecialchars($_POST['ciudad'] ?? '')?>"></div>
</div>
<label>Estado * (usa CDMX, JAL, NL, MEX, BC, etc. o nombre completo)</label>
<input name="estado" required value="<?=htmlspecialchars($_POST['estado'] ?? 'CDMX')?>">

<button type="submit" class="btn">🚀 Consultar CDC</button>
</form>

<?php if ($result !== null || $rawResponse !== null): ?>
<h2>Resultado</h2>
<?php
$isOk = $result && ($result['success'] ?? false);
$isNotFound = $result && isset($result['error']) && strpos($rawResponse ?? '', '404') !== false;
if ($isOk) {
    echo '<div class="ok"><strong>🎉 CDC respondió exitosamente</strong><br>';
    if (isset($result['score']))             echo '<strong>Score:</strong> ' . htmlspecialchars($result['score']) . '<br>';
    if (isset($result['pago_mensual_buro'])) echo '<strong>Pago mensual buró:</strong> $' . number_format((float)$result['pago_mensual_buro'], 2) . '<br>';
    if (isset($result['dpd90_flag']))        echo '<strong>DPD 90+:</strong> ' . ($result['dpd90_flag'] ? 'Sí' : 'No') . '<br>';
    if (isset($result['dpd_max']))           echo '<strong>Max DPD:</strong> ' . htmlspecialchars($result['dpd_max']) . ' días<br>';
    if (isset($result['num_cuentas']))       echo '<strong>Número de cuentas:</strong> ' . htmlspecialchars($result['num_cuentas']) . '<br>';
    if (isset($result['folioConsulta']))     echo '<strong>Folio consulta:</strong> ' . htmlspecialchars($result['folioConsulta']) . '<br>';
    echo '</div>';
} else {
    echo '<div class="err"><strong>❌ Error</strong><br>' . htmlspecialchars($result['message'] ?? $result['error'] ?? 'sin mensaje') . '</div>';
}
?>
<h2>Response raw de consultar-buro.php</h2>
<pre><?=htmlspecialchars($rawResponse ?? '(vacío)')?></pre>

<?php if ($lastBodySent): ?>
<h2>Body enviado a CDC</h2>
<pre><?= htmlspecialchars($lastBodySent) ?></pre>
<h2>Respuesta cruda de CDC</h2>
<pre><?= htmlspecialchars($lastCdcResp) ?></pre>
<?php endif; ?>
<?php endif; ?>

<h2>Enlaces</h2>
<ul>
  <li><a href="cdc-preflight.php?key=voltika_cdc_2026">cdc-preflight.php</a> — diagnóstico completo</li>
  <li><a href="../index.html">Configurador</a> — flujo completo cliente</li>
</ul>
</body></html>
