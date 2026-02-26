/* ==========================================================================
   Voltika - PASO 1: Model Selection
   Renders scrollable model cards with 3 payment tabs each
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

        // Badge (only for models with badge)
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

        // Model info
        html += '<div class="vk-card__info">' +
            '<h3 class="vk-card__nombre">' + modelo.nombre + '</h3>';

        // Specs
        if (modelo.autonomia || modelo.velocidad) {
            html += '<div class="vk-card__specs">';
            if (modelo.autonomia) {
                html += '<span>Autonomia: <strong>' + modelo.autonomia + ' Km</strong></span>';
            }
            if (modelo.velocidad) {
                html += '<span>Velocidad: <strong>' + modelo.velocidad + ' Km/h</strong></span>';
            }
            html += '</div>';
        }

        // Base price
        html += '<div class="vk-card__precio-base">' +
            'Desde <strong>' + VkUI.formatPrecio(modelo.precioContado) + ' MXN</strong> ' +
            '<span>(contado)</span>' +
            '</div>';

        html += '</div>'; // end .vk-card__info

        // Green banner
        html += VkUI.renderBanner();

        // Bullets
        html += VkUI.renderBullets();

        // Payment tabs
        html += '<div class="vk-card__tabs" data-modelo="' + modelo.id + '">' +
            '<button class="vk-tab vk-tab--active" data-metodo="credito" data-modelo="' + modelo.id + '">Credito Voltika</button>' +
            '<button class="vk-tab" data-metodo="msi" data-modelo="' + modelo.id + '">9 MSI</button>' +
            '<button class="vk-tab" data-metodo="contado" data-modelo="' + modelo.id + '">Contado</button>' +
            '</div>';

        // Tab contents
        html += this.renderTabCredito(modelo);
        html += this.renderTabMSI(modelo);
        html += this.renderTabContado(modelo);

        // CTA Button
        html += '<button class="vk-btn vk-btn--primary vk-card__continuar" data-modelo="' + modelo.id + '">' +
            'CONTINUAR' +
            '</button>';

        // Footer note
        html += '<p class="vk-card__footer-note">' +
            'Podras confirmar tu Punto Voltika antes de finalizar.' +
            '</p>';

        // Trust badges
        html += VkUI.renderTrustBadges();

        html += '</div>'; // end .vk-card

        return html;
    },

    renderTabCredito: function(modelo) {
        return '<div class="vk-card__tab-content vk-card__tab-content--active" data-content="credito" data-modelo="' + modelo.id + '">' +
            '<div class="vk-card__credito-logo">' +
                '<span class="vk-shield">&#9745;</span> credito <strong>voltika</strong>' +
            '</div>' +
            '<div class="vk-card__precio-destacado">' +
                'Desde <strong>' + VkUI.formatPrecio(modelo.precioSemanal) + '</strong> / semana' +
            '</div>' +
            '<div class="vk-card__tab-bullets">' +
                VkUI.renderTabBullet('Aprobacion en minutos - Solo INE') +
                VkUI.renderTabBullet('Costo de envio a tu ciudad incluido') +
            '</div>' +
            '</div>';
    },

    renderTabMSI: function(modelo) {
        return '<div class="vk-card__tab-content" data-content="msi" data-modelo="' + modelo.id + '">' +
            '<div class="vk-card__msi-header">' +
                '9 MSI ' + VkUI.renderCardLogos() + ' con todas las tarjetas' +
            '</div>' +
            '<div class="vk-card__precio-destacado">' +
                'Desde <strong>' + VkUI.formatPrecio(modelo.precioMSI) + '</strong> / mes' +
            '</div>' +
            '<div class="vk-card__tab-bullets">' +
                VkUI.renderTabBullet('Sin tramites &middot; Pago con tarjeta') +
                VkUI.renderTabBullet('Entrega en Punto Voltika autorizado en tu ciudad') +
                VkUI.renderTabBullet('Moto lista para circular + Documentos para emplacar') +
            '</div>' +
            '</div>';
    },

    renderTabContado: function(modelo) {
        return '<div class="vk-card__tab-content" data-content="contado" data-modelo="' + modelo.id + '">' +
            '<div class="vk-card__contado-label">Precio contado</div>' +
            '<div class="vk-card__precio-destacado">' +
                '<strong>' + VkUI.formatPrecio(modelo.precioContado) + ' MXN</strong>' +
            '</div>' +
            '<div class="vk-card__iva">IVA incluido</div>' +
            '<div class="vk-card__tarjetas">' +
                VkUI.renderCardLogos() + ' IVA incluido' +
            '</div>' +
            '<div class="vk-card__tab-bullets">' +
                VkUI.renderTabBullet('Sin tramites adicionales') +
                VkUI.renderTabBullet('Entrega en Punto Voltika autorizado en tu ciudad') +
                VkUI.renderTabBullet('Moto lista para circular + documentos para emplacar') +
            '</div>' +
            '<span class="vk-card__nota-logistico">Costo logistico se confirma segun tu ciudad.</span>' +
            '</div>';
    },

    bindEvents: function() {
        var self = this;

        // Tab switching (per card)
        $(document).on('click', '.vk-tab', function() {
            var $tab = $(this);
            var metodo = $tab.data('metodo');
            var modeloId = $tab.data('modelo');
            var $card = $tab.closest('.vk-card');

            // Update tabs
            $card.find('.vk-tab').removeClass('vk-tab--active');
            $tab.addClass('vk-tab--active');

            // Update tab content
            $card.find('.vk-card__tab-content').removeClass('vk-card__tab-content--active');
            $card.find('.vk-card__tab-content[data-content="' + metodo + '"][data-modelo="' + modeloId + '"]')
                .addClass('vk-card__tab-content--active');
        });

        // CONTINUAR button
        $(document).on('click', '.vk-card__continuar', function() {
            var $card = $(this).closest('.vk-card');
            var modeloId = $card.data('modelo');
            var metodoActivo = $card.find('.vk-tab--active').data('metodo');

            self.app.seleccionarModelo(modeloId, metodoActivo);
        });
    }
};
