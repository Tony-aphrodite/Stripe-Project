/* ==========================================================================
   Voltika - PASO 4B: Credit Application Flow
   Selection summary + 5-step credit process + Truora/Circulo stubs
   ========================================================================== */

var Paso4B = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var state = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        if (!modelo) return;

        var credito = VkCalculadora.calcular(modelo.precioContado, modelo.enganchePorcentaje);
        var img = VkUI.getImagenMoto(modelo.id, state.colorSeleccionado || modelo.colorDefault);

        var html = '';

        // Header
        html += '<div style="text-align:center;margin-bottom:16px;">';
        html += '<div style="font-size:22px;font-weight:800;">&#9745; voltika</div>';
        html += '<h2 style="font-size:24px;font-weight:800;margin-top:8px;">&#161;Toma solo 2 minutos! &#9200;</h2>';
        html += '</div>';

        // Selection summary card
        html += '<div class="vk-credit-summary">';
        html += '<div style="font-weight:700;font-size:15px;margin-bottom:12px;">Resumen de tu seleccion:</div>';

        html += '<div class="vk-credit-summary__model">';
        html += '<div class="vk-credit-summary__details">';
        html += '<div><strong>Modelo:</strong> ' + modelo.nombre + '</div>';
        html += '<div><strong>Enganche:</strong> <span style="color:var(--vk-green-primary);font-weight:700;">' + VkUI.formatPrecio(credito.enganche) + '</span></div>';
        html += '<div>Desde ' + VkUI.formatPrecio(credito.pagoSemanal) + ' por semana</div>';
        html += '<div><strong>Color:</strong> ' + (state.colorSeleccionado || modelo.colorDefault) + '</div>';
        html += '</div>';
        html += '<img class="vk-credit-summary__img" src="' + img + '" alt="' + modelo.nombre + '">';
        html += '</div>';

        // Delivery info
        html += '<div style="background:var(--vk-green-soft);padding:10px;border-radius:8px;margin-top:12px;font-size:13px;">';
        html += '<span style="color:var(--vk-green-primary);">&#10004;</span> ';
        html += '<strong>Entrega en tu ciudad en punto aliado Voltika</strong><br>';
        html += '<span style="font-size:12px;color:var(--vk-text-secondary);">Se entrega en permiso provisional y documentos para que puedas emplacar facilmente</span>';
        html += '</div>';

        html += '</div>'; // end credit summary

        // 5 Steps
        html += '<div class="vk-credit-steps">';
        html += '<div class="vk-credit-steps__title">5 pasos muy sencillos:</div>';

        var steps = [
            {
                titulo: 'Verifica tu identidad',
                desc: 'INE y selfie',
                icono: '&#128196;'
            },
            {
                titulo: 'Confirma tu lugar de entrega cercano',
                desc: 'Puedes elegir un punto aliado Voltika',
                icono: '&#128205;'
            },
            {
                titulo: 'Hablamos contigo y te guiamos en persona',
                desc: 'Tu asesor Voltika te llama, resuelve tus dudas y agenda la entrega',
                icono: '&#128222;'
            },
            {
                titulo: 'Paga tu enganche de forma segura',
                desc: 'Puedes pagar con tarjeta, efectivo o transferencia',
                icono: '&#128179;'
            },
            {
                titulo: 'Firma contrato y recibe tu moto',
                desc: 'Activamos pagos semanales con tu tarjeta',
                icono: '&#128221;'
            }
        ];

        for (var i = 0; i < steps.length; i++) {
            var step = steps[i];
            html += '<div class="vk-credit-step">';
            html += '<div class="vk-credit-step__number">' + (i + 1) + '</div>';
            html += '<div class="vk-credit-step__content">';
            html += '<div class="vk-credit-step__title">' + step.icono + ' ' + step.titulo + '</div>';
            html += '<div class="vk-credit-step__desc">' + step.desc + '</div>';
            html += '</div>';
            html += '</div>';
        }

        html += '</div>'; // end credit steps

        // CTA
        html += '<button class="vk-btn vk-btn--blue" id="vk-iniciar-credito">Iniciar proceso</button>';
        html += '<p style="text-align:center;font-size:13px;color:var(--vk-text-muted);margin-top:8px;">Toma menos de 2 minutos.</p>';

        // Credit calculator section (expandable)
        html += '<div style="margin-top:24px;border-top:1px solid var(--vk-border);padding-top:16px;">';
        html += '<button id="vk-toggle-calculadora" style="background:none;border:none;cursor:pointer;font-family:var(--vk-font-family);font-size:14px;color:var(--vk-green-primary);font-weight:600;width:100%;text-align:center;padding:8px;">' +
            '&#128200; Ver calculadora de credito detallada &#9660;' +
            '</button>';
        html += '<div id="vk-calculadora-panel" style="display:none;">';
        html += this.renderCalculadora(modelo, credito);
        html += '</div>';
        html += '</div>';

        // Demo result panel (hidden)
        html += '<div id="vk-credito-resultado" style="display:none;margin-top:24px;"></div>';

        $('#vk-credito-container').html(html);
    },

    renderCalculadora: function(modelo, credito) {
        var html = '';

        html += '<div style="padding:16px 0;">';
        html += '<div style="font-weight:700;font-size:15px;margin-bottom:12px;">Calculadora de credito</div>';

        // Enganche slider
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Enganche: <strong id="vk-enganche-display">' +
            Math.round(credito.enganchePorcentaje * 100) + '% (' + VkUI.formatPrecio(credito.enganche) + ')</strong></label>';
        html += '<input type="range" id="vk-enganche-slider" min="30" max="80" value="30" step="5" ' +
            'style="width:100%;accent-color:var(--vk-green-primary);">';
        html += '</div>';

        // Results display
        html += '<div id="vk-calc-results" style="background:var(--vk-bg-light);padding:16px;border-radius:8px;">';
        html += this.renderCalcResults(credito);
        html += '</div>';

        html += '</div>';

        return html;
    },

    renderCalcResults: function(credito) {
        var html = '';
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px;">';
        html += '<div>Precio contado:</div><div style="text-align:right;font-weight:600;">' + VkUI.formatPrecio(credito.precioContado) + '</div>';
        html += '<div>Enganche (' + Math.round(credito.enganchePorcentaje * 100) + '%):</div><div style="text-align:right;font-weight:600;">' + VkUI.formatPrecio(credito.enganche) + '</div>';
        html += '<div>Monto financiado:</div><div style="text-align:right;font-weight:600;">' + VkUI.formatPrecio(credito.montoFinanciado) + '</div>';
        html += '<div>Plazo:</div><div style="text-align:right;font-weight:600;">' + credito.plazoMeses + ' meses (' + credito.numeroPagos + ' pagos)</div>';
        html += '<div>Tasa anual:</div><div style="text-align:right;font-weight:600;">' + credito.tasaAnual + '%</div>';
        html += '<div style="font-size:15px;font-weight:700;border-top:2px solid var(--vk-text-primary);padding-top:8px;margin-top:4px;">Pago semanal:</div>';
        html += '<div style="text-align:right;font-size:18px;font-weight:800;color:var(--vk-green-primary);border-top:2px solid var(--vk-text-primary);padding-top:8px;margin-top:4px;">' + VkUI.formatPrecio(credito.pagoSemanal) + '</div>';
        html += '<div>Total a pagar:</div><div style="text-align:right;font-weight:600;">' + VkUI.formatPrecio(credito.totalConEnganche) + '</div>';
        html += '</div>';
        return html;
    },

    renderDemoResult: function(resultado) {
        var html = '';

        if (resultado === 'aprobado') {
            html += '<div style="background:#E8F5E9;border:2px solid #4CAF50;border-radius:12px;padding:24px;text-align:center;">';
            html += '<div style="font-size:48px;">&#10004;</div>';
            html += '<h3 style="color:#2E7D32;margin:8px 0;">&#161;Felicidades! Credito aprobado</h3>';
            html += '<p style="font-size:14px;color:var(--vk-text-secondary);">Se ha generado tu registro. Recibiras un mensaje de WhatsApp y un correo con los siguientes pasos.</p>';
            html += '<p style="font-size:13px;color:var(--vk-text-muted);margin-top:12px;">DEMO: En produccion se crearia registro en Zoho y se iniciaria flujo Truora.</p>';
            html += '</div>';
        } else if (resultado === 'mas-enganche') {
            html += '<div style="background:#FFF8E1;border:2px solid #FFB300;border-radius:12px;padding:24px;text-align:center;">';
            html += '<div style="font-size:48px;">&#9888;</div>';
            html += '<h3 style="color:#F57F17;margin:8px 0;">Se requiere mayor enganche</h3>';
            html += '<p style="font-size:14px;color:var(--vk-text-secondary);">Tu historial crediticio requiere un enganche mayor. Ajusta el monto en la calculadora.</p>';
            html += '<button class="vk-btn vk-btn--primary" onclick="$(\'#vk-toggle-calculadora\').click();" style="margin-top:12px;">Ajustar enganche</button>';
            html += '</div>';
        } else {
            html += '<div style="background:#FFEBEE;border:2px solid #E53935;border-radius:12px;padding:24px;text-align:center;">';
            html += '<div style="font-size:48px;">&#10060;</div>';
            html += '<h3 style="color:#C62828;margin:8px 0;">Credito no aprobado</h3>';
            html += '<p style="font-size:14px;color:var(--vk-text-secondary);">Lo sentimos, en este momento no pudimos aprobar tu solicitud de credito.</p>';
            html += '<p style="font-size:14px;margin-top:12px;">Puedes comprar tu moto a:</p>';
            html += '<div style="display:flex;gap:12px;margin-top:12px;justify-content:center;">';
            html += '<button class="vk-btn vk-btn--secondary" id="vk-switch-contado" style="width:auto;padding:10px 20px;font-size:14px;">Pago de contado</button>';
            html += '<button class="vk-btn vk-btn--secondary" id="vk-switch-msi" style="width:auto;padding:10px 20px;font-size:14px;">Meses sin intereses</button>';
            html += '</div>';
            html += '</div>';
        }

        return html;
    },

    bindEvents: function() {
        var self = this;

        // Toggle calculator
        $(document).on('click', '#vk-toggle-calculadora', function() {
            $('#vk-calculadora-panel').slideToggle(300);
        });

        // Enganche slider
        $(document).on('input', '#vk-enganche-slider', function() {
            var pct = parseInt($(this).val()) / 100;
            var modelo = self.app.getModelo(self.app.state.modeloSeleccionado);
            var credito = VkCalculadora.calcular(modelo.precioContado, pct);

            self.app.state.enganchePorcentaje = pct;

            $('#vk-enganche-display').html(Math.round(pct * 100) + '% (' + VkUI.formatPrecio(credito.enganche) + ')');
            $('#vk-calc-results').html(self.renderCalcResults(credito));
        });

        // Start credit process (Phase 1: demo with random result)
        $(document).on('click', '#vk-iniciar-credito', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).html(VkUI.renderSpinner() + ' Verificando...');

            // Simulate API call
            setTimeout(function() {
                var resultados = ['aprobado', 'mas-enganche', 'rechazado'];
                var idx = Math.floor(Math.random() * resultados.length);
                var resultado = resultados[idx];

                // For demo, always show approved first time, then random
                if (!self._demoCalled) {
                    resultado = 'aprobado';
                    self._demoCalled = true;
                }

                var html = self.renderDemoResult(resultado);
                $('#vk-credito-resultado').html(html).slideDown(400);

                $btn.prop('disabled', false).html('Iniciar proceso');

                $('html, body').animate({
                    scrollTop: $('#vk-credito-resultado').offset().top - 20
                }, 300);
            }, 2000);
        });

        // Switch to contado/MSI if rejected
        $(document).on('click', '#vk-switch-contado', function() {
            self.app.state.metodoPago = 'contado';
            self.app.irAPaso(4);
        });

        $(document).on('click', '#vk-switch-msi', function() {
            self.app.state.metodoPago = 'msi';
            self.app.irAPaso(4);
        });
    },

    _demoCalled: false
};
