/* ==========================================================================
   Voltika - Mock Postal Code Data
   Phase 1: Local lookup for prototype
   Phase 2: Replace with AJAX call to php/api/codigo-postal.php
   ========================================================================== */

var VOLTIKA_CP = {
    '01000': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika CDMX Sur' },
    '03100': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika Benito Juarez' },
    '06600': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika Roma Norte' },
    '11560': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika Polanco' },
    '14000': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika Tlalpan' },

    '20000': { ciudad: 'Aguascalientes', estado: 'Aguascalientes', centro: 'Centro Voltika Aguascalientes' },
    '22000': { ciudad: 'Tijuana', estado: 'Baja California', centro: 'Centro Voltika Tijuana' },
    '24000': { ciudad: 'Campeche', estado: 'Campeche', centro: 'Centro Voltika Campeche' },
    '29000': { ciudad: 'Tuxtla Gutierrez', estado: 'Chiapas', centro: 'Centro Voltika Tuxtla' },
    '31000': { ciudad: 'Chihuahua', estado: 'Chihuahua', centro: 'Centro Voltika Chihuahua' },

    '44100': { ciudad: 'Guadalajara', estado: 'Jalisco', centro: 'Centro Voltika Guadalajara Centro' },
    '44600': { ciudad: 'Guadalajara', estado: 'Jalisco', centro: 'Centro Voltika Guadalajara Sur' },
    '45050': { ciudad: 'Zapopan', estado: 'Jalisco', centro: 'Centro Voltika Zapopan' },

    '50000': { ciudad: 'Toluca', estado: 'Estado de Mexico', centro: 'Centro Voltika Toluca' },
    '53100': { ciudad: 'Naucalpan', estado: 'Estado de Mexico', centro: 'Centro Voltika Naucalpan' },

    '58000': { ciudad: 'Morelia', estado: 'Michoacan', centro: 'Centro Voltika Morelia' },
    '62000': { ciudad: 'Cuernavaca', estado: 'Morelos', centro: 'Centro Voltika Cuernavaca' },

    '64000': { ciudad: 'Monterrey', estado: 'Nuevo Leon', centro: 'Centro Voltika Monterrey Centro' },
    '64700': { ciudad: 'Monterrey', estado: 'Nuevo Leon', centro: 'Centro Voltika Monterrey Sur' },
    '66220': { ciudad: 'San Pedro Garza Garcia', estado: 'Nuevo Leon', centro: 'Centro Voltika San Pedro' },

    '68000': { ciudad: 'Oaxaca', estado: 'Oaxaca', centro: 'Centro Voltika Oaxaca' },
    '72000': { ciudad: 'Puebla', estado: 'Puebla', centro: 'Centro Voltika Puebla' },
    '76000': { ciudad: 'Queretaro', estado: 'Queretaro', centro: 'Centro Voltika Queretaro' },
    '78000': { ciudad: 'San Luis Potosi', estado: 'San Luis Potosi', centro: 'Centro Voltika SLP' },

    '80000': { ciudad: 'Culiacan', estado: 'Sinaloa', centro: 'Centro Voltika Culiacan' },
    '83000': { ciudad: 'Hermosillo', estado: 'Sonora', centro: 'Centro Voltika Hermosillo' },
    '86000': { ciudad: 'Villahermosa', estado: 'Tabasco', centro: 'Centro Voltika Villahermosa' },
    '91000': { ciudad: 'Xalapa', estado: 'Veracruz', centro: 'Centro Voltika Xalapa' },
    '97000': { ciudad: 'Merida', estado: 'Yucatan', centro: 'Centro Voltika Merida' },

    '_buscar': function(cp) {
        if (this[cp]) {
            return this[cp];
        }
        // Fallback: match by first 2 digits (state-level)
        var prefix = cp.substring(0, 2);
        for (var key in this) {
            if (key.substring(0, 2) === prefix && key !== '_buscar') {
                return {
                    ciudad: this[key].ciudad,
                    estado: this[key].estado,
                    centro: 'Centro Voltika Autorizado'
                };
            }
        }
        return null;
    }
};
