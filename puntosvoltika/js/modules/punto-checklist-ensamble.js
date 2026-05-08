/* ==========================================================================
   Voltika Punto — Checklist de Ensamble
   3 fases, 14 secciones, 40+ items. Mismo schema que admin/admin-checklists
   para que los datos queden en la misma tabla checklist_ensamble.
   ========================================================================== */

window.PV_checklistEnsamble = (function(){

  // Phase structure — must match the admin side exactly.
  // Bug 4.1 (customer brief 2026-05-08): each section now declares a
  // `fotoCampo` mirroring admin/admin-checklists.js so photos uploaded
  // here land in the same DB column the admin already reads from. Only
  // the UI was missing on PoS — server schema already had these columns
  // (created on demand by subir-foto.php).
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
    fotos: {},         // { campo: [url1, url2, ...] }  Bug 4.1
  };

  // Bug 4.1 — Render the photo upload + thumbnail strip per section.
  // Visual structure mirrors admin/admin-checklists.js photoZone helper so
  // operators trained on the admin panel see the same UX on PoS.
  function renderPhotoZone(campo, urls){
    var thumbs = '';
    (urls||[]).forEach(function(u, i){
      // Each thumb has its own delete button (×). Removal happens client-side
      // only — the next save call will not include it (server keeps the
      // file on disk; that's fine for audit trail).
      thumbs += '<div class="pvEnsThumb" style="position:relative;width:64px;height:64px;border-radius:6px;overflow:hidden;border:1px solid #e2e8f0;background:#fff;flex-shrink:0;">' +
                  '<img src="' + u + '" alt="foto" style="width:100%;height:100%;object-fit:cover;">' +
                  '<button type="button" class="pvEnsThumbX" data-campo="'+campo+'" data-idx="'+i+'" ' +
                    'style="position:absolute;top:2px;right:2px;background:rgba(0,0,0,.6);color:#fff;border:0;border-radius:50%;width:18px;height:18px;font-size:11px;line-height:1;cursor:pointer;">×</button>' +
                '</div>';
    });
    return ''+
      '<div style="margin-top:8px;padding-top:8px;border-top:1px dashed #e2e8f0;">'+
        '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">'+
          '<span style="font-size:12px;color:#64748b;font-weight:600;">📷 Fotos de esta sección</span>'+
          '<button type="button" class="ad-btn ghost sm pvEnsAddFoto" data-campo="'+campo+'" '+
            'style="font-size:11.5px;padding:4px 10px;">+ Agregar foto</button>'+
        '</div>'+
        '<div class="pvEnsThumbStrip" data-campo="'+campo+'" style="display:flex;flex-wrap:wrap;gap:6px;">'+thumbs+'</div>'+
        '<input type="file" accept="image/*" capture="environment" class="pvEnsFotoInput" data-campo="'+campo+'" style="display:none;">'+
      '</div>';
  }

  // Multipart upload helper — bypasses PVApp.api (which sends JSON).
  function uploadFoto(campo, file, onDone, onErr){
    var fd = new FormData();
    fd.append('checklist_tipo', 'ensamble');
    fd.append('moto_id', _state.motoId);
    fd.append('campo', campo);
    fd.append('foto', file);
    $.ajax({
      url: 'php/checklists/subir-foto.php',
      method: 'POST',
      data: fd,
      processData: false,
      contentType: false,
      dataType: 'json',
      xhrFields: { withCredentials: true }
    }).done(function(r){
      if (r && r.ok && r.url) onDone(r.url);
      else (onErr || function(){})(r && r.error || 'Error al subir foto');
    }).fail(function(x){
      (onErr || function(){})((x.responseJSON && x.responseJSON.error) || 'Error de conexión');
    });
  }

  function open(motoId){
    _state.motoId = motoId;
    _state.data = {};
    _state.notas = '';
    _state.activeFase = 'fase1';
    _state.fotos = {};

    PVApp.api('checklists/detalle.php?moto_id=' + motoId, null, 'GET').done(function(r){
      if (!r.ok) { alert(r.error || 'Error'); return; }
      var cl = r.checklist || {};
      // Pre-fill existing data (draft)
      ALL_FIELDS.forEach(function(k){ _state.data[k] = cl[k] ? 1 : 0; });
      _state.notas = cl.notas || '';
      _state.activeFase = cl.fase_actual || 'fase1';
      // Bug 4.1: pre-load existing photos per section so operator sees what's
      // already attached when they reopen the checklist.
      var fotoCols = ['fotos_fase1','fotos_fase3','fotos_desembalaje','fotos_base',
        'fotos_manubrio','fotos_llanta','fotos_espejos','fotos_3_1_frenos',
        'fotos_3_2_iluminacion','fotos_3_3_electrico','fotos_3_4_motor',
        'fotos_3_5_acceso','fotos_3_6_mecanica'];
      fotoCols.forEach(function(c){
        var raw = cl[c];
        if (!raw) return;
        try { _state.fotos[c] = JSON.parse(raw) || []; } catch(e){ _state.fotos[c] = []; }
      });
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
      // Bug 4.1 photo zone — same DB column the admin uses, so files
      // uploaded here surface in the admin checklist UI in real time.
      // _state.fotos is updated lazily after each upload; falling back to
      // the original checklist row keeps existing photos visible on first
      // open even before the user adds anything new.
      if (sec.fotoCampo) {
        var raw = (_state.fotos && _state.fotos[sec.fotoCampo]) ||
                  (existing && existing[sec.fotoCampo]) ||
                  '[]';
        var fotosArr = [];
        try { fotosArr = (typeof raw === 'string') ? (JSON.parse(raw) || []) : (raw || []); }
        catch(e){ fotosArr = []; }
        if (!Array.isArray(fotosArr)) fotosArr = [];
        html += renderPhotoZone(sec.fotoCampo, fotosArr);
      }
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

    // Bug 4.1 — Photo upload bindings.
    // "+ Agregar foto" triggers the hidden file input scoped to the same campo.
    $('.pvEnsAddFoto').on('click', function(){
      var campo = $(this).data('campo');
      $('.pvEnsFotoInput[data-campo="'+campo+'"]').first().click();
    });
    $('.pvEnsFotoInput').on('change', function(){
      var $input = $(this);
      var campo  = $input.data('campo');
      var file   = this.files && this.files[0];
      if (!file) return;
      var $btn = $('.pvEnsAddFoto[data-campo="'+campo+'"]');
      var origText = $btn.text();
      $btn.prop('disabled', true).html('<span class="ad-spin"></span> Subiendo...');
      uploadFoto(campo, file, function(url){
        if (!_state.fotos[campo]) _state.fotos[campo] = [];
        _state.fotos[campo].push(url);
        // Re-render the strip in place — keeps the rest of the form intact
        // and avoids losing the user's checkbox progress.
        var thumbs = '';
        _state.fotos[campo].forEach(function(u, i){
          thumbs += '<div class="pvEnsThumb" style="position:relative;width:64px;height:64px;border-radius:6px;overflow:hidden;border:1px solid #e2e8f0;background:#fff;flex-shrink:0;">' +
                      '<img src="' + u + '" alt="foto" style="width:100%;height:100%;object-fit:cover;">' +
                      '<button type="button" class="pvEnsThumbX" data-campo="'+campo+'" data-idx="'+i+'" ' +
                        'style="position:absolute;top:2px;right:2px;background:rgba(0,0,0,.6);color:#fff;border:0;border-radius:50%;width:18px;height:18px;font-size:11px;line-height:1;cursor:pointer;">×</button>' +
                    '</div>';
        });
        $('.pvEnsThumbStrip[data-campo="'+campo+'"]').html(thumbs);
        $btn.prop('disabled', false).text(origText);
        // Reset input so picking the same file again still triggers change
        $input.val('');
      }, function(err){
        alert(err);
        $btn.prop('disabled', false).text(origText);
        $input.val('');
      });
    });
    // Local-only delete: just trim from _state.fotos so the next save doesn't
    // resend it. The file stays on disk for audit.
    $(document).off('click.pvEnsThumbX').on('click.pvEnsThumbX', '.pvEnsThumbX', function(){
      var campo = $(this).data('campo');
      var idx   = parseInt($(this).data('idx'), 10);
      if (!_state.fotos[campo]) return;
      _state.fotos[campo].splice(idx, 1);
      // Re-render strip so subsequent indices stay correct.
      var thumbs = '';
      _state.fotos[campo].forEach(function(u, i){
        thumbs += '<div class="pvEnsThumb" style="position:relative;width:64px;height:64px;border-radius:6px;overflow:hidden;border:1px solid #e2e8f0;background:#fff;flex-shrink:0;">' +
                    '<img src="' + u + '" alt="foto" style="width:100%;height:100%;object-fit:cover;">' +
                    '<button type="button" class="pvEnsThumbX" data-campo="'+campo+'" data-idx="'+i+'" ' +
                      'style="position:absolute;top:2px;right:2px;background:rgba(0,0,0,.6);color:#fff;border:0;border-radius:50%;width:18px;height:18px;font-size:11px;line-height:1;cursor:pointer;">×</button>' +
                  '</div>';
      });
      $('.pvEnsThumbStrip[data-campo="'+campo+'"]').html(thumbs);
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
