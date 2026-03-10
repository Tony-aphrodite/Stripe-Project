/* ==========================================================================
   Voltika - Crédito: Aprobado Screen
   Shows "¡Felicidades! Tu crédito Voltika ya fue aprobado."
   Then user clicks "Ver mi plan de pagos" → credito-identidad (Truora)
   ========================================================================== */

var PasoCreditoAprobado = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var state = this.app.state;

        // Get delivery info
        var cpEntrega = state.codigoPostal || '';
        var cpInfo = cpEntrega && typeof VOLTIKA_CP !== 'undefined' ? VOLTIKA_CP._buscar(cpEntrega) : null;
        var ciudadEntrega = cpInfo ? (cpInfo.ciudad + ', ' + cpInfo.estado) : '';

        var html = '';

        // Blue gradient header
        html += '<div class="vk-aprobado-header">';
        html += '<div class="vk-aprobado-header__logo">';
        html += '<img src="img/voltika_logo_h.svg" alt="Voltika">';
        html += '</div>';
        html += '<div class="vk-aprobado-header__check">';
        html += '<svg viewBox="0 0 80 80" width="80" height="80">';
        html += '<circle cx="40" cy="40" r="36" fill="#4CAF50" />';
        html += '<path d="M24 40 L35 52 L56 28" fill="none" stroke="white" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" />';
        html += '</svg>';
        html += '</div>';
        html += '<h2 class="vk-aprobado-header__title">\u00a1Felicidades!</h2>';
        html += '<p class="vk-aprobado-header__subtitle">Tu cr\u00e9dito Voltika<br>ya fue aprobado.</p>';
        html += '</div>';

        // White card area
        html += '<div class="vk-aprobado-body">';

        html += '<p style="text-align:center;font-size:14px;color:var(--vk-text-secondary);margin-bottom:16px;">Tu plan de pagos ya est\u00e1 listo.</p>';

        // Delivery info
        if (cpEntrega) {
            html += '<div class="vk-aprobado-info">';
            html += '<div class="vk-aprobado-info__row">';
            html += '<span class="vk-check"></span>';
            html += '<div>';
            html += '<strong>Tu moto ya est\u00e1 apartada</strong> para entrega en:';
            html += '<div style="font-weight:700;">' + ciudadEntrega + ' (CP ' + cpEntrega + ')</div>';
            html += '</div>';
            html += '</div>';
            html += '</div>';
        }

        // Truora steps preview
        html += '<div class="vk-aprobado-steps">';
        html += '<div class="vk-aprobado-steps__header">';
        html += '<span style="font-size:16px;">&#9201;</span>';
        html += '<strong>Solo toma menos de 30 segundos</strong>';
        html += '</div>';
        html += '<div class="vk-aprobado-steps__item"><span class="vk-check vk-check--sm"></span> Toma una foto de tu INE</div>';
        html += '<div class="vk-aprobado-steps__item"><span class="vk-check vk-check--sm"></span> Selfie r\u00e1pida</div>';
        html += '</div>';

        // CTA
        html += '<button class="vk-btn vk-btn--primary" id="vk-aprobado-continuar" style="margin-top:16px;text-transform:uppercase;letter-spacing:0.5px;">Continuar y confirmar mi identidad &rsaquo;</button>';
        html += '<p style="text-align:center;font-size:12px;color:var(--vk-text-muted);margin-top:6px;">Tu plan de pagos se mostrar\u00e1 en el siguiente paso.</p>';

        // Trust badges
        html += '<div class="vk-aprobado-trust">';
        html += '<div class="vk-aprobado-trust__item">&#128274; Proceso 100% seguro y encriptado</div>';
        html += '<div class="vk-aprobado-trust__item">&#9989; Consulta en Bur\u00f3 de Cr\u00e9dito</div>';
        html += '<div class="vk-aprobado-trust__item">&#128172; Un asesor Voltika estar\u00e1 disponible si necesitas ayuda.</div>';
        html += '</div>';

        html += '</div>'; // end body

        jQuery('#vk-credito-aprobado-container').html(html);
    },

    bindEvents: function() {
        var self = this;
        jQuery(document).off('click', '#vk-aprobado-continuar');
        jQuery(document).on('click', '#vk-aprobado-continuar', function() {
            self.app.irAPaso('credito-identidad');
        });
    }
};
