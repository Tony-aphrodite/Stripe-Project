window.PV_inicio = (function(){
  function render(){
    var p = PVApp.state.punto || {};
    PVApp.render('<div class="ad-h1">👋 '+(PVApp.state.user.nombre||'')+'</div><div>Cargando...</div>');
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
    html += '<div class="ad-card"><strong>Venta directa:</strong> <code>'+(p.codigo_venta||'—')+'</code></div>';
    html += '<div class="ad-card"><strong>Venta electrónica:</strong> <code>'+(p.codigo_electronico||'—')+'</code></div>';

    html += '<div class="ad-h2">Acciones rápidas</div>';
    html += '<div style="display:flex;gap:8px;flex-wrap:wrap">';
    html += '<button class="ad-btn primary" onclick="PVApp.go(\'recepcion\')">📦 Recibir moto</button>';
    html += '<button class="ad-btn ghost" onclick="PVApp.go(\'entrega\')">🎁 Entregar al cliente</button>';
    html += '<button class="ad-btn ghost" onclick="PVApp.go(\'venta\')">💰 Venta por referido</button>';
    html += '</div>';

    PVApp.render(html);
  }
  return { render:render };
})();
