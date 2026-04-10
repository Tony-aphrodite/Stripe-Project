window.VK_inicio = (function(){
  function pill(state){
    var map = {
      account_current:['ok','AL CORRIENTE ✅'],
      payment_due_soon:['warn','PRÓXIMO PAGO ⏰'],
      payment_due_today:['warn','PAGA HOY ⚡'],
      payment_pending:['warn','PAGO PENDIENTE'],
      payment_overdue:['err','PAGO VENCIDO ⚠️'],
      card_update_required:['err','ACTUALIZA TU TARJETA'],
      no_subscription:['warn','SIN SUSCRIPCIÓN']
    };
    var m = map[state]||['warn',state];
    return '<span class="vk-pill '+m[0]+'">'+m[1]+'</span>';
  }

  function formatFechaES(dateStr){
    if(!dateStr) return '—';
    var d = new Date(dateStr+'T12:00:00');
    if(isNaN(d)) return dateStr;
    var dias = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    var meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    return dias[d.getDay()]+' '+d.getDate()+' de '+meses[d.getMonth()];
  }

  function render(){
    var e = VKApp.state.estado || {};
    var c = VKApp.state.cliente || {};
    var nombre = c.nombrePila || (c.nombre||'').split(' ')[0] || 'Cliente';
    var prox = e.proximo_pago || {};
    var prog = e.progreso || {};
    var pct = prog.total? Math.round((prog.pagados/prog.total)*100):0;
    var montoNum = Number(prox.monto||0);
    var monto = montoNum ? '$'+montoNum.toLocaleString('es-MX') : '—';
    var fecha = prox.fecha_vencimiento || '';
    var fechaES = formatFechaES(fecha);

    var monto2 = montoNum*2;
    var monto4 = montoNum*4;

    VKApp.render(
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">'+
        '<div><div class="vk-muted">Bienvenido</div><div class="vk-h1">¡Hola, '+nombre+'!</div></div>'+
        pill(e.state||'no_subscription')+
      '</div>'+

      // --- Main payment card (dark blue) ---
      '<div class="vk-card vk-card-dark">'+
        '<div class="vk-card-dark-label">Paga esta semana</div>'+
        '<div class="vk-card-dark-amount">'+monto+'</div>'+
        '<div class="vk-card-dark-fecha">Vence: <strong>'+fechaES+'</strong></div>'+
        '<button id="vkPayNow" class="vk-btn-pay">PAGAR '+monto+'</button>'+
        '<div class="vk-trust-line">🛡 Pago 100% seguro &bull; Te toma menos de 1 minuto</div>'+
        '<div class="vk-trust-sub">Si pagas ahora, no se volverá a cobrar automáticamente.<br>Tu tarjeta domiciliada solo se usa si no realizas el pago a tiempo.</div>'+
      '</div>'+

      // --- Prepay options (4 cards) ---
      '<div class="vk-card">'+
        '<div class="vk-h2">¡Adelanta pagos sin penalización!</div>'+
        '<div class="vk-muted">Paga dos semanas o más y termina tu plan antes.</div>'+
        '<div class="vk-prepay-grid" style="margin-top:12px">'+
          '<div class="vk-prepay-opt" data-tipo="semanal">'+
            '<div class="vk-prepay-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg></div>'+
            '<div class="vk-prepay-label">1 semana</div>'+
            '<div class="vk-prepay-amount">'+(montoNum?'$'+montoNum.toLocaleString('es-MX'):'—')+'</div>'+
            '<div class="vk-prepay-cur">MXN</div>'+
          '</div>'+
          '<div class="vk-prepay-opt popular" data-tipo="dos_semanas">'+
            '<div class="vk-prepay-badge">POPULAR</div>'+
            '<div class="vk-prepay-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg></div>'+
            '<div class="vk-prepay-label">2 semanas</div>'+
            '<div class="vk-prepay-amount">'+(monto2?'$'+monto2.toLocaleString('es-MX'):'—')+'</div>'+
            '<div class="vk-prepay-cur">MXN</div>'+
          '</div>'+
          '<div class="vk-prepay-opt" data-tipo="adelanto" data-semanas="4">'+
            '<div class="vk-prepay-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg></div>'+
            '<div class="vk-prepay-label">4 semanas</div>'+
            '<div class="vk-prepay-amount">'+(monto4?'$'+monto4.toLocaleString('es-MX'):'—')+'</div>'+
            '<div class="vk-prepay-cur">MXN</div>'+
            '<div class="vk-prepay-impact">MAYOR IMPACTO</div>'+
          '</div>'+
          '<div class="vk-prepay-opt" data-tipo="custom">'+
            '<div class="vk-prepay-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg></div>'+
            '<div class="vk-prepay-label">Elegir monto</div>'+
            '<div class="vk-prepay-amount">Tú decides</div>'+
            '<div class="vk-prepay-cur">MXN</div>'+
          '</div>'+
        '</div>'+
      '</div>'+

      // --- Payment methods ---
      '<div class="vk-card">'+
        '<div class="vk-h2">Paga tu semana o tu adelanto como quieras</div>'+
        '<div class="vk-pay-method">'+
          '<span class="vk-pm-icon"><svg viewBox="0 0 24 24" width="28" height="28"><rect x="1" y="4" width="22" height="16" rx="3" fill="#1a1f71"/><rect x="1" y="8" width="22" height="3" fill="#f7b600"/></svg></span>'+
          '<span class="k">Tarjeta guardada</span><span class="v">Automática</span>'+
        '</div>'+
        '<div class="vk-pay-method">'+
          '<span class="vk-pm-icon"><svg viewBox="0 0 24 24" width="28" height="28"><rect x="2" y="3" width="20" height="18" rx="3" fill="#004990"/><text x="12" y="15" text-anchor="middle" fill="#fff" font-size="7" font-weight="bold">SPEI</text></svg></span>'+
          '<span class="k">Transferencia SPEI</span><span class="v">Manual</span>'+
        '</div>'+
        '<div class="vk-pay-method">'+
          '<span class="vk-pm-icon"><svg viewBox="0 0 24 24" width="28" height="28"><rect x="2" y="3" width="20" height="18" rx="3" fill="#cd1719"/><text x="12" y="15" text-anchor="middle" fill="#fff" font-size="6" font-weight="bold">OXXO</text></svg></span>'+
          '<span class="k">OXXO</span><span class="v">Efectivo</span>'+
        '</div>'+
      '</div>'+

      '<div class="vk-banner ok">🔒 Tu pago manual siempre tiene prioridad. Nunca cobramos dos veces por la misma semana.</div>'+

      '<a class="vk-link" onclick="VKApp.go(\'pagos\')">Ver todos mis pagos →</a>'
    );

    $('#vkPayNow').on('click', function(){ pay('semanal'); });
    $('.vk-prepay-opt').on('click', function(){
      var tipo = $(this).data('tipo');
      if(tipo === 'custom'){
        var sem = prompt('¿Cuántas semanas deseas adelantar?','4');
        if(!sem || isNaN(sem) || sem < 1) return;
        pay('adelanto', parseInt(sem));
      } else if(tipo === 'adelanto'){
        pay('adelanto', parseInt($(this).data('semanas'))||4);
      } else {
        pay(tipo);
      }
    });
  }

  var paying = false;
  function pay(tipo, numSemanas){
    if(paying) return;
    if(!confirm('¿Confirmar pago ('+tipo+')?')) return;
    paying = true;
    $('#vkPayNow,.vk-prepay-opt').css('opacity','0.5').css('pointer-events','none');
    VKApp.toast('Procesando pago...');
    var data = {tipo:tipo};
    if(numSemanas) data.num_semanas = numSemanas;
    VKApp.api('pagos/crear-pago-directo.php',data).done(function(r){
      if(r.ok){ VKApp.toast('Pago exitoso'); VKApp.loadEstado(function(){ render(); }); }
      else VKApp.toast(r.error||'Error en el pago');
    }).fail(function(x){ VKApp.toast((x.responseJSON&&x.responseJSON.error)||'Pago rechazado'); })
    .always(function(){ paying = false; $('#vkPayNow,.vk-prepay-opt').css('opacity','').css('pointer-events',''); });
  }
  return { render:render };
})();
