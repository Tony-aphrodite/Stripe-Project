window.AD_buro = (function(){

  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  function render(){
    ADApp.render(
      _backBtn+
      '<div class="ad-toolbar">'+
        '<div class="ad-h1">Consultas Buro de Credito</div>'+
        '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">'+
          '<input id="burDesde" class="ad-input" type="date" style="width:auto;">'+
          '<input id="burHasta" class="ad-input" type="date" style="width:auto;">'+
          '<button id="burExport" class="ad-btn primary">Exportar Excel</button>'+
        '</div>'+
      '</div>'+
      '<div id="burKpis" class="ad-kpis" style="margin-bottom:14px;"></div>'+
      '<div id="burTable">Cargando...</div>'
    );
    loadData();
    $('#burExport').on('click', exportExcel);
  }

  function loadData(){
    ADApp.api('buro/listar.php').done(function(r){
      if(!r.ok){ $('#burTable').html('<div class="ad-card">Error al cargar datos</div>'); return; }

      // KPIs
      var total = r.total || 0;
      var rows = r.rows || [];
      var conScore = rows.filter(function(x){ return x.score && x.score > 0; }).length;
      $('#burKpis').html(
        kpi('Total consultas', total, 'blue')+
        kpi('Con score', conScore, 'green')+
        kpi('Sin score', total - conScore, 'yellow')
      );

      if(!rows.length){
        $('#burTable').html('<div class="ad-card" style="text-align:center;padding:32px;">No hay consultas registradas</div>');
        return;
      }

      var html = '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table"><thead><tr>'+
        '<th>Folio CDC</th><th>Nombre</th><th>Score</th><th>Tipo</th>'+
        '<th>Fecha aprobacion</th><th>Hora aprobacion</th>'+
        '<th>Fecha consulta</th><th>Hora consulta</th>'+
        '<th>NIP</th><th>Leyenda</th><th>TyC</th>'+
        '</tr></thead><tbody>';

      rows.forEach(function(r){
        html += '<tr>'+
          '<td>'+(r.folio_consulta||'-')+'</td>'+
          '<td>'+(r.nombre||'')+' '+(r.apellido_paterno||'')+' '+(r.apellido_materno||'')+'</td>'+
          '<td><strong>'+(r.score||'-')+'</strong></td>'+
          '<td><span class="ad-badge blue">'+(r.tipo_consulta||'PF')+'</span></td>'+
          '<td>'+(r.fecha_aprobacion_consulta||'-')+'</td>'+
          '<td>'+(r.hora_aprobacion_consulta||'-')+'</td>'+
          '<td>'+(r.fecha_consulta||fmtDate(r.freg)||'-')+'</td>'+
          '<td>'+(r.hora_consulta||fmtTime(r.freg)||'-')+'</td>'+
          '<td><span class="ad-badge green">'+(r.ingreso_nip_ciec||'SI')+'</span></td>'+
          '<td><span class="ad-badge green">'+(r.respuesta_leyenda||'SI')+'</span></td>'+
          '<td><span class="ad-badge green">'+(r.aceptacion_tyc||'SI')+'</span></td>'+
          '</tr>';
      });

      html += '</tbody></table></div></div>';
      $('#burTable').html(html);
    }).fail(function(){
      $('#burTable').html('<div class="ad-card">Error de conexion</div>');
    });
  }

  function exportExcel(){
    var desde = $('#burDesde').val() || '';
    var hasta = $('#burHasta').val() || '';
    var url = 'php/buro/exportar.php?desde='+encodeURIComponent(desde)+'&hasta='+encodeURIComponent(hasta);
    window.open(url, '_blank');
  }

  function kpi(label, value, color){
    return '<div class="ad-kpi"><div class="label">'+label+'</div><div class="value '+color+'">'+value+'</div></div>';
  }

  function fmtDate(dt){
    if(!dt) return '';
    return dt.substring(0,10);
  }
  function fmtTime(dt){
    if(!dt) return '';
    var t = dt.indexOf(' ');
    return t >= 0 ? dt.substring(t+1) : '';
  }

  return { render: render };
})();
