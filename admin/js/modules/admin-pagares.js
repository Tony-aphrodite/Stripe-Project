// Admin module: Pagarés viewer.
// Embeds /admin/php/inventario/view-pagares.php in an iframe so admin can
// browse all generated PAGARÉs from a sidebar menu item.
window.AD_pagares = (function(){
  function render(){
    var back = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')">'
             + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';
    var html = back
      + '<div class="ad-toolbar"><div class="ad-h1">📜 PAGARÉs generados</div>'
      + '<button class="ad-btn sm ghost" onclick="document.getElementById(\'pagaresFrame\').contentWindow.location.reload()">Actualizar</button>'
      + '</div>'
      + '<iframe id="pagaresFrame" src="/admin/php/inventario/view-pagares.php" '
      + 'style="width:100%;height:calc(100vh - 160px);border:1px solid #e2e8f0;border-radius:8px;background:#fff;"></iframe>';
    ADApp.render(html);
  }
  return { render: render };
})();

// Admin module: Créditos entregados (unified dashboard).
window.AD_creditosEntregados = (function(){
  function render(){
    var back = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')">'
             + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';
    var html = back
      + '<div class="ad-toolbar"><div class="ad-h1">💳 Créditos entregados — Vista unificada</div>'
      + '<button class="ad-btn sm ghost" onclick="document.getElementById(\'creditosFrame\').contentWindow.location.reload()">Actualizar</button>'
      + '</div>'
      + '<iframe id="creditosFrame" src="/admin/php/inventario/creditos-entregados.php" '
      + 'style="width:100%;height:calc(100vh - 160px);border:1px solid #e2e8f0;border-radius:8px;background:#fff;"></iframe>';
    ADApp.render(html);
  }
  return { render: render };
})();
