/* ==========================================================================
   Voltika - Shared UI Components
   Reusable rendering functions used across all pasos
   ========================================================================== */

var VkUI = {

    /**
     * Render the progress bar for steps 2+
     * @param {number} pasoActual - Current step number (1-4)
     */
    renderProgressBar: function(pasoActual, metodo) {
        var steps;
        if (metodo === 'credito') {
            steps = [
                { num: 1, label: 'SELECCION' },
                { num: 2, label: 'CONFIRMACION' },
                { num: 3, label: 'ENTREGA OFICIAL' }
            ];
        } else {
            steps = [
                { num: 1, label: 'DATOS' },
                { num: 2, label: 'PAGO' },
                { num: 3, label: 'ENTREGA OFICIAL' }
            ];
        }

        var html = '';
        for (var i = 0; i < steps.length; i++) {
            var step = steps[i];
            var cls = 'vk-progress__step';
            if (step.num < pasoActual) cls += ' vk-progress__step--completed';
            if (step.num === pasoActual || (pasoActual >= 4 && step.num === 3)) cls += ' vk-progress__step--active';

            html += '<div class="' + cls + '" data-step="' + step.num + '">' + step.num;
            if (step.label) {
                html += '<span class="vk-progress__label">' + step.label + '</span>';
            }
            html += '</div>';

            if (i < steps.length - 1) {
                var lineCls = 'vk-progress__line';
                if (step.num < pasoActual) lineCls += ' vk-progress__line--active';
                html += '<div class="' + lineCls + '"></div>';
            }
        }

        var $progress = $('#vk-progress');
        $progress.html(html);

        if (pasoActual > 1) {
            $progress.addClass('vk-progress--visible');
        } else {
            $progress.removeClass('vk-progress--visible');
        }
    },

    /**
     * Render the green "Punto Voltika" banner
     */
    renderBanner: function() {
        return '<div class="vk-card__banner">' +
            '<span class="vk-card__banner-icon">&#x1F6E1;</span> ' +
            'Entrega en <strong>Punto Voltika autorizado</strong> en tu ciudad' +
            '</div>';
    },

    /**
     * Render standard bullets (Moto lista + Documentos)
     */
    renderBullets: function() {
        return '<div class="vk-card__bullets">' +
            '<div class="vk-card__bullet">' +
                '<span class="vk-icon-check">&#10003;</span> ' +
                'Moto lista para circular en tu ciudad' +
            '</div>' +
            '<div class="vk-card__bullet">' +
                '<span class="vk-icon-check">&#10003;</span> ' +
                'Documentos para emplacar incluidos' +
            '</div>' +
            '</div>';
    },

    /**
     * Render card logo images (Visa, MC, Amex as text placeholders)
     */
    renderCardLogos: function() {
        return '<span class="vk-card__tarjeta-logo" style="color:#1A1F71;font-weight:800;">VISA</span> ' +
            '<span class="vk-card__tarjeta-logo" style="color:#EB001B;font-weight:800;">&#9679;</span>' +
            '<span class="vk-card__tarjeta-logo" style="color:#FF5F00;font-weight:800;">&#9679;</span> ' +
            '<span class="vk-card__tarjeta-logo" style="color:#006FCF;font-weight:800;">AMEX</span>';
    },

    /**
     * Render trust badges footer
     */
    renderTrustBadges: function() {
        return '<div class="vk-trust-badges">' +
            '<div class="vk-trust-badge">' +
                '<span class="vk-trust-badge__icon">&#9889;</span> 100% Electrica' +
            '</div>' +
            '<div class="vk-trust-badge">' +
                '<span class="vk-trust-badge__icon">&#9745;</span> Garantia Voltika' +
            '</div>' +
            '<div class="vk-trust-badge">' +
                '<span class="vk-trust-badge__icon">&#128179;</span> Hasta 9 MSI' +
            '</div>' +
            '</div>';
    },

    /**
     * Render a tab bullet item
     */
    renderTabBullet: function(text) {
        return '<div class="vk-card__tab-bullet">' +
            '<span class="vk-icon-check">&#10003;</span> ' +
            text +
            '</div>';
    },

    /**
     * Render back button
     */
    renderBackButton: function(targetPaso) {
        return '<button class="vk-back-btn" data-go-to="' + targetPaso + '">' +
            '&#8592; Volver' +
            '</button>';
    },

    /**
     * Scroll to top of configurator
     */
    scrollToTop: function() {
        var $el = $('#voltika-configurador');
        if ($el.length) {
            $('html, body').animate({
                scrollTop: $el.offset().top - 20
            }, 300);
        }
    },

    /**
     * Format number as MXN currency
     */
    formatPrecio: function(monto) {
        if (monto === null || monto === undefined) return '$--';
        return '$' + Math.round(monto).toLocaleString('es-MX');
    },

    /**
     * Show a simple loading spinner
     */
    renderSpinner: function() {
        return '<div class="vk-spinner"></div>';
    },

    renderGoElectricFooter: function() {
        return '<div class="vk-go-electric">' +
            '<div class="vk-go-electric__logo">GO<span>electric</span></div>' +
            '<div class="vk-go-electric__tagline">Movilidad el\u00e9ctrica inteligente para M\u00e9xico</div>' +
            '<div class="vk-go-electric__social">' +
            '&#10004; Cada semana m\u00e1s mexicanos eligen <strong>Voltika</strong>' +
            '</div>' +
            '<div class="vk-go-electric__more">' +
            'M\u00e1s modelos disponibles \u2193' +
            '</div>' +
            '</div>';
    },

    getImagenMoto: function(modeloId, color) {
        var modeloFolderMap = {
            'm03':        'm03',
            'm05':        'm05',
            'mc10':       'mc10',
            'mino':       'mino',
            'pesgo-plus': 'pesgo',
            'ukko-s':     'ukko'
        };

        var colorFileMap = {
            'negro':   'black_side',
            'gris':    'grey_side',
            'plata':   'silver_side',
            'azul':    'blue_side',
            'verde':   'green_side',
            'naranja': 'orange_side'
        };

        var folder = modeloFolderMap[modeloId];
        var file   = colorFileMap[color];

        var base = (window.VK_BASE_PATH || '');
        if (folder && file) {
            return base + 'img/' + folder + '/' + file + '.png';
        }
        if (folder) {
            return base + 'img/' + folder + '/model.png';
        }

        return 'data:image/svg+xml,' + encodeURIComponent(
            '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="250" viewBox="0 0 400 250">' +
            '<rect width="400" height="250" rx="12" fill="#1A1A1A"/>' +
            '<text x="200" y="140" text-anchor="middle" fill="#FFF" font-family="Arial,sans-serif" font-size="32" font-weight="bold">' + (modeloId || '').toUpperCase() + '</text>' +
            '</svg>'
        );
    }
};
