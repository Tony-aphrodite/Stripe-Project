window.PV_inventario = (function(){
  function render(){
    PVApp.render('<div class="ad-h1">Inventario</div><div><span class="ad-spin"></span></div>');
    PVApp.api('inventario/listar.php').done(paint);
  }
  function paint(r){
    var html = '<div class="ad-h1">Inventario</div>';

    // CASE 3: online referral sales waiting for CEDIS to assign a physical moto.
    // These show at the top because the point staff can't act on them beyond
    // confirming the client info — they are informational until a real bike
    // arrives.
    var ventasPendientes = r.ventas_referido_pendientes || [];
    if (ventasPendientes.length) {
      html += '<div class="ad-h2">🛒 Ventas por referido · pendientes de asignación ('+ventasPendientes.length+')</div>';
      html += '<div style="font-size:12px;color:var(--ad-dim);margin:2px 0 8px 2px">'+
        'Órdenes realizadas con el código de este punto. CEDIS debe asignar una moto y enviarla.</div>';
      ventasPendientes.forEach(function(v){ html += ventaReferidoCard(v); });
    }

    // Pending arrival — motos still in transit, split by showroom vs for-delivery
    var porLlegarEntrega  = r.inventario_por_llegar_entrega  || [];
    var porLlegarShowroom = r.inventario_por_llegar_showroom || [];
    var totalPorLlegar = porLlegarEntrega.length + porLlegarShowroom.length;
    if (totalPorLlegar > 0) {
      html += '<div class="ad-h2">🚚 Por llegar ('+totalPorLlegar+')</div>';
      if (porLlegarEntrega.length) {
        html += '<div style="font-size:12px;color:var(--ad-dim);margin:4px 0 4px 2px">Para entrega a cliente</div>';
        porLlegarEntrega.forEach(function(m){ html += bikeCard(m, 'por_llegar_entrega'); });
      }
      if (porLlegarShowroom.length) {
        html += '<div style="font-size:12px;color:var(--ad-dim);margin:8px 0 4px 2px">Para showroom</div>';
        porLlegarShowroom.forEach(function(m){ html += bikeCard(m, 'por_llegar_showroom'); });
      }
    }

    html += '<div class="ad-h2">Para entrega ('+(r.inventario_entrega||[]).length+')</div>';
    if((r.inventario_entrega||[]).length===0) html += '<div style="color:var(--ad-dim)">Sin motos reservadas</div>';
    (r.inventario_entrega||[]).forEach(function(m){
      html += bikeCard(m, 'entrega');
    });
    html += '<div class="ad-h2">Disponible para venta ('+(r.inventario_venta||[]).length+')</div>';
    if((r.inventario_venta||[]).length===0) html += '<div style="color:var(--ad-dim)">Sin motos libres</div>';
    (r.inventario_venta||[]).forEach(function(m){
      html += bikeCard(m, 'venta');
    });
    PVApp.render(html);

    $('.pv-bike-card').on('click', function(e){
      if ($(e.target).closest('.pv-estado-btn').length) return;
      showDetalle($(this).data('id'));
    });
    $('.pv-estado-btn').on('click', function(e){
      e.stopPropagation();
      var id = $(this).data('id');
      var action = $(this).data('action');
      if (action === 'ensamble')  iniciarEnsamble(id);
      if (action === 'lista')     marcarLista(id);
    });
  }
  function ventaReferidoCard(v){
    // Uses its own class (pv-venta-card) so the bike-card click handler
    // doesn't fire — these rows are informational and have no detail page.
    var h = '<div class="pv-venta-card" style="background:#FFF8E1;border-left:3px solid #FFC107;padding:10px;margin-bottom:6px;border-radius:6px">';
    h += '<div class="pv-info">';
    h += '<div style="font-weight:700">VK-'+(v.pedido||v.id)+' · '+(v.modelo||'—')+' · '+(v.color||'—')+'</div>';
    h += '<div style="font-size:12px">Cliente: <strong>'+(v.nombre||'—')+'</strong></div>';
    h += '<div style="font-size:11px;color:var(--ad-dim)">'+(v.telefono||'')+(v.email?' · '+v.email:'')+'</div>';
    h += '<div style="font-size:11px;margin-top:4px"><span class="ad-badge yellow">pendiente asignación</span>';
    if (v.tpago) h += ' <span style="font-size:11px;color:var(--ad-dim)">· '+v.tpago+'</span>';
    h += '</div>';
    h += '</div></div>';
    return h;
  }
  function bikeCard(m, tipo){
    var h = '<div class="pv-bike-card" data-id="'+m.id+'" style="cursor:pointer">';
    h += '<div class="pv-info">';
    h += '<div style="font-weight:700">'+m.modelo+' · '+m.color+'</div>';
    h += '<div style="font-size:12px;color:var(--ad-dim)">VIN: '+(m.vin_display||m.vin)+'</div>';
    if (tipo==='entrega' && m.cliente_nombre) {
      h += '<div style="font-size:12px">Cliente: <strong>'+m.cliente_nombre+'</strong></div>';
      h += '<div style="font-size:11px;color:var(--ad-dim)">'+(m.cliente_telefono||'')+'</div>';
    }
    if (parseInt(m.bloqueado_venta)) {
      h += '<div style="display:flex;align-items:center;gap:6px;margin-top:4px;padding:6px 10px;border-radius:6px;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.15);">';
      h += '<span class="ad-badge red" style="font-size:10px;">BLOQUEADA</span>';
      h += '<span style="font-size:11px;color:#b91c1c;">'+(m.bloqueado_motivo||'')+'</span>';
      h += '</div>';
    }
    var badgeColor = 'blue';
    if (m.estado === 'lista_para_entrega') badgeColor = 'green';
    else if (m.estado === 'en_ensamble')   badgeColor = 'yellow';
    else if (m.estado === 'entregada')     badgeColor = 'green';
    h += '<div style="font-size:11px;margin-top:4px"><span class="ad-badge '+badgeColor+'">'+m.estado+'</span>';
    if (m.estado === 'lista_para_entrega' && m.fecha_entrega_estimada) {
      h += ' <span style="font-size:11px;color:var(--ad-dim)">· recolección: '+m.fecha_entrega_estimada+'</span>';
    }
    h += '</div>';

    // State-transition action buttons (diagram: reception → assembly → lista_para_entrega)
    if (tipo === 'entrega') {
      if (m.estado === 'recibida') {
        h += '<div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">';
        h += '<button class="ad-btn ghost sm pv-estado-btn" data-id="'+m.id+'" data-action="ensamble">🔧 Iniciar ensamble</button>';
        h += '<button class="ad-btn primary sm pv-estado-btn" data-id="'+m.id+'" data-action="lista">✅ Marcar lista para entrega</button>';
        h += '</div>';
      } else if (m.estado === 'en_ensamble') {
        h += '<div style="margin-top:8px">';
        h += '<button class="ad-btn primary sm pv-estado-btn" data-id="'+m.id+'" data-action="lista">✅ Marcar lista para entrega</button>';
        h += '</div>';
      }
    }

    h += '</div></div>';
    return h;
  }
  function iniciarEnsamble(motoId){
    if (!motoId) return;
    if (!confirm('¿Iniciar ensamble de esta moto?')) return;
    PVApp.api('inventario/cambiar-estado.php', {
      moto_id: motoId,
      nuevo_estado: 'en_ensamble'
    }).done(function(r){
      if (r.ok) { PVApp.toast('🔧 Moto en ensamble'); render(); }
    }).fail(function(x){ alert((x.responseJSON&&x.responseJSON.error)||'Error'); });
  }
  function marcarLista(motoId){
    if (!motoId) return;
    // Default pickup date: today + 2 days
    var d = new Date(); d.setDate(d.getDate() + 2);
    var dflt = d.toISOString().slice(0,10);
    var minDate = new Date().toISOString().slice(0,10);
    PVApp.modal(
      '<div class="ad-h2">✅ Lista para entrega</div>'+
      '<div style="color:var(--ad-dim);font-size:13px;margin-bottom:12px">'+
        'Selecciona la fecha estimada en la que el cliente puede recoger su moto. '+
        'Le enviaremos un SMS con esta fecha.'+
      '</div>'+
      '<label class="ad-label">Fecha de recolección</label>'+
      '<input id="pvLFecha" type="date" class="ad-input" value="'+dflt+'" min="'+minDate+'" style="margin-bottom:10px">'+
      '<label class="ad-label">Notas (opcional)</label>'+
      '<textarea id="pvLNotas" class="ad-input" placeholder="Ej. ensamble sin incidencias"></textarea>'+
      '<button id="pvLSave" class="ad-btn primary" style="width:100%;margin-top:14px">Confirmar y notificar al cliente</button>'
    );
    $('#pvLSave').on('click', function(){
      var fecha = $('#pvLFecha').val();
      if (!fecha) { alert('Selecciona una fecha'); return; }
      PVApp.api('inventario/cambiar-estado.php', {
        moto_id: motoId,
        nuevo_estado: 'lista_para_entrega',
        fecha_entrega_estimada: fecha,
        notas: $('#pvLNotas').val()
      }).done(function(r){
        if (r.ok) {
          PVApp.closeModal();
          PVApp.toast('✅ Cliente notificado · recolección: '+fecha);
          render();
        }
      }).fail(function(x){ alert((x.responseJSON&&x.responseJSON.error)||'Error'); });
    });
  }
  function showDetalle(motoId){
    if(!motoId) return;
    PVApp.api('inventario/detalle.php?id='+motoId).done(function(r){
      if(!r.ok && r.error){ PVApp.modal('<div>'+r.error+'</div>'); return; }
      var m = r.moto || r;
      var html = '<div class="ad-h2">'+m.modelo+' — '+m.color+'</div>';
      html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px">';
      [['VIN',m.vin_display||m.vin||'—'],['Estado',m.estado||'—'],['Pago',m.pago_estado||'—'],
       ['Cliente',m.cliente_nombre||'—'],['Email',m.cliente_email||'—'],['Teléfono',m.cliente_telefono||'—'],
       ['Pedido',m.pedido_num||'—'],['Fecha recolección',m.fecha_entrega_estimada||'—']].forEach(function(p){
        html += '<div><span style="color:var(--ad-dim)">'+p[0]+':</span> <strong>'+p[1]+'</strong></div>';
      });
      html += '</div>';

      // ── Bloqueo ──
      var isBloq = parseInt(m.bloqueado_venta) === 1;
      html += '<div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--ad-border,#eee);">';
      html += '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--ad-primary,#039fe1);margin-bottom:10px;">Bloqueo</div>';
      if(isBloq){
        html += '<div style="display:flex;align-items:flex-start;gap:8px;padding:10px 14px;border-radius:8px;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.15);margin-bottom:10px;">';
        html += '<div style="font-size:12px;color:#b91c1c;"><strong>Moto bloqueada</strong><br>Motivo: '+(m.bloqueado_motivo||'Sin motivo')+'</div></div>';
        html += '<div style="font-size:11px;color:var(--ad-dim,#888);">Para desbloquear esta moto, contacta a CEDIS.</div>';
      } else {
        html += '<div style="font-size:12px;color:#059669;margin-bottom:10px;">Moto disponible (no bloqueada)</div>';
        html += '<button class="ad-btn ghost" id="pvLockMoto" style="color:#b91c1c;border-color:#b91c1c;width:100%;">Bloquear moto</button>';
      }
      html += '</div>';

      PVApp.modal(html);
      $('#pvLockMoto').on('click', function(){ showPuntoLockModal(m.id); });
    });
  }
  function showPuntoLockModal(motoId){
    var html = '<div class="ad-h2">Bloquear moto para venta</div>'+
      '<div style="color:var(--ad-dim,#888);margin-bottom:12px;font-size:13px;">Esta moto no podrá ser vendida mientras esté bloqueada.</div>'+
      '<label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Motivo del bloqueo *</label>'+
      '<textarea id="pvLockMotivo" class="ad-input" placeholder="Ej. Pendiente revisión, daño detectado, etc." style="width:100%;min-height:80px;"></textarea>'+
      '<button class="ad-btn primary" id="pvLockSave" style="width:100%;margin-top:12px;">Bloquear moto</button>';
    PVApp.modal(html);
    $('#pvLockSave').on('click', function(){
      var motivo = $('#pvLockMotivo').val().trim();
      if(!motivo){ alert('El motivo es obligatorio'); return; }
      $(this).prop('disabled',true).html('<span class="ad-spin"></span> Bloqueando...');
      PVApp.api('inventario/bloquear-venta.php', {
        moto_id: motoId,
        bloqueado: 1,
        motivo: motivo
      }).done(function(r){
        if(r.ok){
          PVApp.closeModal();
          PVApp.toast('Moto bloqueada para venta');
          render();
        } else {
          alert(r.error||'Error');
          $('#pvLockSave').prop('disabled',false).html('Bloquear moto');
        }
      }).fail(function(x){
        alert((x.responseJSON&&x.responseJSON.error)||'Error de conexión');
        $('#pvLockSave').prop('disabled',false).html('Bloquear moto');
      });
    });
  }
  return { render:render };
})();
