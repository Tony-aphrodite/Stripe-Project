window.VK_login = (function(){
  function render(){
    VKApp.showTabbar(false);
    VKApp.render(
      '<div class="vk-logo"><img src="../configurador_prueba/img/voltika_logo_h.svg" alt="Voltika" onerror="this.style.display=\'none\'"></div>'+
      '<div class="vk-card">'+
        '<div class="vk-h1">Hola 👋</div>'+
        '<div class="vk-muted">Accede a tu cuenta con tu número de teléfono</div>'+
        '<label class="vk-label">Número de teléfono (10 dígitos)</label>'+
        '<input id="vkTel" class="vk-input" inputmode="numeric" maxlength="10" placeholder="55 0000 0000">'+
        '<button id="vkLoginBtn" class="vk-btn primary">Recibir código por SMS</button>'+
        '<div style="text-align:center;margin-top:12px"><a class="vk-link" id="vkRecLink">¿Cambiaste de número?</a></div>'+
      '</div>'
    );
    $('#vkTel').on('input',function(){ this.value=this.value.replace(/\D/g,''); });
    $('#vkLoginBtn').on('click', requestOTP);
    $('#vkRecLink').on('click', function(){ VK_recovery.render(); });
  }
  function requestOTP(){
    var tel = $('#vkTel').val();
    if(tel.length<10) return VKApp.toast('Ingresa 10 dígitos');
    var $b = $('#vkLoginBtn').prop('disabled',true).html('<span class="vk-spin"></span>');
    VKApp.api('auth/login-request.php',{telefono:tel}).done(function(r){
      if(r.ok){ otpScreen(tel, r.testCode); }
      else VKApp.toast(r.error||'Error');
    }).fail(function(x){ VKApp.toast((x.responseJSON&&x.responseJSON.error)||'No se pudo enviar'); })
      .always(function(){ $b.prop('disabled',false).text('Recibir código por SMS'); });
  }
  function otpScreen(tel, testCode){
    VKApp.render(
      '<div class="vk-logo"><img src="../configurador_prueba/img/voltika_logo_h.svg" alt="Voltika" onerror="this.style.display=\'none\'"></div>'+
      '<div class="vk-card">'+
        '<div class="vk-h1">Ingresa tu código</div>'+
        '<div class="vk-muted">Enviado a '+tel+'</div>'+
        '<div class="vk-otp">'+
          [0,1,2,3,4,5].map(function(i){return '<input data-i="'+i+'" inputmode="numeric" maxlength="1">';}).join('')+
        '</div>'+
        (testCode?'<div class="vk-banner warn">Código de prueba: <b>'+testCode+'</b></div>':'')+
        '<button id="vkVerifyBtn" class="vk-btn primary" disabled>Verificar</button>'+
        '<div style="text-align:center;margin-top:12px"><a class="vk-link" id="vkBack">← Cambiar número</a></div>'+
      '</div>'
    );
    var $ins = $('.vk-otp input');
    $ins.on('input',function(){
      this.value=this.value.replace(/\D/g,'');
      if(this.value) $ins.eq($(this).data('i')+1).focus();
      var code = $ins.map(function(){return this.value;}).get().join('');
      $('#vkVerifyBtn').prop('disabled', code.length<6);
    }).on('keydown',function(e){
      if(e.key==='Backspace' && !this.value) $ins.eq($(this).data('i')-1).focus();
    });
    $ins.eq(0).focus();
    $('#vkBack').on('click',render);
    $('#vkVerifyBtn').on('click',function(){
      var code = $ins.map(function(){return this.value;}).get().join('');
      var $b=$(this).prop('disabled',true).html('<span class="vk-spin"></span>');
      VKApp.api('auth/login-verify.php',{telefono:tel,codigo:code}).done(function(r){
        if(r.ok){
          VKApp.state.cliente=r.cliente;
          VKApp.loadEstado(function(){ VKApp.showTabbar(true); VKApp.go('inicio'); });
        } else VKApp.toast(r.error||'Código incorrecto');
      }).fail(function(x){ VKApp.toast((x.responseJSON&&x.responseJSON.error)||'Error'); })
        .always(function(){ $b.prop('disabled',false).text('Verificar'); });
    });
  }
  return { render:render };
})();
