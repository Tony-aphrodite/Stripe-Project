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
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<title>Voltika — Mi cuenta</title>
<link rel="icon" type="image/svg+xml" href="../configurador_prueba/img/favicon.svg">
<link rel="stylesheet" href="<?= $asset('css/portal.css') ?>">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>
<body>
<div id="vkApp" class="vk-app">
  <div id="vkScreen" class="vk-screen"></div>
  <nav id="vkTabbar" class="vk-tabbar" style="display:none">
    <button data-route="inicio"><span class="ic">🏠</span><em>Inicio</em></button>
    <button data-route="pagos"><span class="ic">💳</span><em>Pagos</em></button>
    <button data-route="entrega"><span class="ic">🚚</span><em>Entrega</em></button>
    <button data-route="documentos"><span class="ic">📄</span><em>Docs</em></button>
    <button data-route="cuenta"><span class="ic">👤</span><em>Cuenta</em></button>
    <button data-route="ayuda"><span class="ic">💬</span><em>Ayuda</em></button>
  </nav>
</div>
<script src="<?= $asset('js/app.js') ?>"></script>
<script src="<?= $asset('js/modules/login.js') ?>"></script>
<script src="<?= $asset('js/modules/recovery.js') ?>"></script>
<script src="<?= $asset('js/modules/inicio.js') ?>"></script>
<script src="<?= $asset('js/modules/pagos.js') ?>"></script>
<script src="<?= $asset('js/modules/entrega.js') ?>"></script>
<script src="<?= $asset('js/modules/documentos.js') ?>"></script>
<script src="<?= $asset('js/modules/cuenta.js') ?>"></script>
<script src="<?= $asset('js/modules/ayuda.js') ?>"></script>
<script>$(function(){ VKApp.start(); });</script>
</body>
</html>
