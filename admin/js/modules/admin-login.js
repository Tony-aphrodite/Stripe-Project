window.AD_login = (function(){
  function render(){
    $('#adSidebar').hide();
    ADApp.render(
      '<div class="ad-login">'+
        '<div style="font-size:32px;font-weight:800;color:var(--ad-primary);margin-bottom:24px">⚡ VOLTIKA</div>'+
        '<div class="ad-card">'+
          '<div class="ad-h2">Acceso al Panel</div>'+
          '<input id="adEmail" class="ad-input" type="email" placeholder="Email">'+
          '<input id="adPass" class="ad-input" type="password" placeholder="Contraseña">'+
          '<button id="adLoginBtn" class="ad-btn primary" style="width:100%;margin-top:10px">Ingresar</button>'+
        '</div>'+
      '</div>'
    );
    $('#adLoginBtn').on('click', doLogin);
    $('#adPass').on('keypress', function(e){ if(e.which===13) doLogin(); });
  }
  function doLogin(){
    var email=$('#adEmail').val(), pass=$('#adPass').val();
    if(!email||!pass) return;
    var $b=$('#adLoginBtn').prop('disabled',true).html('<span class="ad-spin"></span>');
    ADApp.api('auth/login.php',{email:email,password:pass}).done(function(r){
      if(r.ok){ ADApp.state.user=r.usuario; ADApp.showApp(); }
    }).fail(function(x){
      var msg=(x.responseJSON&&x.responseJSON.error)||'Error de acceso';
      alert(msg);
    }).always(function(){ $b.prop('disabled',false).text('Ingresar'); });
  }
  return { render:render };
})();
