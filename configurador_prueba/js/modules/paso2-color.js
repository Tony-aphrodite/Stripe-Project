/* ==========================================================================
   Voltika - PASO 2: Color Selection
   Shows selected model with color picker and payment-specific info
   ========================================================================== */

var Paso2 = {

    init: function(app) {
        this.app = app;
        this._invMap = {}; // color → count

        // Fetch inventory for ALL colors of this model, then render
        var self = this;
        var modelo = app.getModelo(app.state.modeloSeleccionado);
        var base = window.VK_BASE_PATH || '';
        if (modelo) {
            var invUrl = base + 'php/check-inventory.php?modelo=' + encodeURIComponent(modelo.nombre);
            var existingRef = (app.state.codigoReferido || '').trim();
            if (existingRef && app.state.referidoData && app.state.referidoData.tipo === 'punto') {
                invUrl += '&referido=' + encodeURIComponent(existingRef);
            }
            jQuery.getJSON(invUrl)
            .done(function(r) {
                if (r.ok && r.mapa && r.mapa[modelo.nombre]) {
                    self._invMap = r.mapa[modelo.nombre];
                }
                var color = app.state.colorSeleccionado || modelo.colorDefault;
                app.state._invColorTotal = self._invMap[color] || 0;
                app.state._invColorEnStock = app.state._invColorTotal > 0;
            })
            .always(function() {
                self.render();
                self.bindEvents();
            });
        } else {
            this.render();
            this.bindEvents();
        }
    },

    render: function() {
        var self = this;
        var state = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        if (!modelo) return;

        var colorActual = state.colorSeleccionado || modelo.colorDefault;
        var invMap = this._invMap || {};
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

        // ETA date for out-of-stock colors (~2 months)
        var _etaDate = new Date(); _etaDate.setMonth(_etaDate.getMonth() + 2);
        var _meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        var _etaStr = _etaDate.getDate() + ' de ' + _meses[_etaDate.getMonth()];

        html += '<div class="vk-color-picker">';
        for (var i = 0; i < modelo.colores.length; i++) {
            var c = modelo.colores[i];
            var stock = invMap[c.id] || 0;
            var activeCls = c.id === colorActual ? ' vk-color-swatch--active' : '';

            html += '<div class="vk-color-swatch' + activeCls + '" data-color="' + c.id + '">' +
                '<div class="vk-color-swatch__circle" style="background:' + c.hex + ';"></div>' +
                '<div class="vk-color-swatch__label">' + c.nombre + '</div>' +
                (stock > 0 ? '' : '<div style="font-size:10px;margin-top:2px;color:#b91c1c;font-weight:600;">Agotado</div>') +
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
        html += '<div style="background:#fff;border-radius:12px;padding:16px;border:1px solid #eee;box-shadow:0 1px 4px rgba(0,0,0,0.05);">';
        html += this.renderPaymentInfo(modelo, state.metodoPago);
        html += '</div>';
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
        html += '<div id="vk-referido-feedback" style="font-size:13px;margin-top:6px;min-height:18px;"></div>';
        html += '</div>';
        html += '</div>';

        // Out-of-stock notice (hidden by default, shown via JS)
        html += '<div id="vk-stock-notice" style="display:none;margin:0 20px 12px;padding:12px 14px;border-radius:10px;background:#FFF7ED;border:1px solid #FDBA74;font-size:13px;color:#9A3412;">' +
            'Este color no está disponible de momento. Puedes continuar y recibirás tu moto el <strong>' + _etaStr + '</strong>, o seleccionar otro color.' +
            '</div>';
        html += '<button class="vk-btn vk-btn--primary" id="vk-paso2-continuar">' + btnTexto + '</button>';

        html += '<p class="vk-card__footer-note">' +
            'Solo falta confirmar tu <strong>punto de entrega.</strong>' +
            '</p>';

        html += VkUI.renderTrustBadges(state.metodoPago || 'credito');

        html += '</div>'; // end right

        html += '</div>'; // end desktop-split

        html += '</div>'; // end card

        jQuery('#vk-color-container').html(html);

        // Show/hide stock notice for initial color
        var initStock = invMap[colorActual] || 0;
        if (initStock <= 0) jQuery('#vk-stock-notice').show();

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

                // Update inventory state from cached map
                var stock = (self._invMap || {})[color] || 0;
                self.app.state._invColorTotal = stock;
                self.app.state._invColorEnStock = stock > 0;

                // Toggle out-of-stock notice
                if (stock > 0) {
                    jQuery('#vk-stock-notice').slideUp(150);
                } else {
                    jQuery('#vk-stock-notice').slideDown(150);
                }
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
            var referido = (jQuery('#vk-referido-input').val() || '').trim().toUpperCase();
            if (!referido) {
                // Clear any previous referido state and advance
                self.app.state.codigoReferido = '';
                self.app.state.referidoData = null;
                self.app.irAPaso(3);
                return;
            }
            // Validate before advancing — block on invalid code
            self._validarReferido(referido, function(data) {
                if (data && data.ok) {
                    self.app.state.codigoReferido = referido;
                    self.app.state.referidoData = data;
                    self.app.irAPaso(3);
                } else {
                    self._setReferidoFeedback('error', (data && data.error) || 'Código no válido');
                    jQuery('#vk-referido-field').slideDown(0);
                    jQuery('#vk-referido-input').focus().css('border-color', '#D32F2F');
                    setTimeout(function(){ jQuery('#vk-referido-input').css('border-color',''); }, 3000);
                }
            });
        });

        // Referido toggle (credit flow)
        jQuery(document).off('click', '#vk-referido-toggle');
        jQuery(document).on('click', '#vk-referido-toggle', function() {
            jQuery('#vk-referido-field').slideToggle(200);
        });

        // Referido input — debounced validation on typing + uppercase
        jQuery(document).off('input', '#vk-referido-input').off('blur', '#vk-referido-input');
        var debounceTimer = null;
        jQuery(document).on('input', '#vk-referido-input', function() {
            var $el = jQuery(this);
            var val = ($el.val() || '').toUpperCase();
            $el.val(val);
            self._setReferidoFeedback('idle', '');
            if (debounceTimer) clearTimeout(debounceTimer);
            if (val.length < 3) return;
            debounceTimer = setTimeout(function() {
                self._validarReferido(val, function(data) {
                    if (data && data.ok) {
                        var label = data.tipo === 'punto'
                            ? 'Código válido · Punto: ' + data.nombre
                            : 'Código válido · Referido: ' + data.nombre;
                        self._setReferidoFeedback('ok', label);
                        self.app.state.referidoData = data;
                        self.app.state.codigoReferido = val;
                        // Re-fetch inventory including punto's consignación stock
                        if (data.tipo === 'punto') {
                            self._refetchInventory(val);
                        }
                    } else {
                        self._setReferidoFeedback('error', (data && data.error) || 'Código no válido');
                        self.app.state.referidoData = null;
                    }
                });
            }, 450);
        });
    },

    _setReferidoFeedback: function(kind, msg) {
        var $fb = jQuery('#vk-referido-feedback');
        if (!$fb.length) return;
        if (!msg) { $fb.text('').css('color',''); return; }
        var color = kind === 'ok' ? '#2e7d32' : (kind === 'error' ? '#C62828' : '#777');
        var prefix = kind === 'ok' ? '\u2713 ' : (kind === 'error' ? '\u26A0 ' : '');
        $fb.text(prefix + msg).css('color', color);
    },

    _validarReferido: function(codigo, cb) {
        var basePath = window.VK_BASE_PATH || '';
        jQuery.ajax({
            url: basePath + 'php/validar-referido.php',
            data: { codigo: codigo },
            dataType: 'json',
            timeout: 8000,
            success: function(data) { cb(data || {ok:false}); },
            error:   function()     { cb({ok:false, error:'Error de red'}); }
        });
    },

    _refetchInventory: function(referidoCodigo) {
        var self = this;
        var app = this.app;
        var modelo = app.getModelo(app.state.modeloSeleccionado);
        if (!modelo) return;
        var base = window.VK_BASE_PATH || '';
        var url = base + 'php/check-inventory.php?modelo=' + encodeURIComponent(modelo.nombre)
                + '&referido=' + encodeURIComponent(referidoCodigo);
        jQuery.getJSON(url).done(function(r) {
            if (r.ok && r.mapa && r.mapa[modelo.nombre]) {
                self._invMap = r.mapa[modelo.nombre];
                var color = app.state.colorSeleccionado || modelo.colorDefault;
                app.state._invColorTotal = self._invMap[color] || 0;
                app.state._invColorEnStock = app.state._invColorTotal > 0;
            }
        });
    }
};
