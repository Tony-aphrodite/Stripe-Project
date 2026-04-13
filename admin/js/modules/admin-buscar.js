window.AD_buscar = (function(){
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  function render(query){
    var q = query || '';
    var html = _backBtn;
    html += '<div class="ad-toolbar"><div class="ad-h1">Buscar</div></div>';
    html += '<div class="ad-filters">';
    html += '<input class="ad-input" id="adSearchInput" placeholder="Buscar cliente, teléfono, email, VIN o pedido..." value="' + esc(q) + '" style="flex:1;min-width:250px;">';
    html += '<button class="ad-btn primary" id="adSearchBtn">Buscar</button>';
    html += '</div>';
    html += '<div id="adSearchResults"></div>';
    ADApp.render(html);

    $('#adSearchBtn').on('click', doSearch);
    $('#adSearchInput').on('keypress', function(e){ if(e.which===13) doSearch(); });
    if (q) doSearch();
  }

  function doSearch(){
    var q = $('#adSearchInput').val().trim();
    if (q.length < 2) {
      $('#adSearchResults').html('<div class="ad-banner warn">Ingresa al menos 2 caracteres para buscar</div>');
      return;
    }
    $('#adSearchResults').html('<div style="text-align:center;padding:30px;"><span class="ad-spin"></span> Buscando...</div>');

    ADApp.api('buscar/global.php?q=' + encodeURIComponent(q)).done(function(r){
      if (!r.ok) {
        $('#adSearchResults').html('<div class="ad-banner err">' + esc(r.error || 'Error') + '</div>');
        return;
      }
      paintResults(r);
    }).fail(function(){
      $('#adSearchResults').html('<div class="ad-banner err">Error de conexión</div>');
    });
  }

  function paintResults(r){
    var html = '<div style="margin:16px 0 8px;font-size:13px;color:var(--ad-dim);">' + r.total + ' resultados para "<strong>' + esc(r.query) + '</strong>"</div>';

    // ── Clientes ──
    if (r.results.clientes.length) {
      html += '<div class="ad-h2">Clientes (' + r.results.clientes.length + ')</div>';
      html += '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table"><thead><tr>';
      html += '<th>Nombre</th><th>Email</th><th>Teléfono</th><th>Registro</th>';
      html += '</tr></thead><tbody>';
      r.results.clientes.forEach(function(c){
        html += '<tr>';
        html += '<td><strong>' + esc(c.nombre) + '</strong></td>';
        html += '<td>' + esc(c.email || '—') + '</td>';
        html += '<td>' + esc(c.telefono || '—') + '</td>';
        html += '<td>' + (c.freg || '—').substring(0, 10) + '</td>';
        html += '</tr>';
      });
      html += '</tbody></table></div></div>';
    }

    // ── Ordenes ──
    if (r.results.ordenes.length) {
      html += '<div class="ad-h2">Órdenes (' + r.results.ordenes.length + ')</div>';
      html += '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table"><thead><tr>';
      html += '<th>Pedido</th><th>Cliente</th><th>Modelo</th><th>Tipo</th><th>Monto</th><th>Fecha</th>';
      html += '</tr></thead><tbody>';
      r.results.ordenes.forEach(function(o){
        html += '<tr>';
        html += '<td><strong>VK-' + (o.pedido || o.id) + '</strong></td>';
        html += '<td>' + esc(o.nombre) + '<br><small class="ad-dim">' + esc(o.telefono || '') + '</small></td>';
        html += '<td>' + esc(o.modelo || '') + ' ' + esc(o.color || '') + '</td>';
        html += '<td><span class="ad-badge blue">' + esc(o.tipo_pago || '') + '</span></td>';
        html += '<td>' + ADApp.money(o.monto) + '</td>';
        html += '<td>' + (o.freg || '—').substring(0, 10) + '</td>';
        html += '</tr>';
      });
      html += '</tbody></table></div></div>';
    }

    // ── Inventario ──
    if (r.results.inventario.length) {
      html += '<div class="ad-h2">Inventario (' + r.results.inventario.length + ')</div>';
      html += '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table"><thead><tr>';
      html += '<th>VIN</th><th>Modelo</th><th>Color</th><th>Estado</th><th>Cliente</th><th>Punto</th>';
      html += '</tr></thead><tbody>';
      r.results.inventario.forEach(function(m){
        html += '<tr>';
        html += '<td><strong>' + esc(m.vin_display || m.vin || '—') + '</strong></td>';
        html += '<td>' + esc(m.modelo || '') + '</td>';
        html += '<td>' + esc(m.color || '') + '</td>';
        html += '<td>' + ADApp.badgeEstado(m.estado || '') + '</td>';
        html += '<td>' + esc(m.cliente_nombre || '—') + '</td>';
        html += '<td>' + esc(m.punto_nombre || '—') + '</td>';
        html += '</tr>';
      });
      html += '</tbody></table></div></div>';
    }

    // ── Créditos ──
    if (r.results.creditos.length) {
      html += '<div class="ad-h2">Créditos (' + r.results.creditos.length + ')</div>';
      html += '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table"><thead><tr>';
      html += '<th>ID</th><th>Cliente</th><th>Modelo</th><th>Pago semanal</th><th>Plazo</th><th>Fecha</th>';
      html += '</tr></thead><tbody>';
      r.results.creditos.forEach(function(c){
        html += '<tr>';
        html += '<td><strong>VK-SC-' + c.id + '</strong></td>';
        html += '<td>' + esc(c.nombre) + '<br><small class="ad-dim">' + esc(c.email || '') + '</small></td>';
        html += '<td>' + esc(c.modelo || '') + ' ' + esc(c.color || '') + '</td>';
        html += '<td>' + ADApp.money(c.monto_semanal) + '</td>';
        html += '<td>' + (c.plazo_semanas || '—') + ' semanas</td>';
        html += '<td>' + (c.freg || '—').substring(0, 10) + '</td>';
        html += '</tr>';
      });
      html += '</tbody></table></div></div>';
    }

    if (r.total === 0) {
      html += '<div class="ad-empty"><span class="ic">&#128269;</span>No se encontraron resultados para "<strong>' + esc(r.query) + '</strong>"</div>';
    }

    $('#adSearchResults').html(html);
  }

  function esc(s){ return $('<span>').text(s||'').html(); }

  return { render: render };
})();

// ── Global search hotkey (Ctrl+K) ──
$(document).on('keydown', function(e){
  if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
    e.preventDefault();
    ADApp.go('buscar');
  }
});
