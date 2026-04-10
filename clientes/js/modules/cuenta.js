window.VK_cuenta = (function(){

  function initials(nombre){
    var parts = (nombre||'').trim().split(/\s+/);
    if(parts.length>=2) return (parts[0][0]+parts[1][0]).toUpperCase();
    if(parts[0]) return parts[0].substring(0,2).toUpperCase();
    return 'VK';
  }

  function maskEmail(e){
    if(!e) return '—';
    var at=e.indexOf('@'); if(at<2) return e;
    return e[0]+'•••'+e.substring(at);
  }

  function maskPhone(t){
    if(!t) return '—';
    if(t.length<=4) return t;
    return '•••• '+t.substring(t.length-4);
  }

  var profileData = null;

  function render(){
    VKApp.render(
      '<div class="vk-logo"><img src="../configurador_prueba/img/voltika_logo_h.svg" alt="Voltika" onerror="this.style.display=\'none\'"></div>'+
      '<div class="vk-h1">Mi cuenta</div>'+
      '<div class="vk-muted" style="margin-bottom:14px"><span class="vk-spin"></span> Cargando...</div>'
    );
    VKApp.api('cliente/perfil.php').done(function(p){ profileData=p; paint(p); });
  }

  function paint(p){
    var c = p.cliente||{};
    var m = p.moto||{};
    var img = p.moto_img||'';
    var fullName = ((c.nombre||'')+ ' '+(c.apellido_paterno||'')).trim() || 'Cliente';
    var ini = initials(fullName);
    var estadoMoto = (m.estado||'activa');

    VKApp.render(
      '<div class="vk-logo"><img src="../configurador_prueba/img/voltika_logo_h.svg" alt="Voltika" onerror="this.style.display=\'none\'"></div>'+
      '<div class="vk-h1">Mi cuenta</div>'+
      '<div class="vk-muted" style="margin-bottom:14px">Administra tu perfil, tu Voltika y tus preferencias.</div>'+

      // --- Profile card ---
      '<div class="vk-card vk-profile-card">'+
        '<div class="vk-profile-top">'+
          '<div class="vk-profile-avatar">'+ini+'</div>'+
          '<div class="vk-profile-info">'+
            '<div class="vk-profile-name">'+esc(fullName)+' <span class="vk-doc-badge green">Cuenta verificada</span></div>'+
            '<div class="vk-profile-row">'+
              '<span class="vk-profile-icon">✉</span>'+
              '<span class="vk-profile-val">'+esc(c.email||'No registrado')+'</span>'+
              '<button class="vk-profile-edit" data-campo="email">Editar ›</button>'+
            '</div>'+
            '<div class="vk-profile-row">'+
              '<span class="vk-profile-icon">📱</span>'+
              '<span class="vk-profile-val">'+(c.telefono||'No registrado')+'</span>'+
              '<span class="vk-doc-badge green" style="font-size:9px">Verificado</span>'+
            '</div>'+
          '</div>'+
        '</div>'+
        '<div class="vk-profile-footer">'+
          '<div class="vk-profile-foot-item"><div class="vk-muted">Dirección</div><div class="vk-profile-foot-val">Ver registrada</div></div>'+
          '<div class="vk-profile-foot-item"><div class="vk-muted">Correo</div><div class="vk-profile-foot-val">Verificado</div></div>'+
        '</div>'+
      '</div>'+

      // --- Edit panel (hidden by default) ---
      '<div id="vkEditPanel" class="vk-card" style="display:none">'+
        '<div class="vk-h2" id="vkEditTitle"></div>'+
        '<div class="vk-muted" id="vkEditDesc"></div>'+
        '<input class="vk-input" id="vkEditInput" placeholder="">'+
        '<button class="vk-btn primary" id="vkEditSend" style="margin-top:10px">Enviar código de verificación</button>'+
        '<div id="vkOtpSection" style="display:none;margin-top:14px">'+
          '<div class="vk-muted" id="vkOtpMsg"></div>'+
          '<input class="vk-input" id="vkOtpInput" placeholder="Código de 6 dígitos" maxlength="6" style="margin-top:8px;text-align:center;font-size:20px;letter-spacing:6px">'+
          '<button class="vk-btn primary" id="vkOtpVerify" style="margin-top:10px">Verificar</button>'+
        '</div>'+
        '<button class="vk-btn ghost" id="vkEditCancel" style="margin-top:8px">Cancelar</button>'+
      '</div>'+

      // --- Mi Voltika ---
      '<div class="vk-card vk-moto-card">'+
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">'+
          '<div class="vk-h2" style="margin:0">Mi Voltika</div>'+
          '<a class="vk-link" style="font-size:12px">Ver detalles ›</a>'+
        '</div>'+
        '<div class="vk-moto-body">'+
          (img ? '<div class="vk-moto-img"><img src="'+img+'" alt="'+esc(m.modelo||'Voltika')+'"></div>' : '')+
          '<div class="vk-moto-info">'+
            '<div class="vk-moto-model">'+esc(m.modelo||'Voltika')+' <span class="vk-doc-badge green">Activa</span></div>'+
            '<div class="vk-muted">Color: '+esc(m.color||'—')+'</div>'+
            '<div class="vk-muted vk-moto-serie">Serie: '+esc(m.serie||'—')+' <span class="vk-moto-copy" title="Copiar">📋</span></div>'+
          '</div>'+
        '</div>'+
      '</div>'+

      // --- Preferencias + Accesos rápidos ---
      '<div class="vk-shortcuts-grid">'+
        '<div>'+
          '<div class="vk-h2" style="margin:0 0 10px">Preferencias</div>'+
          '<div class="vk-shortcut-item" data-go="notif">'+
            '<span class="vk-sc-icon">🔔</span>'+
            '<div class="vk-sc-body"><div class="vk-sc-title">Notificaciones</div><div class="vk-sc-sub">Elige cómo y cuándo te contactamos.</div></div>'+
            '<span class="vk-sc-arrow">›</span>'+
          '</div>'+
          '<div class="vk-shortcut-item" data-go="cuenta">'+
            '<span class="vk-sc-icon">💳</span>'+
            '<div class="vk-sc-body"><div class="vk-sc-title">Métodos de pago</div><div class="vk-sc-sub">Administra tus tarjetas y cuentas.</div></div>'+
            '<span class="vk-sc-arrow">›</span>'+
          '</div>'+
          '<div class="vk-shortcut-item" data-go="cuenta">'+
            '<span class="vk-sc-icon">🔒</span>'+
            '<div class="vk-sc-body"><div class="vk-sc-title">Seguridad</div><div class="vk-sc-sub">Cambia tu contraseña y revisa tus sesiones.</div></div>'+
            '<span class="vk-sc-arrow">›</span>'+
          '</div>'+
          '<div class="vk-shortcut-item vk-sc-disabled">'+
            '<span class="vk-sc-icon">🌐</span>'+
            '<div class="vk-sc-body"><div class="vk-sc-title">Idioma</div><div class="vk-sc-sub">Español</div></div>'+
          '</div>'+
        '</div>'+
        '<div>'+
          '<div class="vk-h2" style="margin:0 0 10px">Accesos rápidos</div>'+
          '<div class="vk-shortcut-item" data-go="pagos">'+
            '<span class="vk-sc-icon">💰</span>'+
            '<div class="vk-sc-body"><div class="vk-sc-title">Mis pagos</div><div class="vk-sc-sub">Ver historial y próximos pagos.</div></div>'+
            '<span class="vk-sc-arrow">›</span>'+
          '</div>'+
          '<div class="vk-shortcut-item" data-go="documentos">'+
            '<span class="vk-sc-icon">📄</span>'+
            '<div class="vk-sc-body"><div class="vk-sc-title">Mis documentos</div><div class="vk-sc-sub">Descarga tus documentos.</div></div>'+
            '<span class="vk-sc-arrow">›</span>'+
          '</div>'+
          '<div class="vk-shortcut-item" data-go="ayuda">'+
            '<span class="vk-sc-icon">💬</span>'+
            '<div class="vk-sc-body"><div class="vk-sc-title">Ayuda y soporte</div><div class="vk-sc-sub">Estamos para ayudarte.</div></div>'+
            '<span class="vk-sc-arrow">›</span>'+
          '</div>'+
          '<div class="vk-shortcut-item vk-sc-danger" id="vkLogout">'+
            '<span class="vk-sc-icon">🚪</span>'+
            '<div class="vk-sc-body"><div class="vk-sc-title">Cerrar sesión</div><div class="vk-sc-sub">Salir de tu cuenta.</div></div>'+
          '</div>'+
        '</div>'+
      '</div>'+

      // --- WhatsApp help ---
      '<div class="vk-wa-help">'+
        '<div class="vk-wa-text"><strong>¿Necesitas ayuda?</strong><br>Nuestro equipo está listo para apoyarte.</div>'+
        '<a class="vk-wa-btn" href="https://wa.me/525500000000" target="_blank">💬 Chatear por WhatsApp</a>'+
      '</div>'
    );

    // Bind events
    $('#vkLogout').on('click', VKApp.logout);
    $('.vk-shortcut-item[data-go]').on('click', function(){ VKApp.go($(this).data('go')); });
    $('.vk-profile-edit').on('click', function(){ openEdit($(this).data('campo')); });
    $('#vkEditCancel').on('click', closeEdit);
    $('#vkEditSend').on('click', sendOtp);
    $('#vkOtpVerify').on('click', verifyOtp);
    $('.vk-moto-copy').on('click', function(){
      var serie = (p.moto||{}).serie||'';
      if(navigator.clipboard){ navigator.clipboard.writeText(serie); VKApp.toast('Serie copiada'); }
    });
  }

  var editCampo = '';

  function openEdit(campo){
    editCampo = campo;
    var c = (profileData||{}).cliente||{};
    $('#vkEditPanel').show();
    $('#vkOtpSection').hide();
    if(campo==='email'){
      $('#vkEditTitle').text('Cambiar correo electrónico');
      $('#vkEditDesc').text('Se enviará un código de verificación a tu teléfono.');
      $('#vkEditInput').attr('placeholder','Nuevo correo electrónico').attr('type','email').val('');
    } else {
      $('#vkEditTitle').text('Cambiar número de teléfono');
      $('#vkEditDesc').text('Se enviará un código de verificación al nuevo número.');
      $('#vkEditInput').attr('placeholder','Nuevo número de teléfono').attr('type','tel').val('');
    }
    $('#vkEditPanel')[0].scrollIntoView({behavior:'smooth'});
  }

  function closeEdit(){
    $('#vkEditPanel').hide();
    editCampo = '';
  }

  function sendOtp(){
    var val = $('#vkEditInput').val().trim();
    if(!val){ VKApp.toast('Ingresa el nuevo valor'); return; }
    $('#vkEditSend').prop('disabled',true).text('Enviando...');
    VKApp.api('cliente/solicitar-cambio.php',{campo:editCampo, nuevo_valor:val}).done(function(r){
      if(r.ok){
        $('#vkOtpSection').show();
        $('#vkOtpMsg').html('Código enviado por '+r.canal+' a <strong>'+r.destino+'</strong>');
        if(r.debug_code) $('#vkOtpMsg').append('<br><small style="color:#ef4444">SMS falló. Código: '+r.debug_code+'</small>');
        $('#vkEditSend').hide();
        $('#vkEditInput').prop('disabled',true);
      } else {
        VKApp.toast(r.error||'Error al enviar');
      }
    }).fail(function(x){
      VKApp.toast((x.responseJSON&&x.responseJSON.error)||'Error');
    }).always(function(){
      $('#vkEditSend').prop('disabled',false).text('Enviar código de verificación');
    });
  }

  function verifyOtp(){
    var code = $('#vkOtpInput').val().trim();
    if(!code||code.length<6){ VKApp.toast('Ingresa el código de 6 dígitos'); return; }
    $('#vkOtpVerify').prop('disabled',true).text('Verificando...');
    VKApp.api('cliente/verificar-cambio.php',{campo:editCampo, codigo:code}).done(function(r){
      if(r.ok){
        VKApp.toast('Actualizado correctamente');
        closeEdit();
        render(); // Reload profile
      } else {
        VKApp.toast(r.error||'Código incorrecto');
      }
    }).fail(function(x){
      VKApp.toast((x.responseJSON&&x.responseJSON.error)||'Error');
    }).always(function(){
      $('#vkOtpVerify').prop('disabled',false).text('Verificar');
    });
  }

  function esc(s){ return $('<span>').text(s||'').html(); }

  return { render:render };
})();
