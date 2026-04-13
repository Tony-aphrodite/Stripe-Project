window.AD_alertas = (function(){
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  function render(){
    ADApp.render(_backBtn+'<div class="ad-h1">Alertas y Decisiones</div><div><span class="ad-spin"></span> Analizando datos...</div>');
    load();
  }

  function load(){
    ADApp.api('alertas/listar.php').done(paint).fail(function(){
      ADApp.render(_backBtn+'<div class="ad-h1">Alertas</div><div class="ad-banner err">Error al cargar alertas</div>');
    });
  }

  function paint(r){
    var html = _backBtn;
    html += '<div class="ad-toolbar"><div class="ad-h1">Alertas y Decisiones</div>';
    html += '<button class="ad-btn sm ghost" onclick="AD_alertas.refresh()">Actualizar</button></div>';

    // Count by priority
    var alta = 0, media = 0, info = 0;
    (r.alertas||[]).forEach(function(a){
      if (a.prioridad === 'alta') alta++;
      else if (a.prioridad === 'media') media++;
      else info++;
    });

    html += '<div class="ad-kpis">';
    html += '<div class="ad-kpi"><div class="label">Total alertas</div><div class="value '+(r.total > 0 ? 'red' : 'green')+'">' + r.total + '</div></div>';
    html += '<div class="ad-kpi"><div class="label">Alta prioridad</div><div class="value '+(alta > 0 ? 'red' : 'green')+'">' + alta + '</div></div>';
    html += '<div class="ad-kpi"><div class="label">Media prioridad</div><div class="value '+(media > 0 ? 'yellow' : 'green')+'">' + media + '</div></div>';
    html += '<div class="ad-kpi"><div class="label">Informativas</div><div class="value blue">' + info + '</div></div>';
    html += '</div>';

    if (!r.alertas || !r.alertas.length) {
      html += '<div class="ad-empty"><span class="ic">&#9989;</span>No hay alertas activas. Todo está en orden.</div>';
      ADApp.render(html);
      return;
    }

    // Alert cards
    r.alertas.forEach(function(a){
      var borderColor = a.prioridad === 'alta' ? '#ef4444' : (a.prioridad === 'media' ? '#f59e0b' : '#3b82f6');
      var bgColor = a.prioridad === 'alta' ? 'rgba(239,68,68,.04)' : (a.prioridad === 'media' ? 'rgba(245,158,11,.04)' : 'rgba(59,130,246,.04)');
      var prioLabel = a.prioridad === 'alta' ? 'ALTA' : (a.prioridad === 'media' ? 'MEDIA' : 'INFO');
      var prioClass = a.prioridad === 'alta' ? 'red' : (a.prioridad === 'media' ? 'yellow' : 'blue');
      var iconMap = {
        inventario: '&#128230;', demanda: '&#128293;', mora: '&#9888;',
        pago_fallo: '&#10060;', detenida: '&#9203;', exito: '&#11088;'
      };
      var icon = iconMap[a.icono] || '&#9888;';

      html += '<div class="ad-card" style="border-left:4px solid '+borderColor+';background:'+bgColor+';margin-bottom:12px;">';
      html += '<div style="display:flex;align-items:flex-start;gap:12px;">';
      html += '<div style="font-size:24px;">'+icon+'</div>';
      html += '<div style="flex:1;">';
      html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">';
      html += '<span class="ad-badge '+prioClass+'">'+prioLabel+'</span>';
      html += '<span class="ad-badge gray">'+esc(a.tipo)+'</span>';
      html += '</div>';
      html += '<div style="font-weight:700;font-size:15px;color:var(--ad-navy);margin-bottom:4px;">'+esc(a.titulo)+'</div>';
      html += '<div style="font-size:13px;color:var(--ad-dim);">'+esc(a.mensaje)+'</div>';
      html += '</div></div></div>';
    });

    ADApp.render(html);
  }

  function refresh(){ load(); }
  function esc(s){ return $('<span>').text(s||'').html(); }

  return { render: render, refresh: refresh };
})();
