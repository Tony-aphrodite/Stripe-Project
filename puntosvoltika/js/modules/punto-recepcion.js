window.PV_recepcion = (function(){
  function render(){
    PVApp.render('<div class="ad-h1">Recepción de motos</div><div><span class="ad-spin"></span></div>');
    PVApp.api('recepcion/envios-pendientes.php').done(paint);
  }
  function paint(r){
    var html = '<div class="ad-h1">Recepción de motos</div>';
    html += '<div style="color:var(--ad-dim);margin-bottom:14px">Motos enviadas desde CEDIS esperando recepción</div>';
    if((r.envios||[]).length===0) html += '<div class="ad-card">No hay envíos pendientes</div>';
    (r.envios||[]).forEach(function(e){
      html += '<div class="ad-card">'+
        (e.pedido_num ? '<div style="font-size:12px;font-weight:700;color:var(--ad-primary,#039fe1);margin-bottom:4px;">📋 '+e.pedido_num+'</div>' : '')+
        '<div style="font-weight:700">'+e.modelo+' · '+e.color+'</div>'+
        '<div style="font-size:12px;color:var(--ad-dim)">VIN esperado: '+(e.vin_display||e.vin)+'</div>'+
        (e.cliente_nombre ? '<div style="font-size:12px;margin-top:4px;">👤 Cliente: <strong>'+e.cliente_nombre+'</strong></div>' : '')+
        '<div style="font-size:11px;margin-top:4px;"><span class="ad-badge '+(e.estado==='enviada'?'yellow':'blue')+'">'+e.estado+'</span>'+
        (e.fecha_estimada_llegada ? ' <span style="font-size:11px;color:var(--ad-dim)">· ETA: '+e.fecha_estimada_llegada+'</span>' : '')+'</div>'+
        '<button class="ad-btn primary sm pvReceive" data-env="'+e.id+'" data-moto="'+e.moto_id+'" data-vin="'+e.vin+'" style="margin-top:8px"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg> Recibir moto</button>'+
      '</div>';
    });
    PVApp.render(html);
    $('.pvReceive').on('click', function(){
      showReceiveForm($(this).data('env'), $(this).data('moto'), $(this).data('vin'));
    });
  }
  function showReceiveForm(envioId, motoId, vinEsperado){
    PVApp.modal(
      '<div class="ad-h2"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg> Recibir moto</div>'+
      '<div style="color:var(--ad-dim);font-size:12px;margin-bottom:10px">VIN esperado: <code>'+vinEsperado+'</code></div>'+
      '<label class="ad-label">Escanear o escribir VIN</label>'+
      '<div style="display:flex;gap:6px;margin-bottom:14px;">'+
        '<input id="pvRVin" class="ad-input" placeholder="VIN escaneado" style="flex:1;">'+
        '<button class="ad-btn sm primary" id="pvScanBtn" type="button" style="white-space:nowrap;">📷 Escanear</button>'+
      '</div>'+
      '<div class="ad-h2">Checklist</div>'+
      '<label class="pv-check"><input type="checkbox" id="pvC1"> Estado físico OK</label>'+
      '<label class="pv-check"><input type="checkbox" id="pvC2"> Sin daños visibles</label>'+
      '<label class="pv-check"><input type="checkbox" id="pvC3"> Componentes completos</label>'+
      '<label class="pv-check"><input type="checkbox" id="pvC4"> Batería OK</label>'+
      '<label class="ad-label">Notas</label>'+
      '<textarea id="pvRNotas" class="ad-input"></textarea>'+
      '<button id="pvRSave" class="ad-btn primary" style="width:100%;margin-top:14px">Confirmar recepción</button>'
    );

    $('#pvScanBtn').on('click', function(){ openVinScanner(vinEsperado); });

    $('#pvRSave').on('click', function(){
      var data = {
        envio_id: envioId, moto_id: motoId,
        vin_escaneado: $('#pvRVin').val().trim(),
        estado_fisico_ok: $('#pvC1').is(':checked')?1:0,
        sin_danos: $('#pvC2').is(':checked')?1:0,
        componentes_completos: $('#pvC3').is(':checked')?1:0,
        bateria_ok: $('#pvC4').is(':checked')?1:0,
        notas: $('#pvRNotas').val()
      };
      PVApp.api('recepcion/recibir.php', data).done(function(r){
        if(r.ok){ PVApp.closeModal(); PVApp.toast('Moto recibida'); render(); }
      }).fail(function(x){ alert((x.responseJSON&&x.responseJSON.error)||'Error'); });
    });
  }

  // ── Camera-based VIN scanner ─────────────────────────────────────────────
  // Opens a live camera preview (rear camera on mobile, webcam on desktop) and
  // scans barcodes using the native BarcodeDetector API. On most motorcycles
  // the VIN is printed as Code 128 or DataMatrix on the frame/plate.
  var _scanState = { stream: null, video: null, detector: null, running: false };

  function openVinScanner(vinEsperado){
    var html = '<div class="ad-h2">📷 Escanear VIN</div>'+
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
          errEl.innerHTML = '⚠ Detector de códigos no disponible. Podés escribir el VIN manualmente.';
          errEl.style.display = 'block';
        }
      } else {
        errEl.innerHTML = '⚠ Tu navegador no soporta lectura automática de códigos. Usa "Escribir manualmente" o abrí la app en Chrome de Android.';
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
