window.ADApp = (function(){
  var state = { user: null, route: 'dashboard' };
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
  function modal(html) {
    $('#adModalBody').html(html);
    $('#adModal').show();
  }
  function closeModal() { $('#adModal').hide(); }
  function go(route) {
    state.route = route;
    $('.ad-nav button').removeClass('active').filter('[data-route="'+route+'"]').addClass('active');
    var mod = window['AD_' + route];
    if (mod && mod.render) mod.render();
  }
  function start() {
    $screen = $('#adScreen');
    $sidebar = $('#adSidebar');
    $('#adModalClose').on('click', closeModal);
    $('#adModal').on('click', function(e) { if (e.target === this) closeModal(); });
    $('.ad-hamburger').on('click', function() { $('.ad-nav').toggleClass('open'); });
    $('.ad-nav').on('click', 'button', function() { $('.ad-nav').removeClass('open'); go($(this).data('route')); });
    $('#adLogout').on('click', function() {
      api('auth/logout.php', {}).always(function() { state.user = null; $sidebar.hide(); AD_login.render(); });
    });
    $('#adChangePass').on('click', function() { showChangePasswordModal(); });
    // Check session
    api('auth/me.php').done(function(r) {
      if (r.usuario) { state.user = r.usuario; showApp(); }
      else AD_login.render();
    }).fail(function() { AD_login.render(); });
  }
  function canWrite() {
    var r = state.user ? state.user.rol : '';
    return r === 'admin' || r === 'cedis';
  }
  function isAdmin() {
    return state.user && state.user.rol === 'admin';
  }
  function showApp() {
    $sidebar.show();
    var rolLabel = {admin:'ADMIN',cedis:'CEDIS',operador:'OPERADOR',dealer:'DEALER'}[state.user.rol] || state.user.rol;
    $('#adUser').html('<div style="display:flex;align-items:center;gap:10px;"><div style="width:32px;height:32px;border-radius:50%;background:rgba(3,159,225,.2);display:flex;align-items:center;justify-content:center;"><img src="../configurador_prueba/img/asesor_icon.jpg" style="width:22px;height:22px;border-radius:50%;object-fit:cover;"></div><div><div style="color:rgba(255,255,255,.85);font-weight:600;font-size:13px;">' + state.user.nombre + '</div><div style="font-size:10px;letter-spacing:.5px;color:rgba(255,255,255,.4);text-transform:uppercase;">' + rolLabel + '</div></div></div>');
    go('dashboard');
  }
  function badgeEstado(est) {
    var map = {
      por_llegar:'blue', recibida:'green', por_ensamblar:'yellow', en_ensamble:'yellow',
      lista_para_entrega:'green', por_validar_entrega:'yellow', entregada:'green', retenida:'red',
      lista_para_enviar:'blue', enviada:'yellow',
      pagada:'green', pendiente:'yellow', parcial:'yellow'
    };
    return '<span class="ad-badge '+(map[est]||'gray')+'">'+est+'</span>';
  }
  function money(n) { return '$' + Number(n||0).toLocaleString('es-MX', {minimumFractionDigits:0}); }

  function showChangePasswordModal() {
    modal(
      '<div style="max-width:360px;margin:0 auto;">'+
        '<div style="font-size:20px;font-weight:800;color:var(--ad-navy);margin-bottom:6px;">Cambiar contraseña</div>'+
        '<div style="font-size:13px;color:var(--ad-dim);margin-bottom:20px;">Ingresa tu contraseña actual y la nueva contraseña</div>'+
        '<input id="adCpCurrent" class="ad-input" type="password" placeholder="Contraseña actual" style="margin-bottom:12px">'+
        '<input id="adCpNew" class="ad-input" type="password" placeholder="Nueva contraseña (mín. 6 caracteres)" style="margin-bottom:12px">'+
        '<input id="adCpConfirm" class="ad-input" type="password" placeholder="Confirmar nueva contraseña" style="margin-bottom:16px">'+
        '<button id="adCpBtn" class="ad-btn primary" style="width:100%;padding:14px;font-size:15px;">Cambiar contraseña</button>'+
        '<div id="adCpMsg" style="display:none;margin-top:12px;padding:12px;border-radius:var(--ad-radius-sm);font-size:13px;font-weight:600;text-align:center;"></div>'+
      '</div>'
    );
    $('#adCpBtn').on('click', doChangePassword);
    $('#adCpConfirm').on('keypress', function(e){ if(e.which===13) doChangePassword(); });
  }

  function doChangePassword() {
    var cur = $('#adCpCurrent').val(), nw = $('#adCpNew').val(), conf = $('#adCpConfirm').val();
    var $msg = $('#adCpMsg');
    if (!cur) { $msg.css({background:'rgba(239,68,68,.08)',color:'#b91c1c'}).text('Ingresa tu contraseña actual').show(); return; }
    if (!nw || nw.length < 6) { $msg.css({background:'rgba(239,68,68,.08)',color:'#b91c1c'}).text('La nueva contraseña debe tener al menos 6 caracteres').show(); return; }
    if (nw !== conf) { $msg.css({background:'rgba(239,68,68,.08)',color:'#b91c1c'}).text('Las contraseñas no coinciden').show(); return; }
    $msg.hide();
    var $b = $('#adCpBtn').prop('disabled', true).html('<span class="ad-spin"></span> Guardando...');
    api('auth/change-password.php', { currentPassword: cur, newPassword: nw }).done(function(r) {
      if (r.ok) {
        $msg.css({background:'rgba(5,150,105,.08)',color:'#059669'}).text('Contraseña actualizada correctamente').show();
        setTimeout(function() { closeModal(); }, 2000);
      }
    }).fail(function(x) {
      var msg = (x.responseJSON && x.responseJSON.error) || 'Error al cambiar contraseña';
      $msg.css({background:'rgba(239,68,68,.08)',color:'#b91c1c'}).text(msg).show();
    }).always(function() { $b.prop('disabled', false).text('Cambiar contraseña'); });
  }

  return { start:start, api:api, render:render, go:go, modal:modal, closeModal:closeModal,
           state:state, showApp:showApp, badgeEstado:badgeEstado, money:money,
           canWrite:canWrite, isAdmin:isAdmin };
})();
