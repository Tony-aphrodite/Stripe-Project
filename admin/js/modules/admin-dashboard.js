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
  }

  function paint(k){
    var kpis = [
      {label:'Ventas hoy', value:k.ventas_hoy, cls:'green'},
      {label:'Ventas semana', value:k.ventas_semana, cls:'green'},
      {label:'Cobrado hoy', value:ADApp.money(k.cobrado_hoy), cls:'green'},
      {label:'Ingresos esperados esta semana', value:ADApp.money(k.flujo_esperado), cls:'blue'},
      {label:'Cartera al corriente', value:k.cartera_corriente, cls:'green'},
      {label:'Cartera vencida', value:k.cartera_vencida, cls:k.cartera_vencida>0?'red':'green'},
      {label:'Inventario disponible', value:k.inventario_disponible, cls:'blue'},
      {label:'Apartadas por pago', value:k.unidades_apartadas, cls:'yellow'},
      {label:'Pendientes de envío', value:k.en_transito, cls:'yellow'},
      {label:'Pendientes de entrega a clientes', value:k.pendientes_entrega_clientes, cls:'yellow'},
      {label:'Placas pendientes', value:k.placas_pendientes||0, cls:(k.placas_pendientes||0)>0?'yellow':'green'},
      {label:'Quálitas pendientes', value:k.seguro_pendientes||0, cls:(k.seguro_pendientes||0)>0?'yellow':'green'},
    ];
    // Environment badge hidden in customer-facing dashboard — configurable via
    // the APP_ENV .env var. Re-enable below if you want a visible indicator.
    var envBadge = '';
    var html = '<div class="ad-h1">Dashboard '+envBadge+'</div><div class="ad-kpis" style="grid-template-columns:repeat(4,1fr);">';
    kpis.forEach(function(kpi){
      html += '<div class="ad-kpi"><div class="label">'+kpi.label+'</div><div class="value '+kpi.cls+'">'+kpi.value+'</div></div>';
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
  }

  return { render:render };
})();
