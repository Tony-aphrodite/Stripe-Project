/* ==========================================================================
   Voltika - PASO 1: Model Selection
   2-column grid with global payment method selector
   ========================================================================== */

var Paso1 = {

    _metodoActivo: 'credito',

    init: function(app) {
        this.app = app;
        this._metodoActivo = 'credito';
        this.render();
        this.bindEvents();
    },

    render: function() {
        var container = $('#vk-modelos-container');
        var html = '';

        // Global payment method selector
        html += '<div class="vk-metodo-tabs" id="vk-metodo-tabs">';
        html += '<div class="vk-metodo-tab vk-metodo-tab--active" data-metodo="credito">Credito<br>Voltika</div>';
        html += '<div class="vk-metodo-tab" data-metodo="msi">9 MSI</div>';
        html += '<div class="vk-metodo-tab" data-metodo="contado">Contado</div>';
        html += '</div>';

        // 2-column model cards
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
        html += '<div style="padding:0 10px;text-align:center;">';
        html += '<div class="vk-card__nombre">' + modelo.nombre + '</div>';

        if (modelo.autonomia) {
            html += '<div style="font-size:11px;color:#777;margin-bottom:6px;">' +
                modelo.autonomia + ' Km &middot; ' + modelo.velocidad + ' Km/h' +
                '</div>';
        }

        // Price area (swappable on method change)
        html += '<div class="vk-precio-area">' + this.renderPrecio(modelo, this._metodoActivo) + '</div>';

        html += '</div>'; // end info

        // CTA
        html += '<button class="vk-btn vk-btn--primary vk-card__continuar" data-modelo="' + modelo.id + '">' +
            'SELECCIONAR' +
            '</button>';

        html += '</div>'; // end card

        return html;
    },

    renderPrecio: function(modelo, metodo) {
        if (metodo === 'credito') {
            return '<div class="vk-card__precio-destacado">' +
                '<strong>' + VkUI.formatPrecio(modelo.precioSemanal) + '</strong>/sem' +
                '</div>' +
                '<div class="vk-card__precio-secundario">Contado ' + VkUI.formatPrecio(modelo.precioContado) + '</div>';
        }
        if (metodo === 'msi') {
            if (!modelo.tieneMSI) {
                return '<div class="vk-card__precio-secundario">Sin opcion MSI</div>' +
                    '<div class="vk-card__precio-secundario">Contado ' + VkUI.formatPrecio(modelo.precioContado) + '</div>';
            }
            return '<div class="vk-card__precio-destacado">' +
                '<strong>' + VkUI.formatPrecio(modelo.precioMSI) + '</strong>/mes' +
                '</div>' +
                '<div class="vk-card__precio-secundario">9 MSI sin intereses</div>';
        }
        // contado
        return '<div class="vk-card__precio-destacado">' +
            '<strong>' + VkUI.formatPrecio(modelo.precioContado) + '</strong> MXN' +
            '</div>' +
            '<div class="vk-card__precio-secundario">IVA incluido</div>';
    },

    bindEvents: function() {
        var self = this;

        // Payment method tab switching — updates all card prices
        $(document).off('click', '#vk-metodo-tabs .vk-metodo-tab');
        $(document).on('click', '#vk-metodo-tabs .vk-metodo-tab', function() {
            var metodo = $(this).data('metodo');
            self._metodoActivo = metodo;

            $('#vk-metodo-tabs .vk-metodo-tab').removeClass('vk-metodo-tab--active');
            $(this).addClass('vk-metodo-tab--active');

            // Refresh price area on every card
            var modelos = VOLTIKA_PRODUCTOS.modelos;
            for (var i = 0; i < modelos.length; i++) {
                var m = modelos[i];
                $('.vk-card[data-modelo="' + m.id + '"] .vk-precio-area')
                    .html(self.renderPrecio(m, metodo));
            }
        });

        // SELECCIONAR button
        $(document).off('click', '.vk-card__continuar');
        $(document).on('click', '.vk-card__continuar', function() {
            var modeloId = $(this).closest('.vk-card').data('modelo');
            self.app.seleccionarModelo(modeloId, self._metodoActivo);
        });
    }
};
