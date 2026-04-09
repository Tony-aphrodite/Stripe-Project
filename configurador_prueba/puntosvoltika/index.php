<?php require_once __DIR__ . '/php/bootstrap.php'; ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Voltika — Punto</title>
<link rel="stylesheet" href="../admin/css/admin.css?v=2">
<link rel="stylesheet" href="css/punto.css?v=2">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>
<body>
<div id="pvApp" class="ad-app">
  <nav id="pvSidebar" class="ad-sidebar" style="display:none">
    <div class="ad-logo">📍 PUNTO</div>
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
<script src="js/punto-app.js?v=2"></script>
<script src="js/modules/punto-login.js?v=2"></script>
<script src="js/modules/punto-inicio.js?v=2"></script>
<script src="js/modules/punto-inventario.js?v=2"></script>
<script src="js/modules/punto-recepcion.js?v=2"></script>
<script src="js/modules/punto-entrega.js?v=2"></script>
<script src="js/modules/punto-venta.js?v=2"></script>
<script>$(function(){ PVApp.start(); });</script>
</body>
</html>
