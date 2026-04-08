<?php require_once __DIR__ . '/php/bootstrap.php'; ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Voltika — Mi cuenta</title>
<link rel="stylesheet" href="css/portal.css?v=1">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>
<body>
<div id="vkApp" class="vk-app">
  <div id="vkScreen" class="vk-screen"></div>
  <nav id="vkTabbar" class="vk-tabbar" style="display:none">
    <button data-route="inicio"><span class="ic">⚡</span><em>Inicio</em></button>
    <button data-route="pagos"><span class="ic">💳</span><em>Mis pagos</em></button>
    <button data-route="documentos"><span class="ic">📄</span><em>Documentos</em></button>
    <button data-route="cuenta"><span class="ic">👤</span><em>Mi cuenta</em></button>
    <button data-route="ayuda"><span class="ic">💬</span><em>Ayuda</em></button>
  </nav>
</div>
<script src="js/app.js?v=1"></script>
<script src="js/modules/login.js?v=1"></script>
<script src="js/modules/recovery.js?v=1"></script>
<script src="js/modules/inicio.js?v=1"></script>
<script src="js/modules/pagos.js?v=1"></script>
<script src="js/modules/documentos.js?v=1"></script>
<script src="js/modules/cuenta.js?v=1"></script>
<script src="js/modules/ayuda.js?v=1"></script>
<script>$(function(){ VKApp.start(); });</script>
</body>
</html>
