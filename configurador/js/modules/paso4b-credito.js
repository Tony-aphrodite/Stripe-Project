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

        // Back button
        html += VkUI.renderBackButton(3);

        // ── INTRO SECTION ─────────────────────────────────────────────────
        html += '<div style="text-align:center;margin-bottom:20px;">';
        html += '<div style="font-size:22px;font-weight:800;">&#9745; voltika</div>';
        html += '<h2 style="font-size:22px;font-weight:800;margin-top:8px;">&#161;Toma solo 2 minutos! &#9200;</h2>';
        html += '</div>';

        // Model summary box
        html += '<div class="vk-credit-summary">';
        html += '<div class="vk-credit-summary__model">';
        html += '<div class="vk-credit-summary__details">';
        html += '<div style="font-size:15px;font-weight:700;margin-bottom:6px;">' + modelo.nombre + '</div>';
        html += '<div style="font-size:13px;margin-bottom:2px;">Enganche: <strong>' + VkUI.formatPrecio(credito.enganche) + '</strong></div>';
        html += '<div style="font-size:13px;margin-bottom:2px;"><strong>' + VkUI.formatPrecio(credito.pagoSemanal) + '</strong> / semana</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);">Color: ' + (state.colorSeleccionado || modelo.colorDefault) + '</div>';
        html += '<div style="background:var(--vk-green-soft);border-radius:6px;padding:6px 8px;margin-top:8px;font-size:12px;">' +
            '<span style="color:var(--vk-green-primary);">&#10004;</span> Entrega en Centro Voltika en tu ciudad' +
            '</div>';
        html += '</div>';
        html += '<img class="vk-credit-summary__img" src="' + img + '" alt="' + modelo.nombre + '">';
        html += '</div>';
        html += '</div>'; // end credit-summary

        // 5 pasos list
        html += '<div class="vk-credit-steps">';
        html += '<div class="vk-credit-steps__title">5 pasos muy sencillos:</div>';

        var pasos = [
            ['Verifica tu identidad', 'INE y selfie'],
            ['Confirma tu lugar de entrega cercano', 'Centro Voltika autorizado en tu ciudad'],
            ['Hablamos contigo y te guiamos en persona', 'Un asesor Voltika te contacta directamente'],
            ['Paga tu enganche de forma segura', 'Con tarjeta de credito o debito'],
            ['Firma contrato y recibe tu moto', 'Con permiso provisional y documentos para emplacar']
        ];

        for (var s = 0; s < pasos.length; s++) {
            html += '<div class="vk-credit-step">';
            html += '<div class="vk-credit-step__number">' + (s + 1) + '</div>';
            html += '<div class="vk-credit-step__content">';
            html += '<div class="vk-credit-step__title">' + pasos[s][0] + '</div>';
            html += '<div class="vk-credit-step__desc">' + pasos[s][1] + '</div>';
            html += '</div>';
            html += '</div>';
        }
        html += '</div>'; // end credit-steps

        // Iniciar proceso CTA
        html += '<button class="vk-btn vk-btn--blue" id="vk-iniciar-proceso">Iniciar proceso</button>';
        html += '<p style="text-align:center;font-size:12px;color:var(--vk-text-muted);margin:4px 0 24px;">Toma menos de 2 minutos.</p>';

        // ── V3 FORM (hidden until "Iniciar proceso" is clicked) ───────────
        html += '<div id="vk-v3-form" style="display:none;">';

        // Bloque 1: Ingreso mensual
        html += '<div class="vk-info-box">';
        html += '<div style="font-weight:700;font-size:14px;margin-bottom:12px;">&#128181; 1. ¿Cual es tu ingreso mensual?</div>';
        html += '<select id="vk-ingreso-select" class="vk-form-input" style="width:100%;">';
        html += '<option value="">-- Selecciona tu rango --</option>';
        for (var i = 0; i < RANGOS_INGRESO.length; i++) {
            html += '<option value="' + RANGOS_INGRESO[i].valor + '">' + RANGOS_INGRESO[i].label + '</option>';
        }
        html += '</select>';
        html += '<div style="font-size:12px;color:var(--vk-text-muted);margin-top:6px;">Usamos el minimo de tu rango para ser conservadores.</div>';
        html += '</div>';

        // Bloque 2: Calculadora
        html += '<div class="vk-info-box" style="margin-top:16px;">';
        html += '<div style="font-weight:700;font-size:14px;margin-bottom:12px;">&#128200; 2. Ajusta tu plan de pago</div>';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Enganche: <strong id="vk-enganche-display">' +
            Math.round(this._enganchePct * 100) + '% — ' + VkUI.formatPrecio(modelo.precioContado * this._enganchePct) + '</strong></label>';
        html += '<input type="range" id="vk-enganche-slider" min="25" max="80" value="30" step="5" ' +
            'style="width:100%;accent-color:var(--vk-green-primary);">';
        html += '<div style="display:flex;justify-content:space-between;font-size:11px;color:var(--vk-text-muted);">' +
            '<span>25% min</span><span>80%</span></div>';
        html += '</div>';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Plazo:</label>';
        html += '<div id="vk-plazo-btns" style="display:flex;gap:8px;flex-wrap:wrap;">';
        html += this._renderPlazoBtns([12, 18, 24, 36], this._plazoMeses, null);
        html += '</div>';
        html += '</div>';

        html += '<div id="vk-calc-results">';
        html += this._renderCalcResults(modelo, credito);
        html += '</div>';
        html += '</div>'; // end bloque 2

        // Bloque 3: CTA pre-aprobación
        html += '<div id="vk-preaprobacion-panel">';
        html += '<div style="text-align:center;margin-top:16px;">';
        html += '<p style="font-size:13px;color:var(--vk-text-muted);margin-bottom:12px;">' +
            'Al continuar verificamos tu identidad con INE y consultamos Circulo de Credito (no afecta tu historial).' +
            '</p>';
        html += '<button class="vk-btn vk-btn--blue" id="vk-iniciar-credito" disabled>' +
            '&#10004; Verificar pre-aprobacion' +
            '</button>';
        html += '<p style="font-size:12px;color:var(--vk-text-muted);margin-top:8px;">Selecciona tu ingreso para continuar.</p>';
        html += '</div>';
        html += '</div>';

        // Panel de resultado
        html += '<div id="vk-credito-resultado" style="display:none;margin-top:20px;"></div>';

        html += '</div>'; // end v3-form

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

        // "Iniciar proceso" button — reveals the V3 form
        $(document).on('click', '#vk-iniciar-proceso', function() {
            $(this).hide();
            $(this).next('p').hide();
            $('#vk-v3-form').slideDown(400);
            VkUI.scrollToTop();
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

        // Selector de ingreso
        $(document).on('change', '#vk-ingreso-select', function() {
            var val = $(this).val();
            self._ingresoVal = val ? parseInt(val) : null;
            self._actualizarCTA();
        });

        // Botón verificar
        $(document).on('click', '#vk-iniciar-credito', function() {
            self._iniciarVerificacion();
        });

        // Switch a contado/MSI desde NO_VIABLE
        $(document).on('click', '#vk-switch-contado', function() {
            self.app.state.metodoPago = 'contado';
            self.app.irAPaso(4);
        });

        $(document).on('click', '#vk-switch-msi', function() {
            self.app.state.metodoPago = 'msi';
            self.app.irAPaso(4);
        });

        // Botón "recalcular" en CONDICIONAL
        $(document).on('click', '#vk-recalcular-condicional', function() {
            self._iniciarVerificacion();
        });
    },

    _actualizarCTA: function() {
        var $btn = $('#vk-iniciar-credito');
        var $note = $btn.next('p');
        if (this._ingresoVal) {
            $btn.prop('disabled', false);
            $note.hide();
        } else {
            $btn.prop('disabled', true);
            $note.show();
        }
    },

    _iniciarVerificacion: function() {
        var self   = this;
        var modelo = self.app.getModelo(self.app.state.modeloSeleccionado);
        var credito = self._calcularCredito(modelo);

        var $btn = $('#vk-iniciar-credito');
        $btn.prop('disabled', true).html(VkUI.renderSpinner() + ' Verificando...');

        // POST al servidor para evaluación V3
        // (En esta fase, el servidor evalúa con score simulado o real de Círculo)
        $.ajax({
            url: 'php/preaprobacion-v3.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                ingreso_mensual_est:  self._ingresoVal,
                pago_semanal_voltika: credito.pagoSemanal,
                enganche_pct:         self._enganchePct,
                plazo_meses:          self._plazoMeses,
                precio_contado:       modelo.precioContado,
                modelo:               modelo.nombre
            }),
            success: function(response) {
                self._resultadoV3 = response;
                self._mostrarResultado(response, modelo, credito);
            },
            error: function() {
                // Fallback: evaluar solo con PTI (sin Círculo)
                var resultado = PreaprobacionV3.evaluar({
                    ingreso_mensual_est:  self._ingresoVal,
                    pago_semanal_voltika: credito.pagoSemanal,
                    score: null,
                    pago_mensual_buro: 0,
                    dpd90_flag: null,
                    dpd_max: null
                });
                self._resultadoV3 = resultado;
                self._mostrarResultado(resultado, modelo, credito);
            },
            complete: function() {
                $btn.prop('disabled', false).html('&#10004; Verificar pre-aprobacion');
            }
        });
    },

    _mostrarResultado: function(resultado, modelo, credito) {
        var self = this;
        var html = '';

        if (resultado.status === 'PREAPROBADO' || resultado.status === 'PREAPROBADO_ESTIMADO') {
            // ── PREAPROBADO ─────────────────────────────────────────────────
            var plazoMax = resultado.plazo_max_meses || 36;

            html += '<div style="background:#E8F5E9;border:2px solid #4CAF50;border-radius:12px;padding:20px;">';
            html += '<div style="font-size:40px;text-align:center;">&#10004;</div>';
            html += '<h3 style="color:#2E7D32;text-align:center;margin:8px 0;">&#161;Pre-aprobado!</h3>';
            if (resultado.status === 'PREAPROBADO_ESTIMADO') {
                html += '<p style="font-size:12px;color:#666;text-align:center;margin-bottom:12px;">' +
                    '(Estimacion basada en tu ingreso. Sujeto a verificacion con Circulo de Credito.)</p>';
            }
            html += '<div style="background:#FFF;border-radius:8px;padding:12px;font-size:13px;margin-bottom:16px;">';
            html += '<div><strong>Enganche a pagar:</strong> ' + VkUI.formatPrecio(credito.enganche) + '</div>';
            html += '<div><strong>Pago semanal:</strong> ' + VkUI.formatPrecio(credito.pagoSemanal) + '</div>';
            html += '<div><strong>Plazo maximo aprobado:</strong> ' + plazoMax + ' meses</div>';
            html += '</div>';

            // Re-render plazo buttons with max allowed
            html += '<div style="margin-bottom:12px;">';
            html += '<div style="font-size:13px;font-weight:600;margin-bottom:8px;">Plazos disponibles:</div>';
            html += '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
            html += self._renderPlazoBtns([12, 18, 24, 36], self._plazoMeses, plazoMax);
            html += '</div></div>';

            html += '<p style="font-size:13px;color:#555;margin-bottom:12px;">' +
                'Siguiente paso: verificar tu identidad con INE + selfie (Truora).' +
                '</p>';
            html += '<button class="vk-btn vk-btn--blue" id="vk-continuar-truora" style="margin-bottom:8px;">' +
                '&#128196; Verificar identidad (INE + selfie)' +
                '</button>';
            html += '<p style="font-size:12px;color:#9CA3AF;text-align:center;">No afecta tu historial crediticio.</p>';
            html += '</div>';

        } else if (resultado.status === 'CONDICIONAL' || resultado.status === 'CONDICIONAL_ESTIMADO') {
            // ── CONDICIONAL ─────────────────────────────────────────────────
            var engMin    = resultado.enganche_requerido_min || 0.35;
            var engMinPct = Math.round(engMin * 100);
            var plazoMaxC = resultado.plazo_max_meses || 18;
            var engReq    = Math.round(modelo.precioContado * engMin);

            html += '<div style="background:#FFF8E1;border:2px solid #FFB300;border-radius:12px;padding:20px;">';
            html += '<div style="font-size:40px;text-align:center;">&#9888;</div>';
            html += '<h3 style="color:#F57F17;text-align:center;margin:8px 0;">Ajuste requerido</h3>';
            html += '<p style="font-size:13px;color:#555;text-align:center;margin-bottom:12px;">' +
                'Puedes calificar ajustando tu enganche y/o plazo.' +
                '</p>';

            html += '<div style="background:#FFF;border-radius:8px;padding:12px;font-size:13px;margin-bottom:16px;">';
            html += '<div style="color:#E65100;font-weight:700;">&#10140; Enganche minimo requerido: ' + engMinPct + '% (' + VkUI.formatPrecio(engReq) + ')</div>';
            html += '<div style="color:#E65100;font-weight:700;margin-top:4px;">&#10140; Plazo maximo permitido: ' + plazoMaxC + ' meses</div>';
            html += '</div>';

            html += '<div style="margin-bottom:12px;">';
            html += '<label class="vk-form-label">Ajusta tu enganche (min ' + engMinPct + '%):</label>';
            var sliderMin = Math.max(engMinPct, 25);
            html += '<input type="range" id="vk-enganche-slider-c" min="' + sliderMin + '" max="80" value="' +
                Math.max(Math.round(self._enganchePct * 100), engMinPct) + '" step="5" ' +
                'style="width:100%;accent-color:var(--vk-green-primary);">';
            html += '<div id="vk-enganche-display-c" style="font-size:13px;font-weight:600;text-align:center;margin-top:4px;">' +
                engMinPct + '% — ' + VkUI.formatPrecio(engReq) + '</div>';
            html += '</div>';

            html += '<div style="margin-bottom:16px;">';
            html += '<label class="vk-form-label">Plazo (max ' + plazoMaxC + ' meses):</label>';
            html += '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
            html += self._renderPlazoBtns([12, 18, 24, 36], Math.min(self._plazoMeses, plazoMaxC), plazoMaxC);
            html += '</div></div>';

            html += '<div id="vk-calc-results-c"></div>';

            html += '<button class="vk-btn vk-btn--primary" id="vk-recalcular-condicional" style="margin-top:12px;">' +
                '&#128260; Recalcular con estos valores' +
                '</button>';

            // Alternativas
            html += '<div style="border-top:1px solid #FFB300;margin-top:16px;padding-top:12px;">';
            html += '<p style="font-size:13px;color:#555;text-align:center;">O elige otra forma de pago:</p>';
            html += '<div style="display:flex;gap:8px;margin-top:8px;">';
            html += '<button class="vk-btn vk-btn--secondary" id="vk-switch-contado" style="flex:1;font-size:12px;padding:8px;">Contado</button>';
            if (modelo.tieneMSI) {
                html += '<button class="vk-btn vk-btn--secondary" id="vk-switch-msi" style="flex:1;font-size:12px;padding:8px;">' + modelo.msiMeses + ' MSI</button>';
            }
            html += '</div></div>';
            html += '</div>';

            // Actualizar slider CONDICIONAL
            setTimeout(function() {
                self._bindCondicional(modelo, plazoMaxC, engMin);
            }, 200);

        } else {
            // ── NO_VIABLE ───────────────────────────────────────────────────
            html += '<div style="background:#FFEBEE;border:2px solid #E53935;border-radius:12px;padding:20px;text-align:center;">';
            html += '<div style="font-size:40px;">&#10060;</div>';
            html += '<h3 style="color:#C62828;margin:8px 0;">No es posible el credito en este momento</h3>';
            html += '<p style="font-size:13px;color:#555;margin-bottom:16px;">' +
                'Lo sentimos. Sin embargo, puedes adquirir tu Voltika de otras formas:' +
                '</p>';
            html += '<div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">';
            html += '<button class="vk-btn vk-btn--secondary" id="vk-switch-contado" style="flex:1;min-width:120px;">Pago contado</button>';
            if (modelo.tieneMSI) {
                html += '<button class="vk-btn vk-btn--secondary" id="vk-switch-msi" style="flex:1;min-width:120px;">' + modelo.msiMeses + ' MSI</button>';
            }
            html += '</div>';
            html += '<p style="font-size:12px;color:#9CA3AF;margin-top:16px;">' +
                'Tambien puedes hablar con un asesor: <strong>ventas@voltika.com.mx</strong>' +
                '</p>';
            html += '</div>';
        }

        $('#vk-credito-resultado').html(html).slideDown(400);
        $('html, body').animate({ scrollTop: $('#vk-credito-resultado').offset().top - 20 }, 300);
    },

    _bindCondicional: function(modelo, plazoMaxC, engMinFrac) {
        var self = this;

        $(document).on('input', '#vk-enganche-slider-c', function() {
            var pct     = parseInt($(this).val()) / 100;
            var enganche = Math.round(modelo.precioContado * pct);
            $('#vk-enganche-display-c').html(Math.round(pct * 100) + '% — ' + VkUI.formatPrecio(enganche));

            // Actualizar calculadora
            var credito = VkCalculadora.calcular(modelo.precioContado, pct, self._plazoMeses);
            $('#vk-calc-results-c').html(self._renderCalcResults(modelo, credito));
            self._enganchePct = pct;
        });
    }
};
