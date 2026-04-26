/* ==========================================================================
   Voltika - PASO 4B: Credito Voltika — Pre-aprobacion V3
   Implementa algoritmo V3 (VOLTIKA_Preaprobacion_V3_Guia_Programador.docx)
   Flujo: ingreso + enganche/plazo → PTI → PREAPROBADO / CONDICIONAL / NO_VIABLE
   ========================================================================== */

// ── Rangos de ingreso mensual (mínimo del rango = conservador) ────────────────
var RANGOS_INGRESO = [
    { label: 'Menos de $6,000',          valor: 5000  },
    { label: '$6,000 – $9,999',           valor: 6000  },
    { label: '$10,000 – $14,999',         valor: 10000 },
    { label: '$15,000 – $19,999',         valor: 15000 },
    { label: '$20,000 – $29,999',         valor: 20000 },
    { label: '$30,000 o mas',             valor: 30000 }
];

// ── Algoritmo V3 — config-driven (ajustar umbrales aquí sin tocar lógica) ─────
var PreaprobacionV3 = {

    config: {
        downPaymentMin: 0.25,
        KO: {
            scoreMin:   420,
            ptiExtreme: 1.05,
            severeDPD:  true
        },
        PRE: {
            scoreMin: 480,
            ptiMax:   0.90
        },
        CONDITIONAL: {
            downPaymentRequiredByPTI: [
                { maxPTI: 0.90, required: 0.25 },
                { maxPTI: 0.95, required: 0.30 },
                { maxPTI: 1.00, required: 0.35 },
                { maxPTI: 1.05, required: 0.45 }
            ],
            maxTermByRisk: [
                { minScore: 520, maxPTI: 0.85, term: 36 },
                { minScore: 480, maxPTI: 0.90, term: 24 },
                { minScore: 440, maxPTI: 0.95, term: 18 },
                { minScore: 420, maxPTI: 1.05, term: 18 }
            ],
            lowScorePTIGuardrail: { scoreMax: 439, ptiMax: 0.95 }
        }
    },

    evaluar: function(inputs) {
        var ing    = inputs.ingreso_mensual_est;
        var psv    = inputs.pago_semanal_voltika;
        var score  = inputs.score;
        var buro   = inputs.pago_mensual_buro || 0;
        var dpd90  = inputs.dpd90_flag;
        var dpdMax = inputs.dpd_max;
        // enganche_pct enables Policy C: high-enganche fallback for
        // null-score and low-score applicants. Defaults to 0.25 if not
        // provided so legacy callers still get sensible behavior (no
        // accidental approval).
        var engPct = (typeof inputs.enganche_pct === 'number') ? inputs.enganche_pct : 0.25;
        // Tri-state identity flag from consultar-buro.php:
        //   true  → CDC confirmed the persona exists (approval flow continues)
        //   false → CDC returned 404.1 "no existe"  → hard REJECT
        //   undefined/null → CDC unreachable or not consulted → self-score OK
        var personFound = (inputs.person_found === undefined) ? null : inputs.person_found;
        var cfg    = this.config;

        if (!isFinite(ing) || ing <= 0 || !isFinite(psv) || psv <= 0) {
            return { status: 'ERROR', mensaje: 'Datos insuficientes para evaluar.' };
        }

        var pmv  = psv * 4.3333;
        var pti  = (buro + pmv) / ing;
        var mora = dpd90 === true || (dpdMax != null && dpdMax >= 90);

        // HARD KO — CDC explicitly says the persona does NOT exist. Reject
        // before any scoring runs. Customer report 2026-04-23: fake
        // identities were passing through self-scoring when `score=null`.
        if (personFound === false) {
            return {
                status:    'NO_VIABLE',
                pti:       pti,
                reasons:   ['IDENTIDAD_NO_ENCONTRADA_EN_CDC'],
                mensaje:   'La persona no aparece en el Buró de Crédito. No es posible otorgar crédito a identidades que no se pueden verificar.'
            };
        }

        // Sin datos de Círculo → Policy C: high-enganche fallback
        if (score === null || score === undefined) {
            return this._evaluarSinCirculo(pti, engPct, personFound);
        }

        var s = Number(score);

        // 1) KO reales → NO_VIABLE
        // Customer brief 2026-04-26 v2: removed the 60%-enganche escape.
        // Low score = REJECTED, route to alternative payment options.
        if (s < cfg.KO.scoreMin) {
            return {
                status: 'NO_VIABLE',
                pti: pti,
                enganche_min: cfg.downPaymentMin,
                reasons:      ['KO_SCORE_LT_MIN']
            };
        }
        if (cfg.KO.severeDPD && mora) {
            return { status: 'NO_VIABLE', pti: pti, enganche_min: cfg.downPaymentMin, reasons: ['KO_SEVERE_DPD_90PLUS'] };
        }
        if (pti > cfg.KO.ptiExtreme) {
            return { status: 'NO_VIABLE', pti: pti, enganche_min: cfg.downPaymentMin, reasons: ['KO_PTI_EXTREME'] };
        }

        // 2) Guardrail: score bajo + PTI alto → NO_VIABLE
        if (s <= cfg.CONDITIONAL.lowScorePTIGuardrail.scoreMax && pti > cfg.CONDITIONAL.lowScorePTIGuardrail.ptiMax) {
            return { status: 'NO_VIABLE', pti: pti, enganche_min: cfg.downPaymentMin, reasons: ['KO_GUARDRAIL_LOW_SCORE_HIGH_PTI'] };
        }

        // 3) PREAPROBADO
        if (s >= cfg.PRE.scoreMin && pti <= cfg.PRE.ptiMax) {
            return {
                status: 'PREAPROBADO',
                pti: pti,
                enganche_min:          cfg.downPaymentMin,
                enganche_requerido_min: cfg.downPaymentMin,
                plazo_max_meses:       this._plazoMax(s, pti)
            };
        }

        // 4) CONDICIONAL — single hard threshold (customer brief 2026-04-26 v2):
        //    50% enganche, 12-month max plazo for ALL conditional applicants.
        //    Replaces the PTI-tiered table for consistency and simpler messaging.
        return {
            status: 'CONDICIONAL',
            pti: pti,
            enganche_min:           cfg.downPaymentMin,
            enganche_requerido_min: 0.50,
            plazo_max_meses:        12
        };
    },

    // Sin Círculo: Policy C (customer brief 2026-04-26). High enganche
    // (≥50%) lets the applicant pass with a CONDICIONAL_ESTIMADO status —
    // Voltika's risk is covered by the upfront amount + asset repossession
    // rights + mandatory Truora identity verification downstream. Below
    // the threshold, NO_VIABLE but with a clear path forward (raise to
    // 50%) instead of a dead end.
    _evaluarSinCirculo: function(pti, engPct, personFound) {
        var threshold = 0.50;
        var plazoMax  = 12;
        if (typeof engPct === 'number' && engPct >= threshold) {
            return {
                status:                 'CONDICIONAL_ESTIMADO',
                pti:                    pti,
                enganche_min:           threshold,
                enganche_requerido_min: threshold,
                plazo_max_meses:        plazoMax,
                reasons:                ['SIN_SCORE_APROBADO_POR_ENGANCHE_ALTO'],
                mensaje:                'Aprobación condicional por enganche elevado. Verificación de identidad obligatoria.',
                person_found:           personFound
            };
        }
        return {
            status:                       'NO_VIABLE',
            pti:                          pti,
            reasons:                      ['SIN_SCORE_RECOMIENDA_AUMENTAR_ENGANCHE'],
            mensaje:                      'No obtuvimos tu historial crediticio. Sube tu enganche al ' + Math.round(threshold * 100) + '% para continuar tu solicitud.',
            enganche_min_para_continuar:  threshold,
            plazo_max_para_continuar:     plazoMax,
            person_found:                 personFound
        };
    },

    // Enganche mínimo requerido por tabla PTI
    _engancheMin: function(pti) {
        var table = this.config.CONDITIONAL.downPaymentRequiredByPTI;
        for (var i = 0; i < table.length; i++) {
            if (pti <= table[i].maxPTI) return table[i].required;
        }
        return table[table.length - 1].required;
    },

    // Plazo máximo por score + PTI
    _plazoMax: function(score, pti) {
        var rules = this.config.CONDITIONAL.maxTermByRisk;
        for (var i = 0; i < rules.length; i++) {
            if (score >= rules[i].minScore && pti <= rules[i].maxPTI) return rules[i].term;
        }
        return 18;
    }
};

// ── Módulo PASO 4B ────────────────────────────────────────────────────────────
var Paso4B = {

    init: function(app) {
        this.app = app;
        var s = app.state || {};

        // Dual-mode init:
        //   - INITIAL configurator visit → default to 25% / 36 months.
        //   - CONDICIONAL post-evaluation (customer brief 2026-04-24) →
        //     seed with the min-compliant values set by
        //     paso-credito-resultado.js so the slider starts in a valid
        //     position. Restrictions (min enganche, max plazo) are read
        //     from state.enganchePctMin / state.plazoMesesMax during
        //     render() and bindEvents().
        if (s.modoCondicional) {
            // Seed from current state, then clamp to algorithm-authorized
            // bounds. Without clamping, a user who set 25%/36 months during
            // the initial (unrestricted) Paso4B visit would land here with
            // values that violate the CONDICIONAL algorithm output (e.g.
            // enganchePctMin=0.40, plazoMesesMax=24) and the UI buttons for
            // the old plazo would be absent, leaving no selected plazo.
            var engSeed = (typeof s.enganchePorcentaje === 'number')
                ? s.enganchePorcentaje
                : (s.enganchePctMin || 0.40);
            var plazoSeed = (typeof s.plazoMeses === 'number')
                ? s.plazoMeses
                : (s.plazoMesesMax || 24);
            if (s.enganchePctMin && engSeed < s.enganchePctMin) engSeed = s.enganchePctMin;
            if (s.plazoMesesMax  && plazoSeed > s.plazoMesesMax) plazoSeed = s.plazoMesesMax;
            this._enganchePct = engSeed;
            this._plazoMeses  = plazoSeed;
        } else {
            this._enganchePct = 0.25;
            this._plazoMeses  = 36;
        }
        this._ingresoVal  = null;
        this._resultadoV3 = null;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var state  = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        if (!modelo) return;

        var credito = this._calcularCredito(modelo);
        var img     = VkUI.getImagenMoto(modelo.id, state.colorSeleccionado || modelo.colorDefault);

        // CONDICIONAL mode limits exposed from paso-credito-resultado.js.
        var cond      = !!state.modoCondicional;
        var engPctMin = cond && state.enganchePctMin  ? state.enganchePctMin  : 0.25;
        var plazoMax  = cond && state.plazoMesesMax   ? state.plazoMesesMax   : 36;

        var html = '';

        // Back button — credit-adjust mode should not let the user rewind
        // to the color picker; they've already completed the whole
        // application. Go back to the pre-authorization screen instead.
        html += VkUI.renderBackButton(cond ? 'credito-pago' : 2);

        // ── Title ───────────────────────────────────────────────────────────
        html += '<div style="text-align:left;margin-bottom:16px;">';
        if (cond) {
            html += '<div style="font-size:22px;font-weight:800;">Ajusta tu plan dentro de las condiciones aprobadas</div>';
        } else {
            html += '<div style="font-size:22px;font-weight:800;">Arma tu plan Voltika</div>';
        }
        html += '</div>';

        // Constraint banner — explains the restrictions in plain language
        if (cond) {
            html += '<div style="background:#FFF3E0;border:1.5px solid #FB8C00;border-radius:10px;padding:14px 16px;margin-bottom:16px;">';
            html += '<div style="font-weight:700;color:#E65100;margin-bottom:6px;font-size:14px;">⚠ Condiciones de tu aprobación</div>';
            html += '<div style="font-size:13px;color:#5d4037;line-height:1.5;">';
            html += 'Tu evaluación crediticia requiere al menos <strong>' + Math.round(engPctMin * 100) + '% de enganche</strong>';
            html += ' y un plazo máximo de <strong>' + plazoMax + ' meses</strong>. ';
            html += 'Puedes subir más el enganche o bajar el plazo si quieres.';
            html += '</div>';
            html += '</div>';
        }

        // ── Model summary (compact) ─────────────────────────────────────────
        html += '<div style="background:#fff;border:1.5px solid var(--vk-border);border-radius:10px;padding:14px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;">';
        html += '<div>';
        html += '<div style="font-size:16px;font-weight:800;">' + Paso1._getModeloLogo(modelo.id, modelo.nombre) + '</div>';
        html += '</div>';
        html += '<img src="' + img + '" alt="' + modelo.nombre + '" style="height:60px;width:auto;object-fit:contain;">';
        html += '</div>';

        // ── Enganche slider ────────────────────────────────────────────────
        html += '<div class="vk-card" style="padding:20px;">';

        html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;flex-wrap:nowrap;">' +
            '<span style="font-size:15px;font-weight:700;color:var(--vk-text-primary);">Elige tu Enganche</span>' +
            '<span style="font-size:14px;font-weight:700;white-space:nowrap;margin-left:8px;">' +
            '<span id="vk-enganche-pct-display">' + Math.round(this._enganchePct * 100) + '%</span>' +
            ' \u2014 ' +
            '<span id="vk-enganche-amount-display">' + VkUI.formatPrecio(modelo.precioContado * this._enganchePct) + '</span>' +
            '</span>' +
            '</div>';
        html += '<div id="vk-enganche-big" style="font-size:32px;font-weight:800;color:var(--vk-text-primary);margin-bottom:8px;">' +
            VkUI.formatPrecio(modelo.precioContado * this._enganchePct) + '</div>';
        // Slider bounds: in CONDICIONAL mode the lower bound is the
        // min-required enganche from the credit evaluation (customer
        // cannot go below it).
        var sliderMin = cond ? Math.max(25, Math.round(engPctMin * 100)) : 25;
        var sliderVal = Math.max(sliderMin, Math.round(this._enganchePct * 100));
        html += '<input type="range" id="vk-enganche-slider" min="' + sliderMin + '" max="80" value="' + sliderVal + '" step="5" ' +
            'style="width:100%;">';
        html += '<div style="display:flex;justify-content:space-between;font-size:12px;color:var(--vk-text-muted);margin-top:4px;">' +
            '<span>' + sliderMin + '%</span><span>80%</span></div>';
        html += '<div style="text-align:center;font-size:13px;color:var(--vk-text-secondary);margin-top:6px;">M\u00e1s enganche = menor pago semanal</div>';

        // ── Plazo buttons ──────────────────────────────────────────────────
        // CONDICIONAL mode filters out any plazo longer than what the
        // credit evaluation allowed.
        var plazoOptions = [12, 18, 24, 36];
        if (cond) {
            plazoOptions = plazoOptions.filter(function(p){ return p <= plazoMax; });
            if (plazoOptions.length === 0) plazoOptions = [12];
        }
        html += '<div style="margin-top:20px;">';
        html += '<div style="font-weight:700;font-size:15px;margin-bottom:10px;">Elige tu plazo</div>';
        html += '<div id="vk-plazo-btns" style="display:flex;gap:8px;">';
        html += this._renderPlazoBtns(plazoOptions, this._plazoMeses, null, modelo, this._enganchePct);
        html += '</div>';
        html += '<div style="text-align:center;font-size:13px;color:var(--vk-text-secondary);margin-top:8px;">Mayor plazo = menor pago semanal</div>';
        html += '</div>';

        // ── Pago semanal result box ────────────────────────────────────────
        html += '<div id="vk-calc-results">';
        html += this._renderCalcResults(modelo, credito);
        html += '</div>';

        html += '</div>'; // end card

        // ── Benefit bullets ─────────────────────────────────────────────────
        html += '<div style="margin-top:16px;padding:0 4px;">';
        html += '<div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:10px;">' +
            '<span style="font-size:14px;"><strong>Usa lo que hoy gastas en gasolina para pagar tu Voltika</strong></span></div>';
        html += '<div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:8px;">' +
            '<span style="color:var(--vk-green-primary);font-size:15px;">&#10004;</span>' +
            '<span style="font-size:14px;">Aprobaci\u00f3n en menos de <strong>2 minutos</strong></span></div>';
        html += '<div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:8px;">' +
            '<span style="color:var(--vk-green-primary);font-size:15px;">&#10004;</span>' +
            '<span style="font-size:14px;">Solo necesitas tu <strong>INE</strong></span></div>';
        html += '<div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:8px;">' +
            '<span style="color:var(--vk-green-primary);font-size:15px;">&#10004;</span>' +
            '<span style="font-size:14px;"><strong>Sin penalizaci\u00f3n por prepago</strong></span></div>';
        html += '<div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:10px;">' +
            '<img src="' + (window.VK_BASE_PATH || '') + 'img/entrega.png" alt="" style="width:20px;height:20px;object-fit:contain;flex-shrink:0;">' +
            '<span style="font-size:14px;">Entrega sin costo en un punto Voltika autorizado en tu ciudad</span></div>';
        html += '</div>';

        // ── Price summary ───────────────────────────────────────────────────
        html += '<div id="vk-price-summary" style="padding:12px 16px;border-top:1px solid var(--vk-border);margin-top:8px;">';
        var summaryRows = [
            { label: 'Precio de la moto',   id: '',                    val: VkUI.formatPrecio(modelo.precioContado),              color: '' },
            { label: 'Enganche',            id: 'vk-enganche-summary', val: VkUI.formatPrecio(credito.enganche),                  color: 'color:var(--vk-green-primary);' },
            { label: 'Plazo y pago',        id: 'vk-plazo-summary',    val: this._plazoMeses + ' meses &middot; ' + VkUI.formatPrecio(credito.pagoSemanal) + '/semana', color: 'color:#039fe1;' }
        ];
        for (var si = 0; si < summaryRows.length; si++) {
            var row = summaryRows[si];
            html += '<div style="display:flex;justify-content:space-between;align-items:center;' + (si > 0 ? 'margin-top:6px;' : '') + '">';
            html += '<span style="font-size:14px;color:var(--vk-text-secondary);">' + row.label + '</span>';
            html += '<span style="font-size:14px;font-weight:700;' + row.color + '"' + (row.id ? ' id="' + row.id + '"' : '') + '>' + row.val + '</span>';
            html += '</div>';
        }
        html += '</div>';

        // ── APARTAR MI VOLTIKA button ───────────────────────────────────────
        // In CONDICIONAL mode the applicant has already completed the full
        // credit pre-authorization flow. The "APARTAR" wording implies a
        // reservation step that's already done; the next real action is to
        // pay the adjusted enganche, so relabel accordingly.
        var ctaLabel = cond ? 'PAGAR ENGANCHE \u203a' : 'APARTAR MI VOLTIKA';
        html += '<button class="vk-btn vk-btn--blue" id="vk-confirmar-credito" style="margin-top:12px;font-size:16px;font-weight:800;letter-spacing:0.5px;">' + ctaLabel + '</button>';

        html += '<p style="text-align:center;font-size:12px;color:var(--vk-text-muted);margin-top:8px;">' +
            (cond
                ? 'A continuaci\u00f3n ir\u00e1s al pago seguro del enganche ajustado.'
                : 'Proceso 100% digital \u00b7 Sin tr\u00e1mites complicados') +
            '</p>';

        $('#vk-credito-container').html(html);
    },

    _renderPlazoBtns: function(plazos, activo, maxPermitido, modelo, enganchePct) {
        var html = '';
        for (var i = 0; i < plazos.length; i++) {
            var p = plazos[i];
            var isDisabled = (maxPermitido !== null && p > maxPermitido);
            var isActive = p === activo;
            var cls = 'vk-plazo-btn';
            var style = 'flex:1;min-width:60px;padding:8px 4px;font-size:13px;font-weight:600;border-radius:8px;border:1.5px solid var(--vk-border);cursor:pointer;text-align:center;line-height:1.3;';
            if (isActive) {
                style += 'background:#039fe1;color:#fff;border-color:#039fe1;';
            } else {
                style += 'background:#fff;color:var(--vk-text-primary);';
            }
            if (isDisabled) {
                style += 'opacity:0.4;cursor:not-allowed;';
            }
            var pagoLine = '';
            if (modelo && enganchePct !== undefined) {
                var calc = VkCalculadora.calcular(modelo.precioContado, enganchePct, p);
                var pagoColor = isActive ? '#fff' : '#039fe1';
                pagoLine = '<br><span style="font-size:11px;font-weight:700;color:' + pagoColor + ';">' + VkUI.formatPrecio(calc.pagoSemanal) + '/sem</span>';
            }
            html += '<button class="' + cls + '"' + (isDisabled ? ' disabled' : '') +
                ' data-plazo="' + p + '" style="' + style + '">' +
                p + ' meses' + pagoLine + '</button>';
        }
        return html;
    },

    _calcularCredito: function(modelo) {
        return VkCalculadora.calcular(modelo.precioContado, this._enganchePct, this._plazoMeses);
    },

    _renderCalcResults: function(modelo, credito) {
        var pagoDiario = Math.ceil(credito.pagoSemanal / 7);
        var html = '<div style="border:2px solid var(--vk-text-primary);border-radius:10px;padding:18px;margin-top:16px;text-align:center;">';
        html += '<div style="font-weight:700;font-size:15px;margin-bottom:4px;">Tu pago semanal</div>';
        html += '<div style="font-size:36px;font-weight:800;color:var(--vk-green-primary);margin-bottom:4px;">' + VkUI.formatPrecio(credito.pagoSemanal) + '</div>';
        html += '<div style="font-size:14px;color:var(--vk-text-secondary);">Menos de <strong>' + VkUI.formatPrecio(pagoDiario) + '</strong> al d\u00eda</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-muted);margin-top:4px;">Primer pago 7 d\u00edas despu\u00e9s de la entrega</div>';
        html += '</div>';
        return html;
    },

    bindEvents: function() {
        var self = this;

        // Remove previous handlers to prevent duplicates on re-init
        $(document).off('click', '#vk-confirmar-credito')
                   .off('click', '#vk-switch-msi')
                   .off('click', '#vk-switch-contado')
                   .off('click', '.vk-plazo-btn')
                   .off('input', '#vk-enganche-slider');

        // Helper — list of plazo options honoring CONDICIONAL max cap.
        var getPlazoOptions = function() {
            var base = [12, 18, 24, 36];
            var s = self.app.state || {};
            if (s.modoCondicional && s.plazoMesesMax) {
                base = base.filter(function(p) { return p <= s.plazoMesesMax; });
                if (base.length === 0) base = [12];
            }
            return base;
        };

        // CTA — route depends on flow mode:
        //   CONDICIONAL (customer brief 2026-04-25): credit evaluation
        //     already ran once. User just adjusted enganche/plazo within
        //     the algorithm's authorized bounds. Go to Truora
        //     (credito-identidad), NOT back through credito-resultado or
        //     any ingreso/evaluation step — re-posting to
        //     preaprobacion-v3.php would create duplicate rows in
        //     preaprobacion_log / solicitudes_credito. After Truora
        //     completes, the flow continues to credito-enganche (Stripe).
        //   NORMAL (first-time config, no evaluation yet): user hasn't
        //     picked a color yet — go to the color selector.
        $(document).on('click', '#vk-confirmar-credito', function() {
            var modelo = self.app.getModelo(self.app.state.modeloSeleccionado);
            var credito = self._calcularCredito(modelo);
            self.app.state.enganchePorcentaje = self._enganchePct;
            self.app.state.plazoMeses = self._plazoMeses;
            self.app.state.cuotaSemanal = credito.pagoSemanal;

            if (self.app.state.modoCondicional) {
                self.app.irAPaso('credito-identidad');
            } else {
                self.app.irAPaso(2);
            }
        });

        // Slider enganche — clamp to min required in CONDICIONAL mode.
        $(document).on('input', '#vk-enganche-slider', function() {
            var pct = parseInt($(this).val()) / 100;
            var s = self.app.state || {};
            if (s.modoCondicional && s.enganchePctMin && pct < s.enganchePctMin) {
                pct = s.enganchePctMin;
                $(this).val(Math.round(pct * 100));
            }
            self._enganchePct = pct;
            var modelo  = self.app.getModelo(self.app.state.modeloSeleccionado);
            var credito = self._calcularCredito(modelo);
            var engancheStr = VkUI.formatPrecio(credito.enganche);

            $('#vk-enganche-pct-display').text(Math.round(self._enganchePct * 100) + '%');
            $('#vk-enganche-amount-display').text(engancheStr);
            $('#vk-enganche-big').text(engancheStr);
            $('#vk-enganche-summary').text(engancheStr);
            $('#vk-monto-summary').text(VkUI.formatPrecio(credito.montoFinanciado));
            $('#vk-plazo-summary').html(self._plazoMeses + ' meses &middot; ' + VkUI.formatPrecio(credito.pagoSemanal) + '/semana');
            $('#vk-calc-results').html(self._renderCalcResults(modelo, credito));
            $('#vk-plazo-btns').html(self._renderPlazoBtns(getPlazoOptions(), self._plazoMeses, null, modelo, self._enganchePct));
        });

        // Botones de plazo
        $(document).on('click', '.vk-plazo-btn:not([disabled])', function() {
            self._plazoMeses = parseInt($(this).data('plazo'));
            var modelo  = self.app.getModelo(self.app.state.modeloSeleccionado);
            var credito = self._calcularCredito(modelo);

            $('#vk-plazo-btns').html(self._renderPlazoBtns(getPlazoOptions(), self._plazoMeses, null, modelo, self._enganchePct));
            $('#vk-monto-summary').text(VkUI.formatPrecio(credito.montoFinanciado));
            $('#vk-plazo-summary').html(self._plazoMeses + ' meses &middot; ' + VkUI.formatPrecio(credito.pagoSemanal) + '/semana');
            $('#vk-calc-results').html(self._renderCalcResults(modelo, credito));
        });

        // Switch a contado/MSI — go to color selector first (color not yet chosen)
        $(document).on('click', '#vk-switch-contado', function() {
            self.app.state.metodoPago = 'contado';
            self.app.irAPaso(2);
        });

        $(document).on('click', '#vk-switch-msi', function() {
            self.app.state.metodoPago = 'msi';
            self.app.irAPaso(2);
        });
    }
};
