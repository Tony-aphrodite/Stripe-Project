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
<link rel="icon" type="image/svg+xml" href="../configurador_prueba/img/favicon.svg">
<link rel="stylesheet" href="<?= htmlspecialchars($sharedAdminCss . '?v=' . $sharedV) ?>">
<link rel="stylesheet" href="<?= $asset('css/punto.css') ?>">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>
<body>
<div id="pvApp" class="ad-app">
  <nav id="pvSidebar" class="ad-sidebar" style="display:none">
    <div class="ad-logo"><img src="../configurador_prueba/img/voltika_logo_h_white.svg" alt="Voltika" onerror="this.style.display='none'"></div>
    <button class="ad-hamburger" id="pvHamburger">&#9776;</button>
    <div class="ad-nav">
      <button data-route="inicio" class="active"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span> Inicio</button>
      <button data-route="inventario"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></span> Inventario</button>
      <button data-route="recepcion"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.45-6.89A2 2 0 0016.76 4H7.24a2 2 0 00-1.79 1.11z"/></svg></span> Recepción</button>
      <button data-route="entrega"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></span> Entregas</button>
      <button data-route="venta"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg></span> Venta referido</button>
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
<script src="<?= $asset('js/modules/punto-checklist-ensamble.js') ?>"></script>
<script src="<?= $asset('js/modules/punto-recepcion.js') ?>"></script>
<script src="<?= $asset('js/modules/punto-entrega.js') ?>"></script>
<script src="<?= $asset('js/modules/punto-venta.js') ?>"></script>
<script>$(function(){ PVApp.start(); });</script>
</body>
</html>
