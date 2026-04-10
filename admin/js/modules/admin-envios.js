window.AD_envios = (function(){

  function render(){
    ADApp.render('<div class="ad-h1">Envíos</div><div><span class="ad-spin"></span></div>');
    ADApp.api('envios/listar.php').done(paint);
  }

  function paint(r){
    var envios = r.envios||[];
    var html = '<div class="ad-toolbar"><div class="ad-h1">Envíos</div>'+
      '<button class="ad-btn primary" id="adNewEnvio">+ Crear envío</button>'+
      '</div>';

    // KPIs
    var total = envios.length;
    var pendientes = envios.filter(function(e){return e.estado==='lista_para_enviar';}).length;
    var enviadas = envios.filter(function(e){return e.estado==='enviada';}).length;
    var recibidas = envios.filter(function(e){return e.estado==='recibida';}).length;
    html += '<div class="ad-kpis">'+
      '<div class="ad-kpi"><div class="label">Total</div><div class="value blue">'+total+'</div></div>'+
      '<div class="ad-kpi"><div class="label">Pendientes</div><div class="value yellow">'+pendientes+'</div></div>'+
      '<div class="ad-kpi"><div class="label">Enviadas</div><div class="value blue">'+enviadas+'</div></div>'+
      '<div class="ad-kpi"><div class="label">Recibidas</div><div class="value green">'+recibidas+'</div></div>'+
      '</div>';

    if(!envios.length){
      html += '<div class="ad-card" style="text-align:center;padding:32px;">No hay envíos registrados</div>';
      ADApp.render(html);
      $('#adNewEnvio').on('click', showCrearEnvio);
      return;
    }

    html += '<div style="overflow-x:auto;"><table class="ad-table"><thead><tr>'+
      '<th>Moto</th><th>VIN</th><th>Pedido</th><th>Destino</th>'+
      '<th>Tracking</th><th>Carrier</th><th>Estado</th>'+
      '<th>Fecha envío</th><th>ETA</th><th>Acciones</th>'+
      '</tr></thead><tbody>';

    envios.forEach(function(e){
      html += '<tr>'+
        '<td>'+e.modelo+' '+e.color+'</td>'+
        '<td>'+(e.vin_display||e.vin||'—')+'</td>'+
        '<td>'+(e.pedido_num||'<span class="ad-dim">Inventario</span>')+'</td>'+
        '<td>'+(e.punto_nombre||'—')+(e.punto_ciudad?' <small class="ad-dim">'+e.punto_ciudad+'</small>':'')+'</td>'+
        '<td>'+(e.tracking_number||'<span class="ad-dim">—</span>')+'</td>'+
        '<td>'+(e.carrier||'—')+'</td>'+
        '<td>'+ADApp.badgeEstado(e.estado)+'</td>'+
        '<td>'+(e.fecha_envio||'—')+'</td>'+
        '<td>'+(e.fecha_estimada_llegada||'—')+'</td>'+
        '<td style="white-space:nowrap">';

      // Action buttons
      html += '<button class="ad-btn sm ghost adEditTracking" data-id="'+e.id+'" '+
        'data-tracking="'+(e.tracking_number||'')+'" data-carrier="'+(e.carrier||'')+'" '+
        'data-notas="'+esc(e.notas||'')+'" data-eta="'+(e.fecha_estimada_llegada||'')+'">Tracking</button> ';

      if(e.estado==='lista_para_enviar')
        html += '<button class="ad-btn sm primary adChg" data-id="'+e.id+'" data-est="enviada">Enviar</button>';

      html += '</td></tr>';
    });

    html += '</tbody></table></div>';
    ADApp.render(html);

    $('#adNewEnvio').on('click', showCrearEnvio);
    $('.adChg').on('click', function(){
      var id=$(this).data('id'), est=$(this).data('est');
      if(!confirm('Marcar este envío como enviada?')) return;
      ADApp.api('envios/cambiar-estado.php',{envio_id:id,estado:est}).done(function(r2){
        if(r2.ok) render(); else alert(r2.error);
      });
    });
    $('.adEditTracking').on('click', function(){
      showEditTracking($(this).data('id'), $(this).data('tracking'), $(this).data('carrier'),
        $(this).data('notas'), $(this).data('eta'));
    });
  }

  // ── Create shipment modal ────────────────────────────────────────────
  function showCrearEnvio(){
    var html = '<div class="ad-h2">Crear envío</div>'+
      '<div class="ad-dim" style="margin-bottom:12px;">Selecciona el tipo de envío:</div>'+
      '<div style="display:flex;flex-direction:column;gap:10px;">'+
        '<div class="ad-card" style="cursor:pointer;padding:14px;" id="adEnvInv">'+
          '<strong>Inventario para venta en punto</strong>'+
          '<div class="ad-dim" style="margin-top:2px;">Enviar moto al punto para exhibición</div>'+
        '</div>'+
        '<div class="ad-card" style="cursor:pointer;padding:14px;" id="adEnvVenta">'+
          '<strong>Venta (vincular a orden)</strong>'+
          '<div class="ad-dim" style="margin-top:2px;">Enviar moto de una compra al punto de entrega</div>'+
        '</div>'+
      '</div>';
    ADApp.modal(html);
    $('#adEnvInv').on('click', function(){ crearEnvioStep2(null); });
    $('#adEnvVenta').on('click', crearEnvioSelectOrder);
  }

  function crearEnvioSelectOrder(){
    ADApp.api('ventas/sin-punto.php').done(function(r){
      if(!r.ok || !r.rows.length){
        ADApp.modal('<div class="ad-h2">Sin órdenes pendientes</div>'+
          '<div class="ad-dim" style="padding:20px;text-align:center;">No hay órdenes sin moto asignada.</div>');
        return;
      }
      var html = '<div class="ad-h2">Seleccionar orden</div>'+
        '<div style="max-height:350px;overflow-y:auto;">';
      r.rows.forEach(function(o){
        html += '<div class="ad-card adPickOrder" style="cursor:pointer;padding:10px;margin-bottom:6px;" data-tid="'+o.id+'" data-pid="'+(o.punto_id||'')+'">'+
          '<strong>VK-'+(o.pedido||o.id)+'</strong> · '+(o.nombre||'')+
          '<br><small class="ad-dim">'+o.modelo+' · '+o.color+' · '+ADApp.money(o.monto)+
          (o.punto_nombre?' · Punto: '+o.punto_nombre:'')+'</small>'+
        '</div>';
      });
      html += '</div>';
      ADApp.modal(html);
      $('.adPickOrder').on('click', function(){
        crearEnvioStep2($(this).data('tid'));
      });
    });
  }

  function crearEnvioStep2(transId){
    // Load motos + puntos in parallel
    $.when(
      ADApp.api('ventas/motos-disponibles.php'),
      ADApp.api('puntos/listar.php')
    ).done(function(mRes, pRes){
      var motos = (mRes[0]||mRes).motos||[];
      var puntos = (pRes[0]||pRes).puntos||[];

      var html = '<div class="ad-h2">Crear envío'+(transId?' (Venta)':' (Inventario)')+'</div>';

      // Moto selector
      html += '<label style="font-weight:600;font-size:13px;">Moto:</label>'+
        '<select class="ad-select" id="adEnvMoto" style="margin-bottom:10px;width:100%;">';
      html += '<option value="">— Seleccionar moto —</option>';
      motos.forEach(function(m){
        html += '<option value="'+m.id+'">'+(m.vin_display||m.vin)+' · '+m.modelo+' · '+m.color+'</option>';
      });
      html += '</select>';

      // Punto selector
      html += '<label style="font-weight:600;font-size:13px;">Punto destino:</label>'+
        '<select class="ad-select" id="adEnvPunto" style="margin-bottom:10px;width:100%;">';
      html += '<option value="">— Seleccionar punto —</option>';
      puntos.forEach(function(p){
        html += '<option value="'+p.id+'">'+p.nombre+' · '+(p.ciudad||'')+'</option>';
      });
      html += '</select>';

      // Tracking
      html += '<label style="font-weight:600;font-size:13px;">Número de tracking (opcional):</label>'+
        '<input class="ad-input" id="adEnvTracking" placeholder="Ej: 1234567890" style="margin-bottom:10px;">';

      // Carrier
      html += '<label style="font-weight:600;font-size:13px;">Paquetería / Carrier (opcional):</label>'+
        '<input class="ad-input" id="adEnvCarrier" placeholder="Ej: Estafeta, DHL, FedEx" style="margin-bottom:10px;">';

      // Notas
      html += '<label style="font-weight:600;font-size:13px;">Notas (opcional):</label>'+
        '<input class="ad-input" id="adEnvNotas" placeholder="Notas del envío" style="margin-bottom:14px;">';

      html += '<div id="adEnvQuote" style="display:none;margin-bottom:12px;padding:10px;border-radius:8px;background:#E3F2FD;font-size:13px;"></div>';
      html += '<button class="ad-btn primary" id="adEnvSave" style="width:100%;padding:10px;">Crear envío</button>';

      ADApp.modal(html);

      // Auto-quote when punto selected
      $('#adEnvPunto').on('change', function(){
        var pid = $(this).val();
        if(!pid){ $('#adEnvQuote').hide(); return; }
        var $q = $('#adEnvQuote');
        $q.html('<span class="ad-spin"></span> Cotizando...').show();
        ADApp.api('inventario/cotizar-envio.php',{punto_id:pid}).done(function(q){
          if(q.ok){
            $q.html('<strong>ETA:</strong> '+q.dias+' días · <strong>Llegada:</strong> '+q.fecha_estimada+
              ' · <strong>Carrier:</strong> '+q.carrier);
            $q.data('eta', q.fecha_estimada);
          } else {
            $q.html('<span style="color:orange;">'+q.error+'</span>');
            $q.data('eta','');
          }
        }).fail(function(){
          $q.html('<span style="color:orange;">Error de conexión</span>');
          $q.data('eta','');
        });
      });

      // Save
      $('#adEnvSave').on('click', function(){
        var motoId = $('#adEnvMoto').val();
        var puntoId = $('#adEnvPunto').val();
        if(!motoId || !puntoId){ alert('Selecciona moto y punto'); return; }

        var payload = {
          moto_id: parseInt(motoId),
          punto_id: parseInt(puntoId),
          tracking_number: $('#adEnvTracking').val().trim(),
          carrier: $('#adEnvCarrier').val().trim(),
          notas: $('#adEnvNotas').val().trim(),
          fecha_estimada: $('#adEnvQuote').data('eta')||null
        };
        if(transId) payload.transaccion_id = transId;

        $(this).prop('disabled',true).html('<span class="ad-spin"></span> Creando...');
        ADApp.api('envios/crear.php', payload).done(function(res){
          if(res.ok){
            ADApp.closeModal();
            render();
          } else {
            alert(res.error||'Error');
            $('#adEnvSave').prop('disabled',false).html('Crear envío');
          }
        }).fail(function(){
          alert('Error de conexión');
          $('#adEnvSave').prop('disabled',false).html('Crear envío');
        });
      });
    });
  }

  // ── Edit tracking modal ──────────────────────────────────────────────
  function showEditTracking(envioId, tracking, carrier, notas, eta){
    var html = '<div class="ad-h2">Editar tracking</div>'+
      '<label style="font-weight:600;font-size:13px;">Número de tracking:</label>'+
      '<input class="ad-input" id="adTrk" value="'+esc(tracking)+'" style="margin-bottom:10px;">'+
      '<label style="font-weight:600;font-size:13px;">Paquetería / Carrier:</label>'+
      '<input class="ad-input" id="adCar" value="'+esc(carrier)+'" style="margin-bottom:10px;">'+
      '<label style="font-weight:600;font-size:13px;">Notas:</label>'+
      '<input class="ad-input" id="adNot" value="'+esc(notas)+'" style="margin-bottom:10px;">'+
      '<label style="font-weight:600;font-size:13px;">Fecha estimada llegada:</label>'+
      '<input type="date" class="ad-input" id="adEta" value="'+esc(eta)+'" style="margin-bottom:14px;">'+
      '<button class="ad-btn primary" id="adTrkSave" style="width:100%;padding:10px;">Guardar</button>';
    ADApp.modal(html);

    $('#adTrkSave').on('click', function(){
      $(this).prop('disabled',true).html('<span class="ad-spin"></span> Guardando...');
      ADApp.api('envios/actualizar.php',{
        envio_id: envioId,
        tracking_number: $('#adTrk').val().trim(),
        carrier: $('#adCar').val().trim(),
        notas: $('#adNot').val().trim(),
        fecha_estimada: $('#adEta').val()||null
      }).done(function(res){
        if(res.ok){ ADApp.closeModal(); render(); }
        else{ alert(res.error||'Error'); $('#adTrkSave').prop('disabled',false).html('Guardar'); }
      }).fail(function(){
        alert('Error de conexión');
        $('#adTrkSave').prop('disabled',false).html('Guardar');
      });
    });
  }

  function esc(s){
    return (s||'').replace(/'/g,"&#39;").replace(/"/g,'&quot;');
  }

  return { render:render };
})();
