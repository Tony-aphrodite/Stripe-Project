/* ==========================================================================
   Voltika - Crédito Screen 11: Aceptación de acuerdos
   Per Dibujo.pdf: 2 checkboxes + 6-digit OTP input
   When OTP correct + checkboxes checked → "EVALUAR MI SOLICITUD"
   If OTP incorrect → "REENVIAR" + change phone button
   ========================================================================== */

var PasoCreditoConsentimiento = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var state = this.app.state;
        var html = '';

        html += VkUI.renderBackButton('credito-ingresos');

        html += '<h2 class="vk-paso__titulo">Aceptaci\u00f3n de acuerdos</h2>';
        html += '<p class="vk-paso__subtitulo">Verifica tu c\u00f3digo y autoriza la consulta</p>';

        html += '<div class="vk-card" style="padding:20px;">';

        // Phone display
        var telDisplay = state.telefono ? ('+52 ' + state.telefono.replace(/(\d{2})(\d{4})(\d{4})/, '$1 $2 $3')) : '';
        html += '<div style="text-align:center;margin-bottom:16px;">';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);">C\u00f3digo enviado a</div>';
        html += '<div style="font-size:16px;font-weight:700;">' + telDisplay + '</div>';
        html += '</div>';

        // Test code hint
        if (state._otpTestCode) {
            html += '<div style="background:#E3F2FD;border-radius:6px;padding:8px;margin-bottom:12px;text-align:center;font-size:12px;color:#1565C0;">' +
                '&#128161; C\u00f3digo de prueba: <strong>' + state._otpTestCode + '</strong></div>';
        }

        // 6-digit OTP input
        html += '<div class="vk-form-group" style="margin-bottom:16px;">';
        html += '<label class="vk-form-label" style="text-align:center;display:block;">Ingresa el c\u00f3digo de 6 d\u00edgitos</label>';
        html += '<input type="text" class="vk-form-input" id="vk-cons-otp" ' +
            'maxlength="6" inputmode="numeric" pattern="[0-9]*" ' +
            'placeholder="000000" ' +
            'style="text-align:center;font-size:28px;letter-spacing:8px;font-weight:700;padding:14px;">';
        html += '</div>';

        html += '<div style="border-top:1px solid var(--vk-border);margin:16px 0;"></div>';

        // Checkbox 1: TyC
        html += '<div class="vk-checkbox-group" style="margin-bottom:12px;">';
        html += '<input type="checkbox" class="vk-checkbox" id="vk-cons-tyc">';
        html += '<label class="vk-checkbox-label" for="vk-cons-tyc" style="font-size:13px;">' +
            'He le\u00eddo y acepto los ' +
            '<a href="https://voltika.mx/docs/tyc_2026.pdf" target="_blank" style="color:var(--vk-green-primary);">T\u00e9rminos y Condiciones</a> ' +
            'y el Aviso de Privacidad de Voltika.' +
            '</label>';
        html += '</div>';

        // Checkbox 2: Círculo de Crédito consent
        html += '<div class="vk-checkbox-group" style="margin-bottom:16px;">';
        html += '<input type="checkbox" class="vk-checkbox" id="vk-cons-buro">';
        html += '<label class="vk-checkbox-label" for="vk-cons-buro" style="font-size:13px;">' +
            'Autorizo expresamente a Voltika S.A. de C.V. a consultar mi historial crediticio ante ' +
            'C\u00edrculo de Cr\u00e9dito y/o cualquier Sociedad de Informaci\u00f3n Crediticia.' +
            '</label>';
        html += '</div>';

        // Error
        html += '<div id="vk-cons-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        // CTA
        html += '<button class="vk-btn vk-btn--primary" id="vk-cons-evaluar" disabled>' +
            '<span id="vk-cons-label">EVALUAR MI SOLICITUD</span>' +
            '<span id="vk-cons-spinner" style="display:none;">' + VkUI.renderSpinner() + ' Evaluando...</span>' +
            '</button>';

        // Resend + change phone
        html += '<div style="display:flex;gap:10px;justify-content:center;margin-top:12px;">';
        html += '<button class="vk-btn vk-btn--secondary" id="vk-cons-reenviar" style="font-size:12px;padding:8px 14px;">Reenviar c\u00f3digo</button>';
        html += '<button class="vk-btn vk-btn--secondary" id="vk-cons-cambiar-tel" style="font-size:12px;padding:8px 14px;">Cambiar tel\u00e9fono</button>';
        html += '</div>';

        html += '</div>'; // end card

        jQuery('#vk-credito-consentimiento-container').html(html);
    },

    _updateCTA: function() {
        var otp = jQuery('#vk-cons-otp').val().replace(/\D/g, '');
        var tyc = jQuery('#vk-cons-tyc').is(':checked');
        var buro = jQuery('#vk-cons-buro').is(':checked');
        jQuery('#vk-cons-evaluar').prop('disabled', !(otp.length === 6 && tyc && buro));
    },

    bindEvents: function() {
        var self = this;

        jQuery(document).off('input', '#vk-cons-otp')
            .off('change', '#vk-cons-tyc')
            .off('change', '#vk-cons-buro')
            .off('click', '#vk-cons-evaluar')
            .off('click', '#vk-cons-reenviar')
            .off('click', '#vk-cons-cambiar-tel');

        jQuery(document).on('input', '#vk-cons-otp', function() {
            var val = jQuery(this).val().replace(/\D/g, '');
            jQuery(this).val(val);
            self._updateCTA();
        });

        jQuery(document).on('change', '#vk-cons-tyc, #vk-cons-buro', function() {
            self._updateCTA();
        });

        // EVALUAR MI SOLICITUD
        jQuery(document).on('click', '#vk-cons-evaluar', function() {
            self._evaluar();
        });

        // Resend OTP
        jQuery(document).on('click', '#vk-cons-reenviar', function() {
            var tel = self.app.state.telefono;
            jQuery(this).prop('disabled', true).text('Enviando...');
            jQuery.ajax({
                url: 'php/enviar-otp.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ telefono: '+52' + tel, nombre: self.app.state.nombre || '' }),
                success: function(res) {
                    if (res && res.testCode) {
                        self.app.state._otpTestCode = res.testCode;
                    }
                    jQuery('#vk-cons-error').html('&#10004; C\u00f3digo reenviado.').css('color', 'var(--vk-green-primary)').css('background', 'var(--vk-green-soft)').show();
                },
                error: function() {
                    jQuery('#vk-cons-error').html('Error al reenviar. Intenta de nuevo.').css('color', '#C62828').css('background', '#FFEBEE').show();
                },
                complete: function() {
                    jQuery('#vk-cons-reenviar').prop('disabled', false).text('Reenviar c\u00f3digo');
                }
            });
        });

        // Change phone → go back to ingresos
        jQuery(document).on('click', '#vk-cons-cambiar-tel', function() {
            self.app.irAPaso('credito-ingresos');
        });
    },

    _evaluar: function() {
        var self = this;
        var state = self.app.state;
        var otp = jQuery('#vk-cons-otp').val().replace(/\D/g, '');

        // First verify OTP
        jQuery('#vk-cons-evaluar').prop('disabled', true);
        jQuery('#vk-cons-label').hide();
        jQuery('#vk-cons-spinner').show();
        jQuery('#vk-cons-error').hide();

        jQuery.ajax({
            url: 'php/verificar-otp.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ telefono: '+52' + state.telefono, codigo: otp }),
            success: function(res) {
                if (res && res.verificado) {
                    state._otpVerificado = true;
                    // Now query Círculo de Crédito
                    self._consultarBuro();
                } else {
                    jQuery('#vk-cons-error').html('C\u00f3digo incorrecto. Verifica e intenta de nuevo.').css('color', '#C62828').css('background', '#FFEBEE').show();
                    self._resetCTA();
                }
            },
            error: function() {
                // Fallback: accept OTP and proceed
                state._otpVerificado = true;
                self._consultarBuro();
            }
        });
    },

    _consultarBuro: function() {
        var self = this;
        var state = self.app.state;

        jQuery.ajax({
            url: 'php/consultar-buro.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                primerNombre:    state.nombre || '',
                apellidoPaterno: state.apellidoPaterno || '',
                apellidoMaterno: state.apellidoMaterno || '',
                fechaNacimiento: state.fechaNacimiento || '',
                CP:              state.cpDomicilio || '',
                ciudad:          state.ciudad || '',
                estado:          state.estadoDomicilio || state.estado || ''
            }),
            success: function(res) {
                state._buroResult = res;
                state._buroConsent = true;
                self.app.irAPaso('credito-identidad');
            },
            error: function() {
                state._buroResult = { success: false, fallback: true };
                state._buroConsent = true;
                self.app.irAPaso('credito-identidad');
            }
        });
    },

    _resetCTA: function() {
        jQuery('#vk-cons-evaluar').prop('disabled', false);
        jQuery('#vk-cons-label').show();
        jQuery('#vk-cons-spinner').hide();
    }
};
