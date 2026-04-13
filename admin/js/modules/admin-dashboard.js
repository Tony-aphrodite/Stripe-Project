window.AD_dashboard = (function(){
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
    ];
    var html = '<div class="ad-h1">Dashboard</div><div class="ad-kpis">';
    kpis.forEach(function(kpi){
      html += '<div class="ad-kpi"><div class="label">'+kpi.label+'</div><div class="value '+kpi.cls+'">'+kpi.value+'</div></div>';
    });
    html += '</div>';
    // Quick actions
    html += '<div class="ad-h2">Acciones rápidas</div>';
    html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px">';
    var imgBase = '../configurador_prueba/img/';
    var actions = [
      {img:'iconos-03.svg',label:'Ventas',route:'ventas',bg:'linear-gradient(135deg,#e0f4fd,#fff)'},
      {img:'iconos-02.svg',label:'CEDIS',route:'inventario',bg:'linear-gradient(135deg,#dcfce7,#fff)'},
      {img:'entrega.png',label:'Envíos',route:'envios',bg:'linear-gradient(135deg,#fef3c7,#fff)'},
      {img:'garantia.png',label:'Pagos',route:'pagos',bg:'linear-gradient(135deg,#e0e7ff,#fff)'},
      {img:'asesor_icon.jpg',label:'Puntos',route:'puntos',bg:'linear-gradient(135deg,#fce7f3,#fff)'},
      {img:'entrega.png',label:'Entregas',route:'envios',bg:'linear-gradient(135deg,#dcfce7,#e0fdf0)'},
      {img:'garantia.png',label:'Cobranza',route:'cobranza',bg:'linear-gradient(135deg,#fee2e2,#fff)'},
      {img:'iconos-01.svg',label:'Buscar',route:'buscar',bg:'linear-gradient(135deg,#f0f4f8,#fff)'}
    ];
    actions.forEach(function(a){
      html += '<div class="ad-card" style="cursor:pointer;text-align:center;padding:28px 16px;background:'+a.bg+';border:1.5px solid var(--ad-border);" onclick="ADApp.go(\''+a.route+'\')" onmouseover="this.style.transform=\'translateY(-3px)\';this.style.boxShadow=\'0 8px 30px rgba(12,35,64,.12)\'" onmouseout="this.style.transform=\'none\';this.style.boxShadow=\'\'">';
      html += '<div style="margin-bottom:10px"><img src="'+imgBase+a.img+'" alt="'+a.label+'" style="width:40px;height:40px;object-fit:contain;"></div>';
      html += '<div style="font-weight:700;font-size:14px;color:var(--ad-navy)">'+a.label+'</div>';
      html += '</div>';
    });
    html += '</div>';
    ADApp.render(html);
  }
  return { render:render };
})();
