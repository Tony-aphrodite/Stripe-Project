window.AD_roles = (function(){
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';
  var _data = null;

  function render(){
    ADApp.render(_backBtn+'<div class="ad-h1">Roles y Permisos</div><div><span class="ad-spin"></span></div>');
    load();
  }

  function load(){
    ADApp.api('roles/listar.php').done(function(r){
      _data = r;
      paint(r);
    }).fail(function(){
      ADApp.render(_backBtn+'<div class="ad-h1">Roles</div><div class="ad-banner err">Error al cargar</div>');
    });
  }

  function paint(r){
    var html = _backBtn;
    html += '<div class="ad-h1">Roles y Permisos</div>';

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
    html += '<th>Nombre</th><th>Email</th><th>Rol</th><th>Punto</th><th>Estado</th><th></th>';
    html += '</tr></thead><tbody>';
    (r.usuarios||[]).forEach(function(u){
      var rolBadge = u.rol === 'admin' ? 'red' : (u.rol === 'cedis' ? 'blue' : 'gray');
      html += '<tr>';
      html += '<td><strong>'+esc(u.nombre)+'</strong></td>';
      html += '<td>'+esc(u.email)+'</td>';
      html += '<td><span class="ad-badge '+rolBadge+'">'+u.rol+'</span></td>';
      html += '<td>'+esc(u.punto_nombre||'—')+'</td>';
      html += '<td>'+(u.activo?'<span class="ad-badge green">Activo</span>':'<span class="ad-badge red">Inactivo</span>')+'</td>';
      html += '<td><button class="ad-btn sm ghost rlEditar" data-id="'+u.id+'">Editar rol</button></td>';
      html += '</tr>';
    });
    html += '</tbody></table></div>';

    ADApp.render(html);
    $('.rlEditar').on('click',function(){
      var uid = $(this).data('id');
      var u = (r.usuarios||[]).find(function(x){return x.id==uid;});
      if (u) showEditRole(u, r);
    });
  }

  function showEditRole(u, r){
    var currentPermisos = [];
    try { currentPermisos = JSON.parse(u.permisos||'[]'); } catch(e){}
    if (!currentPermisos.length) currentPermisos = r.matriz[u.rol] || [];

    var html = '<div class="ad-h2">Editar rol — '+esc(u.nombre)+'</div>';
    html += '<label style="font-size:13px;">Rol<select id="rlRol" class="ad-input" style="margin-bottom:12px;">';
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

    // Auto-fill permisos on role change
    $('#rlRol').on('change', function(){
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
        rol: $('#rlRol').val(),
        permisos: permisos
      }).done(function(r){ if(r.ok){ADApp.closeModal();load();} });
    });
  }

  function esc(s){return $('<span>').text(s||'').html();}
  return { render:render };
})();
