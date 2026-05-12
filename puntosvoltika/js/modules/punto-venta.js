window.PV_venta = (function(){
  var _cache = null;

  function render(){
    PVApp.render('<div class="ad-h1">Venta por referido</div><div><span class="ad-spin"></span></div>');
    PVApp.api('inventario/listar.php').done(function(r){
      _cache = r;
      paint(r);
    });
  }

  function paint(r){
    var pendientes = r.ventas_referido_pendientes || [];
    var disponibles = r.inventario_venta || [];

    var html = '<div class="ad-h1">Venta por referido</div>';
    html += '<div style="color:var(--ad-dim);margin-bottom:14px;font-size:13px;">Asigna motos libres de tu punto a pedidos pendientes o ventas directas en tienda.</div>';

    // ───────────── Section 1: Pending online orders (CASE 3) ─────────────
    html += '<div style="background:#FFF3E0;border-left:4px solid #FB8C00;padding:10px 12px;border-radius:6px;margin-bottom:10px;">'+
      '<strong style="color:#E65100;">Pedidos pendientes de asignar ('+pendientes.length+')</strong>'+
      '<div style="font-size:12px;color:#795548;margin-top:2px;">Ventas online que usaron tu código de referido. Asigna una moto libre de tu inventario al pedido.</div>'+
    '</div>';

    if (pendientes.length === 0) {
      html += '<div class="ad-card" style="color:#666;font-size:13px;margin-bottom:20px;">Sin pedidos pendientes por asignar.</div>';
    } else {
      pendientes.forEach(function(p){
        html += '<div class="ad-card" style="border:1px solid #FFE0B2;background:#FFFDE7;margin-bottom:10px;">';
        html += '<div style="font-weight:700;font-size:14px;color:#1a3a5c;">Pedido VK-'+esc(p.pedido)+'</div>';
        html += '<div style="font-size:13px;margin-top:4px;">'+esc(p.modelo||'')+' · '+esc(p.color||'')+' · <strong>$'+Number(p.total||0).toLocaleString('es-MX')+'</strong></div>';
        html += '<div style="font-size:12px;color:var(--ad-dim);margin-top:4px;">Cliente: '+esc(p.nombre||'—')+' · '+esc(p.telefono||'')+' · '+esc(p.email||'')+'</div>';
        html += '<div style="font-size:11px;color:var(--ad-dim);margin-top:2px;">Fecha: '+(p.freg||'').substring(0,16)+'</div>';
        html += '<button class="ad-btn primary sm pvAssignPedido" data-pedido="'+esc(p.pedido)+'" data-modelo="'+esc(p.modelo||'')+'" data-color="'+esc(p.color||'')+'" data-nombre="'+esc(p.nombre||'')+'" style="margin-top:10px;">Asignar moto de inventario</button>';
        html += '</div>';
      });
    }

    // ───────────── Section 2: Walk-in direct sales ─────────────
    html += '<div style="background:#E3F2FD;border-left:4px solid #1976D2;padding:10px 12px;border-radius:6px;margin:20px 0 10px;">'+
      '<strong style="color:#0D47A1;">Venta directa en tienda ('+disponibles.length+')</strong>'+
      '<div style="font-size:12px;color:#455A64;margin-top:2px;">Motos libres en tu inventario. Asígnalas directamente a un cliente que llegó a tu punto.</div>'+
    '</div>';

    if (disponibles.length === 0) {
      html += '<div class="ad-card" style="color:#666;font-size:13px;">Sin motos disponibles para venta directa.</div>';
    } else {
      disponibles.forEach(function(m){
        html += '<div class="ad-card" style="margin-bottom:8px;">';
        html += '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">';
        html += '<div>';
        html += '<div style="font-weight:700">'+esc(m.modelo)+' · '+esc(m.color)+'</div>';
        html += '<div style="font-size:12px;color:var(--ad-dim)">VIN: <code>'+esc(m.vin_display||m.vin||'')+'</code></div>';
        html += '</div>';
        html += '<div style="display:flex;gap:6px;align-items:center;">';
        html += '<button class="ad-btn primary sm pvSellDirect" data-id="'+m.id+'" data-modelo="'+esc(m.modelo||'')+'" data-color="'+esc(m.color||'')+'">Venta directa</button>';
        // Customer brief 2026-05-09: mirror the "Eliminar" affordance
        // from punto-inventario.js so duplicate / test rows can be
        // cleaned up from this view too. Server endpoint re-validates
        // the same safety gate (no cliente, no envío activo).
        html += '<button class="ad-btn ghost sm pvDeleteDirect" data-id="'+m.id+'" '+
                'data-vin="'+esc(m.vin_display||m.vin||'')+'" '+
                'data-modelo="'+esc(m.modelo||'')+'" data-color="'+esc(m.color||'')+'" '+
                'style="color:#b91c1c;border-color:#b91c1c;" '+
                'title="Eliminar moto del inventario">'+
                '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 01-2 2H9a2 2 0 01-2-2L5 6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>'+
                '</button>';
        html += '</div>';
        html += '</div>';
        html += '</div>';
      });
    }

    // ───────────── Info ─────────────
    html += '<div style="background:#F5F5F5;padding:10px 12px;border-radius:6px;margin-top:20px;font-size:11px;color:#666;">'+
      'Las motos ya reservadas en <strong>"Para entrega"</strong> no aparecen aquí — están asignadas a pedidos confirmados por CEDIS y no pueden revenderse.'+
    '</div>';

    PVApp.render(html);

    $('.pvAssignPedido').on('click', function(){
      var $b = $(this);
      showAssignModal({
        pedido:  $b.data('pedido'),
        modelo:  $b.data('modelo'),
        color:   $b.data('color'),
        nombre:  $b.data('nombre'),
      });
    });
    $('.pvSellDirect').on('click', function(){
      var $b = $(this);
      showDirectSaleModal({
        moto_id: $b.data('id'),
        modelo:  $b.data('modelo'),
        color:   $b.data('color'),
      });
    });
    $('.pvDeleteDirect').on('click', function(){
      var $b = $(this);
      showDeleteDirectModal({
        moto_id: $b.data('id'),
        vin:     $b.data('vin'),
        modelo:  $b.data('modelo'),
        color:   $b.data('color'),
      });
    });
  }

  function showDeleteDirectModal(ctx){
    PVApp.modal(
      '<div class="ad-h2" style="color:#b91c1c;">Eliminar moto del inventario</div>'+
      '<div style="color:var(--ad-dim);margin-bottom:14px;font-size:13px;line-height:1.55;">'+
        'Vas a eliminar <strong>'+esc(ctx.modelo||'—')+' '+esc(ctx.color||'')+'</strong>'+
        (ctx.vin ? ' (VIN <code>'+esc(ctx.vin)+'</code>)' : '')+' de tu inventario.<br><br>'+
        'La moto queda como <strong>eliminada</strong> en la base de datos (no se borra de forma definitiva — admin puede restaurarla). '+
        'Usa esto para entradas duplicadas, motos de prueba o motos regresadas a CEDIS.'+
      '</div>'+
      '<label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Motivo de eliminación *</label>'+
      '<textarea id="pvDelDirMotivo" class="ad-input" placeholder="Ej. entrada duplicada · moto de prueba · regresada a CEDIS" '+
      'style="width:100%;min-height:70px;margin-bottom:6px;"></textarea>'+
      '<div id="pvDelDirMsg" style="font-size:12px;color:#b91c1c;min-height:16px;"></div>'+
      '<div style="display:flex;gap:8px;margin-top:10px;">'+
        '<button class="ad-btn ghost" id="pvDelDirCancel" style="flex:1;">Cancelar</button>'+
        '<button class="ad-btn primary" id="pvDelDirOk" style="flex:1;background:#b91c1c;border-color:#b91c1c;">Eliminar</button>'+
      '</div>'
    );
    $('#pvDelDirCancel').on('click', PVApp.closeModal);
    $('#pvDelDirOk').on('click', function(){
      var motivo = ($('#pvDelDirMotivo').val()||'').trim();
      if (motivo.length < 4) {
        $('#pvDelDirMsg').text('Escribe un motivo (mínimo 4 caracteres).');
        return;
      }
      var $btn = $(this).prop('disabled', true).html('<span class="ad-spin"></span> Eliminando...');
      PVApp.api('inventario/eliminar.php', { moto_id: ctx.moto_id, motivo: motivo })
        .done(function(r){
          if (r && r.ok) {
            PVApp.closeModal();
            PVApp.toast('Moto eliminada');
            render();
          } else {
            $('#pvDelDirMsg').text((r && r.error) || 'Error desconocido');
            $btn.prop('disabled', false).text('Eliminar');
          }
        })
        .fail(function(x){
          var err = (x.responseJSON && x.responseJSON.error) || 'Error de conexión';
          $('#pvDelDirMsg').text(err);
          $btn.prop('disabled', false).text('Eliminar');
        });
    });
  }

  // ── Modal: assign free inventory bike to a pending online order (CASE 3) ──
  function showAssignModal(ord){
    if(!_cache) return;
    // Filter free bikes that match model + color
    var matches = (_cache.inventario_venta || []).filter(function(m){
      return (m.modelo||'').toLowerCase().trim() === (ord.modelo||'').toLowerCase().trim()
          && (m.color||'').toLowerCase().trim()  === (ord.color||'').toLowerCase().trim();
    });

    var html = '<div class="ad-h2">Asignar moto al pedido VK-'+esc(ord.pedido)+'</div>';
    html += '<div style="font-size:13px;color:var(--ad-dim);margin-bottom:14px;">Cliente: <strong>'+esc(ord.nombre)+'</strong> · solicitó '+esc(ord.modelo)+' '+esc(ord.color)+'</div>';

    if (matches.length === 0) {
      html += '<div class="ad-card" style="background:#FDECEA;color:#C62828;">'+
        '<strong>Aviso:</strong> No hay motos <strong>'+esc(ord.modelo)+' '+esc(ord.color)+'</strong> disponibles en tu inventario.<br>'+
        '<small>Contacta a CEDIS para solicitar un envío.</small></div>';
      html += '<button class="ad-btn ghost" onclick="PVApp.closeModal()" style="width:100%;margin-top:10px;">Cerrar</button>';
      PVApp.modal(html);
      return;
    }

    html += '<div style="font-size:13px;font-weight:600;margin-bottom:8px;">Selecciona una moto ('+matches.length+' disponible'+(matches.length>1?'s':'')+'):</div>';
    matches.forEach(function(m){
      html += '<div class="ad-card" style="margin-bottom:6px;display:flex;justify-content:space-between;align-items:center;">';
      html += '<div><strong>'+esc(m.modelo)+' · '+esc(m.color)+'</strong><br><small>VIN: <code>'+esc(m.vin_display||m.vin||'')+'</code></small></div>';
      html += '<button class="ad-btn primary sm pvConfirmAssign" data-pedido="'+esc(ord.pedido)+'" data-moto-id="'+m.id+'">Asignar esta</button>';
      html += '</div>';
    });
    html += '<button class="ad-btn ghost" onclick="PVApp.closeModal()" style="width:100%;margin-top:10px;">Cancelar</button>';
    PVApp.modal(html);

    $('.pvConfirmAssign').on('click', function(){
      var $b = $(this).prop('disabled', true).text('Asignando…');
      PVApp.api('asignar/pedido-pendiente.php', {
        pedido:  $(this).data('pedido'),
        moto_id: $(this).data('moto-id'),
      }).done(function(r){
        if (r.ok) {
          PVApp.closeModal();
          PVApp.toast('Moto asignada. Cliente notificado.');
          render();
        } else {
          alert(r.error || 'Error');
          $b.prop('disabled', false).text('Asignar esta');
        }
      }).fail(function(xhr){
        alert((xhr.responseJSON && xhr.responseJSON.error) || 'Error de conexión');
        $b.prop('disabled', false).text('Asignar esta');
      });
    });
  }

  // ── Modal: walk-in direct sale (CASE 4) ──
  // Customer feedback 2026-04-23: the precio input was removed — prices are
  // fixed per model and come from the configurador, not editable by the
  // point staff. The backend now looks up the canonical price from the
  // `modelos` catalog, so this modal only collects the CLIENT data.
  function showDirectSaleModal(ctx){
    PVApp.modal(
      '<div class="ad-h2">Venta directa: '+esc(ctx.modelo)+' '+esc(ctx.color)+'</div>'+
      '<label class="ad-label">Canal</label>'+
      '<select id="pvCanal" class="ad-input">'+
        '<option value="directa">Venta directa en tienda</option>'+
        '<option value="electronica">Venta electrónica</option>'+
      '</select>'+
      '<label class="ad-label">Nombre del cliente</label>'+
      '<input id="pvVN" class="ad-input">'+
      '<label class="ad-label">Email</label>'+
      '<input id="pvVE" class="ad-input" type="email">'+
      '<label class="ad-label">Teléfono</label>'+
      '<input id="pvVT" class="ad-input" inputmode="numeric" maxlength="10">'+
      '<button id="pvVSave" class="ad-btn primary" style="width:100%;margin-top:14px">Registrar venta</button>'+
      '<button class="ad-btn ghost" onclick="PVApp.closeModal()" style="width:100%;margin-top:6px;">Cancelar</button>'
    );
    $('#pvVSave').on('click', function(){
      // Customer brief 2026-05-09 (Óscar, 5th round — "cannot add a
      // motorcycle"): minimum-field validation in JS so the user sees the
      // problem before the round-trip, plus a richer error message when
      // the backend rejects (typical causes: moto not in 'consignacion'
      // state, cliente fields missing, moto already linked to a customer).
      var nombre = ($('#pvVN').val() || '').trim();
      var email  = ($('#pvVE').val() || '').trim();
      var tel    = ($('#pvVT').val() || '').trim();
      if (!nombre) { alert('El nombre del cliente es requerido.'); return; }
      if (!email && !tel) { alert('Email o teléfono del cliente es requerido (al menos uno).'); return; }
      var $b = $(this).prop('disabled', true).text('Guardando…');
      PVApp.api('asignar/referido.php',{
        moto_id: ctx.moto_id, canal: $('#pvCanal').val(),
        cliente_nombre: nombre, cliente_email: email,
        cliente_telefono: tel
        // precio intentionally omitted — backend derives it from modelos
      }).done(function(r){
        if(r.ok){ PVApp.closeModal(); PVApp.toast('Venta registrada. Cliente notificado.'); render(); }
        else {
          var msg = (r && r.error) || 'Error';
          // Common backend rejection — explain in plain language.
          if (msg.indexOf('consignación') >= 0 || msg.indexOf('showroom') >= 0) {
            msg += '\n\n💡 Esta moto fue asignada para entrega a un pedido específico, no como stock de showroom. Para venderla en venta directa, pídele a CEDIS que cambie su tipo a "consignación" desde el panel admin.';
          } else if (msg.indexOf('ya asignada') >= 0) {
            msg += '\n\n💡 Esta moto ya tiene un cliente. Si quieres asignársela a otro cliente, primero contacta a admin para desasignarla.';
          }
          alert(msg);
          $b.prop('disabled', false).text('Registrar venta');
        }
      }).fail(function(x){
        var err = (x.responseJSON&&x.responseJSON.error)||'Error de conexión con el servidor';
        alert(err);
        $b.prop('disabled', false).text('Registrar venta');
      });
    });
  }

  function esc(s){
    return (s==null?'':String(s)).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  return { render:render };
})();
