window.PV_inventario = (function(){
  function render(){
    PVApp.render('<div class="ad-h1">Inventario</div><div><span class="ad-spin"></span></div>');
    PVApp.api('inventario/listar.php').done(paint);
  }
  function paint(r){
    var html = '<div class="ad-h1">Inventario</div>';
    html += '<div class="ad-h2">🎁 Para entrega ('+(r.inventario_entrega||[]).length+')</div>';
    if((r.inventario_entrega||[]).length===0) html += '<div style="color:var(--ad-dim)">Sin motos reservadas</div>';
    (r.inventario_entrega||[]).forEach(function(m){
      html += bikeCard(m, 'entrega');
    });
    html += '<div class="ad-h2">💰 Disponible para venta ('+(r.inventario_venta||[]).length+')</div>';
    if((r.inventario_venta||[]).length===0) html += '<div style="color:var(--ad-dim)">Sin motos libres</div>';
    (r.inventario_venta||[]).forEach(function(m){
      html += bikeCard(m, 'venta');
    });
    PVApp.render(html);
  }
  function bikeCard(m, tipo){
    var h = '<div class="pv-bike-card"><div class="pv-info">';
    h += '<div style="font-weight:700">'+m.modelo+' · '+m.color+'</div>';
    h += '<div style="font-size:12px;color:var(--ad-dim)">VIN: '+(m.vin_display||m.vin)+'</div>';
    if (tipo==='entrega' && m.cliente_nombre) {
      h += '<div style="font-size:12px">Cliente: <strong>'+m.cliente_nombre+'</strong></div>';
      h += '<div style="font-size:11px;color:var(--ad-dim)">'+m.cliente_telefono+'</div>';
    }
    h += '<div style="font-size:11px;margin-top:4px"><span class="ad-badge '+(m.estado==='entregada'?'green':'blue')+'">'+m.estado+'</span></div>';
    h += '</div></div>';
    return h;
  }
  return { render:render };
})();
