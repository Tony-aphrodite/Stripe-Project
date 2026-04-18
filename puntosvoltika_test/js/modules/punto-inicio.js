window.PV_inicio = (function(){
  function render(){
    var p = PVApp.state.punto || {};
    PVApp.render('<div class="ad-h1">'+(PVApp.state.user.nombre||'')+'</div><div>Cargando...</div>');
    $.when(
      PVApp.api('inventario/listar.php'),
      PVApp.api('recepcion/envios-pendientes.php')
    ).done(function(inv, env){
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

    html += '<div class="ad-h2">Códigos de referido</div>';
    html += '<div class="ad-card"><strong>Venta directa en tienda:</strong> <code>'+(p.codigo_venta||'—')+'</code></div>';
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
