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
      <!-- ═══ OPERACIONES ═══ -->
      <button data-route="dashboard" class="active"><span><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg></span> Dashboard</button>
      <button data-route="ventas"><span><svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg></span> Ventas</button>
      <button data-route="preaprobaciones"><span><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg></span> Solicitudes</button>
      <button data-route="inventario"><span><svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></span> CEDIS</button>
      <button data-route="envios"><span><svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13" rx="1"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></span> Envíos</button>
      <button data-route="pagos"><span><svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></span> Pagos</button>
      <button data-route="cobranza"><span><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></span> Cobranza</button>
      <button data-route="puntos"><span><svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg></span> Puntos</button>
      <button data-route="referidos"><span><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg></span> Referidos</button>
      <button data-route="checklists"><span><svg viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M9 14l2 2 4-4"/></svg></span> Checklists</button>

      <!-- ═══ ADMINISTRACIÓN (collapsible) ═══ -->
      <div class="ad-nav-group" data-group="admin">
        <button class="ad-nav-group-toggle" data-toggle="admin"><span><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg></span> Administración <svg class="ad-nav-chevron" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></button>
        <div class="ad-nav-sub">
          <button data-route="modelos"><span><svg viewBox="0 0 24 24"><circle cx="5" cy="17" r="3"/><circle cx="19" cy="17" r="3"/><path d="M5 14l4-7h4l2 3h4"/><path d="M9 7l3 7"/><path d="M15 10l4 4"/></svg></span> Modelos</button>
          <button data-route="precios"><span><svg viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg></span> Precios</button>
          <button data-route="documentos"><span><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span> Documentos</button>
          <button data-route="entregas"><span><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span> Tiempos Entrega</button>
          <button data-route="roles"><span><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg></span> Roles</button>
          <button data-route="notificaciones"><span><svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></span> Notificaciones</button>
          <button data-route="gestores"><span><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 9h18"/><path d="M8 5V3"/><path d="M16 5V3"/></svg></span> Gestores de placas</button>
        </div>
      </div>

      <!-- ═══ ANÁLISIS (collapsible) ═══ -->
      <div class="ad-nav-group" data-group="analisis">
        <button class="ad-nav-group-toggle" data-toggle="analisis"><span><svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></span> Análisis <svg class="ad-nav-chevron" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></button>
        <div class="ad-nav-sub">
          <button data-route="analytics"><span><svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></span> Analítica</button>
          <button data-route="alertas"><span><svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/><circle cx="18" cy="4" r="3" fill="currentColor" stroke="none"/></svg></span> Alertas</button>
          <button data-route="reportes"><span><svg viewBox="0 0 24 24"><path d="M21.21 15.89A10 10 0 118 2.83"/><path d="M22 12A10 10 0 0012 2v10z"/></svg></span> Reportes</button>
          <button data-route="puntosperf"><span><svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></span> Rendimiento</button>
          <button data-route="buro"><span><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg></span> Buro CDC</button>
        </div>
      </div>

      <!-- ═══ BUSCAR (always visible) ═══ -->
      <button data-route="buscar"><span><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span> Buscar</button>
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
<script src="<?= $asset('js/modules/admin-referidos.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-ventas.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-checklists.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-buro.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-preaprobaciones.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-cobranza.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-buscar.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-analytics.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-alertas.js') ?>"></script>
<script src="<?= $asset('js/modules/admin-gestores.js') ?>"></script>
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
