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

// ── Algoritmo V3 (implementación exacta del documento) ───────────────────────
var PreaprobacionV3 = {

    /**
     * Evalúa inputs y devuelve resultado V3.
     * @param {object} inputs
     *   ingreso_mensual_est   – mínimo del rango elegido
     *   pago_semanal_voltika  – del slider
     *   score                 – Círculo de Crédito (null = pendiente)
     *   pago_mensual_buro     – Círculo de Crédito (null = pendiente)
     *   dpd90_flag            – boolean (null = pendiente)
     *   dpd_max               – número (null = pendiente)
     */
    evaluar: function(inputs) {
        var ing   = inputs.ingreso_mensual_est;
        var psv   = inputs.pago_semanal_voltika;
        var score = inputs.score;
        var buro  = inputs.pago_mensual_buro || 0;
        var dpd90 = inputs.dpd90_flag;
        var dpdMax = inputs.dpd_max;

        if (!isFinite(ing) || ing <= 0 || !isFinite(psv) || psv <= 0) {
            return { status: 'ERROR', mensaje: 'Datos insuficientes para evaluar.' };
        }

        // Mensualización: pago_mensual_voltika = pago_semanal * 4.3333
        var pmv   = psv * 4.3333;
        var pti   = (buro + pmv) / ing;

        var mora  = dpd90 === true || (dpdMax != null && dpdMax >= 90);

        // Sin datos de Círculo → estimación solo por PTI
        if (score === null || score === undefined) {
            return this._evaluarSinCirculo(pti, pmv, ing);
        }

        // ── Con datos completos de Círculo ────────────────────────────────
        // KO reales → NO_VIABLE
        if (score < 420 || mora || pti > 1.05) {
            return { status: 'NO_VIABLE', pti: pti, reasons: ['KO_REAL'] };
        }

        // Guardrail → NO_VIABLE
        if (score <= 439 && pti > 0.95) {
            return { status: 'NO_VIABLE', pti: pti, reasons: ['GUARDRAIL'] };
        }

        // PREAPROBADO
        if (score >= 480 && pti <= 0.90) {
            return {
                status: 'PREAPROBADO',
                pti: pti,
                plazo_max_meses: this._plazoMax(score, pti)
            };
        }

        // CONDICIONAL (todo lo demás)
        return {
            status: 'CONDICIONAL',
            pti: pti,
            enganche_requerido_min: this._engancheMin(pti),
            plazo_max_meses: this._plazoMax(score, pti)
        };
    },

    // Evaluación sin datos de Círculo (solo PTI estimado)
    _evaluarSinCirculo: function(pti) {
        if (pti > 1.05) {
            return { status: 'NO_VIABLE', pti: pti, reasons: ['PTI_EXTREMO'] };
        }
        if (pti <= 0.75) {
            return { status: 'PREAPROBADO_ESTIMADO', pti: pti, plazo_max_meses: 36 };
        }
        return {
            status: 'CONDICIONAL_ESTIMADO',
            pti: pti,
            enganche_requerido_min: this._engancheMin(pti),
            plazo_max_meses: 24
        };
    },

    // Enganche mínimo por PTI (tabla V3)
    _engancheMin: function(pti) {
        if (pti <= 0.90) return 0.25;
        if (pti <= 0.95) return 0.30;
        if (pti <= 1.00) return 0.35;
        return 0.45;
    },

    // Plazo máximo por score + PTI (tabla V3)
    _plazoMax: function(score, pti) {
        if (score >= 520 && pti <= 0.85) return 36;
        if (score >= 480 && pti <= 0.90) return 24;
        if (score >= 440 && pti <= 0.95) return 18;
        if (score >= 420 && pti <= 1.05) return 18;
        return 18; // fallback
    }
};

// ── Módulo PASO 4B ────────────────────────────────────────────────────────────
var Paso4B = {

    init: function(app) {
        this.app = app;
        this._enganchePct = 0.30;
        this._plazoMeses  = 12;
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

        // Back button — back to color selection (paso 2)
        html += VkUI.renderBackButton(2);

        // ── Title ───────────────────────────────────────────────────────────
        html += '<div style="text-align:center;margin-bottom:16px;">';
        html += '<div style="font-size:20px;font-weight:800;">Calculadora de cr\u00e9dito</div>';
        html += '</div>';

        // ── Model summary (compact) ─────────────────────────────────────────
        html += '<div class="vk-credit-summary">';
        html += '<div class="vk-credit-summary__model">';
        html += '<div class="vk-credit-summary__details">';
        html += '<div style="font-size:15px;font-weight:700;margin-bottom:4px;">' + modelo.nombre + '</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);">Color: ' + (state.colorSeleccionado || modelo.colorDefault) + '</div>';
        html += '</div>';
        html += '<img class="vk-credit-summary__img" src="' + img + '" alt="' + modelo.nombre + '">';
        html += '</div>';
        html += '</div>';

        // ── Enganche slider (per Dibujo.pdf) ────────────────────────────────
        html += '<div class="vk-info-box" style="margin-top:16px;">';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Enganche: <strong id="vk-enganche-display">' +
            Math.round(this._enganchePct * 100) + '% \u2014 ' + VkUI.formatPrecio(modelo.precioContado * this._enganchePct) + '</strong></label>';
        html += '<input type="range" id="vk-enganche-slider" min="25" max="80" value="30" step="5" ' +
            'style="width:100%;accent-color:var(--vk-green-primary);">';
        html += '<div style="display:flex;justify-content:space-between;font-size:11px;color:var(--vk-text-muted);">' +
            '<span>25%</span><span>80%</span></div>';
        html += '</div>';

        // ── Plazo buttons (per Dibujo.pdf) ──────────────────────────────────
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Plazo:</label>';
        html += '<div id="vk-plazo-btns" style="display:flex;gap:8px;flex-wrap:wrap;">';
        html += this._renderPlazoBtns([12, 18, 24, 36], this._plazoMeses, null);
        html += '</div>';
        html += '</div>';

        // ── Calculated results (Enganche, pago semanal, plazo total) ────────
        html += '<div id="vk-calc-results">';
        html += this._renderCalcResults(modelo, credito);
        html += '</div>';

        html += '</div>'; // end info-box

        // ── CONFIRMAR COMPRA button ─────────────────────────────────────────
        html += '<button class="vk-btn vk-btn--blue" id="vk-confirmar-credito" style="margin-top:16px;">CONFIRMAR COMPRA</button>';

        $('#vk-credito-container').html(html);
    },

    _renderPlazoBtns: function(plazos, activo, maxPermitido) {
        var html = '';
        for (var i = 0; i < plazos.length; i++) {
            var p = plazos[i];
            var disabled = (maxPermitido !== null && p > maxPermitido) ? ' disabled style="opacity:0.4;cursor:not-allowed;"' : '';
            var active   = p === activo ? ' vk-btn--primary' : ' vk-btn--secondary';
            var sty      = p === activo ? '' : ' style="padding:8px 16px;font-size:13px;"';
            html += '<button class="vk-btn' + active + ' vk-plazo-btn"' + disabled + sty +
                ' data-plazo="' + p + '" style="flex:1;min-width:60px;padding:8px;font-size:13px;">' +
                p + ' meses</button>';
        }
        return html;
    },

    _calcularCredito: function(modelo) {
        return VkCalculadora.calcular(modelo.precioContado, this._enganchePct, this._plazoMeses);
    },

    _renderCalcResults: function(modelo, credito) {
        var html = '<div style="background:var(--vk-bg-light);padding:14px;border-radius:8px;margin-top:8px;">';
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:13px;">';
        html += '<div>Precio contado:</div><div style="text-align:right;font-weight:600;">' + VkUI.formatPrecio(credito.precioContado) + '</div>';
        html += '<div>Enganche (' + Math.round(this._enganchePct * 100) + '%):</div>' +
            '<div style="text-align:right;font-weight:700;color:var(--vk-green-primary);">' + VkUI.formatPrecio(credito.enganche) + '</div>';
        html += '<div>Monto financiado:</div><div style="text-align:right;font-weight:600;">' + VkUI.formatPrecio(credito.montoFinanciado) + '</div>';
        html += '<div>Plazo:</div><div style="text-align:right;font-weight:600;">' + credito.plazoMeses + ' meses (' + credito.numeroPagos + ' pagos)</div>';
        html += '<div>Tasa anual:</div><div style="text-align:right;font-weight:600;">' + credito.tasaAnual + '%</div>';
        html += '</div>';
        html += '<div style="border-top:2px solid var(--vk-text-primary);margin-top:8px;padding-top:8px;display:flex;justify-content:space-between;align-items:center;">';
        html += '<span style="font-weight:700;font-size:14px;">Pago semanal:</span>';
        html += '<span style="font-size:20px;font-weight:800;color:var(--vk-green-primary);">' + VkUI.formatPrecio(credito.pagoSemanal) + '</span>';
        html += '</div>';
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

        // "CONFIRMAR COMPRA" — save enganche/plazo and go to next credit step
        $(document).on('click', '#vk-confirmar-credito', function() {
            self.app.state.enganchePorcentaje = self._enganchePct;
            self.app.state.plazoMeses = self._plazoMeses;
            self.app.irAPaso('credito-nombre');
        });

        // Slider enganche
        $(document).on('input', '#vk-enganche-slider', function() {
            self._enganchePct = parseInt($(this).val()) / 100;
            var modelo  = self.app.getModelo(self.app.state.modeloSeleccionado);
            var credito = self._calcularCredito(modelo);

            $('#vk-enganche-display').html(
                Math.round(self._enganchePct * 100) + '% — ' + VkUI.formatPrecio(credito.enganche)
            );
            $('#vk-calc-results').html(self._renderCalcResults(modelo, credito));
            self._actualizarCTA();
        });

        // Botones de plazo
        $(document).on('click', '.vk-plazo-btn:not([disabled])', function() {
            self._plazoMeses = parseInt($(this).data('plazo'));
            var modelo  = self.app.getModelo(self.app.state.modeloSeleccionado);
            var credito = self._calcularCredito(modelo);

            $('.vk-plazo-btn').removeClass('vk-btn--primary').addClass('vk-btn--secondary');
            $(this).removeClass('vk-btn--secondary').addClass('vk-btn--primary');

            $('#vk-calc-results').html(self._renderCalcResults(modelo, credito));
            self._actualizarCTA();
        });

        // Switch a contado/MSI
        $(document).on('click', '#vk-switch-contado', function() {
            self.app.state.metodoPago = 'contado';
            self.app.irAPaso(3);
        });

        $(document).on('click', '#vk-switch-msi', function() {
            self.app.state.metodoPago = 'msi';
            self.app.irAPaso(3);
        });
    }
};
