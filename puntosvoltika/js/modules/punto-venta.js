window.PV_venta = (function(){
  function render(){
    PVApp.render('<div class="ad-h1">Venta por referido</div><div><span class="ad-spin"></span></div>');
    PVApp.api('inventario/listar.php').done(paint);
  }
  function paint(r){
    var html = '<div class="ad-h1">Venta por referido</div>';
    html += '<div style="color:var(--ad-dim);margin-bottom:14px">Asigna motos libres del punto a una venta directa o electrónica.</div>';
    var venta = r.inventario_venta||[];
    if (venta.length===0) html += '<div class="ad-card">Sin motos disponibles para venta</div>';
    venta.forEach(function(m){
      html += '<div class="ad-card">'+
        '<div style="font-weight:700">'+m.modelo+' · '+m.color+'</div>'+
        '<div style="font-size:12px;color:var(--ad-dim)">VIN: '+(m.vin_display||m.vin)+'</div>'+
        '<button class="ad-btn primary sm pvSell" data-id="'+m.id+'" data-modelo="'+m.modelo+'" style="margin-top:8px"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:3px;"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg> Asignar a venta</button>'+
      '</div>';
    });
    PVApp.render(html);
    $('.pvSell').on('click', function(){
      showForm($(this).data('id'), $(this).data('modelo'));
    });
  }
  function showForm(motoId, modelo){
    PVApp.modal(
      '<div class="ad-h2">Venta: '+modelo+'</div>'+
      '<label class="ad-label">Canal</label>'+
      '<select id="pvCanal" class="ad-input">'+
        '<option value="directa">Venta directa en tienda</option>'+
        '<option value="electronica">Venta electrónica</option>'+
      '</select>'+
      '<label class="ad-label">Nombre del cliente</label>'+
      '<input id="pvVN" class="ad-input">'+
      '<label class="ad-label">Email</label>'+
      '<input id="pvVE" class="ad-input" type="email">'+
      '<label class="ad-label">Teléfono</label>'+
      '<input id="pvVT" class="ad-input" inputmode="numeric" maxlength="10">'+
      '<label class="ad-label">Precio</label>'+
      '<input id="pvVP" class="ad-input" type="number">'+
      '<button id="pvVSave" class="ad-btn primary" style="width:100%;margin-top:14px">Asignar</button>'
    );
    $('#pvVSave').on('click', function(){
      PVApp.api('asignar/referido.php',{
        moto_id: motoId, canal: $('#pvCanal').val(),
        cliente_nombre: $('#pvVN').val(), cliente_email: $('#pvVE').val(),
        cliente_telefono: $('#pvVT').val(), precio: parseFloat($('#pvVP').val())||0
      }).done(function(r){
        if(r.ok){ PVApp.closeModal(); PVApp.toast('Venta registrada'); render(); }
      }).fail(function(x){ alert((x.responseJSON&&x.responseJSON.error)||'Error'); });
    });
  }
  return { render:render };
})();
