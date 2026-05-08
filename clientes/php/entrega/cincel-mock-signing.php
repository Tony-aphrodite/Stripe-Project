<?php
/**
 * MOCK Cincel signing page — solo para testing cuando la auth real con
 * Cincel falla. Permite verificar que el resto del flujo (webhook, polling,
 * customer portal completion) funciona correctamente.
 *
 * Flow:
 *   1. Cliente abre cincel-mock-signing.php?moto_id=N en el iframe
 *   2. Hace click en "Simular firma"
 *   3. Esa acción dispara webhook simulado que pone cliente_acta_firmada=1
 *   4. El portal (que está poleando cincel-acta-status.php) detecta signed=true
 *   5. UI avanza a "¡ACTA firmada!"
 *
 * NO es para producción — es solo para desbloquear el testing del Bug 5.7.
 */
require_once __DIR__ . '/../bootstrap.php';

// Override the default JSON content-type that bootstrap.php sets — this is
// an HTML page, not an API response. Without this, the browser shows the
// raw HTML source instead of rendering it.
header_remove('Content-Type');
header('Content-Type: text/html; charset=UTF-8');

$cid    = portalRequireAuth();
$motoId = (int)($_GET['moto_id'] ?? 0);

if (!$motoId) { http_response_code(400); exit('moto_id requerido'); }

$moto = portalFindOwnedMoto($cid, $motoId);
if (!$moto) { http_response_code(404); exit('Moto no encontrada'); }

$pdo = getDB();

// Si llegan ?confirm=1, marcamos como firmado.
if (!empty($_GET['confirm'])) {
    try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cliente_acta_firmada TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cliente_acta_fecha DATETIME NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cliente_acta_firma VARCHAR(150) NULL"); } catch (Throwable $e) {}

    $pdo->prepare("UPDATE inventario_motos
        SET cincel_acta_status   = 'firmado',
            cliente_acta_firmada = 1,
            cliente_acta_fecha   = COALESCE(cliente_acta_fecha, NOW()),
            cliente_acta_firma   = COALESCE(cliente_acta_firma, ?)
        WHERE id = ?")
        ->execute([$moto['cliente_nombre'] ?? 'Test User', $motoId]);

    portalLog('cincel_acta_mock_signed', ['cliente_id' => $cid, 'detalle' => 'moto=' . $motoId]);

    // Página de confirmación
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<style>
  body { font-family:system-ui;background:#0c4a6e;color:#fff;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;padding:20px;text-align:center; }
  .box { background:#fff;color:#111;border-radius:12px;padding:40px;max-width:420px; }
  .ok { font-size:60px;color:#16a34a; }
  h1 { color:#16a34a; }
</style></head><body>
<div class="box">
  <div class="ok">✓</div>
  <h1>Firma simulada completada</h1>
  <p>El portal cliente detectará la firma en pocos segundos y avanzará automáticamente.</p>
  <p style="font-size:12px;color:#888;">Esto es solo modo TEST — en producción usaría Cincel real.</p>
</div>
</body></html>';
    exit;
}

// Página principal del mock — botón "Simular firma"
?>
<!DOCTYPE html>
<html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Mock Cincel — Test Mode</title>
<style>
  body { font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif; margin:0; padding:20px; background:#f1f5f9; }
  .container { max-width:480px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,.08); }
  .header { background:linear-gradient(135deg,#1a3a5c,#039fe1); color:#fff; padding:24px; }
  .header h1 { margin:0; font-size:22px; }
  .header p { margin:6px 0 0; font-size:13px; opacity:.9; }
  .content { padding:24px; }
  .alert { background:#fef3c7; border-left:4px solid #f59e0b; padding:12px 14px; border-radius:6px; margin-bottom:18px; font-size:13px; color:#92400e; }
  .moto-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px; margin-bottom:18px; }
  .moto-card .label { font-size:11px; color:#64748b; text-transform:uppercase; }
  .moto-card .value { font-size:15px; font-weight:700; color:#111; margin-top:2px; }
  .step { display:flex; align-items:center; gap:10px; padding:8px 0; }
  .step .num { width:24px; height:24px; border-radius:50%; background:#cbd5e1; color:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; flex-shrink:0; }
  .step.done .num { background:#16a34a; }
  .step.active .num { background:#039fe1; }
  .btn { display:block; width:100%; padding:14px; background:#16a34a; color:#fff; border:none; border-radius:8px; font-size:15px; font-weight:700; cursor:pointer; margin-top:18px; text-decoration:none; text-align:center; }
  .btn:hover { background:#15803d; }
</style>
</head><body>
<div class="container">
  <div class="header">
    <h1>🔐 Cincel — Modo Test</h1>
    <p>Firma electrónica con validez NOM-151</p>
  </div>
  <div class="content">
    <div class="alert">
      <strong>⚠ MODO TEST</strong><br>
      Esta es una simulación de la interfaz Cincel para verificar el flujo completo de firma. En producción aparecería el formulario real de Cincel (captura INE, selfie, OTP).
    </div>

    <div class="moto-card">
      <div class="label">Documento a firmar</div>
      <div class="value">ACTA DE ENTREGA — <?= htmlspecialchars($moto['modelo'] ?? '') ?> <?= htmlspecialchars($moto['color'] ?? '') ?></div>
      <div style="font-size:12px;color:#64748b;margin-top:4px;font-family:monospace;">VIN: <?= htmlspecialchars($moto['vin'] ?? $moto['vin_display'] ?? '') ?></div>
    </div>

    <div class="step done"><div class="num">✓</div><div>Documento cargado</div></div>
    <div class="step done"><div class="num">✓</div><div>Identidad validada (OTP previo)</div></div>
    <div class="step active"><div class="num">3</div><div>Confirmación de firma</div></div>

    <a class="btn" href="?moto_id=<?= $motoId ?>&confirm=1">✍ Simular firma del cliente</a>
  </div>
</div>
</body></html>
