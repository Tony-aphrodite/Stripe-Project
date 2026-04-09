window.VK_documentos = (function(){
  var ICONS = {contrato:'📝',pagare:'📄',acta_entrega:'🛵',comprobantes:'🧾',carta_factura:'⭐'};
  function render(){
    VKApp.render('<div class="vk-h1">Documentos</div><div class="vk-muted"><span class="vk-spin"></span> Cargando...</div>');
    VKApp.api('documentos/lista.php').done(paint);
  }
  function paint(r){
    var docs = r.documentos||[];
    var carta = docs.filter(function(d){return d.tipo==='carta_factura';})[0];
    var otros = docs.filter(function(d){return d.tipo!=='carta_factura';});

    var cartaHtml = carta ? (
      '<div class="vk-card '+(carta.disponible?'hl':'')+'">'+
        '<div class="vk-h2">⭐ '+carta.titulo+'</div>'+
        '<div class="vk-muted">'+carta.subtitulo+'</div>'+
        (carta.disponible
          ? '<button class="vk-btn primary" data-tipo="carta_factura">Descargar</button>'
          : '<div class="vk-banner warn">🔒 Para activar tu carta factura, tu compra debe estar al corriente.</div>')+
      '</div>'
    ) : '';

    var otrosHtml = otros.map(function(d){
      return '<div class="vk-doc '+(d.disponible?'':'locked')+'" '+(d.disponible?'data-tipo="'+d.tipo+'"':'')+'>'+
        '<div class="ic">'+(ICONS[d.tipo]||'📄')+'</div>'+
        '<div class="body"><div class="title">'+d.titulo+'</div><div class="sub">'+d.subtitulo+'</div></div>'+
        '<div>'+(d.disponible?'⬇️':'🔒')+'</div>'+
      '</div>';
    }).join('');

    VKApp.render(
      '<div class="vk-h1">Documentos</div>'+
      cartaHtml+
      '<div class="vk-h2">Otros documentos</div>'+
      otrosHtml+
      '<div class="vk-card"><div class="vk-h2">¿Necesitas ayuda?</div>'+
        '<div class="vk-muted">Escríbenos por WhatsApp y te apoyamos.</div>'+
        '<button class="vk-btn ghost" onclick="VKApp.go(\'ayuda\')">Contactar soporte</button>'+
      '</div>'
    );
    $('[data-tipo]').on('click',function(){
      var t=$(this).data('tipo'); window.open('php/documentos/descargar.php?tipo='+encodeURIComponent(t),'_blank');
    });
  }
  return { render:render };
})();
