window.AD_analytics = (function(){
  var _filters = {periodo:'month'};
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  function render(){
    ADApp.render(_backBtn+'<div class="ad-h1">Analítica de Ventas</div><div><span class="ad-spin"></span> Cargando...</div>');
    load();
  }

  function load(){
    ADApp.api('ventas/analytics.php?' + $.param(_filters)).done(paint).fail(function(){
      ADApp.render(_backBtn+'<div class="ad-h1">Analítica de Ventas</div><div class="ad-banner err">Error al cargar</div>');
    });
  }

  function paint(r){
    var s = r.summary || {};
    var cmp = r.comparacion || {};
    var prev = cmp.periodo_anterior || {};
    var html = _backBtn;
    html += '<div class="ad-toolbar"><div class="ad-h1">Analítica de Ventas</div>';
    html += '<span class="ad-dim" style="font-size:12px;">' + r.fecha_inicio + ' — ' + r.fecha_fin + '</span></div>';

    // ── Filters ──
    html += '<div class="ad-filters" style="flex-wrap:wrap;">';
    html += '<div class="ad-tabs" style="margin:0;box-shadow:none;padding:0;">';
    html += periodBtn('day','Hoy');
    html += periodBtn('week','Semana');
    html += periodBtn('month','Mes');
    html += periodBtn('custom','Personalizado');
    html += '</div>';
    html += '<select class="ad-select" id="anModelo"><option value="">Todos los modelos</option>';
    (r.filtros.modelos||[]).forEach(function(m){ html += '<option value="'+esc(m)+'"'+(m===(_filters.modelo||'')?'selected':'')+'>'+esc(m)+'</option>'; });
    html += '</select>';
    html += '<select class="ad-select" id="anTipo"><option value="">Todos los tipos</option>';
    (r.filtros.tipos_pago||[]).forEach(function(t){ html += '<option value="'+esc(t)+'"'+(t===(_filters.tipo_pago||'')?'selected':'')+'>'+esc(t)+'</option>'; });
    html += '</select>';
    html += '<button class="ad-btn sm primary" id="anFilter">Filtrar</button>';
    html += '</div>';
    html += '<div id="anCustomDates" style="display:'+(_filters.periodo==='custom'?'flex':'none')+';gap:8px;align-items:center;margin:8px 0;font-size:13px;">';
    html += '<label>Desde <input type="date" class="ad-input" id="anFechaInicio" value="'+(_filters.fecha_inicio||r.fecha_inicio||'')+'"></label>';
    html += '<label>Hasta <input type="date" class="ad-input" id="anFechaFin" value="'+(_filters.fecha_fin||r.fecha_fin||'')+'"></label>';
    html += '<button class="ad-btn sm primary" id="anApplyDates">Aplicar</button>';
    html += '</div>';

    // ── KPIs ──
    var ventasChange = calcChange(s.total_ventas, prev.total_ventas);
    var ingresosChange = calcChange(s.ingresos_totales, prev.ingresos_totales);

    html += '<div class="ad-kpis">';
    html += kpi('Ventas totales', s.total_ventas || 0, 'green', ventasChange);
    html += kpi('Ingresos totales', ADApp.money(s.ingresos_totales), 'blue', ingresosChange);
    html += kpi('Ticket promedio', ADApp.money(s.ticket_promedio), 'blue');
    html += kpi('Modelos vendidos', s.modelos_vendidos || 0, 'green');
    html += '</div>';

    // ── Comparison ──
    if (prev.total_ventas !== undefined) {
      html += '<div class="ad-banner">';
      html += 'Periodo anterior (' + cmp.prev_inicio + ' — ' + cmp.prev_fin + '): ';
      html += '<strong>' + (prev.total_ventas||0) + ' ventas</strong> · <strong>' + ADApp.money(prev.ingresos_totales) + '</strong>';
      html += '</div>';
    }

    // ── Daily Trend ──
    if (r.tendencia && r.tendencia.length > 1) {
      html += '<div class="ad-h2">Tendencia diaria</div>';
      html += '<div class="ad-card" style="padding:16px;">';
      html += renderBarChart(r.tendencia);
      html += '</div>';
    }

    // ── Sales per model ──
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">';

    html += '<div>';
    html += '<div class="ad-h2">Ventas por modelo</div>';
    html += '<div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Modelo</th><th>Unidades</th><th>Ingresos</th></tr></thead><tbody>';
    (r.por_modelo||[]).forEach(function(m){
      html += '<tr><td><strong>'+esc(m.modelo)+'</strong></td><td>'+m.unidades+'</td><td>'+ADApp.money(m.ingresos)+'</td></tr>';
    });
    if (!r.por_modelo || !r.por_modelo.length) html += '<tr><td colspan="3" class="ad-dim" style="text-align:center">Sin datos</td></tr>';
    html += '</tbody></table></div>';
    html += '</div>';

    html += '<div>';
    html += '<div class="ad-h2">Ventas por tipo de pago</div>';
    html += '<div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Tipo</th><th>Unidades</th><th>Ingresos</th></tr></thead><tbody>';
    (r.por_tipo_pago||[]).forEach(function(t){
      html += '<tr><td><span class="ad-badge blue">'+esc(t.tipo_pago)+'</span></td><td>'+t.unidades+'</td><td>'+ADApp.money(t.ingresos)+'</td></tr>';
    });
    if (!r.por_tipo_pago || !r.por_tipo_pago.length) html += '<tr><td colspan="3" class="ad-dim" style="text-align:center">Sin datos</td></tr>';
    html += '</tbody></table></div>';
    html += '</div>';

    html += '</div>';

    // ── Top models ──
    html += '<div class="ad-h2">Top modelos vendidos</div>';
    html += '<div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>#</th><th>Modelo</th><th>Color</th><th>Unidades</th><th>Ingresos</th></tr></thead><tbody>';
    (r.top_modelos||[]).forEach(function(m, i){
      html += '<tr><td><strong>'+(i+1)+'</strong></td><td>'+esc(m.modelo)+'</td><td>'+esc(m.color)+'</td><td>'+m.unidades+'</td><td>'+ADApp.money(m.ingresos)+'</td></tr>';
    });
    html += '</tbody></table></div>';

    // ── Per point ──
    html += '<div class="ad-h2">Ventas por punto</div>';
    html += '<div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Punto</th><th>Unidades</th><th>Ingresos</th></tr></thead><tbody>';
    (r.por_punto||[]).forEach(function(p){
      html += '<tr><td>'+esc(p.punto)+'</td><td>'+p.unidades+'</td><td>'+ADApp.money(p.ingresos)+'</td></tr>';
    });
    html += '</tbody></table></div>';

    ADApp.render(html);

    // Bindings
    $('.anPeriod').on('click', function(){
      _filters.periodo = $(this).data('p');
      if (_filters.periodo !== 'custom') {
        delete _filters.fecha_inicio;
        delete _filters.fecha_fin;
        load();
      } else {
        $('#anCustomDates').show();
      }
    });
    $('#anApplyDates').on('click', function(){
      _filters.fecha_inicio = $('#anFechaInicio').val();
      _filters.fecha_fin = $('#anFechaFin').val();
      if (_filters.fecha_inicio && _filters.fecha_fin) load();
    });
    $('#anFilter').on('click', function(){
      _filters.modelo = $('#anModelo').val();
      _filters.tipo_pago = $('#anTipo').val();
      load();
    });
  }

  function renderBarChart(data){
    if (!data || !data.length) return '';
    var maxVal = Math.max.apply(null, data.map(function(d){ return d.unidades; })) || 1;
    var html = '<div style="display:flex;align-items:flex-end;gap:3px;height:120px;padding:0 4px;">';
    data.forEach(function(d){
      var h = Math.max(4, (d.unidades / maxVal) * 100);
      var label = d.fecha.substring(5);
      html += '<div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;">';
      html += '<span style="font-size:10px;font-weight:700;color:var(--ad-primary);">'+d.unidades+'</span>';
      html += '<div style="width:100%;height:'+h+'px;background:linear-gradient(180deg,var(--ad-primary),#027db0);border-radius:4px 4px 0 0;min-width:8px;"></div>';
      html += '<span style="font-size:9px;color:var(--ad-dim);white-space:nowrap;">'+label+'</span>';
      html += '</div>';
    });
    html += '</div>';
    return html;
  }

  function periodBtn(p, label){
    var active = (_filters.periodo||'month') === p ? ' class="active anPeriod"' : ' class="anPeriod"';
    return '<button data-p="'+p+'"'+active+'>'+label+'</button>';
  }

  function calcChange(current, prev){
    if (!prev || prev == 0) return null;
    var pct = ((current - prev) / prev * 100).toFixed(1);
    return pct;
  }

  function kpi(label, value, cls, change){
    var changeHtml = '';
    if (change !== null && change !== undefined) {
      var color = change >= 0 ? '#22c55e' : '#ef4444';
      var arrow = change >= 0 ? '&#9650;' : '&#9660;';
      changeHtml = '<div style="font-size:11px;color:'+color+';margin-top:4px;font-weight:700;">'+arrow+' '+Math.abs(change)+'%</div>';
    }
    return '<div class="ad-kpi"><div class="label">'+label+'</div><div class="value '+cls+'">'+value+'</div>'+changeHtml+'</div>';
  }

  function esc(s){ return $('<span>').text(s||'').html(); }

  return { render: render };
})();
