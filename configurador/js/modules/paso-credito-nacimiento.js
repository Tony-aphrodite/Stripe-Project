/* ==========================================================================
   Voltika - Crédito Screen 7: CURP del cliente
   ──────────────────────────────────────────────────────────────────────────
   Customer brief 2026-04-30: matching by name was rejecting valid users
   for trivial differences (accent on Pérez vs Perez, double space, hyphen)
   and burning credit-bureau queries on each retry. The CURP is a unique
   18-character identifier with no whitespace/accent ambiguity, AND it
   embeds the date of birth (positions 5-10 = YYMMDD) — so a single CURP
   field replaces the previous birthday picker AND becomes the anchor we
   compare against the CURP Truora extracts from the INE photo.
   This screen now collects the CURP, derives fechaNacimiento from it for
   the CDC bureau call, and persists state.curp for the Truora token
   endpoint to use as expected_curp.
   Format: AAAA YYMMDD H/M EE EEE C  (regex below).
   ========================================================================== */

var PasoCreditoNacimiento = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    // CURP regex — RENAPO standard. 4 letters + 6 digits + H/M + 5 letters
    // + 1 alphanumeric homoclave + 1 digit verifier.
    _curpRegex: /^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/,

    render: function() {
        var state = this.app.state;
        var html = '';

        html += VkUI.renderBackButton('credito-nombre');
        html += VkUI.renderCreditoStepBar(1);

        html += '<h2 class="vk-paso__titulo">¿Cuál es tu CURP?</h2>';
        html += '<p class="vk-paso__subtitulo">La usamos para validar tu identidad y aprobar tu crédito Voltika.</p>';
        html += '<p class="vk-trust-highlight"><span class="vk-check"></span> Tu aprobación tarda <strong>menos de 2 minutos</strong></p>';

        var defaultCurp = state.curp || '';

        html += '<div class="vk-card" style="padding:20px;">';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">CURP (18 caracteres)</label>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:8px;">Debe coincidir exactamente con la CURP de tu INE.</div>';
        html += '<input type="text" class="vk-form-input" id="vk-cnac-curp" ' +
            'value="' + defaultCurp + '" ' +
            'maxlength="18" ' +
            'autocapitalize="characters" autocorrect="off" autocomplete="off" spellcheck="false" ' +
            'placeholder="Ej. PEMA850712HDFRRR05" ' +
            'style="color:#111;font-size:15px;padding:12px 14px;letter-spacing:1px;text-transform:uppercase;font-family:ui-monospace,Menlo,monospace;">';
        html += '</div>';

        // Live age preview — once the CURP parses, show the derived
        // birthday so the user sees what we'll send to the bureau.
        html += '<div id="vk-cnac-fecha-preview" style="display:none;font-size:12px;color:#16a34a;margin:-4px 0 12px;">';
        html += 'Fecha de nacimiento detectada: <strong id="vk-cnac-fecha-label"></strong>';
        html += '</div>';

        html += '<div id="vk-cnac-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        html += '<a href="https://www.gob.mx/curp/" target="_blank" rel="noopener" ' +
            'style="display:block;font-size:12px;color:#039fe1;text-align:center;margin-bottom:12px;">' +
            '¿No recuerdas tu CURP? Consultarla en gob.mx</a>';

        html += '<button class="vk-btn vk-btn--primary" id="vk-cnac-continuar">CONTINUAR</button>';

        html += '<div class="vk-trust">';
        html += '<div><span class="vk-check vk-check--sm"></span> Proceso seguro</div>';
        html += '<div><span class="vk-check vk-check--sm"></span> No afecta tu historial crediticio</div>';
        html += '</div>';

        html += '</div>';

        jQuery('#vk-credito-nacimiento-container').html(html);

        // Re-show the birthday preview if state already has a parsed CURP
        // (returning to this paso via back button etc.).
        if (defaultCurp && this._curpRegex.test(defaultCurp)) {
            this._showBirthdayPreview(defaultCurp);
        }
    },

    bindEvents: function() {
        var self = this;

        // Live formatting + birthday preview as the user types.
        jQuery(document).off('input', '#vk-cnac-curp');
        jQuery(document).on('input', '#vk-cnac-curp', function() {
            var raw = jQuery(this).val() || '';
            var clean = raw.toUpperCase().replace(/[^A-Z0-9]/g, '').substr(0, 18);
            if (clean !== raw) jQuery(this).val(clean);
            jQuery('#vk-cnac-error').hide();
            if (clean.length === 18 && self._curpRegex.test(clean)) {
                self._showBirthdayPreview(clean);
            } else {
                jQuery('#vk-cnac-fecha-preview').hide();
            }
        });

        jQuery(document).off('click', '#vk-cnac-continuar');
        jQuery(document).on('click', '#vk-cnac-continuar', function() {
            var curp = (jQuery('#vk-cnac-curp').val() || '').toUpperCase().trim();

            if (!curp) {
                jQuery('#vk-cnac-error').text('Ingresa tu CURP.').show();
                return;
            }
            if (curp.length !== 18) {
                jQuery('#vk-cnac-error').text('La CURP debe tener exactamente 18 caracteres.').show();
                return;
            }
            if (!self._curpRegex.test(curp)) {
                jQuery('#vk-cnac-error').text('Formato de CURP inválido. Revisa el orden de letras y números.').show();
                return;
            }

            var fecha = self._birthdayFromCurp(curp);
            if (!fecha) {
                jQuery('#vk-cnac-error').text('No pudimos extraer la fecha de nacimiento de tu CURP. Verifícala.').show();
                return;
            }

            // Age >= 18 check (same business rule as before, just derived
            // from the CURP instead of a separate date input).
            var nacimiento = new Date(fecha + 'T00:00:00');
            var hoy = new Date();
            var edad = hoy.getFullYear() - nacimiento.getFullYear();
            var mesDiff = hoy.getMonth() - nacimiento.getMonth();
            if (mesDiff < 0 || (mesDiff === 0 && hoy.getDate() < nacimiento.getDate())) {
                edad--;
            }
            if (edad < 18) {
                jQuery('#vk-cnac-error').text('Debes tener al menos 18 años para solicitar un crédito Voltika.').show();
                return;
            }

            jQuery('#vk-cnac-error').hide();

            // Persist BOTH so downstream code keeps working unchanged.
            // - state.curp        → truora-token.php expected_curp anchor
            // - state.fechaNacimiento → CDC bureau call
            self.app.state.curp = curp;
            self.app.state.fechaNacimiento = fecha;
            self.app.irAPaso('credito-cp-dom');
        });
    },

    // Position 5-10 of CURP (0-indexed 4-9) = YYMMDD. Positions 25-99
    // belong to the 21st century (year 2000-2099); positions 00-24 to
    // the 20th century (1925-1999). Standard RENAPO interpretation.
    _birthdayFromCurp: function(curp) {
        if (!curp || curp.length < 10) return null;
        var yy = parseInt(curp.substr(4, 2), 10);
        var mm = parseInt(curp.substr(6, 2), 10);
        var dd = parseInt(curp.substr(8, 2), 10);
        if (isNaN(yy) || isNaN(mm) || isNaN(dd)) return null;
        if (mm < 1 || mm > 12 || dd < 1 || dd > 31) return null;
        // The 11th char (homoclave letter) used to disambiguate century:
        // letters A-Z → 21st century (2000+); digits 0-9 → 20th century.
        // Pre-1996 CURPs used digits at this position; post-1996 use letters.
        var homo = curp.substr(16, 1);
        var century = /[A-Z]/.test(homo) ? 2000 : 1900;
        var fullYear = century + yy;
        // Build YYYY-MM-DD with zero-padding.
        var pad = function(n) { return n < 10 ? '0' + n : '' + n; };
        return fullYear + '-' + pad(mm) + '-' + pad(dd);
    },

    _showBirthdayPreview: function(curp) {
        var fecha = this._birthdayFromCurp(curp);
        if (!fecha) {
            jQuery('#vk-cnac-fecha-preview').hide();
            return;
        }
        // Display as DD/MM/YYYY for the user (Spanish locale convention).
        var parts = fecha.split('-');
        var pretty = parts[2] + '/' + parts[1] + '/' + parts[0];
        jQuery('#vk-cnac-fecha-label').text(pretty);
        jQuery('#vk-cnac-fecha-preview').show();
    }
};
