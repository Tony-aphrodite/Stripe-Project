window.VKApp = (function(){
  var state = { cliente:null, estado:null, route:'inicio' };
  var $screen = null, $tabbar = null;

  function api(path, data, method){
    return $.ajax({
      url: 'php/' + path,
      method: method || (data ? 'POST' : 'GET'),
      data: data ? JSON.stringify(data) : undefined,
      contentType: 'application/json',
      dataType: 'json',
      xhrFields:{ withCredentials:true }
    });
  }
  function toast(msg){
    var $t = $('<div class="vk-toast">'+msg+'</div>').appendTo('body');
    setTimeout(function(){ $t.addClass('show'); },10);
    setTimeout(function(){ $t.removeClass('show'); setTimeout(function(){$t.remove();},300); },2400);
  }
  function render(html){ $screen.html(html); window.scrollTo(0,0); }
  function showTabbar(show){ $tabbar.toggle(!!show); }

  function go(route){
    state.route = route;
    $tabbar.find('button').removeClass('active').filter('[data-route="'+route+'"]').addClass('active');
    var mod = window['VK_'+route];
    if(mod && mod.render) mod.render();
  }

  function start(){
    $screen = $('#vkScreen'); $tabbar = $('#vkTabbar');
    $tabbar.on('click','button',function(){ go($(this).data('route')); });
    api('auth/me.php').done(function(r){
      if(r && r.cliente){ state.cliente=r.cliente; loadEstado(function(){ showTabbar(true); go('inicio'); }); }
      else { VK_login.render(); }
    }).fail(function(){ VK_login.render(); });
  }

  function loadEstado(cb){
    api('cliente/estado.php').done(function(r){ state.estado=r; if(cb)cb(); })
      .fail(function(){ if(cb)cb(); });
  }

  function logout(){
    api('auth/logout.php',{}).always(function(){
      state.cliente=null; state.estado=null; showTabbar(false); VK_login.render();
    });
  }

  return { start:start, api:api, render:render, go:go, toast:toast, state:state, loadEstado:loadEstado, logout:logout, showTabbar:showTabbar };
})();
