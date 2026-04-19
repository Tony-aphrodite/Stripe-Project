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

  // ── Multi-purchase banner (if client has 2+ purchases) ─────────
  function renderComprasAdicionales(){
    var e = VKApp.state.estado || {};
    var compras = e.compras || [];
    if (compras.length < 2) return '';
    var active = VKApp.getActiveCompra && VKApp.getActiveCompra();
    // Identify which compra is being displayed
    var current = null;
    if (active) {
      for (var i=0; i<compras.length; i++){
        if (compras[i].tipo === active.tipo && parseInt(compras[i].id,10) === parseInt(active.id,10)){
          current = compras[i]; break;
        }
      }
    }
    if (!current) current = compras[0];

    var tipoLabel = current.tipo === 'credito' ? 'Crédito'
                 : current.tipo === 'msi'     ? 'MSI'
                 : 'Contado';

    var html = '<div style="background:#E8F0FE;border:1px solid #90CAF9;border-radius:10px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">';
    html += '<div style="font-size:13px;color:#0D47A1;">';
    html += '<strong>Viendo:</strong> '+tipoLabel+' · '+(current.modelo||'')+' '+(current.color||'');
    html += '<span style="color:#1976D2;font-weight:700;margin-left:6px;">(ID '+current.id+')</span>';
    html += '<div style="font-size:11px;color:#37474F;margin-top:2px;">Tienes <strong>'+compras.length+' compras</strong> vinculadas a tu cuenta.</div>';
    html += '</div>';
    html += '<button class="vk-btn primary" onclick="VKApp.go(\'miscompras\')" style="font-size:12px;padding:7px 14px;">Ver todas</button>';
    html += '</div>';
    return html;
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
    // Customer brief: greet with FULL name (nombre + apellido_paterno + materno).
    // me.php now returns `nombre_completo` pre-composed; fall back to manual
    // concatenation in case an old cached payload is still in memory.
    var nombre = c.nombre_completo
      || [c.nombre, c.apellido_paterno, c.apellido_materno].filter(function(v){return v;}).join(' ').trim()
      || c.nombre
      || 'Cliente';
    var compra = e.compra || {};
    var entrega = e.entrega || {};
    var paso = entrega.paso || 1;
    var etiqueta = entrega.etiqueta || 'preparacion';
    var punto = entrega.punto || {};
    var totalFmt = compra.total ? '$'+Number(compra.total).toLocaleString('es-MX') : '$0';
    var tpagoLabel = compra.tpago === 'msi' ? 'MSI' : 'Contado';
    if(compra.msi_meses) tpagoLabel = compra.msi_meses + ' MSI';

    var html = '';
    html += renderComprasAdicionales();

    // ── Header ──
    html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">';
    html += '<div style="display:flex;align-items:center;gap:8px"><img src="../configurador_prueba/img/favicon.svg" style="width:28px;height:28px"> <span style="font-size:18px;font-weight:800;color:#333">voltika</span></div>';
    html += '<div style="display:flex;gap:12px"><span style="font-size:18px;cursor:pointer" onclick="VKApp.go(\'notificaciones\')" title="Notificaciones">&#128276;</span><span style="font-size:18px;cursor:pointer" onclick="VKApp.go(\'cuenta\')" title="Mi cuenta">&#128100;</span></div>';
    html += '</div>';

    html += '<div class="vk-h1" style="margin-bottom:2px">¡Hola, '+nombre+'!</div>';
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
      if(entrega.fecha_recoleccion) html += '<div style="font-size:13px;color:#2563eb;margin-top:4px;font-weight:700">Recógela el '+formatFechaES(entrega.fecha_recoleccion)+'</div>';
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
        html += '<div class="vk-delivery-title">Tu VOLTIKA va en camino</div>';
        html += '<div class="vk-delivery-sub">'+ (etaArrival ? 'Llegada estimada al punto: '+etaArrival : 'En tránsito hacia tu punto.') +'</div>';
      } else {
        html += '<div class="vk-delivery-title">Tu VOLTIKA esta en preparacion</div>';
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
    // Customer brief: greet with FULL name (nombre + apellido_paterno + materno).
    // me.php now returns `nombre_completo` pre-composed; fall back to manual
    // concatenation in case an old cached payload is still in memory.
    var nombre = c.nombre_completo
      || [c.nombre, c.apellido_paterno, c.apellido_materno].filter(function(v){return v;}).join(' ').trim()
      || c.nombre
      || 'Cliente';
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
      renderComprasAdicionales()+
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">'+
        '<div><div class="vk-muted">Bienvenido</div><div class="vk-h1">¡Hola, '+nombre+'!</div></div>'+
        '<div style="display:flex;align-items:center;gap:10px">'+
          '<span style="cursor:pointer" onclick="VKApp.go(\'notificaciones\')" title="Notificaciones"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg></span>'+
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

      // ── Section 2 (customer brief): backup-card explainer + change card ──
      // Mounted as a placeholder; populated by a follow-up API call so render
      // doesn't have to wait for Stripe.
      '<div class="vk-card" id="vkBackupCard">'+
        '<div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">'+
          '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#039fe1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'+
          '<div style="font-size:12.5px;font-weight:700;letter-spacing:.4px;color:#039fe1;text-transform:uppercase;">¿Para qué sirve tu tarjeta guardada?</div>'+
        '</div>'+
        '<div style="font-size:13px;color:#555;line-height:1.55;">Tu tarjeta es un <strong>respaldo automático</strong> — solo se usa si no realizas tu pago antes del vencimiento. Si ya pagaste por OXXO, SPEI o tarjeta manualmente, el cargo automático no se realiza. <strong>Tu pago nunca se duplica.</strong></div>'+
        '<div style="font-size:11.5px;font-weight:700;color:#888;letter-spacing:.5px;margin:14px 0 8px;text-transform:uppercase;">Tarjeta de respaldo</div>'+
        '<div id="vkBackupCardBody"><div class="vk-muted" style="text-align:center;padding:14px 0;font-size:12px;"><span class="vk-spin"></span> Cargando…</div></div>'+
      '</div>'+

      // ── Section 3 (customer brief): branded payment-method buttons ──
      '<div class="vk-card">'+
        '<div class="vk-h2">Paga tu semana o tu adelanto como quieras</div>'+
        '<div class="vk-pay-action" data-method="tarjeta">'+
          '<div class="vk-pay-action-icons">'+
            // Visa
            '<svg viewBox="0 0 64 24" width="34" height="14"><rect width="64" height="24" rx="3" fill="#1a1f71"/><text x="32" y="17" text-anchor="middle" fill="#fff" font-family="Arial,sans-serif" font-weight="900" font-size="13" font-style="italic" letter-spacing="1">VISA</text></svg>'+
            // Mastercard
            '<svg viewBox="0 0 32 24" width="22" height="14"><circle cx="12" cy="12" r="9" fill="#eb001b"/><circle cx="20" cy="12" r="9" fill="#f79e1b"/><path d="M16 5.5a9 9 0 010 13 9 9 0 010-13z" fill="#ff5f00"/></svg>'+
            // Amex
            '<svg viewBox="0 0 64 24" width="30" height="14"><rect width="64" height="24" rx="3" fill="#2e77bb"/><text x="32" y="16" text-anchor="middle" fill="#fff" font-family="Arial,sans-serif" font-weight="900" font-size="9">AMEX</text></svg>'+
          '</div>'+
          '<div class="vk-pay-action-body">'+
            '<div class="vk-pay-action-title">Tarjeta</div>'+
            '<div class="vk-pay-action-sub">Visa, Mastercard o Amex · Débito o crédito</div>'+
          '</div>'+
          '<svg class="vk-pay-action-arrow" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#888" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>'+
        '</div>'+
        '<div class="vk-pay-action" data-method="oxxo">'+
          '<div class="vk-pay-action-icons">'+
            // OXXO logo
            '<svg viewBox="0 0 80 32" width="46" height="20"><rect width="80" height="32" rx="4" fill="#e30613"/><text x="40" y="22" text-anchor="middle" fill="#fff" font-family="Arial Black,Arial,sans-serif" font-weight="900" font-size="16" letter-spacing="-0.5">OXXO</text></svg>'+
          '</div>'+
          '<div class="vk-pay-action-body">'+
            '<div class="vk-pay-action-title">OXXO</div>'+
            '<div class="vk-pay-action-sub">Efectivo en cualquier tienda OXXO del país</div>'+
          '</div>'+
          '<svg class="vk-pay-action-arrow" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#888" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>'+
        '</div>'+
        '<div class="vk-pay-action" data-method="spei">'+
          '<div class="vk-pay-action-icons">'+
            // SPEI logo (Banxico style, simplified)
            '<svg viewBox="0 0 80 32" width="46" height="20"><rect width="80" height="32" rx="4" fill="#0072bc"/><text x="40" y="22" text-anchor="middle" fill="#fff" font-family="Arial Black,Arial,sans-serif" font-weight="900" font-size="16" letter-spacing="0.5">SPEI</text></svg>'+
          '</div>'+
          '<div class="vk-pay-action-body">'+
            '<div class="vk-pay-action-title">SPEI</div>'+
            '<div class="vk-pay-action-sub">Pago con transferencia de cualquier banco</div>'+
          '</div>'+
          '<svg class="vk-pay-action-arrow" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#888" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>'+
        '</div>'+
      '</div>'+

      // ── Section 4 (customer brief): green-styled note replacing the old tip ──
      '<div style="background:#ecfdf5;border:1px solid #a7f3d0;border-left:4px solid #22c55e;border-radius:8px;padding:12px 14px;margin:14px 0;display:flex;gap:10px;align-items:flex-start;">'+
        '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;"><polyline points="20 6 9 17 4 12"/></svg>'+
        '<div style="font-size:13px;color:#166534;line-height:1.55;"><strong>Tu pago nunca se duplica.</strong> Si realizas un pago manual, el sistema lo detecta automáticamente y cancela cualquier cargo adicional.</div>'+
      '</div>'+

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

    // ── Branded payment-method buttons (customer brief 2026-04-19) ─────────
    // Each button starts the corresponding manual payment flow. Falls back to
    // the existing pay() function with the right tipo so the rest of the
    // pipeline (Stripe call → success toast → reload) is reused as-is.
    $('.vk-pay-action').on('click', function(){
      var method = $(this).data('method');
      if (method === 'tarjeta') pay('tarjeta_manual');
      else if (method === 'oxxo') pay('oxxo');
      else if (method === 'spei') pay('spei');
    });

    // ── Backup-card section (customer brief 2026-04-19) ────────────────────
    loadBackupCard();
  }

  function backupCardBrandSvg(brand){
    var b = (brand||'').toLowerCase();
    if (b === 'visa')
      return '<svg viewBox="0 0 64 24" width="56" height="22"><rect width="64" height="24" rx="3" fill="#1a1f71"/><text x="32" y="17" text-anchor="middle" fill="#fff" font-family="Arial,sans-serif" font-weight="900" font-size="13" font-style="italic" letter-spacing="1">VISA</text></svg>';
    if (b === 'mastercard')
      return '<svg viewBox="0 0 64 32" width="56" height="28"><rect width="64" height="32" rx="3" fill="#fff"/><circle cx="26" cy="16" r="11" fill="#eb001b"/><circle cx="38" cy="16" r="11" fill="#f79e1b"/><path d="M32 7.5a11 11 0 010 17 11 11 0 010-17z" fill="#ff5f00"/></svg>';
    if (b === 'amex' || b === 'american_express')
      return '<svg viewBox="0 0 64 24" width="56" height="22"><rect width="64" height="24" rx="3" fill="#2e77bb"/><text x="32" y="16" text-anchor="middle" fill="#fff" font-family="Arial,sans-serif" font-weight="900" font-size="9">AMEX</text></svg>';
    return '<svg viewBox="0 0 64 24" width="56" height="22"><rect width="64" height="24" rx="3" fill="#666"/><text x="32" y="16" text-anchor="middle" fill="#fff" font-family="Arial,sans-serif" font-weight="700" font-size="9">'+(brand||'CARD').toUpperCase()+'</text></svg>';
  }

  function loadBackupCard(){
    VKApp.api('pagos/metodo-pago.php').done(function(r){
      var $body = $('#vkBackupCardBody');
      if (!$body.length) return;
      var m = r && r.metodo;
      if (m && m.last4) {
        var brand = (m.brand||'').toString();
        var brandLabel = brand.charAt(0).toUpperCase() + brand.slice(1);
        $body.html(
          '<div style="display:flex;gap:12px;align-items:center;padding:12px 14px;background:#f7fafc;border:1px solid #e1e8ee;border-radius:8px;flex-wrap:wrap;">'+
            '<div style="flex-shrink:0;">'+ backupCardBrandSvg(brand) +'</div>'+
            '<div style="flex:1;min-width:140px;">'+
              '<div style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;color:#334;letter-spacing:1px;">•••• •••• •••• '+ String(m.last4||'').replace(/[<>"&]/g,'') +'</div>'+
              '<div style="font-size:11.5px;color:#666;margin-top:2px;">'+ String(brandLabel).replace(/[<>"&]/g,'') +' · Respaldo automático</div>'+
            '</div>'+
            '<button class="vk-btn ghost sm" id="vkCambiarTarjeta" style="padding:6px 14px;font-size:12px;">Cambiar</button>'+
          '</div>'
        );
      } else {
        $body.html(
          '<div style="padding:12px 14px;background:#fffbeb;border:1px solid #fde68a;border-left:4px solid #f59e0b;border-radius:8px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">'+
            '<div style="flex:1;min-width:160px;font-size:13px;color:#7a4f08;">Aún no tienes tarjeta de respaldo registrada.</div>'+
            '<button class="vk-btn primary sm" id="vkCambiarTarjeta" style="padding:6px 14px;font-size:12px;">Agregar tarjeta</button>'+
          '</div>'
        );
      }
      $('#vkCambiarTarjeta').on('click', function(){
        var $btn = $(this);
        var originalLabel = $btn.text();   // remember "Cambiar" or "Agregar tarjeta"
        $btn.prop('disabled', true).html('<span class="vk-spin"></span>');
        VKApp.api('pagos/cambiar-tarjeta.php', {}).done(function(res){
          if (res && res.url) { window.location.href = res.url; }
          else { VKApp.toast(res.error||'No se pudo iniciar el cambio'); $btn.prop('disabled', false).text(originalLabel); }
        }).fail(function(x){
          VKApp.toast((x.responseJSON&&x.responseJSON.error)||'Error de conexión');
          $btn.prop('disabled', false).text(originalLabel);
        });
      });
    }).fail(function(){
      $('#vkBackupCardBody').html('<div class="vk-muted" style="font-size:12px;">No se pudo cargar la tarjeta de respaldo.</div>');
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
