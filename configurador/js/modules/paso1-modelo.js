/* ==========================================================================
   Voltika - PASO 1: Model Selection
   Full-width card per model with per-card payment tabs [Crédito|9 MSI|Contado]
   ========================================================================== */

var Paso1 = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var container = $('#vk-modelos-container');
        var html = '';

        var modelos = VOLTIKA_PRODUCTOS.modelos;
        for (var i = 0; i < modelos.length; i++) {
            html += this.renderCard(modelos[i]);
        }

        container.html(html);
    },

    renderCard: function(modelo) {
        var img = VkUI.getImagenMoto(modelo.id, modelo.colorDefault);

        var html = '<div class="vk-card" data-modelo="' + modelo.id + '">';

        // Badge
        if (modelo.badge) {
            html += '<div class="vk-card__badge">' +
                '<span class="vk-card__badge-star">&#11088;</span> ' +
                modelo.badge +
                '</div>';
        }

        // Image
        html += '<div class="vk-card__imagen">' +
            '<img src="' + img + '" alt="' + modelo.nombre + '" loading="lazy">' +
            '</div>';

        // Info block
        html += '<div class="vk-card__info">';
        html += '<div class="vk-card__nombre">' + modelo.nombre + '</div>';
        if (modelo.subtitulo) {
            html += '<div class="vk-card__subtitulo">' + modelo.subtitulo + '</div>';
        }

        if (modelo.autonomia) {
            html += '<div class="vk-card__specs">' +
                '<span>&#9889; ' + modelo.autonomia + ' Km</span>' +
                '<span>&#128663; ' + modelo.velocidad + ' Km/h</span>' +
                '</div>';
        }

        html += '<div class="vk-card__precio-base">Desde ' + VkUI.formatPrecio(modelo.precioContado) + ' MXN <span>(contado)</span></div>';
        html += '</div>'; // end info

        // Green banner
        html += VkUI.renderBanner();

        // Bullets
        html += VkUI.renderBullets();

        // Per-card payment tabs
        html += '<div class="vk-card__tabs">';
        html += '<button class="vk-tab vk-tab--active" data-tab="credito">Credito Voltika</button>';
        html += '<button class="vk-tab" data-tab="msi">9 MSI</button>';
        html += '<button class="vk-tab" data-tab="contado">Contado</button>';
        html += '</div>';

        // Tab content — Credito (default active)
        html += '<div class="vk-card__tab-content vk-card__tab-content--active" data-tab-content="credito">';
        html += this.renderTabCredito(modelo);
        html += '</div>';

        // Tab content — MSI
        html += '<div class="vk-card__tab-content" data-tab-content="msi">';
        html += this.renderTabMSI(modelo);
        html += '</div>';

        // Tab content — Contado
        html += '<div class="vk-card__tab-content" data-tab-content="contado">';
        html += this.renderTabContado(modelo);
        html += '</div>';

        // CTA button
        html += '<button class="vk-btn vk-btn--primary vk-card__continuar" data-modelo="' + modelo.id + '">' +
            'SELECCIONAR' +
            '</button>';

        // Microcopy
        html += '<p class="vk-card__footer-note">Podras confirmar tu Punto Voltika antes de continuar.</p>';

        // Trust badges
        html += VkUI.renderTrustBadges();

        html += '</div>'; // end card

        return html;
    },

    renderTabCredito: function(modelo) {
        var html = '';
        html += '<div class="vk-card__credito-logo"><span class="vk-shield">&#9745;</span> credito voltika</div>';
        html += '<div class="vk-card__precio-destacado">Desde <strong>' + VkUI.formatPrecio(modelo.precioSemanal) + '</strong> / semana</div>';
        html += '<div class="vk-card__tab-bullets">';
        html += VkUI.renderTabBullet('Aprobaci\u00f3n en minutos \u00b7 Solo INE');
        html += VkUI.renderTabBullet('Costo de env\u00edo a tu ciudad incluido');
        html += VkUI.renderTabBullet('Confirmas tu Punto Voltika en el siguiente paso');
        html += '</div>';
        return html;
    },

    renderTabMSI: function(modelo) {
        var html = '';
        if (!modelo.tieneMSI) {
            html += '<div style="padding:12px 0;text-align:center;">';
            html += '<div class="vk-card__precio-secundario">Sin opcion MSI para este modelo</div>';
            html += '<div class="vk-card__precio-secundario">Contado: ' + VkUI.formatPrecio(modelo.precioContado) + ' MXN</div>';
            html += '</div>';
            return html;
        }
        html += '<div class="vk-card__msi-header">9 MSI con todas las tarjetas ' + VkUI.renderCardLogos() + '</div>';
        html += '<div class="vk-card__precio-destacado">Desde <strong>' + VkUI.formatPrecio(modelo.precioMSI) + '</strong> / mes</div>';
        html += '<div class="vk-card__tab-bullets">';
        html += VkUI.renderTabBullet('Sin tr\u00e1mites \u00b7 Pago con tarjeta');
        html += VkUI.renderTabBullet('Entrega en Punto Voltika autorizado en tu ciudad');
        html += VkUI.renderTabBullet('Moto lista para circular + Documentos para emplacar');
        html += '</div>';
        return html;
    },

    renderTabContado: function(modelo) {
        var html = '';
        html += '<div class="vk-card__contado-label">Precio contado</div>';
        html += '<div class="vk-card__precio-destacado"><strong>' + VkUI.formatPrecio(modelo.precioContado) + ' MXN</strong></div>';
        html += '<div class="vk-card__iva">IVA incluido &middot; ' + VkUI.renderCardLogos() + '</div>';
        html += '<div class="vk-card__tab-bullets">';
        html += VkUI.renderTabBullet('Sin tr\u00e1mites adicionales');
        html += VkUI.renderTabBullet('Entrega en Punto Voltika autorizado en tu ciudad');
        html += VkUI.renderTabBullet('Moto lista para circular + documentos para emplacar');
        html += '</div>';
        html += '<div class="vk-card__nota-logistico">*Costo log\u00edstico se confirma seg\u00fan tu ciudad.*</div>';
        return html;
    },

    bindEvents: function() {
        var self = this;

        // Per-card tab switching
        $(document).off('click', '#vk-paso-1 .vk-card__tabs .vk-tab');
        $(document).on('click', '#vk-paso-1 .vk-card__tabs .vk-tab', function() {
            var tab = $(this).data('tab');
            var $card = $(this).closest('.vk-card');

            // Toggle active tab within this card only
            $(this).closest('.vk-card__tabs').find('.vk-tab').removeClass('vk-tab--active');
            $(this).addClass('vk-tab--active');

            // Show matching tab content within this card
            $card.find('.vk-card__tab-content').removeClass('vk-card__tab-content--active');
            $card.find('[data-tab-content="' + tab + '"]').addClass('vk-card__tab-content--active');
        });

        // SELECCIONAR button — passes active tab as metodoPago
        $(document).off('click', '.vk-card__continuar');
        $(document).on('click', '.vk-card__continuar', function() {
            var modeloId = $(this).closest('.vk-card').data('modelo');
            var $card = $(this).closest('.vk-card');
            var activeTab = $card.find('.vk-tab--active').data('tab') || 'credito';
            self.app.seleccionarModelo(modeloId, activeTab);
        });
    }
};
