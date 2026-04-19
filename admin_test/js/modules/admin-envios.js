window.AD_envios = (function(){

  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  function render(){
    ADApp.render('<div class="ad-h1">Envíos</div><div><span class="ad-spin"></span></div>');
    ADApp.api('envios/listar.php').done(paint);
  }

  function paint(r){
    var envios = r.envios||[];
    var html = _backBtn+'<div class="ad-toolbar"><div class="ad-h1">Envíos</div>'+
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

    html += '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table"><thead><tr>'+
      '<th>Orden</th><th>Cliente</th><th>Moto</th><th>VIN</th><th>Destino</th>'+
      '<th>Tracking</th><th>Carrier</th><th>Estado</th>'+
      '<th>Fecha envío</th><th>ETA</th><th>Acciones</th>'+
      '</tr></thead><tbody>';

    envios.forEach(function(e){
      html += '<tr>'+
        '<td style="white-space:nowrap">'+(e.pedido_num ? '<strong>'+e.pedido_num+'</strong>' : '<span class="ad-dim">Inventario</span>')+'</td>'+
        '<td>'+(e.cliente_nombre||'<span class="ad-dim">—</span>')+'</td>'+
        '<td>'+e.modelo+' '+e.color+'</td>'+
        '<td style="font-size:11px;">'+(e.vin_display||e.vin||'—')+'</td>'+
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

    html += '</tbody></table></div></div>';
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
  // Per dashboards_diagrams.pdf diagram 5, shipments without an order must
  // specify a "type of assignment" — showroom vs entrega.
  function showCrearEnvio(){
    var html = '<div class="ad-h2">Crear envío</div>'+
      '<div class="ad-dim" style="margin-bottom:12px;">Selecciona el tipo de envío:</div>'+
      '<div style="display:flex;flex-direction:column;gap:10px;">'+
        '<div class="ad-card" style="cursor:pointer;padding:14px;" id="adEnvShowroom">'+
          '<strong>Sin orden — a consignación</strong>'+
          '<div class="ad-dim" style="margin-top:2px;">Inventario para el punto de venta (exhibición y venta directa)</div>'+
        '</div>'+
        '<div class="ad-card" style="cursor:pointer;padding:14px;" id="adEnvVenta">'+
          '<strong>Entrega con orden</strong>'+
          '<div class="ad-dim" style="margin-top:2px;">Enviar moto de una compra pagada al punto de entrega</div>'+
        '</div>'+
      '</div>';
    ADApp.modal(html);
    $('#adEnvShowroom').on('click', function(){ crearEnvioStep2(null, 'showroom', null); });
    $('#adEnvVenta').on('click', crearEnvioSelectOrder);
  }

  function crearEnvioSelectOrder(){
    ADApp.api('ventas/sin-punto.php').done(function(r){
      if(!r.ok || !r.rows.length){
        ADApp.modal('<div class="ad-h2">Sin órdenes listas</div>'+
          '<div class="ad-dim" style="padding:20px;text-align:center;line-height:1.6;">'+
            'No hay órdenes listas para envío.<br><br>'+
            'Para aparecer aquí una orden debe:<br>'+
            '• Estar <strong>pagada</strong> (o con enganche en crédito)<br>'+
            '• Tener un <strong>punto de entrega asignado</strong><br>'+
            '• Tener una <strong>moto asignada</strong> (desde Ventas)<br>'+
            '• No tener un envío creado todavía'+
          '</div>');
        return;
      }
      var html = '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">'+
        '<button class="ad-btn ghost sm" id="adEnvBack1" style="padding:4px 8px;">← Volver</button>'+
        '<div class="ad-h2" style="margin:0;">Paso 1 de 2 — Seleccionar orden</div>'+
      '</div>'+
      '<div class="ad-dim" style="margin-bottom:10px;font-size:12px;">Órdenes pagadas con punto y moto asignada, sin envío creado · Ordenadas de nueva a vieja ('+r.rows.length+')</div>'+
      '<div style="max-height:350px;overflow-y:auto;">';
      r.rows.forEach(function(o, idx){
        var pe = (o.pago_estado||'').toLowerCase();
        var pagoLabel = pe === 'parcial' ? 'Enganche' : (pe === 'pagada' ? 'Pagado' : (pe || 'OK'));
        var pagoCol   = pe === 'parcial' ? 'yellow' : 'green';
        html += '<div class="ad-card adPickOrder" style="cursor:pointer;padding:10px;margin-bottom:6px;" data-idx="'+idx+'">'+
          '<div style="display:flex;justify-content:space-between;align-items:center;gap:6px;flex-wrap:wrap;">'+
            '<strong>VK-'+(o.pedido||o.id)+'</strong>'+
            '<div style="display:flex;gap:4px;">'+
              '<span class="ad-badge '+pagoCol+'" style="font-size:10px;">'+pagoLabel+'</span>'+
              '<span class="ad-badge '+(o.tipo==='credito'||o.tipo==='enganche'?'yellow':(o.tipo==='msi'?'blue':'green'))+'" style="font-size:10px;">'+
                (o.tipo||'contado')+'</span>'+
            '</div>'+
          '</div>'+
          '<div style="font-size:13px;margin-top:4px;">Cliente: '+(o.nombre||'Sin nombre')+'</div>'+
          '<div style="font-size:12px;color:var(--ad-dim)">'+o.modelo+' · '+o.color+' · '+ADApp.money(o.monto)+'</div>'+
          '<div style="font-size:12px;margin-top:2px;">Moto: <strong>'+(o.moto_vin||'—')+'</strong> · Punto: <strong>'+(o.punto_nombre||'—')+'</strong></div>'+
          '<div style="font-size:11px;color:var(--ad-dim);margin-top:2px;">'+(o.fecha?String(o.fecha).substring(0,16):'')+'</div>'+
        '</div>';
      });
      html += '</div>';
      ADApp.closeModal();
      setTimeout(function(){
        ADApp.modal(html);
        $('#adEnvBack1').on('click', showCrearEnvio);
        $('.adPickOrder').on('click', function(){
          var o = r.rows[$(this).data('idx')];
          crearEnvioStep2(o.id, 'entrega', {
            pedido: o.pedido, nombre: o.nombre, modelo: o.modelo,
            color: o.color, monto: o.monto, punto_id: o.punto_id,
            punto_nombre: o.punto_nombre,
            moto_id: o.moto_id, moto_vin: o.moto_vin,
            moto_modelo: o.moto_modelo, moto_color: o.moto_color
          });
        });
      }, 50);
    });
  }

  function crearEnvioStep2(transId, envioTipo, orderInfo){
    envioTipo = envioTipo || 'entrega';
    // Load motos (for no-order flow) + puntos in parallel. In order-linked
    // flow the moto is already assigned in Ventas, so we don't need the motos
    // list here — but we still fetch it for the no-order dropdown.
    var motosUrl = 'ventas/motos-disponibles.php';

    $.when(
      ADApp.api(motosUrl),
      ADApp.api('puntos/listar.php')
    ).done(function(mRes, pRes){
      var motos = (mRes[0]||mRes).motos||[];
      var puntos = (pRes[0]||pRes).puntos||[];

      var tipoLabel = transId
        ? 'Entrega con orden'
        : 'Sin orden — a consignación';
      var html = '';
      if (transId) {
        html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">'+
          '<button class="ad-btn ghost sm" id="adEnvBack2" style="padding:4px 8px;">← Volver</button>'+
          '<div class="ad-h2" style="margin:0;">Paso 2 de 2 — Crear envío</div>'+
        '</div>';
      } else {
        html += '<div class="ad-h2">Crear envío ('+tipoLabel+')</div>';
      }

      // Show order summary when creating for a specific order
      if (transId && orderInfo) {
        html += '<div style="padding:10px;background:#E8F4FD;border-radius:6px;margin-bottom:10px;font-size:12px;border:1px solid #B3D4FC;">'+
          '<strong>Orden: VK-'+(orderInfo.pedido||transId)+'</strong><br>'+
          'Cliente: <strong>'+(orderInfo.nombre||'—')+'</strong><br>'+
          'Modelo: '+(orderInfo.modelo||'—')+' · Color: '+(orderInfo.color||'—')+' · '+ADApp.money(orderInfo.monto||0)+
          (orderInfo.punto_nombre ? '<br>Punto solicitado: <strong>'+orderInfo.punto_nombre+'</strong>' : '')+
        '</div>';
      }
      if (!transId) {
        html += '<div style="padding:10px;background:#E3F2FD;border-radius:6px;margin-bottom:10px;font-size:12px;">La moto entrará al inventario del punto de venta para exhibición y venta directa.</div>';
      }

      // ── Moto ────────────────────────────────────────────────────────
      // Order-linked flow (Flow A): moto is already assigned in Ventas
      // (via asignar-moto.php). Show it read-only — to change it, the
      // admin must go back to Ventas. No dropdown here.
      // No-order flow (Flow B): classic dropdown so admin picks stock.
      if (transId) {
        var amLabel = (orderInfo && orderInfo.moto_vin) ? orderInfo.moto_vin : '—';
        if (orderInfo && orderInfo.moto_modelo) amLabel += ' · '+orderInfo.moto_modelo;
        if (orderInfo && orderInfo.moto_color)  amLabel += ' · '+orderInfo.moto_color;
        html += '<label style="font-weight:600;font-size:13px;">Moto asignada:</label>'+
          '<div style="padding:10px;margin-bottom:10px;border:1.5px solid #C8E6C9;background:#E8F5E9;border-radius:6px;font-size:13px;">'+
            '<strong>'+esc(amLabel)+'</strong>'+
            '<div class="ad-dim" style="font-size:11px;margin-top:2px;">Asignada desde Ventas · Para cambiarla, reasigna desde la pantalla de Ventas</div>'+
          '</div>';
      } else {
        html += '<label style="font-weight:600;font-size:13px;">Moto:</label>'+
          '<select class="ad-select" id="adEnvMoto" style="margin-bottom:10px;width:100%;">';
        html += '<option value="">— Seleccionar moto —</option>';
        motos.forEach(function(m){
          var label = (m.vin_display||m.vin)+' · '+m.modelo+' · '+m.color;
          if (m.punto_nombre) label += ' · Punto: '+m.punto_nombre;
          html += '<option value="'+m.id+'">'+label+'</option>';
        });
        html += '</select>';
      }

      // Punto selector — auto-select if order has a punto already.
      // Match by ID first; fall back to name match because legacy orders may
      // store punto_id as a slug ("punto-5") or have it NULL while punto_nombre
      // is set (configurador saved only the display name).
      var orderPuntoId = (orderInfo && orderInfo.punto_id) ? orderInfo.punto_id.toString().replace(/^punto-/,'') : '';
      var orderPuntoName = (orderInfo && orderInfo.punto_nombre) ? orderInfo.punto_nombre.toString().toLowerCase().trim() : '';
      html += '<label style="font-weight:600;font-size:13px;">Punto destino:</label>'+
        '<select class="ad-select" id="adEnvPunto" style="margin-bottom:10px;width:100%;">';
      html += '<option value="">— Seleccionar punto —</option>';
      puntos.forEach(function(p){
        var matchId   = orderPuntoId && p.id.toString() === orderPuntoId;
        var matchName = !matchId && orderPuntoName && (p.nombre||'').toLowerCase().trim() === orderPuntoName;
        var sel = (matchId || matchName) ? ' selected' : '';
        html += '<option value="'+p.id+'"'+sel+'>'+p.nombre+' · '+(p.ciudad||'')+'</option>';
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

      ADApp.closeModal();
      setTimeout(function(){
      ADApp.modal(html);

      // Back button to return to order selection
      $('#adEnvBack2').on('click', function(){ crearEnvioSelectOrder(); });

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
        // Order-linked flow: moto comes from the pre-assignment (server
        // re-derives it from transaccion_id, so we don't send moto_id).
        // No-order flow: dropdown required.
        var puntoId = $('#adEnvPunto').val();
        if(!puntoId){ alert('Selecciona un punto destino'); return; }

        var payload = {
          punto_id: parseInt(puntoId),
          envio_tipo: envioTipo,
          tracking_number: $('#adEnvTracking').val().trim(),
          carrier: $('#adEnvCarrier').val().trim(),
          notas: $('#adEnvNotas').val().trim(),
          fecha_estimada: $('#adEnvQuote').data('eta')||null
        };
        if(transId) {
          payload.transaccion_id = transId;
        } else {
          var motoId = $('#adEnvMoto').val();
          if(!motoId){ alert('Selecciona una moto'); return; }
          payload.moto_id = parseInt(motoId);
        }

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
    }, 50);
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
