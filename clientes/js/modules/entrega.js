window.VK_entrega = (function(){
  var data = null;

  // Format YYYY-MM-DD or ISO datetime as "15 de abril de 2026"
  function fechaLarga(dateStr){
    if(!dateStr) return '';
    var s = String(dateStr).slice(0,10);
    var d = new Date(s+'T12:00:00');
    if(isNaN(d)) return s;
    var meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    return d.getDate()+' de '+meses[d.getMonth()]+' de '+d.getFullYear();
  }
  function escapeHtml(s){
    return String(s||'').replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function _scopeQS(){
    var a = VKApp.state.activeCompra;
    if (a && a.tipo && a.id) return '?compra_tipo=' + encodeURIComponent(a.tipo) + '&compra_id=' + encodeURIComponent(a.id);
    return '';
  }

  function render(){
    VKApp.render('<div class="vk-h1">Mi entrega </div><div class="vk-muted">Cargando...</div>');
    VKApp.api('entrega/estado.php' + _scopeQS()).done(function(r){
      data = r && r.entrega;
      paint();
    }).fail(function(){
      VKApp.render('<div class="vk-h1">Mi entrega</div><div class="vk-card">No se pudo cargar la información.</div>');
    });
  }

  function paint(){
    if (!data) {
      VKApp.render(
        '<div class="vk-h1">Mi entrega </div>'+
        '<div class="vk-card">Aún no tienes una moto asignada para entrega.<br><div class="vk-muted" style="margin-top:6px">Cuando el CEDIS asigne tu unidad a un punto, aquí verás los detalles.</div></div>'
      );
      return;
    }

    var st = data.estado_ui;
    var html = '<div class="vk-h1">Mi entrega </div>';

    // Stepper
    var steps = [
      {k:'pendiente',     l:'En tránsito'},
      {k:'otp_enviado',   l:'Código enviado'},
      {k:'confirmado',    l:'Identificado'},
      {k:'rostro_ok',     l:'Verificado'},
      {k:'checklist_ok',  l:'Checklist'},
      {k:'firmada',       l:'ACTA firmada'},
      {k:'entregada',     l:'Entregada'},
    ];
    var curIdx = steps.findIndex(function(s){return s.k===st;});
    if (curIdx < 0) curIdx = 0;
    html += '<div class="vk-stepper">';
    steps.forEach(function(s, i){
      var cls = i < curIdx ? 'done' : (i === curIdx ? 'active' : '');
      html += '<div class="vk-step '+cls+'"><span>'+(i+1)+'</span><em>'+s.l+'</em></div>';
    });
    html += '</div>';

    // Moto info. When the punto hasn't been assigned yet the portal used to
    // render a raw "—" in the Punto row which looked broken. Now we show a
    // human-readable pill ("Asignando punto…") so the customer understands
    // the moto is in an earlier stage of the logistics flow, not that the
    // portal is broken.
    var puntoNombreHtml = (data.punto && data.punto.nombre)
      ? escapeHtml(data.punto.nombre)
      : '<span style="display:inline-block;background:#fef3c7;color:#92400e;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;">Asignando punto…</span>';
    html += '<div class="vk-card">'+
      '<div class="vk-h2">'+(data.modelo||'Moto')+' · '+(data.color||'')+'</div>'+
      '<div class="vk-muted">VIN: '+(data.vin_display||'—')+'</div>'+
      '<div class="vk-row"><span class="k">Punto Voltika</span><span class="v">'+puntoNombreHtml+'</span></div>'+
      (data.punto && data.punto.direccion?'<div class="vk-row"><span class="k">Dirección</span><span class="v">'+escapeHtml(data.punto.direccion)+'</span></div>':'')+
      (data.punto && data.punto.telefono ?'<div class="vk-row"><span class="k">Teléfono</span><span class="v">'+escapeHtml(data.punto.telefono)+'</span></div>':'')+
    '</div>';

    // Round 74 v2 (2026-05-25) — Suppress shipment narrative until punto is assigned.
    // When punto_voltika_id is null the customer was seeing "Recibida en el punto"
    // (green) inside the Envío card AND "Tu moto está en tránsito al punto de
    // entrega" (amber) at the bottom — both contradictory with the "Asignando
    // punto..." badge above. The shipment can't logically be "in transit" or
    // "received at the point" if no point exists yet, so we hide both pieces
    // until the point is actually assigned, and show one clear info card instead.
    var hasPunto = !!(data.punto && data.punto.nombre);
    if (!hasPunto) {
      html += '<div class="vk-card" style="background:#fef9c3;border-left:4px solid #ca8a04;">'+
                '<div style="display:flex;gap:10px;align-items:flex-start;">'+
                  '<div style="font-size:20px;">📍</div>'+
                  '<div style="flex:1;font-size:13.5px;color:#713f12;line-height:1.5;">'+
                    '<strong>Estamos asignando tu punto de entrega.</strong><br>'+
                    'En cuanto Voltika seleccione el punto más cercano a tu zona, verás aquí la dirección, '+
                    'la fecha estimada de llegada y los siguientes pasos para recibir tu moto. '+
                    'Te avisaremos por SMS y por este portal.'+
                  '</div>'+
                '</div>'+
              '</div>';
    }

    // Shipment tracking card — Skydrop ETA. Shown whenever we have envio data
    // and the client hasn't received the moto at the point yet.
    if (hasPunto && data.envio && st !== 'entregada' && st !== 'firmada' && st !== 'checklist_ok') {
      var env = data.envio;
      var etaFmt = fechaLarga(env.fecha_estimada_llegada);
      var enviadoFmt = fechaLarga(env.fecha_envio);
      var shipHtml = '<div class="vk-card">'+
        '<div class="vk-h2">Envío</div>';
      if (env.fecha_recepcion) {
        shipHtml += '<div class="vk-row"><span class="k">Estado</span><span class="v" style="color:#22c55e">Recibida en el punto</span></div>';
      } else if (env.fecha_envio) {
        shipHtml += '<div class="vk-row"><span class="k">Estado</span><span class="v">En tránsito</span></div>';
      } else {
        shipHtml += '<div class="vk-row"><span class="k">Estado</span><span class="v">Preparando envío</span></div>';
      }
      if (enviadoFmt) shipHtml += '<div class="vk-row"><span class="k">Enviado</span><span class="v">'+enviadoFmt+'</span></div>';
      if (etaFmt && !env.fecha_recepcion) shipHtml += '<div class="vk-row"><span class="k">Llegada estimada</span><span class="v"><strong>'+etaFmt+'</strong></span></div>';
      if (env.carrier) shipHtml += '<div class="vk-row"><span class="k">Paquetería</span><span class="v">'+escapeHtml(env.carrier)+'</span></div>';
      if (env.tracking_number) shipHtml += '<div class="vk-row"><span class="k">Guía</span><span class="v" style="font-family:monospace">'+escapeHtml(env.tracking_number)+'</span></div>';
      shipHtml += '</div>';
      html += shipHtml;
    }

    // Pickup-ready banner — fires when the assembly checklist is completed on
    // the point side (guardar-ensamble.php sets fecha_entrega_estimada and
    // estado='lista_para_entrega'). Customer brief 2026-04-24: prominent
    // green card + contact point + payment-scam warning.
    if (data.fecha_recoleccion && st !== 'entregada') {
      var puntoLinea = (data.punto && data.punto.nombre) ? data.punto.nombre : 'el punto asignado';
      var puntoDir   = (data.punto && data.punto.direccion) ? data.punto.direccion : '';
      var puntoTel   = (data.punto && data.punto.telefono) ? data.punto.telefono : '';
      html += '<div style="background:linear-gradient(135deg,#dcfce7 0%,#bbf7d0 100%);'+
              'border:2px solid #22c55e;border-radius:14px;padding:18px 20px;margin:12px 0;'+
              'box-shadow:0 4px 12px rgba(34,197,94,.15);">'+
              '<div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:10px;">'+
                '<div style="flex-shrink:0;width:40px;height:40px;border-radius:50%;background:#22c55e;'+
                      'display:flex;align-items:center;justify-content:center;color:#fff;">'+
                  '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" '+
                       'stroke-width="3" stroke-linecap="round" stroke-linejoin="round">'+
                       '<polyline points="20 6 9 17 4 12"/></svg>'+
                '</div>'+
                '<div style="flex:1;">'+
                  '<div style="font-size:17px;font-weight:800;color:#14532d;line-height:1.3;">'+
                    '¡Tu moto está lista para entrega!</div>'+
                  '<div style="font-size:14px;color:#166534;margin-top:4px;line-height:1.45;">'+
                    'Ya puedes recogerla en <strong>'+escapeHtml(puntoLinea)+'</strong>.</div>'+
                '</div>'+
              '</div>'+
              (puntoDir
                ? '<div style="background:rgba(255,255,255,.6);border-radius:8px;padding:10px 12px;'+
                   'margin-top:10px;font-size:13px;color:#14532d;line-height:1.55;">'+
                   '<div style="font-weight:700;margin-bottom:4px;">📍 Dirección del punto</div>'+
                   escapeHtml(puntoDir)+
                   (puntoTel ? '<div style="margin-top:6px;">☎ <strong>'+escapeHtml(puntoTel)+'</strong></div>' : '')+
                   '</div>'
                : '')+
              '<div style="background:#fef3c7;border-left:4px solid #f59e0b;border-radius:6px;'+
                    'padding:11px 13px;margin-top:12px;font-size:12.5px;color:#78350f;line-height:1.55;">'+
                '<strong>⚠ Importante · Tu entrega no requiere ningún pago extra.</strong><br>'+
                'Si te piden dinero por cualquier concepto, <strong>no pagues</strong> y '+
                'repórtalo a Voltika al <a href="mailto:ventas@voltika.mx" '+
                   'style="color:#78350f;font-weight:700;text-decoration:underline;">'+
                   'ventas@voltika.mx</a>.'+
              '</div>'+
              '</div>';
    }

    // State-specific actions
    // Round 74 v2 — only show the "en tránsito al punto" banner when a punto
    // actually exists. Without one the message is misleading (the moto can't
    // be in transit to a point that hasn't been assigned).
    if (hasPunto && (st === 'pendiente' || st === 'en_transito') && !data.fecha_recoleccion) {
      var etaMsg = (data.envio && data.envio.fecha_estimada_llegada)
        ? 'Tu moto está en tránsito al punto de entrega. Llegada estimada: <strong>'+fechaLarga(data.envio.fecha_estimada_llegada)+'</strong>.'
        : 'Tu moto está en tránsito al punto de entrega. Te avisaremos cuando esté lista para recogerla.';
      html += '<div class="vk-banner warn">'+etaMsg+'</div>';
    }

    if (data.otp_activo && st === 'otp_enviado') {
      html += '<div class="vk-card hl">'+
        '<div class="vk-muted">Muestra este código al personal de Voltika</div>'+
        '<div class="vk-otp-big">'+data.otp_activo.split('').join(' ')+'</div>'+
        '<div class="vk-muted">Es tu clave de entrega. Expira en 15 min.</div>'+
      '</div>';
    }

    if (st === 'confirmado' || st === 'rostro_ok') {
      html += '<div class="vk-banner ok">Código verificado. El personal está revisando tu identificación y la moto.</div>';
    }

    if (st === 'checklist_ok' && !data.acta_firmada) {
      html += '<div class="vk-card">'+
        '<div class="vk-h2">Firma del ACTA DE ENTREGA</div>'+
        '<div class="vk-muted">Por favor revisa y firma el acta para completar la entrega.</div>'+
        '<button id="vkVerActa" class="vk-btn primary" style="margin-top:10px">Ver y firmar ACTA</button>'+
      '</div>';
    }

    if (data.acta_firmada && st !== 'entregada') {
      html += '<div class="vk-banner ok">ACTA firmada el '+(data.acta_fecha||'')+'. El personal finalizará la entrega ahora.</div>';
    }

    // Bug 5.8 (customer brief 2026-05-08): the "Confirmar recepción" button
    // is removed. The delivery is finalized — and reflected here — by the
    // PoS staff calling finalizar.php after the customer's signature. The
    // welcome banner now shows immediately when estado='entregada',
    // regardless of whether recepcion_confirmada was ever set. The legacy
    // confirmar-recepcion.php endpoint stays available for backward compat
    // and incident reporting (internal use only) but is no longer surfaced
    // in the customer portal UI.
    if (st === 'entregada') {
      html += '<div class="vk-banner ok" style="font-size:15px">¡Bienvenido a la familia Voltika! Tu moto fue recibida correctamente.</div>';
    }

    VKApp.render(html);

    $('#vkVerActa').on('click', openActa);
    // Confirmar recepción / Reportar incidencia handlers intentionally not
    // bound here anymore — buttons no longer rendered. confirmarRecepcion
    // is preserved below for external callers that may still poke it.
  }

  // Renders the full ACTA DE ENTREGA text — shared by both the inline form
  // and the read-on-demand modal. Kept as a single source of truth so the
  // two views cannot drift.
  function buildActaBodyHtml(nombre){
    var hoy = new Date().toLocaleDateString('es-MX');
    return ''+
      '<p>En '+(data.punto.ciudad||'México')+', a fecha '+hoy+', quien suscribe <strong>'+(nombre||'el cliente')+'</strong>, declara haber recibido de conformidad el siguiente vehículo:</p>'+
      '<p><strong>Modelo:</strong> '+(data.modelo||'')+'<br>'+
        '<strong>Color:</strong> '+(data.color||'')+'<br>'+
        '<strong>VIN:</strong> '+(data.vin||data.vin_display||'')+'</p>'+
      '<p>El vehículo fue entregado en perfectas condiciones físicas y mecánicas, con todos sus componentes y accesorios completos, según el checklist verificado por el personal de Voltika.</p>'+
      '<p>El suscrito reconoce que a partir de este momento asume la responsabilidad total del uso, custodia y cuidado del vehículo, así como el cumplimiento puntual de los pagos semanales del plan de crédito contratado.</p>'+
      '<p>Asimismo, declara haber recibido la información sobre garantía, uso correcto y medidas de seguridad del vehículo eléctrico.</p>';
  }

  // Renders the full ACTA in a modal triggered by the "ACTA DE ENTREGA" link
  // inside the acceptance checkbox. Customer feedback 2026-04-23: the inline
  // document made the sign form feel overwhelming on mobile; a read-on-tap
  // modal matches the standard terms-and-conditions pattern.
  function showActaModal(nombre){
    if ($('#vkActaBackdrop').length) return; // already open
    var modal =
      '<div class="vk-modal-backdrop" id="vkActaBackdrop"></div>'+
      '<div class="vk-modal" id="vkActaModal" style="max-width:560px;">'+
        '<button class="vk-modal-close" id="vkActaModalClose" aria-label="Cerrar">&times;</button>'+
        '<div class="vk-h2">ACTA DE ENTREGA DE VEHÍCULO</div>'+
        '<div style="font-size:13px;line-height:1.6;max-height:60vh;overflow:auto;padding:8px 2px;">'+
          buildActaBodyHtml(nombre)+
        '</div>'+
        '<div style="display:flex;gap:8px;margin-top:14px;">'+
          '<button id="vkActaModalCerrar" class="vk-btn primary" style="flex:1;">Entendido</button>'+
        '</div>'+
      '</div>';
    $('body').append(modal);
    function close(){ $('#vkActaBackdrop,#vkActaModal').remove(); }
    $('#vkActaBackdrop,#vkActaModalClose,#vkActaModalCerrar').on('click', close);
  }

  // Bug 5.7 (customer brief 2026-05-08, CRITICAL): the ACTA is now signed
  // through the Cincel signature system instead of the legacy
  // checkbox-+-typed-name shortcut. Pattern:
  //   1. Show a confirmation card so the customer sees Modelo / Color / VIN
  //      and can read the ACTA via the modal.
  //   2. POST entrega/cincel-firma-acta.php → returns signing_url.
  //   3. Embed the signing_url in an iframe (option A from briefing).
  //   4. Poll entrega/cincel-acta-status.php every 4 s; when `signed=true`
  //      lands (Cincel webhook fired), close the iframe and re-render.
  //
  // The legacy entrega/firmar-acta.php endpoint stays available so existing
  // automated tests keep working — it's just not invoked from the UI.
  var _cincelActaPoll = null;
  function openActa(){
    var motoInfo =
      '<div class="vk-card" style="border-left:4px solid #039fe1;">'+
        '<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Vehículo a recibir</div>'+
        '<div style="font-weight:700;font-size:15px;">'+(data.modelo||'—')+' · '+(data.color||'—')+'</div>'+
        '<div style="font-size:12px;color:#4b5563;font-family:monospace;margin-top:2px;">VIN: '+(data.vin||data.vin_display||'—')+'</div>'+
      '</div>';

    var introHtml =
      '<div class="vk-h1">Firma del ACTA DE ENTREGA</div>'+
      '<div class="vk-muted" style="margin-bottom:12px;">Vas a firmar electrónicamente con validez NOM-151 a través de <strong>Cincel</strong>. Toma menos de un minuto.</div>'+
      motoInfo+
      '<button id="vkVerActaLinkBtn" class="vk-btn ghost" style="width:100%;margin-top:10px">📄 Ver el contenido del acta</button>'+
      '<button id="vkIniciarFirmaBtn" class="vk-btn primary" style="width:100%;margin-top:10px">Iniciar firma con Cincel</button>'+
      '<button id="vkCancelarActa" class="vk-btn ghost" style="width:100%;margin-top:6px">Cancelar</button>';

    VKApp.render(introHtml);

    var c = VKApp.state.cliente || {};
    var nombre = [(c.nombre||''), (c.apellido_paterno||''), (c.apellido_materno||'')].join(' ').trim();

    $('#vkVerActaLinkBtn').on('click', function(){ showActaModal(nombre); });
    $('#vkCancelarActa').on('click', render);

    $('#vkIniciarFirmaBtn').on('click', function(){
      var $b = $(this).prop('disabled', true).text('Preparando documento…');
      // Helper — extract the most useful debug message from a server JSON
      // response so the toast always tells the user/dev WHY it failed
      // instead of a generic "Error".
      function pickError(r) {
        if (!r) return 'Sin respuesta del servidor';
        var msg = r.error || 'Error desconocido';
        if (r.detail) msg += ' · ' + r.detail;
        if (r.attempts && Array.isArray(r.attempts) && r.attempts.length) {
          var first = r.attempts[0] || {};
          msg += ' · HTTP ' + (first.http || '?') + ' en ' + (first.endpoint || '?');
        }
        return msg;
      }
      // Forward `?cincel_mock=1` from the page URL to the backend so the
       // operator can bypass real Cincel auth for testing without changing
       // any config. The AJAX path doesn't include the URL's query string
       // automatically — we have to read window.location and append it.
       var mockSuffix = /[?&]cincel_mock=1\b/.test(window.location.search) ? '?cincel_mock=1' : '';
       VKApp.api('entrega/cincel-firma-acta.php' + mockSuffix, { moto_id: data.moto_id })
        .done(function(r){
          if (!r || !r.ok) {
            $b.prop('disabled', false).text('Iniciar firma con Cincel');
            // Log the full payload to the console so devtools shows the
            // attempts[] array even when the toast itself is too short.
            if (window.console) console.error('[cincel-firma-acta]', r);
            VKApp.toast(pickError(r));
            return;
          }
          // Already signed — just refresh.
          if (r.already_signed) { VKApp.toast('ACTA ya firmada.'); render(); return; }

          // Round 73 (2026-05-24): backend always routes here — autograph
          // signature + Cincel NOM-151 timestamp on the resulting PDF.
          // No Cincel signing iframe / ceremony any more (Óscar's brief:
          // "we only need the timestamp from Cincel"). The previous
          // wording said "Cincel is unavailable" which scared customers —
          // updated to make clear NOM-151 IS applied to their signature.
          if (r.fallback_autograph) {
            showAutographSignaturePad(data.moto_id, r.pdf_url || null,
              'Firma con tu dedo en el recuadro de abajo. Tu firma se sellará con un timestamp NOM-151 a través de Cincel — '+
              'tiene plena validez legal como declaración de conformidad de recepción.');
            return;
          }

          if (!r.signing_url) {
            $b.prop('disabled', false).text('Iniciar firma con Cincel');
            VKApp.toast('Cincel no devolvió URL de firma.');
            return;
          }
          showCincelIframe(r.signing_url);
        })
        .fail(function(x){
          $b.prop('disabled', false).text('Iniciar firma con Cincel');
          if (window.console) console.error('[cincel-firma-acta] fail', x.responseJSON || x);
          VKApp.toast(pickError(x.responseJSON));
        });
    });
  }

  // Embeds the Cincel signing UI in a full-screen iframe and polls the
  // status endpoint until the webhook flips cliente_acta_firmada=1.
  // Round 65 (2026-05-20): autograph fallback when Cincel auth is unavailable.
  // Renders a canvas signature pad + name input, validates both are provided,
  // and submits to entrega/firmar-acta.php which already handles base64
  // signature_data + cliente_acta_firmada=1. After success the page re-renders
  // and the punto staff sees the ACTA as signed and can finalize delivery.
  function showAutographSignaturePad(motoId, pdfUrl, explanation){
    var html = ''+
      '<div class="vk-h1" style="margin-bottom:6px;">Firma del ACTA — autógrafa</div>'+
      '<div class="vk-banner warn" style="margin-bottom:12px;font-size:13px;">'+
        '⚠ '+(explanation||'Cincel no disponible — firma con autógrafa para no demorar la entrega.')+
      '</div>'+
      (pdfUrl ? '<div style="margin-bottom:10px;font-size:13px;"><a href="'+pdfUrl+'" target="_blank" rel="noopener" class="vk-link">📄 Ver el contenido del ACTA antes de firmar</a></div>' : '')+
      '<label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;">Tu nombre completo:</label>'+
      '<input type="text" id="vkActaNombre" class="vk-input" placeholder="Ej. Adrian Montoya Diaz" style="width:100%;margin-bottom:14px;" autocomplete="name">'+
      '<label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;">Firma con el dedo o ratón en el recuadro:</label>'+
      '<div style="border:2px solid #cbd5e1;border-radius:8px;background:#fff;position:relative;">'+
        '<canvas id="vkActaCanvas" style="width:100%;height:180px;display:block;touch-action:none;cursor:crosshair;"></canvas>'+
      '</div>'+
      '<div style="display:flex;gap:8px;margin-top:8px;">'+
        '<button id="vkActaClear"   class="vk-btn ghost"   style="flex:1;">Limpiar</button>'+
        '<button id="vkActaSubmit"  class="vk-btn primary" style="flex:2;">Firmar y completar entrega</button>'+
      '</div>'+
      '<div id="vkActaMsg" style="margin-top:8px;font-size:12px;color:#b91c1c;"></div>'+
      '<div style="margin-top:10px;font-size:11.5px;color:#64748b;line-height:1.5;">'+
        'Esta firma tiene validez como declaración de conformidad de recepción. '+
        'Se registra la fecha, hora, IP y un hash criptográfico de tu firma. '+
        'Cuando el sistema NOM-151 esté disponible, podrás re-firmar con sello digital si lo prefieres.'+
      '</div>';
    VKApp.modal(html, { wide: true });
    // Initialize canvas
    var canvas = document.getElementById('vkActaCanvas');
    var ctx    = canvas.getContext('2d');
    function resizeCanvas(){
      var ratio = window.devicePixelRatio || 1;
      var cssW  = canvas.offsetWidth, cssH = canvas.offsetHeight;
      // Round 42 lesson: enforce a minimum width so a narrow mobile viewport
      // doesn't reduce the canvas to ~1px (which made signatures invisible).
      if (cssW < 280) cssW = 280;
      canvas.width  = cssW * ratio;
      canvas.height = cssH * ratio;
      ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
      ctx.strokeStyle = '#0c2340';
      ctx.lineWidth   = 2;
      ctx.lineCap     = 'round';
      ctx.lineJoin    = 'round';
    }
    resizeCanvas();
    var drawing = false, lastX = 0, lastY = 0, hasDrawn = false;
    function getPos(e){
      var rect = canvas.getBoundingClientRect();
      var pt   = e.touches ? e.touches[0] : e;
      return { x: pt.clientX - rect.left, y: pt.clientY - rect.top };
    }
    function start(e){ e.preventDefault(); drawing = true; var p = getPos(e); lastX = p.x; lastY = p.y; }
    function move(e){
      if (!drawing) return; e.preventDefault();
      var p = getPos(e);
      ctx.beginPath(); ctx.moveTo(lastX, lastY); ctx.lineTo(p.x, p.y); ctx.stroke();
      lastX = p.x; lastY = p.y; hasDrawn = true;
    }
    function stop(){ drawing = false; }
    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    canvas.addEventListener('mouseup',   stop);
    canvas.addEventListener('mouseleave',stop);
    canvas.addEventListener('touchstart',start, {passive:false});
    canvas.addEventListener('touchmove', move,  {passive:false});
    canvas.addEventListener('touchend',  stop);

    $('#vkActaClear').on('click', function(){
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      hasDrawn = false;
      $('#vkActaMsg').text('');
    });

    $('#vkActaSubmit').on('click', function(){
      var $b = $(this);
      var nombre = String($('#vkActaNombre').val() || '').trim();
      if (nombre.length < 3) {
        $('#vkActaMsg').text('Escribe tu nombre completo (mínimo 3 caracteres).');
        return;
      }
      if (!hasDrawn) {
        $('#vkActaMsg').text('Por favor firma con el dedo o el ratón en el recuadro.');
        return;
      }
      var dataUrl = canvas.toDataURL('image/png');
      $b.prop('disabled', true).text('Firmando…');
      $('#vkActaMsg').text('');
      VKApp.api('entrega/firmar-acta.php', {
        moto_id:        motoId,
        firma_nombre:   nombre,
        signature_data: dataUrl
      })
      .done(function(r){
        if (!r || !r.ok) {
          $b.prop('disabled', false).text('Firmar y completar entrega');
          $('#vkActaMsg').text((r && r.error) || 'No se pudo firmar. Intenta de nuevo.');
          return;
        }
        VKApp.toast('✓ ACTA firmada correctamente.');
        VKApp.closeModal();
        render();
      })
      .fail(function(x){
        $b.prop('disabled', false).text('Firmar y completar entrega');
        $('#vkActaMsg').text((x.responseJSON && x.responseJSON.error) || 'Error de conexión.');
      });
    });
  }

  function showCincelIframe(signingUrl){
    var html = ''+
      '<div class="vk-h1" style="margin-bottom:8px;">Firma con Cincel</div>'+
      '<div class="vk-muted" style="margin-bottom:8px;">Completa los pasos en el formulario de Cincel. Esta página se actualizará automáticamente cuando termines.</div>'+
      '<div id="vkCincelStatus" class="vk-banner" style="margin-bottom:8px;">'+
        '<span class="vk-spin" style="display:inline-block;vertical-align:middle;width:12px;height:12px;border:2px solid #039fe1;border-top-color:transparent;border-radius:50%;animation:vkspin 0.8s linear infinite;"></span>'+
        ' Esperando confirmación de Cincel…'+
      '</div>'+
      '<div style="position:relative;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;background:#fff;">'+
        '<iframe id="vkCincelFrame" src="'+signingUrl+'" '+
          'allow="camera; microphone; geolocation" '+
          'style="width:100%;height:75vh;min-height:520px;border:0;display:block;"></iframe>'+
      '</div>'+
      '<div style="display:flex;gap:8px;margin-top:10px;">'+
        '<button id="vkCincelOpenNew" class="vk-btn ghost" style="flex:1;">Abrir en pestaña nueva</button>'+
        '<button id="vkCincelCancel" class="vk-btn ghost" style="flex:1;">Cancelar</button>'+
      '</div>';
    VKApp.render(html);

    $('#vkCincelOpenNew').on('click', function(){
      // Some browsers (older Safari) block iframe permissions for camera
      // capture; this gives the customer a clean fallback.
      window.open(signingUrl, '_blank', 'noopener');
    });
    $('#vkCincelCancel').on('click', function(){
      stopActaPoll();
      render();
    });

    // Begin polling. Customer brief 2026-05-08: signing should land within
    // ~30 s of the customer hitting "Firmar" on Cincel. Poll every 4 s,
    // give up after 10 min so the page doesn't hammer the API forever.
    var tries = 0;
    var maxTries = 150; // 10 min
    stopActaPoll();
    _cincelActaPoll = setInterval(function(){
      tries++;
      if (tries > maxTries) { stopActaPoll(); return; }
      VKApp.api('entrega/cincel-acta-status.php?moto_id=' + encodeURIComponent(data.moto_id), null, 'GET')
        .done(function(r){
          if (r && r.signed) {
            stopActaPoll();
            VKApp.toast('¡ACTA firmada correctamente!');
            render();
          }
        });
    }, 4000);
  }

  function stopActaPoll(){
    if (_cincelActaPoll) { clearInterval(_cincelActaPoll); _cincelActaPoll = null; }
  }

  function confirmarRecepcion(incidencia){
    var comentario = '';
    if (incidencia) {
      comentario = prompt('Describe brevemente la incidencia:');
      if (comentario === null) return;
    } else if (!confirm('¿Confirmar que recibiste tu moto en buen estado?')) return;

    VKApp.api('entrega/confirmar-recepcion.php', {
      moto_id: data.moto_id,
      incidencia: incidencia ? 1 : 0,
      comentario: comentario || null,
    }).done(function(r){
      if (r.ok) { VKApp.toast(r.mensaje); render(); }
    }).fail(function(x){
      VKApp.toast((x.responseJSON&&x.responseJSON.error)||'Error');
    });
  }

  return { render:render };
})();
