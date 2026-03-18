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

        // Back button — credit: back to calculator (4B), others: back to model (1)
        var backPaso = state.metodoPago === 'credito' ? 4 : 1;
        html += VkUI.renderBackButton(backPaso);

        // Step header
        html += '<h2 class="vk-paso__titulo">Elige el color de tu Voltika <strong>' + modelo.nombre + '</strong></h2>';

        var btnTexto = state.metodoPago === 'contado' ? 'PAGAR DE CONTADO' :
                      state.metodoPago === 'msi'     ? 'QUIERO MIS 9 MSI \u203a' :
                      'CONFIRMAR COLOR Y CONTINUAR';

        // Card
        html += '<div class="vk-card">';

        // Subtitle bullets
        html += '<div class="vk-card__subtitle-bullets" style="padding:12px 16px 8px;">';
        html += '<div class="vk-card__bullet"><span class="vk-icon-check">&#10003;</span> Moto se entrega lista para circular en tu ciudad</div>';
        html += '<div class="vk-card__bullet"><span class="vk-icon-check">&#10003;</span> Garant\u00eda incluida</div>';
        html += '<div class="vk-card__bullet"><span class="vk-icon-check">&#10003;</span> Documentos para tr\u00e1mites de placas incluidos</div>';
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

        html += '<p style="font-size:12px;color:var(--vk-text-muted);text-align:center;margin:6px 0 4px;">' +
            'El color no afecta precio ni tiempo de entrega.' +
            '</p>';
        html += '<p style="font-size:12px;color:var(--vk-text-muted);text-align:center;margin:0 0 16px;">' +
            'Colores sujetos a disponibilidad.' +
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

        html += VkUI.renderTrustBadges(state.metodoPago || 'credito');

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
            var cuota = (this.app && this.app.state && this.app.state.cuotaSemanal) ? this.app.state.cuotaSemanal : modelo.precioSemanal;
            html += '<div class="vk-card__credito-logo">' + VkUI.renderCreditoLogo(24) + ' seleccionado</div>';
            html += '<div class="vk-card__precio-destacado"><strong>' + VkUI.formatPrecio(cuota) + '</strong> semanales</div>';
            html += '<div class="vk-card__tab-bullets" style="text-align:left;">';
            html += VkUI.renderTabBullet('Aprobaci\u00f3n en 2 minutos solo con INE');
            html += VkUI.renderTabBullet('En cr\u00e9dito Voltika se incluye flete a tu ciudad');
            html += VkUI.renderTabBullet('Enganche flexible');
            html += VkUI.renderTabBullet('Sin penalizaci\u00f3n por pago anticipado');
            html += '</div>';
        } else if (metodo === 'msi') {
            html += '<div style="font-size:18px;font-weight:700;margin-bottom:6px;">' +
                '<strong>' + VkUI.formatPrecio(modelo.precioMSI) + '</strong>' +
                ' <span style="font-weight:400;">/mes</span>' +
                '</div>';
            html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:10px;">Sin intereses &middot; 9 MSI ' + VkUI.renderCardLogos() + '</div>';
            html += '<div class="vk-card__tab-bullets" style="text-align:left;">';
            html += VkUI.renderTabBullet('Entrega en Punto Voltika autorizado en tu ciudad');
            html += VkUI.renderTabBullet('Confirmas tu punto en el siguiente paso');
            html += '</div>';
        } else { // contado
            html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:4px;">Precio contado</div>';
            html += '<div class="vk-card__precio-destacado"><strong>' + VkUI.formatPrecio(modelo.precioContado) + ' MXN</strong></div>';
            html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:8px;">IVA incluido &middot; ' + VkUI.renderCardLogos() + '</div>';
            html += '<div class="vk-card__tab-bullets" style="text-align:left;">';
            html += VkUI.renderTabBullet('Entrega en Punto Voltika autorizado en tu ciudad');
            html += VkUI.renderTabBullet('Confirmas tu punto en el siguiente paso');
            html += '</div>';
        }

        return html;
    },

    bindEvents: function() {
        var self = this;

        // Continue: all methods → delivery (3)
        // Credit flux: calculator already done before color selection
        jQuery(document).off('click', '#vk-paso2-continuar');
        jQuery(document).on('click', '#vk-paso2-continuar', function() {
            self.app.irAPaso(3);
        });
    }
};
