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
    // Check session
    api('auth/me.php').done(function(r) {
      if (r.usuario) { state.user = r.usuario; showApp(); }
      else AD_login.render();
    }).fail(function() { AD_login.render(); });
  }
  function showApp() {
    $sidebar.show();
    $('#adUser').html('👤 ' + state.user.nombre + '<br><small>' + state.user.rol.toUpperCase() + '</small>');
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

  return { start:start, api:api, render:render, go:go, modal:modal, closeModal:closeModal,
           state:state, showApp:showApp, badgeEstado:badgeEstado, money:money };
})();
