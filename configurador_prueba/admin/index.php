<?php require_once __DIR__ . '/php/bootstrap.php'; ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Voltika — Admin Dashboard</title>
<link rel="stylesheet" href="css/admin.css?v=2">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>
<body>
<div id="adApp" class="ad-app">
  <nav id="adSidebar" class="ad-sidebar" style="display:none">
    <div class="ad-logo">⚡ VOLTIKA</div>
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
<script src="js/admin-app.js?v=2"></script>
<script src="js/modules/admin-login.js?v=2"></script>
<script src="js/modules/admin-dashboard.js?v=2"></script>
<script src="js/modules/admin-inventario.js?v=2"></script>
<script src="js/modules/admin-envios.js?v=2"></script>
<script src="js/modules/admin-pagos.js?v=2"></script>
<script src="js/modules/admin-puntos.js?v=2"></script>
<script>$(function(){ ADApp.start(); });</script>
</body>
</html>
