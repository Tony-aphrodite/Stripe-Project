/* ==========================================================================
   Voltika - PASO 4A: Payment Form (Contado / MSI)
   Order summary + payment options + contact form + Stripe stub
   ========================================================================== */

var Paso4A = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var state = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        if (!modelo) return;

        var total = modelo.precioContado + state.costoLogistico;
        var msiPago = Math.round(total / modelo.msiMeses);

        var html = '';

        // Top bar: card logos
        html += '<div style="text-align:center;padding:8px 0;margin-bottom:8px;">' +
            '<span style="font-size:18px;">' + VkUI.renderCardLogos() + '</span>' +
            '</div>';

        // Header
        html += '<h2 class="vk-paso__titulo">PASO 4</h2>';
        html += '<p class="vk-paso__subtitulo">Confirma tu forma de pago segura</p>';

        // Order summary
        html += '<div class="vk-summary">';
        html += '<div style="font-weight:700;font-size:15px;margin-bottom:12px;">Resumen de tu compra</div>';

        html += '<div class="vk-summary__row">';
        html += '<span class="vk-summary__label">Modelo:</span>';
        html += '<span class="vk-summary__value">' + modelo.nombre + ' - ' + (state.colorSeleccionado || 'Negro') + '</span>';
        html += '</div>';

        html += '<div class="vk-summary__row">';
        html += '<span class="vk-summary__label">Entrega en:</span>';
        html += '<span class="vk-summary__value">' + (state.ciudad || '--') + ', ' + (state.estado || '--') + '</span>';
        html += '</div>';

        html += '<div class="vk-summary__row">';
        html += '<span class="vk-summary__label">Entrega estimada:</span>';
        html += '<span class="vk-summary__value">' + VOLTIKA_PRODUCTOS.config.entregaDiasHabiles + ' dias habiles</span>';
        html += '</div>';

        html += '<div style="font-size:12px;color:var(--vk-text-muted);margin:8px 0;">' +
            'Asesor Voltika confirma la ubicacion exacta del centro autorizado entre 24 a 48 horas despues del pago.' +
            '</div>';

        html += '<div class="vk-summary__row">';
        html += '<span class="vk-summary__label">Costo logistico:</span>';
        html += '<span class="vk-summary__value">' + VkUI.formatPrecio(state.costoLogistico) + ' MXN</span>';
        html += '</div>';

        html += '<div class="vk-summary__row vk-summary__row--total">';
        html += '<span>Total a pagar hoy:</span>';
        html += '<span>' + VkUI.formatPrecio(total) + ' MXN</span>';
        html += '</div>';

        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-top:8px;">' +
            '&bull; o ' + modelo.msiMeses + ' pagos de ' + VkUI.formatPrecio(msiPago) + ' MXN <span style="color:var(--vk-text-muted);">(' + modelo.msiMeses + ' MSI sin intereses)</span>' +
            '</div>';

        html += '</div>'; // end summary

        // Payment options (2 columns)
        html += '<div class="vk-payment-options">';

        // Option 1: Pago unico
        html += '<div class="vk-payment-option" data-pago-tipo="unico">';
        html += '<div class="vk-payment-option__title">Pago unico<br>100% seguro</div>';
        html += '<div class="vk-payment-option__bullet">&#10004; Pago protegido y encriptado</div>';
        html += '<div class="vk-payment-option__bullet">&#10004; Confirmacion bancaria al instante</div>';
        html += '<div class="vk-payment-option__bullet">&#10004; Atencion personalizada post-venta</div>';
        html += '<button class="vk-btn vk-btn--primary" style="width:100%;margin:12px 0 0;font-size:13px;padding:10px;" data-pago-tipo="unico">' +
            'PAGAR ' + VkUI.formatPrecio(total) + ' MXN</button>';
        html += '</div>';

        // Option 2: MSI
        html += '<div class="vk-payment-option" data-pago-tipo="msi">';
        html += '<div class="vk-payment-option__title">' + modelo.msiMeses + ' MSI sin intereses<br><small>Tu moto hoy, sin pagar todo de golpe</small></div>';
        html += '<div class="vk-payment-option__bullet">&#10004; ' + modelo.msiMeses + ' pagos fijos de ' + VkUI.formatPrecio(msiPago) + ' MXN</div>';
        html += '<div class="vk-payment-option__bullet">&#10004; Sin intereses ni cargos ocultos</div>';
        html += '<div class="vk-payment-option__bullet">&#10004; Cargo automatico seguro cada mes</div>';
        html += '<button class="vk-btn vk-btn--secondary" style="width:100%;margin:12px 0 0;font-size:13px;padding:10px;" data-pago-tipo="msi">' +
            'PAGAR PRIMER CARGO</button>';
        html += '</div>';

        html += '</div>'; // end payment options

        // Security badge
        html += '<div class="vk-security">';
        html += '<div class="vk-security__title">&#128274; Compra 100% segura</div>';
        html += '<div class="vk-security__note">' +
            'Voltika procesa pagos con tecnologia bancaria certificada.' +
            '</div>';
        html += '</div>';

        // Contact info note
        html += '<p style="text-align:center;font-size:12px;color:var(--vk-text-muted);margin-top:16px;">' +
            'Los datos para facturar se te pediran posteriormente.' +
            '</p>';

        // Checkout form (hidden until payment option selected)
        html += '<div id="vk-checkout-form" style="display:none;">';
        html += this.renderCheckoutForm(modelo, total, msiPago);
        html += '</div>';

        $('#vk-pago-container').html(html);
    },

    renderCheckoutForm: function(modelo, total, msiPago) {
        var html = '';

        html += '<div style="border-top:2px solid var(--vk-border);margin-top:24px;padding-top:24px;">';

        // Voltika logo / header
        html += '<div style="text-align:center;margin-bottom:16px;">';
        html += '<div style="font-size:22px;font-weight:800;">&#9745; voltika</div>';
        html += '<div style="font-size:28px;font-weight:800;margin-top:8px;">Total hoy: ' + VkUI.formatPrecio(total) + ' MXN</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-muted);margin-top:4px;">' +
            '&#10004; 9 MSI disponibles ' + VkUI.renderCardLogos() +
            '</div>';
        html += '</div>';

        // Order mini-summary
        html += '<div style="background:var(--vk-bg-light);padding:12px;border-radius:8px;margin-bottom:16px;font-size:13px;">';
        html += '<div>&bull; ' + modelo.nombre + ' - ' + (this.app.state.colorSeleccionado || 'Negro') + '</div>';
        html += '<div>&bull; Tiempo de entrega: 7 a 10 dias habiles en tu ciudad</div>';
        html += '<div>&bull; Te contactara un asesor personal Voltika en 24 a 48 horas</div>';
        html += '</div>';

        // Terms
        html += '<div class="vk-checkbox-group">';
        html += '<input type="checkbox" class="vk-checkbox" id="vk-terms-check">';
        html += '<label class="vk-checkbox-label" for="vk-terms-check">' +
            'Acepto los terminos y condiciones y el aviso de privacidad' +
            '</label>';
        html += '</div>';

        // Payment buttons
        html += '<button class="vk-btn vk-btn--primary" id="vk-pagar-total" style="margin-bottom:8px;">' +
            'PAGAR ' + VkUI.formatPrecio(total) + ' MXN</button>';
        html += '<button class="vk-btn vk-btn--secondary" id="vk-pagar-msi">' +
            '9 MSI de ' + VkUI.formatPrecio(msiPago) + ' / mes</button>';

        // Contact form
        html += '<div style="margin-top:24px;">';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Nombre completo</label>';
        html += '<input type="text" class="vk-form-input" id="vk-nombre" placeholder="Juan Perez">';
        html += '</div>';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Correo electronico</label>';
        html += '<input type="email" class="vk-form-input" id="vk-email" placeholder="juanperez@email.com">';
        html += '</div>';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Telefono</label>';
        html += '<div class="vk-phone-group">';
        html += '<div class="vk-phone-prefix">&#127474;&#127485; +52</div>';
        html += '<input type="tel" class="vk-form-input" id="vk-telefono" placeholder="1234 5678" maxlength="15">';
        html += '</div>';
        html += '</div>';

        // Stripe card element placeholder (Phase 2: real Stripe Elements here)
        html += '<div style="border:1.5px solid var(--vk-border);border-radius:6px;padding:14px;margin:16px 0;background:#FAFAFA;text-align:center;color:var(--vk-text-muted);font-size:13px;">' +
            '&#128179; Formulario de tarjeta Stripe (se activa con credenciales reales)' +
            '</div>';

        html += '<div style="text-align:center;font-size:12px;color:var(--vk-text-muted);">' +
            'Pago cifrado SSL &middot; ' + VkUI.renderCardLogos() +
            '</div>';

        html += '</div>'; // end contact form
        html += '</div>'; // end checkout

        return html;
    },

    bindEvents: function() {
        var self = this;

        // Payment option selection
        $(document).on('click', '.vk-payment-option, .vk-payment-option .vk-btn', function(e) {
            e.stopPropagation();
            $('#vk-checkout-form').slideDown(400);
            VkUI.scrollToTop();

            setTimeout(function() {
                $('html, body').animate({
                    scrollTop: $('#vk-checkout-form').offset().top - 20
                }, 300);
            }, 450);
        });

        // Pay buttons (Phase 1: demo)
        $(document).on('click', '#vk-pagar-total, #vk-pagar-msi', function() {
            var termsChecked = $('#vk-terms-check').is(':checked');
            if (!termsChecked) {
                alert('Por favor acepta los terminos y condiciones.');
                return;
            }

            var valid = true;
            valid = VkValidacion.validarCampo($('#vk-nombre'), VkValidacion.nombre, 'Ingresa tu nombre completo') && valid;
            valid = VkValidacion.validarCampo($('#vk-email'), VkValidacion.email, 'Ingresa un correo valido') && valid;
            valid = VkValidacion.validarCampo($('#vk-telefono'), VkValidacion.telefono, 'Ingresa un telefono valido (10 digitos)') && valid;

            if (valid) {
                self.app.state.nombre = $('#vk-nombre').val();
                self.app.state.email = $('#vk-email').val();
                self.app.state.telefono = $('#vk-telefono').val();

                // Phase 1: Demo success
                alert('DEMO: Pago procesado exitosamente.\n\nEn produccion, aqui se conectaria Stripe para procesar el cobro real.\n\nModelo: ' + self.app.state.modeloSeleccionado.toUpperCase() + '\nColor: ' + self.app.state.colorSeleccionado + '\nCiudad: ' + self.app.state.ciudad);
            }
        });
    }
};
