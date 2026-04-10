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
  function render(){
    var e = VKApp.state.estado || {};
    var c = VKApp.state.cliente || {};
    var nombre = c.nombrePila || (c.nombre||'').split(' ')[0] || 'Cliente';
    var prox = e.proximo_pago || {};
    var prog = e.progreso || {};
    var pct = prog.total? Math.round((prog.pagados/prog.total)*100):0;
    var monto = prox.monto ? '$'+Number(prox.monto).toLocaleString('es-MX') : '—';
    var fecha = prox.fecha_vencimiento || '—';

    VKApp.render(
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">'+
        '<div><div class="vk-muted">Bienvenido</div><div class="vk-h1">¡Hola, '+nombre+'!</div></div>'+
        pill(e.state||'no_subscription')+
      '</div>'+

      '<div class="vk-card hl">'+
        '<div class="vk-muted">Paga esta semana ⚡</div>'+
        '<div class="vk-amount">'+monto+'</div>'+
        '<div class="vk-muted">Vence: '+fecha+'</div>'+
        '<div class="vk-progress"><div style="width:'+pct+'%"></div></div>'+
        '<div class="vk-muted">Semana '+(prog.pagados||0)+' de '+(prog.total||0)+' ('+pct+'%)</div>'+
        '<button id="vkPayNow" class="vk-btn primary">Pagar ahora</button>'+
      '</div>'+

      '<div class="vk-card">'+
        '<div class="vk-h2">¡Adelanta pagos sin penalización! 💰</div>'+
        '<div class="vk-muted">Paga dos semanas o más y termina tu plan antes.</div>'+
        '<div class="vk-grid3" style="margin-top:10px">'+
          '<div class="vk-chip" data-tipo="semanal"><span class="ic">1️⃣</span>1 semana</div>'+
          '<div class="vk-chip" data-tipo="dos_semanas"><span class="ic">2️⃣</span>2 semanas</div>'+
          '<div class="vk-chip" data-tipo="adelanto"><span class="ic">🚀</span>Adelanto</div>'+
        '</div>'+
      '</div>'+

      '<div class="vk-card">'+
        '<div class="vk-h2">Paga como quieras</div>'+
        '<div class="vk-row"><span class="k">💳 Tarjeta guardada</span><span class="v">Automática</span></div>'+
        '<div class="vk-row"><span class="k">🏦 Transferencia SPEI</span><span class="v">Manual</span></div>'+
        '<div class="vk-row"><span class="k">🏪 OXXO</span><span class="v">Efectivo</span></div>'+
      '</div>'+

      '<div class="vk-banner ok">🔒 Tu pago manual siempre tiene prioridad. Nunca cobramos dos veces por la misma semana.</div>'+

      '<a class="vk-link" onclick="VKApp.go(\'pagos\')">Ver todos mis pagos →</a>'
    );
    $('#vkPayNow').on('click', function(){ pay('semanal'); });
    $('.vk-chip').on('click', function(){ pay($(this).data('tipo')); });
  }
  var paying = false;
  function pay(tipo){
    if(paying) return;
    if(!confirm('¿Confirmar pago ('+tipo+')?')) return;
    paying = true;
    $('#vkPayNow,.vk-chip').css('opacity','0.5').css('pointer-events','none');
    VKApp.toast('Procesando pago...');
    VKApp.api('pagos/crear-pago-directo.php',{tipo:tipo}).done(function(r){
      if(r.ok){ VKApp.toast('✅ Pago exitoso'); VKApp.loadEstado(function(){ render(); }); }
      else VKApp.toast(r.error||'Error en el pago');
    }).fail(function(x){ VKApp.toast((x.responseJSON&&x.responseJSON.error)||'Pago rechazado'); })
    .always(function(){ paying = false; $('#vkPayNow,.vk-chip').css('opacity','').css('pointer-events',''); });
  }
  return { render:render };
})();
