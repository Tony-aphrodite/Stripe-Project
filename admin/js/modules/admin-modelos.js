window.AD_modelos = (function(){
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  function render(){
    ADApp.render(_backBtn+'<div class="ad-h1">Modelos</div><div><span class="ad-spin"></span></div>');
    load();
  }

  function load(){
    ADApp.api('modelos/listar.php').done(paint).fail(function(){
      ADApp.render(_backBtn+'<div class="ad-h1">Modelos</div><div class="ad-banner err">Error al cargar modelos</div>');
    });
  }

  function paint(r){
    var html = _backBtn;
    html += '<div class="ad-toolbar"><div class="ad-h1">Modelos</div>';
    if (ADApp.isAdmin()) {
      html += '<button class="ad-btn primary" id="mdNuevo">+ Nuevo modelo</button>';
    }
    html += '</div>';

    // Cards grid
    html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;">';
    (r.modelos||[]).forEach(function(m){
      var statusBadge = m.activo ? '<span class="ad-badge green">Activo</span>' : '<span class="ad-badge red">Inactivo</span>';
      html += '<div class="ad-card" style="'+(m.activo?'':'opacity:.6;')+'">';
      if (m.imagen_url) {
        html += '<div style="text-align:center;margin-bottom:10px;"><img src="'+esc(m.imagen_url)+'" alt="'+esc(m.nombre)+'" style="max-height:120px;object-fit:contain;border-radius:8px;" onerror="this.style.display=\'none\'"></div>';
      }
      html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">';
      html += '<div style="font-size:18px;font-weight:800;color:var(--ad-navy);">'+esc(m.nombre)+'</div>';
      html += statusBadge;
      html += '</div>';
      html += '<div style="font-size:12px;color:var(--ad-dim);margin-bottom:8px;">'+esc(m.categoria||'Sin categoría')+'</div>';
      html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;font-size:12px;">';
      html += field('Contado', ADApp.money(m.precio_contado));
      html += field('Financiado', ADApp.money(m.precio_financiado));
      html += field('Costo', ADApp.money(m.costo));
      html += field('Batería', m.bateria||'—');
      html += field('Velocidad', m.velocidad||'—');
      html += field('Autonomía', m.autonomia||'—');
      html += field('Torque', m.torque||'—');
      html += field('Stock', m.stock_disponible + '/' + m.stock_total);
      html += '</div>';
      if (ADApp.isAdmin()) {
        html += '<div style="margin-top:12px;display:flex;gap:6px;">';
        html += '<button class="ad-btn sm ghost mdEditar" data-id="'+m.id+'">Editar</button>';
        html += '<button class="ad-btn sm '+(m.activo?'danger':'success')+' mdToggle" data-id="'+m.id+'">'+(m.activo?'Desactivar':'Activar')+'</button>';
        html += '<button class="ad-btn sm danger mdEliminar" data-id="'+m.id+'" data-nombre="'+esc(m.nombre)+'" data-stock="'+(m.stock_total||0)+'">Eliminar</button>';
        html += '</div>';
      }
      html += '</div>';
    });
    html += '</div>';

    if (!r.modelos || !r.modelos.length) {
      html += '<div class="ad-empty"><span class="ic">&#128736;</span>No hay modelos registrados. Crea el primero.</div>';
    }

    ADApp.render(html);
    $('#mdNuevo').on('click', function(){ showForm({}); });
    $('.mdEditar').on('click', function(){
      var id = $(this).data('id');
      var m = (r.modelos||[]).find(function(x){ return x.id == id; });
      if (m) showForm(m);
    });
    $('.mdToggle').on('click', function(){
      var id = $(this).data('id');
      ADApp.api('modelos/toggle.php', {id: id}).done(function(){ load(); });
    });
    $('.mdEliminar').on('click', function(){
      var id = $(this).data('id');
      var nombre = $(this).data('nombre');
      var stock = parseInt($(this).data('stock'))||0;
      function doDelete(force){
        ADApp.api('modelos/eliminar.php', {id: id, force: force}).done(function(res){
          if (res.warn && !force) {
            if (confirm(res.message)) doDelete(true);
          } else if (res.ok) {
            load();
          } else {
            alert(res.error || 'Error al eliminar');
          }
        });
      }
      var msg = 'Eliminar modelo "' + nombre + '"?';
      if (stock > 0) msg += '\n(Tiene ' + stock + ' unidades en inventario)';
      if (confirm(msg)) doDelete(false);
    });
  }

  function showForm(m){
    var isEdit = !!m.id;
    var html = '<div class="ad-h2">'+(isEdit?'Editar':'Nuevo')+' modelo</div>';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px;">';
    html += '<label>Nombre del modelo<input id="mdNombre" class="ad-input" value="'+esc(m.nombre||'')+'"></label>';
    html += '<label>Categoría<input id="mdCategoria" class="ad-input" value="'+esc(m.categoria||'')+'"></label>';
    html += '<label>Precio contado<input id="mdContado" class="ad-input" type="number" value="'+(m.precio_contado||0)+'"></label>';
    html += '<label>Precio financiado<input id="mdFinanciado" class="ad-input" type="number" value="'+(m.precio_financiado||0)+'"></label>';
    html += '<label>Costo<input id="mdCosto" class="ad-input" type="number" value="'+(m.costo||0)+'"></label>';
    html += '<label>Batería<input id="mdBateria" class="ad-input" value="'+esc(m.bateria||'')+'"></label>';
    html += '<label>Velocidad<input id="mdVelocidad" class="ad-input" value="'+esc(m.velocidad||'')+'"></label>';
    html += '<label>Autonomía<input id="mdAutonomia" class="ad-input" value="'+esc(m.autonomia||'')+'"></label>';
    html += '<label>Torque<input id="mdTorque" class="ad-input" value="'+esc(m.torque||'')+'"></label>';
    html += '<label>URL de imagen<input id="mdImagen" class="ad-input" value="'+esc(m.imagen_url||'')+'"></label>';
    html += '</div>';
    html += '<div style="margin-top:14px;display:flex;gap:8px;justify-content:flex-end;">';
    html += '<button class="ad-btn ghost" onclick="ADApp.closeModal()">Cancelar</button>';
    html += '<button class="ad-btn primary" id="mdGuardar">Guardar</button>';
    html += '</div>';
    html += '<div id="mdMsg" style="margin-top:8px;font-size:12px;"></div>';

    ADApp.modal(html);
    $('#mdGuardar').on('click', function(){
      var payload = {
        id: m.id || 0,
        nombre: $('#mdNombre').val().trim(),
        categoria: $('#mdCategoria').val().trim(),
        precio_contado: parseFloat($('#mdContado').val())||0,
        precio_financiado: parseFloat($('#mdFinanciado').val())||0,
        costo: parseFloat($('#mdCosto').val())||0,
        bateria: $('#mdBateria').val().trim(),
        velocidad: $('#mdVelocidad').val().trim(),
        autonomia: $('#mdAutonomia').val().trim(),
        torque: $('#mdTorque').val().trim(),
        imagen_url: $('#mdImagen').val().trim(),
        activo: m.activo !== undefined ? m.activo : 1,
      };
      if (!payload.nombre) { $('#mdMsg').html('<span style="color:#b91c1c;">Nombre requerido</span>'); return; }
      $('#mdGuardar').prop('disabled',true).text('Guardando...');
      ADApp.api('modelos/guardar.php', payload).done(function(r){
        if (r.ok) {
          ADApp.closeModal();
          load();
        } else {
          $('#mdMsg').html('<span style="color:#b91c1c;">'+esc(r.error)+'</span>');
          $('#mdGuardar').prop('disabled',false).text('Guardar');
        }
      });
    });
  }

  function field(label, value){
    return '<div><span style="color:var(--ad-dim)">'+label+':</span> <strong>'+(value||'—')+'</strong></div>';
  }
  function esc(s){ return $('<span>').text(s||'').html(); }

  return { render: render };
})();
