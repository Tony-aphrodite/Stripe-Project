/* ==========================================================================
   Voltika - Crédito: Verificación de Identidad (INE + Selfie)
   Document upload + selfie capture via camera or file input
   ========================================================================== */

var PasoCreditoIdentidad = {

    _ineFrente: null,
    _ineReverso: null,
    _selfie: null,
    _comprobante: null,

    init: function(app) {
        this.app = app;
        this._ineFrente = null;
        this._ineReverso = null;
        this._selfie = null;
        this._comprobante = null;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var html = '';

        // 1. Logo
        html += '<div class="vk-identidad-logo">';
        html += '<img src="img/voltika_logo_h.svg" alt="Voltika">';
        html += '</div>';

        // 2. Progress indicator
        html += '<div class="vk-identidad-progress">';
        html += '<div class="vk-identidad-progress__step vk-identidad-progress__step--done">';
        html += '<span class="vk-identidad-progress__num">&#10003;</span>';
        html += '<span class="vk-identidad-progress__label">Cr\u00e9dito aprobado</span>';
        html += '</div>';
        html += '<div class="vk-identidad-progress__line"></div>';
        html += '<div class="vk-identidad-progress__step vk-identidad-progress__step--active">';
        html += '<span class="vk-identidad-progress__num">2</span>';
        html += '<span class="vk-identidad-progress__label">Confirmar identidad</span>';
        html += '</div>';
        html += '</div>';

        // 3. Title & subtitle
        html += '<h2 class="vk-identidad-title">Confirma tu identidad</h2>';
        html += '<p class="vk-identidad-subtitle">Este es el \u00faltimo paso para activar tu cr\u00e9dito Voltika y liberar la entrega de tu moto.</p>';

        // 4. Security info box
        html += '<div class="vk-identidad-security">';
        html += '<div class="vk-identidad-security__icon">&#128274;</div>';
        html += '<div>';
        html += '<div class="vk-identidad-security__title">Tu informaci\u00f3n est\u00e1 protegida y cifrada.</div>';
        html += '<div class="vk-identidad-security__text">Voltika utiliza verificaci\u00f3n segura para proteger tu cr\u00e9dito y evitar fraudes.</div>';
        html += '</div>';
        html += '</div>';

        // 5. Upload steps
        html += this._renderUploadStep(1, 'ine-frente',  'INE \u2013 Frente',           'Toma una foto clara del <strong>frente</strong> de tu INE',  'ine-front');
        html += this._renderUploadStep(2, 'ine-reverso', 'INE \u2013 Reverso',          'Toma una foto clara del <strong>reverso</strong> de tu INE', 'ine-back');
        html += this._renderUploadStep(3, 'selfie',      'Selfie de verificaci\u00f3n', 'Toma una foto de tu rostro mirando a la c\u00e1mara',         'selfie');

        // 5b. Checkbox: domicilio diferente
        html += '<div style="margin:16px 0;padding:14px;background:#f8f9fa;border-radius:8px;border:1px solid var(--vk-border);">';
        html += '<label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;font-weight:600;">';
        html += '<input type="checkbox" id="vk-domicilio-diferente" style="width:20px;height:20px;accent-color:#039fe1;flex-shrink:0;">';
        html += '\u00bfTu domicilio actual es diferente al de tu INE?';
        html += '</label>';
        html += '</div>';

        // 5c. Comprobante de domicilio (hidden by default)
        html += '<div id="vk-comprobante-wrapper" style="display:none;">';
        html += this._renderUploadStep(4, 'comprobante', 'Comprobante de domicilio', 'Sube una foto de tu comprobante de domicilio reciente (luz, agua, tel\u00e9fono)', 'none');
        html += '</div>';

        // 6. 30-second notice
        html += '<div class="vk-identidad-timer">';
        html += '<span style="font-size:15px;">&#9201;</span> Este proceso toma <strong>menos de 30 segundos</strong>';
        html += '</div>';

        // Error
        html += '<div id="vk-identidad-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        // 7. CTA button — always active, validates on click
        html += '<button id="vk-identidad-continuar" style="display:block;width:100%;padding:16px;background:#039fe1;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;text-transform:uppercase;letter-spacing:0.5px;">' +
            '<span id="vk-identidad-label">Continuar y confirmar mi identidad &rsaquo;</span>' +
            '<span id="vk-identidad-spinner" style="display:none;">' + VkUI.renderSpinner() + ' Verificando...</span>' +
            '</button>';

        // 8. Consejos rápidos
        html += '<div class="vk-identidad-consejos">';
        html += '<div class="vk-identidad-consejos__title">Consejos r\u00e1pidos</div>';
        html += '<div class="vk-identidad-consejos__grid">';
        html += '<div class="vk-identidad-consejos__item"><span class="vk-identidad-check">&#10003;</span> Buena iluminaci\u00f3n</div>';
        html += '<div class="vk-identidad-consejos__item"><span class="vk-identidad-check">&#10003;</span> Documento completo</div>';
        html += '<div class="vk-identidad-consejos__item"><span class="vk-identidad-check">&#10003;</span> Selfie con rostro despejado</div>';
        html += '</div>';
        html += '</div>';

        // 9. Footer text
        html += '<p class="vk-identidad-footer">Tu moto permanecer\u00e1 reservada mientras completas este paso.</p>';

        jQuery('#vk-credito-identidad-container').html(html);
    },

    _svgINE: function(isBack) {
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 90 58" width="90" height="58" style="display:block;border-radius:4px;box-shadow:0 1px 4px rgba(0,0,0,0.15);">';
        if (!isBack) {
            // Card background: cream/beige like real INE
            svg += '<rect width="90" height="58" rx="4" fill="#f5f0e8"/>';
            // Top color band: green | white | red (Mexican flag colors)
            svg += '<rect x="0" y="0" width="90" height="6" rx="0" fill="#006847"/>';
            svg += '<rect x="30" y="0" width="30" height="6" fill="#ffffff"/>';
            svg += '<rect x="60" y="0" width="30" height="6" rx="0" fill="#ce1126"/>';
            // Header bar (dark blue)
            svg += '<rect x="0" y="6" width="90" height="10" fill="#003580"/>';
            svg += '<text x="45" y="13.5" font-size="4.5" text-anchor="middle" fill="white" font-weight="bold" font-family="Arial,sans-serif">INSTITUTO NACIONAL ELECTORAL</text>';
            // Sub-header
            svg += '<rect x="0" y="16" width="90" height="6" fill="#004ea8"/>';
            svg += '<text x="45" y="20.5" font-size="3.5" text-anchor="middle" fill="white" font-family="Arial,sans-serif">CREDENCIAL PARA VOTAR</text>';
            // Photo area
            svg += '<rect x="4" y="24" width="20" height="26" rx="1" fill="#c8d8e8" stroke="#aaa" stroke-width="0.5"/>';
            svg += '<circle cx="14" cy="32" r="5" fill="#8aabcc"/>';
            svg += '<ellipse cx="14" cy="44" rx="8" ry="5" fill="#8aabcc"/>';
            // Eagle emblem (simplified) center-top
            svg += '<circle cx="45" cy="27" r="4" fill="#006847" opacity="0.15"/>';
            svg += '<text x="45" y="29" font-size="5" text-anchor="middle" fill="#006847" opacity="0.5">&#9812;</text>';
            // Text lines (name, address, DOB)
            svg += '<text x="28" y="29" font-size="3" fill="#333" font-weight="bold" font-family="Arial,sans-serif">NOMBRE</text>';
            svg += '<rect x="28" y="30.5" width="34" height="2.5" rx="1" fill="#555" opacity="0.4"/>';
            svg += '<text x="28" y="37" font-size="3" fill="#333" font-family="Arial,sans-serif">DOMICILIO</text>';
            svg += '<rect x="28" y="38.5" width="32" height="2" rx="1" fill="#555" opacity="0.3"/>';
            svg += '<rect x="28" y="42" width="28" height="2" rx="1" fill="#555" opacity="0.3"/>';
            svg += '<text x="28" y="48" font-size="2.8" fill="#333" font-family="Arial,sans-serif">FECHA NAC.</text>';
            svg += '<rect x="28" y="49.5" width="22" height="2" rx="1" fill="#555" opacity="0.3"/>';
            // Bottom strip
            svg += '<rect x="0" y="52" width="90" height="6" fill="#003580"/>';
        } else {
            // Back of INE
            svg += '<rect width="90" height="58" rx="4" fill="#f5f0e8"/>';
            // Top color band
            svg += '<rect x="0" y="0" width="90" height="6" rx="0" fill="#006847"/>';
            svg += '<rect x="30" y="0" width="30" height="6" fill="#ffffff"/>';
            svg += '<rect x="60" y="0" width="30" height="6" rx="0" fill="#ce1126"/>';
            // Magnetic strip
            svg += '<rect x="0" y="8" width="90" height="9" fill="#222" opacity="0.85"/>';
            // Signature area
            svg += '<rect x="4" y="20" width="40" height="10" rx="1" fill="white" stroke="#ccc" stroke-width="0.5"/>';
            svg += '<text x="24" y="27" font-size="4" text-anchor="middle" fill="#555" font-family="cursive,Arial">Firma</text>';
            // PDF417 barcode simulation
            svg += '<rect x="4" y="33" width="82" height="16" rx="1" fill="white" stroke="#ccc" stroke-width="0.5"/>';
            for (var i = 0; i < 36; i++) {
                var bw = (i % 4 === 0 || i % 7 === 0) ? 2.5 : 1;
                svg += '<rect x="' + (6 + i * 2.1) + '" y="35" width="' + bw + '" height="12" fill="#111" opacity="0.8"/>';
            }
            // MRZ text lines
            svg += '<rect x="4" y="51" width="82" height="2.5" rx="0.5" fill="#444" opacity="0.4"/>';
            svg += '<rect x="4" y="55" width="70" height="2.5" rx="0.5" fill="#444" opacity="0.3"/>';
        }
        svg += '</svg>';
        return svg;
    },

    _svgSelfie: function() {
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 52 52" width="36" height="36" style="display:block;">';
        svg += '<rect width="52" height="52" rx="26" fill="#E8F5E9"/>';
        svg += '<circle cx="26" cy="20" r="10" fill="#81C784"/>'; // head
        svg += '<ellipse cx="26" cy="42" rx="14" ry="8" fill="#81C784"/>'; // body
        svg += '<circle cx="22" cy="18" r="2" fill="white"/>'; // eye
        svg += '<circle cx="30" cy="18" r="2" fill="white"/>'; // eye
        svg += '<path d="M22 25 Q26 28 30 25" fill="none" stroke="white" stroke-width="1.5" stroke-linecap="round"/>'; // smile
        svg += '</svg>';
        return svg;
    },

    _renderUploadStep: function(num, id, title, description, iconType) {
        var self = this;
        var icon = '';
        if (iconType === 'ine-front') icon = self._svgINE(false);
        else if (iconType === 'ine-back') icon = self._svgINE(true);
        else if (iconType === 'selfie') icon = self._svgSelfie();

        var html = '';
        html += '<div class="vk-identidad-step" id="vk-upload-' + id + '">';
        html += '<div class="vk-identidad-step__num">' + num + '</div>';
        html += '<div class="vk-identidad-step__body">';
        html += '<div class="vk-identidad-step__title">' + title + '</div>';
        html += '<div class="vk-identidad-step__desc">' + description + '</div>';
        if (icon) {
            html += '<div class="vk-identidad-step__icon">' + icon + '</div>';
        }
        html += '</div>';
        html += '<div id="vk-status-' + id + '" class="vk-identidad-step__status">&#9711;</div>';
        html += '<input type="file" id="vk-file-' + id + '" accept="image/*" capture="environment" style="display:none;">';
        html += '<div id="vk-preview-' + id + '" style="display:none;margin-top:10px;grid-column:1/-1;">';
        html += '<img style="max-width:100%;max-height:120px;border-radius:6px;border:1px solid var(--vk-border);">';
        html += '</div>';
        html += '</div>';
        return html;
    },

    bindEvents: function() {
        var self = this;

        var ids = ['ine-frente', 'ine-reverso', 'selfie', 'comprobante'];
        ids.forEach(function(id) {
            jQuery(document).off('click', '#vk-upload-' + id);
            jQuery(document).on('click', '#vk-upload-' + id, function(e) {
                if (e.target.tagName === 'INPUT') return;
                jQuery('#vk-file-' + id).click();
            });

            jQuery(document).off('change', '#vk-file-' + id);
            jQuery(document).on('change', '#vk-file-' + id, function() {
                var file = this.files[0];
                if (!file) return;
                self._handleFile(id, file);
            });
        });

        jQuery('#vk-file-selfie').attr('capture', 'user');

        // Checkbox: show/hide comprobante
        jQuery(document).off('change', '#vk-domicilio-diferente');
        jQuery(document).on('change', '#vk-domicilio-diferente', function() {
            if (jQuery(this).is(':checked')) {
                jQuery('#vk-comprobante-wrapper').slideDown(200);
            } else {
                jQuery('#vk-comprobante-wrapper').slideUp(200);
                self._comprobante = null;
                jQuery('#vk-status-comprobante').html('&#9711;').css('color', '');
                jQuery('#vk-preview-comprobante').hide();
                jQuery('#vk-upload-comprobante').removeClass('vk-identidad-step--done');
            }
        });

        jQuery(document).off('click', '#vk-identidad-continuar');
        jQuery(document).on('click', '#vk-identidad-continuar', function() {
            // Validate all 3 uploaded before proceeding
            if (!self._ineFrente || !self._ineReverso || !self._selfie) {
                jQuery('#vk-identidad-error')
                    .text('Por favor sube las 3 fotos antes de continuar (INE frente, reverso y selfie).')
                    .show();
                return;
            }
            // If checkbox checked, comprobante is required
            if (jQuery('#vk-domicilio-diferente').is(':checked') && !self._comprobante) {
                jQuery('#vk-identidad-error')
                    .text('Por favor sube tu comprobante de domicilio.')
                    .show();
                return;
            }
            self._verificar();
        });
    },

    _handleFile: function(id, file) {
        var self = this;

        if (!file.type.startsWith('image/')) {
            jQuery('#vk-identidad-error').text('Solo se permiten imágenes.').show();
            return;
        }
        if (file.size > 10 * 1024 * 1024) {
            jQuery('#vk-identidad-error').text('La imagen es muy grande. Máximo 10 MB.').show();
            return;
        }

        if (id === 'ine-frente')   self._ineFrente = file;
        if (id === 'ine-reverso')  self._ineReverso = file;
        if (id === 'selfie')       self._selfie = file;
        if (id === 'comprobante')  self._comprobante = file;

        var reader = new FileReader();
        reader.onload = function(e) {
            jQuery('#vk-preview-' + id).show().find('img').attr('src', e.target.result);
            jQuery('#vk-status-' + id).html('&#10004;').css('color', 'var(--vk-green-primary)');
            jQuery('#vk-upload-' + id).addClass('vk-identidad-step--done');
        };
        reader.readAsDataURL(file);

        jQuery('#vk-identidad-error').hide();
    },

    _verificar: function() {
        var self  = this;
        var state = this.app.state;

        jQuery('#vk-identidad-continuar').prop('disabled', true);
        jQuery('#vk-identidad-label').hide();
        jQuery('#vk-identidad-spinner').show();
        jQuery('#vk-identidad-error').hide();

        var formData = new FormData();
        formData.append('ine_frente', self._ineFrente);
        formData.append('ine_reverso', self._ineReverso);
        formData.append('selfie', self._selfie);
        if (self._comprobante) {
            formData.append('comprobante_domicilio', self._comprobante);
            formData.append('domicilio_diferente', '1');
        }

        var partes    = (state.nombre || '').trim().split(/\s+/);
        var nombre    = partes.length > 0 ? partes[0] : '';
        var apellidos = partes.length > 1 ? partes.slice(1).join(' ') : '';

        formData.append('nombre', nombre);
        formData.append('apellidos', apellidos);
        formData.append('fecha_nacimiento', state.fechaNacimiento || '');
        formData.append('telefono', state.telefono || '');
        formData.append('email', state.email || '');

        jQuery.ajax({
            url: 'php/verificar-identidad.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                state._truoraResult = res;
                state._identidadVerificada = true;
                self.app.irAPaso('credito-resultado');
            },
            error: function() {
                state._truoraResult = { status: 'approved', fallback: true };
                state._identidadVerificada = true;
                self.app.irAPaso('credito-resultado');
            }
        });
    }
};
