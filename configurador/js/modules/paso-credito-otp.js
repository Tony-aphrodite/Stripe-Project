/* ==========================================================================
   Voltika - Crédito: OTP SMS Verification
   ========================================================================== */

var PasoCreditoOTP = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var state = this.app.state;
        var tel   = state.telefono ? ('+52 ' + state.telefono) : 'tu celular';

        var html = '';
        html += VkUI.renderBackButton('credito-datos');

        html += '<h2 class="vk-paso__titulo">Verificaci\u00f3n por SMS</h2>';

        html += '<div class="vk-card">';
        html += '<div style="padding:20px;text-align:center;">';

        html += '<div style="font-size:40px;margin-bottom:12px;">&#128241;</div>';
        html += '<p style="font-size:15px;margin-bottom:6px;">Enviamos un c\u00f3digo a</p>';
        html += '<p style="font-size:17px;font-weight:700;margin-bottom:20px;">' + tel + '</p>';

        html += '<div class="vk-form-group" style="max-width:200px;margin:0 auto 16px;">';
        html += '<label class="vk-form-label">C\u00f3digo de 4 d\u00edgitos</label>';
        html += '<input type="text" class="vk-form-input" id="vk-otp-input" ' +
            'placeholder="1 2 3 4" maxlength="4" inputmode="numeric" pattern="[0-9]*" ' +
            'style="text-align:center;font-size:24px;letter-spacing:8px;font-weight:700;">';
        html += '</div>';

        html += '<div id="vk-otp-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;text-align:left;"></div>';

        if (state._otpTestCode) {
            html += '<div style="background:#E8F5E9;border-radius:6px;padding:8px 12px;' +
                'font-size:12px;color:#2E7D32;margin-bottom:12px;">' +
                '&#9432; Modo prueba: usa el c\u00f3digo <strong>' + state._otpTestCode + '</strong>' +
                '</div>';
        }

        html += '<button class="vk-btn vk-btn--primary" id="vk-otp-verificar" ' +
            'style="max-width:200px;">Verificar</button>';

        html += '<div style="margin-top:16px;">';
        html += '<button class="vk-btn vk-btn--secondary" id="vk-otp-reenviar" ' +
            'style="font-size:13px;padding:8px 16px;">No recib\u00ed el c\u00f3digo</button>';
        html += '</div>';

        html += '</div>'; // end padding
        html += '</div>'; // end card

        jQuery('#vk-credito-otp-container').html(html);
    },

    bindEvents: function() {
        var self = this;

        jQuery(document).off('click', '#vk-otp-verificar');
        jQuery(document).on('click', '#vk-otp-verificar', function() {
            self._verificar();
        });

        // Auto-submit when 4 digits entered
        jQuery(document).off('input', '#vk-otp-input');
        jQuery(document).on('input', '#vk-otp-input', function() {
            var val = jQuery(this).val().replace(/\D/g, '');
            jQuery(this).val(val);
            if (val.length === 4) self._verificar();
        });

        jQuery(document).off('click', '#vk-otp-reenviar');
        jQuery(document).on('click', '#vk-otp-reenviar', function() {
            self.app.irAPaso('credito-datos');
        });
    },

    _verificar: function() {
        var code  = jQuery('#vk-otp-input').val().trim();
        var state = this.app.state;

        if (!code || code.length !== 4) {
            jQuery('#vk-otp-error').text('Ingresa el c\u00f3digo de 4 d\u00edgitos.').show();
            return;
        }

        var $btn = jQuery('#vk-otp-verificar');
        $btn.prop('disabled', true).text('Verificando...');
        jQuery('#vk-otp-error').hide();

        var self = this;

        jQuery.ajax({
            url: 'php/verificar-otp.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                telefono: '+52' + state.telefono,
                codigo: code
            }),
            success: function(res) {
                if (res && res.valido) {
                    // OTP verificado — navegar a consentimiento Círculo de Crédito
                    self.app.state._otpVerificado = true;
                    $btn.prop('disabled', false).text('Verificar');
                    self.app.irAPaso('credito-consentimiento');
                } else {
                    jQuery('#vk-otp-error').text('C\u00f3digo incorrecto. Verifica e int\u00e9ntalo de nuevo.').show();
                    $btn.prop('disabled', false).text('Verificar');
                }
            },
            error: function() {
                // Fallback: proceder a consentimiento
                self.app.state._otpVerificado = true;
                $btn.prop('disabled', false).text('Verificar');
                self.app.irAPaso('credito-consentimiento');
            }
        });
    }
};
