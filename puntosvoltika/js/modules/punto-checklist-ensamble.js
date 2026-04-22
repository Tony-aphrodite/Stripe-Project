/* ==========================================================================
   Voltika Punto — Checklist de Ensamble
   3 fases, 14 secciones, 40+ items. Mismo schema que admin/admin-checklists
   para que los datos queden en la misma tabla checklist_ensamble.
   ========================================================================== */

window.PV_checklistEnsamble = (function(){

  // Phase structure — must match the admin side exactly
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

  var ALL_FIELDS = [];
  ENSAMBLE_PHASES.forEach(function(ph){
    ph.sections.forEach(function(s){
      s.fields.forEach(function(f){ ALL_FIELDS.push(f.key); });
    });
  });
  var TOTAL = ALL_FIELDS.length;

  var _state = {
    motoId: null,
    data: {},          // { field_key: 0|1 }
    notas: '',
    activeFase: 'fase1',
  };

  function open(motoId){
    _state.motoId = motoId;
    _state.data = {};
    _state.notas = '';
    _state.activeFase = 'fase1';

    PVApp.api('checklists/detalle.php?moto_id=' + motoId, null, 'GET').done(function(r){
      if (!r.ok) { alert(r.error || 'Error'); return; }
      var cl = r.checklist || {};
      // Pre-fill existing data (draft)
      ALL_FIELDS.forEach(function(k){ _state.data[k] = cl[k] ? 1 : 0; });
      _state.notas = cl.notas || '';
      _state.activeFase = cl.fase_actual || 'fase1';
      if (cl.completado) {
        alert('Este checklist ya fue completado.');
      }
      render(r.moto, cl);
    }).fail(function(x){
      alert((x.responseJSON && x.responseJSON.error) || 'Error de conexión');
    });
  }

  function countDone(){
    var n = 0;
    ALL_FIELDS.forEach(function(k){ if (_state.data[k]) n++; });
    return n;
  }

  function render(moto, existing){
    var active = _state.activeFase;
    var phase = ENSAMBLE_PHASES.filter(function(p){ return p.key === active; })[0] || ENSAMBLE_PHASES[0];
    var done = countDone();
    var pct  = Math.round((done / TOTAL) * 100);

    var html = '';
    html += '<div class="ad-h2">Checklist Ensamble — ' + (moto.vin_display || moto.vin || '') + '</div>';
    html += '<div class="ad-muted" style="font-size:13px;margin-bottom:10px;">' +
            (moto.modelo || '') + ' · ' + (moto.color || '') + '</div>';

    // Progress bar
    html += '<div style="background:#e5e7eb;border-radius:999px;height:8px;overflow:hidden;margin-bottom:4px;">' +
              '<div style="background:#22c55e;height:100%;width:' + pct + '%;transition:width .3s;"></div>' +
            '</div>';
    html += '<div style="font-size:12px;color:#64748b;margin-bottom:14px;">' +
              done + ' / ' + TOTAL + ' items (' + pct + '%)' +
            '</div>';

    // Phase tabs
    html += '<div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap;">';
    ENSAMBLE_PHASES.forEach(function(ph){
      var bg = ph.key === active ? '#039fe1' : '#f1f5f9';
      var fg = ph.key === active ? '#fff' : '#334155';
      html += '<button class="pvEnsFaseBtn" data-fase="' + ph.key + '" ' +
              'style="padding:8px 12px;border:0;border-radius:6px;cursor:pointer;' +
              'background:' + bg + ';color:' + fg + ';font-size:12.5px;font-weight:600;">' +
              ph.title + '</button>';
    });
    html += '</div>';

    // Sections of active phase
    phase.sections.forEach(function(sec){
      html += '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:10px;">';
      html += '<div style="font-weight:700;font-size:13.5px;color:#0f172a;margin-bottom:8px;">' + sec.title + '</div>';
      sec.fields.forEach(function(f){
        var checked = _state.data[f.key] ? 'checked' : '';
        html += '<label style="display:flex;align-items:flex-start;gap:8px;padding:6px 0;font-size:13px;cursor:pointer;">' +
                  '<input type="checkbox" class="pvEnsChk" data-key="' + f.key + '" ' + checked + ' ' +
                    'style="width:18px;height:18px;flex-shrink:0;margin-top:2px;accent-color:#22c55e;">' +
                  '<span>' + f.label + '</span>' +
                '</label>';
      });
      html += '</div>';
    });

    // Notes
    html += '<label class="ad-label" style="margin-top:10px;">Notas (opcional)</label>';
    html += '<textarea id="pvEnsNotas" class="ad-input" ' +
            'style="min-height:70px;">' + (_state.notas ? escapeHtml(_state.notas) : '') + '</textarea>';

    // Buttons
    html += '<div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap;">';
    html += '<button id="pvEnsSaveDraft" class="ad-btn ghost" style="flex:1;min-width:130px;">Guardar borrador</button>';
    html += '<button id="pvEnsComplete" class="ad-btn primary" ' +
            'style="flex:1;min-width:130px;background:#22c55e;border-color:#22c55e;">' +
            '✓ Completar checklist</button>';
    html += '</div>';

    PVApp.modal(html);

    // Bind
    $('.pvEnsFaseBtn').on('click', function(){
      saveCurrentFormState();
      _state.activeFase = $(this).data('fase');
      render(moto, existing);
    });
    $('.pvEnsChk').on('change', function(){
      _state.data[$(this).data('key')] = $(this).is(':checked') ? 1 : 0;
      // Live update progress bar
      var d = countDone();
      var p = Math.round((d / TOTAL) * 100);
      $('.pv-progress-fill').css('width', p + '%');
    });
    $('#pvEnsNotas').on('input', function(){ _state.notas = $(this).val(); });
    $('#pvEnsSaveDraft').on('click', function(){ saveCurrentFormState(); save(false); });
    $('#pvEnsComplete').on('click', function(){
      saveCurrentFormState();
      if (countDone() < TOTAL) {
        alert('Faltan ' + (TOTAL - countDone()) + ' items por marcar antes de completar.');
        return;
      }
      if (!confirm('¿Completar y bloquear este checklist? No se podrá modificar después.')) return;
      save(true);
    });
  }

  function saveCurrentFormState(){
    $('.pvEnsChk').each(function(){
      _state.data[$(this).data('key')] = $(this).is(':checked') ? 1 : 0;
    });
    _state.notas = $('#pvEnsNotas').val() || '';
  }

  function save(completar){
    var payload = { moto_id: _state.motoId, notas: _state.notas, completar: completar ? 1 : 0 };
    ALL_FIELDS.forEach(function(k){ payload[k] = _state.data[k] ? 1 : 0; });

    var $btn = completar ? $('#pvEnsComplete') : $('#pvEnsSaveDraft');
    var orig = $btn.text();
    $btn.prop('disabled', true).html('<span class="ad-spin"></span>');

    PVApp.api('checklists/guardar-ensamble.php', payload).done(function(r){
      if (r.ok) {
        if (completar) {
          PVApp.closeModal();
          PVApp.toast('✓ Ensamble completado. Moto lista para entrega.');
          if (window.PV_inventario && typeof PV_inventario.render === 'function') {
            PV_inventario.render();
          }
        } else {
          $btn.prop('disabled', false).text(orig);
          PVApp.toast('Borrador guardado (' + r.fase_actual + ')');
        }
      } else {
        alert(r.error || 'Error al guardar');
        $btn.prop('disabled', false).text(orig);
      }
    }).fail(function(x){
      alert((x.responseJSON && x.responseJSON.error) || 'Error de conexión');
      $btn.prop('disabled', false).text(orig);
    });
  }

  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  return { open: open };
})();
