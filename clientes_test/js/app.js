window.VKApp = (function(){
  var LS_KEY = 'vk_active_compra';
  var state = {
    cliente: null,
    estado: null,
    route: 'inicio',
    tipoPortal: 'credito',
    activeCompra: null,   // { tipo:'credito'|'contado'|'msi', id:Number }
    compras: []           // cache of all client purchases
  };
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

  // ── Active purchase (activeCompra) ────────────────────────────────────────
  function _persistActive(){
    try {
      if (state.activeCompra) localStorage.setItem(LS_KEY, JSON.stringify(state.activeCompra));
      else localStorage.removeItem(LS_KEY);
    } catch(e){}
  }
  function _loadActiveFromStorage(){
    try {
      var raw = localStorage.getItem(LS_KEY);
      if (!raw) return null;
      var obj = JSON.parse(raw);
      if (obj && obj.tipo && obj.id) return obj;
    } catch(e){}
    return null;
  }
  function setActiveCompra(compra, targetRoute){
    if (!compra || !compra.tipo || !compra.id){ clearActiveCompra(); return; }
    state.activeCompra = { tipo: compra.tipo, id: parseInt(compra.id,10) };
    // Portal type follows the active purchase so tabs switch accordingly
    state.tipoPortal = compra.tipo;
    _persistActive();
    setupTabs(state.tipoPortal);
    // Reload client state scoped to this purchase, then land on the requested route
    var target = targetRoute || 'inicio';
    loadEstado(function(){ go(target); });
  }
  function clearActiveCompra(){
    state.activeCompra = null;
    _persistActive();
    loadEstado(function(){
      setupTabs(state.tipoPortal);
      go('miscompras');
    });
  }
  function getActiveCompra(){ return state.activeCompra; }

  /** Configure visible tabs based on portal type. Always includes "Mis compras". */
  function setupTabs(tipo){
    state.tipoPortal = tipo;
    $tabbar.find('button').hide();
    if(tipo === 'contado' || tipo === 'msi'){
      $tabbar.find('[data-route="inicio"],[data-route="miscompras"],[data-route="documentos"],[data-route="mivoltika"],[data-route="cuenta"],[data-route="ayuda"]').show();
    } else {
      $tabbar.find('[data-route="inicio"],[data-route="miscompras"],[data-route="pagos"],[data-route="entrega"],[data-route="documentos"],[data-route="cuenta"],[data-route="ayuda"]').show();
    }
  }

  function go(route){
    state.route = route;
    $tabbar.find('button').removeClass('active').filter('[data-route="'+route+'"]').addClass('active');
    var mod = window['VK_'+route];
    if(mod && mod.render) mod.render();
  }

  function start(){
    $screen = $('#vkScreen'); $tabbar = $('#vkTabbar');
    $tabbar.on('click','button',function(){ go($(this).data('route')); });
    // Restore any previously selected purchase
    state.activeCompra = _loadActiveFromStorage();
    api('auth/me.php').done(function(r){
      if(r && r.cliente){
        state.cliente = r.cliente;
        loadEstado(function(){
          setupTabs(state.tipoPortal);
          showTabbar(true);
          // If there are multiple purchases and none is active, land on list
          if (!state.activeCompra && Array.isArray(state.compras) && state.compras.length > 1) {
            go('miscompras');
          } else {
            go('inicio');
          }
        });
      } else {
        VK_login.render();
      }
    }).fail(function(){ VK_login.render(); });
  }

  function loadEstado(cb){
    // Scope query to active purchase when set, so estado/pagos/entrega are per-compra
    var qs = '';
    if (state.activeCompra) {
      qs = '?compra_tipo=' + encodeURIComponent(state.activeCompra.tipo)
         + '&compra_id='   + encodeURIComponent(state.activeCompra.id);
    }
    api('cliente/estado.php' + qs).done(function(r){
      state.estado = r;
      if (r && r.tipo_portal) state.tipoPortal = r.tipo_portal;
      if (r && Array.isArray(r.compras)) state.compras = r.compras;
      // If the active purchase no longer exists in the list, clear it
      if (state.activeCompra && Array.isArray(state.compras) && state.compras.length){
        var found = state.compras.some(function(c){
          return String(c.tipo) === String(state.activeCompra.tipo)
              && parseInt(c.id,10) === parseInt(state.activeCompra.id,10);
        });
        if (!found){
          state.activeCompra = null;
          _persistActive();
        }
      }
      if(cb) cb();
    }).fail(function(){ if(cb) cb(); });
  }

  function logout(){
    api('auth/logout.php',{}).always(function(){
      state.cliente = null; state.estado = null; state.activeCompra = null; state.compras = [];
      _persistActive();
      showTabbar(false);
      VK_login.render();
    });
  }

  return {
    start: start,
    api: api,
    render: render,
    go: go,
    toast: toast,
    state: state,
    loadEstado: loadEstado,
    logout: logout,
    showTabbar: showTabbar,
    setActiveCompra: setActiveCompra,
    clearActiveCompra: clearActiveCompra,
    getActiveCompra: getActiveCompra
  };
})();
