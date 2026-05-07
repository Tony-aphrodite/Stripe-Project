window.VK_pagos = (function(){

  function formatFechaCorta(dateStr){
    if(!dateStr) return '—';
    var d = new Date(dateStr+'T12:00:00');
    if(isNaN(d)) return dateStr;
    var meses = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
    return d.getDate()+' '+meses[d.getMonth()]+' '+d.getFullYear();
  }

  function formatFechaLarga(dateStr){
    if(!dateStr) return '—';
    var d = new Date(dateStr+'T12:00:00');
    if(isNaN(d)) return dateStr;
    var dias = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
    var meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    return d.getDate()+' de '+meses[d.getMonth()]+' de '+d.getFullYear();
  }

  function estadoBadge(s){
    if(s==='paid_manual'||s==='paid_auto') return '<span class="vk-estado-badge pagado">Pagado</span>';
    // Customer brief 2026-05-07 (item 12): the Vencido badge now ships
    // with a yellow warning triangle right next to the label so past-
    // due cycles are visually undeniable in the payments history.
    // The SVG inherits its yellow color from inline style; alt-text
    // ensures screen readers announce the warning state.
    if(s==='overdue') return '<span class="vk-estado-badge vencido">'
      +'<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="#fbbf24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:5px;flex-shrink:0;" aria-label="Pago vencido">'
      +'<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" fill="#fef3c7"/>'
      +'<line x1="12" y1="9" x2="12" y2="13"/>'
      +'<line x1="12" y1="17" x2="12.01" y2="17"/>'
      +'</svg>Vencido</span>';
    if(s==='skipped') return '<span class="vk-estado-badge omitido">Omitido</span>';
    return '<span class="vk-estado-badge pendiente">Pendiente</span>';
  }

  function estadoDot(s){
    if(s==='paid_manual'||s==='paid_auto') return '<span class="vk-hist-dot pagado"></span>';
    if(s==='overdue') return '<span class="vk-hist-dot vencido"></span>';
    return '<span class="vk-hist-dot pendiente"></span>';
  }

  function _scopeQS(){
    var a = VKApp.state.activeCompra;
    if (a && a.tipo === 'credito' && a.id) return '?subscripcion_id=' + encodeURIComponent(a.id);
    return '';
  }

  function render(){
    VKApp.render('<div class="vk-h1">Mis pagos</div><div class="vk-muted"><span class="vk-spin"></span> Cargando...</div>');
    VKApp.api('pagos/historial.php' + _scopeQS()).done(function(h){ paint(h); });
  }

  function paint(h){
    var e = VKApp.state.estado||{};
    var prog = e.progreso||{};
    var prox = e.proximo_pago||{};
    var fechaProx = prox.fecha_vencimiento||'';

    var pagado = Number(h.pagado_a_la_fecha||0);
    var realizados = h.pagos_realizados||0;
    var restantes = h.pagos_restantes||0;
    var total = (prog.total||h.total_ciclos||0);
    var pct = total ? Math.round((realizados/total)*100) : 0;

    // Customer brief 2026-05-07 (item 13): when the account has past
    // due cycles, the bottom banner switches from the friendly green
    // "Tu próximo pago vence el …" to a red warning that surfaces the
    // earliest overdue date and explicit collections language. Uses
    // the `vencido` aggregate already exposed by estado.php (count,
    // monto, desde) so the calculation is consistent with Inicio's
    // "Paga de Inmediato" treatment.
    var venc = e.vencido || {};
    var hasOverdue = (Number(venc.count) || 0) > 0;
    var overdueDesde = venc.desde || '';

    // Motivational text
    var motivacion = '';
    if(pct>=75) motivacion = '¡Ya casi terminas, sigue así!';
    else if(pct>=50) motivacion = '¡Vas a más de la mitad, excelente!';
    else if(pct>=25) motivacion = '¡Sigue así, vas muy bien!';
    else motivacion = '¡Buen inicio, cada pago cuenta!';

    // History rows
    var rows = (h.ciclos||[]).map(function(c){
      return '<div class="vk-hist-row">'+
        '<div class="vk-hist-left">'+
          estadoDot(c.estado)+
          '<div>'+
            '<div class="vk-hist-fecha">'+formatFechaCorta(c.fecha_vencimiento)+'</div>'+
            '<div class="vk-hist-sub">Pago semanal</div>'+
          '</div>'+
        '</div>'+
        '<div class="vk-hist-right">'+
          '<div class="vk-hist-monto">$'+Number(c.monto).toLocaleString('es-MX',{minimumFractionDigits:2})+'</div>'+
          estadoBadge(c.estado)+
        '</div>'+
        '<div class="vk-hist-arrow">›</div>'+
      '</div>';
    }).join('');

    VKApp.render(
      '<div class="vk-h1">Mis pagos</div>'+
      '<div class="vk-muted" style="margin-bottom:14px">Aquí puedes ver tu progreso e historial completo.</div>'+

      // --- 3-metric summary ---
      '<div class="vk-card">'+
        '<div class="vk-metrics-grid">'+
          '<div class="vk-metric">'+
            '<div class="vk-metric-circle blue"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#fff" stroke-width="2"><circle cx="12" cy="12" r="8"/><text x="12" y="16" text-anchor="middle" fill="#fff" font-size="12" font-weight="bold" stroke="none">$</text></svg></div>'+
            '<div class="vk-metric-label">Pagado a la fecha</div>'+
            '<div class="vk-metric-value">$'+pagado.toLocaleString('es-MX')+'</div>'+
            '<div class="vk-metric-sub">MXN</div>'+
          '</div>'+
          '<div class="vk-metric">'+
            '<div class="vk-metric-circle green"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#fff" stroke-width="2.5"><path d="M9 12l2 2 4-4"/></svg></div>'+
            '<div class="vk-metric-label">Pagos realizados</div>'+
            '<div class="vk-metric-value">'+realizados+' de '+total+'</div>'+
          '</div>'+
          '<div class="vk-metric">'+
            '<div class="vk-metric-circle orange"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#fff" stroke-width="2"><rect x="5" y="5" width="14" height="14" rx="2"/><path d="M5 10h14"/></svg></div>'+
            '<div class="vk-metric-label">Pagos restantes</div>'+
            '<div class="vk-metric-value">'+restantes+'</div>'+
          '</div>'+
        '</div>'+
      '</div>'+

      // --- Credit progress ---
      '<div class="vk-card">'+
        '<div class="vk-credit-progress">'+
          '<div class="vk-credit-left">'+
            '<div class="vk-h2" style="margin:0 0 4px">Avance de tu crédito</div>'+
            '<div class="vk-muted">Has completado</div>'+
            '<div class="vk-progress" style="margin:10px 0 8px"><div style="width:'+pct+'%"></div></div>'+
            '<div class="vk-muted">'+motivacion+'</div>'+
          '</div>'+
          '<div class="vk-credit-pct">'+pct+'%</div>'+
        '</div>'+
      '</div>'+

      // --- History ---
      '<div class="vk-card">'+
        '<div style="display:flex;justify-content:space-between;align-items:center">'+
          '<div class="vk-h2" style="margin:0">Historial de pagos</div>'+
          '<a class="vk-link vk-descargar-link" style="cursor:pointer">Descargar <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle"><path d="M8 3v8M5 8l3 3 3-3"/><path d="M3 13h10"/></svg></a>'+
        '</div>'+
        '<div class="vk-hist-list">'+(rows||'<div class="vk-muted" style="padding:14px 0">Sin pagos registrados</div>')+'</div>'+
      '</div>'+

      // --- Bottom next payment banner ---
      // Customer brief 2026-05-07 (item 13): two visual states.
      // (a) Past due → red warning banner with ⚠ triangle + saldo-vencido copy
      // (b) On track → original green check banner with próximo-pago copy
      (hasOverdue
        ? '<div class="vk-next-pay-banner overdue" style="background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #ef4444;color:#991b1b;align-items:flex-start;">'+
            '<svg viewBox="0 0 24 24" width="22" height="22" fill="#fef3c7" stroke="#f59e0b" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:2px;">'+
              '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>'+
              '<line x1="12" y1="9" x2="12" y2="13"/>'+
              '<line x1="12" y1="17" x2="12.01" y2="17"/>'+
            '</svg>'+
            '<span style="line-height:1.5;"><strong>Saldo vencido desde:</strong> '+formatFechaLarga(overdueDesde||fechaProx)+'.<br>'+
            'Realiza tu pago para evitar sobrecargos y modificaciones en tu historial de crédito.</span>'+
          '</div>'
        : (fechaProx
          ? '<div class="vk-next-pay-banner">'+
              '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#22c55e" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2 4-4"/></svg>'+
              '<span>Tu próximo pago vence el <strong>'+formatFechaLarga(fechaProx)+'</strong>.</span>'+
            '</div>'
          : ''))
    );

    // Descargar link
    $('.vk-descargar-link').on('click', function(e){
      e.preventDefault();
      var a = VKApp.state.activeCompra;
      var extra = (a && a.tipo === 'credito' && a.id) ? '&subscripcion_id=' + encodeURIComponent(a.id) : '';
      window.open('php/documentos/descargar.php?tipo=comprobantes' + extra, '_blank');
    });
  }
  return { render:render };
})();
