window.AD_alertas = (function(){
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  // Compact SVG icon set — avoids emoji rendering inconsistencies across browsers
  var _iconMap = {
    inventario: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
    demanda:    '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>',
    mora:       '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    pago_fallo: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
    detenida:   '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>',
    exito:      '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
    // Icons for new aging/SLA alerts (2026-04-19 customer feedback)
    pickup:       '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
    ensamble:     '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>',
    consignacion: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>'
  };

  var _prioOrder = { alta: 0, media: 1, info: 2 };

  var _isRefreshing = false;
  var _lastTotal = null;

  function render(){
    ADApp.render(_backBtn + '<div class="ad-h1">Alertas</div><div><span class="ad-spin"></span> Analizando datos...</div>');
    load();
  }

  function load(){
    ADApp.api('alertas/listar.php').done(function(r){
      paint(r);
      // Refresh feedback — toast with result, comparing to the previous count
      // so the operator knows the click had an effect even when the list
      // looks identical.
      if (_isRefreshing && ADApp.toast) {
        var total = (r && r.total) || 0;
        var diff  = (_lastTotal !== null) ? total - _lastTotal : 0;
        var msg;
        if (diff > 0)      msg = 'Actualizado · ' + diff + ' alertas nuevas (total ' + total + ')';
        else if (diff < 0) msg = 'Actualizado · ' + Math.abs(diff) + ' resueltas (total ' + total + ')';
        else               msg = 'Actualizado · sin cambios (' + total + ' activas)';
        ADApp.toast(msg);
      }
      _lastTotal = (r && r.total) || 0;
      _isRefreshing = false;
    }).fail(function(){
      _isRefreshing = false;
      ADApp.render(_backBtn + '<div class="ad-h1">Alertas</div><div class="ad-banner err">Error al cargar alertas</div>');
    });
  }

  function paint(r){
    var html = _backBtn;
    html += '<div class="ad-toolbar"><div class="ad-h1">Alertas <span class="ad-dim" style="font-size:14px;font-weight:400;">(' + (r.total || 0) + ' activas)</span></div>';
    html += '<button class="ad-btn sm ghost" id="adAlertRefreshBtn"><span id="adAlertRefreshIcon">\u{1F504}</span> Actualizar</button></div>';

    // Count by priority
    var counts = { alta: 0, media: 0, info: 0 };
    var alertas = r.alertas || [];
    alertas.forEach(function(a){
      if (counts.hasOwnProperty(a.prioridad)) counts[a.prioridad]++;
      else counts.info++;
    });

    // KPI summary
    html += '<div class="ad-kpis">';
    html += '<div class="ad-kpi"><div class="label">Total alertas</div><div class="value ' + (r.total > 0 ? 'red' : 'green') + '">' + (r.total || 0) + '</div></div>';
    html += '<div class="ad-kpi"><div class="label">Alta prioridad</div><div class="value ' + (counts.alta > 0 ? 'red' : 'green') + '">' + counts.alta + '</div></div>';
    html += '<div class="ad-kpi"><div class="label">Media prioridad</div><div class="value ' + (counts.media > 0 ? 'yellow' : 'green') + '">' + counts.media + '</div></div>';
    html += '<div class="ad-kpi"><div class="label">Informativas</div><div class="value blue">' + counts.info + '</div></div>';
    html += '</div>';

    // Empty state
    if (!alertas.length) {
      html += '<div class="ad-empty"><span class="ic">\u2705</span>Sin alertas activas. Todo est\u00E1 en orden.</div>';
      ADApp.render(html);
      return;
    }

    // Sort alerts: alta first, then media, then info
    alertas.sort(function(a, b){
      return (_prioOrder[a.prioridad] || 2) - (_prioOrder[b.prioridad] || 2);
    });

    // Group by priority
    var groups = { alta: [], media: [], info: [] };
    alertas.forEach(function(a){
      var key = groups.hasOwnProperty(a.prioridad) ? a.prioridad : 'info';
      groups[key].push(a);
    });

    var groupLabels = {
      alta:  { title: 'Alta prioridad',  color: '#ef4444', bg: 'rgba(239,68,68,.04)',  badge: 'red' },
      media: { title: 'Media prioridad', color: '#f59e0b', bg: 'rgba(245,158,11,.04)', badge: 'yellow' },
      info:  { title: 'Informativas',    color: '#3b82f6', bg: 'rgba(59,130,246,.04)', badge: 'blue' }
    };

    ['alta', 'media', 'info'].forEach(function(prio){
      var items = groups[prio];
      if (!items.length) return;

      var g = groupLabels[prio];
      html += '<div style="margin-bottom:20px;">';
      html += '<div style="font-weight:700;font-size:14px;color:' + g.color + ';margin-bottom:8px;padding-left:4px;text-transform:uppercase;letter-spacing:.5px;">' + g.title + ' (' + items.length + ')</div>';

      items.forEach(function(a){
        var icon = _iconMap[a.icono] || '\u26A0\uFE0F';
        var prioLabel = prio === 'alta' ? 'ALTA' : (prio === 'media' ? 'MEDIA' : 'INFO');

        html += '<div class="ad-card" style="border-left:4px solid ' + g.color + ';background:' + g.bg + ';margin-bottom:10px;">';
        html += '<div style="display:flex;align-items:flex-start;gap:12px;">';
        html += '<div style="font-size:24px;line-height:1;">' + icon + '</div>';
        html += '<div style="flex:1;">';
        html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">';
        html += '<span class="ad-badge ' + g.badge + '">' + prioLabel + '</span>';
        html += '<span class="ad-badge gray">' + esc(a.tipo) + '</span>';
        html += '</div>';
        html += '<div style="font-weight:700;font-size:15px;color:var(--ad-navy);margin-bottom:4px;">' + esc(a.titulo) + '</div>';
        html += '<div style="font-size:13px;color:var(--ad-dim);line-height:1.5;">' + esc(a.mensaje) + '</div>';
        html += '</div></div></div>';
      });

      html += '</div>';
    });

    ADApp.render(html);
    // Wire the Actualizar button (explicit spinner feedback beats onclick-only)
    $('#adAlertRefreshBtn').on('click', function(){
      var $btn = $(this).prop('disabled', true);
      $('#adAlertRefreshIcon').replaceWith('<span class="ad-spin" id="adAlertRefreshIcon"></span>');
      refresh();
    });
  }

  function refresh(){
    _isRefreshing = true;
    load();
  }
  function esc(s){ return $('<span>').text(s || '').html(); }

  return { render: render, refresh: refresh };
})();
