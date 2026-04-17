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
<title>Voltika — Mi cuenta (TEST)</title>
<link rel="icon" type="image/svg+xml" href="../configurador_prueba_test/img/favicon.svg">
<link rel="stylesheet" href="<?= $asset('css/portal.css') ?>">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>
<body>
<div id="vk-test-banner" style="background:#ff9800;color:#fff;text-align:center;padding:6px 12px;font-size:13px;font-weight:700;position:fixed;top:0;left:0;right:0;z-index:99999;">MODO DE PRUEBA — Portal de clientes (test)</div>
<div id="vkApp" class="vk-app" style="padding-top:32px;">
  <div id="vkScreen" class="vk-screen"></div>
  <nav id="vkTabbar" class="vk-tabbar" style="display:none">
    <button data-route="inicio"><span class="ic"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span><em>Inicio</em></button>
    <button data-route="miscompras"><span class="ic"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg></span><em>Mis compras</em></button>
    <button data-route="pagos"><span class="ic"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></span><em>Pagos</em></button>
    <button data-route="entrega"><span class="ic"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></span><em>Entrega</em></button>
    <button data-route="documentos"><span class="ic"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span><em>Documentos</em></button>
    <button data-route="mivoltika"><span class="ic"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></span><em>Mi Voltika</em></button>
    <button data-route="cuenta"><span class="ic"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span><em>Cuenta</em></button>
    <button data-route="ayuda"><span class="ic"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></span><em>Ayuda</em></button>
  </nav>
</div>
<script src="<?= $asset('js/app.js') ?>"></script>
<script src="<?= $asset('js/modules/login.js') ?>"></script>
<script src="<?= $asset('js/modules/recovery.js') ?>"></script>
<script src="<?= $asset('js/modules/inicio.js') ?>"></script>
<script src="<?= $asset('js/modules/mis-compras.js') ?>"></script>
<script src="<?= $asset('js/modules/pagos.js') ?>"></script>
<script src="<?= $asset('js/modules/entrega.js') ?>"></script>
<script src="<?= $asset('js/modules/documentos.js') ?>"></script>
<script src="<?= $asset('js/modules/cuenta.js') ?>"></script>
<script src="<?= $asset('js/modules/mivoltika.js') ?>"></script>
<script src="<?= $asset('js/modules/ayuda.js') ?>"></script>
<script src="<?= $asset('js/modules/notificaciones.js') ?>"></script>
<script>$(function(){ VKApp.start(); });</script>
</body>
</html>
