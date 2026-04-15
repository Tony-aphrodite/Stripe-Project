window.AD_referidos = (function(){
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  function render(){
    ADApp.render('<div class="ad-h1">Referidos</div><div><span class="ad-spin"></span></div>');
    ADApp.api('referidos/listar.php').done(paint);
  }

  function paint(r){
    var referidos = r.referidos||[];
    var puntos = r.puntos||[];

    var html = _backBtn+'<div class="ad-toolbar"><div class="ad-h1">Referidos &amp; Códigos</div>'+
      '<button class="ad-btn primary" id="adNewRef">+ Nuevo referido</button></div>';

    // ── Summary cards ──
    var totalOps = 0, totalVentas = 0, totalCom = 0;
    referidos.forEach(function(x){ totalOps += Number(x.operaciones)||0; totalVentas += Number(x.total_ventas)||0; totalCom += Number(x.comision_calculada)||0; });
    puntos.forEach(function(x){ totalOps += Number(x.operaciones)||0; totalVentas += Number(x.total_ventas)||0; totalCom += Number(x.comision_calculada)||0; });

    html += '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px;">';
    html += kpiCard('Operaciones totales', totalOps, '#039fe1');
    html += kpiCard('Ventas totales', '$'+numFmt(totalVentas), '#00C851');
    html += kpiCard('Comisiones generadas', '$'+numFmt(totalCom), '#ff9800');
    html += '</div>';

    // ── Tabs ──
    html += '<div style="display:flex;gap:0;margin-bottom:16px;border-bottom:2px solid #eee;">';
    html += '<button class="adRefTab" data-tab="puntos" style="padding:8px 20px;font-weight:700;border:none;background:none;cursor:pointer;border-bottom:2px solid #039fe1;color:#039fe1;margin-bottom:-2px;">Códigos de Puntos</button>';
    html += '<button class="adRefTab" data-tab="influencers" style="padding:8px 20px;font-weight:700;border:none;background:none;cursor:pointer;color:#999;margin-bottom:-2px;">Influencers / Referidos</button>';
    html += '</div>';

    // ── Puntos table ──
    html += '<div id="adRefPuntos">';
    html += '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table"><thead><tr>'+
      '<th>Punto</th><th>Ciudad</th><th>Cód. Venta</th><th>Cód. Online</th>'+
      '<th>Operaciones</th><th>Ventas $</th><th>Comisión $</th><th></th></tr></thead><tbody>';
    puntos.forEach(function(p){
      html += '<tr>'+
        '<td><strong>'+esc(p.nombre)+'</strong></td>'+
        '<td>'+(p.ciudad||'')+', '+(p.estado||'')+'</td>'+
        '<td><code>'+(p.codigo_venta||'—')+'</code></td>'+
        '<td><code>'+(p.codigo_electronico||'—')+'</code></td>'+
        '<td style="text-align:center;font-weight:700;">'+(Number(p.operaciones)||0)+'</td>'+
        '<td style="text-align:right;">$'+numFmt(p.total_ventas)+'</td>'+
        '<td style="text-align:right;color:#ff9800;font-weight:700;">$'+numFmt(p.comision_calculada)+'</td>'+
        '<td><button class="ad-btn sm ghost adRefDetalle" data-tipo="punto" data-id="'+p.id+'">Detalle</button></td>'+
      '</tr>';
    });
    if(!puntos.length) html += '<tr><td colspan="8" style="text-align:center;color:#999;">Sin puntos registrados</td></tr>';
    html += '</tbody></table></div></div></div>';

    // ── Influencers table ──
    html += '<div id="adRefInfluencers" style="display:none;">';
    html += '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table"><thead><tr>'+
      '<th>Nombre</th><th>Código</th><th>Email</th><th>Teléfono</th>'+
      '<th>Operaciones</th><th>Ventas $</th><th>Comisión $</th><th></th></tr></thead><tbody>';
    referidos.forEach(function(ref){
      html += '<tr>'+
        '<td><strong>'+esc(ref.nombre)+'</strong></td>'+
        '<td><code>'+(ref.codigo||'—')+'</code></td>'+
        '<td>'+esc(ref.email||'—')+'</td>'+
        '<td>'+esc(ref.telefono||'—')+'</td>'+
        '<td style="text-align:center;font-weight:700;">'+(Number(ref.operaciones)||0)+'</td>'+
        '<td style="text-align:right;">$'+numFmt(ref.total_ventas)+'</td>'+
        '<td style="text-align:right;color:#ff9800;font-weight:700;">$'+numFmt(ref.comision_calculada)+'</td>'+
        '<td>'+
          '<button class="ad-btn sm ghost adRefDetalle" data-tipo="referido" data-id="'+ref.id+'">Detalle</button> '+
          '<button class="ad-btn sm ghost adRefEdit" data-id="'+ref.id+'" data-nombre="'+esc(ref.nombre)+'" data-email="'+esc(ref.email||'')+'" data-tel="'+esc(ref.telefono||'')+'">Editar</button> '+
          '<button class="ad-btn sm ghost adRefDel" data-id="'+ref.id+'" style="color:#e53935;">Eliminar</button>'+
        '</td></tr>';
    });
    if(!referidos.length) html += '<tr><td colspan="8" style="text-align:center;color:#999;">Sin referidos registrados</td></tr>';
    html += '</tbody></table></div></div></div>';

    ADApp.render(html);

    // Tab switching
    $('.adRefTab').on('click', function(){
      var tab = $(this).data('tab');
      $('.adRefTab').css({borderBottom:'2px solid transparent',color:'#999'});
      $(this).css({borderBottom:'2px solid #039fe1',color:'#039fe1'});
      $('#adRefPuntos, #adRefInfluencers').hide();
      if(tab==='puntos') $('#adRefPuntos').show();
      else $('#adRefInfluencers').show();
    });

    // New referido
    $('#adNewRef').on('click', function(){ showRefForm({}); });

    // Edit referido
    $('.adRefEdit').on('click', function(){
      showRefForm({id:$(this).data('id'), nombre:$(this).data('nombre'), email:$(this).data('email'), telefono:$(this).data('tel')});
    });

    // Delete referido
    $('.adRefDel').on('click', function(){
      var id = $(this).data('id');
      if(!confirm('Eliminar este referido?')) return;
      ADApp.api('referidos/guardar.php', {accion:'eliminar', id:id}).done(function(r2){
        if(r2.ok) render();
        else alert(r2.error||'Error');
      });
    });

    // Detail view
    $('.adRefDetalle').on('click', function(){
      showDetalle($(this).data('tipo'), $(this).data('id'));
    });
  }

  function showRefForm(ref){
    var isNew = !ref.id;
    var html = '<div class="ad-h2">'+(isNew?'Nuevo':'Editar')+' Referido</div>';
    html += '<input class="ad-input" id="rfNombre" placeholder="Nombre" value="'+esc(ref.nombre||'')+'" style="margin-bottom:8px">';
    html += '<input class="ad-input" id="rfEmail" placeholder="Email" value="'+esc(ref.email||'')+'" style="margin-bottom:8px">';
    html += '<input class="ad-input" id="rfTel" placeholder="Teléfono" value="'+esc(ref.telefono||'')+'" style="margin-bottom:8px">';
    html += '<button class="ad-btn primary" id="rfSave" style="width:100%;padding:10px;">Guardar</button>';
    ADApp.modal(html);

    $('#rfSave').on('click', function(){
      var payload = {
        accion: isNew ? 'agregar' : 'actualizar',
        id: ref.id||undefined,
        nombre: $('#rfNombre').val(),
        email: $('#rfEmail').val(),
        telefono: $('#rfTel').val()
      };
      $(this).prop('disabled',true).html('<span class="ad-spin"></span>');
      ADApp.api('referidos/guardar.php', payload).done(function(r2){
        if(r2.ok){ ADApp.closeModal(); render(); }
        else{ alert(r2.error||'Error'); $('#rfSave').prop('disabled',false).html('Guardar'); }
      });
    });
  }

  function showDetalle(tipo, id){
    var html = '<div class="ad-h2">Detalle de operaciones</div><div><span class="ad-spin"></span></div>';
    ADApp.modal(html);

    ADApp.api('referidos/detalle.php?tipo='+tipo+'&id='+id).done(function(r){
      if(!r.ok){ ADApp.closeModal(); return; }
      var s = r.summary||{};
      var txns = r.transacciones||[];
      var coms = r.comisiones||[];

      var dh = '<div class="ad-h2">Detalle de operaciones</div>';

      // Summary
      dh += '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px;">';
      dh += miniCard('Operaciones', s.total_operaciones||0);
      dh += miniCard('Ventas', '$'+numFmt(s.total_ventas||0));
      dh += miniCard('Comisiones', '$'+numFmt(s.total_comision||0));
      dh += '</div>';

      // Transactions table
      dh += '<div style="font-weight:700;font-size:13px;margin-bottom:6px;">Transacciones</div>';
      if(txns.length){
        dh += '<div style="max-height:250px;overflow-y:auto;"><table class="ad-table" style="font-size:12px;"><thead><tr>'+
          '<th>Pedido</th><th>Cliente</th><th>Modelo</th><th>Monto</th><th>Fecha</th></tr></thead><tbody>';
        txns.forEach(function(t){
          dh += '<tr><td><code>'+esc(t.pedido_num||'—')+'</code></td>'+
            '<td>'+esc(t.nombre||'')+'</td>'+
            '<td>'+esc(t.modelo||'')+'</td>'+
            '<td style="text-align:right;">$'+numFmt(t.monto||0)+'</td>'+
            '<td>'+esc((t.freg||'').substring(0,10))+'</td></tr>';
        });
        dh += '</tbody></table></div>';
      } else {
        dh += '<div style="color:#999;font-size:12px;margin-bottom:12px;">Sin transacciones registradas.</div>';
      }

      // Commission log
      if(coms.length){
        dh += '<div style="font-weight:700;font-size:13px;margin:12px 0 6px;">Comisiones generadas</div>';
        dh += '<div style="max-height:200px;overflow-y:auto;"><table class="ad-table" style="font-size:12px;"><thead><tr>'+
          '<th>Pedido</th><th>Modelo</th><th>Venta $</th><th>%</th><th>Comisión $</th><th>Tipo</th><th>Fecha</th></tr></thead><tbody>';
        coms.forEach(function(c){
          dh += '<tr><td><code>'+esc(c.pedido_num||'—')+'</code></td>'+
            '<td>'+esc(c.modelo||'')+'</td>'+
            '<td style="text-align:right;">$'+numFmt(c.monto_venta||0)+'</td>'+
            '<td style="text-align:center;">'+c.comision_pct+'%</td>'+
            '<td style="text-align:right;color:#ff9800;font-weight:700;">$'+numFmt(c.comision_monto||0)+'</td>'+
            '<td>'+esc(c.tipo||'')+'</td>'+
            '<td>'+esc((c.freg||'').substring(0,10))+'</td></tr>';
        });
        dh += '</tbody></table></div>';
      }

      ADApp.modal(dh);
    });
  }

  function kpiCard(label, value, color){
    return '<div style="background:'+color+';color:#fff;padding:14px 16px;border-radius:10px;">'+
      '<div style="font-size:12px;opacity:.8;">'+label+'</div>'+
      '<div style="font-size:22px;font-weight:800;">'+value+'</div></div>';
  }
  function miniCard(label, value){
    return '<div style="background:#f5f7fa;padding:8px 12px;border-radius:8px;text-align:center;">'+
      '<div style="font-size:11px;color:#999;">'+label+'</div>'+
      '<div style="font-size:16px;font-weight:800;">'+value+'</div></div>';
  }
  function numFmt(n){ return Number(n||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function esc(s){ return (s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  return { render:render };
})();
