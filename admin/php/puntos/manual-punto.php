<?php
/**
 * GET — Printable operator manual for new Punto Voltika staff.
 * Opens in a browser; use Ctrl+P to save as PDF.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Manual del Operador — Punto Voltika</title>
<style>
  @page { size: A4; margin: 20mm; }
  body { font-family: Arial, Helvetica, sans-serif; color: #222; line-height: 1.55; max-width: 800px; margin: 0 auto; padding: 24px; }
  h1 { color: #0b2559; border-bottom: 3px solid #22d37a; padding-bottom: 8px; }
  h2 { color: #0b2559; border-bottom: 1px solid #ddd; padding-bottom: 4px; margin-top: 28px; }
  h3 { color: #0b2559; margin-top: 18px; }
  code { background: #f0f4f8; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
  .step { background: #f7f9fc; border-left: 4px solid #22d37a; padding: 10px 14px; margin: 8px 0; }
  .warning { background: #fff4e0; border-left: 4px solid #e65100; padding: 10px 14px; margin: 8px 0; }
  .info { background: #e3f2fd; border-left: 4px solid #1565c0; padding: 10px 14px; margin: 8px 0; }
  table { border-collapse: collapse; width: 100%; margin: 10px 0; }
  th, td { border: 1px solid #ccc; padding: 8px; text-align: left; font-size: 13px; }
  th { background: #0b2559; color: #fff; }
  .print-btn { position: fixed; top: 10px; right: 10px; padding: 10px 18px; background: #22d37a; color: #fff; border: 0; border-radius: 6px; cursor: pointer; font-weight: 700; }
  @media print { .print-btn { display: none; } }
  .cover { text-align: center; padding: 40px 0; }
  .cover img { max-width: 200px; margin-bottom: 20px; }
  .toc { background: #f7f9fc; padding: 16px; border-radius: 8px; margin: 20px 0; }
  .toc ol { margin: 0; padding-left: 22px; }
</style>
</head>
<body>

<button class="print-btn" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>

<div class="cover">
  <h1 style="border:0;font-size:32px;">Manual del Operador</h1>
  <div style="font-size:20px;color:#22d37a;font-weight:700;">Punto Voltika</div>
  <div style="margin-top:20px;color:#666;">Guía completa para el personal de un Punto afiliado</div>
  <div style="margin-top:40px;color:#999;font-size:12px;">Versión 1.0 — <?= date('Y-m-d') ?></div>
</div>

<div class="toc">
  <strong>Contenido</strong>
  <ol>
    <li>Bienvenida</li>
    <li>Acceso al Panel</li>
    <li>Dashboard: vista general</li>
    <li>Gestión de inventario</li>
    <li>Envíos y recepción de motos</li>
    <li>Entregas a clientes</li>
    <li>Checklists de preparación</li>
    <li>Soporte y contacto</li>
  </ol>
</div>

<h2>1. Bienvenida</h2>
<p>Bienvenido al equipo Voltika. Como operador de un Punto afiliado, eres pieza clave en la experiencia del cliente. Este manual te guiará en el uso del <strong>Panel Voltika para Puntos</strong>.</p>

<h2>2. Acceso al Panel</h2>
<div class="step">
  <strong>Paso 1:</strong> Abre el navegador y entra a <code>https://voltika.mx/puntosvoltika/</code>
</div>
<div class="step">
  <strong>Paso 2:</strong> Ingresa el <strong>correo electrónico</strong> y <strong>contraseña</strong> que Voltika te envió por SMS o email.
</div>
<div class="step">
  <strong>Paso 3:</strong> Al primer ingreso, <strong>cambia tu contraseña</strong> desde el menú superior → "Cambiar contraseña".
</div>
<div class="warning">
  ⚠️ No compartas tus credenciales con nadie. Cada empleado debe tener su propio usuario.
</div>

<h2>3. Dashboard: vista general</h2>
<p>Al iniciar sesión verás un panel con los siguientes indicadores de tu punto:</p>
<table>
  <tr><th>Indicador</th><th>Significado</th></tr>
  <tr><td>Inventario actual</td><td>Motos físicamente en tu punto</td></tr>
  <tr><td>Envíos en tránsito</td><td>Motos en camino hacia tu punto</td></tr>
  <tr><td>Listos para entrega</td><td>Clientes que pueden recoger hoy</td></tr>
  <tr><td>Alertas</td><td>Acciones pendientes (OTP, checklists, etc.)</td></tr>
</table>

<h2>4. Gestión de inventario</h2>
<h3>4.1 Recibir una moto nueva</h3>
<div class="step">
  1. Cuando llegue el envío, busca la moto en <strong>Envíos → Pendientes</strong><br>
  2. Compara el número de serie físico con el del sistema<br>
  3. Pulsa <strong>"Recibir"</strong> e ingresa el kilometraje inicial<br>
  4. La moto aparece ahora en <strong>Inventario</strong>
</div>

<h3>4.2 Consultar estado de una moto</h3>
<div class="step">
  Menú <strong>Inventario</strong> → busca por número de serie o por cliente asignado.
</div>

<h2>5. Envíos y recepción de motos</h2>
<p>Los envíos llegan desde el centro de distribución Voltika. Cada envío incluye:</p>
<ul>
  <li>Motos con cliente ya asignado (entrega programada)</li>
  <li>Motos de inventario general del punto</li>
</ul>
<div class="info">
  💡 Usa el menú <strong>Envíos</strong> para ver qué motos deben llegar esta semana.
</div>

<h2>6. Entregas a clientes</h2>
<h3>6.1 Flujo de entrega</h3>
<div class="step">
  <strong>Paso 1:</strong> El cliente llega con identificación oficial<br>
  <strong>Paso 2:</strong> Verifica su identidad en <strong>Entregas → Hoy</strong><br>
  <strong>Paso 3:</strong> El cliente recibe un <strong>código OTP</strong> por SMS — pídele que te lo muestre<br>
  <strong>Paso 4:</strong> Ingresa el OTP en el sistema → se habilita el botón de entrega<br>
  <strong>Paso 5:</strong> Realiza el checklist de pre-entrega (ver sección 7)<br>
  <strong>Paso 6:</strong> Cliente firma el <strong>ACTA DE ENTREGA</strong> digitalmente<br>
  <strong>Paso 7:</strong> Entrega las llaves y el casco
</div>
<div class="warning">
  ⚠️ Nunca entregues una moto sin OTP válido y acta firmada.
</div>

<h2>7. Checklists de preparación</h2>
<p>Antes de cada entrega, realiza el <strong>checklist de pre-entrega</strong> en el menú <strong>Checklists</strong>:</p>
<ul>
  <li>Batería cargada al 100%</li>
  <li>Llantas con presión correcta</li>
  <li>Luces y claxon funcionando</li>
  <li>Frenos revisados</li>
  <li>Número de serie visible y coincide</li>
  <li>Casco incluido y en buen estado</li>
</ul>

<h2>8. Soporte y contacto</h2>
<table>
  <tr><th>Canal</th><th>Para qué</th><th>Contacto</th></tr>
  <tr><td>WhatsApp</td><td>Urgencias operativas</td><td>+52 1 442 119 8928</td></tr>
  <tr><td>Email</td><td>Consultas generales</td><td>soporte@voltika.com.mx</td></tr>
  <tr><td>Panel → Alertas</td><td>Reportar incidencias</td><td>Botón "Nueva incidencia"</td></tr>
</table>

<div class="info">
  💡 En caso de duda o problema técnico, <strong>siempre</strong> reporta en el Panel → Alertas. Así queda registro y el equipo Voltika puede ayudarte rápido.
</div>

<h2>Fin del manual</h2>
<p style="text-align:center;color:#666;margin-top:30px;">
  Gracias por ser parte de Voltika ⚡<br>
  <small>Este manual se actualiza periódicamente. Revisa la versión más reciente en el Panel.</small>
</p>

</body>
</html>
