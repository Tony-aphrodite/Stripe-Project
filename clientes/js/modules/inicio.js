window.VK_inicio = (function(){

  // ── Credit portal helpers (unchanged) ───────────────────────────
  function pill(state){
    var map = {
      account_current:['ok','AL CORRIENTE'],
      payment_due_soon:['warn','PROXIMO PAGO'],
      payment_due_today:['warn','PAGA HOY'],
      payment_pending:['warn','PAGO PENDIENTE'],
      payment_overdue:['err','PAGO VENCIDO'],
      card_update_required:['err','ACTUALIZA TU TARJETA'],
      no_subscription:['warn','SIN SUSCRIPCION'],
      compra_confirmada:['ok','COMPRA CONFIRMADA']
    };
    var m = map[state]||['warn',state];
    return '<span class="vk-pill '+m[0]+'">'+m[1]+'</span>';
  }

  function formatFechaES(dateStr){
    if(!dateStr) return '';
    var d = new Date(dateStr+'T12:00:00');
    if(isNaN(d)) return dateStr;
    var dias = ['Domingo','Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'];
    var meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    return dias[d.getDay()]+' '+d.getDate()+' de '+meses[d.getMonth()];
  }

  // ── Main router ─────────────────────────────────────────────────
  function render(){
    var tipo = VKApp.state.tipoPortal;
    if(tipo === 'contado' || tipo === 'msi'){
      renderContado();
    } else {
      renderCredito();
    }
  }

  // ================================================================
  //  CONTADO / MSI — Delivery tracking home
  // ================================================================
  function renderContado(){
    var e = VKApp.state.estado || {};
    var c = VKApp.state.cliente || {};
    var nombre = c.nombrePila || (c.nombre||'').split(' ')[0] || 'Cliente';
    var compra = e.compra || {};
    var entrega = e.entrega || {};
    var paso = entrega.paso || 1;
    var etiqueta = entrega.etiqueta || 'preparacion';
    var punto = entrega.punto || {};
    var totalFmt = compra.total ? '$'+Number(compra.total).toLocaleString('es-MX') : '$0';
    var tpagoLabel = compra.tpago === 'msi' ? 'MSI' : 'Contado';
    if(compra.msi_meses) tpagoLabel = compra.msi_meses + ' MSI';

    var html = '';

    // ── Header ──
    html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">';
    html += '<div style="display:flex;align-items:center;gap:8px"><img src="../configurador_prueba/img/favicon.svg" style="width:28px;height:28px"> <span style="font-size:18px;font-weight:800;color:#333">voltika</span></div>';
    html += '<div style="display:flex;gap:12px"><span style="font-size:18px;cursor:pointer" onclick="VKApp.go(\'notificaciones\')" title="Notificaciones">&#128276;</span><span style="font-size:18px;cursor:pointer" onclick="VKApp.go(\'cuenta\')" title="Mi cuenta">&#128100;</span></div>';
    html += '</div>';

    html += '<div class="vk-h1" style="margin-bottom:2px">¡Hola, '+nombre+'! &#128075;</div>';
    html += pill(e.state||'compra_confirmada');

    // ── Dynamic message based on delivery step ──
    var statusMsg = '';
    if(etiqueta === 'listo'){
      statusMsg = 'Tu Voltika esta en camino a tu punto.';
    } else {
      statusMsg = 'Tu Voltika esta en preparacion.';
    }
    html += '<div class="vk-muted" style="margin:4px 0 16px">'+statusMsg+'</div>';

    // ── Delivery tracking card ──
    if(etiqueta === 'listo'){
      // GREEN card — ready to pick up
      html += '<div class="vk-delivery-card vk-delivery-ready">';
      html += '<div class="vk-delivery-title">Tu VOLTIKA esta lista para recoger! &#128666;</div>';
      html += '<div class="vk-delivery-sub">¡Tu scooter te espera, '+nombre+'!</div>';

      // Moto image
      var modelSlug = (compra.modelo||'').toLowerCase().replace(/\s+/g,'_');
      var colorSlug = (compra.color||entrega.color||'').toLowerCase();
      html += '<div style="text-align:center;margin:12px 0"><img src="../configurador_prueba/img/'+modelSlug+'/model.png" alt="" style="max-width:180px;height:auto" onerror="this.style.display=\'none\'"></div>';

      // Pickup point info
      html += '<div class="vk-pickup-info">';
      html += '<div style="font-weight:700;color:#333;margin-bottom:2px">Punto de entrega</div>';
      html += '<div style="font-weight:800;font-size:15px;color:#333">'+(punto.nombre||'Voltika')+'</div>';
      if(punto.direccion) html += '<div style="font-size:13px;color:#555">'+punto.direccion+'</div>';
      if(entrega.fecha_recoleccion) html += '<div style="font-size:13px;color:#2563eb;margin-top:4px;font-weight:700">📅 Recógela el '+formatFechaES(entrega.fecha_recoleccion)+'</div>';
      if(punto.horario) html += '<div style="font-size:13px;color:#22c55e;margin-top:4px">Horario: '+punto.horario+'</div>';
      html += '<button class="vk-btn-outline" style="margin-top:10px" onclick="if(window.punto_dir){window.open(\'https://maps.google.com/?q=\'+encodeURIComponent(window.punto_dir))}">VER UBICACION &rarr;</button>';
      html += '</div>';

      html += '<div class="vk-delivery-note"><span style="color:#3b82f6">&#9432;</span> Recuerda llevar tu <strong>identificacion oficial</strong> y firmar al recoger tu Voltika.</div>';
      html += '</div>';

      // Docs ready banner
      html += '<div class="vk-docs-banner">';
      html += '<div class="vk-docs-banner-left">';
      html += '<img src="../configurador_prueba/img/favicon.svg" style="width:40px;height:40px">';
      html += '</div>';
      html += '<div class="vk-docs-banner-center">';
      html += '<div style="font-weight:700;color:#333">Documentos listos para ti</div>';
      html += '<div style="font-size:12px;color:#555">Factura (CFDI), Carta factura, Poliza garantia, Manual</div>';
      html += '</div>';
      html += '<button class="vk-btn-sm-green" onclick="VKApp.go(\'documentos\')">VER DOCUMENTOS</button>';
      html += '</div>';

    } else {
      // BLUE card — preparation / in transit
      var envio = entrega.envio || {};
      var etaArrival = envio.fecha_estimada_llegada ? formatFechaES(envio.fecha_estimada_llegada) : '';
      var enTransito = !!envio.fecha_envio && !envio.fecha_recepcion;
      html += '<div class="vk-delivery-card vk-delivery-prep">';
      if (enTransito) {
        html += '<div class="vk-delivery-title">Tu VOLTIKA va en camino &#128666;</div>';
        html += '<div class="vk-delivery-sub">'+ (etaArrival ? 'Llegada estimada al punto: '+etaArrival : 'En tránsito hacia tu punto.') +'</div>';
      } else {
        html += '<div class="vk-delivery-title">Tu VOLTIKA esta en preparacion &#128640;</div>';
        html += '<div class="vk-delivery-sub">Estamos asignando tu punto de entrega y fecha estimada.</div>';
      }

      // Moto image + checklist
      var modelSlug2 = (compra.modelo||'').toLowerCase().replace(/\s+/g,'_');
      html += '<div style="display:flex;align-items:center;gap:16px;margin:14px 0">';
      html += '<img src="../configurador_prueba/img/'+modelSlug2+'/model.png" alt="" style="max-width:120px;height:auto" onerror="this.style.display=\'none\'">';
      html += '<div>';
      html += '<div style="font-size:13px;font-weight:700;color:#fff;margin-bottom:6px">Pronto tendras:</div>';
      html += '<div style="font-size:12px;color:rgba(255,255,255,.9);line-height:1.8">';
      html += '&#10003; Punto de entrega asignado<br>';
      html += '&#10003; Fecha estimada<br>';
      html += '&#10003; Notificacion por WhatsApp y correo';
      html += '</div></div></div>';

      // 4-step progress bar
      var steps = ['Preparacion','Asignacion','En transito','Listo para<br>recoger'];
      html += '<div class="vk-delivery-steps">';
      for(var i=0;i<steps.length;i++){
        var cls = (i+1) < paso ? 'done' : ((i+1)===paso ? 'active' : '');
        html += '<div class="vk-dstep '+cls+'">';
        html += '<div class="vk-dstep-dot"></div>';
        html += '<div class="vk-dstep-label">'+steps[i]+'</div>';
        html += '</div>';
      }
      html += '</div>';

      html += '<div class="vk-delivery-note"><span style="color:#60a5fa">&#9432;</span> Esto puede tomar hasta 48-72 horas.<br>Te avisaremos automaticamente cuando haya novedades.</div>';
      html += '</div>';

      // Docs banner (upcoming)
      html += '<div class="vk-docs-banner">';
      html += '<div class="vk-docs-banner-left">';
      html += '<img src="../configurador_prueba/img/favicon.svg" style="width:40px;height:40px">';
      html += '</div>';
      html += '<div class="vk-docs-banner-center">';
      html += '<div style="font-weight:700;color:#333">Documentos de tu compra <span class="vk-doc-badge yellow">Proximamente</span></div>';
      html += '<div style="font-size:12px;color:#555">Factura, carta factura, garantia y mas.<br>Estaran disponibles en cuanto se generen.</div>';
      html += '</div>';
      html += '<button class="vk-btn-sm-green" onclick="VKApp.go(\'documentos\')">Ver documentos</button>';
      html += '</div>';
    }

    // ── Resumen de tu compra ──
    html += '<div style="margin:20px 0 8px;font-size:15px;font-weight:700;color:#333">Resumen de tu compra</div>';
    html += '<div class="vk-summary-grid">';
    html += '<div class="vk-summary-item"><div class="vk-summary-icon">&#128179;</div><div class="vk-summary-label">Compra</div><div class="vk-summary-value">'+tpagoLabel+'</div></div>';
    html += '<div class="vk-summary-item"><div class="vk-summary-icon">&#128176;</div><div class="vk-summary-label">Monto</div><div class="vk-summary-value">'+totalFmt+' MXN</div></div>';
    html += '<div class="vk-summary-item"><div class="vk-summary-icon">&#9989;</div><div class="vk-summary-label">Estado</div><div class="vk-summary-value" style="color:#22c55e;font-weight:700">Pagado</div></div>';
    html += '</div>';

    // ── Accesos rapidos ──
    html += '<div style="margin:20px 0 8px;font-size:15px;font-weight:700;color:#333">Accesos rapidos</div>';
    html += '<div class="vk-quick-grid">';
    html += '<div class="vk-quick-item" onclick="VKApp.go(\'ayuda\')"><div class="vk-quick-icon">&#127919;</div><div class="vk-quick-label">Centro de ayuda</div><div class="vk-quick-sub">Preguntas frecuentes</div></div>';
    html += '<div class="vk-quick-item" onclick="VKApp.go(\'mivoltika\')"><div class="vk-quick-icon">&#128269;</div><div class="vk-quick-label">Estado de pedido</div><div class="vk-quick-sub">Ver detalles</div></div>';
    html += '<div class="vk-quick-item" onclick="VKApp.go(\'ayuda\')"><div class="vk-quick-icon">&#128172;</div><div class="vk-quick-label">Contacto soporte</div><div class="vk-quick-sub">Escribenos</div></div>';
    html += '</div>';

    VKApp.render(html);

    // Store punto direction for map link
    if(punto.direccion) window.punto_dir = punto.direccion;
  }

  // ================================================================
  //  CREDIT — Weekly payment home (existing)
  // ================================================================
  function renderCredito(){
    var e = VKApp.state.estado || {};
    var c = VKApp.state.cliente || {};
    var nombre = c.nombrePila || (c.nombre||'').split(' ')[0] || 'Cliente';
    var prox = e.proximo_pago || {};
    var prog = e.progreso || {};
    var pct = prog.total? Math.round((prog.pagados/prog.total)*100):0;
    var montoNum = Number(prox.monto||0);
    var monto = montoNum ? '$'+montoNum.toLocaleString('es-MX') : '';
    var fecha = prox.fecha_vencimiento || '';
    var fechaES = formatFechaES(fecha);

    var monto2 = montoNum*2;
    var monto4 = montoNum*4;

    VKApp.render(
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">'+
        '<div><div class="vk-muted">Bienvenido</div><div class="vk-h1">¡Hola, '+nombre+'!</div></div>'+
        '<div style="display:flex;align-items:center;gap:10px">'+
          '<span style="font-size:20px;cursor:pointer" onclick="VKApp.go(\'notificaciones\')" title="Notificaciones">&#128276;</span>'+
          pill(e.state||'no_subscription')+
        '</div>'+
      '</div>'+

      // --- Main payment card (dark blue) ---
      '<div class="vk-card vk-card-dark">'+
        '<div class="vk-card-dark-label">Paga esta semana</div>'+
        '<div class="vk-card-dark-amount">'+monto+'</div>'+
        '<div class="vk-card-dark-fecha">Vence: <strong>'+fechaES+'</strong></div>'+
        '<button id="vkPayNow" class="vk-btn-pay">PAGAR '+monto+'</button>'+
        '<div class="vk-trust-line">Pago 100% seguro &bull; Te toma menos de 1 minuto</div>'+
        '<div class="vk-trust-sub">Si pagas ahora, no se volvera a cobrar automaticamente.<br>Tu tarjeta domiciliada solo se usa si no realizas el pago a tiempo.</div>'+
      '</div>'+

      // --- Prepay options (4 cards) ---
      '<div class="vk-card">'+
        '<div class="vk-h2">¡Adelanta pagos sin penalizacion!</div>'+
        '<div class="vk-muted">Paga dos semanas o mas y termina tu plan antes.</div>'+
        '<div class="vk-prepay-grid" style="margin-top:12px">'+
          '<div class="vk-prepay-opt" data-tipo="semanal">'+
            '<div class="vk-prepay-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg></div>'+
            '<div class="vk-prepay-label">1 semana</div>'+
            '<div class="vk-prepay-amount">'+(montoNum?'$'+montoNum.toLocaleString('es-MX'):'')+'</div>'+
            '<div class="vk-prepay-cur">MXN</div>'+
          '</div>'+
          '<div class="vk-prepay-opt popular" data-tipo="dos_semanas">'+
            '<div class="vk-prepay-badge">POPULAR</div>'+
            '<div class="vk-prepay-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg></div>'+
            '<div class="vk-prepay-label">2 semanas</div>'+
            '<div class="vk-prepay-amount">'+(monto2?'$'+monto2.toLocaleString('es-MX'):'')+'</div>'+
            '<div class="vk-prepay-cur">MXN</div>'+
          '</div>'+
          '<div class="vk-prepay-opt" data-tipo="adelanto" data-semanas="4">'+
            '<div class="vk-prepay-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg></div>'+
            '<div class="vk-prepay-label">4 semanas</div>'+
            '<div class="vk-prepay-amount">'+(monto4?'$'+monto4.toLocaleString('es-MX'):'')+'</div>'+
            '<div class="vk-prepay-cur">MXN</div>'+
            '<div class="vk-prepay-impact">MAYOR IMPACTO</div>'+
          '</div>'+
          '<div class="vk-prepay-opt" data-tipo="custom">'+
            '<div class="vk-prepay-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg></div>'+
            '<div class="vk-prepay-label">Elegir monto</div>'+
            '<div class="vk-prepay-amount">Tu decides</div>'+
            '<div class="vk-prepay-cur">MXN</div>'+
          '</div>'+
        '</div>'+
      '</div>'+

      // --- Payment methods ---
      '<div class="vk-card">'+
        '<div class="vk-h2">Paga tu semana o tu adelanto como quieras</div>'+
        '<div class="vk-pay-method">'+
          '<span class="vk-pm-icon"><svg viewBox="0 0 24 24" width="28" height="28"><rect x="1" y="4" width="22" height="16" rx="3" fill="#1a1f71"/><rect x="1" y="8" width="22" height="3" fill="#f7b600"/></svg></span>'+
          '<span class="k">Tarjeta guardada</span><span class="v">Automatica</span>'+
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

      '<div class="vk-banner ok">Tu pago manual siempre tiene prioridad. Nunca cobramos dos veces por la misma semana.</div>'+

      '<a class="vk-link" onclick="VKApp.go(\'pagos\')">Ver todos mis pagos &rarr;</a>'
    );

    $('#vkPayNow').on('click', function(){ pay('semanal'); });
    $('.vk-prepay-opt').on('click', function(){
      var tipo = $(this).data('tipo');
      if(tipo === 'custom'){
        var sem = prompt('¿Cuantas semanas deseas adelantar?','4');
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
