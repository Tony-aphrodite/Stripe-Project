window.VK_ayuda = (function(){
  var FAQ = [
    ['¿Cómo funciona mi pago semanal?','Cada semana se cobra automáticamente a tu tarjeta registrada. También puedes pagar manualmente desde la app.'],
    ['¿Puedo adelantar pagos?','¡Sí! Puedes adelantar 2 semanas o más sin penalización y terminar tu plan antes.'],
    ['¿Qué pasa si mi tarjeta falla?','Te notificamos para que actualices tu método de pago. Tienes varios días antes de que tu cuenta se marque como vencida.'],
    ['¿Cuándo puedo descargar mi carta factura?','Cuando tu cuenta esté AL CORRIENTE. La usas para emplacar tu Voltika.'],
    ['¿Me pueden cobrar dos veces?','No. Si pagas manualmente una semana, el sistema nunca la volverá a cobrar automáticamente.']
  ];
  function render(){
    VKApp.render(
      '<div class="vk-h1">Ayuda</div>'+
      '<div class="vk-card hl">'+
        '<div class="vk-h2">💬 WhatsApp de soporte</div>'+
        '<div class="vk-muted">Atención de lunes a sábado, 9:00 a 19:00</div>'+
        '<a class="vk-btn primary" href="https://wa.me/525500000000" target="_blank" style="text-decoration:none;text-align:center">Abrir WhatsApp</a>'+
      '</div>'+
      '<div class="vk-h2">Preguntas frecuentes</div>'+
      FAQ.map(function(f,i){
        return '<div class="vk-card"><div class="vk-h2" style="cursor:pointer" data-i="'+i+'">'+f[0]+' ▾</div><div class="vk-muted vkA" data-i="'+i+'" style="display:none">'+f[1]+'</div></div>';
      }).join('')
    );
    $('[data-i]').on('click',function(){ $('.vkA[data-i="'+$(this).data('i')+'"]').toggle(); });
  }
  return { render:render };
})();
