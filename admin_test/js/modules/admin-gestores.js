window.AD_gestores = (function(){
  var _data = [];
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  function render(){
    ADApp.render(_backBtn + '<div class="ad-h1">Gestores de placas</div><div><span class="ad-spin"></span> Cargando...</div>');
    load();
  }

  function load(){
    ADApp.api('gestores/listar.php').done(paint).fail(function(){
      ADApp.render(_backBtn + '<div class="ad-h1">Gestores de placas</div><div class="ad-banner err">Error al cargar gestores</div>');
    });
  }

  function paint(r){
    _data = (r && r.estados) || [];

    var cobertura = _data.filter(function(e){ return e.gestores.some(function(g){ return parseInt(g.activo); }); }).length;
    var total = _data.length;

    var html = _backBtn;
    html += '<div class="ad-toolbar"><div class="ad-h1">Gestores de placas</div></div>';
    html += '<div class="ad-dim" style="font-size:13px;margin-bottom:14px;">'+
      'Cada estado puede tener uno o varios gestores de placas. Cuando un cliente agrega "Asesoría de placas" durante el checkout, el sistema notifica automáticamente al gestor del estado correspondiente (si está registrado y activo); si no hay gestor asignado, el mensaje se envía a admin como respaldo.</div>';

    // KPI strip
    html += '<div class="ad-kpis" style="margin-bottom:18px;">';
    html += '<div class="ad-kpi"><div class="label">Estados con gestor</div><div class="value green">'+cobertura+' / '+total+'</div></div>';
    html += '<div class="ad-kpi"><div class="label">Estados sin gestor</div><div class="value '+((total-cobertura)>0?'yellow':'green')+'">'+(total-cobertura)+'</div></div>';
    html += '</div>';

    // Grid of estados
    html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px;">';
    _data.forEach(function(e){
      var active = e.gestores.filter(function(g){ return parseInt(g.activo); });
      var hasActive = active.length > 0;
      html += '<div class="ad-card" style="border-left:4px solid '+(hasActive?'#059669':'#d97706')+';">';
      html += '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:6px;">';
      html += '<div style="font-weight:800;font-size:15px;color:var(--ad-navy);">'+esc(e.estado)+'</div>';
      if (hasActive) {
        html += '<span class="ad-badge green" style="font-size:10px;">'+active.length+' activo'+(active.length>1?'s':'')+'</span>';
      } else {
        html += '<span class="ad-badge yellow" style="font-size:10px;">Sin gestor</span>';
      }
      html += '</div>';

      if (e.gestores.length === 0) {
        html += '<div style="font-size:12px;color:var(--ad-dim);margin-bottom:10px;">Ningún gestor registrado para este estado.</div>';
      } else {
        e.gestores.forEach(function(g){
          var dim = parseInt(g.activo) ? '' : 'opacity:.5;';
          html += '<div style="padding:8px 10px;border:1px solid var(--ad-border);border-radius:6px;margin-bottom:6px;font-size:12px;'+dim+'">';
          html += '<div style="display:flex;justify-content:space-between;align-items:center;gap:6px;">';
          html += '<strong style="font-size:13px;color:var(--ad-navy);">'+esc(g.nombre)+'</strong>';
          html += '<div style="display:flex;gap:4px;">';
          html += '<button class="ad-btn sm ghost adGestEdit" data-id="'+g.id+'" style="padding:3px 8px;font-size:11px;">Editar</button>';
          html += '<button class="ad-btn sm ghost adGestDelete" data-id="'+g.id+'" style="padding:3px 8px;font-size:11px;color:#b91c1c;">Eliminar</button>';
          html += '</div></div>';
          if (g.telefono) html += '<div style="color:#555;">📱 '+esc(g.telefono)+'</div>';
          if (g.whatsapp && g.whatsapp !== g.telefono) html += '<div style="color:#555;">💬 WhatsApp: '+esc(g.whatsapp)+'</div>';
          if (g.email)    html += '<div style="color:#555;">📧 '+esc(g.email)+'</div>';
          if (g.notas)    html += '<div style="color:#888;margin-top:4px;font-size:11px;">'+esc(g.notas)+'</div>';
          if (!parseInt(g.activo)) html += '<div style="color:#b91c1c;margin-top:4px;font-size:11px;font-weight:700;">Inactivo</div>';
          html += '</div>';
        });
      }

      html += '<button class="ad-btn sm primary adGestAdd" data-estado="'+esc(e.estado)+'" style="width:100%;margin-top:4px;padding:6px;font-size:12px;">+ Agregar gestor</button>';
      html += '</div>';
    });
    html += '</div>';

    ADApp.render(html);

    $('.adGestAdd').on('click', function(){
      showForm({ estado_mx: $(this).data('estado') });
    });
    $('.adGestEdit').on('click', function(){
      var id = parseInt($(this).data('id'));
      var g = findById(id);
      if (g) showForm(g);
    });
    $('.adGestDelete').on('click', function(){
      var id = parseInt($(this).data('id'));
      var g = findById(id);
      if (!g) return;
      if (!confirm('¿Eliminar gestor "'+g.nombre+'" de '+g.estado_mx+'? Esta acción no se puede deshacer.')) return;
      ADApp.api('gestores/eliminar.php', { id: id }).done(function(r){
        if (r.ok) {
          if (ADApp.toast) ADApp.toast('Gestor eliminado');
          render();
        } else {
          alert(r.error || 'Error al eliminar');
        }
      });
    });
  }

  function findById(id){
    for (var i=0; i<_data.length; i++) {
      for (var j=0; j<_data[i].gestores.length; j++) {
        if (parseInt(_data[i].gestores[j].id) === id) return _data[i].gestores[j];
      }
    }
    return null;
  }

  function showForm(g){
    g = g || {};
    var isNew = !g.id;
    var html = '<div class="ad-h2">'+(isNew?'Nuevo gestor de placas':'Editar gestor')+'</div>';
    html += '<div style="font-size:12px;color:var(--ad-dim);margin-bottom:12px;">Estado: <strong>'+esc(g.estado_mx||'')+'</strong></div>';
    html += '<label class="ad-label">Nombre completo *</label>';
    html += '<input id="adGNombre" class="ad-input" value="'+esc(g.nombre||'')+'" placeholder="Ej: Juan Pérez">';
    html += '<label class="ad-label">Teléfono</label>';
    html += '<input id="adGTel" class="ad-input" inputmode="numeric" maxlength="10" value="'+esc(g.telefono||'')+'" placeholder="10 dígitos">';
    html += '<label class="ad-label">WhatsApp <span style="color:var(--ad-dim);font-weight:400;">(opcional — si es distinto al teléfono)</span></label>';
    html += '<input id="adGWa" class="ad-input" inputmode="numeric" maxlength="10" value="'+esc(g.whatsapp||'')+'" placeholder="Igual al teléfono si se deja vacío">';
    html += '<label class="ad-label">Email</label>';
    html += '<input id="adGEmail" class="ad-input" type="email" value="'+esc(g.email||'')+'" placeholder="gestor@ejemplo.com">';
    html += '<label class="ad-label">Notas</label>';
    html += '<textarea id="adGNotas" class="ad-input" rows="2" placeholder="Cédula, comisión, zona específica, etc.">'+esc(g.notas||'')+'</textarea>';
    html += '<label style="display:flex;align-items:center;gap:6px;font-size:13px;margin-top:10px;">'+
      '<input type="checkbox" id="adGActivo" '+((isNew||parseInt(g.activo))?'checked':'')+'> Activo (recibe notificaciones)</label>';
    html += '<div style="display:flex;gap:8px;margin-top:16px;">';
    html += '<button class="ad-btn ghost" onclick="ADApp.closeModal()" style="flex:1;">Cancelar</button>';
    html += '<button class="ad-btn primary" id="adGSave" style="flex:2;">'+(isNew?'Crear gestor':'Guardar cambios')+'</button>';
    html += '</div>';

    ADApp.modal(html);

    $('#adGSave').on('click', function(){
      var payload = {
        id:        g.id || 0,
        estado_mx: g.estado_mx,
        nombre:    $('#adGNombre').val().trim(),
        telefono:  $('#adGTel').val().replace(/\D/g,''),
        whatsapp:  $('#adGWa').val().replace(/\D/g,''),
        email:     $('#adGEmail').val().trim(),
        notas:     $('#adGNotas').val().trim(),
        activo:    $('#adGActivo').is(':checked') ? 1 : 0,
      };
      if (!payload.nombre) { alert('El nombre es requerido'); return; }
      var $b = $(this).prop('disabled', true).html('<span class="ad-spin"></span>');
      ADApp.api('gestores/guardar.php', payload).done(function(r){
        if (r.ok) {
          ADApp.closeModal();
          if (ADApp.toast) ADApp.toast(isNew ? 'Gestor creado' : 'Cambios guardados');
          render();
        } else {
          alert(r.error || 'Error');
          $b.prop('disabled', false).text(isNew ? 'Crear gestor' : 'Guardar cambios');
        }
      }).fail(function(x){
        alert((x.responseJSON && x.responseJSON.error) || 'Error de conexión');
        $b.prop('disabled', false).text(isNew ? 'Crear gestor' : 'Guardar cambios');
      });
    });
  }

  function esc(s){ return (s==null?'':String(s)).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

  return { render: render };
})();
