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

        // Step header — PDF: "Modelo M05 seleccionado" as primary heading
        html += '<h2 class="vk-paso__titulo">Modelo <strong>' + modelo.nombre + '</strong> seleccionado</h2>';

        var btnTexto = state.metodoPago === 'contado' ? 'PAGAR DE CONTADO' :
                      state.metodoPago === 'msi'     ? 'QUIERO MIS 9 MSI \u203a' :
                      'CONFIRMAR COLOR Y CONTINUAR';

        // Card
        html += '<div class="vk-card">';

        // Banner + bullets — full-width row (always visible)
        html += '<div class="vk-desktop-split-wrap">';
        html += VkUI.renderBanner();
        html += VkUI.renderBullets();
        html += '</div>';

        // Desktop 2-col split (stacks on mobile)
        html += '<div class="vk-desktop-split">';

        // ── Left: visual ──────────────────────────────────────
        html += '<div class="vk-desktop-split__left">';

        html += '<div class="vk-card__imagen" id="vk-paso2-imagen">' +
            '<img src="' + img + '" alt="' + modelo.nombre + ' ' + colorActual + '">' +
            '</div>';

        html += '<div class="vk-color-picker">';
        for (var i = 0; i < modelo.colores.length; i++) {
            var c = modelo.colores[i];
            var activeCls = c.id === colorActual ? ' vk-color-swatch--active' : '';
            html += '<div class="vk-color-swatch' + activeCls + '" data-color="' + c.id + '">' +
                '<div class="vk-color-swatch__circle" style="background:' + c.hex + ';"></div>' +
                '<div class="vk-color-swatch__label">' + c.nombre + '</div>' +
                '</div>';
        }
        html += '</div>';

        html += '<p style="font-size:12px;color:var(--vk-text-muted);text-align:center;margin:6px 0 16px;">' +
            'El color no afecta precio ni tiempo de entrega.' +
            '</p>';

        html += '</div>'; // end left

        // ── Right: purchase ───────────────────────────────────
        html += '<div class="vk-desktop-split__right">';

        html += '<div style="padding:16px 20px 0;text-align:center;">';
        html += this.renderPaymentInfo(modelo, state.metodoPago);
        html += '</div>';

        html += '<button class="vk-btn vk-btn--primary" id="vk-paso2-continuar">' + btnTexto + '</button>';

        html += '<p class="vk-card__footer-note">' +
            'Solo falta confirmar tu <strong>punto de entrega.</strong>' +
            '</p>';

        html += VkUI.renderTrustBadges();

        html += '</div>'; // end right

        html += '</div>'; // end desktop-split

        html += '</div>'; // end card

        jQuery('#vk-color-container').html(html);

        // Attach color click handlers directly to elements (avoids delegation conflicts)
        jQuery('#vk-paso-2 .vk-color-swatch').each(function() {
            this.addEventListener('click', function() {
                var color = this.getAttribute('data-color');
                self.app.state.colorSeleccionado = color;

                // Update active state
                var options = document.querySelectorAll('#vk-paso-2 .vk-color-swatch');
                for (var j = 0; j < options.length; j++) {
                    options[j].classList.remove('vk-color-swatch--active');
                }
                this.classList.add('vk-color-swatch--active');

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
            html += '<div class="vk-card__credito-logo"><img class="vk-shield-icon" src="img/voltika_shield.svg" alt="Voltika"> cr\u00e9dito voltika seleccionado</div>';
            html += '<div class="vk-card__precio-destacado"><strong>' + VkUI.formatPrecio(modelo.precioSemanal) + '</strong> semanales</div>';
            html += '<div class="vk-card__tab-bullets" style="text-align:left;">';
            html += VkUI.renderTabBullet('Aprobaci\u00f3n en minutos');
            html += VkUI.renderTabBullet('Incluye flete');
            html += VkUI.renderTabBullet('Entrega en Punto Voltika autorizado en tu ciudad');
            html += VkUI.renderTabBullet('Confirmas tu punto en el siguiente paso');
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
        }

        return html;
    },

    bindEvents: function() {
        var self = this;

        // Continue: crédito → calculator (4B), contado/msi → CP (3)
        jQuery(document).off('click', '#vk-paso2-continuar');
        jQuery(document).on('click', '#vk-paso2-continuar', function() {
            if (self.app.state.metodoPago === 'credito') {
                self.app.irAPaso(4); // Goes to Paso4B (calculator)
            } else {
                self.app.irAPaso(3); // Goes to Paso3 (CP)
            }
        });
    }
};
