/* ==========================================================================
   Voltika - Form Validations
   ========================================================================== */

var VkValidacion = {

    codigoPostal: function(cp) {
        return /^\d{5}$/.test(cp);
    },

    telefono: function(tel) {
        var limpio = tel.replace(/\D/g, '');
        return /^(\+?52)?[1-9]\d{9}$/.test(limpio) || /^[1-9]\d{9}$/.test(limpio);
    },

    email: function(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    },

    nombre: function(nombre) {
        var trimmed = nombre.trim();
        return trimmed.length >= 3;
    },

    /**
     * Validate a field and show/hide error
     * @param {jQuery} $input - The input element
     * @param {Function} validatorFn - Validation function
     * @param {string} errorMsg - Error message to display
     * @returns {boolean} Whether the field is valid
     */
    validarCampo: function($input, validatorFn, errorMsg) {
        var valor = $input.val();
        var $error = $input.siblings('.vk-form-error');

        if (!$error.length) {
            $error = $('<div class="vk-form-error"></div>');
            $input.after($error);
        }

        if (validatorFn(valor)) {
            $input.removeClass('vk-form-input--error');
            $error.text('').hide();
            return true;
        } else {
            $input.addClass('vk-form-input--error');
            $error.text(errorMsg).show();
            return false;
        }
    }
};
