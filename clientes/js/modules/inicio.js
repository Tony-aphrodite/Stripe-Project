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
    // Class-based handler (wired below). Inline onclick failed silently for
    // some clients — using jQuery + e.preventDefault is more robust and gives
    // us a visible toast on the (extremely unlikely) case VKApp.go isn't ready.
    html += '<button class="vk-btn primary vkVerTodasCompras" type="button" style="font-size:12px;padding:7px 14px;">Ver mis compras</button>';
    html += '</div>';
    return html;
  }

  // Direct binding helper — called right after every VKApp.render() that
  // includes the multi-purchase banner. Matches the pattern used by every
  // other working button in this file (#vkPayNow, .vk-pay-action, etc).
  function wireVerTodas(){
    $('.vkVerTodasCompras').off('click').on('click', function(e){
      e.preventDefault();
      VKApp.go('miscompras');
    });
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
  //  CONTADO / MSI / SPEI / OXXO — single-payment home
  //  Customer brief 2026-04-19: hero moto card + payment summary.
  // ================================================================

  // Specs are resolved from the SHARED catalog (configurador/js/data/
  // productos.js + clientes/js/data/catalogo-specs.js) so that velocidad and
  // autonomía always match the configurador home page — no more drift
  // between the store ("M05 75 km/h 90 km") and the portal ("M05 85 km/h
  // 120 km"). Battery voltage lives in catalogo-specs.js because
  // productos.js doesn't carry it.
  function specsFor(modelo){
    if (window.VK_SPECS && typeof window.VK_SPECS.forModel === 'function') {
      return window.VK_SPECS.forModel(modelo);
    }
    // Emergency fallback only — VK_SPECS is loaded in index.php before this
    // module, so in normal operation this branch never runs.
    return { vel: '—', auton: '—', bat: '—' };
  }
  function modeloImg(modelo, color){
    if (!modelo) return null;
    var slug = String(modelo).toLowerCase().replace(/\s+/g, '_').replace(/\+/g, 'plus');
    // Color-specific filename (matches perfil.php pattern)
    var colorMap = { negro:'black', gris:'grey', plata:'silver', azul:'blue', rojo:'red', blanco:'white' };
    var c = (color||'').toLowerCase();
    var colorFile = null;
    for (var k in colorMap) if (c.indexOf(k) !== -1) { colorFile = colorMap[k] + '_side.png'; break; }
    var base = '../configurador/img/' + slug + '/';
    return colorFile ? (base + colorFile) : (base + 'model.png');
  }
  function colorDot(color){
    var c = (color||'').toLowerCase();
    var hex = '#888';
    if (c.indexOf('negro') !== -1) hex = '#1a1a1a';
    else if (c.indexOf('gris') !== -1 || c.indexOf('plata') !== -1) hex = '#9ca3af';
    else if (c.indexOf('blanco') !== -1) hex = '#f3f4f6';
    else if (c.indexOf('azul') !== -1) hex = '#2563eb';
    else if (c.indexOf('rojo') !== -1) hex = '#dc2626';
    return '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:'+hex+';border:1px solid #ddd;vertical-align:middle;margin-right:4px;"></span>';
  }
  function shortDate(iso){
    if (!iso) return '';
    try {
      var meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
      var d = new Date(iso);
      return d.getDate() + ' ' + meses[d.getMonth()];
    } catch(e){ return iso; }
  }
  function fullDate(iso){
    if (!iso) return '';
    try {
      var meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
      var d = new Date(iso);
      return d.getDate() + ' ' + meses[d.getMonth()] + ' ' + d.getFullYear();
    } catch(e){ return iso; }
  }

  function renderContado(){
    var e = VKApp.state.estado || {};
    var c = VKApp.state.cliente || {};
    var nombre = c.nombre_completo
      || [c.nombre, c.apellido_paterno, c.apellido_materno].filter(function(v){return v;}).join(' ').trim()
      || c.nombre
      || 'Cliente';
    var compra  = e.compra  || {};
    var entrega = e.entrega || {};
    var punto   = entrega.punto || {};
    var modelo  = entrega.modelo || compra.modelo || '';
    var color   = entrega.color  || compra.color  || '';
    var vin     = entrega.vin    || '';
    var vinTail = vin ? vin.toString().slice(-3) : '';
    var specs   = specsFor(modelo);
    var motoImg = modeloImg(modelo, color);
    var totalFmt = compra.total ? '$'+Number(compra.total).toLocaleString('es-MX', {minimumFractionDigits:0, maximumFractionDigits:0}) : '$0';
    var modalidad = compra.tpago === 'msi'
      ? ((compra.msi_meses ? compra.msi_meses + ' ' : '') + 'MSI')
      : (compra.tpago === 'oxxo' ? 'Contado · OXXO'
        : (compra.tpago === 'spei' ? 'Contado · SPEI' : 'Contado'));
    var metodoLabel = compra.metodo_label || 'Pagado';
    if (compra.metodo_last4) metodoLabel = (compra.metodo_label || 'Tarjeta') + ' •••• ' + compra.metodo_last4;
    var folio = compra.pedido_corto || (compra.pedido ? 'VK-'+compra.pedido : '—');
    var entregadoTxt = entrega.etiqueta === 'listo'
      ? ('Entregada · ' + shortDate(entrega.envio && entrega.envio.fecha_recepcion) + (punto.nombre ? ' · ' + punto.nombre : ''))
      : 'Tu Voltika está en preparación';

    var html = '';
    html += renderComprasAdicionales();

    // ── Header: Bienvenido + ¡Hola, Nombre! + 🔔 badge ──
    html += '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px;">'+
              '<div>'+
                '<div class="vk-muted" style="font-size:13px;margin-bottom:2px;">Bienvenido</div>'+
                '<div class="vk-h1" style="margin:0;">¡Hola, '+nombre+'!</div>'+
              '</div>'+
              '<button class="vk-bell-btn" onclick="VKApp.go(\'notificaciones\')" title="Notificaciones" '+
                'style="position:relative;border:none;background:transparent;cursor:pointer;padding:6px;">'+
                '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#1a3a5c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'+
                  '<path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/>'+
                '</svg>'+
              '</button>'+
            '</div>';

    // ── Hero moto card (4:3 photo + specs + entrega status) ──
    html += '<div class="vk-card vk-moto-hero">'+
              '<div class="vk-moto-hero-photo">'+
                (motoImg
                  ? '<img src="'+motoImg+'" alt="Tu moto" onerror="this.parentElement.classList.add(\'no-img\');this.style.display=\'none\'">'
                  : '<div class="vk-moto-placeholder">Tu moto</div>')+
                (vinTail ? '<div class="vk-moto-vintag">VIN ··· '+vinTail+'</div>' : '')+
              '</div>'+
              '<div class="vk-moto-hero-body">'+
                '<div class="vk-moto-hero-row">'+
                  '<div class="vk-moto-hero-title">Voltika '+(modelo || '—')+'</div>'+
                  '<div class="vk-moto-hero-color">'+colorDot(color)+(color || '—')+'</div>'+
                '</div>'+
                '<div class="vk-moto-hero-sub">Motocicleta eléctrica · 2026</div>'+

                '<div class="vk-spec-grid">'+
                  '<div class="vk-spec"><div class="vk-spec-val">'+specs.vel+'</div><div class="vk-spec-lbl">VEL.</div></div>'+
                  '<div class="vk-spec"><div class="vk-spec-val">'+specs.auton+'</div><div class="vk-spec-lbl">AUTON.</div></div>'+
                  '<div class="vk-spec"><div class="vk-spec-val">'+specs.bat+'</div><div class="vk-spec-lbl">BAT.</div></div>'+
                '</div>'+

                '<div class="vk-moto-hero-status">'+
                  (entrega.etiqueta === 'listo'
                    ? '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg><span style="color:#166534;">'+entregadoTxt+'</span>'
                    : '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#b45309" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><span style="color:#b45309;">'+entregadoTxt+'</span>'
                  )+
                '</div>'+
              '</div>'+
            '</div>';

    // ── Resumen de pago card ──
    // Round 70 v4 (2026-05-23, Óscar — "says paid but order isn't paid yet"):
    // previously this card hardcoded "Pagado al 100%" + "Tu compra está
    // liquidada" + green check, regardless of whether the order had
    // actually cleared. For SPEI/OXXO orders sitting in pago_estado='pendiente'
    // (customer hasn't paid at the bank yet), this falsely told the
    // customer their purchase was complete and they could just wait
    // for delivery — leading to no-pays and ghost orders. We now
    // branch the UI by the real pago_estado returned by me.php.
    var _pe = String(compra.pago_estado || '').toLowerCase();
    var _esPagado = (_pe === 'pagada' || _pe === 'aprobada' ||
                     _pe === 'approved' || _pe === 'paid');
    var _esCreditoParcial = (_pe === 'parcial');
    // Different visuals for paid vs pending vs failed.
    var _statusBigLabel, _statusFooter, _headIcon, _amountColor;
    if (_esPagado) {
        _statusBigLabel = 'Pagado al 100%';
        _statusFooter   = 'Tu compra está liquidada';
        _headIcon = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
        _amountColor = '';
    } else if (_esCreditoParcial) {
        _statusBigLabel = 'Enganche pagado · resto a plazos';
        _statusFooter   = 'Pagos semanales activos';
        _headIcon = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#039fe1" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
        _amountColor = '';
    } else if (_pe === 'fallido' || _pe === 'cancelada') {
        _statusBigLabel = 'Pago no completado';
        _statusFooter   = 'Tu pago no se procesó. Intenta de nuevo o contacta soporte.';
        _headIcon = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
        _amountColor = 'color:#dc2626;';
    } else {
        // pendiente, requires_action, vacío, etc. — SPEI/OXXO waiting,
        // 3DS not completed, processing.
        var _metodoPago = String(compra.tpago || '').toLowerCase();
        var _hintByMethod = {
            spei:    'Espera la confirmación del depósito SPEI (puede tardar hasta 24 horas).',
            oxxo:    'Paga en OXXO con la ficha que te enviamos. Tu pago se aplicará en 24-48 horas.',
            tarjeta: 'Estamos esperando la confirmación de tu banco.',
            contado: 'Estamos esperando la confirmación de tu pago.'
        };
        _statusBigLabel = 'Pago pendiente';
        _statusFooter   = _hintByMethod[_metodoPago] || 'Estamos esperando la confirmación de tu pago.';
        _headIcon = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#d97706" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
        _amountColor = 'color:#7c2d12;';
    }
    var _paidClass = _esPagado || _esCreditoParcial ? 'vk-resumen-paid' : 'vk-resumen-paid vk-resumen-pending';
    var _paidStyle = _esPagado || _esCreditoParcial
        ? ''
        : 'color:#9a3412;background:#fff7ed;border:1px solid #fed7aa;padding:6px 10px;border-radius:6px;display:inline-block;';

    html += '<div class="vk-card vk-resumen-pago">'+
              '<div class="vk-resumen-head">'+
                '<div class="vk-resumen-h">RESUMEN DE PAGO</div>'+
                _headIcon +
              '</div>'+
              '<div class="vk-resumen-amount" style="'+_amountColor+'">'+totalFmt+'</div>'+
              '<div class="'+_paidClass+'" style="'+_paidStyle+'">'+_statusBigLabel+'</div>'+
              '<div class="vk-resumen-divider"></div>'+
              '<div class="vk-resumen-rows">'+
                '<div class="vk-resumen-row"><span class="k">Modalidad</span><span class="v">'+modalidad+'</span></div>'+
                '<div class="vk-resumen-row"><span class="k">Método</span><span class="v">'+metodoLabel+'</span></div>'+
                '<div class="vk-resumen-row"><span class="k">Fecha</span><span class="v">'+(compra.fecha ? fullDate(compra.fecha) : '—')+'</span></div>'+
                '<div class="vk-resumen-row"><span class="k">Folio</span><span class="v" style="font-family:ui-monospace,Consolas,monospace;font-size:12.5px;">'+folio+'</span></div>'+
                '<div class="vk-resumen-row"><span class="k">Factura</span>'+
                  (compra.factura_disponible
                    ? '<a class="v vk-link" onclick="VKApp.go(\'documentos\')" style="cursor:pointer;">Disponible</a>'
                    : '<span class="v" style="color:#888;">Tras la entrega</span>')+
                '</div>'+
              '</div>'+
              '<div class="vk-resumen-footer" style="'+(_esPagado || _esCreditoParcial ? '' : 'color:#9a3412;font-weight:600;')+'">'+_statusFooter+'</div>'+
            '</div>';

    VKApp.render(html);
    wireVerTodas();

    if (punto.direccion) window.punto_dir = punto.direccion;
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

    // Customer brief 2026-05-07: when there are overdue cycles, the
    // main payment card must shout "Paga de Inmediato" with the
    // accumulated past-due amount (e.g. 2 weeks past due of $554 →
    // $1,108) in bold red, and the date label switches from "Vence:"
    // to "Vencido desde el …". The amount paid via PAGAR also
    // settles the full overdue total, not just one week.
    var venc = e.vencido || {};
    var isOverdue = (Number(venc.count) || 0) > 0;
    var overdueAmount = Number(venc.monto || 0);
    var overdueAmountStr = overdueAmount ? '$'+overdueAmount.toLocaleString('es-MX') : '';
    var overdueDesdeES = formatFechaES(venc.desde || fecha);

    // What the dark card actually shows depends on isOverdue.
    var titleText  = isOverdue ? 'Paga de Inmediato' : 'Paga esta semana';
    var amountText = isOverdue ? overdueAmountStr   : monto;
    var amountCls  = isOverdue ? 'vk-card-dark-amount vk-card-dark-amount--overdue' : 'vk-card-dark-amount';
    var fechaLabel = isOverdue ? 'Vencido desde el ' : 'Vence: ';
    var fechaShown = isOverdue ? overdueDesdeES     : fechaES;
    var btnText    = isOverdue ? ('PAGAR '+overdueAmountStr) : ('PAGAR '+monto);

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
        '<div class="vk-card-dark-label">'+titleText+'</div>'+
        '<div class="'+amountCls+'">'+amountText+'</div>'+
        '<div class="vk-card-dark-fecha">'+fechaLabel+'<strong>'+fechaShown+'</strong></div>'+
        '<button id="vkPayNow" class="vk-btn-pay">'+btnText+'</button>'+
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
      // Customer brief 2026-05-07 (item 4): the backup card section
      // header used to show only the "TARJETA DE RESPALDO" label with
      // no clear CTA. Now we surface a bold "Cambiar tu tarjeta"
      // subtitle directly below the title — this is the only allowed
      // action (cards can be replaced but never eliminated).
      '<div class="vk-card" id="vkBackupCard">'+
        '<div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">'+
          '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#039fe1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'+
          '<div style="font-size:12.5px;font-weight:700;letter-spacing:.4px;color:#039fe1;text-transform:uppercase;">¿Para qué sirve tu tarjeta guardada?</div>'+
        '</div>'+
        '<div style="font-size:13px;color:#555;line-height:1.55;">Tu tarjeta es un <strong>respaldo automático</strong> — solo se usa si no realizas tu pago antes del vencimiento. Si ya pagaste por OXXO, SPEI o tarjeta manualmente, el cargo automático no se realiza. <strong>Tu pago nunca se duplica.</strong></div>'+
        '<div style="margin:16px 0 4px;">'+
          '<div style="font-size:13px;font-weight:700;color:#1a3a5c;letter-spacing:.3px;text-transform:uppercase;">Tarjeta de Respaldo</div>'+
          '<div style="font-size:13px;font-weight:800;color:#0f172a;margin-top:4px;">Cambiar tu tarjeta</div>'+
        '</div>'+
        '<div id="vkBackupCardBody" style="margin-top:8px;"><div class="vk-muted" style="text-align:center;padding:14px 0;font-size:12px;"><span class="vk-spin"></span> Cargando…</div></div>'+
      '</div>'+

      // ── Section 3 (customer brief): branded payment-method buttons ──
      '<div class="vk-card">'+
        '<div class="vk-h2">Paga tu semana o tu adelanto como quieras</div>'+
        // Customer brief 2026-05-07: previous inline SVGs were
        // hand-drawn approximations and the customer flagged them as
        // "looking fake". Replaced with the real brand assets shipped
        // under /configurador/img/ (oxxo_logo.png, logo_spei.png) and
        // /configurador/img/tarjetas/ (visa.svg, mastercard.svg,
        // amex.svg). Each <img> uses height for vertical alignment +
        // alt text for accessibility/SEO.
        '<div class="vk-pay-action" data-method="tarjeta">'+
          '<div class="vk-pay-action-icons">'+
            '<img src="/configurador/img/tarjetas/visa.svg" alt="Visa" style="height:14px;width:auto;">'+
            '<img src="/configurador/img/tarjetas/mastercard.svg" alt="Mastercard" style="height:18px;width:auto;">'+
            '<img src="/configurador/img/tarjetas/amex.svg" alt="American Express" style="height:18px;width:auto;">'+
          '</div>'+
          '<div class="vk-pay-action-body">'+
            '<div class="vk-pay-action-title">Tarjeta</div>'+
            '<div class="vk-pay-action-sub">Visa, Mastercard o Amex · Débito o crédito</div>'+
          '</div>'+
          '<svg class="vk-pay-action-arrow" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#888" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>'+
        '</div>'+
        '<div class="vk-pay-action" data-method="oxxo">'+
          '<div class="vk-pay-action-icons">'+
            '<img src="/configurador/img/oxxo_logo.png" alt="OXXO" style="height:22px;width:auto;">'+
          '</div>'+
          '<div class="vk-pay-action-body">'+
            '<div class="vk-pay-action-title">OXXO</div>'+
            '<div class="vk-pay-action-sub">Efectivo en cualquier tienda OXXO del país</div>'+
          '</div>'+
          '<svg class="vk-pay-action-arrow" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#888" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>'+
        '</div>'+
        '<div class="vk-pay-action" data-method="spei">'+
          '<div class="vk-pay-action-icons">'+
            '<img src="/configurador/img/logo_spei.png" alt="SPEI" style="height:22px;width:auto;">'+
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

    wireVerTodas();

    // Customer brief 2026-05-07 (item 3, 3.1): the prepay options
    // were "selecting" by triggering an immediate charge — there was
    // no single-selection visual state, so users saw both the always-
    // highlighted POPULAR card and their last click as "selected at
    // the same time". Now the prepay options work like radio buttons:
    // click → toggle .selected (deselects others), and ALL the
    // payment buttons (vkPayNow + Tarjeta/OXXO/SPEI rows) update
    // their visible amount to match. Payment is only triggered when
    // the user actually clicks a payment method.
    //
    // selectedPrepay = null  → default weekly amount (or overdue total)
    // selectedPrepay = { tipo, semanas, monto } → user picked an option
    var selectedPrepay = null;

    function effectiveAmount() {
      if (selectedPrepay) return Number(selectedPrepay.monto || 0);
      // No prepay selected — fall back to overdue (if any) or normal weekly.
      return isOverdue ? overdueAmount : montoNum;
    }
    function effectiveAmountStr() {
      var n = effectiveAmount();
      return n ? '$' + n.toLocaleString('es-MX') : '';
    }
    function refreshPayButtonsCopy() {
      var amt = effectiveAmountStr();
      // Main dark-card PAGAR button — keeps "PAGAR " prefix.
      $('#vkPayNow').text('PAGAR ' + amt);
      // Tarjeta / OXXO / SPEI rows — replace the .vk-pay-action-sub
      // text only when a prepay is actively selected, so the rows
      // still show their normal descriptive copy in the default
      // (no-prepay) state. Customer brief 3.1: "the payment
      // reference amount has to automatically indicate the new
      // prepay amount" when a prepay is selected.
      $('.vk-pay-action').each(function(){
        var $row = $(this);
        var $sub = $row.find('.vk-pay-action-sub');
        var orig = $sub.data('orig');
        if (orig === undefined) {
          orig = $sub.text();
          $sub.data('orig', orig);
        }
        if (selectedPrepay) {
          $sub.html('<strong style="color:#039fe1;">Pagar adelanto: ' + amt + '</strong>');
        } else {
          $sub.text(orig);
        }
      });
    }

    $('#vkPayNow').on('click', function(){
      if (selectedPrepay) {
        if (selectedPrepay.tipo === 'custom' || selectedPrepay.tipo === 'adelanto') {
          pay('adelanto', selectedPrepay.semanas || 4);
        } else {
          pay(selectedPrepay.tipo);
        }
      } else {
        pay('semanal');
      }
    });

    // Deep-link from cobranza notifications (?action=pay) — auto-open the
    // primary payment button once the inicio finishes rendering.
    if (VKApp.state && VKApp.state.pendingAction === 'pay') {
      VKApp.state.pendingAction = null;
      setTimeout(function(){ $('#vkPayNow').trigger('click'); }, 450);
    }
    $('.vk-prepay-opt').on('click', function(){
      var $card = $(this);
      var tipo = $card.data('tipo');
      var monto = 0;
      var semanas = 0;
      if (tipo === 'custom') {
        var sem = prompt('¿Cuantas semanas deseas adelantar?','4');
        if (!sem || isNaN(sem) || sem < 1) return;
        semanas = parseInt(sem);
        monto = montoNum * semanas;
      } else if (tipo === 'adelanto') {
        semanas = parseInt($card.data('semanas')) || 4;
        monto = montoNum * semanas;
      } else if (tipo === 'dos_semanas') {
        semanas = 2;
        monto = montoNum * 2;
      } else {
        // semanal
        semanas = 1;
        monto = montoNum;
      }
      // Toggle: click again to deselect.
      var wasSelected = $card.hasClass('selected');
      $('.vk-prepay-opt').removeClass('selected');
      if (wasSelected) {
        selectedPrepay = null;
      } else {
        $card.addClass('selected');
        selectedPrepay = { tipo: tipo, semanas: semanas, monto: monto };
      }
      refreshPayButtonsCopy();
      return; // do NOT auto-pay anymore — user picks a payment method next.
    });

    // ── Branded payment-method buttons (customer brief 2026-04-19) ─────────
    // Customer brief 2026-05-07 (3.1): when a prepay is selected, the
    // method buttons must charge the selected prepay amount instead of
    // the default weekly amount. We translate the selection into the
    // tipo/semanas args expected by payWithX() — 'adelanto' with the
    // weeks count for prepay, 'semanal' for the default path.
    $('.vk-pay-action').on('click', function(){
      var method = $(this).data('method');
      var tipo, semanas;
      if (selectedPrepay) {
        tipo = (selectedPrepay.tipo === 'semanal') ? 'semanal' : 'adelanto';
        semanas = selectedPrepay.semanas || 1;
      } else {
        tipo = 'semanal';
        semanas = 1;
      }
      if (method === 'tarjeta') payWithTarjeta(tipo, semanas);
      else if (method === 'oxxo') payWithOxxo(tipo, semanas);
      else if (method === 'spei') payWithSpei(tipo, semanas);
    });

    // ── Backup-card section (customer brief 2026-04-19) ────────────────────
    loadBackupCard();

    // ── Pending OXXO/SPEI banner (re-open instructions) ────────────────────
    loadPendientesPagos();
  }

  function loadPendientesPagos(){
    VKApp.api('pagos/pendientes.php').done(function(r){
      var list = (r && r.pendientes) || [];
      if (!list.length) { $('#vkPendientesPagos').remove(); return; }
      var html = '<div id="vkPendientesPagos" class="vk-card" style="border-left:4px solid #f59e0b;background:#fffbeb;">'+
        '<div class="vk-h2" style="margin:0 0 6px;font-size:15px;color:#7a4f08;">Pagos en proceso</div>'+
        '<div style="font-size:12.5px;color:#78350f;margin-bottom:10px;">Estas referencias siguen pendientes. Cuando Voltika reciba tu pago se marcará automáticamente.</div>';
      list.forEach(function(p){
        var isOxxo = p.origen === 'portal_oxxo';
        var label  = isOxxo ? 'OXXO' : 'SPEI';
        var color  = isOxxo ? '#e30613' : '#0072bc';
        var monto  = speiFormatMonto(p.monto);
        html += '<div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-top:1px dashed #fcd34d;">'+
          '<div style="background:'+color+';color:#fff;font-weight:900;font-size:11px;padding:4px 8px;border-radius:4px;letter-spacing:.5px;">'+ label +'</div>'+
          '<div style="flex:1;font-size:13px;color:#0f172a;"><strong>'+ monto +'</strong></div>'+
          '<button class="vk-btn ghost sm vkReopenPendiente" data-id="'+ p.id +'" type="button" style="padding:6px 12px;font-size:12px;">Ver instrucciones</button>'+
        '</div>';
      });
      html += '</div>';
      $('#vkPendientesPagos').remove();
      // Insert the banner right after the first header (vk-h1) so it's prominent
      var $host = $('#vkScreen').find('.vk-h1').first();
      if ($host.length) $host.after(html); else $('#vkScreen').prepend(html);

      // Cache pending data on the DOM so re-open doesn't need another round-trip
      $('#vkPendientesPagos').data('cache', list);
      $(document).off('click.vkReopen').on('click.vkReopen', '.vkReopenPendiente', function(){
        var id = String($(this).data('id'));
        var cache = $('#vkPendientesPagos').data('cache') || [];
        var p = cache.filter(function(x){ return String(x.id) === id; })[0];
        if (!p) return;
        if (p.origen === 'portal_oxxo') showOxxoModal(p);
        else if (p.origen === 'portal_spei') showSpeiModal(p);
      });
    });
  }

  // Customer brief 2026-05-07: backup-card brand badge in the Tarjeta
  // domiciliada section now uses the real brand SVGs from
  // /configurador/img/tarjetas/, matching the payment-action icons
  // above. Unknown brands fall back to a neutral grey badge.
  function backupCardBrandSvg(brand){
    var b = (brand||'').toLowerCase();
    if (b === 'visa')
      return '<img src="/configurador/img/tarjetas/visa.svg" alt="Visa" style="height:22px;width:auto;">';
    if (b === 'mastercard')
      return '<img src="/configurador/img/tarjetas/mastercard.svg" alt="Mastercard" style="height:28px;width:auto;">';
    if (b === 'amex' || b === 'american_express')
      return '<img src="/configurador/img/tarjetas/amex.svg" alt="American Express" style="height:28px;width:auto;">';
    return '<svg viewBox="0 0 64 24" width="56" height="22"><rect width="64" height="24" rx="3" fill="#666"/><text x="32" y="16" text-anchor="middle" fill="#fff" font-family="Arial,sans-serif" font-weight="700" font-size="9">'+(brand||'CARD').toUpperCase()+'</text></svg>';
  }

  // Customer brief 2026-05-07 (item 4): the backup card row must
  // ALWAYS be present and only allow replacement (Cambiar). The
  // previous "no card" state used a yellow Agregar-tarjeta CTA that
  // implied the user could leave the section blank; the customer
  // wants this method of payment to be mandatory once a credit
  // subscription exists. The two states still differ visually so the
  // operator can tell them apart at a glance, but BOTH end in the
  // same "Cambiar" action — never delete, never blank.
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
        // No card on file (edge case — usually populated by the
        // configurador SetupIntent at credit signup). Surface this as
        // an informational state so the customer knows a backup card
        // is required, but the action is still framed as "Cambiar"
        // (replace the implicit empty state) — never as "Agregar"
        // since elimination is not allowed by policy.
        $body.html(
          '<div style="padding:12px 14px;background:#fffbeb;border:1px solid #fde68a;border-left:4px solid #f59e0b;border-radius:8px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">'+
            '<div style="flex:1;min-width:160px;font-size:13px;color:#7a4f08;">Tu tarjeta de respaldo aún no está registrada. Es obligatoria para tu plan de crédito.</div>'+
            '<button class="vk-btn primary sm" id="vkCambiarTarjeta" style="padding:6px 14px;font-size:12px;">Cambiar</button>'+
          '</div>'
        );
      }
      $('#vkCambiarTarjeta').on('click', function(){
        var $btn = $(this);
        var originalLabel = $btn.text();   // remember "Cambiar"
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

  // ── Tarjeta flow (Stripe Checkout redirect) ─────────────────────────────
  // Creates a Checkout Session server-side and redirects the user to Stripe's
  // hosted page where they enter any card. On success Stripe redirects back
  // to /clientes/?pago=ok; the webhook marks ciclos paid via PI metadata.
  function payWithTarjeta(tipo, numSemanas){
    if (paying) return;
    paying = true;
    $('.vk-pay-action').css('opacity','0.5').css('pointer-events','none');
    VKApp.toast('Redirigiendo a Stripe...');
    var data = { tipo: tipo };
    if (numSemanas) data.num_semanas = numSemanas;
    VKApp.api('pagos/iniciar-tarjeta.php', data).done(function(r){
      if (r && r.url) { window.location.href = r.url; return; }
      VKApp.toast((r && r.error) || 'No se pudo iniciar el pago con tarjeta');
      paying = false;
      $('.vk-pay-action').css('opacity','').css('pointer-events','');
    }).fail(function(x){
      VKApp.toast((x.responseJSON && x.responseJSON.error) || 'Error de conexión');
      paying = false;
      $('.vk-pay-action').css('opacity','').css('pointer-events','');
    });
  }

  // ── SPEI flow ────────────────────────────────────────────────────────────
  // Creates a Stripe customer_balance PaymentIntent on the server, then shows
  // the CLABE + reference in a modal. Ciclos stay pending; the webhook marks
  // them paid when the bank transfer settles (up to 24h).
  function payWithSpei(tipo, numSemanas){
    if (paying) return;
    paying = true;
    $('.vk-pay-action').css('opacity','0.5').css('pointer-events','none');
    VKApp.toast('Generando instrucciones SPEI...');
    var data = { tipo: tipo };
    if (numSemanas) data.num_semanas = numSemanas;
    VKApp.api('pagos/iniciar-spei.php', data).done(function(r){
      if (r && r.clabe) showSpeiModal(r);
      else VKApp.toast((r && r.error) || 'No se pudo generar la referencia SPEI');
    }).fail(function(x){
      VKApp.toast((x.responseJSON && x.responseJSON.error) || 'Error de conexión');
    }).always(function(){
      paying = false;
      $('.vk-pay-action').css('opacity','').css('pointer-events','');
    });
  }

  function speiFormatMonto(n){
    return '$' + Number(n||0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' MXN';
  }

  function showSpeiModal(r){
    $('#vkSpeiModal').remove();
    var clabe  = String(r.clabe || '').replace(/\s+/g,'');
    var banco  = r.banco || 'STP';
    var benef  = r.beneficiario || 'MTECH GEARS S.A. DE C.V.';
    var ref    = r.referencia || '';
    var monto  = speiFormatMonto(r.monto);

    var html =
      '<div id="vkSpeiModal" style="position:fixed;inset:0;background:rgba(15,23,42,0.55);display:flex;align-items:flex-end;justify-content:center;z-index:9999;">'+
        '<div style="background:#fff;width:100%;max-width:480px;border-radius:16px 16px 0 0;padding:20px 18px 24px;max-height:92vh;overflow-y:auto;box-shadow:0 -6px 24px rgba(0,0,0,.2);">'+
          '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">'+
            '<div style="display:flex;align-items:center;gap:10px;">'+
              '<img src="/configurador/img/logo_spei.png" alt="SPEI" style="height:22px;width:auto;">'+
              '<div style="font-size:16px;font-weight:700;color:#1a3a5c;">Transferencia SPEI</div>'+
            '</div>'+
            '<button id="vkSpeiClose" type="button" aria-label="Cerrar" style="background:none;border:0;font-size:22px;line-height:1;color:#64748b;cursor:pointer;padding:4px 8px;">&times;</button>'+
          '</div>'+

          '<div style="background:#eef7ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 14px;margin-bottom:14px;">'+
            '<div style="font-size:12px;color:#1e40af;margin-bottom:4px;">Monto a transferir</div>'+
            '<div style="font-size:24px;font-weight:800;color:#0b3c7a;">'+ monto +'</div>'+
          '</div>'+

          '<div style="border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:14px;">'+
            '<div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">CLABE interbancaria</div>'+
            '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">'+
              '<div id="vkSpeiClabe" style="flex:1;min-width:0;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:15px;font-weight:700;letter-spacing:.5px;color:#0f172a;word-break:break-all;line-height:1.4;">'+ (clabe||'—') +'</div>'+
              (clabe ? '<button id="vkSpeiCopy" type="button" style="width:auto;flex-shrink:0;padding:6px 14px;font-size:12px;font-weight:700;background:#039fe1;color:#fff;border:0;border-radius:6px;cursor:pointer;">Copiar</button>' : '')+
            '</div>'+
          '</div>'+

          '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">'+
            '<div style="border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;">'+
              '<div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Banco</div>'+
              '<div style="font-size:14px;font-weight:600;color:#0f172a;margin-top:2px;">'+ String(banco).replace(/[<>"&]/g,'') +'</div>'+
            '</div>'+
            '<div style="border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;">'+
              '<div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Referencia</div>'+
              '<div style="font-size:14px;font-weight:600;color:#0f172a;margin-top:2px;word-break:break-all;">'+ (ref ? String(ref).replace(/[<>"&]/g,'') : '—') +'</div>'+
            '</div>'+
          '</div>'+

          '<div style="border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;margin-bottom:16px;">'+
            '<div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Beneficiario</div>'+
            '<div style="font-size:14px;font-weight:600;color:#0f172a;margin-top:2px;">'+ String(benef).replace(/[<>"&]/g,'') +'</div>'+
          '</div>'+

          '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-left:4px solid #22c55e;border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px;color:#166534;line-height:1.55;">'+
            '<strong>Acreditación automática.</strong> Cuando recibamos tu transferencia, tu pago se marcará como realizado (hasta 24 horas). No envíes comprobantes.'+
          '</div>'+

          '<button id="vkSpeiDone" class="vk-btn primary" type="button" style="width:100%;padding:12px;font-size:14px;">Entendido</button>'+
        '</div>'+
      '</div>';

    $('body').append(html);

    $('#vkSpeiClose,#vkSpeiDone').on('click', function(){ $('#vkSpeiModal').remove(); });
    $('#vkSpeiCopy').on('click', function(){
      var $b = $(this);
      var orig = $b.text();
      var done = function(){ $b.text('Copiado ✓'); setTimeout(function(){ $b.text(orig); }, 1600); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(clabe).then(done, function(){
          window.prompt('Copia la CLABE:', clabe);
        });
      } else {
        window.prompt('Copia la CLABE:', clabe);
        done();
      }
    });
  }

  // ── OXXO flow ────────────────────────────────────────────────────────────
  // Creates a Stripe OXXO PaymentIntent on the server and shows the voucher
  // URL + reference in a modal. Ciclos stay pending; webhook marks paid when
  // the customer pays at the store (typically same-day to 24h).
  function payWithOxxo(tipo, numSemanas){
    if (paying) return;
    paying = true;
    $('.vk-pay-action').css('opacity','0.5').css('pointer-events','none');
    VKApp.toast('Generando referencia OXXO...');
    var data = { tipo: tipo };
    if (numSemanas) data.num_semanas = numSemanas;
    VKApp.api('pagos/iniciar-oxxo.php', data).done(function(r){
      if (r && (r.voucher_url || r.referencia)) showOxxoModal(r);
      else VKApp.toast((r && r.error) || 'No se pudo generar la referencia OXXO');
    }).fail(function(x){
      VKApp.toast((x.responseJSON && x.responseJSON.error) || 'Error de conexión');
    }).always(function(){
      paying = false;
      $('.vk-pay-action').css('opacity','').css('pointer-events','');
    });
  }

  function oxxoFormatExpiry(ts){
    if (!ts) return '—';
    var d = new Date(ts * 1000);
    if (isNaN(d)) return '—';
    var meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    return d.getDate() + ' de ' + meses[d.getMonth()] + ' de ' + d.getFullYear();
  }

  function showOxxoModal(r){
    $('#vkOxxoModal').remove();
    var ref    = String(r.referencia || '').replace(/\s+/g,'');
    var voucher= r.voucher_url || '';
    var monto  = speiFormatMonto(r.monto);
    var exp    = oxxoFormatExpiry(r.expires_at);

    var html =
      '<div id="vkOxxoModal" style="position:fixed;inset:0;background:rgba(15,23,42,0.55);display:flex;align-items:flex-end;justify-content:center;z-index:9999;">'+
        '<div style="background:#fff;width:100%;max-width:480px;border-radius:16px 16px 0 0;padding:20px 18px 24px;max-height:92vh;overflow-y:auto;box-shadow:0 -6px 24px rgba(0,0,0,.2);">'+
          '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">'+
            '<div style="display:flex;align-items:center;gap:10px;">'+
              '<img src="/configurador/img/oxxo_logo.png" alt="OXXO" style="height:22px;width:auto;">'+
              '<div style="font-size:16px;font-weight:700;color:#1a3a5c;">Pago en OXXO</div>'+
            '</div>'+
            '<button id="vkOxxoClose" type="button" aria-label="Cerrar" style="background:none;border:0;font-size:22px;line-height:1;color:#64748b;cursor:pointer;padding:4px 8px;">&times;</button>'+
          '</div>'+

          '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 14px;margin-bottom:14px;">'+
            '<div style="font-size:12px;color:#991b1b;margin-bottom:4px;">Monto a pagar</div>'+
            '<div style="font-size:24px;font-weight:800;color:#7f1d1d;">'+ monto +'</div>'+
          '</div>'+

          '<div style="border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:14px;">'+
            '<div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Número de referencia</div>'+
            '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">'+
              '<div id="vkOxxoRef" style="flex:1;min-width:0;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:15px;font-weight:700;letter-spacing:.5px;color:#0f172a;word-break:break-all;line-height:1.4;">'+ (ref||'—') +'</div>'+
              (ref ? '<button id="vkOxxoCopy" type="button" style="width:auto;flex-shrink:0;padding:6px 14px;font-size:12px;font-weight:700;background:#039fe1;color:#fff;border:0;border-radius:6px;cursor:pointer;">Copiar</button>' : '')+
            '</div>'+
          '</div>'+

          '<div style="border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;margin-bottom:14px;">'+
            '<div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Paga antes del</div>'+
            '<div style="font-size:14px;font-weight:600;color:#0f172a;margin-top:2px;">'+ exp +'</div>'+
          '</div>'+

          (voucher
            ? '<a href="'+ voucher +'" target="_blank" rel="noopener noreferrer" class="vk-btn primary" style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:12px;font-size:14px;background:#e30613;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;margin-bottom:12px;">'+
                '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h.01M11 8h.01M15 8h.01M7 12h10M7 16h6"/></svg>'+
                'Ver comprobante con código de barras'+
              '</a>'
            : '')+

          '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-left:4px solid #22c55e;border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px;color:#166534;line-height:1.55;">'+
            '<strong>Presenta la referencia o el código de barras</strong> en cualquier tienda OXXO del país. Tu pago se acreditará automáticamente (hasta 24 horas).'+
          '</div>'+

          '<button id="vkOxxoDone" class="vk-btn primary" type="button" style="width:100%;padding:12px;font-size:14px;">Entendido</button>'+
        '</div>'+
      '</div>';

    $('body').append(html);

    $('#vkOxxoClose,#vkOxxoDone').on('click', function(){ $('#vkOxxoModal').remove(); });
    $('#vkOxxoCopy').on('click', function(){
      var $b = $(this);
      var orig = $b.text();
      var done = function(){ $b.text('Copiado ✓'); setTimeout(function(){ $b.text(orig); }, 1600); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(ref).then(done, function(){
          window.prompt('Copia la referencia:', ref);
        });
      } else {
        window.prompt('Copia la referencia:', ref);
        done();
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
