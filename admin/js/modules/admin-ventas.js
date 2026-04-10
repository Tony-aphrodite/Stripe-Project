window.AD_ventas = (function(){

  function render(){
    ADApp.render(
      '<div class="ad-toolbar">'+
        '<div class="ad-h1">Ventas / Ordenes</div>'+
      '</div>'+
      '<div id="vtKpis" class="ad-kpis" style="margin-bottom:14px;"></div>'+
      '<div id="vtTable">Cargando...</div>'
    );
    loadData();
  }

  function loadData(){
    ADApp.api('ventas/listar.php').done(function(r){
      if(!r.ok){ $('#vtTable').html('<div class="ad-card">Error al cargar</div>'); return; }

      // KPIs
      var pendingPunto = (r.rows||[]).filter(function(o){ return o.punto_id==='centro-cercano'; }).length;
      $('#vtKpis').html(
        kpi('Total ordenes', r.total, 'blue')+
        kpi('Moto asignada', r.asignadas, 'green')+
        kpi('Sin asignar', r.sin_asignar, r.sin_asignar > 0 ? 'red' : 'green')+
        kpi('Punto pendiente', pendingPunto, pendingPunto > 0 ? 'yellow' : 'green')
      );

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

        html += '<tr>'+
          '<td><strong>VK-'+(r.pedido||r.id)+'</strong></td>'+
          '<td>'+(r.nombre||'-')+'<br><small class="ad-dim">'+(r.telefono||'')+'</small></td>'+
          '<td>'+(r.modelo||'-')+'</td>'+
          '<td>'+(r.color||'-')+'</td>'+
          '<td><span class="ad-badge blue">'+(r.tipo||'-')+'</span></td>'+
          '<td>'+ADApp.money(r.monto)+'</td>'+
          '<td>'+puntoHtml+'</td>'+
          '<td>'+(r.fecha?r.fecha.substring(0,10):'-')+'</td>';

        if(asignada){
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

  return { render:render, showAsignar:showAsignar, doAsignar:doAsignar, showDetalle:showDetalle };
})();
