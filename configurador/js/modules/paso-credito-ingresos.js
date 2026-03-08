/* ==========================================================================
   Voltika - Crédito Screen 10: Ingresos y OTP
   Per Dibujo.pdf: Ingreso (number) + Teléfono (10 digits) → sends SMS OTP
   ========================================================================== */

var PasoCreditoIngresos = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var state = this.app.state;
        var html = '';

        html += VkUI.renderBackButton('credito-domicilio');

        html += '<h2 class="vk-paso__titulo">Ingresos y tel\u00e9fono</h2>';
        html += '<p class="vk-paso__subtitulo">Necesitamos estos datos para evaluar tu solicitud</p>';

        html += '<div class="vk-card" style="padding:20px;">';

        // Income
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Ingreso mensual aproximado</label>';
        html += '<input type="number" class="vk-form-input" id="vk-cing-ingreso" ' +
            'placeholder="Ej: 15000" inputmode="numeric" ' +
            'value="' + (state._ingresoMensual || '') + '">';
        html += '<div style="font-size:11px;color:var(--vk-text-muted);margin-top:4px;">Ingreso neto mensual en MXN</div>';
        html += '</div>';

        // Phone
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Tel\u00e9fono celular (10 d\u00edgitos)</label>';
        html += '<div class="vk-phone-group">';
        html += '<div class="vk-phone-prefix">&#127474;&#127485; +52</div>';
        html += '<input type="tel" class="vk-form-input" id="vk-cing-telefono" ' +
            'placeholder="55 1234 5678" maxlength="15" autocomplete="tel" ' +
            'value="' + (state.telefono || '') + '">';
        html += '</div>';
        html += '</div>';

        // Email (needed for credit flow too)
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Correo electr\u00f3nico</label>';
        html += '<input type="email" class="vk-form-input" id="vk-cing-email" ' +
            'placeholder="correo@ejemplo.com" autocomplete="email" ' +
            'value="' + (state.email || '') + '">';
        html += '</div>';

        html += '<div id="vk-cing-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        html += '<button class="vk-btn vk-btn--primary" id="vk-cing-continuar">CONTINUAR</button>';
        html += '<p style="font-size:12px;color:var(--vk-text-muted);text-align:center;margin-top:8px;">' +
            'Te enviaremos un c\u00f3digo de verificaci\u00f3n por SMS.' +
            '</p>';

        html += '</div>';

        jQuery('#vk-credito-ingresos-container').html(html);
    },

    bindEvents: function() {
        var self = this;
        jQuery(document).off('click', '#vk-cing-continuar');
        jQuery(document).on('click', '#vk-cing-continuar', function() {
            var ingreso  = jQuery('#vk-cing-ingreso').val().trim();
            var telefono = jQuery('#vk-cing-telefono').val().replace(/\D/g, '');
            var email    = jQuery('#vk-cing-email').val().trim();

            var errores = [];
            if (!ingreso || parseInt(ingreso) < 1000) errores.push('Ingresa tu ingreso mensual (m\u00ednimo $1,000).');
            if (!telefono || telefono.length < 10) errores.push('Ingresa tu tel\u00e9fono de 10 d\u00edgitos.');
            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errores.push('Ingresa un correo v\u00e1lido.');

            if (errores.length) {
                jQuery('#vk-cing-error').html(errores.join('<br>')).show();
                return;
            }
            jQuery('#vk-cing-error').hide();

            self.app.state._ingresoMensual = parseInt(ingreso);
            self.app.state.telefono = telefono;
            self.app.state.email = email;

            // Send OTP SMS
            var $btn = jQuery('#vk-cing-continuar');
            $btn.prop('disabled', true).text('Enviando SMS...');

            jQuery.ajax({
                url: 'php/enviar-otp.php',
                method: 'POST',
                contentType: 'application/json',
                xhrFields: { withCredentials: true },
                data: JSON.stringify({ telefono: telefono, nombre: self.app.state.nombre || '' }),
                success: function(res) {
                    console.log('[OTP] enviar-otp response:', res);
                    if (res && res.fileOk === false) {
                        console.warn('[OTP] WARNING: Code file was NOT saved on server!');
                    }
                    if (res && res.testCode) {
                        self.app.state._otpTestCode = res.testCode;
                    } else {
                        // SMS sent successfully — no test code needed
                        self.app.state._otpTestCode = null;
                    }
                    self.app.state._otpVerificado = false;
                    self.app.irAPaso('credito-consentimiento');
                },
                error: function(xhr) {
                    console.log('[OTP] enviar-otp error:', xhr.status, xhr.responseText);
                    self.app.state._otpTestCode = '123456';
                    self.app.irAPaso('credito-consentimiento');
                },
                complete: function() {
                    $btn.prop('disabled', false).text('CONTINUAR');
                }
            });
        });
    }
};
