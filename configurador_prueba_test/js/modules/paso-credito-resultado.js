/* ==========================================================================
   Voltika - Crédito: Resultado de Evaluación
   Shows credit bureau + identity verification results
   ========================================================================== */

var PasoCreditoResultado = {

    init: function(app) {
        this.app = app;
        this._evaluarResultado();
        this.render();
        this.bindEvents();
    },

    /**
     * Re-run V3 pre-approval with real Círculo de Crédito data
     */
    _evaluarResultado: function() {
        var state  = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        if (!modelo) return;

        var buro = state._buroResult || {};
        var credito = VkCalculadora.calcular(
            modelo.precioContado,
            state.enganchePorcentaje || 0.30,
            state.plazoMeses || 12
        );

        // Re-evaluate with real bureau data. IMPORTANT: forward the
        // tri-state person_found signal so this re-run cannot flip a
        // server-side rejection (404.1 "identidad no encontrada") into an
        // approval. Without this, a fake identity could be rejected by
        // preaprobacion-v3.php and then silently re-approved here because
        // `score=null` alone would send it into _evaluarSinCirculo().
        state._resultadoFinal = PreaprobacionV3.evaluar({
            ingreso_mensual_est:   state._ingresoMensual || 10000,
            pago_semanal_voltika:  credito.pagoSemanal,
            score:                 buro.score || null,
            pago_mensual_buro:     buro.pagoMensual || 0,
            dpd90_flag:            buro.dpd90 || false,
            dpd_max:               buro.dpdMax || 0,
            person_found:          (buro.person_found === undefined) ? null : buro.person_found
        });
    },

    render: function() {
        var state     = this.app.state;
        var resultado = state._resultadoFinal || {};
        var buro      = state._buroResult || {};
        var truora    = state._truoraResult || {};

        var html = '';

        // If NO_VIABLE (skipped Truora), back goes to consentimiento
        var backTarget = (resultado.status === 'NO_VIABLE') ? 'credito-consentimiento' : 'credito-identidad';
        html += VkUI.renderBackButton(backTarget);

        html += '<h2 class="vk-paso__titulo">Resultado de evaluación</h2>';

        html += '<div class="vk-card">';
        html += '<div style="padding:24px 20px;">';

        // Identity verification result (only show if Truora was performed)
        if (resultado.status !== 'NO_VIABLE') {
            html += '<div style="margin-bottom:20px;">';
            html += '<div style="font-size:13px;font-weight:700;color:var(--vk-text-secondary);' +
                'text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Identidad</div>';

            var idStatus = (truora && truora.status === 'approved') || truora.fallback;
            html += '<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;' +
                'border-radius:8px;background:' + (idStatus ? 'var(--vk-green-soft)' : '#FFF3E0') + ';">';
            html += '<span style="font-size:24px;">' + (idStatus ? '&#9989;' : '&#9888;') + '</span>';
            html += '<div>';
            html += '<div style="font-weight:700;font-size:14px;">' +
                (idStatus ? 'Identidad verificada' : 'Verificación pendiente') + '</div>';
            html += '<div style="font-size:12px;color:var(--vk-text-secondary);">' +
                (idStatus ? 'Tu INE y selfie coinciden correctamente' : 'Se requiere revisión adicional por un asesor') +
                '</div>';
            html += '</div>';
            html += '</div>';
            html += '</div>';
        }

        // Credit bureau result
        html += '<div style="margin-bottom:20px;">';
        html += '<div style="font-size:13px;font-weight:700;color:var(--vk-text-secondary);' +
            'text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Historial crediticio</div>';

        var score = buro.score || null;
        var hasScore = score !== null && score !== undefined;

        if (hasScore) {
            html += '<div style="text-align:center;padding:14px;background:var(--vk-bg-light);border-radius:8px;margin-bottom:12px;">';
            html += '<div style="font-size:12px;color:var(--vk-text-secondary);">Score crediticio</div>';
            html += '<div style="font-size:36px;font-weight:800;color:' + this._scoreColor(score) + ';">' + score + '</div>';
            html += '<div style="font-size:11px;color:var(--vk-text-muted);">de 850 puntos posibles</div>';
            html += '</div>';
        } else {
            html += '<div style="text-align:center;padding:14px;background:var(--vk-bg-light);border-radius:8px;margin-bottom:12px;">';
            html += '<div style="font-size:13px;color:var(--vk-text-secondary);">' +
                'No se pudo obtener tu score. Se realizará una evaluación estimada.</div>';
            html += '</div>';
        }
        html += '</div>';

        // Divider
        html += '<div style="border-top:2px solid var(--vk-border);margin:16px 0;"></div>';

        // Final pre-approval result. Fail-closed: if resultado is somehow
        // missing status (edge case where an early return bypassed scoring),
        // render NO_VIABLE instead of silently approving. The previous
        // default 'CONDICIONAL_ESTIMADO' is what let fake-identity flows
        // land on an approval-looking screen.
        var status = resultado.status || 'NO_VIABLE';

        if (status === 'PREAPROBADO' || status === 'PREAPROBADO_ESTIMADO') {
            html += this._renderAprobado(resultado);
        } else if (status === 'CONDICIONAL' || status === 'CONDICIONAL_ESTIMADO') {
            html += this._renderCondicional(resultado);
        } else {
            html += this._renderNoViable(resultado);
        }

        html += '</div>'; // end padding
        html += '</div>'; // end card

        jQuery('#vk-credito-resultado-container').html(html);
    },

    _scoreColor: function(score) {
        if (score >= 480) return 'var(--vk-green-primary)';
        if (score >= 420) return '#F9A825';
        return '#C62828';
    },

    _renderAprobado: function(resultado) {
        var state  = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        var credito = VkCalculadora.calcular(
            modelo.precioContado,
            state.enganchePorcentaje || 0.30,
            state.plazoMeses || 12
        );

        var html = '';
        html += '<div style="background:var(--vk-green-soft);border-radius:10px;padding:20px;text-align:center;margin-bottom:16px;">';
        html += '<div style="font-size:40px;margin-bottom:8px;">&#10004;</div>';
        html += '<div style="font-size:20px;font-weight:800;color:var(--vk-green-primary);">¡Pre-aprobado!</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-top:4px;">' +
            'Cumples con los requisitos para Crédito Voltika</div>';
        html += '</div>';

        // Summary
        html += '<div style="background:var(--vk-bg-light);border-radius:8px;padding:14px;margin-bottom:16px;">';
        html += '<div style="display:flex;justify-content:space-between;margin-bottom:8px;">';
        html += '<span style="font-size:13px;color:var(--vk-text-secondary);">Enganche</span>';
        html += '<span style="font-size:14px;font-weight:700;">' +
            VkUI.formatPrecio(credito.enganche) + ' MXN</span>';
        html += '</div>';
        html += '<div style="display:flex;justify-content:space-between;margin-bottom:8px;">';
        html += '<span style="font-size:13px;color:var(--vk-text-secondary);">Plazo</span>';
        html += '<span style="font-size:14px;font-weight:700;">' +
            (state.plazoMeses || 12) + ' meses</span>';
        html += '</div>';
        html += '<div style="display:flex;justify-content:space-between;">';
        html += '<span style="font-size:13px;color:var(--vk-text-secondary);">Pago semanal</span>';
        html += '<span style="font-size:16px;font-weight:800;color:var(--vk-green-primary);">' +
            VkUI.formatPrecio(credito.pagoSemanal) + '</span>';
        html += '</div>';
        html += '</div>';

        html += '<button class="vk-btn vk-btn--primary" id="vk-resultado-continuar">' +
            'Continuar &#9654;</button>';
        html += '<p style="text-align:center;font-size:11px;color:var(--vk-text-muted);margin-top:8px;">' +
            'Siguiente paso: confirmar entrega y pagar enganche.</p>';

        return html;
    },

    _renderCondicional: function(resultado) {
        var html = '';
        html += '<div style="background:#FFF8E1;border:1px solid #FFD54F;border-radius:10px;padding:20px;text-align:center;margin-bottom:16px;">';
        html += '<div style="font-size:40px;margin-bottom:8px;">&#9888;</div>';
        html += '<div style="font-size:20px;font-weight:800;color:#F9A825;">Aprobación condicional</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-top:4px;">' +
            'Puedes calificar ajustando el enganche y/o plazo</div>';
        html += '</div>';

        if (resultado.enganche_requerido_min) {
            html += '<div style="background:var(--vk-bg-light);border-radius:8px;padding:12px;margin-bottom:12px;font-size:13px;">';
            html += '<strong>Enganche mínimo requerido:</strong> ' +
                Math.round(resultado.enganche_requerido_min * 100) + '%';
            html += '</div>';
        }
        if (resultado.plazo_max_meses) {
            html += '<div style="background:var(--vk-bg-light);border-radius:8px;padding:12px;margin-bottom:12px;font-size:13px;">';
            html += '<strong>Plazo máximo:</strong> ' + resultado.plazo_max_meses + ' meses';
            html += '</div>';
        }

        html += '<div style="background:#FFF8E1;border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px;color:#5D4037;">';
        html += '&#9888; Al continuar, tu enganche y plazo ser\u00e1n ajustados autom\u00e1ticamente a los m\u00ednimos requeridos.';
        html += '</div>';

        html += '<button class="vk-btn vk-btn--primary" id="vk-resultado-continuar">' +
            'Continuar con cr\u00e9dito condicional</button>';

        html += '<div style="text-align:center;margin-top:12px;">';
        html += '<button class="vk-btn vk-btn--secondary" id="vk-resultado-contado" ' +
            'style="font-size:13px;">Pagar con tarjeta (Stripe)</button>';
        html += '</div>';

        return html;
    },

    _renderNoViable: function(resultado) {
        // Redesigned 2026-04-23 per customer design brief:
        //   - Encouraging copy ("pero tu moto sí") instead of generic rejection
        //   - Motorcycle card with change-model escape hatch
        //   - Primary CTA returns to the slider so user can retry with
        //     different enganche / plazo
        //   - 4 direct payment alternatives as cards (not buried in a submenu)
        //   - Trust badges footer for payment-security reassurance
        //   - (WhatsApp advisor box intentionally omitted per customer request)

        var state  = this.app.state || {};
        var modelo = this.app.getModelo(state.modeloSeleccionado) || {};
        var base   = window.VK_BASE_PATH || '';

        var colorId      = state.colorSeleccionado || modelo.colorDefault || '';
        var colorObj     = null;
        if (modelo.colores) {
            for (var ci = 0; ci < modelo.colores.length; ci++) {
                if (modelo.colores[ci].id === colorId) { colorObj = modelo.colores[ci]; break; }
            }
        }
        var colorNombre = colorObj ? colorObj.nombre : (colorId || '');

        // Resolve moto image — try color-specific side, then fallback to model.png
        var slug = (modelo.id || '').toLowerCase();
        var colorFileMap = { negro:'black', gris:'grey', plata:'silver', verde:'green', azul:'blue', rojo:'red', blanco:'white', naranja:'orange' };
        var colorFile = colorFileMap[(colorId || '').toLowerCase()];
        var imgPath = base + 'img/' + slug + '/' + (colorFile ? colorFile + '_side.png' : 'model.png');

        var precio    = modelo.precioContado || 0;
        var precioMSI = Math.round(precio / 9);

        function fmt(n){
            return '$' + Number(n||0).toLocaleString('es-MX', {minimumFractionDigits:0, maximumFractionDigits:0});
        }
        function esc(s){
            return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        }

        // Subtitle explaining WHY — keeps the copy honest without being harsh.
        var reasons = resultado.reasons || [];
        var subtitle = 'Revisamos tu historial y hoy no aprobamos el plan que elegiste.';
        if (reasons.indexOf('KO_SEVERE_DPD_90PLUS') !== -1) {
            subtitle = 'Tu historial muestra pagos atrasados graves y hoy no podemos aprobar crédito.';
        } else if (reasons.indexOf('KO_PTI_EXTREME') !== -1 || reasons.indexOf('PTI_EXTREMO_SIN_CIRCULO') !== -1) {
            subtitle = 'El pago semanal supera tu capacidad declarada. Ajustar enganche o plazo puede cambiar el resultado.';
        } else if (reasons.indexOf('IDENTIDAD_NO_ENCONTRADA_EN_CDC') !== -1) {
            subtitle = 'No pudimos confirmar tu identidad en el Buró de Crédito. Puedes pagar directamente sin crédito.';
        }

        var html = '';

        // ── Headline ──────────────────────────────────────────────────────
        html += '<div style="text-align:center;margin-bottom:18px;">';
        html += '<h2 style="font-size:26px;font-weight:800;color:#111;line-height:1.25;margin:0 0 10px;">'+
                'Esta vez el crédito no salió,<br>'+
                'pero <span style="color:#039fe1;">tu moto sí.</span></h2>';
        html += '<p style="font-size:14px;color:#5b6b7a;line-height:1.5;margin:0 0 6px;max-width:380px;margin-left:auto;margin-right:auto;">' +
                subtitle + '</p>';
        html += '<p style="font-size:13px;color:#5b6b7a;margin:6px 0 0;">Esto pasa y no te define. Elige cómo llevarte tu Voltika hoy:</p>';
        html += '</div>';

        // ── Motorcycle card ───────────────────────────────────────────────
        html += '<div class="vk-nv-moto-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px;margin-bottom:14px;box-shadow:0 1px 3px rgba(0,0,0,.04);">';
        html += '<div style="display:flex;align-items:center;gap:12px;">';
        html += '<div style="width:96px;height:72px;flex-shrink:0;background:linear-gradient(180deg,#ffffff 0%,#f3f6fb 100%);border-radius:10px;display:flex;align-items:center;justify-content:center;overflow:hidden;">';
        html += '<img src="'+esc(imgPath)+'" alt="'+esc(modelo.nombre||'')+'" '+
                'onerror="this.onerror=null;this.src=\''+esc(base+'img/'+slug+'/model.png')+'\';" '+
                'style="max-width:92%;max-height:92%;object-fit:contain;filter:drop-shadow(0 3px 6px rgba(12,35,64,.14));">';
        html += '</div>';
        html += '<div style="flex:1;min-width:0;">';
        html += '<div style="font-size:15px;font-weight:800;color:#111;line-height:1.2;">VOLTIKA ' + esc(modelo.nombre||'') + (colorNombre ? ' · <span style="font-weight:600;color:#5b6b7a;">'+esc(colorNombre)+'</span>':'') + '</div>';
        html += '<div style="font-size:12px;color:#5b6b7a;margin-top:3px;">Precio público:</div>';
        html += '<div style="font-size:22px;font-weight:900;color:#111;line-height:1.1;">' + fmt(precio) + '</div>';
        html += '</div>';
        html += '<button type="button" class="vk-nv-cambiar" id="vk-nv-cambiar" '+
                'style="flex-shrink:0;display:inline-flex;align-items:center;gap:5px;background:#fff;color:#039fe1;border:1.5px solid #039fe1;border-radius:999px;padding:7px 14px;font-size:12.5px;font-weight:700;cursor:pointer;">'+
                '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>'+
                'Cambiar</button>';
        html += '</div></div>';

        // ── Primary CTA: retry with different plan ────────────────────────
        html += '<button type="button" class="vk-nv-retry" id="vk-nv-retry" '+
                'style="width:100%;display:flex;align-items:center;gap:14px;background:linear-gradient(135deg,#039fe1 0%,#0280b5 100%);color:#fff;border:0;border-radius:14px;padding:16px 18px;margin-bottom:18px;cursor:pointer;text-align:left;box-shadow:0 4px 10px rgba(3,159,225,.25);">'+
            '<span style="width:38px;height:38px;background:rgba(255,255,255,.18);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;">'+
                '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="8" x2="20" y2="8"/><line x1="4" y1="16" x2="20" y2="16"/><circle cx="14" cy="8" r="2" fill="currentColor" stroke="currentColor"/><circle cx="8" cy="16" r="2" fill="currentColor" stroke="currentColor"/></svg>'+
            '</span>'+
            '<span style="flex:1;">'+
                '<span style="display:block;font-size:15px;font-weight:800;line-height:1.1;">Ajusta tu plan y reintenta</span>'+
                '<span style="display:block;font-size:12px;font-weight:500;opacity:.9;margin-top:3px;">Sube enganche o baja plazo. La mayoría aprueba al segundo intento.</span>'+
            '</span>'+
            '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><polyline points="9 18 15 12 9 6"/></svg>'+
            '</button>';

        // ── Divider ──────────────────────────────────────────────────────
        html += '<div style="display:flex;align-items:center;gap:10px;margin:14px 0 14px;">'+
                '<div style="flex:1;height:1px;background:#e5e7eb;"></div>'+
                '<span style="font-size:12.5px;color:#5b6b7a;font-weight:600;">o paga directo</span>'+
                '<div style="flex:1;height:1px;background:#e5e7eb;"></div>'+
                '</div>';

        // ── 4 payment method cards (2x2 grid) ─────────────────────────────
        function payCard(id, iconHtml, iconBg, title, subtitle){
            return '<button type="button" class="vk-nv-pay" id="'+id+'" style="'+
                'display:flex;align-items:center;gap:10px;width:100%;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px;cursor:pointer;text-align:left;transition:border-color .15s,box-shadow .15s;">'+
                '<span style="width:40px;height:40px;flex-shrink:0;border-radius:50%;background:'+iconBg+';display:inline-flex;align-items:center;justify-content:center;color:#fff;">'+iconHtml+'</span>'+
                '<span style="flex:1;min-width:0;">'+
                    '<span style="display:block;font-size:13.5px;font-weight:700;color:#111;line-height:1.15;">'+title+'</span>'+
                    '<span style="display:block;font-size:11.5px;color:#5b6b7a;margin-top:2px;line-height:1.3;">'+subtitle+'</span>'+
                '</span>'+
                '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#9ca3af" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><polyline points="9 18 15 12 9 6"/></svg>'+
                '</button>';
        }

        var iconCard = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>';
        var iconBank = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 3 22 9 2 9 12 3"/><line x1="5" y1="12" x2="5" y2="18"/><line x1="10" y1="12" x2="10" y2="18"/><line x1="14" y1="12" x2="14" y2="18"/><line x1="19" y1="12" x2="19" y2="18"/><line x1="2" y1="21" x2="22" y2="21"/></svg>';
        var iconCash = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><line x1="6" y1="12" x2="6.01" y2="12"/><line x1="18" y1="12" x2="18.01" y2="12"/></svg>';

        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px;">';
        html += payCard(
            'vk-nv-msi',
            iconCard,
            'linear-gradient(135deg,#3b82f6,#1d4ed8)',
            '9 MSI con tarjeta',
            '9 pagos de '+fmt(precioMSI)+'<br>sin intereses'
        );
        html += payCard(
            'vk-nv-tarjeta',
            iconCard,
            'linear-gradient(135deg,#10b981,#047857)',
            'Tarjeta en 1 solo pago',
            fmt(precio)+' · se aparta<br>al instante'
        );
        html += payCard(
            'vk-nv-spei',
            iconBank,
            'linear-gradient(135deg,#6366f1,#4338ca)',
            'Transferencia SPEI',
            fmt(precio)+' · confirmación<br>en 15 min'
        );
        html += payCard(
            'vk-nv-oxxo',
            iconCash,
            'linear-gradient(135deg,#f97316,#c2410c)',
            'Efectivo en OXXO',
            fmt(precio)+' · paga<br>en 48 horas'
        );
        html += '</div>';

        // ── Trust badges footer ───────────────────────────────────────────
        html += '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px;">';
        html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">'+
                '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#039fe1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>'+
                '<span style="font-size:12.5px;font-weight:700;color:#111;">Pagos seguros con</span>'+
                '</div>';
        html += '<div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:10px;">'+
                '<span style="font-size:16px;font-weight:700;color:#635bff;letter-spacing:-.5px;">stripe</span>'+
                '<span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;color:#111;"><span style="width:16px;height:16px;background:#c62828;border-radius:50%;display:inline-block;"></span>SAT</span>'+
                '<span style="font-size:12px;font-weight:700;color:#0284c7;letter-spacing:.5px;">REPUVE</span>'+
                '<span style="font-size:13px;font-weight:800;color:#0d9488;letter-spacing:-.3px;">◆ CINCEL</span>'+
                '</div>';
        html += '<div style="border-top:1px solid #f3f4f6;padding-top:10px;display:flex;align-items:center;justify-content:center;gap:10px;">'+
                '<span style="font-size:12px;color:#5b6b7a;">Convenio con</span>'+
                '<img src="'+base+'img/qualitas-logo.png" alt="Qualitas Seguros" style="height:22px;" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'inline\'">'+
                '<span style="display:none;font-size:13px;font-weight:800;color:#0d47a1;">QUÁLITAS SEGUROS</span>'+
                '</div>';
        html += '</div>';

        return html;
    },

    bindEvents: function() {
        var self = this;

        jQuery(document).off('click', '#vk-resultado-continuar');
        jQuery(document).on('click', '#vk-resultado-continuar', function() {
            var resultado = self.app.state._resultadoFinal || {};
            var status    = resultado.status || '';

            self.app.state.creditoAprobado = true;

            // CONDICIONAL: enforce required minimum enganche and maximum plazo.
            // Stash the user's originally selected values so the pago screen
            // can show "adjusted: 30% → 40%" and the customer understands WHY
            // the on-screen amount differs from what they picked earlier.
            if (status === 'CONDICIONAL' || status === 'CONDICIONAL_ESTIMADO') {
                self.app.state.engancheAjustado = false;
                self.app.state.plazoAjustado    = false;

                if (resultado.enganche_requerido_min) {
                    var minEnganche = resultado.enganche_requerido_min;
                    var prevPct = self.app.state.enganchePorcentaje || 0.30;
                    if (prevPct < minEnganche) {
                        self.app.state.enganchePorcentajeOriginal = prevPct;
                        self.app.state.enganchePorcentaje = minEnganche;
                        self.app.state.engancheAjustado = true;
                    }
                }
                if (resultado.plazo_max_meses) {
                    var maxPlazo = resultado.plazo_max_meses;
                    var prevPlazo = self.app.state.plazoMeses || 36;
                    if (prevPlazo > maxPlazo) {
                        self.app.state.plazoMesesOriginal = prevPlazo;
                        self.app.state.plazoMeses = maxPlazo;
                        self.app.state.plazoAjustado = true;
                    }
                }
            }

            self.app.irAPaso('credito-pago'); // Confirmation screen before Stripe payment
        });

        jQuery(document).off('click', '#vk-resultado-contado');
        jQuery(document).on('click', '#vk-resultado-contado', function() {
            self.app.state.metodoPago = 'contado';
            self.app.irAPaso(3);
        });

        jQuery(document).off('click', '#vk-resultado-msi');
        jQuery(document).on('click', '#vk-resultado-msi', function() {
            self.app.state.metodoPago = 'msi';
            self.app.irAPaso(3);
        });

        // ── New "No viable" screen handlers ───────────────────────────────
        // Moto "Cambiar" button: back to model selector. Clear credit state
        // so the next attempt starts clean.
        jQuery(document).off('click', '#vk-nv-cambiar');
        jQuery(document).on('click', '#vk-nv-cambiar', function() {
            self.app.state.creditoAprobado = false;
            self.app.state._resultadoFinal = null;
            self.app.irAPaso(1);
        });

        // Primary CTA: adjust enganche/plazo and retry evaluation.
        // Returns to paso 4 (Paso4B slider), NOT 'credito-enganche' (Stripe).
        // Customer report 2026-04-23: button sent user straight to pay,
        // skipping the adjustment they wanted.
        jQuery(document).off('click', '#vk-nv-retry');
        jQuery(document).on('click', '#vk-nv-retry', function() {
            self.app.state._resultadoFinal = null;
            self.app.state.creditoAprobado = false;
            self.app.state.metodoPago = 'credito';
            self.app.irAPaso(4);
        });

        // 4 direct payment shortcuts. Each sets metodoPago + a preferred
        // sub-method hint so paso4a-checkout auto-opens the right section.
        jQuery(document).off('click', '#vk-nv-msi');
        jQuery(document).on('click', '#vk-nv-msi', function() {
            self.app.state.metodoPago = 'msi';
            self.app.state._preferredSubMethod = 'tarjeta';
            self.app.irAPaso(3);
        });

        jQuery(document).off('click', '#vk-nv-tarjeta');
        jQuery(document).on('click', '#vk-nv-tarjeta', function() {
            self.app.state.metodoPago = 'contado';
            self.app.state._preferredSubMethod = 'tarjeta';
            self.app.irAPaso(3);
        });

        jQuery(document).off('click', '#vk-nv-spei');
        jQuery(document).on('click', '#vk-nv-spei', function() {
            self.app.state.metodoPago = 'contado';
            self.app.state._preferredSubMethod = 'spei';
            self.app.irAPaso(3);
        });

        jQuery(document).off('click', '#vk-nv-oxxo');
        jQuery(document).on('click', '#vk-nv-oxxo', function() {
            self.app.state.metodoPago = 'contado';
            self.app.state._preferredSubMethod = 'oxxo';
            self.app.irAPaso(3);
        });

        // Hover feedback for payment cards
        jQuery(document).off('mouseenter', '.vk-nv-pay').on('mouseenter', '.vk-nv-pay', function(){
            jQuery(this).css({'border-color':'#039fe1','box-shadow':'0 2px 8px rgba(3,159,225,.15)'});
        });
        jQuery(document).off('mouseleave', '.vk-nv-pay').on('mouseleave', '.vk-nv-pay', function(){
            jQuery(this).css({'border-color':'#e5e7eb','box-shadow':''});
        });
    }
};
