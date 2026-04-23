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

        // 5a-bis. CURP input — required for Truora Mexico identity lookup.
        // Gender is derived from CURP position 11 (H=hombre, M=mujer).
        var prefillCurp = (this.app && this.app.state && this.app.state.curp) ? this.app.state.curp : '';
        html += '<div style="margin:16px 0;">';
        html += '<label for="vk-curp-input" style="display:block;font-size:14px;font-weight:700;color:#1f2937;margin-bottom:6px;">';
        html += 'CURP <span style="color:#C62828;">*</span>';
        html += '</label>';
        html += '<input type="text" id="vk-curp-input" maxlength="18" autocomplete="off" ' +
            'value="' + String(prefillCurp).replace(/"/g, '&quot;') + '" ' +
            'style="width:100%;padding:12px;border:1px solid var(--vk-border);border-radius:8px;' +
            'font-size:15px;font-family:monospace;text-transform:uppercase;letter-spacing:1px;box-sizing:border-box;" ' +
            'placeholder="AAAA000000HAAAAA00">';
        html += '<div style="font-size:12px;color:#6b7280;margin-top:6px;">';
        html += '18 caracteres — lo encuentras al reverso de tu INE';
        html += '</div>';
        html += '</div>';

        // 5b. Checkbox: domicilio diferente
        html += '<div style="margin:16px 0;padding:14px;background:#f8f9fa;border-radius:8px;border:1px solid var(--vk-border);">';
        html += '<label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;font-weight:600;">';
        html += '<input type="checkbox" id="vk-domicilio-diferente" style="width:20px;height:20px;accent-color:#039fe1;flex-shrink:0;">';
        html += '\u00bfTu domicilio actual es diferente al de tu INE?';
        html += '</label>';
        html += '</div>';

        // 5c. Comprobante de domicilio (hidden by default)
        html += '<div id="vk-comprobante-wrapper" style="display:none;">';
        html += this._renderUploadStep(4, 'comprobante', 'Comprobante de domicilio', 'Sube una foto de tu comprobante de domicilio reciente (luz, agua, tel\u00e9fono, m\u00e1ximo 3 meses de antig\u00fcedad)', 'none');
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
        var base = window.VK_BASE_PATH || '';
        var icon = '';
        if (iconType === 'ine-front') icon = '<img src="' + base + 'img/ine1.png" alt="INE Frente" style="max-width:120px;height:auto;border-radius:4px;">';
        else if (iconType === 'ine-back') icon = '<img src="' + base + 'img/ine2.png" alt="INE Reverso" style="max-width:120px;height:auto;border-radius:4px;">';
        else if (iconType === 'selfie') icon = '<img src="' + base + 'img/faceid.png" alt="Selfie" style="width:36px;height:36px;">';

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
        // Deliberately NOT setting capture="environment" on INE / comprobante —
        // forcing the camera on mobile caused iOS Safari to reload the page
        // after shooting (returning the user to paso 1 = "home"). With only
        // accept="image/*" the user sees the native picker AND can still
        // choose the camera from there. The selfie is the exception:
        // for it we keep capture="user" (set in bindEvents) so the live
        // selfie is always taken fresh.
        html += '<input type="file" id="vk-file-' + id + '" accept="image/*" style="display:none;">';
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
                // Defense: stop the click from bubbling to any ancestor that
                // might navigate (e.g. a dropdown toggle or anchor wrapper).
                e.preventDefault();
                e.stopPropagation();
                try {
                    jQuery('#vk-file-' + id).click();
                } catch (err) {
                    jQuery('#vk-identidad-error').text('No se pudo abrir el selector de archivos. Intenta con otro navegador.').show();
                }
            });

            jQuery(document).off('change', '#vk-file-' + id);
            jQuery(document).on('change', '#vk-file-' + id, function() {
                var file = this.files[0];
                if (!file) return;
                self._handleFile(id, file);
            });
        });

        // Selfie is the only input where we force the live camera.
        jQuery('#vk-file-selfie').attr('capture', 'user');

        // Arm navigation guard while the user has uploaded at least one file
        // but hasn't submitted yet. Protects against accidental back / reload.
        self._armBeforeUnload();

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

        // Accept HEIC/HEIF (iOS default) by checking extension too — some iOS
        // versions report empty mime type for HEIC photos from the gallery.
        var name = (file.name || '').toLowerCase();
        var looksImage = file.type && file.type.indexOf('image/') === 0;
        var looksHeic  = /\.(heic|heif)$/.test(name);
        if (!looksImage && !looksHeic) {
            jQuery('#vk-identidad-error').text('El archivo no parece ser una imagen. Intenta con JPG o PNG.').show();
            return;
        }
        if (file.size > 25 * 1024 * 1024) {
            jQuery('#vk-identidad-error').text('La imagen es muy grande. Máximo 25 MB.').show();
            return;
        }
        if (looksHeic) {
            // HEIC can't be rendered in <img> on Chrome/Firefox Android.
            // Tell the user + still try to upload as-is (server handles it).
            jQuery('#vk-identidad-error').text('Aviso: tu foto es formato HEIC (iPhone). Si no ves la vista previa cambia a JPG en Ajustes → Cámara → Formatos → Más compatible.').show();
        }

        // Show a "processing" indicator while we resize to keep memory low.
        jQuery('#vk-status-' + id).html('&#8987;').css('color', '#999');

        self._resizeImage(file, 1920, 0.85).then(function(resized) {
            if (id === 'ine-frente')   self._ineFrente   = resized;
            if (id === 'ine-reverso')  self._ineReverso  = resized;
            if (id === 'selfie')       self._selfie      = resized;
            if (id === 'comprobante')  self._comprobante = resized;

            // Preview from the resized blob (cheaper than the original).
            var previewReader = new FileReader();
            previewReader.onload = function(e) {
                jQuery('#vk-preview-' + id).show().find('img').attr('src', e.target.result);
                jQuery('#vk-status-' + id).html('&#10004;').css('color', 'var(--vk-green-primary)');
                jQuery('#vk-upload-' + id).addClass('vk-identidad-step--done');
                if (!looksHeic) jQuery('#vk-identidad-error').hide();
                self._persistUploadedIds();
            };
            previewReader.onerror = function() {
                jQuery('#vk-identidad-error').text('No se pudo leer la imagen. Intenta con otra foto.').show();
                jQuery('#vk-status-' + id).html('&#10060;').css('color', '#C62828');
            };
            try {
                previewReader.readAsDataURL(resized);
            } catch (e) {
                jQuery('#vk-identidad-error').text('Error al procesar la imagen. Intenta con otra.').show();
                jQuery('#vk-status-' + id).html('&#10060;').css('color', '#C62828');
            }
        }).catch(function() {
            // Resize failed (likely HEIC or corrupted) — fall back to using
            // the original file so the user isn't blocked.
            if (id === 'ine-frente')   self._ineFrente   = file;
            if (id === 'ine-reverso')  self._ineReverso  = file;
            if (id === 'selfie')       self._selfie      = file;
            if (id === 'comprobante')  self._comprobante = file;
            jQuery('#vk-status-' + id).html('&#10004;').css('color', 'var(--vk-green-primary)');
            jQuery('#vk-upload-' + id).addClass('vk-identidad-step--done');
            self._persistUploadedIds();
        });
    },

    // Canvas-based downscale. Returns a Promise resolving to a Blob.
    // Keeps aspect ratio, converts to JPEG (smaller). Shrinks massive camera
    // photos (often 10-20 MB from modern phones) down to ~1-2 MB, dramatically
    // reducing mobile memory pressure and upload time.
    _resizeImage: function(file, maxSide, quality) {
        return new Promise(function(resolve, reject) {
            try {
                var url = URL.createObjectURL(file);
                var img = new Image();
                img.onload = function() {
                    try {
                        var w = img.width, h = img.height;
                        if (!w || !h) { URL.revokeObjectURL(url); reject(); return; }
                        var scale = Math.min(1, maxSide / Math.max(w, h));
                        var tw = Math.round(w * scale), th = Math.round(h * scale);
                        var canvas = document.createElement('canvas');
                        canvas.width = tw; canvas.height = th;
                        var ctx = canvas.getContext('2d');
                        ctx.drawImage(img, 0, 0, tw, th);
                        URL.revokeObjectURL(url);
                        canvas.toBlob(function(blob) {
                            if (blob) resolve(blob); else reject();
                        }, 'image/jpeg', quality);
                    } catch (e) { URL.revokeObjectURL(url); reject(); }
                };
                img.onerror = function() { URL.revokeObjectURL(url); reject(); };
                img.src = url;
            } catch (e) { reject(); }
        });
    },

    // Save which uploads are done so that if the page reloads or bfcache
    // restores the user, we can remind them which files to re-pick.
    _persistUploadedIds: function() {
        try {
            var done = [];
            if (this._ineFrente)  done.push('ine-frente');
            if (this._ineReverso) done.push('ine-reverso');
            if (this._selfie)     done.push('selfie');
            if (this._comprobante) done.push('comprobante');
            sessionStorage.setItem('vk_identidad_uploads', done.join(','));
        } catch (e) {}
    },

    // Warn the user before leaving the page if they've uploaded something
    // but haven't submitted. Prevents lost work from accidental back/reload.
    _armBeforeUnload: function() {
        var self = this;
        // Use the onbeforeunload property (not addEventListener) so we can
        // disarm it cleanly when leaving the step.
        window.onbeforeunload = function() {
            var hasSomething = self._ineFrente || self._ineReverso || self._selfie || self._comprobante;
            if (hasSomething) {
                return 'Si sales ahora se perderán las fotos que ya subiste. ¿Seguro que deseas salir?';
            }
        };
    },

    _disarmBeforeUnload: function() {
        window.onbeforeunload = null;
    },

    _verificar: function() {
        var self  = this;
        var state = this.app.state;

        jQuery('#vk-identidad-continuar').prop('disabled', true);
        jQuery('#vk-identidad-label').hide();
        jQuery('#vk-identidad-spinner').show();
        jQuery('#vk-identidad-error').hide();

        // Compress all images before upload (resize 1600px, JPEG q=0.82).
        // Prevents the "tamaño máximo del servidor" error on large phone
        // photos and dramatically speeds up mobile uploads. If the compress
        // util is missing, the originals are sent untouched (feature-detect).
        var _compress = window.voltikaCompressImage || function(f){ return Promise.resolve(f); };
        Promise.all([
            _compress(self._ineFrente),
            _compress(self._ineReverso),
            _compress(self._selfie),
            self._comprobante ? _compress(self._comprobante) : Promise.resolve(null)
        ]).then(function(compressed){
        var formData = new FormData();
        formData.append('ine_frente', compressed[0]);
        formData.append('ine_reverso', compressed[1]);
        formData.append('selfie', compressed[2]);
        if (compressed[3]) {
            formData.append('comprobante_domicilio', compressed[3]);
            formData.append('domicilio_diferente', '1');
        }

        // Use the dedicated fields collected in paso-credito-nombre.js
        // (apellidoPaterno + apellidoMaterno). Falling back to splitting
        // state.nombre on whitespace fails when nombre is a single word
        // (no apellido) → 400 from verificar-identidad.php.
        var nombre = (state.nombre || '').trim();
        var apellidos = ((state.apellidoPaterno || '') + ' ' + (state.apellidoMaterno || '')).trim();
        if (!apellidos && nombre.indexOf(' ') > 0) {
            // Last-resort: state.nombre might already include surname
            var parts = nombre.split(/\s+/);
            nombre = parts[0];
            apellidos = parts.slice(1).join(' ');
        }

        // CURP — required; gender derived from CURP position 11.
        var curpRaw = (jQuery('#vk-curp-input').val() || '').toUpperCase().replace(/\s+/g, '');
        var curpOk = /^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/.test(curpRaw);
        if (!curpOk) {
            jQuery('#vk-identidad-continuar').prop('disabled', false);
            jQuery('#vk-identidad-label').show();
            jQuery('#vk-identidad-spinner').hide();
            jQuery('#vk-identidad-error')
                .html('<strong>Ingresa un CURP válido.</strong><div style="margin-top:6px;font-size:12px;color:#666;">Son 18 caracteres. Lo encuentras al reverso de tu INE o en tu constancia oficial.</div>')
                .show();
            jQuery('#vk-curp-input').focus();
            return;
        }
        state.curp = curpRaw;
        var genderFromCurp = curpRaw.charAt(10);
        var truoraGender = genderFromCurp === 'M' ? 'F' : 'M';

        formData.append('nombre', nombre);
        formData.append('apellidos', apellidos);
        formData.append('fecha_nacimiento', state.fechaNacimiento || '');
        formData.append('telefono', state.telefono || '');
        formData.append('email', state.email || '');
        formData.append('gender', truoraGender);
        formData.append('state_id', state.estadoDomicilio || state.estado || '');
        formData.append('curp', curpRaw);

        // Helper: show an error below the Continuar button and re-enable it.
        // If the backend returned HTTP/body detail, render them so we can
        // diagnose Truora-side failures (signature, 503, etc.).
        function showError(msg, detail) {
            jQuery('#vk-identidad-continuar').prop('disabled', false);
            jQuery('#vk-identidad-label').show();
            jQuery('#vk-identidad-spinner').hide();
            var html = '<strong>' + msg + '</strong>';
            if (detail) html += '<div style="margin-top:8px;font-size:11px;color:#666;white-space:pre-wrap;">' + detail + '</div>';
            jQuery('#vk-identidad-error').html(html).show();
        }

        function detailFromRes(res) {
            if (!res) return '';
            var parts = [];
            if (res.http)     parts.push('HTTP: ' + res.http);
            if (res.curl_err) parts.push('curl: ' + res.curl_err);
            if (res.body)     parts.push('resp: ' + String(res.body).substring(0,400));
            return parts.join('\n');
        }

        jQuery.ajax({
            url: 'php/verificar-identidad.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                if (res && res.status === 'error') {
                    showError(res.message || 'No pudimos verificar tu identidad.', detailFromRes(res));
                    return;
                }
                if (res && res.status === 'pending') {
                    showError(res.message || 'Truora aún está procesando. Espera un momento y reintenta.', detailFromRes(res));
                    return;
                }

                state._truoraResult = res;

                // BLOCK if face match explicitly failed (selfie ≠ INE photo)
                if (res && res.face && res.face.match === false) {
                    var err = 'No pudimos confirmar que la selfie coincide con la foto de tu INE. Toma la selfie con buena iluminación y rostro despejado, usando la MISMA persona que aparece en la INE.';
                    if (res.face.error === 'face_match_api_error') {
                        err = 'El servicio de verificación facial está temporalmente no disponible. Vuelve a intentar en un momento.';
                    } else if (res.face.similarity !== null && res.face.similarity !== undefined) {
                        err += ' (similitud: ' + Math.round(res.face.similarity * 100) + '%, mínimo 70%)';
                    }
                    showError(err, detailFromRes(res.face));
                    return;
                }

                // BLOCK if overall Truora status is rejected (no face check, bad identity)
                if (res && res.status === 'rejected') {
                    showError('No pudimos verificar tu identidad con el gobierno. Revisa que tu nombre, fecha de nacimiento, CURP y estado coincidan con los datos oficiales de tu INE.', detailFromRes(res));
                    return;
                }

                state._identidadVerificada = true;
                self._disarmBeforeUnload();
                try { sessionStorage.removeItem('vk_identidad_uploads'); } catch (e) {}
                self.app.irAPaso('credito-enganche');
            },
            error: function(xhr) {
                var body = (xhr && xhr.responseJSON) || null;
                var msg  = (body && body.message) || 'No pudimos conectar con el servicio de verificación.';
                showError(msg, detailFromRes(body || { http: xhr && xhr.status }));
            }
        });
        });  // Promise.all(_compress).then
    }
};
