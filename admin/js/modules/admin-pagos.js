window.AD_pagos = (function(){
  var filtro = {};
  function render(){
    ADApp.render('<div class="ad-h1">Pagos y Órdenes</div><div><span class="ad-spin"></span></div>');
    load();
  }
  function load(){
    ADApp.api('pagos/listar.php?' + $.param(filtro)).done(paint);
  }
  function paint(r){
    var ro=r.resumen_ordenes||{}, rc=r.resumen_credito||{};
    var html = '<div class="ad-toolbar"><div class="ad-h1">Pagos y Órdenes</div></div>';
    html += '<div class="ad-kpis">';
    html += '<div class="ad-kpi"><div class="label">Total órdenes</div><div class="value">'+(ro.total_ordenes||0)+'</div></div>';
    html += '<div class="ad-kpi"><div class="label">Ingresos ordenes</div><div class="value green">'+ADApp.money(ro.total_ingresos)+'</div></div>';
    html += '<div class="ad-kpi"><div class="label">Créditos activos</div><div class="value blue">'+(rc.total_creditos||0)+'</div></div>';
    html += '<div class="ad-kpi"><div class="label">Monto financiado</div><div class="value blue">'+ADApp.money(rc.total_credito_monto)+'</div></div>';
    html += '</div>';
    // Filters
    html += '<div class="ad-filters">'+
      '<select class="ad-select" id="adPTipo"><option value="">Todos</option><option value="contado">Contado</option><option value="msi">MSI</option><option value="credito">Crédito</option></select>'+
      '<button class="ad-btn sm ghost" id="adPFilter">Filtrar</button></div>';
    // Table
    html += '<table class="ad-table"><thead><tr><th>Pedido</th><th>Cliente</th><th>Modelo</th><th>Tipo</th><th>Monto</th><th>Estado</th><th>Fecha</th></tr></thead><tbody>';
    (r.pagos||[]).forEach(function(p){
      html += '<tr>'+
        '<td>'+(p.pedido_num||p.id)+'</td>'+
        '<td>'+p.nombre+'</td>'+
        '<td>'+p.modelo+' '+p.color+'</td>'+
        '<td><span class="ad-badge blue">'+p.tipo_pago+'</span></td>'+
        '<td>'+ADApp.money(p.monto)+'</td>'+
        '<td>'+ADApp.badgeEstado(p.pago_estado||'—')+'</td>'+
        '<td>'+(p.freg||'').substring(0,10)+'</td>'+
      '</tr>';
    });
    html += '</tbody></table>';
    ADApp.render(html);
    $('#adPFilter').on('click',function(){ filtro.tipo=$('#adPTipo').val(); load(); });
  }
  return { render:render };
})();
