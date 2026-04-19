window.VK_recovery = (function(){
  var ctx = {};
  function wrap(title, sub, body){
    VKApp.showTabbar(false);
    VKApp.render(
      '<div class="vk-logo"><img src="../configurador_prueba/img/voltika_logo_h.svg" alt="Voltika" onerror="this.style.display=\'none\'"></div>'+
      '<div class="vk-card">'+
        '<div class="vk-h1">'+title+'</div>'+
        '<div class="vk-muted">'+sub+'</div>'+
        body+
        '<div style="text-align:center;margin-top:12px"><a class="vk-link" id="vkCancel">← Volver al login</a></div>'+
      '</div>'
    );
    $('#vkCancel').on('click', function(){ ctx={}; VK_login.render(); });
  }
  function call(step, data, cb){
    return VKApp.api('auth/recovery.php?step='+step, data).done(function(r){
      // Backend uses {status:'sent'|'ok'} as success marker — accept either
      // that or the more conventional {ok:true}. Treat any other shape as a
      // structured error so the toast tells the user something useful.
      if (r && (r.ok || r.status === 'sent' || r.status === 'ok')) {
        cb(r);
      } else if (r && r.error) {
        VKApp.toast(r.error);
      } else {
        VKApp.toast('Respuesta inesperada del servidor');
      }
    }).fail(function(x){
      var msg = 'Error';
      if (x.responseJSON && x.responseJSON.error) msg = x.responseJSON.error;
      else if (x.responseText) msg = 'HTTP ' + (x.status||'?') + ': ' + x.responseText.substring(0, 200);
      VKApp.toast(msg);
    });
  }
  function render(){ ctx={}; step1(); }
  function step1(){
    wrap('Recuperar acceso','1 de 6 — Ingresa tu email registrado',
      '<label class="vk-label">Email</label>'+
      '<input id="rEmail" class="vk-input" type="email" placeholder="tu@email.com">'+
      '<button id="rNext" class="vk-btn primary">Enviar código</button>');
    $('#rNext').on('click',function(){
      var em=$('#rEmail').val().trim(); if(!em) return;
      var $b=$(this).prop('disabled',true).html('<span class="vk-spin"></span>');
      call(1,{email:em},function(r){ ctx.email=em; step2(r.testCode); })
        .always(function(){ $b.prop('disabled',false).text('Enviar código'); });
    });
  }
  function step2(testCode){
    wrap('Código por email','2 de 6 — Revisa tu correo',
      // Green confirmation banner — customer brief 2026-04-19
      '<div style="background:#ecfdf5;border-left:4px solid #22c55e;color:#0e8f55;'+
        'padding:10px 12px;border-radius:6px;font-size:13px;font-weight:600;margin:0 0 12px;">'+
        '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:6px;"><polyline points="20 6 9 17 4 12"/></svg>'+
        'Confirmación enviada a tu correo registrado!'+
      '</div>'+
      '<label class="vk-label">Código de 6 dígitos</label>'+
      '<input id="rCode" class="vk-input" inputmode="numeric" maxlength="6">'+
      (testCode?'<div class="vk-banner warn" style="margin-top:8px;">Código de prueba: <b>'+testCode+'</b></div>':'')+
      // Optional phone update — captures the new comms number here so the
      // user doesn't re-enter it later. If left blank we keep the old one.
      '<div style="margin-top:18px;padding-top:14px;border-top:1px solid #e1e8ee;">'+
        '<label class="vk-label" style="display:block;">Nuevo número de comunicación <span style="color:#888;font-weight:400;">(opcional)</span></label>'+
        '<input id="rNewTel" class="vk-input" inputmode="numeric" maxlength="10" placeholder="10 dígitos">'+
        '<div class="vk-muted" style="font-size:11px;margin-top:4px;line-height:1.5;">Si tu número cambió, déjanos el nuevo aquí — recibirás futuras notificaciones por WhatsApp y SMS en este.</div>'+
      '</div>'+
      '<button id="rNext" class="vk-btn primary" style="margin-top:14px;">Verificar</button>');

    $('#rNewTel').on('input', function(){ this.value = this.value.replace(/\D/g, ''); });

    $('#rNext').on('click',function(){
      var tel = $('#rNewTel').val().trim();
      if (tel && tel.length !== 10) { VKApp.toast('Teléfono debe tener 10 dígitos'); return; }
      var payload = { email: ctx.email, codigo: $('#rCode').val() };
      if (tel) payload.telefono = tel;
      call(2, payload, function(){
        if (tel) ctx.tel = tel;  // remember for step 5 auto-skip
        step3();
      });
    });
  }
  function step3(){
    wrap('Verificación de identidad','3 de 6 — Apellido paterno',
      '<label class="vk-label">Apellido paterno (como aparece en tu contrato)</label>'+
      '<input id="rAp" class="vk-input">'+
      '<button id="rNext" class="vk-btn primary">Continuar</button>');
    $('#rNext').on('click',function(){
      call(3,{email:ctx.email,apellido:$('#rAp').val()},function(){ step4(); });
    });
  }
  function step4(){
    wrap('Verificación de identidad','4 de 6 — Fecha de nacimiento',
      '<label class="vk-label">Fecha de nacimiento (DD/MM/AAAA)</label>'+
      '<input id="rDob" class="vk-input" placeholder="01/01/1990">'+
      '<button id="rNext" class="vk-btn primary">Continuar</button>');
    $('#rNext').on('click',function(){
      call(4,{email:ctx.email,fecha:$('#rDob').val()},function(){ step5(); });
    });
  }
  function step5(){
    // If the user already entered a new phone at step 2, skip this screen and
    // jump straight to SMS dispatch. Otherwise show the original input form.
    if (ctx.tel) {
      wrap('Enviando SMS','5 de 6 — Confirmando tu nuevo número',
        '<div style="text-align:center;padding:30px 0;"><span class="vk-spin"></span></div>'+
        '<div class="vk-muted" style="text-align:center;font-size:13px;">Enviando código a +52 '+ctx.tel.substr(0,3)+' ****'+ctx.tel.substr(-2)+'…</div>');
      call(5, {email: ctx.email, telefono: ctx.tel}, function(r){ step6(r.testCode); });
      return;
    }
    wrap('Nuevo número','5 de 6 — Ingresa tu nuevo teléfono',
      '<label class="vk-label">Nuevo número (10 dígitos)</label>'+
      '<input id="rTel" class="vk-input" inputmode="numeric" maxlength="10">'+
      '<button id="rNext" class="vk-btn primary">Enviar SMS</button>');
    $('#rTel').on('input',function(){ this.value=this.value.replace(/\D/g,''); });
    $('#rNext').on('click',function(){
      var tel=$('#rTel').val(); if(tel.length<10) return;
      call(5,{email:ctx.email,telefono:tel},function(r){ ctx.tel=tel; step6(r.testCode); });
    });
  }
  function step6(testCode){
    wrap('Confirma tu nuevo número','6 de 6 — Código SMS',
      '<label class="vk-label">Código de 6 dígitos</label>'+
      '<input id="rCode" class="vk-input" inputmode="numeric" maxlength="6">'+
      (testCode?'<div class="vk-banner warn">Código de prueba: <b>'+testCode+'</b></div>':'')+
      '<button id="rNext" class="vk-btn primary">Finalizar</button>');
    $('#rNext').on('click',function(){
      call(6,{email:ctx.email,telefono:ctx.tel,codigo:$('#rCode').val()},function(r){
        VKApp.state.cliente=r.cliente;
        VKApp.loadEstado(function(){ VKApp.showTabbar(true); VKApp.go('inicio'); VKApp.toast('¡Acceso restaurado!'); });
      });
    });
  }
  return { render:render };
})();
