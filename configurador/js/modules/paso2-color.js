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
        html += '<h2 class="vk-paso__titulo">PASO 2</h2>';
        html += '<p class="vk-paso__subtitulo">Selecciona el color de tu moto</p>';

        // Model name
        html += '<h3 class="vk-card__nombre" style="margin:12px 0 8px;">' + modelo.nombre + '</h3>';

        // Card
        html += '<div class="vk-card">';

        // Banner
        html += VkUI.renderBanner();
        html += VkUI.renderBullets();

        // Image
        html += '<div class="vk-card__imagen" id="vk-paso2-imagen">' +
            '<img src="' + img + '" alt="' + modelo.nombre + ' ' + colorActual + '">' +
            '</div>';

        // Color picker
        html += '<div class="vk-color-picker" style="padding:0 20px;">';
        for (var i = 0; i < modelo.colores.length; i++) {
            var c = modelo.colores[i];
            var activeCls = c.id === colorActual ? ' vk-color-option--active' : '';
            html += '<div class="vk-color-option' + activeCls + '" data-color="' + c.id + '">' +
                '<div class="vk-color-option__dot vk-color-option__dot--' + c.id + '" style="background:' + c.hex + ';"></div>' +
                '<div class="vk-color-option__label">' + c.nombre + '</div>' +
                '</div>';
        }
        html += '</div>';

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

        $('#vk-color-container').html(html);
    },

    renderPaymentInfo: function(modelo, metodo) {
        var html = '';

        if (metodo === 'credito') {
            html += '<div style="font-size:15px;font-weight:700;margin-bottom:6px;">&#128179; cr\u00e9dito voltika <span style="font-weight:400;color:var(--vk-text-secondary);">seleccionado</span></div>';
            html += '<div class="vk-card__precio-destacado"><strong>' + VkUI.formatPrecio(modelo.precioSemanal) + '</strong> semanales</div>';
            html += '<div class="vk-card__tab-bullets" style="text-align:left;">';
            html += VkUI.renderTabBullet('Aprobaci\u00f3n en 2 minutos');
            html += VkUI.renderTabBullet('Env\u00edo asegurado incluido');
            html += '</div>';
        } else if (metodo === 'msi') {
            html += '<div style="font-size:18px;font-weight:700;margin-bottom:10px;">' +
                '<strong>' + VkUI.formatPrecio(modelo.precioMSI) + '</strong>' +
                ' <span style="font-weight:400;">/mes durante <strong>9</strong> meses</span> ' +
                VkUI.renderCardLogos() +
                '</div>';
            html += '<div class="vk-card__tab-bullets" style="text-align:left;">';
            html += VkUI.renderTabBullet('<strong>Sin tr\u00e1mites</strong> \u00b7 Pago <strong>inmediato</strong> con tarjeta');
            html += VkUI.renderTabBullet('Env\u00edo asegurado a tu ciudad');
            html += VkUI.renderTabBullet('<strong>Costo log\u00edstico</strong> confirmado con tu <strong>c\u00f3digo postal</strong> en el siguiente paso');
            html += '</div>';
        } else { // contado
            html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:4px;">Precio contado</div>';
            html += '<div class="vk-card__precio-destacado"><strong>' + VkUI.formatPrecio(modelo.precioContado) + ' MXN</strong></div>';
            html += '<div class="vk-card__tab-bullets" style="text-align:left;">';
            html += VkUI.renderTabBullet('<strong>Sin tr\u00e1mites</strong> \u00b7 Pago <strong>inmediato</strong> con tarjeta');
            html += VkUI.renderTabBullet('Env\u00edo asegurado a tu ciudad');
            html += VkUI.renderTabBullet('<strong>Costo log\u00edstico</strong> confirmado con tu <strong>c\u00f3digo postal</strong> en el siguiente paso');
            html += '</div>';
            html += '<div style="margin-top:12px;">' + VkUI.renderCardLogos() + '</div>';
        }

        return html;
    },

    bindEvents: function() {
        var self = this;

        // Color selection (off first to prevent duplicate handlers on re-init)
        $(document).off('click', '#vk-paso-2 .vk-color-option');
        $(document).on('click', '#vk-paso-2 .vk-color-option', function() {
            var color = $(this).data('color');
            self.app.state.colorSeleccionado = color;

            // Update active state
            $('#vk-paso-2 .vk-color-option').removeClass('vk-color-option--active');
            $(this).addClass('vk-color-option--active');

            // Update image
            var modelo = self.app.getModelo(self.app.state.modeloSeleccionado);
            var newImg = VkUI.getImagenMoto(modelo.id, color);
            $('#vk-paso2-imagen img').attr('src', newImg);
        });

        // Continue to PASO 3
        $(document).off('click', '#vk-paso2-continuar');
        $(document).on('click', '#vk-paso2-continuar', function() {
            self.app.irAPaso(3);
        });
    }
};
