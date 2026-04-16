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
      if(r.ok) cb(r); else VKApp.toast(r.error||'Error');
    }).fail(function(x){ VKApp.toast((x.responseJSON&&x.responseJSON.error)||'Error'); });
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
      '<label class="vk-label">Código de 6 dígitos</label>'+
      '<input id="rCode" class="vk-input" inputmode="numeric" maxlength="6">'+
      (testCode?'<div class="vk-banner warn">Código de prueba: <b>'+testCode+'</b></div>':'')+
      '<button id="rNext" class="vk-btn primary">Verificar</button>');
    $('#rNext').on('click',function(){
      call(2,{email:ctx.email,codigo:$('#rCode').val()},function(){ step3(); });
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
