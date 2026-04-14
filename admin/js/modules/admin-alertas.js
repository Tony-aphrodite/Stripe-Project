window.AD_alertas = (function(){
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  var _iconMap = {
    inventario: '\u{1F4E6}',   // 📦
    demanda:    '\u{1F4C8}',   // 📈
    mora:       '\u26A0\uFE0F',// ⚠️
    pago_fallo: '\u{1F4B3}',   // 💳
    detenida:   '\u{1F512}',   // 🔒
    exito:      '\u{1F3C6}'    // 🏆
  };

  var _prioOrder = { alta: 0, media: 1, info: 2 };

  function render(){
    ADApp.render(_backBtn + '<div class="ad-h1">Alertas</div><div><span class="ad-spin"></span> Analizando datos...</div>');
    load();
  }

  function load(){
    ADApp.api('alertas/listar.php').done(paint).fail(function(){
      ADApp.render(_backBtn + '<div class="ad-h1">Alertas</div><div class="ad-banner err">Error al cargar alertas</div>');
    });
  }

  function paint(r){
    var html = _backBtn;
    html += '<div class="ad-toolbar"><div class="ad-h1">Alertas <span class="ad-dim" style="font-size:14px;font-weight:400;">(' + (r.total || 0) + ' activas)</span></div>';
    html += '<button class="ad-btn sm ghost" onclick="AD_alertas.refresh()">\u{1F504} Actualizar</button></div>';

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
  }

  function refresh(){ load(); }
  function esc(s){ return $('<span>').text(s || '').html(); }

  return { render: render, refresh: refresh };
})();
