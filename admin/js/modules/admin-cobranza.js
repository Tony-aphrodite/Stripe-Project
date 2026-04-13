window.AD_cobranza = (function(){
  var _bucket = '';
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  function render(){
    ADApp.render(_backBtn+'<div class="ad-h1">Cobranza</div><div><span class="ad-spin"></span> Cargando datos de cobranza...</div>');
    _bucket = '';
    load();
  }

  function load(){
    var params = {bucket: _bucket};
    ADApp.api('pagos/cobranza.php?' + $.param(params)).done(paint).fail(function(){
      ADApp.render(_backBtn+'<div class="ad-h1">Cobranza</div><div class="ad-banner err">Error al cargar datos de cobranza</div>');
    });
  }

  function paint(r){
    var html = _backBtn;
    html += '<div class="ad-toolbar"><div class="ad-h1">Cobranza</div>';
    html += '<button class="ad-btn sm ghost" onclick="AD_cobranza.refresh()">Actualizar</button></div>';

    // ── KPIs ──
    html += '<div class="ad-kpis">';
    html += kpi('Cobrado hoy', ADApp.money(r.cobrado_hoy), 'green');
    html += kpi('Pendientes hoy', r.pendientes_hoy + ' (' + ADApp.money(r.monto_pendiente_hoy) + ')', 'yellow');
    html += kpi('Total atrasados', r.total_overdue, r.total_overdue > 0 ? 'red' : 'green');
    html += kpi('Pagos rechazados', r.pagos_rechazados, r.pagos_rechazados > 0 ? 'red' : 'green');
    html += kpi('Sin tarjeta activa', r.sin_tarjeta, r.sin_tarjeta > 0 ? 'red' : 'green');
    html += '</div>';

    // ── Overdue Buckets ──
    html += '<div class="ad-h2">Cartera vencida por antigüedad</div>';
    html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:20px;">';
    html += bucketCard('1-7 días', r.bucket_1_7, '#f59e0b', '1-7');
    html += bucketCard('8-30 días', r.bucket_8_30, '#ef4444', '8-30');
    html += bucketCard('30+ días', r.bucket_30_plus, '#b91c1c', '30+');
    html += bucketCard('Pendientes hoy', r.pendientes_hoy, '#3b82f6', 'pending');
    html += '</div>';

    // ── Filter tabs ──
    html += '<div class="ad-tabs" id="cbTabs">';
    html += tabBtn('', 'Todos los atrasados');
    html += tabBtn('1-7', '1-7 días');
    html += tabBtn('8-30', '8-30 días');
    html += tabBtn('30+', '30+ días');
    html += tabBtn('pending', 'Pendientes hoy');
    html += '</div>';

    // ── Table ──
    html += '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table"><thead><tr>';
    html += '<th>Ciclo</th><th>Cliente</th><th>Modelo</th><th>Monto</th><th>Vencimiento</th><th>Días atraso</th><th>Estado</th><th>Acciones</th>';
    html += '</tr></thead><tbody>';

    if (!r.ciclos || !r.ciclos.length) {
      html += '<tr><td colspan="8" style="text-align:center;padding:30px;color:var(--ad-dim);">No hay registros en este filtro</td></tr>';
    } else {
      r.ciclos.forEach(function(c){
        var diasAtraso = parseInt(c.dias_atraso) || 0;
        var diasBadge = diasAtraso > 30 ? 'red' : (diasAtraso > 7 ? 'yellow' : 'blue');
        var estadoBadge = c.estado === 'overdue' ? 'red' : (c.estado === 'pending' ? 'yellow' : 'green');
        var tieneTarjeta = c.stripe_payment_method_id && c.stripe_payment_method_id !== '';

        html += '<tr>';
        html += '<td><strong>#' + c.semana_num + '</strong></td>';
        html += '<td>' + esc(c.nombre || '—') + '<br><small class="ad-dim">' + esc(c.telefono || '') + '</small></td>';
        html += '<td>' + esc(c.modelo || '') + ' ' + esc(c.color || '') + '</td>';
        html += '<td><strong>' + ADApp.money(c.monto) + '</strong></td>';
        html += '<td>' + (c.fecha_vencimiento || '—') + '</td>';
        html += '<td><span class="ad-badge ' + diasBadge + '">' + diasAtraso + ' días</span></td>';
        html += '<td><span class="ad-badge ' + estadoBadge + '">' + (c.estado === 'overdue' ? 'Atrasado' : 'Pendiente') + '</span></td>';
        html += '<td><div style="display:flex;gap:4px;flex-wrap:wrap;">';

        // Action buttons
        if (ADApp.canWrite()) {
          if (tieneTarjeta) {
            html += '<button class="ad-btn sm primary cbCobrar" data-id="' + c.id + '" style="padding:5px 10px;font-size:11px;">Cobrar ahora</button>';
            html += '<button class="ad-btn sm ghost cbReintentar" data-id="' + c.id + '" style="padding:5px 10px;font-size:11px;">Reintentar</button>';
          }
          html += '<button class="ad-btn sm ghost cbLink" data-id="' + c.id + '" style="padding:5px 10px;font-size:11px;">Enviar link</button>';
          if (ADApp.isAdmin()) {
            html += '<button class="ad-btn sm ghost cbMarcar" data-id="' + c.id + '" style="padding:5px 10px;font-size:11px;">Marcar pagado</button>';
          }
        }

        html += '</div></td>';
        html += '</tr>';
      });
    }

    html += '</tbody></table></div></div>';
    ADApp.render(html);

    // ── Event bindings ──
    $('#cbTabs').on('click', 'button', function(){
      _bucket = $(this).data('bucket');
      load();
    });
    $('#cbTabs button').each(function(){
      if ($(this).data('bucket') === _bucket) $(this).addClass('active');
    });

    $('.cbCobrar').on('click', function(){ cobrarAhora($(this).data('id')); });
    $('.cbReintentar').on('click', function(){ reintentar($(this).data('id')); });
    $('.cbLink').on('click', function(){ generarLink($(this).data('id')); });
    $('.cbMarcar').on('click', function(){ marcarPagado($(this).data('id')); });
  }

  function cobrarAhora(cicloId){
    if (!confirm('¿Cobrar ahora al cliente? Se cargará a su tarjeta registrada.')) return;
    ADApp.modal('<div class="ad-h2">Procesando cobro...</div><div style="text-align:center;padding:30px;"><span class="ad-spin"></span></div>');
    ADApp.api('pagos/cobrar-ahora.php', {ciclo_id: cicloId}).done(function(r){
      if (r.ok) {
        ADApp.modal('<div class="ad-h2">Cobro exitoso</div>'+
          '<div class="ad-banner ok">Pago procesado por ' + ADApp.money(r.monto) + '</div>'+
          '<p style="font-size:13px;color:var(--ad-dim);margin-top:8px;">Stripe PI: ' + r.stripe_pi + '</p>');
        setTimeout(function(){ ADApp.closeModal(); load(); }, 2000);
      } else {
        ADApp.modal('<div class="ad-h2">Error en cobro</div>'+
          '<div class="ad-banner err">' + esc(r.error) + '</div>'+
          '<div style="margin-top:12px;text-align:right;"><button class="ad-btn ghost" onclick="ADApp.closeModal()">Cerrar</button></div>');
      }
    }).fail(function(){
      ADApp.modal('<div class="ad-h2">Error</div><div class="ad-banner err">Error de conexión</div>');
    });
  }

  function reintentar(cicloId){
    if (!confirm('¿Reintentar cobro para este ciclo?')) return;
    ADApp.modal('<div class="ad-h2">Reintentando cobro...</div><div style="text-align:center;padding:30px;"><span class="ad-spin"></span></div>');
    ADApp.api('pagos/reintentar.php', {ciclo_id: cicloId}).done(function(r){
      if (r.ok) {
        ADApp.modal('<div class="ad-h2">Reintento exitoso</div><div class="ad-banner ok">Pago procesado correctamente</div>');
        setTimeout(function(){ ADApp.closeModal(); load(); }, 2000);
      } else {
        ADApp.modal('<div class="ad-h2">Reintento fallido</div><div class="ad-banner err">' + esc(r.error) + '</div>'+
          '<div style="margin-top:12px;text-align:right;"><button class="ad-btn ghost" onclick="ADApp.closeModal()">Cerrar</button></div>');
      }
    }).fail(function(){
      ADApp.modal('<div class="ad-h2">Error</div><div class="ad-banner err">Error de conexión</div>');
    });
  }

  function generarLink(cicloId){
    ADApp.modal('<div class="ad-h2">Generando link de pago...</div><div style="text-align:center;padding:30px;"><span class="ad-spin"></span></div>');
    ADApp.api('pagos/generar-link.php', {ciclo_id: cicloId}).done(function(r){
      if (r.ok) {
        ADApp.modal(
          '<div class="ad-h2">Link de pago generado</div>'+
          '<div class="ad-banner ok">Link listo para compartir</div>'+
          '<div style="margin-top:12px;"><input class="ad-input" value="' + esc(r.url) + '" id="cbLinkUrl" readonly style="font-size:12px;"></div>'+
          '<div style="margin-top:12px;display:flex;gap:8px;">'+
            '<button class="ad-btn primary" id="cbCopyLink">Copiar link</button>'+
            '<a href="' + esc(r.url) + '" target="_blank" class="ad-btn ghost">Abrir</a>'+
          '</div>'
        );
        $('#cbCopyLink').on('click', function(){
          $('#cbLinkUrl').select();
          document.execCommand('copy');
          $(this).text('Copiado!');
        });
      } else {
        ADApp.modal('<div class="ad-h2">Error</div><div class="ad-banner err">' + esc(r.error) + '</div>');
      }
    }).fail(function(){
      ADApp.modal('<div class="ad-h2">Error</div><div class="ad-banner err">Error de conexión</div>');
    });
  }

  function marcarPagado(cicloId){
    ADApp.modal(
      '<div class="ad-h2">Marcar como pagado</div>'+
      '<p class="ad-dim" style="font-size:13px;margin-bottom:12px;">Este ciclo se marcará como pagado manualmente. Agrega una nota de referencia.</p>'+
      '<input class="ad-input" id="cbNotaPago" placeholder="Nota (ej: Pago en efectivo, transferencia SPEI...)" style="margin-bottom:16px;">'+
      '<div style="display:flex;gap:8px;justify-content:flex-end;">'+
        '<button class="ad-btn ghost" onclick="ADApp.closeModal()">Cancelar</button>'+
        '<button class="ad-btn success" id="cbConfirmarPago">Confirmar pago</button>'+
      '</div>'
    );
    $('#cbConfirmarPago').on('click', function(){
      var nota = $('#cbNotaPago').val();
      $(this).prop('disabled', true).text('Procesando...');
      ADApp.api('pagos/marcar-pagado.php', {ciclo_id: cicloId, nota: nota}).done(function(r){
        if (r.ok) {
          ADApp.modal('<div class="ad-h2">Pago registrado</div><div class="ad-banner ok">Ciclo marcado como pagado</div>');
          setTimeout(function(){ ADApp.closeModal(); load(); }, 1500);
        } else {
          ADApp.modal('<div class="ad-h2">Error</div><div class="ad-banner err">' + esc(r.error) + '</div>');
        }
      }).fail(function(){
        ADApp.modal('<div class="ad-h2">Error</div><div class="ad-banner err">Error de conexión</div>');
      });
    });
  }

  function kpi(label, value, cls){
    return '<div class="ad-kpi"><div class="label">' + label + '</div><div class="value ' + cls + '">' + value + '</div></div>';
  }

  function bucketCard(label, count, color, bucket){
    return '<div class="ad-card" style="cursor:pointer;text-align:center;border-left:4px solid ' + color + ';" onclick="AD_cobranza.filterBucket(\'' + bucket + '\')">' +
      '<div style="font-size:32px;font-weight:800;color:' + color + ';">' + count + '</div>' +
      '<div style="font-size:13px;font-weight:600;color:var(--ad-dim);margin-top:4px;">' + label + '</div>' +
    '</div>';
  }

  function tabBtn(bucket, label){
    return '<button data-bucket="' + bucket + '"' + (_bucket === bucket ? ' class="active"' : '') + '>' + label + '</button>';
  }

  function filterBucket(b){
    _bucket = b;
    load();
  }

  function refresh(){ load(); }

  function esc(s){ return $('<span>').text(s||'').html(); }

  return { render: render, filterBucket: filterBucket, refresh: refresh };
})();
