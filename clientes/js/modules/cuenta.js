window.VK_cuenta = (function(){
  function render(){
    VKApp.render('<div class="vk-h1">Mi cuenta</div><div class="vk-muted"><span class="vk-spin"></span> Cargando...</div>');
    VKApp.api('cliente/perfil.php').done(paint);
  }
  function paint(p){
    var c = p.cliente||{}; var m=p.moto||{}; var pr=p.preferencias||{};
    VKApp.render(
      '<div class="vk-h1">Mi cuenta</div>'+
      '<div class="vk-card">'+
        '<div class="vk-h2">👤 '+(c.nombre||'')+' '+(c.apellido_paterno||'')+'</div>'+
        '<div class="vk-row"><span class="k">Email</span><span class="v">'+(c.email||'—')+'</span></div>'+
        '<div class="vk-row"><span class="k">Teléfono</span><span class="v">'+(c.telefono||'—')+'</span></div>'+
        '<div class="vk-row"><span class="k">RFC</span><span class="v">'+(c.rfc||'—')+'</span></div>'+
        '<button class="vk-btn ghost" id="vkEdit">Editar email</button>'+
      '</div>'+

      '<div class="vk-card">'+
        '<div class="vk-h2">🛵 Mi Voltika</div>'+
        '<div class="vk-row"><span class="k">Modelo</span><span class="v">'+(m.modelo||'Voltika')+'</span></div>'+
        '<div class="vk-row"><span class="k">Color</span><span class="v">'+(m.color||'—')+'</span></div>'+
        '<div class="vk-row"><span class="k">VIN/Serie</span><span class="v">'+(m.vin||'—')+'</span></div>'+
      '</div>'+

      '<div class="vk-card">'+
        '<div class="vk-h2">🔔 Preferencias de notificación</div>'+
        '<div class="vk-row"><span class="k">Email</span><span class="v"><input type="checkbox" class="vkPref" data-k="notif_email" '+(pr.notif_email!=0?'checked':'')+'></span></div>'+
        '<div class="vk-row"><span class="k">WhatsApp</span><span class="v"><input type="checkbox" class="vkPref" data-k="notif_whatsapp" '+(pr.notif_whatsapp!=0?'checked':'')+'></span></div>'+
        '<div class="vk-row"><span class="k">SMS</span><span class="v"><input type="checkbox" class="vkPref" data-k="notif_sms" '+(pr.notif_sms!=0?'checked':'')+'></span></div>'+
      '</div>'+

      '<div class="vk-card">'+
        '<div class="vk-h2">🛡️ Protección y seguridad</div>'+
        '<div class="vk-muted">Sesión protegida con VOLTIKA_PORTAL · verificación por SMS/email</div>'+
      '</div>'+

      '<button class="vk-btn danger" id="vkLogout">Cerrar sesión</button>'
    );
    $('#vkLogout').on('click', VKApp.logout);
    $('#vkEdit').on('click', function(){
      var em = prompt('Nuevo email:', c.email||''); if(!em) return;
      VKApp.api('cliente/perfil.php',{email:em}).done(function(){ VKApp.toast('✅ Actualizado'); render(); });
    });
    $('.vkPref').on('change', function(){
      var data={}; $('.vkPref').each(function(){ data[$(this).data('k')] = this.checked?1:0; });
      VKApp.api('cliente/perfil.php',{preferencias:data}).done(function(){ VKApp.toast('Guardado'); });
    });
  }
  return { render:render };
})();
