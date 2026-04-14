window.AD_inventario = (function(){
  var filters = {};
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';
  function render(){
    ADApp.render('<div class="ad-h1">CEDIS</div><div><span class="ad-spin"></span> Cargando...</div>');
    load();
  }
  function load(){
    ADApp.api('inventario/listar.php?' + $.param(filters)).done(paint);
  }
  function paint(r){
    var html = _backBtn+'<div class="ad-toolbar"><div class="ad-h1">CEDIS</div>';
    if(ADApp.isAdmin()){
      html += '<div style="display:flex;gap:6px">'+
        '<button class="ad-btn primary" id="adNewMoto">+ Nueva moto</button>'+
        '<button class="ad-btn ghost" id="adImportExcel">Importar Excel</button>'+
        '</div>';
    }
    html += '</div>';
    // Summary KPIs
    var s = r.resumen||{};
    html += '<div class="ad-kpis">';
    [{l:'Total',v:s.total,c:'blue'},{l:'Existencias',v:s.disponible,c:'green'},{l:'Reservado',v:s.reservado,c:'yellow'},
     {l:'Entregado',v:s.entregado,c:'green'},{l:'En tránsito',v:s.en_transito,c:'blue'},
     {l:'En ensamble',v:s.en_ensamble,c:'yellow'},{l:'Total en puntos',v:s.en_puntos,c:'blue'},
     {l:'Bloqueado',v:s.bloqueado,c:'red'}].forEach(function(k){
      html += '<div class="ad-kpi"><div class="label">'+k.l+'</div><div class="value '+(k.c||'')+'">'+Number(k.v||0)+'</div></div>';
    });
    html += '</div>';
    // Model summary
    var pm = r.por_modelo||[];
    if(pm.length){
      html += '<div style="margin-bottom:16px;"><div style="font-weight:700;font-size:13px;margin-bottom:8px;color:var(--ad-navy);">Unidades por modelo</div>';
      html += '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
      pm.forEach(function(m){
        html += '<div style="background:var(--ad-surface);border:1px solid var(--ad-border);border-radius:var(--ad-radius-sm);padding:10px 16px;text-align:center;min-width:100px;">'+
          '<div style="font-size:22px;font-weight:800;color:var(--ad-navy);">'+m.cnt+'</div>'+
          '<div style="font-size:11px;font-weight:600;color:var(--ad-dim);text-transform:uppercase;">'+m.modelo+'</div>'+
        '</div>';
      });
      html += '</div></div>';
    }
    // Filters
    var modeloOpts = '<option value="">Modelo</option>';
    (r.modelos||[]).forEach(function(m){ modeloOpts += '<option value="'+m+'"'+(filters.modelo===m?' selected':'')+'>'+m+'</option>'; });
    html += '<div class="ad-filters">'+
      '<input class="ad-input" style="width:160px" placeholder="Buscar VIN..." id="adFVin" value="'+(filters.vin||'')+'">'+
      '<select class="ad-select" id="adFModelo">'+modeloOpts+'</select>'+
      '<select class="ad-select" id="adFEstado"><option value="">Estado</option>'+
        ['por_llegar','recibida','por_ensamblar','en_ensamble','lista_para_entrega','por_validar_entrega','entregada','retenida']
        .map(function(e){return '<option'+(filters.estado===e?' selected':'')+'>'+e+'</option>';}).join('')+'</select>'+
      '<select class="ad-select" id="adFChecklist"><option value="">Checklist</option>'+
        '<option value="sin"'+(filters.checklist==='sin'?' selected':'')+'>Sin checklist</option>'+
        '<option value="origen"'+(filters.checklist==='origen'?' selected':'')+'>Con origen</option>'+
        '<option value="ensamble"'+(filters.checklist==='ensamble'?' selected':'')+'>Con ensamble</option>'+
        '<option value="completo"'+(filters.checklist==='completo'?' selected':'')+'>Completo</option>'+
      '</select>'+
      '<button class="ad-btn sm ghost" id="adFApply">Filtrar</button>'+
    '</div>';
    // Group motos by model
    var motos = r.motos||[];
    var groups = {};
    var modelOrder = [];
    motos.forEach(function(m){
      var mod = m.modelo||'Sin modelo';
      if(!groups[mod]){ groups[mod] = []; modelOrder.push(mod); }
      groups[mod].push(m);
    });
    modelOrder.sort();
    // Render grouped tables
    modelOrder.forEach(function(mod){
      html += '<div style="margin-bottom:20px;">';
      html += '<div style="font-weight:700;font-size:15px;color:var(--ad-navy);margin-bottom:6px;padding-left:4px;">'+mod+' <span style="font-weight:400;color:var(--ad-dim);font-size:13px;">('+groups[mod].length+')</span></div>';
      html += '<div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>VIN</th><th>Color</th><th>Estado</th><th>Punto</th><th>Días</th><th>Cliente</th><th>Pago</th><th></th></tr></thead><tbody>';
      groups[mod].forEach(function(m){
        var diasCell = '—';
        if(m.dias_en_punto !== null && m.dias_en_punto !== undefined){
          var dp = parseInt(m.dias_en_punto);
          var dpC = dp <= 7 ? '#059669' : dp <= 30 ? '#d97706' : '#dc2626';
          diasCell = '<span style="font-weight:700;color:'+dpC+';">'+dp+'d</span>';
        }
        html += '<tr>'+
          '<td>'+(m.vin_display||m.vin||'—')+'</td>'+
          '<td>'+m.color+'</td>'+
          '<td>'+ADApp.badgeEstado(m.estado)+'</td>'+
          '<td>'+(m.punto_voltika_nombre||'—')+'</td>'+
          '<td>'+diasCell+'</td>'+
          '<td>'+(m.cliente_nombre||'—')+'</td>'+
          '<td>'+ADApp.badgeEstado(m.pago_estado||'—')+'</td>'+
          '<td><button class="ad-btn sm ghost adDetail" data-id="'+m.id+'">Ver</button></td>'+
        '</tr>';
      });
      html += '</tbody></table></div></div>';
    });
    // Pagination
    if(r.pages>1){
      html += '<div class="ad-pagination">';
      for(var p=1;p<=r.pages;p++) html += '<button class="'+(p===r.page?'active':'')+' adPage" data-p="'+p+'">'+p+'</button>';
      html += '</div>';
    }
    ADApp.render(html);
    $('#adFApply').on('click',function(){
      filters.vin=$('#adFVin').val();
      filters.modelo=$('#adFModelo').val();
      filters.estado=$('#adFEstado').val();
      filters.checklist=$('#adFChecklist').val();
      filters.page=1;
      load();
    });
    $('.adDetail').on('click',function(){ showDetail($(this).data('id')); });
    $('.adPage').on('click',function(){ filters.page=$(this).data('p'); load(); });
    $('#adNewMoto').on('click', showNewForm);
    $('#adImportExcel').on('click', showImportForm);
  }
  function showDetail(id){
    ADApp.api('inventario/detalle.php?id='+id).done(function(r){
      var m=r.moto; if(!m) return;

      // ── Styled helpers ──
      var secIx = 0;
      function secHead(title, icon){
        return '<div style="display:flex;align-items:center;gap:8px;margin:24px 0 12px;padding-bottom:10px;border-bottom:1px solid var(--ad-border);">'+
          '<div style="color:var(--ad-primary);">'+icon+'</div>'+
          '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ad-primary);">'+title+'</div></div>';
      }
      function fRow(label, value){
        var bg = secIx++ % 2 === 0 ? 'background:var(--ad-surface-2);' : '';
        return '<div style="'+bg+'padding:8px 14px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(0,0,0,.04);">'+
          '<span style="color:var(--ad-dim);font-size:12px;font-weight:500;white-space:nowrap;margin-right:12px;">'+label+'</span>'+
          '<span style="font-size:13px;font-weight:600;color:var(--ad-navy);text-align:right;">'+(value||'—')+'</span></div>';
      }

      // ── Modal header ──
      var estadoColor = {por_llegar:'blue',recibida:'green',por_ensamblar:'yellow',en_ensamble:'yellow',lista_para_entrega:'green',entregada:'green',retenida:'red'}[m.estado]||'gray';
      var html = '<div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;padding-bottom:18px;border-bottom:2px solid var(--ad-border);">';
      html += '<div style="width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#039fe1,#0280b5);display:flex;align-items:center;justify-content:center;flex-shrink:0;">';
      html += '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></div>';
      html += '<div style="flex:1;min-width:0;"><div style="font-size:20px;font-weight:800;color:var(--ad-navy);line-height:1.2;">'+m.modelo+' — '+m.color+'</div>';
      html += '<div style="display:flex;align-items:center;gap:8px;margin-top:4px;"><span class="ad-badge '+estadoColor+'">'+m.estado+'</span>';
      if(m.vin_display||m.vin) html += '<code style="font-size:11px;background:var(--ad-surface-2);padding:2px 8px;border-radius:4px;">'+( m.vin_display||m.vin)+'</code>';
      html += '</div></div></div>';

      // ── Vehículo ──
      secIx = 0;
      html += secHead('Vehículo','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>');
      html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:8px;">';
      html += fRow('VIN', '<code style="font-size:11px;background:var(--ad-surface-2);padding:2px 6px;border-radius:4px;">'+(m.vin_display||m.vin)+'</code>');
      html += fRow('Año modelo', m.anio_modelo||'—');
      html += fRow('Núm. motor', m.num_motor||'—');
      html += fRow('Potencia', m.potencia||'—');
      html += fRow('Baterías', m.config_baterias||'—');
      html += fRow('Hecho en', m.hecho_en||'—');
      html += fRow('Tipo asignación', m.tipo_asignacion||'—');
      html += '</div>';

      // ── Importación ──
      if(m.num_pedimento || m.aduana || m.cedis_origen){
        secIx = 0;
        html += secHead('Importación','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>');
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:8px;">';
        html += fRow('Pedimento', m.num_pedimento||'—');
        html += fRow('Aduana', m.aduana||'—');
        html += fRow('Ingreso país', m.fecha_ingreso_pais||'—');
        html += fRow('CEDIS origen', m.cedis_origen||'—');
        html += '</div>';
      }

      // ── Cliente / Pedido ──
      secIx = 0;
      html += secHead('Cliente / Pedido','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>');
      html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:8px;">';
      html += fRow('Cliente', m.cliente_nombre||'—');
      html += fRow('Email', m.cliente_email||'—');
      html += fRow('Teléfono', m.cliente_telefono||'—');
      html += fRow('Pedido', m.pedido_num||'—');
      html += fRow('Pago', m.pago_estado ? '<span class="ad-badge '+(m.pago_estado==='pagada'?'green':'yellow')+'">'+m.pago_estado+'</span>' : '—');
      html += fRow('Punto', m.punto_voltika_nombre||'—');
      if(m.dias_en_punto !== null && m.dias_en_punto !== undefined){
        var dp = parseInt(m.dias_en_punto);
        var dpColor = dp <= 7 ? '#059669' : dp <= 30 ? '#d97706' : '#dc2626';
        html += fRow('Días en punto', '<span style="font-weight:700;font-size:15px;color:'+dpColor+';">'+dp+' día'+(dp!==1?'s':'')+'</span>');
      }
      html += fRow('Método pago', r.transaccion ? '<span style="display:inline-block;padding:2px 10px;border-radius:20px;background:rgba(3,159,225,.08);color:#039fe1;font-size:12px;font-weight:600;">'+r.transaccion.tpago+'</span>' : '—');
      html += fRow('Monto', r.transaccion ? '<span style="font-size:15px;font-weight:800;">'+ADApp.money(r.transaccion.total)+'</span>' : '—');
      html += fRow('Stripe PI', m.stripe_pi ? '<code style="font-size:10px;background:var(--ad-surface-2);padding:2px 6px;border-radius:4px;">'+m.stripe_pi+'</code>' : '—');
      html += fRow('Precio venta', m.precio_venta ? '<span style="font-weight:700;">'+ADApp.money(m.precio_venta)+'</span>' : '—');
      html += '</div>';

      // ── Fechas ──
      secIx = 0;
      html += secHead('Fechas','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>');
      html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:8px;">';
      html += fRow('Llegada', m.fecha_llegada||'—');
      html += fRow('Estimada llegada', m.fecha_estimada_llegada||'—');
      html += fRow('Estimada entrega', m.fecha_entrega_estimada||'—');
      html += fRow('Último cambio estado', m.fecha_estado||'—');
      html += fRow('Días en paso', '<span style="font-weight:700;color:'+(parseInt(m.dias_en_paso||0)>5?'var(--ad-danger)':'var(--ad-navy)')+';">'+(m.dias_en_paso||'0')+'</span>');
      html += fRow('Recepción', m.recepcion_completada
        ? '<span style="display:inline-flex;align-items:center;gap:4px;color:#059669;font-weight:600;"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#059669" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>Completada</span>'
        : '<span style="color:var(--ad-warning);font-weight:600;">Pendiente</span>');
      html += '</div>';

      // ── Notas ──
      if(m.notas){
        html += secHead('Notas','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>');
        html += '<div style="font-size:13px;padding:12px 16px;background:var(--ad-surface-2);border-radius:8px;border-left:3px solid var(--ad-primary);line-height:1.6;color:var(--ad-navy);">'+m.notas+'</div>';
      }

      // ── Checklists ──
      html += secHead('Checklists','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>');
      html += '<div style="display:flex;gap:10px;flex-wrap:wrap;">';
      var clItems = [
        {key:'checklist_origen',   label:'Origen'},
        {key:'checklist_ensamble', label:'Ensamble'},
        {key:'checklist_entrega',  label:'Entrega'}
      ];
      clItems.forEach(function(cl){
        var done = r[cl.key] && r[cl.key].completado;
        var bg = done ? 'background:rgba(5,150,105,.08);border:1.5px solid rgba(5,150,105,.25);color:#059669;' : 'background:rgba(234,179,8,.08);border:1.5px solid rgba(234,179,8,.25);color:#92400e;';
        var icon = done
          ? '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#059669" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>'
          : '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#92400e" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
        html += '<div style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;'+bg+'">'+icon+' '+cl.label+'</div>';
      });
      html += '</div>';

      // ── Envíos ──
      if(r.envios&&r.envios.length){
        html += secHead('Envíos','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>');
        r.envios.forEach(function(e){
          html += '<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:var(--ad-surface-2);border-radius:8px;margin-bottom:6px;font-size:13px;">';
          html += '<span style="font-weight:600;color:var(--ad-navy);">'+e.punto_nombre+'</span>';
          html += '<span style="display:flex;align-items:center;gap:8px;">'+ADApp.badgeEstado(e.estado)+'<span style="font-size:11px;color:var(--ad-dim);">'+(e.fecha_envio||'sin fecha')+'</span></span>';
          html += '</div>';
        });
      }

      // ── Acciones ──
      var origenOk = r.checklist_origen && r.checklist_origen.completado;
      if(ADApp.canWrite()){
        html += secHead('Acciones','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>');
        if(!origenOk){
          html += '<div style="display:flex;align-items:center;gap:8px;padding:12px 16px;border-radius:8px;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.15);color:#b91c1c;font-size:12px;margin-bottom:12px;">';
          html += '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#b91c1c" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
          html += 'El checklist de origen debe estar completo antes de asignar a un punto.</div>';
        }
        html += '<div style="display:flex;gap:10px;flex-wrap:wrap;">';
        html += '<button class="ad-btn primary" id="adAssign" data-id="'+m.id+'" '+(origenOk?'':'disabled style="opacity:.5;cursor:not-allowed;"')+'>Asignar a punto</button>';
        html += '<button class="ad-btn ghost" id="adVerifyPay" data-id="'+m.id+'">Verificar pago</button>';
        html += '</div>';
      }
      ADApp.modal(html);
      $('#adAssign').on('click',function(){ if(!origenOk) return; assignToPunto(m.id, {modelo:m.modelo,color:m.color}); });
      $('#adVerifyPay').on('click',function(){
        ADApp.api('pagos/verificar.php',{moto_id:m.id}).done(function(r2){
          var msg = r2.verificado ? 'Pago verificado correctamente' : 'No verificado: '+(r2.stripe_status||'sin Stripe PI');
          var bg = r2.verificado ? 'rgba(5,150,105,.08)' : 'rgba(239,68,68,.08)';
          var clr = r2.verificado ? '#059669' : '#b91c1c';
          ADApp.modal('<div style="text-align:center;padding:30px 20px;">'+(r2.verificado?'<svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="#059669" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>':'<svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="#b91c1c" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>')+'<div style="margin-top:12px;font-size:15px;font-weight:700;color:'+clr+';">'+msg+'</div></div>');
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
