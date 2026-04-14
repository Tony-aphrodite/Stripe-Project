window.AD_checklists = (function(){

  // ── Section definitions for Checklist de Origen ──────────────────────────
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
      {key:'freno_delantero', label:'Freno delantero'},
      {key:'freno_trasero', label:'Freno trasero'},
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
      {key:'motor_conexion', label:'Conexión correcta del motor'}
    ]},
    { title: '7. Baterías', fields: [
      {key:'bateria_1', label:'Batería 1 presente'},
      {key:'bateria_2', label:'Batería 2 presente'},
      {key:'baterias_sin_dano', label:'Baterías sin daño'},
      {key:'cargador_incluido', label:'Cargador incluido'}
    ]},
    { title: '8. Accesorios', fields: [
      {key:'espejos', label:'Espejos'},
      {key:'tornilleria_completa', label:'Tornillería completa'},
      {key:'birlos_completos', label:'Birlos completos'},
      {key:'kit_herramientas', label:'Kit de herramientas'}
    ]},
    { title: '9. Complementos', fields: [
      {key:'llaves_2', label:'2 llaves incluidas'},
      {key:'manual_usuario', label:'Manual de usuario'},
      {key:'carnet_garantia', label:'Carnet de garantía'}
    ]},
    { title: '10. Validación eléctrica', fields: [
      {key:'sistema_enciende', label:'Sistema enciende'},
      {key:'dashboard_funcional', label:'Dashboard funcional'},
      {key:'indicador_bateria', label:'Indicador de batería'},
      {key:'luces_funcionando', label:'Luces funcionando'},
      {key:'conectores_firmes', label:'Conectores firmes'},
      {key:'cableado_sin_dano', label:'Cableado sin daño'}
    ]},
    { title: '11. Artes decorativos', fields: [
      {key:'calcomanias_correctas', label:'Calcomanías correctas'},
      {key:'alineacion_correcta', label:'Alineación correcta'},
      {key:'sin_burbujas', label:'Sin burbujas'},
      {key:'sin_desprendimientos', label:'Sin desprendimientos'},
      {key:'sin_rayones', label:'Sin rayones'},
      {key:'acabados_correctos', label:'Acabados correctos'}
    ]},
    { title: '12. Empaque', fields: [
      {key:'embalaje_correcto', label:'Embalaje correcto'},
      {key:'protecciones_colocadas', label:'Protecciones colocadas'},
      {key:'caja_sin_dano', label:'Caja sin daño'},
      {key:'sellos_colocados', label:'Sellos colocados'}
    ]},
    { title: '13. Declaración y validación final', fields: [
      {key:'declaracion_aceptada', label:'Declaración aceptada'},
      {key:'validacion_final', label:'Validación final'}
    ]}
  ];

  // Count all binary fields
  var ALL_ORIGEN_FIELDS = [];
  ORIGEN_SECTIONS.forEach(function(s){ s.fields.forEach(function(f){ ALL_ORIGEN_FIELDS.push(f.key); }); });
  var TOTAL_ORIGEN = ALL_ORIGEN_FIELDS.length;

  // ── Main list render ─────────────────────────────────────────────────────
  var currentFilter = '';
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  function render(){
    ADApp.render('<div class="ad-h1">Checklists</div><div><span class="ad-spin"></span> Cargando...</div>');
    load();
  }

  function load(){
    ADApp.api('checklists/listar.php?filtro='+currentFilter).done(paint);
  }

  function paint(r){
    var s = r.resumen||{};
    var html = _backBtn+'<div class="ad-toolbar"><div class="ad-h1">Checklists</div></div>';

    // KPIs
    html += '<div class="ad-kpis">';
    [{l:'Total motos',v:s.total,c:'blue'},{l:'Con origen',v:s.con_origen,c:'blue'},
     {l:'Con ensamble',v:s.con_ensamble,c:'yellow'},{l:'Con entrega',v:s.con_entrega,c:'green'},
     {l:'Completos',v:s.completos,c:'green'}].forEach(function(k){
      html += '<div class="ad-kpi"><div class="label">'+k.l+'</div><div class="value '+(k.c||'')+'">'+Number(k.v||0)+'</div></div>';
    });
    html += '</div>';

    // Filters
    html += '<div class="ad-filters">'+
      '<select class="ad-select" id="clFiltro" style="width:200px;">'+
        '<option value="">Todos</option>'+
        '<option value="sin_origen"'+(currentFilter==='sin_origen'?' selected':'')+'>Sin checklist origen</option>'+
        '<option value="con_origen"'+(currentFilter==='con_origen'?' selected':'')+'>Con origen completo</option>'+
        '<option value="sin_ensamble"'+(currentFilter==='sin_ensamble'?' selected':'')+'>Sin ensamble</option>'+
        '<option value="con_ensamble"'+(currentFilter==='con_ensamble'?' selected':'')+'>Con ensamble completo</option>'+
        '<option value="completos"'+(currentFilter==='completos'?' selected':'')+'>3 checklists completos</option>'+
      '</select>'+
      '<button class="ad-btn sm ghost" id="clApply">Filtrar</button>'+
    '</div>';

    // Table
    html += '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table"><thead><tr>'+
      '<th>VIN</th><th>Modelo</th><th>Color</th><th>Estado</th>'+
      '<th>Origen</th><th>Ensamble</th><th>Entrega</th><th></th>'+
    '</tr></thead><tbody>';

    (r.motos||[]).forEach(function(m){
      html += '<tr>'+
        '<td><strong>'+(m.vin_display||m.vin||'—')+'</strong></td>'+
        '<td>'+m.modelo+'</td>'+
        '<td>'+m.color+'</td>'+
        '<td>'+ADApp.badgeEstado(m.estado)+'</td>'+
        '<td>'+clBadge(m.co_id, m.co_ok)+'</td>'+
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

    $('#clApply').on('click',function(){ currentFilter=$('#clFiltro').val(); load(); });
    $('.clOpen').on('click',function(){ showMotoChecklists($(this).data('id')); });
    $('.clPage').on('click',function(){ /* TODO pagination */ load(); });
  }

  function clBadge(id, ok, fase){
    if(!id) return '<span class="ad-badge gray">Pendiente</span>';
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
        if(tipo==='origen') openOrigenForm(mid, r.origen);
        else if(tipo==='ensamble') openEnsambleForm(mid, r.ensamble);
        else if(tipo==='entrega') openEntregaForm(mid, r.entrega);
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
  function openOrigenForm(motoId, existing){
    var data = existing || {};
    var isLocked = data.completado == 1;

    var html = '<div class="ad-h2">Checklist de Origen</div>';

    // Progress bar
    var done = countDone(data, ALL_ORIGEN_FIELDS);
    var pct = TOTAL_ORIGEN > 0 ? Math.round(done/TOTAL_ORIGEN*100) : 0;
    html += progressBar(done, TOTAL_ORIGEN, pct);

    if(isLocked){
      html += '<div style="background:#E8F5E9;padding:12px;border-radius:8px;margin-bottom:12px;font-size:13px;color:#2E7D32;">'+
        'Este checklist fue completado y no se puede modificar. Hash: <code>'+(data.hash_registro||'')+'</code></div>';
    }

    // Config baterias
    html += '<div style="margin-bottom:12px;">'+
      '<label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:4px;">Configuración de baterías:</label>'+
      '<select class="ad-select" id="clConfigBat" style="width:120px;"'+(isLocked?' disabled':'')+'>'+
        '<option value="1"'+(data.config_baterias!=='2'?' selected':'')+'>1 batería</option>'+
        '<option value="2"'+(data.config_baterias==='2'?' selected':'')+'>2 baterías</option>'+
      '</select>'+
    '</div>';

    // Sections with checkboxes
    ORIGEN_SECTIONS.forEach(function(section){
      html += '<div style="margin-bottom:14px;">';
      html += sectionTitle(section.title);
      section.fields.forEach(function(f){
        html += checkItem(f.key, f.label, data[f.key], isLocked);
      });
      html += '</div>';
    });

    // Num sellos
    html += '<div style="margin-bottom:12px;">'+
      '<label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:4px;">Número de sellos:</label>'+
      '<input type="number" class="ad-input" id="clNumSellos" value="'+(data.num_sellos||0)+'" style="width:100px;"'+(isLocked?' disabled':'')+'>'+
    '</div>';

    // Photos
    html += sectionTitle('Evidencia fotográfica');
    var origenFotos = [];
    try { origenFotos = JSON.parse(data.fotos||'[]'); } catch(e){}
    if(!Array.isArray(origenFotos)) origenFotos = [];
    html += photoZone('clOrigenFotos', 'origen', motoId, 'fotos', origenFotos, isLocked);

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
      if(!confirm('¿Completar y bloquear este checklist? No se podrá modificar después.')) return;
      saveOrigen(motoId, true);
    });

    $('#clBackToMoto').on('click', function(){
      ADApp.closeModal();
      showMotoChecklists(motoId);
    });
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

  var ENSAMBLE_PHASES = [
    { key:'fase1', title:'Fase 1 — Inicio', sections:[
      { title:'Recepción y preparación', fields:[
        {key:'recepcion_validada', label:'Recepción validada (checklist origen completo)'},
        {key:'primera_apertura', label:'Primera apertura del embalaje'},
        {key:'area_segura', label:'Área de trabajo segura y despejada'},
        {key:'herramientas_disponibles', label:'Herramientas disponibles'},
        {key:'equipo_proteccion', label:'Equipo de protección personal'},
        {key:'declaracion_fase1', label:'Declaración de responsabilidad aceptada'}
      ]}
    ]},
    { key:'fase2', title:'Fase 2 — Proceso de ensamble', sections:[
      { title:'2.1 Desembalaje', fields:[
        {key:'componentes_sin_dano', label:'Componentes sin daño'},
        {key:'accesorios_separados', label:'Accesorios separados e identificados'},
        {key:'llanta_identificada', label:'Llanta delantera identificada'}
      ]},
      { title:'2.2 Base y asiento', fields:[
        {key:'base_instalada', label:'Base instalada correctamente'},
        {key:'asiento_instalado', label:'Asiento instalado'},
        {key:'tornilleria_base', label:'Tornillería de base completa'},
        {key:'torque_base_25', label:'Torque base 25 Nm confirmado'}
      ]},
      { title:'2.3 Manubrio', fields:[
        {key:'manubrio_instalado', label:'Manubrio instalado'},
        {key:'cableado_sin_tension', label:'Cableado sin tensión'},
        {key:'alineacion_manubrio', label:'Alineación del manubrio correcta'},
        {key:'torque_manubrio_25', label:'Torque manubrio 25 Nm confirmado'}
      ]},
      { title:'2.4 Llanta delantera', fields:[
        {key:'buje_corto', label:'Buje corto instalado'},
        {key:'buje_largo', label:'Buje largo instalado'},
        {key:'disco_alineado', label:'Disco de freno alineado'},
        {key:'eje_instalado', label:'Eje instalado correctamente'},
        {key:'torque_llanta_50', label:'Torque llanta 50 Nm confirmado'}
      ]},
      { title:'2.5 Espejos', fields:[
        {key:'espejo_izq', label:'Espejo izquierdo instalado'},
        {key:'espejo_der', label:'Espejo derecho instalado'},
        {key:'roscas_ok', label:'Roscas en buen estado'},
        {key:'ajuste_espejos', label:'Ajuste y posición correcta'}
      ]}
    ]},
    { key:'fase3', title:'Fase 3 — Validación final', sections:[
      { title:'3.1 Frenos', fields:[
        {key:'freno_del_funcional', label:'Freno delantero funcional'},
        {key:'freno_tras_funcional', label:'Freno trasero funcional'},
        {key:'luz_freno_operativa', label:'Luz de freno operativa'}
      ]},
      { title:'3.2 Iluminación', fields:[
        {key:'direccionales_ok', label:'Direccionales funcionando'},
        {key:'intermitentes_ok', label:'Intermitentes funcionando'},
        {key:'luz_alta', label:'Luz alta funcional'},
        {key:'luz_baja', label:'Luz baja funcional'}
      ]},
      { title:'3.3 Sistema eléctrico', fields:[
        {key:'claxon_ok', label:'Claxon funcional'},
        {key:'dashboard_ok', label:'Dashboard funcional'},
        {key:'bateria_cargando', label:'Batería cargando correctamente'},
        {key:'puerto_carga_ok', label:'Puerto de carga funcional'}
      ]},
      { title:'3.4 Motor y modos', fields:[
        {key:'modo_eco', label:'Modo ECO funcional'},
        {key:'modo_drive', label:'Modo DRIVE funcional'},
        {key:'modo_sport', label:'Modo SPORT funcional'},
        {key:'reversa_ok', label:'Reversa funcional'}
      ]},
      { title:'3.5 Acceso', fields:[
        {key:'nfc_ok', label:'NFC funcional'},
        {key:'control_remoto_ok', label:'Control remoto funcional'},
        {key:'llaves_funcionales', label:'Llaves funcionales'}
      ]},
      { title:'3.6 Validación mecánica', fields:[
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

    // Photo campo mapping: fase→ array of {campo, label}
    var ensFotoMap = {
      fase1: [{campo:'fotos_fase1', label:'Fotos de recepción'}],
      fase2: [
        {campo:'fotos_base', label:'Fotos base y asiento'},
        {campo:'fotos_manubrio', label:'Fotos manubrio'},
        {campo:'fotos_llanta', label:'Fotos llanta delantera'},
        {campo:'fotos_espejos', label:'Fotos espejos'}
      ],
      fase3: [{campo:'fotos_fase3', label:'Fotos validación final'}]
    };

    // Phase contents
    ENSAMBLE_PHASES.forEach(function(ph){
      var isVisible = ph.key === activeFase;
      html += '<div class="clEnsPane" data-fase="'+ph.key+'" style="'+(isVisible?'':'display:none;')+'">';
      html += '<div style="font-weight:700;font-size:14px;margin-bottom:10px;">'+ph.title+'</div>';

      ph.sections.forEach(function(section){
        html += '<div style="margin-bottom:14px;">';
        html += sectionTitle(section.title);
        section.fields.forEach(function(f){
          html += checkItem(f.key, f.label, data[f.key], isLocked);
        });
        html += '</div>';
      });

      // Phase photos (multiple zones for fase2)
      var fotoEntries = ensFotoMap[ph.key] || [];
      fotoEntries.forEach(function(fe){
        var pFotos = [];
        try { pFotos = JSON.parse(data[fe.campo]||'[]'); } catch(e){}
        if(!Array.isArray(pFotos)) pFotos = [];
        html += '<div style="font-size:12px;font-weight:600;color:var(--ad-dim);margin-top:8px;">'+fe.label+'</div>';
        html += photoZone('clEnsFoto_'+fe.campo, 'ensamble', motoId, fe.campo, pFotos, isLocked);
      });

      html += '</div>';
    });

    // Notas
    html += '<div style="margin-bottom:12px;">'+
      '<label style="font-size:12px;color:var(--ad-dim);display:block;margin-bottom:4px;">Notas:</label>'+
      '<textarea class="ad-input" id="clEnsNotas" style="min-height:60px;"'+(isLocked?' disabled':'')+'>'+esc(data.notas||'')+'</textarea>'+
    '</div>';

    if(!isLocked){
      html += '<div style="display:flex;gap:8px;margin-top:16px;">';
      html += '<button class="ad-btn ghost" id="clEnsSave" style="flex:1;padding:10px;">Guardar borrador</button>';
      html += '<button class="ad-btn primary" id="clEnsComplete" style="flex:1;padding:10px;">Completar checklist</button>';
      html += '</div>';
    }
    html += '<button class="ad-btn ghost" id="clEnsBack" style="width:100%;margin-top:8px;">Volver</button>';

    ADApp.modal(html);

    // Bind photo events
    bindPhotoEvents();

    // Tab switching
    $('.clEnsTab').on('click',function(){
      var f = $(this).data('fase');
      $('.clEnsTab').removeClass('primary').addClass('ghost');
      $(this).removeClass('ghost').addClass('primary');
      $('.clEnsPane').hide();
      $('.clEnsPane[data-fase="'+f+'"]').show();
    });

    // Progress update
    $('.clCheck').on('change', function(){
      updateProgressGeneric(ALL_ENSAMBLE_FIELDS, TOTAL_ENSAMBLE);
      updateTabCounters(ENSAMBLE_PHASES, '.clEnsTab');
    });

    $('#clEnsSave').on('click', function(){ saveEnsamble(motoId, false); });
    $('#clEnsComplete').on('click', function(){
      var checked = countChecked();
      if(checked < TOTAL_ENSAMBLE){
        alert('Faltan '+(TOTAL_ENSAMBLE-checked)+' items por marcar antes de completar el checklist.');
        return;
      }
      if(!confirm('Completar y bloquear este checklist?')) return;
      saveEnsamble(motoId, true);
    });
    $('#clEnsBack').on('click', function(){ ADApp.closeModal(); showMotoChecklists(motoId); });
  }

  function saveEnsamble(motoId, completar){
    var payload = { moto_id: motoId };
    $('.clCheck').each(function(){ payload[$(this).data('key')] = $(this).is(':checked') ? 1 : 0; });
    payload.notas = $('#clEnsNotas').val();
    if(completar) payload.completar = 1;

    var $btn = completar ? $('#clEnsComplete') : $('#clEnsSave');
    $btn.prop('disabled',true).html('<span class="ad-spin"></span>');

    ADApp.api('checklists/guardar-ensamble.php', payload).done(function(r){
      if(r.ok){
        if(completar){
          ADApp.closeModal();
          alert('Checklist de ensamble completado exitosamente');
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
    { key:'fase5', title:'Fase 5 — Acta legal de entrega', sections:[
      { title:'Documento legal', fields:[
        {key:'acta_aceptada', label:'Acta de entrega aceptada'},
        {key:'clausula_identidad', label:'Cláusula de identidad aceptada'},
        {key:'clausula_medios', label:'Cláusula de medios de pago aceptada'},
        {key:'clausula_uso_info', label:'Cláusula de uso de información aceptada'},
        {key:'firma_digital', label:'Firma digital registrada'}
      ]}
    ]}
  ];

  var ALL_ENTREGA_FIELDS = [];
  ENTREGA_PHASES.forEach(function(ph){ ph.sections.forEach(function(s){ s.fields.forEach(function(f){ ALL_ENTREGA_FIELDS.push(f.key); }); }); });
  var TOTAL_ENTREGA = ALL_ENTREGA_FIELDS.length;

  function openEntregaForm(motoId, existing){
    var data = existing || {};
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
        html += '<div style="margin-bottom:14px;">';
        html += sectionTitle(section.title);
        section.fields.forEach(function(f){
          html += checkItem(f.key, f.label, data[f.key], isLocked);
        });
        html += '</div>';
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

      // ── Fase 5: Signature canvas ──
      if(ph.key === 'fase5'){
        html += '<div style="margin-top:10px;">';
        html += sectionTitle('Firma digital del cliente');
        if(data.firma_data){
          html += '<div style="border:1px solid #ddd;border-radius:8px;padding:8px;display:inline-block;">'+
            '<img src="'+data.firma_data+'" style="max-width:300px;height:auto;"></div>';
          html += '<div style="font-size:12px;color:#4CAF50;margin-top:4px;">Firma capturada</div>';
        } else if(!isLocked){
          html += '<div id="clFirmaWrapper" style="border:2px dashed #ccc;border-radius:8px;position:relative;background:#fafafa;">';
          html += '<canvas id="clFirmaCanvas" style="width:100%;height:120px;display:block;cursor:crosshair;"></canvas>';
          html += '<div id="clFirmaPlaceholder" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);color:#bbb;font-size:13px;pointer-events:none;">Firmar aquí</div>';
          html += '</div>';
          html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;">';
          html += '<span id="clFirmaStatus" style="font-size:12px;color:#999;">Pendiente de firma</span>';
          html += '<button class="ad-btn sm ghost" id="clFirmaClear">Limpiar</button>';
          html += '</div>';
        } else {
          html += '<div style="font-size:13px;color:var(--ad-dim);">Sin firma registrada</div>';
        }
        html += '</div>';
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
      var file = this.files[0];
      if(!file) return;
      var fd = new FormData();
      fd.append('foto', file);
      fd.append('moto_id', motoId);
      $('#clFaceStatus').html('<span class="ad-spin"></span> Comparando...').css('color','var(--ad-dim)');
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
    var payload = { moto_id: motoId };
    $('.clCheck').each(function(){ payload[$(this).data('key')] = $(this).is(':checked') ? 1 : 0; });
    payload.notas = $('#clEntNotas').val();
    if(completar) payload.completar = 1;

    var $btn = completar ? $('#clEntComplete') : $('#clEntSave');
    $btn.prop('disabled',true).html('<span class="ad-spin"></span>');

    // Save signature first if canvas has data
    var firmaData = getSignatureData();
    var firmaPromise = firmaData
      ? ADApp.api('checklists/guardar-firma.php', { moto_id: motoId, firma_data: firmaData })
      : $.Deferred().resolve({ok:true});

    firmaPromise.always(function(){
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
    }); // end firmaPromise
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

  var _sigCanvas = null, _sigCtx = null, _sigDrawing = false, _sigHasSigned = false;

  function initSignatureCanvas(){
    var canvas = document.getElementById('clFirmaCanvas');
    if(!canvas) return;

    var rect = canvas.getBoundingClientRect();
    var w = rect.width > 0 ? rect.width : (canvas.parentElement ? canvas.parentElement.clientWidth : 320);
    var h = 120;
    canvas.width = w;
    canvas.height = h;
    canvas.style.height = h + 'px';

    var ctx = canvas.getContext('2d');
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#111827';

    _sigCanvas = canvas;
    _sigCtx = ctx;
    _sigHasSigned = false;

    function getPos(e){
      var r = canvas.getBoundingClientRect();
      return { x: e.clientX - r.left, y: e.clientY - r.top };
    }

    canvas.addEventListener('mousedown', function(e){ _sigDrawing = true; var p=getPos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); $('#clFirmaPlaceholder').hide(); });
    canvas.addEventListener('mousemove', function(e){ if(!_sigDrawing) return; var p=getPos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); _sigHasSigned=true; $('#clFirmaStatus').text('Firma capturada').css('color','#4CAF50'); $('#clFirmaWrapper').css('border-color','#4CAF50'); });
    canvas.addEventListener('mouseup', function(){ _sigDrawing=false; });
    canvas.addEventListener('mouseleave', function(){ _sigDrawing=false; });

    canvas.addEventListener('touchstart', function(e){ e.preventDefault(); _sigDrawing=true; var p=getPos(e.touches[0]); ctx.beginPath(); ctx.moveTo(p.x,p.y); $('#clFirmaPlaceholder').hide(); }, {passive:false});
    canvas.addEventListener('touchmove', function(e){ e.preventDefault(); if(!_sigDrawing) return; var p=getPos(e.touches[0]); ctx.lineTo(p.x,p.y); ctx.stroke(); _sigHasSigned=true; $('#clFirmaStatus').text('Firma capturada').css('color','#4CAF50'); $('#clFirmaWrapper').css('border-color','#4CAF50'); }, {passive:false});
    canvas.addEventListener('touchend', function(){ _sigDrawing=false; });

    $('#clFirmaClear').on('click', function(){
      if(!_sigCtx || !_sigCanvas) return;
      _sigCtx.clearRect(0, 0, _sigCanvas.width, _sigCanvas.height);
      _sigHasSigned = false;
      $('#clFirmaPlaceholder').show();
      $('#clFirmaStatus').text('Pendiente de firma').css('color','#999');
      $('#clFirmaWrapper').css('border-color','#ccc');
    });
  }

  function getSignatureData(){
    if(!_sigCanvas || !_sigHasSigned) return null;
    return _sigCanvas.toDataURL('image/png');
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
    html += '<div style="font-size:12px;color:var(--ad-dim);margin-bottom:4px;">Evidencia fotográfica:</div>';
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
        (function(file){
          var fd = new FormData();
          fd.append('foto', file);
          fd.append('checklist_tipo', tipo);
          fd.append('moto_id', motoId);
          fd.append('campo', campo);

          // Show uploading placeholder
          var placeholderId = 'ph_' + Date.now() + '_' + Math.random().toString(36).substr(2,4);
          $grid.append('<div id="'+placeholderId+'" style="width:64px;height:64px;border-radius:6px;border:1px solid #ddd;display:flex;align-items:center;justify-content:center;background:#f5f5f5;"><span class="ad-spin"></span></div>');

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
        if(r.ok) $thumb.remove();
        else { $thumb.css('opacity','1'); alert(r.error||'Error'); }
      }).fail(function(xhr){
        $thumb.css('opacity','1');
        var msg = 'Error de conexión';
        if(xhr.responseJSON && xhr.responseJSON.error) msg = xhr.responseJSON.error;
        alert(msg);
      });
    });
  }

  function esc(s){
    return (s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  return { render: render };
})();
