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

  function render(){
    VKApp.render('<div class="vk-h1">Mi entrega </div><div class="vk-muted">Cargando...</div>');
    VKApp.api('entrega/estado.php').done(function(r){
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

    // Pickup date banner — set when the point marks moto lista_para_entrega
    if (data.fecha_recoleccion && st !== 'entregada') {
      html += '<div class="vk-banner ok">Tu moto está lista. <strong>Recógela el '+fechaLarga(data.fecha_recoleccion)+'</strong> en el punto.</div>';
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

  function openActa(){
    var c = VKApp.state.cliente || {};
    var nombre = [(c.nombre||''), (c.apellido_paterno||''), (c.apellido_materno||'')].join(' ').trim();
    var hoy = new Date().toLocaleDateString('es-MX');
    var actaHtml =
      '<div class="vk-h2">ACTA DE ENTREGA DE VEHÍCULO</div>'+
      '<div class="vk-card" style="max-height:280px;overflow:auto;font-size:13px;line-height:1.5">'+
        '<p>En '+(data.punto.ciudad||'México')+', a fecha '+hoy+', quien suscribe <strong>'+(nombre||'el cliente')+'</strong>, declara haber recibido de conformidad el siguiente vehículo:</p>'+
        '<p><strong>Modelo:</strong> '+(data.modelo||'')+'<br>'+
        '<strong>Color:</strong> '+(data.color||'')+'<br>'+
        '<strong>VIN:</strong> '+(data.vin||data.vin_display||'')+'</p>'+
        '<p>El vehículo fue entregado en perfectas condiciones físicas y mecánicas, con todos sus componentes y accesorios completos, según el checklist verificado por el personal de Voltika.</p>'+
        '<p>El suscrito reconoce que a partir de este momento asume la responsabilidad total del uso, custodia y cuidado del vehículo, así como el cumplimiento puntual de los pagos semanales del plan de crédito contratado.</p>'+
        '<p>Asimismo, declara haber recibido la información sobre garantía, uso correcto y medidas de seguridad del vehículo eléctrico.</p>'+
      '</div>'+
      '<label class="vk-label" style="margin-top:10px">Escribe tu nombre completo para firmar</label>'+
      '<input id="vkFirmaNombre" class="vk-input" placeholder="Nombre y apellidos" value="'+nombre+'">'+
      '<label class="vk-check" style="margin-top:8px"><input type="checkbox" id="vkFirmaAcepto"> He leído y acepto el ACTA DE ENTREGA</label>'+
      '<button id="vkFirmarBtn" class="vk-btn primary" style="width:100%;margin-top:10px" disabled>Firmar ACTA</button>'+
      '<button id="vkCancelarActa" class="vk-btn ghost" style="width:100%;margin-top:6px">Cancelar</button>';

    VKApp.render(actaHtml);
    $('#vkFirmaAcepto').on('change', function(){
      $('#vkFirmarBtn').prop('disabled', !this.checked || !$('#vkFirmaNombre').val().trim());
    });
    $('#vkFirmaNombre').on('input', function(){
      $('#vkFirmarBtn').prop('disabled', !$('#vkFirmaAcepto').is(':checked') || !$(this).val().trim());
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
