window.AD_ventas = (function(){

  function render(){
    ADApp.render(
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
        '<th>Tipo</th><th>Monto</th><th>Punto</th><th>Fecha</th><th>Moto asignada</th><th>Accion</th>'+
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
          '<td>'+puntoHtml+'</td>'+
          '<td>'+(r.fecha?r.fecha.substring(0,10):'-')+'</td>';

        var isOrphan = r.source === 'transacciones_errores' || r.source === 'subscripciones_credito';
        if(isOrphan){
          var isVksc = r.source === 'subscripciones_credito';
          var needsEdit = isVksc && (!r.modelo || r.modelo==='-' || !r.color || r.color==='-');
          html += '<td><span class="ad-badge yellow">'+(r.source==='transacciones_errores'?'Error':'Crédito huérfano')+'</span></td>'+
                  '<td>';
          if(needsEdit){
            // For VK-SC orphans missing modelo/color, the Recuperar action
            // can't know what to insert — show Editar first so the admin
            // can set modelo/color, then the Asignar flow works normally.
            html += '<button class="ad-btn primary" style="padding:5px 12px;font-size:12px;background:#d97706;" '+
                    'onclick="AD_ventas.showEditarVksc('+r.id+')">Editar datos</button> ';
          }
          html += '<button class="ad-btn primary" style="padding:5px 12px;font-size:12px;background:#b91c1c;" '+
                  'onclick="AD_ventas.showRecuperar('+r.id+',\''+esc(r.source)+'\',\''+esc(r.stripe_pi||'')+'\')">Recuperar</button> '+
                  '<button class="ad-btn sm ghost" style="margin-left:4px" onclick="AD_ventas.showDetalle('+r.id+')">Ver</button></td>';
        } else if(asignada){
          html += '<td><span class="ad-badge green">'+(r.moto_vin||'****')+'</span></td>'+
                  '<td><button class="ad-btn sm ghost" onclick="AD_ventas.showDetalle('+r.id+')">Ver</button></td>';
        } else {
          html += '<td><span class="ad-badge red">Sin asignar</span></td>'+
                  '<td><button class="ad-btn primary" style="padding:5px 12px;font-size:12px" '+
                  'onclick="AD_ventas.showAsignar('+r.id+',\''+esc(r.modelo)+'\',\''+esc(r.color)+'\',\'VK-'+(r.pedido||r.id)+'\')">Asignar</button> '+
                  '<button class="ad-btn sm ghost" style="margin-left:4px" onclick="AD_ventas.showDetalle('+r.id+')">Ver</button></td>';
        }
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
      '<div class="ad-dim" style="margin-bottom:12px;">Modelo: <strong>'+modelo+'</strong> &middot; Color: <strong>'+color+'</strong></div>'+
      '<div id="vtMotos">Buscando motos disponibles...</div>'
    );

    var url = 'ventas/motos-disponibles.php?modelo='+encodeURIComponent(modelo)+'&color='+encodeURIComponent(color);
    ADApp.api(url).done(function(r){
      if(!r.ok || !r.motos.length){
        // Show all bikes if none match model/color
        ADApp.api('ventas/motos-disponibles.php').done(function(r2){
          renderMotos(r2.motos||[], transId, pedido, true);
        });
        return;
      }
      renderMotos(r.motos, transId, pedido, false);
    });
  }

  function renderMotos(motos, transId, pedido, showAll){
    if(!motos.length){
      $('#vtMotos').html('<div style="text-align:center;padding:20px;color:var(--ad-dim);">No hay motos disponibles en inventario</div>');
      return;
    }

    var html = '';
    if(showAll){
      html += '<div class="ad-banner warn" style="margin-bottom:10px;">No hay motos del mismo modelo/color. Mostrando todas las disponibles.</div>';
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
    }).fail(function(){
      alert('Error de conexion');
    });
  }

  function kpi(label, value, color){
    return '<div class="ad-kpi"><div class="label">'+label+'</div><div class="value '+color+'">'+value+'</div></div>';
  }

  function showDetalle(transId){
    var rows = _lastRows || [];
    var r = null;
    for(var i=0;i<rows.length;i++){ if(rows[i].id===transId){ r=rows[i]; break; } }
    if(!r) return;

    var isPending = r.punto_id==='centro-cercano';
    var html = '<div class="ad-h2">Orden VK-'+(r.pedido||r.id)+'</div>';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px">';
    [['Cliente',r.nombre||'—'],['Email',r.email||'—'],['Teléfono',r.telefono||'—'],
     ['Modelo',r.modelo||'—'],['Color',r.color||'—'],['Tipo pago',r.tipo||'—'],
     ['Monto',ADApp.money(r.monto)],['Fecha',r.fecha?r.fecha.substring(0,10):'—'],
     ['Stripe PI',r.stripe_pi||'—'],['Pago',r.pago_estado||'pendiente']].forEach(function(p){
      html += '<div><span style="color:var(--ad-dim)">'+p[0]+':</span> <strong>'+p[1]+'</strong></div>';
    });
    html += '</div>';
    // Punto info
    html += '<div class="ad-h2" style="margin-top:12px;">Punto de entrega</div>';
    if(isPending){
      html += '<span class="ad-badge yellow">Pendiente de asignar punto</span>';
      html += '<div style="font-size:12px;color:var(--ad-dim);margin-top:4px;">El cliente seleccionó "Centro Voltika cercano". Asignar manualmente.</div>';
    } else if(r.punto_nombre){
      html += '<span class="ad-badge green">'+r.punto_nombre+'</span>';
    } else {
      html += '<span class="ad-badge red">Sin punto seleccionado</span>';
    }
    // Moto info
    html += '<div class="ad-h2" style="margin-top:12px;">Moto asignada</div>';
    if(r.moto_id){
      html += '<span class="ad-badge green">'+(r.moto_vin||'VIN ****')+'</span> · '+ADApp.badgeEstado(r.moto_estado||'—');
    } else {
      html += '<span class="ad-badge red">Sin moto asignada</span>';
    }
    ADApp.modal(html);
  }

  var _lastRows = [];

  function esc(s){
    return (s||'').replace(/'/g,"\\'").replace(/"/g,'&quot;');
  }

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
