window.AD_ventas = (function(){

  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  function render(){
    ADApp.render(
      _backBtn+
      '<div class="ad-toolbar">'+
        '<div class="ad-h1">Ventas / Ordenes</div>'+
        '<div style="display:flex;align-items:center;gap:10px;">'+
          '<span id="vtLastUpdate" style="font-size:11px;color:var(--ad-dim);"></span>'+
          '<button class="ad-btn" id="vtRefresh" style="background:#f0f4f8;color:var(--ad-navy);padding:6px 14px;font-size:13px;">'+
            '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;margin-right:4px;"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>'+
            'Actualizar</button>'+
        '</div>'+
      '</div>'+
      '<div id="vtKpis" class="ad-kpis" style="margin-bottom:14px;"></div>'+
      '<div id="vtTabs" style="display:flex;gap:0;margin-bottom:16px;border-bottom:2px solid var(--ad-border);"></div>'+
      '<div id="vtTable"><div style="text-align:center;padding:40px;"><span class="ad-spin"></span> Cargando ventas...</div></div>'
    );
    loadData();
    $('#vtRefresh').on('click', function(){
      $('#vtTable').html('<div style="text-align:center;padding:40px;"><span class="ad-spin"></span> Actualizando...</div>');
      loadData();
    });
  }

  function loadData(){
    var _loadStart = Date.now();
    ADApp.api('ventas/listar.php').done(function(r){
      var elapsed = ((Date.now() - _loadStart) / 1000).toFixed(1);
      var now = new Date();
      var timeStr = now.getHours().toString().padStart(2,'0') + ':' + now.getMinutes().toString().padStart(2,'0') + ':' + now.getSeconds().toString().padStart(2,'0');
      $('#vtLastUpdate').html('Actualizado: ' + timeStr + ' (' + elapsed + 's)');
      if(!r.ok){ $('#vtTable').html('<div class="ad-card">Error al cargar</div>'); return; }

      // KPIs
      var pendingPunto = (r.rows||[]).filter(function(o){ return o.punto_id==='centro-cercano'; }).length;
      var orfanos = r.orfanos || (r.rows||[]).filter(function(o){ return o.source; }).length;
      $('#vtKpis').html(
        kpi('Total ordenes', r.total, 'blue')+
        kpi('Moto asignada', r.asignadas, 'green')+
        kpi('Sin asignar', r.sin_asignar, r.sin_asignar > 0 ? 'red' : 'green')+
        kpi('Ventas con Pago', r.con_pago||0, 'green')+
        kpi('Ventas sin Pago', r.sin_pago||0, (r.sin_pago||0) > 0 ? 'red' : 'green')+
        kpi('Punto pendiente', pendingPunto, pendingPunto > 0 ? 'yellow' : 'green')+
        kpi('Huérfanos/errores', orfanos, orfanos > 0 ? 'red' : 'green')
      );
      var rows = r.rows || [];
      _lastRows = rows;
      renderTable(rows);
    }).fail(function(){
      $('#vtTable').html('<div class="ad-card">Error de conexion</div>');
    });
  }

  function renderTable(allRows){
    renderTabs(allRows);
    var rows = filterRows(allRows);
    if(!allRows.length){
      $('#vtTable').html('<div class="ad-card" style="text-align:center;padding:32px;">No hay ordenes registradas</div>');
      return;
    }
    if(!rows.length){
      $('#vtTable').html('<div class="ad-card" style="text-align:center;padding:32px;color:var(--ad-dim);">No hay ordenes en esta categoría</div>');
      return;
    }

    var html = '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table"><thead><tr>'+
      '<th>Pedido</th><th>Cliente</th><th>Modelo</th><th>Color</th>'+
      '<th>Tipo</th><th>Monto</th><th>Estatus de Pago</th><th>Punto</th><th>Fecha</th><th>Moto asignada</th><th>Accion</th>'+
      '</tr></thead><tbody>';

    rows.forEach(function(r){
      var asignada = r.moto_id ? true : false;
      var isPendingPunto = r.punto_id === 'centro-cercano';
      var puntoHtml = '';
      if(isPendingPunto){
        puntoHtml = '<span class="ad-badge yellow">Pendiente asignar</span>';
      } else if(r.punto_nombre){
        puntoHtml = '<span class="ad-badge green" style="font-size:11px;">'+r.punto_nombre+'</span>';
      } else if(!r.punto_id){
        puntoHtml = '<span class="ad-badge red">Sin punto</span>';
      } else {
        puntoHtml = '<span class="ad-badge gray">'+r.punto_id+'</span>';
      }

      var tipoBadge = 'blue';
      if(r.tipo === 'credito-orfano') tipoBadge = 'yellow';
      if(r.tipo === 'error-captura') tipoBadge = 'red';
      // Display label: normalize legacy 'unico' → 'contado' for operators.
      var tipoDisplay = (r.tipo === 'unico') ? 'contado' : (r.tipo || '-');
      var alertaHtml = r.alerta
        ? '<div style="font-size:11px;color:#b91c1c;margin-top:2px;">'+esc(r.alerta)+'</div>'
        : '';

      var extrasHtml = '';
      if(r.asesoria_placas) extrasHtml += '<span title="Solicitó asesoría para placas" style="display:inline-block;margin-left:4px;padding:2px 8px;background:#FFF3E0;color:#E65100;border:1px solid #FFE0B2;border-radius:10px;font-size:10px;font-weight:700;letter-spacing:.3px;cursor:help;">PLACAS</span>';
      if(r.seguro_qualitas) extrasHtml += '<span title="Solicitó seguro (Quálitas)" style="display:inline-block;margin-left:4px;padding:2px 8px;background:#E3F2FD;color:#0277BD;border:1px solid #90CAF9;border-radius:10px;font-size:10px;font-weight:700;letter-spacing:.3px;cursor:help;">SEGURO</span>';

      html += '<tr>'+
        '<td><strong>'+(r.pedido_corto||'VK-'+(r.pedido||r.id))+'</strong>'+extrasHtml+alertaHtml+'</td>'+
        '<td>'+(r.nombre||'-')+'<br><small class="ad-dim">'+(r.telefono||'')+'</small></td>'+
        '<td>'+(r.modelo||'-')+'</td>'+
        '<td>'+(r.color||'-')+'</td>'+
        '<td><span class="ad-badge '+tipoBadge+'">'+tipoDisplay+'</span></td>'+
        '<td>'+ADApp.money(r.monto)+'</td>'+
        '<td>'+pagoEstadoBadge(r.pago_estado, r.tipo)+'</td>'+
        '<td>'+puntoHtml+'</td>'+
        '<td>'+(r.fecha?r.fecha.substring(0,10):'-')+'</td>';

      var stockInfo = '';
      var stock = r.inventario_disponible;
      var transit = r.inventario_en_transito || 0;
      if(!r.moto_id && stock !== undefined){
        var reqModelo = (r.modelo||'').replace(/\s+/g,' ').trim();
        var reqColor  = (r.color||'').trim();
        var reqLabel  = esc(reqModelo + (reqColor ? ' '+reqColor : '')) || 'modelo solicitado';
        if(stock === 0){
          stockInfo = '<div style="font-size:11px;color:#b91c1c;margin-top:2px;" title="Se requiere exactamente: '+reqLabel+'">Sin '+reqLabel+' en stock</div>';
          if(transit > 0){
            stockInfo += '<div style="font-size:11px;color:#d97706;margin-top:1px;">'+transit+' en tránsito (por llegar)</div>';
          }
        } else {
          stockInfo = '<div style="font-size:11px;color:#059669;margin-top:2px;">'+stock+' '+reqLabel+' disponible'+(stock>1?'s':'')+'</div>';
        }
      }

      var isOrphan = r.source === 'transacciones_errores' || r.source === 'subscripciones_credito';
      var motoCell, actions;
      var actionsLayout = 'row';   // 'row' | 'stacked_pago_pendiente'
      var btnStyleBase = 'padding:5px 10px;font-size:12px;white-space:nowrap;';
      if(isOrphan){
        var isVksc = r.source === 'subscripciones_credito';
        var needsEdit = isVksc && (!r.modelo || r.modelo==='-' || !r.color || r.color==='-');
        motoCell = '<span class="ad-badge yellow">'+(r.source==='transacciones_errores'?'Error':'Crédito huérfano')+'</span>';
        actions  = '';
        if(ADApp.canWrite()){
          if(needsEdit){
            actions += '<button class="ad-btn primary" style="'+btnStyleBase+'background:#d97706;" '+
                       'onclick="AD_ventas.showEditarVksc('+r.id+')">Editar</button>';
          }
          actions += '<button class="ad-btn primary" style="'+btnStyleBase+'background:#b91c1c;" '+
                     'onclick="AD_ventas.showRecuperar('+r.id+',\''+esc(r.source)+'\',\''+esc(r.stripe_pi||'')+'\')">Recuperar</button>';
        }
        actions += '<button class="ad-btn sm ghost" style="'+btnStyleBase+'" onclick="AD_ventas.showDetalle('+r.id+')">Ver</button>';
      } else if(asignada){
        // Truncate long VINs so the ACCION column stays inside the viewport
        var vinFull  = (r.moto_vin||'****');
        var vinShort = vinFull.length > 10 ? vinFull.slice(-8) : vinFull;
        motoCell = '<span class="ad-badge green" title="'+esc(vinFull)+'" style="font-family:ui-monospace,Menlo,monospace;">'+esc(vinShort)+'</span>';
        actions  = '<button class="ad-btn sm ghost" style="'+btnStyleBase+'" onclick="AD_ventas.showDetalle('+r.id+')">Ver</button>';
      } else {
        motoCell = '<span class="ad-badge red">Sin asignar</span>'+stockInfo;
        actions  = '';
        if(ADApp.canWrite()){
          // Only allow "Asignar" once payment is confirmed. Credit-family orders
          // release the moto on the enganche (pago_estado='parcial'); every other
          // tpago (contado/unico/msi/spei/oxxo) needs full 'pagada'.
          var pe = (r.pago_estado||'').toLowerCase();
          var tp = (r.tipo||r.tpago||'').toLowerCase();
          var isCreditFam = ['credito','credito-orfano','enganche','parcial'].indexOf(tp) >= 0;
          var canAssign = (pe === 'pagada' || pe === 'aprobada' || pe === 'approved' || pe === 'paid')
                       || (isCreditFam && pe === 'parcial');
          if (canAssign) {
            actions += '<button class="ad-btn primary" style="'+btnStyleBase+'" '+
                       'onclick="AD_ventas.showAsignar('+r.id+',\''+esc(r.modelo)+'\',\''+esc(r.color)+'\',\''+(r.pedido_corto||'VK-'+(r.pedido||r.id))+'\')">Asignar</button>';
          } else if (r.stripe_pi) {
            // Payment not confirmed: Enviar link prominently on top (full width),
            // Sinc + Ver on the bottom row. Prevents "Ver" from overflowing to
            // the right when 3 buttons stack horizontally.
            actionsLayout = 'stacked_pago_pendiente';
          } else {
            actions += '<button class="ad-btn sm ghost" style="'+btnStyleBase+';opacity:.55;cursor:not-allowed;" '+
                       'title="El pago de esta orden aún no ha sido confirmado" disabled>Pendiente</button>';
          }
        }
        if (actionsLayout !== 'stacked_pago_pendiente') {
          actions += '<button class="ad-btn sm ghost" style="'+btnStyleBase+'" onclick="AD_ventas.showDetalle('+r.id+')">Ver</button>';
        }
      }
      var actionTd;
      if (actionsLayout === 'stacked_pago_pendiente') {
        actionTd = '<td style="min-width:170px;"><div style="display:flex;flex-direction:column;gap:5px;align-items:stretch;">'+
          '<button class="ad-btn sm" style="'+btnStyleBase+';background:#d97706;color:#fff;width:100%;" '+
            'title="Reenviar link de pago al cliente" '+
            'onclick="AD_ventas.showEnviarLink('+r.id+')">Enviar link</button>'+
          '<div style="display:flex;gap:5px;">'+
            '<button class="ad-btn sm" style="'+btnStyleBase+';background:#0ea5e9;color:#fff;flex:1;" '+
              'title="Verificar estado real con Stripe" '+
              'onclick="AD_ventas.syncStripe('+r.id+', this)">🔄 Sinc</button>'+
            '<button class="ad-btn sm ghost" style="'+btnStyleBase+';flex:1;" '+
              'onclick="AD_ventas.showDetalle('+r.id+')">Ver</button>'+
          '</div>'+
        '</div></td>';
      } else {
        actionTd = '<td><div style="display:flex;gap:6px;flex-wrap:nowrap;justify-content:flex-end;align-items:center;">'+
          actions +
        '</div></td>';
      }
      html += '<td>'+motoCell+'</td>' + actionTd;
      html += '</tr>';
    });

    html += '</tbody></table></div></div>';
    $('#vtTable').html(html);
  }

  function showAsignar(transId, modelo, color, pedido){
    ADApp.modal(
      '<div class="ad-h2">Asignar moto a '+pedido+'</div>'+
      '<div style="margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">'+
        '<span style="font-size:18px;font-weight:800;color:var(--ad-navy);">'+modelo+'</span>'+
        '<span style="font-size:16px;font-weight:700;color:var(--ad-primary);">'+color+'</span>'+
      '</div>'+
      '<div id="vtMotos">Buscando motos disponibles...</div>'
    );

    // First try exact model+color, then fallback to same model only (never show other models)
    var url = 'ventas/motos-disponibles.php?modelo='+encodeURIComponent(modelo)+'&color='+encodeURIComponent(color);
    ADApp.api(url).done(function(r){
      if(!r.ok || !r.motos.length){
        // Fallback: same model, any color — never show different models
        var urlModelo = 'ventas/motos-disponibles.php?modelo='+encodeURIComponent(modelo);
        ADApp.api(urlModelo).done(function(r2){
          renderMotos(r2.motos||[], transId, pedido, true, modelo);
        });
        return;
      }
      renderMotos(r.motos, transId, pedido, false, modelo);
    });
  }

  function renderMotos(motos, transId, pedido, showAll, modelo){
    if(!motos.length){
      var twoMonths = new Date(); twoMonths.setMonth(twoMonths.getMonth()+2);
      var eta = twoMonths.toISOString().slice(0,10);
      $('#vtMotos').html(
        '<div style="text-align:center;padding:20px;">'+
          '<div style="color:var(--ad-dim);margin-bottom:8px;">No hay motos <strong>'+(modelo||'')+'</strong> disponibles en inventario</div>'+
          '<div style="font-size:13px;color:#b91c1c;background:#fde8e8;padding:10px;border-radius:8px;">'+
            'La orden quedará en estado <strong>"Pendiente de asignar"</strong> hasta que CEDIS registre '+
            'nuevas motos de este modelo en el inventario.<br>'+
            'Entrega estimada: <strong>'+eta+'</strong> (~2 meses)'+
          '</div>'+
        '</div>'
      );
      return;
    }

    var html = '';
    if(showAll){
      html += '<div class="ad-banner warn" style="margin-bottom:10px;">No hay motos del mismo color. Mostrando otras unidades del mismo modelo.</div>';
    }

    html += '<div style="max-height:350px;overflow-y:auto;">';
    motos.forEach(function(m){
      html += '<div class="ad-card" style="padding:10px;margin-bottom:6px;display:flex;align-items:center;justify-content:space-between;cursor:pointer" '+
              'onclick="AD_ventas.doAsignar('+transId+','+m.id+')">';
      html += '<div>'+
        '<strong>'+(m.vin_display||m.vin)+'</strong>'+
        '<span class="ad-badge blue" style="margin-left:8px;">'+m.modelo+'</span>'+
        '<span class="ad-badge gray" style="margin-left:4px;">'+m.color+'</span>'+
        '<br><small class="ad-dim">Estado: '+m.estado+(m.punto_nombre?' &middot; '+m.punto_nombre:'')+'</small>'+
        '</div>';
      html += '<button class="ad-btn primary" style="padding:5px 14px;font-size:12px;flex-shrink:0">Seleccionar</button>';
      html += '</div>';
    });
    html += '</div>';

    $('#vtMotos').html(html);
  }

  function doAsignar(transId, motoId){
    if(!confirm('Confirmar asignacion de esta moto?')) return;

    ADApp.api('ventas/asignar-moto.php', {
      transaccion_id: transId,
      moto_id: motoId
    }).done(function(r){
      if(r.ok){
        ADApp.closeModal();
        loadData();
      } else {
        alert(r.error || 'Error al asignar');
      }
    }).fail(function(x){
      alert((x.responseJSON && x.responseJSON.error) || 'Error de conexión');
    });
  }

  function kpi(label, value, color){
    return '<div class="ad-kpi"><div class="label">'+label+'</div><div class="value '+color+'">'+value+'</div></div>';
  }

  function pagoEstadoBadge(estado, tipo){
    estado = (estado||'pendiente').toLowerCase();
    var tipoLabel = (tipo||'').toLowerCase();
    // Map payment method labels
    var metodo = '';
    if(['contado','unico','stripe','tarjeta'].indexOf(tipoLabel)>=0) metodo = 'Tarjeta';
    else if(tipoLabel==='spei') metodo = 'SPEI';
    else if(tipoLabel==='oxxo') metodo = 'OXXO';
    else if(['credito','credito-orfano','enganche'].indexOf(tipoLabel)>=0) metodo = 'Crédito';
    else if(tipoLabel==='msi') metodo = 'MSI';

    if(estado==='pagada'){
      var label = metodo ? 'Pagado · '+metodo : 'Pagado';
      return '<span class="ad-badge green" style="font-size:11px;">'+label+'</span>';
    } else if(estado==='parcial'){
      var label2 = metodo ? 'Enganche · '+metodo : 'Parcial';
      return '<span class="ad-badge yellow" style="font-size:11px;">'+label2+'</span>';
    } else if(estado==='orfano' || estado==='error'){
      return '<span class="ad-badge red" style="font-size:11px;">'+capitalize(estado)+'</span>';
    } else {
      var label3 = metodo ? 'Pendiente · '+metodo : 'Pendiente';
      return '<span class="ad-badge red" style="font-size:11px;">'+label3+'</span>';
    }
  }

  function showDetalle(transId){
    var rows = _lastRows || [];
    var r = null;
    for(var i=0;i<rows.length;i++){ if(rows[i].id===transId){ r=rows[i]; break; } }
    if(!r) return;

    // Silent Stripe re-check for non-paid orders on detail open.
    // Fixes the common drift where Stripe already processed the payment but
    // the DB still reads 'pendiente' because the webhook never landed.
    _autoVerifyOnDetail(r);

    var isPending = r.punto_id==='centro-cercano' || !r.punto_nombre;

    // ── Styled helpers (CEDIS pattern) ──
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

    // ── Modal header (CEDIS pattern) ──
    var pe = (r.pago_estado||'pendiente').toLowerCase();
    var pagoColor = pe==='pagada' ? 'green' : (pe==='parcial' ? 'yellow' : 'red');
    var pagoLabel = pe==='pagada' ? 'Pagado' : (pe==='parcial' ? 'Parcial' : 'Pendiente');

    var html = '<div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;padding-bottom:18px;border-bottom:2px solid var(--ad-border);">';
    html += '<div style="width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#039fe1,#0280b5);display:flex;align-items:center;justify-content:center;flex-shrink:0;">';
    html += '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg></div>';
    html += '<div style="flex:1;min-width:0;"><div style="font-size:20px;font-weight:800;color:var(--ad-navy);line-height:1.2;">'+(r.pedido_corto||'VK-'+(r.pedido||r.id))+'</div>';
    html += '<div style="display:flex;align-items:center;gap:8px;margin-top:4px;flex-wrap:wrap;"><span class="ad-badge '+pagoColor+'">'+pagoLabel+'</span>';
    html += '<span style="font-size:13px;color:var(--ad-dim);">'+(r.modelo||'—')+' · '+(r.color||'—')+' · '+ADApp.money(r.monto)+'</span>';
    html += '</div></div></div>';

    // ── Section: Cliente ──
    secIx = 0;
    html += secHead('Cliente','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>');
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:8px;">';
    html += fRow('Nombre', r.nombre||'—');
    html += fRow('Email', r.email ? '<a href="mailto:'+r.email+'" style="color:var(--ad-primary);text-decoration:none;">'+r.email+'</a>' : '—');
    html += fRow('Teléfono', r.telefono||'—');
    html += '</div>';

    // ── Section: Pedido ──
    secIx = 0;
    html += secHead('Pedido','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h4"/></svg>');
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:8px;">';
    html += fRow('Modelo', r.modelo||'—');
    html += fRow('Color', r.color||'—');
    var tipoMap = {
      'contado': {label:'Contado · Tarjeta', color:'green'},
      'unico':   {label:'Contado · Tarjeta', color:'green'},  // legacy alias
      'spei':    {label:'Contado · SPEI',    color:'green'},
      'oxxo':    {label:'Contado · OXXO',    color:'green'},
      'msi':     {label:'MSI · Tarjeta',     color:'blue'},
      'enganche':{label:'Crédito · Enganche', color:'yellow'},
      'credito': {label:'Crédito',            color:'yellow'}
    };
    var tm = tipoMap[(r.tipo||'').toLowerCase()] || {label:r.tipo||'—', color:'blue'};
    html += fRow('Tipo de pago', '<span class="ad-badge '+tm.color+'" style="font-size:11px;">'+tm.label+'</span>');
    html += fRow('Monto', '<span style="font-size:15px;font-weight:800;">'+ADApp.money(r.monto)+'</span>');
    html += fRow('Fecha', r.fecha ? r.fecha.substring(0,10) : '—');
    html += '</div>';

    // ── Section: Detalle del crédito (only for enganche/credito) ──
    if(r.credito && (r.tipo==='enganche' || r.tipo==='credito')){
      var cr = r.credito;
      secIx = 0;
      html += secHead('Detalle del crédito','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>');
      html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:8px;">';
      html += fRow('Precio contado', ADApp.money(cr.precio_contado));
      html += fRow('Enganche', '<span style="color:#059669;font-weight:700;">'+ADApp.money(cr.enganche)+'</span>');
      html += fRow('Monto financiado', ADApp.money(cr.monto_financiado));
      html += fRow('Pago semanal', '<span style="font-size:15px;font-weight:800;">'+ADApp.money(cr.monto_semanal)+'</span>');
      html += fRow('Plazo', (cr.plazo_semanas ? cr.plazo_semanas+' semanas' : (cr.plazo_meses ? cr.plazo_meses+' meses' : '—')));
      html += '</div>';
    }

    // ── Section: Stripe ──
    secIx = 0;
    html += secHead('Stripe','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 4H3a2 2 0 00-2 2v12a2 2 0 002 2h18a2 2 0 002-2V6a2 2 0 00-2-2z"/><path d="M1 10h22"/></svg>');
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:8px;">';
    var piVal = r.stripe_pi
      ? '<code style="font-size:11px;background:var(--ad-surface-2);padding:2px 6px;border-radius:4px;">'+r.stripe_pi+'</code>'
      : '—';
    html += fRow('Payment Intent', piVal);
    html += fRow('Estado de pago', '<span class="ad-badge '+pagoColor+'">'+pagoLabel+'</span>');
    html += '</div>';

    // ── Section: Punto de entrega ──
    secIx = 0;
    html += secHead('Punto de entrega','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>');
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:8px;">';
    if(isPending){
      html += fRow('Punto', '<span class="ad-badge yellow">Pendiente de asignar</span>');
      html += fRow('Nota', '<span style="font-size:11px;color:var(--ad-dim);">El cliente seleccionó "Centro Voltika cercano"</span>');
    } else if(r.punto_nombre){
      html += fRow('Punto', '<span style="color:#059669;font-weight:700;">'+r.punto_nombre+'</span>');
      // Full address from puntos_voltika
      var pAddr = [r.punto_direccion, r.punto_colonia].filter(function(v){return v;}).join(', ');
      var pLoc  = [r.punto_ciudad, r.punto_estado, r.punto_cp].filter(function(v){return v;}).join(', ');
      if(pAddr) html += fRow('Dirección', pAddr);
      if(pLoc)  html += fRow('Ubicación', pLoc);
      if(r.punto_telefono) html += fRow('Teléfono punto', '<a href="tel:'+r.punto_telefono+'" style="color:var(--ad-primary);text-decoration:none;">'+r.punto_telefono+'</a>');
    } else {
      html += fRow('Punto', '<span class="ad-badge red">Sin punto seleccionado</span>');
    }
    if(r.estado || r.ciudad || r.cp){
      html += fRow('Estado', r.estado || '—');
      html += fRow('Ciudad', r.ciudad || '—');
      html += fRow('C.P.', r.cp || '—');
    }
    html += '</div>';

    // [Asignar punto] / [Cambiar punto] button — always visible so admin can
    // (re)assign a punto even after one has been picked. Pending orders get
    // the prominent primary style; assigned orders get a subtle ghost style.
    // Inline SVG (pin / pencil) instead of emoji — keeps admin UI clean.
    var iconPin    = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:6px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>';
    var iconPencil = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:5px;"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
    var asignBtnLabel = isPending ? (iconPin + 'Asignar punto') : (iconPencil + 'Cambiar punto');
    var asignBtnCls   = isPending ? 'primary' : 'ghost sm';
    html += '<div style="margin:0 0 14px;">'
         +    '<button class="ad-btn '+asignBtnCls+' adAsignarPuntoBtn" data-tx="'+r.id+'" '
         +      'style="'+(isPending?'width:100%;padding:11px;':'')+'">'+asignBtnLabel+'</button>'
         +  '</div>';

    // ── Section: Estatus de moto ──
    // Neutral heading so it reads naturally whether the moto is already
    // assigned (shows VIN + estado) or still pending (shows "Sin asignar").
    // Customer feedback 2026-04-19: include physical location + aging so the
    // operator knows where the moto physically sits right now.
    secIx = 0;
    html += secHead('Estatus de moto','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 3v18"/></svg>');
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:8px;">';
    if(r.moto_id){
      html += fRow('VIN', '<code style="font-size:11px;background:var(--ad-surface-2);padding:2px 6px;border-radius:4px;">'+(r.moto_vin||'****')+'</code>');
      html += fRow('Estado', ADApp.badgeEstado(r.moto_estado||'—'));

      // Ubicación actual — where the moto physically is right now.
      var motoEst = (r.moto_estado||'').toLowerCase();
      var locHtml = '';
      if (motoEst === 'entregada') {
        locHtml = '<span style="color:#059669;font-weight:600;">Entregada al cliente</span>';
      } else if (motoEst === 'por_llegar') {
        locHtml = '<span style="color:#d97706;font-weight:600;">En tránsito desde CEDIS</span>';
        if (r.punto_moto_nombre) locHtml += ' <span style="color:var(--ad-dim);">→ ' + esc(r.punto_moto_nombre) + '</span>';
      } else if (r.punto_moto_nombre) {
        var mapsAddr = r.punto_moto_nombre + (r.punto_moto_direccion ? ', ' + r.punto_moto_direccion : '') + (r.punto_moto_ciudad ? ', ' + r.punto_moto_ciudad : '');
        locHtml = '<strong>' + esc(r.punto_moto_nombre) + '</strong>';
        if (r.punto_moto_ciudad) locHtml += ' · <span style="color:var(--ad-dim);">' + esc(r.punto_moto_ciudad) + '</span>';
        locHtml += ' <a href="https://maps.google.com/?q=' + encodeURIComponent(mapsAddr) + '" target="_blank" style="color:var(--ad-primary);font-size:11px;margin-left:4px;">📍 Maps</a>';
      } else {
        locHtml = '<span style="color:var(--ad-dim);">En CEDIS</span>';
      }
      html += fRow('Ubicación', locHtml);

      // Aging in current state — color-coded per CEDIS "Por punto" convention.
      if (r.dias_en_estado != null && motoEst !== 'entregada') {
        var d = parseInt(r.dias_en_estado) || 0;
        var col = d <= 7 ? '#059669' : d <= 30 ? '#d97706' : '#dc2626';
        var label = d === 0 ? 'Hoy' : (d === 1 ? 'Hace 1 día' : 'Hace ' + d + ' días');
        html += fRow('En este estado', '<span style="font-weight:700;color:' + col + ';">' + label + '</span>');
      }

      // Shipment status for in-transit motos (Skydrop or similar).
      if (r.envio && (motoEst === 'por_llegar' || motoEst === 'recibida')) {
        var envLine = esc(r.envio.carrier || 'Envío') + ' · ' + esc(r.envio.estado || 'en tránsito');
        if (r.envio.fecha_estimada_llegada) envLine += ' · ETA ' + esc(String(r.envio.fecha_estimada_llegada).substring(0, 10));
        if (r.envio.tracking_number) envLine += ' · <code style="font-size:11px;">' + esc(r.envio.tracking_number) + '</code>';
        html += fRow('Envío', envLine);
      }
    } else {
      html += fRow('Estado', '<span class="ad-badge red">Sin moto asignada</span>');
    }
    html += '</div>';

    // ── Section: Servicios adicionales ──
    secIx = 0;
    html += secHead('Servicios adicionales','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 22 12 18.27 5.82 22 7 14.14l-5-4.87 6.91-1.01L12 2z"/></svg>');
    html += renderServiciosAdicionales(r, fRow);

    ADApp.modal(html);

    // Wire Servicios adicionales action buttons
    $('.vkServicioAction').off('click').on('click', function(){
      var action = $(this).data('action');
      var id     = $(this).data('id');
      if(action === 'placas')  openGestionPlacas(id, r);
      if(action === 'seguro')  openGestionSeguro(id, r);
    });

    // Wire [Asignar/Cambiar punto] button
    $('.adAsignarPuntoBtn').off('click').on('click', function(){
      openAsignarPuntoOrden(r);
    });
  }

  // ── Modal: Asignar punto a la orden ─────────────────────────────────────
  // Lists active puntos sorted by same-state-first. On confirm, calls the
  // backend which updates transacciones + fires the punto_asignado notif.
  function openAsignarPuntoOrden(r){
    ADApp.modal('<div class="ad-h2">Cargando puntos...</div><div style="text-align:center;padding:30px;"><span class="ad-spin"></span></div>');
    ADApp.api('puntos/listar.php').done(function(resp){
      var puntos = (resp && resp.puntos) ? resp.puntos.filter(function(p){ return Number(p.activo) === 1; }) : [];
      if (!puntos.length) {
        ADApp.modal('<div class="ad-h2">Sin puntos activos</div>'+
          '<div class="ad-dim" style="padding:20px;text-align:center;">No hay puntos Voltika activos en el catálogo.</div>');
        return;
      }
      var orderEstado = (r.estado||'').toLowerCase();
      var sameState  = puntos.filter(function(p){ return (p.estado||'').toLowerCase() === orderEstado; });
      var otherState = puntos.filter(function(p){ return (p.estado||'').toLowerCase() !== orderEstado; });

      function puntoCardHtml(p){
        var dir = [p.direccion, p.colonia].filter(function(v){return v;}).join(', ');
        var loc = [p.ciudad, p.estado, p.cp].filter(function(v){return v;}).join(', ');
        var iconBox = '<svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-right:4px;"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>';
        var stockNote = (typeof p.inventario_actual !== 'undefined')
          ? '<span style="font-size:11px;color:var(--ad-dim);">'+iconBox+(p.inventario_actual||0)+' unidades en este punto</span>'
          : '';
        var isCurrent = String(p.id) === String(r.punto_id);
        return '<label class="adPickPunto" data-pid="'+p.id+'" '
             +   'style="display:block;cursor:pointer;padding:12px;margin-bottom:6px;border:1.5px solid '
             +   (isCurrent ? 'var(--ad-primary)' : 'var(--ad-border)')+';border-radius:8px;background:'
             +   (isCurrent ? '#E8F4FD' : 'var(--ad-surface)')+';">'
             +   '<div style="display:flex;gap:10px;align-items:flex-start;">'
             +     '<input type="radio" name="puntoChoice" value="'+p.id+'" style="margin-top:4px;flex-shrink:0;" '+(isCurrent?'checked':'')+'>'
             +     '<div style="flex:1;min-width:0;">'
             +       '<div style="font-weight:700;font-size:14px;color:var(--ad-navy);">'+esc(p.nombre)+(isCurrent?' <span style="font-size:11px;color:var(--ad-primary);">· actual</span>':'')+'</div>'
             +       (dir ? '<div style="font-size:12px;color:#555;margin-top:2px;">'+esc(dir)+'</div>' : '')
             +       (loc ? '<div style="font-size:12px;color:var(--ad-dim);margin-top:2px;">'+esc(loc)+'</div>' : '')
             +       (stockNote ? '<div style="margin-top:4px;">'+stockNote+'</div>' : '')
             +     '</div>'
             +   '</div>'
             + '</label>';
      }

      var iconPinH = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:6px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>';
      var html = '<div class="ad-h2">'+iconPinH+'Asignar punto de entrega</div>'
               + '<div style="font-size:12px;color:var(--ad-dim);margin-bottom:8px;">Pedido <strong>'+(r.pedido_corto||'VK-'+(r.pedido||r.id))+'</strong> · '+esc(r.nombre||'')+'</div>'
               + '<div style="background:var(--ad-surface-2);padding:10px 12px;border-radius:6px;margin-bottom:14px;font-size:12px;">'
               +   '<strong>Modelo:</strong> '+esc(r.modelo||'—')+' · '+esc(r.color||'—')+'<br>'
               +   '<strong>Solicitado:</strong> '+esc(r.estado||'—')+(r.ciudad?' · '+esc(r.ciudad):'')+(r.cp?' · CP '+esc(r.cp):'')
               + '</div>'
               + '<div style="max-height:340px;overflow-y:auto;padding-right:4px;">';

      if (sameState.length) {
        html += '<div style="font-size:12px;font-weight:700;color:var(--ad-primary);text-transform:uppercase;letter-spacing:.5px;margin:4px 0 6px;">Misma entidad ('+esc(r.estado||'')+')</div>';
        sameState.forEach(function(p){ html += puntoCardHtml(p); });
      }
      if (otherState.length) {
        html += '<div style="font-size:12px;font-weight:700;color:var(--ad-dim);text-transform:uppercase;letter-spacing:.5px;margin:'+(sameState.length?'14px':'4px')+' 0 6px;">Otros puntos</div>';
        otherState.forEach(function(p){ html += puntoCardHtml(p); });
      }

      html += '</div>'
            + '<div id="vkAsignPuntoMsg" style="font-size:12px;margin:10px 0 0;"></div>'
            + '<div style="display:flex;gap:8px;margin-top:12px;">'
            +   '<button class="ad-btn ghost" id="vkAsignPuntoCancel" style="flex:1;">Cancelar</button>'
            +   '<button class="ad-btn primary" id="vkAsignPuntoSave" style="flex:1;" disabled>Confirmar asignación</button>'
            + '</div>';

      ADApp.modal(html);

      // Pre-select if a current punto is already chosen (e.g. cambiar)
      if ($('input[name="puntoChoice"]:checked').length) {
        $('#vkAsignPuntoSave').prop('disabled', false);
      }

      $('.adPickPunto').on('click', function(){
        $('.adPickPunto').css({borderColor:'var(--ad-border)', background:'var(--ad-surface)'});
        $(this).css({borderColor:'var(--ad-primary)', background:'#E8F4FD'});
        $(this).find('input[type="radio"]').prop('checked', true);
        $('#vkAsignPuntoSave').prop('disabled', false);
      });

      $('#vkAsignPuntoCancel').on('click', function(){
        ADApp.closeModal();
        showDetalle(r.id);
      });

      $('#vkAsignPuntoSave').on('click', function(){
        var pid = $('input[name="puntoChoice"]:checked').val();
        if (!pid) { $('#vkAsignPuntoMsg').css('color','#b91c1c').text('Selecciona un punto'); return; }
        var $btn = $(this).prop('disabled', true).html('<span class="ad-spin"></span> Guardando...');
        ADApp.api('ventas/asignar-punto-orden.php', {
          transaccion_id: r.id,
          punto_id: parseInt(pid)
        }).done(function(res){
          if (res && res.ok) {
            var iconChk = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:5px;"><polyline points="20 6 9 17 4 12"/></svg>';
            $('#vkAsignPuntoMsg').css('color','#0e8f55').html(iconChk+'Punto asignado · Notificación enviada al cliente');
            setTimeout(function(){
              ADApp.closeModal();
              showDetalle(r.id);
            }, 700);
          } else {
            $('#vkAsignPuntoMsg').css('color','#b91c1c').text((res && res.error) || 'Error al guardar');
            $btn.prop('disabled', false).text('Confirmar asignación');
          }
        }).fail(function(xhr){
          var err = 'Error de conexión';
          try { var p = JSON.parse(xhr.responseText); if (p && p.error) err = p.error; } catch(e){}
          $('#vkAsignPuntoMsg').css('color','#b91c1c').text(err);
          $btn.prop('disabled', false).text('Confirmar asignación');
        });
      });
    }).fail(function(){
      ADApp.modal('<div class="ad-h2">Error</div><div class="ad-dim" style="padding:20px;text-align:center;">No se pudieron cargar los puntos.</div>');
    });
  }

  function renderServiciosAdicionales(r, fRow){
    var placas = !!r.asesoria_placas;
    var seguro = !!r.seguro_qualitas;
    var h = '';

    if(!placas && !seguro){
      h += '<div style="padding:10px 12px;background:var(--ad-surface-2);border-radius:6px;font-size:12px;color:var(--ad-dim);margin-bottom:8px;">'+
        'El cliente no solicitó servicios adicionales.'+
        '</div>';
      return h;
    }

    // Asesoría para placas
    h += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:10px;">';
    if(placas){
      var placasEstado = (r.placas_estado||'pendiente').toLowerCase();
      var placasColor = placasEstado==='completado' ? 'green' : (placasEstado==='en_proceso' ? 'blue' : 'yellow');
      var placasLabel = placasEstado==='completado' ? 'Completado' : (placasEstado==='en_proceso' ? 'En proceso' : 'Pendiente');
      h += fRow('Asesoría de placas', '<span class="ad-badge '+placasColor+'">'+placasLabel+'</span>');
      h += fRow('Para estado', r.estado || '—');
      if(r.placas_gestor_nombre){
        h += fRow('Gestor', r.placas_gestor_nombre);
        if(r.placas_gestor_telefono) h += fRow('Tel. gestor', '<a href="tel:'+r.placas_gestor_telefono+'" style="color:var(--ad-primary);text-decoration:none;">'+r.placas_gestor_telefono+'</a>');
      }
      if(r.placas_nota){
        h += '<div style="grid-column:1/-1;padding:6px 10px;font-size:11px;color:var(--ad-dim);background:var(--ad-surface-2);border-radius:4px;margin:4px 0;"><strong>Nota:</strong> '+esc(r.placas_nota)+'</div>';
      }
    } else {
      h += fRow('Asesoría de placas', '<span class="ad-badge gray">No solicitado</span>');
    }
    h += '</div>';

    // Seguro
    h += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:8px;">';
    if(seguro){
      var seguroEstado = (r.seguro_estado||'pendiente').toLowerCase();
      var seguroColor = seguroEstado==='activo' ? 'green' : (seguroEstado==='cotizado' ? 'blue' : 'yellow');
      var seguroLabel = seguroEstado==='activo' ? 'Póliza activa' : (seguroEstado==='cotizado' ? 'Cotizado' : 'Pendiente');
      h += fRow('Seguro', '<span class="ad-badge '+seguroColor+'">'+seguroLabel+'</span>');
      h += fRow('Modelo asegurar', (r.modelo||'—')+' · '+(r.color||'—'));
      if(r.seguro_cotizacion){
        h += fRow('Cotización', '$'+Number(r.seguro_cotizacion).toLocaleString('es-MX'));
      }
      if(r.seguro_poliza){
        h += fRow('N° póliza', '<code style="font-size:11px;background:var(--ad-surface-2);padding:2px 6px;border-radius:4px;">'+esc(r.seguro_poliza)+'</code>');
      }
      if(r.seguro_nota){
        h += '<div style="grid-column:1/-1;padding:6px 10px;font-size:11px;color:var(--ad-dim);background:var(--ad-surface-2);border-radius:4px;margin:4px 0;"><strong>Nota:</strong> '+esc(r.seguro_nota)+'</div>';
      }
    } else {
      h += fRow('Seguro', '<span class="ad-badge gray">No solicitado</span>');
    }
    h += '</div>';

    // Action buttons (wired in Phase C — if column exists)
    if(placas || seguro){
      h += '<div style="display:flex;gap:6px;margin-top:4px;flex-wrap:wrap;">';
      if(placas){
        h += '<button class="ad-btn sm ghost vkServicioAction" data-action="placas" data-id="'+(r.id||'')+'" data-pedido="'+(r.pedido||'')+'" style="font-size:11px;">Gestionar placas</button>';
      }
      if(seguro){
        h += '<button class="ad-btn sm ghost vkServicioAction" data-action="seguro" data-id="'+(r.id||'')+'" data-pedido="'+(r.pedido||'')+'" style="font-size:11px;">Gestionar seguro</button>';
      }
      h += '</div>';
    }

    return h;
  }

  // ── Cotización file block — shared by seguro + placas modals ────────────
  // Renders either the current attachment (with Ver/Reemplazar/Eliminar) or a
  // plain file picker when nothing is attached yet. The block is keyed by
  // `tipo` ('seguro'|'placas') so both modals can live side-by-side without
  // DOM id collisions.
  function cotizacionBlock(tipo, r, txId){
    var has     = !!r[tipo+'_cotizacion_archivo'];
    var subido  = r[tipo+'_cotizacion_subido'] || '';
    var size    = r[tipo+'_cotizacion_size'] || 0;
    var mime    = r[tipo+'_cotizacion_mime'] || '';
    var urlBase = 'ventas/serve-cotizacion.php?transaccion_id='+txId+'&tipo='+tipo;
    var h = '<div style="margin:0 0 10px;">';
    h += '<label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:4px;">Archivo de cotización (PDF, JPG, PNG — máx 5 MB)</label>';
    h += '<div id="vkCot_'+tipo+'_panel">';
    if (has) {
      var kb = size ? (size >= 1024*1024 ? (size/1024/1024).toFixed(1)+' MB' : Math.round(size/1024)+' KB') : '';
      var iconFile  = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#039fe1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
      var iconImage = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#039fe1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
      h += '<div style="display:flex;gap:8px;align-items:center;padding:10px 12px;background:#E8F4FD;border:1px solid #B3D4FC;border-radius:6px;flex-wrap:wrap;">'
         +   '<span style="display:inline-flex;align-items:center;">'+(mime.indexOf('pdf')>=0?iconFile:iconImage)+'</span>'
         +   '<div style="flex:1;min-width:140px;font-size:12px;">'
         +     '<div><strong>Archivo cargado</strong></div>'
         +     '<div class="ad-dim" style="font-size:11px;">'+esc(subido)+(kb?(' · '+kb):'')+'</div>'
         +   '</div>'
         +   '<a href="/admin/php/'+urlBase+'&inline=1" target="_blank" class="ad-btn sm ghost" style="text-decoration:none;">Ver</a>'
         +   '<button class="ad-btn sm ghost" id="vkCot_'+tipo+'_replace" type="button">Reemplazar</button>'
         +   '<button class="ad-btn sm ghost" id="vkCot_'+tipo+'_delete"  type="button" style="color:#b91c1c;">Eliminar</button>'
         + '</div>';
    } else {
      h += '<input type="file" id="vkCot_'+tipo+'_file" accept="application/pdf,image/jpeg,image/png,image/webp" style="width:100%;padding:8px;border:1.5px dashed var(--ad-border);border-radius:6px;font-size:12px;background:var(--ad-surface-2);">';
    }
    h += '</div>';
    h += '<div id="vkCot_'+tipo+'_msg" style="font-size:11px;margin-top:4px;"></div>';
    h += '</div>';
    return h;
  }

  function wireCotizacionBlock(tipo, r, txId){
    var $msg = $('#vkCot_'+tipo+'_msg');

    function doUpload(file){
      if (!file) return;
      if (file.size > 5*1024*1024) { $msg.css('color','#b91c1c').text('Archivo excede 5 MB'); return; }
      var fd = new FormData();
      fd.append('transaccion_id', txId);
      fd.append('tipo', tipo);
      fd.append('file', file);
      $msg.css('color','#555').html('<span class="ad-spin"></span> Subiendo...');
      $.ajax({
        url: 'php/ventas/subir-cotizacion.php',
        type: 'POST', data: fd, processData:false, contentType:false,
        xhrFields: { withCredentials: true }
      }).done(function(resp){
        if (resp && resp.ok){
          $msg.css('color','#0e8f55').html('<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg>Cargado');
          // Reflect new state in the row obj + redraw the panel (stay in modal)
          r[tipo+'_cotizacion_archivo'] = 'uploaded';
          r[tipo+'_cotizacion_mime']    = resp.mime;
          r[tipo+'_cotizacion_size']    = resp.size;
          r[tipo+'_cotizacion_subido']  = (new Date()).toISOString().replace('T',' ').substring(0,19);
          $('#vkCot_'+tipo+'_panel').replaceWith(
            $('<div>'+cotizacionBlock(tipo, r, txId)+'</div>').find('#vkCot_'+tipo+'_panel')
          );
          wireCotizacionBlock(tipo, r, txId);
        } else {
          $msg.css('color','#b91c1c').text(resp && resp.error ? resp.error : 'Error al subir');
        }
      }).fail(function(xhr){
        var err = 'Error de conexión';
        try { err = JSON.parse(xhr.responseText).error || err; } catch(e){}
        $msg.css('color','#b91c1c').text(err);
      });
    }

    $('#vkCot_'+tipo+'_file').on('change', function(){ doUpload(this.files && this.files[0]); });

    $('#vkCot_'+tipo+'_replace').on('click', function(){
      var $inp = $('<input type="file" accept="application/pdf,image/jpeg,image/png,image/webp">');
      $inp.on('change', function(){ doUpload(this.files && this.files[0]); }).trigger('click');
    });

    $('#vkCot_'+tipo+'_delete').on('click', function(){
      if (!confirm('¿Eliminar el archivo de cotización? Esta acción no se puede deshacer.')) return;
      $msg.css('color','#555').html('<span class="ad-spin"></span> Eliminando...');
      ADApp.api('ventas/eliminar-cotizacion.php', {transaccion_id: txId, tipo: tipo})
        .done(function(resp){
          if (resp && resp.ok){
            r[tipo+'_cotizacion_archivo'] = null;
            r[tipo+'_cotizacion_mime']    = null;
            r[tipo+'_cotizacion_size']    = null;
            r[tipo+'_cotizacion_subido']  = null;
            $('#vkCot_'+tipo+'_panel').replaceWith(
              $('<div>'+cotizacionBlock(tipo, r, txId)+'</div>').find('#vkCot_'+tipo+'_panel')
            );
            wireCotizacionBlock(tipo, r, txId);
            $msg.text('');
          } else {
            $msg.css('color','#b91c1c').text(resp.error || 'Error al eliminar');
          }
        });
    });
  }

  // ── Servicios adicionales: Gestión modals ───────────────────────────────
  function openGestionPlacas(txId, r){
    var estado = (r.placas_estado||'pendiente');
    var html = '<div class="ad-h2">Gestión de placas</div>';
    html += '<div style="font-size:12px;color:var(--ad-dim);margin-bottom:14px;">Pedido <strong>'+(r.pedido_corto||'VK-'+(r.pedido||txId))+'</strong> · '+(r.nombre||'')+'</div>';
    html += '<div style="background:var(--ad-surface-2);padding:10px 12px;border-radius:6px;margin-bottom:14px;font-size:12px;">';
    html += '<strong>Cliente:</strong> '+(r.nombre||'—')+' · <a href="tel:'+(r.telefono||'')+'" style="color:var(--ad-primary);">'+(r.telefono||'')+'</a><br>';
    html += '<strong>Estado MX:</strong> '+(r.estado||'—')+' · <strong>Ciudad:</strong> '+(r.ciudad||'—');
    html += '</div>';

    html += '<label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:4px;">Estado de la gestión</label>';
    html += '<select class="ad-select" id="vkPlacasEstado" style="width:100%;margin-bottom:10px;">';
    ['pendiente','en_proceso','completado'].forEach(function(s){
      html += '<option value="'+s+'"'+(estado===s?' selected':'')+'>'+s+'</option>';
    });
    html += '</select>';

    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">';
    html += '<div><label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:4px;">Gestor asignado</label>'+
      '<input type="text" class="ad-input" id="vkPlacasGestor" value="'+esc(r.placas_gestor_nombre||'')+'" placeholder="Nombre del gestor" style="width:100%;"></div>';
    html += '<div><label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:4px;">Teléfono gestor</label>'+
      '<input type="text" class="ad-input" id="vkPlacasTel" value="'+esc(r.placas_gestor_telefono||'')+'" placeholder="555..." style="width:100%;"></div>';
    html += '</div>';

    html += '<label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:4px;">Notas internas</label>';
    html += '<textarea class="ad-input" id="vkPlacasNota" style="width:100%;min-height:60px;margin-bottom:14px;">'+esc(r.placas_nota||'')+'</textarea>';

    html += cotizacionBlock('placas', r, txId);

    html += '<div style="display:flex;gap:8px;">';
    html += '<button class="ad-btn ghost" id="vkPlacasCancel" style="flex:1;">Cancelar</button>';
    html += '<button class="ad-btn primary" id="vkPlacasSave" style="flex:1;">Guardar</button>';
    html += '</div>';

    ADApp.modal(html);
    wireCotizacionBlock('placas', r, txId);
    $('#vkPlacasCancel').on('click', function(){ ADApp.closeModal(); showDetalle(r.id); });
    $('#vkPlacasSave').on('click', function(){
      var $btn = $(this).prop('disabled', true).html('<span class="ad-spin"></span>');
      ADApp.api('ventas/actualizar-servicio.php', {
        id: txId, tipo: 'placas',
        estado:   $('#vkPlacasEstado').val(),
        gestor:   $('#vkPlacasGestor').val(),
        telefono: $('#vkPlacasTel').val(),
        nota:     $('#vkPlacasNota').val(),
      }).done(function(resp){
        if(resp.ok){
          // Merge changes back into local row
          r.placas_estado          = $('#vkPlacasEstado').val();
          r.placas_gestor_nombre   = $('#vkPlacasGestor').val();
          r.placas_gestor_telefono = $('#vkPlacasTel').val();
          r.placas_nota            = $('#vkPlacasNota').val();
          ADApp.closeModal();
          showDetalle(r.id);
        } else {
          alert(resp.error||'Error al guardar');
          $btn.prop('disabled', false).text('Guardar');
        }
      }).fail(function(xhr){
        var msg = 'Error de conexión';
        if(xhr.responseJSON && xhr.responseJSON.error) msg = xhr.responseJSON.error;
        alert(msg);
        $btn.prop('disabled', false).text('Guardar');
      });
    });
  }

  function openGestionSeguro(txId, r){
    var estado = (r.seguro_estado||'pendiente');
    var html = '<div class="ad-h2">Gestión de seguro</div>';
    html += '<div style="font-size:12px;color:var(--ad-dim);margin-bottom:14px;">Pedido <strong>'+(r.pedido_corto||'VK-'+(r.pedido||txId))+'</strong> · '+(r.nombre||'')+'</div>';
    html += '<div style="background:var(--ad-surface-2);padding:10px 12px;border-radius:6px;margin-bottom:14px;font-size:12px;">';
    html += '<strong>Cliente:</strong> '+(r.nombre||'—')+' · <a href="tel:'+(r.telefono||'')+'" style="color:var(--ad-primary);">'+(r.telefono||'')+'</a><br>';
    html += '<strong>Unidad:</strong> '+(r.modelo||'—')+' · '+(r.color||'—');
    html += '</div>';

    html += '<label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:4px;">Estado</label>';
    html += '<select class="ad-select" id="vkSeguroEstado" style="width:100%;margin-bottom:10px;">';
    ['pendiente','cotizado','activo'].forEach(function(s){
      html += '<option value="'+s+'"'+(estado===s?' selected':'')+'>'+s+'</option>';
    });
    html += '</select>';

    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">';
    html += '<div><label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:4px;">Monto cotización (MXN)</label>'+
      '<input type="number" step="0.01" class="ad-input" id="vkSeguroCotiz" value="'+(r.seguro_cotizacion||'')+'" placeholder="0.00" style="width:100%;"></div>';
    html += '<div><label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:4px;">N° de póliza</label>'+
      '<input type="text" class="ad-input" id="vkSeguroPoliza" value="'+esc(r.seguro_poliza||'')+'" placeholder="POL-..." style="width:100%;"></div>';
    html += '</div>';

    html += '<label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:4px;">Notas internas</label>';
    html += '<textarea class="ad-input" id="vkSeguroNota" style="width:100%;min-height:60px;margin-bottom:14px;">'+esc(r.seguro_nota||'')+'</textarea>';

    html += cotizacionBlock('seguro', r, txId);

    html += '<div style="display:flex;gap:8px;">';
    html += '<button class="ad-btn ghost" id="vkSeguroCancel" style="flex:1;">Cancelar</button>';
    html += '<button class="ad-btn primary" id="vkSeguroSave" style="flex:1;">Guardar</button>';
    html += '</div>';

    ADApp.modal(html);
    wireCotizacionBlock('seguro', r, txId);
    $('#vkSeguroCancel').on('click', function(){ ADApp.closeModal(); showDetalle(r.id); });
    $('#vkSeguroSave').on('click', function(){
      var $btn = $(this).prop('disabled', true).html('<span class="ad-spin"></span>');
      ADApp.api('ventas/actualizar-servicio.php', {
        id: txId, tipo: 'seguro',
        estado:     $('#vkSeguroEstado').val(),
        cotizacion: $('#vkSeguroCotiz').val(),
        poliza:     $('#vkSeguroPoliza').val(),
        nota:       $('#vkSeguroNota').val(),
      }).done(function(resp){
        if(resp.ok){
          r.seguro_estado     = $('#vkSeguroEstado').val();
          r.seguro_cotizacion = $('#vkSeguroCotiz').val();
          r.seguro_poliza     = $('#vkSeguroPoliza').val();
          r.seguro_nota       = $('#vkSeguroNota').val();
          ADApp.closeModal();
          showDetalle(r.id);
        } else {
          alert(resp.error||'Error al guardar');
          $btn.prop('disabled', false).text('Guardar');
        }
      }).fail(function(xhr){
        var msg = 'Error de conexión';
        if(xhr.responseJSON && xhr.responseJSON.error) msg = xhr.responseJSON.error;
        alert(msg);
        $btn.prop('disabled', false).text('Guardar');
      });
    });
  }

  var _lastRows = [];
  var _activeTab = 'todas';

  function renderTabs(rows){
    var counts = {todas:rows.length, completadas:0, en_proceso:0, pendientes:0, pago_pendiente:0, errores:0, extras:0};
    rows.forEach(function(r){
      var cat = categorizePago(r);
      counts[cat]++;
      if(isPagoPendiente(r)) counts.pago_pendiente++;
      if(r.asesoria_placas || r.seguro_qualitas) counts.extras++;
    });
    var tabs = [
      {key:'todas',          label:'Todas'},
      {key:'completadas',    label:'Completadas'},
      {key:'en_proceso',     label:'En proceso'},
      {key:'pendientes',     label:'Pendientes'},
      {key:'pago_pendiente', label:'Pago pendiente'},
      {key:'errores',        label:'Errores'},
      {key:'extras',         label:'Con extras'}
    ];
    var html = '';
    tabs.forEach(function(t){
      var isActive = _activeTab === t.key;
      var countColor = 'var(--ad-dim)';
      if(t.key==='errores' && counts[t.key]>0) countColor = '#b91c1c';
      else if(t.key==='pendientes' && counts[t.key]>0) countColor = '#d97706';
      else if(t.key==='pago_pendiente' && counts[t.key]>0) countColor = '#c41e3a';
      if(isActive) countColor = '#fff';
      html += '<button class="vtTab" data-tab="'+t.key+'" style="'+
        'padding:10px 18px;font-size:13px;font-weight:600;border:none;cursor:pointer;'+
        'border-bottom:3px solid '+(isActive?'var(--ad-primary)':'transparent')+';'+
        'background:'+(isActive?'var(--ad-primary)':'transparent')+';'+
        'color:'+(isActive?'#fff':'var(--ad-dim)')+';'+
        'border-radius:8px 8px 0 0;transition:all .2s;'+
        '">'+t.label+' <span style="font-size:11px;font-weight:400;color:'+countColor+';">'+counts[t.key]+'</span></button>';
    });
    $('#vtTabs').html(html);
    $('.vtTab').on('click', function(){
      _activeTab = $(this).data('tab');
      renderTable(_lastRows);
    });
  }

  function categorizePago(r){
    var pe = (r.pago_estado||'').toLowerCase();
    var src = (r.source||'').toLowerCase();
    if(src === 'transacciones_errores' || pe === 'error' || pe === 'orfano') return 'errores';
    if(pe === 'pagada' && r.moto_id) return 'completadas';
    if(pe === 'pagada' || pe === 'parcial') return 'en_proceso';
    return 'pendientes';
  }

  // Cliente inició el pago (tenemos stripe_pi) pero no ha sido confirmado.
  // Estos son los que necesitan follow-up con link de pago.
  function isPagoPendiente(r){
    if (!r.stripe_pi) return false;
    var pe = (r.pago_estado||'').toLowerCase();
    // Credit orders: enganche is 'parcial' once captured — not "pending" for this view.
    return pe === 'pendiente' || pe === 'fallido' || pe === '';
  }

  function filterRows(rows){
    if(_activeTab === 'todas') return rows;
    if(_activeTab === 'extras') return rows.filter(function(r){ return r.asesoria_placas || r.seguro_qualitas; });
    if(_activeTab === 'pago_pendiente') return rows.filter(isPagoPendiente);
    return rows.filter(function(r){ return categorizePago(r) === _activeTab; });
  }

  function esc(s){
    return (s||'').replace(/'/g,"\\'").replace(/"/g,'&quot;');
  }
  function capitalize(s){ return s ? s.charAt(0).toUpperCase()+s.slice(1) : ''; }

  // Recuperar — promueve una orden huérfana (transacciones_errores) o
  // reconstruye desde Stripe PI a la tabla `transacciones`.
  function showRecuperar(rowId, source, stripePi){
    var rows = _lastRows || [];
    var r = null;
    for(var i=0;i<rows.length;i++){
      if(rows[i].id===rowId && rows[i].source===source){ r=rows[i]; break; }
    }
    if(!r){ alert('Fila no encontrada'); return; }

    var isErr = source === 'transacciones_errores';
    var html = '<div class="ad-h2">Recuperar orden</div>'+
      '<p class="ad-dim" style="font-size:13px;margin-bottom:12px;">'+
        (isErr
          ? 'Promover esta fila de <code>transacciones_errores</code> a <code>transacciones</code>. Puedes editar los campos antes de confirmar.'
          : 'Reconstruir la transacción desde Stripe PaymentIntent. Campos vacíos se llenan con metadata del PI.')+
      '</p>'+
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px;">'+
        '<label>Nombre<input id="rcvNombre" class="ad-input" value="'+esc(r.nombre||'')+'"></label>'+
        '<label>Email<input id="rcvEmail" class="ad-input" value="'+esc(r.email||'')+'"></label>'+
        '<label>Teléfono<input id="rcvTelefono" class="ad-input" value="'+esc(r.telefono||'')+'"></label>'+
        '<label>Modelo<input id="rcvModelo" class="ad-input" value="'+esc(r.modelo||'')+'"></label>'+
        '<label>Color<input id="rcvColor" class="ad-input" value="'+esc(r.color||'')+'"></label>'+
        '<label>Total MXN<input id="rcvTotal" class="ad-input" type="number" value="'+(r.monto||0)+'"></label>'+
        '<label>Folio contrato<input id="rcvFolio" class="ad-input" placeholder="VK-YYYYMMDD-XXX" readonly style="background:#f0f4f8;cursor:not-allowed;" value="'+esc(r.folio_contrato||'')+'"></label>'+
        '<label>Stripe PI<input id="rcvStripePi" class="ad-input" value="'+esc(stripePi||r.stripe_pi||'')+'" readonly style="background:#f0f4f8;cursor:not-allowed;"></label>'+
      '</div>'+
      '<div style="margin-top:14px;text-align:right;">'+
        '<button class="ad-btn ghost" onclick="ADApp.closeModal()">Cancelar</button> '+
        '<button class="ad-btn primary" id="rcvConfirm">Recuperar</button>'+
      '</div>'+
      '<div id="rcvMsg" style="margin-top:10px;font-size:12px;"></div>';

    ADApp.modal(html);

    $('#rcvConfirm').on('click', function(){
      var payload = {
        source:         isErr ? 'transacciones_errores' : 'stripe',
        err_id:         isErr ? r.id : 0,
        stripe_pi:      $('#rcvStripePi').val().trim(),
        nombre:         $('#rcvNombre').val().trim(),
        email:          $('#rcvEmail').val().trim(),
        telefono:       $('#rcvTelefono').val().trim(),
        modelo:         $('#rcvModelo').val().trim(),
        color:          $('#rcvColor').val().trim(),
        total:          parseFloat($('#rcvTotal').val())||0,
        folio_contrato: $('#rcvFolio').val().trim(),
      };
      if(!isErr && !payload.stripe_pi){
        $('#rcvMsg').html('<span style="color:#b91c1c;">Stripe PI requerido.</span>');
        return;
      }
      $('#rcvConfirm').prop('disabled', true).text('Recuperando...');
      ADApp.api('ventas/recuperar-orden.php', payload).done(function(resp){
        if(resp.ok){
          $('#rcvMsg').html('<span style="color:#059669;">Recuperada · tx_id='+resp.tx_id+' · folio='+(resp.folio||'')+'</span>');
          setTimeout(function(){ ADApp.closeModal(); loadData(); }, 1200);
        } else {
          $('#rcvMsg').html('<span style="color:#b91c1c;">Error: '+(resp.error||'desconocido')+'</span>');
          $('#rcvConfirm').prop('disabled', false).text('Recuperar');
        }
      }).fail(function(){
        $('#rcvMsg').html('<span style="color:#b91c1c;">Error de conexión.</span>');
        $('#rcvConfirm').prop('disabled', false).text('Recuperar');
      });
    });
  }

  // Editar datos manuales de una fila VK-SC (subscripciones_credito) que
  // quedó sin modelo/color por ser legacy (creada antes de Plan G). Sin
  // estos campos, ni "Asignar moto" ni "Recuperar" pueden operar bien.
  function showEditarVksc(vkscId){
    var rows = _lastRows || [];
    var r = null;
    for(var i=0;i<rows.length;i++){
      if(rows[i].id===vkscId && rows[i].source==='subscripciones_credito'){ r=rows[i]; break; }
    }
    if(!r){ alert('Fila VK-SC no encontrada'); return; }

    ADApp.modal(
      '<div class="ad-h2">Editar datos — VK-SC-'+r.id+'</div>'+
      '<p class="ad-dim" style="font-size:13px;margin-bottom:12px;">'+
        'Esta suscripción de crédito fue creada sin modelo/color. Completa los datos para poder recuperar la orden y asignar una moto.'+
      '</p>'+
      '<div id="vkscEditForm">Cargando modelos disponibles...</div>'
    );

    ADApp.api('ventas/modelos-colores.php').done(function(resp){
      if(!resp.ok){
        $('#vkscEditForm').html('<div style="color:#b91c1c;">Error cargando inventario: '+(resp.error||'')+'</div>');
        return;
      }
      _renderEditVkscForm(r, resp.pares || []);
    }).fail(function(){
      $('#vkscEditForm').html('<div style="color:#b91c1c;">Error de conexión.</div>');
    });
  }

  function _renderEditVkscForm(r, pares){
    // Build modelo dropdown from unique modelos in pares
    var modelosSet = {};
    pares.forEach(function(p){ modelosSet[p.modelo] = true; });
    var modelos = Object.keys(modelosSet).sort();

    var modeloOpts = '<option value="">— seleccionar —</option>';
    modelos.forEach(function(m){
      modeloOpts += '<option value="'+esc(m)+'"'+(r.modelo===m?' selected':'')+'>'+m+'</option>';
    });

    var html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px;">'+
      '<label>Nombre<input id="vkeNombre" class="ad-input" value="'+esc(r.nombre||'')+'"></label>'+
      '<label>Teléfono<input id="vkeTelefono" class="ad-input" value="'+esc(r.telefono||'')+'"></label>'+
      '<label>Email<input id="vkeEmail" class="ad-input" value="'+esc(r.email||'')+'"></label>'+
      '<label>Precio contado MXN<input id="vkePrecio" class="ad-input" type="number" value="'+(r.monto||0)+'"></label>'+
      '<label>Modelo<select id="vkeModelo" class="ad-input">'+modeloOpts+'</select></label>'+
      '<label>Color<select id="vkeColor" class="ad-input"><option value="">— elegir modelo primero —</option></select></label>'+
      '</div>'+
      '<div id="vkeInventarioInfo" style="margin-top:8px;font-size:12px;color:var(--ad-dim);"></div>'+
      '<div style="margin-top:14px;text-align:right;">'+
        '<button class="ad-btn ghost" onclick="ADApp.closeModal()">Cancelar</button> '+
        '<button class="ad-btn primary" id="vkeGuardar">Guardar</button>'+
      '</div>'+
      '<div id="vkeMsg" style="margin-top:10px;font-size:12px;"></div>';

    $('#vkscEditForm').html(html);

    // Populate color dropdown dynamically based on selected modelo
    function refreshColors(){
      var selModelo = $('#vkeModelo').val();
      var colors = pares.filter(function(p){ return p.modelo === selModelo; });
      var opts = '<option value="">— seleccionar —</option>';
      colors.forEach(function(c){
        opts += '<option value="'+esc(c.color)+'"'+(r.color===c.color?' selected':'')+'>'+
                c.color+' ('+c.disponibles+' disponibles)</option>';
      });
      $('#vkeColor').html(opts);
      if(!selModelo){
        $('#vkeInventarioInfo').text('');
      } else {
        var total = colors.reduce(function(s,c){ return s+c.disponibles; }, 0);
        $('#vkeInventarioInfo').text('Inventario para '+selModelo+': '+total+' unidades disponibles en '+colors.length+' colores.');
      }
    }
    $('#vkeModelo').on('change', refreshColors);
    if(r.modelo) refreshColors();

    $('#vkeGuardar').on('click', function(){
      var payload = {
        id:             r.id,
        nombre:         $('#vkeNombre').val().trim(),
        telefono:       $('#vkeTelefono').val().trim(),
        email:          $('#vkeEmail').val().trim(),
        modelo:         $('#vkeModelo').val(),
        color:          $('#vkeColor').val(),
        precio_contado: parseFloat($('#vkePrecio').val())||0,
      };
      if(!payload.modelo || !payload.color){
        $('#vkeMsg').html('<span style="color:#b91c1c;">Modelo y color son obligatorios.</span>');
        return;
      }
      $('#vkeGuardar').prop('disabled', true).text('Guardando...');
      ADApp.api('ventas/actualizar-vksc.php', payload).done(function(resp){
        if(resp.ok){
          $('#vkeMsg').html('<span style="color:#059669;">Actualizada. '+resp.updated_fields+' campos guardados.</span>');
          setTimeout(function(){ ADApp.closeModal(); loadData(); }, 900);
        } else {
          $('#vkeMsg').html('<span style="color:#b91c1c;">Error: '+(resp.error||'desconocido')+'</span>');
          $('#vkeGuardar').prop('disabled', false).text('Guardar');
        }
      }).fail(function(){
        $('#vkeMsg').html('<span style="color:#b91c1c;">Error de conexión.</span>');
        $('#vkeGuardar').prop('disabled', false).text('Guardar');
      });
    });
  }

  // ── Enviar link de pago (follow-up para pagos pendientes) ────────────────
  function showEnviarLink(rowId){
    var rows = _lastRows || [];
    var r = null;
    for(var i=0;i<rows.length;i++){
      if(rows[i].id === rowId){ r = rows[i]; break; }
    }
    if(!r){ alert('Fila no encontrada'); return; }

    var tel  = (r.telefono || '').trim();
    var em   = (r.email    || '').trim();
    var last = r.last_reminder_at || '';
    var sentCount = r.reminders_sent_count || 0;
    var hasAnyContact = !!(tel || em);

    var h = '<div class="ad-h2">Enviar link de pago a cliente</div>';
    h += '<div style="background:#FFF8E1;border-left:3px solid #FFC107;padding:10px 12px;border-radius:6px;margin-bottom:14px;font-size:12px;color:#795548;">'+
      'Se le reenviará al cliente un link para que complete el pago pendiente. Si es SPEI/OXXO se reutiliza la referencia original; si es tarjeta se genera un nuevo Checkout.'+
    '</div>';

    h += '<div style="font-size:13px;margin-bottom:10px;">';
    h += '<strong>Pedido:</strong> '+esc(r.pedido_corto||'VK-'+(r.pedido||r.id))+'<br>';
    h += '<strong>Cliente:</strong> '+esc(r.nombre||'—')+'<br>';
    h += '<strong>Modelo:</strong> '+esc(r.modelo||'—')+' · '+esc(r.color||'—')+'<br>';
    h += '<strong>Monto:</strong> '+ADApp.money(r.monto)+'<br>';
    h += '<strong>Método:</strong> '+esc(r.tipo||r.tpago||'—');
    h += '</div>';

    if(last){
      h += '<div style="background:#FFFDE7;padding:8px 10px;border-radius:6px;font-size:11px;color:#666;margin-bottom:12px;">'+
        'Último recordatorio: '+esc(last.substring(0,16))+' · Total envíos: '+sentCount+
      '</div>';
    }

    // Warning if no contact info at all
    if (!hasAnyContact) {
      h += '<div style="background:#FDECEA;border-left:3px solid #c41e3a;padding:10px 12px;border-radius:6px;margin-bottom:12px;font-size:12px;color:#7a0e1f;">'+
        '<strong>Sin datos de contacto.</strong> Esta orden no tiene email ni teléfono registrado. '+
        'Puedes introducir los datos del cliente abajo para enviarle el link ahora. '+
        'Los datos se guardarán en la orden para envíos futuros.'+
      '</div>';
    }

    h += '<div style="font-size:13px;font-weight:600;margin-bottom:8px;">Canales de envío</div>';

    // Email
    h += '<label style="display:flex;align-items:center;gap:8px;padding:8px;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:6px;cursor:pointer;">';
    h += '<input type="checkbox" id="elSendEmail" '+(em?'checked':'')+'>';
    h += '<span style="flex:1;">📧 Email';
    if (em) {
      h += ' <span style="color:#666;font-size:11px;">'+esc(em)+'</span></span>';
      h += '<input type="hidden" id="elEmailInput" value="'+esc(em)+'">';
    } else {
      h += '</span>';
    }
    h += '</label>';
    if (!em) {
      h += '<input type="email" id="elEmailInput" placeholder="cliente@ejemplo.com" class="ad-input" style="margin:-4px 0 8px 28px;width:calc(100% - 28px);font-size:12px;padding:6px 8px;">';
    }

    // SMS
    h += '<label style="display:flex;align-items:center;gap:8px;padding:8px;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:6px;cursor:pointer;">';
    h += '<input type="checkbox" id="elSendSms" '+(tel?'checked':'')+'>';
    h += '<span style="flex:1;">📱 SMS';
    if (tel) {
      h += ' <span style="color:#666;font-size:11px;">'+esc(tel)+'</span></span>';
      h += '<input type="hidden" id="elSmsInput" value="'+esc(tel)+'">';
    } else {
      h += '</span>';
    }
    h += '</label>';
    if (!tel) {
      h += '<input type="tel" id="elSmsInput" inputmode="numeric" maxlength="10" placeholder="10 dígitos" class="ad-input" style="margin:-4px 0 8px 28px;width:calc(100% - 28px);font-size:12px;padding:6px 8px;">';
    }

    // WhatsApp (shares the phone input if no tel)
    h += '<label style="display:flex;align-items:center;gap:8px;padding:8px;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:10px;cursor:pointer;">';
    h += '<input type="checkbox" id="elSendWa" '+(tel?'checked':'')+'>';
    h += '<span style="flex:1;">💬 WhatsApp';
    if (tel) h += ' <span style="color:#666;font-size:11px;">'+esc(tel)+'</span>';
    else      h += ' <span style="color:#666;font-size:11px;">(usa el mismo número que SMS)</span>';
    h += '</span></label>';

    h += '<label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#666;margin-bottom:12px;cursor:pointer;">';
    h += '<input type="checkbox" id="elForce"> Forzar envío aunque haya sido enviado en las últimas 24h';
    h += '</label>';

    h += '<div style="display:flex;gap:8px;margin-top:10px;">';
    h += '<button class="ad-btn ghost" onclick="ADApp.closeModal()" style="flex:1;">Cancelar</button>';
    h += '<button class="ad-btn primary" id="elSendBtn" style="flex:2;background:#d97706;">Enviar recordatorio</button>';
    h += '</div>';

    ADApp.modal(h);

    $('#elSendBtn').on('click', function(){
      var canales = [];
      var emailVal = ($('#elEmailInput').val() || '').trim();
      var smsVal   = ($('#elSmsInput').val()   || '').trim();

      if($('#elSendEmail').is(':checked')) {
        if (!emailVal || emailVal.indexOf('@') < 0) { alert('Ingresa un email válido'); return; }
        canales.push('email');
      }
      if($('#elSendSms').is(':checked')) {
        if (!smsVal || smsVal.replace(/\D/g,'').length < 10) { alert('Ingresa un teléfono de 10 dígitos'); return; }
        canales.push('sms');
      }
      if($('#elSendWa').is(':checked')) {
        if (!smsVal || smsVal.replace(/\D/g,'').length < 10) { alert('Para WhatsApp ingresa un teléfono de 10 dígitos'); return; }
        canales.push('whatsapp');
      }
      if(!canales.length){ alert('Selecciona al menos un canal'); return; }

      var $b = $(this).prop('disabled',true).html('<span class="ad-spin"></span> Enviando...');
      ADApp.api('ventas/enviar-link-pago.php', {
        transaccion_id: rowId,
        canales: canales,
        force: $('#elForce').is(':checked') ? 1 : 0,
        // Manual overrides (used if the DB row is missing contact info)
        email:    emailVal,
        telefono: smsVal
      }).done(function(resp){
        if(resp.ok){
          ADApp.closeModal();
          var parts = [];
          if(resp.sent_email)    parts.push('Email');
          if(resp.sent_sms)      parts.push('SMS');
          if(resp.sent_whatsapp) parts.push('WhatsApp');
          alert('Recordatorio enviado · ' + (parts.length ? parts.join(' + ') : 'sin canal exitoso'));
          render();
        } else {
          alert(resp.error||'Error al enviar');
          $b.prop('disabled',false).html('Enviar recordatorio');
        }
      }).fail(function(xhr){
        var msg = (xhr.responseJSON && xhr.responseJSON.error) || 'Error de conexión';
        alert(msg);
        $b.prop('disabled',false).html('Enviar recordatorio');
      });
    });
  }

  // ── Sincronizar fila con Stripe (fix DB drift when webhook missed it) ────
  function syncStripe(transId, btnEl){
    var $btn = $(btnEl);
    var originalHtml = $btn.html();
    $btn.prop('disabled', true).html('<span class="ad-spin"></span>');
    ADApp.api('ventas/verificar-stripe-uno.php', { transaccion_id: transId }).done(function(resp){
      if (!resp.ok) {
        alert(resp.error || 'Error al verificar');
        $btn.prop('disabled', false).html(originalHtml);
        return;
      }
      if (resp.changed) {
        ADApp.toast
          ? ADApp.toast('Estado actualizado: ' + resp.before + ' → ' + resp.after + ' (Stripe: ' + resp.stripe_status + ')')
          : alert('Estado actualizado: ' + resp.before + ' → ' + resp.after);
        render(); // refresh the entire list so UI reflects new state
      } else {
        if (ADApp.toast) {
          ADApp.toast('Ya estaba sincronizado (' + resp.stripe_status + ')');
        } else {
          alert('Ya estaba sincronizado con Stripe: ' + resp.stripe_status);
        }
        $btn.prop('disabled', false).html(originalHtml);
      }
    }).fail(function(xhr){
      var msg = (xhr.responseJSON && xhr.responseJSON.error) || 'Error de conexión';
      alert(msg);
      $btn.prop('disabled', false).html(originalHtml);
    });
  }

  // Auto-verify silently when operator opens detail for a non-paid order.
  // Fixes drift without the operator having to click Sincronizar manually.
  function _autoVerifyOnDetail(row){
    if (!row || !row.stripe_pi) return;
    var pe = (row.pago_estado || '').toLowerCase();
    var tp = (row.tipo || row.tpago || '').toLowerCase();
    var isCreditFam = ['credito','credito-orfano','enganche','parcial'].indexOf(tp) >= 0;
    // Credit family: 'parcial' is the terminal state (enganche captured), so don't re-verify it.
    if (pe === 'pagada' || (isCreditFam && pe === 'parcial')) return;
    ADApp.api('ventas/verificar-stripe-uno.php', { transaccion_id: row.id }).done(function(resp){
      if (resp && resp.ok && resp.changed) {
        // Refresh the list so the caller sees updated state
        if (ADApp.toast) ADApp.toast('Stripe indica ' + resp.after + ' — actualizado automáticamente');
        render();
      }
    });
  }

  return { render:render, showAsignar:showAsignar, doAsignar:doAsignar, showDetalle:showDetalle, showRecuperar:showRecuperar, showEditarVksc:showEditarVksc, showEnviarLink:showEnviarLink, syncStripe:syncStripe };
})();
