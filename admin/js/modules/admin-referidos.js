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
    // Cache the full referido records keyed by id so the Editar button can
    // pre-fill EVERY field (codigo + per-model comisiones) without extra
    // network calls. HTML data-* attributes only store scalars cleanly, so
    // we look up the whole object from this in-memory map.
    window._ADREFMAP = {};
    referidos.forEach(function(ref){ window._ADREFMAP[ref.id] = ref; });

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
          '<button class="ad-btn sm ghost adRefEdit" data-id="'+ref.id+'">Editar</button> '+
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

    // Edit referido — look up the full row (including codigo + per-model
    // comisiones) from the cache set by paint(). Previously this passed only
    // nombre/email/tel via data-* attrs, so the edit form had empty codigo
    // and empty comisiones every time — which is what users meant by
    // "can't edit the influencer data".
    $('.adRefEdit').on('click', function(){
      var id = $(this).data('id');
      var ref = (window._ADREFMAP || {})[id] || { id: id };
      showRefForm(ref);
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

  // Catalog of models for per-model commission inputs. Sourced from
  // configurador_prueba/js/data/productos.js when available (loaded by the
  // page), falls back to a static list so the modal never breaks.
  function _modelosCatalogo(){
    if (window.VOLTIKA_PRODUCTOS && Array.isArray(window.VOLTIKA_PRODUCTOS.modelos)) {
      return window.VOLTIKA_PRODUCTOS.modelos.map(function(m){
        return { slug: m.id, nombre: m.nombre };
      });
    }
    return [
      { slug:'m05',        nombre:'M05' },
      { slug:'m03',        nombre:'M03' },
      { slug:'ukko-s',     nombre:'Ukko S+' },
      { slug:'mc10',       nombre:'MC10 Streetx' },
      { slug:'pesgo-plus', nombre:'Pesgo Plus' },
      { slug:'mino',       nombre:'Mino-B' }
    ];
  }

  function showRefForm(ref){
    var isNew = !ref.id;
    // Existing commissions — keyed by modelo_slug — arrive on edit from
    // referidos/listar.php. Missing keys render as empty inputs (= $0).
    var existingComms = (ref.comisiones && typeof ref.comisiones === 'object') ? ref.comisiones : {};

    var html = '<div class="ad-h2">'+(isNew?'Nuevo':'Editar')+' Referido</div>';
    html += '<input class="ad-input" id="rfNombre" placeholder="Nombre" value="'+esc(ref.nombre||'')+'" style="margin-bottom:8px">';
    html += '<input class="ad-input" id="rfEmail" placeholder="Email" value="'+esc(ref.email||'')+'" style="margin-bottom:8px">';
    html += '<input class="ad-input" id="rfTel" placeholder="Teléfono" value="'+esc(ref.telefono||'')+'" style="margin-bottom:8px">';
    // Optional manual código: admin can enter a custom one, or leave blank to auto-generate
    html += '<label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:2px;">Código <small>(opcional — vacío = auto)</small></label>';
    html += '<div style="display:flex;gap:6px;margin-bottom:12px;">'+
      '<input class="ad-input" id="rfCodigo" placeholder="Ej: VOLT2026" value="'+esc(ref.codigo||'')+'" style="flex:1;text-transform:uppercase;">'+
      '<button class="ad-btn sm ghost" id="rfCodigoGen" type="button" style="white-space:nowrap;">Generar</button>'+
    '</div>';

    // Commission per model — fixed MXN amount, paid to the referido per sale.
    // Customer feedback 2026-04-23: needs per-model $ so payouts are
    // computed automatically instead of guessed per deal.
    html += '<div style="background:var(--ad-surface-2);border-radius:8px;padding:10px 12px;margin-bottom:12px;">'+
            '<div style="font-size:13px;font-weight:700;color:var(--ad-navy);margin-bottom:4px;">Comisiones por modelo (MXN fijo)</div>'+
            '<div style="font-size:11.5px;color:var(--ad-dim);margin-bottom:10px;">Monto que gana este referido cada vez que se vende un modelo con su código. Deja en $0 si no aplica.</div>';
    var mods = _modelosCatalogo();
    mods.forEach(function(m){
      var current = existingComms[m.slug];
      var val = (current !== undefined && current !== null && current !== '') ? Number(current) : '';
      html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">'+
              '<div style="flex:1;font-size:12.5px;color:var(--ad-navy);font-weight:600;">'+esc(m.nombre)+'</div>'+
              '<div style="position:relative;flex:0 0 140px;">'+
                '<span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:12px;color:var(--ad-dim);">$</span>'+
                '<input type="number" min="0" step="1" class="ad-input rfComision" data-slug="'+esc(m.slug)+'" '+
                  'placeholder="0" value="'+(val === '' ? '' : val)+'" '+
                  'style="padding-left:22px;text-align:right;font-family:ui-monospace,Menlo,Consolas,monospace;">'+
              '</div></div>';
    });
    html += '</div>';

    html += '<div id="rfMsg" style="font-size:12px;margin-bottom:8px;"></div>';
    html += '<button class="ad-btn primary" id="rfSave" style="width:100%;padding:10px;">Guardar</button>';
    ADApp.modal(html);

    $('#rfCodigoGen').on('click', function(){
      var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
      var code = '';
      for(var i=0;i<7;i++) code += chars.charAt(Math.floor(Math.random()*chars.length));
      $('#rfCodigo').val(code);
    });

    $('#rfSave').on('click', function(){
      var codigo = ($('#rfCodigo').val() || '').toUpperCase().trim();
      // Collect non-zero / non-empty commissions into a slug→amount map.
      // Zero is stored explicitly as 0 so backend can delete existing rows.
      var comisiones = {};
      $('.rfComision').each(function(){
        var slug = $(this).data('slug');
        var raw  = ($(this).val() || '').trim();
        if (raw === '') return;
        var n = parseFloat(raw);
        if (isNaN(n) || n < 0) return;
        comisiones[slug] = n;
      });
      var payload = {
        accion: isNew ? 'agregar' : 'actualizar',
        id: ref.id||undefined,
        nombre: $('#rfNombre').val(),
        email: $('#rfEmail').val(),
        telefono: $('#rfTel').val(),
        codigo: codigo || null,
        comisiones: comisiones
      };
      $(this).prop('disabled',true).html('<span class="ad-spin"></span>');
      ADApp.api('referidos/guardar.php', payload).done(function(r2){
        if(r2.ok){ ADApp.closeModal(); render(); }
        else{
          $('#rfMsg').css('color','#b91c1c').text(r2.error||'Error');
          $('#rfSave').prop('disabled',false).html('Guardar');
        }
      }).fail(function(x){
        $('#rfMsg').css('color','#b91c1c').text((x.responseJSON && x.responseJSON.error) || 'Error de conexión');
        $('#rfSave').prop('disabled',false).html('Guardar');
      });
    });
  }

  function showDetalle(tipo, id){
    var html = '<div class="ad-h2">Detalle de operaciones</div><div style="text-align:center;padding:30px;"><span class="ad-spin"></span></div>';
    ADApp.modal(html);

    ADApp.api('referidos/detalle.php?tipo='+tipo+'&id='+id).done(function(r){
      if(!r.ok){
        ADApp.modal('<div class="ad-h2">Detalle de operaciones</div>'+
          '<div style="padding:20px;background:#fde8e8;color:#c41e3a;border-radius:8px;">Error: '+esc(r.error||'No se pudo cargar')+'</div>'+
          '<div style="text-align:right;margin-top:14px;"><button class="ad-btn ghost" onclick="ADApp.closeModal()">Cerrar</button></div>');
        return;
      }
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
