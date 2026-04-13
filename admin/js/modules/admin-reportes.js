window.AD_reportes = (function(){
  var _tab = 'ventas';
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  function render(){
    ADApp.render(_backBtn+'<div class="ad-h1">Reportes</div><div><span class="ad-spin"></span> Cargando...</div>');
    loadTab();
  }

  function loadTab(){
    var html = _backBtn;
    html += '<div class="ad-toolbar"><div class="ad-h1">Reportes</div></div>';
    html += '<div class="ad-tabs" id="rpTabs">';
    html += tabBtn('ventas','Reporte de Ventas');
    html += tabBtn('financiero','Reporte Financiero');
    html += tabBtn('cartera','Cartera');
    html += tabBtn('inventario','Inventario');
    html += '</div>';
    html += '<div id="rpContent"><span class="ad-spin"></span> Cargando reporte...</div>';
    ADApp.render(html);
    $('#rpTabs').on('click','button',function(){ _tab=$(this).data('tab'); loadTab(); });

    if (_tab === 'ventas') loadVentas();
    else if (_tab === 'financiero') loadFinanciero();
    else if (_tab === 'cartera') loadCartera();
    else if (_tab === 'inventario') loadInventario();
  }

  function tabBtn(tab, label){
    return '<button data-tab="'+tab+'"'+(_tab===tab?' class="active"':'')+'>'+label+'</button>';
  }

  // ─────────────── VENTAS ───────────────
  function loadVentas(){
    ADApp.api('reportes/ventas.php').done(function(r){
      var html = '<div class="ad-filters">';
      html += '<select class="ad-select" id="rpVTipo"><option value="diario">Diario</option><option value="semanal">Semanal</option><option value="mensual" selected>Mensual</option></select>';
      html += '<input type="date" class="ad-input" id="rpVInicio" value="'+r.fecha_inicio+'" style="width:auto;">';
      html += '<input type="date" class="ad-input" id="rpVFin" value="'+r.fecha_fin+'" style="width:auto;">';
      html += '<button class="ad-btn sm primary" id="rpVFilter">Aplicar</button>';
      html += '<a href="php/reportes/ventas.php?export=csv&fecha_inicio='+r.fecha_inicio+'&fecha_fin='+r.fecha_fin+'" class="ad-btn sm ghost" target="_blank">Exportar CSV</a>';
      html += '</div>';

      // Period table
      html += '<div class="ad-h2">Ventas por periodo</div>';
      html += '<div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Periodo</th><th>Unidades</th><th>Ingresos</th><th>Ticket Promedio</th></tr></thead><tbody>';
      (r.periodos||[]).forEach(function(p){
        html += '<tr><td><strong>'+p.periodo+'</strong></td><td>'+p.unidades+'</td><td>'+ADApp.money(p.ingresos)+'</td><td>'+ADApp.money(p.ticket_promedio)+'</td></tr>';
      });
      if (!r.periodos || !r.periodos.length) html += '<tr><td colspan="4" class="ad-dim" style="text-align:center">Sin datos</td></tr>';
      html += '</tbody></table></div>';

      // By model
      html += '<div class="ad-h2">Desglose por modelo</div>';
      html += '<div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Modelo</th><th>Unidades</th><th>Ingresos</th></tr></thead><tbody>';
      (r.por_modelo||[]).forEach(function(m){
        html += '<tr><td>'+esc(m.modelo)+'</td><td>'+m.unidades+'</td><td>'+ADApp.money(m.ingresos)+'</td></tr>';
      });
      html += '</tbody></table></div>';

      $('#rpContent').html(html);
      $('#rpVFilter').on('click', function(){
        ADApp.api('reportes/ventas.php?tipo='+$('#rpVTipo').val()+'&fecha_inicio='+$('#rpVInicio').val()+'&fecha_fin='+$('#rpVFin').val()).done(function(r2){
          loadTab(); // simplify: reload entire tab
        });
      });
    });
  }

  // ─────────────── FINANCIERO ───────────────
  function loadFinanciero(){
    ADApp.api('reportes/financiero.php').done(function(r){
      var html = '<div class="ad-kpis">';
      html += kpi('Total cobrado', ADApp.money(r.total_cobrado), 'green');
      html += kpi('Ingresos contado/MSI', ADApp.money(r.ingreso_contado), 'blue');
      html += kpi('Cobros de crédito', ADApp.money(r.ingreso_credito), 'blue');
      html += kpi('Proyección 30 días', ADApp.money(r.proyectado_30d), 'yellow');
      html += kpi('Monto vencido', ADApp.money(r.monto_vencido), r.monto_vencido > 0 ? 'red' : 'green');
      html += '</div>';

      html += '<div style="margin-bottom:12px;"><a href="php/reportes/financiero.php?export=csv" class="ad-btn sm ghost" target="_blank">Exportar CSV</a></div>';

      if (r.tendencia && r.tendencia.length) {
        html += '<div class="ad-h2">Tendencia de cobros</div>';
        html += '<div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Fecha</th><th>Cobrado</th><th>Pagos</th></tr></thead><tbody>';
        r.tendencia.forEach(function(t){
          html += '<tr><td>'+t.fecha+'</td><td>'+ADApp.money(t.cobrado)+'</td><td>'+t.pagos+'</td></tr>';
        });
        html += '</tbody></table></div>';
      }

      $('#rpContent').html(html);
    });
  }

  // ─────────────── CARTERA ───────────────
  function loadCartera(){
    ADApp.api('reportes/cartera.php').done(function(r){
      var html = '<div class="ad-kpis">';
      html += kpi('Suscripciones activas', r.suscripciones, 'blue');
      html += kpi('Ciclos pagados', r.ciclos_pagados, 'green');
      html += kpi('Ciclos pendientes', r.ciclos_pendientes, 'yellow');
      html += kpi('Ciclos vencidos', r.ciclos_overdue, r.ciclos_overdue > 0 ? 'red' : 'green');
      html += kpi('Tasa de recuperación', r.recovery_rate + '%', r.recovery_rate >= 80 ? 'green' : 'red');
      html += '</div>';

      html += '<div style="margin-bottom:12px;"><a href="php/reportes/cartera.php?export=csv" class="ad-btn sm ghost" target="_blank">Exportar CSV</a></div>';

      // Amounts
      html += '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px;">';
      html += amountCard('Monto pagado', r.monto_pagado, '#22c55e');
      html += amountCard('Monto pendiente', r.monto_pendiente, '#f59e0b');
      html += amountCard('Monto vencido', r.monto_overdue, '#ef4444');
      html += '</div>';

      // Aging buckets
      html += '<div class="ad-h2">Antigüedad de cartera vencida</div>';
      html += '<div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Bucket</th><th>Ciclos</th><th>Monto</th></tr></thead><tbody>';
      (r.buckets||[]).forEach(function(b){
        html += '<tr><td><strong>'+b.label+'</strong></td><td>'+b.ciclos+'</td><td>'+ADApp.money(b.monto)+'</td></tr>';
      });
      html += '</tbody></table></div>';

      $('#rpContent').html(html);
    });
  }

  // ─────────────── INVENTARIO ───────────────
  function loadInventario(){
    ADApp.api('reportes/inventario.php').done(function(r){
      var html = '<div class="ad-kpis">';
      html += kpi('Total unidades', r.total_unidades, 'blue');
      html += kpi('Disponible', r.total_disponible, 'green');
      html += kpi('Entregado', r.total_entregado, 'green');
      html += kpi('Rotación 30d', r.rotacion_30d + 'x', r.rotacion_30d > 0.5 ? 'green' : 'yellow');
      html += '</div>';

      html += '<div style="margin-bottom:12px;"><a href="php/reportes/inventario.php?export=csv" class="ad-btn sm ghost" target="_blank">Exportar CSV</a></div>';

      // By model
      html += '<div class="ad-h2">Stock por modelo</div>';
      html += '<div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Modelo</th><th>Disponible</th><th>Reservado</th><th>Entregado</th><th>En Tránsito</th><th>Total</th></tr></thead><tbody>';
      (r.por_modelo||[]).forEach(function(m){
        html += '<tr><td><strong>'+esc(m.modelo)+'</strong></td><td>'+m.disponible+'</td><td>'+m.reservado+'</td><td>'+m.entregado+'</td><td>'+m.en_transito+'</td><td>'+m.total+'</td></tr>';
      });
      html += '</tbody></table></div>';

      // By location
      html += '<div class="ad-h2">Stock por ubicación</div>';
      html += '<div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Ubicación</th><th>Disponible</th><th>Entregado</th><th>Total</th></tr></thead><tbody>';
      (r.por_ubicacion||[]).forEach(function(u){
        html += '<tr><td>'+esc(u.ubicacion)+'</td><td>'+u.disponible+'</td><td>'+u.entregado+'</td><td>'+u.total+'</td></tr>';
      });
      html += '</tbody></table></div>';

      $('#rpContent').html(html);
    });
  }

  function kpi(label, value, cls){
    return '<div class="ad-kpi"><div class="label">'+label+'</div><div class="value '+cls+'">'+value+'</div></div>';
  }
  function amountCard(label, amount, color){
    return '<div class="ad-card" style="text-align:center;border-top:3px solid '+color+';">'+
      '<div style="font-size:24px;font-weight:800;color:'+color+';">'+ADApp.money(amount)+'</div>'+
      '<div style="font-size:12px;color:var(--ad-dim);margin-top:4px;">'+label+'</div></div>';
  }
  function esc(s){ return $('<span>').text(s||'').html(); }

  return { render: render };
})();
