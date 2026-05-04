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
    // Three buckets drive header colour + recommendation copy + button
    // state:
    //   safe:    no DPD90, PTI < 35%, status PREAPROBADO/CONDICIONAL
    //   warn:    PTI 35-50% OR thin file with no morosidad
    //   danger:  DPD90 active OR PTI > 50% OR status NO_VIABLE
    var pti        = Number(row.pti_total || 0);
    var ptiPct     = Math.round(pti * 100);
    var hasDPD90   = (row.dpd90_flag == 1) || (row.buro_dpd90_flag == 1);
    var dpdMax     = Number(row.dpd_max || row.buro_dpd_max || 0);
    var status     = String(row.status || '').toUpperCase();
    var scoreNum   = Number(row.score || row.synth_score || 0);
    var risk = 'safe';
    if (status === 'NO_VIABLE' || hasDPD90 || pti > 0.50) risk = 'danger';
    else if (status === 'CONDICIONAL' || pti > 0.35) risk = 'warn';

    var theme = ({
      safe:   { hbg:'#e8f5e8', htext:'#1a6b1a', haccent:'#1a6b1a', headerLabel:'PLAZO MÁXIMO', headerLabelColor:'#1a4b1a' },
      warn:   { hbg:'#fff4d6', htext:'#7a5800', haccent:'#c89a3a', headerLabel:'PLAZO MÁXIMO', headerLabelColor:'#5a4000' },
      danger: { hbg:'#fce8e8', htext:'#5a1a1a', haccent:'#8b1a1a', headerLabel:'PLAZO MÁXIMO', headerLabelColor:'#8b3a3a' }
    })[risk];

    var html = '';

    // ── 1. Risk header banner ─────────────────────────────────────────
    html += '<div style="background:'+theme.hbg+';color:'+theme.htext+';padding:24px 20px;text-align:center;margin:-20px -20px 0 -20px;border-radius:12px 12px 0 0;">';
    if (risk === 'danger') {
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

    // Identity strip
    html += '<div style="background:#fafafa;padding:10px 16px;font-size:12px;color:#666;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin:0 -20px;">';
    html += '<div><strong style="color:#333">'+esc(fullName)+'</strong> · #'+row.id+'</div>';
    html += '<div>Status: <strong style="color:'+theme.haccent+'">'+esc(row.status||'—')+'</strong></div>';
    html += '</div>';

    // ── 2. Indicadores Críticos ───────────────────────────────────────
    html += '<div style="padding:18px 8px 6px;">';
    html += '<div style="font-size:11px;letter-spacing:1.2px;color:#666;text-transform:uppercase;margin-bottom:12px;font-weight:700;">⚠️ Indicadores Críticos</div>';
    // PLD Check — we don't capture PLD hits in the DB yet. CDC API DOES
    // return PLD info but it's not parsed/stored. Until that's wired,
    // show the conservative "—" so admin doesn't think a missing check
    // means "passed".
    html += alertRow('PLD Check', '— No verificado en este sistema', 'neutral');
    // DPD90 actual
    if (hasDPD90) {
      html += alertRow('DPD90 actual', '✗ '+(row.buro_num_cuentas||row.dpd_max||'?')+' cuentas con mora activa', 'bad');
    } else {
      html += alertRow('DPD90 actual', '✓ Ninguna cuenta activa con mora', 'good');
    }
    // Vencido / Aprobado — we don't store these totals yet
    html += alertRow('Vencido / Aprobado', '— Datos detallados no disponibles', 'neutral');
    // Razones de score — encoded in preaprobacion-v3 reasons field; not
    // surfaced to listar.php yet. Approximate from status: thin file
    // markers (E0/G1/J0/T5) for safe-with-no-score, severe markers for
    // danger.
    var razones = inferScoreReasons(row, risk);
    html += alertRowCustom('Razones de score', razones, risk === 'danger' ? 'bad' : 'good');
    html += '</div>';

    // ── 3. Resumen Buró ───────────────────────────────────────────────
    html += '<div style="padding:18px 8px;border-top:1px solid #eee;">';
    html += '<div style="font-size:11px;letter-spacing:1.2px;color:#666;text-transform:uppercase;margin-bottom:12px;font-weight:700;">📊 Resumen Buró</div>';
    html += dataRow('Aprobado total',          '<span style="color:#999">—</span>');
    html += dataRow('Vencido total',           '<span style="color:#999">—</span>');
    html += dataRow('Pago mensual requerido',  fmtMoney(row.buro_pago_mensual || row.pago_mensual_buro));
    var cuentasTxt = (row.buro_num_cuentas != null ? row.buro_num_cuentas : '—') + ' / ' + (row.dpd_max || row.buro_dpd_max || '0');
    html += dataRow('Cuentas activas / DPD90 histórico', cuentasTxt);
    html += dataRow('Crédito más antiguo',     '<span style="color:#999">—</span>');
    html += dataRow('Consultas últimos 6 meses','<span style="color:#999">—</span>');
    if (row.buro_folio) {
      html += dataRow('Folio CDC', '<code style="font-size:11px;color:#666">'+esc(row.buro_folio)+'</code>');
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
    html += '<div style="padding:18px 8px;border-top:1px solid #eee;">';
    html += '<div style="font-size:11px;letter-spacing:1.2px;color:#666;text-transform:uppercase;margin-bottom:12px;font-weight:700;">🛵 Crédito Solicitado</div>';
    html += dataRow('Modelo',           (row.modelo||'—') + (row.precio_contado ? ' — '+fmtMoney(row.precio_contado) : ''));
    html += dataRow('Ingreso mensual',  fmtMoney(row.ingreso_mensual));
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

    // ── 7. Decision action buttons ───────────────────────────────────
    // Smart disable: when DPD90 active or status NO_VIABLE the safe
    // approve options grey out. Admin can still override but the visual
    // signal mirrors the recommendation.
    var canApprove = !hasDPD90 && status !== 'NO_VIABLE' && pti < 0.50;
    var canMSI     = canApprove;  // same rules — MSI requires healthy file
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:16px 8px;background:#fafafa;border-radius:8px;margin:8px;">';
    html += decisionBtn('apDecApprove', '✓ Aprobar Plazos',  '#1a6b1a', canApprove);
    html += decisionBtn('apDecContado', '$ Ofrecer Contado', '#1a4b7a', true);
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
    $('#apDecApprove').on('click', function(){
      if ($(this).is(':disabled')) return;
      applyDecision({
        seguimiento: 'aprobado',
        notas_admin: appendNote(row.notas_admin, 'Aprobar plazos: '+(row.enganche_requerido?Math.round(row.enganche_requerido*100)+'%/':'')+(row.plazo_max||'?')+' meses')
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
      applyDecision({
        seguimiento: 'ofrecer_msi',
        notas_admin: appendNote(row.notas_admin, 'Ofrecer 9 MSI sin intereses')
      }, '9 MSI sin intereses');
    });
    $('#apDecReject').on('click', function(){
      if (!confirm('¿Rechazar esta solicitud?\n\nCliente: '+fullName+'\n\nEl status quedará como NO_VIABLE.')) return;
      applyDecision({
        status: 'NO_VIABLE',
        seguimiento: 'rechazado',
        notas_admin: appendNote(row.notas_admin, 'Rechazado en revisión manual')
      }, 'Rechazar');
    });

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

  return { render: render };
})();
