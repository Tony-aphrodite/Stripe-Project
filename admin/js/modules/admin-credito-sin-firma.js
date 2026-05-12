/* admin-credito-sin-firma.js — Audit panel for credit orders missing a
 * signed contract.
 *
 * Customer brief 2026-05-12 (Óscar, 10th round — "There's other purchase
 * operations without signed contract"): systemic recovery tool. Lists
 * every credit-family transaction whose Truora+Cincel contract PDF is
 * missing. Admin can review per-customer status (CDC, Truora, days since
 * down payment) and bulk-resend signing links.
 *
 * Sidebar route: data-route="creditoSinFirma"
 * Endpoint:      /admin/php/ventas/credito-sin-firma.php (GET)
 * Bulk action:   /admin/php/ventas/reenviar-firmas-masivo.php (POST)
 */
window.AD_creditoSinFirma = (function(){
  var state = { rows: [], kpi: null, selected: {} };

  function esc(s){ return jQuery('<div/>').text(s == null ? '' : String(s)).html(); }
  function money(n){
    if (n == null || n === '' || isNaN(Number(n))) return '—';
    return '$' + Number(n).toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2});
  }

  function render(){
    ADApp.render('<div class="ad-h1">Crédito sin firma</div>'+
      '<div style="color:var(--ad-dim);font-size:13px;margin-bottom:16px;">'+
        'Pedidos a crédito (enganche / parcial) donde aceptamos el pago pero el cliente '+
        '<strong>aún no ha firmado el contrato</strong> electrónicamente con Truora + Cincel.'+
      '</div>'+
      '<div><span class="ad-spin"></span> Cargando auditoría…</div>');
    load();
  }

  function load(){
    ADApp.api('ventas/credito-sin-firma.php').done(function(r){
      if (!r || !r.ok) {
        ADApp.render('<div class="ad-h1">Crédito sin firma</div>'+
          '<div style="color:#b91c1c;padding:14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;">'+
            'Error al cargar: '+esc((r && r.error) || 'desconocido')+
          '</div>');
        return;
      }
      state.rows = r.rows || [];
      state.kpi  = r.kpi || null;
      state.selected = {};
      paint();
      // Update sidebar badge with the count.
      try {
        var $b = $('#adSinFirmaBadge');
        if (state.rows.length > 0) {
          $b.text(state.rows.length).show();
        } else {
          $b.hide();
        }
      } catch (e) {}
    }).fail(function(x){
      ADApp.render('<div class="ad-h1">Crédito sin firma</div>'+
        '<div style="color:#b91c1c;padding:14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;">'+
          'Error de conexión: '+esc((x.responseJSON && x.responseJSON.error) || 'conexión perdida')+
        '</div>');
    });
  }

  function paint(){
    var rows = state.rows;
    var kpi  = state.kpi || {};
    var html = '';

    // ── Header ──────────────────────────────────────────────────────
    html += '<div class="ad-h1">Crédito sin firma</div>';
    html += '<div style="color:var(--ad-dim);font-size:13px;margin-bottom:14px;">'+
      'Pedidos a crédito donde aceptamos el pago pero el cliente <strong>no ha firmado el contrato</strong> con Truora + Cincel. '+
      'Solicítales completar la firma desde su portal.'+
    '</div>';

    // ── KPI cards ──────────────────────────────────────────────────
    function kpiCard(label, val, color, sub){
      return '<div style="background:'+color+';color:#fff;border-radius:10px;padding:14px 16px;flex:1;min-width:160px;">'+
        '<div style="font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;opacity:.9;">'+esc(label)+'</div>'+
        '<div style="font-size:24px;font-weight:800;margin-top:4px;">'+val+'</div>'+
        (sub ? '<div style="font-size:11px;opacity:.85;margin-top:2px;">'+esc(sub)+'</div>' : '')+
      '</div>';
    }
    html += '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">';
    html += kpiCard('Pedidos sin firma',     kpi.total_pedidos ?? rows.length, '#dc2626');
    html += kpiCard('Monto en juego',        money(kpi.monto_total), '#ea580c');
    html += kpiCard('Con preaprobación',     kpi.con_preaprobacion ?? 0, '#16a34a', 'Listos para reenviar Truora');
    html += kpiCard('Sin preaprobación',     kpi.sin_preaprobacion ?? 0, '#d97706', 'Revisión manual');
    html += kpiCard('Máx días sin firmar',   (kpi.dias_max_sin_firmar ?? 0) + ' días', '#7c3aed', 'Más urgente');
    html += '</div>';

    // ── Bulk action bar ────────────────────────────────────────────
    html += '<div id="csfBar" style="display:flex;gap:10px;align-items:center;background:#fffbeb;border:1px solid #fde68a;padding:12px 14px;border-radius:8px;margin-bottom:12px;">'+
      '<div style="flex:1;font-size:13px;color:#92400e;">'+
        '<strong>Selecciona pedidos</strong> abajo y reenvía el link de firma (Truora + Cincel) a todos en un click.'+
      '</div>'+
      '<button class="ad-btn ghost" id="csfSelectAll" style="font-size:12px;">Seleccionar todos</button>'+
      '<button class="ad-btn primary" id="csfBulkSend" disabled style="background:#dc2626;border-color:#dc2626;">'+
        'Reenviar firma (<span id="csfSelCount">0</span>)'+
      '</button>'+
    '</div>';

    if (!rows.length) {
      html += '<div style="padding:24px;background:#f0fdf4;border:1px solid #86efac;color:#166534;border-radius:8px;text-align:center;font-size:14px;">'+
        '🎉 <strong>¡Excelente!</strong> No hay pedidos a crédito pendientes de firma.'+
      '</div>';
      ADApp.render(html);
      bindBar();
      return;
    }

    // ── Table ──────────────────────────────────────────────────────
    html += '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table"><thead><tr>'+
      '<th style="width:36px;"><input type="checkbox" id="csfChkAll"></th>'+
      '<th>Pedido</th>'+
      '<th>Cliente</th>'+
      '<th>Contacto</th>'+
      '<th>Modelo</th>'+
      '<th style="text-align:right;">Enganche</th>'+
      '<th style="text-align:center;">Días</th>'+
      '<th>Truora</th>'+
      '<th>CDC</th>'+
      '<th>Acción</th>'+
    '</tr></thead><tbody>';

    rows.forEach(function(r){
      var pedido = r.pedido_corto || (r.pedido_legacy ? 'VK-'+r.pedido_legacy : 'TX'+r.transaccion_id);
      var contacto = '<div style="font-size:11.5px;">'+esc(r.email||'—')+'</div>'+
                     '<div style="font-size:11px;color:var(--ad-dim);">'+esc(r.telefono||'—')+'</div>';
      var dias = parseInt(r.dias_sin_firmar||0,10);
      var diasColor = dias > 7 ? '#dc2626' : (dias > 3 ? '#d97706' : '#16a34a');
      var diasCell = '<span style="color:'+diasColor+';font-weight:700;">'+dias+'d</span>';

      // Truora cell
      var truoraCell;
      if (r.truora_approved == 1) {
        truoraCell = '<span style="color:#16a34a;font-weight:600;">✓ Aprobado</span>';
      } else if (r.truora_process_id) {
        truoraCell = '<span style="color:#d97706;font-weight:600;">⏳ Iniciado</span><div style="font-size:10px;color:var(--ad-dim);">'+esc(r.truora_status||'')+'</div>';
      } else {
        truoraCell = '<span style="color:#b91c1c;">✗ Sin iniciar</span>';
      }

      // CDC cell (preaprobacion presence + score)
      var cdcCell;
      if (r.preaprobacion_id) {
        var scoreColor = (r.score >= 700) ? '#16a34a' : (r.score >= 600 ? '#d97706' : '#dc2626');
        var statusLbl  = (r.preap_status || '').toUpperCase();
        var statusColor = statusLbl==='PREAPROBADO' ? '#16a34a' : (statusLbl==='NO_VIABLE' ? '#dc2626' : '#d97706');
        cdcCell = '<div style="font-weight:700;color:'+statusColor+';font-size:12px;">'+esc(statusLbl)+'</div>'+
                  (r.score != null ? '<div style="font-size:11px;color:'+scoreColor+';">Score '+r.score+'</div>' : '');
      } else {
        cdcCell = '<span style="color:#b91c1c;">Sin solicitud</span>';
      }

      // Per-row action
      var rowAction = '<button class="ad-btn sm primary csfRowSend" data-id="'+r.transaccion_id+'" '+
        'style="background:#dc2626;border-color:#dc2626;font-size:11px;">📲 Enviar firma</button>';

      html += '<tr data-id="'+r.transaccion_id+'">'+
        '<td><input type="checkbox" class="csfChk" data-id="'+r.transaccion_id+'"></td>'+
        '<td><strong>'+esc(pedido)+'</strong></td>'+
        '<td>'+esc(r.nombre||'—')+'</td>'+
        '<td>'+contacto+'</td>'+
        '<td>'+esc((r.modelo||'')+' '+(r.color||''))+'</td>'+
        '<td style="text-align:right;font-weight:700;">'+money(r.total)+'</td>'+
        '<td style="text-align:center;">'+diasCell+'</td>'+
        '<td>'+truoraCell+'</td>'+
        '<td>'+cdcCell+'</td>'+
        '<td>'+rowAction+'</td>'+
      '</tr>';
    });

    html += '</tbody></table></div></div>';

    ADApp.render(html);
    bindBar();
    bindRows();
  }

  function bindBar(){
    $('#csfChkAll').on('change', function(){
      var on = this.checked;
      $('.csfChk').each(function(){
        this.checked = on;
        state.selected[$(this).data('id')] = on;
      });
      updateBulkBtn();
    });
    $('#csfSelectAll').on('click', function(){
      $('#csfChkAll').prop('checked', true).trigger('change');
    });
    $('#csfBulkSend').on('click', doBulkSend);
  }

  function bindRows(){
    $('.csfChk').on('change', function(){
      state.selected[$(this).data('id')] = this.checked;
      updateBulkBtn();
    });
    $('.csfRowSend').on('click', function(){
      var id = $(this).data('id');
      doBulkSend([id]);
    });
  }

  function updateBulkBtn(){
    var n = Object.keys(state.selected).filter(function(k){ return state.selected[k]; }).length;
    $('#csfSelCount').text(n);
    $('#csfBulkSend').prop('disabled', n === 0);
  }

  function doBulkSend(forcedIds){
    var ids = forcedIds || Object.keys(state.selected)
      .filter(function(k){ return state.selected[k]; })
      .map(function(k){ return parseInt(k,10); });
    if (!ids.length) return;

    var confirmMsg = 'Vas a reenviar el link de firma (Truora + Cincel) a '+ids.length+' cliente(s).\n\n'+
      'Cada uno recibirá un email y SMS con un link de 7 días para completar la firma.\n\n'+
      '¿Continuar?';
    if (!confirm(confirmMsg)) return;

    // UI feedback — disable everything during the send.
    $('#csfBulkSend, .csfRowSend, .csfChk').prop('disabled', true);
    var prevText = $('#csfBulkSend').text();
    $('#csfBulkSend').html('<span class="ad-spin"></span> Enviando ' + ids.length + '…');

    ADApp.api('ventas/reenviar-firmas-masivo.php', { transaccion_ids: ids })
      .done(function(r){
        if (!r || !r.ok) {
          alert('Error: ' + ((r && r.error) || 'desconocido'));
          $('#csfBulkSend').prop('disabled', false).text(prevText);
          $('.csfChk').prop('disabled', false);
          $('.csfRowSend').prop('disabled', false);
          return;
        }
        showResultsModal(r);
        // Reload the list to reflect any seguimiento updates.
        load();
      })
      .fail(function(x){
        alert('Error de conexión: ' + ((x.responseJSON && x.responseJSON.error) || 'falló la red'));
        $('#csfBulkSend').prop('disabled', false).text(prevText);
        $('.csfChk').prop('disabled', false);
        $('.csfRowSend').prop('disabled', false);
      });
  }

  function showResultsModal(r){
    var s = r.summary || {};
    var html = '<div class="ad-h2">Resultados del reenvío</div>';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;">';
    html += '<div style="background:#dcfce7;padding:10px;border-radius:6px;"><strong style="font-size:18px;color:#166534;">'+(s.ok||0)+'</strong><div style="font-size:11px;color:#166534;">✓ Enviados OK</div></div>';
    html += '<div style="background:#fee2e2;padding:10px;border-radius:6px;"><strong style="font-size:18px;color:#991b1b;">'+(s.errores||0)+'</strong><div style="font-size:11px;color:#991b1b;">✗ Errores</div></div>';
    html += '<div style="background:#fef3c7;padding:10px;border-radius:6px;"><strong style="font-size:18px;color:#92400e;">'+(s.sin_preaprobacion||0)+'</strong><div style="font-size:11px;color:#92400e;">Sin preaprobación vinculada</div></div>';
    html += '<div style="background:#fef3c7;padding:10px;border-radius:6px;"><strong style="font-size:18px;color:#92400e;">'+(s.sin_contacto||0)+'</strong><div style="font-size:11px;color:#92400e;">Sin email/teléfono</div></div>';
    html += '</div>';

    // Per-row detail
    html += '<div style="max-height:300px;overflow-y:auto;border:1px solid var(--ad-border);border-radius:6px;">';
    html += '<table class="ad-table" style="margin:0;"><thead><tr><th>Tx ID</th><th>Email</th><th>SMS</th><th>Estado</th></tr></thead><tbody>';
    (r.results || []).forEach(function(row){
      var emailIc = row.email_sent ? '<span style="color:#16a34a;">✓</span>' : '<span style="color:#b91c1c;">✗</span>';
      var smsIc   = row.sms_sent   ? '<span style="color:#16a34a;">✓</span>' : '<span style="color:#b91c1c;">✗</span>';
      var status  = row.error
        ? '<span style="color:#b91c1c;font-size:11px;">'+esc(row.error)+'</span>'
        : '<span style="color:#16a34a;font-size:11px;">OK</span>';
      html += '<tr><td>'+row.transaccion_id+'</td><td>'+emailIc+'</td><td>'+smsIc+'</td><td>'+status+'</td></tr>';
    });
    html += '</tbody></table></div>';

    html += '<div style="margin-top:14px;text-align:right;"><button class="ad-btn primary" onclick="ADApp.closeModal()">Cerrar</button></div>';

    ADApp.modal(html);
  }

  return { render: render };
})();
