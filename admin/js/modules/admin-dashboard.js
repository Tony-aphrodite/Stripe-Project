window.AD_dashboard = (function(){

  // Inline SVG icons matching each quick-action (same icons as sidebar)
  var _icons = {
    ventas:    '<svg viewBox="0 0 24 24" fill="none" stroke="#039fe1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>',
    inventario:'<svg viewBox="0 0 24 24" fill="none" stroke="#039fe1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
    envios:    '<svg viewBox="0 0 24 24" fill="none" stroke="#039fe1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
    pagos:     '<svg viewBox="0 0 24 24" fill="none" stroke="#039fe1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
    puntos:    '<svg viewBox="0 0 24 24" fill="none" stroke="#039fe1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>',
    cobranza:  '<svg viewBox="0 0 24 24" fill="none" stroke="#039fe1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>',
    buscar:    '<svg viewBox="0 0 24 24" fill="none" stroke="#039fe1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
    alertas:   '<svg viewBox="0 0 24 24" fill="none" stroke="#039fe1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>',
    reportes:  '<svg viewBox="0 0 24 24" fill="none" stroke="#039fe1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21.21 15.89A10 10 0 118 2.83"/><path d="M22 12A10 10 0 0012 2v10z"/></svg>',
    analytics: '<svg viewBox="0 0 24 24" fill="none" stroke="#039fe1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
    checklists:'<svg viewBox="0 0 24 24" fill="none" stroke="#039fe1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M9 14l2 2 4-4"/></svg>',
    modelos:   '<svg viewBox="0 0 24 24" fill="none" stroke="#039fe1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="5" cy="17" r="3"/><circle cx="19" cy="17" r="3"/><path d="M5 14l4-7h4l2 3h4"/><path d="M9 7l3 7"/><path d="M15 10l4 4"/></svg>',
    documentos:'<svg viewBox="0 0 24 24" fill="none" stroke="#039fe1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>'
  };

  function render(){
    ADApp.render('<div class="ad-h1">Dashboard</div><div class="ad-kpis"><div class="ad-kpi"><span class="ad-spin"></span></div></div>');
    ADApp.api('dashboard/kpis.php').done(paint);
    // Customer brief 2026-05-12 (Óscar, 10th round — Fase 3 prevention):
    // surface unsigned-credit-contract count as a red banner on the
    // dashboard so admin sees the risk every time they log in, not just
    // when they remember to navigate to the audit panel. Fire-and-forget
    // — if the endpoint is missing on legacy installs we just skip the
    // banner without breaking the page.
    fetchSinFirmaWarning();
  }

  function fetchSinFirmaWarning(){
    ADApp.api('ventas/credito-sin-firma.php?limit=1').done(function(r){
      if (!r || !r.ok) return;
      var k = r.kpi || {};
      var n = parseInt(k.total_pedidos || 0, 10);
      // Mirror count in sidebar badge regardless of whether the banner
      // shows — admin can also navigate via that visual cue.
      var $sb = $('#adSinFirmaBadge');
      if (n > 0) $sb.text(n).show(); else $sb.hide();

      if (n === 0) return; // nothing to warn about
      var monto = (k.monto_total != null)
        ? '$' + Number(k.monto_total).toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2})
        : '—';
      var dias = parseInt(k.dias_max_sin_firmar || 0, 10);
      var sev  = n > 5 || dias > 14 ? 'critical' : (n > 0 ? 'warning' : 'ok');
      var bg   = sev === 'critical' ? '#fef2f2' : '#fffbeb';
      var bd   = sev === 'critical' ? '#fecaca' : '#fde68a';
      var tx   = sev === 'critical' ? '#991b1b' : '#92400e';
      var ic   = sev === 'critical' ? '🚨' : '⚠️';
      var banner = '<div id="adSinFirmaBanner" '+
        'style="display:flex;gap:12px;align-items:center;background:'+bg+';border:1.5px solid '+bd+';color:'+tx+';padding:14px 18px;border-radius:10px;margin:0 0 18px;cursor:pointer;" '+
        'onclick="ADApp.go(\'creditoSinFirma\')">'+
        '<div style="font-size:30px;line-height:1;">'+ic+'</div>'+
        '<div style="flex:1;">'+
          '<div style="font-weight:800;font-size:15px;">'+
            n+' pedido'+(n===1?'':'s')+' a crédito sin contrato firmado'+
          '</div>'+
          '<div style="font-size:12.5px;opacity:.9;margin-top:2px;">'+
            'Monto en juego: <strong>'+monto+'</strong>'+
            ' · Máx '+dias+' días sin firmar'+
            ' · Reenvía el link a los clientes para que firmen con Truora + Cincel'+
          '</div>'+
        '</div>'+
        '<button class="ad-btn primary" style="background:'+tx+';border-color:'+tx+';white-space:nowrap;">'+
          'Ver lista →'+
        '</button>'+
      '</div>';
      // Insert at the very top of the screen, above the H1 + KPIs.
      var $screen = $('#adScreen');
      if ($screen.find('#adSinFirmaBanner').length === 0) {
        $screen.prepend(banner);
      } else {
        $screen.find('#adSinFirmaBanner').replaceWith(banner);
      }
    }).fail(function(){ /* legacy install — silently skip */ });
  }

  function paint(k){
    // Customer brief 2026-05-06: every KPI card must be clickable and
    // navigate to the corresponding screen. Each card declares a
    // route (and optional filter hint stashed on window so the target
    // module can pre-apply it).
    var kpis = [
      {label:'Ventas hoy',                     value:k.ventas_hoy,                  cls:'green',  route:'ventas',     filter:'today'},
      {label:'Ventas semana',                  value:k.ventas_semana,               cls:'green',  route:'ventas',     filter:'week'},
      {label:'Cobrado hoy',                    value:ADApp.money(k.cobrado_hoy),    cls:'green',  route:'pagos',      filter:'today_paid'},
      {label:'Ingresos esperados esta semana', value:ADApp.money(k.flujo_esperado), cls:'blue',   route:'pagos',      filter:'week_expected'},
      {label:'Cartera al corriente',           value:k.cartera_corriente,           cls:'green',  route:'cobranza',   filter:'corriente'},
      {label:'Cartera vencida',                value:k.cartera_vencida,             cls:k.cartera_vencida>0?'red':'green', route:'cobranza', filter:'vencida'},
      {label:'Inventario disponible',          value:k.inventario_disponible,       cls:'blue',   route:'inventario', filter:'disponible'},
      {label:'Apartadas por pago',             value:k.unidades_apartadas,          cls:'yellow', route:'ventas',     filter:'pago_pendiente'},
      {label:'Pendientes de envío',            value:k.en_transito,                 cls:'yellow', route:'envios',     filter:'pendiente'},
      {label:'Pendientes de entrega a clientes', value:k.pendientes_entrega_clientes, cls:'yellow', route:'entregas',  filter:'pendiente'},
      {label:'Placas pendientes',              value:k.placas_pendientes||0,        cls:(k.placas_pendientes||0)>0?'yellow':'green', route:'ventas', filter:'placas_pendientes'},
      {label:'Seguros pendientes',             value:k.seguro_pendientes||0,        cls:(k.seguro_pendientes||0)>0?'yellow':'green', route:'ventas', filter:'seguro_pendientes'},
    ];
    var envBadge = '';
    var html = '<div class="ad-h1">Dashboard '+envBadge+'</div><div class="ad-kpis">';
    kpis.forEach(function(kpi, idx){
      // Make the whole KPI card a clickable button. Use data-* attributes
      // (not inline onclick with JSON.stringify — that injected double
      // quotes into a double-quoted HTML attribute and broke parsing on
      // every card with a "Uncaught SyntaxError: Unexpected end of input").
      // A delegated jQuery handler below reads the data attributes and
      // routes/sets the filter hint.
      html += '<div class="ad-kpi adKpiCard" '
           +    'data-route="'+(kpi.route||'dashboard')+'" '
           +    'data-filter="'+(kpi.filter||'').replace(/"/g,'&quot;')+'" '
           +    'style="cursor:pointer;transition:transform .12s, box-shadow .12s;" '
           +    'onmouseover="this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 6px 18px rgba(12,35,64,.12)\'" '
           +    'onmouseout="this.style.transform=\'none\';this.style.boxShadow=\'\'">'
           +    '<div class="label">'+kpi.label+'</div>'
           +    '<div class="value '+kpi.cls+'">'+kpi.value+'</div>'
           +  '</div>';
    });
    html += '</div>';

    // Quick actions
    html += '<div class="ad-h2">Acciones rápidas</div>';
    html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px">';

    var actions = [
      {icon:'ventas',    label:'Ventas',    route:'ventas',    bg:'linear-gradient(135deg,#e0f4fd,#fff)'},
      {icon:'inventario',label:'CEDIS',     route:'inventario',bg:'linear-gradient(135deg,#dcfce7,#fff)'},
      {icon:'envios',    label:'Envíos',    route:'envios',    bg:'linear-gradient(135deg,#fef3c7,#fff)'},
      {icon:'pagos',     label:'Pagos',     route:'pagos',     bg:'linear-gradient(135deg,#e0e7ff,#fff)'},
      {icon:'puntos',    label:'Puntos',    route:'puntos',    bg:'linear-gradient(135deg,#fce7f3,#fff)'},
      {icon:'cobranza',  label:'Cobranza',  route:'cobranza',  bg:'linear-gradient(135deg,#fee2e2,#fff)'},
      {icon:'alertas',   label:'Alertas',   route:'alertas',   bg:'linear-gradient(135deg,#fef3c7,#fff)'},
      {icon:'reportes',  label:'Reportes',  route:'reportes',  bg:'linear-gradient(135deg,#e0e7ff,#fff)'},
      {icon:'analytics', label:'Analítica', route:'analytics', bg:'linear-gradient(135deg,#dcfce7,#fff)'},
      {icon:'buscar',    label:'Buscar',    route:'buscar',    bg:'linear-gradient(135deg,#f0f4f8,#fff)'}
    ];

    actions.forEach(function(a){
      var svg = _icons[a.icon] || '';
      html += '<div class="ad-card" style="cursor:pointer;text-align:center;padding:28px 16px;background:'+a.bg+';border:1.5px solid var(--ad-border);" onclick="ADApp.go(\''+a.route+'\')" onmouseover="this.style.transform=\'translateY(-3px)\';this.style.boxShadow=\'0 8px 30px rgba(12,35,64,.12)\'" onmouseout="this.style.transform=\'none\';this.style.boxShadow=\'\'">';
      html += '<div style="margin-bottom:10px;display:flex;justify-content:center;align-items:center;width:44px;height:44px;margin-left:auto;margin-right:auto;background:rgba(3,159,225,.08);border-radius:12px;">'+
              '<div style="width:26px;height:26px;">'+svg+'</div></div>';
      html += '<div style="font-weight:700;font-size:14px;color:var(--ad-navy)">'+a.label+'</div>';
      html += '</div>';
    });
    html += '</div>';
    ADApp.render(html);

    // KPI card click handler — uses data-route + data-filter set in render.
    // jQuery delegation off the document so it survives re-renders.
    $(document).off('click.adKpi').on('click.adKpi', '.adKpiCard', function(){
      var $c = $(this);
      window._adFilterHint = $c.data('filter') || '';
      ADApp.go($c.data('route') || 'dashboard');
    });
  }

  return { render:render };
})();
