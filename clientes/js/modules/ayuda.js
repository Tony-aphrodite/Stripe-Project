window.VK_ayuda = (function(){
  // Customer brief 2026-04-20: show the WhatsApp number on screen — not just
  // hidden inside the button's href. Users want to see / copy / dial it.
  var WA_NUMBER_RAW    = '5214421198928';                 // wa.me / api.whatsapp expects digits only
  var WA_NUMBER_HUMAN  = '+52 442 119 8928';              // pretty-printed for display
  var WA_LINK          = 'https://api.whatsapp.com/send?phone=' + WA_NUMBER_RAW;

  var FAQ = [
    ['¿Cómo funciona mi pago semanal?','Cada semana se cobra automáticamente a tu tarjeta registrada. También puedes pagar manualmente desde la app.'],
    ['¿Puedo adelantar pagos?','¡Sí! Puedes adelantar 2 semanas o más sin penalización y terminar tu plan antes.'],
    ['¿Qué pasa si mi tarjeta falla?','Te notificamos para que actualices tu método de pago. Tienes varios días antes de que tu cuenta se marque como vencida.'],
    ['¿Cuándo puedo descargar mi carta factura?','Cuando tu cuenta esté AL CORRIENTE. La usas para emplacar tu Voltika.'],
    ['¿Me pueden cobrar dos veces?','No. Si pagas manualmente una semana, el sistema nunca la volverá a cobrar automáticamente.']
  ];

  function render(){
    var iconChat = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#039fe1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-4px;margin-right:6px;"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>';
    var iconPhone = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:6px;"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>';
    var iconArrow = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-left:6px;"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>';

    VKApp.render(
      '<div class="vk-h1">Ayuda</div>'+

      // Highlighted WhatsApp card with number prominently displayed
      '<div class="vk-card hl" style="text-align:center;padding:20px 18px;">'+
        '<div class="vk-h2" style="margin:0 0 4px;">'+iconChat+'WhatsApp de soporte</div>'+

        // Phone number — large, monospace, clickable
        '<a href="'+WA_LINK+'" target="_blank" rel="noopener noreferrer" '+
            'style="display:inline-block;font-size:22px;font-weight:800;color:#16a34a;'+
                   'text-decoration:none;letter-spacing:.5px;margin:14px 0 6px;'+
                   'font-family:ui-monospace,Menlo,Consolas,monospace;">'+
          iconPhone+WA_NUMBER_HUMAN+
        '</a>'+

        '<div class="vk-muted" style="font-size:12.5px;margin:6px 0 14px;">Atención de lunes a sábado<br>9:00 a 19:00</div>'+

        '<a class="vk-btn primary" href="'+WA_LINK+'" target="_blank" rel="noopener noreferrer" '+
            'style="text-decoration:none;display:inline-block;padding:12px 28px;font-size:14px;">'+
          'Abrir WhatsApp'+iconArrow+
        '</a>'+
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
