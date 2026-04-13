<?php
require_once __DIR__ . '/php/bootstrap.php';
// Force-disable HTML caching so updates always reach the browser
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}
// Auto cache-busting helper — the query param changes every time the file changes
$asset = function(string $rel): string {
    $path = __DIR__ . '/' . $rel;
    $v = file_exists($path) ? filemtime($path) : time();
    return htmlspecialchars($rel . '?v=' . $v);
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<title>Voltika — Admin Dashboard</title>
<link rel="icon" type="image/svg+xml" href="../configurador_prueba/img/favicon.svg">
<link rel="stylesheet" href="<?= $asset('css/admin.css') ?>">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>
<body>
<div id="adApp" class="ad-app">
  <nav id="adSidebar" class="ad-sidebar" style="display:none">
    <div class="ad-logo"><img src="../configurador_prueba/img/voltika_logo_h_white.svg" alt="Voltika" onerror="this.style.display='none'"></div>
    <button class="ad-hamburger" id="adHamburger">&#9776;</button>
    <div class="ad-nav">
      <button data-route="dashboard" class="active"><span><img src="../configurador_prueba/img/iconos-01.svg" alt=""></span> Dashboard</button>
      <button data-route="ventas"><span><img src="../configurador_prueba/img/iconos-03.svg" alt=""></span> Ventas</button>
      <button data-route="inventario"><span><img src="../configurador_prueba/img/iconos-02.svg" alt=""></span> CEDIS</button>
      <button data-route="envios"><span><img src="../configurador_prueba/img/entrega.png" alt=""></span> Envíos</button>
      <button data-route="pagos"><span><img src="../configurador_prueba/img/garantia.png" alt=""></span> Pagos</button>
      <button data-route="cobranza"><span><img src="../configurador_prueba/img/garantia.png" alt=""></span> Cobranza</button>
      <button data-route="puntos"><span><img src="../configurador_prueba/img/asesor_icon.jpg" alt=""></span> Puntos Voltika</button>
      <button data-route="checklists"><span><img src="../configurador_prueba/img/aprobado.png" alt=""></span> Checklists</button>
      <button data-route="modelos"><span><img src="../configurador_prueba/img/iconos-02.svg" alt=""></span> Modelos</button>
      <button data-route="precios"><span><img src="../configurador_prueba/img/garantia.png" alt=""></span> Precios</button>
      <button data-route="documentos"><span><img src="../configurador_prueba/img/aprobado.png" alt=""></span> Documentos</button>
      <button data-route="analytics"><span><img src="../configurador_prueba/img/iconos-03.svg" alt=""></span> Analítica</button>
      <button data-route="alertas"><span><img src="../configurador_prueba/img/aprobado.png" alt=""></span> Alertas</button>
      <button data-route="reportes"><span><img src="../configurador_prueba/img/iconos-01.svg" alt=""></span> Reportes</button>
      <button data-route="buro"><span><img src="../configurador_prueba/img/faceid.png" alt=""></span> Buro CDC</button>
      <button data-route="entregas"><span><img src="../configurador_prueba/img/entrega.png" alt=""></span> Tiempos Entrega</button>
      <button data-route="roles"><span><img src="../configurador_prueba/img/faceid.png" alt=""></span> Roles</button>
      <button data-route="notificaciones"><span><img src="../configurador_prueba/img/iconos-01.svg" alt=""></span> Notificaciones</button>
      <button data-route="puntosperf"><span><img src="../configurador_prueba/img/asesor_icon.jpg" alt=""></span> Rendimiento Puntos</button>
      <button data-route="buscar"><span><img src="../configurador_prueba/img/iconos-01.svg" alt=""></span> Buscar</button>
    </div>
    <div class="ad-user" id="adUser"></div>
    <button class="ad-change-pass" id="adChangePass">Cambiar contraseña</button>
    <button class="ad-logout" id="adLogout">Cerrar sesión</button>
  </nav>
  <main id="adMain" class="ad-main">
    <div id="adScreen"></div>
  </main>
</div>
<!-- Modal -->
<div id="adModal" class="ad-modal" style="display:none">
  <div class="ad-modal-content">
    <button class="ad-modal-close" id="adModalClose">&times;</button>
    <div id="adModalBody"></div>
  </div>
</div>
<script src="<?= $asset('js/admin-app.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-login.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-dashboard.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-inventario.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-envios.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-pagos.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-puntos.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-ventas.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-checklists.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-buro.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-cobranza.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-buscar.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-analytics.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-alertas.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-reportes.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-modelos.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-precios.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-documentos.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-entregas.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-roles.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-notificaciones.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-puntos-performance.js') ?>"></script>
<script>$(function(){ ADApp.start(); });</script>
</body>
</html>
