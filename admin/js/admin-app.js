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
    if (!route) return;
    state.route = route;
    // Clear all active states (top-level + sub-buttons)
    $('.ad-nav button').removeClass('active');
    var $btn = $('.ad-nav button[data-route="'+route+'"]').addClass('active');
    // Auto-expand the parent group if the target is inside a collapsible section
    var $group = $btn.closest('.ad-nav-group');
    if ($group.length) $group.addClass('open');
    var mod = window['AD_' + route];
    if (mod && mod.render) mod.render();
  }
  function start() {
    $screen = $('#adScreen');
    $sidebar = $('#adSidebar');
    $('#adModalClose').on('click', closeModal);
    $('#adModal').on('click', function(e) { if (e.target === this) closeModal(); });
    $('.ad-hamburger').on('click', function() { $('.ad-nav').toggleClass('open'); });
    $('.ad-logo').on('click', function() { go('dashboard'); }).css('cursor','pointer');
    // Collapsible nav group toggles
    $('.ad-nav').on('click', '.ad-nav-group-toggle', function(e) {
      e.stopPropagation();
      $(this).closest('.ad-nav-group').toggleClass('open');
    });
    // Route navigation (skip group toggle buttons which have no data-route)
    $('.ad-nav').on('click', 'button:not(.ad-nav-group-toggle)', function() {
      var route = $(this).data('route');
      if (!route) return;
      $('.ad-nav').removeClass('open');
      go(route);
    });
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
  function canWrite(module) {
    // Customer brief 2026-05-04 round 8: module-scoped write check.
    // Old behaviour (no arg) is kept identical — admin + cedis can
    // always write. With an arg ("inventario", "envios", etc.) the
    // function ALSO grants write when the current user's permisos
    // array includes that module — letting logistica/cobranza/etc.
    // perform the actions their checkboxes promised.
    var r = state.user ? state.user.rol : '';
    if (r === 'admin' || r === 'cedis') return true;
    if (module && state.user && Array.isArray(state.user.permisos)
        && state.user.permisos.indexOf(module) >= 0) {
      return true;
    }
    return false;
  }
  function isAdmin() {
    return state.user && state.user.rol === 'admin';
  }
  function showApp() {
    $sidebar.show();
    var rolLabel = {admin:'ADMIN',cedis:'CEDIS',operador:'OPERADOR',dealer:'DEALER',logistica:'LOGISTICA',cobranza:'COBRANZA',documentos:'DOCUMENTOS'}[state.user.rol] || state.user.rol;
    $('#adUser').html('<div style="display:flex;align-items:center;gap:10px;"><div style="width:32px;height:32px;border-radius:50%;background:rgba(3,159,225,.2);display:flex;align-items:center;justify-content:center;"><img src="../configurador/img/asesor_icon.jpg" style="width:22px;height:22px;border-radius:50%;object-fit:cover;"></div><div><div style="color:rgba(255,255,255,.85);font-weight:600;font-size:13px;">' + state.user.nombre + '</div><div style="font-size:10px;letter-spacing:.5px;color:rgba(255,255,255,.4);text-transform:uppercase;">' + rolLabel + '</div></div></div>');
    filterSidebarByPermisos();
    go(firstAllowedRoute());
  }

  // ── Per-user sidebar filtering (customer brief 2026-05-04 round 7) ──
  // Hide the menu buttons whose data-route is not in the user's
  // permisos array. Admin role bypasses this — sees everything.
  // Several data-route values don't have a 1:1 match in the permisos
  // checkbox list; the routeToPerm map below resolves those (e.g.
  // "preaprobaciones" → "ventas", "puntosperf" → "reportes").
  function filterSidebarByPermisos(){
    var perms = (state.user && Array.isArray(state.user.permisos)) ? state.user.permisos : [];
    var isA   = isAdmin();

    // Map of data-route → permission key. Routes pointing to a value
    // not in the checkbox list are mapped to the closest permission so
    // they share visibility with the parent feature.
    var routeToPerm = {
      dashboard:       'dashboard',
      ventas:          'ventas',
      preaprobaciones: 'ventas',          // credit applications live with ventas
      inventario:      'inventario',
      envios:          'envios',
      pagos:           'pagos',
      cobranza:        'cobranza',
      puntos:          'puntos',
      referidos:       'puntos',          // referidos is a sub-feature of puntos
      checklists:      'checklists',
      modelos:         'modelos',
      precios:         'precios',
      documentos:      'documentos',
      entregas:        'documentos',      // delivery times live with documentos
      roles:           'roles',
      notificaciones:  'reportes',        // notifications live with analytics
      gestores:        'documentos',      // license-plate managers
      analytics:       'analytics',
      alertas:         'alertas',
      reportes:        'reportes',
      puntosperf:      'reportes',        // point performance is reportes
      buro:            'buro',
      buscar:          'buscar'
    };

    // Hide / show each top-level button + sub-button by permission.
    // Tag with data-perm-allowed so the group-visibility step below can
    // count permitted children INDEPENDENTLY of jQuery's :visible check
    // (the .ad-nav-sub container is collapsed by CSS until the admin
    // clicks the toggle, so :visible would falsely report 0 children
    // for groups whose submenu hasn't been opened yet — which hid the
    // ADMINISTRACIÓN + ANÁLISIS groups for the admin user too).
    $('.ad-nav button[data-route]').each(function(){
      var $b    = $(this);
      var route = $b.attr('data-route');
      var perm  = routeToPerm[route] || route;
      var allowed = isA || perms.indexOf(perm) >= 0;
      $b.toggle(allowed);
      $b.attr('data-perm-allowed', allowed ? '1' : '0');
    });

    // Show a group if AT LEAST ONE of its sub-buttons is permitted —
    // regardless of whether the submenu is currently expanded or
    // collapsed. Counting `[data-perm-allowed="1"]` survives the CSS
    // collapse state.
    $('.ad-nav-group').each(function(){
      var $g = $(this);
      var allowedCount = $g.find('.ad-nav-sub button[data-perm-allowed="1"]').length;
      $g.toggle(allowedCount > 0);
    });
  }

  // Pick the first route the user is allowed to see — used as the
  // landing page after login. Admin always lands on dashboard;
  // restricted users land on whatever their first permitted module is.
  function firstAllowedRoute(){
    if (isAdmin()) return 'dashboard';
    var perms = (state.user && Array.isArray(state.user.permisos)) ? state.user.permisos : [];
    var preferred = ['dashboard','ventas','inventario','envios','pagos','cobranza','puntos','checklists','documentos','reportes','analytics','buro','buscar'];
    for (var i = 0; i < preferred.length; i++) {
      if (perms.indexOf(preferred[i]) >= 0) return preferred[i];
    }
    // No permisos at all — land on dashboard anyway (will hit 403, but
    // that surfaces the misconfiguration instead of hiding it).
    return 'dashboard';
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
  function money(n) { return '$' + Number(n||0).toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2}); }

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
