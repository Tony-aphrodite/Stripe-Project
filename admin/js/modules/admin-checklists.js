window.AD_checklists = (function(){

  // ── Section definitions for Checklist de Origen ──────────────────────────
  var ORIGEN_LEGAL_TEXT = 'La unidad fue revisada, validada y empacada conforme a estándares Voltika. Se confirma que: contenido completo, sistema eléctrico funcional, estética en buen estado.';
  var ORIGEN_REGLA_ORO  = 'Lo que no está validado en origen, no existe.';

  // 10 mandatory photo categories (each needs >=1 image to complete)
  var ORIGEN_PHOTO_CATEGORIES = [
    {campo:'foto_unidad_completa',         label:'Unidad completa'},
    {campo:'foto_vin',                      label:'VIN'},
    {campo:'foto_tablero_encendido',        label:'Tablero encendido'},
    {campo:'foto_bateria',                  label:'Batería'},
    {campo:'foto_contenido_previo_cierre',  label:'Contenido previo cierre'},
    {campo:'foto_caja_cerrada',             label:'Caja cerrada'},
    {campo:'foto_sellos',                   label:'Sellos'},
    {campo:'foto_detalle_calcomanias',      label:'Detalle calcomanías'},
    {campo:'foto_empaque_accesorios',       label:'Empaque de accesorios'},
    {campo:'foto_empaque_llaves',           label:'Empaque de llaves'},
  ];

  var ORIGEN_SECTIONS = [
    { title: '1. Estructura principal', fields: [
      {key:'frame_completo', label:'Frame completo'},
      {key:'chasis_sin_deformaciones', label:'Chasis sin deformaciones'},
      {key:'soportes_estructurales', label:'Soportes estructurales completos'},
      {key:'charola_trasera', label:'Charola trasera instalada'}
    ]},
    { title: '2. Sistema de rodamiento', fields: [
      {key:'llanta_delantera', label:'Llanta delantera'},
      {key:'llanta_trasera', label:'Llanta trasera'},
      {key:'rines_sin_dano', label:'Rines sin daño'},
      {key:'ejes_completos', label:'Ejes completos'}
    ]},
    { title: '3. Dirección y control', fields: [
      {key:'manubrio', label:'Manubrio'},
      {key:'soportes_completos', label:'Soportes completos'},
      {key:'dashboard_incluido', label:'Dashboard incluido'},
      {key:'controles_completos', label:'Controles completos'}
    ]},
    { title: '4. Sistema de frenado', fields: [
      {key:'freno_delantero', label:'Freno delantero completo'},
      {key:'freno_trasero', label:'Freno trasero completo'},
      {key:'discos_sin_dano', label:'Discos sin daño'},
      {key:'calipers_instalados', label:'Cálipers instalados'},
      {key:'lineas_completas', label:'Líneas completas'}
    ]},
    { title: '5. Sistema eléctrico', fields: [
      {key:'cableado_completo', label:'Cableado completo'},
      {key:'conectores_correctos', label:'Conectores correctos'},
      {key:'controlador_instalado', label:'Controlador instalado'},
      {key:'encendido_operativo', label:'Encendido operativo'}
    ]},
    { title: '6. Motor', fields: [
      {key:'motor_instalado', label:'Motor instalado'},
      {key:'motor_sin_dano', label:'Motor sin daño'},
      {key:'motor_conexion', label:'Conexión correcta'}
    ]},
    { title: '7. Baterías', fields: [
      {key:'bateria_1', label:'Batería 1'},
      {key:'bateria_2', label:'Batería 2 (si aplica)', conditional:'bateria_2'},
      {key:'baterias_sin_dano', label:'Sin daño visible'}
    ]},
    { title: '8. Accesorios', fields: [
      {key:'espejos', label:'Espejos (2)'},
      {key:'tornilleria_completa', label:'Tornillería completa'},
      {key:'birlos_completos', label:'Birlos completos'},
      {key:'kit_herramientas', label:'Kit de herramientas'},
      {key:'cargador_incluido', label:'Cargador correspondiente'}
    ]},
    { title: '9. Complementos', fields: [
      {key:'llaves_2', label:'2 llaves con tarjetas NFC (si aplica)'},
      {key:'manual_usuario', label:'Manual de usuario'},
      {key:'carnet_garantia', label:'Carnet de garantía'}
    ]},
    { title: '10. Validación eléctrica (sin movimiento)', nota:'Validación sin rodaje', fields: [
      {key:'sistema_enciende', label:'Sistema enciende'},
      {key:'dashboard_funcional', label:'Dashboard funcional'},
      {key:'indicador_bateria', label:'Indicador de batería'},
      {key:'luces_funcionando', label:'Luces funcionando'},
      {key:'conectores_firmes', label:'Conectores firmes'},
      {key:'cableado_sin_dano', label:'Cableado sin daño'}
    ]},
    { title: '11. Artes decorativos y acabados', fields: [
      {key:'calcomanias_correctas', label:'Calcomanías Voltika correctamente colocadas'},
      {key:'alineacion_correcta', label:'Alineación correcta'},
      {key:'sin_burbujas', label:'Sin burbujas'},
      {key:'sin_desprendimientos', label:'Sin desprendimientos'},
      {key:'sin_rayones', label:'Sin rayones visibles'},
      {key:'acabados_correctos', label:'Acabados estéticos correctos'}
    ]},
    { title: '12. Empaque', fields: [
      {key:'embalaje_correcto', label:'Embalaje correcto con etiquetas Voltika'},
      {key:'protecciones_colocadas', label:'Protecciones colocadas'},
      {key:'caja_sin_dano', label:'Caja sin daño'},
      {key:'sellos_colocados', label:'Sellos colocados'},
      {key:'empaque_accesorios', label:'Empaque de accesorios'},
      {key:'empaque_llaves', label:'Empaque de llaves completo'}
    ]},
    { title: '14. Declaración legal', prologo: ORIGEN_LEGAL_TEXT, fields: [
      {key:'declaracion_aceptada', label:'Acepto la declaración'}
    ]},
    { title: '15. Validación final', fields: [
      {key:'validacion_final', label:'Confirmo información correcta'}
    ]}
  ];

  // Count all binary fields
  var ALL_ORIGEN_FIELDS = [];
  ORIGEN_SECTIONS.forEach(function(s){ s.fields.forEach(function(f){ ALL_ORIGEN_FIELDS.push(f.key); }); });
  var TOTAL_ORIGEN = ALL_ORIGEN_FIELDS.length;

  // ── Main list render ─────────────────────────────────────────────────────
  var currentFilter = '';
  var currentPage   = 1;
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  function render(){
    ADApp.render('<div class="ad-h1">Checklists</div><div><span class="ad-spin"></span> Cargando...</div>');
    load();
  }

  function load(){
    ADApp.api('checklists/listar.php?filtro='+encodeURIComponent(currentFilter)+'&page='+currentPage).done(paint);
  }

  function paint(r){
    var s = r.resumen||{};
    var html = _backBtn+'<div class="ad-toolbar"><div class="ad-h1">Checklists</div></div>';

    // KPIs
    html += '<div class="ad-kpis">';
    [{l:'Total motos',v:s.total,c:'blue'},
     {l:'Origen real',v:s.con_origen,c:'green'},
     {l:'Origen forzado',v:s.origen_forzado,c:'yellow'},
     {l:'Con ensamble',v:s.con_ensamble,c:'yellow'},
     {l:'Con entrega',v:s.con_entrega,c:'green'},
     {l:'3 checklists OK',v:s.completos,c:'green'}].forEach(function(k){
      html += '<div class="ad-kpi"><div class="label">'+k.l+'</div><div class="value '+(k.c||'')+'">'+Number(k.v||0)+'</div></div>';
    });
    html += '</div>';

    // Filters + bulk action toolbar. Customer reported 2026-04-23 that the
    // dropdown + separate "Filtrar" button often felt broken — clicking the
    // button did nothing if the dropdown value looked already selected, or
    // the handler was stuck because paint() re-bound it without off(). Now
    // the filter applies instantly on change (no Filtrar button needed), and
    // a visible "Limpiar" escape hatch resets to "Todos".
    html += '<div class="ad-filters" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">'+
      '<label style="font-size:12px;color:var(--ad-dim);font-weight:600;">Filtrar:</label>'+
      '<select class="ad-select" id="clFiltro" style="width:260px;">'+
        '<option value="">Todos</option>'+
        '<option value="sin_origen"'+(currentFilter==='sin_origen'?' selected':'')+'>Sin checklist origen</option>'+
        '<option value="origen_forzado"'+(currentFilter==='origen_forzado'?' selected':'')+'>⚠ Origen forzado (pendiente inspección)</option>'+
        '<option value="con_origen"'+(currentFilter==='con_origen'?' selected':'')+'>Con origen real</option>'+
        '<option value="sin_ensamble"'+(currentFilter==='sin_ensamble'?' selected':'')+'>Sin ensamble</option>'+
        '<option value="con_ensamble"'+(currentFilter==='con_ensamble'?' selected':'')+'>Con ensamble completo</option>'+
        '<option value="completos"'+(currentFilter==='completos'?' selected':'')+'>3 checklists completos</option>'+
      '</select>'+
      (currentFilter ? '<button class="ad-btn sm ghost" id="clClear" title="Limpiar filtro">✕ Limpiar</button>' : '')+
      '<span id="clCount" style="font-size:12px;color:var(--ad-dim);">'+Number(r.total||0)+' motos</span>'+
      '<div style="flex:1;"></div>'+
      '<button class="ad-btn sm primary" id="clBulkOrigen" disabled '+
        'title="Selecciona motos abajo para activar" '+
        'style="background:#22c55e;border-color:#22c55e;">'+
        'Marcar origen Completo (<span id="clBulkCount">0</span>)'+
      '</button>'+
    '</div>';

    // Table
    html += '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table"><thead><tr>'+
      '<th style="width:36px;"><input type="checkbox" id="clSelAll" title="Seleccionar todos en esta página"></th>'+
      '<th>VIN</th><th>Modelo</th><th>Color</th><th>Estado</th>'+
      '<th>Origen</th><th>Ensamble</th><th>Entrega</th><th></th>'+
    '</tr></thead><tbody>';

    (r.motos||[]).forEach(function(m){
      // Allow selecting motos whose origen is NOT yet complete OR was force-completed
      // (so admin can re-bulk if they want — though re-running bulk on already-forced
      // is a no-op, kept for consistency)
      var canCheck = !m.co_ok || m.co_force == 1;
      html += '<tr>'+
        '<td>'+ (canCheck
          ? '<input type="checkbox" class="clRowChk" data-id="'+m.id+'">'
          : '<span style="color:#cbd5e1;font-size:11px;" title="Ya completado correctamente">&#10003;</span>') +'</td>'+
        '<td><strong>'+(m.vin_display||m.vin||'—')+'</strong></td>'+
        '<td>'+m.modelo+'</td>'+
        '<td>'+m.color+'</td>'+
        '<td>'+ADApp.badgeEstado(m.estado)+'</td>'+
        '<td>'+clBadge(m.co_id, m.co_ok, null, m.co_force)+'</td>'+
        '<td>'+clBadge(m.ce_id, m.ce_ok, m.ce_fase)+'</td>'+
        '<td>'+clBadge(m.cv_id, m.cv_ok, m.cv_fase)+'</td>'+
        '<td><button class="ad-btn sm primary clOpen" data-id="'+m.id+'">Abrir</button></td>'+
      '</tr>';
    });
    html += '</tbody></table></div></div>';

    // Pagination
    if(r.pages>1){
      html += '<div class="ad-pagination">';
      for(var p=1;p<=r.pages;p++) html += '<button class="'+(p===r.page?'active':'')+' clPage" data-p="'+p+'">'+p+'</button>';
      html += '</div>';
    }

    ADApp.render(html);

    // Instant filter — apply on dropdown change. off/on pair prevents
    // duplicate handlers from stacking across paint() re-renders, which was
    // the root cause of the "Filtrar button does nothing" report.
    $('#clFiltro').off('change.clFiltro').on('change.clFiltro', function(){
      var newVal = this.value || '';
      if (newVal === currentFilter) return;
      currentFilter = newVal;
      currentPage   = 1;
      // Loading feedback — avoids the "nothing happened" perception.
      $('#clCount').text('Filtrando…');
      load();
    });
    $('#clClear').off('click').on('click', function(){
      currentFilter = '';
      currentPage   = 1;
      load();
    });
    $('.clOpen').on('click',function(){ showMotoChecklists($(this).data('id')); });
    $('.clPage').on('click',function(){
      var p = parseInt($(this).data('p'), 10);
      if (!p || p === currentPage) return;
      currentPage = p;
      load();
      // Scroll to top so the user doesn't stay stuck at the pagination bar
      // after the table re-renders with the new page's rows.
      $('html, body').animate({ scrollTop: 0 }, 200);
    });

    // ── Bulk origen complete: row checkboxes + select-all + button ──────
    function refreshBulkBtn(){
      var n = $('.clRowChk:checked').length;
      $('#clBulkCount').text(n);
      $('#clBulkOrigen').prop('disabled', n === 0);
    }
    $(document).off('change.clBulk').on('change.clBulk', '.clRowChk', refreshBulkBtn);
    $('#clSelAll').on('change', function(){
      $('.clRowChk').prop('checked', this.checked);
      refreshBulkBtn();
    });
    $('#clBulkOrigen').on('click', function(){
      var ids = $('.clRowChk:checked').map(function(){ return parseInt($(this).data('id'),10); }).get();
      if (!ids.length) return;
      var msg = '⚠️ MARCADO MASIVO SIN INSPECCIÓN\n\n' +
                ids.length + ' motos serán marcadas como Completo SIN realizar la inspección detallada de 55 items + fotos.\n\n' +
                'Úsalo solo para hacer visible el stock en el configurador rápidamente. ' +
                'La inspección física correcta debe hacerse luego abriendo cada moto y reabriendo el checklist.\n\n' +
                '¿Continuar?';
      if (!confirm(msg)) return;
      var $b = $(this).prop('disabled', true).html('<span class="ad-spin"></span> Procesando...');
      ADApp.api('checklists/bulk-complete-origen.php', { moto_ids: ids, notas: 'Inspección masiva desde panel admin' })
        .done(function(r){
          if (r.ok) {
            alert('Listo:\n  Completados: ' + r.completados + '\n  Ya completos: ' + r.ya_completos + '\n  Errores: ' + r.errores);
            load();
          } else {
            alert(r.error || 'Error');
            $b.prop('disabled', false).text('Marcar origen Completo');
          }
        })
        .fail(function(x){
          alert((x.responseJSON && x.responseJSON.error) || 'Error de conexión');
          $b.prop('disabled', false).text('Marcar origen Completo');
        });
    });
  }

  function clBadge(id, ok, fase, isForce){
    if(!id) return '<span class="ad-badge gray">Pendiente</span>';
    if(ok && isForce == 1) {
      // Force-completed (bulk button) — show warning, NOT "Completo"
      return '<span class="ad-badge" style="background:#fef3c7;color:#92400e;border:1px solid #f59e0b" title="Marcado completo sin inspección física. Abrir para reabrir y completar correctamente.">⚠ Pendiente inspección</span>';
    }
    if(ok) return '<span class="ad-badge green">Completo</span>';
    if(fase) return '<span class="ad-badge yellow">'+fase+'</span>';
    return '<span class="ad-badge yellow">En progreso</span>';
  }

  // ── Moto checklists overview (3 tabs) ────────────────────────────────────
  function showMotoChecklists(motoId){
    ADApp.api('checklists/detalle.php?moto_id='+motoId).done(function(r){
      if(!r.ok) return alert(r.error||'Error');
      var m = r.moto;

      var html = '<div class="ad-h2">'+(m.vin_display||m.vin)+' — '+m.modelo+' '+m.color+'</div>';

      // 3 checklist cards
      html += '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px;">';

      // Origen
      html += clCard('Origen', r.origen, 'origen', motoId, function(c){
        if(!c) return 'No iniciado';
        if(c.completado) return 'Completado el '+fmtDate(c.freg);
        var done = countDone(c, ALL_ORIGEN_FIELDS);
        return done+'/'+TOTAL_ORIGEN+' items';
      });

      // Ensamble
      html += clCard('Ensamble', r.ensamble, 'ensamble', motoId, function(c){
        if(!c) return 'No iniciado';
        if(c.completado) return 'Completado';
        return 'Fase: '+c.fase_actual;
      });

      // Entrega
      html += clCard('Entrega', r.entrega, 'entrega', motoId, function(c){
        if(!c) return 'No iniciado';
        if(c.completado) return 'Completado';
        return 'Fase: '+c.fase_actual;
      });

      html += '</div>';

      html += '<button class="ad-btn ghost" id="clBack" style="width:100%;">Volver a lista</button>';

      ADApp.render(html);

      $('.clAction').on('click',function(){
        var tipo = $(this).data('tipo');
        var mid = $(this).data('mid');
        if(tipo==='origen') openOrigenForm(mid, r.origen, r.moto);
        else if(tipo==='ensamble') openEnsambleForm(mid, r.ensamble);
        else if(tipo==='entrega') openEntregaForm(mid, r.entrega, r.moto);
      });

      $('.clPdf').on('click',function(){
        var tipo = $(this).data('tipo');
        var mid = $(this).data('mid');
        window.open('php/checklists/generar-pdf.php?tipo='+tipo+'&moto_id='+mid, '_blank');
      });

      $('#clBack').on('click', load);
    });
  }

  function clCard(title, data, tipo, motoId, statusFn){
    var isComplete = data && data.completado;
    var color = !data ? '#f5f5f5' : (isComplete ? '#E8F5E9' : '#FFF8E1');
    var borderColor = !data ? '#ddd' : (isComplete ? '#4CAF50' : '#FFA000');
    var html = '<div style="background:'+color+';border:2px solid '+borderColor+';border-radius:10px;padding:16px;">'+
      '<div style="font-weight:700;font-size:15px;margin-bottom:6px;">'+title+'</div>'+
      '<div style="font-size:13px;color:#666;margin-bottom:12px;">'+statusFn(data)+'</div>';
    if(isComplete){
      html += '<div style="display:flex;gap:6px;">';
      html += '<button class="ad-btn sm ghost clAction" data-tipo="'+tipo+'" data-mid="'+motoId+'">Ver detalle</button>';
      html += '<button class="ad-btn sm ghost clPdf" data-tipo="'+tipo+'" data-mid="'+motoId+'">PDF</button>';
      html += '</div>';
    } else {
      html += '<button class="ad-btn sm primary clAction" data-tipo="'+tipo+'" data-mid="'+motoId+'">'+(data?'Continuar':'Iniciar')+'</button>';
    }
    html += '</div>';
    return html;
  }

  function countDone(checklist, fields){
    var n = 0;
    fields.forEach(function(f){ if(checklist[f]) n++; });
    return n;
  }

  function fmtDate(d){
    if(!d) return '';
    return d.substring(0,10);
  }

  // ── Checklist de Origen Form ─────────────────────────────────────────────
  function openOrigenForm(motoId, existing, moto){
    var data = existing || {};
    var m    = moto || {};
    var isLocked = data.completado == 1;

    // Detect "force-completed" state: completado=1 but most items are 0
    // (typically from bulk-complete-origen.php). Allow reopen for proper inspection.
    var doneCount = countDone(data, ALL_ORIGEN_FIELDS);
    var isForceCompleted = isLocked && doneCount < (TOTAL_ORIGEN * 0.5);

    var html = '<div class="ad-h2">Checklist de Origen</div>';

    // Progress bar
    var done = doneCount;
    var pct = TOTAL_ORIGEN > 0 ? Math.round(done/TOTAL_ORIGEN*100) : 0;
    html += progressBar(done, TOTAL_ORIGEN, pct);

    if(isLocked){
      if(isForceCompleted){
        // Force-completed via bulk button — show option to reopen
        html += '<div style="background:#FFF3E0;border:2px solid #FB8C00;padding:14px 16px;border-radius:8px;margin-bottom:12px;font-size:13px;color:#E65100;">'+
          '<div style="font-weight:700;font-size:14px;margin-bottom:6px;">⚠️ Checklist marcado como completado sin inspección detallada</div>'+
          '<div style="margin-bottom:10px;">Solo '+done+'/'+TOTAL_ORIGEN+' items están marcados, pero el checklist está cerrado. Probablemente fue activado con el botón "Marcar origen Completo" para que la moto aparezca como stock disponible en el configurador.</div>'+
          '<div style="margin-bottom:10px;">Para realizar la inspección física correcta (marcar 55 items + subir 10 categorías de fotos), <strong>reabre el checklist</strong>.</div>'+
          '<button class="ad-btn primary" id="clReabrirOrigen" style="background:#FB8C00;border-color:#FB8C00;padding:8px 18px;font-weight:700;">🔓 Reabrir y completar correctamente</button>'+
        '</div>';
      } else {
        // Genuinely completed — fully locked
        html += '<div style="background:#E8F5E9;padding:12px;border-radius:8px;margin-bottom:12px;font-size:13px;color:#2E7D32;">'+
          '<div style="font-weight:700;margin-bottom:4px">✅ Checklist completado correctamente ('+done+'/'+TOTAL_ORIGEN+')</div>'+
          '<div>Este checklist está bloqueado para preservar el registro de inspección. Hash: <code style="font-size:10px;word-break:break-all">'+(data.hash_registro||'')+'</code></div>'+
        '</div>';
      }
    }

    // IDENTIFICACIÓN DE UNIDAD
    html += sectionTitle('Identificación de unidad');
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">';
    html += identifField('VIN', esc(m.vin_display || m.vin || data.vin || ''));
    html += identifInput('Número de motor', 'clNumMotor', esc(data.num_motor || ''), isLocked);
    html += identifField('Modelo', esc(m.modelo || data.modelo || ''));
    html += identifField('Color', esc(m.color || data.color || ''));
    html += identifField('Año modelo', esc(m.anio_modelo || data.anio_modelo || ''));
    html += '<div>'+
      '<label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:4px;">Configuración de baterías</label>'+
      '<select class="ad-select" id="clConfigBat" style="width:100%;"'+(isLocked?' disabled':'')+'>'+
        '<option value="1"'+(String(data.config_baterias)!=='2'?' selected':'')+'>1 batería</option>'+
        '<option value="2"'+(String(data.config_baterias)==='2'?' selected':'')+'>2 baterías</option>'+
      '</select>'+
    '</div>';
    html += '</div>';

    // Sections 1-12, 14, 15 with checkboxes
    ORIGEN_SECTIONS.forEach(function(section){
      html += '<div style="margin-bottom:14px;">';
      html += sectionTitle(section.title);
      if(section.nota){
        html += '<div style="background:#FFF3E0;border-left:3px solid #FB8C00;padding:8px 12px;margin-bottom:8px;font-size:12px;color:#795548;"><strong>NOTA:</strong> '+esc(section.nota)+'</div>';
      }
      if(section.prologo){
        html += '<div style="background:#F5F7FA;border:1px solid #e3e7ed;border-radius:6px;padding:10px 12px;margin-bottom:10px;font-size:12.5px;color:#37474F;line-height:1.5;">'+esc(section.prologo)+'</div>';
      }
      section.fields.forEach(function(f){
        html += checkItem(f.key, f.label, data[f.key], isLocked);
      });
      // Section 12 Empaque: append Número de sellos
      if(section.title.indexOf('12.') === 0){
        html += '<div style="margin-top:8px;">'+
          '<label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:4px;">Número de sellos:</label>'+
          '<input type="number" class="ad-input" id="clNumSellos" value="'+(data.num_sellos||0)+'" style="width:100px;"'+(isLocked?' disabled':'')+'>'+
        '</div>';
      }
      html += '</div>';
      // Insert section 13 (photos by category) between section 12 and section 14
      if(section.title.indexOf('12.') === 0){
        html += '<div style="margin-bottom:14px;">';
        html += sectionTitle('13. Evidencia fotográfica');
        html += '<div style="font-size:12px;color:var(--ad-dim);margin-bottom:10px;">Subí al menos una foto por cada categoría. Todas son obligatorias para completar el checklist.</div>';
        ORIGEN_PHOTO_CATEGORIES.forEach(function(cat){
          var catPhotos = [];
          try { catPhotos = JSON.parse(data[cat.campo]||'[]'); } catch(e){}
          if(!Array.isArray(catPhotos)) catPhotos = [];
          html += '<div class="clPhotoCat" data-cat="'+cat.campo+'" style="margin-bottom:10px;padding:10px;background:#FAFBFC;border:1px solid #EAECEE;border-radius:8px;">';
          html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">';
          html += '<span style="font-size:12.5px;font-weight:600;color:#37474F;">'+esc(cat.label)+'</span>';
          html += '<span class="clPhotoCatCount" style="font-size:11px;color:'+(catPhotos.length?'#1e7e34':'#c0392b')+';font-weight:600;">'+catPhotos.length+' foto(s)</span>';
          html += '</div>';
          html += photoZone('clOrigenFoto_'+cat.campo, 'origen', motoId, cat.campo, catPhotos, isLocked);
          html += '</div>';
        });
        // Legacy fotos (if any) shown read-only for backwards compat
        var legacyFotos = [];
        try { legacyFotos = JSON.parse(data.fotos||'[]'); } catch(e){}
        if(Array.isArray(legacyFotos) && legacyFotos.length){
          html += '<details style="margin-top:8px;font-size:11.5px;color:#666;">';
          html += '<summary style="cursor:pointer;">Fotos legacy ('+legacyFotos.length+') — sin categoría</summary>';
          html += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;">';
          legacyFotos.forEach(function(u){
            html += '<img src="'+u+'" style="width:60px;height:60px;object-fit:cover;border-radius:4px;border:1px solid #ddd;cursor:pointer;" onclick="window.open(\''+u+'\',\'_blank\')">';
          });
          html += '</div></details>';
        }
        html += '</div>';
      }
    });

    // Regla de Oro banner
    html += '<div style="background:linear-gradient(135deg,#FFC107,#FF9800);color:#3E2723;border-radius:10px;padding:14px 16px;margin:14px 0;text-align:center;font-weight:700;font-size:14px;letter-spacing:0.5px;box-shadow:0 2px 8px rgba(255,152,0,0.25);">REGLA DE ORO — '+esc(ORIGEN_REGLA_ORO)+'</div>';

    // Notas
    html += '<div style="margin-bottom:12px;">'+
      '<label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:4px;">Notas:</label>'+
      '<textarea class="ad-input" id="clNotas" style="min-height:60px;"'+(isLocked?' disabled':'')+'>'+esc(data.notas||'')+'</textarea>'+
    '</div>';

    if(!isLocked){
      html += '<div style="display:flex;gap:8px;margin-top:16px;">';
      html += '<button class="ad-btn ghost" id="clSaveDraft" style="flex:1;padding:10px;">Guardar borrador</button>';
      html += '<button class="ad-btn primary" id="clComplete" style="flex:1;padding:10px;">Completar checklist</button>';
      html += '</div>';
    }

    html += '<button class="ad-btn ghost" id="clBackToMoto" style="width:100%;margin-top:8px;">Volver</button>';

    ADApp.modal(html);

    // Bind photo events
    bindPhotoEvents();

    // Conditional bateria_2: auto-check & disable when config=1
    function syncBateria2(){
      var cfg = $('#clConfigBat').val();
      var $b2 = $('.clCheck[data-key="bateria_2"]');
      if(isLocked) return;
      if(cfg === '1'){
        $b2.prop('checked', true).prop('disabled', true);
      } else {
        $b2.prop('disabled', false);
      }
      updateProgressGeneric(ALL_ORIGEN_FIELDS, TOTAL_ORIGEN);
    }
    $('#clConfigBat').on('change', syncBateria2);
    syncBateria2();

    // Update progress bar on checkbox change
    $('.clCheck').on('change', function(){ updateProgressGeneric(ALL_ORIGEN_FIELDS, TOTAL_ORIGEN); });

    // Save draft
    $('#clSaveDraft').on('click', function(){
      saveOrigen(motoId, false);
    });

    // Complete
    $('#clComplete').on('click', function(){
      var checked = countChecked();
      if(checked < TOTAL_ORIGEN){
        alert('Faltan '+(TOTAL_ORIGEN-checked)+' items por marcar antes de completar el checklist.');
        return;
      }
      if(!$('#clNumMotor').val().trim()){
        alert('Falta el Número de motor en la sección Identificación de unidad.');
        $('#clNumMotor').focus();
        return;
      }
      // Validate at least 1 photo per category
      var missingPhotos = [];
      $('.clPhotoCat').each(function(){
        if($(this).find('.clThumb').length === 0){
          var label = $(this).find('span').first().text().trim();
          missingPhotos.push(label);
        }
      });
      if(missingPhotos.length){
        alert('Faltan fotos en '+missingPhotos.length+' categoría(s):\n\n• '+missingPhotos.join('\n• '));
        return;
      }
      if(!confirm('¿Completar y bloquear este checklist? No se podrá modificar después.')) return;
      saveOrigen(motoId, true);
    });

    $('#clBackToMoto').on('click', function(){
      ADApp.closeModal();
      showMotoChecklists(motoId);
    });

    // Reabrir force-completed checklist
    $('#clReabrirOrigen').on('click', function(){
      var motivo = prompt('¿Por qué reabres este checklist?\n(Ej: "Inspección física pendiente desde llegada de inventario")', 'Inspección física manual en CEDIS');
      if (motivo === null) return; // user cancelled
      var $btn = $(this).prop('disabled', true).text('Procesando...');
      ADApp.api('checklists/reabrir-origen.php', {
        method: 'POST', contentType: 'application/json',
        data: JSON.stringify({ moto_id: motoId, motivo: motivo })
      }).done(function(r){
        if (r.ok) {
          ADApp.closeModal();
          // Re-fetch and re-open the form (now editable)
          ADApp.api('checklists/detalle.php?moto_id=' + motoId).done(function(rd){
            openOrigenForm(motoId, rd.origen, rd.moto);
          });
        } else {
          alert(r.error || 'Error');
          $btn.prop('disabled', false).text('🔓 Reabrir y completar correctamente');
        }
      }).fail(function(x){
        alert((x.responseJSON && x.responseJSON.error) || 'Error de conexión');
        $btn.prop('disabled', false).text('🔓 Reabrir y completar correctamente');
      });
    });
  }

  function identifField(label, value){
    return '<div>'+
      '<label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:4px;">'+label+'</label>'+
      '<div style="background:#f5f5f5;border:1px solid #e0e0e0;border-radius:6px;padding:8px 10px;font-size:13px;color:#333;min-height:18px;">'+(value||'—')+'</div>'+
    '</div>';
  }

  function identifInput(label, id, value, disabled){
    return '<div>'+
      '<label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:4px;">'+label+'</label>'+
      '<input type="text" class="ad-input" id="'+id+'" value="'+value+'" style="width:100%;"'+(disabled?' disabled':'')+' placeholder="Ingresar…">'+
    '</div>';
  }

  function countChecked(){
    var n = 0;
    $('.clCheck').each(function(){ if($(this).is(':checked')) n++; });
    return n;
  }

  function saveOrigen(motoId, completar){
    var payload = { moto_id: motoId };

    // Collect all checkbox values
    $('.clCheck').each(function(){
      payload[$(this).data('key')] = $(this).is(':checked') ? 1 : 0;
    });

    payload.config_baterias = $('#clConfigBat').val();
    payload.num_motor = ($('#clNumMotor').val() || '').trim();
    payload.num_sellos = parseInt($('#clNumSellos').val()) || 0;
    payload.notas = $('#clNotas').val();
    payload.completado = completar ? 1 : 0;

    var $btn = completar ? $('#clComplete') : $('#clSaveDraft');
    $btn.prop('disabled',true).html('<span class="ad-spin"></span>');

    ADApp.api('checklists/guardar-origen.php', payload).done(function(r){
      if(r.ok){
        if(completar){
          ADApp.closeModal();
          alert('Checklist de origen completado exitosamente');
          showMotoChecklists(motoId);
        } else {
          alert('Borrador guardado');
          $btn.prop('disabled',false).html('Guardar borrador');
        }
      } else {
        alert(r.error||'Error al guardar');
        $btn.prop('disabled',false).html(completar?'Completar checklist':'Guardar borrador');
      }
    }).fail(function(xhr){
      var msg = 'Error de conexión';
      if(xhr.responseJSON && xhr.responseJSON.error) msg = xhr.responseJSON.error;
      alert(msg);
      $btn.prop('disabled',false).html(completar?'Completar checklist':'Guardar borrador');
    });
  }

  // ── Checklist de Ensamble ──────────────────────────────────────────────────

  // Each section carries its own `fotoCampo` so photo evidence is captured
  // per-step rather than dumped into one bulk bucket at the end of the phase.
  // (Customer feedback 2026-04-18: "Pictures of listed assembly process
  // evidence" — every section now has its own photo zone.)
  var ENSAMBLE_PHASES = [
    { key:'fase1', title:'Fase 1 — Inicio', sections:[
      { title:'Recepción y preparación', fotoCampo:'fotos_fase1', fields:[
        {key:'recepcion_validada', label:'Recepción validada (checklist origen completo)'},
        {key:'primera_apertura', label:'Primera apertura del embalaje'},
        {key:'area_segura', label:'Área de trabajo segura y despejada'},
        {key:'herramientas_disponibles', label:'Herramientas disponibles'},
        {key:'equipo_proteccion', label:'Equipo de protección personal'},
        {key:'declaracion_fase1', label:'Declaración de responsabilidad aceptada'}
      ]}
    ]},
    { key:'fase2', title:'Fase 2 — Proceso de ensamble', sections:[
      { title:'2.1 Desembalaje', fotoCampo:'fotos_desembalaje', fields:[
        {key:'componentes_sin_dano', label:'Componentes sin daño'},
        {key:'accesorios_separados', label:'Accesorios separados e identificados'},
        {key:'llanta_identificada', label:'Llanta delantera identificada'}
      ]},
      { title:'2.2 Base y asiento', fotoCampo:'fotos_base', fields:[
        {key:'base_instalada', label:'Base instalada correctamente'},
        {key:'asiento_instalado', label:'Asiento instalado'},
        {key:'tornilleria_base', label:'Tornillería de base completa'},
        {key:'torque_base_25', label:'Torque base 25 Nm confirmado'}
      ]},
      { title:'2.3 Manubrio', fotoCampo:'fotos_manubrio', fields:[
        {key:'manubrio_instalado', label:'Manubrio instalado'},
        {key:'cableado_sin_tension', label:'Cableado sin tensión'},
        {key:'alineacion_manubrio', label:'Alineación del manubrio correcta'},
        {key:'torque_manubrio_25', label:'Torque manubrio 25 Nm confirmado'}
      ]},
      { title:'2.4 Llanta delantera', fotoCampo:'fotos_llanta', fields:[
        {key:'buje_corto', label:'Buje corto instalado'},
        {key:'buje_largo', label:'Buje largo instalado'},
        {key:'disco_alineado', label:'Disco de freno alineado'},
        {key:'eje_instalado', label:'Eje instalado correctamente'},
        {key:'torque_llanta_50', label:'Torque llanta 50 Nm confirmado'}
      ]},
      { title:'2.5 Espejos', fotoCampo:'fotos_espejos', fields:[
        {key:'espejo_izq', label:'Espejo izquierdo instalado'},
        {key:'espejo_der', label:'Espejo derecho instalado'},
        {key:'roscas_ok', label:'Roscas en buen estado'},
        {key:'ajuste_espejos', label:'Ajuste y posición correcta'}
      ]}
    ]},
    { key:'fase3', title:'Fase 3 — Validación final', sections:[
      { title:'3.1 Frenos', fotoCampo:'fotos_3_1_frenos', fields:[
        {key:'freno_del_funcional', label:'Freno delantero funcional'},
        {key:'freno_tras_funcional', label:'Freno trasero funcional'},
        {key:'luz_freno_operativa', label:'Luz de freno operativa'}
      ]},
      { title:'3.2 Iluminación', fotoCampo:'fotos_3_2_iluminacion', fields:[
        {key:'direccionales_ok', label:'Direccionales funcionando'},
        {key:'intermitentes_ok', label:'Intermitentes funcionando'},
        {key:'luz_alta', label:'Luz alta funcional'},
        {key:'luz_baja', label:'Luz baja funcional'}
      ]},
      { title:'3.3 Sistema eléctrico', fotoCampo:'fotos_3_3_electrico', fields:[
        {key:'claxon_ok', label:'Claxon funcional'},
        {key:'dashboard_ok', label:'Dashboard funcional'},
        {key:'bateria_cargando', label:'Batería cargando correctamente'},
        {key:'puerto_carga_ok', label:'Puerto de carga funcional'}
      ]},
      { title:'3.4 Motor y modos', fotoCampo:'fotos_3_4_motor', fields:[
        {key:'modo_eco', label:'Modo ECO funcional'},
        {key:'modo_drive', label:'Modo DRIVE funcional'},
        {key:'modo_sport', label:'Modo SPORT funcional'},
        {key:'reversa_ok', label:'Reversa funcional'}
      ]},
      { title:'3.5 Acceso', fotoCampo:'fotos_3_5_acceso', fields:[
        {key:'nfc_ok', label:'NFC funcional'},
        {key:'control_remoto_ok', label:'Control remoto funcional'},
        {key:'llaves_funcionales', label:'Llaves funcionales'}
      ]},
      { title:'3.6 Validación mecánica', fotoCampo:'fotos_3_6_mecanica', fields:[
        {key:'sin_ruidos', label:'Sin ruidos anormales'},
        {key:'sin_interferencias', label:'Sin interferencias mecánicas'},
        {key:'torques_verificados', label:'Todos los torques verificados'},
        {key:'declaracion_fase3', label:'Declaración de validación aceptada'}
      ]}
    ]}
  ];

  var ALL_ENSAMBLE_FIELDS = [];
  ENSAMBLE_PHASES.forEach(function(ph){ ph.sections.forEach(function(s){ s.fields.forEach(function(f){ ALL_ENSAMBLE_FIELDS.push(f.key); }); }); });
  var TOTAL_ENSAMBLE = ALL_ENSAMBLE_FIELDS.length;

  function openEnsambleForm(motoId, existing){
    var data = existing || {};
    var isLocked = data.completado == 1;

    // Determine active phase tab
    var activeFase = data.fase_actual || 'fase1';
    if(activeFase === 'completado') activeFase = 'fase3';

    var html = '<div class="ad-h2">Checklist de Ensamble</div>';

    // Progress
    var done = countDone(data, ALL_ENSAMBLE_FIELDS);
    var pct = TOTAL_ENSAMBLE > 0 ? Math.round(done/TOTAL_ENSAMBLE*100) : 0;
    html += progressBar(done, TOTAL_ENSAMBLE, pct);

    if(isLocked){
      html += '<div style="background:#E8F5E9;padding:12px;border-radius:8px;margin-bottom:12px;font-size:13px;color:#2E7D32;">'+
        'Este checklist fue completado y no se puede modificar.</div>';
    }

    // Phase tabs
    html += '<div style="display:flex;gap:4px;margin-bottom:16px;" id="clEnsTabs">';
    ENSAMBLE_PHASES.forEach(function(ph, i){
      var phFields = [];
      ph.sections.forEach(function(s){ s.fields.forEach(function(f){ phFields.push(f.key); }); });
      var phDone = countDone(data, phFields);
      var phTotal = phFields.length;
      var isActive = ph.key === activeFase;
      html += '<button class="ad-btn sm '+(isActive?'primary':'ghost')+' clEnsTab" data-fase="'+ph.key+'" style="flex:1;">'+
        'F'+(i+1)+' <span style="font-size:11px;opacity:0.8;">('+phDone+'/'+phTotal+')</span></button>';
    });
    html += '</div>';

    // Phase contents — one photo zone INSIDE each section (customer asked for
    // per-item evidence, implemented at section granularity as the practical
    // balance — too many zones at the checkbox level slows operators down).
    ENSAMBLE_PHASES.forEach(function(ph){
      var isVisible = ph.key === activeFase;
      html += '<div class="clEnsPane" data-fase="'+ph.key+'" style="'+(isVisible?'':'display:none;')+'">';
      html += '<div style="font-weight:700;font-size:14px;margin-bottom:10px;">'+ph.title+'</div>';

      ph.sections.forEach(function(section){
        html += '<div style="margin-bottom:18px;padding-bottom:12px;border-bottom:1px dashed var(--ad-border,#e8eef5);">';
        html += sectionTitle(section.title);
        section.fields.forEach(function(f){
          html += checkItem(f.key, f.label, data[f.key], isLocked);
        });

        // Inline per-section photo zone
        if (section.fotoCampo) {
          var pFotos = [];
          try { pFotos = JSON.parse(data[section.fotoCampo]||'[]'); } catch(e){}
          if(!Array.isArray(pFotos)) pFotos = [];
          html += '<div style="font-size:11px;font-weight:700;letter-spacing:.3px;color:var(--ad-primary,#039fe1);text-transform:uppercase;margin-top:10px;margin-bottom:4px;">Fotos · '+section.title+'</div>';
          html += photoZone('clEnsFoto_'+section.fotoCampo, 'ensamble', motoId, section.fotoCampo, pFotos, isLocked);
        }

        html += '</div>';
      });

      // Legacy bulk photos (fotos_fase3): if the record has them, surface at
      // the bottom of fase3 under a "Fotos generales (legado)" heading so the
      // audit trail isn't lost. New uploads go into the section-specific zones.
      if (ph.key === 'fase3' && data.fotos_fase3) {
        var legacy = [];
        try { legacy = JSON.parse(data.fotos_fase3||'[]'); } catch(e){}
        if (Array.isArray(legacy) && legacy.length) {
          html += '<div style="margin-top:14px;padding:10px;background:#FFFDE7;border-left:3px solid #FFC107;border-radius:4px;">';
          html += '<div style="font-size:11px;font-weight:700;color:#795548;margin-bottom:6px;text-transform:uppercase;letter-spacing:.3px;">Fotos generales (legado)</div>';
          html += photoZone('clEnsFoto_fotos_fase3', 'ensamble', motoId, 'fotos_fase3', legacy, isLocked);
          html += '</div>';
        }
      }

      html += '</div>';
    });

    // Notas
    html += '<div style="margin-bottom:12px;">'+
      '<label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:4px;">Notas:</label>'+
      '<textarea class="ad-input" id="clEnsNotas" style="min-height:60px;"'+(isLocked?' disabled':'')+'>'+esc(data.notas||'')+'</textarea>'+
    '</div>';

    // Wizard-style primary button: on Fase 1/2 it says "Continuar", on Fase 3
    // it says "Completar". The primary button's behavior depends on the
    // currently active fase. We also keep "Guardar borrador" as a secondary
    // escape hatch so the operator can pause mid-work.
    if(!isLocked){
      html += '<div style="display:flex;gap:8px;margin-top:16px;">';
      html += '<button class="ad-btn ghost" id="clEnsSave" style="flex:1;padding:10px;">Guardar borrador</button>';
      html += '<button class="ad-btn primary" id="clEnsNext" style="flex:1;padding:10px;"></button>';
      html += '</div>';
    }
    html += '<button class="ad-btn ghost" id="clEnsBack" style="width:100%;margin-top:8px;">Volver</button>';

    ADApp.modal(html);

    // Bind photo events
    bindPhotoEvents();

    // Wizard state — tracks which fase is currently visible + updates the
    // primary button label/behavior accordingly. Separate from the
    // checklist_ensamble.fase_actual server value (which reflects server-side
    // progress) so the user can also jump tabs manually and come back.
    var faseKeys = ENSAMBLE_PHASES.map(function(p){ return p.key; });
    var currentFaseKey = activeFase;

    function getFaseFields(faseKey){
      var ph = ENSAMBLE_PHASES.filter(function(p){ return p.key === faseKey; })[0];
      if (!ph) return [];
      var fields = [];
      ph.sections.forEach(function(s){ s.fields.forEach(function(f){ fields.push(f.key); }); });
      return fields;
    }

    function isFaseComplete(faseKey){
      var fields = getFaseFields(faseKey);
      if (!fields.length) return false;
      for (var i = 0; i < fields.length; i++) {
        if (!$('input[name="'+fields[i]+'"]').is(':checked')) return false;
      }
      return true;
    }

    function updateWizardButton(){
      var isLastFase = (currentFaseKey === faseKeys[faseKeys.length - 1]);
      var $btn = $('#clEnsNext');
      if (isLastFase) {
        $btn.text('✓ Completar Checklist').css({background:'#22c55e',borderColor:'#22c55e'});
      } else {
        $btn.text('Continuar Checklist →').css({background:'',borderColor:''});
      }
    }

    // Tab switching — also updates the wizard's current fase so the primary
    // button stays in sync when the user jumps tabs manually.
    $('.clEnsTab').on('click',function(){
      var f = $(this).data('fase');
      currentFaseKey = f;
      $('.clEnsTab').removeClass('primary').addClass('ghost');
      $(this).removeClass('ghost').addClass('primary');
      $('.clEnsPane').hide();
      $('.clEnsPane[data-fase="'+f+'"]').show();
      updateWizardButton();
    });

    // Progress update
    $('.clCheck').on('change', function(){
      updateProgressGeneric(ALL_ENSAMBLE_FIELDS, TOTAL_ENSAMBLE);
      updateTabCounters(ENSAMBLE_PHASES, '.clEnsTab');
    });

    $('#clEnsSave').on('click', function(){ saveEnsamble(motoId, false); });

    // Wizard primary button. On fase 1/2 it advances to the next fase after
    // verifying current fase is complete. On fase 3 it finalizes + locks.
    $('#clEnsNext').on('click', function(){
      var faseIdx  = faseKeys.indexOf(currentFaseKey);
      var isLast   = (faseIdx === faseKeys.length - 1);

      if (!isFaseComplete(currentFaseKey)) {
        var ph = ENSAMBLE_PHASES[faseIdx];
        var missing = getFaseFields(currentFaseKey).filter(function(k){ return !$('input[name="'+k+'"]').is(':checked'); }).length;
        alert('Faltan ' + missing + ' item(s) por marcar en ' + (ph ? ph.title : 'esta fase') + ' antes de continuar.');
        return;
      }

      if (isLast) {
        // Final fase → verify ALL items across all fases, then complete
        var checked = countChecked();
        if (checked < TOTAL_ENSAMBLE) {
          alert('Faltan ' + (TOTAL_ENSAMBLE - checked) + ' items por marcar antes de completar el checklist.');
          return;
        }
        if (!confirm('Completar y bloquear este checklist?\n\nSe enviará automáticamente una notificación al cliente: "Tu moto está lista para entrega".')) return;
        saveEnsamble(motoId, true);
        return;
      }

      // Not last fase → save progress + auto-advance to next fase
      saveEnsamble(motoId, false, function(){
        var nextKey = faseKeys[faseIdx + 1];
        currentFaseKey = nextKey;
        $('.clEnsTab').removeClass('primary').addClass('ghost');
        $('.clEnsTab[data-fase="' + nextKey + '"]').removeClass('ghost').addClass('primary');
        $('.clEnsPane').hide();
        $('.clEnsPane[data-fase="' + nextKey + '"]').show();
        updateWizardButton();
        // Scroll to top of modal so user sees the new fase heading
        try { $('#adModalBody').scrollTop(0); } catch(e){}
      });
    });

    $('#clEnsBack').on('click', function(){ ADApp.closeModal(); showMotoChecklists(motoId); });

    // Initialize wizard button label based on the active fase
    updateWizardButton();
  }

  function saveEnsamble(motoId, completar, onAfterSave){
    var payload = { moto_id: motoId };
    $('.clCheck').each(function(){ payload[$(this).data('key')] = $(this).is(':checked') ? 1 : 0; });
    payload.notas = $('#clEnsNotas').val();
    if(completar) payload.completar = 1;

    // Primary "Continuar/Completar" button is #clEnsNext, draft is #clEnsSave.
    var $btn = completar ? $('#clEnsNext') : $('#clEnsSave');
    var originalHtml = $btn.html();
    $btn.prop('disabled',true).html('<span class="ad-spin"></span>');

    ADApp.api('checklists/guardar-ensamble.php', payload).done(function(r){
      if(r.ok){
        if(completar){
          ADApp.closeModal();
          alert('✓ Checklist de ensamble completado.\n\nSe envió la notificación al cliente: "Tu moto está lista para entrega".');
          showMotoChecklists(motoId);
        } else if (typeof onAfterSave === 'function') {
          // Wizard advance: progress saved, move to next fase silently
          $btn.prop('disabled', false).html(originalHtml);
          onAfterSave(r);
        } else {
          alert('Borrador guardado (Fase: '+r.fase_actual+')');
          $btn.prop('disabled', false).html(originalHtml);
        }
      } else {
        alert(r.error||'Error al guardar');
        $btn.prop('disabled', false).html(originalHtml);
      }
    }).fail(function(xhr){
      var msg = 'Error de conexión';
      if(xhr.responseJSON && xhr.responseJSON.error) msg = xhr.responseJSON.error;
      alert(msg);
      $btn.prop('disabled', false).html(originalHtml);
    });
  }

  // ── Checklist de Entrega ─────────────────────────────────────────────────

  var ENTREGA_PHASES = [
    { key:'fase1', title:'Fase 1 — Verificación de identidad', sections:[
      { title:'Identificación del cliente', fields:[
        {key:'ine_presentada', label:'INE presentada'},
        {key:'nombre_coincide', label:'Nombre coincide con la orden'},
        {key:'foto_coincide', label:'Foto de INE coincide con el cliente'},
        {key:'datos_confirmados', label:'Datos personales confirmados'},
        {key:'ultimos4_telefono', label:'Últimos 4 dígitos del teléfono verificados'},
        {key:'modelo_confirmado', label:'Modelo de moto confirmado'},
        {key:'forma_pago_confirmada', label:'Forma de pago confirmada'}
      ]}
    ]},
    { key:'fase2', title:'Fase 2 — Validación de pago', sections:[
      { title:'Confirmación de pagos', fields:[
        {key:'pago_confirmado', label:'Pago confirmado en sistema'},
        {key:'enganche_validado', label:'Enganche validado'},
        {key:'metodo_pago_registrado', label:'Método de pago registrado'},
        {key:'domiciliacion_confirmada', label:'Domiciliación confirmada'}
      ]}
    ]},
    { key:'fase3', title:'Fase 3 — Verificación de unidad', sections:[
      { title:'Estado de la unidad', fields:[
        {key:'vin_coincide', label:'VIN coincide con la orden'},
        {key:'unidad_ensamblada', label:'Unidad ensamblada (checklist ensamble completo)'},
        {key:'estado_fisico_ok', label:'Estado físico correcto'},
        {key:'sin_danos', label:'Sin daños visibles'},
        {key:'unidad_completa', label:'Unidad completa (accesorios, llaves, manual)'}
      ]}
    ]},
    { key:'fase4', title:'Fase 4 — Validación OTP', sections:[
      { title:'Código de verificación', fields:[
        {key:'otp_enviado', label:'OTP enviado al cliente'},
        {key:'otp_validado', label:'OTP validado correctamente'}
      ]}
    ]},
    { key:'fase5', title:'Fase 5 — Finaliza tu compra', sections:[
      { title:'Pagaré electrónico', fields:[
        {key:'acta_aceptada', label:'Acta de entrega aceptada', auto:true},
        {key:'clausula_identidad', label:'Cláusula de identidad', auto:true},
        {key:'clausula_medios', label:'Cláusula de medios de pago', auto:true},
        {key:'clausula_uso_info', label:'Cláusula de uso de información', auto:true},
        {key:'firma_digital', label:'Firma digital registrada', auto:true}
      ]}
    ]}
  ];

  var ALL_ENTREGA_FIELDS = [];
  ENTREGA_PHASES.forEach(function(ph){ ph.sections.forEach(function(s){ s.fields.forEach(function(f){ ALL_ENTREGA_FIELDS.push(f.key); }); }); });
  var TOTAL_ENTREGA = ALL_ENTREGA_FIELDS.length;

  function openEntregaForm(motoId, existing, moto){
    var data = existing || {};
    moto = moto || {};
    var isLocked = data.completado == 1;

    var activeFase = data.fase_actual || 'fase1';
    if(activeFase === 'completado') activeFase = 'fase5';

    var html = '<div class="ad-h2">Checklist de Entrega</div>';

    var done = countDone(data, ALL_ENTREGA_FIELDS);
    var pct = TOTAL_ENTREGA > 0 ? Math.round(done/TOTAL_ENTREGA*100) : 0;
    html += progressBar(done, TOTAL_ENTREGA, pct);

    if(isLocked){
      html += '<div style="background:#E8F5E9;padding:12px;border-radius:8px;margin-bottom:12px;font-size:13px;color:#2E7D32;">'+
        'Este checklist fue completado y no se puede modificar.</div>';
    }

    // Phase tabs — 5 tabs, compact
    html += '<div style="display:flex;gap:3px;margin-bottom:16px;" id="clEntTabs">';
    ENTREGA_PHASES.forEach(function(ph, i){
      var phFields = [];
      ph.sections.forEach(function(s){ s.fields.forEach(function(f){ phFields.push(f.key); }); });
      var phDone = countDone(data, phFields);
      var phTotal = phFields.length;
      var isActive = ph.key === activeFase;
      html += '<button class="ad-btn sm '+(isActive?'primary':'ghost')+' clEntTab" data-fase="'+ph.key+'" style="flex:1;font-size:12px;padding:6px 4px;">'+
        'F'+(i+1)+' <span style="font-size:10px;opacity:0.8;">('+phDone+'/'+phTotal+')</span></button>';
    });
    html += '</div>';

    // Photo campo mapping per entrega phase (only fase1 and fase3 have photos)
    var entFotoCampos = {fase1:'fotos_identidad', fase3:'fotos_unidad'};

    // Phase contents
    ENTREGA_PHASES.forEach(function(ph){
      var isVisible = ph.key === activeFase;
      html += '<div class="clEntPane" data-fase="'+ph.key+'" style="'+(isVisible?'':'display:none;')+'">';
      html += '<div style="font-weight:700;font-size:14px;margin-bottom:10px;">'+ph.title+'</div>';

      ph.sections.forEach(function(section){
        // Fase 5: auto fields are hidden (no checkboxes per customer request — only "Finaliza tu compra")
        var visibleFields = section.fields.filter(function(f){ return !f.auto; });
        var autoFields = section.fields.filter(function(f){ return f.auto; });

        if(visibleFields.length > 0){
          html += '<div style="margin-bottom:14px;">';
          html += sectionTitle(section.title);
          visibleFields.forEach(function(f){
            html += checkItem(f.key, f.label, data[f.key], isLocked);
          });
          html += '</div>';
        }
        // Auto fields: hidden inputs, auto-checked when signatures are saved
        autoFields.forEach(function(f){
          html += '<input type="checkbox" class="clCheck" data-key="'+f.key+'" '+(data[f.key]?'checked':'')+' style="display:none;">';
        });
      });

      // Phase photos
      var fotoCampo = entFotoCampos[ph.key];
      if(fotoCampo){
        var pFotos = [];
        try { pFotos = JSON.parse(data[fotoCampo]||'[]'); } catch(e){}
        if(!Array.isArray(pFotos)) pFotos = [];
        html += photoZone('clEntFoto_'+ph.key, 'entrega', motoId, fotoCampo, pFotos, isLocked);
      }

      // ── Fase 1: Face match ──
      if(ph.key === 'fase1'){
        html += '<div style="background:#f5f7fa;padding:12px;border-radius:8px;margin-top:8px;" id="clFaceZone">';
        html += sectionTitle('Reconocimiento facial');
        if(data.face_match_result){
          var fScore = data.face_match_score ? (parseFloat(data.face_match_score)*100).toFixed(1)+'%' : '—';
          var fColor = data.face_match_result === 'match' ? '#4CAF50' : '#F44336';
          html += '<div style="font-size:13px;">Resultado: <span style="color:'+fColor+';font-weight:600;">'+data.face_match_result+'</span> ('+fScore+')</div>';
        }
        if(!isLocked && !data.face_match_result){
          html += '<div style="font-size:12px;color:var(--ad-dim);margin-bottom:8px;">Tomar foto del cliente para comparar con selfie del expediente:</div>';
          html += '<label class="ad-btn sm primary" style="cursor:pointer;display:inline-flex;align-items:center;gap:4px;">'+
            '<input type="file" accept="image/*" capture="user" id="clFaceInput" style="display:none;">'+
            'Tomar / subir foto</label>';
          html += '<span id="clFaceStatus" style="margin-left:8px;font-size:12px;color:var(--ad-dim);"></span>';
        } else if(!data.face_match_result && !isLocked){
          html += '<div style="font-size:12px;color:var(--ad-dim);">No se ha realizado comparación facial.</div>';
        }
        html += '</div>';
      }

      // ── Fase 4: OTP send/verify UI ──
      if(ph.key === 'fase4'){
        html += '<div style="background:#f5f7fa;padding:14px;border-radius:8px;margin-top:8px;" id="clOtpZone">';
        if(data.otp_validado){
          html += '<div style="color:#4CAF50;font-weight:600;font-size:14px;">OTP validado correctamente</div>';
        } else if(!isLocked) {
          html += '<div style="margin-bottom:10px;">';
          html += '<button class="ad-btn sm primary" id="clOtpSend">Enviar OTP al cliente</button>';
          html += '<span id="clOtpStatus" style="margin-left:8px;font-size:12px;color:var(--ad-dim);"></span>';
          html += '</div>';
          html += '<div style="display:flex;gap:6px;align-items:center;">';
          html += '<input class="ad-input" id="clOtpCode" placeholder="Código de 6 dígitos" maxlength="6" style="width:160px;">';
          html += '<button class="ad-btn sm ghost" id="clOtpVerify">Verificar</button>';
          html += '</div>';
        }
        html += '</div>';
      }

      // ── Fase 5: Dual signatures ──
      if(ph.key === 'fase5'){

        // ── Firma 1: Acta de entrega ──
        html += '<div style="margin-top:10px;padding:16px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;margin-bottom:14px;">';
        html += '<div style="font-weight:700;font-size:14px;color:#0369a1;margin-bottom:4px;">1. Confirma la entrega</div>';
        html += '<div style="font-size:12px;color:#64748b;margin-bottom:10px;">Acta de entrega — OTP ya confirmado</div>';
        if(data.firma_acta_data){
          html += '<div style="border:1px solid #ddd;border-radius:8px;padding:8px;display:inline-block;background:#fff;">'+
            '<img src="'+data.firma_acta_data+'" style="max-width:300px;height:auto;"></div>';
          html += '<div style="font-size:12px;color:#4CAF50;margin-top:4px;font-weight:600;">Firma de entrega capturada</div>';
        } else if(!isLocked){
          html += '<div id="clFirmaActaWrapper" style="border:2px dashed #0ea5e9;border-radius:8px;position:relative;background:#fff;">';
          html += '<canvas id="clFirmaActaCanvas" style="width:100%;height:120px;display:block;cursor:crosshair;"></canvas>';
          html += '<div id="clFirmaActaPlaceholder" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);color:#bbb;font-size:13px;pointer-events:none;">Firmar aquí</div>';
          html += '</div>';
          html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;">';
          html += '<span id="clFirmaActaStatus" style="font-size:12px;color:#999;">Pendiente de firma</span>';
          html += '<button class="ad-btn sm ghost" id="clFirmaActaClear">Limpiar</button>';
          html += '</div>';
        } else {
          html += '<div style="font-size:13px;color:var(--ad-dim);">Sin firma registrada</div>';
        }
        html += '</div>';

        // ── Firma 2: Plan de pago (SOLO para órdenes de crédito) ──
        // Pagaré is a legal promissory note — only meaningful when the customer
        // owes installments. For contado / MSI / SPEI / OXXO orders the full
        // amount is already paid, so this block is hidden and only the acta
        // signature above remains. (Customer feedback 2026-04-19.)
        var tpagoLc = (moto.tpago || '').toLowerCase();
        var isCreditFamily = ['credito','enganche','parcial'].indexOf(tpagoLc) >= 0;
        if (isCreditFamily) {
        html += '<div style="padding:16px;background:#fefce8;border:1px solid #fde68a;border-radius:10px;">';
        html += '<div style="font-weight:700;font-size:14px;color:#92400e;margin-bottom:4px;">2. Confirma tu plan de pago</div>';
        html += '<div style="font-size:12px;color:#64748b;margin-bottom:10px;">Acuerdo certificado digitalmente</div>';

        // Native plan summary card (replaces the old PDF preview card)
        var plan = (moto && moto.plan) ? moto.plan : {};
        var planNombre  = plan.nombre || (moto && moto.cliente_nombre) || data.cliente_nombre || '—';
        var planModelo  = (moto && moto.modelo) ? (moto.modelo + (moto.color ? ' · ' + moto.color : '')) : '—';
        var planSemanal = plan.pago_semanal ? '$' + Number(plan.pago_semanal).toLocaleString('es-MX', {minimumFractionDigits:2}) + ' MXN' : '—';
        var planPlazo   = plan.plazo_semanas ? plan.plazo_semanas + ' semanas' : (plan.plazo_meses ? plan.plazo_meses + ' meses' : '—');
        var planPrimer  = 'Día de entrega';

        html += '<div style="margin-bottom:12px;padding:14px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;">';
        html += '<div style="display:grid;grid-template-columns:auto 1fr;gap:8px 12px;font-size:13px;line-height:1.5;">';
        html += '<div style="color:#64748b;">🏍️ Modelo:</div><div style="font-weight:600;color:#1a3a5c;">'+esc(planModelo)+'</div>';
        html += '<div style="color:#64748b;">👤 Nombre:</div><div style="font-weight:600;color:#1a3a5c;">'+esc(planNombre)+'</div>';
        html += '<div style="color:#64748b;">💳 Pago semanal:</div><div style="font-weight:700;color:#039fe1;">'+esc(planSemanal)+'</div>';
        html += '<div style="color:#64748b;">⏳ Plazo:</div><div style="font-weight:600;color:#1a3a5c;">'+esc(planPlazo)+'</div>';
        html += '<div style="color:#64748b;">📆 Primer pago:</div><div style="font-weight:600;color:#1a3a5c;">'+esc(planPrimer)+'</div>';
        html += '</div>';
        html += '<div style="margin-top:12px;padding-top:10px;border-top:1px dashed #e5e7eb;font-size:12px;color:#64748b;text-align:center;">Al firmar aceptas tu plan de pago con <strong style="color:#1a3a5c;">VOLTIKA</strong>.</div>';
        html += '</div>';

        if(data.firma_pagare_data){
          html += '<div style="border:1px solid #ddd;border-radius:8px;padding:8px;display:inline-block;background:#fff;">'+
            '<img src="'+data.firma_pagare_data+'" style="max-width:300px;height:auto;"></div>';
          html += '<div style="font-size:12px;color:#4CAF50;margin-top:4px;font-weight:600;">Firma capturada</div>';
        } else if(!isLocked){
          html += '<div id="clFirmaPagareWrapper" style="border:2px dashed #f59e0b;border-radius:8px;position:relative;background:#fff;">';
          html += '<canvas id="clFirmaPagareCanvas" style="width:100%;height:120px;display:block;cursor:crosshair;"></canvas>';
          html += '<div id="clFirmaPagarePlaceholder" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);color:#bbb;font-size:13px;pointer-events:none;">Firma aquí</div>';
          html += '</div>';
          html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;">';
          html += '<span id="clFirmaPagareStatus" style="font-size:12px;color:#999;">Pendiente de firma</span>';
          html += '<button class="ad-btn sm ghost" id="clFirmaPagareClear">Limpiar</button>';
          html += '</div>';
        } else {
          html += '<div style="font-size:13px;color:var(--ad-dim);">Sin firma registrada</div>';
        }
        html += '</div>';
        } // end if(isCreditFamily)

      }

      html += '</div>';
    });

    // Notas
    html += '<div style="margin-bottom:12px;">'+
      '<label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:4px;">Notas:</label>'+
      '<textarea class="ad-input" id="clEntNotas" style="min-height:60px;"'+(isLocked?' disabled':'')+'>'+esc(data.notas||'')+'</textarea>'+
    '</div>';

    if(!isLocked){
      html += '<div style="display:flex;gap:8px;margin-top:16px;">';
      html += '<button class="ad-btn ghost" id="clEntSave" style="flex:1;padding:10px;">Guardar borrador</button>';
      html += '<button class="ad-btn primary" id="clEntComplete" style="flex:1;padding:10px;">Completar checklist</button>';
      html += '</div>';
    }
    html += '<button class="ad-btn ghost" id="clEntBack" style="width:100%;margin-top:8px;">Volver</button>';

    ADApp.modal(html);

    // Bind photo events
    bindPhotoEvents();

    // Init signature canvas
    initSignatureCanvas();

    // OTP events
    $('#clOtpSend').on('click', function(){
      var $btn = $(this);
      $btn.prop('disabled',true).html('<span class="ad-spin"></span>');
      ADApp.api('checklists/enviar-otp.php', {moto_id: motoId}).done(function(r){
        if(r.ok){
          var msg = r.enviado ? 'SMS enviado a '+r.telefono_masked : 'Código de respaldo: '+r.fallback_code;
          $('#clOtpStatus').text(msg).css('color', r.enviado ? '#4CAF50' : '#FF9800');
          // Auto-check otp_enviado
          $('.clCheck[data-key="otp_enviado"]').prop('checked', true);
        } else {
          $('#clOtpStatus').text(r.error||'Error').css('color','#F44336');
        }
        $btn.prop('disabled',false).html('Enviar OTP al cliente');
      }).fail(function(xhr){
        var msg = 'Error de conexión';
        if(xhr.responseJSON && xhr.responseJSON.error) msg = xhr.responseJSON.error;
        $('#clOtpStatus').text(msg).css('color','#F44336');
        $btn.prop('disabled',false).html('Enviar OTP al cliente');
      });
    });

    $('#clOtpVerify').on('click', function(){
      var code = $('#clOtpCode').val().trim();
      if(!code || code.length < 6){ alert('Ingresa el código de 6 dígitos'); return; }
      var $btn = $(this);
      $btn.prop('disabled',true).html('<span class="ad-spin"></span>');
      ADApp.api('checklists/verificar-otp.php', {moto_id: motoId, codigo: code}).done(function(r){
        if(r.ok && r.validado){
          $('#clOtpZone').html('<div style="color:#4CAF50;font-weight:600;font-size:14px;">OTP validado correctamente</div>');
          $('.clCheck[data-key="otp_validado"]').prop('checked', true);
        } else {
          alert(r.error||'Código incorrecto');
          $btn.prop('disabled',false).html('Verificar');
        }
      }).fail(function(xhr){
        var msg = 'Error de conexión';
        if(xhr.responseJSON && xhr.responseJSON.error) msg = xhr.responseJSON.error;
        alert(msg);
        $btn.prop('disabled',false).html('Verificar');
      });
    });

    // Face compare
    $('#clFaceInput').on('change', function(){
      var rawFile = this.files[0];
      if(!rawFile) return;
      $('#clFaceStatus').html('<span class="ad-spin"></span> Comparando...').css('color','var(--ad-dim)');
      (window.voltikaCompressImage ? window.voltikaCompressImage(rawFile) : Promise.resolve(rawFile)).then(function(file){
      var fd = new FormData();
      fd.append('foto', file);
      fd.append('moto_id', motoId);
      $.ajax({
        url: 'php/checklists/face-compare.php',
        method: 'POST', data: fd, processData: false, contentType: false,
        xhrFields: { withCredentials: true }, dataType: 'json'
      }).done(function(r){
        if(r.ok){
          if(r.comparison && r.match){
            $('#clFaceStatus').html('Coincide (' + (r.similarity ? (r.similarity*100).toFixed(1)+'%' : '') + ')').css('color','#4CAF50');
            $('.clCheck[data-key="foto_coincide"]').prop('checked', true);
          } else if(r.comparison && !r.match){
            $('#clFaceStatus').html('NO coincide. Verificar manualmente.').css('color','#F44336');
          } else {
            $('#clFaceStatus').html(r.message||'Sin comparación automática').css('color','#FF9800');
          }
        } else {
          $('#clFaceStatus').html(r.error||'Error').css('color','#F44336');
        }
      }).fail(function(xhr){
        var msg = 'Error de conexión';
        if(xhr.responseJSON && xhr.responseJSON.error) msg = xhr.responseJSON.error;
        $('#clFaceStatus').html(msg).css('color','#F44336');
      });
      });  // voltikaCompressImage
    });

    // Tab switching
    $('.clEntTab').on('click',function(){
      var f = $(this).data('fase');
      $('.clEntTab').removeClass('primary').addClass('ghost');
      $(this).removeClass('ghost').addClass('primary');
      $('.clEntPane').hide();
      $('.clEntPane[data-fase="'+f+'"]').show();
      // Re-init signature canvas when F5 becomes visible
      if(f === 'fase5') initSignatureCanvas();
    });

    // Pagaré preview handler removed — legal document is backend-only, never
    // exposed to the customer (customer feedback 2026-04-19).

    $('.clCheck').on('change', function(){
      updateProgressGeneric(ALL_ENTREGA_FIELDS, TOTAL_ENTREGA);
      updateTabCounters(ENTREGA_PHASES, '.clEntTab');
    });

    $('#clEntSave').on('click', function(){ saveEntrega(motoId, false); });
    $('#clEntComplete').on('click', function(){
      var checked = countChecked();
      if(checked < TOTAL_ENTREGA){
        alert('Faltan '+(TOTAL_ENTREGA-checked)+' items por marcar antes de completar el checklist.');
        return;
      }
      if(!confirm('Completar y bloquear este checklist?')) return;
      saveEntrega(motoId, true);
    });
    $('#clEntBack').on('click', function(){ ADApp.closeModal(); showMotoChecklists(motoId); });
  }

  function saveEntrega(motoId, completar){
    // Auto-check fase5 hidden fields when signatures exist
    var firmaActa = getSignatureData('acta');
    var firmaPagare = getSignatureData('pagare');
    if(firmaActa || firmaPagare){
      ['acta_aceptada','clausula_identidad','clausula_medios','clausula_uso_info','firma_digital'].forEach(function(k){
        $('.clCheck[data-key="'+k+'"]').prop('checked', true);
      });
    }

    var payload = { moto_id: motoId };
    $('.clCheck').each(function(){ payload[$(this).data('key')] = $(this).is(':checked') ? 1 : 0; });
    payload.notas = $('#clEntNotas').val();
    if(completar) payload.completar = 1;

    var $btn = completar ? $('#clEntComplete') : $('#clEntSave');
    $btn.prop('disabled',true).html('<span class="ad-spin"></span>');

    // Step 1: Save acta signature
    var actaPromise = firmaActa
      ? ADApp.api('checklists/guardar-firma.php', { moto_id: motoId, tipo: 'acta', firma_data: firmaActa })
      : $.Deferred().resolve({ok:true});

    actaPromise.always(function(){
      // Step 2: Save pagaré signature (this triggers PDF generation + CINCEL)
      var pagarePromise;
      if(firmaPagare){
        $('#clFirmaPagareStatus').text('Guardando firma...').css('color','#92400e');
        pagarePromise = ADApp.api('checklists/guardar-firma.php', { moto_id: motoId, tipo: 'pagare', firma_data: firmaPagare });
      } else {
        pagarePromise = $.Deferred().resolve({ok:true});
      }

      pagarePromise.done(function(pagareResp){
        // Legal document (pagaré + NOM-151 hash + CINCEL ID) is generated
        // server-side on save but intentionally hidden from the customer-facing
        // UI — do not surface pdf_hash, cincel_id, or download links here.
        if(firmaPagare){
          $('#clFirmaPagareStatus').text('Firma capturada').css('color','#4CAF50');
        }
      }).always(function(){
        // Step 3: Save checklist data
        ADApp.api('checklists/guardar-entrega.php', payload).done(function(r){
          if(r.ok){
            if(completar){
              ADApp.closeModal();
              alert('Checklist de entrega completado exitosamente');
              showMotoChecklists(motoId);
            } else {
              alert('Borrador guardado (Fase: '+r.fase_actual+')');
              $btn.prop('disabled',false).html('Guardar borrador');
            }
          } else {
            alert(r.error||'Error al guardar');
            $btn.prop('disabled',false).html(completar?'Completar checklist':'Guardar borrador');
          }
        }).fail(function(xhr){
          var msg = 'Error de conexión';
          if(xhr.responseJSON && xhr.responseJSON.error) msg = xhr.responseJSON.error;
          alert(msg);
          $btn.prop('disabled',false).html(completar?'Completar checklist':'Guardar borrador');
        });
      }); // end pagarePromise.always
    }); // end actaPromise
  }

  // ── Shared UI helpers ────────────────────────────────────────────────────

  function progressBar(done, total, pct){
    return '<div style="margin-bottom:16px;">'+
      '<div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;">'+
        '<span>Progreso: <strong>'+done+'/'+total+'</strong></span>'+
        '<span><strong>'+pct+'%</strong></span>'+
      '</div>'+
      '<div style="background:#eee;border-radius:6px;height:8px;overflow:hidden;">'+
        '<div id="clProgressBar" style="background:var(--ad-primary);height:100%;width:'+pct+'%;transition:width 0.3s;"></div>'+
      '</div>'+
    '</div>';
  }

  function sectionTitle(t){
    return '<div style="font-weight:600;font-size:13px;color:var(--ad-primary);border-bottom:1px solid #eee;padding-bottom:4px;margin-bottom:8px;">'+t+'</div>';
  }

  function checkItem(key, label, value, disabled){
    return '<label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;cursor:'+(disabled?'default':'pointer')+';">'+
      '<input type="checkbox" class="clCheck" data-key="'+key+'"'+(value?' checked':'')+(disabled?' disabled':'')+'>'+
      '<span>'+label+'</span>'+
    '</label>';
  }

  function updateProgressGeneric(allFields, total){
    var done = countChecked();
    var pct = total > 0 ? Math.round(done/total*100) : 0;
    $('#clProgressBar').css('width', pct+'%');
    $('#clProgressBar').parent().prev().find('span:first strong').text(done+'/'+total);
    $('#clProgressBar').parent().prev().find('span:last strong').text(pct+'%');

  }

  function updateTabCounters(phases, tabSelector){
    $(tabSelector).each(function(i){
      var ph = phases[i];
      if(!ph) return;
      var phDone = 0, phTotal = 0;
      ph.sections.forEach(function(s){
        s.fields.forEach(function(f){
          phTotal++;
          if($('.clCheck[data-key="'+f.key+'"]').is(':checked')) phDone++;
        });
      });
      $(this).find('span').text('('+phDone+'/'+phTotal+')');
    });
  }

  // ── Signature canvas ──────────────────────────────────────────────────────

  // Dual signature state
  var _sigState = { acta: { canvas:null, ctx:null, drawing:false, signed:false }, pagare: { canvas:null, ctx:null, drawing:false, signed:false } };

  function _initOneCanvas(tipo, canvasId, wrapperId, placeholderId, statusId, clearBtnId, borderColor){
    var canvas = document.getElementById(canvasId);
    if(!canvas) return;
    var rect = canvas.getBoundingClientRect();
    var w = rect.width > 0 ? rect.width : (canvas.parentElement ? canvas.parentElement.clientWidth : 320);
    var h = 120;
    canvas.width = w; canvas.height = h; canvas.style.height = h+'px';
    var ctx = canvas.getContext('2d');
    ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111827';
    var st = _sigState[tipo];
    st.canvas = canvas; st.ctx = ctx; st.signed = false;
    function getPos(e){ var r=canvas.getBoundingClientRect(); return {x:e.clientX-r.left,y:e.clientY-r.top}; }
    canvas.addEventListener('mousedown', function(e){ st.drawing=true; var p=getPos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); $('#'+placeholderId).hide(); });
    canvas.addEventListener('mousemove', function(e){ if(!st.drawing) return; var p=getPos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); st.signed=true; $('#'+statusId).text('Firma capturada').css('color','#4CAF50'); $('#'+wrapperId).css('border-color', borderColor); });
    canvas.addEventListener('mouseup', function(){ st.drawing=false; });
    canvas.addEventListener('mouseleave', function(){ st.drawing=false; });
    canvas.addEventListener('touchstart', function(e){ e.preventDefault(); st.drawing=true; var p=getPos(e.touches[0]); ctx.beginPath(); ctx.moveTo(p.x,p.y); $('#'+placeholderId).hide(); }, {passive:false});
    canvas.addEventListener('touchmove', function(e){ e.preventDefault(); if(!st.drawing) return; var p=getPos(e.touches[0]); ctx.lineTo(p.x,p.y); ctx.stroke(); st.signed=true; $('#'+statusId).text('Firma capturada').css('color','#4CAF50'); $('#'+wrapperId).css('border-color', borderColor); }, {passive:false});
    canvas.addEventListener('touchend', function(){ st.drawing=false; });
    $('#'+clearBtnId).on('click', function(){
      if(!st.ctx||!st.canvas) return;
      st.ctx.clearRect(0,0,st.canvas.width,st.canvas.height);
      st.signed=false;
      $('#'+placeholderId).show();
      $('#'+statusId).text('Pendiente de firma').css('color','#999');
      $('#'+wrapperId).css('border-color','#ccc');
    });
  }

  function initSignatureCanvas(){
    _initOneCanvas('acta','clFirmaActaCanvas','clFirmaActaWrapper','clFirmaActaPlaceholder','clFirmaActaStatus','clFirmaActaClear','#0ea5e9');
    _initOneCanvas('pagare','clFirmaPagareCanvas','clFirmaPagareWrapper','clFirmaPagarePlaceholder','clFirmaPagareStatus','clFirmaPagareClear','#f59e0b');
  }

  function getSignatureData(tipo){
    var st = _sigState[tipo||'acta'];
    if(!st.canvas || !st.signed) return null;
    return st.canvas.toDataURL('image/png');
  }

  // ── Photo upload helpers ──────────────────────────────────────────────────

  /**
   * Render a photo upload zone with existing thumbnails
   * @param {string} id       - unique DOM id for this upload zone
   * @param {string} tipo     - 'origen' | 'ensamble' | 'entrega'
   * @param {number} motoId
   * @param {string} campo    - DB column name (e.g. 'fotos', 'fotos_fase1')
   * @param {Array}  existing - array of existing photo URLs
   * @param {boolean} disabled
   */
  function photoZone(id, tipo, motoId, campo, existing, disabled){
    var photos = existing || [];
    var html = '<div class="clPhotoZone" id="'+id+'" data-tipo="'+tipo+'" data-moto="'+motoId+'" data-campo="'+campo+'" style="margin:8px 0 14px;">';
    html += '';
    html += '<div class="clPhotoGrid" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px;">';
    photos.forEach(function(url){
      html += photoThumb(url, disabled);
    });
    html += '</div>';
    if(!disabled){
      html += '<label class="ad-btn sm ghost" style="cursor:pointer;display:inline-flex;align-items:center;gap:4px;">'+
        '<input type="file" accept="image/*" class="clPhotoInput" style="display:none;" multiple>'+
        '+ Agregar fotos</label>';
    }
    html += '</div>';
    return html;
  }

  function photoThumb(url, disabled){
    return '<div class="clThumb" style="position:relative;width:64px;height:64px;border-radius:6px;overflow:hidden;border:1px solid #ddd;">'+
      '<img src="'+url+'" style="width:100%;height:100%;object-fit:cover;cursor:pointer;" onclick="window.open(\''+url+'\',\'_blank\')">'+
      (!disabled ? '<button class="clPhotoRemove" data-url="'+url+'" style="position:absolute;top:0;right:0;background:rgba(0,0,0,0.6);color:#fff;border:none;width:18px;height:18px;font-size:12px;cursor:pointer;line-height:18px;padding:0;">&times;</button>' : '')+
    '</div>';
  }

  function bindPhotoEvents(){
    // Upload
    $('.clPhotoInput').off('change').on('change', function(){
      var $zone = $(this).closest('.clPhotoZone');
      var files = this.files;
      if(!files.length) return;

      var tipo = $zone.data('tipo');
      var motoId = $zone.data('moto');
      var campo = $zone.data('campo');
      var $grid = $zone.find('.clPhotoGrid');
      var $input = $(this);

      for(var i = 0; i < files.length; i++){
        (function(rawFile){
          // Show uploading placeholder immediately (compression is async)
          var placeholderId = 'ph_' + Date.now() + '_' + Math.random().toString(36).substr(2,4);
          $grid.append('<div id="'+placeholderId+'" style="width:64px;height:64px;border-radius:6px;border:1px solid #ddd;display:flex;align-items:center;justify-content:center;background:#f5f5f5;"><span class="ad-spin"></span></div>');

          (window.voltikaCompressImage ? window.voltikaCompressImage(rawFile) : Promise.resolve(rawFile)).then(function(file){
            var fd = new FormData();
            fd.append('foto', file);
            fd.append('checklist_tipo', tipo);
            fd.append('moto_id', motoId);
            fd.append('campo', campo);

            $.ajax({
              url: 'php/checklists/subir-foto.php',
              method: 'POST',
              data: fd,
              processData: false,
              contentType: false,
              xhrFields: { withCredentials: true },
              dataType: 'json'
            }).done(function(r){
              if(r.ok){
                $('#'+placeholderId).replaceWith(photoThumb(r.url, false));
                bindPhotoRemoveEvents();
                refreshPhotoCatCounter($zone);
              } else {
                $('#'+placeholderId).remove();
                alert(r.error||'Error al subir foto');
              }
            }).fail(function(xhr){
              $('#'+placeholderId).remove();
              var msg = 'Error de conexión al subir foto';
              if(xhr.responseJSON && xhr.responseJSON.error) msg = xhr.responseJSON.error;
              alert(msg);
            });
          });
        })(files[i]);
      }
      // Reset input so same file can be re-selected
      $input.val('');
    });

    bindPhotoRemoveEvents();
  }

  function bindPhotoRemoveEvents(){
    $('.clPhotoRemove').off('click').on('click', function(e){
      e.stopPropagation();
      var $btn = $(this);
      var url = $btn.data('url');
      var $zone = $btn.closest('.clPhotoZone');
      var tipo = $zone.data('tipo');
      var motoId = $zone.data('moto');
      var campo = $zone.data('campo');

      if(!confirm('Eliminar esta foto?')) return;

      var $thumb = $btn.closest('.clThumb');
      $thumb.css('opacity','0.4');

      ADApp.api('checklists/eliminar-foto.php', {
        checklist_tipo: tipo, moto_id: motoId, campo: campo, url: url
      }).done(function(r){
        if(r.ok){ $thumb.remove(); refreshPhotoCatCounter($zone); }
        else { $thumb.css('opacity','1'); alert(r.error||'Error'); }
      }).fail(function(xhr){
        $thumb.css('opacity','1');
        var msg = 'Error de conexión';
        if(xhr.responseJSON && xhr.responseJSON.error) msg = xhr.responseJSON.error;
        alert(msg);
      });
    });
  }

  function refreshPhotoCatCounter($zone){
    var $cat = $zone.closest('.clPhotoCat');
    if(!$cat.length) return;
    var n = $cat.find('.clThumb').length;
    var $count = $cat.find('.clPhotoCatCount');
    $count.text(n+' foto(s)');
    $count.css('color', n>0 ? '#1e7e34' : '#c0392b');
  }

  function esc(s){
    return (s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  // Public helper: open the Checklist de Origen modal for a specific moto.
  // Fetches detalle first so external modules (e.g. Ventas "Asignar moto")
  // can trigger fast-fill without knowing the internal data shape.
  function openOrigenById(motoId, onAfterClose){
    ADApp.api('checklists/detalle.php?moto_id=' + motoId).done(function(rd){
      if (!rd || rd.ok === false) {
        alert((rd && rd.error) || 'No se pudo cargar el checklist');
        return;
      }
      openOrigenForm(motoId, rd.origen, rd.moto);
      if (typeof onAfterClose === 'function') {
        // Stash callback so the "close" chrome of the form can invoke it.
        window.AD_checklists._onOrigenClose = onAfterClose;
      }
    }).fail(function(x){
      alert((x.responseJSON && x.responseJSON.error) || 'Error de conexión');
    });
  }

  return {
    render: render,
    openOrigenForm: openOrigenForm,
    openOrigenById: openOrigenById
  };
})();
