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
      nuevo:      { bg:'#fef3c7', tx:'#78350f', dot:'#f59e0b', lbl:'Nuevo' },
      contactado: { bg:'#dbeafe', tx:'#1e40af', dot:'#3b82f6', lbl:'Contactado' },
      vendido:    { bg:'#d1fae5', tx:'#065f46', dot:'#10b981', lbl:'Vendido' },
      descartado: { bg:'#f3f4f6', tx:'#6b7280', dot:'#9ca3af', lbl:'Descartado' }
    };
    var theme = themes[s] || themes.nuevo;
    return '<span style="background:'+theme.bg+';color:'+theme.tx+';padding:3px 10px 3px 8px;border-radius:12px;font-size:11px;font-weight:600;display:inline-flex;align-items:center;gap:6px">'
         + '<span style="width:7px;height:7px;border-radius:50%;background:'+theme.dot+';display:inline-block"></span>'
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

    // Tabla
    html += '<div class="ad-table-wrap"><table class="ad-table"><thead><tr>';
    html += '<th style="width:30px"><input type="checkbox" id="apCheckAll"></th>';
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
      ADApp.api('preaprobaciones/eliminar.php', {
        method:'POST', contentType:'application/json',
        data: JSON.stringify({ ids: ids, modo: 'archivar' })
      }).done(function(){ load(); });
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
      ADApp.api('preaprobaciones/eliminar.php', {
        method:'POST', contentType:'application/json',
        data: JSON.stringify({ ids: ids, modo: 'eliminar' })
      }).done(function(){ load(); }).fail(function(xhr){
        alert('Error: ' + (xhr.responseJSON && xhr.responseJSON.error || 'desconocido'));
      });
    });
  }

  function showDetail(id, rows){
    var row = (rows || []).find(function(r){ return r.id == id; });
    if (!row) return;
    var fullName = [row.nombre, row.apellido_paterno, row.apellido_materno].filter(Boolean).join(' ') || 'Sin nombre';

    // Status color theme
    var statusColors = {
      PREAPROBADO: { bg: '#10b981', text: '#fff', light: '#d1fae5', dark: '#065f46' },
      CONDICIONAL: { bg: '#d97706', text: '#fff', light: '#fef3c7', dark: '#78350f' },
      NO_VIABLE:   { bg: '#dc2626', text: '#fff', light: '#fee2e2', dark: '#991b1b' }
    };
    var color = statusColors[row.status] || statusColors.NO_VIABLE;

    var html = '';
    // ── Header banner ─────────────────────────────────────────────────────
    html += '<div style="background:linear-gradient(135deg,'+color.bg+',#0ea5e9);color:#fff;padding:24px 28px;border-radius:12px 12px 0 0;margin:-20px -20px 0 -20px">';
    html += '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">';
    html += '<div><div style="font-size:13px;opacity:0.85;margin-bottom:4px">SOLICITUD #'+row.id+'</div>';
    html += '<div style="font-size:24px;font-weight:800;line-height:1.2">'+esc(fullName)+'</div>';
    html += '<div style="font-size:13px;opacity:0.85;margin-top:6px">'+esc(row.email||'sin email')+(row.telefono?' · '+esc(row.telefono):'')+'</div></div>';
    html += '<div style="background:#fff;color:'+color.bg+';padding:8px 16px;border-radius:6px;font-weight:800;font-size:14px;letter-spacing:0.5px">'+esc(row.status)+'</div>';
    html += '</div></div>';

    // ── Decision summary cards ─────────────────────────────────────────────
    html += '<div style="background:'+color.light+';color:'+color.dark+';padding:18px 28px;display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin:0 -20px 20px -20px">';
    html += summaryCard('Enganche requerido', row.enganche_requerido ? Math.round(row.enganche_requerido*100)+'%' : '—');
    html += summaryCard('Plazo máximo', (row.plazo_max||'—')+' meses');
    html += summaryCard('PTI', fmtPct(row.pti_total));
    html += summaryCard('Score', (row.score || row.synth_score || '—') + (row.synth_score && !row.score ? ' (est.)' : ''));
    html += '</div>';

    // ── Two-column body ──────────────────────────────────────────────────
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;padding:0 8px">';

    // Persona
    html += '<div>';
    html += sectionTitle('👤', 'Datos personales');
    html += dataRow('Email', row.email || '—');
    html += dataRow('Teléfono', row.telefono || '—');
    html += dataRow('Fecha nac.', row.fecha_nacimiento || '—');
    html += dataRow('CP', row.cp || '—');
    html += dataRow('Ciudad', row.ciudad || '—');
    html += dataRow('Estado', row.estado || '—');
    html += '</div>';

    // Crédito
    html += '<div>';
    html += sectionTitle('🏍', 'Crédito solicitado');
    html += dataRow('Modelo', row.modelo || '—');
    html += dataRow('Precio moto', fmtMoney(row.precio_contado));
    html += dataRow('Ingreso mensual', fmtMoney(row.ingreso_mensual));
    html += dataRow('Pago semanal', fmtMoney(row.pago_semanal));
    html += dataRow('Pago mensual', fmtMoney(row.pago_mensual));
    html += dataRow('Source', sourceLabel(row.circulo_source));
    html += dataRow('Truora ID', row.truora_ok == 1 ? '<span style="color:#10b981;font-weight:700">✓ Verificado</span>' : '<span style="color:#dc2626">✗ No verificado</span>');
    html += '</div>';

    html += '</div>'; // end grid

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

    // ── Action bar ───────────────────────────────────────────────────────
    html += '<div style="display:flex;gap:10px;justify-content:space-between;margin-top:16px;padding:0 8px;flex-wrap:wrap">';
    html += '<div style="display:flex;gap:8px">';
    html += '<button id="apEditArchive" style="padding:8px 14px;background:#fef3c7;color:#78350f;border:1px solid #f59e0b;border-radius:6px;font-weight:600;cursor:pointer;font-size:13px">📁 Archivar</button>';
    html += '<button id="apEditDelete" style="padding:8px 14px;background:#fee2e2;color:#991b1b;border:1px solid #dc2626;border-radius:6px;font-weight:600;cursor:pointer;font-size:13px">🗑 Eliminar</button>';
    html += '</div>';
    html += '<div style="display:flex;gap:10px">';
    html += '<button id="apEditClose" style="padding:10px 22px;background:#fff;color:#374151;border:1px solid #d1d5db;border-radius:6px;font-weight:600;cursor:pointer">Cerrar</button>';
    html += '<button id="apEditSave" style="padding:10px 22px;background:'+color.bg+';color:#fff;border:none;border-radius:6px;font-weight:700;cursor:pointer">💾 Guardar cambios</button>';
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
      ADApp.api('preaprobaciones/actualizar.php', {
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ id: row.id, seguimiento: seg, notas_admin: $('#apEditNotas').val() })
      }).done(function(){ ADApp.closeModal(); load(); });
    });
    $('#apEditClose').on('click', function(){ ADApp.closeModal(); });

    $('#apEditArchive').on('click', function(){
      if (!confirm('¿Archivar esta solicitud? (Se oculta del listado pero queda en BD)')) return;
      ADApp.api('preaprobaciones/eliminar.php', {
        method:'POST', contentType:'application/json',
        data: JSON.stringify({ id: row.id, modo: 'archivar' })
      }).done(function(){ ADApp.closeModal(); load(); });
    });

    $('#apEditDelete').on('click', function(){
      var msg = '⚠️ ELIMINACIÓN PERMANENTE\n\nSolicitud de "' + fullName + '" será BORRADA de la base de datos.\n\nNo se puede deshacer (solo audit log).\n\nEscriba "ELIMINAR" para confirmar:';
      var typed = prompt(msg);
      if (typed === null) return;                         // Cancel
      if (String(typed).trim().toUpperCase() !== 'ELIMINAR') {
        alert('Texto incorrecto. Debes escribir exactamente: ELIMINAR');
        return;
      }
      ADApp.api('preaprobaciones/eliminar.php', {
        method:'POST', contentType:'application/json',
        data: JSON.stringify({ id: row.id, modo: 'eliminar' })
      }).done(function(){ ADApp.closeModal(); load(); }).fail(function(xhr){
        alert('Error: ' + (xhr.responseJSON && xhr.responseJSON.error || 'Solo admin puede eliminar permanentemente'));
      });
    });
  }

  function summaryCard(label, value) {
    return '<div style="text-align:center"><div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;opacity:0.7;margin-bottom:4px">'+esc(label)+'</div>'
         + '<div style="font-size:20px;font-weight:800">'+esc(value)+'</div></div>';
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

  return { render: render };
})();
