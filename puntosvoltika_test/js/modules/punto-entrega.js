window.PV_entrega = (function(){
  var ctx = {};
  function render(){
    PVApp.render('<div class="ad-h1">Entregar al cliente</div><div><span class="ad-spin"></span></div>');
    PVApp.api('inventario/listar.php').done(function(r){
      var list = (r.inventario_entrega||[]).filter(function(m){ return m.estado!=='entregada'; });
      var html = '<div class="ad-h1">Entregar al cliente</div>';
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
        if(r.ok){ ctx.testCode=r.test_code; step2(); }
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
  // Step 3: Face verification + photos
  function step3(){
    PVApp.modal(
      steps(2)+
      '<div class="ad-h2">3. Foto del cliente e INE</div>'+
      '<div style="color:var(--ad-dim);font-size:12px;margin-bottom:10px">Toma foto del rostro del cliente y de su identificación.</div>'+
      '<label class="ad-label">Foto rostro cliente</label>'+
      '<input type="file" id="pvFCliente" accept="image/*" capture="user" class="ad-input" style="margin-bottom:10px">'+
      '<label class="ad-label">Foto INE</label>'+
      '<input type="file" id="pvFIne" accept="image/*" class="ad-input" style="margin-bottom:10px">'+
      '<button id="pvS3" class="ad-btn primary" style="width:100%">Verificar rostro</button>'
    );
    $('#pvS3').on('click', function(){
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
        '<div class="ad-h2" style="color:var(--ad-err)">⛔ No se puede entregar</div>'+
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
  // Step 4: Moto checklist + photos
  function step4(){
    PVApp.modal(
      steps(3)+
      '<div class="ad-h2">4. Checklist de la moto</div>'+
      '<label class="pv-check"><input type="checkbox" id="pvM1"> VIN coincide con lo esperado</label>'+
      '<label class="pv-check"><input type="checkbox" id="pvM2"> Estado físico OK</label>'+
      '<label class="pv-check"><input type="checkbox" id="pvM3"> Sin daños</label>'+
      '<label class="pv-check"><input type="checkbox" id="pvM4"> Unidad completa</label>'+
      '<label class="ad-label">Fotos de la moto</label>'+
      '<input type="file" id="pvFoto1" accept="image/*" class="ad-input" style="margin-bottom:6px" placeholder="Frente">'+
      '<input type="file" id="pvFoto2" accept="image/*" class="ad-input" style="margin-bottom:6px" placeholder="Lateral">'+
      '<input type="file" id="pvFoto3" accept="image/*" class="ad-input" style="margin-bottom:10px" placeholder="Trasera">'+
      '<button id="pvS4" class="ad-btn primary" style="width:100%">Guardar checklist</button>'
    );
    $('#pvS4').on('click', function(){
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
  // Step 5: Wait for ACTA + finalize
  function step5(){
    PVApp.modal(
      steps(4)+
      '<div class="ad-h2">5. Firma del ACTA DE ENTREGA</div>'+
      '<div class="ad-card" style="color:var(--ad-warn)">'+
        'Pide al cliente que ingrese al portal <strong>voltika.mx/clientes</strong> desde su celular, revise el acta y la firme.'+
      '</div>'+
      '<button id="pvS5" class="ad-btn primary" style="width:100%">Finalizar entrega</button>'
    );
    $('#pvS5').on('click', function(){
      PVApp.api('entrega/finalizar.php',{moto_id:ctx.moto_id}).done(function(r){
        if(r.ok){ PVApp.closeModal(); PVApp.toast(r.mensaje); render(); }
      }).fail(function(x){ alert((x.responseJSON&&x.responseJSON.error)||'Error'); });
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
