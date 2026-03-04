/* ==========================================================================
   Voltika - Crédito: Consentimiento Círculo de Crédito
   Explicit consent before querying credit bureau
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

        html += VkUI.renderBackButton('credito-otp');

        html += '<h2 class="vk-paso__titulo">Autorización crediticia</h2>';

        html += '<div class="vk-card">';
        html += '<div style="padding:24px 20px;">';

        // Círculo de Crédito branding
        html += '<div style="text-align:center;margin-bottom:20px;">';
        html += '<div style="font-size:48px;margin-bottom:8px;">&#128203;</div>';
        html += '<div style="font-size:18px;font-weight:700;color:var(--vk-text-primary);">' +
            'Círculo de Crédito</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-top:4px;">' +
            'Consulta de historial crediticio</div>';
        html += '</div>';

        // Explanation
        html += '<div style="background:var(--vk-bg-light);border-radius:8px;padding:16px;margin-bottom:20px;">';
        html += '<p style="font-size:14px;color:var(--vk-text-primary);margin-bottom:12px;font-weight:600;">' +
            '¿Qué consultaremos?</p>';
        html += '<ul style="font-size:13px;color:var(--vk-text-secondary);padding-left:20px;margin:0;">';
        html += '<li style="margin-bottom:6px;">Tu historial de pagos en instituciones financieras</li>';
        html += '<li style="margin-bottom:6px;">Tu score crediticio (FICO Score)</li>';
        html += '<li style="margin-bottom:6px;">Obligaciones financieras actuales</li>';
        html += '</ul>';
        html += '</div>';

        // Privacy note
        html += '<div style="background:#E3F2FD;border-radius:8px;padding:12px;margin-bottom:20px;font-size:12px;color:#1565C0;">';
        html += '<strong>&#128274; Tu información está protegida.</strong> ';
        html += 'Esta consulta no afecta tu score crediticio y tus datos se manejan conforme a la Ley Federal de Protección de Datos Personales.';
        html += '</div>';

        // Consent checkbox
        html += '<div class="vk-checkbox-group" style="margin-bottom:20px;">';
        html += '<input type="checkbox" class="vk-checkbox" id="vk-consent-buro">';
        html += '<label class="vk-checkbox-label" for="vk-consent-buro" style="font-size:13px;">' +
            'Autorizo expresamente a Voltika S.A. de C.V. a consultar mi historial crediticio ante ' +
            'Círculo de Crédito y/o cualquier Sociedad de Información Crediticia.' +
            '</label>';
        html += '</div>';

        // Error
        html += '<div id="vk-consent-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        // CTA
        html += '<button class="vk-btn vk-btn--primary" id="vk-consent-continuar" disabled>' +
            '<span id="vk-consent-label">Autorizar y continuar</span>' +
            '<span id="vk-consent-spinner" style="display:none;">' + VkUI.renderSpinner() + ' Consultando...</span>' +
            '</button>';

        html += '</div>'; // end padding
        html += '</div>'; // end card

        jQuery('#vk-credito-consentimiento-container').html(html);
    },

    bindEvents: function() {
        var self = this;

        // Enable/disable CTA based on checkbox
        jQuery(document).off('change', '#vk-consent-buro');
        jQuery(document).on('change', '#vk-consent-buro', function() {
            jQuery('#vk-consent-continuar').prop('disabled', !jQuery(this).is(':checked'));
        });

        jQuery(document).off('click', '#vk-consent-continuar');
        jQuery(document).on('click', '#vk-consent-continuar', function() {
            self._consultarBuro();
        });
    },

    _consultarBuro: function() {
        var state = this.app.state;
        var self  = this;

        if (!jQuery('#vk-consent-buro').is(':checked')) {
            jQuery('#vk-consent-error').text('Debes autorizar la consulta para continuar.').show();
            return;
        }

        // Show loading
        jQuery('#vk-consent-continuar').prop('disabled', true);
        jQuery('#vk-consent-label').hide();
        jQuery('#vk-consent-spinner').show();
        jQuery('#vk-consent-error').hide();

        // Split name for bureau query
        var partes          = (state.nombre || '').trim().split(/\s+/);
        var primerNombre    = partes.length > 0 ? partes[0] : '';
        var apellidoPaterno = partes.length > 1 ? partes[1] : '';
        var apellidoMaterno = partes.length > 2 ? partes.slice(2).join(' ') : '';

        jQuery.ajax({
            url: 'php/consultar-buro.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                primerNombre:    primerNombre,
                apellidoPaterno: apellidoPaterno,
                apellidoMaterno: apellidoMaterno,
                fechaNacimiento: state.fechaNacimiento || '',
                CP:              state.cpDomicilio || '',
                ciudad:          state.ciudad || '',
                estado:          state.estado || ''
            }),
            success: function(res) {
                state._buroResult = res;
                state._buroConsent = true;
                self.app.irAPaso('credito-identidad');
            },
            error: function() {
                // Fallback: continue without bureau data
                state._buroResult = { success: false, fallback: true };
                state._buroConsent = true;
                self.app.irAPaso('credito-identidad');
            }
        });
    }
};
