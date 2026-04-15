window.AD_entregas = (function(){
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  function render(){
    ADApp.render(_backBtn+'<div class="ad-h1">Tiempos de Entrega</div><div><span class="ad-spin"></span></div>');
    load();
  }

  function load(){
    ADApp.api('entregas/listar.php').done(paint).fail(function(){
      ADApp.render(_backBtn+'<div class="ad-h1">Tiempos de Entrega</div><div class="ad-banner err">Error</div>');
    });
  }

  function paint(r){
    var html = _backBtn;
    html += '<div class="ad-toolbar"><div class="ad-h1">Tiempos de Entrega</div>';
    if (ADApp.isAdmin()) html += '<button class="ad-btn primary" id="etNuevo">+ Nuevo</button>';
    html += '</div>';

    html += '<div class="ad-table-wrap"><table class="ad-table"><thead><tr>';
    html += '<th>Modelo</th><th>Ciudad</th><th>Días estimados</th><th>Disponible inmediato</th><th>Notas</th><th></th>';
    html += '</tr></thead><tbody>';
    (r.tiempos||[]).forEach(function(t){
      html += '<tr>';
      html += '<td><strong>'+esc(t.modelo||'Todos')+'</strong></td>';
      html += '<td>'+esc(t.ciudad||'Todas')+'</td>';
      html += '<td><span class="ad-badge blue">'+t.dias_estimados+' días</span></td>';
      html += '<td>'+(t.disponible_inmediato?'<span class="ad-badge green">Sí</span>':'<span class="ad-badge gray">No</span>')+'</td>';
      html += '<td class="ad-dim" style="font-size:12px;">'+esc(t.notas||'—')+'</td>';
      html += '<td>';
      if (ADApp.isAdmin()) html += '<button class="ad-btn sm ghost etEditar" data-id="'+t.id+'">Editar</button>';
      html += '</td></tr>';
    });
    if (!r.tiempos||!r.tiempos.length) html += '<tr><td colspan="6" class="ad-dim" style="text-align:center">Sin configuraciones</td></tr>';
    html += '</tbody></table></div>';

    ADApp.render(html);
    var tiempos = r.tiempos||[], modelos = r.modelos||[];
    $('#etNuevo').on('click',function(){ showForm({}, modelos); });
    $('.etEditar').on('click',function(){
      var id=$(this).data('id');
      var t = tiempos.find(function(x){return x.id==id;});
      if(t) showForm(t, modelos);
    });
  }

  function showForm(t, modelos){
    var html = '<div class="ad-h2">'+(t.id?'Editar':'Nuevo')+' tiempo de entrega</div>';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px;">';
    html += '<label>Modelo<select id="etModelo" class="ad-input"><option value="">Todos</option>';
    modelos.forEach(function(m){ html += '<option value="'+esc(m)+'"'+(m===t.modelo?' selected':'')+'>'+esc(m)+'</option>'; });
    html += '</select></label>';
    html += '<label>Ciudad<input id="etCiudad" class="ad-input" value="'+esc(t.ciudad||'')+'"></label>';
    html += '<label>Días estimados<input id="etDias" class="ad-input" type="number" value="'+(t.dias_estimados||7)+'"></label>';
    html += '<label><input type="checkbox" id="etInmediato" '+(t.disponible_inmediato?'checked':'')+' style="width:auto;margin-right:8px;">Disponible inmediato</label>';
    html += '<label style="grid-column:span 2;">Notas<input id="etNotas" class="ad-input" value="'+esc(t.notas||'')+'"></label>';
    html += '</div>';
    html += '<div style="margin-top:14px;text-align:right;"><button class="ad-btn ghost" onclick="ADApp.closeModal()">Cancelar</button> <button class="ad-btn primary" id="etGuardar">Guardar</button></div>';

    ADApp.modal(html);
    $('#etGuardar').on('click',function(){
      ADApp.api('entregas/guardar.php',{
        id: t.id||0, modelo:$('#etModelo').val(), ciudad:$('#etCiudad').val().trim(),
        dias_estimados:parseInt($('#etDias').val())||7,
        disponible_inmediato:$('#etInmediato').is(':checked')?1:0,
        notas:$('#etNotas').val().trim()
      }).done(function(r){ if(r.ok){ADApp.closeModal();load();} });
    });
  }

  function esc(s){return $('<span>').text(s||'').html();}
  return { render:render };
})();
