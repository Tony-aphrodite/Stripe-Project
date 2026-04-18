window.VK_miscompras = (function(){
  var _data = null;

  function render(){
    VKApp.render('<div class="vk-h1">Mis compras</div><div class="vk-muted"><span class="vk-spin"></span> Cargando...</div>');
    VKApp.api('cliente/compras.php').done(function(r){
      _data = r;
      paint(r);
    }).fail(function(){
      VKApp.render('<div class="vk-h1">Mis compras</div><div class="vk-card">No se pudieron cargar tus compras.</div>');
    });
  }

  function paint(r){
    var compras = (r && r.compras) || [];
    var active = (VKApp.state.activeCompra || null);
    var html = '<div class="vk-h1">Mis compras</div>';
    html += '<div class="vk-muted" style="margin-bottom:14px">Aquí ves todas las compras asociadas a tu número de teléfono o correo ('+compras.length+').</div>';

    if (!compras.length) {
      html += '<div class="vk-card">Aún no tienes compras registradas con este teléfono.</div>';
      VKApp.render(html);
      return;
    }

    // Active-compra banner
    if (active) {
      var actLabel = active.tipo === 'credito' ? 'Crédito' : (active.tipo === 'msi' ? 'MSI' : 'Contado');
      html += '<div style="background:#E3F2FD;border:1px solid #90CAF9;color:#0D47A1;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;display:flex;align-items:center;justify-content:space-between;gap:10px;">'+
        '<span>Compra activa: <strong>'+actLabel+' · ID '+active.id+'</strong> — toda la información de Inicio / Pagos / Entrega se filtra por esta compra.</span>'+
        '<button class="vk-btn ghost" id="vkMCClear" style="font-size:12px;padding:6px 10px;">Ver la más reciente</button>'+
      '</div>';
    }

    html += '<div class="vk-compras-grid">';
    compras.forEach(function(c){
      html += renderCard(c, active);
    });
    html += '</div>';

    VKApp.render(html);

    $('.vk-compra-card').on('click', function(e){
      if ($(e.target).closest('.vk-compra-actions').length) return;
      var idx = $(this).data('idx');
      openDetail(compras[idx]);
    });
    $('.vk-compra-setactive').on('click', function(e){
      e.stopPropagation();
      var idx = $(this).data('idx');
      var c = compras[idx];
      VKApp.setActiveCompra({ tipo: c.tipo, id: c.id });
      VKApp.toast('Compra seleccionada como activa');
      paint(_data);
    });
    $('.vk-compra-detail').on('click', function(e){
      e.stopPropagation();
      var idx = $(this).data('idx');
      openDetail(compras[idx]);
    });
    $('#vkMCClear').on('click', function(){
      VKApp.clearActiveCompra();
      VKApp.toast('Mostrando la compra más reciente');
      paint(_data);
    });
  }

  function renderCard(c, active){
    var idx = _data.compras.indexOf(c);
    var isActive = active && active.tipo === c.tipo && active.id === c.id;
    var tipoLabel = c.tipo === 'credito' ? 'Crédito'
                  : c.tipo === 'msi'     ? 'MSI'
                  :                         'Contado';
    var tipoColor = c.tipo === 'credito' ? '#1976D2'
                  : c.tipo === 'msi'     ? '#6A1B9A'
                  :                         '#2E7D32';
    var fecha = (c.fecha_compra || '').substring(0,10);

    // Main amount / payment info
    var amountHtml = '';
    if (c.tipo === 'credito') {
      amountHtml = '<div class="vk-compra-amount">$'+Number(c.pago_semanal||0).toLocaleString('es-MX')+'<span style="font-size:12px;font-weight:500;color:#666;"> / semana</span></div>'+
        '<div style="font-size:12px;color:#666;">'+(c.plazo_meses||'—')+' meses · '+(c.pagos.pagados||0)+'/'+(c.pagos.total||0)+' pagos'+
        (c.pagos.atrasados>0?'<span style="color:#c41e3a;font-weight:700"> · '+c.pagos.atrasados+' atrasados</span>':'')+
        '</div>';
    } else {
      var tot = Number(c.total||0).toLocaleString('es-MX');
      amountHtml = '<div class="vk-compra-amount">$'+tot+'</div>'+
        '<div style="font-size:12px;color:#666;">'+(c.metodo||'contado').toUpperCase()+
        (c.msi_meses?' · '+c.msi_meses+' MSI':'')+
        ' · '+(c.pago_estado==='pagada'?'Pagado':c.pago_estado||'pendiente')+
        '</div>';
    }

    // Delivery/state badge
    var estadoBadge = buildStateBadge(c);

    var motoLine = c.moto
      ? '<div style="font-size:12px;color:#333;margin-top:4px;">VIN: <code style="background:#f5f5f5;padding:2px 6px;border-radius:3px;">'+esc(c.moto.vin||'—')+'</code></div>'
      : '<div style="font-size:12px;color:#999;margin-top:4px;">VIN aún no asignado</div>';

    var activeBadge = isActive
      ? '<span style="background:#1976D2;color:#fff;padding:2px 8px;border-radius:3px;font-size:10px;font-weight:700;margin-left:8px;">ACTIVA</span>'
      : '';

    var h = '<div class="vk-compra-card'+(isActive?' is-active':'')+'" data-idx="'+idx+'">';
    h += '<div class="vk-compra-head">';
    h += '<div><span style="background:'+tipoColor+';color:#fff;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;letter-spacing:.3px;">'+tipoLabel+'</span>'+activeBadge+'</div>';
    h += '<div style="font-size:11px;color:#999;">'+(fecha||'')+'</div>';
    h += '</div>';
    h += '<div class="vk-compra-model">'+esc(c.modelo||'Modelo')+' '+esc(c.color||'')+'</div>';
    h += amountHtml;
    h += motoLine;
    h += '<div style="margin-top:8px;">'+estadoBadge+'</div>';
    h += '<div class="vk-compra-actions" style="margin-top:12px;display:flex;gap:6px;">';
    if (!isActive) {
      h += '<button class="vk-btn primary vk-compra-setactive" data-idx="'+idx+'" style="flex:1;padding:8px;font-size:12px;">Hacer activa</button>';
    }
    h += '<button class="vk-btn ghost vk-compra-detail" data-idx="'+idx+'" style="flex:1;padding:8px;font-size:12px;">Ver detalles</button>';
    h += '</div>';
    h += '</div>';
    return h;
  }

  function buildStateBadge(c){
    if (c.tipo === 'credito') {
      if (c.estado === 'cancelada') return '<span class="vk-doc-badge red">Cancelada</span>';
      if (c.estado === 'liquidada') return '<span class="vk-doc-badge green">Liquidada</span>';
      if (!c.fecha_inicio)          return '<span class="vk-doc-badge yellow">Esperando entrega</span>';
      return '<span class="vk-doc-badge green">Activa</span>';
    }
    // Contado / MSI → Use delivery step
    var eti = (c.entrega && c.entrega.etiqueta) || 'preparacion';
    if (eti === 'listo')       return '<span class="vk-doc-badge green">Entregada</span>';
    if (eti === 'en_transito') return '<span class="vk-doc-badge yellow">Por validar entrega</span>';
    if (eti === 'asignacion')  return '<span class="vk-doc-badge blue">Lista en punto</span>';
    return '<span class="vk-doc-badge yellow">En preparación</span>';
  }

  function openDetail(c){
    var tipoLabel = c.tipo === 'credito' ? 'Crédito'
                  : c.tipo === 'msi'     ? 'MSI'
                  :                         'Contado';
    var html = '<div class="vk-modal-backdrop" id="vkMCBackdrop"></div>';
    html += '<div class="vk-modal" id="vkMCModal">';
    html += '<button class="vk-modal-close" id="vkMCClose" aria-label="Cerrar">×</button>';
    html += '<div class="vk-h2">'+tipoLabel+' — '+esc(c.modelo||'')+' '+esc(c.color||'')+'</div>';
    html += '<div class="vk-muted" style="font-size:12px;margin-bottom:14px;">Compra #'+c.id+(c.pedido?' · Pedido VK-'+esc(c.pedido):'')+' · '+(c.fecha_compra||'').substring(0,10)+'</div>';

    html += '<div class="vk-detail-row"><span class="k">Estado</span><span class="v">'+buildStateBadge(c)+'</span></div>';
    if (c.moto) {
      html += '<div class="vk-detail-row"><span class="k">VIN asignado</span><span class="v"><code>'+esc(c.moto.vin)+'</code></span></div>';
      html += '<div class="vk-detail-row"><span class="k">Estado moto</span><span class="v">'+esc(c.moto.estado||'—')+'</span></div>';
    } else {
      html += '<div class="vk-detail-row"><span class="k">VIN</span><span class="v" style="color:#999">Aún no asignado</span></div>';
    }

    if (c.tipo === 'credito') {
      html += '<div class="vk-detail-row"><span class="k">Pago semanal</span><span class="v">$'+Number(c.pago_semanal||0).toLocaleString('es-MX')+'</span></div>';
      html += '<div class="vk-detail-row"><span class="k">Plazo</span><span class="v">'+(c.plazo_meses||'—')+' meses ('+(c.plazo_semanas||'—')+' semanas)</span></div>';
      html += '<div class="vk-detail-row"><span class="k">Ciclos pagados</span><span class="v">'+(c.pagos.pagados||0)+' de '+(c.pagos.total||0)+'</span></div>';
      if (c.pagos.atrasados > 0) {
        html += '<div class="vk-detail-row"><span class="k">Atrasados</span><span class="v" style="color:#c41e3a;font-weight:700;">'+c.pagos.atrasados+'</span></div>';
      }
      html += '<div class="vk-detail-row"><span class="k">Fecha inicio</span><span class="v">'+(c.fecha_inicio||'Al entregar la moto')+'</span></div>';
      html += '<div class="vk-detail-row"><span class="k">Tarjeta guardada</span><span class="v">'+(c.tiene_tarjeta?'Sí':'No')+'</span></div>';
    } else {
      html += '<div class="vk-detail-row"><span class="k">Total</span><span class="v">$'+Number(c.total||0).toLocaleString('es-MX')+'</span></div>';
      html += '<div class="vk-detail-row"><span class="k">Método</span><span class="v">'+(c.metodo||'').toUpperCase()+(c.msi_meses?' · '+c.msi_meses+' MSI':'')+'</span></div>';
      html += '<div class="vk-detail-row"><span class="k">Estado pago</span><span class="v">'+(c.pago_estado==='pagada'?'Pagado':(c.pago_estado||'pendiente'))+'</span></div>';
      if (c.entrega && c.entrega.punto_nombre) {
        html += '<div class="vk-detail-row"><span class="k">Punto</span><span class="v">'+esc(c.entrega.punto_nombre)+'</span></div>';
      }
    }

    html += '<div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap;">';
    html += '<button class="vk-btn primary" id="vkMCMakeActive" style="flex:1;min-width:140px;">Hacer activa</button>';
    html += '<button class="vk-btn ghost" id="vkMCGoEntrega" style="flex:1;min-width:140px;">Ver entrega</button>';
    if (c.tipo === 'credito') {
      html += '<button class="vk-btn ghost" id="vkMCGoPagos" style="flex:1;min-width:140px;">Ver pagos</button>';
    }
    html += '<button class="vk-btn ghost" id="vkMCGoDocs" style="flex:1;min-width:140px;">Ver documentos</button>';
    html += '</div>';

    html += '</div>';

    $('body').append(html);

    function closeModal(){ $('#vkMCBackdrop,#vkMCModal').remove(); }
    $('#vkMCBackdrop, #vkMCClose').on('click', closeModal);

    $('#vkMCMakeActive').on('click', function(){
      VKApp.setActiveCompra({ tipo: c.tipo, id: c.id });
      VKApp.toast('Compra seleccionada como activa');
      closeModal();
      paint(_data);
    });
    $('#vkMCGoEntrega').on('click', function(){
      closeModal();
      VKApp.setActiveCompra({ tipo: c.tipo, id: c.id }, 'entrega');
    });
    $('#vkMCGoPagos').on('click', function(){
      closeModal();
      VKApp.setActiveCompra({ tipo: c.tipo, id: c.id }, 'pagos');
    });
    $('#vkMCGoDocs').on('click', function(){
      closeModal();
      VKApp.setActiveCompra({ tipo: c.tipo, id: c.id }, 'documentos');
    });
  }

  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

  return { render: render };
})();
