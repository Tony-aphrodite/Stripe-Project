/* admin-contratos-firmados.js — Master list of every signed contract.
 *
 * Customer brief 2026-05-13 (Óscar, 12th round — "my boss cannot check
 * the signed contracts"): single dashboard for the boss to audit every
 * signed contract (contado, MSI, crédito) without drilling into each
 * order. Search + filters + per-row download.
 *
 * Sidebar route: data-route="contratosFirmados"
 * Endpoint:      /admin/php/ventas/contratos-firmados.php
 */
window.AD_contratosFirmados = (function(){
  var state = {
    rows: [],
    kpi: null,
    filters: { q: '', desde: '', hasta: '', tpago: '' },
  };

  function esc(s){ return jQuery('<div/>').text(s == null ? '' : String(s)).html(); }
  function money(n){
    if (n == null || n === '' || isNaN(Number(n))) return '—';
    return '$' + Number(n).toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2});
  }
  function fmtDate(d){ return d ? String(d).slice(0,10) : '—'; }

  function render(){
    ADApp.render('<div class="ad-h1">Contratos firmados</div>'+
      '<div style="color:var(--ad-dim);font-size:13px;margin-bottom:14px;">'+
        'Todos los contratos firmados (contado, MSI, crédito) en un solo lugar. '+
        'Para descargar, abrir o auditar cualquier contrato sin entrar a cada pedido.'+
      '</div>'+
      '<div><span class="ad-spin"></span> Cargando contratos…</div>');
    load();
  }

  function load(){
    var params = jQuery.param(state.filters);
    ADApp.api('ventas/contratos-firmados.php?' + params).done(function(r){
      if (!r || !r.ok) {
        renderError(r);
        return;
      }
      state.rows = r.rows || [];
      state.kpi  = r.kpi  || null;
      paint();
    }).fail(function(x){
      renderError(x.responseJSON || { error:'conexión perdida' });
    });
  }

  function renderError(r){
    var err = (r && r.error) || 'desconocido';
    var detail = (r && r.detail) || '';
    var html = '<div class="ad-h1">Contratos firmados</div>'+
      '<div style="color:#b91c1c;padding:14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;">'+
        '<strong>Error:</strong> '+esc(err);
    if (detail) {
      html += '<details style="margin-top:8px;"><summary style="cursor:pointer;font-size:12px;">Detalle técnico</summary>'+
        '<pre style="background:#1e293b;color:#e2e8f0;padding:10px;border-radius:6px;margin-top:6px;font-size:11px;white-space:pre-wrap;word-break:break-word;">'+esc(detail)+'</pre></details>';
    }
    html += '</div>';
    ADApp.render(html);
  }

  function paint(){
    var kpi  = state.kpi  || {};
    var rows = state.rows || [];
    var f    = state.filters;

    var html = '';
    html += '<div class="ad-h1">Contratos firmados</div>';
    html += '<div style="color:var(--ad-dim);font-size:13px;margin-bottom:14px;">'+
      'Todos los contratos firmados (contado, MSI, crédito). Búsqueda, filtros, descarga directa por fila.'+
    '</div>';

    // ── KPI cards ───────────────────────────────────────────────────
    function kpiCard(label, val, color, sub){
      return '<div style="background:'+color+';color:#fff;border-radius:10px;padding:14px 16px;flex:1;min-width:140px;">'+
        '<div style="font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;opacity:.9;">'+esc(label)+'</div>'+
        '<div style="font-size:24px;font-weight:800;margin-top:4px;">'+esc(val)+'</div>'+
        (sub ? '<div style="font-size:11px;opacity:.85;margin-top:2px;">'+esc(sub)+'</div>' : '')+
      '</div>';
    }
    html += '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">';
    html += kpiCard('Total firmados',    kpi.total ?? rows.length,  '#039fe1');
    html += kpiCard('Hoy',                kpi.hoy ?? 0,             '#22c55e');
    html += kpiCard('Esta semana',        kpi.esta_semana ?? 0,     '#10b981');
    html += kpiCard('Este mes',           kpi.este_mes ?? 0,        '#0284c7');
    html += kpiCard('Crédito',            kpi.credito ?? 0,         '#7c3aed');
    html += kpiCard('Contado / MSI',      kpi.contado_msi ?? 0,     '#06b6d4');
    html += kpiCard('PDF listo',          kpi.pdf_listo ?? 0,       '#16a34a');
    html += kpiCard('PDF pendiente',      kpi.pdf_pendiente ?? 0,   '#d97706', 'firma incompleta');
    html += '</div>';

    // ── Filter bar ──────────────────────────────────────────────────
    html += '<div class="ad-card" style="padding:14px;margin-bottom:14px;">';
    html += '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">';
    html += '<div style="flex:2;min-width:220px;">'+
      '<label style="font-size:11px;color:var(--ad-dim);font-weight:600;display:block;margin-bottom:3px;">BUSCAR</label>'+
      '<input type="text" id="cfQ" class="ad-input" placeholder="Cliente · pedido · email · VIN" value="'+esc(f.q)+'" style="width:100%;">'+
    '</div>';
    html += '<div style="flex:1;min-width:140px;">'+
      '<label style="font-size:11px;color:var(--ad-dim);font-weight:600;display:block;margin-bottom:3px;">DESDE</label>'+
      '<input type="date" id="cfDesde" class="ad-input" value="'+esc(f.desde)+'">'+
    '</div>';
    html += '<div style="flex:1;min-width:140px;">'+
      '<label style="font-size:11px;color:var(--ad-dim);font-weight:600;display:block;margin-bottom:3px;">HASTA</label>'+
      '<input type="date" id="cfHasta" class="ad-input" value="'+esc(f.hasta)+'">'+
    '</div>';
    html += '<div style="min-width:140px;">'+
      '<label style="font-size:11px;color:var(--ad-dim);font-weight:600;display:block;margin-bottom:3px;">TIPO DE PAGO</label>'+
      '<select id="cfTpago" class="ad-select">'+
        '<option value=""'+(f.tpago===''?' selected':'')+'>Todos</option>'+
        '<option value="contado"'+(f.tpago==='contado'?' selected':'')+'>Contado</option>'+
        '<option value="msi"'+(f.tpago==='msi'?' selected':'')+'>MSI</option>'+
        '<option value="credito"'+(f.tpago==='credito'?' selected':'')+'>Crédito</option>'+
        '<option value="enganche"'+(f.tpago==='enganche'?' selected':'')+'>Enganche</option>'+
        '<option value="parcial"'+(f.tpago==='parcial'?' selected':'')+'>Parcial</option>'+
      '</select>'+
    '</div>';
    html += '<button class="ad-btn primary" id="cfApply">Aplicar filtros</button>';
    var anyFilter = f.q || f.desde || f.hasta || f.tpago;
    if (anyFilter) html += '<button class="ad-btn ghost" id="cfClear">✕ Limpiar</button>';
    html += '</div>';
    html += '</div>';

    // ── Table ───────────────────────────────────────────────────────
    if (!rows.length) {
      html += '<div style="padding:30px;background:#fff;border:1px dashed #cbd5e1;color:var(--ad-dim);text-align:center;border-radius:10px;">'+
        (anyFilter
          ? 'No hay contratos firmados que coincidan con los filtros aplicados.'
          : 'No hay contratos firmados todavía en el sistema.')+
      '</div>';
    } else {
      html += '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table"><thead><tr>'+
        '<th>Pedido</th>'+
        '<th>Cliente</th>'+
        '<th>Modelo</th>'+
        '<th>Tipo</th>'+
        '<th style="text-align:right;">Monto</th>'+
        '<th>Fecha firma</th>'+
        '<th>Punto</th>'+
        '<th>PDF</th>'+
        '<th>Acciones</th>'+
      '</tr></thead><tbody>';

      rows.forEach(function(r){
        var pedido = r.pedido_corto || (r.pedido ? 'VK-'+r.pedido : 'TX'+r.id);
        var fecha = r.contrato_aceptado_at || r.fecha_compra;
        var tipoBadge;
        var tp = String(r.tpago||'').toLowerCase();
        if (tp === 'contado' || tp === 'unico')      tipoBadge = '<span class="ad-badge green">CONTADO</span>';
        else if (tp === 'msi')                       tipoBadge = '<span class="ad-badge blue">MSI</span>';
        else if (tp === 'credito' || tp === 'enganche' || tp === 'parcial') tipoBadge = '<span class="ad-badge yellow">CRÉDITO</span>';
        else                                          tipoBadge = '<span class="ad-badge gray">'+esc(r.tpago||'—')+'</span>';

        var pdfIc = r.pdf_on_disk
          ? '<span style="color:#16a34a;font-weight:700;" title="PDF disponible">✓ Listo</span>'
          : '<span style="color:#d97706;font-weight:700;" title="PDF no disponible — esperar firma Cincel">⏳ Pendiente</span>';

        var actions;
        if (r.pdf_on_disk) {
          actions = '<a href="'+r.contract_url+'" target="_blank" rel="noopener" class="ad-btn sm primary" style="text-decoration:none;font-size:11px;padding:5px 10px;">📄 Ver</a> '+
                    '<a href="'+r.contract_dl_url+'" target="_blank" rel="noopener" class="ad-btn sm ghost" style="text-decoration:none;font-size:11px;padding:5px 10px;">📥 Descargar</a>';
        } else {
          actions = '<span style="color:var(--ad-dim);font-size:11px;">No disponible</span>';
        }

        html += '<tr>'+
          '<td><strong>'+esc(pedido)+'</strong>'+
            (r.folio_contrato ? '<div style="font-size:10px;color:var(--ad-dim);">Folio: '+esc(r.folio_contrato)+'</div>' : '')+
          '</td>'+
          '<td>'+esc(r.nombre || '—')+
            '<div style="font-size:11px;color:var(--ad-dim);">'+esc(r.email || '—')+'</div>'+
          '</td>'+
          '<td>'+esc((r.modelo||'')+' '+(r.color||''))+'</td>'+
          '<td>'+tipoBadge+'</td>'+
          '<td style="text-align:right;font-weight:700;">'+money(r.total)+'</td>'+
          '<td>'+esc(fmtDate(fecha))+'</td>'+
          '<td>'+esc(r.punto_nombre || '—')+'</td>'+
          '<td>'+pdfIc+'</td>'+
          '<td style="white-space:nowrap;">'+actions+'</td>'+
        '</tr>';
      });

      html += '</tbody></table></div></div>';

      // CSV export hint
      html += '<div style="margin-top:14px;font-size:12px;color:var(--ad-dim);">'+
        '<strong>'+rows.length+' contratos</strong> mostrados. ¿Necesitas exportar a CSV? '+
        '<a href="javascript:void(0)" id="cfExportCsv" style="color:var(--ad-primary);">Descargar CSV</a>'+
      '</div>';
    }

    ADApp.render(html);
    bind();
  }

  function bind(){
    function readFilters(){
      state.filters = {
        q:     ($('#cfQ').val()     || '').trim(),
        desde: ($('#cfDesde').val() || '').trim(),
        hasta: ($('#cfHasta').val() || '').trim(),
        tpago: ($('#cfTpago').val() || '').trim(),
      };
    }
    $('#cfApply').on('click', function(){ readFilters(); load(); });
    $('#cfClear').on('click', function(){
      state.filters = { q:'', desde:'', hasta:'', tpago:'' };
      load();
    });
    $('#cfQ').on('keydown', function(e){ if (e.which === 13) { readFilters(); load(); } });
    $('#cfExportCsv').on('click', exportCsv);
  }

  function exportCsv(){
    var rows = state.rows || [];
    if (!rows.length) { alert('Sin filas para exportar.'); return; }
    var header = ['Pedido','Cliente','Email','Telefono','Modelo','Color','Tipo','Monto','Fecha firma','Punto','PDF listo'];
    var lines = [header.join(',')];
    rows.forEach(function(r){
      var pedido = r.pedido_corto || (r.pedido ? 'VK-'+r.pedido : 'TX'+r.id);
      function esc(v){ if (v == null) return ''; v = String(v).replace(/"/g,'""'); return '"' + v + '"'; }
      lines.push([
        esc(pedido),
        esc(r.nombre),
        esc(r.email),
        esc(r.telefono),
        esc(r.modelo),
        esc(r.color),
        esc(r.tpago),
        esc(r.total),
        esc(fmtDate(r.contrato_aceptado_at || r.fecha_compra)),
        esc(r.punto_nombre),
        r.pdf_on_disk ? 'sí' : 'no',
      ].join(','));
    });
    var blob = new Blob(["﻿" + lines.join('\n')], {type:'text/csv;charset=utf-8;'});
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href     = url;
    a.download = 'contratos-firmados-' + (new Date().toISOString().slice(0,10)) + '.csv';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function(){ URL.revokeObjectURL(url); }, 100);
  }

  return { render: render };
})();
