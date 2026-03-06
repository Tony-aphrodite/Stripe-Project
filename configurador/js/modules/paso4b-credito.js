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

        // Back button — back to color selection (paso 2)
        html += VkUI.renderBackButton(2);

        // ── Title ───────────────────────────────────────────────────────────
        html += '<div style="text-align:left;margin-bottom:16px;">';
        html += '<div style="font-size:22px;font-weight:800;">Arma tu plan Voltika</div>';
        html += '</div>';

        // ── Model summary (compact) ─────────────────────────────────────────
        html += '<div style="background:var(--vk-bg-light);border-radius:10px;padding:14px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;">';
        html += '<div>';
        html += '<div style="font-size:16px;font-weight:800;">' + modelo.nombre + '</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-top:2px;">Color: ' + (state.colorSeleccionado || modelo.colorDefault) + '</div>';
        html += '</div>';
        html += '<img src="' + img + '" alt="' + modelo.nombre + '" style="height:60px;width:auto;object-fit:contain;">';
        html += '</div>';

        // ── Enganche slider ────────────────────────────────────────────────
        html += '<div class="vk-card" style="padding:20px;">';

        html += '<div style="font-size:14px;margin-bottom:4px;">Enganche recomendado <strong id="vk-enganche-pct-display">' +
            Math.round(this._enganchePct * 100) + '%</strong> \u2014 <strong id="vk-enganche-amount-display">' +
            VkUI.formatPrecio(modelo.precioContado * this._enganchePct) + '</strong></div>';
        html += '<div id="vk-enganche-big" style="font-size:32px;font-weight:800;color:var(--vk-text-primary);margin-bottom:8px;">' +
            VkUI.formatPrecio(modelo.precioContado * this._enganchePct) + '</div>';
        html += '<input type="range" id="vk-enganche-slider" min="25" max="80" value="' + Math.round(this._enganchePct * 100) + '" step="5" ' +
            'style="width:100%;accent-color:#2563EB;height:8px;">';
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
        html += '<div id="vk-price-summary" style="display:flex;justify-content:space-between;padding:12px 16px;border-top:1px solid var(--vk-border);margin-top:8px;">';
        html += '<div style="font-size:14px;">';
        html += '<div>Precio de la moto</div>';
        html += '<div style="margin-top:4px;">Enganche</div>';
        html += '</div>';
        html += '<div style="font-size:14px;text-align:right;font-weight:700;">';
        html += '<div>' + VkUI.formatPrecio(modelo.precioContado) + '</div>';
        html += '<div style="margin-top:4px;color:var(--vk-green-primary);" id="vk-enganche-summary">' + VkUI.formatPrecio(modelo.precioContado * this._enganchePct) + '</div>';
        html += '</div>';
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
                style += 'background:var(--vk-text-primary);color:#fff;border-color:var(--vk-text-primary);';
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
            var engancheStr = VkUI.formatPrecio(credito.enganche);

            $('#vk-enganche-pct-display').text(Math.round(self._enganchePct * 100) + '%');
            $('#vk-enganche-amount-display').text(engancheStr);
            $('#vk-enganche-big').text(engancheStr);
            $('#vk-enganche-summary').text(engancheStr);
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
                'background': 'var(--vk-text-primary)',
                'color': '#fff',
                'border-color': 'var(--vk-text-primary)'
            });

            $('#vk-calc-results').html(self._renderCalcResults(modelo, credito));
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
