window.PV_recepcion = (function(){
  function render(){
    PVApp.render('<div class="ad-h1">Recepción de motos</div><div><span class="ad-spin"></span></div>');
    PVApp.api('recepcion/envios-pendientes.php').done(paint);
  }
  function paint(r){
    var html = '<div class="ad-h1">Recepción de motos</div>';
    html += '<div style="color:var(--ad-dim);margin-bottom:14px">Motos enviadas desde CEDIS esperando recepción</div>';
    if((r.envios||[]).length===0) html += '<div class="ad-card">No hay envíos pendientes</div>';
    (r.envios||[]).forEach(function(e){
      html += '<div class="ad-card">'+
        (e.pedido_num ? '<div style="font-size:12px;font-weight:700;color:var(--ad-primary,#039fe1);margin-bottom:4px;">📋 '+e.pedido_num+'</div>' : '')+
        '<div style="font-weight:700">'+e.modelo+' · '+e.color+'</div>'+
        '<div style="font-size:12px;color:var(--ad-dim)">VIN esperado: '+(e.vin_display||e.vin)+'</div>'+
        (e.cliente_nombre ? '<div style="font-size:12px;margin-top:4px;">👤 Cliente: <strong>'+e.cliente_nombre+'</strong></div>' : '')+
        '<div style="font-size:11px;margin-top:4px;"><span class="ad-badge '+(e.estado==='enviada'?'yellow':'blue')+'">'+e.estado+'</span>'+
        (e.fecha_estimada_llegada ? ' <span style="font-size:11px;color:var(--ad-dim)">· ETA: '+e.fecha_estimada_llegada+'</span>' : '')+'</div>'+
        '<button class="ad-btn primary sm pvReceive" data-env="'+e.id+'" data-moto="'+e.moto_id+'" data-vin="'+e.vin+'" style="margin-top:8px"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg> Recibir moto</button>'+
      '</div>';
    });
    PVApp.render(html);
    $('.pvReceive').on('click', function(){
      showReceiveForm($(this).data('env'), $(this).data('moto'), $(this).data('vin'));
    });
  }
  function showReceiveForm(envioId, motoId, vinEsperado){
    PVApp.modal(
      '<div class="ad-h2"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg> Recibir moto</div>'+
      '<div style="color:var(--ad-dim);font-size:12px;margin-bottom:10px">VIN esperado: <code>'+vinEsperado+'</code></div>'+
      '<label class="ad-label">Escanear o escribir VIN</label>'+
      '<input id="pvRVin" class="ad-input" placeholder="VIN escaneado" style="margin-bottom:14px">'+
      '<div class="ad-h2">Checklist</div>'+
      '<label class="pv-check"><input type="checkbox" id="pvC1"> Estado físico OK</label>'+
      '<label class="pv-check"><input type="checkbox" id="pvC2"> Sin daños visibles</label>'+
      '<label class="pv-check"><input type="checkbox" id="pvC3"> Componentes completos</label>'+
      '<label class="pv-check"><input type="checkbox" id="pvC4"> Batería OK</label>'+
      '<label class="ad-label">Notas</label>'+
      '<textarea id="pvRNotas" class="ad-input"></textarea>'+
      '<button id="pvRSave" class="ad-btn primary" style="width:100%;margin-top:14px">Confirmar recepción</button>'
    );
    $('#pvRSave').on('click', function(){
      var data = {
        envio_id: envioId, moto_id: motoId,
        vin_escaneado: $('#pvRVin').val().trim(),
        estado_fisico_ok: $('#pvC1').is(':checked')?1:0,
        sin_danos: $('#pvC2').is(':checked')?1:0,
        componentes_completos: $('#pvC3').is(':checked')?1:0,
        bateria_ok: $('#pvC4').is(':checked')?1:0,
        notas: $('#pvRNotas').val()
      };
      PVApp.api('recepcion/recibir.php', data).done(function(r){
        if(r.ok){ PVApp.closeModal(); PVApp.toast('Moto recibida'); render(); }
      }).fail(function(x){ alert((x.responseJSON&&x.responseJSON.error)||'Error'); });
    });
  }
  return { render:render };
})();
