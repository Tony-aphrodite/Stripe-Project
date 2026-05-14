window.PV_recepcion = (function(){
  // Customer brief 2026-05-12 (Óscar, 6th round — screenshot 2: Recepción
  // sidebar entry): the screen used to show ONLY pending arrivals. The
  // operator now needs visibility of past receptions too (checklist, who
  // received, photos, seal numbers). We split the screen into two tabs:
  // "Pendientes" (the legacy view) and "Historial" (new).
  var _tab = 'pendientes';
  var _historyFilter = '';

  function render(){
    if (_tab === 'historial') return renderHistorial();
    PVApp.render(tabsBar() + '<div class="ad-h1">Recepción de motos</div><div><span class="ad-spin"></span></div>');
    bindTabs();
    PVApp.api('recepcion/envios-pendientes.php').done(function(r){ paint(r); bindTabs(); });
  }

  function tabsBar(){
    var pCls = _tab === 'pendientes' ? 'primary' : 'ghost';
    var hCls = _tab === 'historial'  ? 'primary' : 'ghost';
    return '<div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap;">'+
      '<button class="ad-btn '+pCls+' sm pvRTab" data-tab="pendientes">📦 Pendientes</button>'+
      '<button class="ad-btn '+hCls+' sm pvRTab" data-tab="historial">🗂️ Historial</button>'+
    '</div>';
  }

  function bindTabs(){
    $('.pvRTab').off('click').on('click', function(){
      _tab = $(this).data('tab');
      render();
    });
  }
  // Bug 3.1 (customer brief 2026-05-08): the raw enum values
  // ('lista_para_enviar', 'enviada') are not human-friendly. Translate to
  // a label + color so the operator instantly understands the status.
  // Bug 3.4: also handle the synthetic 'pendiente_asignacion' state from
  // envios-pendientes.php for motos that CEDIS hasn't shipped yet.
  function statusBadge(estado){
    var map = {
      'pendiente_asignacion': { label:'Pendiente de asignación', cls:'gray', help:'CEDIS aún no inicia el envío.' },
      'lista_para_enviar':    { label:'Por enviar',              cls:'blue', help:'CEDIS ya creó el envío. Aún no sale.' },
      'enviada':              { label:'En tránsito',             cls:'yellow', help:'La moto va camino al punto.' },
      'en_transito':          { label:'En tránsito',             cls:'yellow', help:'La moto va camino al punto.' }
    };
    var s = map[estado] || { label: estado || 'Sin estado', cls:'gray', help:'' };
    return '<span class="ad-badge '+s.cls+'" title="'+(s.help||'')+'">'+s.label+'</span>';
  }

  function fmtDate(d){
    if(!d) return '—';
    // Accept 'YYYY-MM-DD' or 'YYYY-MM-DD HH:MM:SS' — chop the time off for display.
    return String(d).slice(0,10);
  }

  function paint(r){
    var html = tabsBar();
    html += '<div class="ad-h1">Recepción de motos</div>';
    html += '<div style="color:var(--ad-dim);margin-bottom:14px">Motos enviadas desde CEDIS esperando recepción</div>';
    if((r.envios||[]).length===0) html += '<div class="ad-card">No hay envíos pendientes</div>';
    (r.envios||[]).forEach(function(e){
      var origenOk = e.origen_certificado == 1 || e.origen_certificado === '1' || e.origen_certificado === true;
      var origenBadge = origenOk
        ? '<span class="ad-badge green" title="Checklist de Origen completado en CEDIS">Origen certificado ✓</span>'
        : '<span class="ad-badge gray" title="Checklist de Origen aún no certificado">Origen pendiente</span>';

      // Bug 3.2 (customer brief 2026-05-08): show shipment metadata.
      var infoRows = '';
      if (e.tracking_number) infoRows += '<div>Tracking: <code>'+e.tracking_number+'</code></div>';
      if (e.carrier)         infoRows += '<div>Paquetería: <strong>'+e.carrier+'</strong></div>';
      if (e.fecha_envio)     infoRows += '<div>Enviada: <strong>'+fmtDate(e.fecha_envio)+'</strong></div>';
      if (e.fecha_estimada_llegada) infoRows += '<div>ETA: <strong>'+fmtDate(e.fecha_estimada_llegada)+'</strong></div>';

      var pending = e.estado === 'pendiente_asignacion';
      // For pending_asignacion rows there is no envio_id so the "Recibir"
      // button must be disabled — they're listed for visibility only.
      var btn = pending
        ? '<button class="ad-btn ghost sm" disabled title="Aún no hay envío creado por CEDIS" style="margin-top:8px;opacity:.6;">Esperando envío de CEDIS</button>'
        : '<button class="ad-btn primary sm pvReceive" data-env="'+e.id+'" data-moto="'+e.moto_id+'" data-vin="'+e.vin+'" style="margin-top:8px">'+
            '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg> Recibir moto'+
          '</button>';

      html += '<div class="ad-card">'+
        (e.pedido_num ? '<div style="font-size:12px;font-weight:700;color:var(--ad-primary,#039fe1);margin-bottom:4px;">'+e.pedido_num+'</div>' : '')+
        '<div style="font-weight:700">'+(e.modelo||'')+' · '+(e.color||'')+'</div>'+
        '<div style="font-size:12px;color:var(--ad-dim)">VIN esperado: '+(e.vin_display||e.vin||'—')+'</div>'+
        (e.cliente_nombre ? '<div style="font-size:12px;margin-top:4px;">Cliente: <strong>'+e.cliente_nombre+'</strong></div>' : '')+
        '<div style="font-size:11px;margin-top:6px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;">'+
          statusBadge(e.estado)+
          ' '+origenBadge+
        '</div>'+
        (infoRows ? '<div style="font-size:11.5px;color:var(--ad-dim);margin-top:6px;line-height:1.5;">'+infoRows+'</div>' : '')+
        btn+
      '</div>';
    });
    PVApp.render(html);
    bindTabs();
    $('.pvReceive').on('click', function(){
      showReceiveForm($(this).data('env'), $(this).data('moto'), $(this).data('vin'));
    });
  }

  // ── Historial de recepciones (Bug 6.2) ───────────────────────────────
  // Loads recepcion_punto rows for this punto with full checklist info,
  // who received, photos, seal numbers, vin-caja match status. Each row
  // is rendered as a collapsible card; clicking it expands the full
  // checklist detail with photo thumbnails.
  function renderHistorial(){
    var html = tabsBar();
    html += '<div class="ad-h1">Historial de recepciones</div>';
    html += '<div style="color:var(--ad-dim);margin-bottom:10px;">Todas las motos recibidas en este punto, con checklist completo y fotos.</div>';
    html += '<div style="display:flex;gap:6px;margin-bottom:12px;">'+
      '<input id="pvHistSearch" class="ad-input" placeholder="Buscar por VIN, modelo, pedido o cliente" '+
        'value="'+ (_historyFilter||'').replace(/"/g,'&quot;') +'" style="flex:1;">'+
      '<button class="ad-btn primary sm" id="pvHistSearchBtn">Buscar</button>'+
      (_historyFilter ? '<button class="ad-btn ghost sm" id="pvHistClear">Limpiar</button>' : '')+
    '</div>';
    html += '<div id="pvHistList"><div><span class="ad-spin"></span> Cargando historial…</div></div>';
    PVApp.render(html);
    bindTabs();
    $('#pvHistSearchBtn').on('click', function(){
      _historyFilter = ($('#pvHistSearch').val()||'').trim();
      renderHistorial();
    });
    $('#pvHistSearch').on('keydown', function(e){ if(e.which===13){ $('#pvHistSearchBtn').click(); } });
    $('#pvHistClear').on('click', function(){ _historyFilter=''; renderHistorial(); });

    var url = 'recepcion/historial.php' + (_historyFilter ? '?q='+encodeURIComponent(_historyFilter) : '');
    PVApp.api(url).done(paintHistorial).fail(function(x){
      $('#pvHistList').html('<div class="ad-card" style="color:#b91c1c;">Error al cargar historial: '+
        ((x.responseJSON&&x.responseJSON.error)||'conexión')+'</div>');
    });
  }

  function paintHistorial(r){
    var rows = (r && r.recepciones) || [];
    if (rows.length === 0) {
      $('#pvHistList').html('<div class="ad-card" style="color:var(--ad-dim);">No hay recepciones registradas'+
        (_historyFilter ? ' para "'+_historyFilter+'".' : '.') +'</div>');
      return;
    }
    var html = '<div style="font-size:12px;color:var(--ad-dim);margin-bottom:8px;">'+
      rows.length+' recepción' + (rows.length===1?'':'es') +
      (_historyFilter ? ' que coinciden con "'+_historyFilter+'"' : '') + '</div>';
    rows.forEach(function(row, idx){ html += histCard(row, idx); });
    $('#pvHistList').html(html);
    $('.pvHistToggle').on('click', function(){
      var $card = $(this).closest('.pv-hist-card');
      $card.find('.pv-hist-detail').slideToggle(160);
      var $ic = $(this).find('.pv-hist-caret');
      $ic.text($ic.text()==='▾' ? '▴' : '▾');
    });
    // Round 30: photo lightbox. Stop propagation so the chip click doesn't
    // also re-toggle the card (would feel broken on mobile).
    $('.pvShowFoto').off('click.pvFoto').on('click.pvFoto', function(ev){
      ev.preventDefault();
      ev.stopPropagation();
      var url   = $(this).data('url')   || '';
      var label = $(this).data('label') || 'Foto';
      showFotoLightbox(String(url), String(label));
    });
  }

  // Round 30: simple modal lightbox. Renders the image inline and surfaces
  // any load failure (broken URL / 404 / forbidden directory) as a visible
  // error message so the operator knows the photo is missing instead of
  // staring at a dead button.
  function showFotoLightbox(url, label){
    if (!url) return;
    // Build overlay
    var $overlay = $(
      '<div class="pv-foto-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.78);'+
        'z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;">'+
        '<div style="position:relative;max-width:96vw;max-height:96vh;background:#0f172a;'+
          'border-radius:10px;padding:14px;box-shadow:0 10px 40px rgba(0,0,0,.5);">'+
          '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:8px;">'+
            '<div style="color:#fff;font-size:13px;font-weight:700;">📷 '+escapeHtml(label)+'</div>'+
            '<button type="button" class="pv-foto-close" '+
              'style="background:#1e293b;color:#fff;border:0;border-radius:6px;cursor:pointer;'+
                     'padding:4px 10px;font-size:14px;">✕</button>'+
          '</div>'+
          '<div class="pv-foto-body" style="background:#020617;border-radius:6px;min-height:140px;'+
                  'display:flex;align-items:center;justify-content:center;">'+
            '<img src="'+url.replace(/"/g,'&quot;')+'" alt="'+escapeHtml(label)+'" '+
                 'style="display:block;max-width:100%;max-height:80vh;border-radius:6px;" '+
                 'class="pv-foto-img">'+
          '</div>'+
          '<div style="margin-top:8px;display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;">'+
            '<a href="'+url.replace(/"/g,'&quot;')+'" target="_blank" rel="noopener" '+
               'style="color:#7dd3fc;font-size:12px;text-decoration:none;">Abrir en pestaña nueva ↗</a>'+
          '</div>'+
        '</div>'+
      '</div>'
    );
    $('body').append($overlay);
    // Round 30 v5 (2026-05-14, Óscar): present a friendly, actionable
    // message to the operator instead of raw HTTP debug info. The
    // technical diagnostic is still captured (via parallel fetch) but
    // hidden behind a "Detalles técnicos" toggle so support can read it
    // when the operator escalates a case, while normal operators only
    // see a polite explanation + next steps.
    $overlay.find('.pv-foto-img').on('error', function(){
      var $body = $overlay.find('.pv-foto-body');
      // Tipo de foto (label, e.g. "Sello", "VIN etiqueta", "Unidad")
      // helps the operator know WHICH photo to re-upload.
      var labelText = label || 'esta foto';
      $body.html(
        '<div style="padding:28px 24px;color:#f1f5f9;font-size:13.5px;line-height:1.65;">'+
          // Headline
          '<div style="text-align:center;font-size:15px;font-weight:700;color:#fde68a;margin-bottom:8px;">'+
            '📷 La foto de <em>'+escapeHtml(labelText)+'</em> no está disponible'+
          '</div>'+
          // Body — empathetic explanation + actionable next steps.
          '<p style="margin:14px 0 8px;color:#e2e8f0;">'+
            'No pudimos mostrar esta foto. Es probable que la imagen original no se haya guardado correctamente al recibir esta moto (un problema técnico anterior afectaba la subida de fotos).'+
          '</p>'+
          '<div style="background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.20);border-radius:8px;padding:12px 14px;margin:14px 0;">'+
            '<div style="font-weight:700;color:#86efac;margin-bottom:6px;">¿Cómo lo arreglo?</div>'+
            '<ol style="margin:0;padding-left:20px;color:#e2e8f0;line-height:1.7;">'+
              '<li>Acércate a la moto y toma una nueva foto de <strong>'+escapeHtml(labelText)+'</strong>.</li>'+
              '<li>Abre la pantalla de <strong>Recepción</strong> y busca este registro en el historial.</li>'+
              '<li>Vuelve a subir la foto desde ahí. La nueva imagen reemplazará el archivo faltante.</li>'+
            '</ol>'+
          '</div>'+
          '<p style="margin:14px 0 0;color:#94a3b8;font-size:12px;text-align:center;">'+
            'Si el problema persiste después de subir una foto nueva, contacta a soporte y comparte los detalles técnicos de abajo.'+
          '</p>'+
          // Collapsible technical block — useful for support tickets but
          // doesn't clutter the operator's view.
          '<details style="margin-top:18px;background:#0b1322;border-radius:6px;padding:8px 10px;">'+
            '<summary style="cursor:pointer;color:#94a3b8;font-size:11.5px;outline:none;">Detalles técnicos (para soporte)</summary>'+
            '<div class="pv-foto-tech" style="margin-top:10px;color:#94a3b8;font-size:11px;font-family:ui-monospace,monospace;">'+
              '<span style="color:#64748b;">Consultando servidor…</span>'+
            '</div>'+
          '</details>'+
        '</div>'
      );
      // Parallel fetch to populate the technical block. Failure here is
      // harmless — the friendly message already explains the situation.
      $.ajax({
        url: url,
        method: 'GET',
        dataType: 'text',
        cache: false,
      }).always(function(data, textStatus, xhrOrErr){
        var xhr = (xhrOrErr && xhrOrErr.status) ? xhrOrErr : data;
        var status = xhr && xhr.status ? xhr.status : '???';
        var body   = (xhr && xhr.responseText) || (typeof data === 'string' ? data : '') || '';
        var trimmed = String(body).substring(0, 500);
        $overlay.find('.pv-foto-tech').html(
          '<div><span style="color:#64748b;">HTTP status:</span> <strong style="color:#f1f5f9;">'+escapeHtml(String(status))+'</strong></div>'+
          '<div style="margin-top:6px;color:#64748b;">Respuesta:</div>'+
          '<pre style="white-space:pre-wrap;word-break:break-all;margin:4px 0 0;color:#cbd5e1;font-size:10.5px;">'+escapeHtml(trimmed || '(vacía)')+'</pre>'+
          '<div style="margin-top:8px;color:#64748b;font-size:10.5px;">URL: '+escapeHtml(url)+'</div>'
        );
      });
    });
    // Close: button, click on overlay backdrop, or Esc key
    var close = function(){ $overlay.remove(); $(document).off('keydown.pvFoto'); };
    $overlay.find('.pv-foto-close').on('click', close);
    $overlay.on('click', function(ev){ if (ev.target === this) close(); });
    $(document).on('keydown.pvFoto', function(ev){ if (ev.key === 'Escape') close(); });
  }

  function checkRow(label, val){
    var ok = val==1 || val===1 || val==='1' || val===true;
    var icon = ok ? '<span style="color:#059669;">✓</span>' : '<span style="color:#b91c1c;">✗</span>';
    return '<div style="font-size:12.5px;padding:3px 0;">'+icon+' '+label+'</div>';
  }

  function vinCajaStatus(row){
    // 1 = match, 0 = mismatch (confirmed when mismatch_confirmed=1), null = blank
    if (row.vin_caja_coincide === null || typeof row.vin_caja_coincide === 'undefined') {
      return '<span class="ad-badge gray" title="VIN de caja no capturado">VIN caja: —</span>';
    }
    var coincide = row.vin_caja_coincide==1 || row.vin_caja_coincide===1 || row.vin_caja_coincide==='1';
    if (coincide) {
      return '<span class="ad-badge green" title="VIN en caja coincide con chasis">VIN caja ✓</span>';
    }
    var confirmed = row.vin_mismatch_confirmed==1 || row.vin_mismatch_confirmed==='1';
    return '<span class="ad-badge red" title="'+(confirmed?'Discrepancia confirmada por operador':'Discrepancia NO confirmada')+
           '">VIN caja ✗'+(confirmed?' (confirmado)':'')+'</span>';
  }

  function histCard(row, idx){
    var photoChips = '';
    // Round 30 (2026-05-14, Óscar): the previous chips were <a target=_blank>
    // links pointing to /configurador/php/uploads/recepcion/<file>. Some
    // installs blocked that path with .htaccess and the click silently did
    // nothing. Switched to an inline lightbox modal — same URL, but loaded
    // via <img> so a 403/404 surfaces as a visible error instead of a
    // dead-end click.
    function chip(url, label){
      if (!url) return '';
      // Encode URL for safe inclusion in data attribute (avoid quote breakage).
      var safe = String(url).replace(/"/g, '&quot;');
      return '<button type="button" class="ad-badge blue pvShowFoto" '+
        'data-url="'+safe+'" data-label="'+escapeHtml(label)+'" '+
        'style="text-decoration:none;margin-right:4px;display:inline-block;border:0;cursor:pointer;font:inherit;">📷 '+label+'</button>';
    }
    photoChips += chip(row.foto_sello_url,     'Sello');
    photoChips += chip(row.foto_vin_label_url, 'VIN etiqueta');
    photoChips += chip(row.foto_unidad_url,    'Unidad');
    (row.fotos_extra||[]).forEach(function(p,i){
      if (typeof p === 'string' && p) photoChips += chip(p, 'Extra '+(i+1));
    });

    var completado = row.completado==1 || row.completado===1 || row.completado==='1';
    var stateBadge = completado
      ? '<span class="ad-badge green">Recepción OK</span>'
      : '<span class="ad-badge yellow">Retenida</span>';

    var head = '<div class="pvHistToggle" style="cursor:pointer;padding:6px 0;">'+
      '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">'+
        '<div>'+
          (row.pedido_num ? '<div style="font-size:11.5px;font-weight:700;color:var(--ad-primary,#039fe1);">'+row.pedido_num+'</div>' : '')+
          '<div style="font-weight:700;">'+(row.modelo||'—')+' · '+(row.color||'—')+'</div>'+
          '<div style="font-size:12px;color:var(--ad-dim);">VIN: <code>'+(row.vin_display||row.vin||'—')+'</code></div>'+
        '</div>'+
        '<div style="text-align:right;">'+
          stateBadge+
          '<div style="font-size:11px;color:var(--ad-dim);margin-top:2px;">'+fmtDate(row.fecha_recepcion)+'</div>'+
          '<div style="font-size:11px;color:#374151;">Recibió: <strong>'+(row.recibido_por_nombre||'—')+'</strong></div>'+
        '</div>'+
      '</div>'+
      '<div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:6px;align-items:center;">'+
        vinCajaStatus(row)+
        (row.sello_numero ? ' <span class="ad-badge blue" title="Número del sello aplicado">Sello: '+row.sello_numero+'</span>' : '')+
        ' <span class="pv-hist-caret" style="margin-left:auto;font-size:14px;color:var(--ad-dim);">▾</span>'+
      '</div>'+
    '</div>';

    var detail =
      '<div class="pv-hist-detail" style="display:none;border-top:1px solid #eee;margin-top:8px;padding-top:8px;">'+
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 16px;">'+
          checkRow('Estado físico OK',     row.estado_fisico_ok)+
          checkRow('Sin daños visibles',   row.sin_danos)+
          checkRow('Componentes completos',row.componentes_completos)+
          checkRow('Batería OK',           row.bateria_ok)+
          checkRow('Sello aplicado e intacto', row.sello_intacto)+
          checkRow('VIN escaneado coincide',   row.vin_coincide)+
        '</div>'+

        '<div style="margin-top:10px;font-size:12px;">'+
          '<div><span style="color:var(--ad-dim);">VIN escaneado:</span> <code>'+(row.vin_escaneado||'—')+'</code></div>'+
          (row.vin_caja ? '<div><span style="color:var(--ad-dim);">VIN en caja:</span> <code>'+row.vin_caja+'</code></div>' : '')+
          (row.sello_numero ? '<div><span style="color:var(--ad-dim);">Número de sello:</span> '+row.sello_numero+'</div>' : '')+
          (row.tracking_number ? '<div><span style="color:var(--ad-dim);">Tracking:</span> <code>'+row.tracking_number+'</code>'+(row.carrier?' · '+row.carrier:'')+'</div>' : '')+
          (row.cliente_nombre ? '<div><span style="color:var(--ad-dim);">Cliente:</span> <strong>'+row.cliente_nombre+'</strong></div>' : '')+
        '</div>'+

        (photoChips ? '<div style="margin-top:10px;"><div style="font-size:11px;color:var(--ad-dim);margin-bottom:4px;">Fotos</div>'+photoChips+'</div>' : '')+

        (row.observaciones ? '<div style="margin-top:10px;padding:8px 10px;background:#f9fafb;border-radius:6px;font-size:12.5px;"><strong>Observaciones:</strong><br>'+escapeHtml(row.observaciones)+'</div>' : '')+
        (row.notas ? '<div style="margin-top:6px;padding:8px 10px;background:#f9fafb;border-radius:6px;font-size:12.5px;"><strong>Notas:</strong><br>'+escapeHtml(row.notas)+'</div>' : '')+
      '</div>';

    return '<div class="ad-card pv-hist-card">'+head+detail+'</div>';
  }

  function escapeHtml(s){
    return String(s||'').replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function showReceiveForm(envioId, motoId, vinEsperado){
    // Bug 3.3 (customer brief 2026-05-08): the reception checklist must be
    // explicit and capture seal info + 3 photos + observations + dates.
    // Photo inputs use 2-button pattern (camera + file) so the operator on
    // mobile gets a clear choice. All NEW fields are OPTIONAL on save —
    // we never block the legacy reception flow.
    function dualPhoto(slot, label){
      // slot: 'sello' | 'vin_label' | 'unidad'
      var camId  = 'pvR' + slot + 'Cam';
      var fileId = 'pvR' + slot + 'File';
      return '<label class="ad-label" style="display:block;font-weight:700;margin-top:10px;">'+label+'</label>'+
        '<input type="file" id="'+camId+'" accept="image/*" capture="environment" data-slot="'+slot+'" class="pvRPhoto" style="display:none;">'+
        '<input type="file" id="'+fileId+'" accept="image/*" data-slot="'+slot+'" class="pvRPhoto" style="display:none;">'+
        '<div style="display:flex;gap:8px;">'+
          '<button type="button" class="ad-btn primary sm pvRPhotoOpen" data-target="'+camId+'" style="flex:1;">📷 Foto</button>'+
          '<button type="button" class="ad-btn ghost sm pvRPhotoOpen" data-target="'+fileId+'" style="flex:1;">📁 Archivo</button>'+
        '</div>'+
        '<div id="pvRThumb_'+slot+'" style="font-size:12px;color:#16a34a;margin-top:4px;display:none;">✓ Foto cargada</div>';
    }

    PVApp.modal(
      '<div class="ad-h2"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg> Recibir moto</div>'+
      '<div style="color:var(--ad-dim);font-size:12px;margin-bottom:10px">VIN esperado: <code>'+vinEsperado+'</code></div>'+

      '<label class="ad-label">Escanear o escribir VIN</label>'+
      '<div style="display:flex;gap:6px;margin-bottom:10px;">'+
        '<input id="pvRVin" class="ad-input" placeholder="VIN escaneado" style="flex:1;">'+
        '<button class="ad-btn sm primary" id="pvScanBtn" type="button" style="white-space:nowrap;">Escanear</button>'+
      '</div>'+

      // NEW: VIN escrito en la caja de cartón (puede diferir del VIN del chasis)
      // Customer brief 2026-05-09 (Óscar, 5th round — screenshot 4): when the
      // VIN on the box differs from the chassis VIN we expect, the system has
      // to flag it. A silent mismatch can mean wrong moto in the box or a
      // mix-up at CEDIS. Live red border + warning message + server-side
      // mismatch check.
      '<label class="ad-label">VIN impreso en la caja</label>'+
      '<input id="pvRVinCaja" class="ad-input" placeholder="VIN en la caja" style="margin-bottom:4px;">'+
      '<div id="pvRVinCajaWarn" style="font-size:12px;color:#b91c1c;font-weight:600;margin-bottom:10px;min-height:16px;display:none;"></div>'+

      // NEW: número de sello + integridad
      '<label class="ad-label">Número de sello aplicado</label>'+
      '<input id="pvRSelloNum" class="ad-input" placeholder="Número del sello" style="margin-bottom:10px;">'+

      '<div class="ad-h2" style="margin-top:6px;">Verificaciones</div>'+
      '<label class="pv-check"><input type="checkbox" id="pvCSello"> Sello aplicado y SIN violar</label>'+
      '<label class="pv-check"><input type="checkbox" id="pvC1"> Estado físico OK (sin daños ni golpes)</label>'+
      '<label class="pv-check"><input type="checkbox" id="pvC2"> Sin daños visibles</label>'+
      '<label class="pv-check"><input type="checkbox" id="pvC3"> Componentes completos</label>'+
      '<label class="pv-check"><input type="checkbox" id="pvC4"> Batería OK</label>'+

      '<div class="ad-h2" style="margin-top:6px;">Fotos requeridas</div>'+
      dualPhoto('sello',     '🔒 Foto del sello aplicado') +
      dualPhoto('vin_label', '🏷️ Foto de la etiqueta VIN') +
      dualPhoto('unidad',    '📷 Foto de la unidad recibida') +

      '<label class="ad-label" style="margin-top:10px;">Observaciones</label>'+
      '<textarea id="pvRObs" class="ad-input" placeholder="Detalles adicionales sobre la recepción"></textarea>'+

      '<label class="ad-label">Fecha de recepción</label>'+
      '<input id="pvRFecha" type="date" class="ad-input" value="'+(new Date().toISOString().slice(0,10))+'" style="margin-bottom:10px;">'+

      // Customer brief 2026-05-12 (Óscar, 6th round — screenshot 1: field
      // showed "DA…" manually typed): the "Recibido por" name must be
      // populated automatically from the logged-in punto user. We render it
      // readonly with a lock icon so the operator can't accidentally type
      // someone else's name. Server already stamps recibido_por with the
      // session user_id — this field is the human-readable label used in
      // the historial view.
      '<label class="ad-label">Recibido por</label>'+
      '<div style="position:relative;margin-bottom:14px;">'+
        '<input id="pvRUser" class="ad-input" readonly tabindex="-1" '+
          'value="'+ ((PVApp.state && PVApp.state.user && PVApp.state.user.nombre) || '') +'" '+
          'style="background:#f3f4f6;color:#374151;cursor:not-allowed;padding-right:32px;">'+
        '<span title="Tomado automáticamente de la sesión" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:14px;color:#6b7280;">🔒</span>'+
      '</div>'+

      // Legacy "Notas" preserved as a separate quick-jot field for back-compat.
      '<label class="ad-label">Notas</label>'+
      '<textarea id="pvRNotas" class="ad-input"></textarea>'+

      '<button id="pvRSave" class="ad-btn primary" style="width:100%;margin-top:14px">Confirmar recepción</button>'
    );

    $('#pvScanBtn').on('click', function(){ openVinScanner(vinEsperado); });

    // Live VIN-caja mismatch warning. Compares against the expected (chassis)
    // VIN — operator can still proceed if they're sure (server asks for
    // explicit confirmation in that case).
    function _normVin(s){ return (s||'').toString().trim().toUpperCase(); }
    var _vinEsperadoNorm = _normVin(vinEsperado);
    function recheckVinCaja(){
      var caja = _normVin($('#pvRVinCaja').val());
      var $warn = $('#pvRVinCajaWarn');
      var $inp  = $('#pvRVinCaja');
      if (!caja) {
        $warn.hide().text('');
        $inp.css('border-color', '');
        return;
      }
      if (caja !== _vinEsperadoNorm) {
        $warn.show().html(
          '⚠️ VIN en la caja <strong>NO coincide</strong> con el VIN esperado ('+vinEsperado+').<br>'+
          '<small>Verifica que la moto correcta está en esta caja. Si quieres registrar la diferencia para auditoría, podrás confirmar al guardar.</small>'
        );
        $inp.css('border-color', '#b91c1c').css('background', '#fef2f2');
      } else {
        $warn.show().html('<span style="color:#059669;">✓ VIN en la caja coincide con el VIN esperado.</span>');
        $inp.css('border-color', '#059669').css('background', '#f0fdf4');
      }
    }
    $('#pvRVinCaja').on('input change blur', recheckVinCaja);

    // Photo button hooks — one per (slot,kind).
    var photos = { sello:null, vin_label:null, unidad:null };
    $('.pvRPhotoOpen').on('click', function(){
      var t = $(this).data('target');
      if (t) document.getElementById(t).click();
    });
    $('.pvRPhoto').on('change', function(){
      var slot = $(this).data('slot');
      var f = this.files && this.files[0];
      if (!f) return;
      var rdr = new FileReader();
      rdr.onload = function(){
        photos[slot] = rdr.result;
        $('#pvRThumb_'+slot).text('✓ Foto cargada (' + Math.round(f.size/1024) + ' KB)').show();
      };
      rdr.readAsDataURL(f);
    });

    $('#pvRSave').on('click', function(){
      // Customer brief 2026-05-09: when vin_caja differs from the expected
      // chassis VIN, ask explicit confirmation before submitting. Adds a
      // confirm_vin_mismatch flag so the server can persist the discrepancy
      // without blocking when the operator is sure the box-label was the
      // wrong one (manufacturing typo, etc.).
      var caja = _normVin($('#pvRVinCaja').val());
      var confirmMismatch = 0;
      if (caja && caja !== _vinEsperadoNorm) {
        var msg = 'El VIN en la caja (' + $('#pvRVinCaja').val() + ') NO coincide con el VIN esperado (' + vinEsperado + ').\n\n'+
                  '¿La moto correcta está en esta caja a pesar de la diferencia?\n\n'+
                  '• OK = registrar la recepción con la discrepancia para auditoría\n'+
                  '• Cancelar = corregir el dato antes de guardar';
        if (!confirm(msg)) return;
        confirmMismatch = 1;
      }
      var data = {
        envio_id: envioId, moto_id: motoId,
        vin_escaneado: $('#pvRVin').val().trim(),
        estado_fisico_ok: $('#pvC1').is(':checked')?1:0,
        sin_danos: $('#pvC2').is(':checked')?1:0,
        componentes_completos: $('#pvC3').is(':checked')?1:0,
        bateria_ok: $('#pvC4').is(':checked')?1:0,
        // NEW (Bug 3.3) — server treats all of these as optional.
        vin_caja: $('#pvRVinCaja').val().trim(),
        sello_numero: $('#pvRSelloNum').val().trim(),
        sello_intacto: $('#pvCSello').is(':checked')?1:0,
        foto_sello: photos.sello,
        foto_vin_label: photos.vin_label,
        foto_unidad: photos.unidad,
        observaciones: $('#pvRObs').val(),
        fecha_recepcion: $('#pvRFecha').val() || null,
        recibido_por_nombre: $('#pvRUser').val().trim(),
        notas: $('#pvRNotas').val(),
        // Confirmation when vin_caja != vin_esperado — server rejects
        // mismatch without this flag, prompts user to acknowledge.
        confirm_vin_mismatch: confirmMismatch
      };
      PVApp.api('recepcion/recibir.php', data).done(function(r){
        if(r.ok){ PVApp.closeModal(); PVApp.toast('Moto recibida'); render(); }
      }).fail(function(x){
        // Customer brief 2026-05-09 (Óscar, 5th round — "cannot add a
        // motorcycle"): when the backend rejects with the strict-validation
        // missing-fields error, list each missing field by name so the
        // operator immediately sees what to fix instead of getting a
        // generic "Error".
        var r = x.responseJSON || {};
        var err = r.error || 'Error de conexión';
        if (Array.isArray(r.missing) && r.missing.length) {
          var labels = {
            'estado_fisico_ok':       'Estado físico OK',
            'sin_danos':              'Sin daños visibles',
            'componentes_completos':  'Componentes completos',
            'bateria_ok':             'Batería OK',
            'sello_intacto':          'Sello aplicado y SIN violar (checkbox)',
            'sello_numero':           'Número de sello aplicado',
            'vin_caja':               'VIN impreso en la caja',
            'foto_sello':             'Foto del sello',
            'foto_vin_label':         'Foto de la etiqueta VIN',
            'foto_unidad':            'Foto de la unidad'
          };
          err += '\n\nFaltan estos campos:\n  • ' + r.missing.map(function(k){ return labels[k] || k; }).join('\n  • ');
        }
        if (r.requires_confirm) {
          err += '\n\n(Para confirmar la discrepancia y guardar, vuelve a presionar Confirmar recepción.)';
        }
        alert(err);
      });
    });
  }

  // ── Camera-based VIN scanner ─────────────────────────────────────────────
  // Opens a live camera preview (rear camera on mobile, webcam on desktop) and
  // scans barcodes using the native BarcodeDetector API. On most motorcycles
  // the VIN is printed as Code 128 or DataMatrix on the frame/plate.
  var _scanState = { stream: null, video: null, detector: null, running: false };

  function openVinScanner(vinEsperado){
    var html = '<div class="ad-h2">Escanear VIN</div>'+
      '<div style="color:#666;font-size:12px;margin-bottom:10px;">Apunta la cámara al código de barras o texto del VIN.</div>'+
      '<div id="pvScanErr" style="color:#c41e3a;font-size:12px;margin-bottom:8px;display:none;"></div>'+
      '<div style="position:relative;background:#000;border-radius:10px;overflow:hidden;margin-bottom:12px;">'+
        '<video id="pvScanVideo" autoplay playsinline muted style="width:100%;display:block;max-height:60vh;"></video>'+
        '<div style="position:absolute;top:50%;left:8%;right:8%;height:2px;background:#FF0000;box-shadow:0 0 8px rgba(255,0,0,0.7);"></div>'+
      '</div>'+
      '<div style="font-size:12px;color:#666;margin-bottom:10px;">VIN esperado: <code>'+vinEsperado+'</code></div>'+
      '<div style="display:flex;gap:6px;">'+
        '<button class="ad-btn ghost" id="pvScanCancel" style="flex:1;">Cancelar</button>'+
        '<button class="ad-btn primary" id="pvScanManual" style="flex:1;">Escribir manualmente</button>'+
      '</div>';
    PVApp.modal(html);

    $('#pvScanCancel').on('click', function(){ stopScanner(); showReceiveFormReturnFocus(); });
    $('#pvScanManual').on('click', function(){ stopScanner(); showReceiveFormReturnFocus(); $('#pvRVin').focus(); });

    startScanner();
  }

  function showReceiveFormReturnFocus(){
    // The receive form is still in the DOM — just close the scanner modal.
    // (PVApp.modal just rendered over it; closing reveals the original)
    PVApp.closeModal();
  }

  function startScanner(){
    var errEl = document.getElementById('pvScanErr');
    var video = document.getElementById('pvScanVideo');
    if(!video || !errEl) return;

    if(!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){
      errEl.textContent = 'Tu navegador no soporta acceso a la cámara.';
      errEl.style.display = 'block';
      return;
    }

    _scanState.running = true;
    navigator.mediaDevices.getUserMedia({
      video: { facingMode: { ideal: 'environment' } },
      audio: false
    }).then(function(stream){
      _scanState.stream = stream;
      video.srcObject = stream;

      // Try native BarcodeDetector (Chrome/Edge on Android, desktop Chrome with flag)
      if('BarcodeDetector' in window){
        try {
          _scanState.detector = new window.BarcodeDetector({
            formats: ['code_128','code_39','code_93','ean_13','qr_code','data_matrix','pdf417']
          });
          detectLoop(video);
        } catch(e){
          errEl.innerHTML = 'Detector de códigos no disponible. Podés escribir el VIN manualmente.';
          errEl.style.display = 'block';
        }
      } else {
        errEl.innerHTML = 'Tu navegador no soporta lectura automática de códigos. Usa "Escribir manualmente" o abrí la app en Chrome de Android.';
        errEl.style.display = 'block';
      }
    }).catch(function(err){
      errEl.textContent = 'Error al acceder a la cámara: ' + (err.message || err.name || 'permiso denegado');
      errEl.style.display = 'block';
    });
  }

  function detectLoop(video){
    if(!_scanState.running || !_scanState.detector) return;
    if(video.readyState < 2){
      setTimeout(function(){ detectLoop(video); }, 200);
      return;
    }
    _scanState.detector.detect(video).then(function(barcodes){
      if(!_scanState.running) return;
      if(barcodes && barcodes.length){
        var raw = barcodes[0].rawValue || '';
        // VINs are 17 chars alphanumeric, no I/O/Q. Accept any non-empty here — let server/user verify.
        var vin = (raw || '').toString().trim().toUpperCase();
        if(vin.length >= 6){
          stopScanner();
          PVApp.closeModal();
          // Return to receive form (still in DOM) and populate
          setTimeout(function(){
            var $vinInput = $('#pvRVin');
            if($vinInput.length){ $vinInput.val(vin).trigger('change'); }
            PVApp.toast('VIN detectado: ' + vin);
          }, 100);
          return;
        }
      }
      setTimeout(function(){ detectLoop(video); }, 300);
    }).catch(function(){
      setTimeout(function(){ detectLoop(video); }, 500);
    });
  }

  function stopScanner(){
    _scanState.running = false;
    if(_scanState.stream){
      _scanState.stream.getTracks().forEach(function(t){ t.stop(); });
      _scanState.stream = null;
    }
    _scanState.detector = null;
  }

  return { render:render };
})();
