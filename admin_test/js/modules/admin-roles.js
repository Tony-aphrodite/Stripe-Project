window.AD_roles = (function(){
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';
  var _data = null;

  function render(){
    ADApp.render(_backBtn+'<div class="ad-h1">Usuarios, Roles y Permisos</div><div><span class="ad-spin"></span></div>');
    load();
  }

  function load(){
    ADApp.api('roles/listar.php').done(function(r){
      _data = r;
      paint(r);
    }).fail(function(){
      ADApp.render(_backBtn+'<div class="ad-h1">Usuarios</div><div class="ad-banner err">Error al cargar</div>');
    });
  }

  function paint(r){
    var html = _backBtn;
    html += '<div class="ad-toolbar"><div class="ad-h1">Usuarios, Roles y Permisos</div>'+
      '<button class="ad-btn primary" id="rlNuevo">+ Nuevo usuario</button></div>';

    // Permission matrix overview
    html += '<div class="ad-h2">Matriz de permisos por rol</div>';
    html += '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table"><thead><tr><th>Módulo</th>';
    (r.roles||[]).forEach(function(rol){ html += '<th style="text-align:center;">'+rol+'</th>'; });
    html += '</tr></thead><tbody>';
    (r.modulos||[]).forEach(function(mod){
      html += '<tr><td><strong>'+mod+'</strong></td>';
      (r.roles||[]).forEach(function(rol){
        var hasAccess = (r.matriz[rol]||[]).indexOf(mod) >= 0;
        html += '<td style="text-align:center;">'+(hasAccess?'<span style="color:#22c55e;font-size:18px;">&#10003;</span>':'<span style="color:#e5e7eb;">—</span>')+'</td>';
      });
      html += '</tr>';
    });
    html += '</tbody></table></div></div>';

    // Users list
    html += '<div class="ad-h2">Usuarios del sistema</div>';
    html += '<div class="ad-table-wrap"><table class="ad-table"><thead><tr>';
    html += '<th>Nombre</th><th>Email</th><th>Rol</th><th>Punto</th><th>Estado</th><th>Acciones</th>';
    html += '</tr></thead><tbody>';
    (r.usuarios||[]).forEach(function(u){
      var rolBadge = u.rol === 'admin' ? 'red' : (u.rol === 'cedis' ? 'blue' : 'gray');
      html += '<tr>';
      html += '<td><strong>'+esc(u.nombre)+'</strong></td>';
      html += '<td>'+esc(u.email)+'</td>';
      html += '<td><span class="ad-badge '+rolBadge+'">'+u.rol+'</span></td>';
      html += '<td>'+esc(u.punto_nombre||'—')+'</td>';
      html += '<td>'+(Number(u.activo)?'<span class="ad-badge green">Activo</span>':'<span class="ad-badge red">Inactivo</span>')+'</td>';
      html += '<td style="white-space:nowrap;">'+
        '<button class="ad-btn sm ghost rlEditar" data-id="'+u.id+'" title="Editar rol y permisos">Rol</button> '+
        '<button class="ad-btn sm ghost rlReset" data-id="'+u.id+'" title="Restablecer contraseña" style="padding:4px 10px;">Reset</button> '+
        '<button class="ad-btn sm ghost rlToggle" data-id="'+u.id+'" data-activo="'+(Number(u.activo)?1:0)+'" title="'+(Number(u.activo)?'Desactivar':'Activar')+'" style="padding:4px 10px;">'+(Number(u.activo)?'Pausar':'Activar')+'</button>'+
      '</td>';
      html += '</tr>';
    });
    html += '</tbody></table></div>';

    ADApp.render(html);
    $('#rlNuevo').on('click', showCreateForm);
    $('.rlEditar').on('click',function(){
      var uid = $(this).data('id');
      var u = (r.usuarios||[]).find(function(x){return x.id==uid;});
      if (u) showEditRole(u, r);
    });
    $('.rlReset').on('click',function(){
      var uid = $(this).data('id');
      var u = (r.usuarios||[]).find(function(x){return x.id==uid;});
      if (u) showResetPassword(u);
    });
    $('.rlToggle').on('click',function(){
      var uid = $(this).data('id');
      var activo = $(this).data('activo') ? 0 : 1;
      if (!confirm(activo ? '¿Activar usuario?' : '¿Desactivar usuario?')) return;
      ADApp.api('roles/toggle-activo.php',{usuario_id:uid, activo:activo}).done(function(r){
        if(r.ok) load();
      });
    });
  }

  function showCreateForm(){
    var puntos = (_data && _data.puntos) || [];
    var roles = (_data && _data.roles) || ['dealer','admin'];

    var html = '<div class="ad-h2">Nuevo usuario</div>';
    html += '<label class="ad-label">Nombre</label>'+
      '<input class="ad-input" id="rlNomb" placeholder="Ej. Juan Pérez" style="margin-bottom:10px;">';
    html += '<label class="ad-label">Email</label>'+
      '<input class="ad-input" id="rlEmail" type="email" placeholder="correo@voltika.mx" style="margin-bottom:10px;">';
    html += '<label class="ad-label">Contraseña temporal</label>'+
      '<div style="display:flex;gap:6px;margin-bottom:10px;">'+
        '<input class="ad-input" id="rlPass" type="text" placeholder="Mínimo 6 caracteres" style="flex:1;">'+
        '<button class="ad-btn ghost sm" id="rlGen">Generar</button>'+
      '</div>';
    html += '<label class="ad-label">Rol</label>'+
      '<select class="ad-input" id="rlRol" style="margin-bottom:10px;">';
    roles.forEach(function(ro){
      html += '<option value="'+ro+'"'+(ro==='dealer'?' selected':'')+'>'+ro+'</option>';
    });
    html += '</select>';
    html += '<label class="ad-label">Punto Voltika (solo para rol "dealer")</label>'+
      '<select class="ad-input" id="rlPunto" style="margin-bottom:10px;">'+
      '<option value="">— Sin punto asignado —</option>';
    puntos.forEach(function(p){
      var label = esc(p.nombre) + (p.ciudad?' — '+esc(p.ciudad):'') +
        ' [#'+p.id+(p.codigo_venta?' · '+esc(p.codigo_venta):'')+']';
      html += '<option value="'+p.id+'">'+label+'</option>';
    });
    html += '</select>';
    html += '<label style="display:flex;align-items:center;gap:6px;font-size:13px;margin-bottom:12px;">'+
      '<input type="checkbox" id="rlNotif" checked style="width:auto;"> Enviar credenciales por SMS/Email al punto</label>';
    html += '<div style="text-align:right;"><button class="ad-btn ghost" onclick="ADApp.closeModal()">Cancelar</button> '+
      '<button class="ad-btn primary" id="rlCrear">Crear usuario</button></div>';

    ADApp.modal(html);

    // Generate random password
    $('#rlGen').on('click', function(e){
      e.preventDefault();
      var chars = 'abcdefghijkmnpqrstuvwxyz23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
      var p = '';
      for (var i = 0; i < 10; i++) p += chars.charAt(Math.floor(Math.random()*chars.length));
      $('#rlPass').val(p);
    });

    $('#rlCrear').on('click', function(){
      var payload = {
        nombre:    $('#rlNomb').val().trim(),
        email:     $('#rlEmail').val().trim(),
        password:  $('#rlPass').val(),
        rol:       $('#rlRol').val(),
        punto_id:  $('#rlPunto').val() || null,
        notificar: $('#rlNotif').is(':checked') ? 1 : 0
      };
      if (!payload.nombre || !payload.email || payload.password.length < 6) {
        alert('Completa todos los campos. Contraseña mínimo 6 caracteres.');
        return;
      }
      var $b = $(this).prop('disabled', true).html('<span class="ad-spin"></span> Creando...');
      ADApp.api('roles/crear.php', payload).done(function(r){
        if (r.ok) {
          ADApp.closeModal();
          alert('Usuario creado.\n\nEmail: '+payload.email+'\nContraseña: '+payload.password+
            (r.notify === 'enviado' ? '\n\n✓ Credenciales enviadas por SMS/Email.' : ''));
          load();
        } else {
          alert(r.error || 'Error al crear');
          $b.prop('disabled', false).text('Crear usuario');
        }
      }).fail(function(x){
        alert((x.responseJSON && x.responseJSON.error) || 'Error de conexión');
        $b.prop('disabled', false).text('Crear usuario');
      });
    });
  }

  function showResetPassword(u){
    var html = '<div class="ad-h2">Restablecer contraseña</div>';
    html += '<div class="ad-card" style="margin-bottom:10px;">'+esc(u.nombre)+' — '+esc(u.email)+'</div>';
    html += '<label class="ad-label">Nueva contraseña</label>'+
      '<div style="display:flex;gap:6px;margin-bottom:10px;">'+
        '<input class="ad-input" id="rlNewPass" type="text" placeholder="Mínimo 6 caracteres" style="flex:1;">'+
        '<button class="ad-btn ghost sm" id="rlGen2">Generar</button>'+
      '</div>';
    html += '<label style="display:flex;align-items:center;gap:6px;font-size:13px;margin-bottom:12px;">'+
      '<input type="checkbox" id="rlNotif2" checked style="width:auto;"> Enviar nuevas credenciales por SMS/Email</label>';
    html += '<div style="text-align:right;"><button class="ad-btn ghost" onclick="ADApp.closeModal()">Cancelar</button> '+
      '<button class="ad-btn primary" id="rlResetDo">Restablecer</button></div>';

    ADApp.modal(html);

    $('#rlGen2').on('click', function(e){
      e.preventDefault();
      var chars = 'abcdefghijkmnpqrstuvwxyz23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
      var p = '';
      for (var i = 0; i < 10; i++) p += chars.charAt(Math.floor(Math.random()*chars.length));
      $('#rlNewPass').val(p);
    });

    $('#rlResetDo').on('click', function(){
      var pass = $('#rlNewPass').val();
      if (pass.length < 6) { alert('Contraseña mínimo 6 caracteres.'); return; }
      var $b = $(this).prop('disabled', true).html('<span class="ad-spin"></span>');
      ADApp.api('roles/reset-password.php', {
        usuario_id: u.id,
        password:   pass,
        notificar:  $('#rlNotif2').is(':checked') ? 1 : 0
      }).done(function(r){
        if (r.ok) {
          ADApp.closeModal();
          alert('Contraseña actualizada.\n\nNueva contraseña: '+pass+
            (r.notify === 'enviado' ? '\n\n✓ Enviada por SMS/Email.' : ''));
          load();
        } else {
          alert(r.error || 'Error');
          $b.prop('disabled', false).text('Restablecer');
        }
      });
    });
  }

  function showEditRole(u, r){
    var currentPermisos = [];
    try { currentPermisos = JSON.parse(u.permisos||'[]'); } catch(e){}
    if (!currentPermisos.length) currentPermisos = r.matriz[u.rol] || [];

    var html = '<div class="ad-h2">Editar rol — '+esc(u.nombre)+'</div>';
    html += '<label style="font-size:13px;">Rol<select id="rlRolE" class="ad-input" style="margin-bottom:12px;">';
    (r.roles||[]).forEach(function(rol){
      html += '<option value="'+rol+'"'+(rol===u.rol?' selected':'')+'>'+rol+'</option>';
    });
    html += '</select></label>';
    html += '<div style="font-size:13px;font-weight:600;margin-bottom:8px;">Permisos de módulo:</div>';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;font-size:13px;">';
    (r.modulos||[]).forEach(function(mod){
      var checked = currentPermisos.indexOf(mod) >= 0;
      html += '<label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" class="rlPerm" value="'+mod+'"'+(checked?' checked':'')+' style="width:auto;"> '+mod+'</label>';
    });
    html += '</div>';
    html += '<div style="margin-top:14px;text-align:right;"><button class="ad-btn ghost" onclick="ADApp.closeModal()">Cancelar</button> <button class="ad-btn primary" id="rlGuardar">Guardar</button></div>';

    ADApp.modal(html);

    $('#rlRolE').on('change', function(){
      var selRol = $(this).val();
      var defPerms = r.matriz[selRol] || [];
      $('.rlPerm').each(function(){
        $(this).prop('checked', defPerms.indexOf($(this).val()) >= 0);
      });
    });

    $('#rlGuardar').on('click',function(){
      var permisos = [];
      $('.rlPerm:checked').each(function(){ permisos.push($(this).val()); });
      ADApp.api('roles/guardar.php',{
        usuario_id: u.id,
        rol: $('#rlRolE').val(),
        permisos: permisos
      }).done(function(r){ if(r.ok){ADApp.closeModal();load();} });
    });
  }

  function esc(s){return $('<span>').text(s||'').html();}
  return { render:render };
})();
