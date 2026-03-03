/* ==========================================================================
   Voltika - PASO 2: Color Selection
   Matches client reference: color circles, payment info, trust badges
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
        html += '<div class="vk-paso-header">';
        html += '<div class="vk-paso-header__step">PASO 2</div>';
        html += '<h2 class="vk-paso-header__title">Selecciona el color de tu moto</h2>';
        html += '</div>';

        html += '<div class="vk-card">';

        // Model name
        html += '<div class="vk-card__nombre" style="margin-bottom:8px;">' + modelo.nombre + '</div>';

        // Green banner overlay on image area
        html += '<div class="vk-card__imagen-wrap">';
        html += '<div class="vk-card__imagen-banner">';
        html += '<span>&#128737;</span> Moto lista para circular en tu ciudad';
        html += '<br>+ Documentos para emplacar <strong>incluidos</strong>';
        html += '</div>';
        html += '<div class="vk-card__imagen" id="vk-paso2-imagen">' +
            '<img src="' + img + '" alt="' + modelo.nombre + ' ' + colorActual + '">' +
            '</div>';
        html += '</div>';

        // Color circles
        html += '<div class="vk-color-picker">';
        for (var i = 0; i < modelo.colores.length; i++) {
            var c = modelo.colores[i];
            var activeCls = c.id === colorActual ? ' vk-color-option--active' : '';
            html += '<div class="vk-color-option' + activeCls + '" data-color="' + c.id + '">';
            html += '<div class="vk-color-option__dot" style="background:' + c.hex + ';border-color:' + (c.hex === '#C0C0C0' || c.hex === '#A0A0A0' ? '#999' : c.hex) + ';"></div>';
            html += '<div class="vk-color-option__label">' + c.nombre + '</div>';
            html += '</div>';
        }
        html += '</div>';

        html += '<p class="vk-card__color-note">El color no afecta precio ni tiempo de entrega.</p>';

        // Payment info section (varies by method)
        html += '<div class="vk-card__payment-info">';
        html += this.renderPaymentInfo(modelo, state.metodoPago);
        html += '</div>';

        // CTA
        html += '<button class="vk-btn vk-btn--primary" id="vk-paso2-continuar">CONTINUAR</button>';

        // Microcopy
        html += '<p class="vk-card__tab-microcopy">Solo falta confirmar tu <strong>Punto Voltika</strong> en tu ciudad.</p>';

        // Trust badges
        html += VkUI.renderTrustBadges();

        html += '</div>'; // end card

        jQuery('#vk-color-container').html(html);

        // Color click handlers
        jQuery('#vk-paso-2 .vk-color-option').each(function() {
            this.addEventListener('click', function() {
                var color = this.getAttribute('data-color');
                self.app.state.colorSeleccionado = color;

                var options = document.querySelectorAll('#vk-paso-2 .vk-color-option');
                for (var j = 0; j < options.length; j++) {
                    options[j].classList.remove('vk-color-option--active');
                }
                this.classList.add('vk-color-option--active');

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
            html += '<div class="vk-payment-label">Cr\u00e9dito Voltika</div>';
            html += '<div class="vk-card__precio-destacado"><strong>' + VkUI.formatPrecio(modelo.precioSemanal) + '</strong> semanales</div>';
            html += '<div class="vk-card__tab-bullets">';
            html += VkUI.renderTabBullet('Aprobaci\u00f3n en <strong>minutos</strong>');
            html += VkUI.renderTabBullet('Incluye flete');
            html += VkUI.renderTabBullet('Entrega en <strong>Punto Voltika</strong> autorizado en tu ciudad');
            html += VkUI.renderTabBullet('Confirmas tu punto en el siguiente paso');
            html += '</div>';
        } else if (metodo === 'msi') {
            html += '<div class="vk-payment-label">9 MSI &nbsp; cr\u00e9dito<strong>voltika</strong></div>';
            html += '<div class="vk-card__precio-destacado"><strong>' + VkUI.formatPrecio(modelo.precioMSI) + '</strong> al mes</div>';
            html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:8px;">Sin intereses</div>';
            html += '<div class="vk-card__tab-bullets">';
            html += VkUI.renderTabBullet('Entrega en <strong>Punto Voltika</strong> autorizado en tu ciudad');
            html += VkUI.renderTabBullet('Confirmas tu punto en el siguiente paso');
            html += '</div>';
        } else {
            html += '<div class="vk-payment-label">Contado</div>';
            html += '<div class="vk-card__precio-destacado"><strong>' + VkUI.formatPrecio(modelo.precioContado) + ' MXN</strong></div>';
            html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:4px;">IVA incluido</div>';
            html += '<div style="margin-bottom:8px;">' + VkUI.renderCardLogos() + '</div>';
            html += '<div class="vk-card__tab-bullets">';
            html += VkUI.renderTabBullet('<strong>Sin tr\u00e1mites</strong> adicionales');
            html += VkUI.renderTabBullet('Entrega en <strong>Punto Voltika</strong> autorizado en tu ciudad');
            html += VkUI.renderTabBullet('Moto lista para circular + documentos para emplacar');
            html += '</div>';
            html += '<div style="font-size:12px;color:var(--vk-text-muted);font-style:italic;margin-top:6px;">Costo log\u00edstico se confirma seg\u00fan tu ciudad.</div>';
        }

        return html;
    },

    bindEvents: function() {
        var self = this;

        jQuery(document).off('click', '#vk-paso2-continuar');
        jQuery(document).on('click', '#vk-paso2-continuar', function() {
            self.app.irAPaso(3);
        });
    }
};
