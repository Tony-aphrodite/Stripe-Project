window.AD_dashboard = (function(){
  function render(){
    ADApp.render('<div class="ad-h1">Dashboard</div><div class="ad-kpis"><div class="ad-kpi"><span class="ad-spin"></span></div></div>');
    ADApp.api('dashboard/kpis.php').done(paint);
  }
  function paint(k){
    var kpis = [
      {label:'Ventas hoy', value:k.ventas_hoy, cls:'green'},
      {label:'Ventas semana', value:k.ventas_semana, cls:'green'},
      {label:'Cobrado hoy', value:ADApp.money(k.cobrado_hoy), cls:'green'},
      {label:'Flujo esperado', value:ADApp.money(k.flujo_esperado), cls:'blue'},
      {label:'Cartera al corriente', value:k.cartera_corriente, cls:'green'},
      {label:'Cartera vencida', value:k.cartera_vencida, cls:k.cartera_vencida>0?'red':'green'},
      {label:'Inventario disponible', value:k.inventario_disponible, cls:'blue'},
      {label:'Unidades apartadas', value:k.unidades_apartadas, cls:'yellow'},
      {label:'En tránsito', value:k.en_transito, cls:'blue'},
      {label:'Entregas pendientes', value:k.entregas_pendientes, cls:'yellow'},
    ];
    var html = '<div class="ad-h1">Dashboard</div><div class="ad-kpis">';
    kpis.forEach(function(kpi){
      html += '<div class="ad-kpi"><div class="label">'+kpi.label+'</div><div class="value '+kpi.cls+'">'+kpi.value+'</div></div>';
    });
    html += '</div>';
    // Quick actions
    html += '<div class="ad-h2">Acciones rápidas</div>';
    html += '<div style="display:flex;gap:8px;flex-wrap:wrap">';
    html += '<button class="ad-btn primary" onclick="ADApp.go(\'ventas\')">🛒 Ventas</button>';
    html += '<button class="ad-btn ghost" onclick="ADApp.go(\'inventario\')">🏭 Inventario</button>';
    html += '<button class="ad-btn ghost" onclick="ADApp.go(\'envios\')">🚚 Envíos</button>';
    html += '<button class="ad-btn ghost" onclick="ADApp.go(\'pagos\')">💳 Pagos</button>';
    html += '<button class="ad-btn ghost" onclick="ADApp.go(\'puntos\')">📍 Puntos</button>';
    html += '</div>';
    ADApp.render(html);
  }
  return { render:render };
})();
