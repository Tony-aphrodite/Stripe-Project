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
        html += '<h2 class="vk-paso__titulo">Ya casi es tu Voltika <strong>' + modelo.nombre + '</strong></h2>';
        html += '<p style="font-size:14px;color:var(--vk-text-secondary);text-align:center;margin:-4px 0 8px;">Selecciona tu color para apartarla</p>';

        var btnTexto = state.metodoPago === 'contado' ? 'PAGAR DE CONTADO' :
                      state.metodoPago === 'msi'     ? 'QUIERO MIS 9 MSI \u203a' :
                      'CONFIRMAR COLOR Y CONTINUAR';

        // Card
        html += '<div class="vk-card">';

        // Subtitle bullets removed for all flows

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
            'Colores sujetos a inventario' +
            '</p>';

        html += '</div>'; // end left

        // ── Right: purchase ───────────────────────────────────
        html += '<div class="vk-desktop-split__right">';

        html += '<div style="padding:16px 20px 0;text-align:center;">';
        html += this.renderPaymentInfo(modelo, state.metodoPago);
        html += '</div>';

        // Código de referido (collapsible toggle for all flows)
        html += '<div style="padding:0 20px;margin-top:20px;margin-bottom:12px;">';
        if (state.metodoPago === 'credito') {
            html += '<div style="font-size:13px;color:#2e7d32;margin-bottom:10px;"><span style="color:#2e7d32;">&#10003;</span> Entrega en tu ciudad</div>';
        }
        html += '<div id="vk-referido-toggle" style="font-size:13px;color:var(--vk-text-secondary);font-weight:600;cursor:pointer;display:flex;align-items:center;gap:4px;margin-bottom:4px;">' +
            '\u00bfTienes c\u00f3digo de referido? (opcional) <span style="font-size:10px;">&#9660;</span></div>';
        html += '<div id="vk-referido-field" style="display:none;">';
        html += '<input type="text" id="vk-referido-input" class="vk-form-input" placeholder="C\u00f3digo de referido" ' +
            'value="' + (state.codigoReferido || '') + '" ' +
            'style="font-size:15px;padding:12px 14px;text-transform:uppercase;margin-top:6px;">';
        html += '</div>';
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
            var dailyCost = Math.round(cuota / 7);
            html += '<div style="font-size:16px;font-weight:700;color:#039fe1;margin-bottom:8px;">As\u00ed pagas tu Voltika</div>';
            html += '<div style="display:flex;align-items:baseline;justify-content:center;gap:6px;margin-bottom:4px;">';
            html += '<span style="font-size:32px;font-weight:700;color:#1a3a5c;">' + VkUI.formatPrecio(cuota) + '</span>';
            html += '<span style="font-size:16px;color:#1a3a5c;">por semana</span>';
            html += '</div>';
            html += '<div style="font-size:14px;color:#039fe1;margin-bottom:12px;">Menos de ' + VkUI.formatPrecio(dailyCost) + ' al d\u00eda</div>';
            html += '<div style="text-align:left;">';
            html += '<div style="font-size:13px;color:#2e7d32;"><span style="color:#2e7d32;">&#10003;</span> Aprobaci\u00f3n en menos de 2 minutos</div>';
            html += '</div>';
        } else if (metodo === 'msi') {
            html += '<div style="font-size:16px;font-weight:700;color:#039fe1;margin-bottom:8px;">As\u00ed pagas tu Voltika</div>';
            html += '<div style="display:flex;align-items:baseline;justify-content:center;gap:6px;margin-bottom:4px;">';
            html += '<span style="font-size:32px;font-weight:700;color:#1a3a5c;">' + VkUI.formatPrecio(modelo.precioMSI) + '</span>';
            html += '<span style="font-size:16px;color:#1a3a5c;">al mes</span>';
            html += '</div>';
            html += '<div style="font-size:14px;color:#039fe1;margin-bottom:4px;">9 meses sin intereses</div>';
            html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:8px;">MSI con todas las tarjetas</div>';
            html += '<div style="margin-bottom:10px;">' + VkUI.renderCardLogos() + '</div>';
            html += '<div style="text-align:left;">';
            html += '<div style="font-size:13px;color:#2e7d32;margin-bottom:2px;"><span style="color:#2e7d32;">&#10003;</span> Sin intereses</div>';
            html += '<div style="font-size:13px;color:#2e7d32;margin-bottom:2px;"><span style="color:#2e7d32;">&#10003;</span> Pago 100% seguro</div>';
            html += '<div style="font-size:13px;color:#2e7d32;"><span style="color:#2e7d32;">&#10003;</span> Entrega en tu ciudad</div>';
            html += '</div>';
        } else { // contado
            html += '<div style="font-size:16px;font-weight:700;color:#039fe1;margin-bottom:8px;">Ll\u00e9vate tu Voltika hoy</div>';
            html += '<div style="font-size:32px;font-weight:700;color:#1a3a5c;margin-bottom:4px;">' + VkUI.formatPrecio(modelo.precioContado) + ' MXN</div>';
            html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:4px;">IVA incluido</div>';
            html += '<div style="font-size:14px;color:#039fe1;margin-bottom:8px;">Compra hoy &middot; recibe en tu ciudad</div>';
            html += '<div style="margin-bottom:10px;">' + VkUI.renderCardLogos() + '</div>';
            html += '<div style="text-align:left;">';
            html += '<div style="font-size:13px;color:#2e7d32;margin-bottom:2px;"><span style="color:#2e7d32;">&#10003;</span> Pago 100% seguro</div>';
            html += '<div style="font-size:13px;color:#2e7d32;margin-bottom:2px;"><span style="color:#2e7d32;">&#10003;</span> Entrega en tu ciudad</div>';
            html += '<div style="font-size:13px;color:#2e7d32;"><span style="color:#2e7d32;">&#10003;</span> Documentos incluidos</div>';
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
            var referido = jQuery('#vk-referido-input').val().trim();
            if (referido) self.app.state.codigoReferido = referido;
            self.app.irAPaso(3);
        });

        // Referido toggle (credit flow)
        jQuery(document).off('click', '#vk-referido-toggle');
        jQuery(document).on('click', '#vk-referido-toggle', function() {
            jQuery('#vk-referido-field').slideToggle(200);
        });
    }
};
