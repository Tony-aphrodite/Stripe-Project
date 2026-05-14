window.PV_inicio = (function(){
  function render(){
    PVApp.render('<div class="ad-h1">'+(PVApp.state.user.nombre||'')+'</div><div>Cargando...</div>');
    // Customer brief 2026-05-09 (boss's report — "when he changes the
    // referido code in the dashboard, this change isn't shown in the
    // punto panel"): the punto SPA loaded PVApp.state.punto once at
    // login (via auth/me.php in punto-app.js#start) and never refreshed
    // it. Admin edits to puntos_voltika.codigo_venta /
    // codigo_electronico hit the DB correctly but the punto user kept
    // seeing the old code until they logged out + back in (or hit
    // Ctrl+R hard refresh). Fix: re-fetch auth/me.php every time the
    // user lands on Inicio (where the codes are displayed) and
    // overwrite the cached state with whatever the server has now.
    $.when(
      PVApp.api('auth/me.php'),
      PVApp.api('inventario/listar.php'),
      PVApp.api('recepcion/envios-pendientes.php')
    ).done(function(me, inv, env){
      if (me && me[0]) {
        if (me[0].punto)   PVApp.state.punto = me[0].punto;
        if (me[0].usuario) PVApp.state.user  = me[0].usuario;
      }
      paint(inv[0], env[0]);
    });
  }
  function paint(inv, env){
    var p = PVApp.state.punto || {};
    var html = '<div class="ad-h1">'+(p.nombre||'Mi punto')+'</div>';
    html += '<div class="ad-muted" style="color:var(--ad-dim);margin-bottom:16px">'+(p.ciudad||'')+(p.estado?', '+p.estado:'')+'</div>';

    html += '<div class="ad-kpis">';
    html += '<div class="ad-kpi"><div class="label">Inventario total</div><div class="value">'+(inv.total||0)+'</div></div>';
    html += '<div class="ad-kpi"><div class="label">Para entrega</div><div class="value yellow">'+(inv.inventario_entrega?inv.inventario_entrega.length:0)+'</div></div>';
    html += '<div class="ad-kpi"><div class="label">Para venta</div><div class="value green">'+(inv.inventario_venta?inv.inventario_venta.length:0)+'</div></div>';
    html += '<div class="ad-kpi"><div class="label">Envíos pendientes</div><div class="value blue">'+(env.envios?env.envios.length:0)+'</div></div>';
    html += '</div>';

    // Round 27 v2 (2026-05-14, Óscar Inicio screenshot): "Venta directa
    // en tienda: PSVOLTCD" line removed. Puntos no longer perform
    // walk-in sales (Round 27 removed the corresponding button in
    // punto-venta.js); showing this code on the home page misled
    // operators into believing the channel still existed. The web
    // referral code (VOLTCD) is the only code they need.
    html += '<div class="ad-h2">Código de referido</div>';
    html += '<div class="ad-card"><strong>Ventas desde la Web Voltika:</strong> <code>'+(p.codigo_electronico||'—')+'</code></div>';

    html += '<div class="ad-h2">Acciones rápidas</div>';
    html += '<div style="display:flex;gap:8px;flex-wrap:wrap">';
    html += '<button class="ad-btn primary" onclick="PVApp.go(\'recepcion\')"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.45-6.89A2 2 0 0016.76 4H7.24a2 2 0 00-1.79 1.11z"/></svg> Recibir moto</button>';
    html += '<button class="ad-btn ghost" onclick="PVApp.go(\'entrega\')"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;"><rect x="1" y="3" width="15" height="13" rx="1"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg> Entregar al cliente</button>';
    html += '<button class="ad-btn ghost" onclick="PVApp.go(\'venta\')"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg> Venta por referido</button>';
    html += '</div>';

    PVApp.render(html);
  }
  return { render:render };
})();
