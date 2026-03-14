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
        var badges = [];
        if (metodo === 'credito') {
            badges = [
                { icon: '&#9981;',  text: 'P\u00e1gala con tu ahorro en gasolina' },
                { icon: '&#128203;', text: 'Documentos para placas incluidos' },
                { icon: '&#128737;', text: 'Garant\u00eda Voltika' }
            ];
        } else if (metodo === 'msi') {
            badges = [
                { icon: '&#128179;', text: 'Sin inter\u00e9s a 9 meses' },
                { icon: '&#128203;', text: 'Documentos para placas incluidos' },
                { icon: '&#128737;', text: 'Garant\u00eda Voltika' }
            ];
        } else if (metodo === 'contado') {
            badges = [
                { icon: '&#10004;',  text: 'Mejor precio de contado' },
                { icon: '&#128203;', text: 'Documentos para placas incluidos' },
                { icon: '&#128737;', text: 'Garant\u00eda Voltika' }
            ];
        } else {
            badges = [
                { icon: '&#9889;',   text: '100% El\u00e9ctrica' },
                { icon: '&#128737;', text: 'Garant\u00eda Voltika' },
                { icon: '&#128179;', text: 'Hasta 9 MSI' }
            ];
        }
        var html = '<div class="vk-trust-badges">';
        for (var i = 0; i < badges.length; i++) {
            html += '<div class="vk-trust-badge">' +
                '<span class="vk-trust-badge__icon">' + badges[i].icon + '</span> ' + badges[i].text +
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

    /**
     * Render credito step progress bar (5 steps)
     * @param {number} activeStep - Active step (1-5)
     * Step 1=Datos, 2=Teléfono, 3=Identidad, 4=Entrega, 5=Pago
     */
    renderCreditoStepBar: function(activeStep) {
        var steps = [
            {num:1, label:'Datos'},
            {num:2, label:'Direcci\u00f3n'},
            {num:3, label:'Ingresos'},
            {num:4, label:'Tel\u00e9fono'},
            {num:5, label:'Resultado'}
        ];
        var html = '<div style="margin-bottom:16px;overflow:hidden;">';
        html += '<div style="font-size:12px;color:#999;margin-bottom:6px;">Paso ' + activeStep + ' de 5</div>';
        html += '<div style="display:flex;align-items:center;gap:2px;">';
        for (var i = 0; i < steps.length; i++) {
            var s = steps[i];
            var done = s.num < activeStep;
            var active = s.num === activeStep;
            html += '<div style="display:flex;align-items:center;gap:2px;flex-shrink:0;">';
            if (done) {
                html += '<span style="width:16px;height:16px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;flex-shrink:0;background:#039fe1;color:#fff;">&#10003;</span>';
            } else {
                html += '<span style="width:16px;height:16px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;flex-shrink:0;' +
                    (active ? 'background:#039fe1;color:#fff;' : 'background:#e5e7eb;color:#999;') + '">' + s.num + '</span>';
            }
            html += '<span style="font-size:10px;font-weight:' + (active ? '700' : '400') + ';color:' + (active ? '#039fe1' : (done ? '#039fe1' : '#999')) + ';">' + s.label + '</span>';
            html += '</div>';
            if (i < steps.length - 1) {
                html += '<div style="flex:1;height:1px;background:' + (done ? '#039fe1' : '#e5e7eb') + ';min-width:4px;"></div>';
            }
        }
        html += '</div></div>';
        return html;
    },

    renderGoElectricFooter: function() {
        return '<div class="vk-go-electric">' +
            '<div class="vk-go-electric__logo"><img src="' + (window.VK_BASE_PATH || '') + 'img/goelectric.svg" alt="GO electric" class="vk-go-electric__svg"></div>' +
            '<div class="vk-go-electric__tagline">Movilidad el\u00e9ctrica <em>inteligente</em> para M\u00e9xico</div>' +
            '<div class="vk-go-electric__social">' +
            '&#9889; Cada semana m\u00e1s mexicanos eligen <strong>Voltika</strong>' +
            '</div>' +
            '</div>';
    },

    getGaleriaImagenes: function(modeloId) {
        var base = (window.VK_BASE_PATH || '');
        var folderMap = {
            'm03':        'm03',
            'm05':        'm05',
            'mc10':       'mc10',
            'mino':       'mino',
            'pesgo-plus': 'pesgo',
            'ukko-s':     'ukko'
        };
        var galeria = {
            'm03':        ['1.jpg','2.jpg','3.jpg','4.jpg','5.jpg','gal4.jpg'],
            'm05':        ['1.jpg','2.jpg','3.jpg','4.jpg','5.jpg','gal3.jpg'],
            'mc10':       ['1.jpg','2.jpg','3.jpg','gal1.jpg'],
            'mino':       ['1.jpg','2.jpg','3.jpg','gal1.jpg'],
            'pesgo-plus': ['1.jpg','2.jpg','3.jpg','4.jpg','5.jpg','gal1.jpg'],
            'ukko-s':     ['1.jpg','2.jpg','3.jpg','4.jpg']
        };
        var folder = folderMap[modeloId];
        var files  = galeria[modeloId];
        if (!folder || !files) return [];
        return files.map(function(f) { return base + 'img/' + folder + '/' + f; });
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
