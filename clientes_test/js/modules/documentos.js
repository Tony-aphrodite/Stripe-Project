window.VK_documentos = (function(){

  // ── Document metadata for CREDIT customers ──────────────────────
  var DOC_META_CREDITO = {
    contrato: {
      icon: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#3b82f6" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>',
      desc: 'Tu contrato con Voltika, firmado de forma electronica.',
      size: 'PDF - 2.4 MB'
    },
    acta_entrega: {
      icon: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#22c55e" stroke-width="2"><path d="M9 12l2 2 4-4"/><rect x="3" y="3" width="18" height="18" rx="3"/></svg>',
      desc: 'Confirmacion de que recibiste tu Voltika en perfecto estado.',
      size: 'PDF - 1.1 MB'
    },
    comprobantes: {
      icon: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#8b5cf6" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>',
      desc: 'Historial completo de los pagos que has realizado.',
      size: 'PDF - 800 KB'
    },
    manual: {
      icon: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>',
      desc: 'Guia digital para el cuidado y uso correcto de tu Voltika.',
      size: 'PDF'
    },
    seguro: {
      icon: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
      desc: 'Cotizacion y poliza de seguro de tu Voltika.',
      size: 'PDF / IMG'
    },
    carta_factura: {
      icon: '<svg viewBox="0 0 24 24" width="32" height="32" fill="none"><rect x="3" y="2" width="18" height="20" rx="2" fill="#fff3cd" stroke="#f59e0b" stroke-width="1.5"/><path d="M7 7h10M7 11h10M7 15h6" stroke="#f59e0b" stroke-width="1.5" stroke-linecap="round"/><circle cx="17" cy="17" r="5" fill="#22c55e"/><path d="M15 17l1.5 1.5 3-3" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      desc: 'Tu cuenta esta al corriente. Puedes descargarla y usarla para realizar tu tramite de placas.',
      descLocked: 'Para activar tu carta factura, tu compra debe estar al corriente. Ponte al corriente y descargala al instante.',
      size: 'PDF - 1.2 MB'
    }
  };

  // ── Document metadata for CONTADO/MSI customers ──────────────────
  // Customer feedback 2026-04-23:
  //   - add Recibos (Stripe hosted receipt)
  //   - remove Contrato de compraventa for now (legal text still in review)
  //   - Manual del usuario → web page instead of PDF download
  var DOC_META_CONTADO = {
    confirmacion: {
      icon: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#3b82f6" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>',
      label: 'Confirmacion de compra',
      desc: 'Resumen de tu compra y detalles del pedido.',
      size: 'PDF - 650 KB',
      badge: 'DISPONIBLE',
      badgeColor: 'green',
      available: true
    },
    recibo: {
      icon: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#10b981" stroke-width="2"><path d="M20 12V8.5a2.5 2.5 0 00-2.5-2.5h-11A2.5 2.5 0 004 8.5v11A2.5 2.5 0 006.5 22h11a2.5 2.5 0 002.5-2.5V12z"/><path d="M8 10h8M8 14h5"/><circle cx="17" cy="17" r="3" fill="#10b981" stroke="none"/><path d="M15.5 17l1 1 2-2" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      label: 'Recibo de pago',
      desc: 'Recibo oficial emitido por Stripe.',
      size: 'Web',
      badge: 'DISPONIBLE',
      badgeColor: 'green',
      available: true
    },
    factura: {
      icon: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#8b5cf6" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>',
      label: 'Factura (CFDI)',
      desc: 'Comprobante fiscal de tu compra.',
      size: 'PDF - 1.0 MB',
      badge: 'DISPONIBLE DESPUES DE ENTREGA',
      badgeColor: 'yellow',
      available: false
    },
    carta_factura: {
      icon: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none"><rect x="3" y="2" width="18" height="20" rx="2" fill="#fff3cd" stroke="#f59e0b" stroke-width="1.5"/><path d="M7 7h10M7 11h10M7 15h6" stroke="#f59e0b" stroke-width="1.5" stroke-linecap="round"/><circle cx="17" cy="17" r="5" fill="#22c55e"/><path d="M15 17l1.5 1.5 3-3" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      label: 'Carta factura',
      desc: 'Documento valido para emplacar tu Voltika.',
      size: 'PDF - 1.3 MB',
      badge: 'DISPONIBLE DESPUES DE ENTREGA',
      badgeColor: 'yellow',
      available: false
    },
    // contrato_cv intentionally retained below but NOT included in `keys`
    // — hidden from the UI per customer brief 2026-04-23. Re-enable by
    // adding 'contrato_cv' back to the keys array.
    contrato_cv: {
      icon: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#22c55e" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/><path d="M9 12l2 2 4-4"/></svg>',
      label: 'Contrato de compraventa',
      desc: 'Documento que acredita la compraventa.',
      size: 'PDF - 700 KB',
      badge: 'DISPONIBLE',
      badgeColor: 'green',
      available: true
    },
    manual: {
      icon: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#3b82f6" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/><path d="M8 7h8M8 11h8M8 15h5"/></svg>',
      label: 'Manual del usuario',
      desc: 'Guia digital interactiva en voltika.mx.',
      size: 'Web',
      badge: 'DISPONIBLE',
      badgeColor: 'green',
      available: true,
      // External URL opened in a new tab instead of downloading a PDF.
      // Appends ?modelo=<slug> so voltika.mx can redirect to the specific
      // model's manual page if it has per-model routing; a generic landing
      // page works too.
      isExternal: true,
      url: 'https://voltika.mx/manual/'
    }
  };

  function badgeFor(doc){
    var t = doc.tipo;
    if(t==='contrato')     return doc.disponible ? '<span class="vk-doc-badge green">Firmado digitalmente</span>' : '<span class="vk-doc-badge gray">Pendiente</span>';
    if(t==='acta_entrega') return doc.disponible ? '<span class="vk-doc-badge green">Confirmada</span>'           : '<span class="vk-doc-badge gray">Pendiente</span>';
    if(t==='manual')       return doc.disponible ? '<span class="vk-doc-badge green">Disponible</span>'           : '<span class="vk-doc-badge gray">Pendiente</span>';
    if(t==='seguro')       return doc.disponible ? '<span class="vk-doc-badge green">Disponible</span>'           : '<span class="vk-doc-badge gray">Pendiente</span>';
    if(t==='comprobantes') return '';
    return '';
  }

  // ── Main router ─────────────────────────────────────────────────
  function render(){
    var tipo = VKApp.state.tipoPortal;
    if(tipo === 'contado' || tipo === 'msi'){
      renderContado();
    } else {
      renderCredito();
    }
  }

  // ================================================================
  //  CONTADO/MSI Documents page
  // ================================================================
  function renderContado(){
    var entrega = (VKApp.state.estado||{}).entrega || {};
    var entregado = entrega.etiqueta === 'listo' || entrega.estado_db === 'entregada';

    var html = '';

    // Header
    html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">';
    html += '<div class="vk-h1" style="margin:0">Mis documentos</div>';
    html += '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#3b82f6" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>';
    html += '</div>';
    html += '<div class="vk-muted" style="margin-bottom:16px">Consulta y descarga los documentos de tu compra Voltika en cualquier momento.</div>';

    // Info banner
    html += '<div class="vk-doc-info-banner">';
    html += '<span style="color:#3b82f6;font-size:16px;flex-shrink:0">&#9432;</span>';
    html += '<span style="font-size:13px;color:#333">Todos tus documentos son digitales y tienen validez legal en Mexico.</span>';
    html += '</div>';

    // Status card
    if(entregado){
      html += '<div class="vk-doc-status-card ok">';
      html += '<div class="vk-doc-status-icon"><svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="#22c55e" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg></div>';
      html += '<div><div style="font-weight:700;color:#333">Todo en orden <span class="vk-doc-badge green">DOCUMENTOS DISPONIBLES</span></div>';
      html += '<div style="font-size:12px;color:#555;margin-top:2px">Estos documentos ya estan listos para ti. Descargalos cuando los necesites.<br>Gracias por tu compra.</div></div>';
      html += '</div>';
    } else {
      html += '<div class="vk-doc-status-card pending">';
      html += '<div class="vk-doc-status-icon"><svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="#f59e0b" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></div>';
      html += '<div><div style="font-weight:700;color:#333">En proceso <span class="vk-doc-badge yellow">PREPARANDO DOCUMENTOS</span></div>';
      html += '<div style="font-size:12px;color:#555;margin-top:2px">Algunos documentos estaran disponibles despues de la entrega de tu Voltika.</div></div>';
      html += '</div>';
    }

    // Document list header
    html += '<div style="display:flex;justify-content:space-between;align-items:center;margin:20px 0 10px">';
    html += '<div style="font-size:15px;font-weight:700;color:#333">Documentos de tu compra</div>';
    html += '<a class="vk-link" id="vkDownloadAll" style="font-size:12px;cursor:pointer">Descargar todo &#128229;</a>';
    html += '</div>';

    // Document rows — visible order per customer brief 2026-04-23:
    //   confirmacion → recibo → factura → carta_factura → manual
    // Contrato de compraventa is temporarily hidden (still defined in
    // DOC_META_CONTADO for easy re-enable).
    var docs = DOC_META_CONTADO;
    var keys = ['confirmacion','recibo','factura','carta_factura','manual'];
    for(var i=0;i<keys.length;i++){
      var k = keys[i];
      var d = docs[k];
      var isAvail = d.available || entregado;
      var badge = isAvail ? 'DISPONIBLE' : d.badge;
      var badgeCol = isAvail ? 'green' : d.badgeColor;

      html += '<div class="vk-cdoc-row'+(isAvail?'':' locked')+'">';
      html += '<div class="vk-cdoc-icon">'+d.icon+'</div>';
      html += '<div class="vk-cdoc-body">';
      html += '<div class="vk-cdoc-title">'+d.label+' <span class="vk-doc-badge '+badgeCol+'">'+badge+'</span></div>';
      html += '<div class="vk-cdoc-desc">'+d.desc+'</div>';
      html += '</div>';
      html += '<div class="vk-cdoc-right">';
      html += '<div class="vk-cdoc-size">'+d.size+'</div>';
      if(isAvail){
        html += '<button class="vk-cdoc-dl" data-tipo="'+k+'">&#128229;</button>';
      } else {
        html += '<span class="vk-cdoc-lock">&#128274;</span>';
      }
      html += '</div>';
      html += '</div>';
    }

    // Footer warning
    html += '<div class="vk-doc-info-banner" style="margin-top:16px">';
    html += '<span style="color:#f59e0b;font-size:16px;flex-shrink:0">&#9888;</span>';
    html += '<span style="font-size:12px;color:#555">Algunos documentos estaran disponibles despues de la entrega.<br>Te notificaremos cuando esten listos.</span>';
    html += '</div>';

    // Security footer
    html += '<div class="vk-doc-security">';
    html += '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#22c55e" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>';
    html += '<div><div style="font-weight:700;font-size:13px;color:#333">Compra segura, documentos siempre contigo.</div>';
    html += '<div style="font-size:11px;color:#555">Voltika cumple con todos los requisitos fiscales y legales en Mexico.</div></div>';
    html += '</div>';

    VKApp.render(html);

    function _openDoc(t){
      var meta = DOC_META_CONTADO[t];
      // External docs (manual) → open the web page directly; do NOT hit
      // descargar.php. A modelo slug is appended when available so the
      // destination can route to the right model manual.
      if (meta && meta.isExternal && meta.url) {
        var sluggedModelo = (VKApp.state && VKApp.state.modelo)
          ? String(VKApp.state.modelo).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
          : '';
        window.open(meta.url + (sluggedModelo || ''), '_blank');
        return;
      }
      var sc = VKApp.state.activeCompra;
      var ext = (sc && sc.tipo && sc.id) ? '&compra_tipo='+encodeURIComponent(sc.tipo)+'&compra_id='+encodeURIComponent(sc.id) : '';
      window.open('php/documentos/descargar.php?tipo='+encodeURIComponent(t)+ext,'_blank');
    }

    $('[data-tipo]').on('click', function(){ _openDoc($(this).data('tipo')); });
    $('#vkDownloadAll').on('click',function(){
      var avail = keys.filter(function(k){ return DOC_META_CONTADO[k].available || entregado; });
      avail.forEach(_openDoc);
    });
  }

  // ================================================================
  //  CREDIT Documents page (existing)
  // ================================================================
  function _scopeQS(){
    var a = VKApp.state.activeCompra;
    if (a && a.tipo && a.id) return '?compra_tipo=' + encodeURIComponent(a.tipo) + '&compra_id=' + encodeURIComponent(a.id);
    return '';
  }

  function renderCredito(){
    VKApp.render('<div class="vk-h1">Documentos</div><div class="vk-muted"><span class="vk-spin"></span> Cargando...</div>');
    VKApp.api('documentos/lista.php' + _scopeQS()).done(paintCredito);
  }

  function paintCredito(r){
    var docs = r.documentos||[];
    var alCorriente = r.al_corriente;
    var carta = null;
    var otros = [];
    for(var i=0;i<docs.length;i++){
      if(docs[i].tipo==='carta_factura') carta=docs[i];
      else otros.push(docs[i]);
    }

    var meta = DOC_META_CREDITO.carta_factura;

    var cartaHtml = '';
    if(carta){
      if(carta.disponible){
        cartaHtml =
          '<div class="vk-doc-highlight">'+
            '<div class="vk-doc-hl-header">'+
              '<div class="vk-doc-hl-icon">'+meta.icon+'</div>'+
              '<div class="vk-doc-hl-info">'+
                '<div class="vk-doc-hl-title">Carta factura <span class="vk-doc-badge green">LISTA PARA DESCARGAR</span></div>'+
                '<div class="vk-doc-hl-sub">Para emplacar tu Voltika</div>'+
                '<div class="vk-doc-hl-desc">'+meta.desc+'</div>'+
                '<div class="vk-doc-hl-tags">'+
                  '<span class="vk-doc-tag">Oficial</span>'+
                  '<span class="vk-doc-tag">Valida para emplacar</span>'+
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
          '<div class="vk-doc-warn-banner">'+
            '<div class="vk-doc-warn-text">'+
              '<strong>¿Aun no puedes descargarla?</strong><br>'+
              '<span>'+meta.descLocked+'</span>'+
            '</div>'+
            '<button class="vk-doc-warn-btn" onclick="VKApp.go(\'inicio\')">PONERME AL CORRIENTE</button>'+
          '</div>';
      }
    }

    var otrosHtml = otros.map(function(d){
      var dm = DOC_META_CREDITO[d.tipo]||{};
      return '<div class="vk-doc-row'+(d.disponible?'':' locked')+'">'+
        '<div class="vk-doc-row-icon">'+(dm.icon||'')+'</div>'+
        '<div class="vk-doc-row-body">'+
          '<div class="vk-doc-row-title">'+d.titulo+' '+badgeFor(d)+'</div>'+
          '<div class="vk-doc-row-desc">'+(dm.desc||d.subtitulo)+'</div>'+
        '</div>'+
        '<div class="vk-doc-row-right">'+
          '<div class="vk-doc-row-size">'+(dm.size||'PDF')+'</div>'+
          (d.disponible
            ? '<button class="vk-doc-ver" data-tipo="'+d.tipo+'">VER</button>'
            : '<span class="vk-doc-lock">&#128274;</span>')+
        '</div>'+
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
      '<div class="vk-doc-help">'+
        '<div class="vk-doc-help-text">'+
          '<div class="vk-h2" style="margin:0 0 4px">¿Dudas sobre tus documentos?</div>'+
          '<div class="vk-muted">Nuestro equipo esta aqui para ayudarte.</div>'+
        '</div>'+
        '<button class="vk-doc-help-btn" onclick="VKApp.go(\'ayuda\')">CONTACTAR SOPORTE &rsaquo;</button>'+
      '</div>'
    );

    $('[data-tipo]').on('click',function(){
      var t=$(this).data('tipo');
      var sc = VKApp.state.activeCompra;
      var ext = (sc && sc.tipo && sc.id) ? '&compra_tipo='+encodeURIComponent(sc.tipo)+'&compra_id='+encodeURIComponent(sc.id) : '';
      window.open('php/documentos/descargar.php?tipo='+encodeURIComponent(t)+ext,'_blank');
    });
    $('#vkDownloadAll').on('click',function(){
      var avail = docs.filter(function(d){return d.disponible;});
      avail.forEach(function(d){
        var sc = VKApp.state.activeCompra;
        var ext = (sc && sc.tipo && sc.id) ? '&compra_tipo='+encodeURIComponent(sc.tipo)+'&compra_id='+encodeURIComponent(sc.id) : '';
        window.open('php/documentos/descargar.php?tipo='+encodeURIComponent(d.tipo)+ext,'_blank');
      });
    });
  }
  return { render:render };
})();
