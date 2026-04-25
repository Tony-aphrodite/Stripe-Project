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
        var base = (window.VK_BASE_PATH || '');
        return '<div class="vk-card__banner" style="position:relative;overflow:hidden;">' +
            '<img src="' + base + 'img/Archivo 4/last_elements-02.png" alt="" style="position:absolute;right:5px;top:50%;transform:translateY(-50%);width:100px;height:auto;opacity:1;">' +
            '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">' +
            '<span style="font-size:18px;">\ud83d\udccd</span>' +
            '<span style="font-size:16px;font-weight:800;">Entrega en tu ciudad</span>' +
            '</div>' +
            '<div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">' +
            '<span style="color:#4CAF50;font-size:14px;">\u2714</span>' +
            '<span style="font-size:13px;">Punto <strong>Voltika</strong> autorizado</span>' +
            '</div>' +
            '<div style="display:flex;align-items:center;gap:6px;">' +
            '<span style="color:#4CAF50;font-size:14px;">\u2714</span>' +
            '<span style="font-size:12px;">Disponible en todo M\u00e9xico</span>' +
            '</div>' +
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
     * Render the unified trust badges footer used at the bottom of every
     * payment-related screen (NO_VIABLE recovery, paso5 resumen, paso4a
     * checkout, optional credito-pago/credito-enganche).
     *
     * Three-row layout (per customer brief 2026-04-26):
     *   1. "Pago seguro · encriptación bancaria"  +  VISA/MC/AMEX/stripe
     *   2. "Voltika respaldada por:"              +  REPUVE / Cincel / Quálitas
     *   3. WhatsApp +52 55 1341 6370 · ventas@voltika.mx
     *
     * Single source of truth — change copy/colors/contacts here, all
     * screens update at once.
     */
    renderTrustFooter: function() {
        var base = (window.VK_BASE_PATH || '');
        var pillStyle = 'display:inline-flex;align-items:center;justify-content:center;background:#fff;border:1px solid #e5e7eb;border-radius:7px;padding:6px 9px;min-height:30px;min-width:48px;';
        var dotStyle  = 'display:inline-flex;align-items:center;gap:6px;';
        var linkStyle = 'display:inline-flex;align-items:center;gap:7px;color:#111;text-decoration:none;';

        var html = '<div class="vk-trust-footer" style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-top:14px;">';

        // Row 1 — Pago seguro + payment brand badges
        html += '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">';
        html += '<div style="display:flex;align-items:center;gap:10px;">'+
                '<span style="width:36px;height:36px;background:#E8F5E9;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;">'+
                    '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#10b981" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>'+
                '</span>'+
                '<div style="line-height:1.2;">'+
                    '<div style="font-size:14px;font-weight:800;color:#111;">Pago seguro <span style="color:#9ca3af;font-weight:500;">·</span></div>'+
                    '<div style="font-size:11.5px;color:#5b6b7a;">encriptación bancaria</div>'+
                '</div>'+
                '</div>';
        html += '<div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;">';
        html += '<span style="' + pillStyle + '"><img src="' + base + 'img/tarjetas/visa.svg" alt="Visa" style="height:14px;width:auto;"></span>';
        html += '<span style="' + pillStyle + '"><img src="' + base + 'img/tarjetas/mastercard.svg" alt="Mastercard" style="height:18px;width:auto;"></span>';
        html += '<span style="' + pillStyle + '"><img src="' + base + 'img/tarjetas/amex.svg" alt="American Express" style="height:14px;width:auto;"></span>';
        html += '<span style="' + pillStyle + '"><span style="font-size:13px;font-weight:700;font-style:italic;color:#635bff;letter-spacing:-.5px;">stripe</span></span>';
        html += '</div>';
        html += '</div>'; // end row 1

        // Divider
        html += '<div style="border-top:1px solid #f3f4f6;margin:14px 0;"></div>';

        // Row 2 — Voltika respaldada por + partner dots
        html += '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">';
        html += '<span style="font-size:12.5px;color:#5b6b7a;">Voltika respaldada por:</span>';
        html += '<div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:13px;font-weight:700;color:#111;">'+
                '<span style="' + dotStyle + '"><span style="width:8px;height:8px;background:#0284c7;border-radius:50%;display:inline-block;"></span>REPUVE</span>'+
                '<span style="' + dotStyle + '"><span style="width:8px;height:8px;background:#10b981;border-radius:50%;display:inline-block;"></span>Cincel</span>'+
                '<span style="' + dotStyle + '"><span style="width:8px;height:8px;background:#8b5cf6;border-radius:50%;display:inline-block;"></span>Quálitas</span>'+
                '</div>';
        html += '</div>';

        // Divider
        html += '<div style="border-top:1px solid #f3f4f6;margin:14px 0;"></div>';

        // Row 3 — Contact info (WhatsApp + email). Tappable on mobile.
        html += '<div style="display:flex;align-items:center;justify-content:center;gap:22px;flex-wrap:wrap;font-size:13px;font-weight:600;color:#111;">';
        html += '<a href="https://wa.me/5215513416370" target="_blank" rel="noopener" style="' + linkStyle + '">'+
                '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'+
                    '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>'+
                '</svg>'+
                '+52 55 1341 6370'+
                '</a>';
        html += '<a href="mailto:ventas@voltika.mx" style="' + linkStyle + '">'+
                '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'+
                    '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>'+
                    '<polyline points="22,6 12,13 2,6"/>'+
                '</svg>'+
                'ventas@voltika.mx'+
                '</a>';
        html += '</div>';

        html += '</div>'; // end footer card
        return html;
    },

    /**
     * Render trust badges footer
     */
    renderTrustBadges: function(metodo) {
        var base = window.VK_BASE_PATH || '';
        var imgAhorro   = '<img src="' + base + 'img/ahorro_gasolina.png" alt="" style="width:45px;height:45px;object-fit:contain;">';
        var imgPlacas   = '<svg width="45" height="45" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">' +
            '<rect x="4" y="12" width="40" height="24" rx="3" fill="#fff" stroke="#1a3a5c" stroke-width="2"/>' +
            '<rect x="7" y="15" width="6" height="8" rx="1" fill="#039fe1" opacity="0.2" stroke="#039fe1" stroke-width="0.8"/>' +
            '<text x="24" y="27" font-size="9" font-weight="800" text-anchor="middle" fill="#1a3a5c" font-family="Arial,sans-serif">MEX</text>' +
            '<text x="24" y="33" font-size="5" text-anchor="middle" fill="#666" font-family="Arial,sans-serif">PLACA</text>' +
            '<circle cx="10" cy="32" r="1.5" fill="#039fe1"/>' +
            '<circle cx="38" cy="32" r="1.5" fill="#039fe1"/>' +
            '<rect x="35" y="15" width="6" height="8" rx="1" fill="#10b981" opacity="0.3" stroke="#10b981" stroke-width="0.8"/>' +
            '</svg>';
        var imgGarantia = '<img src="' + base + 'img/garantia.png" alt="" style="width:45px;height:45px;object-fit:contain;">';

        var badges = [];
        if (metodo === 'credito') {
            badges = [
                { icon: '<img src="' + base + 'img/Archivo 4/last_elements-01.png" alt="" style="width:45px;height:45px;object-fit:contain;">', text: 'Aprobaci\u00f3n en 2 minutos 100% en l\u00ednea' },
                { icon: imgPlacas,   text: 'Documentos para placas incluidos' },
                { icon: imgAhorro,   text: 'P\u00e1gala con lo que hoy gastas en gasolina' },
                { icon: imgGarantia, text: 'Garant\u00eda Voltika' }
            ];
        } else if (metodo === 'msi') {
            badges = [
                { icon: imgAhorro,   text: 'Sin inter\u00e9s a 9 meses' },
                { icon: imgPlacas,   text: 'Documentos para placas incluidos' },
                { icon: imgGarantia, text: 'Garant\u00eda Voltika' }
            ];
        } else if (metodo === 'contado') {
            badges = [
                { icon: imgAhorro,   text: 'Mejor precio de contado' },
                { icon: imgPlacas,   text: 'Documentos para placas incluidos' },
                { icon: imgGarantia, text: 'Garant\u00eda Voltika' }
            ];
        } else {
            badges = [
                { icon: imgAhorro,   text: '100% El\u00e9ctrica' },
                { icon: imgGarantia, text: 'Garant\u00eda Voltika' },
                { icon: imgPlacas,   text: 'Hasta 9 MSI' }
            ];
        }
        var html = '<div class="vk-trust-badges" style="display:flex;justify-content:space-around;gap:8px;padding:14px 8px;">';
        for (var i = 0; i < badges.length; i++) {
            html += '<div style="display:flex;flex-direction:column;align-items:center;text-align:center;flex:1;min-width:0;">' +
                '<div style="margin-bottom:6px;height:38px;display:flex;align-items:center;justify-content:center;">' + badges[i].icon + '</div>' +
                '<div style="font-size:10px;font-weight:600;color:#1a3a5c;line-height:1.3;">' + badges[i].text + '</div>' +
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
