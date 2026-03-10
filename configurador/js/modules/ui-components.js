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
            '<div class="vk-card__banner-line1">&#10003; Entrega <strong>Garantizada</strong> en tu <strong>Ciudad</strong></div>' +
            '<div class="vk-card__banner-line2"><span class="vk-card__banner-icon">&#x1F6E1;</span> Punto Voltika autorizado</div>' +
            '</div>';
    },

    /**
     * Render standard bullets (Moto lista + Documentos)
     */
    renderBullets: function() {
        return '<div class="vk-card__bullets">' +
            '<div class="vk-card__bullet">' +
                '<span class="vk-icon-check">&#10003;</span> ' +
                'Moto lista para circular en tu ciudad \u00b7 <strong>Garant\u00eda incluida</strong>' +
            '</div>' +
            '<div class="vk-card__bullet">' +
                '<span class="vk-icon-check">&#10003;</span> ' +
                'Documentos para que tramites <strong>tus placas</strong> en tu ciudad incluidos' +
            '</div>' +
            '</div>';
    },

    /**
     * Render card logo images (Visa, MC, Amex as text placeholders)
     */
    renderCardLogos: function() {
        var base = (window.VK_BASE_PATH || '');
        return '<span class="vk-card-logos">' +
            '<img src="' + base + 'img/tarjetas/visa.svg" alt="Visa" style="height:20px;vertical-align:middle;">' +
            '<img src="' + base + 'img/tarjetas/mastercard.svg" alt="Mastercard" style="height:20px;vertical-align:middle;margin:0 4px;">' +
            '<img src="' + base + 'img/tarjetas/amex.svg" alt="American Express" style="height:20px;vertical-align:middle;">' +
            '</span>';
    },

    /**
     * Render trust badges footer
     */
    renderTrustBadges: function(metodo) {
        var items = [];
        if (metodo === 'credito') {
            items = [
                'P\u00e1gala con tu ahorro en gasolina',
                'Documentos para placas incluidos',
                'Garant\u00eda Voltika'
            ];
        } else if (metodo === 'msi') {
            items = [
                'Sin gasolina desde hoy',
                'Documentos para placas incluidos',
                'Garant\u00eda Voltika'
            ];
        } else if (metodo === 'contado') {
            items = [
                'Ahorra gasolina cada mes',
                'Documentos para placas incluidos',
                'Garant\u00eda Voltika'
            ];
        } else {
            items = [
                '100% Electrica',
                'Garant\u00eda Voltika',
                'Hasta 9 MSI'
            ];
        }
        var icons = ['&#9889;', '&#9745;', '&#128179;'];
        var html = '<div class="vk-trust-badges">';
        for (var i = 0; i < items.length; i++) {
            html += '<div class="vk-trust-badge">' +
                '<span class="vk-trust-badge__icon">' + icons[i] + '</span> ' + items[i] +
                '</div>';
        }
        html += '</div>';
        return html;
    },

    renderCreditoLogo: function(height) {
        var base = (window.VK_BASE_PATH || '');
        var h = height || 22;
        return '<span class="vk-credito-logo" style="display:inline-flex;align-items:center;vertical-align:middle;">' +
            '<img src="' + base + 'img/credito_bk.svg" alt="crédito voltika" style="height:' + h + 'px;width:auto;">' +
            '</span>';
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
            '<div class="vk-go-electric__logo"><img src="img/goelectric.svg" alt="GO electric" class="vk-go-electric__svg"></div>' +
            '<div class="vk-go-electric__tagline">Movilidad el\u00e9ctrica <em>inteligente</em> para M\u00e9xico</div>' +
            '<div class="vk-go-electric__social">' +
            '&#9889; Cada semana m\u00e1s mexicanos eligen <strong>Voltika</strong>' +
            '</div>' +
            '<div class="vk-go-electric__chevron">&#9660;</div>' +
            '<div class="vk-go-electric__divider"></div>' +
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
