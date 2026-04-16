window.AD_puntos = (function(){
  var puntosData = [];
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  function render(){
    ADApp.render('<div class="ad-h1">Puntos Voltika</div><div><span class="ad-spin"></span></div>');
    ADApp.api('puntos/listar.php').done(paint);
  }

  function paint(r){
    puntosData = r.puntos||[];
    var html = _backBtn+'<div class="ad-toolbar"><div class="ad-h1">Puntos Voltika</div><div style="display:flex;gap:8px;">'+
      '<button class="ad-btn" id="adImportPuntos" style="background:#f0f4f8;color:var(--ad-navy);">Importar Excel</button>'+
      '<button class="ad-btn primary" id="adNewPunto">+ Nuevo punto</button></div></div>';

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
    $('#adImportPuntos').on('click', showImportForm);
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
    html += '<input class="ad-input" id="pfResponsable" placeholder="Nombre del responsable" value="'+esc(p.responsable||'')+'" style="margin-bottom:8px">';
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
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">'+
      '<input class="ad-input" id="pfCP" placeholder="Código postal" value="'+esc(p.cp||'')+'" maxlength="5" inputmode="numeric">'+
      '<input class="ad-input" id="pfTel" placeholder="Teléfono" value="'+esc(p.telefono||'')+'">'+
    '</div>';
    html += '<input class="ad-input" id="pfColonia" placeholder="Colonia (se autocompleta con CP)" value="'+esc(p.colonia||'')+'" style="margin-bottom:8px">';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">'+
      '<input class="ad-input" id="pfCiudad" placeholder="Ciudad" value="'+esc(p.ciudad||'')+'" style="background:#f5f7fa;">'+
      '<input class="ad-input" id="pfEstado" placeholder="Estado" value="'+esc(p.estado||'')+'" style="background:#f5f7fa;">'+
    '</div>';
    html += '<input class="ad-input" id="pfEmail" placeholder="Email" value="'+esc(p.email||'')+'" style="margin-bottom:8px">';

    // ── Schedule & capacity ──
    html += sectionTitle('Horario y capacidad');
    html += '<input class="ad-input" id="pfHorarios" placeholder="Ej: Lun-Vie 9:00-18:00" value="'+esc(p.horarios||'')+'" style="margin-bottom:8px">';
    html += '<label style="font-size:12px;color:var(--ad-dim);margin-bottom:2px;display:block;">Capacidad (número de motos)</label>';
    html += '<input class="ad-input" id="pfCap" placeholder="Ej: 10" type="number" value="'+(p.capacidad?p.capacidad:'')+'" style="margin-bottom:8px">';
    html += '<label style="font-size:12px;color:var(--ad-dim);margin-bottom:2px;display:block;">Orden de aparición (menor = primero)</label>';
    html += '<input class="ad-input" id="pfOrden" placeholder="Ej: 1" type="number" value="'+(p.orden?p.orden:'')+'" style="margin-bottom:8px">';

    // ── Configurador fields ──
    html += sectionTitle('Configurador (visible al cliente)');
    html += '<textarea class="ad-input" id="pfDesc" placeholder="Descripción del punto" style="margin-bottom:8px;min-height:60px;">'+esc(p.descripcion||'')+'</textarea>';

    // Servicios: multiple selection buttons with icons
    html += '<label style="font-size:12px;color:var(--ad-dim);margin-bottom:6px;display:block;">Servicios disponibles:</label>';
    var servicioOpts = [
      {key:'Entrega', icon:'<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="6" width="15" height="12" rx="2"/><path d="M16 10h4l3 4v4h-7V10z"/><circle cx="5.5" cy="20.5" r="1.5"/><circle cx="18.5" cy="20.5" r="1.5"/></svg>'},
      {key:'Exhibición y venta', icon:'<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>'},
      {key:'Servicio Técnico', icon:'<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>'},
      {key:'Pruebas de Manejo', icon:'<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>'},
      {key:'Refacciones', icon:'<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>'}
    ];
    html += '<div id="pfServiciosWrap" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;">';
    servicioOpts.forEach(function(opt){
      var active = servicios.indexOf(opt.key) !== -1;
      html += '<button type="button" class="pfServBtn" data-svc="'+esc(opt.key)+'" style="display:flex;align-items:center;gap:6px;padding:8px 14px;border-radius:8px;border:2px solid '+(active?'var(--ad-primary)':'#ddd')+';background:'+(active?'rgba(0,122,255,0.08)':'#fff')+';cursor:pointer;font-size:13px;font-weight:'+(active?'600':'400')+';color:'+(active?'var(--ad-primary)':'#555')+';transition:all .2s;">'+opt.icon+' '+opt.key+'</button>';
    });
    html += '</div>';

    html += '<label style="font-size:12px;color:var(--ad-dim);margin-bottom:2px;display:block;">Tags / etiquetas (separados por coma):</label>';
    html += '<input class="ad-input" id="pfTags" placeholder="Exhibición, Prueba de manejo, Entrega" value="'+tags.join(', ')+'" style="margin-bottom:8px">';

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

    // ── Commissions per model ──
    if(!isNew){
      html += sectionTitle('Comisiones por modelo');
      html += '<div id="pfComisionesWrap"><span class="ad-spin"></span> Cargando modelos...</div>';
    }

    // ── Coordinates ──
    html += sectionTitle('Ubicación (mapa)');
    html += '<div style="background:#f0f7ff;border-radius:8px;padding:10px 12px;margin-bottom:10px;font-size:12px;color:#336;">'+
      '<strong>Instrucciones:</strong> Para agregar la ubicación exacta del punto, abre <a href="https://www.google.com/maps" target="_blank" style="color:var(--ad-primary);">Google Maps</a>, '+
      'busca la dirección del punto, haz clic derecho sobre la ubicación y copia las coordenadas (latitud, longitud). Pégalas en los campos de abajo.'+
    '</div>';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">'+
      '<input class="ad-input" id="pfLat" placeholder="Ej: 20.5881" value="'+(p.lat||'')+'">'+
      '<input class="ad-input" id="pfLng" placeholder="Ej: -100.3899" value="'+(p.lng||'')+'">'+
    '</div>';

    html += '</div>'; // end scroll container
    html += '<button class="ad-btn primary" id="pfSave" style="width:100%;margin-top:12px;padding:10px;">Guardar</button>';

    ADApp.modal(html);

    // ── Servicios toggle buttons ──
    $(document).off('click.pfServ').on('click.pfServ', '.pfServBtn', function(){
      var $b = $(this);
      var isActive = $b.css('border-color') !== 'rgb(221, 221, 221)';
      if(isActive){
        $b.css({border:'2px solid #ddd', background:'#fff', fontWeight:'400', color:'#555'});
      } else {
        $b.css({border:'2px solid var(--ad-primary)', background:'rgba(0,122,255,0.08)', fontWeight:'600', color:'var(--ad-primary)'});
      }
    });

    // ── CP autofill: Ciudad, Estado, Colonia ──
    $('#pfCP').on('input', function(){
      var cp = $(this).val().replace(/\D/g, '');
      if(cp.length !== 5) return;
      $.ajax({
        url: '../configurador_prueba_test/php/buscar-colonias.php?cp=' + cp,
        dataType: 'json'
      }).done(function(r){
        if(!r.ok) return;
        if(r.estado)  $('#pfEstado').val(r.estado);
        if(r.ciudad || r.municipio) $('#pfCiudad').val(r.ciudad || r.municipio);
        var colonias = r.colonias || [];
        if(colonias.length === 1){
          $('#pfColonia').val(colonias[0]);
        } else if(colonias.length > 1){
          // Replace input with select dropdown
          var sel = '<select class="ad-input" id="pfColonia" style="margin-bottom:8px">';
          sel += '<option value="">— Seleccionar colonia —</option>';
          colonias.forEach(function(c){ sel += '<option value="'+c+'">'+c+'</option>'; });
          sel += '</select>';
          $('#pfColonia').replaceWith(sel);
        }
      });
    });

    // Load commissions for existing punto
    if(!isNew){
      ADApp.api('puntos/comisiones.php?punto_id='+p.id).done(function(rc){
        if(!rc.ok) return;
        var modelos = rc.modelos||[];
        var comMap = {};
        (rc.comisiones||[]).forEach(function(c){ comMap[c.modelo_id] = c; });

        var ch = '<table style="width:100%;font-size:12px;border-collapse:collapse;">';
        ch += '<thead><tr style="border-bottom:1px solid #ddd;">'+
          '<th style="text-align:left;padding:4px;">Modelo</th>'+
          '<th style="text-align:center;padding:4px;">$ Comisión venta</th>'+
          '<th style="text-align:center;padding:4px;">$ Comisión entrega</th></tr></thead><tbody>';

        modelos.forEach(function(m){
          var c = comMap[m.id]||{};
          ch += '<tr data-mid="'+m.id+'" style="border-bottom:1px solid #f0f0f0;">'+
            '<td style="padding:4px;font-weight:600;">'+esc(m.nombre)+'</td>'+
            '<td style="padding:4px;text-align:center;"><div style="display:flex;align-items:center;justify-content:center;gap:2px;"><span style="color:#888;">$</span><input type="number" class="ad-input pfComVenta" step="50" min="0" value="'+(parseFloat(c.comision_venta_pct)||0)+'" style="width:80px;text-align:center;padding:4px;"></div></td>'+
            '<td style="padding:4px;text-align:center;"><div style="display:flex;align-items:center;justify-content:center;gap:2px;"><span style="color:#888;">$</span><input type="number" class="ad-input pfComEntrega" step="50" min="0" value="'+(parseFloat(c.comision_entrega_pct)||0)+'" style="width:80px;text-align:center;padding:4px;"></div></td>'+
          '</tr>';
        });
        ch += '</tbody></table>';
        if(!modelos.length) ch = '<div style="color:#999;font-size:12px;">No hay modelos registrados.</div>';
        $('#pfComisionesWrap').html(ch);
      });
    }

    $('#pfSave').on('click',function(){
      // Collect selected servicios from toggle buttons
      var serviciosArr = [];
      $('.pfServBtn').each(function(){
        var $b = $(this);
        if($b.css('border-color') !== 'rgb(221, 221, 221)') serviciosArr.push($b.data('svc'));
      });
      var tagsArr = $('#pfTags').val().split(',').map(function(s){return s.trim();}).filter(Boolean);
      var zonasArr = [];

      var payload = {
        id: p.id||undefined,
        nombre: $('#pfNombre').val(),
        responsable: $('#pfResponsable').val(),
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
        orden: parseInt($('#pfOrden').val())||0,
        activo: $('#pfActivo').is(':checked')?1:0,
        descripcion: $('#pfDesc').val(),
        servicios: serviciosArr,
        tags: tagsArr,
        zonas: zonasArr,
        lat: $('#pfLat').val()||null,
        lng: $('#pfLng').val()||null,
        autorizado: 1
      };

      // Collect commission data
      var comisiones = [];
      $('#pfComisionesWrap tr[data-mid]').each(function(){
        var mid = $(this).data('mid');
        comisiones.push({
          modelo_id: mid,
          comision_venta_pct: parseFloat($(this).find('.pfComVenta').val())||0,
          comision_entrega_pct: parseFloat($(this).find('.pfComEntrega').val())||0
        });
      });

      $(this).prop('disabled',true).html('<span class="ad-spin"></span> Guardando...');
      ADApp.api('puntos/guardar.php', payload).done(function(r2){
        if(!r2.ok){ alert(r2.error||'Error'); $('#pfSave').prop('disabled',false).html('Guardar'); return; }
        var puntoId = r2.punto_id || p.id;
        if(comisiones.length && puntoId){
          ADApp.api('puntos/comisiones.php', {punto_id: puntoId, comisiones: comisiones}).done(function(){
            ADApp.closeModal(); render();
          }).fail(function(){ ADApp.closeModal(); render(); });
        } else {
          ADApp.closeModal(); render();
        }
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

  // ── Import from Excel ───────────────────────────────────────────────────
  function showImportForm(){
    ADApp.modal(
      '<div class="ad-h2">Importar puntos desde Excel</div>'+
      '<div class="ad-dim" style="margin-bottom:12px;">Formato: CSV o XLSX. Usa la columna <strong>Acción</strong> para controlar la operación:<br>'+
        '<span style="color:#2E7D32;font-weight:600;">agregar</span> = crear nuevo punto, '+
        '<span style="color:#1565C0;font-weight:600;">actualizar</span> = modificar existente (busca por Nombre+CP), '+
        '<span style="color:#C62828;font-weight:600;">eliminar</span> = desactivar punto existente.<br>'+
        'Si no se incluye la columna Acción, todos los registros se crean como nuevos.</div>'+
      '<div style="margin-bottom:12px;">'+
        '<a href="#" id="adDlPuntosTemplate" style="color:var(--ad-primary);font-size:13px;">Descargar plantilla CSV</a>'+
      '</div>'+
      '<input type="file" id="adImportPuntosFile" accept=".csv,.xlsx,.txt" class="ad-input" style="margin-bottom:12px">'+
      '<div id="adImportPuntosPreview" style="display:none;margin-bottom:12px;max-height:200px;overflow-y:auto;font-size:12px;"></div>'+
      '<div id="adImportPuntosResult" style="display:none;margin-bottom:12px;"></div>'+
      '<button class="ad-btn primary" id="adImportPuntosBtn" disabled>Importar</button>'
    );

    $('#adDlPuntosTemplate').on('click', function(e){
      e.preventDefault();
      var csv = 'Acción,Nombre,Tipo,Dirección,Colonia,Ciudad,Estado,CP,Teléfono,Email,Latitud,Longitud,Horarios,Capacidad,Descripción\n';
      csv += 'agregar,Punto Ejemplo,entrega,Av. Reforma 123,Juárez,Ciudad de México,CDMX,06600,5551234567,punto@ejemplo.com,19.4326,-99.1332,Lun-Vie 9:00-18:00,20,Punto de entrega ejemplo\n';
      csv += 'actualizar,Punto Existente,center,Blvd. Centro 456,Centro,Querétaro,QRO,76000,4421234567,centro@ejemplo.com,20.5881,-100.3899,Lun-Sab 10:00-20:00,50,Centro Voltika actualizado\n';
      csv += 'eliminar,Punto A Eliminar,,,,,,76060,,,,,,,,\n';
      var blob = new Blob(['\uFEFF' + csv], {type:'text/csv;charset=utf-8;'});
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'plantilla_puntos.csv';
      a.click();
    });

    $('#adImportPuntosFile').on('change', function(){
      var file = this.files[0];
      if(!file) return;
      if(file.name.match(/\.(csv|txt)$/i)){
        var reader = new FileReader();
        reader.onload = function(e){
          var lines = e.target.result.split('\n').filter(function(l){return l.trim();});
          if(lines.length < 2){ $('#adImportPuntosPreview').html('<div style="color:red;">Archivo vacío</div>').show(); return; }
          var html = '<strong>' + (lines.length-1) + ' puntos detectados</strong><br>';
          html += '<table class="ad-table" style="font-size:11px"><thead><tr>';
          var headers = lines[0].split(',');
          headers.forEach(function(h){ html += '<th>'+h.trim()+'</th>'; });
          html += '</tr></thead><tbody>';
          var max = Math.min(lines.length, 6);
          for(var i=1; i<max; i++){
            html += '<tr>';
            lines[i].split(',').forEach(function(c){ html += '<td>'+c.trim()+'</td>'; });
            html += '</tr>';
          }
          if(lines.length > 6) html += '<tr><td colspan="'+headers.length+'" style="text-align:center">... y ' + (lines.length-6) + ' más</td></tr>';
          html += '</tbody></table>';
          $('#adImportPuntosPreview').html(html).show();
          $('#adImportPuntosBtn').prop('disabled', false);
        };
        reader.readAsText(file);
      } else {
        $('#adImportPuntosPreview').html('<strong>Archivo: </strong>' + file.name + ' (' + Math.round(file.size/1024) + ' KB)').show();
        $('#adImportPuntosBtn').prop('disabled', false);
      }
    });

    $('#adImportPuntosBtn').on('click', function(){
      var file = $('#adImportPuntosFile')[0].files[0];
      if(!file) return;
      var $btn = $(this);
      $btn.prop('disabled', true).html('<span class="ad-spin"></span> Importando...');

      var fd = new FormData();
      fd.append('archivo', file);

      $.ajax({
        url: 'php/puntos/importar.php',
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        xhrFields: { withCredentials: true },
        dataType: 'json'
      }).done(function(r){
        if(r.ok){
          var html = '<div style="padding:12px;border-radius:8px;background:#E8F5E9;color:#2E7D32;">'+
            '<strong>Importación completada</strong><br>'+
            'Creados: <strong>'+r.creados+'</strong> · '+
            'Actualizados: <strong>'+(r.actualizados||0)+'</strong> · '+
            'Eliminados: <strong>'+(r.eliminados||0)+'</strong> · '+
            'Duplicados: '+r.duplicados+' · '+
            'Errores: '+r.errores+' · '+
            'Total filas: '+r.total_filas+
            '</div>';
          if(r.detalle && r.detalle.length){
            html += '<div style="margin-top:8px;font-size:11px;color:#666;">';
            r.detalle.forEach(function(d){ html += d + '<br>'; });
            html += '</div>';
          }
          $('#adImportPuntosResult').html(html).show();
          $btn.html('Cerrar').prop('disabled',false).off('click').on('click',function(){
            ADApp.closeModal();
            render();
          });
        } else {
          $('#adImportPuntosResult').html('<div style="padding:12px;border-radius:8px;background:#FFEBEE;color:#C62828;">'+r.error+'</div>').show();
          $btn.html('Importar').prop('disabled', false);
        }
      }).fail(function(){
        $('#adImportPuntosResult').html('<div style="padding:12px;border-radius:8px;background:#FFEBEE;color:#C62828;">Error de conexión</div>').show();
        $btn.html('Importar').prop('disabled', false);
      });
    });
  }

  return { render:render };
})();
