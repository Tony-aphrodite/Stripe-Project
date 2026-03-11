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
        var cfg    = this.config;

        if (!isFinite(ing) || ing <= 0 || !isFinite(psv) || psv <= 0) {
            return { status: 'ERROR', mensaje: 'Datos insuficientes para evaluar.' };
        }

        var pmv  = psv * 4.3333;
        var pti  = (buro + pmv) / ing;
        var mora = dpd90 === true || (dpdMax != null && dpdMax >= 90);

        // Sin datos de Círculo → evaluación estimada solo por PTI
        if (score === null || score === undefined) {
            return this._evaluarSinCirculo(pti);
        }

        var s = Number(score);

        // 1) KO reales → NO_VIABLE
        if (s < cfg.KO.scoreMin) {
            return { status: 'NO_VIABLE', pti: pti, enganche_min: cfg.downPaymentMin, reasons: ['KO_SCORE_LT_MIN'] };
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

        // 4) CONDICIONAL — palancas: enganche mayor y/o plazo más corto
        var engReq = Math.min(Math.max(this._engancheMin(pti), cfg.downPaymentMin), 0.60);
        return {
            status: 'CONDICIONAL',
            pti: pti,
            enganche_min:          cfg.downPaymentMin,
            enganche_requerido_min: engReq,
            plazo_max_meses:       this._plazoMax(s, pti)
        };
    },

    // Sin Círculo: evaluación estimada solo por PTI
    _evaluarSinCirculo: function(pti) {
        var cfg    = this.config;
        var engMin = cfg.downPaymentMin;
        if (pti > cfg.KO.ptiExtreme) {
            return { status: 'NO_VIABLE', pti: pti, reasons: ['PTI_EXTREMO_SIN_CIRCULO'] };
        }
        if (pti <= 0.75) {
            return { status: 'PREAPROBADO_ESTIMADO', pti: pti, enganche_requerido_min: engMin, plazo_max_meses: 36 };
        }
        return {
            status: 'CONDICIONAL_ESTIMADO',
            pti: pti,
            enganche_requerido_min: Math.min(Math.max(this._engancheMin(pti), engMin), 0.60),
            plazo_max_meses: 24
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
        this._enganchePct = 0.25;
        this._plazoMeses  = 24;
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

        var html = '';

        // Back button — back to model selection (paso 1)
        html += VkUI.renderBackButton(1);

        // ── Title ───────────────────────────────────────────────────────────
        html += '<div style="text-align:left;margin-bottom:16px;">';
        html += '<div style="font-size:22px;font-weight:800;">Arma tu plan Voltika</div>';
        html += '</div>';

        // ── Model summary (compact) ─────────────────────────────────────────
        html += '<div style="background:#fff;border:1.5px solid var(--vk-border);border-radius:10px;padding:14px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;">';
        html += '<div>';
        html += '<div style="font-size:16px;font-weight:800;">' + modelo.nombre + '</div>';
        html += '</div>';
        html += '<img src="' + img + '" alt="' + modelo.nombre + '" style="height:60px;width:auto;object-fit:contain;">';
        html += '</div>';

        // ── Enganche slider ────────────────────────────────────────────────
        html += '<div class="vk-card" style="padding:20px;">';

        html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;flex-wrap:nowrap;">' +
            '<span style="font-size:13px;color:var(--vk-text-secondary);">Enganche recomendado</span>' +
            '<span style="font-size:14px;font-weight:700;white-space:nowrap;margin-left:8px;">' +
            '<span id="vk-enganche-pct-display">' + Math.round(this._enganchePct * 100) + '%</span>' +
            ' \u2014 ' +
            '<span id="vk-enganche-amount-display">' + VkUI.formatPrecio(modelo.precioContado * this._enganchePct) + '</span>' +
            '</span>' +
            '</div>';
        html += '<div id="vk-enganche-big" style="font-size:32px;font-weight:800;color:var(--vk-text-primary);margin-bottom:8px;">' +
            VkUI.formatPrecio(modelo.precioContado * this._enganchePct) + '</div>';
        html += '<input type="range" id="vk-enganche-slider" min="25" max="80" value="' + Math.round(this._enganchePct * 100) + '" step="5" ' +
            'style="width:100%;height:8px;accent-color:#2563EB;-webkit-appearance:none;appearance:none;background:#2563EB;border-radius:4px;outline:none;cursor:pointer;">';
        html += '<div style="display:flex;justify-content:space-between;font-size:12px;color:var(--vk-text-muted);margin-top:4px;">' +
            '<span>25%</span><span>80%</span></div>';
        html += '<div style="text-align:center;font-size:13px;color:var(--vk-text-secondary);margin-top:6px;">M\u00e1s enganche = menor pago semanal</div>';

        // ── Plazo buttons ──────────────────────────────────────────────────
        html += '<div style="margin-top:20px;">';
        html += '<div style="font-weight:700;font-size:15px;margin-bottom:10px;">Elige tu plazo</div>';
        html += '<div id="vk-plazo-btns" style="display:flex;gap:8px;">';
        html += this._renderPlazoBtns([12, 18, 24, 36], this._plazoMeses, null);
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
            '<span style="font-size:16px;">&#9889;</span>' +
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
            '<span style="color:#D4A017;font-size:15px;">&#128205;</span>' +
            '<span style="font-size:14px;">Entrega sin costo en un punto Voltika autorizado en tu ciudad</span></div>';
        html += '</div>';

        // ── Price summary ───────────────────────────────────────────────────
        html += '<div id="vk-price-summary" style="padding:12px 16px;border-top:1px solid var(--vk-border);margin-top:8px;">';
        var summaryRows = [
            { label: 'Precio de la moto',   id: '',                    val: VkUI.formatPrecio(modelo.precioContado),              color: '' },
            { label: 'Enganche',            id: 'vk-enganche-summary', val: VkUI.formatPrecio(credito.enganche),                  color: 'color:var(--vk-green-primary);' },
            { label: 'Monto a financiar',   id: 'vk-monto-summary',    val: VkUI.formatPrecio(credito.montoFinanciado),           color: '' },
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
        html += '<button class="vk-btn vk-btn--blue" id="vk-confirmar-credito" style="margin-top:12px;font-size:16px;font-weight:800;letter-spacing:0.5px;">APARTAR MI VOLTIKA</button>';

        html += '<p style="text-align:center;font-size:12px;color:var(--vk-text-muted);margin-top:8px;">' +
            'Proceso 100% digital \u00b7 Sin tr\u00e1mites complicados</p>';

        $('#vk-credito-container').html(html);
    },

    _renderPlazoBtns: function(plazos, activo, maxPermitido) {
        var html = '';
        for (var i = 0; i < plazos.length; i++) {
            var p = plazos[i];
            var isDisabled = (maxPermitido !== null && p > maxPermitido);
            var isActive = p === activo;
            var star = (p === 24) ? ' &#11088;' : '';
            var cls = 'vk-plazo-btn';
            var style = 'flex:1;min-width:60px;padding:10px 8px;font-size:13px;font-weight:600;border-radius:8px;border:1.5px solid var(--vk-border);cursor:pointer;';
            if (isActive) {
                style += 'background:#039fe1;color:#fff;border-color:#039fe1;';
            } else {
                style += 'background:#fff;color:var(--vk-text-primary);';
            }
            if (isDisabled) {
                style += 'opacity:0.4;cursor:not-allowed;';
            }
            html += '<button class="' + cls + '"' + (isDisabled ? ' disabled' : '') +
                ' data-plazo="' + p + '" style="' + style + '">' +
                p + ' meses' + star + '</button>';
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

        // "CONFIRMAR COMPRA" — save enganche/plazo and go to color selection
        $(document).on('click', '#vk-confirmar-credito', function() {
            var modelo = self.app.getModelo(self.app.state.modeloSeleccionado);
            var credito = self._calcularCredito(modelo);
            self.app.state.enganchePorcentaje = self._enganchePct;
            self.app.state.plazoMeses = self._plazoMeses;
            self.app.state.cuotaSemanal = credito.pagoSemanal;
            self.app.irAPaso(2); // Go to color selector
        });

        // Slider enganche
        $(document).on('input', '#vk-enganche-slider', function() {
            self._enganchePct = parseInt($(this).val()) / 100;
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
        });

        // Botones de plazo
        $(document).on('click', '.vk-plazo-btn:not([disabled])', function() {
            self._plazoMeses = parseInt($(this).data('plazo'));
            var modelo  = self.app.getModelo(self.app.state.modeloSeleccionado);
            var credito = self._calcularCredito(modelo);

            // Update active button style
            $('.vk-plazo-btn').css({
                'background': '#fff',
                'color': 'var(--vk-text-primary)',
                'border-color': 'var(--vk-border)'
            });
            $(this).css({
                'background': '#039fe1',
                'color': '#fff',
                'border-color': '#039fe1'
            });

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
