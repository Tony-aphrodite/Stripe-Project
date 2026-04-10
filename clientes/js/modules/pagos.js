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
    var dias = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    var meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    return dias[d.getDay()]+' '+d.getDate()+' de '+meses[d.getMonth()]+' '+d.getFullYear();
  }

  function brandIcon(brand){
    var b = (brand||'').toLowerCase();
    if(b==='visa') return '<svg viewBox="0 0 48 32" width="40" height="26"><rect width="48" height="32" rx="4" fill="#1a1f71"/><text x="24" y="20" text-anchor="middle" fill="#fff" font-size="12" font-weight="bold" font-family="Arial">VISA</text></svg>';
    if(b==='mastercard') return '<svg viewBox="0 0 48 32" width="40" height="26"><rect width="48" height="32" rx="4" fill="#2b2b2b"/><circle cx="19" cy="16" r="9" fill="#eb001b"/><circle cx="29" cy="16" r="9" fill="#f79e1b"/></svg>';
    if(b==='amex'||b==='american_express') return '<svg viewBox="0 0 48 32" width="40" height="26"><rect width="48" height="32" rx="4" fill="#006fcf"/><text x="24" y="20" text-anchor="middle" fill="#fff" font-size="9" font-weight="bold" font-family="Arial">AMEX</text></svg>';
    return '<svg viewBox="0 0 48 32" width="40" height="26"><rect width="48" height="32" rx="4" fill="#666"/><text x="24" y="20" text-anchor="middle" fill="#fff" font-size="10" font-weight="bold" font-family="Arial">CARD</text></svg>';
  }

  function brandName(brand){
    var b = (brand||'').toLowerCase();
    if(b==='visa') return 'Visa';
    if(b==='mastercard') return 'Mastercard';
    if(b==='amex'||b==='american_express') return 'American Express';
    return brand || 'Tarjeta';
  }

  function estadoBadge(s){
    if(s==='paid_manual'||s==='paid_auto') return '<span class="vk-estado-badge pagado">Pagado</span>';
    if(s==='overdue') return '<span class="vk-estado-badge vencido">Vencido</span>';
    if(s==='skipped') return '<span class="vk-estado-badge omitido">Omitido</span>';
    return '<span class="vk-estado-badge pendiente">Pendiente</span>';
  }

  function estadoDot(s){
    if(s==='paid_manual'||s==='paid_auto') return '<span class="vk-hist-dot pagado"></span>';
    if(s==='overdue') return '<span class="vk-hist-dot vencido"></span>';
    return '<span class="vk-hist-dot pendiente"></span>';
  }

  function render(){
    VKApp.render('<div class="vk-h1">Mis pagos</div><div class="vk-muted"><span class="vk-spin"></span> Cargando...</div>');
    $.when(
      VKApp.api('pagos/historial.php'),
      VKApp.api('pagos/metodo-pago.php')
    ).done(function(h,m){ paint(h[0], m[0]); });
  }

  function paint(h, m){
    var e = VKApp.state.estado||{};
    var prox = e.proximo_pago||{};
    var montoNum = Number(prox.monto||0);
    var montoFmt = montoNum ? '$'+montoNum.toLocaleString('es-MX') : '—';
    var fechaProx = prox.fecha_vencimiento||'';

    var pagado = Number(h.pagado_a_la_fecha||0);
    var realizados = h.pagos_realizados||0;
    var restantes = h.pagos_restantes||0;

    // Brand info
    var metodo = m.metodo;
    var brandSvg = metodo ? brandIcon(metodo.brand) : '';
    var brandLabel = metodo ? brandName(metodo.brand) : '';
    var last4 = metodo ? metodo.last4 : '';

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
            '<div class="vk-metric-icon"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="var(--vk-primary)" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></div>'+
            '<div class="vk-metric-label">Pagado a la fecha</div>'+
            '<div class="vk-metric-value">$'+pagado.toLocaleString('es-MX')+'</div>'+
          '</div>'+
          '<div class="vk-metric">'+
            '<div class="vk-metric-icon"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#22c55e" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg></div>'+
            '<div class="vk-metric-label">Pagos realizados</div>'+
            '<div class="vk-metric-value">'+realizados+'</div>'+
          '</div>'+
          '<div class="vk-metric">'+
            '<div class="vk-metric-icon"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#f59e0b" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M3 10h18"/></svg></div>'+
            '<div class="vk-metric-label">Pagos restantes</div>'+
            '<div class="vk-metric-value">'+restantes+'</div>'+
          '</div>'+
        '</div>'+
      '</div>'+

      // --- Payment method ---
      '<div class="vk-card">'+
        (fechaProx ? '<div class="vk-pm-next">Tu próximo pago vence el <strong>'+formatFechaLarga(fechaProx)+'</strong></div>' : '')+
        '<div class="vk-pm-header">'+
          '<div class="vk-pm-title">Método de pago principal</div>'+
        '</div>'+
        (metodo
          ? '<div class="vk-pm-card">'+
              '<div class="vk-pm-brand">'+brandSvg+'</div>'+
              '<div class="vk-pm-info">'+
                '<div class="vk-pm-name">'+brandLabel+' •••• '+last4+'</div>'+
              '</div>'+
              '<button class="vk-pm-cambiar" id="vkCambiarTarjeta">Cambiar</button>'+
            '</div>'
          : '<div class="vk-muted">No hay método de pago configurado</div>'
        )+
        '<button id="vkPayFromPagos" class="vk-btn-pay" style="margin-top:14px">PAGAR '+montoFmt+' AHORA</button>'+
        '<div class="vk-trust-line-dark">🛡 Pago 100% seguro &bull; Te toma menos de 1 minuto</div>'+
        '<div class="vk-trust-sub-dark">Si pagas ahora, no se volverá a cobrar automáticamente.</div>'+
      '</div>'+

      // --- History ---
      '<div class="vk-card">'+
        '<div style="display:flex;justify-content:space-between;align-items:center">'+
          '<div class="vk-h2" style="margin:0">Historial de pagos</div>'+
          '<a class="vk-link" style="font-size:12px">Descargar ⬇</a>'+
        '</div>'+
        '<div class="vk-hist-list">'+(rows||'<div class="vk-muted" style="padding:14px 0">Sin pagos registrados</div>')+'</div>'+
      '</div>'+

      // --- Bottom pay button ---
      '<button class="vk-btn-pay" id="vkPayBottom">PAGAR '+montoFmt+'</button>'
    );

    $('#vkPayFromPagos,#vkPayBottom').on('click', function(){ VKApp.go('inicio'); });
    $('#vkCambiarTarjeta').on('click', function(){ VKApp.go('cuenta'); });
  }
  return { render:render };
})();
