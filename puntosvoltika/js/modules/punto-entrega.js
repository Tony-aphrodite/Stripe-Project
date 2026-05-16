window.PV_entrega = (function(){
  var ctx = {};

  // Customer brief 2026-05-12 (Óscar, 7th round — screenshot 1:
  // "Here we need the history of the delivered bikes"): split the
  // Entregas screen into "Pendientes" (legacy active deliveries) and
  // "Historial" (completed deliveries with documents). Same UX pattern
  // as the Recepción screen so the operator only needs one mental model.
  var _tab = 'pendientes';
  var _historyFilter = '';

  function tabsBar(){
    var pCls = _tab === 'pendientes' ? 'primary' : 'ghost';
    var hCls = _tab === 'historial'  ? 'primary' : 'ghost';
    return '<div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap;">'+
      '<button class="ad-btn '+pCls+' sm pvEntTab" data-tab="pendientes">🚚 Pendientes</button>'+
      '<button class="ad-btn '+hCls+' sm pvEntTab" data-tab="historial">🗂️ Historial</button>'+
    '</div>';
  }

  function bindTabs(){
    $('.pvEntTab').off('click').on('click', function(){
      _tab = $(this).data('tab');
      render();
    });
  }

  function escapeHtml(s){
    return String(s||'').replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  // Bug 5.1 (customer brief 2026-05-08): auto-save the wizard state every
  // time the user advances. The server keeps the latest snapshot in
  // entregas.step / step_data so a closed window or refresh doesn't blow
  // away the operator's progress.
  function autosave(step, data){
    if (!ctx || !ctx.moto_id) return;
    PVApp.api('entrega/guardar-paso.php', {
      moto_id: ctx.moto_id,
      step: step,
      step_data: data || {}
    }); // fire-and-forget — we don't block the UI on this
  }

  // Bug 5.1 — "Entrega no exitosa" button (added to every step). Asks
  // the operator for a reason, hits marcar-no-exitosa.php, then resets.
  function noExitosaBtnHtml(){
    return '<button id="pvNoExitosa" class="ad-btn ghost" '+
      'style="width:100%;margin-top:8px;border:1px dashed #c41e3a;color:#c41e3a;">'+
      '✗ Entrega NO exitosa'+
      '</button>';
  }
  function bindNoExitosa(){
    $('#pvNoExitosa').off('click').on('click', function(){
      PVApp.modal(
        '<div class="ad-h2">Cerrar entrega como NO exitosa</div>'+
        '<div class="ad-dim" style="font-size:12.5px;margin-bottom:10px;">Esta entrega quedará cerrada. Si más adelante el cliente regresa, podrás iniciar un nuevo proceso.</div>'+
        '<label class="ad-label">Motivo (mínimo 5 caracteres)</label>'+
        '<textarea id="pvNoExMotivo" class="ad-input" rows="3" placeholder="Ej: cliente no se presentó, INE vencido, problema con la moto, etc."></textarea>'+
        '<div style="display:flex;gap:8px;margin-top:12px;">'+
          '<button id="pvNoExCancel" class="ad-btn ghost" style="flex:1;">Cancelar</button>'+
          '<button id="pvNoExConfirm" class="ad-btn primary" style="flex:1;background:#c41e3a;border-color:#c41e3a;">Confirmar cierre</button>'+
        '</div>'
      );
      $('#pvNoExCancel').on('click', function(){ PVApp.closeModal(); });
      $('#pvNoExConfirm').on('click', function(){
        var motivo = ($('#pvNoExMotivo').val()||'').trim();
        if (motivo.length < 5) { alert('Describe brevemente el motivo (mínimo 5 caracteres).'); return; }
        var $b = $(this).prop('disabled', true).html('<span class="ad-spin"></span>');
        PVApp.api('entrega/marcar-no-exitosa.php', { moto_id: ctx.moto_id, motivo: motivo })
          .done(function(r){
            if (r.ok) { PVApp.closeModal(); PVApp.toast('Entrega cerrada como NO exitosa'); render(); }
            else { $b.prop('disabled', false).text('Confirmar cierre'); alert(r.error||'Error'); }
          })
          .fail(function(x){
            $b.prop('disabled', false).text('Confirmar cierre');
            alert((x.responseJSON&&x.responseJSON.error)||'Error');
          });
      });
    });
  }

  function render(){
    if (_tab === 'historial') return renderHistorial();
    PVApp.render(tabsBar() + '<div class="ad-h1">Entregar al cliente</div><div><span class="ad-spin"></span></div>');
    bindTabs();
    PVApp.api('inventario/listar.php').done(function(r){
      var list = (r.inventario_entrega||[]).filter(function(m){ return m.estado!=='entregada'; });
      var html = tabsBar() + '<div class="ad-h1">Entregar al cliente</div>';

      // Fraud-prevention warning. Always shown, regardless of whether the list
      // has items — punto operator must always see this notice before acting.
      // Icon: simple outlined padlock (stroke only, geometric). Explicitly NOT
      // a generic "🚨/⚠️" emoji to avoid the AI-generated look.
      html += '<div style="display:flex;gap:10px;align-items:flex-start;'+
        'background:#FFF4F2;border-left:4px solid #C41E3A;'+
        'padding:12px 14px;margin:8px 0 14px;border-radius:6px;">'+
        '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#C41E3A" '+
          'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" '+
          'style="flex-shrink:0;margin-top:1px;">'+
          '<rect x="4" y="11" width="16" height="10" rx="1.5"/>'+
          '<path d="M8 11V7a4 4 0 018 0v4"/>'+
          '<circle cx="12" cy="16" r="1.2" fill="#C41E3A"/>'+
        '</svg>'+
        '<div style="font-size:12.5px;line-height:1.55;color:#4a1220;">'+
          '<div style="margin-bottom:6px;">Estas son las <strong>únicas motos autorizadas</strong> que puedes entregar. '+
          'No entregues por ningún motivo si el número <strong>VIN</strong> no aparece en esta lista.</div>'+
          '<div>Nadie puede pedirte entregar una moto si no aparece en esta lista.</div>'+
        '</div>'+
      '</div>';

      if (list.length===0) html += '<div class="ad-card">Sin motos para entregar</div>';
      list.forEach(function(m){
        html += '<div class="ad-card">'+
          (m.pedido_num ? '<div style="font-size:12px;font-weight:700;color:var(--ad-primary,#039fe1);margin-bottom:4px;">'+m.pedido_num+'</div>' : '')+
          '<div style="font-weight:700">'+m.modelo+' · '+m.color+'</div>'+
          '<div style="font-size:12px">Cliente: <strong>'+(m.cliente_nombre||'—')+'</strong></div>'+
          '<div style="font-size:11px;color:var(--ad-dim)">'+(m.cliente_telefono||'')+' · '+(m.cliente_email||'')+'</div>'+
          '<button class="ad-btn primary sm pvStart" data-id="'+m.id+'" data-nombre="'+m.cliente_nombre+'" data-tel="'+m.cliente_telefono+'" data-pedido="'+(m.pedido_num||'')+'" style="margin-top:8px"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:3px;"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 000-5C13 2 12 7 12 7z"/></svg> Iniciar entrega</button>'+
        '</div>';
      });
      PVApp.render(html);
      bindTabs();
      $('.pvStart').on('click', function(){
        ctx = { moto_id: $(this).data('id'), cliente: $(this).data('nombre'), tel: $(this).data('tel'), pedido: $(this).data('pedido'), step:0 };
        step1();
      });
    });
  }

  // ── Historial de entregas (Bug 7.1) ──────────────────────────────────
  // Lists every delivered moto for this punto with the linked entrega,
  // the receiving dealer user, and a "Documentos" button to view the
  // signed contract + Stripe receipt without leaving the punto panel.
  function renderHistorial(){
    var html = tabsBar();
    html += '<div class="ad-h1">Historial de entregas</div>';
    html += '<div style="color:var(--ad-dim);margin-bottom:10px;">Todas las motos entregadas al cliente en este punto, con acceso a contrato y recibo de pago.</div>';
    html += '<div style="display:flex;gap:6px;margin-bottom:12px;">'+
      '<input id="pvEntHistSearch" class="ad-input" placeholder="Buscar por VIN, modelo, pedido o cliente" '+
        'value="'+(_historyFilter||'').replace(/"/g,'&quot;')+'" style="flex:1;">'+
      '<button class="ad-btn primary sm" id="pvEntHistSearchBtn">Buscar</button>'+
      (_historyFilter ? '<button class="ad-btn ghost sm" id="pvEntHistClear">Limpiar</button>' : '')+
    '</div>';
    html += '<div id="pvEntHistList"><div><span class="ad-spin"></span> Cargando historial…</div></div>';
    PVApp.render(html);
    bindTabs();
    $('#pvEntHistSearchBtn').on('click', function(){
      _historyFilter = ($('#pvEntHistSearch').val()||'').trim();
      renderHistorial();
    });
    $('#pvEntHistSearch').on('keydown', function(e){ if(e.which===13){ $('#pvEntHistSearchBtn').click(); } });
    $('#pvEntHistClear').on('click', function(){ _historyFilter=''; renderHistorial(); });

    var url = 'entrega/historial.php' + (_historyFilter ? '?q='+encodeURIComponent(_historyFilter) : '');
    PVApp.api(url).done(paintHistorial).fail(function(x){
      $('#pvEntHistList').html('<div class="ad-card" style="color:#b91c1c;">Error al cargar historial: '+
        ((x.responseJSON&&x.responseJSON.error)||'conexión')+'</div>');
    });
  }

  function paintHistorial(r){
    var rows = (r && r.entregas) || [];
    if (rows.length === 0) {
      $('#pvEntHistList').html('<div class="ad-card" style="color:var(--ad-dim);">No hay entregas completadas'+
        (_historyFilter ? ' para "'+_historyFilter+'".' : '.') + '</div>');
      return;
    }
    var html = '<div style="font-size:12px;color:var(--ad-dim);margin-bottom:8px;">'+
      rows.length + ' entrega' + (rows.length===1?'':'s') +
      (_historyFilter ? ' que coinciden con "'+_historyFilter+'"' : '') + '</div>';
    rows.forEach(function(row, i){ html += histCard(row, i); });
    $('#pvEntHistList').html(html);
    $('.pvEntDocs').on('click', function(){
      var idx = parseInt($(this).data('idx'), 10);
      var row = rows[idx];
      if (row) showDocumentosModal(row);
    });
  }

  function fmtDate(d){
    if(!d) return '—';
    return String(d).slice(0,10);
  }

  function histCard(row, idx){
    // idx position is implicit by iteration order — we re-bind via data-idx
    // below using a closure over the rows array.
    var actaBadge = row.cliente_acta_firmada == 1
      ? '<span class="ad-badge green" title="Acta de entrega firmada por el cliente">Acta ✓</span>'
      : '<span class="ad-badge yellow" title="Falta la firma del acta">Acta pend.</span>';
    var paymentBadge = (row.stripe_pi && String(row.stripe_pi).indexOf('pi_')===0)
      ? '<span class="ad-badge blue" title="Pago Stripe vinculado">Stripe</span>'
      : '<span class="ad-badge gray" title="Sin PaymentIntent">Sin Stripe</span>';

    return '<div class="ad-card">'+
      '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">'+
        '<div style="flex:1;min-width:200px;">'+
          (row.pedido_num ? '<div style="font-size:11.5px;font-weight:700;color:var(--ad-primary,#039fe1);">'+row.pedido_num+'</div>' : '')+
          '<div style="font-weight:700;">'+(row.modelo||'—')+' · '+(row.color||'—')+'</div>'+
          '<div style="font-size:12px;color:var(--ad-dim);">VIN: <code>'+(row.vin_display||row.vin||'—')+'</code></div>'+
          (row.cliente_nombre ? '<div style="font-size:12.5px;margin-top:4px;">Cliente: <strong>'+escapeHtml(row.cliente_nombre)+'</strong></div>' : '')+
          (row.cliente_telefono ? '<div style="font-size:11.5px;color:var(--ad-dim);">'+escapeHtml(row.cliente_telefono)+'</div>' : '')+
        '</div>'+
        '<div style="text-align:right;">'+
          '<div style="font-size:11px;color:var(--ad-dim);">'+fmtDate(row.fecha_entrega || row.entrega_freg)+'</div>'+
          '<div style="font-size:11px;color:#374151;margin-top:2px;">Entregó: <strong>'+escapeHtml(row.recibido_por_nombre||'—')+'</strong></div>'+
          '<div style="margin-top:6px;display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end;">'+
            actaBadge + ' ' + paymentBadge +
          '</div>'+
        '</div>'+
      '</div>'+
      '<button class="ad-btn ghost sm pvEntDocs" data-idx="'+idx+'" style="margin-top:10px;">'+
        '📁 Ver documentos'+
      '</button>'+
    '</div>';
  }

  // Documentos modal — contract + Stripe receipt, both reachable from the
  // punto session. Contract: descargar-contrato.php (now accepts
  // VOLTIKA_PUNTO session). Receipt: stripe-recibo.php (server fetches the
  // public receipt_url and 302-redirects — no Stripe dashboard login).
  function showDocumentosModal(row){
    var dispo = row.disponible || {};
    var contractKey = row.contract_key || '';
    var html = '<div class="ad-h2">Documentos de la entrega</div>'+
      '<div style="font-size:12.5px;color:var(--ad-dim);margin-bottom:14px;">'+
        (row.pedido_num ? '<strong>'+escapeHtml(row.pedido_num)+'</strong> · ' : '')+
        escapeHtml((row.modelo||'')+' '+(row.color||''))+
        (row.cliente_nombre ? '<br>Cliente: <strong>'+escapeHtml(row.cliente_nombre)+'</strong>' : '')+
      '</div>';

    html += '<div style="display:flex;flex-direction:column;gap:8px;">';

    // Contrato — 3 estados posibles:
    //   1. disponible        → enlace clicable (azul)
    //   2. crédito pendiente → tarjeta amarilla informativa, no clicable
    //   3. sin identificador → tarjeta gris (no debería ocurrir si historial
    //                          devolvió contract_key)
    if (dispo.contrato && contractKey) {
      var contractUrl = '/configurador/php/descargar-contrato.php?pedido='+
        encodeURIComponent(contractKey)+'&inline=1';
      html += '<a href="'+contractUrl+'" target="_blank" rel="noopener" '+
        'style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:#dbeafe;border:1px solid #93c5fd;border-radius:8px;text-decoration:none;color:#1e40af;">'+
        '<span style="font-size:22px;">📄</span>'+
        '<div style="flex:1;"><div style="font-weight:700;">Contrato firmado</div>'+
        '<div style="font-size:11.5px;opacity:.85;">Contrato de compraventa con firma electrónica del cliente.</div></div>'+
        '<span>›</span>'+
      '</a>';
    } else if (dispo.contrato_credito_pendiente) {
      // Customer brief 2026-05-12 (Óscar, 7th round): credit contracts
      // only get a PDF once the customer signs via Truora+Cincel. We
      // surface this state explicitly so the punto operator doesn't end
      // up on the technical diagnostic page.
      html += '<div style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;color:#92400e;">'+
        '<span style="font-size:22px;">⏳</span>'+
        '<div style="flex:1;">'+
          '<div style="font-weight:700;">Contrato de crédito pendiente de firma</div>'+
          '<div style="font-size:11.5px;line-height:1.5;margin-top:2px;">'+
            'Este pedido es a crédito y el cliente <strong>aún no ha firmado</strong> el contrato electrónico '+
            '(vía Truora + Cincel desde su portal). El PDF se generará automáticamente cuando complete la firma.'+
          '</div>'+
        '</div>'+
      '</div>';
    } else {
      html += '<div style="padding:12px 14px;background:#f3f4f6;border:1px dashed #d1d5db;border-radius:8px;color:#6b7280;font-size:12.5px;">'+
        '<strong>📄 Contrato firmado</strong><br>No disponible — falta identificador de pedido.</div>';
    }

    // Recibo de pago (Stripe)
    if (dispo.recibo_pago) {
      var receiptUrl = 'php/entrega/stripe-recibo.php?moto_id='+encodeURIComponent(row.moto_id);
      html += '<a href="'+receiptUrl+'" target="_blank" rel="noopener" '+
        'style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:#dcfce7;border:1px solid #86efac;border-radius:8px;text-decoration:none;color:#15803d;">'+
        '<span style="font-size:22px;">💳</span>'+
        '<div style="flex:1;"><div style="font-weight:700;">Recibo de pago</div>'+
        '<div style="font-size:11.5px;opacity:.85;">Recibo público de Stripe — no requiere iniciar sesión.</div></div>'+
        '<span>›</span>'+
      '</a>';
    } else {
      html += '<div style="padding:12px 14px;background:#f3f4f6;border:1px dashed #d1d5db;border-radius:8px;color:#6b7280;font-size:12.5px;">'+
        '<strong>💳 Recibo de pago</strong><br>No disponible — esta orden no tiene PaymentIntent de Stripe asociado.</div>';
    }

    // Acta de entrega
    if (row.cliente_acta_firmada == 1) {
      html += '<div style="padding:12px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;color:#166534;font-size:12.5px;">'+
        '<strong>✅ Acta de entrega firmada</strong><br>El cliente firmó electrónicamente vía portal Cincel.</div>';
    } else {
      html += '<div style="padding:12px 14px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;color:#92400e;font-size:12.5px;">'+
        '<strong>⏳ Acta de entrega pendiente</strong><br>El cliente aún no ha firmado el acta en su portal.</div>';
    }

    html += '</div>'+
      '<div style="margin-top:14px;text-align:right;">'+
        '<button class="ad-btn ghost" onclick="PVApp.closeModal()">Cerrar</button>'+
      '</div>';

    PVApp.modal(html);
  }
  function steps(idx){
    var s='<div class="pv-wizard-steps">';
    for(var i=0;i<5;i++) s+='<div class="'+(i<idx?'done':(i===idx?'active':''))+'"></div>';
    s+='</div>';
    return s;
  }
  // Step 1: Send OTP
  function step1(){
    PVApp.modal(
      steps(0)+
      '<div class="ad-h2">1. Enviar OTP al cliente</div>'+
      (ctx.pedido ? '<div style="font-size:12px;font-weight:700;color:var(--ad-primary,#039fe1);margin-bottom:8px;">'+ctx.pedido+'</div>' : '')+
      '<div class="ad-card">Cliente: <strong>'+ctx.cliente+'</strong><br>Tel: '+ctx.tel+'</div>'+
      '<button id="pvS1" class="ad-btn primary" style="width:100%">Enviar código por SMS</button>'+
      noExitosaBtnHtml()
    );
    bindNoExitosa();
    $('#pvS1').on('click', function(){
      var $b=$(this).prop('disabled',true).html('<span class="ad-spin"></span>');
      PVApp.api('entrega/iniciar.php',{moto_id:ctx.moto_id}).done(function(r){
        if(r.ok){
          ctx.testCode = r.test_code;
          autosave('step1', { otp_enviado: true });
          // When no channel delivered the OTP (warning present + test_code
          // returned), surface it loudly so staff reads the code aloud
          // instead of silently letting the flow stall. Customer reported
          // "OTP didn't receive it" — this makes that case actionable.
          if (r.warning) {
            alert('⚠️ ' + r.warning + '\n\nCódigo del cliente: ' + (r.test_code || ctx.testCode || '(no disponible)'));
          }
          step2();
        }
      }).fail(function(x){ alert((x.responseJSON&&x.responseJSON.error)||'Error'); $b.prop('disabled',false).text('Enviar código por SMS'); });
    });
  }
  // Step 2: Verify OTP
  function step2(){
    PVApp.modal(
      steps(1)+
      '<div class="ad-h2">2. Verificar código del cliente</div>'+
      '<div>Pide al cliente que te muestre el código recibido por SMS.</div>'+
      '<div class="pv-otp-input">'+[0,1,2,3,4,5].map(function(i){return '<input maxlength="1" data-i="'+i+'" inputmode="numeric">';}).join('')+'</div>'+
      (ctx.testCode?'<div class="ad-card" style="color:var(--ad-warn)">Código de prueba: <b>'+ctx.testCode+'</b></div>':'')+
      '<button id="pvS2" class="ad-btn primary" style="width:100%">Verificar</button>'+
      // Round 38 (2026-05-14, Óscar — "OTP never coming"): when the
      // first SMS attempt silently fails (carrier blocks, customer's
      // phone off, wrong number), the operator was stuck with no way
      // to retry. Resend button regenerates the OTP and retransmits —
      // any failure surfaces via the same warning + test_code path.
      '<button id="pvS2Resend" class="ad-btn ghost sm" style="width:100%;margin-top:8px;">'+
        '📩 ¿No le llegó el SMS al cliente? Reenviar código'+
      '</button>'+
      // Round 43 (2026-05-16, Óscar — "we need this today to deliver a
      // moto"): even after Resend the SMS may never reach the customer
      // (carrier block, phone off, wrong number variant). Last-resort
      // option: show the OTP on the operator's screen so it can be
      // verified IN PERSON with the customer (INE + face match cover
      // the identity check). The button calls revelar-otp.php which
      // requires a motivo, audit-logs the action, and returns the OTP.
      '<button id="pvS2Reveal" class="ad-btn ghost sm" style="width:100%;margin-top:6px;color:#9a3412;border-color:#fed7aa;">'+
        '🚨 Cliente en el punto pero no recibe SMS — Verificar con código en pantalla'+
      '</button>'+
      noExitosaBtnHtml()
    );
    bindNoExitosa();
    var $ins=$('.pv-otp-input input');
    $ins.on('input',function(){ this.value=this.value.replace(/\D/g,''); if(this.value)$ins.eq($(this).data('i')+1).focus(); });
    $ins.eq(0).focus();
    $('#pvS2').on('click', function(){
      var code=$ins.map(function(){return this.value;}).get().join('');
      PVApp.api('entrega/verificar-otp.php',{moto_id:ctx.moto_id,codigo:code}).done(function(r){
        if(r.ok){ ctx.entrega_id=r.entrega_id; autosave('step2', { otp_verified: true }); step3(); }
      }).fail(function(x){ alert((x.responseJSON&&x.responseJSON.error)||'Código incorrecto'); });
    });
    // Round 38: resend OTP handler — re-calls entrega/iniciar.php which
    // issues a fresh OTP and re-attempts every channel. Reuses the
    // warning + test_code surfacing from step1 so failure mode stays
    // visible (e.g., "El proveedor de SMS rechazó el envío HTTP 401").
    $('#pvS2Resend').on('click', function(){
      var $r = $(this).prop('disabled', true).text('Reenviando…');
      PVApp.api('entrega/iniciar.php', { moto_id: ctx.moto_id }).done(function(r){
        if (r && r.ok) {
          if (r.test_code) ctx.testCode = r.test_code;
          if (r.warning) {
            alert('⚠️ ' + r.warning + '\n\nCódigo del cliente: ' + (r.test_code || ctx.testCode || '(no disponible)'));
          } else {
            PVApp.toast && PVApp.toast('SMS reenviado al ' + (ctx.tel || 'cliente'));
          }
          // Re-render step2 to refresh the test_code visibility.
          step2();
        } else {
          alert('No se pudo reenviar el código. Reporta a soporte.');
        }
      }).fail(function(x){
        alert('Error al reenviar: ' + ((x.responseJSON && x.responseJSON.error) || 'Conexión'));
        $r.prop('disabled', false).text('📩 ¿No le llegó el SMS al cliente? Reenviar código');
      });
    });
    // Round 43: emergency in-person OTP reveal. Requires a written
    // justification (≥10 chars) which is recorded in entrega_otp_revelaciones
    // + admin_log. The OTP is shown in a confirmation modal so the operator
    // and customer can verify together — operator then types it into the
    // 6-digit inputs above and presses Verificar normally.
    $('#pvS2Reveal').on('click', function(){
      var motivo = prompt(
        'Esta acción muestra el código OTP en pantalla para verificarlo con el cliente EN PERSONA.\n\n' +
        '• Úsala solo cuando el cliente está físicamente presente y su teléfono no recibe SMS.\n' +
        '• La acción queda registrada (quién, cuándo, qué moto, motivo).\n' +
        '• Verifica la identidad del cliente con INE antes de continuar.\n\n' +
        'Escribe el motivo (mínimo 10 caracteres):'
      );
      if (motivo === null) return;   // user cancelled
      motivo = String(motivo).trim();
      if (motivo.length < 10) {
        alert('El motivo debe tener al menos 10 caracteres. Describe la razón concreta (ej. "teléfono apagado, cliente presente con INE").');
        return;
      }
      var $btn = $(this).prop('disabled', true).text('Procesando...');
      PVApp.api('entrega/revelar-otp.php', { moto_id: ctx.moto_id, motivo: motivo })
        .done(function(r){
          if (r && r.ok) {
            if (r.already_verified) {
              PVApp.toast && PVApp.toast('El código ya fue verificado — avanza al siguiente paso');
              return;
            }
            // Show the OTP in a clear, large display the operator can read aloud.
            alert(
              '🔐 Código de entrega del cliente:\n\n' +
              '          ' + r.otp + '\n\n' +
              'Cliente: ' + (r.cliente || '—') + '\n' +
              'Teléfono: ' + (r.telefono || '—') + '\n\n' +
              '⚠ ' + (r.warning || 'Verifica identidad con INE antes de continuar.') + '\n\n' +
              'Ahora teclea el código en los 6 cuadros arriba y presiona Verificar.'
            );
            // Pre-fill the OTP inputs for convenience.
            try {
              var digits = String(r.otp).replace(/\D/g, '').split('');
              for (var i = 0; i < 6 && i < digits.length; i++) {
                $ins.eq(i).val(digits[i]);
              }
              $ins.eq(5).focus();
            } catch (_e) {}
          } else {
            alert((r && r.error) || 'No se pudo revelar el código.');
          }
        })
        .fail(function(x){
          alert((x.responseJSON && x.responseJSON.error) || 'Error de conexión al revelar el código.');
        })
        .always(function(){
          $btn.prop('disabled', false).text('🚨 Cliente en el punto pero no recibe SMS — Verificar con código en pantalla');
        });
    });
  }
  // Step 3: Face verification + photos.
  // Customer brief 2026-04-24: explain to the operator that the current photo
  // will be compared against the Truora selfie taken when the client applied
  // for credit — same person must appear. Button stays disabled until both
  // files are picked so the operator can't skip the comparison.
  //
  // Bug 5.3 + 5.4 (customer brief 2026-05-08): the INE slot was a single
  // file input WITHOUT `capture` so on the operator's device it only
  // opened the file browser instead of the camera. Now each ID side has
  // BOTH a camera button (capture=environment, hidden file input) AND a
  // gallery button (no capture). And the back side gets its own slot —
  // operators previously could only upload the front, but the briefing
  // requires both sides. Backward compatibility: foto_ine is still sent
  // (as the front), so the existing verificar-rostro.php contract holds;
  // foto_ine_reverso is added as an OPTIONAL second photo.
  function step3(){
    function dualInput(slot, label){
      // slot: 'cliente' | 'ineFrente' | 'ineReverso'
      // Two hidden file inputs (camera vs file) + visible buttons that
      // trigger them. A small thumbnail confirms the picked file.
      var camId  = 'pvF' + slot + 'Cam';
      var fileId = 'pvF' + slot + 'File';
      var camCap = (slot === 'cliente') ? 'user' : 'environment';
      return ''+
        '<input type="file" id="'+camId+'" accept="image/*" capture="'+camCap+'" '+
          'data-slot="'+slot+'" class="pvIneInput" style="display:none;">'+
        '<input type="file" id="'+fileId+'" accept="image/*" '+
          'data-slot="'+slot+'" class="pvIneInput" style="display:none;">'+
        '<div style="display:flex;gap:8px;margin-bottom:6px;">'+
          '<button type="button" class="ad-btn primary pvOpenCam" data-target="'+camId+'" '+
            'style="flex:1;">📷 Tomar foto</button>'+
          '<button type="button" class="ad-btn ghost pvOpenFile" data-target="'+fileId+'" '+
            'style="flex:1;">📁 Elegir archivo</button>'+
        '</div>'+
        '<div id="pvFThumb_'+slot+'" style="font-size:12px;color:#16a34a;margin-bottom:14px;display:none;">'+
          '✓ Foto cargada'+
        '</div>';
    }
    PVApp.modal(
      steps(2)+
      '<div class="ad-h2">3. Foto del cliente e INE</div>'+
      '<div style="color:var(--ad-dim);font-size:12.5px;margin-bottom:14px;line-height:1.45;">'+
        'Toma una <strong>foto del rostro del cliente</strong> y <strong>fotos de su INE (frente y reverso)</strong>.<br>'+
        'La foto del rostro se compara automáticamente con la selfie que tomó al solicitar su crédito — '+
        '<strong>debe ser la misma persona</strong> que compró la moto.'+
      '</div>'+

      '<label class="ad-label" style="display:block;font-weight:700;margin-bottom:4px;">📸 Foto rostro del cliente <span style="color:#dc2626">*</span></label>'+
      '<div style="font-size:11.5px;color:var(--ad-dim);margin-bottom:4px;">Rostro de frente, bien iluminado, sin lentes oscuros ni cubrebocas.</div>'+
      dualInput('cliente') +

      '<label class="ad-label" style="display:block;font-weight:700;margin-bottom:4px;">🪪 INE — Frente <span style="color:#dc2626">*</span></label>'+
      '<div style="font-size:11.5px;color:var(--ad-dim);margin-bottom:4px;">Lado frontal, con foto visible y legible.</div>'+
      dualInput('ineFrente') +

      '<label class="ad-label" style="display:block;font-weight:700;margin-bottom:4px;">🪪 INE — Reverso <span style="color:#dc2626">*</span></label>'+
      '<div style="font-size:11.5px;color:var(--ad-dim);margin-bottom:4px;">Lado posterior con la dirección y firma.</div>'+
      dualInput('ineReverso') +

      '<div id="pvS3Hint" style="font-size:12px;color:#b45309;background:#fffbeb;border-left:3px solid #f59e0b;padding:8px 10px;border-radius:4px;margin-bottom:10px;display:none;">'+
        '⚠ Faltan fotos por seleccionar.'+
      '</div>'+
      '<button id="pvS3" class="ad-btn primary" style="width:100%" disabled>Verificar rostro</button>'+
      noExitosaBtnHtml()
    );
    bindNoExitosa();

    // Tracks the current File chosen per slot. Last-write-wins, so picking
    // "Tomar foto" after "Elegir archivo" replaces it (and vice-versa).
    var picked = { cliente: null, ineFrente: null, ineReverso: null };

    // Each visible button triggers its hidden input — on mobile this gives
    // the operator a clear choice between camera (capture attr) and file
    // gallery (no capture attr).
    $('.pvOpenCam, .pvOpenFile').on('click', function(){
      var t = $(this).data('target');
      if (t) document.getElementById(t).click();
    });

    $('.pvIneInput').on('change', function(){
      var slot = $(this).data('slot');
      var f = this.files && this.files[0];
      if (f) {
        picked[slot] = f;
        $('#pvFThumb_'+slot).text('✓ Foto cargada (' + Math.round(f.size/1024) + ' KB)').show();
      }
      refreshStep3Btn();
    });

    function refreshStep3Btn(){
      var ready = picked.cliente && picked.ineFrente && picked.ineReverso;
      $('#pvS3').prop('disabled', !ready);
      var missing = [];
      if (!picked.cliente)    missing.push('rostro');
      if (!picked.ineFrente)  missing.push('INE frente');
      if (!picked.ineReverso) missing.push('INE reverso');
      if (missing.length) {
        $('#pvS3Hint').text('⚠ Faltan: ' + missing.join(', ')).show();
      } else {
        $('#pvS3Hint').hide();
      }
    }
    refreshStep3Btn();

    $('#pvS3').on('click', function(){
      if (!picked.cliente || !picked.ineFrente || !picked.ineReverso) {
        refreshStep3Btn();
        return;
      }
      var $b=$(this).prop('disabled',true).html('<span class="ad-spin"></span>');
      // Read all 3 files as base64 in parallel.
      var slots = ['cliente','ineFrente','ineReverso'];
      var out = {}; var pending = slots.length;
      slots.forEach(function(s){
        var rdr = new FileReader();
        rdr.onload = function(){ out[s] = rdr.result; if(--pending === 0) submit(out); };
        rdr.readAsDataURL(picked[s]);
      });

      function submit(files){
        PVApp.api('entrega/verificar-rostro.php',{
          entrega_id: ctx.entrega_id, moto_id: ctx.moto_id,
          foto_cliente: files.cliente,
          foto_ine: files.ineFrente,           // backward-compat: front goes to existing field
          foto_ine_reverso: files.ineReverso   // new: back side
        }).done(function(r){
          if(!r.ok) return;
          autosave('step3', { face_match: r.match === true });
          handleFaceResult(r);
        }).fail(function(x){ alert((x.responseJSON&&x.responseJSON.error)||'Error'); $b.prop('disabled',false).text('Verificar rostro'); });
      }
    });
  }
  // Decide how to proceed after the face-check endpoint responds.
  // CREDITO purchases: hard-block on no_match, offer manual override if no original selfie on file.
  // MSI / CONTADO: there is no comparison — just advance.
  function handleFaceResult(r){
    if (!r.es_credito) {
      PVApp.toast(r.message || 'Fotos guardadas');
      step4();
      return;
    }
    // CREDITO — match confirmed by Truora
    if (r.match === true) {
      PVApp.toast('Rostro coincide con el crédito'+(r.face_score!=null?' ('+r.face_score+'%)':''));
      step4();
      return;
    }
    // CREDITO — faces do NOT match → cannot deliver
    if (r.match === false) {
      PVApp.modal(
        '<div class="ad-h2" style="color:var(--ad-err)">No se puede entregar</div>'+
        '<div class="ad-card" style="color:var(--ad-err)">'+
          '<strong>Las caras NO coinciden</strong> con la persona que realizó el crédito'+
          (r.face_score!=null?' (similitud '+r.face_score+'%)':'')+'.<br><br>'+
          'La entrega a crédito <strong>solo puede hacerse al titular</strong>. '+
          'Solicita al cliente que se presente el titular del crédito, o escala a soporte para verificación manual.'+
        '</div>'+
        '<button id="pvFaceRetry" class="ad-btn ghost" style="width:100%;margin-top:10px">Reintentar con otra foto</button>'+
        '<button id="pvFaceAbort" class="ad-btn" style="width:100%;margin-top:6px">Cancelar entrega</button>'
      );
      $('#pvFaceRetry').on('click', step3);
      $('#pvFaceAbort').on('click', function(){ PVApp.closeModal(); render(); });
      return;
    }
    // CREDITO — manual review path (no original selfie on file or Truora unavailable)
    PVApp.modal(
      '<div class="ad-h2" style="color:var(--ad-warn)">Verificación manual requerida</div>'+
      '<div class="ad-card">'+
        (r.message || 'No se pudo comparar automáticamente con la selfie del crédito.')+'<br><br>'+
        '<strong>Compara visualmente</strong> la foto tomada hoy contra la identificación (INE). '+
        'Si estás seguro de que es el mismo titular del crédito, puedes continuar bajo tu responsabilidad.'+
      '</div>'+
      '<label class="pv-check"><input type="checkbox" id="pvFaceConfirm"> Confirmo que la persona presente es el titular del crédito</label>'+
      '<button id="pvFaceManualOk" class="ad-btn primary" style="width:100%;margin-top:10px" disabled>Continuar con la entrega</button>'+
      '<button id="pvFaceManualCancel" class="ad-btn ghost" style="width:100%;margin-top:6px">Cancelar</button>'
    );
    $('#pvFaceConfirm').on('change', function(){ $('#pvFaceManualOk').prop('disabled', !this.checked); });
    $('#pvFaceManualOk').on('click', step4);
    $('#pvFaceManualCancel').on('click', function(){ PVApp.closeModal(); render(); });
  }
  // Step 4: Full delivery checklist (Bug 5.6 — customer brief 2026-05-08).
  //
  // Replaces the legacy reduced version (4 checkboxes + 3 photos) with the
  // full 5-phase checklist matching the admin panel:
  //   F1 — Identidad
  //   F2 — Pago
  //   F3 — Unidad (legacy fields, kept)
  //   F4 — OTP (auto-filled from previous step, info-only)
  //   F5 — Acta (signed by the customer in their portal — info-only here)
  //
  // The 3 photo slots are preserved for backward compatibility with the
  // existing fotos_entrega rows. The submit endpoint is the same
  // entrega/checklist.php which now accepts all phase fields.
  var STEP4_PHASES = [
    { key:'fase1', title:'F1 — Identidad', sections:[
      { title:'Identificación del cliente', fields:[
        {key:'ine_presentada',         label:'INE presentada'},
        {key:'nombre_coincide',        label:'Nombre coincide con la orden'},
        {key:'foto_coincide',          label:'Foto de INE coincide con el cliente'},
        {key:'datos_confirmados',      label:'Datos personales confirmados'},
        {key:'ultimos4_telefono',      label:'Últimos 4 dígitos del teléfono verificados'},
        {key:'modelo_confirmado',      label:'Modelo de moto confirmado'},
        {key:'forma_pago_confirmada',  label:'Forma de pago confirmada'}
      ]}
    ]},
    { key:'fase2', title:'F2 — Pago', sections:[
      { title:'Confirmación de pagos', fields:[
        {key:'pago_confirmado',        label:'Pago confirmado en sistema'},
        {key:'enganche_validado',      label:'Enganche validado'},
        {key:'metodo_pago_registrado', label:'Método de pago registrado'},
        {key:'domiciliacion_confirmada', label:'Domiciliación confirmada'}
      ]}
    ]},
    { key:'fase3', title:'F3 — Unidad', sections:[
      { title:'Estado de la unidad', fields:[
        {key:'vin_coincide',      label:'VIN coincide con la orden'},
        {key:'unidad_ensamblada', label:'Unidad ensamblada (checklist ensamble completo)'},
        {key:'estado_fisico_ok',  label:'Estado físico correcto'},
        {key:'sin_danos',         label:'Sin daños visibles'},
        {key:'unidad_completa',   label:'Unidad completa (accesorios, llaves, manual)'}
      ]}
    ]}
  ];

  function step4(){
    var data = {};
    STEP4_PHASES.forEach(function(p){ p.sections.forEach(function(s){ s.fields.forEach(function(f){ data[f.key] = 0; }); }); });
    var activeKey = 'fase1';

    function totalDone(){
      var n = 0;
      STEP4_PHASES.forEach(function(p){ p.sections.forEach(function(s){ s.fields.forEach(function(f){ if (data[f.key]) n++; }); }); });
      return n;
    }
    function totalCount(){
      var n = 0;
      STEP4_PHASES.forEach(function(p){ p.sections.forEach(function(s){ n += s.fields.length; }); });
      return n;
    }

    function paint(){
      var done = totalDone(), total = totalCount();
      var pct  = total ? Math.round(done/total*100) : 0;

      var html = steps(3) +
        '<div class="ad-h2">4. Checklist de Entrega</div>' +
        '<div style="color:var(--ad-dim);font-size:12.5px;margin-bottom:10px;">Completa las 3 fases. Las fases F4 (OTP) y F5 (Acta firmada por el cliente) se completan automáticamente.</div>' +
        '<div style="background:#e5e7eb;border-radius:999px;height:8px;overflow:hidden;margin-bottom:4px;"><div style="background:#22c55e;height:100%;width:'+pct+'%;transition:width .3s;"></div></div>' +
        '<div style="font-size:12px;color:#64748b;margin-bottom:12px;">'+done+' / '+total+' items ('+pct+'%)</div>';

      // Phase tabs
      html += '<div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap;">';
      STEP4_PHASES.forEach(function(p){
        var bg = p.key === activeKey ? '#039fe1' : '#f1f5f9';
        var fg = p.key === activeKey ? '#fff' : '#334155';
        html += '<button class="pvS4Tab" data-fase="'+p.key+'" style="padding:7px 12px;border:0;border-radius:6px;cursor:pointer;background:'+bg+';color:'+fg+';font-size:12.5px;font-weight:600;">'+p.title+'</button>';
      });
      // Info-only F4 + F5 chips
      html += '<span style="padding:7px 12px;background:#dcfce7;color:#166534;border-radius:6px;font-size:12px;font-weight:600;" title="Completado al verificar OTP en el paso 2">F4 — OTP ✓</span>';
      html += '<span style="padding:7px 12px;background:#fef3c7;color:#92400e;border-radius:6px;font-size:12px;font-weight:600;" title="Se completa cuando el cliente firma en su portal">F5 — Acta ⏳</span>';
      html += '</div>';

      // Active phase content
      var ph = STEP4_PHASES.filter(function(p){ return p.key === activeKey; })[0];
      ph.sections.forEach(function(sec){
        html += '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:10px;">';
        html += '<div style="font-weight:700;font-size:13.5px;color:#0f172a;margin-bottom:8px;">'+sec.title+'</div>';
        sec.fields.forEach(function(f){
          var checked = data[f.key] ? 'checked' : '';
          html += '<label class="pv-check"><input type="checkbox" class="pvS4Chk" data-key="'+f.key+'" '+checked+'> '+f.label+'</label>';
        });
        html += '</div>';
      });

      // Photos (legacy slots — kept for backward compat with fotos_entrega)
      if (activeKey === 'fase3') {
        html += '<div style="font-weight:700;font-size:13px;color:var(--ad-navy,#1a3a5c);margin:14px 0 8px;text-transform:uppercase;letter-spacing:.3px;border-bottom:2px solid var(--ad-primary,#039fe1);padding-bottom:4px;">Fotos de la moto</div>'+
          '<label class="ad-label" for="pvFoto1" style="display:block;font-weight:700;margin-bottom:3px;">📷 Foto 1: Moto de frente <span style="color:#dc2626">*</span></label>'+
          '<input type="file" id="pvFoto1" accept="image/*" capture="environment" class="ad-input" style="margin-bottom:12px">'+
          '<label class="ad-label" for="pvFoto2" style="display:block;font-weight:700;margin-bottom:3px;">📷 Foto 2: Lateral (costado) <span style="color:#dc2626">*</span></label>'+
          '<input type="file" id="pvFoto2" accept="image/*" capture="environment" class="ad-input" style="margin-bottom:12px">'+
          '<label class="ad-label" for="pvFoto3" style="display:block;font-weight:700;margin-bottom:3px;">📷 Foto 3: Trasera <span style="color:#dc2626">*</span></label>'+
          '<input type="file" id="pvFoto3" accept="image/*" capture="environment" class="ad-input" style="margin-bottom:14px">';
      }

      html += '<div id="pvS4Hint" style="font-size:12px;color:#b45309;background:#fffbeb;border-left:3px solid #f59e0b;padding:8px 10px;border-radius:4px;margin-bottom:10px;display:none;">⚠ Faltan elementos por completar.</div>'+
        '<button id="pvS4" class="ad-btn primary" style="width:100%" disabled>Guardar checklist y enviar al cliente</button>'+
        noExitosaBtnHtml();

      PVApp.modal(html);
      bindNoExitosa();
      bind();
    }

    function bind(){
      $('.pvS4Tab').on('click', function(){
        // Persist current checkbox state before re-rendering, otherwise it's lost.
        $('.pvS4Chk').each(function(){ data[$(this).data('key')] = $(this).is(':checked') ? 1 : 0; });
        activeKey = $(this).data('fase');
        paint();
      });
      $('.pvS4Chk').on('change', function(){
        data[$(this).data('key')] = $(this).is(':checked') ? 1 : 0;
        refreshBtn();
      });
      $('#pvFoto1, #pvFoto2, #pvFoto3').on('change', refreshBtn);
      refreshBtn();
      $('#pvS4').on('click', submit);
    }

    function refreshBtn(){
      // Persist current visible checkbox state (other tabs already in `data`).
      $('.pvS4Chk').each(function(){ data[$(this).data('key')] = $(this).is(':checked') ? 1 : 0; });
      var done = totalDone(), total = totalCount();
      var photoCount = ['pvFoto1','pvFoto2','pvFoto3']
        .filter(function(id){ return $('#'+id).prop('files') && $('#'+id).prop('files').length > 0; }).length;
      var missing = [];
      if (done < total) missing.push((total-done)+' verificación(es)');
      if (photoCount < 3) missing.push((3-photoCount)+' foto(s)');
      var ready = missing.length === 0;
      $('#pvS4').prop('disabled', !ready);
      if (ready) $('#pvS4Hint').hide();
      else       $('#pvS4Hint').text('⚠ Faltan: ' + missing.join(' + ')).show();
    }

    function submit(){
      // Capture latest checkbox state (covers tabs the user didn't revisit).
      $('.pvS4Chk').each(function(){ data[$(this).data('key')] = $(this).is(':checked') ? 1 : 0; });
      var $b=$('#pvS4').prop('disabled',true).html('<span class="ad-spin"></span>');
      readFiles(['pvFoto1','pvFoto2','pvFoto3'], function(files){
        var payload = Object.assign({}, data, {
          moto_id: ctx.moto_id,
          fotos_moto: [files.pvFoto1, files.pvFoto2, files.pvFoto3].filter(Boolean)
        });
        PVApp.api('entrega/checklist.php', payload).done(function(r){
          if(r.ok) { autosave('step4', { checklist_done: true, progreso: r.progreso || null }); step5(); }
          else { $b.prop('disabled',false).text('Guardar checklist'); alert(r.error||'Error'); }
        }).fail(function(x){
          $b.prop('disabled',false).text('Guardar checklist');
          alert((x.responseJSON&&x.responseJSON.error)||'Error');
        });
      });
    }

    paint();
  }
  // Step 5: Wait for ACTA + finalize.
  //
  // The customer must sign the ACTA on their own portal before this dealer
  // can finalize. We poll entrega/estado-acta.php every 4 s so the button
  // auto-enables the moment the signature is recorded — the dealer doesn't
  // have to refresh or guess. Before our ACTA-signing bugfix this step
  // appeared broken because dealers clicked Finalizar while the signature
  // was silently failing on the portal side.
  var _step5Poll = null;
  function step5(){
    autosave('step5', { waiting_signature: true });
    PVApp.modal(
      steps(4)+
      '<div class="ad-h2">5. Firma del ACTA DE ENTREGA</div>'+
      '<div class="ad-card" style="color:var(--ad-warn)">'+
        'Pide al cliente que ingrese al portal <strong>voltika.mx/clientes</strong> desde su celular, revise el acta y la firme con <strong>Cincel</strong>.'+
      '</div>'+
      '<div id="pvS5Status" class="ad-card" style="font-size:13px;">'+
        '<span class="ad-spin" style="vertical-align:middle"></span> '+
        '<span id="pvS5StatusText">Esperando la firma del cliente...</span>'+
      '</div>'+
      '<button id="pvS5" class="ad-btn primary" disabled '+
        'style="width:100%;opacity:.5;cursor:not-allowed;">Finalizar entrega</button>'+
      noExitosaBtnHtml()
    );
    bindNoExitosa();

    function stopPolling(){
      if(_step5Poll){ clearInterval(_step5Poll); _step5Poll = null; }
    }

    function enableFinalize(signerName, signedAt){
      $('#pvS5Status').html(
        '<span style="color:#10b981;font-weight:700">&#10003; ACTA firmada'+
        (signerName ? ' por ' + $('<div/>').text(signerName).html() : '') + '</span>' +
        (signedAt ? '<div style="font-size:11px;color:#6b7280;margin-top:2px">'+ signedAt +'</div>' : '')
      );
      $('#pvS5').prop('disabled', false).css({opacity:'1', cursor:'pointer'});
    }

    function checkStatus(){
      // Stop polling if the modal got closed (X, backdrop, or moved to another
      // step). PVApp.modal uses a shared #pvModal element that is only
      // hidden — not removed — so we detect closure by checking visibility
      // and by confirming our button is still the one in the body.
      if (!$('#pvS5').length || !$('#pvModal').is(':visible')) {
        stopPolling();
        return;
      }
      // No second arg → PVApp.api issues a GET (see punto-app.js).
      PVApp.api('entrega/estado-acta.php?moto_id='+encodeURIComponent(ctx.moto_id))
        .done(function(r){
          if(!r) return;
          if(r.ready){
            stopPolling();
            enableFinalize(r.firma_nombre, r.acta_fecha);
          } else if(r.acta_firmada && !r.otp_verified){
            $('#pvS5StatusText').text('Firma recibida, pero el OTP no está verificado. Reintenta el paso 2.');
          }
        })
        .fail(function(){ /* transient — keep polling */ });
    }
    // First check immediately so a customer who signed before step 5 opened
    // doesn't wait the poll interval.
    checkStatus();
    _step5Poll = setInterval(checkStatus, 4000);

    $('#pvS5').on('click', function(){
      var $b = $(this).prop('disabled', true).text('Finalizando...');
      PVApp.api('entrega/finalizar.php', {moto_id:ctx.moto_id}).done(function(r){
        if(r.ok){
          stopPolling();
          PVApp.closeModal();
          PVApp.toast(r.mensaje);
          render();
        } else {
          $b.prop('disabled', false).text('Finalizar entrega');
        }
      }).fail(function(x){
        $b.prop('disabled', false).text('Finalizar entrega');
        alert((x.responseJSON && x.responseJSON.error) || 'Error al finalizar');
      });
    });
  }

  function readFiles(ids, cb){
    var out={}; var pending=ids.length;
    if(pending===0) return cb(out);
    ids.forEach(function(id){
      var f=document.getElementById(id); var file=f&&f.files&&f.files[0];
      if(!file){ if(--pending===0) cb(out); return; }
      var reader=new FileReader();
      reader.onload=function(){ out[id]=reader.result; if(--pending===0) cb(out); };
      reader.readAsDataURL(file);
    });
  }
  return { render:render };
})();
