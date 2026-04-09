window.AD_puntos = (function(){
  function render(){
    ADApp.render('<div class="ad-h1">Puntos Voltika</div><div><span class="ad-spin"></span></div>');
    ADApp.api('puntos/listar.php').done(paint);
  }
  function paint(r){
    var html = '<div class="ad-toolbar"><div class="ad-h1">Puntos Voltika</div>'+
      '<button class="ad-btn primary" id="adNewPunto">+ Nuevo punto</button></div>';
    html += '<table class="ad-table"><thead><tr><th>Nombre</th><th>Ciudad</th><th>Inventario</th><th>Listas entrega</th><th>Envíos pend.</th><th>Cód. venta</th><th>Cód. electr.</th><th>Activo</th><th></th></tr></thead><tbody>';
    (r.puntos||[]).forEach(function(p){
      html += '<tr>'+
        '<td><strong>'+p.nombre+'</strong></td>'+
        '<td>'+(p.ciudad||'—')+', '+(p.estado||'')+'</td>'+
        '<td>'+p.inventario_actual+'</td>'+
        '<td>'+p.listas_entrega+'</td>'+
        '<td>'+p.envios_pendientes+'</td>'+
        '<td><code>'+(p.codigo_venta||'—')+'</code></td>'+
        '<td><code>'+(p.codigo_electronico||'—')+'</code></td>'+
        '<td>'+(p.activo?'<span class="ad-badge green">Sí</span>':'<span class="ad-badge red">No</span>')+'</td>'+
        '<td><button class="ad-btn sm ghost adEditP" data-id="'+p.id+'">Editar</button></td>'+
      '</tr>';
    });
    html += '</tbody></table>';
    ADApp.render(html);
    $('#adNewPunto').on('click',function(){ showForm({}); });
    $('.adEditP').on('click',function(){
      var id=$(this).data('id');
      var p = (r.puntos||[]).find(function(x){return x.id==id;});
      if(p) showForm(p);
    });
  }
  function showForm(p){
    var isNew = !p.id;
    ADApp.modal(
      '<div class="ad-h2">'+(isNew?'Nuevo':'Editar')+' Punto Voltika</div>'+
      '<input class="ad-input" id="pfNombre" placeholder="Nombre" value="'+(p.nombre||'')+'" style="margin-bottom:8px">'+
      '<input class="ad-input" id="pfDir" placeholder="Dirección" value="'+(p.direccion||'')+'" style="margin-bottom:8px">'+
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">'+
        '<input class="ad-input" id="pfCiudad" placeholder="Ciudad" value="'+(p.ciudad||'')+'">'+
        '<input class="ad-input" id="pfEstado" placeholder="Estado" value="'+(p.estado||'')+'">'+
      '</div>'+
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">'+
        '<input class="ad-input" id="pfCP" placeholder="CP" value="'+(p.cp||'')+'">'+
        '<input class="ad-input" id="pfTel" placeholder="Teléfono" value="'+(p.telefono||'')+'">'+
      '</div>'+
      '<input class="ad-input" id="pfEmail" placeholder="Email" value="'+(p.email||'')+'" style="margin-bottom:8px">'+
      '<input class="ad-input" id="pfHorarios" placeholder="Horarios" value="'+(p.horarios||'')+'" style="margin-bottom:8px">'+
      '<input class="ad-input" id="pfCap" placeholder="Capacidad" type="number" value="'+(p.capacidad||0)+'" style="margin-bottom:8px">'+
      '<label><input type="checkbox" id="pfActivo" '+(p.activo!==0?'checked':'')+'> Activo</label><br><br>'+
      '<button class="ad-btn primary" id="pfSave">Guardar</button>'
    );
    $('#pfSave').on('click',function(){
      ADApp.api('puntos/guardar.php',{
        id:p.id||undefined,
        nombre:$('#pfNombre').val(), direccion:$('#pfDir').val(), ciudad:$('#pfCiudad').val(),
        estado:$('#pfEstado').val(), cp:$('#pfCP').val(), telefono:$('#pfTel').val(),
        email:$('#pfEmail').val(), horarios:$('#pfHorarios').val(),
        capacidad:parseInt($('#pfCap').val())||0, activo:$('#pfActivo').is(':checked')?1:0
      }).done(function(r2){
        if(r2.ok){ ADApp.closeModal(); render(); } else alert(r2.error||'Error');
      });
    });
  }
  return { render:render };
})();
