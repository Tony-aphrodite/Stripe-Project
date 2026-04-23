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

    // Moto info
    html += '<div class="vk-card">'+
      '<div class="vk-h2">'+(data.modelo||'Moto')+' · '+(data.color||'')+'</div>'+
      '<div class="vk-muted">VIN: '+(data.vin_display||'—')+'</div>'+
      '<div class="vk-row"><span class="k">Punto Voltika</span><span class="v">'+(data.punto.nombre||'—')+'</span></div>'+
      (data.punto.direccion?'<div class="vk-row"><span class="k">Dirección</span><span class="v">'+data.punto.direccion+'</span></div>':'')+
      (data.punto.telefono ?'<div class="vk-row"><span class="k">Teléfono</span><span class="v">'+data.punto.telefono+'</span></div>':'')+
    '</div>';

    // Shipment tracking card — Skydrop ETA. Shown whenever we have envio data
    // and the client hasn't received the moto at the point yet.
    if (data.envio && st !== 'entregada' && st !== 'firmada' && st !== 'checklist_ok') {
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
    if ((st === 'pendiente' || st === 'en_transito') && !data.fecha_recoleccion) {
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

    if (st === 'entregada' && !data.recepcion_confirmada) {
      html += '<div class="vk-card hl">'+
        '<div class="vk-h2">¡Tu moto ya fue entregada!</div>'+
        '<div class="vk-muted">Confirma la recepción para cerrar el proceso.</div>'+
        '<button id="vkConfirmarRecep" class="vk-btn primary" style="margin-top:10px">Confirmar recepción</button>'+
        '<button id="vkIncidencia" class="vk-btn ghost" style="margin-top:6px">Reportar incidencia</button>'+
      '</div>';
    }

    if (st === 'entregada' && data.recepcion_confirmada) {
      html += '<div class="vk-banner ok" style="font-size:15px">¡Bienvenido a la familia Voltika! Tu moto fue recibida correctamente.</div>';
    }

    VKApp.render(html);

    $('#vkVerActa').on('click', openActa);
    $('#vkConfirmarRecep').on('click', function(){ confirmarRecepcion(false); });
    $('#vkIncidencia').on('click', function(){ confirmarRecepcion(true); });
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

  function openActa(){
    var c = VKApp.state.cliente || {};
    var nombre = [(c.nombre||''), (c.apellido_paterno||''), (c.apellido_materno||'')].join(' ').trim();

    // Short summary card — so the customer always sees WHAT they're signing
    // for even before opening the full ACTA. Important for fraud prevention:
    // shows Modelo / Color / VIN prominently.
    var motoInfo =
      '<div class="vk-card" style="border-left:4px solid #039fe1;">'+
        '<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Vehículo a recibir</div>'+
        '<div style="font-weight:700;font-size:15px;">'+(data.modelo||'—')+' · '+(data.color||'—')+'</div>'+
        '<div style="font-size:12px;color:#4b5563;font-family:monospace;margin-top:2px;">VIN: '+(data.vin||data.vin_display||'—')+'</div>'+
      '</div>';

    var actaHtml =
      '<div class="vk-h1">Firma del ACTA DE ENTREGA</div>'+
      '<div class="vk-muted" style="margin-bottom:12px;">Lee y firma el acta para completar la entrega.</div>'+
      motoInfo+
      '<label class="vk-label" style="margin-top:16px">Escribe tu nombre completo para firmar</label>'+
      '<input id="vkFirmaNombre" class="vk-input" placeholder="Nombre y apellidos" value="'+String(nombre).replace(/"/g,'&quot;')+'">'+
      '<label class="vk-check" style="margin-top:12px;display:flex;align-items:center;gap:8px;cursor:pointer;">'+
        '<input type="checkbox" id="vkFirmaAcepto"> '+
        '<span>He leído y acepto el <a href="#" id="vkVerActaLink" style="color:#039fe1;text-decoration:underline;font-weight:700;">ACTA DE ENTREGA</a></span>'+
      '</label>'+
      '<button id="vkFirmarBtn" class="vk-btn primary" style="width:100%;margin-top:14px" disabled>Firmar ACTA</button>'+
      '<button id="vkCancelarActa" class="vk-btn ghost" style="width:100%;margin-top:6px">Cancelar</button>';

    VKApp.render(actaHtml);

    function updateBtn(){
      var nameOk  = !!$('#vkFirmaNombre').val().trim();
      var checkOk = $('#vkFirmaAcepto').is(':checked');
      $('#vkFirmarBtn').prop('disabled', !(nameOk && checkOk));
    }
    $('#vkFirmaAcepto').on('change', updateBtn);
    $('#vkFirmaNombre').on('input', updateBtn);

    // Clicking the "ACTA DE ENTREGA" text in the checkbox opens the modal
    // without flipping the checkbox (preventDefault + stopPropagation).
    $('#vkVerActaLink').on('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      showActaModal($('#vkFirmaNombre').val().trim());
    });

    $('#vkCancelarActa').on('click', render);
    $('#vkFirmarBtn').on('click', function(){
      var $b = $(this).prop('disabled', true).text('Firmando...');
      VKApp.api('entrega/firmar-acta.php', {
        moto_id: data.moto_id,
        firma_nombre: $('#vkFirmaNombre').val().trim(),
      }).done(function(r){
        if (r.ok) { VKApp.toast('ACTA firmada'); render(); }
        else { $b.prop('disabled', false).text('Firmar ACTA'); VKApp.toast(r.error||'Error'); }
      }).fail(function(x){
        $b.prop('disabled', false).text('Firmar ACTA');
        VKApp.toast((x.responseJSON&&x.responseJSON.error)||'Error al firmar');
      });
    });
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
