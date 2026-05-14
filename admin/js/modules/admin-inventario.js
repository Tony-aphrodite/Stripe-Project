window.AD_inventario = (function(){
  // limit=500 so grouped-by-model view shows every bike of each model in a
  // single table. Previously the default limit of 50 split M05's 40+ bikes
  // across pages and readers had to click "next" to see the rest.
  var filters = { limit: 500 };
  var _view = 'global';   // 'global' | 'por_punto' | 'detalle_punto'
  var _selectedPuntoId = null;
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';
  function render(){
    ADApp.render('<div class="ad-h1">CEDIS</div><div><span class="ad-spin"></span> Cargando...</div>');
    if (_view === 'por_punto')      return loadPorPunto();
    if (_view === 'detalle_punto')  return loadDetallePunto(_selectedPuntoId);
    load();
  }
  function load(){
    ADApp.api('inventario/listar.php?' + $.param(filters)).done(paint);
  }
  function loadPorPunto(){
    ADApp.api('inventario/por-punto.php').done(paintPorPunto);
  }
  function loadDetallePunto(puntoId){
    ADApp.api('inventario/por-punto.php?punto_id=' + encodeURIComponent(puntoId)).done(paintDetallePunto);
  }

  function viewToggle(){
    function btn(key, label){
      var active = _view === key || (key === 'por_punto' && _view === 'detalle_punto');
      return '<button class="adInvView" data-view="'+key+'" style="padding:8px 16px;font-size:13px;font-weight:600;border:1px solid var(--ad-border);'+
        'background:'+(active?'var(--ad-primary)':'#fff')+';color:'+(active?'#fff':'var(--ad-dim)')+';'+
        'cursor:pointer;border-radius:6px;">'+label+'</button>';
    }
    return '<div style="display:flex;gap:6px;margin:8px 0 12px;">'+
      btn('global','Inventario global')+
      btn('por_punto','Por punto')+
    '</div>';
  }
  function paint(r){
    var html = _backBtn+'<div class="ad-toolbar"><div class="ad-h1">CEDIS</div>';
    if(ADApp.isAdmin()){
      html += '<div style="display:flex;gap:6px;flex-wrap:wrap">'+
        '<button class="ad-btn primary" id="adNewMoto">+ Nueva moto</button>'+
        '<button class="ad-btn ghost" id="adImportExcel">Importar Excel</button>'+
        '<button class="ad-btn" id="adReplaceExcel" style="background:#dc2626;color:#fff;border-color:#dc2626" '+
          'title="DESTRUCTIVO: borra TODO el inventario actual y lo reemplaza con el archivo">'+
          '⚠ Reemplazar inventario</button>'+
        '</div>';
    }
    html += '</div>';
    html += viewToggle();
    // Summary KPIs
    var s = r.resumen||{};
    html += '<div class="ad-kpis">';
    [{l:'Total',v:s.total,c:'blue'},{l:'Existencias',v:s.disponible,c:'green'},{l:'Reservado',v:s.reservado,c:'yellow'},
     {l:'Entregado',v:s.entregado,c:'green'},{l:'En tránsito',v:s.en_transito,c:'blue'},
     {l:'En ensamble',v:s.en_ensamble,c:'yellow'},{l:'Total en puntos',v:s.en_puntos,c:'blue'},
     {l:'Bloqueado',v:s.bloqueado,c:'red'}].forEach(function(k){
      html += '<div class="ad-kpi"><div class="label">'+k.l+'</div><div class="value '+(k.c||'')+'">'+Number(k.v||0)+'</div></div>';
    });
    // Pagos pendientes KPI — clickable, jumps to Ventas → Pago pendiente tab
    if (s.pagos_pendientes != null) {
      html += '<div class="ad-kpi" id="adKpiPagosPendientes" style="cursor:pointer;" title="Ir a Ventas → Pago pendiente"><div class="label">Pagos pendientes</div><div class="value red">'+Number(s.pagos_pendientes||0)+'</div></div>';
    }
    html += '</div>';
    // Model summary
    var pm = r.por_modelo||[];
    if(pm.length){
      html += '<div style="margin-bottom:16px;"><div style="font-weight:700;font-size:13px;margin-bottom:8px;color:var(--ad-navy);">Unidades por modelo</div>';
      html += '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
      pm.forEach(function(m){
        html += '<div style="background:var(--ad-surface);border:1px solid var(--ad-border);border-radius:var(--ad-radius-sm);padding:10px 16px;text-align:center;min-width:100px;">'+
          '<div style="font-size:22px;font-weight:800;color:var(--ad-navy);">'+m.cnt+'</div>'+
          '<div style="font-size:11px;font-weight:600;color:var(--ad-dim);text-transform:uppercase;">'+m.modelo+'</div>'+
        '</div>';
      });
      html += '</div></div>';
    }
    // Filters
    var modeloOpts = '<option value="">Modelo</option>';
    (r.modelos||[]).forEach(function(m){ modeloOpts += '<option value="'+m+'"'+(filters.modelo===m?' selected':'')+'>'+m+'</option>'; });
    html += '<div class="ad-filters">'+
      '<input class="ad-input" style="width:160px" placeholder="Buscar VIN..." id="adFVin" value="'+(filters.vin||'')+'">'+
      '<select class="ad-select" id="adFModelo">'+modeloOpts+'</select>'+
      '<select class="ad-select" id="adFEstado"><option value="">Estado</option>'+
        ['por_llegar','recibida','por_ensamblar','en_ensamble','lista_para_entrega','por_validar_entrega','entregada','retenida']
        .map(function(e){return '<option'+(filters.estado===e?' selected':'')+'>'+e+'</option>';}).join('')+'</select>'+
      '<select class="ad-select" id="adFChecklist"><option value="">Checklist</option>'+
        '<option value="sin"'+(filters.checklist==='sin'?' selected':'')+'>Sin checklist</option>'+
        '<option value="origen"'+(filters.checklist==='origen'?' selected':'')+'>Con origen</option>'+
        '<option value="ensamble"'+(filters.checklist==='ensamble'?' selected':'')+'>Con ensamble</option>'+
        '<option value="completo"'+(filters.checklist==='completo'?' selected':'')+'>Completo</option>'+
      '</select>'+
      '<button class="ad-btn sm ghost" id="adFApply">Filtrar</button>'+
    '</div>';
    // Group motos by model
    var motos = r.motos||[];
    var groups = {};
    var modelOrder = [];
    motos.forEach(function(m){
      var mod = m.modelo||'Sin modelo';
      if(!groups[mod]){ groups[mod] = []; modelOrder.push(mod); }
      groups[mod].push(m);
    });
    modelOrder.sort();
    // Render grouped tables
    modelOrder.forEach(function(mod){
      // Breakdown per estado so "2 Ukko S+" doesn't read the same as "2 disponibles"
      var brk = {recibida:0, lista_para_entrega:0, por_llegar:0, en_ensamble:0, entregada:0, retenida:0};
      groups[mod].forEach(function(m){ if(brk[m.estado] !== undefined) brk[m.estado]++; });
      var disp = brk.recibida + brk.lista_para_entrega;
      var trn  = brk.por_llegar;
      var parts = [];
      if(disp>0) parts.push('<span style="color:#059669;">'+disp+' disponible'+(disp>1?'s':'')+'</span>');
      if(trn>0)  parts.push('<span style="color:#d97706;">'+trn+' por llegar</span>');
      if(brk.en_ensamble>0) parts.push('<span style="color:#6366f1;">'+brk.en_ensamble+' en ensamble</span>');
      if(brk.entregada>0)   parts.push('<span style="color:#6b7280;">'+brk.entregada+' entregada'+(brk.entregada>1?'s':'')+'</span>');
      var brkHtml = parts.length ? ' &nbsp;•&nbsp; <span style="font-weight:500;font-size:12px;">'+parts.join(' &nbsp;•&nbsp; ')+'</span>' : '';

      html += '<div style="margin-bottom:20px;">';
      html += '<div style="font-weight:700;font-size:15px;color:var(--ad-navy);margin-bottom:6px;padding-left:4px;">'+mod+' <span style="font-weight:400;color:var(--ad-dim);font-size:13px;">('+groups[mod].length+')</span>'+brkHtml+'</div>';
      // Customer brief 2026-05-04: surface Año Modelo + Núm. Motor on the
      // CEDIS list so the operator can spot wrong-year imports / motor-VIN
      // mismatches at a glance, without opening the detail card.
      // wrap in overflow-x scroll so 9 columns don't get clipped on
      // narrower monitors (consistent with .ad-table-wrap > div CSS rule).
      html += '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table" style="min-width:1200px;"><thead><tr>'+
        '<th>VIN</th><th>Año</th><th>Núm. Motor</th><th>Color</th><th>Estado</th><th>Punto</th><th>Días</th><th>Asignación</th><th>Pago</th><th></th>'+
        '</tr></thead><tbody>';
      groups[mod].forEach(function(m){
        var diasCell = '—';
        if(m.dias_en_punto !== null && m.dias_en_punto !== undefined){
          var dp = parseInt(m.dias_en_punto);
          var dpC = dp <= 7 ? '#059669' : dp <= 30 ? '#d97706' : '#dc2626';
          diasCell = '<span style="font-weight:700;color:'+dpC+';">'+dp+'d</span>';
        }
        var lockBadge = parseInt(m.bloqueado_venta) ? ' <span class="ad-badge red" style="font-size:10px;">BLOQUEADA</span>' : '';
        var motorCell = m.num_motor
          ? '<code style="font-size:11px;font-family:ui-monospace,Menlo,monospace;background:var(--ad-surface-2);padding:2px 6px;border-radius:3px;" title="'+m.num_motor+'">'+m.num_motor+'</code>'
          : '<span style="color:#9ca3af;">—</span>';

        // Customer brief 2026-05-04 round 4: "when he assign a bike to a
        // purchase, in the inventory shows like is not assigned."
        // Root cause: CLIENTE column only showed nombre, no pedido_num,
        // so the boss couldn't tell at a glance if a row was already
        // linked to an order. Now the column merges client name +
        // pedido_num and the entire row gets a subtle green tint when
        // any assignment field is populated. A red "✓ ASIGNADA" badge
        // makes it impossible to miss.
        var asignada = !!(m.cliente_nombre || m.pedido_num || m.cliente_email);
        var asignacionCell;
        if (asignada) {
          var pedidoBadge = m.pedido_num
            ? '<span style="display:inline-block;background:#10b981;color:#fff;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;letter-spacing:.3px;margin-right:4px;">'+esc(m.pedido_num)+'</span>'
            : '';
          var clienteName = m.cliente_nombre
            ? '<span style="font-weight:600;color:#065f46;">'+esc(m.cliente_nombre)+'</span>'
            : (m.cliente_email ? '<small>'+esc(m.cliente_email)+'</small>' : '<small style="color:#9ca3af">— sin nombre —</small>');
          asignacionCell = pedidoBadge + clienteName;
        } else {
          asignacionCell = '<span style="color:#9ca3af;font-style:italic;">Sin asignar</span>';
        }
        var rowStyle = asignada ? ' style="background:#f0fdf4;"' : '';

        // Round 32 (2026-05-14, Óscar): the "Ver" action column got cut off
        // on narrow screens when the table overflowed horizontally — the
        // operator couldn't find the button without scrolling all the way
        // right. Fix #1: the whole row is now clickable (cursor:pointer +
        // data-id on <tr>) so any click opens the detail. Fix #2: the
        // action <td> uses position:sticky;right:0 so it remains visible
        // even when the table scrolls horizontally.
        var stickyBg = asignada ? '#f0fdf4' : 'var(--ad-surface,#fff)';
        html += '<tr'+rowStyle+' class="adInvRow" data-id="'+m.id+'" style="cursor:pointer;'+(asignada?'background:#f0fdf4;':'')+'">'+
          '<td>'+(m.vin_display||m.vin||'—')+lockBadge+'</td>'+
          '<td style="text-align:center;">'+(m.anio_modelo||'<span style="color:#9ca3af;">—</span>')+'</td>'+
          '<td>'+motorCell+'</td>'+
          '<td>'+m.color+'</td>'+
          '<td>'+ADApp.badgeEstado(m.estado)+'</td>'+
          '<td>'+(m.punto_voltika_nombre||'—')+'</td>'+
          '<td>'+diasCell+'</td>'+
          '<td>'+asignacionCell+'</td>'+
          '<td>'+ADApp.badgeEstado(m.pago_estado||'—')+'</td>'+
          '<td style="position:sticky;right:0;background:'+stickyBg+';box-shadow:-2px 0 6px rgba(0,0,0,.04);"><button class="ad-btn sm ghost adDetail" data-id="'+m.id+'">Ver</button></td>'+
        '</tr>';
      });
      html += '</tbody></table></div></div></div>';
    });
    // Pagination
    if(r.pages>1){
      html += '<div class="ad-pagination">';
      for(var p=1;p<=r.pages;p++) html += '<button class="'+(p===r.page?'active':'')+' adPage" data-p="'+p+'">'+p+'</button>';
      html += '</div>';
    }
    ADApp.render(html);
    bindViewToggle();
    $('#adFApply').on('click',function(){
      filters.vin=$('#adFVin').val();
      filters.modelo=$('#adFModelo').val();
      filters.estado=$('#adFEstado').val();
      filters.checklist=$('#adFChecklist').val();
      filters.page=1;
      load();
    });
    // Round 32: Ver button click + row-level click (anywhere on the row
    // except interactive children opens the detail). Both bind here.
    $('.adDetail').on('click',function(ev){ ev.stopPropagation(); showDetail($(this).data('id')); });
    $('.adInvRow').on('click', function(ev){
      // Don't hijack clicks on real interactive children (buttons, links,
      // inputs). Only fire when the click target is the row/cells.
      var tag = (ev.target.tagName || '').toLowerCase();
      if (tag === 'button' || tag === 'a' || tag === 'input' || tag === 'select' || tag === 'textarea') return;
      if ($(ev.target).closest('button,a,input,select,textarea').length) return;
      var id = $(this).data('id'); if (id) showDetail(id);
    });
    $('.adPage').on('click',function(){ filters.page=$(this).data('p'); load(); });
    $('#adNewMoto').on('click', showNewForm);
    $('#adImportExcel').on('click', showImportForm);
    $('#adReplaceExcel').on('click', showReplaceForm);
    $('#adKpiPagosPendientes').on('click', function(){
      ADApp.go('ventas');
      // Set the Ventas activeTab to 'pago_pendiente' after nav
      setTimeout(function(){
        if (window.AD_ventas && typeof window.AD_ventas.render === 'function') {
          // AD_ventas reads _activeTab internally; simplest path is to click the tab
          var $t = $('.vtTab[data-tab="pago_pendiente"]');
          if ($t.length) $t.trigger('click');
        }
      }, 300);
    });
  }

  function bindViewToggle(){
    $('.adInvView').on('click', function(){
      _view = $(this).data('view');
      if (_view !== 'detalle_punto') _selectedPuntoId = null;
      render();
    });
  }

  // ═══════════════════════════════════════════════════════════════════════
  //  POR PUNTO — overview (card grid)
  // ═══════════════════════════════════════════════════════════════════════
  function paintPorPunto(r){
    var html = _backBtn+'<div class="ad-toolbar"><div class="ad-h1">CEDIS — Inventario por punto</div></div>';
    html += viewToggle();

    var puntos = (r && r.puntos) || [];
    if (!puntos.length) {
      html += '<div class="ad-card">No hay puntos registrados.</div>';
      ADApp.render(html);
      bindViewToggle();
      return;
    }

    // Summary across all points
    var totals = { consignacion:0, en_transito:0, en_ensamble:0, lista:0, disp:0, pagos:0 };
    puntos.forEach(function(p){
      totals.consignacion += p.consignacion_count;
      totals.en_transito  += p.en_transito_count;
      totals.en_ensamble  += p.en_ensamble_count;
      totals.lista        += p.lista_para_entrega_count;
      totals.disp         += p.disponible_venta_count;
      totals.pagos        += p.pagos_pendientes_count;
    });
    html += '<div class="ad-kpis" style="margin-bottom:14px;">';
    [{l:'Consignación',v:totals.consignacion,c:'blue'},
     {l:'En tránsito',v:totals.en_transito,c:'yellow'},
     {l:'En ensamble',v:totals.en_ensamble,c:'yellow'},
     {l:'Para entrega',v:totals.lista,c:'green'},
     {l:'Para venta',v:totals.disp,c:'green'},
     {l:'Pagos pendientes',v:totals.pagos,c:'red'}].forEach(function(k){
      html += '<div class="ad-kpi"><div class="label">'+k.l+'</div><div class="value '+k.c+'">'+k.v+'</div></div>';
    });
    html += '</div>';

    // Per-punto grid
    html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;">';
    puntos.forEach(function(p){
      var aging = p.aging_max_dias || 0;
      var agingColor = aging <= 30 ? '#059669' : aging <= 60 ? '#d97706' : '#dc2626';
      var agingNote = aging > 0
        ? '<span style="color:'+agingColor+';font-weight:700;">'+aging+' días</span> en consignación'
        : 'Sin consignación';

      html += '<div class="ad-card adPuntoCard" data-punto="'+p.id+'" style="cursor:pointer;transition:box-shadow .15s;">';
      html += '<div style="font-size:15px;font-weight:800;color:var(--ad-navy);margin-bottom:2px;">'+esc(p.nombre)+'</div>';
      html += '<div style="font-size:11px;color:var(--ad-dim);margin-bottom:10px;">'+esc(p.ciudad||'')+'</div>';

      html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 10px;font-size:12px;margin-bottom:8px;">';
      html += statRow('Consignación', p.consignacion_count, '#0ea5e9');
      html += statRow('En tránsito', p.en_transito_count, '#d97706');
      html += statRow('En ensamble', p.en_ensamble_count, '#6366f1');
      html += statRow('Para entrega', p.lista_para_entrega_count, '#059669');
      html += statRow('Para venta', p.disponible_venta_count, '#059669');
      html += statRow('Pagos pendientes', p.pagos_pendientes_count, '#dc2626');
      html += '</div>';

      if (aging > 0) {
        html += '<div style="font-size:11px;color:var(--ad-dim);padding-top:8px;border-top:1px dashed var(--ad-border);">Aging: '+agingNote+'</div>';
      }
      html += '</div>';
    });
    html += '</div>';

    ADApp.render(html);
    bindViewToggle();
    $('.adPuntoCard').on('click', function(){
      _selectedPuntoId = parseInt($(this).data('punto'), 10);
      _view = 'detalle_punto';
      render();
    });
  }

  function statRow(label, count, color){
    var c = count > 0 ? color : 'var(--ad-dim)';
    return '<div style="display:flex;justify-content:space-between;align-items:baseline;">'+
      '<span style="color:var(--ad-dim);">'+label+'</span>'+
      '<span style="font-weight:800;font-size:16px;color:'+c+';">'+count+'</span>'+
    '</div>';
  }

  // ═══════════════════════════════════════════════════════════════════════
  //  POR PUNTO — detail of a single punto
  // ═══════════════════════════════════════════════════════════════════════
  function paintDetallePunto(r){
    if (!r || !r.ok) {
      ADApp.render('<div class="ad-card">'+((r&&r.error)||'Error al cargar punto')+'</div>');
      return;
    }
    var html = '<button class="ad-back" id="adPBack"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver a puntos</button>';
    html += '<div class="ad-h1" style="margin-top:8px;">'+esc(r.punto.nombre)+'</div>';
    html += '<div style="color:var(--ad-dim);font-size:13px;margin-bottom:14px;">'+esc(r.punto.ciudad||'')+(r.punto.direccion?' · '+esc(r.punto.direccion):'')+'</div>';

    // Summary strip
    var s = r.resumen;
    html += '<div class="ad-kpis" style="margin-bottom:18px;">';
    [{l:'Consignación',v:s.consignacion,c:'blue'},
     {l:'En tránsito',v:s.en_transito,c:'yellow'},
     {l:'En ensamble',v:s.en_ensamble,c:'yellow'},
     {l:'Para entrega',v:s.lista_para_entrega,c:'green'},
     {l:'Para venta',v:s.disponible_venta,c:'green'},
     {l:'Pagos pendientes',v:s.pagos_pendientes,c:'red'}].forEach(function(k){
      html += '<div class="ad-kpi"><div class="label">'+k.l+'</div><div class="value '+k.c+'">'+k.v+'</div></div>';
    });
    html += '</div>';

    html += motoSection('Consignación (ventas directas)',  'Motos en el punto listas para venta directa en tienda. Alto aging = revisar.', r.consignacion, true);
    html += motoSection('En tránsito hacia este punto',    'Motos enviadas desde CEDIS, pendientes de recepción.', r.en_transito, false);
    html += motoSection('En ensamble',                     'Motos en proceso de ensamble en el punto.', r.en_ensamble, false);
    html += motoSection('Lista para entrega (cliente)',    'Motos con cliente asignado, listas para recogerse.', r.lista_para_entrega, false);
    html += motoSection('Disponible para venta directa',   'Motos recibidas sin cliente aún (walk-in).', r.disponible_venta, false);

    // Payment follow-up
    if (r.pagos_pendientes && r.pagos_pendientes.length) {
      html += '<div style="margin-top:24px;">';
      html += '<div style="font-weight:700;font-size:15px;color:var(--ad-navy);margin-bottom:8px;">Pagos pendientes vinculados al punto ('+r.pagos_pendientes.length+')</div>';
      html += '<div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Pedido</th><th>Cliente</th><th>Modelo</th><th>Monto</th><th>Método</th><th></th></tr></thead><tbody>';
      r.pagos_pendientes.forEach(function(p){
        html += '<tr>'+
          '<td><strong>VK-'+esc(p.pedido||p.id)+'</strong></td>'+
          '<td>'+esc(p.nombre||'—')+'<br><small class="ad-dim">'+esc(p.telefono||'')+'</small></td>'+
          '<td>'+esc(p.modelo||'')+' · '+esc(p.color||'')+'</td>'+
          '<td>'+ADApp.money(p.total)+'</td>'+
          '<td>'+esc(p.tpago||'')+'</td>'+
          '<td><button class="ad-btn sm" style="background:#d97706;color:#fff;" onclick="AD_ventas.showEnviarLink('+p.id+')">Enviar link</button></td>'+
        '</tr>';
      });
      html += '</tbody></table></div></div>';
    }

    ADApp.render(html);
    $('#adPBack').on('click', function(){
      _view = 'por_punto';
      _selectedPuntoId = null;
      render();
    });
    // Round 32: Ver button click + row-level click (anywhere on the row
    // except interactive children opens the detail). Both bind here.
    $('.adDetail').on('click',function(ev){ ev.stopPropagation(); showDetail($(this).data('id')); });
    $('.adInvRow').on('click', function(ev){
      // Don't hijack clicks on real interactive children (buttons, links,
      // inputs). Only fire when the click target is the row/cells.
      var tag = (ev.target.tagName || '').toLowerCase();
      if (tag === 'button' || tag === 'a' || tag === 'input' || tag === 'select' || tag === 'textarea') return;
      if ($(ev.target).closest('button,a,input,select,textarea').length) return;
      var id = $(this).data('id'); if (id) showDetail(id);
    });
  }

  function motoSection(title, subtitle, list, showAging){
    var html = '<div style="margin-top:20px;">';
    html += '<div style="font-weight:700;font-size:14px;color:var(--ad-navy);margin-bottom:2px;">'+title+' ('+list.length+')</div>';
    html += '<div style="font-size:12px;color:var(--ad-dim);margin-bottom:8px;">'+subtitle+'</div>';
    if (!list.length) {
      html += '<div class="ad-card" style="color:var(--ad-dim);font-size:13px;">— sin motos en esta categoría —</div>';
      return html + '</div>';
    }
    // Customer brief 2026-05-04: Año + Núm. Motor visible at the list
    // level (was previously only in the detail card). Wrapped in
    // overflow-x scroll so the extra columns don't clip the action button.
    // Round 4: replaced "Cliente" with "Asignación" (pedido_num + name)
    // so assigned bikes are unmistakable on this view too.
    html += '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table" style="min-width:1200px;"><thead><tr>'+
      '<th>VIN</th><th>Modelo</th><th>Año</th><th>Núm. Motor</th><th>Color</th><th>Estado</th>'+
      (showAging ? '<th>Días en punto</th>' : '')+
      '<th>Asignación</th><th>Pago</th><th></th></tr></thead><tbody>';
    list.forEach(function(m){
      var agingCell = '';
      if (showAging) {
        var d = m.dias_en_punto || 0;
        var c = d <= 30 ? '#059669' : d <= 60 ? '#d97706' : '#dc2626';
        agingCell = '<td><span style="font-weight:700;color:'+c+';">'+d+'d</span></td>';
      }
      var motorCell = m.num_motor
        ? '<code style="font-size:11px;font-family:ui-monospace,Menlo,monospace;background:var(--ad-surface-2);padding:2px 6px;border-radius:3px;" title="'+esc(m.num_motor)+'">'+esc(m.num_motor)+'</code>'
        : '<span style="color:#9ca3af;">—</span>';
      var asignada = !!(m.cliente_nombre || m.pedido_num || m.cliente_email);
      var asignacionCell;
      if (asignada) {
        var pedidoBadge = m.pedido_num
          ? '<span style="display:inline-block;background:#10b981;color:#fff;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;letter-spacing:.3px;margin-right:4px;">'+esc(m.pedido_num)+'</span>'
          : '';
        var clienteName = m.cliente_nombre
          ? '<span style="font-weight:600;color:#065f46;">'+esc(m.cliente_nombre)+'</span>'
          : (m.cliente_email ? '<small>'+esc(m.cliente_email)+'</small>' : '<small style="color:#9ca3af">— sin nombre —</small>');
        asignacionCell = pedidoBadge + clienteName;
      } else {
        asignacionCell = '<span style="color:#9ca3af;font-style:italic;">Sin asignar</span>';
      }
      // Round 32: same sticky-right + clickable row treatment as the
      // primary renderer above. Keeps the "Ver" affordance reachable on
      // narrow screens.
      var stickyBg2 = asignada ? '#f0fdf4' : 'var(--ad-surface,#fff)';
      html += '<tr class="adInvRow" data-id="'+m.id+'" style="cursor:pointer;'+(asignada?'background:#f0fdf4;':'')+'">'+
        '<td><code style="font-size:11px;">'+esc(m.vin_display||m.vin||'—')+'</code></td>'+
        '<td>'+esc(m.modelo||'')+'</td>'+
        '<td style="text-align:center;">'+(m.anio_modelo||'<span style="color:#9ca3af;">—</span>')+'</td>'+
        '<td>'+motorCell+'</td>'+
        '<td>'+esc(m.color||'')+'</td>'+
        '<td>'+ADApp.badgeEstado(m.estado||'')+'</td>'+
        agingCell+
        '<td>'+asignacionCell+'</td>'+
        '<td>'+ADApp.badgeEstado(m.pago_estado||'—')+'</td>'+
        '<td style="position:sticky;right:0;background:'+stickyBg2+';box-shadow:-2px 0 6px rgba(0,0,0,.04);"><button class="ad-btn sm ghost adDetail" data-id="'+m.id+'">Ver</button></td>'+
      '</tr>';
    });
    // Extra </div> closes the overflow-x wrapper added for the new
    // Año + Núm. Motor columns (customer brief 2026-05-04).
    html += '</tbody></table></div></div></div>';
    return html;
  }

  function esc(s){ return (s==null?'':String(s)).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function showDetail(id){
    ADApp.api('inventario/detalle.php?id='+id).done(function(r){
      var m=r.moto; if(!m) return;

      // ── Styled helpers ──
      var secIx = 0;
      function secHead(title, icon){
        return '<div style="display:flex;align-items:center;gap:8px;margin:24px 0 12px;padding-bottom:10px;border-bottom:1px solid var(--ad-border);">'+
          '<div style="color:var(--ad-primary);">'+icon+'</div>'+
          '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ad-primary);">'+title+'</div></div>';
      }
      function fRow(label, value){
        var bg = secIx++ % 2 === 0 ? 'background:var(--ad-surface-2);' : '';
        return '<div style="'+bg+'padding:8px 14px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(0,0,0,.04);">'+
          '<span style="color:var(--ad-dim);font-size:12px;font-weight:500;white-space:nowrap;margin-right:12px;">'+label+'</span>'+
          '<span style="font-size:13px;font-weight:600;color:var(--ad-navy);text-align:right;">'+(value||'—')+'</span></div>';
      }

      // ── Modal header ──
      var estadoColor = {por_llegar:'blue',recibida:'green',por_ensamblar:'yellow',en_ensamble:'yellow',lista_para_entrega:'green',entregada:'green',retenida:'red'}[m.estado]||'gray';
      var html = '<div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;padding-bottom:18px;border-bottom:2px solid var(--ad-border);">';
      html += '<div style="width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#039fe1,#0280b5);display:flex;align-items:center;justify-content:center;flex-shrink:0;">';
      html += '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></div>';
      html += '<div style="flex:1;min-width:0;"><div style="font-size:20px;font-weight:800;color:var(--ad-navy);line-height:1.2;">'+m.modelo+' — '+m.color+'</div>';
      html += '<div style="display:flex;align-items:center;gap:8px;margin-top:4px;"><span class="ad-badge '+estadoColor+'">'+m.estado+'</span>';
      if(m.vin_display||m.vin) html += '<code style="font-size:11px;background:var(--ad-surface-2);padding:2px 8px;border-radius:4px;">'+( m.vin_display||m.vin)+'</code>';
      html += '</div></div></div>';

      // ── Vehículo ──
      secIx = 0;
      html += secHead('Vehículo','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>');
      html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:8px;">';
      html += fRow('VIN', '<code style="font-size:11px;background:var(--ad-surface-2);padding:2px 6px;border-radius:4px;">'+(m.vin_display||m.vin)+'</code>');
      html += fRow('Año modelo', m.anio_modelo||'—');
      html += fRow('Núm. motor', m.num_motor||'—');
      html += fRow('Potencia', m.potencia||'—');
      html += fRow('Baterías', m.config_baterias||'—');
      html += fRow('Hecho en', m.hecho_en||'—');
      var tipoAsigLabel = {'voltika_entrega':'Entrega con orden','consignacion':'Consignación'}[m.tipo_asignacion] || m.tipo_asignacion || '—';
      html += fRow('Tipo asignación', tipoAsigLabel);
      html += '</div>';

      // ── Importación ──
      if(m.num_pedimento || m.aduana || m.cedis_origen){
        secIx = 0;
        html += secHead('Importación','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>');
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:8px;">';
        html += fRow('Pedimento', m.num_pedimento||'—');
        html += fRow('Aduana', m.aduana||'—');
        html += fRow('Ingreso país', m.fecha_ingreso_pais||'—');
        html += fRow('CEDIS origen', m.cedis_origen||'—');
        html += '</div>';
      }

      // ── Cliente / Pedido ──
      secIx = 0;
      html += secHead('Cliente / Pedido','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>');
      html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:8px;">';
      html += fRow('Cliente', m.cliente_nombre||'—');
      html += fRow('Email', m.cliente_email||'—');
      html += fRow('Teléfono', m.cliente_telefono||'—');
      html += fRow('Pedido', m.pedido_num||'—');
      html += fRow('Pago', m.pago_estado ? '<span class="ad-badge '+(m.pago_estado==='pagada'?'green':'yellow')+'">'+m.pago_estado+'</span>' : '—');
      html += fRow('Punto', m.punto_voltika_nombre||'—');
      if(m.dias_en_punto !== null && m.dias_en_punto !== undefined){
        var dp = parseInt(m.dias_en_punto);
        var dpColor = dp <= 7 ? '#059669' : dp <= 30 ? '#d97706' : '#dc2626';
        html += fRow('Días en punto', '<span style="font-weight:700;font-size:15px;color:'+dpColor+';">'+dp+' día'+(dp!==1?'s':'')+'</span>');
      }
      html += fRow('Método pago', r.transaccion ? '<span style="display:inline-block;padding:2px 10px;border-radius:20px;background:rgba(3,159,225,.08);color:#039fe1;font-size:12px;font-weight:600;">'+r.transaccion.tpago+'</span>' : '—');
      html += fRow('Monto', r.transaccion ? '<span style="font-size:15px;font-weight:800;">'+ADApp.money(r.transaccion.total)+'</span>' : '—');
      html += fRow('Stripe PI', m.stripe_pi ? '<code style="font-size:10px;background:var(--ad-surface-2);padding:2px 6px;border-radius:4px;">'+m.stripe_pi+'</code>' : '—');
      html += fRow('Precio venta', m.precio_venta ? '<span style="font-weight:700;">'+ADApp.money(m.precio_venta)+'</span>' : '—');
      html += '</div>';

      // Customer brief 2026-05-04 round 8: contract download direct from
      // the moto detail card. logistica/cedis users with `inventario`
      // permission can now check the signed contract for the order
      // assigned to this bike without bouncing through Ventas (which
      // they may not have permission to see).
      if (m.pedido_num) {
        var pedidoOnly = String(m.pedido_num).replace(/^VK-/, '');
        var contratoUrl = '/configurador/php/descargar-contrato.php?pedido='
                        + encodeURIComponent(pedidoOnly) + '&inline=1&debug=1';
        html += '<div style="margin:10px 0 14px;">'
              +   '<a href="'+contratoUrl+'" target="_blank" rel="noopener" '
              +      'class="ad-btn sm" '
              +      'style="background:#fff;color:#039fe1;border:1px solid #039fe1;padding:8px 14px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;">'
              +      '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>'
              +      'Ver contrato del pedido '+esc(m.pedido_num)
              +   '</a>'
              + '</div>';
      }

      // ── Fechas ──
      secIx = 0;
      html += secHead('Fechas','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>');
      html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:8px;">';
      html += fRow('Llegada', m.fecha_llegada||'—');
      html += fRow('Estimada llegada', m.fecha_estimada_llegada||'—');
      html += fRow('Estimada entrega', m.fecha_entrega_estimada||'—');
      html += fRow('Último cambio estado', m.fecha_estado||'—');
      html += fRow('Días en paso', '<span style="font-weight:700;color:'+(parseInt(m.dias_en_paso||0)>5?'var(--ad-danger)':'var(--ad-navy)')+';">'+(m.dias_en_paso||'0')+'</span>');
      html += fRow('Recepción', m.recepcion_completada
        ? '<span style="display:inline-flex;align-items:center;gap:4px;color:#059669;font-weight:600;"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#059669" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>Completada</span>'
        : '<span style="color:var(--ad-warning);font-weight:600;">Pendiente</span>');
      html += '</div>';

      // ── Notas ──
      if(m.notas){
        html += secHead('Notas','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>');
        html += '<div style="font-size:13px;padding:12px 16px;background:var(--ad-surface-2);border-radius:8px;border-left:3px solid var(--ad-primary);line-height:1.6;color:var(--ad-navy);">'+m.notas+'</div>';
      }

      // ── Checklists ──
      html += secHead('Checklists','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>');
      html += '<div style="display:flex;gap:10px;flex-wrap:wrap;">';
      var clItems = [
        {key:'checklist_origen',   label:'Origen'},
        {key:'checklist_ensamble', label:'Ensamble'},
        {key:'checklist_entrega',  label:'Entrega'}
      ];
      clItems.forEach(function(cl){
        var done = r[cl.key] && r[cl.key].completado;
        var bg = done ? 'background:rgba(5,150,105,.08);border:1.5px solid rgba(5,150,105,.25);color:#059669;' : 'background:rgba(234,179,8,.08);border:1.5px solid rgba(234,179,8,.25);color:#92400e;';
        var icon = done
          ? '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#059669" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>'
          : '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#92400e" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
        html += '<div style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;'+bg+'">'+icon+' '+cl.label+'</div>';
      });
      html += '</div>';

      // ── Envíos ──
      // Bug fix 2026-05-09 (cliente report): el panel de detalle mostraba
      // los envíos del VIN en modo solo-lectura — cuando se reasignaba
      // la moto a otro punto los envíos viejos quedaban visibles sin
      // posibilidad de cerrarlos. Ahora cada envío activo muestra un
      // botón "Cerrar" rojo que llama a envios/eliminar.php (soft-close
      // estado='completado_no_exitoso'). El botón solo aparece cuando
      // el operador tiene permiso de escritura sobre inventario.
      if(r.envios&&r.envios.length){
        html += secHead('Envíos','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>');
        r.envios.forEach(function(e){
          var canEdit = ADApp.canWrite('inventario');
          var isActiveEstado = ['lista_para_enviar','enviada','enviado','en_transito']
            .indexOf(String(e.estado||'').toLowerCase()) !== -1;
          html += '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 14px;background:var(--ad-surface-2);border-radius:8px;margin-bottom:6px;font-size:13px;flex-wrap:wrap;">';
          html += '<span style="font-weight:600;color:var(--ad-navy);flex:1;min-width:0;">'+(e.punto_nombre||'—')+'</span>';
          html += '<span style="display:flex;align-items:center;gap:8px;flex-shrink:0;">'+ADApp.badgeEstado(e.estado)+'<span style="font-size:11px;color:var(--ad-dim);">'+(e.fecha_envio||'sin fecha')+'</span></span>';
          if (canEdit && isActiveEstado) {
            html += '<button class="ad-btn sm ghost adInvEnvCerrar" data-id="'+e.id+'" '+
                    'data-punto="'+(e.punto_nombre||'').replace(/"/g,'&quot;')+'" '+
                    'style="color:#b91c1c;border-color:#b91c1c;padding:4px 10px;font-size:11.5px;flex-shrink:0;">Cerrar</button>';
          }
          html += '</div>';
        });
      }

      // ── Round 31 (2026-05-14, Óscar) — Recepción info ──────────────────
      // Show the full recepción event (who received, when, integrity
      // checks, seal info, 3 photos) inline so admins don't have to
      // switch to the punto historial view to investigate. Photos use the
      // admin-side serve-recepcion-foto.php helper which bypasses Plesk's
      // .htaccess block on /configurador/php/uploads/recepcion/.
      if (r.recepcion) {
        var rcp = r.recepcion;
        html += secHead('Recepción en el punto','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.45-6.89A2 2 0 0016.76 4H7.24a2 2 0 00-1.79 1.11z"/></svg>');
        var rcpCompletada = rcp.completado == 1 || rcp.completado === '1';
        var bg = rcpCompletada ? 'rgba(5,150,105,.06)' : 'rgba(217,119,6,.07)';
        var bd = rcpCompletada ? 'rgba(5,150,105,.20)' : 'rgba(217,119,6,.20)';
        var stateLabel = rcpCompletada
          ? '<span style="color:#059669;font-weight:700;">✓ Recepción OK</span>'
          : '<span style="color:#b45309;font-weight:700;">⏳ Recepción pendiente</span>';
        html += '<div style="padding:14px 16px;border-radius:8px;background:'+bg+';border:1px solid '+bd+';margin-bottom:12px;font-size:13px;">';
        html += '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;margin-bottom:10px;">'+
                  '<div>'+stateLabel+'</div>'+
                  '<div style="font-size:11.5px;color:var(--ad-dim);text-align:right;">'+
                    (rcp.fecha_recepcion ? '<div><strong>Fecha:</strong> '+rcp.fecha_recepcion+'</div>' : '')+
                    (rcp.recibido_por_nombre ? '<div><strong>Recibió:</strong> '+rcp.recibido_por_nombre+'</div>' : '')+
                    (rcp.punto_nombre ? '<div><strong>Punto:</strong> '+rcp.punto_nombre+'</div>' : '')+
                  '</div>'+
                '</div>';
        // Integrity checklist — show each check with green ✓ / red ✗.
        function rcpCheck(label, val){
          var ok = val == 1 || val === 1 || val === '1' || val === true;
          var unset = (val === null || typeof val === 'undefined');
          var icon = unset ? '<span style="color:#cbd5e1;">○</span>' :
                     (ok ? '<span style="color:#059669;">✓</span>' : '<span style="color:#b91c1c;">✗</span>');
          return '<div style="font-size:12.5px;padding:3px 0;">'+icon+' '+label+'</div>';
        }
        html += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:4px 16px;margin-top:6px;">';
        html += rcpCheck('VIN escaneado coincide',    rcp.vin_coincide);
        html += rcpCheck('Estado físico OK',          rcp.estado_fisico_ok);
        html += rcpCheck('Sin daños visibles',        rcp.sin_danos);
        html += rcpCheck('Componentes completos',     rcp.componentes_completos);
        html += rcpCheck('Batería OK',                rcp.bateria_ok);
        if (typeof rcp.sello_intacto !== 'undefined' && rcp.sello_intacto !== null) {
          html += rcpCheck('Sello aplicado e intacto',rcp.sello_intacto);
        }
        html += '</div>';
        // VIN + sello details (when present)
        var hasIds = rcp.vin_escaneado || rcp.vin_caja || rcp.sello_numero;
        if (hasIds) {
          html += '<div style="margin-top:10px;padding-top:8px;border-top:1px dashed rgba(0,0,0,.08);font-size:12px;color:#374151;">';
          if (rcp.vin_escaneado) html += '<div><span style="color:var(--ad-dim);">VIN escaneado:</span> <code>'+rcp.vin_escaneado+'</code></div>';
          if (rcp.vin_caja)      html += '<div><span style="color:var(--ad-dim);">VIN en caja:</span> <code>'+rcp.vin_caja+'</code></div>';
          if (rcp.sello_numero)  html += '<div><span style="color:var(--ad-dim);">Número de sello:</span> '+rcp.sello_numero+'</div>';
          html += '</div>';
        }
        // Photos — thumbnails that open in a new tab. Server-side
        // (detalle.php Round 31 v2) already verified file existence, so
        // any non-null URL here is guaranteed to load. Missing photos
        // come back as null and get rendered as a polite placeholder
        // explaining what happened + how to fix.
        function rcpPhotoThumb(url, label){
          var safe = String(url).replace(/"/g,'&quot;');
          return '<a href="'+safe+'" target="_blank" rel="noopener" '+
                   'style="display:inline-block;width:88px;height:88px;border-radius:6px;overflow:hidden;border:1px solid #cbd5e1;background:#f1f5f9;margin-right:6px;margin-bottom:6px;text-decoration:none;vertical-align:top;" '+
                   'title="'+esc(label)+'">'+
                   '<img src="'+safe+'" alt="'+esc(label)+'" '+
                        'style="width:100%;height:100%;object-fit:cover;" loading="lazy">'+
                 '</a>';
        }
        function rcpPhotoMissing(label){
          return '<div style="display:inline-block;width:88px;height:88px;border-radius:6px;border:1px dashed #cbd5e1;background:#f8fafc;margin-right:6px;margin-bottom:6px;vertical-align:top;'+
                       'display:inline-flex;align-items:center;justify-content:center;text-align:center;padding:6px;box-sizing:border-box;" '+
                   'title="Foto '+esc(label)+' no disponible">'+
                   '<div style="font-size:10px;color:#94a3b8;line-height:1.3;">'+
                     '📷<br><strong style="color:#64748b;">'+esc(label)+'</strong><br>'+
                     '<span style="font-size:9px;">no disponible</span>'+
                   '</div>'+
                 '</div>';
        }
        var photoSlots = [
          { url: rcp.foto_sello_url,     label: 'Sello' },
          { url: rcp.foto_vin_label_url, label: 'VIN etiqueta' },
          { url: rcp.foto_unidad_url,    label: 'Unidad' },
        ];
        (rcp.fotos_extra || []).forEach(function(u, i){
          photoSlots.push({ url: u, label: 'Extra ' + (i+1) });
        });
        var photos = '';
        var anyMissing = false, anyPresent = false;
        photoSlots.forEach(function(p){
          if (p.url) { photos += rcpPhotoThumb(p.url, p.label); anyPresent = true; }
          else       { photos += rcpPhotoMissing(p.label);      anyMissing = true; }
        });
        html += '<div style="margin-top:10px;padding-top:8px;border-top:1px dashed rgba(0,0,0,.08);">'+
                  '<div style="font-size:11px;color:var(--ad-dim);margin-bottom:6px;text-transform:uppercase;letter-spacing:.3px;">Fotos</div>'+
                  photos;
        if (anyMissing) {
          var missingCount = (rcp.fotos_missing_count != null)
            ? rcp.fotos_missing_count
            : photoSlots.filter(function(p){ return !p.url; }).length;
          html += '<div style="margin-top:10px;padding:10px 12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;font-size:12px;color:#7c2d12;line-height:1.5;">'+
                    '<strong>📷 '+missingCount+' foto'+(missingCount===1?'':'s')+' no '+(missingCount===1?'está':'están')+' disponible'+(missingCount===1?'':'s')+'.</strong> '+
                    'La imagen original no se guardó correctamente al recibir esta moto (problema técnico anterior). '+
                    'Para arreglarlo, pídele al operador del punto que abra <em>Recepción → Historial</em>, '+
                    'busque este registro y vuelva a subir las fotos faltantes desde ahí.'+
                  '</div>';
        }
        if (!anyPresent && !anyMissing) {
          // truly no photo slots at all (legacy row without optional cols)
          html = html.replace(/<div style="font-size:11px[^>]+>Fotos<\/div>/, '');
          html += '<div style="font-size:11.5px;color:var(--ad-dim);font-style:italic;">Sin fotos adjuntas en este registro de recepción.</div>';
        }
        html += '</div>';
        // Notes + observations
        if (rcp.notas) {
          html += '<div style="margin-top:10px;padding:8px 10px;background:#fff;border-radius:6px;font-size:12.5px;border:1px solid rgba(0,0,0,.06);"><strong>Notas:</strong><br>'+esc(rcp.notas)+'</div>';
        }
        if (rcp.observaciones) {
          html += '<div style="margin-top:6px;padding:8px 10px;background:#fff;border-radius:6px;font-size:12.5px;border:1px solid rgba(0,0,0,.06);"><strong>Observaciones:</strong><br>'+esc(rcp.observaciones)+'</div>';
        }
        html += '</div>';
      }

      // ── Round 28 (2026-05-14, Óscar Pesgo Plus VIN ...12) ───────────────
      // Retención (estado='retenida'): the moto is on operational hold,
      // independent of bloqueado_venta. Before Round 28 there was no UI
      // affordance to see WHO retained it, WHEN, WHY, or to Liberar.
      // detalle.php now parses log_estados and returns r.retencion =
      // { fecha, usuario, usuario_nombre, notas, ... } when applicable.
      var isRetenida = String(m.estado||'').toLowerCase() === 'retenida';
      if (isRetenida) {
        var ret = r.retencion || {};
        html += secHead('Retención operativa','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>');
        html += '<div style="display:flex;align-items:flex-start;gap:10px;padding:14px 16px;border-radius:8px;background:rgba(217,119,6,.07);border:1px solid rgba(217,119,6,.20);margin-bottom:12px;">';
        html += '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#b45309" stroke-width="2" style="flex-shrink:0;margin-top:2px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
        html += '<div style="flex:1;min-width:0;">';
        html += '<div style="font-weight:700;color:#b45309;font-size:13px;">Moto retenida (estado operativo)</div>';
        html += '<div style="font-size:11.5px;color:#92400e;margin-top:2px;line-height:1.5;">Este es un retén operativo (almacén/CEDIS). Es distinto del <strong>bloqueo de venta</strong> de abajo — un retén puede existir sin bloqueo formal.</div>';
        // Body: actor + fecha + notas (when present)
        var retActor = ret.usuario_nombre ? esc(ret.usuario_nombre) :
                       (ret.usuario ? ('usuario #'+esc(ret.usuario)) : null);
        if (retActor)     html += '<div style="font-size:12px;color:#78350f;margin-top:8px;"><strong>Retenida por:</strong> '+retActor+'</div>';
        if (ret.fecha)    html += '<div style="font-size:12px;color:#78350f;margin-top:2px;"><strong>Fecha:</strong> '+esc(ret.fecha)+'</div>';
        if (ret.notas)    html += '<div style="font-size:12px;color:#78350f;margin-top:2px;line-height:1.5;"><strong>Motivo:</strong> '+esc(ret.notas)+'</div>';
        if (!retActor && !ret.fecha && !ret.notas) {
          html += '<div style="font-size:12px;color:#92400e;margin-top:8px;font-style:italic;">Sin registro en <code>log_estados</code> — la retención pudo haberse aplicado por SQL directo o en una versión previa del sistema. Si no hay motivo válido, puedes liberar la moto.</div>';
        }
        html += '</div></div>';
        if (ADApp.canWrite && ADApp.canWrite('inventario')) {
          html += '<button class="ad-btn ghost" id="adLiberarMoto" data-id="'+m.id+'" style="color:#059669;border-color:#059669;">Liberar moto (estado &rarr; recibida)</button>';
        }
      }

      // ── Bloqueo de venta ──
      var isBloqueada = parseInt(m.bloqueado_venta) === 1;
      if(ADApp.canWrite('inventario')){
        html += secHead('Bloqueo de venta','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>');
        if(isBloqueada){
          html += '<div style="display:flex;align-items:flex-start;gap:10px;padding:14px 16px;border-radius:8px;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.15);margin-bottom:12px;">';
          html += '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#b91c1c" stroke-width="2" style="flex-shrink:0;margin-top:2px;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>';
          html += '<div><div style="font-weight:700;color:#b91c1c;font-size:13px;">Moto bloqueada para venta</div>';
          html += '<div style="font-size:12px;color:#b91c1c;margin-top:4px;">Motivo: '+(m.bloqueado_motivo||'Sin motivo')+'</div>';
          if(m.bloqueado_fecha) html += '<div style="font-size:11px;color:var(--ad-dim);margin-top:2px;">Fecha: '+m.bloqueado_fecha+'</div>';
          html += '</div></div>';
          html += '<button class="ad-btn ghost" id="adUnlockMoto" data-id="'+m.id+'" style="color:#059669;border-color:#059669;">Desbloquear moto</button>';
        } else {
          html += '<div style="display:flex;align-items:center;gap:8px;padding:10px 16px;border-radius:8px;background:rgba(5,150,105,.06);border:1px solid rgba(5,150,105,.15);color:#059669;font-size:12px;margin-bottom:12px;">';
          html += '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#059669" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 019.9-1"/></svg>';
          html += 'Moto disponible para venta (no bloqueada)</div>';
          html += '<button class="ad-btn ghost" id="adLockMoto" data-id="'+m.id+'" style="color:#b91c1c;border-color:#b91c1c;">Bloquear moto</button>';
        }
      }

      // ── Venta al público ──
      if(ADApp.canWrite('inventario') && m.punto_voltika_id && m.tipo_asignacion === 'consignacion'){
        var isVentaPublico = parseInt(m.venta_publico) === 1;
        html += secHead('Venta al p\u00fablico','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>');
        html += '<div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-radius:8px;background:'+(isVentaPublico?'rgba(5,150,105,.06)':'rgba(107,114,128,.06)')+';border:1px solid '+(isVentaPublico?'rgba(5,150,105,.15)':'rgba(107,114,128,.15)')+';margin-bottom:12px;">';
        html += '<div>';
        html += '<div style="font-weight:700;font-size:13px;color:'+(isVentaPublico?'#059669':'#6b7280')+';">'+(isVentaPublico?'Disponible en configurador':'No visible en configurador')+'</div>';
        html += '<div style="font-size:11px;color:var(--ad-dim);margin-top:2px;">Permite que esta moto aparezca como disponible para compra en l\u00ednea.</div>';
        html += '</div>';
        html += '<div id="adToggleVentaPublico" data-id="'+m.id+'" data-val="'+(isVentaPublico?'0':'1')+'" style="width:44px;height:24px;border-radius:24px;background:'+(isVentaPublico?'#059669':'#ccc')+';position:relative;cursor:pointer;flex-shrink:0;transition:background .3s;">';
        html += '<div style="position:absolute;height:18px;width:18px;left:'+(isVentaPublico?'23px':'3px')+';top:3px;background:white;border-radius:50%;transition:left .3s;box-shadow:0 1px 3px rgba(0,0,0,.2);"></div>';
        html += '</div>';
        html += '</div>';
      }

      // ── Acciones ──
      var origenOk = r.checklist_origen && r.checklist_origen.completado;
      if(ADApp.canWrite('inventario')){
        html += secHead('Acciones','<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>');
        if(!origenOk){
          html += '<div style="display:flex;align-items:center;gap:8px;padding:12px 16px;border-radius:8px;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.15);color:#b91c1c;font-size:12px;margin-bottom:12px;">';
          html += '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#b91c1c" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
          html += 'El checklist de origen debe estar completo antes de asignar a un punto.</div>';
        }
        if(isBloqueada){
          html += '<div style="display:flex;align-items:center;gap:8px;padding:12px 16px;border-radius:8px;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.15);color:#b91c1c;font-size:12px;margin-bottom:12px;">';
          html += '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#b91c1c" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>';
          html += 'Esta moto está bloqueada para venta. Desbloquéala primero para poder asignarla.</div>';
        }
        var canAssign = origenOk && !isBloqueada;
        html += '<div style="display:flex;gap:10px;flex-wrap:wrap;">';
        html += '<button class="ad-btn primary" id="adAssign" data-id="'+m.id+'" '+(canAssign?'':'disabled style="opacity:.5;cursor:not-allowed;"')+'>Asignar a punto</button>';
        html += '<button class="ad-btn ghost" id="adVerifyPay" data-id="'+m.id+'">Verificar pago</button>';
        html += '</div>';
      }
      ADApp.modal(html);
      $('#adAssign').on('click',function(){ if(!origenOk || isBloqueada) return; assignToPunto(m.id, {modelo:m.modelo,color:m.color}); });
      $('#adLockMoto').on('click',function(){ showLockModal(m.id); });
      $('#adUnlockMoto').on('click',function(){ unlockMoto(m.id); });
      // Round 28: Liberar button — flips estado from 'retenida' to 'recibida'
      // via the existing admin-moto-accion.php transition (action='liberar').
      $('#adLiberarMoto').on('click', function(){ liberarMoto(m.id); });
      // Bug fix 2026-05-09: cerrar envíos individuales desde el detalle
      // del inventario. Cada botón rojo "Cerrar" en la sección Envíos
      // dispara un confirm + motivo opcional + POST a eliminar.php.
      $('.adInvEnvCerrar').on('click', function(){
        var envioId = $(this).data('id');
        var puntoNombre = $(this).data('punto') || '';
        var motivo = window.prompt(
          'Cerrar envío a "' + puntoNombre + '"\n\n'
          + 'Motivo (opcional, ej. "moto reasignada", "envío duplicado"):',
          ''
        );
        if (motivo === null) return; // user clicked Cancel
        ADApp.api('envios/eliminar.php', { envio_id: envioId, motivo: motivo })
          .done(function(r){
            if (r.ok) {
              showDetail(m.id); // refresh modal so closed envío disappears from active section
            } else {
              alert(r.error || 'Error al cerrar el envío');
            }
          })
          .fail(function(x){
            alert((x.responseJSON&&x.responseJSON.error)||'Error de conexión');
          });
      });
      $('#adToggleVentaPublico').on('click',function(){
        var $t = $(this);
        var val = parseInt($t.data('val'));
        $t.css('pointer-events','none');
        ADApp.api('inventario/toggle-venta-publico.php',{moto_id:m.id,venta_publico:val}).done(function(res){
          if(res.ok){ showDetail(m.id); }
          else { alert(res.error||'Error'); $t.css('pointer-events',''); }
        }).fail(function(){ alert('Error de conexión'); $t.css('pointer-events',''); });
      });
      $('#adVerifyPay').on('click',function(){
        ADApp.api('pagos/verificar.php',{moto_id:m.id}).done(function(r2){
          var msg = r2.verificado ? 'Pago verificado correctamente' : 'No verificado: '+(r2.stripe_status||'sin Stripe PI');
          var bg = r2.verificado ? 'rgba(5,150,105,.08)' : 'rgba(239,68,68,.08)';
          var clr = r2.verificado ? '#059669' : '#b91c1c';
          ADApp.modal('<div style="text-align:center;padding:30px 20px;">'+(r2.verificado?'<svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="#059669" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>':'<svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="#b91c1c" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>')+'<div style="margin-top:12px;font-size:15px;font-weight:700;color:'+clr+';">'+msg+'</div></div>');
        });
      });
    });
  }
  function assignToPunto(motoId, motoInfo){
    // Step 1: Choose assignment type
    var html = '<div class="ad-h2">Asignar moto</div>'+
      '<div class="ad-dim" style="margin-bottom:16px;">Selecciona el tipo de asignación:</div>'+
      '<div id="adAssignOptions" style="display:flex;flex-direction:column;gap:10px;">'+
        '<div class="ad-card" style="cursor:pointer;padding:16px;border:2px solid transparent;" id="adOptInventario">'+
          '<strong style="font-size:15px;">Inventario para venta en punto</strong>'+
          '<div class="ad-dim" style="margin-top:4px;">Enviar moto al punto para exhibición y venta directa. Sin orden de compra vinculada.</div>'+
        '</div>'+
        '<div class="ad-card" style="cursor:pointer;padding:16px;border:2px solid transparent;" id="adOptVenta">'+
          '<strong style="font-size:15px;">Venta</strong>'+
          '<div class="ad-dim" style="margin-top:4px;">Vincular esta moto a una orden de compra existente y enviar al punto de entrega.</div>'+
        '</div>'+
      '</div>';
    ADApp.modal(html);

    $('#adOptInventario').on('click', function(){ assignInventario(motoId); });
    $('#adOptVenta').on('click', function(){ assignVenta(motoId, motoInfo); });
  }

  // ── Option A: Inventario para venta en punto ──────────────────────────
  function assignInventario(motoId){
    ADApp.api('puntos/listar.php').done(function(r){
      var html = '<div class="ad-h2">Inventario — Seleccionar punto</div>';
      html += '<div id="adPuntosList">';
      (r.puntos||[]).forEach(function(p){
        html += '<div class="ad-card adPuntoCard" style="cursor:pointer;padding:10px;margin-bottom:6px;" data-pid="'+p.id+'" data-cp="'+(p.cp||'')+'">'+
          '<strong>'+p.nombre+'</strong> · '+(p.ciudad||'')+' · Inv: '+(p.inventario_actual||0)+
        '</div>';
      });
      html += '</div>';
      html += '<div id="adQuoteInfo" style="display:none;margin-top:12px;padding:12px;border-radius:8px;background:#E3F2FD;font-size:13px;"></div>';
      html += '<div id="adAssignBtn" style="display:none;margin-top:12px;"></div>';
      ADApp.modal(html);

      $('.adPuntoCard').on('click', function(){
        $('.adPuntoCard').css('border-color','transparent');
        $(this).css('border-color','var(--ad-primary)');
        var pid = $(this).data('pid');
        var cp = $(this).data('cp');
        showQuoteAndConfirm(motoId, pid, 'inventario', null, cp);
      });
    });
  }

  // ── Option B: Venta ───────────────────────────────────────────────────
  function assignVenta(motoId, motoInfo){
    ADApp.api('ventas/sin-punto.php').done(function(r){
      if(!r.ok || !r.rows.length){
        ADApp.modal(
          '<div class="ad-h2">Venta — Sin órdenes pendientes</div>'+
          '<div class="ad-dim" style="padding:20px;text-align:center;">No hay órdenes de compra sin moto asignada.</div>'
        );
        return;
      }

      var html = '<div class="ad-h2">Venta — Seleccionar orden</div>'+
        '<div class="ad-dim" style="margin-bottom:10px;">Órdenes sin moto asignada: <strong>'+r.rows.length+'</strong></div>'+
        '<div style="max-height:400px;overflow-y:auto;">';

      r.rows.forEach(function(o){
        var hasPunto = o.punto_id && o.punto_nombre;
        html += '<div class="ad-card adOrderCard" style="cursor:pointer;padding:10px;margin-bottom:6px;" '+
          'data-tid="'+o.id+'" data-pid="'+(o.punto_id||'')+'" data-pname="'+(o.punto_nombre||'')+'">'+
          '<div style="display:flex;justify-content:space-between;align-items:center;">'+
            '<div>'+
              '<strong>VK-'+(o.pedido||o.id)+'</strong> · '+(o.nombre||'')+'<br>'+
              '<small class="ad-dim">'+o.modelo+' · '+o.color+' · '+ADApp.money(o.monto)+'</small>'+
            '</div>'+
            '<div style="text-align:right;">'+
              (hasPunto
                ? '<span class="ad-badge green" style="font-size:11px;">Punto: '+o.punto_nombre+'</span>'
                : '<span class="ad-badge yellow" style="font-size:11px;">Sin punto</span>')+
            '</div>'+
          '</div>'+
        '</div>';
      });
      html += '</div>';
      html += '<div id="adVentaStep2" style="display:none;margin-top:12px;"></div>';
      html += '<div id="adQuoteInfo" style="display:none;margin-top:12px;padding:12px;border-radius:8px;background:#E3F2FD;font-size:13px;"></div>';
      html += '<div id="adAssignBtn" style="display:none;margin-top:12px;"></div>';
      ADApp.modal(html);

      $('.adOrderCard').on('click', function(){
        $('.adOrderCard').css('border-color','transparent');
        $(this).css('border-color','var(--ad-primary)');
        var tid = $(this).data('tid');
        var pid = $(this).data('pid');
        var pname = $(this).data('pname');

        if(pid){
          // User already chose a punto → auto-assign, just confirm
          $('#adVentaStep2').html(
            '<div style="padding:10px;background:#E8F5E9;border-radius:8px;font-size:13px;">'+
              'Punto seleccionado por el cliente: <strong>'+pname+'</strong>'+
            '</div>'
          ).show();
          // Find punto_voltika_id from punto_id string. Pass motoId/tid so
          // the fallback path (punto not found in catalog) can still show
          // the selector WITH the correct transaccion_id — the previous
          // version leaked closure vars and dropped transId, causing the
          // "transaccion_id requerido para tipo venta" 400 error.
          lookupPuntoId(pid, motoId, tid, function(pvId, cp){
            showQuoteAndConfirm(motoId, pvId, 'venta', tid, cp);
          });
        } else {
          // No punto selected → show punto list
          $('#adVentaStep2').show();
          loadPuntosForVenta(motoId, tid);
        }
      });
    });
  }

  function lookupPuntoId(puntoIdStr, motoId, transId, callback){
    ADApp.api('puntos/listar.php').done(function(r){
      var found = null;
      (r.puntos||[]).forEach(function(p){
        // Match by id or by nombre
        if(String(p.id) === String(puntoIdStr) || p.nombre === puntoIdStr){
          found = p;
        }
      });
      if(found){
        callback(found.id, found.cp||'');
      } else {
        // Fallback: show the manual selector with the original motoId +
        // transId preserved. Dropping transId here is what caused the
        // 400 "transaccion_id requerido" bug.
        $('#adQuoteInfo').html('<div style="color:orange;">No se encontró el punto del cliente. Selecciona manualmente.</div>').show();
        loadPuntosForVenta(motoId, transId);
      }
    });
  }

  function loadPuntosForVenta(motoId, transId){
    ADApp.api('puntos/listar.php').done(function(r){
      var html = '<div style="font-weight:600;margin-bottom:8px;">Seleccionar punto de entrega:</div>';
      (r.puntos||[]).forEach(function(p){
        html += '<div class="ad-card adPuntoVenta" style="cursor:pointer;padding:8px;margin-bottom:4px;font-size:13px;" '+
          'data-pid="'+p.id+'" data-cp="'+(p.cp||'')+'">'+
          '<strong>'+p.nombre+'</strong> · '+(p.ciudad||'')+
        '</div>';
      });
      $('#adVentaStep2').html(html);

      $('.adPuntoVenta').on('click', function(){
        $('.adPuntoVenta').css('border-color','transparent');
        $(this).css('border-color','var(--ad-primary)');
        var pid = $(this).data('pid');
        var cp = $(this).data('cp');
        showQuoteAndConfirm(motoId, pid, 'venta', transId, cp);
      });
    });
  }

  // ── Shared: show Skydropx quote + confirm button ──────────────────────
  function showQuoteAndConfirm(motoId, puntoId, tipo, transId, puntoCp){
    var $info = $('#adQuoteInfo');
    var $btn = $('#adAssignBtn');

    // Try to get Skydropx quote
    $info.html('<span class="ad-spin"></span> Consultando fecha estimada de envío...').show();
    $btn.hide();

    ADApp.api('inventario/cotizar-envio.php', {punto_id: puntoId}).done(function(q){
      if(q.ok){
        $info.html(
          '<strong>Envío estimado:</strong> '+q.dias+' días hábiles<br>'+
          '<strong>Fecha estimada:</strong> '+q.fecha_estimada+'<br>'+
          '<strong>Carrier:</strong> '+q.carrier+(q.servicio?' ('+q.servicio+')':'')
        ).show();
        renderConfirmBtn(motoId, puntoId, tipo, transId, q.fecha_estimada);
      } else {
        // Skydropx failed — allow manual date
        $info.html(
          '<div style="color:orange;">No se pudo obtener cotización automática: '+(q.error||'')+'</div>'+
          '<div style="margin-top:8px;">Fecha estimada (manual): <input type="date" class="ad-input" id="adFechaManual" style="width:180px;display:inline-block;"></div>'
        ).show();
        renderConfirmBtn(motoId, puntoId, tipo, transId, null);
      }
    }).fail(function(){
      $info.html(
        '<div style="color:orange;">Error de conexión al cotizar.</div>'+
        '<div style="margin-top:8px;">Fecha estimada (manual): <input type="date" class="ad-input" id="adFechaManual" style="width:180px;display:inline-block;"></div>'
      ).show();
      renderConfirmBtn(motoId, puntoId, tipo, transId, null);
    });
  }

  function renderConfirmBtn(motoId, puntoId, tipo, transId, fechaAuto){
    var $btn = $('#adAssignBtn');
    $btn.html('<button class="ad-btn primary" id="adDoAssign" style="width:100%;padding:10px;font-size:14px;">Confirmar asignación</button>').show();

    $('#adDoAssign').on('click', function(){
      var fecha = fechaAuto || ($('#adFechaManual').length ? $('#adFechaManual').val() : null);
      var payload = {
        moto_id: motoId,
        punto_id: puntoId,
        tipo: tipo,
        fecha_estimada: fecha || null
      };
      if(transId) payload.transaccion_id = transId;

      $(this).prop('disabled',true).html('<span class="ad-spin"></span> Asignando...');

      ADApp.api('inventario/asignar-punto.php', payload).done(function(r){
        if(r.ok){
          ADApp.closeModal();
          alert('Moto asignada correctamente'+(r.fecha_estimada?' — Llegada estimada: '+r.fecha_estimada:''));
          load();
        } else {
          alert(r.error||'Error al asignar');
          $('#adDoAssign').prop('disabled',false).html('Confirmar asignación');
        }
      }).fail(function(x){
        alert((x.responseJSON&&x.responseJSON.error)||'Error de conexión');
        $('#adDoAssign').prop('disabled',false).html('Confirmar asignación');
      });
    });
  }
  // Catalog: same models and colors the customer sees on the website
  var CATALOGO = [
    {modelo:'M05',           colores:['Gris','Negro','Plata']},
    {modelo:'M03',           colores:['Negro','Gris','Plata']},
    {modelo:'Ukko S+',       colores:['Gris','Negro','Azul','Naranja']},
    {modelo:'MC10 Streetx',  colores:['Negro','Gris']},
    {modelo:'Pesgo Plus',    colores:['Negro','Gris','Azul','Plata']},
    {modelo:'Mino-B',        colores:['Gris','Azul','Verde']},
  ];

  function showNewForm(){
    var html = '<div class="ad-h2">Nueva moto</div>'+
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">';

    // VIN
    html += '<div><label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:2px;">VIN *</label>'+
      '<input class="ad-input" id="nmVin" placeholder="Número de identificación vehicular" style="width:100%"></div>';

    // Modelo select
    html += '<div><label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:2px;">Modelo *</label>'+
      '<select class="ad-input" id="nmModelo" style="width:100%"><option value="">— Seleccionar modelo —</option>';
    CATALOGO.forEach(function(c){ html += '<option value="'+c.modelo+'">'+c.modelo+'</option>'; });
    html += '</select></div>';

    // Color select (populated on modelo change)
    html += '<div><label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:2px;">Color *</label>'+
      '<select class="ad-input" id="nmColor" style="width:100%" disabled><option value="">— Primero selecciona modelo —</option></select></div>';

    // Remaining fields
    var fields = [
      {id:'nmEstado',   label:'Estado inicial *',   ph:'', type:'select', opts:[
        {v:'recibida',   t:'Recibida (ya en inventario)'},
        {v:'por_llegar', t:'Por llegar (en tránsito)'}
      ]},
      {id:'nmAnio',     label:'Año modelo',        ph:new Date().getFullYear()},
      {id:'nmMotor',    label:'Núm. motor',        ph:'Número de motor'},
      {id:'nmPotencia', label:'Potencia',           ph:'500W, 1000W, etc.'},
      {id:'nmBaterias', label:'Config. baterías',   ph:'', type:'select', opts:['1','2']},
      {id:'nmHecho',    label:'Hecho en',           ph:'País de fabricación'},
      {id:'nmPedimento',label:'Núm. pedimento',     ph:'Número de pedimento'},
      {id:'nmFechaIng', label:'Fecha ingreso país *', ph:'', type:'date'},
      {id:'nmAduana',   label:'Aduana',             ph:'Aduana de ingreso'},
      {id:'nmCedis',    label:'CEDIS origen',       ph:'Centro de distribución'},
    ];

    fields.forEach(function(f){
      html += '<div><label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:2px;">'+f.label+'</label>';
      if(f.type === 'select'){
        html += '<select class="ad-input" id="'+f.id+'" style="width:100%">';
        f.opts.forEach(function(o){
          if(typeof o === 'object'){
            html += '<option value="'+o.v+'">'+o.t+'</option>';
          } else {
            html += '<option value="'+o+'">'+o+'</option>';
          }
        });
        html += '</select>';
      } else if(f.type === 'date'){
        html += '<input type="date" class="ad-input" id="'+f.id+'" style="width:100%">';
      } else {
        html += '<input class="ad-input" id="'+f.id+'" placeholder="'+f.ph+'" style="width:100%">';
      }
      html += '</div>';
    });

    html += '</div>'+
      '<div style="margin-top:8px;">'+
        '<label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:2px;">Descripción / Notas</label>'+
        '<textarea class="ad-input" id="nmNotas" placeholder="Notas adicionales" style="width:100%;min-height:60px;"></textarea>'+
      '</div>'+
      '<button class="ad-btn primary" id="nmSave" style="margin-top:12px;width:100%;">Guardar</button>';

    ADApp.modal(html);

    // Cascade: modelo → color
    $(document).off('change.nmModelo').on('change.nmModelo', '#nmModelo', function(){
      var sel = $(this).val();
      var $color = $('#nmColor');
      $color.empty();
      if(!sel){
        $color.append('<option value="">— Primero selecciona modelo —</option>').prop('disabled',true);
        return;
      }
      var cat = null;
      for(var i=0; i<CATALOGO.length; i++){
        if(CATALOGO[i].modelo === sel){ cat = CATALOGO[i]; break; }
      }
      if(!cat){ $color.append('<option value="">Sin colores</option>'); return; }
      $color.prop('disabled',false);
      $color.append('<option value="">— Seleccionar color —</option>');
      for(var j=0; j<cat.colores.length; j++){
        $color.append('<option value="'+cat.colores[j]+'">'+cat.colores[j]+'</option>');
      }
    });

    $('#nmSave').on('click',function(){
      var vin = $('#nmVin').val().trim();
      var modelo = $('#nmModelo').val();
      var color = $('#nmColor').val();
      var fechaIng = $('#nmFechaIng').val();
      if(!vin || !modelo || !color || !fechaIng){
        alert('VIN, Modelo, Color y Fecha ingreso país son obligatorios');
        return;
      }
      $(this).prop('disabled',true).html('<span class="ad-spin"></span> Guardando...');
      ADApp.api('inventario/crear.php',{
        vin: vin,
        modelo: modelo,
        color: color,
        estado: $('#nmEstado').val() || 'recibida',
        anio_modelo: $('#nmAnio').val(),
        num_motor: $('#nmMotor').val(),
        potencia: $('#nmPotencia').val(),
        config_baterias: $('#nmBaterias').val(),
        hecho_en: $('#nmHecho').val(),
        num_pedimento: $('#nmPedimento').val(),
        fecha_ingreso_pais: $('#nmFechaIng').val() || null,
        aduana: $('#nmAduana').val(),
        cedis_origen: $('#nmCedis').val(),
        notas: $('#nmNotas').val()
      }).done(function(r){
        if(r.ok){ ADApp.closeModal(); load(); } else { alert(r.error||'Error'); $('#nmSave').prop('disabled',false).html('Guardar'); }
      }).fail(function(){ alert('Error de conexión'); $('#nmSave').prop('disabled',false).html('Guardar'); });
    });
  }
  function showImportForm(){
    ADApp.modal(
      '<div class="ad-h2">Importar motos desde Excel</div>'+
      '<div class="ad-dim" style="margin-bottom:12px;">Formato: CSV o XLSX con columnas No de serie, Modelo, Color, Año Modelo, No de Motor, Potencia, Posicion, Fecha entrada pais, Puerto entrada, No pedimento, CEDIS Origen, Fecha Entrada Almacen, Fecha Salida Almacen, Punto aliado, Estatus, No de Orden, No de factura</div>'+
      '<div style="margin-bottom:12px;">'+
        '<a href="#" id="adDlTemplate" style="color:var(--ad-primary);font-size:13px;">Descargar plantilla CSV</a>'+
      '</div>'+
      '<input type="file" id="adImportFile" accept=".csv,.xlsx,.txt" class="ad-input" style="margin-bottom:12px">'+
      '<div id="adImportPreview" style="display:none;margin-bottom:12px;max-height:200px;overflow-y:auto;font-size:12px;"></div>'+
      '<div id="adImportResult" style="display:none;margin-bottom:12px;"></div>'+
      '<button class="ad-btn primary" id="adImportBtn" disabled>Importar</button>'
    );

    $('#adDlTemplate').on('click', function(e){
      e.preventDefault();
      var csv = 'No de serie,Modelo,Año Modelo,Color,No de Motor,Potencia del motor,Posicion en inventario,Fecha de entrada al pais,Puerto de entrada,No de pedimento,CEDIS ORIGEN,Fecha Entrada Almacen,Fecha Salida Almacen,Punto aliado/Entrega asignado,Estatus,No de Orden,No de factura,Notas\n';
      var blob = new Blob(['\uFEFF' + csv], {type:'text/csv;charset=utf-8;'});
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'plantilla_inventario.csv';
      a.click();
    });

    $('#adImportFile').on('change', function(){
      var file = this.files[0];
      if(!file) return;
      // Preview for CSV
      if(file.name.match(/\.(csv|txt)$/i)){
        var reader = new FileReader();
        reader.onload = function(e){
          var lines = e.target.result.split('\n').filter(function(l){return l.trim();});
          if(lines.length < 2){ $('#adImportPreview').html('<div style="color:red;">Archivo vacío</div>').show(); return; }
          var html = '<strong>' + (lines.length-1) + ' motos detectadas</strong><br>';
          html += '<table class="ad-table" style="font-size:11px"><thead><tr>';
          var headers = lines[0].split(',');
          headers.forEach(function(h){ html += '<th>'+h.trim()+'</th>'; });
          html += '</tr></thead><tbody>';
          var max = Math.min(lines.length, 6);
          for(var i=1; i<max; i++){
            html += '<tr>';
            lines[i].split(',').forEach(function(c){ html += '<td>'+c.trim()+'</td>'; });
            html += '</tr>';
          }
          if(lines.length > 6) html += '<tr><td colspan="'+headers.length+'" style="text-align:center">... y ' + (lines.length-6) + ' más</td></tr>';
          html += '</tbody></table>';
          $('#adImportPreview').html(html).show();
          $('#adImportBtn').prop('disabled', false);
        };
        reader.readAsText(file);
      } else {
        // XLSX — just show file name, server will parse
        $('#adImportPreview').html('<strong>Archivo: </strong>' + file.name + ' (' + Math.round(file.size/1024) + ' KB)').show();
        $('#adImportBtn').prop('disabled', false);
      }
    });

    $('#adImportBtn').on('click', function(){
      var file = $('#adImportFile')[0].files[0];
      if(!file) return;
      var $btn = $(this);
      $btn.prop('disabled', true).html('<span class="ad-spin"></span> Importando...');

      var fd = new FormData();
      fd.append('archivo', file);

      $.ajax({
        url: 'php/inventario/importar.php',
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        xhrFields: { withCredentials: true },
        dataType: 'json'
      }).done(function(r){
        if(r.ok){
          var html = '<div style="padding:12px;border-radius:8px;background:#E8F5E9;color:#2E7D32;">'+
            '<strong>Importacion completada</strong><br>'+
            'Creadas: <strong>'+r.creados+'</strong> · '+
            'Duplicadas: '+r.duplicados+' · '+
            'Errores: '+r.errores+' · '+
            'Total filas: '+r.total_filas+
            '</div>';
          if(r.detalle && r.detalle.length){
            html += '<div style="margin-top:8px;font-size:11px;color:#666;">';
            r.detalle.forEach(function(d){ html += d + '<br>'; });
            html += '</div>';
          }
          $('#adImportResult').html(html).show();
          $btn.html('Cerrar').prop('disabled',false).off('click').on('click',function(){
            ADApp.closeModal();
            load();
          });
        } else {
          $('#adImportResult').html('<div style="padding:12px;border-radius:8px;background:#FFEBEE;color:#C62828;">'+r.error+'</div>').show();
          $btn.html('Importar').prop('disabled', false);
        }
      }).fail(function(){
        $('#adImportResult').html('<div style="padding:12px;border-radius:8px;background:#FFEBEE;color:#C62828;">Error de conexion</div>').show();
        $btn.html('Importar').prop('disabled', false);
      });
    });
  }

  // ── DESTRUCTIVE: Replace entire inventory from xlsx ───────────────────
  // Customer brief 2026-05-04: customer wants the whole inventory wiped
  // and replaced with the contents of a new xlsx ("the other file has an
  // error, this file is ok"). This UI gates the action behind:
  //   1. Preview step (server returns counts + warnings, no writes)
  //   2. Acknowledged checkbox confirming "I understand transacciones
  //      will lose their moto_id link"
  //   3. Type-to-confirm: must type exactly "ELIMINAR INVENTARIO"
  // Only when all three are satisfied does the Ejecutar button enable.
  function showReplaceForm(){
    ADApp.modal(
      '<div class="ad-h2" style="color:#dc2626;display:flex;align-items:center;gap:8px;">'+
        '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'+
        'Reemplazar inventario completo</div>'+
      '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px;margin-bottom:14px;font-size:13px;color:#991b1b;line-height:1.5;">'+
        '<strong>⚠ ACCIÓN DESTRUCTIVA</strong><br>'+
        'Esta operación <strong>borrará TODO</strong> el inventario actual y lo reemplazará con las filas del archivo xlsx. '+
        'Las transacciones que tengan <code>moto_id</code> asignado <strong>perderán el vínculo</strong> a su moto.<br><br>'+
        '<strong>Antes de continuar verifica que descargaste el backup completo</strong> en '+
        '<code>db-backup.php</code> y lo guardaste en tu computadora local.'+
      '</div>'+
      '<input type="file" id="adReplaceFile" accept=".csv,.xlsx,.txt" class="ad-input" style="margin-bottom:12px">'+
      '<div id="adReplacePreview" style="display:none;margin-bottom:12px;font-size:13px;"></div>'+
      '<div id="adReplaceConfirm" style="display:none;margin:14px 0;padding:14px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;">'+
        '<label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-size:13px;line-height:1.4;margin-bottom:10px;">'+
          '<input type="checkbox" id="adReplaceAck" style="margin-top:2px;flex-shrink:0;">'+
          '<span>Entiendo que esta acción es <strong>irreversible sin restaurar el backup</strong> y que las transacciones con moto_id apuntando a las filas borradas perderán el vínculo.</span>'+
        '</label>'+
        '<label style="font-size:12px;color:#78350f;display:block;margin-bottom:4px;font-weight:600;">'+
          'Para confirmar, escribe exactamente: <code style="background:#fff;padding:1px 6px;border-radius:3px;">ELIMINAR INVENTARIO</code>'+
        '</label>'+
        '<input type="text" id="adReplaceConfirmText" class="ad-input" autocomplete="off" '+
          'placeholder="ELIMINAR INVENTARIO" style="font-family:ui-monospace,monospace;width:100%;">'+
      '</div>'+
      '<div id="adReplaceResult" style="display:none;margin-bottom:12px;"></div>'+
      '<div style="display:flex;gap:8px;">'+
        '<button class="ad-btn ghost" id="adReplaceCancel" style="flex:1;">Cancelar</button>'+
        '<button class="ad-btn ghost" id="adReplacePreviewBtn" disabled style="flex:1;">Vista previa</button>'+
        '<button class="ad-btn" id="adReplaceExecBtn" disabled '+
          'style="flex:1;background:#dc2626;color:#fff;border-color:#dc2626;">Ejecutar reemplazo</button>'+
      '</div>'
    );

    // Enable preview as soon as a file is chosen
    $('#adReplaceFile').on('change', function(){
      var file = this.files[0];
      $('#adReplacePreviewBtn').prop('disabled', !file);
      $('#adReplacePreview').hide().empty();
      $('#adReplaceConfirm').hide();
      $('#adReplaceExecBtn').prop('disabled', true);
      $('#adReplaceResult').hide().empty();
    });

    $('#adReplaceCancel').on('click', function(){ ADApp.closeModal(); });

    // Preview — calls reemplazar-completo.php with action=preview
    $('#adReplacePreviewBtn').on('click', function(){
      var file = $('#adReplaceFile')[0].files[0];
      if (!file) return;
      var $btn = $(this).prop('disabled', true).html('<span class="ad-spin"></span> Analizando...');
      var fd = new FormData();
      fd.append('archivo', file);
      fd.append('action', 'preview');
      $.ajax({
        url: 'php/inventario/reemplazar-completo.php',
        method: 'POST', data: fd, processData: false, contentType: false,
        xhrFields:{withCredentials:true}, dataType:'json'
      }).done(function(r){
        if (!r.ok) {
          $('#adReplacePreview').html('<div style="background:#fee2e2;color:#991b1b;padding:10px;border-radius:6px;">'+(r.error||'Error')+'</div>').show();
          $btn.html('Vista previa').prop('disabled', false);
          return;
        }
        var c = r.current_db || {}, f = r.file || {};
        var html = '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px;">'+
          '<strong style="font-size:14px;color:#1e40af;">📊 Análisis del cambio</strong>'+
          '<table style="width:100%;margin-top:10px;font-size:13px;">'+
          '<tr><td style="color:#64748b;padding:3px 0;">Filas en DB actual</td><td style="text-align:right;font-weight:700;">'+c.total+'</td></tr>'+
          '<tr><td style="color:#64748b;padding:3px 0;">— con cliente asignado</td><td style="text-align:right;color:'+(c.con_cliente>0?'#dc2626':'#64748b')+';font-weight:600;">'+c.con_cliente+'</td></tr>'+
          '<tr><td style="color:#64748b;padding:3px 0;">— en punto de entrega</td><td style="text-align:right;color:'+(c.en_punto>0?'#dc2626':'#64748b')+';font-weight:600;">'+c.en_punto+'</td></tr>'+
          '<tr><td style="color:#64748b;padding:3px 0;">— entregadas</td><td style="text-align:right;color:'+(c.entregadas>0?'#dc2626':'#64748b')+';font-weight:600;">'+c.entregadas+'</td></tr>'+
          '<tr><td style="color:#64748b;padding:3px 0;">Motos vinculadas a pedido</td><td style="text-align:right;color:'+((r.motos_vinculadas_a_pedido||0)>0?'#dc2626':'#10b981')+';font-weight:700;">'+(r.motos_vinculadas_a_pedido||0)+(r.motos_vinculadas_a_pedido>0?' ⚠':' ✓')+'</td></tr>'+
          '<tr><td colspan="2" style="border-top:1px solid #cbd5e1;padding-top:6px;"></td></tr>'+
          '<tr><td style="color:#64748b;padding:3px 0;">Filas en archivo</td><td style="text-align:right;font-weight:700;">'+f.total_filas+'</td></tr>'+
          '<tr><td style="color:#64748b;padding:3px 0;">— sin VIN (descartadas)</td><td style="text-align:right;color:#92400e;">'+f.sin_vin+'</td></tr>'+
          '<tr><td style="color:#64748b;padding:3px 0;">— a insertar</td><td style="text-align:right;font-weight:800;color:#10b981;">'+f.a_insertar+'</td></tr>'+
          '<tr><td style="color:#64748b;padding:3px 0;">— VINs únicos</td><td style="text-align:right;font-weight:700;">'+f.vins_unicos+'</td></tr>'+
          '</table>';
        if (r.warnings && r.warnings.length) {
          html += '<div style="margin-top:10px;font-size:12px;line-height:1.5;">';
          r.warnings.forEach(function(w){
            var isWarn = w.indexOf('⚠')===0;
            html += '<div style="color:'+(isWarn?'#dc2626':'#64748b')+';margin:2px 0;">'+w+'</div>';
          });
          html += '</div>';
        }
        html += '</div>';
        $('#adReplacePreview').html(html).show();
        $('#adReplaceConfirm').show();
        $btn.html('Vista previa').prop('disabled', false);
      }).fail(function(x){
        // Surface as much diagnostic info as possible — when the endpoint
        // returns HTML (PHP fatal) or 404, jQuery's responseJSON is null
        // and the user just sees "Error de conexión" with no clue why.
        // Show HTTP status + first chunk of response so the admin can
        // tell at a glance whether it's a 404 (file not uploaded), 403
        // (auth), or 500 (PHP error).
        var msg = '<strong>HTTP ' + (x.status || '?') + '</strong>';
        if (x.responseJSON && x.responseJSON.error) {
          msg += ' — ' + x.responseJSON.error;
        } else if (x.responseText) {
          var snippet = String(x.responseText).replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim().slice(0, 300);
          msg += '<br><small style="font-family:monospace;font-size:11px;color:#7f1d1d;">' + snippet + '</small>';
        } else {
          msg += ' — sin respuesta del servidor (¿archivo PHP no subido?)';
        }
        $('#adReplacePreview').html('<div style="background:#fee2e2;color:#991b1b;padding:10px;border-radius:6px;font-size:13px;">'+msg+'</div>').show();
        $btn.html('Vista previa').prop('disabled', false);
      });
    });

    // Type-to-confirm gate: enable Ejecutar only when checkbox is on AND
    // the typed text is exactly "ELIMINAR INVENTARIO".
    function refreshExecBtn(){
      var ok = $('#adReplaceAck').is(':checked')
            && $('#adReplaceConfirmText').val().trim() === 'ELIMINAR INVENTARIO'
            && $('#adReplaceFile')[0].files[0];
      $('#adReplaceExecBtn').prop('disabled', !ok);
    }
    $(document).on('change.replInv', '#adReplaceAck', refreshExecBtn);
    $(document).on('input.replInv',  '#adReplaceConfirmText', refreshExecBtn);

    // Final execution
    $('#adReplaceExecBtn').on('click', function(){
      if (!confirm('¿Última confirmación? Esta acción NO se puede deshacer sin restaurar el backup.\n\nProcederá a borrar TODO el inventario y reemplazarlo.')) return;
      var file = $('#adReplaceFile')[0].files[0];
      var $btn = $(this).prop('disabled', true).html('<span class="ad-spin"></span> Reemplazando...');
      var fd = new FormData();
      fd.append('archivo', file);
      fd.append('action', 'execute');
      fd.append('confirm', 'ELIMINAR INVENTARIO');
      fd.append('acknowledged', '1');
      $.ajax({
        url: 'php/inventario/reemplazar-completo.php',
        method: 'POST', data: fd, processData: false, contentType: false,
        xhrFields:{withCredentials:true}, dataType:'json',
        timeout: 120000
      }).done(function(r){
        // Detach the document-level handlers so they don't fire on the
        // next modal open.
        $(document).off('change.replInv input.replInv');
        if (r.ok) {
          var html = '<div style="background:#dcfce7;border:1px solid #86efac;color:#14532d;padding:14px;border-radius:8px;">'+
            '<strong style="font-size:15px;">✓ Reemplazo completado</strong>'+
            '<table style="width:100%;margin-top:8px;font-size:13px;">'+
            '<tr><td>Filas borradas</td><td style="text-align:right;font-weight:700;">'+r.eliminados+'</td></tr>'+
            '<tr><td>Filas insertadas</td><td style="text-align:right;font-weight:700;color:#15803d;">'+r.insertados+'</td></tr>'+
            '<tr><td>Errores</td><td style="text-align:right;font-weight:700;color:'+(r.errores>0?'#dc2626':'#15803d')+';">'+r.errores+'</td></tr>'+
            '<tr><td>Pedidos a reasignar moto</td><td style="text-align:right;color:'+(r.pedidos_a_reasignar>0?'#dc2626':'#15803d')+';">'+r.pedidos_a_reasignar+'</td></tr>'+
            '</table>'+
            (r.errores_detalle && r.errores_detalle.length
              ? '<div style="margin-top:8px;font-size:11px;color:#991b1b;">'+r.errores_detalle.join('<br>')+'</div>'
              : '')+
            '</div>';
          $('#adReplaceResult').html(html).show();
          $btn.html('Cerrar').prop('disabled', false).off('click').on('click', function(){
            ADApp.closeModal(); load();
          });
        } else {
          $('#adReplaceResult').html('<div style="background:#fee2e2;color:#991b1b;padding:12px;border-radius:6px;">'+(r.error||'Error')+'</div>').show();
          $btn.html('Ejecutar reemplazo').prop('disabled', false);
        }
      }).fail(function(x){
        $('#adReplaceResult').html('<div style="background:#fee2e2;color:#991b1b;padding:12px;border-radius:6px;">'+((x.responseJSON&&x.responseJSON.error)||'Error de conexión / timeout')+'</div>').show();
        $btn.html('Ejecutar reemplazo').prop('disabled', false);
      });
    });
  }

  // ── Lock / Unlock moto ─────────────────────────────────────────────────
  function showLockModal(motoId){
    var html = '<div class="ad-h2">Bloquear moto para venta</div>'+
      '<div class="ad-dim" style="margin-bottom:12px;">Esta moto no podrá ser vendida ni asignada mientras esté bloqueada.</div>'+
      '<label class="ad-label">Motivo del bloqueo *</label>'+
      '<textarea id="adLockMotivo" class="ad-input" placeholder="Ej. Pendiente revisión técnica, daño en transporte, etc." style="width:100%;min-height:80px;"></textarea>'+
      '<button class="ad-btn primary" id="adLockSave" style="width:100%;margin-top:12px;">Bloquear moto</button>';
    ADApp.modal(html);
    $('#adLockSave').on('click', function(){
      var motivo = $('#adLockMotivo').val().trim();
      if(!motivo){ alert('El motivo es obligatorio'); return; }
      $(this).prop('disabled',true).html('<span class="ad-spin"></span> Bloqueando...');
      ADApp.api('inventario/bloquear-venta.php', {
        moto_id: motoId,
        bloqueado: 1,
        motivo: motivo
      }).done(function(r){
        if(r.ok){
          ADApp.closeModal();
          alert('Moto bloqueada para venta');
          load();
        } else {
          alert(r.error||'Error');
          $('#adLockSave').prop('disabled',false).html('Bloquear moto');
        }
      }).fail(function(x){
        alert((x.responseJSON&&x.responseJSON.error)||'Error de conexión');
        $('#adLockSave').prop('disabled',false).html('Bloquear moto');
      });
    });
  }
  function unlockMoto(motoId){
    if(!confirm('¿Desbloquear esta moto para venta?')) return;
    ADApp.api('inventario/bloquear-venta.php', {
      moto_id: motoId,
      bloqueado: 0,
      motivo: ''
    }).done(function(r){
      if(r.ok){
        ADApp.closeModal();
        alert('Moto desbloqueada para venta');
        load();
      } else {
        alert(r.error||'Error');
      }
    }).fail(function(x){
      alert((x.responseJSON&&x.responseJSON.error)||'Error de conexión');
    });
  }

  // Round 28 v2 (2026-05-14, Óscar Pesgo Plus VIN ...12): flip estado
  // from 'retenida' back to 'recibida'. Different from unlockMoto()
  // which only touches bloqueado_venta. Both can be needed
  // independently on the same moto.
  //
  // v1 called configurador/php/admin-moto-accion.php directly, which
  // returned "No autenticado" because that endpoint uses the dealer-
  // panel session (requireDealerAuth) not the admin-panel session.
  // v2 calls a new admin-side endpoint (admin/php/inventario/
  // liberar-moto.php) that authenticates via adminRequireAuth so the
  // logged-in admin can liberar without re-authenticating.
  function liberarMoto(motoId){
    var notas = prompt(
      '¿Liberar esta moto?\n\n' +
      'Estado operativo cambia: retenida → recibida.\n' +
      '(Esto NO afecta el bloqueo de venta — esos son campos independientes.)\n\n' +
      'Opcional: escribe un motivo para registrar en el historial:'
    );
    if (notas === null) return;   // user cancelled
    ADApp.api('inventario/liberar-moto.php', {
      moto_id: motoId,
      notas:   (notas || '').trim()
    }).done(function(r){
      if (r && r.ok) {
        ADApp.closeModal();
        alert('Moto liberada · estado: recibida');
        load();
      } else {
        alert((r && r.error) || 'Error al liberar la moto');
      }
    }).fail(function(x){
      alert((x.responseJSON && x.responseJSON.error) || 'Error de conexión');
    });
  }

  return { render:render };
})();
