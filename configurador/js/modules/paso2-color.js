/* ==========================================================================
   Voltika - PASO 2: Color Selection
   Shows selected model with color picker and payment-specific info
   ========================================================================== */

var Paso2 = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var self = this;
        var state = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        if (!modelo) return;

        var colorActual = state.colorSeleccionado || modelo.colorDefault;
        state.colorSeleccionado = colorActual;

        var img = VkUI.getImagenMoto(modelo.id, colorActual);

        var html = '';

        // Back button
        html += VkUI.renderBackButton(1);

        // Step header
        html += '<h2 class="vk-paso__titulo">Elige el color <span style="font-weight:400;">con el que quieres rodar</span></h2>';

        // Card
        html += '<div class="vk-card">';

        // Banner
        html += VkUI.renderBanner();
        html += VkUI.renderBullets();

        // Image
        html += '<div class="vk-card__imagen" id="vk-paso2-imagen">' +
            '<img src="' + img + '" alt="' + modelo.nombre + ' ' + colorActual + '">' +
            '</div>';

        // Model name below image
        html += '<div style="text-align:center;font-size:13px;color:var(--vk-text-secondary);margin:4px 0 12px;">' +
            'Modelo <strong>' + modelo.nombre + '</strong> seleccionado' +
            '</div>';

        // Color picker — thumbnail images
        html += '<div class="vk-color-picker" style="padding:0 20px;">';
        for (var i = 0; i < modelo.colores.length; i++) {
            var c = modelo.colores[i];
            var activeCls = c.id === colorActual ? ' vk-color-option--active' : '';
            var thumb = VkUI.getImagenMoto(modelo.id, c.id);
            html += '<div class="vk-color-option' + activeCls + '" data-color="' + c.id + '">' +
                '<div class="vk-color-option__thumb">' +
                '<img src="' + thumb + '" alt="' + c.nombre + '" ' +
                'style="width:52px;height:36px;object-fit:contain;">' +
                '</div>' +
                '<div class="vk-color-option__label">' + c.nombre + '</div>' +
                '</div>';
        }
        html += '</div>';
        html += '<p style="font-size:12px;color:var(--vk-text-muted);text-align:center;margin:6px 0 4px;">' +
            'El color no afecta precio ni tiempo de entrega.' +
            '</p>';

        // Payment info (varies by method)
        html += '<div style="padding:16px 20px;text-align:center;">';
        html += this.renderPaymentInfo(modelo, state.metodoPago);
        html += '</div>';

        // CTA
        var btnTexto = state.metodoPago === 'contado' ? 'PAGAR DE CONTADO' :
                      state.metodoPago === 'msi'     ? 'QUIERO MIS 9 MSI \u203a' :
                      'CONFIRMAR COLOR Y CONTINUAR';
        html += '<button class="vk-btn vk-btn--primary" id="vk-paso2-continuar">' + btnTexto + '</button>';

        // Footer
        html += '<p class="vk-card__footer-note">' +
            'Solo falta confirmar tu <strong>punto de entrega.</strong>' +
            '</p>';

        // Trust badges
        html += VkUI.renderTrustBadges();

        html += '</div>'; // end card

        jQuery('#vk-color-container').html(html);

        // Attach color click handlers directly to elements (avoids delegation conflicts)
        jQuery('#vk-paso-2 .vk-color-option').each(function() {
            this.addEventListener('click', function() {
                var color = this.getAttribute('data-color');
                self.app.state.colorSeleccionado = color;

                // Update active state
                var options = document.querySelectorAll('#vk-paso-2 .vk-color-option');
                for (var j = 0; j < options.length; j++) {
                    options[j].classList.remove('vk-color-option--active');
                }
                this.classList.add('vk-color-option--active');

                // Update image
                var modeloActual = self.app.getModelo(self.app.state.modeloSeleccionado);
                var newImg = VkUI.getImagenMoto(modeloActual.id, color);
                var imgEl = document.querySelector('#vk-paso2-imagen img');
                if (imgEl) imgEl.src = newImg;
            });
        });
    },

    renderPaymentInfo: function(modelo, metodo) {
        var html = '';

        if (metodo === 'credito') {
            html += '<div class="vk-card__credito-logo"><span class="vk-shield">&#9745;</span> cr\u00e9dito voltika seleccionado</div>';
            html += '<div class="vk-card__precio-destacado"><strong>' + VkUI.formatPrecio(modelo.precioSemanal) + '</strong> semanales</div>';
            html += '<div class="vk-card__tab-bullets" style="text-align:left;">';
            html += VkUI.renderTabBullet('Aprobaci\u00f3n en 2 minutos');
            html += VkUI.renderTabBullet('Env\u00edo asegurado incluido');
            html += '</div>';
        } else if (metodo === 'msi') {
            html += '<div style="font-size:18px;font-weight:700;margin-bottom:6px;">' +
                '<strong>' + VkUI.formatPrecio(modelo.precioMSI) + '</strong>' +
                ' <span style="font-weight:400;">/mes</span>' +
                '</div>';
            html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:10px;">Sin intereses &middot; 9 MSI con todas las tarjetas ' + VkUI.renderCardLogos() + '</div>';
            html += '<div class="vk-card__tab-bullets" style="text-align:left;">';
            html += VkUI.renderTabBullet('Entrega en Punto Voltika autorizado en tu ciudad');
            html += VkUI.renderTabBullet('Confirmas tu punto en el siguiente paso');
            html += '</div>';
        } else { // contado
            html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:4px;">Precio contado</div>';
            html += '<div class="vk-card__precio-destacado"><strong>' + VkUI.formatPrecio(modelo.precioContado) + ' MXN</strong></div>';
            html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:8px;">IVA incluido &middot; ' + VkUI.renderCardLogos() + '</div>';
            html += '<div class="vk-card__tab-bullets" style="text-align:left;">';
            html += VkUI.renderTabBullet('Sin tr\u00e1mites adicionales');
            html += VkUI.renderTabBullet('Entrega en Punto Voltika autorizado en tu ciudad');
            html += VkUI.renderTabBullet('Moto lista para circular + documentos para emplacar');
            html += '</div>';
            html += '<div style="font-size:12px;color:var(--vk-text-muted);margin-top:8px;">*Costo log\u00edstico se confirma seg\u00fan tu ciudad.*</div>';
        }

        return html;
    },

    bindEvents: function() {
        var self = this;

        // Continue to PASO 3 (delegation is fine for buttons)
        jQuery(document).off('click', '#vk-paso2-continuar');
        jQuery(document).on('click', '#vk-paso2-continuar', function() {
            self.app.irAPaso(3);
        });
    }
};
