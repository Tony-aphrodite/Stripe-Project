/* ==========================================================================
   Voltika - PASO 1: Model Selection
   Mobile: full-width card per model
   Desktop (1024px+): hero configurator — model tabs + large image + payment panel
   ========================================================================== */

var Paso1 = {

    _activeModeloId: null,
    _activeMetodo: 'credito',

    _modeloLogoMap: {
        'm05': 'img/menu_m05_tx.svg',
        'm03': 'img/menu_m03_tx.svg',
        'ukko-s': 'img/menu_ukko_tx.svg',
        'mc10': 'img/menu_mc10_tx.svg',
        'pesgo-plus': 'img/menu_pesgo_tx.svg',
        'mino': 'img/menu_mino_tx.svg'
    },

    _getModeloLogo: function(modeloId, nombre) {
        var base = window.VK_BASE_PATH || '';
        var src = this._modeloLogoMap[modeloId];
        if (src) {
            return '<img src="' + base + src + '" alt="' + nombre + '" style="height:28px;width:auto;">';
        }
        return nombre;
    },

    init: function(app) {
        this.app = app;
        this.render();
        this._buildFixedNav();
        this.bindEvents();
    },

    _buildFixedNav: function() {
        var modelos = VOLTIKA_PRODUCTOS.modelos;
        var activeId = this._activeModeloId;
        var html = '';
        for (var j = 0; j < modelos.length; j++) {
            var cls = modelos[j].id === activeId ? ' vk-modelo-tab--active' : '';
            html += '<button class="vk-modelo-tab' + cls + '" data-slide="' + j + '" data-mid="' + modelos[j].id + '">';
            html += modelos[j].nombre;
            html += '</button>';
        }
        jQuery('#vk-modelo-tabs-fixed').html(html);
        // Nav starts hidden — scroll handler will show it when user scrolls down
        jQuery('#vk-modelo-nav-fixed').hide();
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
        html += this._renderMobileHero(modelos);
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

        // Gallery carousel
        var galD = VkUI.getGaleriaImagenes(defaultModelo.id);
        var imgD = VkUI.getImagenMoto(defaultModelo.id, defaultModelo.colorDefault);
        var srcsD = galD.length ? galD : [imgD];
        html += '<div class="vk-card__galeria vk-hero__galeria" id="vk-galeria-desktop">';
        if (srcsD.length > 1) {
            html += '<button class="vk-gal__arrow vk-gal__arrow--prev" data-gal="desktop" aria-label="anterior">&#8249;</button>';
            html += '<button class="vk-gal__arrow vk-gal__arrow--next" data-gal="desktop" aria-label="siguiente">&#8250;</button>';
        }
        html += '<div class="vk-gal__track" id="vk-gal-track-desktop">';
        for (var gid = 0; gid < srcsD.length; gid++) {
            html += '<img class="vk-gal__img' + (gid === 0 ? ' vk-gal__img--active' : '') + '" src="' + srcsD[gid] + '" alt="' + defaultModelo.nombre + ' ' + (gid+1) + '" loading="' + (gid === 0 ? 'eager' : 'lazy') + '">';
        }
        html += '</div>';
        if (srcsD.length > 1) {
            html += '<div class="vk-gal__dots">';
            for (var did = 0; did < srcsD.length; did++) {
                html += '<span class="vk-gal__dot' + (did === 0 ? ' vk-gal__dot--active' : '') + '" data-gal="desktop" data-idx="' + did + '"></span>';
            }
            html += '</div>';
        }
        html += '</div>'; // end galeria

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
        html += '<div class="vk-hero__formas-label">&iquest;C&oacute;mo prefieres pagar tu Voltika?</div>';
        html += '<div class="vk-card__formas-sub">Selecciona tu opci&oacute;n</div>';
        html += '<div class="vk-hero__metodo-tabs" id="vk-hero-metodo-tabs">';
        html += '<button class="vk-hero__metodo-tab vk-hero__metodo-tab--active" data-htab="credito">' + VkUI.renderCreditoLogo(16) + '</button>';
        html += '<button class="vk-hero__metodo-tab" data-htab="msi">MSI ' + VkUI.renderCardLogos() + '</button>';
        html += '<button class="vk-hero__metodo-tab" data-htab="contado">Contado ' + VkUI.renderCardLogos() + '</button>';
        html += '</div>';

        // Tab content
        html += '<div class="vk-hero__tab-content" id="vk-hero-tab-content">';
        html += this.renderTabCredito(defaultModelo);
        html += '</div>';

        html += '<button class="vk-btn vk-btn--primary vk-hero__cta" id="vk-hero-cta">' +
            'CALCULAR MI CR&Eacute;DITO &#8250;</button>';

        html += '<div id="vk-hero-trust-badges">' + VkUI.renderTrustBadges('credito') + '</div>';

        html += '</div>'; // end panel
        html += '</div>'; // end hero

        return html;
    },

    _updateDesktopHero: function(modeloId, metodo) {
        var modelo = this.app.getModelo(modeloId);
        if (!modelo) return;

        // Refresh desktop gallery
        var galD = VkUI.getGaleriaImagenes(modelo.id);
        var imgD = VkUI.getImagenMoto(modelo.id, modelo.colorDefault);
        var srcsD = galD.length ? galD : [imgD];
        var trackHtml = '';
        for (var gi = 0; gi < srcsD.length; gi++) {
            trackHtml += '<img class="vk-gal__img' + (gi === 0 ? ' vk-gal__img--active' : '') + '" src="' + srcsD[gi] + '" alt="' + modelo.nombre + ' ' + (gi+1) + '">';
        }
        $('#vk-gal-track-desktop').html(trackHtml);
        var $gal = $('#vk-galeria-desktop');
        $gal.find('.vk-gal__dots').remove();
        $gal.find('.vk-gal__arrow').toggle(srcsD.length > 1);
        if (srcsD.length > 1) {
            var dotsHtml = '<div class="vk-gal__dots">';
            for (var di = 0; di < srcsD.length; di++) {
                dotsHtml += '<span class="vk-gal__dot' + (di === 0 ? ' vk-gal__dot--active' : '') + '" data-gal="desktop" data-idx="' + di + '"></span>';
            }
            dotsHtml += '</div>';
            $gal.append(dotsHtml);
        }

        // Text info
        $('#vk-hero-nombre').html(this._getModeloLogo(modelo.id, modelo.nombre));
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
        var ctaText = 'CALCULAR MI CR&Eacute;DITO &#8250;';
        if (this._activeMetodo === 'msi') ctaText = 'QUIERO MIS 9 MSI &#8250;';
        if (this._activeMetodo === 'contado') ctaText = 'PAGAR DE CONTADO';
        $('#vk-hero-cta').html(ctaText);

        // Update trust badges
        $('#vk-hero-trust-badges').html(VkUI.renderTrustBadges(this._activeMetodo));
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

    _renderMobileHero: function(modelos) {
        var html = '';

        // Horizontal slider wrapper
        html += '<div class="vk-modelo-slider" id="vk-modelo-slider">';
        html += '<div class="vk-modelo-slider__track" id="vk-modelo-slider-track">';
        for (var i = 0; i < modelos.length; i++) {
            html += '<div class="vk-modelo-slider__item">';
            html += this.renderCard(modelos[i]);
            html += '</div>';
        }
        html += '</div>';
        html += '</div>';


        return html;
    },

    /* ------------------------------------------------------------------ */
    /*  MOBILE CARDS (legacy, kept for compatibility)                       */
    /* ------------------------------------------------------------------ */

    renderCard: function(modelo) {
        var galeria = VkUI.getGaleriaImagenes(modelo.id);
        var img     = VkUI.getImagenMoto(modelo.id, modelo.colorDefault);

        var html = '<div class="vk-card" data-modelo="' + modelo.id + '">';

        // Gallery slider
        html += '<div class="vk-card__galeria" id="vk-galeria-' + modelo.id + '">';
        if (modelo.badge) {
            html += '<div class="vk-card__badge"><span class="vk-card__badge-star">&#11088;</span> ' + modelo.badge + '</div>';
        }
        if (galeria.length > 1) {
            html += '<button class="vk-gal__arrow vk-gal__arrow--prev" data-gal="' + modelo.id + '" aria-label="anterior">&#8249;</button>';
            html += '<button class="vk-gal__arrow vk-gal__arrow--next" data-gal="' + modelo.id + '" aria-label="siguiente">&#8250;</button>';
        }
        html += '<div class="vk-gal__track" id="vk-gal-track-' + modelo.id + '">';
        var srcs = galeria.length ? galeria : [img];
        for (var gi = 0; gi < srcs.length; gi++) {
            html += '<img class="vk-gal__img' + (gi === 0 ? ' vk-gal__img--active' : '') + '" src="' + srcs[gi] + '" alt="' + modelo.nombre + ' ' + (gi+1) + '" loading="' + (gi === 0 ? 'eager' : 'lazy') + '">';
        }
        html += '</div>';
        if (srcs.length > 1) {
            html += '<div class="vk-gal__dots">';
            for (var di = 0; di < srcs.length; di++) {
                html += '<span class="vk-gal__dot' + (di === 0 ? ' vk-gal__dot--active' : '') + '" data-gal="' + modelo.id + '" data-idx="' + di + '"></span>';
            }
            html += '</div>';
        }
        html += '</div>';

        html += '<div class="vk-card__info-row">';
        html += '<div class="vk-card__nombre">' + self._getModeloLogo(modelo.id, modelo.nombre) + '</div>';
        if (modelo.autonomia) {
            html += '<div class="vk-card__spec-item">Autonom\u00eda:<br><strong>' + modelo.autonomia + ' Km</strong></div>';
            html += '<div class="vk-card__spec-item">Velocidad:<br><strong>' + modelo.velocidad + ' Km/h</strong></div>';
        }
        html += '</div>';
        html += '<div class="vk-card__precio-base">Desde ' + VkUI.formatPrecio(modelo.precioContado) + ' MXN <span>(contado)</span></div>';

        html += VkUI.renderBanner();

        html += '<div class="vk-card__formas-label">&iquest;C&oacute;mo prefieres pagar tu Voltika?</div>';
        html += '<div class="vk-card__formas-sub">Selecciona tu opci&oacute;n</div>';
        html += '<div class="vk-card__tabs">';
        html += '<button class="vk-tab vk-tab--active" data-tab="credito">' + VkUI.renderCreditoLogo(14) + '</button>';
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

        html += '<div class="vk-card__trust-badges" data-modelo-badges="' + modelo.id + '">' + VkUI.renderTrustBadges('credito') + '</div>';

        html += '</div>'; // end card

        return html;
    },

    renderTabCredito: function(modelo) {
        // Calculate weekly payment with 25% down, 36 months
        var credito    = VkCalculadora.calcular(modelo.precioContado, 0.25, 36);
        var pagoDiario = Math.ceil(credito.pagoSemanal / 7);
        var html = '';
        html += '<div class="vk-card__credito-brand">' + VkUI.renderCreditoLogo(26) + '</div>';
        html += '<div class="vk-card__precio-destacado">Desde <strong>' + VkUI.formatPrecio(credito.pagoSemanal) + '</strong> semanales</div>';
        html += '<div class="vk-card__precio-diario">Menos de <strong>$' + pagoDiario + '</strong> diarios</div>';
        html += '<div style="margin:6px 0 10px;"></div>';
        html += '<button class="vk-btn vk-btn--primary vk-card__tab-cta" data-modelo="' + modelo.id + '" data-metodo="credito">' +
            'CALCULAR MI CR\u00c9DITO &#8250;</button>';
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
                var ctaText = 'CALCULAR MI CR&Eacute;DITO &#8250;';
                if (metodo === 'msi') ctaText = 'QUIERO MIS 9 MSI &#8250;';
                if (metodo === 'contado') ctaText = 'PAGAR DE CONTADO';
                $('#vk-hero-cta').html(ctaText);

                // Update trust badges
                $('#vk-hero-trust-badges').html(VkUI.renderTrustBadges(metodo));
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

            // Update trust badges for this card
            $card.find('.vk-card__trust-badges').html(VkUI.renderTrustBadges(tab));
        });

        // ── Mobile: per-tab CTA buttons ────────────────────────
        $(document).off('click', '.vk-card__tab-cta');
        $(document).on('click', '.vk-card__tab-cta', function() {
            var modeloId = $(this).data('modelo');
            var metodo   = $(this).data('metodo');
            self.app.seleccionarModelo(modeloId, metodo);
        });

        // ── Mobile: fixed model tabs (body level) ─────────────
        $(document).off('click', '#vk-modelo-tabs-fixed .vk-modelo-tab');
        $(document).on('click', '#vk-modelo-tabs-fixed .vk-modelo-tab', function() {
            var idx = parseInt($(this).data('slide'));
            self._goToSlide(idx);
        });

        // ── Show nav bar only after scrolling past 200px ─
        var lastScrollY = window.scrollY || 0;
        $(window).off('scroll.paso1hint');
        $(window).on('scroll.paso1hint', function() {
            var currentY = window.scrollY || 0;
            if (currentY > 200) {
                $('#vk-modelo-nav-fixed').slideDown(200);
            } else {
                $('#vk-modelo-nav-fixed').slideUp(200);
            }
            if (currentY > lastScrollY && currentY > 260) {
                $('#vk-modelo-nav-fixed').addClass('vk-modelo-nav-fixed--compact');
            } else {
                $('#vk-modelo-nav-fixed').removeClass('vk-modelo-nav-fixed--compact');
            }
            lastScrollY = currentY;
        });

        // ── Gallery images: auto-play disabled (manual swipe only) ─────────
        // this._startGalleryAutoPlay();

        // ── Mobile: touch swipe on model slider ───────────────
        var $track = $('#vk-modelo-slider-track');
        if ($track.length) {
            var touchStartX = 0;
            var modelos = VOLTIKA_PRODUCTOS.modelos;
            $track[0].addEventListener('touchstart', function(e) {
                touchStartX = e.changedTouches[0].screenX;
            }, { passive: true });
            $track[0].addEventListener('touchend', function(e) {
                var dx = e.changedTouches[0].screenX - touchStartX;
                if (Math.abs(dx) > 40) {
                    var current = self._currentSlide || 0;
                    if (dx < 0 && current < modelos.length - 1) self._goToSlide(current + 1);
                    if (dx > 0 && current > 0) self._goToSlide(current - 1);
                }
            }, { passive: true });
        }

        // ── Gallery arrows ─────────────────────────────────────
        $(document).off('click', '.vk-gal__arrow');
        $(document).on('click', '.vk-gal__arrow', function(e) {
            e.stopPropagation();
            var galId = $(this).data('gal');
            var isPrev = $(this).hasClass('vk-gal__arrow--prev');
            var $imgs  = $('#vk-gal-track-' + galId + ' .vk-gal__img');
            var $dots  = $('[data-gal="' + galId + '"].vk-gal__dot');
            var curr   = $imgs.index($imgs.filter('.vk-gal__img--active'));
            var next   = isPrev ? (curr - 1 + $imgs.length) % $imgs.length : (curr + 1) % $imgs.length;
            $imgs.removeClass('vk-gal__img--active').eq(next).addClass('vk-gal__img--active');
            $dots.removeClass('vk-gal__dot--active').eq(next).addClass('vk-gal__dot--active');
        });

        // ── Gallery dots ───────────────────────────────────────
        $(document).off('click', '.vk-gal__dot');
        $(document).on('click', '.vk-gal__dot', function(e) {
            e.stopPropagation();
            var galId = $(this).data('gal');
            var idx   = parseInt($(this).data('idx'));
            var $imgs = $('#vk-gal-track-' + galId + ' .vk-gal__img');
            var $dots = $('[data-gal="' + galId + '"].vk-gal__dot');
            $imgs.removeClass('vk-gal__img--active').eq(idx).addClass('vk-gal__img--active');
            $dots.removeClass('vk-gal__dot--active').eq(idx).addClass('vk-gal__dot--active');
        });

        // ── Gallery touch swipe on images ────────────────────
        $(document).off('touchstart.galswipe touchend.galswipe', '.vk-gal__track');
        var galTouchX = 0;
        $(document).on('touchstart.galswipe', '.vk-gal__track', function(e) {
            galTouchX = e.originalEvent.changedTouches[0].screenX;
        });
        $(document).on('touchend.galswipe', '.vk-gal__track', function(e) {
            var dx = e.originalEvent.changedTouches[0].screenX - galTouchX;
            if (Math.abs(dx) < 40) return;
            var $track = $(this);
            var $imgs = $track.find('.vk-gal__img');
            var galId = $track.attr('id').replace('vk-gal-track-', '');
            var $dots = $('[data-gal="' + galId + '"].vk-gal__dot');
            var curr = $imgs.index($imgs.filter('.vk-gal__img--active'));
            var next = dx < 0 ? Math.min(curr + 1, $imgs.length - 1) : Math.max(curr - 1, 0);
            if (next !== curr) {
                $imgs.removeClass('vk-gal__img--active').eq(next).addClass('vk-gal__img--active');
                $dots.removeClass('vk-gal__dot--active').eq(next).addClass('vk-gal__dot--active');
            }
        });
    },

    _currentSlide: 0,
    _galleryAutoPlayInterval: null,

    _goToSlide: function(idx) {
        var modelos = VOLTIKA_PRODUCTOS.modelos;
        if (idx < 0 || idx >= modelos.length) return;
        this._currentSlide = idx;
        var $track = $('#vk-modelo-slider-track');
        var w = $track.closest('#vk-modelo-slider').width();
        $track.css('transform', 'translateX(-' + (idx * w) + 'px)');
        // Sync both inline tabs (if any) and body-level fixed tabs
        var $tabs = $('#vk-modelo-tabs-fixed .vk-modelo-tab');
        $tabs.removeClass('vk-modelo-tab--active');
        var $active = $tabs.filter('[data-slide="' + idx + '"]').addClass('vk-modelo-tab--active');
        if ($active.length) {
            try {
                $active[0].scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            } catch(e) {}
        }
    },

    _startGalleryAutoPlay: function() {
        var self = this;
        if (self._galleryAutoPlayInterval) clearInterval(self._galleryAutoPlayInterval);
        self._galleryAutoPlayInterval = setInterval(function() {
            // Advance gallery for each mobile card
            var modelos = VOLTIKA_PRODUCTOS.modelos;
            for (var i = 0; i < modelos.length; i++) {
                var galId = modelos[i].id;
                var $imgs = $('#vk-gal-track-' + galId + ' .vk-gal__img');
                if ($imgs.length <= 1) continue;
                var $dots = $('[data-gal="' + galId + '"].vk-gal__dot');
                var curr = $imgs.index($imgs.filter('.vk-gal__img--active'));
                var next = (curr + 1) % $imgs.length;
                $imgs.removeClass('vk-gal__img--active').eq(next).addClass('vk-gal__img--active');
                $dots.removeClass('vk-gal__dot--active').eq(next).addClass('vk-gal__dot--active');
            }
            // Also advance desktop gallery
            var $dImgs = $('#vk-gal-track-desktop .vk-gal__img');
            if ($dImgs.length > 1) {
                var $dDots = $('[data-gal="desktop"].vk-gal__dot');
                var currD = $dImgs.index($dImgs.filter('.vk-gal__img--active'));
                var nextD = (currD + 1) % $dImgs.length;
                $dImgs.removeClass('vk-gal__img--active').eq(nextD).addClass('vk-gal__img--active');
                $dDots.removeClass('vk-gal__dot--active').eq(nextD).addClass('vk-gal__dot--active');
            }
        }, 3000);
    }
};
