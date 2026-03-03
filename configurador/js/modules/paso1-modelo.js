/* ==========================================================================
   Voltika - PASO 1: Model Selection
   Mobile: ALL models as vertical scrollable cards (per client reference)
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

        // Pre-select model from URL param ?m=model_id
        var paramModelo = null;
        try {
            var urlParams = new URLSearchParams(window.location.search);
            paramModelo   = urlParams.get('m');
        } catch(e) { /* IE fallback: ignore */ }

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

        // Desktop hero (hidden on mobile via CSS)
        html += '<div class="vk-paso1-desktop">';
        html += this._renderDesktopHero(modelos, defaultModelo);
        html += '</div>';

        // Mobile: all model cards (hidden on desktop via CSS)
        html += '<div class="vk-paso1-mobile">';
        html += this._renderMobileAllCards(modelos);
        html += '</div>';

        container.html(html);
    },

    /* ------------------------------------------------------------------ */
    /*  DESKTOP HERO                                                        */
    /* ------------------------------------------------------------------ */

    _renderDesktopHero: function(modelos, defaultModelo) {
        var html = '';

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

        html += '<div class="vk-hero">';

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
            html += 'Autonom\u00eda: ' + defaultModelo.autonomia + ' Km &nbsp;\u00b7&nbsp; Velocidad: ' + defaultModelo.velocidad + ' Km/h';
        }
        html += '</div>';
        html += '</div>';

        html += '<div class="vk-hero__panel">';
        html += '<div class="vk-hero__nombre" id="vk-hero-nombre">' + defaultModelo.nombre + '</div>';
        html += '<div class="vk-hero__subtitulo" id="vk-hero-subtitulo">' + (defaultModelo.subtitulo || '') + '</div>';
        html += '<div class="vk-hero__precio-base" id="vk-hero-precio-base">' +
            'Desde <strong>' + VkUI.formatPrecio(defaultModelo.precioContado) + ' MXN</strong> <span>(contado)</span>' +
            '</div>';

        html += '<div class="vk-hero__metodo-tabs" id="vk-hero-metodo-tabs">';
        html += '<button class="vk-hero__metodo-tab vk-hero__metodo-tab--active" data-htab="credito">Cr\u00e9dito Voltika</button>';
        html += '<button class="vk-hero__metodo-tab" data-htab="msi">9 MSI</button>';
        html += '<button class="vk-hero__metodo-tab" data-htab="contado">Contado</button>';
        html += '</div>';

        html += '<div class="vk-hero__tab-content" id="vk-hero-tab-content">';
        html += this.renderTabCredito(defaultModelo);
        html += '</div>';

        html += '<button class="vk-btn vk-btn--primary vk-hero__cta" id="vk-hero-cta">CONTINUAR</button>';

        html += '<p style="font-size:12px;color:var(--vk-text-muted);text-align:center;margin-top:8px;">' +
            'Podr\u00e1s confirmar tu Punto Voltika antes de continuar.' +
            '</p>';

        html += VkUI.renderTrustBadges();

        html += '</div>';
        html += '</div>';

        return html;
    },

    _updateDesktopHero: function(modeloId, metodo) {
        var modelo = this.app.getModelo(modeloId);
        if (!modelo) return;

        var $img = $('#vk-hero-img');
        $img.css('opacity', 0);
        setTimeout(function() {
            $img.attr('src', VkUI.getImagenMoto(modelo.id, modelo.colorDefault))
                .attr('alt', modelo.nombre)
                .css('opacity', 1);
        }, 150);

        $('#vk-hero-nombre').text(modelo.nombre);
        $('#vk-hero-subtitulo').text(modelo.subtitulo || '');
        $('#vk-hero-precio-base').html(
            'Desde <strong>' + VkUI.formatPrecio(modelo.precioContado) + ' MXN</strong> <span>(contado)</span>'
        );

        var specs = '';
        if (modelo.autonomia) {
            specs = 'Autonom\u00eda: ' + modelo.autonomia + ' Km &nbsp;\u00b7&nbsp; Velocidad: ' + modelo.velocidad + ' Km/h';
        }
        $('#vk-hero-specs').html(specs);

        var $badge = $('#vk-hero-badge');
        if ($badge.length) {
            if (modelo.badge) {
                $badge.html('<span>&#11088;</span> ' + modelo.badge).show();
            } else {
                $badge.hide();
            }
        }

        this._updateHeroTabContent(modelo, metodo);
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
    /*  MOBILE: ALL MODEL CARDS (vertical scroll)                           */
    /* ------------------------------------------------------------------ */

    _renderMobileAllCards: function(modelos) {
        var html = '';

        // Step header
        html += '<div class="vk-paso1-header">';
        html += '<div class="vk-paso1-header__step">PASO 1</div>';
        html += '<h2 class="vk-paso1-header__title">Selecciona un modelo</h2>';
        html += '</div>';

        // Render ALL model cards vertically
        for (var i = 0; i < modelos.length; i++) {
            html += this._renderMobileCard(modelos[i]);

            // Scroll hint between cards (not after last)
            if (i < modelos.length - 1) {
                html += '<div class="vk-scroll-hint">';
                html += 'Desliza para ver m\u00e1s modelos \u2193';
                html += '</div>';
            }

            // Trust badges after each card
            html += VkUI.renderTrustBadges();
        }

        return html;
    },

    _renderMobileCard: function(modelo) {
        var img = VkUI.getImagenMoto(modelo.id, modelo.colorDefault);
        var html = '';

        html += '<div class="vk-card" data-modelo="' + modelo.id + '">';

        // Badge (e.g. "Mas vendido")
        if (modelo.badge) {
            html += '<div class="vk-card__badge">' +
                '<span class="vk-card__badge-star">&#11088;</span> ' +
                modelo.badge +
                '</div>';
        }

        // Motorcycle image
        html += '<div class="vk-card__imagen">' +
            '<img src="' + img + '" alt="' + modelo.nombre + '">' +
            '</div>';

        // Model name + specs + price
        html += '<div class="vk-card__info">';
        html += '<div class="vk-card__nombre">' + modelo.nombre + '</div>';
        if (modelo.autonomia) {
            html += '<div class="vk-card__specs">' +
                'Autonom\u00eda: <strong>' + modelo.autonomia + ' Km</strong> &nbsp;\u00b7&nbsp; ' +
                'Velocidad: <strong>' + modelo.velocidad + ' Km/h</strong>' +
                '</div>';
        }
        html += '<div class="vk-card__precio-base">Desde ' + VkUI.formatPrecio(modelo.precioContado) + ' MXN <span>(contado)</span></div>';
        html += '</div>';

        // Green banner
        html += VkUI.renderBanner();

        // Bullets
        html += VkUI.renderBullets();

        // Payment tabs
        html += '<div class="vk-card__tabs">';
        html += '<button class="vk-tab vk-tab--active" data-tab="credito">Cr\u00e9dito Voltika</button>';
        html += '<button class="vk-tab" data-tab="msi">9 MSI</button>';
        html += '<button class="vk-tab" data-tab="contado">Contado</button>';
        html += '</div>';

        // Tab content: Credito (default active)
        html += '<div class="vk-card__tab-content vk-card__tab-content--active" data-tab-content="credito">';
        html += this.renderTabCredito(modelo);
        html += '</div>';

        // Tab content: MSI
        html += '<div class="vk-card__tab-content" data-tab-content="msi">';
        html += this.renderTabMSI(modelo);
        html += '</div>';

        // Tab content: Contado
        html += '<div class="vk-card__tab-content" data-tab-content="contado">';
        html += this.renderTabContado(modelo);
        html += '</div>';

        html += '</div>'; // end card

        return html;
    },

    /* ------------------------------------------------------------------ */
    /*  TAB CONTENT RENDERERS (shared by desktop + mobile)                  */
    /* ------------------------------------------------------------------ */

    renderTabCredito: function(modelo) {
        var html = '';
        html += '<div class="vk-card__credito-header">';
        html += '<span class="vk-shield-icon">&#128737;</span> cr\u00e9dito<strong>voltika</strong>';
        html += '</div>';
        html += '<div class="vk-card__precio-destacado">Desde <strong>' + VkUI.formatPrecio(modelo.precioSemanal) + '</strong> / semana</div>';
        html += '<div class="vk-card__tab-bullets">';
        html += VkUI.renderTabBullet('Aprobaci\u00f3n en <strong>minutos</strong> \u00b7 Solo INE');
        html += VkUI.renderTabBullet('Costo de env\u00edo a tu ciudad <strong>incluido</strong>');
        html += '</div>';
        html += '<button class="vk-btn vk-btn--primary vk-card__tab-cta" data-modelo="' + modelo.id + '" data-metodo="credito">CONTINUAR</button>';
        html += '<p class="vk-card__tab-microcopy">Podr\u00e1s confirmar tu Punto Voltika antes de continuar.</p>';
        return html;
    },

    renderTabMSI: function(modelo) {
        var html = '';
        if (!modelo.tieneMSI) {
            html += '<div style="padding:12px 0;text-align:center;">';
            html += '<div class="vk-card__precio-secundario">Sin opci\u00f3n MSI para este modelo</div>';
            html += '<div class="vk-card__precio-secundario" style="margin-top:8px;">Contado: ' + VkUI.formatPrecio(modelo.precioContado) + ' MXN</div>';
            html += '</div>';
            return html;
        }
        html += '<div class="vk-card__msi-header">9 MSI ' + VkUI.renderCardLogos() + ' con todas las tarjetas</div>';
        html += '<div class="vk-card__precio-destacado">Desde <strong>' + VkUI.formatPrecio(modelo.precioMSI) + '</strong> / mes</div>';
        html += '<div class="vk-card__tab-bullets">';
        html += VkUI.renderTabBullet('<strong>Sin tr\u00e1mites</strong> \u00b7 Pago con tarjeta');
        html += VkUI.renderTabBullet('Entrega en <strong>Punto Voltika</strong> autorizado en tu ciudad');
        html += VkUI.renderTabBullet('Moto lista para circular + <strong>Documentos para emplacar</strong>');
        html += '</div>';
        html += '<button class="vk-btn vk-btn--primary vk-card__tab-cta" data-modelo="' + modelo.id + '" data-metodo="msi">CONTINUAR</button>';
        html += '<p class="vk-card__tab-microcopy">Podr\u00e1s confirmar tu Punto Voltika antes de continuar.</p>';
        return html;
    },

    renderTabContado: function(modelo) {
        var html = '';
        html += '<div class="vk-card__contado-label">Precio contado</div>';
        html += '<div class="vk-card__precio-destacado"><strong>' + VkUI.formatPrecio(modelo.precioContado) + ' MXN</strong></div>';
        html += '<div class="vk-card__contado-iva">IVA incluido</div>';
        html += '<div class="vk-card__contado-logos">' + VkUI.renderCardLogos() + '</div>';
        html += '<div class="vk-card__tab-bullets">';
        html += VkUI.renderTabBullet('<strong>Sin tr\u00e1mites</strong> adicionales');
        html += VkUI.renderTabBullet('Entrega en <strong>Punto Voltika</strong> autorizado en tu ciudad');
        html += VkUI.renderTabBullet('Moto lista para circular + documentos para emplacar');
        html += '</div>';
        html += '<div class="vk-card__contado-note"><em>Costo log\u00edstico se confirma seg\u00fan tu ciudad.</em></div>';
        html += '<button class="vk-btn vk-btn--primary vk-card__tab-cta" data-modelo="' + modelo.id + '" data-metodo="contado">CONTINUAR</button>';
        html += '<p class="vk-card__tab-microcopy">Podr\u00e1s confirmar tu Punto Voltika antes de continuar.</p>';
        return html;
    },

    /* ------------------------------------------------------------------ */
    /*  EVENTS                                                              */
    /* ------------------------------------------------------------------ */

    bindEvents: function() {
        var self = this;

        // Desktop: model selector tabs
        $(document).off('click', '#vk-paso-1 .vk-dtab');
        $(document).on('click', '#vk-paso-1 .vk-dtab', function() {
            var mid = $(this).data('mid');
            self._activeModeloId = mid;

            $('#vk-paso-1 .vk-dtab').removeClass('vk-dtab--active');
            $(this).addClass('vk-dtab--active');

            self._updateDesktopHero(mid, self._activeMetodo);
        });

        // Desktop: payment method tabs
        $(document).off('click', '#vk-hero-metodo-tabs .vk-hero__metodo-tab');
        $(document).on('click', '#vk-hero-metodo-tabs .vk-hero__metodo-tab', function() {
            var metodo = $(this).data('htab');
            self._activeMetodo = metodo;

            $('#vk-hero-metodo-tabs .vk-hero__metodo-tab').removeClass('vk-hero__metodo-tab--active');
            $(this).addClass('vk-hero__metodo-tab--active');

            var modelo = self.app.getModelo(self._activeModeloId);
            if (modelo) {
                self._updateHeroTabContent(modelo, metodo);
            }
        });

        // Desktop: CTA
        $(document).off('click', '#vk-hero-cta');
        $(document).on('click', '#vk-hero-cta', function() {
            self.app.seleccionarModelo(self._activeModeloId, self._activeMetodo);
        });

        // Mobile: per-card tab switching
        $(document).off('click', '#vk-paso-1 .vk-card__tabs .vk-tab');
        $(document).on('click', '#vk-paso-1 .vk-card__tabs .vk-tab', function() {
            var tab = $(this).data('tab');
            var $card = $(this).closest('.vk-card');

            $(this).closest('.vk-card__tabs').find('.vk-tab').removeClass('vk-tab--active');
            $(this).addClass('vk-tab--active');

            $card.find('.vk-card__tab-content').removeClass('vk-card__tab-content--active');
            $card.find('[data-tab-content="' + tab + '"]').addClass('vk-card__tab-content--active');
        });

        // Mobile: per-tab CTA buttons
        $(document).off('click', '.vk-card__tab-cta');
        $(document).on('click', '.vk-card__tab-cta', function() {
            var modeloId = $(this).data('modelo');
            var metodo   = $(this).data('metodo');
            self.app.seleccionarModelo(modeloId, metodo);
        });
    }
};
