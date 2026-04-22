window.AD_pagos = (function(){
  var filtro = {};
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';
  function render(){
    ADApp.render('<div class="ad-h1">Pagos y Órdenes</div><div><span class="ad-spin"></span></div>');
    load();
  }
  function load(){
    ADApp.api('pagos/listar.php?' + $.param(filtro)).done(paint);
  }
  function paint(r){
    var ro=r.resumen_ordenes||{}, rc=r.resumen_credito||{};
    var html = _backBtn+'<div class="ad-toolbar"><div class="ad-h1">Pagos y Órdenes</div></div>';
    html += '<div class="ad-kpis">';
    html += '<div class="ad-kpi"><div class="label">Total órdenes</div><div class="value blue">'+(ro.total_ordenes||0)+'</div></div>';
    html += '<div class="ad-kpi"><div class="label">Ingresos ordenes</div><div class="value green">'+ADApp.money(ro.total_ingresos)+'</div></div>';
    html += '<div class="ad-kpi"><div class="label">Créditos activos</div><div class="value blue">'+(rc.total_creditos||0)+'</div></div>';
    html += '<div class="ad-kpi"><div class="label">Monto financiado</div><div class="value blue">'+ADApp.money(rc.total_credito_monto)+'</div></div>';
    html += '</div>';
    // Filters
    html += '<div class="ad-filters">'+
      '<select class="ad-select" id="adPTipo"><option value="">Todos</option><option value="contado">Contado</option><option value="msi">MSI</option><option value="credito">Crédito</option></select>'+
      '<button class="ad-btn sm ghost" id="adPFilter">Filtrar</button></div>';
    // Table
    html += '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table"><thead><tr>'+
      '<th>Pedido</th><th>Cliente</th><th>Modelo</th><th>Tipo</th><th>Monto</th><th>Estado</th><th>Fecha</th><th></th>'+
      '</tr></thead><tbody>';
    (r.pagos||[]).forEach(function(p){
      html += '<tr>'+
        '<td>'+(p.pedido_num||p.id)+'</td>'+
        '<td>'+p.nombre+'</td>'+
        '<td>'+p.modelo+' '+p.color+'</td>'+
        '<td><span class="ad-badge blue">'+p.tipo_pago+'</span></td>'+
        '<td>'+ADApp.money(p.monto)+'</td>'+
        '<td>'+ADApp.badgeEstado(p.pago_estado||'—')+'</td>'+
        '<td>'+(p.freg||'').substring(0,10)+'</td>'+
        '<td><button class="ad-btn sm ghost adVerPago" '+
          'data-id="'+p.id+'" data-pi="'+(p.stripe_pi||'')+'" data-fuente="'+(p.fuente||'orden')+'">Ver</button></td>'+
      '</tr>';
    });
    html += '</tbody></table></div></div>';
    ADApp.render(html);
    $('#adPFilter').on('click',function(){ filtro.tipo=$('#adPTipo').val(); load(); });
    $('.adVerPago').on('click',function(){
      showDetalle($(this).data('id'), $(this).data('pi'), $(this).data('fuente'));
    });
  }

  function showDetalle(pedidoId, stripePi, fuente){
    ADApp.modal('<div style="text-align:center;padding:40px 20px;"><span class="ad-spin"></span><div style="margin-top:12px;font-size:13px;color:var(--ad-dim);">Consultando Stripe...</div></div>');

    ADApp.api('pagos/detalle.php', {
      pedido_id: pedidoId,
      stripe_pi: stripePi,
      fuente: fuente
    }).done(function(r){
      if(!r.ok){ ADApp.modal('<div class="adPD-error"><svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><div style="margin-top:8px;font-weight:700;font-size:16px;color:#dc2626;">Error</div><div style="margin-top:4px;font-size:13px;color:var(--ad-dim);">'+(r.error||'Error desconocido')+'</div></div>'); return; }

      var o = r.orden||{};
      // IMPORTANT: do NOT default stripe to `{}`. When the order has no
      // stripe_pi stored, detalle.php returns stripe=null. A `{}` fallback
      // would make `if(s && !s.error)` truthy, rendering the Stripe section
      // with every field empty ("Estado: undefined", "Creado: —"). Preserve
      // null so the "Sin ID de Stripe asociado" branch fires correctly.
      var s = r.stripe;

      // ── Modal header with icon ──
      var html = '<div style="display:flex;align-items:center;gap:14px;margin-bottom:24px;padding-bottom:20px;border-bottom:2px solid var(--ad-border);">';
      html += '<div style="width:48px;height:48px;border-radius:14px;background:linear-gradient(135deg,#039fe1,#0280b5);display:flex;align-items:center;justify-content:center;flex-shrink:0;">';
      html += '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></div>';
      html += '<div><div style="font-size:20px;font-weight:800;color:var(--ad-navy);line-height:1.2;">Detalle de pago</div>';
      html += '<div style="font-size:12px;color:var(--ad-dim);margin-top:2px;">Pedido '+(o.pedido?'VK-'+o.pedido:(o.id||''))+'</div></div></div>';

      // ── Order info section ──
      html += sectionHeader('Información de la orden', '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>');
      html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:24px;">';
      html += fieldRow('Pedido', o.pedido ? 'VK-'+o.pedido : o.id||'—', 0);
      html += fieldRow('Cliente', o.nombre||'—', 1);
      html += fieldRow('Email', o.email||'—', 2);
      html += fieldRow('Teléfono', o.telefono||'—', 3);
      // Separate Modelo / Color rows so legacy rows with multi-word modelo
      // ("Voltika Tromox Pesgo") do not end up concatenated with color into
      // one giant unreadable string ("Voltika Tromox Pesgo Gris moderno").
      html += fieldRow('Modelo', o.modelo||'—', 4);
      html += fieldRow('Color',  o.color||'—', 5);
      html += fieldRow('Tipo de pago', '<span style="display:inline-block;padding:2px 10px;border-radius:20px;background:rgba(3,159,225,.08);color:#039fe1;font-size:12px;font-weight:600;">'+(o.tipo_pago||'—')+'</span>', 6);
      html += fieldRow('Monto', '<span style="font-size:16px;font-weight:800;color:var(--ad-navy);">'+ADApp.money(o.monto)+'</span>', 7);
      html += fieldRow('Fecha', (o.fecha||'').substring(0,10), 8);
      if(o.punto_nombre) html += fieldRow('Punto', o.punto_nombre, 9);
      html += '</div>';

      // ── Credit details section ──
      if(fuente === 'credito' && (o.monto_semanal || o.plazo_meses || o.enganche)){
        html += sectionHeader('Detalle del crédito', '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>');
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:24px;">';
        var ci = 0;
        if(o.precio_contado) html += fieldRow('Precio contado', '<span style="font-weight:700;">'+ADApp.money(o.precio_contado)+'</span>', ci++);
        html += fieldRow('Enganche', '<span style="font-size:15px;font-weight:800;color:#059669;">'+ADApp.money(o.enganche||0)+'</span>', ci++);
        if(o.monto_financiado) html += fieldRow('Monto financiado', ADApp.money(o.monto_financiado), ci++);
        html += fieldRow('Pago semanal', '<span style="font-size:15px;font-weight:800;color:var(--ad-primary);">'+ADApp.money(o.monto_semanal||0)+'</span>', ci++);
        html += fieldRow('Plazo', '<span style="font-weight:700;">'+(o.plazo_meses||'—')+' meses</span>'+(o.plazo_semanas?' <span style="font-size:11px;color:var(--ad-dim);">('+o.plazo_semanas+' semanas)</span>':''), ci++);
        if(o.tasa_interna) html += fieldRow('Tasa interna', (o.tasa_interna*100).toFixed(2)+'%', ci++);
        if(o.estado_credito) html += fieldRow('Estado crédito', '<span class="ad-badge '+(o.estado_credito==='activa'?'green':o.estado_credito==='pausada'?'yellow':'red')+'">'+o.estado_credito+'</span>', ci++);
        html += '</div>';
      }

      // ── Stripe info section ──
      // Require s.id so a defensive `{}` (from a future caller) doesn't paint
      // the whole section with undefined values. Real Stripe responses always
      // include the id; its absence means there's nothing useful to show.
      if(s && !s.error && s.id){
        html += sectionHeader('Información de Stripe', '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 010-4h14v4"/><path d="M3 5v14a2 2 0 002 2h16v-5"/><path d="M18 12a2 2 0 100 4 2 2 0 000-4z"/></svg>');
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:16px;">';
        html += fieldRow('Stripe ID', '<code style="font-size:11px;background:var(--ad-surface-2);padding:3px 8px;border-radius:4px;font-family:monospace;letter-spacing:-.3px;">'+(s.id||'—')+'</code>', 0);
        html += fieldRow('Tipo', s.type==='setup_intent'?'Setup Intent':'Payment Intent', 1);
        html += fieldRow('Estado', stripeStatusBadge(s.status), 2);
        if(s.amount !== undefined) html += fieldRow('Monto Stripe', '<span style="font-weight:700;">'+ADApp.money(s.amount)+'</span> <span style="font-size:11px;color:var(--ad-dim);text-transform:uppercase;">'+(s.currency||'')+'</span>', 3);
        html += fieldRow('Creado', s.created||'—', 4);
        if(s.payment_method) html += fieldRow('Método', s.payment_method, 5);

        var idx = 6;
        if(s.card){
          html += fieldRow('Tarjeta', '<span style="display:inline-flex;align-items:center;gap:6px;"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--ad-navy)" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>'+capitalize(s.card.brand)+' ****'+s.card.last4+'</span>', idx++);
          html += fieldRow('Vencimiento', s.card.exp, idx++);
          if(s.card.country) html += fieldRow('País tarjeta', s.card.country, idx++);
          if(s.card.funding) html += fieldRow('Tipo tarjeta', capitalize(s.card.funding), idx++);
        }
        if(s.oxxo && s.oxxo.number) html += fieldRow('OXXO ref', '<code style="font-size:12px;background:#FFF8E1;padding:3px 8px;border-radius:4px;">'+s.oxxo.number+'</code>', idx++);
        if(s.paid !== undefined) html += fieldRow('Pagado', s.paid?'<span style="display:inline-flex;align-items:center;gap:5px;color:#059669;font-weight:600;"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#059669" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>Si</span>':'<span style="color:#dc2626;font-weight:600;">No</span>', idx++);
        if(s.refunded) html += fieldRow('Reembolsado', '<span style="color:#ea580c;font-weight:600;">Si — '+ADApp.money(s.amount_refunded)+'</span>', idx++);
        if(s.failure_message) html += fieldRow('Error', '<span style="color:#dc2626;font-size:12px;">'+s.failure_message+'</span>', idx++);
        html += '</div>';

        // Receipt link
        if(s.receipt_url){
          html += '<a href="'+s.receipt_url+'" target="_blank" style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:linear-gradient(135deg,#039fe1,#0280b5);color:#fff;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;transition:opacity .2s;" onmouseover="this.style.opacity=\'.85\'" onmouseout="this.style.opacity=\'1\'">';
          html += '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';
          html += 'Ver recibo de Stripe</a>';
        }

        // Refunds
        if(s.refunds && s.refunds.length){
          html += '<div style="margin-top:20px;">';
          html += sectionHeader('Reembolsos', '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>');
          html += '<table class="ad-table" style="font-size:12px;"><thead><tr><th>ID</th><th>Monto</th><th>Estado</th><th>Fecha</th><th>Razón</th></tr></thead><tbody>';
          s.refunds.forEach(function(ref){
            html += '<tr><td><code style="font-size:10px;">'+ref.id+'</code></td><td><strong>'+ADApp.money(ref.amount)+'</strong></td><td>'+stripeStatusBadge(ref.status)+'</td><td>'+(ref.created||'')+'</td><td>'+(ref.reason||'—')+'</td></tr>';
          });
          html += '</tbody></table></div>';
        }
      } else if(s && s.error){
        html += '<div style="display:flex;align-items:center;gap:10px;padding:14px 18px;background:#FFF3E0;border-radius:10px;border:1px solid #FFE0B2;margin-top:8px;">';
        html += '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#E65100" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
        html += '<span style="font-size:13px;color:#E65100;">'+s.error+'</span></div>';
      } else if(!stripePi){
        // No stripe_pi stored. This happens when the legacy configurador
        // created the transacciones row but the webhook never linked the
        // PaymentIntent (e.g. Eduardo Gonzalez Lopez VK-1776828725: user
        // reports "payment was successful but shows unpaid"). Offer a
        // direct search-in-Stripe action so the admin can recover it.
        html += sectionHeader('Información de Stripe', '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 010-4h14v4"/><path d="M3 5v14a2 2 0 002 2h16v-5"/></svg>');
        html += '<div style="padding:16px 18px;background:#FFF3E0;border-radius:10px;border:1px solid #FFE0B2;">';
        html += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">';
        html += '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#E65100" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
        html += '<span style="font-size:14px;color:#E65100;font-weight:700;">Sin PaymentIntent vinculado</span></div>';
        html += '<div style="font-size:12.5px;color:#333;line-height:1.55;margin-bottom:12px;">Este pedido no tiene <code>stripe_pi</code> registrado. Si el cliente ya pagó, la referencia de Stripe se perdió en el webhook. Puedes buscarla automáticamente por email y monto.</div>';
        html += '<button class="ad-btn primary" id="adPBuscarStripe" data-tid="'+(o.id||'')+'" style="background:#FB8C00;border-color:#FB8C00;padding:8px 16px;font-weight:700;">🔍 Buscar pago en Stripe</button>';
        html += '<div id="adPBuscarResult" style="margin-top:12px;"></div>';
        html += '</div>';
      }

      ADApp.modal(html);

      // Wire up the "Buscar pago en Stripe" backfill action (only present when
      // empty-state fired — empty-state branch above).
      $('#adPBuscarStripe').on('click', function(){
        var tid = $(this).data('tid');
        var $btn = $(this).prop('disabled', true).html('<span class="ad-spin"></span> Buscando en Stripe...');
        $.get('php/ventas/buscar-stripe-pi.php?transaccion_id=' + encodeURIComponent(tid))
          .done(function(rr){
            $btn.prop('disabled', false).html('🔍 Buscar pago en Stripe');
            if (!rr.ok) { $('#adPBuscarResult').html('<div style="color:#b91c1c;font-size:12.5px;margin-top:8px;">'+(rr.error||'Error')+'</div>'); return; }
            if (rr.already_linked) {
              $('#adPBuscarResult').html('<div style="color:#1e7e34;font-size:12.5px;margin-top:8px;">Ya estaba vinculado: <code>'+rr.stripe_pi+'</code>. Recarga el detalle.</div>');
              return;
            }
            renderBuscarResults(rr, tid);
          })
          .fail(function(x){
            $btn.prop('disabled', false).html('🔍 Buscar pago en Stripe');
            $('#adPBuscarResult').html('<div style="color:#b91c1c;font-size:12.5px;margin-top:8px;">'+((x.responseJSON && x.responseJSON.error) || 'Error de conexión')+'</div>');
          });
      });
    }).fail(function(){
      ADApp.modal('<div class="adPD-error"><svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><div style="margin-top:8px;font-weight:700;color:#dc2626;">Error de conexión</div></div>');
    });
  }

  // Render the candidate list from buscar-stripe-pi.php. Shows three tiers:
  // exact (email+amount), amount-only, email-only. Each row has a Vincular
  // button that POSTs back to the same endpoint.
  function renderBuscarResults(rr, tid){
    var sum = rr.summary || {exact:0,amount:0,email:0};
    var totalMatches = (sum.exact||0) + (sum.amount||0) + (sum.email||0);
    if (totalMatches === 0){
      $('#adPBuscarResult').html(
        '<div style="padding:12px;background:#fff;border-radius:8px;border:1px solid var(--ad-border);font-size:12.5px;color:var(--ad-dim);margin-top:10px;">'+
          'No se encontraron PaymentIntents en Stripe para <strong>'+(rr.order && rr.order.email || '—')+'</strong> por <strong>$'+(rr.order && rr.order.total || 0).toLocaleString()+'</strong> en ±'+rr.searched_window_days+' días.'+
          '<div style="margin-top:8px;">Puedes vincular manualmente con el ID de Stripe:</div>'+
          '<div style="display:flex;gap:6px;margin-top:6px;">'+
            '<input type="text" id="adPManualPi" placeholder="pi_xxxxxxxx" class="ad-input" style="flex:1;font-family:monospace;">'+
            '<button class="ad-btn sm primary" id="adPManualLink" data-tid="'+tid+'">Vincular</button>'+
          '</div>'+
        '</div>'
      );
      $('#adPManualLink').on('click', function(){
        var pi = ($('#adPManualPi').val()||'').trim();
        if (!pi) return;
        linkStripePi(tid, pi);
      });
      return;
    }

    var html = '<div style="margin-top:10px;background:#fff;border-radius:8px;border:1px solid var(--ad-border);padding:10px;">';
    html += '<div style="font-size:12.5px;font-weight:700;margin-bottom:8px;">Se encontraron '+totalMatches+' candidato(s):</div>';

    function tier(title, bgColor, items){
      if (!items || !items.length) return '';
      var h = '<div style="margin-bottom:8px;">';
      h += '<div style="font-size:11.5px;color:var(--ad-dim);font-weight:600;margin-bottom:4px;">'+title+'</div>';
      items.forEach(function(it){
        h += '<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 10px;background:'+bgColor+';border-radius:6px;margin-bottom:4px;font-size:12px;">';
        h += '<div style="flex:1;min-width:0;">'+
               '<div><code style="font-size:10.5px;">'+(it.id||'')+'</code> '+stripeStatusBadge(it.status)+'</div>'+
               '<div style="color:var(--ad-dim);margin-top:2px;">'+ADApp.money(it.amount||0)+' '+(it.currency||'')+' · '+(it.email||'—')+' · '+(it.created||'')+(it.card?' · '+it.card:'')+'</div>'+
             '</div>';
        h += '<button class="ad-btn sm primary adPLink" data-tid="'+tid+'" data-pi="'+it.id+'" style="margin-left:8px;">Vincular</button>';
        h += '</div>';
      });
      h += '</div>';
      return h;
    }

    html += tier('Coincidencia exacta (email + monto)', '#E8F5E9', rr.matches.exact);
    html += tier('Solo monto',  '#FFF8E1', rr.matches.amount);
    html += tier('Solo email',  '#FFF3E0', rr.matches.email);
    html += '</div>';
    $('#adPBuscarResult').html(html);

    $('.adPLink').on('click', function(){
      linkStripePi($(this).data('tid'), $(this).data('pi'));
    });
  }

  function linkStripePi(tid, pi){
    if (!confirm('¿Vincular PaymentIntent '+pi+' al pedido #'+tid+'? Se actualizará el estado de pago según Stripe.')) return;
    $.ajax({
      url: 'php/ventas/buscar-stripe-pi.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ transaccion_id: parseInt(tid,10), stripe_pi: pi })
    }).done(function(rr){
      if (rr.ok){
        alert('Vinculado. Estado de pago: '+rr.pago_estado);
        ADApp.closeModal();
        render();
      } else {
        alert(rr.error || 'Error al vincular');
      }
    }).fail(function(x){
      alert((x.responseJSON && x.responseJSON.error) || 'Error de conexión');
    });
  }

  function sectionHeader(title, icon){
    return '<div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--ad-border);">'+
      '<div style="color:var(--ad-primary);">'+icon+'</div>'+
      '<div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ad-primary);">'+title+'</div></div>';
  }

  function fieldRow(label, value, idx){
    var bg = idx % 2 === 0 ? 'background:var(--ad-surface-2);' : '';
    return '<div style="'+bg+'padding:9px 14px;font-size:13px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(0,0,0,.04);">' +
      '<span style="color:var(--ad-dim);font-weight:500;white-space:nowrap;margin-right:12px;">'+label+'</span>' +
      '<span style="font-weight:600;color:var(--ad-navy);text-align:right;">'+(value||'—')+'</span></div>';
  }

  function stripeStatusBadge(status){
    if (!status) return '<span class="ad-badge gray">desconocido</span>';
    var colors = {succeeded:'green', requires_payment_method:'red', requires_action:'yellow',
      processing:'blue', canceled:'red', requires_confirmation:'yellow'};
    var c = colors[status]||'gray';
    return '<span class="ad-badge '+c+'">'+status+'</span>';
  }

  function capitalize(s){ return s ? s.charAt(0).toUpperCase()+s.slice(1) : ''; }

  return { render:render };
})();
