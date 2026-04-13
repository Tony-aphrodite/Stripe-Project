window.AD_inventario = (function(){
  var filters = {};
  function render(){
    ADApp.render('<div class="ad-h1">Inventario</div><div><span class="ad-spin"></span> Cargando...</div>');
    load();
  }
  function load(){
    ADApp.api('inventario/listar.php?' + $.param(filters)).done(paint);
  }
  function paint(r){
    var html = '<div class="ad-toolbar"><div class="ad-h1">Inventario</div>';
    if(ADApp.isAdmin()){
      html += '<div style="display:flex;gap:6px">'+
        '<button class="ad-btn primary" id="adNewMoto">+ Nueva moto</button>'+
        '<button class="ad-btn ghost" id="adImportExcel">Importar Excel</button>'+
        '</div>';
    }
    html += '</div>';
    // Summary
    var s = r.resumen||{};
    html += '<div class="ad-kpis">';
    [{l:'Total',v:s.total},{l:'Disponible',v:s.disponible,c:'green'},{l:'Reservado',v:s.reservado,c:'yellow'},
     {l:'Entregado',v:s.entregado,c:'green'},{l:'En tránsito',v:s.en_transito,c:'blue'},
     {l:'En ensamble',v:s.en_ensamble,c:'yellow'},{l:'Bloqueado',v:s.bloqueado,c:'red'}].forEach(function(k){
      html += '<div class="ad-kpi"><div class="label">'+k.l+'</div><div class="value '+(k.c||'')+'">'+Number(k.v||0)+'</div></div>';
    });
    html += '</div>';
    // Filters
    html += '<div class="ad-filters">'+
      '<input class="ad-input" style="width:160px" placeholder="Buscar VIN..." id="adFVin">'+
      '<select class="ad-select" id="adFEstado"><option value="">Estado</option>'+
        ['por_llegar','recibida','por_ensamblar','en_ensamble','lista_para_entrega','por_validar_entrega','entregada','retenida']
        .map(function(e){return '<option>'+e+'</option>';}).join('')+'</select>'+
      '<button class="ad-btn sm ghost" id="adFApply">Filtrar</button>'+
    '</div>';
    // Table
    html += '<div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>VIN</th><th>Modelo</th><th>Color</th><th>Estado</th><th>Punto</th><th>Cliente</th><th>Pago</th><th></th></tr></thead><tbody>';
    (r.motos||[]).forEach(function(m){
      html += '<tr>'+
        '<td>'+( m.vin_display||m.vin||'—')+'</td>'+
        '<td>'+m.modelo+'</td><td>'+m.color+'</td>'+
        '<td>'+ADApp.badgeEstado(m.estado)+'</td>'+
        '<td>'+(m.punto_voltika_nombre||'—')+'</td>'+
        '<td>'+(m.cliente_nombre||'—')+'</td>'+
        '<td>'+ADApp.badgeEstado(m.pago_estado||'—')+'</td>'+
        '<td><button class="ad-btn sm ghost adDetail" data-id="'+m.id+'">Ver</button></td>'+
      '</tr>';
    });
    html += '</tbody></table></div>';
    // Pagination
    if(r.pages>1){
      html += '<div class="ad-pagination">';
      for(var p=1;p<=r.pages;p++) html += '<button class="'+(p===r.page?'active':'')+' adPage" data-p="'+p+'">'+p+'</button>';
      html += '</div>';
    }
    ADApp.render(html);
    $('#adFApply').on('click',function(){ filters.vin=$('#adFVin').val(); filters.estado=$('#adFEstado').val(); load(); });
    $('.adDetail').on('click',function(){ showDetail($(this).data('id')); });
    $('.adPage').on('click',function(){ filters.page=$(this).data('p'); load(); });
    $('#adNewMoto').on('click', showNewForm);
    $('#adImportExcel').on('click', showImportForm);
  }
  function showDetail(id){
    ADApp.api('inventario/detalle.php?id='+id).done(function(r){
      var m=r.moto; if(!m) return;
      var html = '<div class="ad-h2">'+m.modelo+' — '+m.color+'</div>';

      // Vehicle info
      html += '<div style="font-size:11px;font-weight:600;color:var(--ad-dim);margin:10px 0 4px;text-transform:uppercase;">Vehículo</div>';
      html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px">';
      [['VIN',m.vin_display||m.vin],['Año modelo',m.anio_modelo||'—'],['Núm. motor',m.num_motor||'—'],
       ['Potencia',m.potencia||'—'],['Baterías',m.config_baterias||'—'],['Hecho en',m.hecho_en||'—'],
       ['Estado',m.estado],['Tipo asignación',m.tipo_asignacion||'—']].forEach(function(p){
        html += '<div><span style="color:var(--ad-dim)">'+p[0]+':</span> <strong>'+p[1]+'</strong></div>';
      });
      html += '</div>';

      // Import info
      if(m.num_pedimento || m.aduana || m.cedis_origen){
        html += '<div style="font-size:11px;font-weight:600;color:var(--ad-dim);margin:10px 0 4px;text-transform:uppercase;">Importación</div>';
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px">';
        [['Pedimento',m.num_pedimento||'—'],['Aduana',m.aduana||'—'],
         ['Ingreso país',m.fecha_ingreso_pais||'—'],['CEDIS origen',m.cedis_origen||'—']].forEach(function(p){
          html += '<div><span style="color:var(--ad-dim)">'+p[0]+':</span> <strong>'+p[1]+'</strong></div>';
        });
        html += '</div>';
      }

      // Customer & order info
      html += '<div style="font-size:11px;font-weight:600;color:var(--ad-dim);margin:10px 0 4px;text-transform:uppercase;">Cliente / Pedido</div>';
      html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px">';
      [['Cliente',m.cliente_nombre||'—'],['Email',m.cliente_email||'—'],['Teléfono',m.cliente_telefono||'—'],
       ['Pedido',m.pedido_num||'—'],['Pago',m.pago_estado||'—'],['Punto',m.punto_voltika_nombre||'—'],
       ['Método pago',r.transaccion?r.transaccion.tpago:'—'],['Monto',r.transaccion?ADApp.money(r.transaccion.total):'—'],
       ['Stripe PI',m.stripe_pi||'—'],['Precio venta',m.precio_venta?ADApp.money(m.precio_venta):'—']].forEach(function(p){
        html += '<div><span style="color:var(--ad-dim)">'+p[0]+':</span> <strong>'+p[1]+'</strong></div>';
      });
      html += '</div>';

      // Dates
      html += '<div style="font-size:11px;font-weight:600;color:var(--ad-dim);margin:10px 0 4px;text-transform:uppercase;">Fechas</div>';
      html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px">';
      [['Llegada',m.fecha_llegada||'—'],['Estimada llegada',m.fecha_estimada_llegada||'—'],
       ['Estimada entrega',m.fecha_entrega_estimada||'—'],['Último cambio estado',m.fecha_estado||'—'],
       ['Días en paso',m.dias_en_paso||'0'],['Recepción',m.recepcion_completada?'Completada':'Pendiente']].forEach(function(p){
        html += '<div><span style="color:var(--ad-dim)">'+p[0]+':</span> <strong>'+p[1]+'</strong></div>';
      });
      html += '</div>';

      // Notes
      if(m.notas){
        html += '<div style="font-size:11px;font-weight:600;color:var(--ad-dim);margin:10px 0 4px;text-transform:uppercase;">Notas</div>';
        html += '<div style="font-size:13px;padding:8px;background:var(--ad-bg);border-radius:6px;">'+m.notas+'</div>';
      }
      // Checklists status
      html += '<div class="ad-h2">Checklists</div>';
      html += '<div style="display:flex;gap:8px">';
      html += '<span class="ad-badge '+(r.checklist_origen&&r.checklist_origen.completado?'green':'yellow')+'">'+(r.checklist_origen?'Origen ✓':'Origen ✗')+'</span>';
      html += '<span class="ad-badge '+(r.checklist_ensamble&&r.checklist_ensamble.completado?'green':'yellow')+'">'+(r.checklist_ensamble?'Ensamble ✓':'Ensamble ✗')+'</span>';
      html += '<span class="ad-badge '+(r.checklist_entrega&&r.checklist_entrega.completado?'green':'yellow')+'">'+(r.checklist_entrega?'Entrega ✓':'Entrega ✗')+'</span>';
      html += '</div>';
      // Envios
      if(r.envios&&r.envios.length){
        html += '<div class="ad-h2">Envíos</div>';
        r.envios.forEach(function(e){
          html += '<div class="ad-card" style="padding:10px;font-size:12px">→ '+e.punto_nombre+' · '+ADApp.badgeEstado(e.estado)+' · '+(e.fecha_envio||'sin fecha')+'</div>';
        });
      }
      // Assign to point action
      var origenOk = r.checklist_origen && r.checklist_origen.completado;
      if(ADApp.canWrite()){
        html += '<div class="ad-h2">Acciones</div>';
        if(!origenOk){
          html += '<div style="padding:10px;border-radius:8px;background:rgba(239,68,68,.08);color:#b91c1c;font-size:12px;margin-bottom:8px;">El checklist de origen debe estar completo antes de asignar a un punto.</div>';
        }
        html += '<button class="ad-btn primary" id="adAssign" data-id="'+m.id+'" '+(origenOk?'':'disabled style="opacity:.5;cursor:not-allowed;"')+'>Asignar a punto</button> ';
        html += '<button class="ad-btn ghost" id="adVerifyPay" data-id="'+m.id+'">Verificar pago</button>';
      }
      ADApp.modal(html);
      $('#adAssign').on('click',function(){ if(!origenOk) return; assignToPunto(m.id, {modelo:m.modelo,color:m.color}); });
      $('#adVerifyPay').on('click',function(){
        ADApp.api('pagos/verificar.php',{moto_id:m.id}).done(function(r2){
          alert(r2.verificado?'✅ Pago verificado':'⚠️ No verificado: '+(r2.stripe_status||'sin Stripe PI'));
        });
      });
    });
  }
  function assignToPunto(motoId, motoInfo){
    // Step 1: Choose assignment type
    var html = '<div class="ad-h2">Asignar moto</div>'+
      '<div class="ad-dim" style="margin-bottom:16px;">Selecciona el tipo de asignación:</div>'+
      '<div id="adAssignOptions" style="display:flex;flex-direction:column;gap:10px;">'+
        '<div class="ad-card" style="cursor:pointer;padding:16px;border:2px solid transparent;" id="adOptInventario">'+
          '<strong style="font-size:15px;">Inventario para venta en punto</strong>'+
          '<div class="ad-dim" style="margin-top:4px;">Enviar moto al punto para exhibición y venta directa. Sin orden de compra vinculada.</div>'+
        '</div>'+
        '<div class="ad-card" style="cursor:pointer;padding:16px;border:2px solid transparent;" id="adOptVenta">'+
          '<strong style="font-size:15px;">Venta</strong>'+
          '<div class="ad-dim" style="margin-top:4px;">Vincular esta moto a una orden de compra existente y enviar al punto de entrega.</div>'+
        '</div>'+
      '</div>';
    ADApp.modal(html);

    $('#adOptInventario').on('click', function(){ assignInventario(motoId); });
    $('#adOptVenta').on('click', function(){ assignVenta(motoId, motoInfo); });
  }

  // ── Option A: Inventario para venta en punto ──────────────────────────
  function assignInventario(motoId){
    ADApp.api('puntos/listar.php').done(function(r){
      var html = '<div class="ad-h2">Inventario — Seleccionar punto</div>';
      html += '<div id="adPuntosList">';
      (r.puntos||[]).forEach(function(p){
        html += '<div class="ad-card adPuntoCard" style="cursor:pointer;padding:10px;margin-bottom:6px;" data-pid="'+p.id+'" data-cp="'+(p.cp||'')+'">'+
          '<strong>'+p.nombre+'</strong> · '+(p.ciudad||'')+' · Inv: '+(p.inventario_actual||0)+
        '</div>';
      });
      html += '</div>';
      html += '<div id="adQuoteInfo" style="display:none;margin-top:12px;padding:12px;border-radius:8px;background:#E3F2FD;font-size:13px;"></div>';
      html += '<div id="adAssignBtn" style="display:none;margin-top:12px;"></div>';
      ADApp.modal(html);

      $('.adPuntoCard').on('click', function(){
        $('.adPuntoCard').css('border-color','transparent');
        $(this).css('border-color','var(--ad-primary)');
        var pid = $(this).data('pid');
        var cp = $(this).data('cp');
        showQuoteAndConfirm(motoId, pid, 'inventario', null, cp);
      });
    });
  }

  // ── Option B: Venta ───────────────────────────────────────────────────
  function assignVenta(motoId, motoInfo){
    ADApp.api('ventas/sin-punto.php').done(function(r){
      if(!r.ok || !r.rows.length){
        ADApp.modal(
          '<div class="ad-h2">Venta — Sin órdenes pendientes</div>'+
          '<div class="ad-dim" style="padding:20px;text-align:center;">No hay órdenes de compra sin moto asignada.</div>'
        );
        return;
      }

      var html = '<div class="ad-h2">Venta — Seleccionar orden</div>'+
        '<div class="ad-dim" style="margin-bottom:10px;">Órdenes sin moto asignada: <strong>'+r.rows.length+'</strong></div>'+
        '<div style="max-height:400px;overflow-y:auto;">';

      r.rows.forEach(function(o){
        var hasPunto = o.punto_id && o.punto_nombre;
        html += '<div class="ad-card adOrderCard" style="cursor:pointer;padding:10px;margin-bottom:6px;" '+
          'data-tid="'+o.id+'" data-pid="'+(o.punto_id||'')+'" data-pname="'+(o.punto_nombre||'')+'">'+
          '<div style="display:flex;justify-content:space-between;align-items:center;">'+
            '<div>'+
              '<strong>VK-'+(o.pedido||o.id)+'</strong> · '+(o.nombre||'')+'<br>'+
              '<small class="ad-dim">'+o.modelo+' · '+o.color+' · '+ADApp.money(o.monto)+'</small>'+
            '</div>'+
            '<div style="text-align:right;">'+
              (hasPunto
                ? '<span class="ad-badge green" style="font-size:11px;">Punto: '+o.punto_nombre+'</span>'
                : '<span class="ad-badge yellow" style="font-size:11px;">Sin punto</span>')+
            '</div>'+
          '</div>'+
        '</div>';
      });
      html += '</div>';
      html += '<div id="adVentaStep2" style="display:none;margin-top:12px;"></div>';
      html += '<div id="adQuoteInfo" style="display:none;margin-top:12px;padding:12px;border-radius:8px;background:#E3F2FD;font-size:13px;"></div>';
      html += '<div id="adAssignBtn" style="display:none;margin-top:12px;"></div>';
      ADApp.modal(html);

      $('.adOrderCard').on('click', function(){
        $('.adOrderCard').css('border-color','transparent');
        $(this).css('border-color','var(--ad-primary)');
        var tid = $(this).data('tid');
        var pid = $(this).data('pid');
        var pname = $(this).data('pname');

        if(pid){
          // User already chose a punto → auto-assign, just confirm
          $('#adVentaStep2').html(
            '<div style="padding:10px;background:#E8F5E9;border-radius:8px;font-size:13px;">'+
              'Punto seleccionado por el cliente: <strong>'+pname+'</strong>'+
            '</div>'
          ).show();
          // Find punto_voltika_id from punto_id string
          lookupPuntoId(pid, function(pvId, cp){
            showQuoteAndConfirm(motoId, pvId, 'venta', tid, cp);
          });
        } else {
          // No punto selected → show punto list
          $('#adVentaStep2').show();
          loadPuntosForVenta(motoId, tid);
        }
      });
    });
  }

  function lookupPuntoId(puntoIdStr, callback){
    ADApp.api('puntos/listar.php').done(function(r){
      var found = null;
      (r.puntos||[]).forEach(function(p){
        // Match by id or by nombre
        if(String(p.id) === String(puntoIdStr) || p.nombre === puntoIdStr){
          found = p;
        }
      });
      if(found){
        callback(found.id, found.cp||'');
      } else {
        // Fallback: use first punto or show error
        $('#adQuoteInfo').html('<div style="color:orange;">No se encontró el punto del cliente. Selecciona manualmente.</div>').show();
        loadPuntosForVenta(arguments[0], arguments[1]); // won't work perfectly, fallback
      }
    });
  }

  function loadPuntosForVenta(motoId, transId){
    ADApp.api('puntos/listar.php').done(function(r){
      var html = '<div style="font-weight:600;margin-bottom:8px;">Seleccionar punto de entrega:</div>';
      (r.puntos||[]).forEach(function(p){
        html += '<div class="ad-card adPuntoVenta" style="cursor:pointer;padding:8px;margin-bottom:4px;font-size:13px;" '+
          'data-pid="'+p.id+'" data-cp="'+(p.cp||'')+'">'+
          '<strong>'+p.nombre+'</strong> · '+(p.ciudad||'')+
        '</div>';
      });
      $('#adVentaStep2').html(html);

      $('.adPuntoVenta').on('click', function(){
        $('.adPuntoVenta').css('border-color','transparent');
        $(this).css('border-color','var(--ad-primary)');
        var pid = $(this).data('pid');
        var cp = $(this).data('cp');
        showQuoteAndConfirm(motoId, pid, 'venta', transId, cp);
      });
    });
  }

  // ── Shared: show Skydropx quote + confirm button ──────────────────────
  function showQuoteAndConfirm(motoId, puntoId, tipo, transId, puntoCp){
    var $info = $('#adQuoteInfo');
    var $btn = $('#adAssignBtn');

    // Try to get Skydropx quote
    $info.html('<span class="ad-spin"></span> Consultando fecha estimada de envío...').show();
    $btn.hide();

    ADApp.api('inventario/cotizar-envio.php', {punto_id: puntoId}).done(function(q){
      if(q.ok){
        $info.html(
          '<strong>Envío estimado:</strong> '+q.dias+' días hábiles<br>'+
          '<strong>Fecha estimada:</strong> '+q.fecha_estimada+'<br>'+
          '<strong>Carrier:</strong> '+q.carrier+(q.servicio?' ('+q.servicio+')':'')
        ).show();
        renderConfirmBtn(motoId, puntoId, tipo, transId, q.fecha_estimada);
      } else {
        // Skydropx failed — allow manual date
        $info.html(
          '<div style="color:orange;">No se pudo obtener cotización automática: '+(q.error||'')+'</div>'+
          '<div style="margin-top:8px;">Fecha estimada (manual): <input type="date" class="ad-input" id="adFechaManual" style="width:180px;display:inline-block;"></div>'
        ).show();
        renderConfirmBtn(motoId, puntoId, tipo, transId, null);
      }
    }).fail(function(){
      $info.html(
        '<div style="color:orange;">Error de conexión al cotizar.</div>'+
        '<div style="margin-top:8px;">Fecha estimada (manual): <input type="date" class="ad-input" id="adFechaManual" style="width:180px;display:inline-block;"></div>'
      ).show();
      renderConfirmBtn(motoId, puntoId, tipo, transId, null);
    });
  }

  function renderConfirmBtn(motoId, puntoId, tipo, transId, fechaAuto){
    var $btn = $('#adAssignBtn');
    $btn.html('<button class="ad-btn primary" id="adDoAssign" style="width:100%;padding:10px;font-size:14px;">Confirmar asignación</button>').show();

    $('#adDoAssign').on('click', function(){
      var fecha = fechaAuto || ($('#adFechaManual').length ? $('#adFechaManual').val() : null);
      var payload = {
        moto_id: motoId,
        punto_id: puntoId,
        tipo: tipo,
        fecha_estimada: fecha || null
      };
      if(transId) payload.transaccion_id = transId;

      $(this).prop('disabled',true).html('<span class="ad-spin"></span> Asignando...');

      ADApp.api('inventario/asignar-punto.php', payload).done(function(r){
        if(r.ok){
          ADApp.closeModal();
          alert('Moto asignada correctamente'+(r.fecha_estimada?' — Llegada estimada: '+r.fecha_estimada:''));
          load();
        } else {
          alert(r.error||'Error al asignar');
          $('#adDoAssign').prop('disabled',false).html('Confirmar asignación');
        }
      }).fail(function(x){
        alert((x.responseJSON&&x.responseJSON.error)||'Error de conexión');
        $('#adDoAssign').prop('disabled',false).html('Confirmar asignación');
      });
    });
  }
  // Catalog: same models and colors the customer sees on the website
  var CATALOGO = [
    {modelo:'M05',           colores:['Gris','Negro','Plata']},
    {modelo:'M03',           colores:['Negro','Gris','Plata']},
    {modelo:'Ukko S+',       colores:['Gris','Negro','Azul','Naranja']},
    {modelo:'MC10 Streetx',  colores:['Negro','Gris']},
    {modelo:'Pesgo Plus',    colores:['Negro','Gris','Azul','Plata']},
    {modelo:'Mino-B',        colores:['Gris','Azul','Verde']},
  ];

  function showNewForm(){
    var html = '<div class="ad-h2">Nueva moto</div>'+
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">';

    // VIN
    html += '<div><label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:2px;">VIN *</label>'+
      '<input class="ad-input" id="nmVin" placeholder="Número de identificación vehicular" style="width:100%"></div>';

    // Modelo select
    html += '<div><label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:2px;">Modelo *</label>'+
      '<select class="ad-input" id="nmModelo" style="width:100%"><option value="">— Seleccionar modelo —</option>';
    CATALOGO.forEach(function(c){ html += '<option value="'+c.modelo+'">'+c.modelo+'</option>'; });
    html += '</select></div>';

    // Color select (populated on modelo change)
    html += '<div><label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:2px;">Color *</label>'+
      '<select class="ad-input" id="nmColor" style="width:100%" disabled><option value="">— Primero selecciona modelo —</option></select></div>';

    // Remaining fields
    var fields = [
      {id:'nmAnio',     label:'Año modelo',        ph:new Date().getFullYear()},
      {id:'nmMotor',    label:'Núm. motor',        ph:'Número de motor'},
      {id:'nmPotencia', label:'Potencia',           ph:'500W, 1000W, etc.'},
      {id:'nmBaterias', label:'Config. baterías',   ph:'', type:'select', opts:['1','2']},
      {id:'nmHecho',    label:'Hecho en',           ph:'País de fabricación'},
      {id:'nmPedimento',label:'Núm. pedimento',     ph:'Número de pedimento'},
      {id:'nmFechaIng', label:'Fecha ingreso país', ph:'', type:'date'},
      {id:'nmAduana',   label:'Aduana',             ph:'Aduana de ingreso'},
      {id:'nmCedis',    label:'CEDIS origen',       ph:'Centro de distribución'},
    ];

    fields.forEach(function(f){
      html += '<div><label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:2px;">'+f.label+'</label>';
      if(f.type === 'select'){
        html += '<select class="ad-input" id="'+f.id+'" style="width:100%">';
        f.opts.forEach(function(o){ html += '<option value="'+o+'">'+o+'</option>'; });
        html += '</select>';
      } else if(f.type === 'date'){
        html += '<input type="date" class="ad-input" id="'+f.id+'" style="width:100%">';
      } else {
        html += '<input class="ad-input" id="'+f.id+'" placeholder="'+f.ph+'" style="width:100%">';
      }
      html += '</div>';
    });

    html += '</div>'+
      '<div style="margin-top:8px;">'+
        '<label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:2px;">Descripción / Notas</label>'+
        '<textarea class="ad-input" id="nmNotas" placeholder="Notas adicionales" style="width:100%;min-height:60px;"></textarea>'+
      '</div>'+
      '<button class="ad-btn primary" id="nmSave" style="margin-top:12px;width:100%;">Guardar</button>';

    ADApp.modal(html);

    // Cascade: modelo → color
    $(document).off('change.nmModelo').on('change.nmModelo', '#nmModelo', function(){
      var sel = $(this).val();
      var $color = $('#nmColor');
      $color.empty();
      if(!sel){
        $color.append('<option value="">— Primero selecciona modelo —</option>').prop('disabled',true);
        return;
      }
      var cat = null;
      for(var i=0; i<CATALOGO.length; i++){
        if(CATALOGO[i].modelo === sel){ cat = CATALOGO[i]; break; }
      }
      if(!cat){ $color.append('<option value="">Sin colores</option>'); return; }
      $color.prop('disabled',false);
      $color.append('<option value="">— Seleccionar color —</option>');
      for(var j=0; j<cat.colores.length; j++){
        $color.append('<option value="'+cat.colores[j]+'">'+cat.colores[j]+'</option>');
      }
    });

    $('#nmSave').on('click',function(){
      var vin = $('#nmVin').val().trim();
      var modelo = $('#nmModelo').val();
      var color = $('#nmColor').val();
      if(!vin || !modelo || !color){
        alert('VIN, Modelo y Color son obligatorios');
        return;
      }
      $(this).prop('disabled',true).html('<span class="ad-spin"></span> Guardando...');
      ADApp.api('inventario/crear.php',{
        vin: vin,
        modelo: modelo,
        color: color,
        anio_modelo: $('#nmAnio').val(),
        num_motor: $('#nmMotor').val(),
        potencia: $('#nmPotencia').val(),
        config_baterias: $('#nmBaterias').val(),
        hecho_en: $('#nmHecho').val(),
        num_pedimento: $('#nmPedimento').val(),
        fecha_ingreso_pais: $('#nmFechaIng').val() || null,
        aduana: $('#nmAduana').val(),
        cedis_origen: $('#nmCedis').val(),
        notas: $('#nmNotas').val()
      }).done(function(r){
        if(r.ok){ ADApp.closeModal(); load(); } else { alert(r.error||'Error'); $('#nmSave').prop('disabled',false).html('Guardar'); }
      }).fail(function(){ alert('Error de conexión'); $('#nmSave').prop('disabled',false).html('Guardar'); });
    });
  }
  function showImportForm(){
    ADApp.modal(
      '<div class="ad-h2">Importar motos desde Excel</div>'+
      '<div class="ad-dim" style="margin-bottom:12px;">Formato: CSV o XLSX con columnas VIN, Modelo, Color, Año, Num_motor, Potencia, Config_baterias, Hecho_en, Num_pedimento, Aduana, CEDIS_origen, Notas</div>'+
      '<div style="margin-bottom:12px;">'+
        '<a href="#" id="adDlTemplate" style="color:var(--ad-primary);font-size:13px;">Descargar plantilla CSV</a>'+
      '</div>'+
      '<input type="file" id="adImportFile" accept=".csv,.xlsx,.txt" class="ad-input" style="margin-bottom:12px">'+
      '<div id="adImportPreview" style="display:none;margin-bottom:12px;max-height:200px;overflow-y:auto;font-size:12px;"></div>'+
      '<div id="adImportResult" style="display:none;margin-bottom:12px;"></div>'+
      '<button class="ad-btn primary" id="adImportBtn" disabled>Importar</button>'
    );

    $('#adDlTemplate').on('click', function(e){
      e.preventDefault();
      var csv = 'VIN,Modelo,Color,Año,Num_motor,Potencia,Config_baterias,Hecho_en,Num_pedimento,Aduana,CEDIS_origen,Notas\n';
      var blob = new Blob(['\uFEFF' + csv], {type:'text/csv;charset=utf-8;'});
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'plantilla_inventario.csv';
      a.click();
    });

    $('#adImportFile').on('change', function(){
      var file = this.files[0];
      if(!file) return;
      // Preview for CSV
      if(file.name.match(/\.(csv|txt)$/i)){
        var reader = new FileReader();
        reader.onload = function(e){
          var lines = e.target.result.split('\n').filter(function(l){return l.trim();});
          if(lines.length < 2){ $('#adImportPreview').html('<div style="color:red;">Archivo vacío</div>').show(); return; }
          var html = '<strong>' + (lines.length-1) + ' motos detectadas</strong><br>';
          html += '<table class="ad-table" style="font-size:11px"><thead><tr>';
          var headers = lines[0].split(',');
          headers.forEach(function(h){ html += '<th>'+h.trim()+'</th>'; });
          html += '</tr></thead><tbody>';
          var max = Math.min(lines.length, 6);
          for(var i=1; i<max; i++){
            html += '<tr>';
            lines[i].split(',').forEach(function(c){ html += '<td>'+c.trim()+'</td>'; });
            html += '</tr>';
          }
          if(lines.length > 6) html += '<tr><td colspan="'+headers.length+'" style="text-align:center">... y ' + (lines.length-6) + ' más</td></tr>';
          html += '</tbody></table>';
          $('#adImportPreview').html(html).show();
          $('#adImportBtn').prop('disabled', false);
        };
        reader.readAsText(file);
      } else {
        // XLSX — just show file name, server will parse
        $('#adImportPreview').html('<strong>Archivo: </strong>' + file.name + ' (' + Math.round(file.size/1024) + ' KB)').show();
        $('#adImportBtn').prop('disabled', false);
      }
    });

    $('#adImportBtn').on('click', function(){
      var file = $('#adImportFile')[0].files[0];
      if(!file) return;
      var $btn = $(this);
      $btn.prop('disabled', true).html('<span class="ad-spin"></span> Importando...');

      var fd = new FormData();
      fd.append('archivo', file);

      $.ajax({
        url: 'php/inventario/importar.php',
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        xhrFields: { withCredentials: true },
        dataType: 'json'
      }).done(function(r){
        if(r.ok){
          var html = '<div style="padding:12px;border-radius:8px;background:#E8F5E9;color:#2E7D32;">'+
            '<strong>Importacion completada</strong><br>'+
            'Creadas: <strong>'+r.creados+'</strong> · '+
            'Duplicadas: '+r.duplicados+' · '+
            'Errores: '+r.errores+' · '+
            'Total filas: '+r.total_filas+
            '</div>';
          if(r.detalle && r.detalle.length){
            html += '<div style="margin-top:8px;font-size:11px;color:#666;">';
            r.detalle.forEach(function(d){ html += d + '<br>'; });
            html += '</div>';
          }
          $('#adImportResult').html(html).show();
          $btn.html('Cerrar').prop('disabled',false).off('click').on('click',function(){
            ADApp.closeModal();
            load();
          });
        } else {
          $('#adImportResult').html('<div style="padding:12px;border-radius:8px;background:#FFEBEE;color:#C62828;">'+r.error+'</div>').show();
          $btn.html('Importar').prop('disabled', false);
        }
      }).fail(function(){
        $('#adImportResult').html('<div style="padding:12px;border-radius:8px;background:#FFEBEE;color:#C62828;">Error de conexion</div>').show();
        $btn.html('Importar').prop('disabled', false);
      });
    });
  }

  return { render:render };
})();
