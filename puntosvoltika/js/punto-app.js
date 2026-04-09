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
    $('#pvUser').html('👤 '+state.user.nombre+'<br><small>'+(state.punto?state.punto.nombre:'Sin punto')+'</small>');
    go('inicio');
  }
  function money(n){ return '$'+Number(n||0).toLocaleString('es-MX'); }
  function toast(msg){
    var $t=$('<div style="position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#000;color:#fff;padding:10px 18px;border-radius:999px;font-size:13px;z-index:200">'+msg+'</div>').appendTo('body');
    setTimeout(function(){ $t.remove(); }, 2400);
  }
  return { start:start, api:api, render:render, go:go, modal:modal, closeModal:closeModal, state:state, showApp:showApp, money:money, toast:toast };
})();
