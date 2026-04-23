window.PV_entrega = (function(){
  var ctx = {};
  function render(){
    PVApp.render('<div class="ad-h1">Entregar al cliente</div><div><span class="ad-spin"></span></div>');
    PVApp.api('inventario/listar.php').done(function(r){
      var list = (r.inventario_entrega||[]).filter(function(m){ return m.estado!=='entregada'; });
      var html = '<div class="ad-h1">Entregar al cliente</div>';

      // Fraud-prevention warning. Always shown, regardless of whether the list
      // has items — punto operator must always see this notice before acting.
      // Icon: simple outlined padlock (stroke only, geometric). Explicitly NOT
      // a generic "🚨/⚠️" emoji to avoid the AI-generated look.
      html += '<div style="display:flex;gap:10px;align-items:flex-start;'+
        'background:#FFF4F2;border-left:4px solid #C41E3A;'+
        'padding:12px 14px;margin:8px 0 14px;border-radius:6px;">'+
        '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#C41E3A" '+
          'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" '+
          'style="flex-shrink:0;margin-top:1px;">'+
          '<rect x="4" y="11" width="16" height="10" rx="1.5"/>'+
          '<path d="M8 11V7a4 4 0 018 0v4"/>'+
          '<circle cx="12" cy="16" r="1.2" fill="#C41E3A"/>'+
        '</svg>'+
        '<div style="font-size:12.5px;line-height:1.55;color:#4a1220;">'+
          '<div style="margin-bottom:6px;">Estas son las <strong>únicas motos autorizadas</strong> que puedes entregar. '+
          'No entregues por ningún motivo si el número <strong>VIN</strong> no aparece en esta lista.</div>'+
          '<div>Nadie puede pedirte entregar una moto si no aparece en esta lista.</div>'+
        '</div>'+
      '</div>';

      if (list.length===0) html += '<div class="ad-card">Sin motos para entregar</div>';
      list.forEach(function(m){
        html += '<div class="ad-card">'+
          (m.pedido_num ? '<div style="font-size:12px;font-weight:700;color:var(--ad-primary,#039fe1);margin-bottom:4px;">'+m.pedido_num+'</div>' : '')+
          '<div style="font-weight:700">'+m.modelo+' · '+m.color+'</div>'+
          '<div style="font-size:12px">Cliente: <strong>'+(m.cliente_nombre||'—')+'</strong></div>'+
          '<div style="font-size:11px;color:var(--ad-dim)">'+(m.cliente_telefono||'')+' · '+(m.cliente_email||'')+'</div>'+
          '<button class="ad-btn primary sm pvStart" data-id="'+m.id+'" data-nombre="'+m.cliente_nombre+'" data-tel="'+m.cliente_telefono+'" data-pedido="'+(m.pedido_num||'')+'" style="margin-top:8px"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:3px;"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 000-5C13 2 12 7 12 7z"/></svg> Iniciar entrega</button>'+
        '</div>';
      });
      PVApp.render(html);
      $('.pvStart').on('click', function(){
        ctx = { moto_id: $(this).data('id'), cliente: $(this).data('nombre'), tel: $(this).data('tel'), pedido: $(this).data('pedido'), step:0 };
        step1();
      });
    });
  }
  function steps(idx){
    var s='<div class="pv-wizard-steps">';
    for(var i=0;i<5;i++) s+='<div class="'+(i<idx?'done':(i===idx?'active':''))+'"></div>';
    s+='</div>';
    return s;
  }
  // Step 1: Send OTP
  function step1(){
    PVApp.modal(
      steps(0)+
      '<div class="ad-h2">1. Enviar OTP al cliente</div>'+
      (ctx.pedido ? '<div style="font-size:12px;font-weight:700;color:var(--ad-primary,#039fe1);margin-bottom:8px;">'+ctx.pedido+'</div>' : '')+
      '<div class="ad-card">Cliente: <strong>'+ctx.cliente+'</strong><br>Tel: '+ctx.tel+'</div>'+
      '<button id="pvS1" class="ad-btn primary" style="width:100%">Enviar código por SMS</button>'
    );
    $('#pvS1').on('click', function(){
      var $b=$(this).prop('disabled',true).html('<span class="ad-spin"></span>');
      PVApp.api('entrega/iniciar.php',{moto_id:ctx.moto_id}).done(function(r){
        if(r.ok){
          ctx.testCode = r.test_code;
          // When no channel delivered the OTP (warning present + test_code
          // returned), surface it loudly so staff reads the code aloud
          // instead of silently letting the flow stall. Customer reported
          // "OTP didn't receive it" — this makes that case actionable.
          if (r.warning) {
            alert('⚠️ ' + r.warning + '\n\nCódigo del cliente: ' + (r.test_code || ctx.testCode || '(no disponible)'));
          }
          step2();
        }
      }).fail(function(x){ alert((x.responseJSON&&x.responseJSON.error)||'Error'); $b.prop('disabled',false).text('Enviar código por SMS'); });
    });
  }
  // Step 2: Verify OTP
  function step2(){
    PVApp.modal(
      steps(1)+
      '<div class="ad-h2">2. Verificar código del cliente</div>'+
      '<div>Pide al cliente que te muestre el código recibido por SMS.</div>'+
      '<div class="pv-otp-input">'+[0,1,2,3,4,5].map(function(i){return '<input maxlength="1" data-i="'+i+'" inputmode="numeric">';}).join('')+'</div>'+
      (ctx.testCode?'<div class="ad-card" style="color:var(--ad-warn)">Código de prueba: <b>'+ctx.testCode+'</b></div>':'')+
      '<button id="pvS2" class="ad-btn primary" style="width:100%">Verificar</button>'
    );
    var $ins=$('.pv-otp-input input');
    $ins.on('input',function(){ this.value=this.value.replace(/\D/g,''); if(this.value)$ins.eq($(this).data('i')+1).focus(); });
    $ins.eq(0).focus();
    $('#pvS2').on('click', function(){
      var code=$ins.map(function(){return this.value;}).get().join('');
      PVApp.api('entrega/verificar-otp.php',{moto_id:ctx.moto_id,codigo:code}).done(function(r){
        if(r.ok){ ctx.entrega_id=r.entrega_id; step3(); }
      }).fail(function(x){ alert((x.responseJSON&&x.responseJSON.error)||'Código incorrecto'); });
    });
  }
  // Step 3: Face verification + photos.
  // Customer brief 2026-04-24: explain to the operator that the current photo
  // will be compared against the Truora selfie taken when the client applied
  // for credit — same person must appear. Button stays disabled until both
  // files are picked so the operator can't skip the comparison.
  function step3(){
    PVApp.modal(
      steps(2)+
      '<div class="ad-h2">3. Foto del cliente e INE</div>'+
      '<div style="color:var(--ad-dim);font-size:12.5px;margin-bottom:14px;line-height:1.45;">'+
        'Toma una <strong>foto del rostro del cliente</strong> y una <strong>foto de su INE</strong>.<br>'+
        'La foto del rostro se compara automáticamente con la selfie que tomó al solicitar su crédito — '+
        '<strong>debe ser la misma persona</strong> que compró la moto.'+
      '</div>'+
      '<label class="ad-label" for="pvFCliente" style="display:block;font-weight:700;margin-bottom:4px;">📸 Foto rostro del cliente <span style="color:#dc2626">*</span></label>'+
      '<div style="font-size:11.5px;color:var(--ad-dim);margin-bottom:4px;">Rostro de frente, bien iluminado, sin lentes oscuros ni cubrebocas.</div>'+
      '<input type="file" id="pvFCliente" accept="image/*" capture="user" class="ad-input" style="margin-bottom:14px">'+
      '<label class="ad-label" for="pvFIne" style="display:block;font-weight:700;margin-bottom:4px;">🪪 Foto de la INE <span style="color:#dc2626">*</span></label>'+
      '<div style="font-size:11.5px;color:var(--ad-dim);margin-bottom:4px;">Frente de la identificación, con la foto visible y legible.</div>'+
      '<input type="file" id="pvFIne" accept="image/*" class="ad-input" style="margin-bottom:14px">'+
      '<div id="pvS3Hint" style="font-size:12px;color:#b45309;background:#fffbeb;border-left:3px solid #f59e0b;padding:8px 10px;border-radius:4px;margin-bottom:10px;display:none;">'+
        '⚠ Faltan fotos por seleccionar. Ambas son obligatorias antes de continuar.'+
      '</div>'+
      '<button id="pvS3" class="ad-btn primary" style="width:100%" disabled>Verificar rostro</button>'
    );

    function refreshStep3Btn(){
      var hasCli = $('#pvFCliente').prop('files').length > 0;
      var hasIne = $('#pvFIne').prop('files').length > 0;
      var ready  = hasCli && hasIne;
      $('#pvS3').prop('disabled', !ready);
      $('#pvS3Hint').toggle(!ready);
    }
    $('#pvFCliente, #pvFIne').on('change', refreshStep3Btn);
    refreshStep3Btn();

    $('#pvS3').on('click', function(){
      // Double-check in case the user disabled validation via devtools
      if ($('#pvFCliente').prop('files').length === 0 || $('#pvFIne').prop('files').length === 0) {
        $('#pvS3Hint').show();
        return;
      }
      var $b=$(this).prop('disabled',true).html('<span class="ad-spin"></span>');
      readFiles(['pvFCliente','pvFIne'], function(files){
        PVApp.api('entrega/verificar-rostro.php',{
          entrega_id: ctx.entrega_id, moto_id: ctx.moto_id,
          foto_cliente: files.pvFCliente, foto_ine: files.pvFIne
        }).done(function(r){
          if(!r.ok) return;
          handleFaceResult(r);
        }).fail(function(x){ alert((x.responseJSON&&x.responseJSON.error)||'Error'); $b.prop('disabled',false).text('Verificar rostro'); });
      });
    });
  }
  // Decide how to proceed after the face-check endpoint responds.
  // CREDITO purchases: hard-block on no_match, offer manual override if no original selfie on file.
  // MSI / CONTADO: there is no comparison — just advance.
  function handleFaceResult(r){
    if (!r.es_credito) {
      PVApp.toast(r.message || 'Fotos guardadas');
      step4();
      return;
    }
    // CREDITO — match confirmed by Truora
    if (r.match === true) {
      PVApp.toast('Rostro coincide con el crédito'+(r.face_score!=null?' ('+r.face_score+'%)':''));
      step4();
      return;
    }
    // CREDITO — faces do NOT match → cannot deliver
    if (r.match === false) {
      PVApp.modal(
        '<div class="ad-h2" style="color:var(--ad-err)">No se puede entregar</div>'+
        '<div class="ad-card" style="color:var(--ad-err)">'+
          '<strong>Las caras NO coinciden</strong> con la persona que realizó el crédito'+
          (r.face_score!=null?' (similitud '+r.face_score+'%)':'')+'.<br><br>'+
          'La entrega a crédito <strong>solo puede hacerse al titular</strong>. '+
          'Solicita al cliente que se presente el titular del crédito, o escala a soporte para verificación manual.'+
        '</div>'+
        '<button id="pvFaceRetry" class="ad-btn ghost" style="width:100%;margin-top:10px">Reintentar con otra foto</button>'+
        '<button id="pvFaceAbort" class="ad-btn" style="width:100%;margin-top:6px">Cancelar entrega</button>'
      );
      $('#pvFaceRetry').on('click', step3);
      $('#pvFaceAbort').on('click', function(){ PVApp.closeModal(); render(); });
      return;
    }
    // CREDITO — manual review path (no original selfie on file or Truora unavailable)
    PVApp.modal(
      '<div class="ad-h2" style="color:var(--ad-warn)">Verificación manual requerida</div>'+
      '<div class="ad-card">'+
        (r.message || 'No se pudo comparar automáticamente con la selfie del crédito.')+'<br><br>'+
        '<strong>Compara visualmente</strong> la foto tomada hoy contra la identificación (INE). '+
        'Si estás seguro de que es el mismo titular del crédito, puedes continuar bajo tu responsabilidad.'+
      '</div>'+
      '<label class="pv-check"><input type="checkbox" id="pvFaceConfirm"> Confirmo que la persona presente es el titular del crédito</label>'+
      '<button id="pvFaceManualOk" class="ad-btn primary" style="width:100%;margin-top:10px" disabled>Continuar con la entrega</button>'+
      '<button id="pvFaceManualCancel" class="ad-btn ghost" style="width:100%;margin-top:6px">Cancelar</button>'
    );
    $('#pvFaceConfirm').on('change', function(){ $('#pvFaceManualOk').prop('disabled', !this.checked); });
    $('#pvFaceManualOk').on('click', step4);
    $('#pvFaceManualCancel').on('click', function(){ PVApp.closeModal(); render(); });
  }
  // Step 4: Moto checklist + photos.
  // Customer brief 2026-04-24: each photo slot needs a descriptive title
  // (frente / lateral / trasera). Button is blocked until all 4 checkboxes
  // are ticked AND all 3 photos are selected — no more half-filled submissions.
  function step4(){
    PVApp.modal(
      steps(3)+
      '<div class="ad-h2">4. Checklist de la moto</div>'+
      '<div style="color:var(--ad-dim);font-size:12.5px;margin-bottom:12px;line-height:1.45;">'+
        'Verifica el estado de la moto y captura las <strong>3 fotos obligatorias</strong>. Todo debe estar completo antes de continuar.'+
      '</div>'+

      '<div style="margin-bottom:14px;">'+
        '<label class="pv-check"><input type="checkbox" id="pvM1"> VIN coincide con lo esperado</label>'+
        '<label class="pv-check"><input type="checkbox" id="pvM2"> Estado físico OK</label>'+
        '<label class="pv-check"><input type="checkbox" id="pvM3"> Sin daños</label>'+
        '<label class="pv-check"><input type="checkbox" id="pvM4"> Unidad completa</label>'+
      '</div>'+

      '<div style="font-weight:700;font-size:13px;color:var(--ad-navy,#1a3a5c);margin:14px 0 8px;text-transform:uppercase;letter-spacing:.3px;border-bottom:2px solid var(--ad-primary,#039fe1);padding-bottom:4px;">Fotos de la moto</div>'+

      // 3 photo slots with visible descriptive labels (not placeholders —
      // <input type=file> placeholders are invisible in all browsers).
      '<label class="ad-label" for="pvFoto1" style="display:block;font-weight:700;margin-bottom:3px;">📷 Foto 1: Moto de frente <span style="color:#dc2626">*</span></label>'+
      '<input type="file" id="pvFoto1" accept="image/*" capture="environment" class="ad-input" style="margin-bottom:12px">'+

      '<label class="ad-label" for="pvFoto2" style="display:block;font-weight:700;margin-bottom:3px;">📷 Foto 2: Lateral (costado) <span style="color:#dc2626">*</span></label>'+
      '<input type="file" id="pvFoto2" accept="image/*" capture="environment" class="ad-input" style="margin-bottom:12px">'+

      '<label class="ad-label" for="pvFoto3" style="display:block;font-weight:700;margin-bottom:3px;">📷 Foto 3: Trasera <span style="color:#dc2626">*</span></label>'+
      '<input type="file" id="pvFoto3" accept="image/*" capture="environment" class="ad-input" style="margin-bottom:14px">'+

      '<div id="pvS4Hint" style="font-size:12px;color:#b45309;background:#fffbeb;border-left:3px solid #f59e0b;padding:8px 10px;border-radius:4px;margin-bottom:10px;display:none;">'+
        '⚠ Faltan elementos por completar.'+
      '</div>'+
      '<button id="pvS4" class="ad-btn primary" style="width:100%" disabled>Guardar checklist</button>'
    );

    function refreshStep4Btn(){
      var chkCount = $('#pvM1,#pvM2,#pvM3,#pvM4').filter(':checked').length;
      var photoCount = ['pvFoto1','pvFoto2','pvFoto3']
        .filter(function(id){ return $('#'+id).prop('files').length > 0; }).length;
      var missing = [];
      if (chkCount < 4)   missing.push((4-chkCount)+' verificación(es)');
      if (photoCount < 3) missing.push((3-photoCount)+' foto(s)');
      var ready = missing.length === 0;
      $('#pvS4').prop('disabled', !ready);
      if (ready) {
        $('#pvS4Hint').hide();
      } else {
        $('#pvS4Hint').text('⚠ Faltan: ' + missing.join(' + ') + ' antes de guardar el checklist.').show();
      }
    }
    $('#pvM1,#pvM2,#pvM3,#pvM4').on('change', refreshStep4Btn);
    $('#pvFoto1,#pvFoto2,#pvFoto3').on('change', refreshStep4Btn);
    refreshStep4Btn();

    $('#pvS4').on('click', function(){
      // Defensive re-check in case validation was bypassed
      var chkCount = $('#pvM1,#pvM2,#pvM3,#pvM4').filter(':checked').length;
      var photoCount = ['pvFoto1','pvFoto2','pvFoto3']
        .filter(function(id){ return $('#'+id).prop('files').length > 0; }).length;
      if (chkCount < 4 || photoCount < 3) {
        refreshStep4Btn();
        return;
      }
      var $b=$(this).prop('disabled',true).html('<span class="ad-spin"></span>');
      readFiles(['pvFoto1','pvFoto2','pvFoto3'], function(files){
        PVApp.api('entrega/checklist.php',{
          moto_id: ctx.moto_id,
          vin_coincide: $('#pvM1').is(':checked')?1:0,
          estado_fisico_ok: $('#pvM2').is(':checked')?1:0,
          sin_danos: $('#pvM3').is(':checked')?1:0,
          unidad_completa: $('#pvM4').is(':checked')?1:0,
          fotos_moto: [files.pvFoto1, files.pvFoto2, files.pvFoto3].filter(Boolean)
        }).done(function(r){ if(r.ok) step5(); })
          .fail(function(){ $b.prop('disabled',false).text('Guardar checklist'); });
      });
    });
  }
  // Step 5: Wait for ACTA + finalize.
  // Polls entrega/estado-acta.php every 4 s so the Finalizar entrega button
  // auto-enables when the customer signs on their portal. Before this fix
  // dealers clicked Finalizar while the customer's ACTA-signing was still
  // failing (Moto no encontrada bug) and saw a confusing 400 error.
  var _step5Poll = null;
  function step5(){
    PVApp.modal(
      steps(4)+
      '<div class="ad-h2">5. Firma del ACTA DE ENTREGA</div>'+
      '<div class="ad-card" style="color:var(--ad-warn)">'+
        'Pide al cliente que ingrese al portal <strong>voltika.mx/clientes</strong> desde su celular, revise el acta y la firme.'+
      '</div>'+
      '<div id="pvS5Status" class="ad-card" style="font-size:13px;">'+
        '<span class="ad-spin" style="vertical-align:middle"></span> '+
        '<span id="pvS5StatusText">Esperando la firma del cliente...</span>'+
      '</div>'+
      '<button id="pvS5" class="ad-btn primary" disabled '+
        'style="width:100%;opacity:.5;cursor:not-allowed;">Finalizar entrega</button>'
    );

    function stopPolling(){
      if(_step5Poll){ clearInterval(_step5Poll); _step5Poll = null; }
    }

    function enableFinalize(signerName, signedAt){
      $('#pvS5Status').html(
        '<span style="color:#10b981;font-weight:700">&#10003; ACTA firmada'+
        (signerName ? ' por ' + $('<div/>').text(signerName).html() : '') + '</span>' +
        (signedAt ? '<div style="font-size:11px;color:#6b7280;margin-top:2px">'+ signedAt +'</div>' : '')
      );
      $('#pvS5').prop('disabled', false).css({opacity:'1', cursor:'pointer'});
    }

    function checkStatus(){
      if (!$('#pvS5').length || !$('#pvModal').is(':visible')) {
        stopPolling();
        return;
      }
      PVApp.api('entrega/estado-acta.php?moto_id='+encodeURIComponent(ctx.moto_id))
        .done(function(r){
          if(!r) return;
          if(r.ready){
            stopPolling();
            enableFinalize(r.firma_nombre, r.acta_fecha);
          } else if(r.acta_firmada && !r.otp_verified){
            $('#pvS5StatusText').text('Firma recibida, pero el OTP no está verificado. Reintenta el paso 2.');
          }
        })
        .fail(function(){ /* transient — keep polling */ });
    }
    checkStatus();
    _step5Poll = setInterval(checkStatus, 4000);

    $('#pvS5').on('click', function(){
      var $b = $(this).prop('disabled', true).text('Finalizando...');
      PVApp.api('entrega/finalizar.php', {moto_id:ctx.moto_id}).done(function(r){
        if(r.ok){
          stopPolling();
          PVApp.closeModal();
          PVApp.toast(r.mensaje);
          render();
        } else {
          $b.prop('disabled', false).text('Finalizar entrega');
        }
      }).fail(function(x){
        $b.prop('disabled', false).text('Finalizar entrega');
        alert((x.responseJSON && x.responseJSON.error) || 'Error al finalizar');
      });
    });
  }

  function readFiles(ids, cb){
    var out={}; var pending=ids.length;
    if(pending===0) return cb(out);
    ids.forEach(function(id){
      var f=document.getElementById(id); var file=f&&f.files&&f.files[0];
      if(!file){ if(--pending===0) cb(out); return; }
      var reader=new FileReader();
      reader.onload=function(){ out[id]=reader.result; if(--pending===0) cb(out); };
      reader.readAsDataURL(file);
    });
  }
  return { render:render };
})();
