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
      var alertaHtml = r.alerta
        ? '<div style="font-size:11px;color:#b91c1c;margin-top:2px;">'+esc(r.alerta)+'</div>'
        : '';

      var extrasHtml = '';
      if(r.asesoria_placas) extrasHtml += '<span title="Solicitó asesoría para placas" style="display:inline-block;margin-left:4px;padding:2px 8px;background:#FFF3E0;color:#E65100;border:1px solid #FFE0B2;border-radius:10px;font-size:10px;font-weight:700;letter-spacing:.3px;cursor:help;">PLACAS</span>';
      if(r.seguro_qualitas) extrasHtml += '<span title="Solicitó seguro Quálitas" style="display:inline-block;margin-left:4px;padding:2px 8px;background:#E3F2FD;color:#0277BD;border:1px solid #90CAF9;border-radius:10px;font-size:10px;font-weight:700;letter-spacing:.3px;cursor:help;">QUÁLITAS</span>';

      html += '<tr>'+
        '<td><strong>VK-'+(r.pedido||r.id)+'</strong>'+extrasHtml+alertaHtml+'</td>'+
        '<td>'+(r.nombre||'-')+'<br><small class="ad-dim">'+(r.telefono||'')+'</small></td>'+
        '<td>'+(r.modelo||'-')+'</td>'+
        '<td>'+(r.color||'-')+'</td>'+
        '<td><span class="ad-badge '+tipoBadge+'">'+(r.tipo||'-')+'</span></td>'+
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
                       'onclick="AD_ventas.showAsignar('+r.id+',\''+esc(r.modelo)+'\',\''+esc(r.color)+'\',\'VK-'+(r.pedido||r.id)+'\')">Asignar</button>';
          } else {
            actions += '<button class="ad-btn sm ghost" style="'+btnStyleBase+';opacity:.55;cursor:not-allowed;" '+
                       'title="El pago de esta orden aún no ha sido confirmado" disabled>Pendiente</button>';
          }
        }
        actions += '<button class="ad-btn sm ghost" style="'+btnStyleBase+'" onclick="AD_ventas.showDetalle('+r.id+')">Ver</button>';
      }
      html += '<td>'+motoCell+'</td>'+
              '<td><div style="display:flex;gap:6px;flex-wrap:nowrap;justify-content:flex-end;align-items:center;">'+
              actions+
              '</div></td>';
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
    if(['contado','stripe','tarjeta'].indexOf(tipoLabel)>=0) metodo = 'Tarjeta';
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

    var isPending = r.punto_id==='centro-cercano';

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
    html += '<div style="flex:1;min-width:0;"><div style="font-size:20px;font-weight:800;color:var(--ad-navy);line-height:1.2;">VK-'+(r.pedido||r.id)+'</div>';
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

    // ── Section: Estatus de moto ──
    // Neutral heading so it reads naturally whether the moto is already
    // assigned (shows VIN + estado) or still pending (shows "Sin asignar").
    secIx = 0;
    html += secHead('Estatus de moto','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 3v18"/></svg>');
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:8px;">';
    if(r.moto_id){
      html += fRow('VIN', '<code style="font-size:11px;background:var(--ad-surface-2);padding:2px 6px;border-radius:4px;">'+(r.moto_vin||'****')+'</code>');
      html += fRow('Estado', ADApp.badgeEstado(r.moto_estado||'—'));
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

    // Seguro Quálitas
    h += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:8px;">';
    if(seguro){
      var seguroEstado = (r.seguro_estado||'pendiente').toLowerCase();
      var seguroColor = seguroEstado==='activo' ? 'green' : (seguroEstado==='cotizado' ? 'blue' : 'yellow');
      var seguroLabel = seguroEstado==='activo' ? 'Póliza activa' : (seguroEstado==='cotizado' ? 'Cotizado' : 'Pendiente');
      h += fRow('Seguro Quálitas', '<span class="ad-badge '+seguroColor+'">'+seguroLabel+'</span>');
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
      h += fRow('Seguro Quálitas', '<span class="ad-badge gray">No solicitado</span>');
    }
    h += '</div>';

    // Action buttons (wired in Phase C — if column exists)
    if(placas || seguro){
      h += '<div style="display:flex;gap:6px;margin-top:4px;flex-wrap:wrap;">';
      if(placas){
        h += '<button class="ad-btn sm ghost vkServicioAction" data-action="placas" data-id="'+(r.id||'')+'" data-pedido="'+(r.pedido||'')+'" style="font-size:11px;">Gestionar placas</button>';
      }
      if(seguro){
        h += '<button class="ad-btn sm ghost vkServicioAction" data-action="seguro" data-id="'+(r.id||'')+'" data-pedido="'+(r.pedido||'')+'" style="font-size:11px;">Gestionar Quálitas</button>';
      }
      h += '</div>';
    }

    return h;
  }

  // ── Servicios adicionales: Gestión modals ───────────────────────────────
  function openGestionPlacas(txId, r){
    var estado = (r.placas_estado||'pendiente');
    var html = '<div class="ad-h2">Gestión de placas</div>';
    html += '<div style="font-size:12px;color:var(--ad-dim);margin-bottom:14px;">Pedido <strong>VK-'+(r.pedido||txId)+'</strong> · '+(r.nombre||'')+'</div>';
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

    html += '<div style="display:flex;gap:8px;">';
    html += '<button class="ad-btn ghost" id="vkPlacasCancel" style="flex:1;">Cancelar</button>';
    html += '<button class="ad-btn primary" id="vkPlacasSave" style="flex:1;">Guardar</button>';
    html += '</div>';

    ADApp.modal(html);
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
    var html = '<div class="ad-h2">Gestión Seguro Quálitas</div>';
    html += '<div style="font-size:12px;color:var(--ad-dim);margin-bottom:14px;">Pedido <strong>VK-'+(r.pedido||txId)+'</strong> · '+(r.nombre||'')+'</div>';
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

    html += '<div style="display:flex;gap:8px;">';
    html += '<button class="ad-btn ghost" id="vkSeguroCancel" style="flex:1;">Cancelar</button>';
    html += '<button class="ad-btn primary" id="vkSeguroSave" style="flex:1;">Guardar</button>';
    html += '</div>';

    ADApp.modal(html);
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
    var counts = {todas:rows.length, completadas:0, en_proceso:0, pendientes:0, errores:0, extras:0};
    rows.forEach(function(r){
      var cat = categorizePago(r);
      counts[cat]++;
      if(r.asesoria_placas || r.seguro_qualitas) counts.extras++;
    });
    var tabs = [
      {key:'todas',       label:'Todas'},
      {key:'completadas', label:'Completadas'},
      {key:'en_proceso',  label:'En proceso'},
      {key:'pendientes',  label:'Pendientes'},
      {key:'errores',     label:'Errores'},
      {key:'extras',      label:'Con extras'}
    ];
    var html = '';
    tabs.forEach(function(t){
      var isActive = _activeTab === t.key;
      var countColor = t.key==='errores' && counts[t.key]>0 ? '#b91c1c' : (t.key==='pendientes' && counts[t.key]>0 ? '#d97706' : 'var(--ad-dim)');
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

  function filterRows(rows){
    if(_activeTab === 'todas') return rows;
    if(_activeTab === 'extras') return rows.filter(function(r){ return r.asesoria_placas || r.seguro_qualitas; });
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

  return { render:render, showAsignar:showAsignar, doAsignar:doAsignar, showDetalle:showDetalle, showRecuperar:showRecuperar, showEditarVksc:showEditarVksc };
})();
