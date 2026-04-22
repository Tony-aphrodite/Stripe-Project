window.PVApp = (function(){
  var state = { user: null, punto: null, route: 'inicio' };
  var $screen, $sidebar;

  function api(path, data, method) {
    return $.ajax({
      url: 'php/' + path,
      method: method || (data ? 'POST' : 'GET'),
      data: data ? JSON.stringify(data) : undefined,
      contentType: 'application/json',
      dataType: 'json',
      xhrFields: { withCredentials: true }
    });
  }
  function render(html) { $screen.html(html); }
  function modal(html) { $('#pvModalBody').html(html); $('#pvModal').show(); }
  function closeModal() { $('#pvModal').hide(); }
  function go(route) {
    state.route = route;
    $('.ad-nav button').removeClass('active').filter('[data-route="'+route+'"]').addClass('active');
    var mod = window['PV_' + route];
    if (mod && mod.render) mod.render();
  }
  function start() {
    $screen = $('#pvScreen'); $sidebar = $('#pvSidebar');
    $('#pvModalClose').on('click', closeModal);
    $('#pvModal').on('click', function(e){ if(e.target===this) closeModal(); });
    $('.ad-hamburger').on('click', function(){ $('.ad-nav').toggleClass('open'); });
    $('.ad-nav').on('click', 'button', function(){ $('.ad-nav').removeClass('open'); go($(this).data('route')); });
    $('#pvLogout').on('click', function(){
      api('auth/logout.php', {}).always(function(){ state.user=null; $sidebar.hide(); PV_login.render(); });
    });
    api('auth/me.php').done(function(r){
      if (r.usuario) { state.user=r.usuario; state.punto=r.punto; showApp(); }
      else PV_login.render();
    }).fail(function(){ PV_login.render(); });
  }
  function showApp() {
    $sidebar.show();
    $('#pvUser').html('<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> '+state.user.nombre+'<br><small>'+(state.punto?state.punto.nombre:'Sin punto')+'</small>'+
      '<br><button id="pvChangePassBtn" class="ad-btn sm ghost" style="margin-top:6px;font-size:11px;padding:4px 10px;color:#fff;border:1px solid rgba(255,255,255,0.4);background:transparent;">Cambiar contraseña</button>');
    $('#pvChangePassBtn').on('click', showChangePassword);
    go('inicio');
  }
  function showChangePassword(){
    modal(
      '<div class="ad-h2">Cambiar contraseña</div>'+
      '<div class="ad-dim" style="font-size:12px;margin-bottom:12px;">Ingresá tu contraseña actual y la nueva contraseña (mínimo 6 caracteres).</div>'+
      '<input id="pvCpCur" class="ad-input" type="password" placeholder="Contraseña actual" style="margin-bottom:8px;">'+
      '<input id="pvCpNew" class="ad-input" type="password" placeholder="Nueva contraseña" style="margin-bottom:8px;">'+
      '<input id="pvCpRep" class="ad-input" type="password" placeholder="Repetir nueva contraseña" style="margin-bottom:10px;">'+
      '<div id="pvCpMsg" style="font-size:12px;margin-bottom:8px;"></div>'+
      '<div style="display:flex;gap:8px;justify-content:flex-end;">'+
        '<button id="pvCpCancel" class="ad-btn ghost">Cancelar</button>'+
        '<button id="pvCpSave" class="ad-btn primary">Guardar</button>'+
      '</div>'
    );
    $('#pvCpCancel').on('click', closeModal);
    $('#pvCpSave').on('click', function(){
      var cur=$('#pvCpCur').val(), np=$('#pvCpNew').val(), rep=$('#pvCpRep').val();
      var $msg=$('#pvCpMsg');
      if(!cur||!np){ $msg.html('<span style="color:#c41e3a">Todos los campos son requeridos</span>'); return; }
      if(np.length<6){ $msg.html('<span style="color:#c41e3a">La nueva contraseña debe tener al menos 6 caracteres</span>'); return; }
      if(np!==rep){ $msg.html('<span style="color:#c41e3a">Las contraseñas nuevas no coinciden</span>'); return; }
      var $b=$('#pvCpSave').prop('disabled',true).html('<span class="ad-spin"></span>');
      api('auth/change-password.php', { currentPassword: cur, newPassword: np })
        .done(function(r){
          if(r.ok){ closeModal(); toast('Contraseña actualizada'); }
          else $msg.html('<span style="color:#c41e3a">'+(r.error||'Error')+'</span>');
        })
        .fail(function(x){
          var err=(x.responseJSON&&x.responseJSON.error)||'Error de conexión';
          $msg.html('<span style="color:#c41e3a">'+err+'</span>');
        })
        .always(function(){ $b.prop('disabled',false).text('Guardar'); });
    });
  }
  function money(n){ return '$'+Number(n||0).toLocaleString('es-MX'); }
  function toast(msg){
    var $t=$('<div style="position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#000;color:#fff;padding:10px 18px;border-radius:999px;font-size:13px;z-index:200">'+msg+'</div>').appendTo('body');
    setTimeout(function(){ $t.remove(); }, 2400);
  }
  return { start:start, api:api, render:render, go:go, modal:modal, closeModal:closeModal, state:state, showApp:showApp, money:money, toast:toast };
})();
