window.VK_mivoltika = (function(){

  function render(){
    var e = VKApp.state.estado || {};
    var c = VKApp.state.cliente || {};
    var tipo = VKApp.state.tipoPortal;

    var html = '';
    html += '<div class="vk-h1">Mi Voltika</div>';

    if(tipo === 'contado' || tipo === 'msi'){
      renderContado(html, e, c);
    } else {
      renderCredito(html, e, c);
    }
  }

  function renderContado(html, e, c){
    var compra = e.compra || {};
    var entrega = e.entrega || {};
    var punto = entrega.punto || {};
    var modelo = compra.modelo || entrega.modelo || 'Voltika';
    var color = compra.color || entrega.color || '';
    var totalFmt = compra.total ? '$'+Number(compra.total).toLocaleString('es-MX')+' MXN' : '';
    var tpagoLabel = compra.tpago === 'msi' ? (compra.msi_meses||9)+' MSI sin intereses' : 'Contado';
    var pedido = compra.pedido || '';

    // Moto image
    var modelSlug = modelo.toLowerCase().replace(/\s+/g,'_');
    var colorSlug = color.toLowerCase().replace(/\s+/g,'_');

    html += '<div class="vk-card" style="text-align:center;padding:24px 16px">';
    html += '<img src="../configurador_prueba/img/'+modelSlug+'/model.png" alt="'+modelo+'" style="max-width:220px;height:auto;margin-bottom:12px" onerror="this.style.display=\'none\'">';
    html += '<div style="font-size:20px;font-weight:800;color:#333">'+modelo+'</div>';
    if(color) html += '<div style="font-size:14px;color:#555;margin-top:2px">Color: '+color+'</div>';
    html += '<span class="vk-pill ok" style="margin-top:8px;display:inline-block">COMPRA CONFIRMADA</span>';
    html += '</div>';

    // Details card
    html += '<div class="vk-card" style="padding:16px">';
    html += '<div class="vk-h2" style="margin-bottom:12px">Detalles de tu compra</div>';

    var rows = [
      ['Pedido', pedido || '---'],
      ['Modelo', modelo],
      ['Color', color || '---'],
      ['Metodo de pago', tpagoLabel],
      ['Total', totalFmt || '---'],
      ['VIN/Serie', entrega.vin || 'Pendiente de asignar'],
    ];
    for(var i=0;i<rows.length;i++){
      html += '<div class="vk-detail-row"><span class="k">'+rows[i][0]+'</span><span class="v">'+rows[i][1]+'</span></div>';
    }
    html += '</div>';

    // Delivery status mini
    var pasoLabel = {preparacion:'En preparacion',asignacion:'Punto asignado',en_transito:'En transito',listo:'Lista para recoger'};
    var etiqueta = entrega.etiqueta || 'preparacion';

    html += '<div class="vk-card" style="padding:16px">';
    html += '<div class="vk-h2" style="margin-bottom:8px">Estado de entrega</div>';
    html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">';
    if(etiqueta === 'listo'){
      html += '<span class="vk-pill ok">LISTA PARA RECOGER</span>';
    } else {
      html += '<span class="vk-pill warn">'+(pasoLabel[etiqueta]||'En proceso').toUpperCase()+'</span>';
    }
    html += '</div>';

    if(punto.nombre){
      html += '<div class="vk-detail-row"><span class="k">Punto de entrega</span><span class="v">'+punto.nombre+'</span></div>';
    }
    if(punto.direccion){
      html += '<div class="vk-detail-row"><span class="k">Direccion</span><span class="v">'+punto.direccion+'</span></div>';
    }
    if(punto.horario){
      html += '<div class="vk-detail-row"><span class="k">Horario</span><span class="v">'+punto.horario+'</span></div>';
    }
    html += '</div>';

    // Help
    html += '<div class="vk-card" style="padding:16px;text-align:center">';
    html += '<div style="font-size:13px;color:#555;margin-bottom:8px">¿Necesitas ayuda con tu Voltika?</div>';
    html += '<a class="vk-btn-sm-green" href="https://wa.me/525500000000" target="_blank" style="text-decoration:none;display:inline-block">WhatsApp Soporte</a>';
    html += '</div>';

    VKApp.render(html);
  }

  function renderCredito(html, e, c){
    var sub = (e.subscripcion) || {};
    var modelo = sub.modelo || 'Voltika';
    var color = sub.color || '';
    var serie = sub.serie || 'Pendiente';

    var modelSlug = modelo.toLowerCase().replace(/\s+/g,'_');

    html += '<div class="vk-card" style="text-align:center;padding:24px 16px">';
    html += '<img src="../configurador_prueba/img/'+modelSlug+'/model.png" alt="'+modelo+'" style="max-width:220px;height:auto;margin-bottom:12px" onerror="this.style.display=\'none\'">';
    html += '<div style="font-size:20px;font-weight:800;color:#333">'+modelo+'</div>';
    if(color) html += '<div style="font-size:14px;color:#555;margin-top:2px">Color: '+color+'</div>';
    html += '<span class="vk-pill ok" style="margin-top:8px;display:inline-block">ACTIVA</span>';
    html += '</div>';

    html += '<div class="vk-card" style="padding:16px">';
    html += '<div class="vk-h2" style="margin-bottom:12px">Detalles</div>';
    html += '<div class="vk-detail-row"><span class="k">Modelo</span><span class="v">'+modelo+'</span></div>';
    html += '<div class="vk-detail-row"><span class="k">Color</span><span class="v">'+(color||'---')+'</span></div>';
    html += '<div class="vk-detail-row"><span class="k">Serie/VIN</span><span class="v">'+serie+'</span></div>';
    if(sub.monto_semanal) html += '<div class="vk-detail-row"><span class="k">Pago semanal</span><span class="v">$'+Number(sub.monto_semanal).toLocaleString('es-MX')+' MXN</span></div>';
    html += '</div>';

    VKApp.render(html);
  }

  return { render:render };
})();
