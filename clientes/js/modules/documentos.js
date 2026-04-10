window.VK_documentos = (function(){

  var DOC_META = {
    contrato: {
      icon: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#3b82f6" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>',
      desc: 'Tu contrato con Voltika, firmado de forma electrónica.',
      size: 'PDF • 2.4 MB'
    },
    acta_entrega: {
      icon: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#22c55e" stroke-width="2"><path d="M9 12l2 2 4-4"/><rect x="3" y="3" width="18" height="18" rx="3"/></svg>',
      desc: 'Confirmación de que recibiste tu Voltika en perfecto estado.',
      size: 'PDF • 1.1 MB'
    },
    comprobantes: {
      icon: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#8b5cf6" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>',
      desc: 'Historial completo de los pagos que has realizado.',
      size: 'PDF • 800 KB'
    },
    pagare: {
      icon: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#f59e0b" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/></svg>',
      desc: 'Pagaré firmado como parte de tu compra a plazos.',
      size: 'PDF • 1.0 MB'
    },
    carta_factura: {
      icon: '<svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="#f59e0b" stroke-width="2"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z"/></svg>',
      desc: 'Tu cuenta está al corriente. Puedes descargarla y usarla para realizar tu trámite de placas.',
      descLocked: 'Para activar tu carta factura, tu compra debe estar al corriente. Ponte al corriente y descárgala al instante.',
      size: 'PDF • 1.2 MB'
    }
  };

  function badgeFor(doc){
    var t = doc.tipo;
    if(t==='contrato') return doc.disponible ? '<span class="vk-doc-badge green">Firmado digitalmente</span>' : '';
    if(t==='acta_entrega') return doc.disponible ? '<span class="vk-doc-badge green">Confirmada</span>' : '';
    if(t==='pagare') return '<span class="vk-doc-badge yellow">Documento de tu operación</span>';
    if(t==='comprobantes') return '';
    return '';
  }

  function render(){
    VKApp.render('<div class="vk-h1">Documentos</div><div class="vk-muted"><span class="vk-spin"></span> Cargando...</div>');
    VKApp.api('documentos/lista.php').done(paint);
  }

  function paint(r){
    var docs = r.documentos||[];
    var alCorriente = r.al_corriente;
    var carta = null;
    var otros = [];
    for(var i=0;i<docs.length;i++){
      if(docs[i].tipo==='carta_factura') carta=docs[i];
      else otros.push(docs[i]);
    }

    var meta = DOC_META.carta_factura;

    // --- Carta Factura highlight ---
    var cartaHtml = '';
    if(carta){
      if(carta.disponible){
        cartaHtml =
          '<div class="vk-doc-highlight">'+
            '<div class="vk-doc-hl-header">'+
              '<div class="vk-doc-hl-icon">'+meta.icon+'</div>'+
              '<div class="vk-doc-hl-info">'+
                '<div class="vk-doc-hl-title">Carta factura <span class="vk-doc-badge blue">LISTA PARA DESCARGAR</span></div>'+
                '<div class="vk-doc-hl-sub">Para emplacar tu Voltika</div>'+
                '<div class="vk-doc-hl-desc">'+meta.desc+'</div>'+
                '<div class="vk-doc-hl-tags">'+
                  '<span class="vk-doc-tag">📄 Oficial</span>'+
                  '<span class="vk-doc-tag">✅ Válida para emplacar</span>'+
                '</div>'+
                '<div class="vk-doc-hl-size">'+meta.size+'</div>'+
              '</div>'+
              '<button class="vk-doc-download" data-tipo="carta_factura">DESCARGAR</button>'+
            '</div>'+
          '</div>';
      } else {
        cartaHtml =
          '<div class="vk-doc-highlight locked">'+
            '<div class="vk-doc-hl-header">'+
              '<div class="vk-doc-hl-icon" style="opacity:.5">'+meta.icon+'</div>'+
              '<div class="vk-doc-hl-info">'+
                '<div class="vk-doc-hl-title">Carta factura <span class="vk-doc-badge gray">NO DISPONIBLE</span></div>'+
                '<div class="vk-doc-hl-sub">Para emplacar tu Voltika</div>'+
              '</div>'+
            '</div>'+
          '</div>'+
          '<div class="vk-banner warn" style="margin-top:-6px;margin-bottom:14px">'+
            '<strong>¿Aún no puedes descargarla?</strong><br>'+
            meta.descLocked+'<br>'+
            '<button class="vk-btn primary" style="margin-top:10px;font-size:13px;padding:10px" onclick="VKApp.go(\'inicio\')">PONERME AL CORRIENTE</button>'+
          '</div>';
      }
    }

    // --- Other documents ---
    var otrosHtml = otros.map(function(d){
      var dm = DOC_META[d.tipo]||{};
      return '<div class="vk-doc-row'+(d.disponible?'':' locked')+'">'+
        '<div class="vk-doc-row-icon">'+(dm.icon||'')+'</div>'+
        '<div class="vk-doc-row-body">'+
          '<div class="vk-doc-row-title">'+d.titulo+' '+badgeFor(d)+'</div>'+
          '<div class="vk-doc-row-desc">'+(dm.desc||d.subtitulo)+'</div>'+
          '<div class="vk-doc-row-size">'+(dm.size||'PDF')+'</div>'+
        '</div>'+
        (d.disponible
          ? '<button class="vk-doc-ver" data-tipo="'+d.tipo+'">VER</button>'
          : '<span class="vk-doc-lock">🔒</span>')+
      '</div>';
    }).join('');

    VKApp.render(
      '<div class="vk-h1">Documentos</div>'+
      cartaHtml+

      '<div style="display:flex;justify-content:space-between;align-items:center;margin:18px 0 10px">'+
        '<div class="vk-h2" style="margin:0">Otros documentos de tu compra</div>'+
        '<a class="vk-link" id="vkDownloadAll" style="font-size:12px">Descargar todo</a>'+
      '</div>'+
      otrosHtml+

      '<div class="vk-card" style="margin-top:18px">'+
        '<div class="vk-h2">¿Dudas sobre tus documentos?</div>'+
        '<div class="vk-muted">Nuestro equipo está aquí para ayudarte.</div>'+
        '<button class="vk-btn ghost" style="margin-top:10px" onclick="VKApp.go(\'ayuda\')">CONTACTAR SOPORTE</button>'+
      '</div>'
    );

    $('[data-tipo]').on('click',function(){
      var t=$(this).data('tipo');
      window.open('php/documentos/descargar.php?tipo='+encodeURIComponent(t),'_blank');
    });
    $('#vkDownloadAll').on('click',function(){
      var avail = docs.filter(function(d){return d.disponible;});
      avail.forEach(function(d){
        window.open('php/documentos/descargar.php?tipo='+encodeURIComponent(d.tipo),'_blank');
      });
    });
  }
  return { render:render };
})();
