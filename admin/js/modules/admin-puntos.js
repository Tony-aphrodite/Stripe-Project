window.AD_puntos = (function(){
  var puntosData = [];
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  function render(){
    ADApp.render('<div class="ad-h1">Puntos Voltika</div><div><span class="ad-spin"></span></div>');
    ADApp.api('puntos/listar.php').done(paint);
  }

  function paint(r){
    puntosData = r.puntos||[];
    var html = _backBtn+'<div class="ad-toolbar"><div class="ad-h1">Puntos Voltika</div>'+
      '<button class="ad-btn primary" id="adNewPunto">+ Nuevo punto</button></div>';

    html += '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table"><thead><tr>'+
      '<th>Nombre</th><th>Tipo</th><th>Ciudad</th><th>Inventario</th>'+
      '<th>Listas entrega</th><th>Envíos pend.</th><th>Cód. venta</th><th>Activo</th><th></th>'+
      '</tr></thead><tbody>';

    puntosData.forEach(function(p){
      var tipoLabel = {center:'Center',certificado:'Certificado',entrega:'Entrega'};
      html += '<tr>'+
        '<td><strong>'+p.nombre+'</strong></td>'+
        '<td><span class="ad-badge '+(p.tipo==='center'?'blue':p.tipo==='certificado'?'green':'gray')+'">'+(tipoLabel[p.tipo]||p.tipo||'—')+'</span></td>'+
        '<td>'+(p.ciudad||'—')+', '+(p.estado||'')+'</td>'+
        '<td>'+p.inventario_actual+'</td>'+
        '<td>'+p.listas_entrega+'</td>'+
        '<td>'+p.envios_pendientes+'</td>'+
        '<td><code>'+(p.codigo_venta||'—')+'</code></td>'+
        '<td>'+(Number(p.activo)?'<span class="ad-badge green">Sí</span>':'<span class="ad-badge red">No</span>')+'</td>'+
        '<td><button class="ad-btn sm ghost adEditP" data-id="'+p.id+'">Editar</button></td>'+
      '</tr>';
    });
    html += '</tbody></table></div></div>';

    ADApp.render(html);
    $('#adNewPunto').on('click',function(){ showForm({}); });
    $('.adEditP').on('click',function(){
      var id=$(this).data('id');
      var p = puntosData.find(function(x){return x.id==id;});
      if(p) showForm(p);
    });
  }

  function showForm(p){
    var isNew = !p.id;
    // Parse JSON fields
    var servicios = parseJson(p.servicios);
    var tags = parseJson(p.tags);
    var zonas = parseJson(p.zonas);

    var html = '<div class="ad-h2">'+(isNew?'Nuevo':'Editar')+' Punto Voltika</div>';
    html += '<div>';

    // ── Basic info ──
    html += sectionTitle('Información básica');
    html += '<input class="ad-input" id="pfNombre" placeholder="Nombre del punto" value="'+esc(p.nombre||'')+'" style="margin-bottom:8px">';
    html += '<div style="display:grid;grid-template-columns:2fr 1fr;gap:8px;margin-bottom:8px">'+
      '<select class="ad-select" id="pfTipo" style="width:100%;">'+
        '<option value="center"'+(p.tipo==='center'?' selected':'')+'>Voltika Center</option>'+
        '<option value="certificado"'+(p.tipo==='certificado'?' selected':'')+'>Distribuidor Certificado</option>'+
        '<option value="entrega"'+(!p.tipo||p.tipo==='entrega'?' selected':'')+'>Punto de Entrega</option>'+
      '</select>'+
      '<label style="display:flex;align-items:center;gap:6px;font-size:13px;"><input type="checkbox" id="pfActivo" '+(p.id&&Number(p.activo)===0?'':'checked')+'> Activo</label>'+
    '</div>';

    // ── Address ──
    html += sectionTitle('Dirección');
    html += '<input class="ad-input" id="pfDir" placeholder="Calle y número" value="'+esc(p.direccion||'')+'" style="margin-bottom:8px">';
    html += '<input class="ad-input" id="pfColonia" placeholder="Colonia" value="'+esc(p.colonia||'')+'" style="margin-bottom:8px">';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">'+
      '<input class="ad-input" id="pfCiudad" placeholder="Ciudad" value="'+esc(p.ciudad||'')+'">'+
      '<input class="ad-input" id="pfEstado" placeholder="Estado" value="'+esc(p.estado||'')+'">'+
    '</div>';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">'+
      '<input class="ad-input" id="pfCP" placeholder="Código postal" value="'+esc(p.cp||'')+'">'+
      '<input class="ad-input" id="pfTel" placeholder="Teléfono" value="'+esc(p.telefono||'')+'">'+
    '</div>';
    html += '<input class="ad-input" id="pfEmail" placeholder="Email" value="'+esc(p.email||'')+'" style="margin-bottom:8px">';

    // ── Schedule & capacity ──
    html += sectionTitle('Horario y capacidad');
    html += '<input class="ad-input" id="pfHorarios" placeholder="Ej: Lun-Vie 9:00-18:00" value="'+esc(p.horarios||'')+'" style="margin-bottom:8px">';
    html += '<input class="ad-input" id="pfCap" placeholder="Capacidad (motos)" type="number" value="'+(p.capacidad||0)+'" style="margin-bottom:8px">';

    // ── Configurador fields ──
    html += sectionTitle('Configurador (visible al cliente)');
    html += '<textarea class="ad-input" id="pfDesc" placeholder="Descripción del punto" style="margin-bottom:8px;min-height:60px;">'+esc(p.descripcion||'')+'</textarea>';
    html += '<label style="font-size:12px;color:var(--ad-dim);margin-bottom:2px;display:block;">Servicios (uno por línea):</label>';
    html += '<textarea class="ad-input" id="pfServicios" placeholder="Exhibición de motos Voltika&#10;Pruebas de manejo&#10;Entrega y activación" style="margin-bottom:8px;min-height:70px;">'+servicios.join('\n')+'</textarea>';
    html += '<label style="font-size:12px;color:var(--ad-dim);margin-bottom:2px;display:block;">Tags / etiquetas (separados por coma):</label>';
    html += '<input class="ad-input" id="pfTags" placeholder="Exhibición, Prueba de manejo, Entrega" value="'+tags.join(', ')+'" style="margin-bottom:8px">';
    html += '<label style="font-size:12px;color:var(--ad-dim);margin-bottom:2px;display:block;">Zonas de cobertura (prefijos CP, separados por coma):</label>';
    html += '<input class="ad-input" id="pfZonas" placeholder="01, 02, 03, 06, 11" value="'+zonas.join(', ')+'" style="margin-bottom:8px">';

    // ── Referido codes ──
    if(!isNew){
      html += sectionTitle('Códigos de referido');
      html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">';
      html += '<div><label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:2px;">Código venta en punto</label>'+
        '<div style="display:flex;gap:4px;"><input class="ad-input" id="pfCodVenta" value="'+esc(p.codigo_venta||'')+'" readonly style="background:#f5f7fa;flex:1;">'+
        '<button class="ad-btn sm ghost pfRegen" data-field="codigo_venta" title="Regenerar" style="padding:4px 8px;">&#8635;</button></div></div>';
      html += '<div><label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:2px;">Código venta online</label>'+
        '<div style="display:flex;gap:4px;"><input class="ad-input" id="pfCodElec" value="'+esc(p.codigo_electronico||'')+'" readonly style="background:#f5f7fa;flex:1;">'+
        '<button class="ad-btn sm ghost pfRegen" data-field="codigo_electronico" title="Regenerar" style="padding:4px 8px;">&#8635;</button></div></div>';
      html += '</div>';
    }

    // ── Coordinates ──
    html += sectionTitle('Ubicación (mapa)');
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">'+
      '<input class="ad-input" id="pfLat" placeholder="Latitud" value="'+(p.lat||'')+'">'+
      '<input class="ad-input" id="pfLng" placeholder="Longitud" value="'+(p.lng||'')+'">'+
    '</div>';

    html += '</div>'; // end scroll container
    html += '<button class="ad-btn primary" id="pfSave" style="width:100%;margin-top:12px;padding:10px;">Guardar</button>';

    ADApp.modal(html);

    $('#pfSave').on('click',function(){
      var serviciosArr = $('#pfServicios').val().split('\n').map(function(s){return s.trim();}).filter(Boolean);
      var tagsArr = $('#pfTags').val().split(',').map(function(s){return s.trim();}).filter(Boolean);
      var zonasArr = $('#pfZonas').val().split(',').map(function(s){return s.trim();}).filter(Boolean);

      var payload = {
        id: p.id||undefined,
        nombre: $('#pfNombre').val(),
        tipo: $('#pfTipo').val(),
        direccion: $('#pfDir').val(),
        colonia: $('#pfColonia').val(),
        ciudad: $('#pfCiudad').val(),
        estado: $('#pfEstado').val(),
        cp: $('#pfCP').val(),
        telefono: $('#pfTel').val(),
        email: $('#pfEmail').val(),
        horarios: $('#pfHorarios').val(),
        capacidad: parseInt($('#pfCap').val())||0,
        activo: $('#pfActivo').is(':checked')?1:0,
        descripcion: $('#pfDesc').val(),
        servicios: serviciosArr,
        tags: tagsArr,
        zonas: zonasArr,
        lat: $('#pfLat').val()||null,
        lng: $('#pfLng').val()||null,
        autorizado: 1
      };

      $(this).prop('disabled',true).html('<span class="ad-spin"></span> Guardando...');
      ADApp.api('puntos/guardar.php', payload).done(function(r2){
        if(r2.ok){ ADApp.closeModal(); render(); }
        else{ alert(r2.error||'Error'); $('#pfSave').prop('disabled',false).html('Guardar'); }
      }).fail(function(){
        alert('Error de conexión');
        $('#pfSave').prop('disabled',false).html('Guardar');
      });
    });

    // Regenerate referido code
    $('.pfRegen').on('click', function(){
      var field = $(this).data('field');
      if(!confirm('Regenerar este código? El código anterior dejará de funcionar.')) return;
      ADApp.api('puntos/regenerar-codigo.php', {id: p.id, campo: field}).done(function(r2){
        if(r2.ok){
          if(field==='codigo_venta') $('#pfCodVenta').val(r2.nuevo_codigo);
          else $('#pfCodElec').val(r2.nuevo_codigo);
        } else alert(r2.error||'Error');
      });
    });
  }

  function sectionTitle(t){
    return '<div style="font-weight:600;font-size:13px;color:var(--ad-primary);margin:12px 0 6px;border-bottom:1px solid #eee;padding-bottom:4px;">'+t+'</div>';
  }

  function parseJson(val){
    if(!val) return [];
    if(Array.isArray(val)) return val;
    try { var a = JSON.parse(val); return Array.isArray(a)?a:[]; } catch(e){ return []; }
  }

  function esc(s){
    return (s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  return { render:render };
})();
