window.AD_login = (function(){
  var _recoveryEmail = '';
  var _resetToken = '';

  function render(){
    $('#adSidebar').hide();
    ADApp.render(
      '<div class="ad-login">'+
        '<div style="margin-bottom:28px;display:flex;justify-content:center;">'+
          '<img src="../configurador_prueba/img/voltika_logo_h.svg" alt="Voltika" style="height:44px;width:auto;" onerror="this.style.display=\'none\'">'+
        '</div>'+
        '<div style="font-size:22px;font-weight:800;color:var(--ad-navy);margin-bottom:6px;">Panel de Administraci\u00f3n</div>'+
        '<div style="font-size:13px;color:var(--ad-dim);margin-bottom:24px;">Ingresa tus credenciales para continuar</div>'+
        '<input id="adEmail" class="ad-input" type="email" placeholder="Email" style="margin-bottom:12px">'+
        '<input id="adPass" class="ad-input" type="password" placeholder="Contrase\u00f1a" style="margin-bottom:16px">'+
        '<button id="adLoginBtn" class="ad-btn primary" style="width:100%;padding:14px;font-size:15px;">Ingresar</button>'+
        '<div style="text-align:center;margin-top:14px;">'+
          '<a href="#" id="adForgotLink" style="font-size:13px;color:#039fe1;text-decoration:none;">\u00bfOlvidaste tu contrase\u00f1a?</a>'+
        '</div>'+
        '<div id="adLoginError" style="display:none;margin-top:12px;padding:12px;border-radius:var(--ad-radius-sm);background:rgba(239,68,68,.08);color:#b91c1c;font-size:13px;font-weight:600;text-align:center;"></div>'+
      '</div>'
    );
    $('#adLoginBtn').on('click', doLogin);
    $('#adPass').on('keypress', function(e){ if(e.which===13) doLogin(); });
    $('#adForgotLink').on('click', function(e){ e.preventDefault(); renderRecoveryStep1(); });
  }

  function doLogin(){
    var email=$('#adEmail').val(), pass=$('#adPass').val();
    if(!email||!pass){ $('#adLoginError').text('Ingresa email y contrase\u00f1a').show(); return; }
    $('#adLoginError').hide();
    var $b=$('#adLoginBtn').prop('disabled',true).html('<span class="ad-spin"></span> Ingresando...');
    ADApp.api('auth/login.php',{email:email,password:pass}).done(function(r){
      if(r.ok){ ADApp.state.user=r.usuario; ADApp.showApp(); }
    }).fail(function(x){
      var msg=(x.responseJSON&&x.responseJSON.error)||'Error de acceso';
      $('#adLoginError').text(msg).show();
    }).always(function(){ $b.prop('disabled',false).text('Ingresar'); });
  }

  // ── Recovery Step 1: Enter email ──────────────────────────────────────────
  function renderRecoveryStep1(){
    ADApp.render(
      '<div class="ad-login">'+
        '<div style="margin-bottom:28px;display:flex;justify-content:center;">'+
          '<img src="../configurador_prueba/img/voltika_logo_h.svg" alt="Voltika" style="height:44px;width:auto;" onerror="this.style.display=\'none\'">'+
        '</div>'+
        '<div style="font-size:20px;font-weight:800;color:var(--ad-navy);margin-bottom:6px;">Recuperar contrase\u00f1a</div>'+
        '<div style="font-size:13px;color:var(--ad-dim);margin-bottom:24px;">Ingresa tu email y te enviaremos un c\u00f3digo de verificaci\u00f3n</div>'+
        '<input id="adRecEmail" class="ad-input" type="email" placeholder="Email" style="margin-bottom:16px">'+
        '<button id="adRecSendBtn" class="ad-btn primary" style="width:100%;padding:14px;font-size:15px;">Enviar c\u00f3digo</button>'+
        '<div style="text-align:center;margin-top:14px;">'+
          '<a href="#" id="adRecBack" style="font-size:13px;color:#039fe1;text-decoration:none;">\u2190 Volver al login</a>'+
        '</div>'+
        '<div id="adRecError" style="display:none;margin-top:12px;padding:12px;border-radius:var(--ad-radius-sm);background:rgba(239,68,68,.08);color:#b91c1c;font-size:13px;font-weight:600;text-align:center;"></div>'+
      '</div>'
    );
    $('#adRecSendBtn').on('click', doSendOTP);
    $('#adRecEmail').on('keypress', function(e){ if(e.which===13) doSendOTP(); });
    $('#adRecBack').on('click', function(e){ e.preventDefault(); render(); });
  }

  function doSendOTP(){
    var email = $('#adRecEmail').val();
    if(!email){ $('#adRecError').text('Ingresa tu email').show(); return; }
    $('#adRecError').hide();
    var $b = $('#adRecSendBtn').prop('disabled',true).html('<span class="ad-spin"></span> Enviando...');
    _recoveryEmail = email;
    ADApp.api('auth/recovery-request.php',{email:email}).done(function(r){
      renderRecoveryStep2();
    }).fail(function(x){
      var msg=(x.responseJSON&&x.responseJSON.error)||'Error al enviar el c\u00f3digo';
      $('#adRecError').text(msg).show();
    }).always(function(){ $b.prop('disabled',false).text('Enviar c\u00f3digo'); });
  }

  // ── Recovery Step 2: Enter OTP code ───────────────────────────────────────
  function renderRecoveryStep2(){
    ADApp.render(
      '<div class="ad-login">'+
        '<div style="margin-bottom:28px;display:flex;justify-content:center;">'+
          '<img src="../configurador_prueba/img/voltika_logo_h.svg" alt="Voltika" style="height:44px;width:auto;" onerror="this.style.display=\'none\'">'+
        '</div>'+
        '<div style="font-size:20px;font-weight:800;color:var(--ad-navy);margin-bottom:6px;">Verificar c\u00f3digo</div>'+
        '<div style="font-size:13px;color:var(--ad-dim);margin-bottom:24px;">Ingresa el c\u00f3digo de 6 d\u00edgitos enviado a <strong>'+_recoveryEmail+'</strong></div>'+
        '<input id="adRecCode" class="ad-input" type="text" placeholder="000000" maxlength="6" style="margin-bottom:16px;text-align:center;font-size:24px;letter-spacing:8px;font-weight:800;">'+
        '<button id="adRecVerifyBtn" class="ad-btn primary" style="width:100%;padding:14px;font-size:15px;">Verificar</button>'+
        '<div style="text-align:center;margin-top:14px;">'+
          '<a href="#" id="adRecResend" style="font-size:13px;color:#039fe1;text-decoration:none;">Reenviar c\u00f3digo</a>'+
          ' &middot; '+
          '<a href="#" id="adRecBack2" style="font-size:13px;color:#039fe1;text-decoration:none;">Volver al login</a>'+
        '</div>'+
        '<div id="adRecError2" style="display:none;margin-top:12px;padding:12px;border-radius:var(--ad-radius-sm);background:rgba(239,68,68,.08);color:#b91c1c;font-size:13px;font-weight:600;text-align:center;"></div>'+
      '</div>'
    );
    $('#adRecVerifyBtn').on('click', doVerifyOTP);
    $('#adRecCode').on('keypress', function(e){ if(e.which===13) doVerifyOTP(); });
    $('#adRecResend').on('click', function(e){
      e.preventDefault();
      ADApp.api('auth/recovery-request.php',{email:_recoveryEmail}).done(function(){
        $('#adRecError2').css({background:'rgba(5,150,105,.08)',color:'#059669'}).text('C\u00f3digo reenviado').show();
        setTimeout(function(){ $('#adRecError2').hide(); }, 3000);
      });
    });
    $('#adRecBack2').on('click', function(e){ e.preventDefault(); render(); });
  }

  function doVerifyOTP(){
    var code = $('#adRecCode').val();
    if(!code||code.length<6){ $('#adRecError2').css({background:'',color:''}).text('Ingresa el c\u00f3digo de 6 d\u00edgitos').show(); return; }
    $('#adRecError2').hide();
    var $b = $('#adRecVerifyBtn').prop('disabled',true).html('<span class="ad-spin"></span> Verificando...');
    ADApp.api('auth/recovery-verify.php',{email:_recoveryEmail,codigo:code}).done(function(r){
      if(r.ok && r.resetToken){
        _resetToken = r.resetToken;
        renderRecoveryStep3();
      }
    }).fail(function(x){
      var msg=(x.responseJSON&&x.responseJSON.error)||'C\u00f3digo inv\u00e1lido';
      $('#adRecError2').css({background:'rgba(239,68,68,.08)',color:'#b91c1c'}).text(msg).show();
    }).always(function(){ $b.prop('disabled',false).text('Verificar'); });
  }

  // ── Recovery Step 3: New password ─────────────────────────────────────────
  function renderRecoveryStep3(){
    ADApp.render(
      '<div class="ad-login">'+
        '<div style="margin-bottom:28px;display:flex;justify-content:center;">'+
          '<img src="../configurador_prueba/img/voltika_logo_h.svg" alt="Voltika" style="height:44px;width:auto;" onerror="this.style.display=\'none\'">'+
        '</div>'+
        '<div style="font-size:20px;font-weight:800;color:var(--ad-navy);margin-bottom:6px;">Nueva contrase\u00f1a</div>'+
        '<div style="font-size:13px;color:var(--ad-dim);margin-bottom:24px;">Ingresa tu nueva contrase\u00f1a (m\u00ednimo 6 caracteres)</div>'+
        '<input id="adRecNewPass" class="ad-input" type="password" placeholder="Nueva contrase\u00f1a" style="margin-bottom:12px">'+
        '<input id="adRecConfirmPass" class="ad-input" type="password" placeholder="Confirmar contrase\u00f1a" style="margin-bottom:16px">'+
        '<button id="adRecResetBtn" class="ad-btn primary" style="width:100%;padding:14px;font-size:15px;">Cambiar contrase\u00f1a</button>'+
        '<div id="adRecError3" style="display:none;margin-top:12px;padding:12px;border-radius:var(--ad-radius-sm);font-size:13px;font-weight:600;text-align:center;"></div>'+
      '</div>'
    );
    $('#adRecResetBtn').on('click', doResetPassword);
    $('#adRecConfirmPass').on('keypress', function(e){ if(e.which===13) doResetPassword(); });
  }

  function doResetPassword(){
    var p1 = $('#adRecNewPass').val(), p2 = $('#adRecConfirmPass').val();
    var $err = $('#adRecError3');
    if(!p1||p1.length<6){ $err.css({background:'rgba(239,68,68,.08)',color:'#b91c1c'}).text('M\u00ednimo 6 caracteres').show(); return; }
    if(p1!==p2){ $err.css({background:'rgba(239,68,68,.08)',color:'#b91c1c'}).text('Las contrase\u00f1as no coinciden').show(); return; }
    $err.hide();
    var $b = $('#adRecResetBtn').prop('disabled',true).html('<span class="ad-spin"></span> Guardando...');
    ADApp.api('auth/recovery-reset.php',{resetToken:_resetToken,password:p1}).done(function(r){
      if(r.ok){
        $err.css({background:'rgba(5,150,105,.08)',color:'#059669'}).text('Contrase\u00f1a actualizada. Redirigiendo...').show();
        setTimeout(function(){ render(); }, 2000);
      }
    }).fail(function(x){
      var msg=(x.responseJSON&&x.responseJSON.error)||'Error al cambiar contrase\u00f1a';
      $err.css({background:'rgba(239,68,68,.08)',color:'#b91c1c'}).text(msg).show();
    }).always(function(){ $b.prop('disabled',false).text('Cambiar contrase\u00f1a'); });
  }

  return { render:render };
})();
