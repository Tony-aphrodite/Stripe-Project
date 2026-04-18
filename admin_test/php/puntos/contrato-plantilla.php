<?php
/**
 * GET — Printable contract template for new Punto affiliates.
 * Accepts optional ?punto_id=N to prefill placeholders.
 * Opens in browser; use Ctrl+P to save as PDF.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$puntoId = isset($_GET['punto_id']) ? (int)$_GET['punto_id'] : 0;
$p = null;
if ($puntoId) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM puntos_voltika WHERE id=?");
        $stmt->execute([$puntoId]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

$nombrePunto   = $p['nombre']    ?? '_________________________';
$direccion     = trim(($p['direccion'] ?? '') . ', ' . ($p['colonia'] ?? '') . ', ' . ($p['ciudad'] ?? '') . ', ' . ($p['estado'] ?? '') . ' CP ' . ($p['cp'] ?? ''), ', ');
if (trim($direccion, ', ') === '') $direccion = '_________________________________________________';
$telefono      = $p['telefono']  ?? '_______________';
$email         = $p['email']     ?? '_______________';
$codigoVenta   = $p['codigo_venta'] ?? '_______________';
$fecha         = date('d \d\e F \d\e Y');

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Contrato de Afiliación — Punto Voltika</title>
<style>
  @page { size: A4; margin: 22mm; }
  body { font-family: 'Times New Roman', Times, serif; color: #111; line-height: 1.7; max-width: 780px; margin: 0 auto; padding: 30px; font-size: 14px; }
  h1 { text-align: center; font-size: 20px; margin-bottom: 4px; }
  h2 { text-align: center; font-size: 15px; font-weight: normal; margin-top: 0; color: #444; }
  h3 { font-size: 15px; margin-top: 24px; text-decoration: underline; }
  .clause { margin: 12px 0; text-align: justify; }
  .fill { border-bottom: 1px solid #000; padding: 0 4px; font-weight: bold; }
  .firmas { margin-top: 60px; display: flex; justify-content: space-between; gap: 40px; }
  .firma-box { flex: 1; text-align: center; }
  .firma-box .linea { border-top: 1px solid #000; margin-top: 50px; padding-top: 6px; font-size: 12px; }
  .print-btn { position: fixed; top: 10px; right: 10px; padding: 10px 18px; background: #1565c0; color: #fff; border: 0; border-radius: 6px; cursor: pointer; font-weight: 700; }
  @media print { .print-btn { display: none; } }
  .header-logo { text-align: center; margin-bottom: 10px; font-size: 12px; color: #666; }
</style>
</head>
<body>

<button class="print-btn" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>

<div class="header-logo">VOLTIKA MÉXICO</div>

<h1>CONTRATO DE AFILIACIÓN DE PUNTO VOLTIKA</h1>
<h2>Entre Voltika México y el Punto Afiliado</h2>

<div class="clause">
  En la Ciudad de <span class="fill">________________</span>, a los <span class="fill"><?= $fecha ?></span>, se celebra el presente <strong>Contrato de Afiliación</strong> (en adelante, "El Contrato") entre las siguientes partes:
</div>

<h3>DECLARACIONES</h3>
<div class="clause">
  <strong>1.1</strong> Declara <strong>VOLTIKA MÉXICO</strong> (en adelante, "La Empresa"):<br>
  — Ser una persona moral legalmente constituida conforme a las leyes de los Estados Unidos Mexicanos.<br>
  — Tener su domicilio fiscal en la Ciudad de México.<br>
  — Dedicarse a la comercialización de motocicletas eléctricas y servicios relacionados.
</div>

<div class="clause">
  <strong>1.2</strong> Declara el <strong>PUNTO AFILIADO</strong> (en adelante, "El Punto"):<br>
  — Denominación / Razón social: <span class="fill"><?= htmlspecialchars($nombrePunto) ?></span><br>
  — Domicilio: <span class="fill"><?= htmlspecialchars($direccion) ?></span><br>
  — Teléfono: <span class="fill"><?= htmlspecialchars($telefono) ?></span><br>
  — Correo electrónico: <span class="fill"><?= htmlspecialchars($email) ?></span><br>
  — Código Voltika asignado: <span class="fill"><?= htmlspecialchars($codigoVenta) ?></span><br>
  — Representante legal: <span class="fill">_______________________________</span><br>
  — Identificación oficial: <span class="fill">_______________________________</span>
</div>

<h3>CLÁUSULAS</h3>

<div class="clause">
  <strong>PRIMERA. Objeto.</strong> La Empresa autoriza al Punto a operar como <strong>Punto de Entrega y/o Centro Certificado Voltika</strong>, recibir motocicletas en consignación, entregarlas a clientes finales de la Empresa y brindar servicio posventa conforme a los estándares de la marca.
</div>

<div class="clause">
  <strong>SEGUNDA. Obligaciones del Punto.</strong> El Punto se obliga a:
  <ul>
    <li>Recibir, resguardar y entregar las motocicletas en las condiciones establecidas por La Empresa.</li>
    <li>Utilizar exclusivamente el <strong>Panel Voltika</strong> para registrar entregas, checklists y entregas al cliente.</li>
    <li>Cumplir el protocolo de verificación de identidad (OTP + identificación oficial) antes de cada entrega.</li>
    <li>Mantener en buen estado el inventario y reportar cualquier incidencia en un plazo máximo de 24 horas.</li>
    <li>Capacitarse y capacitar a su personal usando el <em>Manual del Operador de Punto Voltika</em>.</li>
    <li>No compartir credenciales de acceso al Panel Voltika con terceros.</li>
  </ul>
</div>

<div class="clause">
  <strong>TERCERA. Obligaciones de La Empresa.</strong> La Empresa se obliga a:
  <ul>
    <li>Proveer acceso al <strong>Panel Voltika para Puntos</strong> y capacitación inicial.</li>
    <li>Enviar motocicletas con la documentación y trazabilidad correspondiente.</li>
    <li>Pagar comisiones conforme a la Cláusula Cuarta.</li>
    <li>Brindar soporte técnico y operativo en horario laboral.</li>
  </ul>
</div>

<div class="clause">
  <strong>CUARTA. Comisiones.</strong> La Empresa pagará al Punto una comisión por cada entrega exitosa, conforme al siguiente esquema:
  <ul>
    <li>Comisión por entrega: <span class="fill">$________</span> MXN por motocicleta entregada.</li>
    <li>Comisión por venta originada en el Punto: <span class="fill">____</span> % sobre el precio neto.</li>
    <li>Periodo de pago: <span class="fill">mensual / quincenal</span>, mediante transferencia bancaria.</li>
  </ul>
</div>

<div class="clause">
  <strong>QUINTA. Vigencia.</strong> El presente Contrato entra en vigor en la fecha de su firma y tendrá una duración de <span class="fill">12 (doce) meses</span>, renovable automáticamente por periodos iguales salvo aviso en contrario con 30 días de anticipación.
</div>

<div class="clause">
  <strong>SEXTA. Confidencialidad.</strong> El Punto se obliga a mantener en estricta confidencialidad toda la información comercial, técnica, financiera y de clientes a la que tenga acceso como consecuencia del presente Contrato, incluso después de su terminación.
</div>

<div class="clause">
  <strong>SÉPTIMA. Terminación.</strong> El presente Contrato podrá darse por terminado por cualquiera de las partes, con aviso por escrito con 30 días de anticipación, o de forma inmediata en caso de incumplimiento grave de las obligaciones aquí establecidas.
</div>

<div class="clause">
  <strong>OCTAVA. Jurisdicción.</strong> Para la interpretación y cumplimiento del presente Contrato, las partes se someten a la jurisdicción de los tribunales de la Ciudad de México, renunciando expresamente a cualquier otro fuero que pudiera corresponderles.
</div>

<div class="clause" style="margin-top:26px;">
  Leído el presente Contrato por ambas partes, y enteradas de su contenido y alcance legal, lo firman de conformidad en la fecha y lugar arriba indicados.
</div>

<div class="firmas">
  <div class="firma-box">
    <div class="linea">Por <strong>VOLTIKA MÉXICO</strong><br>Representante legal</div>
  </div>
  <div class="firma-box">
    <div class="linea">Por <strong><?= htmlspecialchars($nombrePunto) ?></strong><br>Representante legal</div>
  </div>
</div>

<div style="margin-top:40px;text-align:center;font-size:11px;color:#888;">
  Documento generado desde el Panel Voltika — <?= date('Y-m-d H:i') ?>
</div>

</body>
</html>
