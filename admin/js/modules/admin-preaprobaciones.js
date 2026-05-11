/* admin-preaprobaciones.js — Credit applications (leads) panel */
window.AD_preaprobaciones = (function(){
  var filters = { status: '', seguimiento: '', source: '', search: '', page: 1, limit: 50 };

  function render(){
    ADApp.render('<div class="ad-h1">Solicitudes de Crédito</div><div><span class="ad-spin"></span> Cargando...</div>');
    load();
  }

  function load(){
    ADApp.api('preaprobaciones/listar.php?' + $.param(filters)).done(paint);
  }

  function esc(s){ return jQuery('<div/>').text(s == null ? '' : String(s)).html(); }
  function fmtMoney(n){ if (n == null) return '—'; return '$' + Number(n).toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2}); }
  function fmtPct(n){ if (n == null) return '—'; return Math.round(Number(n) * 100) + '%'; }

  function statusBadge(s){
    var theme = {
      PREAPROBADO: { bg:'#10b981', label:'PREAPROBADO' },
      CONDICIONAL: { bg:'#d97706', label:'CONDICIONAL' },
      NO_VIABLE:   { bg:'#dc2626', label:'NO VIABLE'   }
    }[s] || { bg:'#6b7280', label: s };
    return '<span style="background:'+theme.bg+';color:#fff;padding:4px 10px;border-radius:12px;font-size:10px;font-weight:800;letter-spacing:0.3px;white-space:nowrap">'+esc(theme.label)+'</span>';
  }

  function segBadge(s){
    var themes = {
      nuevo:           { bg:'#fef3c7', tx:'#78350f', dot:'#f59e0b', lbl:'Nuevo' },
      contactado:      { bg:'#dbeafe', tx:'#1e40af', dot:'#3b82f6', lbl:'Contactado' },
      vendido:         { bg:'#d1fae5', tx:'#065f46', dot:'#10b981', lbl:'Vendido' },
      descartado:      { bg:'#f3f4f6', tx:'#6b7280', dot:'#9ca3af', lbl:'Descartado' },
      // New seguimiento values from the manual-review redesign 2026-05-04
      aprobado:        { bg:'#d1fae5', tx:'#065f46', dot:'#10b981', lbl:'Aprobado' },
      ofrecer_contado: { bg:'#dbeafe', tx:'#1e40af', dot:'#1a4b7a', lbl:'Ofrecer contado' },
      ofrecer_msi:     { bg:'#fed7aa', tx:'#7a4a1a', dot:'#7a4a1a', lbl:'Ofrecer MSI' },
      rechazado:       { bg:'#fee2e2', tx:'#991b1b', dot:'#dc2626', lbl:'Rechazado' },
      enviado_a_ventas:{ bg:'#e0f2fe', tx:'#075985', dot:'#0891b2', lbl:'A Ventas' },
      truora_enviado:  { bg:'#ede9fe', tx:'#5b21b6', dot:'#8b5cf6', lbl:'Truora enviado' },
      archivado:       { bg:'#f3f4f6', tx:'#6b7280', dot:'#9ca3af', lbl:'Archivado' }
    };
    var theme = themes[s] || themes.nuevo;
    return '<span style="background:'+theme.bg+';color:'+theme.tx+';padding:3px 10px 3px 8px;border-radius:12px;font-size:11px;font-weight:600;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;">'
         + '<span style="width:7px;height:7px;border-radius:50%;background:'+theme.dot+';display:inline-block;flex-shrink:0;"></span>'
         + esc(theme.lbl)+'</span>';
  }

  function paint(r){
    if (!r || !r.ok) { ADApp.render('<div class="ad-card">'+((r&&r.error)||'Error al cargar')+'</div>'); return; }

    var k = r.kpi || {};
    var html = '<div class="ad-h1">Solicitudes de Crédito <span style="font-size:14px;font-weight:400;color:var(--ad-dim)">('+r.total+' filtradas / '+(k.total||0)+' totales)</span></div>';

    // KPIs
    html += '<div class="ad-kpis" style="margin-bottom:14px;">';
    [
      {l:'Total',                v: k.total || 0,                  c:'blue'},
      {l:'Preaprobados',         v: k.preaprobado || 0,            c:'green'},
      {l:'Condicionales',        v: k.condicional || 0,            c:'yellow'},
      {l:'No Viables',           v: k.no_viable || 0,              c:'red'},
      {l:'Pendiente seguimiento',v: k.pendiente_seguimiento || 0,  c:'yellow'},
      {l:'Con CDC real',         v: k.con_cdc || 0,                c:'blue'},
      {l:'Score estimado',       v: k.sin_cdc || 0,                c:'yellow'},
    ].forEach(function(x){
      html += '<div class="ad-kpi"><div class="label">'+x.l+'</div><div class="value '+x.c+'">'+x.v+'</div></div>';
    });
    html += '</div>';

    // Filtros
    html += '<div class="ad-toolbar" style="gap:8px;flex-wrap:wrap;margin-bottom:14px;">';
    html += '<input id="apFSearch" placeholder="Buscar nombre/email/telefono" value="'+esc(filters.search)+'" style="flex:1;min-width:200px;padding:8px;border:1px solid #ddd;border-radius:4px;">';
    html += '<select id="apFStatus" style="padding:8px;border:1px solid #ddd;border-radius:4px;">';
    ['','PREAPROBADO','CONDICIONAL','NO_VIABLE'].forEach(function(s){
      html += '<option value="'+s+'"'+(filters.status===s?' selected':'')+'>'+(s||'Todos status')+'</option>';
    });
    html += '</select>';
    html += '<select id="apFSeg" style="padding:8px;border:1px solid #ddd;border-radius:4px;">';
    ['','nuevo','contactado','vendido','descartado','archivado'].forEach(function(s){
      var label = s ? (s.charAt(0).toUpperCase()+s.slice(1)) : 'Activos (no archivados)';
      html += '<option value="'+s+'"'+(filters.seguimiento===s?' selected':'')+'>'+label+'</option>';
    });
    html += '</select>';
    html += '<select id="apFSource" style="padding:8px;border:1px solid #ddd;border-radius:4px;">';
    ['','real','estimado'].forEach(function(s){
      html += '<option value="'+s+'"'+(filters.source===s?' selected':'')+'>'+(s||'Todo source')+'</option>';
    });
    html += '</select>';
    html += '<button id="apFApply" class="ad-btn">Filtrar</button>';
    html += '</div>';

    // Bulk action bar (visible when selections exist)
    html += '<div id="apBulkBar" style="display:none;background:#1f2937;color:#fff;padding:10px 16px;border-radius:6px;margin-bottom:8px;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
    html += '<span><span id="apBulkCount">0</span> seleccionadas</span>';
    html += '<div style="display:flex;gap:8px">';
    html += '<button class="apBulkArchive" style="padding:6px 14px;background:#f59e0b;color:#fff;border:none;border-radius:4px;font-weight:600;cursor:pointer">📁 Archivar</button>';
    html += '<button class="apBulkDelete" style="padding:6px 14px;background:#dc2626;color:#fff;border:none;border-radius:4px;font-weight:600;cursor:pointer">🗑 Eliminar permanente</button>';
    html += '</div></div>';

    // Tabla — wrap in overflow-x scroll div so wide content (12 columns)
    // doesn't get clipped on a 1280-1440px monitor where the right-edge
    // columns (Seguimiento + Ver/Editar) were being cut off (customer
    // brief 2026-05-04 screenshot). The CSS rule
    // `.ad-table-wrap > div { overflow-x:auto }` only fires when this
    // inner div exists; previously the table was a direct child of
    // .ad-table-wrap so the rule never matched.
    html += '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table" style="min-width:1280px;"><thead><tr>';
    html += '<th style="width:30px"><input type="checkbox" id="apCheckAll"></th>';
    html += '<th>Fecha</th><th>Cliente</th><th>Email / Teléfono</th><th>Modelo</th><th>Status</th><th>Score</th><th>Source</th><th>Eng req</th><th>Plazo</th><th>Seguimiento</th><th>Acción</th>';
    html += '</tr></thead><tbody>';

    if (!r.rows || !r.rows.length) {
      html += '<tr><td colspan="11" style="text-align:center;padding:30px;color:var(--ad-dim);">Sin solicitudes</td></tr>';
    } else r.rows.forEach(function(row){
      var fullName = [row.nombre, row.apellido_paterno, row.apellido_materno].filter(Boolean).join(' ') || '—';
      var contacto = (row.email || '') + (row.telefono ? '<br><small>'+esc(row.telefono)+'</small>' : '');
      var scoreCell = row.score ? row.score : (row.synth_score ? row.synth_score+' <small style="color:#d97706">(sint.)</small>' : '—');
      var fechaCorta = (row.freg || '').substring(0, 16);

      html += '<tr>';
      html += '<td><input type="checkbox" class="apRowCheck" data-id="'+row.id+'"></td>';
      html += '<td><small>'+esc(fechaCorta)+'</small></td>';
      html += '<td><strong>'+esc(fullName)+'</strong>'+(row.fecha_nacimiento?'<br><small>'+esc(row.fecha_nacimiento)+'</small>':'')+'</td>';
      html += '<td><small>'+esc(row.email||'—')+(row.telefono?'<br>'+esc(row.telefono):'')+'</small></td>';
      html += '<td>'+esc(row.modelo||'—')+'<br><small>'+fmtMoney(row.precio_contado)+'</small></td>';
      html += '<td>'+statusBadge(row.status)+'</td>';
      html += '<td>'+scoreCell+'</td>';
      html += '<td><small>'+esc(row.circulo_source||'—')+'</small></td>';
      html += '<td>'+(row.enganche_requerido?fmtPct(row.enganche_requerido):'—')+'</td>';
      html += '<td>'+(row.plazo_max||'—')+'m</td>';
      html += '<td>'+segBadge(row.seguimiento)+'</td>';
      html += '<td><button class="ad-btn sm ghost apEdit" data-id="'+row.id+'">Ver / Editar</button></td>';
      html += '</tr>';
    });
    html += '</tbody></table></div></div>';

    // Paginación
    if (r.pages > 1) {
      html += '<div class="ad-pagination">';
      for (var p = 1; p <= r.pages; p++) html += '<button class="'+(p===r.page?'active':'')+' apPage" data-p="'+p+'">'+p+'</button>';
      html += '</div>';
    }

    ADApp.render(html);

    $('#apFApply').on('click', function(){
      filters.search      = $('#apFSearch').val();
      filters.status      = $('#apFStatus').val();
      filters.seguimiento = $('#apFSeg').val();
      filters.source      = $('#apFSource').val();
      filters.page = 1;
      load();
    });
    $('.apPage').on('click', function(){ filters.page = $(this).data('p'); load(); });
    $('.apEdit').on('click', function(){ showDetail($(this).data('id'), r.rows); });

    // Bulk-select wiring
    function refreshBulkBar() {
      var n = $('.apRowCheck:checked').length;
      $('#apBulkCount').text(n);
      $('#apBulkBar').css('display', n > 0 ? 'flex' : 'none');
    }
    $('#apCheckAll').on('change', function(){
      $('.apRowCheck').prop('checked', this.checked);
      refreshBulkBar();
    });
    $('.apRowCheck').on('change', refreshBulkBar);

    $('.apBulkArchive').on('click', function(){
      var ids = $('.apRowCheck:checked').map(function(){ return parseInt($(this).data('id'),10); }).get();
      if (!ids.length) return;
      if (!confirm('¿Archivar ' + ids.length + ' solicitud(es)? Se ocultan del listado pero quedan en BD.')) return;
      ADApp.api('preaprobaciones/eliminar.php', { ids: ids, modo: 'archivar' })
        .done(function(){ load(); })
        .fail(function(xhr){ alert('Error: ' + (xhr.responseJSON && xhr.responseJSON.error || 'desconocido')); });
    });

    $('.apBulkDelete').on('click', function(){
      var ids = $('.apRowCheck:checked').map(function(){ return parseInt($(this).data('id'),10); }).get();
      if (!ids.length) return;
      var msg = '⚠️ ELIMINACIÓN PERMANENTE\n\n' + ids.length + ' solicitud(es) serán BORRADAS de la base de datos.\n\nEsta acción NO se puede deshacer (solo queda audit log).\n\nEscriba "ELIMINAR" para confirmar:';
      var typed = prompt(msg);
      if (typed === null) return;                         // Cancel
      if (String(typed).trim().toUpperCase() !== 'ELIMINAR') {
        alert('Texto incorrecto. Debes escribir exactamente: ELIMINAR');
        return;
      }
      ADApp.api('preaprobaciones/eliminar.php', { ids: ids, modo: 'eliminar' })
        .done(function(){ load(); })
        .fail(function(xhr){ alert('Error: ' + (xhr.responseJSON && xhr.responseJSON.error || 'desconocido')); });
    });
  }

  function showDetail(id, rows){
    var row = (rows || []).find(function(r){ return r.id == id; });
    if (!row) return;
    var fullName = [row.nombre, row.apellido_paterno, row.apellido_materno].filter(Boolean).join(' ') || 'Sin nombre';

    // Customer brief 2026-05-04 (mockup redesign): Pantalla de Revisión
    // Manual must surface the credit-decision evidence so the admin can
    // approve / decline at a glance without bouncing to the buró tab.
    // Sections:
    //   1. Risk-coloured header banner with Plazo Máximo / PTI / Score
    //   2. Indicadores Críticos (PLD, DPD90, Vencido, Razones de score)
    //   3. Resumen Buró (totals + counters)
    //   4. Datos Personales
    //   5. Crédito Solicitado + Truora status
    //   6. Recomendación del sistema (auto-generated narrative)
    //   7. Four action buttons (Aprobar / Contado / 9 MSI / Rechazar)
    //      with smart enable/disable based on risk signals

    // ── Risk classification ────────────────────────────────────────────
    // Four buckets drive header colour + recommendation copy + button
    // state. PLD block is highest priority — when CDC/AML returns a hit
    // we lock the screen down to the Rechazar button only (customer
    // brief 2026-05-04: legal requirement, AML obligation).
    //   pld_block: PLD match → only Rechazar enabled
    //   safe:      no DPD90, PTI < 35%, status PREAPROBADO/CONDICIONAL
    //   warn:      PTI 35-50% OR thin file with no morosidad
    //   danger:    DPD90 active OR PTI > 50% OR status NO_VIABLE
    var pti        = Number(row.pti_total || 0);
    var ptiPct     = Math.round(pti * 100);
    var hasDPD90   = (row.dpd90_flag == 1) || (row.buro_dpd90_flag == 1);
    var dpdMax     = Number(row.dpd_max || row.buro_dpd_max || 0);
    var status     = String(row.status || '').toUpperCase();
    var scoreNum   = Number(row.score || row.synth_score || 0);
    var pldBlock   = (row.buro_pld_match == 1) || (row.circulo_source === 'pld_match');
    // Customer brief 2026-05-06 — Truora rejection is now a hard gate on
    // credit approval, just like CDC score. truora_status='failure'/'rejected'
    // (failed Truora review) blocks Aprobar/9 MSI; pending/in_progress lets
    // the admin see the warning but doesn't block until Truora has a verdict.
    var truoraStatus = String(row.truora_status || '').toLowerCase();
    var truoraRejected = (truoraStatus === 'failure' || truoraStatus === 'rejected' || truoraStatus === 'denied');
    var risk = 'safe';
    if (pldBlock) risk = 'pld_block';
    else if (truoraRejected || status === 'NO_VIABLE' || hasDPD90 || pti > 0.50) risk = 'danger';
    else if (status === 'CONDICIONAL' || status === 'CONDICIONAL_ESTIMADO' || pti > 0.35) risk = 'warn';

    // CDC unreachable case (estimado source, no PLD): force yellow banner
    // and override the danger classification so the admin sees the right
    // recommendation ("Aprobar con condiciones conservadoras o reintentar
    // consulta") instead of a NO_VIABLE-style red flag.
    var cdcUnreachable = (row.circulo_source === 'estimado' && !pldBlock && !truoraRejected && !hasDPD90);
    if (cdcUnreachable && risk === 'danger') risk = 'warn';

    var theme = ({
      safe:      { hbg:'#e8f5e8', htext:'#1a6b1a', haccent:'#1a6b1a', headerLabel:'PLAZO MÁXIMO', headerLabelColor:'#1a4b1a' },
      warn:      { hbg:'#fff4d6', htext:'#7a5800', haccent:'#c89a3a', headerLabel:'PLAZO MÁXIMO', headerLabelColor:'#5a4000' },
      danger:    { hbg:'#fce8e8', htext:'#5a1a1a', haccent:'#8b1a1a', headerLabel:'PLAZO MÁXIMO', headerLabelColor:'#8b3a3a' },
      pld_block: { hbg:'#7f1d1d', htext:'#fff',    haccent:'#fff',    headerLabel:'BLOQUEADO POR PLD', headerLabelColor:'#fee2e2' }
    })[risk];

    var html = '';

    // ── 1. Risk header banner ─────────────────────────────────────────
    html += '<div style="background:'+theme.hbg+';color:'+theme.htext+';padding:24px 20px;text-align:center;margin:-20px -20px 0 -20px;border-radius:12px 12px 0 0;">';
    if (risk === 'pld_block') {
      // Mandatory AML/PLD compliance block. SAT report flag must be
      // raised when this banner shows — only Rechazar is allowed.
      html += '<div style="background:#450a0a;color:#fff;padding:10px 12px;margin:-12px -8px 14px;border-radius:6px;font-size:13px;font-weight:800;letter-spacing:.5px;">'
            + '🚫 BLOQUEADO — Match en lista PLD/AML'
            + '<div style="font-size:11px;font-weight:600;margin-top:4px;opacity:.9;">Solo se permite RECHAZAR · Reporte SAT activado</div>'
            + '</div>';
    } else if (risk === 'danger') {
      var bandera = countBanderasRojas(row);
      html += '<div style="background:#8b1a1a;color:#fff;padding:8px 12px;margin:-12px -8px 14px;border-radius:6px;font-size:12px;font-weight:700;letter-spacing:.5px;">'
            + '🚫 NO RECOMENDADO — '+bandera+' banderas rojas activas'
            + '</div>';
    }
    html += '<div style="font-size:11px;letter-spacing:1px;text-transform:uppercase;color:'+theme.headerLabelColor+';">'+theme.headerLabel+'</div>';
    html += '<div style="font-size:28px;font-weight:800;margin:6px 0;">'+(row.plazo_max ? row.plazo_max+' meses' : '— meses')+'</div>';
    html += '<div style="display:flex;justify-content:space-around;margin-top:14px;padding-top:14px;border-top:1px solid rgba(0,0,0,.08);">';
    html += '<div><div style="font-size:20px;font-weight:800;color:'+theme.haccent+';">'+ptiPct+'%</div>'
          + '<div style="font-size:10px;letter-spacing:.5px;color:'+theme.headerLabelColor+';">PTI</div></div>';
    var scoreDisplay = scoreNum ? scoreNum : '—';
    var scoreEst = (row.synth_score && !row.score) ? ' (est.)' : '';
    html += '<div><div style="font-size:20px;font-weight:800;color:'+theme.haccent+';">'+scoreDisplay+scoreEst+'</div>'
          + '<div style="font-size:10px;letter-spacing:.5px;color:'+theme.headerLabelColor+';">SCORE</div></div>';
    html += '</div></div>';

    // Identity strip — when CDC was unreachable we override the stored
    // NO_VIABLE label with a "CONDICIONAL (CDC sin respuesta)" pill so
    // the admin doesn't see contradictory cues (yellow banner above
    // saying "approve with conservative terms" while the strip still
    // shouts NO_VIABLE in red).
    html += '<div style="background:#fafafa;padding:10px 16px;font-size:12px;color:#666;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin:0 -20px;">';
    html += '<div><strong style="color:#333">'+esc(fullName)+'</strong> · #'+row.id+'</div>';
    var statusLabel = cdcUnreachable ? 'CONDICIONAL (CDC sin respuesta)' : (row.status || '—');
    html += '<div>Status: <strong style="color:'+theme.haccent+'">'+esc(statusLabel)+'</strong></div>';
    html += '</div>';

    // ── 2. Indicadores Críticos ───────────────────────────────────────
    html += '<div style="padding:18px 8px 6px;">';
    html += '<div style="font-size:11px;letter-spacing:1.2px;color:#666;text-transform:uppercase;margin-bottom:12px;font-weight:700;">⚠️ Indicadores Críticos</div>';

    // PLD Check — now sourced from CDC raw response (customer brief 2026-05-04)
    if (row.buro_pld_match == 1) {
      html += alertRow('PLD Check', '✗ Coincidencia detectada — revisar', 'bad');
    } else if (row.buro_folio) {
      // We have a CDC query for this applicant; if pld_match is 0/null,
      // CDC didn't flag PLD. (Old rows have null — show conservative "—".)
      html += alertRow('PLD Check', row.buro_pld_match === 0 || row.buro_pld_match === '0'
        ? '✓ Sin coincidencias'
        : '— Sin verificar', row.buro_pld_match === 0 || row.buro_pld_match === '0' ? 'good' : 'neutral');
    } else {
      html += alertRow('PLD Check', '— No verificado (sin consulta CDC)', 'neutral');
    }

    // DPD90 actual
    if (hasDPD90) {
      var nDpd = row.buro_cuentas_dpd90_hist || row.buro_num_cuentas || row.dpd_max || '?';
      html += alertRow('DPD90 actual', '✗ '+nDpd+' cuentas con mora activa', 'bad');
    } else {
      html += alertRow('DPD90 actual', '✓ Ninguna cuenta activa con mora', 'good');
    }

    // Vencido / Aprobado — now from real CDC data when available
    if (row.buro_aprobado_total != null && Number(row.buro_aprobado_total) > 0) {
      var aprob = Number(row.buro_aprobado_total) || 0;
      var venc  = Number(row.buro_vencido_total) || 0;
      var pct   = aprob > 0 ? Math.round((venc / aprob) * 100) : 0;
      var variant = pct >= 30 ? 'bad' : (pct >= 10 ? 'warn' : 'good');
      var label = '$' + venc.toLocaleString('es-MX') + ' / $' + aprob.toLocaleString('es-MX') + ' (' + pct + '%)';
      html += alertRow('Vencido / Aprobado', label, variant);
    } else {
      html += alertRow('Vencido / Aprobado', '— Datos no disponibles', 'neutral');
    }

    // Razones de score — real CDC codes when available, else the inferred ones
    var razones = renderScoreReasonBadges(row, risk);
    html += alertRowCustom('Razones de score', razones, risk === 'danger' ? 'bad' : 'good');
    html += '</div>';

    // ── 3. Resumen Buró ───────────────────────────────────────────────
    html += '<div style="padding:18px 8px;border-top:1px solid #eee;">';
    html += '<div style="font-size:11px;letter-spacing:1.2px;color:#666;text-transform:uppercase;margin-bottom:12px;font-weight:700;">📊 Resumen Buró</div>';

    // Rows that ran CDC BEFORE 2026-05-04 don't have the new
    // aprobado_total / vencido_total / consultas_6m columns populated
    // (we only started extracting them on that date). Show a clear
    // warning so the admin knows the "—" is a data-capture gap, not
    // a customer with truly empty CDC. Detection: if buro_folio
    // exists (CDC was queried) but the new fields are all null, the
    // row predates the enrichment.
    var hasOldCdc = row.buro_folio && (
      row.buro_aprobado_total == null && row.buro_vencido_total == null &&
      row.buro_credito_mas_antiguo_meses == null && row.buro_consultas_6m == null
    );
    if (hasOldCdc) {
      html += '<div style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:10px 12px;border-radius:8px;font-size:12px;margin-bottom:12px;line-height:1.45;">'
            + '<strong>ℹ Datos detallados no capturados</strong><br>'
            + 'Esta solicitud se procesó antes del 2026-05-04. Solo se almacenó score / pago mensual / DPD. '
            + 'Para ver Aprobado/Vencido/Consultas hay que volver a consultar CDC (genera costo).'
            + '</div>';
    }

    var dashSpan = '<span style="color:#999">—</span>';

    html += dataRow('Aprobado total',
      row.buro_aprobado_total != null ? fmtMoney(row.buro_aprobado_total) : dashSpan);

    var vencidoStr = dashSpan;
    if (row.buro_vencido_total != null) {
      var vT = Number(row.buro_vencido_total) || 0;
      var aT = Number(row.buro_aprobado_total) || 0;
      var vPct = aT > 0 ? Math.round((vT / aT) * 100) : 0;
      vencidoStr = fmtMoney(vT) + (aT > 0 ? ' <span style="color:'+(vPct>=30?'#dc2626':'#666')+';">('+vPct+'%)</span>' : '');
    }
    html += dataRow('Vencido total', vencidoStr);

    html += dataRow('Pago mensual requerido', fmtMoney(row.buro_pago_mensual || row.pago_mensual_buro));

    var ca = row.buro_cuentas_activas != null ? row.buro_cuentas_activas : (row.buro_num_cuentas != null ? row.buro_num_cuentas : '—');
    var ch = row.buro_cuentas_dpd90_hist != null ? row.buro_cuentas_dpd90_hist : (row.dpd_max != null ? row.dpd_max : (row.buro_dpd_max != null ? row.buro_dpd_max : '0'));
    html += dataRow('Cuentas activas / DPD90 histórico', ca + ' / ' + ch);

    html += dataRow('Crédito más antiguo',
      row.buro_credito_mas_antiguo_meses != null
        ? row.buro_credito_mas_antiguo_meses + ' meses'
        : dashSpan);

    html += dataRow('Consultas últimos 6 meses',
      row.buro_consultas_6m != null
        ? row.buro_consultas_6m + (Number(row.buro_consultas_6m) >= 6 ? ' <span style="color:#dc2626;">⚠</span>' : '')
        : dashSpan);

    if (row.buro_folio) {
      var freg = row.buro_freg ? ' <span style="color:#9ca3af;font-size:11px;">('+String(row.buro_freg).slice(0,16)+')</span>' : '';
      html += dataRow('Folio CDC', '<code style="font-size:11px;color:#666">'+esc(row.buro_folio)+'</code>'+freg);
    }
    html += '</div>';

    // ── 4. Datos Personales ──────────────────────────────────────────
    html += '<div style="padding:18px 8px;border-top:1px solid #eee;">';
    html += '<div style="font-size:11px;letter-spacing:1.2px;color:#666;text-transform:uppercase;margin-bottom:12px;font-weight:700;">👤 Datos Personales</div>';
    html += dataRow('Email',     row.email || '—');
    html += dataRow('Teléfono',  row.telefono || '—');
    var ageStr = '';
    if (row.fecha_nacimiento) {
      var byear = parseInt(String(row.fecha_nacimiento).slice(0,4),10);
      if (byear) {
        var age = new Date().getFullYear() - byear;
        ageStr = ' ('+age+' años)';
      }
    }
    html += dataRow('Nacimiento', (row.fecha_nacimiento || '—') + ageStr);
    html += dataRow('Ciudad',     [row.ciudad, row.estado].filter(Boolean).join(', ') || '—');
    if (row.cp) html += dataRow('CP', row.cp);
    html += '</div>';

    // ── 5. Crédito Solicitado + Truora ───────────────────────────────
    // Customer brief 2026-05-04 round 5: "How do we know what credit
    // they requested?" — the modal previously surfaced the system's
    // computed recommendation (enganche_requerido / plazo_max) without
    // distinguishing it from what the applicant actually asked for at
    // submission time (enganche_pct / plazo_meses). Now both are shown
    // as separate rows so the reviewer can spot when the system tightened
    // (or relaxed) the customer's request.
    html += '<div style="padding:18px 8px;border-top:1px solid #eee;">';
    html += '<div style="font-size:11px;letter-spacing:1.2px;color:#666;text-transform:uppercase;margin-bottom:12px;font-weight:700;">🛵 Crédito Solicitado por el Cliente</div>';
    html += dataRow('Modelo',           (row.modelo||'—') + (row.precio_contado ? ' — '+fmtMoney(row.precio_contado) : ''));
    html += dataRow('Ingreso mensual',  fmtMoney(row.ingreso_mensual));
    // Customer's chosen enganche % and plazo at the moment of applying.
    // Distinct from enganche_requerido (system minimum) and plazo_max
    // (system ceiling). Shown side-by-side with the system numbers so
    // the reviewer can immediately see the gap.
    var clientePctRaw = Number(row.enganche_pct || 0);
    var clientePct    = clientePctRaw > 1 ? clientePctRaw : Math.round(clientePctRaw * 100);
    var sistemaPct    = row.enganche_requerido != null ? Math.round(Number(row.enganche_requerido) * 100) : null;
    var clientePlazo  = row.plazo_meses != null ? Number(row.plazo_meses) : null;
    var sistemaPlazo  = row.plazo_max    != null ? Number(row.plazo_max)    : null;
    var precioC       = Number(row.precio_contado || 0);

    var solicEng = clientePctRaw > 0
      ? '<span style="font-weight:700;color:#0369a1;">' + clientePct + '%</span>'
        + (precioC > 0 ? ' <span style="color:#666">('+fmtMoney(precioC * (clientePct/100))+')</span>' : '')
        + (sistemaPct != null && sistemaPct !== clientePct
            ? ' <span style="color:#9ca3af;font-size:11px;"> · sistema requiere '+sistemaPct+'%</span>'
            : '')
      : '<span style="color:#9ca3af">—</span>';
    html += dataRow('Enganche solicitado', solicEng);

    var solicPlazo = clientePlazo
      ? '<span style="font-weight:700;color:#0369a1;">' + clientePlazo + ' meses</span>'
        + (sistemaPlazo && sistemaPlazo !== clientePlazo
            ? ' <span style="color:#9ca3af;font-size:11px;"> · sistema máx '+sistemaPlazo+'</span>'
            : '')
      : '<span style="color:#9ca3af">—</span>';
    html += dataRow('Plazo solicitado', solicPlazo);

    html += dataRow('Pago Voltika mensual', fmtMoney(row.pago_mensual));
    html += dataRow('PTI total (CDC + Voltika)', '<span style="color:'+theme.haccent+';font-weight:700">'+fmtPct(row.pti_total)+'</span>');
    html += dataRow('Source', sourceLabel(row.circulo_source));
    html += dataRow('Truora ID', truoraStatusBadge(row));
    if (row.truora_declined_reason) {
      html += dataRow('Razón Truora', '<span style="color:#dc2626;font-weight:600">' + esc(row.truora_declined_reason) + '</span>');
    }
    if (row.curp_match !== null && row.curp_match !== undefined) {
      var curpLabel = (row.curp_match == 1)
        ? '<span style="color:#10b981;font-weight:700">✓ Coincide</span>'
        : '<span style="color:#dc2626;font-weight:700">✗ No coincide</span>';
      html += dataRow('CURP match', curpLabel);
    }
    html += '</div>';

    // ── 6. Recomendación del sistema ─────────────────────────────────
    var rec = buildRecomendacion(row, risk, hasDPD90, ptiPct, scoreNum);
    var recBg = risk === 'danger' ? '#fce8e8' : (risk === 'warn' ? '#fff4d6' : '#f8f4e8');
    var recBorder = risk === 'danger' ? '#8b1a1a' : (risk === 'warn' ? '#c89a3a' : '#c89a3a');
    var recTitleColor = risk === 'danger' ? '#5a1a1a' : '#5a3a00';
    html += '<div style="background:'+recBg+';border-left:3px solid '+recBorder+';padding:12px 14px;margin:16px 8px;border-radius:4px;font-size:13px;line-height:1.5;">';
    html += '<strong style="color:'+recTitleColor+';">⚠ Recomendación del sistema:</strong> '+rec.summary;
    if (rec.bullets && rec.bullets.length) {
      html += '<ul style="margin:8px 0 0 18px;padding:0;font-size:12px;">';
      rec.bullets.forEach(function(b){
        html += '<li style="margin:4px 0;"><strong>'+esc(b.label)+'</strong>'+(b.detail?': '+esc(b.detail):'')+'</li>';
      });
      html += '</ul>';
    }
    if (rec.action) {
      html += '<div style="margin-top:8px;font-weight:700;color:'+recTitleColor+';">Acción sugerida: '+esc(rec.action)+'</div>';
    }
    html += '</div>';

    // ── 7. Manual override of enganche / plazo (NEW FEATURE 2026-05-04) ─
    // Allow the reviewer to adjust the system-suggested terms before
    // sending the offer to the customer. The reviewer can:
    //   - Slide enganche between 25% and 80% (default = system suggestion)
    //   - Pick plazo from [12, 18, 24, 36] (default = system suggestion)
    //   - See live weekly/monthly payment recalculate
    //   - Send a personalized 48-hour link that locks these values in
    //
    // Customer brief 2026-05-09 (Óscar's report — "When we have a
    // conditional we don't have the bar for enganche and plazo"): on
    // NO_VIABLE cases the override UI was hidden entirely, but the
    // admin still wanted to manually approve plazos (with explicit
    // override) AND specify the months/enganche they're approving for
    // — otherwise the audit note shipped as "Aprobar plazos: ? meses",
    // losing the actual terms. Show the bar whenever the admin has at
    // least the option to approve plazos (i.e. anything that isn't
    // hard-blocked), so the soft-blocked NO_VIABLE flow gets the same
    // term-picking UI as the standard PREAPROBADO/CONDICIONAL flow.
    var sysEnganchePct = Math.round((Number(row.enganche_requerido) || 0.30) * 100);
    var sysPlazo       = Number(row.plazo_max) || 12;
    var precioContado  = Number(row.precio_contado) || 0;
    // hardBlocked is computed below in §8; mirror its exclusions here
    // so the override bar disappears when no plazo path is reachable.
    var hardBlockedForOverride = pldBlock || hasDPD90 || truoraRejected || pti >= 0.50;
    var canOverride    = !hardBlockedForOverride && precioContado > 0
                         && (status === 'PREAPROBADO' || status === 'CONDICIONAL' || status === 'NO_VIABLE');
    if (canOverride) {
      // Three-line comparison header so the reviewer always sees:
      //   • What the customer asked for
      //   • What the system suggested
      //   • What they're about to send (slider current value)
      var clientePctHdr = clientePctRaw > 0 ? clientePct + '%' : '—';
      var clientePlzHdr = clientePlazo ? clientePlazo + 'm' : '—';
      var sistemaPctHdr = sysEnganchePct + '%';
      var sistemaPlzHdr = sysPlazo + 'm';
      html += '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:16px;margin:16px 8px;">'
           +    '<div style="font-size:11px;letter-spacing:1.2px;color:#0369a1;text-transform:uppercase;margin-bottom:8px;font-weight:700;">⚙ Ajuste manual de la oferta (opcional)</div>'
           +    '<div style="display:flex;gap:12px;flex-wrap:wrap;font-size:11px;margin-bottom:12px;padding:8px 10px;background:#fff;border-radius:6px;">'
           +      '<div><span style="color:#64748b">Cliente solicitó:</span> <strong style="color:#0369a1;">'+clientePctHdr+' / '+clientePlzHdr+'</strong></div>'
           +      '<div><span style="color:#64748b">Sistema sugiere:</span> <strong style="color:#7a4a1a;">'+sistemaPctHdr+' / '+sistemaPlzHdr+'</strong></div>'
           +      '<div><span style="color:#64748b">Vas a enviar:</span> <strong id="apOvSummary" style="color:#10b981;">'+sistemaPctHdr+' / '+sistemaPlzHdr+'</strong></div>'
           +    '</div>'
           +    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:12px;">'
           +      '<div>'
           +        '<label style="font-size:12px;color:#475569;display:flex;justify-content:space-between;align-items:center;">'
           +          '<span>Enganche</span>'
           +          '<span><strong id="apOvEngangePct">'+sysEnganchePct+'%</strong> · <span id="apOvEngancheMonto" style="color:#0369a1;font-weight:700">'+fmtMoney(precioContado * sysEnganchePct/100)+'</span></span>'
           +        '</label>'
           +        '<input type="range" min="25" max="80" step="5" value="'+sysEnganchePct+'" id="apOvEnganche" style="width:100%;margin-top:8px;">'
           +        '<div style="font-size:10px;color:#94a3b8;display:flex;justify-content:space-between;">'
           +          '<span>25%</span><span>50%</span><span>80%</span>'
           +        '</div>'
           +      '</div>'
           +      '<div>'
           +        '<label style="font-size:12px;color:#475569;">Plazo (meses)</label>'
           +        '<select id="apOvPlazo" style="width:100%;margin-top:8px;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;background:#fff;">'
           +          [12,18,24,36].map(function(p){
                       return '<option value="'+p+'"'+(p===sysPlazo?' selected':'')+'>'+p+' meses</option>';
                     }).join('')
           +        '</select>'
           +        '<div style="font-size:11px;color:#94a3b8;margin-top:4px;">Sugerido por sistema: '+sysPlazo+' meses</div>'
           +      '</div>'
           +    '</div>'
           +    '<div id="apOvLivePayment" style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;font-size:13px;display:flex;justify-content:space-around;text-align:center;margin-bottom:12px;">'
           +      '<div><div style="color:#64748b;font-size:11px;">Monto financiado</div><div id="apOvFinanciado" style="font-weight:800;color:#0369a1;">—</div></div>'
           +      '<div><div style="color:#64748b;font-size:11px;">Pago semanal</div><div id="apOvSemanal" style="font-weight:800;color:#0369a1;">—</div></div>'
           +      '<div><div style="color:#64748b;font-size:11px;">Pago mensual</div><div id="apOvMensual" style="font-weight:800;color:#0369a1;">—</div></div>'
           +    '</div>'
           // Two buttons + a test toggle so the admin can preview the
           // customer landing without sending a real email/SMS or
           // mutating the preaprobaciones row. Testing flow:
           //   1. Adjust sliders
           //   2. Click "Probar (no enviar)" → new tab opens to recover-
           //      aprobado.php with the chosen terms
           //   3. Walk through the customer screens to verify
           //   4. Once satisfied, click "Enviar oferta" for real.
           +    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">'
           +      '<button id="apOvTestLink" style="padding:12px;background:#fff;color:#0369a1;border:1px solid #0ea5e9;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">🧪 Probar (no enviar)</button>'
           +      '<button id="apOvSendOffer" style="padding:12px;background:#0ea5e9;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">📧 Enviar oferta (válida 48h)</button>'
           +    '</div>'
           +    '<div style="font-size:11px;color:#64748b;margin-top:6px;text-align:center;">'
           +      '<strong>🧪 Probar:</strong> abre el link en pestaña nueva sin enviar email ni guardar override. '
           +      '<strong>📧 Enviar:</strong> guarda los valores, expira en 48h, el cliente recibe email+SMS.'
           +    '</div>'
           + '</div>';
    }

    // ── 8. Decision action buttons ───────────────────────────────────
    // Hard blocks (NO override possible from this UI):
    //   - PLD match    — AML/SAT compliance, only Rechazar allowed
    //   - Truora rejected — identity verification failed (Carlos Ricardo
    //                       Sanchez case, customer brief 2026-05-06 NEW3)
    //   - DPD90 active  — applicant is currently delinquent on another
    //                     account, hard credit block
    //   - PTI > 50%     — pago-to-income ratio over half, hard block
    //
    // Soft blocks (admin can override via confirmation dialog):
    //   - Status NO_VIABLE due to low CDC score, low score+high PTI
    //     guardrail, etc. — system recommends rejection but admin has
    //     business judgement (repeat customer, additional collateral,
    //     known referral, etc.) that justifies overriding the algorithm.
    //
    // Customer brief 2026-05-07 (follow-up to NEW1): "Still the issue to
    // approve when the score is lower. We need this buttons available."
    // Yesterday's fix only enabled the buttons for cdcUnreachable; the
    // general low-score case (Score 373, Score 380-419 with high PTI,
    // etc.) still needed manual-approval support. Now Aprobar Plazos +
    // 9 MSI are clickable for any non-hard-blocked case; the click
    // handler asks for explicit confirmation when the system status is
    // NO_VIABLE so the admin acknowledges they're overriding.
    var hardBlocked = pldBlock || hasDPD90 || truoraRejected || pti >= 0.50;
    var canApprove  = !hardBlocked;
    var canContado  = !pldBlock;       // PLD blocks contado offers too
    var canMSI      = !hardBlocked;    // MSI requires healthy file + no PLD + Truora ok
    // Track whether the admin will need to confirm an override; the
    // click handler reads this flag.
    var needsOverrideConfirm = (status === 'NO_VIABLE') && !cdcUnreachable && !hardBlocked;
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:16px 8px;background:#fafafa;border-radius:8px;margin:8px;">';
    html += decisionBtn('apDecApprove', '✓ Aprobar Plazos',  '#1a6b1a', canApprove);
    html += decisionBtn('apDecContado', '$ Ofrecer Contado', '#1a4b7a', canContado);
    html += decisionBtn('apDecMSI',     '9 MSI Sin Intereses','#7a4a1a', canMSI);
    html += decisionBtn('apDecReject',  '✗ Rechazar',         '#8b1a1a', true);
    html += '</div>';

    // ── Seguimiento section ──────────────────────────────────────────────
    html += '<div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin:24px 8px 8px 8px">';
    html += '<div style="font-size:14px;font-weight:700;margin-bottom:12px;color:#374151;display:flex;align-items:center;gap:8px">';
    html += '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
    html += 'Seguimiento de venta</div>';

    html += '<label style="font-size:12px;color:#6b7280;display:block;margin-bottom:6px">Estado</label>';
    html += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px">';
    ['nuevo','contactado','vendido','descartado'].forEach(function(s){
      var isActive = (row.seguimiento || 'nuevo') === s;
      var bgC = isActive ? (s==='vendido'?'#10b981':s==='contactado'?'#3b82f6':s==='descartado'?'#9ca3af':'#f59e0b') : '#fff';
      var txC = isActive ? '#fff' : '#374151';
      var brdC = isActive ? bgC : '#d1d5db';
      html += '<label style="cursor:pointer;background:'+bgC+';color:'+txC+';border:2px solid '+brdC+';padding:10px;border-radius:8px;text-align:center;font-weight:600;font-size:13px;transition:all .15s">';
      html += '<input type="radio" name="apSegRadio" value="'+s+'" style="display:none"'+(isActive?' checked':'')+'>';
      html += s.charAt(0).toUpperCase()+s.slice(1)+'</label>';
    });
    html += '</div>';

    html += '<label style="font-size:12px;color:#6b7280;display:block;margin-bottom:6px">Notas (llamada, visita, recordatorio, etc.)</label>';
    html += '<textarea id="apEditNotas" rows="4" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:6px;font-family:inherit;font-size:13px;resize:vertical;box-sizing:border-box">'+esc(row.notas_admin||'')+'</textarea>';
    html += '</div>';

    // ── Manual review action bar (customer brief 2026-05-02) ─────────────
    // Two buttons that recover stuck applications:
    //  1. "Enviar link Truora" — when the applicant passed CDC but never
    //     started Truora. Generates an HMAC-signed recovery URL and emails
    //     + texts the customer so they can finish identity verification.
    //  2. "Enviar a Ventas para cobro" — only enabled when ALL three
    //     signals are green (CDC real + CURP match + Truora approved).
    //     Promotes the row to a transacciones 'pendiente' entry and
    //     emails the customer a payment link.
    var canSendTruora = !row.truora_process_id;  // never started
    var allVerified  = ((row.circulo_source === 'real' || row.circulo_source === 'cdc_sin_score' || row.circulo_source === 'score_bajo_pti_excelente')
                       && (row.curp_match == 1) && (row.truora_approved == 1));

    html += '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin:16px 8px 8px;padding:14px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;">';
    html += '<button id="apSendTruoraLink" '+(canSendTruora?'':'disabled')+
            ' title="'+(canSendTruora?'Enviar al cliente un link para completar la verificación Truora':'El cliente ya inició Truora')+'"'+
            ' style="padding:10px 16px;background:'+(canSendTruora?'#0ea5e9':'#cbd5e1')+';color:#fff;border:none;border-radius:6px;font-weight:700;cursor:'+(canSendTruora?'pointer':'not-allowed')+';font-size:13px;">📧 Enviar link de Truora</button>';
    html += '<button id="apSendToVentas" '+(allVerified?'':'disabled')+
            ' title="'+(allVerified?'Promover esta solicitud a Ventas y enviar enlace de pago al cliente':'Requiere CDC real + CURP match + Truora aprobado')+'"'+
            ' style="padding:10px 16px;background:'+(allVerified?'#10b981':'#cbd5e1')+';color:#fff;border:none;border-radius:6px;font-weight:700;cursor:'+(allVerified?'pointer':'not-allowed')+';font-size:13px;">💳 Enviar a Ventas para cobro</button>';
    html += '</div>';

    // ── Action bar ───────────────────────────────────────────────────────
    html += '<div style="display:flex;gap:10px;justify-content:space-between;margin-top:16px;padding:0 8px;flex-wrap:wrap">';
    html += '<div style="display:flex;gap:8px">';
    html += '<button id="apEditArchive" style="padding:8px 14px;background:#fef3c7;color:#78350f;border:1px solid #f59e0b;border-radius:6px;font-weight:600;cursor:pointer;font-size:13px">📁 Archivar</button>';
    html += '<button id="apEditDelete" style="padding:8px 14px;background:#fee2e2;color:#991b1b;border:1px solid #dc2626;border-radius:6px;font-weight:600;cursor:pointer;font-size:13px">🗑 Eliminar</button>';
    html += '</div>';
    html += '<div style="display:flex;gap:10px">';
    html += '<button id="apEditClose" style="padding:10px 22px;background:#fff;color:#374151;border:1px solid #d1d5db;border-radius:6px;font-weight:600;cursor:pointer">Cerrar</button>';
    html += '<button id="apEditSave" style="padding:10px 22px;background:'+theme.haccent+';color:#fff;border:none;border-radius:6px;font-weight:700;cursor:pointer">💾 Guardar cambios</button>';
    html += '</div></div>';

    ADApp.modal(html);

    // Make seguimiento radios visually clickable as cards
    $('input[name="apSegRadio"]').on('change', function(){
      $('label').filter(function(){ return $(this).find('input[name="apSegRadio"]').length; }).each(function(){
        var $lbl = $(this), checked = $lbl.find('input').is(':checked');
        var s = $lbl.find('input').val();
        var bgC = checked ? (s==='vendido'?'#10b981':s==='contactado'?'#3b82f6':s==='descartado'?'#9ca3af':'#f59e0b') : '#fff';
        $lbl.css({background: bgC, color: checked ? '#fff' : '#374151', borderColor: checked ? bgC : '#d1d5db'});
      });
    });

    $('#apEditSave').on('click', function(){
      var seg = $('input[name="apSegRadio"]:checked').val() || 'nuevo';
      ADApp.api('preaprobaciones/actualizar.php', { id: row.id, seguimiento: seg, notas_admin: $('#apEditNotas').val() })
        .done(function(){ ADApp.closeModal(); load(); })
        .fail(function(xhr){ alert('Error: ' + (xhr.responseJSON && xhr.responseJSON.error || 'desconocido')); });
    });
    $('#apEditClose').on('click', function(){ ADApp.closeModal(); });

    // ── Decision actions (mockup 2026-05-04) ────────────────────────────
    // Each button is a one-click decision that updates seguimiento (and
    // status when relevant) so the seguimiento radios + notas reflect the
    // admin's verdict without forcing them into the granular Save flow.
    function applyDecision(payload, label){
      if (!confirm('¿Confirmar decisión: '+label+'?\n\nCliente: '+fullName)) return;
      ADApp.api('preaprobaciones/actualizar.php', Object.assign({ id: row.id }, payload))
        .done(function(r){
          if (r && r.ok !== false) { ADApp.closeModal(); load(); }
          else alert('Error: '+((r && r.error) || 'desconocido'));
        })
        .fail(function(xhr){ alert('Error: ' + (xhr.responseJSON && xhr.responseJSON.error || 'desconocido')); });
    }
    // Customer brief 2026-05-07: when admin overrides a NO_VIABLE
    // recommendation, require an explicit confirmation so the override
    // is intentional + auditable. The note recorded reflects that this
    // was a manual override against the algorithm's advice.
    function confirmOverrideIfNeeded(actionLabel) {
      if (!needsOverrideConfirm) return true;
      var reasons = [];
      if (scoreNum && scoreNum < 420) reasons.push('Score ' + scoreNum + ' (sistema considera bajo)');
      if (ptiPct >= 35)               reasons.push('PTI ' + ptiPct + '%');
      if (!reasons.length)            reasons.push('Status NO_VIABLE');
      return confirm(
        'El sistema recomienda RECHAZAR esta solicitud:\n\n  · ' + reasons.join('\n  · ') +
        '\n\n¿Estás seguro que deseas ' + actionLabel + ' como override del sistema?\n' +
        'La nota quedará marcada como override manual del revisor.'
      );
    }
    $('#apDecApprove').on('click', function(){
      if ($(this).is(':disabled')) return;
      if (!confirmOverrideIfNeeded('APROBAR PLAZOS')) return;
      var notePrefix = needsOverrideConfirm ? '⚠ OVERRIDE manual: ' : '';
      // Customer brief 2026-05-09: prefer the override bar's current
      // values when it's rendered (any non-hard-blocked status now
      // shows the bar). Falls back to the system recommendation, then
      // to '?' as a last-resort marker so the audit note never ships
      // without a number — that ambiguity ("Aprobar plazos: ? meses")
      // was the original symptom Óscar reported.
      var $eng    = $('#apOvEnganche');
      var $plazo  = $('#apOvPlazo');
      var engPct  = $eng.length   ? Number($eng.val())   : (row.enganche_requerido ? Math.round(Number(row.enganche_requerido) * 100) : null);
      var plazoM  = $plazo.length ? Number($plazo.val()) : (row.plazo_max || null);
      var engStr  = engPct != null ? engPct + '%' : '?';
      var plazoStr= plazoM != null ? plazoM + ' meses' : '? meses';
      applyDecision({
        seguimiento: 'aprobado',
        enganche_pct_aprobado: engPct,
        plazo_meses_aprobado:  plazoM,
        notas_admin: appendNote(row.notas_admin, notePrefix + 'Aprobar plazos: ' + engStr + ' / ' + plazoStr)
      }, 'Aprobar plazos');
    });
    $('#apDecContado').on('click', function(){
      applyDecision({
        seguimiento: 'ofrecer_contado',
        notas_admin: appendNote(row.notas_admin, 'Ofrecer contado únicamente')
      }, 'Ofrecer contado');
    });
    $('#apDecMSI').on('click', function(){
      if ($(this).is(':disabled')) return;
      if (!confirmOverrideIfNeeded('OFRECER 9 MSI')) return;
      var notePrefix = needsOverrideConfirm ? '⚠ OVERRIDE manual: ' : '';
      applyDecision({
        seguimiento: 'ofrecer_msi',
        notas_admin: appendNote(row.notas_admin, notePrefix + 'Ofrecer 9 MSI sin intereses')
      }, '9 MSI sin intereses');
    });
    $('#apDecReject').on('click', function(){
      if (!confirm('¿Rechazar esta solicitud?\n\nCliente: '+fullName+'\n\nEl status quedará como NO_VIABLE.')) return;
      applyDecision({
        status: 'NO_VIABLE',
        seguimiento: 'rechazado',
        notas_admin: appendNote(row.notas_admin, 'Rechazado en revisión manual'+(pldBlock?' (PLD MATCH)':''))
      }, 'Rechazar');
    });

    // ── Manual override controls (NEW FEATURE 2026-05-04) ──────────────
    // Live recompute weekly/monthly payment as the reviewer adjusts
    // enganche % and plazo. Same formula the customer-facing flow uses
    // (precio_contado × (1 - enganche_pct) over plazo_meses, no interest
    // because Voltika absorbs financing cost).
    function recomputeOverridePayment(){
      var $eng = $('#apOvEnganche');
      var $plazo = $('#apOvPlazo');
      if (!$eng.length || !$plazo.length) return;
      var engPct  = Number($eng.val()) || sysEnganchePct;
      var plazoM  = Number($plazo.val()) || sysPlazo;
      var enganche= precioContado * (engPct / 100);
      var financ  = precioContado - enganche;
      // Customer brief 2026-05-04 round 8: weekly/monthly figures must
      // match the canonical Excel calculation (saldos insolutos, 60%
      // anual + 16% IVA, 52 pagos/año). Earlier rev divided naïvely
      // without interest, producing numbers half the correct amount.
      // Formula mirrors VkCalculadora.calcular (configurador/js/modules/
      // calculadora-credito.js) so admin and customer side stay
      // aligned. Constants pinned to productos.js credito config.
      var tasaAnual    = 0.60;
      var pagosPorAno  = 52;
      var iva          = 0.16;
      var rPeriodoSinIVA = tasaAnual / pagosPorAno;
      var rPeriodoConIVA = rPeriodoSinIVA * (1 + iva);
      var numPagos       = Math.round(plazoM * (pagosPorAno / 12));
      var semanal = 0;
      if (financ > 0 && numPagos > 0) {
        if (rPeriodoConIVA === 0) {
          semanal = financ / numPagos;
        } else {
          semanal = financ * rPeriodoConIVA / (1 - Math.pow(1 + rPeriodoConIVA, -numPagos));
        }
      }
      var mensual = semanal * (pagosPorAno / 12); // ≈ semanal * 4.3333
      $('#apOvEngangePct').text(engPct + '%');
      $('#apOvEngancheMonto').text(fmtMoney(enganche));
      $('#apOvFinanciado').text(fmtMoney(financ));
      $('#apOvMensual').text(fmtMoney(Math.round(mensual)));
      $('#apOvSemanal').text(fmtMoney(Math.round(semanal)));
      $('#apOvSummary').text(engPct + '% / ' + plazoM + 'm');
    }
    $('#apOvEnganche').on('input change', recomputeOverridePayment);
    $('#apOvPlazo').on('change', recomputeOverridePayment);
    if ($('#apOvEnganche').length) recomputeOverridePayment();

    // Shared submit logic — calls the same endpoint with `preview=1` for
    // the test button. In preview mode the backend skips DB write +
    // email/SMS and just returns the signed URL.
    function submitOferta(preview){
      var engPct = Number($('#apOvEnganche').val()) || sysEnganchePct;
      var plazoM = Number($('#apOvPlazo').val()) || sysPlazo;
      if (!preview) {
        // Customer brief 2026-05-09: the customer offer email + landing
        // now show ONLY pago semanal (pago mensual was dropped to avoid
        // "you'll be charged monthly" misreads). Mirror that here so the
        // admin's pre-send confirmation matches what the customer
        // actually receives.
        var msg = '¿Enviar oferta personalizada al cliente?\n\n'
                + 'Cliente: '+fullName+'\n'
                + 'Modelo: '+(row.modelo||'—')+'\n'
                + '─────────────────────\n'
                + 'Enganche: '+engPct+'% ('+fmtMoney(precioContado*engPct/100)+')\n'
                + 'Plazo: '+plazoM+' meses\n'
                + 'Pago semanal: '+$('#apOvSemanal').text()+'\n'
                + '─────────────────────\n'
                + 'Sistema sugería: '+sysEnganchePct+'% / '+sysPlazo+' meses\n\n'
                + 'El enlace expira en 48h y los valores quedan bloqueados.';
        if (!confirm(msg)) return;
      }
      var $sendBtn = $('#apOvSendOffer');
      var $testBtn = $('#apOvTestLink');
      var $busy    = preview ? $testBtn : $sendBtn;
      $busy.prop('disabled', true).text(preview ? 'Generando...' : 'Enviando...');
      ADApp.api('preaprobaciones/enviar-oferta-personalizada.php', {
        id: row.id,
        enganche_pct: engPct / 100,
        plazo_meses:  plazoM,
        original_enganche: sysEnganchePct / 100,
        original_plazo:    sysPlazo,
        preview: preview ? 1 : 0
      }).done(function(r){
        if (r && r.ok) {
          if (preview) {
            // Open the customer landing in a new tab so the admin can
            // walk through it without leaving the modal. Also copy the
            // URL to clipboard for convenience.
            try { navigator.clipboard.writeText(r.link); } catch(e){}
            window.open(r.link, '_blank', 'noopener');
            $testBtn.prop('disabled', false).text('🧪 Probar (no enviar)');
          } else {
            var parts = [];
            if (r.email_sent) parts.push('✓ Email enviado');
            if (r.sms_sent)   parts.push('✓ SMS enviado');
            alert('Oferta personalizada enviada.\n\n' + parts.join('\n') + '\n\nEnlace (válido 48h):\n' + (r.link || ''));
            ADApp.closeModal(); load();
          }
        } else {
          alert('Error: ' + ((r && r.error) || 'desconocido'));
          $sendBtn.prop('disabled', false).text('📧 Enviar oferta (válida 48h)');
          $testBtn.prop('disabled', false).text('🧪 Probar (no enviar)');
        }
      }).fail(function(xhr){
        alert('Error: ' + (xhr.responseJSON && xhr.responseJSON.error || 'desconocido'));
        $sendBtn.prop('disabled', false).text('📧 Enviar oferta (válida 48h)');
        $testBtn.prop('disabled', false).text('🧪 Probar (no enviar)');
      });
    }
    $('#apOvSendOffer').on('click', function(){ submitOferta(false); });
    $('#apOvTestLink').on('click',  function(){ submitOferta(true);  });

    $('#apEditArchive').on('click', function(){
      if (!confirm('¿Archivar esta solicitud? (Se oculta del listado pero queda en BD)')) return;
      ADApp.api('preaprobaciones/eliminar.php', { id: row.id, modo: 'archivar' })
        .done(function(){ ADApp.closeModal(); load(); })
        .fail(function(xhr){ alert('Error: ' + (xhr.responseJSON && xhr.responseJSON.error || 'desconocido')); });
    });

    $('#apEditDelete').on('click', function(){
      var msg = '⚠️ ELIMINACIÓN PERMANENTE\n\nSolicitud de "' + fullName + '" será BORRADA de la base de datos.\n\nNo se puede deshacer (solo audit log).\n\nEscriba "ELIMINAR" para confirmar:';
      var typed = prompt(msg);
      if (typed === null) return;                         // Cancel
      if (String(typed).trim().toUpperCase() !== 'ELIMINAR') {
        alert('Texto incorrecto. Debes escribir exactamente: ELIMINAR');
        return;
      }
      ADApp.api('preaprobaciones/eliminar.php', { id: row.id, modo: 'eliminar' })
        .done(function(){ ADApp.closeModal(); load(); })
        .fail(function(xhr){ alert('Error: ' + (xhr.responseJSON && xhr.responseJSON.error || 'Solo admin puede eliminar permanentemente')); });
    });

    // ── Manual-review: send Truora link to lost lead ────────────────────
    $('#apSendTruoraLink').on('click', function(){
      if ($(this).is(':disabled')) return;
      var contactos = [row.email, row.telefono].filter(Boolean).join(' / ') || '(sin contacto)';
      if (!confirm('¿Enviar link de Truora a este cliente?\n\n' + contactos + '\n\nEl cliente recibirá un email y SMS con un enlace personal (válido 7 días) para completar la verificación de identidad sin volver a llenar el formulario.')) return;
      var $btn = $(this).prop('disabled', true).text('Enviando...');
      ADApp.api('preaprobaciones/enviar-truora-link.php', { id: row.id })
        .done(function(r){
          if (r && r.ok) {
            var parts = [];
            if (r.email_sent) parts.push('✓ Email enviado');
            if (r.sms_sent)   parts.push('✓ SMS enviado');
            if (!parts.length) parts.push('⚠ Sin canal disponible — copia el enlace manualmente');
            alert('Link enviado al cliente.\n\n' + parts.join('\n') + '\n\nEnlace:\n' + (r.recovery_url || ''));
            ADApp.closeModal(); load();
          } else {
            alert('Error: ' + ((r && r.error) || 'desconocido'));
            $btn.prop('disabled', false).text('📧 Enviar link de Truora');
          }
        })
        .fail(function(xhr){
          alert('Error: ' + (xhr.responseJSON && xhr.responseJSON.error || 'desconocido'));
          $btn.prop('disabled', false).text('📧 Enviar link de Truora');
        });
    });

    // ── Manual-review: promote fully-verified lead to Ventas ────────────
    $('#apSendToVentas').on('click', function(){
      if ($(this).is(':disabled')) return;
      var monto = row.precio_contado ? fmtMoney(row.precio_contado) : '';
      if (!confirm('¿Promover esta solicitud a Ventas y enviar enlace de pago al cliente?\n\nCliente: ' + fullName + '\nModelo: ' + (row.modelo || '—') + '\nMonto: ' + monto + '\n\nSe creará una orden "pendiente" y el cliente recibirá un email con el enlace de pago.')) return;
      var $btn = $(this).prop('disabled', true).text('Enviando...');
      ADApp.api('preaprobaciones/enviar-a-ventas.php', { id: row.id })
        .done(function(r){
          if (r && r.ok) {
            var parts = [];
            parts.push('Orden #' + (r.transaccion_id || '?') + ' creada');
            if (r.email_sent) parts.push('✓ Email de pago enviado');
            if (r.sms_sent)   parts.push('✓ SMS enviado');
            alert(r.message || 'Enviado a Ventas.\n\n' + parts.join('\n'));
            ADApp.closeModal(); load();
          } else {
            var msg = (r && r.message) || (r && r.error) || 'desconocido';
            if (r && r.detail) {
              var d = r.detail;
              msg += '\n\n' + (d.cdc_real ? '✓' : '✗') + ' CDC real'
                  + '\n' + (d.curp_match ? '✓' : '✗') + ' CURP match'
                  + '\n' + (d.truora_ok  ? '✓' : '✗') + ' Truora aprobado';
            }
            alert(msg);
            $btn.prop('disabled', false).text('💳 Enviar a Ventas para cobro');
          }
        })
        .fail(function(xhr){
          alert('Error: ' + (xhr.responseJSON && xhr.responseJSON.error || 'desconocido'));
          $btn.prop('disabled', false).text('💳 Enviar a Ventas para cobro');
        });
    });
  }

  function summaryCard(label, value) {
    return '<div style="text-align:center"><div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;opacity:0.7;margin-bottom:4px">'+esc(label)+'</div>'
         + '<div style="font-size:20px;font-weight:800">'+esc(value)+'</div></div>';
  }

  // Mockup helper: coloured alert row for the Indicadores Críticos
  // section. variant=good→green, bad→red, warn→amber, neutral→grey.
  function alertRow(label, value, variant) {
    var styles = {
      good:    { bg:'#e8f5e8', tx:'#1a6b1a' },
      bad:     { bg:'#fde8e8', tx:'#8b1a1a' },
      warn:    { bg:'#fff4d6', tx:'#7a5800' },
      neutral: { bg:'#f3f4f6', tx:'#6b7280' }
    };
    var s = styles[variant] || styles.neutral;
    return '<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;margin:6px 0;border-radius:8px;font-size:13px;background:'+s.bg+';color:'+s.tx+';">'
         + '<span>'+esc(label)+'</span><strong style="text-align:right;">'+value+'</strong></div>';
  }

  // alertRow variant where the value is already pre-rendered HTML (e.g.
  // a row of code badges) — bypasses the strong-wrap.
  function alertRowCustom(label, valueHtml, variant) {
    var styles = {
      good:    { bg:'#e8f5e8', tx:'#1a6b1a' },
      bad:     { bg:'#fde8e8', tx:'#8b1a1a' },
      warn:    { bg:'#fff4d6', tx:'#7a5800' },
      neutral: { bg:'#f3f4f6', tx:'#6b7280' }
    };
    var s = styles[variant] || styles.neutral;
    return '<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;margin:6px 0;border-radius:8px;font-size:13px;background:'+s.bg+';color:'+s.tx+';">'
         + '<span>'+esc(label)+'</span><span>'+valueHtml+'</span></div>';
  }

  function decisionBtn(id, label, color, enabled) {
    var bg = enabled ? color : '#cbd5e1';
    var cursor = enabled ? 'pointer' : 'not-allowed';
    var op = enabled ? '1' : '0.6';
    return '<button id="'+id+'"' + (enabled?'':' disabled')
         + ' style="padding:14px;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:'+cursor+';color:#fff;background:'+bg+';opacity:'+op+';transition:opacity 0.2s;">'
         + esc(label) + '</button>';
  }

  // Render score-reason badges. Uses the real CDC codes from
  // consultas_buro.score_reasons if present (customer brief 2026-05-04
  // — "the query information [should be visible]"). Falls back to the
  // heuristic `inferScoreReasons` for old rows that ran before raw
  // capture was added.
  function renderScoreReasonBadges(row, risk) {
    var raw = row.buro_score_reasons || '';
    if (raw && typeof raw === 'string') {
      var codes = raw.split(/[,\s]+/).filter(Boolean);
      if (codes.length) {
        var styleByVariant = {
          neutral: 'background:#fff;border:1px solid #b5c5d8;color:#1a4b7a;',
          bad:     'background:#fde8e8;border:1px solid #f5b5b5;color:#8b1a1a;',
          warn:    'background:#fff4d6;border:1px solid #f0d68a;color:#7a5800;'
        };
        // Heuristic: codes starting with D/M/P/U/R/Y indicate delinquency
        // and render as bad; T-prefix indicates time-related markers
        // (warn); everything else neutral. This mirrors CDC's general
        // family-letter taxonomy without needing a full code table.
        var html = '<span style="display:inline-flex;gap:6px;flex-wrap:wrap;">';
        codes.forEach(function(c){
          var first = c.charAt(0).toUpperCase();
          var v = (first === 'D' || first === 'M' || first === 'P' || first === 'U' || first === 'R' || first === 'Y')
            ? 'bad'
            : (first === 'T' ? 'warn' : 'neutral');
          html += '<span style="padding:3px 8px;border-radius:4px;font-size:11px;font-family:monospace;font-weight:600;'
                + styleByVariant[v] + '">' + esc(c) + '</span>';
        });
        html += '</span>';
        return html;
      }
    }
    return inferScoreReasons(row, risk);
  }

  // Heuristic mapping from preaprobacion-v3.php's reasons[] to the score-
  // code badges shown in the mockup. We don't currently store the raw
  // CDC score-reason codes, so this is an approximation:
  //   - thin file / no morosidad: E0 G1 J0 T5 (informational, neutral)
  //   - severe morosidad: D8 P1 M5 T5 (red)
  //   - sin score: NS (no score)
  function inferScoreReasons(row, risk) {
    var codes = [];
    if (risk === 'danger' && (row.dpd90_flag == 1 || row.buro_dpd90_flag == 1)) {
      codes = [['D8','bad'],['P1','bad'],['M5','bad'],['T5','warn']];
    } else if (row.circulo_source === 'cdc_sin_score' || row.circulo_source === 'estimado') {
      codes = [['NS','neutral'],['THIN','warn']];
    } else if (Number(row.score||0) > 0 && Number(row.score) < 600) {
      codes = [['E0','neutral'],['G1','neutral'],['J0','neutral'],['T5','warn']];
    } else {
      codes = [['—','neutral']];
    }
    var styleByVariant = {
      neutral: 'background:#fff;border:1px solid #b5c5d8;color:#1a4b7a;',
      bad:     'background:#fde8e8;border:1px solid #f5b5b5;color:#8b1a1a;',
      warn:    'background:#fff4d6;border:1px solid #f0d68a;color:#7a5800;'
    };
    var html = '<span style="display:inline-flex;gap:6px;flex-wrap:wrap;">';
    codes.forEach(function(c){
      html += '<span style="padding:3px 8px;border-radius:4px;font-size:11px;font-family:monospace;font-weight:600;'+(styleByVariant[c[1]]||styleByVariant.neutral)+'">'+esc(c[0])+'</span>';
    });
    html += '</span>';
    return html;
  }

  // Number of red flags driving the "NO RECOMENDADO" banner.
  function countBanderasRojas(row) {
    var n = 0;
    if (row.dpd90_flag == 1 || row.buro_dpd90_flag == 1) n++;
    if (Number(row.pti_total||0) > 0.5) n++;
    if (Number(row.score||0) > 0 && Number(row.score) < 500) n++;
    if (row.dpd_max && Number(row.dpd_max) >= 90) n++;
    return Math.max(1, n);
  }

  // Build the system recommendation panel. Returns an object with
  // { summary, bullets[], action } so the renderer can paint a
  // consistent box across safe/warn/danger cases.
  function buildRecomendacion(row, risk, hasDPD90, ptiPct, scoreNum) {
    var bullets = [];
    if (hasDPD90) {
      bullets.push({ label: 'Cuentas con DPD90 activo', detail: 'está fallando en pagos hoy' });
    }
    if (ptiPct >= 80) {
      bullets.push({ label: 'PTI '+ptiPct+'%', detail: 'gasta casi todo su ingreso en deudas' });
    } else if (ptiPct >= 50) {
      bullets.push({ label: 'PTI '+ptiPct+'%', detail: 'sobreutilización crítica' });
    }
    if (scoreNum > 0 && scoreNum < 500) {
      bullets.push({ label: 'Score '+scoreNum, detail: 'morosidad significativa en historial' });
    }

    // Customer brief 2026-05-06 — Truora rejection: explicit hard block.
    var truoraStatus = String(row.truora_status || '').toLowerCase();
    var truoraRejected = (truoraStatus === 'failure' || truoraStatus === 'rejected' || truoraStatus === 'denied');
    if (truoraRejected) {
      return {
        summary: 'Verificación de identidad rechazada por Truora. No se puede aprobar el crédito.',
        bullets: [{ label: 'Truora '+truoraStatus, detail: 'la identidad del solicitante no fue verificada — la aprobación de crédito requiere verificación exitosa' }],
        action: 'Rechazar la solicitud o pedir al cliente que reintente la verificación. No proceder con aprobación de crédito mientras Truora siga en estado rechazado.'
      };
    }

    // Customer brief 2026-05-06 — CDC unreachable (source='estimado'):
    // the algorithm fell back to PTI-only. Treat as conservative
    // CONDITIONAL — admin should approve with high enganche or retry CDC.
    if (row.circulo_source === 'estimado') {
      return {
        summary: 'No se pudo consultar Círculo de Crédito.',
        bullets: [{ label: 'CDC sin respuesta', detail: 'el algoritmo cayó al fallback PTI-only, no hay score real' }],
        action: 'Aprobar con condiciones conservadoras (50% enganche, 12 meses) o reintentar consulta.'
      };
    }

    if (risk === 'danger') {
      return {
        summary: 'Cliente sobreendeudado o con morosidad activa. Razones detectadas:',
        bullets: bullets.length ? bullets : [{ label: 'Status NO_VIABLE', detail: 'el sistema marcó esta solicitud como no aprobable' }],
        action: 'Rechazar plazos. Ofrecer pago contado únicamente (no MSI con tarjeta — su tarjeta probablemente está saturada).'
      };
    }
    if (risk === 'warn') {
      return {
        summary: 'Solicitud condicional. Aprobar con enganche aumentado y/o plazo reducido.',
        bullets: bullets,
        action: 'Aprobar con '+(row.enganche_requerido?Math.round(row.enganche_requerido*100)+'% enganche':'enganche aumentado')+' y plazo máximo de '+(row.plazo_max||'12')+' meses.'
      };
    }
    // safe case
    var safeMsg = 'Aprobar plazos con '+(row.enganche_requerido?Math.round(row.enganche_requerido*100)+'% enganche':'enganche estándar')+' / '+(row.plazo_max||12)+' meses.';
    if (scoreNum > 0 && scoreNum < 600) safeMsg += ' Score bajo se explica por archivo delgado (E0, G1, J0), no por morosidad. Sin DPD90 actual ni histórico. PTI total sano.';
    return { summary: safeMsg, bullets: [], action: '' };
  }

  // Append a timestamped audit line to existing notas without losing
  // anything. Returns the new full notas string for the API call.
  function appendNote(existing, line) {
    var ts = new Date().toISOString().slice(0,16).replace('T',' ');
    var prefix = existing ? (existing+'\n') : '';
    return prefix + '['+ts+'] '+line;
  }
  function sectionTitle(icon, txt) {
    return '<div style="font-size:13px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;border-bottom:2px solid #e5e7eb;padding-bottom:8px;margin-bottom:12px">'+icon+' '+esc(txt)+'</div>';
  }
  function dataRow(label, value) {
    return '<div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #f3f4f6;font-size:13px">'
         + '<span style="color:#6b7280">'+esc(label)+'</span>'
         + '<span style="font-weight:600;color:#111827;text-align:right">'+value+'</span></div>';
  }
  function sourceLabel(s) {
    if (s === 'real') return '<span style="color:#10b981;font-weight:700">CDC real</span>';
    if (s === 'estimado') return '<span style="color:#d97706;font-weight:700">Score estimado</span>';
    return esc(s || '—');
  }

  // Customer brief 2026-05-02: detailed Truora outcome rendering. Six
  // possible states surfaced from verificaciones_identidad + the legacy
  // truora_ok bool:
  //   1. Verified (truora_approved=1 AND curp_match=1)
  //   2. Verified (legacy — truora_ok=1, no detail row available)
  //   3. CURP mismatch (truora_approved=0, curp_match=0) → user-recoverable
  //   4. Failed (truora_approved=0, declined_reason set)
  //   5. In progress (truora_status=in_progress / pending)
  //   6. Never attempted (no row in verificaciones_identidad)
  function truoraStatusBadge(row) {
    var approved = row.truora_approved;        // null = no verificaciones_identidad row
    var curpM    = row.curp_match;              // null = unknown / never compared
    var status   = (row.truora_status || '').toLowerCase();
    var legacyOk = (row.truora_ok == 1);
    var hasViRow = !!row.truora_process_id;     // truthy iff JOIN matched

    // ── Priority 1: no verificaciones_identidad row at all ─────────────
    // approved/curp_match come back as NULL when there is no row. Without
    // this short-circuit, `(int)NULL === 0` would falsely classify the
    // row as "Rechazado". Either show "Verificado (legacy)" if the old
    // truora_ok=1 flag is set, or "No iniciado" otherwise.
    if (!hasViRow) {
      if (legacyOk) {
        return '<span style="color:#10b981;font-weight:700">✓ Verificado</span>'
             + ' <span style="color:#6b7280;font-size:11px">(legacy)</span>';
      }
      return '<span style="color:#9ca3af;font-weight:600">— No iniciado</span>';
    }

    // ── Priority 2: explicit verified ──────────────────────────────────
    if (approved == 1 && curpM == 1) {
      return '<span style="color:#10b981;font-weight:700">✓ Verificado</span>'
           + (row.truora_updated_at ? ' <span style="color:#6b7280;font-size:11px">' + esc(String(row.truora_updated_at).slice(0,16).replace('T',' ')) + '</span>' : '');
    }

    // ── Priority 3: CURP mismatch (user-recoverable) ──────────────────
    // Only fires when curp_match is EXPLICITLY 0 — not null/undefined.
    if (curpM === 0 || curpM === '0') {
      return '<span style="color:#dc2626;font-weight:700">✗ CURP no coincide</span>';
    }

    // ── Priority 4: in-progress ───────────────────────────────────────
    if (status === 'in_progress' || status === 'pending') {
      return '<span style="color:#f59e0b;font-weight:700">⏳ En proceso</span>';
    }

    // ── Priority 5: explicit rejection ────────────────────────────────
    // approved must be EXPLICITLY 0 (not null) to count as rejected.
    if (approved === 0 || approved === '0') {
      return '<span style="color:#dc2626;font-weight:700">✗ Rechazado</span>';
    }

    // Fallback — has a process_id but state is ambiguous
    return '<span style="color:#9ca3af;font-weight:600">— Sin estado</span>';
  }

  // Customer brief 2026-05-09: external entry point used by the
  // "Documentos del pedido" modal in admin-ventas.js. Lets the
  // Identidad / CURP / Capacidad rows jump directly into this panel
  // with the phone/email pre-filled — no JSON-API leak via listar.php.
  function search(term){
    filters.search = term || '';
    filters.page = 1;
    if (typeof load === 'function') load();
    // After paint runs the input may have been re-rendered; sync it.
    setTimeout(function(){ $('#apFSearch').val(filters.search); }, 200);
  }

  return { render: render, search: search };
})();
