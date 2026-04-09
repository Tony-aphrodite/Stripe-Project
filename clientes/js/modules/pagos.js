window.VK_pagos = (function(){
  function render(){
    VKApp.render('<div class="vk-h1">Mis pagos</div><div class="vk-muted"><span class="vk-spin"></span> Cargando...</div>');
    $.when(
      VKApp.api('pagos/historial.php'),
      VKApp.api('pagos/metodo-pago.php')
    ).done(function(h,m){ paint(h[0], m[0]); });
  }
  function estadoLabel(s){
    return {paid_manual:'✅ Pagado',paid_auto:'✅ Pagado',pending:'⏳ Pendiente',overdue:'⚠️ Vencido',skipped:'⏭️ Omitido'}[s]||s;
  }
  function paint(h, m){
    var e = VKApp.state.estado||{}; var prog=e.progreso||{};
    var pct = prog.total? Math.round((prog.pagados/prog.total)*100):0;
    var metodo = m.metodo? (m.metodo.brand+' •••• '+m.metodo.last4+' · '+m.metodo.exp) : 'No configurado';

    var rows = (h.ciclos||[]).map(function(c){
      return '<div class="vk-row"><span class="k">Semana '+c.semana_num+' · '+c.fecha_vencimiento+'</span><span class="v">$'+Number(c.monto).toLocaleString('es-MX')+' '+estadoLabel(c.estado)+'</span></div>';
    }).join('');

    VKApp.render(
      '<div class="vk-h1">Mis pagos</div>'+
      '<div class="vk-card">'+
        '<div class="vk-muted">Pagado a la fecha</div>'+
        '<div class="vk-amount">$'+Number(h.pagado_a_la_fecha||0).toLocaleString('es-MX')+'</div>'+
        '<div class="vk-progress"><div style="width:'+pct+'%"></div></div>'+
        '<div class="vk-muted">'+(prog.pagados||0)+' de '+(prog.total||0)+' semanas ('+pct+'%)</div>'+
      '</div>'+

      '<div class="vk-card">'+
        '<div class="vk-h2">Método principal</div>'+
        '<div class="vk-muted">'+metodo+'</div>'+
      '</div>'+

      '<div class="vk-banner ok">🛡️ Protección anti-duplicado: tu pago manual siempre gana sobre el cobro automático.</div>'+

      '<div class="vk-card"><div class="vk-h2">Historial</div>'+(rows||'<div class="vk-muted">Sin pagos registrados</div>')+'</div>'+

      '<button class="vk-btn primary" onclick="VKApp.go(\'inicio\')">Pagar ahora</button>'
    );
  }
  return { render:render };
})();
