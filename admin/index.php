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
<link rel="stylesheet" href="<?= $asset('css/admin.css') ?>">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>
<body>
<div id="adApp" class="ad-app">
  <nav id="adSidebar" class="ad-sidebar" style="display:none">
    <div class="ad-logo"><img src="../configurador_prueba/img/voltika_logo_h_white.svg" alt="Voltika" onerror="this.style.display='none'"></div>
    <div class="ad-nav">
      <button data-route="dashboard" class="active"><span>📊</span> Dashboard</button>
      <button data-route="inventario"><span>🏭</span> Inventario</button>
      <button data-route="envios"><span>🚚</span> Envíos</button>
      <button data-route="pagos"><span>💳</span> Pagos</button>
      <button data-route="puntos"><span>📍</span> Puntos Voltika</button>
    </div>
    <div class="ad-user" id="adUser"></div>
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
<script>$(function(){ ADApp.start(); });</script>
</body>
</html>
