window.AD_ventas = (function(){

  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  function render(){
    ADApp.render(
      _backBtn+
      '<div class="ad-toolbar">'+
        '<div class="ad-h1">Ventas / Ordenes</div>'+
        '<div style="display:flex;align-items:center;gap:10px;">'+
          '<span id="vtLastUpdate" style="font-size:11px;color:var(--ad-dim);"></span>'+
          '<button class="ad-btn" id="vtRepararPhantom" style="background:#ffebee;color:#c62828;border-color:#ef9a9a;padding:6px 12px;font-size:13px;" title="Reparar/archivar órdenes con datos incompletos (nombre o modelo vacíos)">'+
            '🛠 Reparar phantom</button>'+
          '<button class="ad-btn" id="vtNormalizar" style="background:#fff4e5;color:#b26200;border-color:#f0c378;padding:6px 12px;font-size:13px;" title="Normalizar modelo/color de ventas legacy (&quot;Voltika Tromox Pesgo&quot; → &quot;Pesgo Plus&quot;, &quot;Gris moderno&quot; → &quot;gris&quot;) para que encuentren motos disponibles">'+
            '🔧 Normalizar catálogo</button>'+
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
    $('#vtNormalizar').on('click', showNormalizarCatalogo);
    $('#vtRepararPhantom').on('click', showRepararPhantom);
  }

  // Modal: preview phantoms (empty nombre/modelo rows) with proposed
  // backfill from Stripe metadata + subscripciones_credito. Admin chooses
  // to backfill (apply proposal), archive (hide), or delete (last resort).
  function showRepararPhantom(){
    ADApp.modal(
      '<div class="ad-h2">🛠 Reparar órdenes phantom</div>'+
      '<div style="font-size:13px;color:var(--ad-dim);margin-bottom:12px;line-height:1.5;">'+
        'Órdenes con <strong>nombre</strong> o <strong>modelo</strong> vacíos. Propuesta de relleno viene de Stripe PI metadata y <code>subscripciones_credito</code>.'+
      '</div>'+
      '<div id="vtPhantomContent" style="min-height:80px;">'+
        '<div style="text-align:center;padding:30px;"><span class="ad-spin"></span> Analizando pedidos...</div>'+
      '</div>'
    );
    $.get('php/ventas/reparar-phantom.php').done(function(r){
      if (!r.ok) { $('#vtPhantomContent').html('<div class="ad-banner warn">'+(r.error||'Error')+'</div>'); return; }
      var ph = r.phantoms || [];
      if (!ph.length) {
        $('#vtPhantomContent').html(
          '<div style="background:#E8F5E9;padding:14px;border-radius:8px;color:#2E7D32;font-size:14px;">'+
            '✅ No hay órdenes phantom. Todas las transacciones tienen nombre + modelo.'+
          '</div>'+
          '<div style="display:flex;justify-content:flex-end;margin-top:12px;">'+
            '<button class="ad-btn ghost" id="vtPhantomClose">Cerrar</button>'+
          '</div>'
        );
        $('#vtPhantomClose').on('click', function(){ ADApp.closeModal(); });
        return;
      }

      var html = '<div style="background:#FFEBEE;padding:10px 14px;border-radius:8px;color:#C62828;font-size:13px;margin-bottom:10px;">'+
        'Se encontraron <strong>'+ph.length+'</strong> órdenes phantom. Selecciona y aplica una acción.'+
        '</div>';
      html += '<div style="max-height:380px;overflow-y:auto;border:1px solid var(--ad-border);border-radius:8px;">';
      html += '<table style="width:100%;border-collapse:collapse;font-size:12px;">'+
              '<thead style="background:#f0f4f8;position:sticky;top:0;">'+
                '<tr>'+
                  '<th style="width:30px;padding:8px;"><input type="checkbox" id="vtPhSelAll"></th>'+
                  '<th style="text-align:left;padding:8px;">Pedido</th>'+
                  '<th style="text-align:left;padding:8px;">Nombre propuesto</th>'+
                  '<th style="text-align:left;padding:8px;">Modelo/Color propuesto</th>'+
                  '<th style="text-align:left;padding:8px;">Monto / Tipo</th>'+
                  '<th style="text-align:left;padding:8px;">Fuentes</th>'+
                '</tr>'+
              '</thead><tbody>';
      ph.forEach(function(c){
        var src = [];
        if (c.sources && c.sources.stripe) src.push('Stripe');
        if (c.sources && c.sources.subs) src.push('Subs.');
        var srcTxt = src.length ? src.join('+') : '<span style="color:#c62828;">ninguna</span>';
        html += '<tr style="border-top:1px solid var(--ad-border);'+(c.can_backfill?'':'background:#FFEBEE;')+'">'+
          '<td style="padding:8px;"><input type="checkbox" class="vtPhSel" data-id="'+c.id+'"></td>'+
          '<td style="padding:8px;"><code style="font-size:11px;">'+esc(c.pedido_corto||'')+'</code></td>'+
          '<td style="padding:8px;">'+
            (c.proposal.nombre ? '<strong style="color:#1e7e34;">'+esc(c.proposal.nombre)+'</strong>' : '<span style="color:#c62828;">— sin dato —</span>')+
            (c.proposal.telefono ? '<br><small class="ad-dim">'+esc(c.proposal.telefono)+'</small>' : '')+
          '</td>'+
          '<td style="padding:8px;">'+
            (c.proposal.modelo ? '<strong style="color:#1e7e34;">'+esc(c.proposal.modelo)+'</strong>' : '<span style="color:#c62828;">— sin dato —</span>')+
            (c.proposal.color ? ' · '+esc(c.proposal.color) : '')+
          '</td>'+
          '<td style="padding:8px;">$'+numFmtLocal(c.current.monto||0)+'<br><small class="ad-dim">'+esc(c.current.tpago||'')+'</small></td>'+
          '<td style="padding:8px;font-size:11px;">'+srcTxt+'</td>'+
        '</tr>';
      });
      html += '</tbody></table></div>';
      html += '<div id="vtPhantomMsg" style="font-size:12px;margin-top:8px;"></div>';
      html += '<div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;">'+
              '<button class="ad-btn ghost" id="vtPhCancel" style="flex:1;min-width:90px;">Cancelar</button>'+
              '<button class="ad-btn" id="vtPhArchive" style="flex:1;min-width:120px;background:#FFF3E0;color:#E65100;border-color:#FFE0B2;">Archivar</button>'+
              '<button class="ad-btn primary" id="vtPhBackfill" style="flex:1;min-width:120px;">Rellenar datos</button>'+
            '</div>';

      $('#vtPhantomContent').html(html);

      $('#vtPhSelAll').on('change', function(){
        $('.vtPhSel').prop('checked', this.checked);
      });
      $('#vtPhCancel').on('click', function(){ ADApp.closeModal(); });

      function selectedIds(){
        return $('.vtPhSel:checked').map(function(){ return parseInt($(this).data('id'),10); }).get();
      }

      $('#vtPhBackfill').on('click', function(){
        var ids = selectedIds();
        if (!ids.length) { $('#vtPhantomMsg').css('color','#b91c1c').text('Selecciona al menos una orden'); return; }
        if (!confirm('Rellenar '+ids.length+' orden(es) con los datos propuestos?')) return;
        $(this).prop('disabled', true).html('<span class="ad-spin"></span> Aplicando...');
        ADApp.api('ventas/reparar-phantom.php', {
          method: 'POST', contentType: 'application/json',
          data: JSON.stringify({ action: 'backfill', ids: ids })
        }).done(function(rr){
          if (rr.ok){
            $('#vtPhantomMsg').css('color','#1e7e34').text(rr.message || 'Aplicado');
            setTimeout(function(){ ADApp.closeModal(); loadData(); }, 900);
          } else {
            $('#vtPhantomMsg').css('color','#b91c1c').text(rr.error || 'Error');
            $('#vtPhBackfill').prop('disabled', false).text('Rellenar datos');
          }
        }).fail(function(x){
          $('#vtPhantomMsg').css('color','#b91c1c').text((x.responseJSON && x.responseJSON.error) || 'Error de conexión');
          $('#vtPhBackfill').prop('disabled', false).text('Rellenar datos');
        });
      });

      $('#vtPhArchive').on('click', function(){
        var ids = selectedIds();
        if (!ids.length) { $('#vtPhantomMsg').css('color','#b91c1c').text('Selecciona al menos una orden'); return; }
        var motivo = prompt('Motivo del archivo (opcional):', 'Orden phantom sin datos recuperables');
        if (motivo === null) return;
        $(this).prop('disabled', true).html('<span class="ad-spin"></span>');
        ADApp.api('ventas/reparar-phantom.php', {
          method: 'POST', contentType: 'application/json',
          data: JSON.stringify({ action: 'archive', ids: ids, motivo: motivo })
        }).done(function(rr){
          if (rr.ok){
            $('#vtPhantomMsg').css('color','#1e7e34').text('Archivadas: '+rr.archived);
            setTimeout(function(){ ADApp.closeModal(); loadData(); }, 700);
          } else {
            $('#vtPhantomMsg').css('color','#b91c1c').text(rr.error || 'Error');
            $('#vtPhArchive').prop('disabled', false).text('Archivar');
          }
        }).fail(function(x){
          $('#vtPhantomMsg').css('color','#b91c1c').text((x.responseJSON && x.responseJSON.error) || 'Error de conexión');
          $('#vtPhArchive').prop('disabled', false).text('Archivar');
        });
      });
    }).fail(function(x){
      $('#vtPhantomContent').html('<div class="ad-banner warn">'+((x.responseJSON && x.responseJSON.error) || 'Error de conexión')+'</div>');
    });
  }

  function numFmtLocal(n){
    var x = Number(n)||0;
    return x.toLocaleString('es-MX', {minimumFractionDigits:0, maximumFractionDigits:0});
  }

  // Modal: preview + commit de normalización de modelo/color en transacciones.
  // Convierte ventas legacy ("Voltika Tromox Pesgo" / "Gris moderno") al
  // código corto que usa inventario_motos para que puedan asignarse.
  function showNormalizarCatalogo(){
    ADApp.modal(
      '<div class="ad-h2">🔧 Normalizar catálogo</div>'+
      '<div style="font-size:13px;color:var(--ad-dim);margin-bottom:12px;line-height:1.5;">'+
        'Busca pedidos con valores legacy en <code>modelo</code>/<code>color</code> '+
        '(ej. <code>Voltika Tromox Pesgo</code>, <code>Gris moderno</code>) y los '+
        'reemplaza por el código corto del catálogo nuevo (<code>Pesgo Plus</code>, <code>gris</code>). '+
        'Después, el pedido podrá encontrar motos disponibles en el inventario.'+
      '</div>'+
      '<div id="vtNormContent" style="min-height:80px;">'+
        '<div style="text-align:center;padding:30px;"><span class="ad-spin"></span> Analizando pedidos...</div>'+
      '</div>'
    );
    ADApp.api('ventas/normalizar-catalogo.php').done(function(r){
      if (!r.ok) { $('#vtNormContent').html('<div class="ad-banner warn">'+(r.error||'Error')+'</div>'); return; }
      if (!r.changes || !r.changes.length){
        $('#vtNormContent').html(
          '<div style="background:#E8F5E9;padding:14px;border-radius:8px;color:#2E7D32;font-size:14px;">'+
            '✅ No se detectaron pedidos con valores legacy. Todo está en formato canónico.'+
          '</div>'+
          '<div style="display:flex;justify-content:flex-end;margin-top:12px;">'+
            '<button class="ad-btn ghost" id="vtNormClose">Cerrar</button>'+
          '</div>'
        );
        $('#vtNormClose').on('click', function(){ ADApp.closeModal(); });
        return;
      }
      var html = '<div style="background:#FFF3E0;padding:10px 14px;border-radius:8px;color:#E65100;font-size:13px;margin-bottom:10px;">'+
        'Se encontraron <strong>'+r.changes.length+'</strong> pedidos para normalizar. Revisa y confirma.'+
        '</div>';
      html += '<div style="max-height:380px;overflow-y:auto;border:1px solid var(--ad-border);border-radius:8px;">';
      html += '<table style="width:100%;border-collapse:collapse;font-size:12.5px;">'+
              '<thead style="background:#f0f4f8;position:sticky;top:0;">'+
                '<tr>'+
                  '<th style="text-align:left;padding:8px;">Pedido</th>'+
                  '<th style="text-align:left;padding:8px;">Cliente</th>'+
                  '<th style="text-align:left;padding:8px;">Modelo</th>'+
                  '<th style="text-align:left;padding:8px;">Color</th>'+
                '</tr>'+
              '</thead><tbody>';
      r.changes.forEach(function(c){
        html += '<tr style="border-top:1px solid var(--ad-border);">'+
          '<td style="padding:8px;"><code style="font-size:11px;">'+esc(c.pedido||'')+'</code></td>'+
          '<td style="padding:8px;">'+esc(c.nombre||'')+'</td>'+
          '<td style="padding:8px;">'+
            (c.changed_m
              ? '<span style="color:#b91c1c;text-decoration:line-through;">'+esc(c.modelo_old||'')+'</span> → <strong style="color:#1e7e34;">'+esc(c.modelo_new||'')+'</strong>'
              : '<span style="color:var(--ad-dim);">'+esc(c.modelo_new||'')+'</span>')+
          '</td>'+
          '<td style="padding:8px;">'+
            (c.changed_c
              ? '<span style="color:#b91c1c;text-decoration:line-through;">'+esc(c.color_old||'')+'</span> → <strong style="color:#1e7e34;">'+esc(c.color_new||'')+'</strong>'
              : '<span style="color:var(--ad-dim);">'+esc(c.color_new||'')+'</span>')+
          '</td>'+
        '</tr>';
      });
      html += '</tbody></table></div>';
      html += '<div style="display:flex;gap:8px;margin-top:12px;">'+
              '<button class="ad-btn ghost" id="vtNormCancel" style="flex:1;">Cancelar</button>'+
              '<button class="ad-btn primary" id="vtNormApply" style="flex:1;background:#FB8C00;border-color:#FB8C00;">Aplicar normalización ('+r.changes.length+')</button>'+
            '</div>';
      html += '<div id="vtNormMsg" style="font-size:12px;margin-top:8px;"></div>';
      $('#vtNormContent').html(html);

      $('#vtNormCancel').on('click', function(){ ADApp.closeModal(); });
      $('#vtNormApply').on('click', function(){
        if (!confirm('Se actualizarán '+r.changes.length+' registros de transacciones. ¿Continuar?')) return;
        var $btn = $(this).prop('disabled', true).html('<span class="ad-spin"></span> Aplicando...');
        ADApp.api('ventas/normalizar-catalogo.php', {
          method: 'POST', contentType: 'application/json',
          data: JSON.stringify({ confirm: true })
        }).done(function(rr){
          if (rr.ok){
            $('#vtNormMsg').css('color','#1e7e34').text(rr.message || 'Aplicado');
            setTimeout(function(){ ADApp.closeModal(); loadData(); }, 800);
          } else {
            $btn.prop('disabled', false).text('Aplicar normalización');
            $('#vtNormMsg').css('color','#b91c1c').text(rr.error || 'Error');
          }
        }).fail(function(x){
          $btn.prop('disabled', false).text('Aplicar normalización');
          $('#vtNormMsg').css('color','#b91c1c').text((x.responseJSON && x.responseJSON.error) || 'Error de conexión');
        });
      });
    }).fail(function(x){
      $('#vtNormContent').html('<div class="ad-banner warn">'+((x.responseJSON && x.responseJSON.error) || 'Error de conexión')+'</div>');
    });
  }

  // Visual feedback after a row was just modified — paints the row green
  // for ~1.2s so the admin sees that the change landed even if the modal
  // is still open over the table.
  function flashRow(rowId){
    setTimeout(function(){
      var $tr = $('#vtTable tr[data-row-id="'+rowId+'"]');
      if (!$tr.length) return;
      $tr.css({transition:'background-color .25s ease', background:'#d1fae5'});
      setTimeout(function(){ $tr.css({background:''}); }, 1200);
    }, 100);
  }

  function loadData(cb){
    var _loadStart = Date.now();
    ADApp.api('ventas/listar.php').done(function(r){
      var elapsed = ((Date.now() - _loadStart) / 1000).toFixed(1);
      var now = new Date();
      var timeStr = now.getHours().toString().padStart(2,'0') + ':' + now.getMinutes().toString().padStart(2,'0') + ':' + now.getSeconds().toString().padStart(2,'0');
      $('#vtLastUpdate').html('Actualizado: ' + timeStr + ' (' + elapsed + 's)');
      if(!r.ok){ $('#vtTable').html('<div class="ad-card">Error al cargar</div>'); if(cb) cb(); return; }

      // KPIs
      var pendingPunto = (r.rows||[]).filter(function(o){ return o.punto_id==='centro-cercano'; }).length;
      var orfanos = r.orfanos || (r.rows||[]).filter(function(o){ return o.source; }).length;
      var phantom = r.phantom || (r.rows||[]).filter(function(o){ return o.datos_incompletos; }).length;
      $('#vtKpis').html(
        kpi('Total ordenes', r.total, 'blue')+
        kpi('Moto asignada', r.asignadas, 'green')+
        kpi('Sin asignar', r.sin_asignar, r.sin_asignar > 0 ? 'red' : 'green')+
        kpi('Ventas con Pago', r.con_pago||0, 'green')+
        kpi('Ventas sin Pago', r.sin_pago||0, (r.sin_pago||0) > 0 ? 'red' : 'green')+
        kpi('Punto pendiente', pendingPunto, pendingPunto > 0 ? 'yellow' : 'green')+
        kpi('Huérfanos/errores', orfanos, orfanos > 0 ? 'red' : 'green')+
        kpi('Datos incompletos', phantom, phantom > 0 ? 'red' : 'green')
      );
      var rows = r.rows || [];
      _lastRows = rows;
      renderTable(rows);
      if(cb) cb();
    }).fail(function(){
      $('#vtTable').html('<div class="ad-card">Error de conexion</div>');
      if(cb) cb();
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

      // Phantom order badge — rows where nombre/modelo are empty. Customer
      // report 2026-04-23 flagged blank cells in Cliente/Modelo columns
      // (VK-2604-0011/0012/0013). Visible warning + tooltip so admin can
      // quickly spot them and invoke the Reparar phantom tool.
      if (r.datos_incompletos) {
        extrasHtml += '<span title="Orden con datos incompletos — falta nombre o modelo. Usa &quot;Reparar phantom&quot; en la barra superior." '+
                      'style="display:inline-block;margin-left:4px;padding:2px 8px;background:#FFEBEE;color:#C62828;border:1px solid #EF9A9A;border-radius:10px;font-size:10px;font-weight:700;letter-spacing:.3px;cursor:help;">⚠ DATOS INCOMPLETOS</span>';
      }

      // Highlight the whole row so phantoms stand out from clean orders.
      var rowStyle = r.datos_incompletos ? ' style="background:#FFF5F5;"' : '';

      html += '<tr data-row-id="'+r.id+'"'+rowStyle+'>'+
        '<td><strong>'+(r.pedido_corto||'VK-'+(r.pedido||r.id))+'</strong>'+extrasHtml+alertaHtml+'</td>'+
        '<td>'+(r.nombre ? esc(r.nombre) : '<span style="color:#C62828;font-style:italic;">— sin nombre —</span>')+'<br><small class="ad-dim">'+(r.telefono||'')+'</small></td>'+
        '<td>'+(r.modelo ? esc(r.modelo) : '<span style="color:#C62828;font-style:italic;">— sin modelo —</span>')+'</td>'+
        '<td>'+(r.color || '<span class="ad-dim">—</span>')+'</td>'+
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

    // Card-based radio picker (matches the asignar-punto modal pattern). The
    // previous flex row collapsed catastrophically on mobile — text wrapped
    // letter-by-letter because VIN, two badges and a button were forced into
    // a single narrow row. Now each moto is a stacked card with VIN on top,
    // meta below; selection happens via a single bottom Confirm button.
    var html = '';
    if (showAll) {
      html += '<div class="ad-banner warn" style="margin-bottom:10px;">No hay motos del mismo color. Mostrando otras unidades del mismo modelo.</div>';
    }

    // Index motos by id so the Confirmar handler can look up checklist status
    // without having to re-parse DOM data attributes.
    var motoIndex = {};
    motos.forEach(function(m){ motoIndex[m.id] = m; });

    html += '<div style="max-height:340px;overflow-y:auto;padding-right:4px;">';
    motos.forEach(function(m, i){
      var vinTxt   = m.vin_display || m.vin || '—';
      var metaTxt  = (m.modelo || '') + (m.color ? ' · ' + m.color : '') + (m.estado ? ' · ' + m.estado : '');
      var locTxt   = m.punto_nombre ? m.punto_nombre : 'En CEDIS';

      // Checklist status: three states rendered as colored pills.
      //  - green  "Checklist OK"       → co_ok=1 and co_force=0
      //  - orange "Revisión pendiente" → co_force=1 (bulk-completed without inspection)
      //  - red    "Sin checklist"      → no checklist_origen row at all
      var coOk    = Number(m.co_ok)    === 1;
      var coForce = Number(m.co_force) === 1;
      var hasCl   = !!m.co_id;
      var badgeHtml, ctaHtml;
      if (coOk && !coForce) {
        badgeHtml = '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#E8F5E9;color:#2E7D32;font-size:11px;font-weight:700;">✓ Checklist OK</span>';
        ctaHtml = '';
      } else if (coForce) {
        badgeHtml = '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#FFF3E0;color:#E65100;font-size:11px;font-weight:700;">⚠️ Revisión pendiente</span>';
        ctaHtml = '<button type="button" class="ad-btn sm vtClFill" data-mid="'+m.id+'" '+
                    'style="margin-top:6px;background:#FB8C00;color:#fff;border-color:#FB8C00;font-size:12px;padding:4px 10px;">'+
                    '🔧 Completar checklist</button>';
      } else if (hasCl) {
        badgeHtml = '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#FFF8E1;color:#B28704;font-size:11px;font-weight:700;">En progreso</span>';
        ctaHtml = '<button type="button" class="ad-btn sm vtClFill" data-mid="'+m.id+'" '+
                    'style="margin-top:6px;background:#F9A825;color:#fff;border-color:#F9A825;font-size:12px;padding:4px 10px;">'+
                    '▶ Continuar checklist</button>';
      } else {
        badgeHtml = '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#FDECEA;color:#B71C1C;font-size:11px;font-weight:700;">✗ Sin checklist</span>';
        ctaHtml = '<button type="button" class="ad-btn sm vtClFill" data-mid="'+m.id+'" '+
                    'style="margin-top:6px;background:#C62828;color:#fff;border-color:#C62828;font-size:12px;padding:4px 10px;">'+
                    '＋ Iniciar checklist</button>';
      }

      html += '<label class="adPickMoto" data-mid="'+m.id+'" '+
                'style="display:block;cursor:pointer;padding:11px 13px;margin-bottom:6px;'+
                       'border:1.5px solid var(--ad-border);border-radius:8px;background:var(--ad-surface);">'+
                '<div style="display:flex;gap:10px;align-items:flex-start;">'+
                  '<input type="radio" name="motoChoice" value="'+m.id+'" style="margin-top:3px;flex-shrink:0;"'+(i===0?' checked':'')+'>'+
                  '<div style="flex:1;min-width:0;">'+
                    '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'+
                      '<div style="font-weight:700;font-size:13.5px;color:var(--ad-navy);font-family:ui-monospace,Menlo,Consolas,monospace;word-break:break-all;">'+vinTxt+'</div>'+
                      badgeHtml+
                    '</div>'+
                    '<div style="font-size:12px;color:var(--ad-dim);margin-top:3px;">'+metaTxt+'</div>'+
                    '<div style="font-size:11.5px;color:#666;margin-top:2px;">'+locTxt+'</div>'+
                    (ctaHtml ? '<div>'+ctaHtml+'</div>' : '')+
                  '</div>'+
                '</div>'+
              '</label>';
    });
    html += '</div>';
    html += '<div id="vtMotosMsg" style="font-size:12px;margin:8px 0 0;"></div>';
    html += '<div style="display:flex;gap:8px;margin-top:12px;">'+
              '<button class="ad-btn ghost" id="vtMotosCancel" style="flex:1;">Cancelar</button>'+
              '<button class="ad-btn primary" id="vtMotosSave" style="flex:1;">Confirmar moto</button>'+
            '</div>';

    $('#vtMotos').html(html);

    // Highlight selected card
    function syncHighlight(){
      $('.adPickMoto').css({borderColor:'var(--ad-border)', background:'var(--ad-surface)'});
      var $sel = $('input[name="motoChoice"]:checked').closest('.adPickMoto');
      $sel.css({borderColor:'var(--ad-primary)', background:'#E8F4FD'});
    }
    syncHighlight();

    $('.adPickMoto').on('click', function(e){
      // Avoid swallowing clicks on the inner "Completar checklist" button
      if ($(e.target).closest('.vtClFill').length) return;
      $(this).find('input[type="radio"]').prop('checked', true);
      syncHighlight();
    });

    // Fast-fill: open checklist modal without losing the Asignar context.
    // When the inner modal closes, re-open Asignar so the list refreshes with
    // up-to-date checklist status.
    $('.vtClFill').on('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      var mid = parseInt($(this).data('mid'), 10);
      if (!window.AD_checklists || typeof window.AD_checklists.openOrigenById !== 'function') {
        alert('Módulo de checklists no disponible');
        return;
      }
      // Close the Asignar modal first, then open the checklist. After the user
      // finishes (or cancels), re-open Asignar so they can confirm the bike.
      ADApp.closeModal();
      window.AD_checklists.openOrigenById(mid, function(){
        showAsignar(transId, modelo, (motoIndex[mid] && motoIndex[mid].color) || '', pedido);
      });
    });

    $('#vtMotosCancel').on('click', function(){ ADApp.closeModal(); });

    $('#vtMotosSave').on('click', function(){
      var motoId = parseInt($('input[name="motoChoice"]:checked').val(), 10);
      if (!motoId) { $('#vtMotosMsg').css('color','#b91c1c').text('Selecciona una moto'); return; }
      var m = motoIndex[motoId] || {};
      var coOk    = Number(m.co_ok)    === 1;
      var coForce = Number(m.co_force) === 1;
      // Block assignment when the bike has no real origin inspection.
      // User can still override by clicking Confirmar a second time after
      // acknowledging — avoids a hard-stop while nudging correct workflow.
      if (!coOk || coForce) {
        var msg = coForce
          ? 'Esta moto tiene el checklist marcado como "completado" pero no fue inspeccionada (bulk-complete).\n\n¿Asignar de todas formas sin revisión física?'
          : 'Esta moto NO tiene checklist de origen completado.\n\n¿Asignar de todas formas? (No recomendado — la moto debería inspeccionarse antes de entregar al cliente.)';
        if (!confirm(msg)) return;
      }
      doAsignar(transId, motoId);
    });
  }

  function doAsignar(transId, motoId){
    var $btn = $('#vtMotosSave').prop('disabled', true).html('<span class="ad-spin"></span> Guardando...');
    ADApp.api('ventas/asignar-moto.php', {
      transaccion_id: transId,
      moto_id: motoId
    }).done(function(r){
      if(r.ok){
        ADApp.closeModal();
        loadData();
      } else {
        $('#vtMotosMsg').css('color','#b91c1c').text(r.error || 'Error al asignar');
        $btn.prop('disabled', false).text('Confirmar moto');
      }
    }).fail(function(x){
      $('#vtMotosMsg').css('color','#b91c1c').text((x.responseJSON && x.responseJSON.error) || 'Error de conexión');
      $btn.prop('disabled', false).text('Confirmar moto');
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
    // Surface the ETA captured in "Asignar punto". Highlighted so the admin
    // can spot missing dates at a glance and trigger the flow to set one.
    var etaTxt = r.fecha_estimada_entrega
      ? '<span style="font-weight:700;color:#0e8f55;">'+String(r.fecha_estimada_entrega).substring(0,10)+'</span>'
      : '<span style="color:#b91c1c;font-size:12px;">Sin definir — asigna punto para capturarla</span>';
    html += fRow('ETA entrega', etaTxt);
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
    // Recovery CTA when stripe_pi is missing. Eduardo Gonzalez Lopez's order
    // (VK-1776828725) paid successfully but the webhook never stored the PI —
    // this lets the operator auto-match it against Stripe by email + monto.
    if (!r.stripe_pi){
      html += '<div style="background:#FFF3E0;border:1px solid #FFE0B2;border-radius:8px;padding:10px 12px;margin-bottom:10px;">'+
              '<div style="font-size:12.5px;color:#E65100;margin-bottom:8px;">'+
                'Esta orden no tiene <code>stripe_pi</code>. Si el cliente pagó, busca el cargo en Stripe para vincularlo.'+
              '</div>'+
              '<button class="ad-btn sm primary vtBuscarStripe" data-tid="'+r.id+'" '+
                'style="background:#FB8C00;border-color:#FB8C00;font-weight:700;">🔍 Buscar pago en Stripe</button>'+
              '<div id="vtBuscarStripeOut_'+r.id+'" style="margin-top:8px;font-size:12px;"></div>'+
              '</div>';
    }

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

    // Wire "Buscar pago en Stripe" (orders without stripe_pi)
    $('.vtBuscarStripe').off('click').on('click', function(){
      var tid = $(this).data('tid');
      buscarStripePi(tid);
    });
  }

  // Auto-match Stripe PaymentIntent for a transacción that lost its stripe_pi.
  // Shows candidates by email+amount+date and lets the admin link one.
  function buscarStripePi(tid){
    var $out = $('#vtBuscarStripeOut_' + tid);
    $out.html('<span class="ad-spin"></span> Buscando en Stripe...');
    $.get('php/ventas/buscar-stripe-pi.php?transaccion_id=' + encodeURIComponent(tid))
      .done(function(rr){
        if (!rr.ok) { $out.html('<span style="color:#b91c1c;">'+(rr.error||'Error')+'</span>'); return; }
        if (rr.already_linked) {
          $out.html('<span style="color:#1e7e34;">Ya estaba vinculado: <code>'+rr.stripe_pi+'</code></span>');
          return;
        }
        var sum = rr.summary || {exact:0,amount:0,email:0};
        var total = (sum.exact||0)+(sum.amount||0)+(sum.email||0);
        if (total === 0) {
          $out.html(
            '<div style="color:var(--ad-dim);margin-bottom:6px;">No se encontraron PaymentIntents para '+
              '<strong>'+(rr.order && rr.order.email || '—')+'</strong> por <strong>$'+(rr.order && rr.order.total || 0).toLocaleString()+'</strong>.</div>'+
            '<div style="display:flex;gap:6px;">'+
              '<input type="text" id="vtManualPi_'+tid+'" placeholder="pi_xxx..." class="ad-input" style="flex:1;font-family:monospace;font-size:11px;">'+
              '<button class="ad-btn sm primary" id="vtManualLink_'+tid+'">Vincular</button>'+
            '</div>'
          );
          $('#vtManualLink_'+tid).on('click', function(){
            var pi = ($('#vtManualPi_'+tid).val()||'').trim();
            if (pi) vincularStripePi(tid, pi);
          });
          return;
        }

        var h = '<div style="background:#fff;border:1px solid var(--ad-border);border-radius:6px;padding:8px;max-height:260px;overflow-y:auto;">';
        function tier(title, bg, items){
          if (!items || !items.length) return '';
          var t = '<div style="font-size:11px;color:var(--ad-dim);font-weight:600;margin:6px 0 3px;">'+title+'</div>';
          items.forEach(function(it){
            t += '<div style="display:flex;align-items:center;justify-content:space-between;padding:6px 8px;background:'+bg+';border-radius:5px;margin-bottom:3px;gap:6px;">'+
                 '<div style="flex:1;min-width:0;font-size:11.5px;">'+
                   '<div><code style="font-size:10.5px;">'+(it.id||'')+'</code> · '+(it.status||'')+'</div>'+
                   '<div style="color:var(--ad-dim);margin-top:2px;">'+ADApp.money(it.amount||0)+' · '+(it.email||'—')+' · '+(it.created||'')+'</div>'+
                 '</div>'+
                 '<button class="ad-btn sm primary vtLinkPi" data-tid="'+tid+'" data-pi="'+it.id+'">Vincular</button>'+
                 '</div>';
          });
          return t;
        }
        h += tier('Coincidencia exacta (email + monto)', '#E8F5E9', rr.matches.exact);
        h += tier('Solo monto',  '#FFF8E1', rr.matches.amount);
        h += tier('Solo email',  '#FFF3E0', rr.matches.email);
        h += '</div>';
        $out.html(h);
        $('.vtLinkPi').off('click').on('click', function(){
          vincularStripePi($(this).data('tid'), $(this).data('pi'));
        });
      })
      .fail(function(x){
        $out.html('<span style="color:#b91c1c;">'+((x.responseJSON && x.responseJSON.error) || 'Error de conexión')+'</span>');
      });
  }

  function vincularStripePi(tid, pi){
    if (!confirm('¿Vincular '+pi+' a este pedido? Se recalculará el estado de pago.')) return;
    $.ajax({
      url: 'php/ventas/buscar-stripe-pi.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ transaccion_id: parseInt(tid,10), stripe_pi: pi })
    }).done(function(rr){
      if (rr.ok){
        alert('Vinculado. Estado: '+rr.pago_estado);
        ADApp.closeModal();
        render();
      } else {
        alert(rr.error || 'Error');
      }
    }).fail(function(x){
      alert((x.responseJSON && x.responseJSON.error) || 'Error de conexión');
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

      html += '</div>';

      // ETA input: default to today + 10 days, matching the notification text.
      // Customer feedback 2026-04-22: previously the modal had no date field,
      // so transacciones.fecha_estimada_entrega was never set and the Envíos
      // page ETA column stayed empty. Capturing it here closes that gap.
      var d = new Date(); d.setDate(d.getDate() + 10);
      var defaultEta = d.toISOString().slice(0, 10);
      var minEta     = new Date().toISOString().slice(0, 10);
      // If the order already has an ETA, preselect it so the admin can edit
      // rather than overwrite blindly on re-open of the modal.
      var currentEta = r.fecha_estimada_entrega ? String(r.fecha_estimada_entrega).slice(0, 10) : '';
      html += '<div style="background:var(--ad-surface-2);padding:10px 12px;border-radius:6px;margin-top:10px;">'
            +   '<label style="display:block;font-size:12px;font-weight:700;color:var(--ad-navy);margin-bottom:6px;">'
            +     'Fecha estimada de entrega'
            +     '<span style="color:var(--ad-dim);font-weight:500;margin-left:6px;">(se mostrará al cliente y en Envíos)</span>'
            +   '</label>'
            +   '<input type="date" id="vkAsignPuntoEta" class="ad-input" '
            +     'min="'+minEta+'" value="'+(currentEta || defaultEta)+'" '
            +     'style="font-family:ui-monospace,Menlo,Consolas,monospace;">'
            + '</div>';

      html += '<div id="vkAsignPuntoMsg" style="font-size:12px;margin:10px 0 0;"></div>'
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
        var eta = ($('#vkAsignPuntoEta').val() || '').trim();
        if (!eta) { $('#vkAsignPuntoMsg').css('color','#b91c1c').text('Selecciona la fecha estimada de entrega'); return; }
        var $btn = $(this).prop('disabled', true).html('<span class="ad-spin"></span> Guardando...');
        ADApp.api('ventas/asignar-punto-orden.php', {
          transaccion_id: r.id,
          punto_id: parseInt(pid),
          fecha_estimada_entrega: eta
        }).done(function(res){
          if (res && res.ok) {
            var iconChk = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:5px;"><polyline points="20 6 9 17 4 12"/></svg>';
            $('#vkAsignPuntoMsg').css('color','#0e8f55').html(iconChk+'Punto asignado · Notificación enviada al cliente');
            // After save: close the modal and refresh the underlying list so
            // the new punto shows up. Detail modal is NOT re-opened — the
            // admin can click Ver again if they want to inspect the result.
            //   1) switch active tab to 'todas' so the row stays visible,
            //   2) reload the list (loadData) + flash the row green,
            //   3) backup reload 1.2s later for slow DB commits.
            setTimeout(function(){
              ADApp.closeModal();
              _activeTab = 'todas';
              loadData(function(){
                flashRow(r.id);
                setTimeout(function(){ loadData(); }, 1200);
              });
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
