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
    var color = { PREAPROBADO: '#10b981', CONDICIONAL: '#d97706', NO_VIABLE: '#dc2626' }[s] || '#6b7280';
    return '<span style="background:'+color+';color:#fff;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:700">'+esc(s)+'</span>';
  }

  function segBadge(s){
    var styles = {
      nuevo:       'background:#fef3c7;color:#78350f',
      contactado:  'background:#dbeafe;color:#1e40af',
      vendido:     'background:#d1fae5;color:#065f46',
      descartado:  'background:#f3f4f6;color:#6b7280'
    };
    var st = styles[s] || styles.nuevo;
    return '<span style="'+st+';padding:3px 10px;border-radius:4px;font-size:11px;font-weight:700">'+esc(s||'nuevo')+'</span>';
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
    ['','nuevo','contactado','vendido','descartado'].forEach(function(s){
      html += '<option value="'+s+'"'+(filters.seguimiento===s?' selected':'')+'>'+(s||'Todo seguimiento')+'</option>';
    });
    html += '</select>';
    html += '<select id="apFSource" style="padding:8px;border:1px solid #ddd;border-radius:4px;">';
    ['','real','estimado'].forEach(function(s){
      html += '<option value="'+s+'"'+(filters.source===s?' selected':'')+'>'+(s||'Todo source')+'</option>';
    });
    html += '</select>';
    html += '<button id="apFApply" class="ad-btn">Filtrar</button>';
    html += '</div>';

    // Tabla
    html += '<div class="ad-table-wrap"><table class="ad-table"><thead><tr>';
    html += '<th>Fecha</th><th>Cliente</th><th>Email / Teléfono</th><th>Modelo</th><th>Status</th><th>Score</th><th>Source</th><th>Eng req</th><th>Plazo</th><th>Seguimiento</th><th></th>';
    html += '</tr></thead><tbody>';

    if (!r.rows || !r.rows.length) {
      html += '<tr><td colspan="11" style="text-align:center;padding:30px;color:var(--ad-dim);">Sin solicitudes</td></tr>';
    } else r.rows.forEach(function(row){
      var fullName = [row.nombre, row.apellido_paterno, row.apellido_materno].filter(Boolean).join(' ') || '—';
      var contacto = (row.email || '') + (row.telefono ? '<br><small>'+esc(row.telefono)+'</small>' : '');
      var scoreCell = row.score ? row.score : (row.synth_score ? row.synth_score+' <small style="color:#d97706">(sint.)</small>' : '—');
      var fechaCorta = (row.freg || '').substring(0, 16);

      html += '<tr>';
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
    html += '</tbody></table></div>';

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
  }

  function showDetail(id, rows){
    var row = (rows || []).find(function(r){ return r.id == id; });
    if (!row) return;
    var fullName = [row.nombre, row.apellido_paterno, row.apellido_materno].filter(Boolean).join(' ') || '—';
    var html = '<h2>Solicitud #'+row.id+' — '+esc(fullName)+'</h2>';
    html += '<table border="1" cellpadding="6" style="border-collapse:collapse;width:100%;font-size:13px;margin:10px 0">';
    html += '<tr><td><strong>Email</strong></td><td>'+esc(row.email||'—')+'</td>';
    html += '<td><strong>Teléfono</strong></td><td>'+esc(row.telefono||'—')+'</td></tr>';
    html += '<tr><td><strong>Fecha nac.</strong></td><td>'+esc(row.fecha_nacimiento||'—')+'</td>';
    html += '<td><strong>CP</strong></td><td>'+esc(row.cp||'—')+'</td></tr>';
    html += '<tr><td><strong>Ciudad / Estado</strong></td><td colspan="3">'+esc((row.ciudad||'—')+' / '+(row.estado||'—'))+'</td></tr>';
    html += '<tr><td colspan="4" style="background:#f8fafc"><strong>Crédito solicitado</strong></td></tr>';
    html += '<tr><td><strong>Modelo</strong></td><td>'+esc(row.modelo||'—')+'</td>';
    html += '<td><strong>Precio</strong></td><td>'+fmtMoney(row.precio_contado)+'</td></tr>';
    html += '<tr><td><strong>Ingreso mensual</strong></td><td>'+fmtMoney(row.ingreso_mensual)+'</td>';
    html += '<td><strong>Pago semanal</strong></td><td>'+fmtMoney(row.pago_semanal)+'</td></tr>';
    html += '<tr><td><strong>PTI</strong></td><td>'+fmtPct(row.pti_total)+'</td>';
    html += '<td><strong>Score</strong></td><td>'+(row.score||row.synth_score||'—')+(row.synth_score?' <small>(sintético)</small>':'')+'</td></tr>';
    html += '<tr><td colspan="4" style="background:#f8fafc"><strong>Decisión</strong></td></tr>';
    html += '<tr><td><strong>Status</strong></td><td>'+statusBadge(row.status)+'</td>';
    html += '<td><strong>Source</strong></td><td>'+esc(row.circulo_source||'—')+'</td></tr>';
    html += '<tr><td><strong>Enganche req.</strong></td><td>'+fmtPct(row.enganche_requerido)+'</td>';
    html += '<td><strong>Plazo máx</strong></td><td>'+(row.plazo_max||'—')+' meses</td></tr>';
    html += '<tr><td><strong>Truora ID OK</strong></td><td colspan="3">'+(row.truora_ok==1?'✅ Sí':'❌ No / desconocido')+'</td></tr>';
    html += '</table>';

    html += '<h3>Seguimiento</h3>';
    html += '<select id="apEditSeg" style="padding:8px;width:100%;margin-bottom:10px">';
    ['nuevo','contactado','vendido','descartado'].forEach(function(s){
      html += '<option value="'+s+'"'+(row.seguimiento===s?' selected':'')+'>'+s+'</option>';
    });
    html += '</select>';
    html += '<textarea id="apEditNotas" rows="4" placeholder="Notas (visita, llamada, etc)" style="width:100%;padding:8px;">'+esc(row.notas_admin||'')+'</textarea>';
    html += '<div style="margin-top:10px;display:flex;gap:8px">';
    html += '<button id="apEditSave" class="ad-btn">Guardar</button>';
    html += '<button id="apEditClose" class="ad-btn ghost">Cerrar</button>';
    html += '</div>';

    ADApp.modal(html);

    $('#apEditSave').on('click', function(){
      ADApp.api('preaprobaciones/actualizar.php', {
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
          id: row.id,
          seguimiento: $('#apEditSeg').val(),
          notas_admin: $('#apEditNotas').val()
        })
      }).done(function(){
        ADApp.closeModal();
        load();
      });
    });
    $('#apEditClose').on('click', function(){ ADApp.closeModal(); });
  }

  return { render: render };
})();
