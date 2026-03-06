/* ==========================================================================
   Voltika - PASO 1: Model Selection
   Mobile: full-width card per model
   Desktop (1024px+): hero configurator — model tabs + large image + payment panel
   ========================================================================== */

var Paso1 = {

    _activeModeloId: null,
    _activeMetodo: 'credito',

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var container = $('#vk-modelos-container');
        var modelos = VOLTIKA_PRODUCTOS.modelos;

        // Pre-select model from URL param ?m=model_id  (e.g. configurador?m=m03)
        var paramModelo = null;
        try {
            var urlParams = new URLSearchParams(window.location.search);
            paramModelo   = urlParams.get('m');
        } catch(e) { /* IE fallback: ignore */ }

        // Default: URL param > first model with badge > first model
        var defaultModelo = null;
        if (paramModelo) {
            for (var k = 0; k < modelos.length; k++) {
                if (modelos[k].id === paramModelo) { defaultModelo = modelos[k]; break; }
            }
        }
        if (!defaultModelo) {
            for (var i = 0; i < modelos.length; i++) {
                if (modelos[i].badge && !defaultModelo) defaultModelo = modelos[i];
            }
        }
        if (!defaultModelo) defaultModelo = modelos[0];

        this._activeModeloId = defaultModelo.id;
        this._activeMetodo   = 'credito';

        var html = '';

        // ── Desktop hero (hidden on mobile via CSS) ───────────
        html += '<div class="vk-paso1-desktop">';
        html += this._renderDesktopHero(modelos, defaultModelo);
        html += '</div>';

        // ── Mobile hero + sidebar (hidden on desktop via CSS) ─
        html += '<div class="vk-paso1-mobile">';
        html += this._renderMobileHero(modelos, defaultModelo);
        html += VkUI.renderGoElectricFooter();
        html += '</div>';

        container.html(html);
    },

    /* ------------------------------------------------------------------ */
    /*  DESKTOP HERO                                                        */
    /* ------------------------------------------------------------------ */

    _renderDesktopHero: function(modelos, defaultModelo) {
        var html = '';

        // ── Top: horizontal model-selector bar ────────────────
        html += '<div class="vk-dtabs">';
        for (var i = 0; i < modelos.length; i++) {
            var m = modelos[i];
            var cls = m.id === defaultModelo.id ? ' vk-dtab--active' : '';
            html += '<button class="vk-dtab' + cls + '" data-mid="' + m.id + '">';
            if (m.badge) html += '<span class="vk-dtab__star">&#11088;</span> ';
            html += m.nombre;
            html += '</button>';
        }
        html += '</div>';

        // ── Main hero area (image left / panel right) ─────────
        html += '<div class="vk-hero">';

        // Left: product visual
        html += '<div class="vk-hero__visual">';
        html += '<img class="vk-hero__img" id="vk-hero-img" ' +
            'src="' + VkUI.getImagenMoto(defaultModelo.id, defaultModelo.colorDefault) + '" ' +
            'alt="' + defaultModelo.nombre + '">';

        if (defaultModelo.badge) {
            html += '<div class="vk-hero__badge"><span>&#11088;</span> ' + defaultModelo.badge + '</div>';
        } else {
            html += '<div class="vk-hero__badge" id="vk-hero-badge" style="display:none;"></div>';
        }

        html += '<div class="vk-hero__specs" id="vk-hero-specs">';
        if (defaultModelo.autonomia) {
            html += '&#9889;&nbsp;' + defaultModelo.autonomia + ' km &nbsp;&bull;&nbsp; &#128694;&nbsp;' + defaultModelo.velocidad + ' km/h';
        }
        html += '</div>';
        html += '</div>'; // end visual

        // Right: configuration panel
        html += '<div class="vk-hero__panel">';

        html += '<div class="vk-hero__nombre" id="vk-hero-nombre">' + defaultModelo.nombre + '</div>';
        html += '<div class="vk-hero__subtitulo" id="vk-hero-subtitulo">' + (defaultModelo.subtitulo || '') + '</div>';
        html += '<div class="vk-hero__precio-base" id="vk-hero-precio-base">' +
            'Precio contado: <strong>' + VkUI.formatPrecio(defaultModelo.precioContado) + ' MXN</strong>' +
            '</div>';

        // Payment-method label + tabs
        html += '<div class="vk-hero__formas-label">Formas de Pago: <span style="font-weight:400;">(selecciona)</span></div>';
        html += '<div class="vk-hero__metodo-tabs" id="vk-hero-metodo-tabs">';
        html += '<button class="vk-hero__metodo-tab vk-hero__metodo-tab--active" data-htab="credito">Cr&eacute;dito Voltika</button>';
        html += '<button class="vk-hero__metodo-tab" data-htab="msi">MSI</button>';
        html += '<button class="vk-hero__metodo-tab" data-htab="contado">Contado</button>';
        html += '</div>';

        // Tab content
        html += '<div class="vk-hero__tab-content" id="vk-hero-tab-content">';
        html += this.renderTabCredito(defaultModelo);
        html += '</div>';

        html += '<button class="vk-btn vk-btn--primary vk-hero__cta" id="vk-hero-cta">' +
            'VER PLAN &#8250;</button>';

        html += VkUI.renderTrustBadges();

        html += '</div>'; // end panel
        html += '</div>'; // end hero

        return html;
    },

    _updateDesktopHero: function(modeloId, metodo) {
        var modelo = this.app.getModelo(modeloId);
        if (!modelo) return;

        // Image (fade trick via opacity)
        var $img = $('#vk-hero-img');
        $img.css('opacity', 0);
        setTimeout(function() {
            $img.attr('src', VkUI.getImagenMoto(modelo.id, modelo.colorDefault))
                .attr('alt', modelo.nombre)
                .css('opacity', 1);
        }, 150);

        // Text info
        $('#vk-hero-nombre').text(modelo.nombre);
        $('#vk-hero-subtitulo').text(modelo.subtitulo || '');
        $('#vk-hero-precio-base').html(
            'Precio contado: <strong>' + VkUI.formatPrecio(modelo.precioContado) + ' MXN</strong>'
        );

        // Specs
        var specs = '';
        if (modelo.autonomia) {
            specs = '&#9889;&nbsp;' + modelo.autonomia + ' km &nbsp;&bull;&nbsp; &#128694;&nbsp;' + modelo.velocidad + ' km/h';
        }
        $('#vk-hero-specs').html(specs);

        // Badge
        var $badge = $('#vk-hero-badge');
        if ($badge.length) {
            if (modelo.badge) {
                $badge.html('<span>&#11088;</span> ' + modelo.badge).show();
            } else {
                $badge.hide();
            }
        }

        // Tab content
        this._updateHeroTabContent(modelo, metodo);

        // CTA text based on active method
        var ctaText = 'VER PLAN &#8250;';
        if (this._activeMetodo === 'msi') ctaText = 'QUIERO MIS 9 MSI &#8250;';
        if (this._activeMetodo === 'contado') ctaText = 'PAGAR DE CONTADO';
        $('#vk-hero-cta').html(ctaText);
    },

    _updateHeroTabContent: function(modelo, metodo) {
        var content = '';
        if (metodo === 'credito') {
            content = this.renderTabCredito(modelo);
        } else if (metodo === 'msi') {
            content = this.renderTabMSI(modelo);
        } else {
            content = this.renderTabContado(modelo);
        }
        $('#vk-hero-tab-content').html(content);
    },

    /* ------------------------------------------------------------------ */
    /*  MOBILE HERO + SIDEBAR                                               */
    /* ------------------------------------------------------------------ */

    _renderMobileHero: function(modelos, defaultModelo) {
        var html = '';

        // Render ALL model cards vertically
        for (var i = 0; i < modelos.length; i++) {
            html += this.renderCard(modelos[i]);

            // Scroll hint after first card
            if (i === 0 && modelos.length > 1) {
                html += '<div class="vk-scroll-hint">Desliza para ver m\u00e1s modelos &#8595;</div>';
            }
        }

        return html;
    },

    /* ------------------------------------------------------------------ */
    /*  MOBILE CARDS (legacy, kept for compatibility)                       */
    /* ------------------------------------------------------------------ */

    renderCard: function(modelo) {
        var img = VkUI.getImagenMoto(modelo.id, modelo.colorDefault);

        var html = '<div class="vk-card" data-modelo="' + modelo.id + '">';

        html += '<div class="vk-card__imagen">';
        if (modelo.badge) {
            html += '<div class="vk-card__badge">' +
                '<span class="vk-card__badge-star">&#11088;</span> ' +
                modelo.badge +
                '</div>';
        }
        html += '<img src="' + img + '" alt="' + modelo.nombre + '" loading="lazy">' +
            '</div>';

        html += '<div class="vk-card__info-row">';
        html += '<div class="vk-card__nombre">' + modelo.nombre + '</div>';
        if (modelo.autonomia) {
            html += '<div class="vk-card__spec-item">Autonom\u00eda:<br><strong>' + modelo.autonomia + ' Km</strong></div>';
            html += '<div class="vk-card__spec-item">Velocidad:<br><strong>' + modelo.velocidad + ' Km/h</strong></div>';
        }
        html += '</div>';
        html += '<div class="vk-card__precio-base">Desde ' + VkUI.formatPrecio(modelo.precioContado) + ' MXN <span>(contado)</span></div>';

        html += VkUI.renderBanner();

        html += '<div class="vk-card__formas-label">Formas de Pago: <span style="font-weight:400;">(selecciona)</span></div>';
        html += '<div class="vk-card__tabs">';
        html += '<button class="vk-tab vk-tab--active" data-tab="credito">Cr\u00e9dito Voltika</button>';
        html += '<button class="vk-tab" data-tab="msi">MSI</button>';
        html += '<button class="vk-tab" data-tab="contado">Contado</button>';
        html += '</div>';

        html += '<div class="vk-card__tab-content vk-card__tab-content--active" data-tab-content="credito">';
        html += this.renderTabCredito(modelo);
        html += '</div>';

        html += '<div class="vk-card__tab-content" data-tab-content="msi">';
        html += this.renderTabMSI(modelo);
        html += '</div>';

        html += '<div class="vk-card__tab-content" data-tab-content="contado">';
        html += this.renderTabContado(modelo);
        html += '</div>';

        html += VkUI.renderTrustBadges();

        html += '</div>'; // end card

        return html;
    },

    renderTabCredito: function(modelo) {
        var html = '';
        html += '<div class="vk-card__credito-brand"><img class="vk-shield-icon" src="img/voltika_shield.svg" alt="Voltika">cr\u00e9ditovoltika</div>';
        html += '<div class="vk-card__precio-destacado">Desde <strong>' + VkUI.formatPrecio(modelo.precioSemanal) + '</strong> semanales</div>';
        html += '<button class="vk-btn vk-btn--primary vk-card__tab-cta" data-modelo="' + modelo.id + '" data-metodo="credito">' +
            'VER PLAN &#8250;</button>';
        return html;
    },

    renderTabMSI: function(modelo) {
        var html = '';
        if (!modelo.tieneMSI) {
            html += '<div style="padding:12px 0;text-align:center;">';
            html += '<div class="vk-card__precio-secundario">Sin opci\u00f3n MSI para este modelo</div>';
            html += '<div class="vk-card__precio-secundario">Contado: ' + VkUI.formatPrecio(modelo.precioContado) + ' MXN</div>';
            html += '</div>';
            return html;
        }
        html += '<div class="vk-card__precio-destacado"><strong>' + VkUI.formatPrecio(modelo.precioMSI) + '</strong> /mes durante 9 meses ' + VkUI.renderCardLogos() + '</div>';
        html += '<button class="vk-btn vk-btn--primary vk-card__tab-cta" data-modelo="' + modelo.id + '" data-metodo="msi">' +
            'QUIERO MIS 9 MSI &#8250;</button>';
        return html;
    },

    renderTabContado: function(modelo) {
        var html = '';
        html += '<div class="vk-card__contado-label">Precio contado</div>';
        html += '<div class="vk-card__precio-destacado"><strong>' + VkUI.formatPrecio(modelo.precioContado) + ' MXN</strong></div>';
        html += '<div class="vk-card__tab-logos">' + VkUI.renderCardLogos() + '</div>';
        html += '<button class="vk-btn vk-btn--primary vk-card__tab-cta" data-modelo="' + modelo.id + '" data-metodo="contado">' +
            'PAGAR DE CONTADO</button>';
        return html;
    },

    /* ------------------------------------------------------------------ */
    /*  EVENTS                                                              */
    /* ------------------------------------------------------------------ */

    bindEvents: function() {
        var self = this;

        // ── Desktop: model selector tabs ──────────────────────
        $(document).off('click', '#vk-paso-1 .vk-dtab');
        $(document).on('click', '#vk-paso-1 .vk-dtab', function() {
            var mid = $(this).data('mid');
            self._activeModeloId = mid;

            $('#vk-paso-1 .vk-dtab').removeClass('vk-dtab--active');
            $(this).addClass('vk-dtab--active');

            self._updateDesktopHero(mid, self._activeMetodo);
        });

        // ── Desktop: payment method tabs ──────────────────────
        $(document).off('click', '#vk-hero-metodo-tabs .vk-hero__metodo-tab');
        $(document).on('click', '#vk-hero-metodo-tabs .vk-hero__metodo-tab', function() {
            var metodo = $(this).data('htab');
            self._activeMetodo = metodo;

            $('#vk-hero-metodo-tabs .vk-hero__metodo-tab').removeClass('vk-hero__metodo-tab--active');
            $(this).addClass('vk-hero__metodo-tab--active');

            var modelo = self.app.getModelo(self._activeModeloId);
            if (modelo) {
                self._updateHeroTabContent(modelo, metodo);
                // Update CTA text based on method
                var ctaText = 'VER PLAN &#8250;';
                if (metodo === 'msi') ctaText = 'QUIERO MIS 9 MSI &#8250;';
                if (metodo === 'contado') ctaText = 'PAGAR DE CONTADO';
                $('#vk-hero-cta').html(ctaText);
            }
        });

        // ── Desktop: CTA ──────────────────────────────────────
        $(document).off('click', '#vk-hero-cta');
        $(document).on('click', '#vk-hero-cta', function() {
            self.app.seleccionarModelo(self._activeModeloId, self._activeMetodo);
        });

        // ── Mobile: per-card tab switching ────────────────────
        $(document).off('click', '#vk-paso-1 .vk-card__tabs .vk-tab');
        $(document).on('click', '#vk-paso-1 .vk-card__tabs .vk-tab', function() {
            var tab = $(this).data('tab');
            var $card = $(this).closest('.vk-card');

            $(this).closest('.vk-card__tabs').find('.vk-tab').removeClass('vk-tab--active');
            $(this).addClass('vk-tab--active');

            $card.find('.vk-card__tab-content').removeClass('vk-card__tab-content--active');
            $card.find('[data-tab-content="' + tab + '"]').addClass('vk-card__tab-content--active');
        });

        // ── Mobile: per-tab CTA buttons ────────────────────────
        $(document).off('click', '.vk-card__tab-cta');
        $(document).on('click', '.vk-card__tab-cta', function() {
            var modeloId = $(this).data('modelo');
            var metodo   = $(this).data('metodo');
            self.app.seleccionarModelo(modeloId, metodo);
        });
    }
};
