window.PV_login = (function(){
  function render(){
    $('#pvSidebar').hide();
    PVApp.render(
      '<div class="ad-login">'+
        '<div style="margin-bottom:24px;display:flex;justify-content:center;"><img src="../configurador_prueba/img/voltika_logo_h.svg" alt="Voltika" style="height:40px;width:auto;" onerror="this.style.display=\'none\'"></div>'+
        '<div class="ad-card">'+
          '<div class="ad-h2">Acceso del punto</div>'+
          '<input id="pvEmail" class="ad-input" type="email" placeholder="Email">'+
          '<input id="pvPass" class="ad-input" type="password" placeholder="Contraseña">'+
          '<button id="pvLoginBtn" class="ad-btn primary" style="width:100%;margin-top:10px">Ingresar</button>'+
        '</div>'+
      '</div>'
    );
    $('#pvLoginBtn').on('click', doLogin);
    $('#pvPass').on('keypress', function(e){ if(e.which===13) doLogin(); });
  }
  function doLogin(){
    var email=$('#pvEmail').val(), pass=$('#pvPass').val();
    if(!email||!pass) return;
    var $b=$('#pvLoginBtn').prop('disabled',true).html('<span class="ad-spin"></span>');
    PVApp.api('auth/login.php',{email:email,password:pass}).done(function(r){
      if(r.ok){ PVApp.state.user=r.usuario; PVApp.state.punto=r.punto; PVApp.showApp(); }
    }).fail(function(x){ alert((x.responseJSON&&x.responseJSON.error)||'Error'); })
     .always(function(){ $b.prop('disabled',false).text('Ingresar'); });
  }
  return { render:render };
})();
