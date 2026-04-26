/* ==========================================================================
   Voltika - Crédito: Resultado de Evaluación
   Shows credit bureau + identity verification results
   ========================================================================== */

var PasoCreditoResultado = {

    init: function(app) {
        this.app = app;
        this._evaluarResultado();

        // Customer brief 2026-04-26 v3: ALWAYS auto-advance for
        // CONDICIONAL/NO_VIABLE — including revisits via back-navigation.
        // The legacy yellow CONDICIONAL screen is now superseded entirely
        // by the unified "Tu Voltika está lista" credito-pago screen.
        // Showing the old screen on revisit confused users (customer
        // report 2026-04-26: "this screen appears after I adjusted").
        // Trade-off: browser back from credito-pago bounces forward
        // again — acceptable since credit application is complete.
        // Customer brief 2026-04-26 v5: BOTH CONDICIONAL and NO_VIABLE
        // render the same recovery screen. The only visible difference is
        // the retry CTA — present for CONDICIONAL (routes to Paso4B
        // slider), hidden for NO_VIABLE (only alt-payment cards).
        var status = (app.state._resultadoFinal && app.state._resultadoFinal.status) || '';
        this._aplicarEstadoResultado(status, app.state._resultadoFinal);

        this.render();
        this.bindEvents();
    },

    /**
     * Apply algorithm output to app state — extracted from the Continuar
     * click handler so it can run without user interaction during the
     * CONDICIONAL/NO_VIABLE auto-advance.
     */
    _aplicarEstadoResultado: function(status, resultado) {
        var s = this.app.state;
        s.creditoAprobado = (status !== 'NO_VIABLE');
        if (status === 'CONDICIONAL' || status === 'CONDICIONAL_ESTIMADO') {
            s.modoCondicional = true;
            s.engancheAjustado = false;
            s.plazoAjustado    = false;
            if (resultado && resultado.enganche_requerido_min) {
                var minEnganche = resultado.enganche_requerido_min;
                var prevPct = s.enganchePorcentaje || 0.30;
                s.enganchePorcentajeOriginal = prevPct;
                s.enganchePctMin = minEnganche;
                if (prevPct < minEnganche) {
                    s.enganchePorcentaje = minEnganche;
                    s.engancheAjustado = true;
                }
            }
            if (resultado && resultado.plazo_max_meses) {
                var maxPlazo = resultado.plazo_max_meses;
                var prevPlazo = s.plazoMeses || 36;
                s.plazoMesesOriginal = prevPlazo;
                s.plazoMesesMax = maxPlazo;
                if (prevPlazo > maxPlazo) {
                    s.plazoMeses = maxPlazo;
                    s.plazoAjustado = true;
                }
            }
        } else {
            s.modoCondicional = false;
        }
    },

    /**
     * Set _resultadoFinal — but only as a FALLBACK when the server
     * response is missing.
     *
     * Customer brief 2026-04-27: previously this function always re-ran
     * the client V3 algorithm, OVERWRITING the server's authoritative
     * response. That caused CONDICIONAL → NO_VIABLE flips when state
     * changed between server response and screen render (e.g. score
     * lost from sessionStorage, enganche_pct still at user's 25%
     * default), which left modoCondicional=false and the Paso4B slider
     * unrestricted (25%~80%/36mo) — the bug the customer reported.
     *
     * Fix: trust the server. Only re-evaluate when no server result
     * exists (direct test-mode entry without pre-computation, etc.).
     */
    _evaluarResultado: function() {
        var state  = this.app.state;
        if (state._resultadoFinal && state._resultadoFinal.status) {
            return; // server result already set — trust it
        }

        var modelo = this.app.getModelo(state.modeloSeleccionado);
        if (!modelo) return;

        var buro = state._buroResult || {};
        var credito = VkCalculadora.calcular(
            modelo.precioContado,
            state.enganchePorcentaje || 0.30,
            state.plazoMeses || 12
        );

        state._resultadoFinal = PreaprobacionV3.evaluar({
            ingreso_mensual_est:   state._ingresoMensual || 10000,
            pago_semanal_voltika:  credito.pagoSemanal,
            enganche_pct:          state.enganchePorcentaje || 0.30,
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
        var status = resultado.status || 'NO_VIABLE';
        // Customer brief 2026-04-26 v4: CONDICIONAL and NO_VIABLE share
        // the same recovery layout (no page title, no identidad/historial
        // blocks — recovery card is the primary content). PREAPROBADO
        // keeps the traditional layout with all the result panels.
        var isRecovery = (status !== 'PREAPROBADO' && status !== 'PREAPROBADO_ESTIMADO');
        var isNoViable = isRecovery; // Backward-compat alias used in some inline checks

        var backTarget = isRecovery ? 'credito-consentimiento' : 'credito-identidad';
        html += VkUI.renderBackButton(backTarget);

        // Customer brief 2026-04-26 item 1: NO_VIABLE skips the page title
        // and the HISTORIAL CREDITICIO + Identidad blocks entirely. The
        // recovery screen ("Esta vez tu plan de pagos no salió...") IS the
        // primary content; redundant headers added noise without value.
        if (!isNoViable) {
            html += '<h2 class="vk-paso__titulo">Resultado de evaluación</h2>';
        }

        html += '<div class="vk-card">';
        html += '<div style="padding:' + (isNoViable ? '20px 20px' : '24px 20px') + ';">';

        if (!isNoViable) {
            // Identity verification result (only show if Truora was performed)
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

            // Credit bureau result (only for approved/conditional flows)
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
                    'No pudimos verificar tu historial crediticio en el Buró de Crédito.</div>';
                html += '</div>';
            }
            html += '</div>';

            // Divider
            html += '<div style="border-top:2px solid var(--vk-border);margin:16px 0;"></div>';
        }

        if (status === 'PREAPROBADO' || status === 'PREAPROBADO_ESTIMADO') {
            html += this._renderAprobado(resultado);
        } else {
            // CONDICIONAL + CONDICIONAL_ESTIMADO + NO_VIABLE all use the
            // recovery layout (image #1 in customer brief 2026-04-26 v4):
            // moto card + "Ajusta tu plan y reintenta" CTA + alt-payment
            // cards. _renderNoViable handles the differences via
            // state.modoCondicional and the resultado.reasons[] array.
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
        // The retryHelps flag controls whether the "Ajusta tu plan y reintenta"
        // CTA is shown. Two reasons make a retry pointless (algorithm will
        // return NO_VIABLE no matter the new enganche/plazo) and showing the
        // button there is a UX trap that wastes 6+ steps for the user:
        //   - SIN_SCORE_CDC_NO_AUTO_APROBACION: no real score → no credit
        //   - IDENTIDAD_NO_ENCONTRADA_EN_CDC : identity not in CDC → hard KO
        //   - KO_SCORE_LT_MIN / KO_SEVERE_DPD_90PLUS / KO_GUARDRAIL_LOW_SCORE:
        //     score-based KOs that plan adjustment cannot lift
        var reasons = resultado.reasons || [];
        var subtitle = 'Revisamos tu historial y hoy no aprobamos el plan que elegiste.';
        var retryHelps = true;
        // Policy C (2026-04-26): When the algorithm returns NO_VIABLE with
        // a concrete escape value (enganche_min_para_continuar), the retry
        // button MUST be shown — raising enganche to that value will flip
        // the result to CONDICIONAL_ESTIMADO on resubmission.
        var hasEscape = (resultado.enganche_min_para_continuar !== undefined &&
                         resultado.enganche_min_para_continuar !== null);
        var escapePct = hasEscape ? Math.round(resultado.enganche_min_para_continuar * 100) : null;

        // Customer brief 2026-04-26 v4: this same recovery layout is used
        // for CONDICIONAL too. CONDICIONAL gets a tailored subtitle
        // explaining the 50%/12 requirement, retry CTA enabled (routes to
        // Paso4B locked slider).
        var status = resultado.status || '';
        var isCondicional = (status === 'CONDICIONAL' || status === 'CONDICIONAL_ESTIMADO');
        if (isCondicional) {
            var engPct = (this.app.state.enganchePctMin || 0.50) * 100;
            var plzMax = this.app.state.plazoMesesMax || 12;
            subtitle = 'Tu solicitud requiere ajustes para aprobar el crédito. Sube tu enganche al ' +
                       Math.round(engPct) + '% y reduce el plazo a ' + plzMax + ' meses.';
            retryHelps = true;
        } else if (reasons.indexOf('KO_SEVERE_DPD_90PLUS') !== -1) {
            subtitle = 'Tu historial muestra pagos atrasados graves y hoy no podemos aprobar crédito.';
            retryHelps = false;
        } else if (reasons.indexOf('KO_PTI_EXTREME') !== -1 || reasons.indexOf('PTI_EXTREMO_SIN_CIRCULO') !== -1) {
            subtitle = 'El pago semanal supera tu capacidad declarada. Ajustar enganche o plazo puede cambiar el resultado.';
            retryHelps = true;
        } else if (reasons.indexOf('IDENTIDAD_NO_ENCONTRADA_EN_CDC') !== -1) {
            subtitle = 'No pudimos confirmar tu identidad en el Buró de Crédito. Puedes pagar directamente sin crédito.';
            retryHelps = false;
        } else if (reasons.indexOf('SIN_SCORE_RECOMIENDA_AUMENTAR_ENGANCHE') !== -1) {
            // Policy C: real escape exists. Tell user EXACTLY what to do.
            subtitle = 'No obtuvimos tu historial crediticio. Sube tu enganche al ' + escapePct + '% para que tu solicitud avance — Voltika compensa la falta de score con un enganche mayor.';
            retryHelps = true;
        } else if (reasons.indexOf('SIN_SCORE_CDC_NO_AUTO_APROBACION') !== -1) {
            // Legacy reason (Option B). Kept for backward compatibility with
            // any cached/in-flight evaluations from before Policy C deploy.
            subtitle = 'No obtuvimos tu historial crediticio. Puedes pagar directamente o contactar a un asesor.';
            retryHelps = false;
        } else if (reasons.indexOf('KO_SCORE_LT_MIN') !== -1) {
            // With Policy C, a low score still has an escape: 60% enganche.
            subtitle = hasEscape
                ? ('Tu score crediticio es bajo, pero puedes avanzar subiendo tu enganche al ' + escapePct + '%.')
                : 'Tu score crediticio actual está por debajo del mínimo requerido. Puedes pagar directamente o reintentar más adelante.';
            retryHelps = hasEscape;
        } else if (reasons.indexOf('KO_GUARDRAIL_LOW_SCORE_HIGH_PTI') !== -1) {
            subtitle = 'La combinación de tu score y carga financiera no permite aprobar el plan elegido. Puedes pagar directamente o contactar a un asesor.';
            retryHelps = false;
        }

        var html = '';

        // ── Headline ──────────────────────────────────────────────────────
        // Customer brief 2026-04-26 items 2 & 3:
        //   - Title: "el crédito" → "tu plan de pagos" (rest unchanged)
        //   - Body: replace the two-paragraph explanation with one
        //     concise actionable sentence. Subtitle is appended only when
        //     the algorithm gave us a concrete escape hint (Policy C);
        //     otherwise the standard text covers all cases.
        html += '<div style="text-align:center;margin-bottom:18px;">';
        html += '<h2 style="font-size:26px;font-weight:800;color:#111;line-height:1.25;margin:0 0 12px;">'+
                'Esta vez tu plan de pagos no salió,<br>'+
                'pero <span style="color:#039fe1;">tu moto sí.</span></h2>';
        html += '<p style="font-size:14px;color:#5b6b7a;line-height:1.5;margin:0 auto;max-width:380px;">' +
                'No fue posible continuar con el plan de pagos que elegiste, elige aquí otras formas de pago o intenta nuevamente un plan de pagos nuevo aumentando tu enganche y bajando el plazo.' +
                '</p>';
        if (hasEscape) {
            html += '<p style="font-size:13px;font-weight:700;color:#039fe1;margin:10px auto 0;max-width:380px;">' +
                    'Sugerencia: sube tu enganche al ' + escapePct + '% para que tu solicitud avance automáticamente.' +
                    '</p>';
        }
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

        // Customer brief 2026-04-26 v5: retry CTA appears ONLY for
        // CONDICIONAL (and PTI-fixable scenarios). For pure REJECTED
        // (NO_VIABLE without escape), no retry button and no WhatsApp
        // button — user goes directly to the alt-payment cards below.
        // The customer is restricted to contado/MSI/SPEI/OXXO only.
        if (retryHelps) {
            var retrySubtitle = hasEscape
                ? ('Sube tu enganche al ' + escapePct + '% — al hacerlo, tu solicitud queda aprobada.')
                : 'Sube enganche o baja plazo para mejorar tu PTI.';
            html += '<button type="button" class="vk-nv-retry" id="vk-nv-retry" '+
                    'style="width:100%;display:flex;align-items:center;gap:14px;background:linear-gradient(135deg,#039fe1 0%,#0280b5 100%);color:#fff;border:0;border-radius:14px;padding:16px 18px;margin-bottom:18px;cursor:pointer;text-align:left;box-shadow:0 4px 10px rgba(3,159,225,.25);">'+
                '<span style="width:38px;height:38px;background:rgba(255,255,255,.18);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;">'+
                    '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="8" x2="20" y2="8"/><line x1="4" y1="16" x2="20" y2="16"/><circle cx="14" cy="8" r="2" fill="currentColor" stroke="currentColor"/><circle cx="8" cy="16" r="2" fill="currentColor" stroke="currentColor"/></svg>'+
                '</span>'+
                '<span style="flex:1;">'+
                    '<span style="display:block;font-size:15px;font-weight:800;line-height:1.1;">Ajusta tu plan y reintenta</span>'+
                    '<span style="display:block;font-size:12px;font-weight:500;opacity:.9;margin-top:3px;">' + retrySubtitle + '</span>'+
                '</span>'+
                '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><polyline points="9 18 15 12 9 6"/></svg>'+
                '</button>';
        }
        // No "else" branch — REJECTED users see only the alt-payment cards.

        // ── Divider ──────────────────────────────────────────────────────
        html += '<div style="display:flex;align-items:center;gap:10px;margin:14px 0 14px;">'+
                '<div style="flex:1;height:1px;background:#e5e7eb;"></div>'+
                '<span style="font-size:12.5px;color:#5b6b7a;font-weight:600;">o paga directo</span>'+
                '<div style="flex:1;height:1px;background:#e5e7eb;"></div>'+
                '</div>';

        // ── 4 payment method cards (2x2 grid) ─────────────────────────────
        // Customer brief 2026-04-26 items 4-7: replace generic SVG icons
        // with real brand logos (Visa/MC/Amex for card cards, SPEI for
        // bank transfer, OXXO for cash). Logo files live in
        // /img/tarjetas/{visa,mastercard,amex,spei,oxxo}.svg
        function payCard(id, logoHtml, title, subtitle){
            return '<button type="button" class="vk-nv-pay" id="'+id+'" style="'+
                'display:flex;align-items:center;gap:10px;width:100%;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px;cursor:pointer;text-align:left;transition:border-color .15s,box-shadow .15s;min-height:78px;">'+
                '<span style="width:54px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;">'+logoHtml+'</span>'+
                '<span style="flex:1;min-width:0;">'+
                    '<span style="display:block;font-size:13px;font-weight:700;color:#111;line-height:1.2;">'+title+'</span>'+
                    '<span style="display:block;font-size:11px;color:#5b6b7a;margin-top:3px;line-height:1.35;">'+subtitle+'</span>'+
                '</span>'+
                '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#9ca3af" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><polyline points="9 18 15 12 9 6"/></svg>'+
                '</button>';
        }

        // Card-brand stack (Visa + Mastercard + Amex) used for both credit
        // card cards. Stacked vertically so they fit the 54px-wide icon
        // column without crowding.
        var logoCards =
            '<span style="display:flex;flex-direction:column;align-items:center;gap:2px;">'+
                '<img src="'+base+'img/tarjetas/visa.svg" alt="Visa" style="height:14px;width:auto;">'+
                '<img src="'+base+'img/tarjetas/mastercard.svg" alt="Mastercard" style="height:14px;width:auto;">'+
                '<img src="'+base+'img/tarjetas/amex.svg" alt="American Express" style="height:14px;width:auto;">'+
            '</span>';
        // Customer brief 2026-04-27: use the real PNG brand logos already
        // present in /img/, not the synthetic SVGs in /img/tarjetas/ which
        // looked fake to users.
        var logoSpei = '<img src="'+base+'img/logo_spei.png" alt="SPEI" style="height:30px;width:auto;max-width:54px;object-fit:contain;">';
        var logoOxxo = '<img src="'+base+'img/oxxo_logo.png" alt="OXXO" style="height:32px;width:auto;max-width:54px;object-fit:contain;">';

        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px;">';
        // 4. 9 MSI con tarjeta — only "9 pagos de $X MXN" subtitle (per spec)
        html += payCard(
            'vk-nv-msi',
            logoCards,
            '9 MSI con tarjeta',
            '9 pagos de '+fmt(precioMSI)+' MXN'
        );
        // 5. Tarjeta en 1 solo pago — only "1 pago de $X MXN" subtitle (per spec)
        html += payCard(
            'vk-nv-tarjeta',
            logoCards,
            'Tarjeta en 1 solo pago',
            '1 pago de '+fmt(precio)+' MXN'
        );
        // 6. SPEI logo + new subtitle (per spec)
        html += payCard(
            'vk-nv-spei',
            logoSpei,
            'Transferencia SPEI',
            'Transferencia bancaria (se acredita tu compra en 24 horas automáticamente)'
        );
        // 7. OXXO logo + new subtitle (per spec)
        html += payCard(
            'vk-nv-oxxo',
            logoOxxo,
            'Efectivo en OXXO',
            'Paga en efectivo con referencia en OXXO (se acredita tu compra en 24 horas automáticamente)'
        );
        html += '</div>';

        // ── Trust badges footer ───────────────────────────────────────────
        // ── Trust badges footer — now centralized in VkUI for reuse
        //    across paso5-resumen, paso4a-checkout, etc. (customer brief
        //    2026-04-26: same footer everywhere). Single source of truth.
        html += VkUI.renderTrustFooter();

        return html;
    },

    bindEvents: function() {
        var self = this;

        jQuery(document).off('click', '#vk-resultado-continuar');
        jQuery(document).on('click', '#vk-resultado-continuar', function() {
            var resultado = self.app.state._resultadoFinal || {};
            var status    = resultado.status || '';

            self.app.state.creditoAprobado = true;

            // CONDICIONAL: store the required minimum enganche and maximum
            // plazo as RESTRICTIONS — don't silently overwrite the user's
            // choices. Customer brief 2026-04-24:
            //   "we need the user sent to the part of enganche and plazo
            //    with restrictions with more enganche and lower plazo"
            // So the user hits the pago pre-auth screen, then goes to the
            // Paso4B slider where those restrictions gate the allowed
            // values. The slider is seeded to the minimum-compliant
            // values but the user can go higher (more enganche) or
            // shorter (lower plazo) if they want.
            if (status === 'CONDICIONAL' || status === 'CONDICIONAL_ESTIMADO') {
                self.app.state.modoCondicional = true;
                self.app.state.engancheAjustado = false;
                self.app.state.plazoAjustado    = false;

                if (resultado.enganche_requerido_min) {
                    var minEnganche = resultado.enganche_requerido_min;
                    var prevPct = self.app.state.enganchePorcentaje || 0.30;
                    // Keep what the user originally selected for display.
                    self.app.state.enganchePorcentajeOriginal = prevPct;
                    // Expose the min as an explicit constraint the slider reads.
                    self.app.state.enganchePctMin = minEnganche;
                    // Seed the current value to the minimum compliant value
                    // so the pago screen shows a valid number right away.
                    if (prevPct < minEnganche) {
                        self.app.state.enganchePorcentaje = minEnganche;
                        self.app.state.engancheAjustado = true;
                    }
                }
                if (resultado.plazo_max_meses) {
                    var maxPlazo = resultado.plazo_max_meses;
                    var prevPlazo = self.app.state.plazoMeses || 36;
                    self.app.state.plazoMesesOriginal = prevPlazo;
                    self.app.state.plazoMesesMax = maxPlazo;
                    if (prevPlazo > maxPlazo) {
                        self.app.state.plazoMeses = maxPlazo;
                        self.app.state.plazoAjustado = true;
                    }
                }
            } else {
                self.app.state.modoCondicional = false;
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
            // Customer brief 2026-04-27: clear ALL CONDICIONAL state so
            // the next round (after picking new model) starts truly fresh.
            // Previously only _resultadoFinal was cleared — modoCondicional
            // and bounds persisted, leaving the next Paso4B in restricted
            // mode with stale 50%/12 even though no new evaluation ran.
            self.app.state.creditoAprobado    = false;
            self.app.state._resultadoFinal    = null;
            self.app.state.modoCondicional    = false;
            self.app.state.enganchePctMin     = null;
            self.app.state.plazoMesesMax      = null;
            self.app.state.engancheAjustado   = false;
            self.app.state.plazoAjustado      = false;
            self.app.state.enganchePorcentajeOriginal = null;
            self.app.state.plazoMesesOriginal = null;
            self.app.irAPaso(1);
        });

        // Primary CTA: adjust enganche/plazo and retry evaluation.
        // Returns to paso 4 (Paso4B for credito) which hosts the enganche +
        // plazo sliders, NOT 'credito-enganche' which is the Stripe payment
        // screen (customer report 2026-04-23: clicking "Ajusta tu plan" sent
        // the user straight to pay, skipping the adjustment they wanted).
        //
        // Policy C (2026-04-26): if the algorithm returned a concrete
        // escape target (enganche_min_para_continuar), pre-seed Paso4B with
        // those values so the user lands exactly where they need to be —
        // they can submit immediately without guessing the threshold.
        jQuery(document).off('click', '#vk-nv-retry');
        jQuery(document).on('click', '#vk-nv-retry', function() {
            var prior = self.app.state._resultadoFinal || {};
            var status = prior.status || '';
            var isCondicional = (status === 'CONDICIONAL' || status === 'CONDICIONAL_ESTIMADO');

            // Customer brief 2026-04-26 v4: CONDICIONAL retry must
            // PRESERVE state (modoCondicional, enganchePctMin,
            // plazoMesesMax) so Paso4B opens with the locked 50%/12
            // slider. Nullifying _resultadoFinal here would put Paso4B
            // in unrestricted initial-exploration mode — wrong.
            if (isCondicional) {
                self.app.state.metodoPago = 'credito';
                self.app.irAPaso(4);
                return;
            }

            // NO_VIABLE retry path (legacy Policy C escape — kept for
            // backward compat with any cached evaluations that still set
            // enganche_min_para_continuar). Set modoCondicional + bounds
            // so Paso4B opens with the slider locked at the escape value
            // (otherwise it'd render as unrestricted 25%~80% and let the
            // user drop below the minimum needed to pass).
            if (typeof prior.enganche_min_para_continuar === 'number') {
                self.app.state.enganchePorcentaje = prior.enganche_min_para_continuar;
                self.app.state.enganchePctMin    = prior.enganche_min_para_continuar;
            }
            if (typeof prior.plazo_max_para_continuar === 'number') {
                self.app.state.plazoMeses     = prior.plazo_max_para_continuar;
                self.app.state.plazoMesesMax  = prior.plazo_max_para_continuar;
            }
            self.app.state.modoCondicional = true;
            self.app.state._resultadoFinal = null;
            self.app.state.creditoAprobado = false;
            self.app.state.metodoPago = 'credito';
            self.app.irAPaso(4);
        });

        // 4 direct payment shortcuts. Each sets metodoPago + a preferred
        // sub-method hint and routes to 'resumen' (paso 5) — customer
        // brief 2026-04-26 v5: skip paso 3 (delivery) since the credit
        // applicant already chose color & delivery point earlier in the
        // flow. paso 5 shows "Confirma tu forma de pago segura" with the
        // chosen method preselected.
        jQuery(document).off('click', '#vk-nv-msi');
        jQuery(document).on('click', '#vk-nv-msi', function() {
            self.app.state.metodoPago = 'msi';
            self.app.state._preferredSubMethod = 'tarjeta';
            self.app.irAPaso('resumen');
        });

        jQuery(document).off('click', '#vk-nv-tarjeta');
        jQuery(document).on('click', '#vk-nv-tarjeta', function() {
            self.app.state.metodoPago = 'contado';
            self.app.state._preferredSubMethod = 'tarjeta';
            self.app.irAPaso('resumen');
        });

        jQuery(document).off('click', '#vk-nv-spei');
        jQuery(document).on('click', '#vk-nv-spei', function() {
            self.app.state.metodoPago = 'contado';
            self.app.state._preferredSubMethod = 'spei';
            self.app.irAPaso('resumen');
        });

        jQuery(document).off('click', '#vk-nv-oxxo');
        jQuery(document).on('click', '#vk-nv-oxxo', function() {
            self.app.state.metodoPago = 'contado';
            self.app.state._preferredSubMethod = 'oxxo';
            self.app.irAPaso('resumen');
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
