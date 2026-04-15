window.AD_puntosperf = (function(){
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  function render(){
    ADApp.render(_backBtn+'<div class="ad-h1">Rendimiento de Puntos</div><div><span class="ad-spin"></span></div>');
    load();
  }

  function load(){
    ADApp.api('puntos/performance.php').done(paint).fail(function(){
      ADApp.render(_backBtn+'<div class="ad-h1">Rendimiento</div><div class="ad-banner err">Error</div>');
    });
  }

  function paint(r){
    var html = _backBtn;
    html += '<div class="ad-h1">Rendimiento de Puntos Voltika</div>';

    // Summary KPIs
    var totalPuntos = (r.puntos||[]).length;
    var activos = (r.puntos||[]).filter(function(p){return p.activo;}).length;
    var totalVentas = (r.puntos||[]).reduce(function(s,p){return s+(p.ventas_mes||0);},0);
    var totalInv = (r.puntos||[]).reduce(function(s,p){return s+(p.inventario_disponible||0);},0);
    var incAbiertas = (r.incidencias||[]).filter(function(i){return i.estado==='abierta';}).length;

    html += '<div class="ad-kpis">';
    html += kpi('Puntos activos', activos+'/'+totalPuntos, 'blue');
    html += kpi('Ventas este mes', totalVentas, 'green');
    html += kpi('Inventario total', totalInv, 'blue');
    html += kpi('Incidencias abiertas', incAbiertas, incAbiertas>0?'red':'green');
    html += '</div>';

    // Performance table
    html += '<div class="ad-h2">Rendimiento por punto</div>';
    html += '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table"><thead><tr>';
    html += '<th>Punto</th><th>Ciudad</th><th>Tipo</th><th>Ventas mes</th><th>Inventario</th><th>Entregas pendientes</th><th>Envíos entrantes</th><th>Capacidad</th>';
    html += '</tr></thead><tbody>';
    (r.puntos||[]).forEach(function(p){
      var capPct = p.capacidad > 0 ? Math.round((p.inventario_disponible / p.capacidad)*100) : 0;
      var capColor = capPct > 90 ? 'red' : (capPct > 60 ? 'yellow' : 'green');
      html += '<tr'+(p.activo?'':' style="opacity:.5;"')+'>';
      html += '<td><strong>'+esc(p.nombre)+'</strong>'+(p.activo?'':' <span class="ad-badge red">Inactivo</span>')+'</td>';
      html += '<td>'+esc(p.ciudad||'—')+'</td>';
      html += '<td><span class="ad-badge gray">'+esc(p.tipo||'—')+'</span></td>';
      html += '<td><strong>'+p.ventas_mes+'</strong></td>';
      html += '<td>'+p.inventario_disponible+'</td>';
      html += '<td>'+(p.entregas_pendientes||0)+'</td>';
      html += '<td>'+(p.envios_pendientes||0)+'</td>';
      html += '<td><div class="ad-progress" style="width:80px;"><div style="width:'+Math.min(100,capPct)+'%;background:var(--ad-'+capColor+')"></div></div><small>'+capPct+'%</small></td>';
      html += '</tr>';
    });
    html += '</tbody></table></div></div>';

    // Incidencias
    html += '<div class="ad-h2">Incidencias recientes</div>';
    if (!r.incidencias||!r.incidencias.length) {
      html += '<div class="ad-empty"><span class="ic">&#9989;</span>Sin incidencias reportadas</div>';
    } else {
      html += '<div class="ad-table-wrap"><table class="ad-table"><thead><tr>';
      html += '<th>Fecha</th><th>Punto</th><th>Tipo</th><th>Descripción</th><th>Estado</th>';
      html += '</tr></thead><tbody>';
      r.incidencias.forEach(function(i){
        var estadoBadge = i.estado==='abierta'?'red':(i.estado==='en_proceso'?'yellow':'green');
        html += '<tr>';
        html += '<td style="font-size:12px;">'+(i.freg||'—').substring(0,10)+'</td>';
        html += '<td>'+esc(i.punto_nombre||'—')+'</td>';
        html += '<td><span class="ad-badge gray">'+esc(i.tipo)+'</span></td>';
        html += '<td style="font-size:12px;">'+esc(i.descripcion||'').substring(0,100)+'</td>';
        html += '<td><span class="ad-badge '+estadoBadge+'">'+esc(i.estado)+'</span></td>';
        html += '</tr>';
      });
      html += '</tbody></table></div>';
    }

    ADApp.render(html);
  }

  function kpi(label,value,cls){
    return '<div class="ad-kpi"><div class="label">'+label+'</div><div class="value '+cls+'">'+value+'</div></div>';
  }
  function esc(s){return $('<span>').text(s||'').html();}
  return { render:render };
})();
