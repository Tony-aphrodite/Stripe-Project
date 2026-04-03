<?php
/**
 * Voltika — Generar PDF del Acta de Entrega
 * GET ?moto_id=N&key=voltika_acta_2026
 *
 * Genera un PDF del acta de entrega usando HTML → PDF nativo del navegador (window.print)
 * Sin dependencias externas (no necesita TCPDF, DOMPDF, etc.)
 */

header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-auth.php';

$motoId = intval($_GET['moto_id'] ?? 0);
if (!$motoId) {
    http_response_code(400);
    exit('moto_id requerido');
}

// Allow either dealer auth OR key param (for direct link sharing)
$keyOk = ($_GET['key'] ?? '') === 'voltika_acta_2026';
if (!$keyOk) {
    requireDealerAuth(false);
}

$pdo = getDB();

// Get moto data
$stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ?");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$moto) {
    http_response_code(404);
    exit('Moto no encontrada');
}

// Get entrega checklist
$stmt2 = $pdo->prepare("SELECT * FROM checklist_entrega_v2 WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
$stmt2->execute([$motoId]);
$entrega = $stmt2->fetch(PDO::FETCH_ASSOC);

$fechaEntrega = $entrega && $entrega['fase5_fecha']
    ? date('d/m/Y H:i', strtotime($entrega['fase5_fecha']))
    : date('d/m/Y H:i');

$dealerNombre = $entrega['dealer_nombre_firma'] ?? 'Punto Voltika Autorizado';

$e = function($v) { return htmlspecialchars($v ?? '—', ENT_QUOTES, 'UTF-8'); };

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Acta de Entrega — <?= $e($moto['vin_display'] ?? $moto['vin']) ?></title>
<style>
@media print {
  body { margin: 0; }
  .no-print { display: none !important; }
  @page { margin: 15mm; size: letter; }
}
body {
  font-family: 'Helvetica Neue', Arial, sans-serif;
  font-size: 11px;
  color: #1a1a1a;
  line-height: 1.6;
  max-width: 700px;
  margin: 0 auto;
  padding: 20px;
}
h1 { font-size: 16px; text-align: center; margin: 0 0 4px; color: #1a3a5c; }
h2 { font-size: 13px; text-align: center; margin: 0 0 12px; color: #555; font-weight: 400; }
.header-line { border-top: 2px solid #1a3a5c; border-bottom: 1px solid #1a3a5c; padding: 4px 0; margin-bottom: 16px; }
.section-title {
  font-size: 11px; font-weight: 700; color: #1a3a5c;
  text-transform: uppercase; letter-spacing: 0.5px;
  border-bottom: 1.5px solid #1a3a5c; padding-bottom: 4px; margin: 16px 0 8px;
}
table.data { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
table.data td { padding: 4px 8px; border-bottom: 1px solid #eee; font-size: 11px; }
table.data td:first-child { color: #666; width: 40%; }
table.data td:last-child { font-weight: 600; }
.clause {
  background: #f8fafc; border-left: 3px solid #1a3a5c;
  padding: 8px 12px; margin: 6px 0; font-size: 10.5px; line-height: 1.5;
}
.clause-num { font-weight: 700; color: #1a3a5c; }
.check-item { padding: 3px 0; font-size: 11px; }
.check-icon { color: #10b981; font-weight: 700; }
.signatures {
  display: flex; gap: 40px; margin-top: 30px; padding-top: 20px;
  border-top: 1px solid #ccc;
}
.sig-block { flex: 1; text-align: center; }
.sig-line { border-top: 1px solid #333; margin-top: 40px; padding-top: 6px; font-size: 10px; color: #666; }
.footer { text-align: center; font-size: 9px; color: #999; margin-top: 20px; border-top: 1px solid #eee; padding-top: 8px; }
.btn-print {
  display: block; width: 200px; margin: 20px auto;
  padding: 12px; background: #039fe1; color: #fff;
  border: none; border-radius: 8px; font-size: 14px;
  font-weight: 700; cursor: pointer; text-align: center;
}
.btn-print:hover { background: #027db0; }
</style>
</head>
<body>

<div class="no-print" style="text-align:center;margin-bottom:20px;">
  <button class="btn-print" onclick="window.print()">📄 Imprimir / Guardar como PDF</button>
  <p style="font-size:11px;color:#666;">Usa "Guardar como PDF" en el diálogo de impresión para descargar.</p>
</div>

<div class="header-line"></div>

<h1>ACTA DE ENTREGA DE MOTOCICLETA ELÉCTRICA</h1>
<h2>VOLTIKA — MTECH GEARS, S.A. DE C.V.</h2>

<p style="text-align:center;font-size:10.5px;color:#555;margin-bottom:16px;">
En este acto, el cliente declara haber recibido la motocicleta eléctrica descrita en el presente documento, en condiciones óptimas de funcionamiento, completa y conforme a lo contratado.
</p>

<!-- DATOS DE LA OPERACIÓN -->
<div class="section-title">Datos de la Operación</div>
<table class="data">
<tr><td>Nombre del cliente</td><td><?= $e($moto['cliente_nombre']) ?></td></tr>
<tr><td>Modelo</td><td><?= $e($moto['modelo']) ?></td></tr>
<tr><td>Color</td><td><?= $e($moto['color']) ?></td></tr>
<tr><td>VIN</td><td><?= $e($moto['vin']) ?></td></tr>
<tr><td>Número de contrato</td><td><?= $e($moto['pedido_num']) ?></td></tr>
<tr><td>Fecha y hora de entrega</td><td><?= $fechaEntrega ?></td></tr>
<tr><td>Lugar de entrega</td><td><?= $e($moto['punto_nombre']) ?></td></tr>
</table>

<!-- DECLARACIONES DEL CLIENTE -->
<div class="section-title">Declaraciones del Cliente</div>

<div class="clause">
<span class="clause-num">1. Identidad</span><br>
Declaro bajo protesta de decir verdad que soy la persona titular del contrato de compraventa y/o crédito previamente celebrado con Mtech Gears, S.A. de C.V. (Voltika), o en su caso, que cuento con autorización válida para recibir la unidad.
</div>

<div class="clause">
<span class="clause-num">2. Validación de la unidad</span><br>
He verificado que la motocicleta corresponde al modelo, versión, color y número de serie (VIN) establecidos en mi pedido.
</div>

<div class="clause">
<span class="clause-num">3. Condición física</span><br>
La motocicleta se encuentra en buen estado físico, sin daños visibles, completa y funcional al momento de la entrega.
</div>

<div class="clause">
<span class="clause-num">4. Componentes</span><br>
Confirmo que recibí la motocicleta con todos sus componentes, accesorios, llaves, cargador, manual y documentación correspondiente.
</div>

<div class="clause">
<span class="clause-num">5. Funcionamiento</span><br>
Se me mostró el funcionamiento básico de la unidad, incluyendo encendido, modos de manejo, carga y operación general.
</div>

<!-- ACEPTACIÓN DE ENTREGA -->
<div class="section-title">Aceptación de Entrega</div>

<div class="check-item"><span class="check-icon">✔</span> Acepto la entrega de la motocicleta a mi entera satisfacción.</div>
<div class="check-item"><span class="check-icon">✔</span> Reconozco que cualquier daño visible, faltante o defecto aparente debió ser señalado al momento de la entrega.</div>
<div class="check-item"><span class="check-icon">✔</span> Libero expresamente a Mtech Gears, S.A. de C.V. (Voltika) de cualquier responsabilidad posterior relacionada con daños visibles, faltantes y condiciones físicas de la unidad, salvo aquellos casos cubiertos por la garantía aplicable.</div>

<!-- VALIDACIÓN ELECTRÓNICA Y LEGAL -->
<div class="section-title">Validación Electrónica y Legal</div>

<p>El cliente acepta que:</p>
<ul style="margin:4px 0;padding-left:18px;font-size:10.5px;">
<li>La presente acta se firma mediante medios electrónicos</li>
<li>La validación puede realizarse mediante OTP, firma digital o aceptación en pantalla</li>
<li>Este documento tiene plena validez jurídica conforme a la legislación mexicana vigente</li>
<li>Forma parte integral del contrato de compraventa y/o crédito firmado previamente</li>
</ul>

<!-- PROTECCIÓN Y USO DE INFORMACIÓN -->
<div class="section-title">Protección y Uso de Información</div>

<p>El cliente autoriza expresamente a Voltika a:</p>
<ul style="margin:4px 0;padding-left:18px;font-size:10.5px;">
<li>Registrar evidencia fotográfica de la entrega</li>
<li>Utilizar registros digitales, OTP y validaciones para fines legales</li>
<li>Usar esta información para: defensa ante contracargos, procesos legales, cumplimiento ante autoridades (PROFECO, SAT, INAI)</li>
</ul>

<!-- ENTREGA Y RECEPCIÓN -->
<div class="section-title">Entrega y Recepción</div>

<p>Declaro que la motocicleta fue entregada físicamente en este acto, quedando bajo mi posesión y responsabilidad a partir de este momento. Asimismo, reconozco que la validación electrónica de esta entrega constituye prueba suficiente de recepción.</p>

<!-- VALIDACIÓN FINAL -->
<div class="section-title">Validación Final</div>

<div class="check-item"><span class="check-icon">✔</span> Acepto los términos de entrega</div>
<div class="check-item"><span class="check-icon">✔</span> OTP validado: <?= ($entrega && $entrega['otp_validado']) ? '<strong style="color:#10b981;">Sí</strong>' : '<strong style="color:#C62828;">Pendiente</strong>' ?></div>

<!-- FIRMAS -->
<div class="signatures">
  <div class="sig-block">
    <div class="sig-line">
      <?= $e($moto['cliente_nombre']) ?><br>
      <strong>Cliente</strong>
    </div>
  </div>
  <div class="sig-block">
    <div class="sig-line">
      <?= $e($dealerNombre) ?><br>
      <strong>Punto Voltika Autorizado</strong>
    </div>
  </div>
</div>

<!-- CLÁUSULA FINAL -->
<div class="footer">
  La presente acta constituye prueba plena de la entrega de la motocicleta, su aceptación por parte del cliente y la conformidad con el estado de la unidad al momento de la entrega.<br>
  <strong>VOLTIKA — MTECH GEARS, S.A. DE C.V.</strong> · Folio: VK-<?= $motoId ?>-<?= date('Ymd') ?>
</div>

</body>
</html>
