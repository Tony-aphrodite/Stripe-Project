<?php
require_once __DIR__ . '/php/bootstrap.php';
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}
$asset = function(string $rel): string {
    $path = __DIR__ . '/' . $rel;
    $v = file_exists($path) ? filemtime($path) : time();
    return htmlspecialchars($rel . '?v=' . $v);
};
$sharedAdminCss = '../admin/css/admin.css';
$sharedAdminCssPath = __DIR__ . '/' . $sharedAdminCss;
$sharedV = file_exists($sharedAdminCssPath) ? filemtime($sharedAdminCssPath) : time();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<title>Voltika — Punto</title>
<link rel="stylesheet" href="<?= htmlspecialchars($sharedAdminCss . '?v=' . $sharedV) ?>">
<link rel="stylesheet" href="<?= $asset('css/punto.css') ?>">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>
<body>
<div id="pvApp" class="ad-app">
  <nav id="pvSidebar" class="ad-sidebar" style="display:none">
    <div class="ad-logo"><img src="../configurador_prueba/img/voltika_logo_h_white.svg" alt="Voltika" onerror="this.style.display='none'"></div>
    <div class="ad-nav">
      <button data-route="inicio" class="active"><span>🏠</span> Inicio</button>
      <button data-route="inventario"><span>🛵</span> Inventario</button>
      <button data-route="recepcion"><span>📦</span> Recepción</button>
      <button data-route="entrega"><span>🎁</span> Entregas</button>
      <button data-route="venta"><span>💰</span> Venta referido</button>
    </div>
    <div class="ad-user" id="pvUser"></div>
    <button class="ad-logout" id="pvLogout">Cerrar sesión</button>
  </nav>
  <main id="pvMain" class="ad-main">
    <div id="pvScreen"></div>
  </main>
</div>
<div id="pvModal" class="ad-modal" style="display:none">
  <div class="ad-modal-content">
    <button class="ad-modal-close" id="pvModalClose">&times;</button>
    <div id="pvModalBody"></div>
  </div>
</div>
<script src="<?= $asset('js/punto-app.js') ?>"></script>
<script src="<?= $asset('js/modules/punto-login.js') ?>"></script>
<script src="<?= $asset('js/modules/punto-inicio.js') ?>"></script>
<script src="<?= $asset('js/modules/punto-inventario.js') ?>"></script>
<script src="<?= $asset('js/modules/punto-recepcion.js') ?>"></script>
<script src="<?= $asset('js/modules/punto-entrega.js') ?>"></script>
<script src="<?= $asset('js/modules/punto-venta.js') ?>"></script>
<script>$(function(){ PVApp.start(); });</script>
</body>
</html>
