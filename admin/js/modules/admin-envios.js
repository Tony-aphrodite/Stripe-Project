window.AD_envios = (function(){
  function render(){
    ADApp.render('<div class="ad-h1">Envíos</div><div><span class="ad-spin"></span></div>');
    ADApp.api('envios/listar.php').done(paint);
  }
  function paint(r){
    var html = '<div class="ad-h1">Envíos</div>';
    html += '<table class="ad-table"><thead><tr><th>Moto</th><th>VIN</th><th>Destino</th><th>Estado</th><th>Fecha envío</th><th>ETA</th><th>Acción</th></tr></thead><tbody>';
    (r.envios||[]).forEach(function(e){
      html += '<tr>'+
        '<td>'+e.modelo+' '+e.color+'</td>'+
        '<td>'+(e.vin_display||e.vin)+'</td>'+
        '<td>'+(e.punto_nombre||'—')+'</td>'+
        '<td>'+ADApp.badgeEstado(e.estado)+'</td>'+
        '<td>'+(e.fecha_envio||'—')+'</td>'+
        '<td>'+(e.fecha_estimada_llegada||'—')+'</td>'+
        '<td>';
      if(e.estado==='lista_para_enviar')
        html += '<button class="ad-btn sm primary adChg" data-id="'+e.id+'" data-est="enviada">Marcar enviada</button>';
      html += '</td></tr>';
    });
    html += '</tbody></table>';
    ADApp.render(html);
    $('.adChg').on('click',function(){
      var id=$(this).data('id'), est=$(this).data('est');
      ADApp.api('envios/cambiar-estado.php',{envio_id:id,estado:est}).done(function(r2){
        if(r2.ok) render(); else alert(r2.error);
      });
    });
  }
  return { render:render };
})();
