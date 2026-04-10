window.AD_login = (function(){
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
        '<div id="adLoginError" style="display:none;margin-top:12px;padding:12px;border-radius:var(--ad-radius-sm);background:rgba(239,68,68,.08);color:#b91c1c;font-size:13px;font-weight:600;text-align:center;"></div>'+
      '</div>'
    );
    $('#adLoginBtn').on('click', doLogin);
    $('#adPass').on('keypress', function(e){ if(e.which===13) doLogin(); });
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
  return { render:render };
})();
