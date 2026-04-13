window.AD_ventas = (function(){

  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  function render(){
    ADApp.render(
      _backBtn+
      '<div class="ad-toolbar" style="display:flex;align-items:center;justify-content:space-between;">'+
        '<div class="ad-h1">Ventas / Ordenes</div>'+
        '<div style="display:flex;align-items:center;gap:10px;">'+
          '<span id="vtLastUpdated" class="ad-dim" style="font-size:12px;"></span>'+
          '<button id="vtRefreshBtn" class="ad-btn sm" style="padding:6px 12px;">Actualizar</button>'+
        '</div>'+
      '</div>'+
      '<div id="vtKpis" class="ad-kpis" style="margin-bottom:14px;"></div>'+
      '<div id="vtTable">Cargando...</div>'
    );
    $('#vtRefreshBtn').on('click', loadData);
    loadData();
  }

  function _formatLastUpdated(){
    var d = new Date();
    var hh = String(d.getHours()).padStart(2,'0');
    var mm = String(d.getMinutes()).padStart(2,'0');
    var ss = String(d.getSeconds()).padStart(2,'0');
    return 'Última actualización: '+hh+':'+mm+':'+ss;
  }

  function loadData(){
    $('#vtLastUpdated').text('Cargando...');
    ADApp.api('ventas/listar.php').done(function(r){
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
      $('#vtLastUpdated').text(_formatLastUpdated());

      var rows = r.rows || [];
      _lastRows = rows;
      if(!rows.length){
        $('#vtTable').html('<div class="ad-card" style="text-align:center;padding:32px;">No hay ordenes registradas</div>');
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
          ? '<div style="font-size:11px;color:#b91c1c;margin-top:2px;">⚠ '+esc(r.alerta)+'</div>'
          : '';

        html += '<tr>'+
          '<td><strong>VK-'+(r.pedido||r.id)+'</strong>'+alertaHtml+'</td>'+
          '<td>'+(r.nombre||'-')+'<br><small class="ad-dim">'+(r.telefono||'')+'</small></td>'+
          '<td>'+(r.modelo||'-')+'</td>'+
          '<td>'+(r.color||'-')+'</td>'+
          '<td><span class="ad-badge '+tipoBadge+'">'+(r.tipo||'-')+'</span></td>'+
          '<td>'+ADApp.money(r.monto)+'</td>'+
          '<td>'+pagoEstadoBadge(r.pago_estado, r.tipo)+'</td>'+
          '<td>'+puntoHtml+'</td>'+
          '<td>'+(r.fecha?r.fecha.substring(0,10):'-')+'</td>';

        // Inventory availability + delivery estimate info under the moto column
        var stockInfo = '';
        var stock = r.inventario_disponible;
        if(!r.moto_id && stock !== undefined){
          if(stock === 0){
            stockInfo = '<div style="font-size:11px;color:#b91c1c;margin-top:2px;">Sin inventario<br>Pendiente de asignar moto</div>';
          } else {
            stockInfo = '<div style="font-size:11px;color:#059669;margin-top:2px;">'+stock+' disponible'+(stock>1?'s':'')+'</div>';
          }
        }

        // Action buttons are always wrapped in a horizontal flex container
        // so they never stack vertically, regardless of how many appear.
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
          motoCell = '<span class="ad-badge green">'+(r.moto_vin||'****')+'</span>';
          actions  = '<button class="ad-btn sm ghost" style="'+btnStyleBase+'" onclick="AD_ventas.showDetalle('+r.id+')">Ver</button>';
        } else {
          motoCell = '<span class="ad-badge red">Sin asignar</span>'+stockInfo;
          actions  = '';
          if(ADApp.canWrite()){
            actions += '<button class="ad-btn primary" style="'+btnStyleBase+'" '+
                       'onclick="AD_ventas.showAsignar('+r.id+',\''+esc(r.modelo)+'\',\''+esc(r.color)+'\',\'VK-'+(r.pedido||r.id)+'\')">Asignar</button>';
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
    }).fail(function(){
      $('#vtTable').html('<div class="ad-card">Error de conexion</div>');
    });
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

    function sec(title, icon){
      return '<div style="display:flex;align-items:center;gap:8px;padding:10px 16px;margin:0 -20px;background:#f1f5f9;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;">'+
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'+icon+'</svg>'+
        '<span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#475569;">'+title+'</span></div>';
    }
    function row(label, value, idx){
      var bg = idx % 2 === 0 ? '#fff' : '#f8fafc';
      return '<div style="display:flex;justify-content:space-between;align-items:center;padding:9px 16px;margin:0 -20px;background:'+bg+';min-height:36px;">'+
        '<span style="font-size:13px;color:#64748b;flex-shrink:0;margin-right:12px;">'+label+'</span>'+
        '<span style="font-size:13px;font-weight:600;color:#1e293b;text-align:right;word-break:break-all;">'+value+'</span></div>';
    }

    // Header
    var pagoBadge = '';
    var pe = (r.pago_estado||'pendiente').toLowerCase();
    if(pe==='pagada') pagoBadge = '<span style="background:#059669;color:#fff;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;">Pagado</span>';
    else if(pe==='parcial') pagoBadge = '<span style="background:#d97706;color:#fff;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;">Parcial</span>';
    else pagoBadge = '<span style="background:#dc2626;color:#fff;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;">Pendiente</span>';

    var html = '<div style="background:linear-gradient(135deg,#0f172a,#1e3a5f);margin:-20px -20px 0;padding:24px 24px 20px;border-radius:12px 12px 0 0;">'+
      '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">'+
        '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>'+
        '<span style="font-size:20px;font-weight:800;color:#fff;letter-spacing:-.3px;">VK-'+(r.pedido||r.id)+'</span>'+
        pagoBadge+
      '</div>'+
      '<div style="font-size:13px;color:#94a3b8;">'+(r.modelo||'—')+' · '+(r.color||'—')+' · '+ADApp.money(r.monto)+'</div>'+
    '</div>';

    // Section: Cliente
    var n = 0;
    html += sec('Cliente','<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>');
    html += row('Nombre', r.nombre||'—', n++);
    html += row('Email', r.email ? '<a href="mailto:'+r.email+'" style="color:#2563eb;text-decoration:none;">'+r.email+'</a>' : '—', n++);
    html += row('Teléfono', r.telefono||'—', n++);

    // Section: Pedido
    html += sec('Pedido','<rect x="2" y="3" width="20" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h4"/>');
    html += row('Modelo', r.modelo||'—', n++);
    html += row('Color', r.color||'—', n++);
    html += row('Tipo de pago', '<span class="ad-badge blue" style="font-size:11px;">'+(r.tipo||'—')+'</span>', n++);
    html += row('Monto', '<span style="font-size:15px;font-weight:700;">'+ADApp.money(r.monto)+'</span>', n++);
    html += row('Fecha', r.fecha ? r.fecha.substring(0,10) : '—', n++);

    // Section: Stripe
    html += sec('Stripe','<path d="M21 4H3a2 2 0 00-2 2v12a2 2 0 002 2h18a2 2 0 002-2V6a2 2 0 00-2-2z"/><path d="M1 10h22"/>');
    var piVal = r.stripe_pi
      ? '<code style="font-size:11px;background:#f1f5f9;padding:3px 8px;border-radius:4px;color:#334155;letter-spacing:.3px;">'+r.stripe_pi+'</code>'
      : '<span style="color:#94a3b8;">—</span>';
    html += row('Payment Intent', piVal, n++);
    html += row('Estado de pago', pagoBadge, n++);

    // Section: Punto de entrega
    html += sec('Punto de entrega','<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>');
    if(isPending){
      html += '<div style="padding:12px 16px;margin:0 -20px;background:#fffbeb;">'+
        '<span style="display:inline-block;background:#fbbf24;color:#92400e;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:600;">Pendiente de asignar</span>'+
        '<div style="font-size:12px;color:#92400e;margin-top:6px;">El cliente seleccionó "Centro Voltika cercano". Asignar manualmente.</div>'+
      '</div>';
    } else if(r.punto_nombre){
      html += row('Punto', '<span style="color:#059669;font-weight:700;">'+r.punto_nombre+'</span>', n++);
    } else {
      html += '<div style="padding:12px 16px;margin:0 -20px;background:#fef2f2;">'+
        '<span style="display:inline-block;background:#fecaca;color:#991b1b;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:600;">Sin punto seleccionado</span>'+
      '</div>';
    }

    // Section: Moto asignada
    html += sec('Moto asignada','<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 3v18"/>');
    if(r.moto_id){
      html += '<div style="padding:12px 16px;margin:0 -20px;background:#f0fdf4;">'+
        '<span style="display:inline-block;background:#059669;color:#fff;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:600;margin-right:8px;">'+(r.moto_vin||'VIN ****')+'</span>'+
        ADApp.badgeEstado(r.moto_estado||'—')+
      '</div>';
    } else {
      html += '<div style="padding:12px 16px;margin:0 -20px;background:#fef2f2;">'+
        '<span style="display:inline-block;background:#fecaca;color:#991b1b;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:600;">Sin moto asignada</span>'+
      '</div>';
    }

    ADApp.modal(html);
  }

  var _lastRows = [];

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
        '<label>Folio contrato<input id="rcvFolio" class="ad-input" placeholder="VK-YYYYMMDD-XXX"></label>'+
        '<label>Stripe PI<input id="rcvStripePi" class="ad-input" value="'+esc(stripePi||r.stripe_pi||'')+'" '+(isErr?'readonly':'')+'></label>'+
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
          $('#rcvMsg').html('<span style="color:#059669;">✓ Recuperada · tx_id='+resp.tx_id+' · folio='+(resp.folio||'')+'</span>');
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
          $('#vkeMsg').html('<span style="color:#059669;">✓ Actualizada. '+resp.updated_fields+' campos guardados.</span>');
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
