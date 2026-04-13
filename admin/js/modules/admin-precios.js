window.AD_precios = (function(){
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  function render(){
    ADApp.render(_backBtn+'<div class="ad-h1">Precios y Condiciones</div><div><span class="ad-spin"></span></div>');
    load();
  }

  function load(){
    ADApp.api('precios/listar.php').done(paint).fail(function(){
      ADApp.render(_backBtn+'<div class="ad-h1">Precios</div><div class="ad-banner err">Error al cargar</div>');
    });
  }

  function paint(r){
    var html = _backBtn;
    html += '<div class="ad-toolbar"><div class="ad-h1">Precios y Condiciones</div>';
    if (ADApp.isAdmin()) {
      html += '<button class="ad-btn primary" id="prNuevo">+ Agregar condiciones</button>';
    }
    html += '</div>';

    html += '<div class="ad-table-wrap"><table class="ad-table"><thead><tr>';
    html += '<th>Modelo</th><th>Enganche mín</th><th>Enganche máx</th><th>Pago semanal</th><th>Tasa</th><th>Plazo</th><th>MSI</th><th>Promoción</th><th></th>';
    html += '</tr></thead><tbody>';

    (r.precios||[]).forEach(function(p){
      var msi = '—';
      try { msi = JSON.parse(p.msi_opciones||'[]').join(', ') + ' meses'; } catch(e){}
      var promoHtml = p.promocion_activa
        ? '<span class="ad-badge green">'+esc(p.promocion_nombre)+' (-'+ADApp.money(p.promocion_descuento)+')</span>'
        : '<span class="ad-badge gray">Sin promo</span>';

      html += '<tr>';
      html += '<td><strong>'+esc(p.modelo_nombre||'ID:'+p.modelo_id)+'</strong></td>';
      html += '<td>'+ADApp.money(p.enganche_min)+'</td>';
      html += '<td>'+ADApp.money(p.enganche_max)+'</td>';
      html += '<td>'+ADApp.money(p.pago_semanal)+'</td>';
      html += '<td>'+(p.tasa_interna*100).toFixed(2)+'%</td>';
      html += '<td>'+p.plazo_semanas+' sem</td>';
      html += '<td>'+msi+'</td>';
      html += '<td>'+promoHtml+'</td>';
      html += '<td>';
      if (ADApp.isAdmin()) {
        html += '<button class="ad-btn sm ghost prEditar" data-mid="'+p.modelo_id+'">Editar</button>';
      }
      html += '</td></tr>';
    });
    if (!r.precios || !r.precios.length) {
      html += '<tr><td colspan="9" class="ad-dim" style="text-align:center;">Sin condiciones de precio configuradas</td></tr>';
    }
    html += '</tbody></table></div>';

    ADApp.render(html);
    $('#prNuevo').on('click', function(){ showForm({}, r.modelos||[]); });
    $('.prEditar').on('click', function(){
      var mid = $(this).data('mid');
      var p = (r.precios||[]).find(function(x){ return x.modelo_id == mid; });
      showForm(p||{modelo_id:mid}, r.modelos||[]);
    });
  }

  function showForm(p, modelos){
    var msiArr = [];
    try { msiArr = JSON.parse(p.msi_opciones||'[]'); } catch(e){}

    var html = '<div class="ad-h2">Condiciones de precio</div>';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px;">';
    html += '<label>Modelo<select id="prModelo" class="ad-input">';
    modelos.forEach(function(m){
      html += '<option value="'+m.id+'"'+(m.id==p.modelo_id?' selected':'')+'>'+esc(m.nombre)+'</option>';
    });
    html += '</select></label>';
    html += '<label>Enganche mínimo<input id="prEngMin" class="ad-input" type="number" value="'+(p.enganche_min||0)+'"></label>';
    html += '<label>Enganche máximo<input id="prEngMax" class="ad-input" type="number" value="'+(p.enganche_max||0)+'"></label>';
    html += '<label>Pago semanal<input id="prSemanal" class="ad-input" type="number" value="'+(p.pago_semanal||0)+'"></label>';
    html += '<label>Tasa interna (decimal)<input id="prTasa" class="ad-input" type="number" step="0.0001" value="'+(p.tasa_interna||0)+'"></label>';
    html += '<label>Plazo (semanas)<input id="prPlazo" class="ad-input" type="number" value="'+(p.plazo_semanas||52)+'"></label>';
    html += '<label>MSI opciones (meses, separados por coma)<input id="prMsi" class="ad-input" value="'+msiArr.join(',')+'"></label>';
    html += '<label>Nombre promoción<input id="prPromoNombre" class="ad-input" value="'+esc(p.promocion_nombre||'')+'"></label>';
    html += '<label>Descuento promoción<input id="prPromoDesc" class="ad-input" type="number" value="'+(p.promocion_descuento||0)+'"></label>';
    html += '<label><input type="checkbox" id="prPromoActiva" '+(p.promocion_activa?'checked':'')+' style="width:auto;margin-right:8px;">Promoción activa</label>';
    html += '</div>';
    html += '<div style="margin-top:14px;text-align:right;">';
    html += '<button class="ad-btn ghost" onclick="ADApp.closeModal()">Cancelar</button> ';
    html += '<button class="ad-btn primary" id="prGuardar">Guardar</button>';
    html += '</div><div id="prMsg" style="margin-top:8px;font-size:12px;"></div>';

    ADApp.modal(html);
    $('#prGuardar').on('click', function(){
      var msiVal = $('#prMsi').val().split(',').map(function(x){ return parseInt(x.trim()); }).filter(function(x){ return x > 0; });
      var payload = {
        modelo_id: parseInt($('#prModelo').val()),
        enganche_min: parseFloat($('#prEngMin').val())||0,
        enganche_max: parseFloat($('#prEngMax').val())||0,
        pago_semanal: parseFloat($('#prSemanal').val())||0,
        tasa_interna: parseFloat($('#prTasa').val())||0,
        plazo_semanas: parseInt($('#prPlazo').val())||52,
        msi_opciones: msiVal,
        promocion_nombre: $('#prPromoNombre').val().trim(),
        promocion_activa: $('#prPromoActiva').is(':checked') ? 1 : 0,
        promocion_descuento: parseFloat($('#prPromoDesc').val())||0,
      };
      $('#prGuardar').prop('disabled',true).text('Guardando...');
      ADApp.api('precios/guardar.php', payload).done(function(r){
        if (r.ok) { ADApp.closeModal(); load(); }
        else { $('#prMsg').html('<span style="color:#b91c1c;">'+esc(r.error)+'</span>'); $('#prGuardar').prop('disabled',false).text('Guardar'); }
      });
    });
  }

  function esc(s){ return $('<span>').text(s||'').html(); }
  return { render: render };
})();
