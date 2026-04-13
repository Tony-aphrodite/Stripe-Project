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
    ADApp.modal('<div class="ad-h2">Detalle de pago</div><div style="text-align:center;padding:30px;"><span class="ad-spin"></span> Consultando Stripe...</div>');

    ADApp.api('pagos/detalle.php', {
      pedido_id: pedidoId,
      stripe_pi: stripePi,
      fuente: fuente
    }).done(function(r){
      if(!r.ok){ ADApp.modal('<div class="ad-h2">Error</div><p>'+(r.error||'Error desconocido')+'</p>'); return; }

      var o = r.orden||{};
      var s = r.stripe||{};
      var html = '<div class="ad-h2">Detalle de pago</div>';

      // ── Order info ──
      html += '<div style="margin-bottom:16px;">';
      html += '<div style="font-weight:600;margin-bottom:8px;color:var(--ad-primary);">Información de la orden</div>';
      html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:13px;">';
      html += field('Pedido', o.pedido ? 'VK-'+o.pedido : o.id||'—');
      html += field('Cliente', o.nombre||'—');
      html += field('Email', o.email||'—');
      html += field('Teléfono', o.telefono||'—');
      html += field('Modelo', (o.modelo||'')+ ' '+(o.color||''));
      html += field('Tipo de pago', o.tipo_pago||'—');
      html += field('Monto', ADApp.money(o.monto));
      html += field('Fecha', (o.fecha||'').substring(0,10));
      if(o.punto_nombre) html += field('Punto', o.punto_nombre);
      html += '</div></div>';

      // ── Stripe info ──
      if(s && !s.error){
        html += '<div style="margin-bottom:16px;">';
        html += '<div style="font-weight:600;margin-bottom:8px;color:var(--ad-primary);">Información de Stripe</div>';
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:13px;">';
        html += field('Stripe ID', s.id||'—');
        html += field('Tipo', s.type==='setup_intent'?'Setup Intent':'Payment Intent');
        html += field('Estado', stripeStatusBadge(s.status));
        if(s.amount !== undefined) html += field('Monto Stripe', ADApp.money(s.amount)+' '+( s.currency||''));
        html += field('Creado', s.created||'—');

        if(s.payment_method) html += field('Método', s.payment_method);
        if(s.card){
          html += field('Tarjeta', capitalize(s.card.brand)+' ****'+s.card.last4);
          html += field('Vencimiento', s.card.exp);
          if(s.card.country) html += field('País tarjeta', s.card.country);
          if(s.card.funding) html += field('Tipo tarjeta', capitalize(s.card.funding));
        }
        if(s.oxxo && s.oxxo.number) html += field('OXXO ref', s.oxxo.number);
        if(s.paid !== undefined) html += field('Pagado', s.paid?'<span style="color:green;">Sí</span>':'<span style="color:red;">No</span>');
        if(s.refunded) html += field('Reembolsado', '<span style="color:orange;">Sí — '+ADApp.money(s.amount_refunded)+'</span>');
        if(s.failure_message) html += field('Error', '<span style="color:red;">'+s.failure_message+'</span>');
        html += '</div>';

        // Receipt link
        if(s.receipt_url){
          html += '<div style="margin-top:8px;"><a href="'+s.receipt_url+'" target="_blank" style="color:var(--ad-primary);font-size:13px;">Ver recibo de Stripe</a></div>';
        }

        // Refunds
        if(s.refunds && s.refunds.length){
          html += '<div style="margin-top:12px;font-weight:600;font-size:13px;">Reembolsos</div>';
          html += '<table class="ad-table" style="font-size:12px;margin-top:4px;"><thead><tr><th>ID</th><th>Monto</th><th>Estado</th><th>Fecha</th><th>Razón</th></tr></thead><tbody>';
          s.refunds.forEach(function(ref){
            html += '<tr><td>'+ref.id+'</td><td>'+ADApp.money(ref.amount)+'</td><td>'+ref.status+'</td><td>'+(ref.created||'')+'</td><td>'+(ref.reason||'—')+'</td></tr>';
          });
          html += '</tbody></table>';
        }
        html += '</div>';
      } else if(s && s.error){
        html += '<div style="padding:12px;background:#FFF3E0;border-radius:8px;color:#E65100;font-size:13px;">'+s.error+'</div>';
      } else if(!stripePi){
        html += '<div style="padding:12px;background:#F5F5F5;border-radius:8px;color:#666;font-size:13px;">Sin ID de Stripe asociado</div>';
      }

      ADApp.modal(html);
    }).fail(function(){
      ADApp.modal('<div class="ad-h2">Error</div><p>Error de conexión al obtener los datos</p>');
    });
  }

  function field(label, value){
    return '<div><span style="color:var(--ad-dim)">'+label+':</span> <strong>'+(value||'—')+'</strong></div>';
  }

  function stripeStatusBadge(status){
    var colors = {succeeded:'green', requires_payment_method:'red', requires_action:'yellow',
      processing:'blue', canceled:'red', requires_confirmation:'yellow'};
    var c = colors[status]||'gray';
    return '<span class="ad-badge '+c+'">'+status+'</span>';
  }

  function capitalize(s){ return s ? s.charAt(0).toUpperCase()+s.slice(1) : ''; }

  return { render:render };
})();
