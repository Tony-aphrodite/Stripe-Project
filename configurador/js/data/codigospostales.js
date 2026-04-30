/* ==========================================================================
   Voltika - Codigo Postal Database
   Covers all Mexican states by CP prefix (first 2 digits = state)
   Any valid 5-digit Mexican CP will resolve to a city/state
   ========================================================================== */

var VOLTIKA_CP = {

    // ── CDMX (01-16) ──────────────────────────────────────────────────────────
    '01000': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika CDMX Sur' },
    '02000': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika Azcapotzalco' },
    '03100': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika Benito Juarez' },
    '04000': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika Coyoacan' },
    '05000': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika Gustavo A. Madero' },
    '06000': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika Centro Historico' },
    '06600': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika Roma Norte' },
    '07000': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika GAM Norte' },
    '08000': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika Iztacalco' },
    '09000': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika Iztapalapa' },
    '10000': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika Alvaro Obregon' },
    '11000': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika Miguel Hidalgo' },
    '11560': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika Polanco' },
    '12000': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika Mixcoac' },
    '13000': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika Tlahuac' },
    '14000': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika Tlalpan' },
    '15000': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika Venustiano Carranza' },
    '16000': { ciudad: 'Ciudad de Mexico', estado: 'CDMX', centro: 'Centro Voltika Xochimilco' },

    // ── Aguascalientes (20) ───────────────────────────────────────────────────
    '20000': { ciudad: 'Aguascalientes', estado: 'Aguascalientes', centro: 'Centro Voltika Aguascalientes' },

    // ── Baja California (21-22) ───────────────────────────────────────────────
    '21000': { ciudad: 'Mexicali', estado: 'Baja California', centro: 'Centro Voltika Mexicali' },
    '22000': { ciudad: 'Tijuana', estado: 'Baja California', centro: 'Centro Voltika Tijuana' },
    '23000': { ciudad: 'La Paz', estado: 'Baja California Sur', centro: 'Centro Voltika La Paz' },

    // ── Campeche (24) ─────────────────────────────────────────────────────────
    '24000': { ciudad: 'Campeche', estado: 'Campeche', centro: 'Centro Voltika Campeche' },
    '24100': { ciudad: 'Ciudad del Carmen', estado: 'Campeche', centro: 'Centro Voltika Ciudad del Carmen' },

    // ── Coahuila (25-27) ──────────────────────────────────────────────────────
    '25000': { ciudad: 'Saltillo', estado: 'Coahuila', centro: 'Centro Voltika Saltillo' },
    '26000': { ciudad: 'Piedras Negras', estado: 'Coahuila', centro: 'Centro Voltika Piedras Negras' },
    '27000': { ciudad: 'Torreon', estado: 'Coahuila', centro: 'Centro Voltika Torreon' },

    // ── Colima (28) ───────────────────────────────────────────────────────────
    '28000': { ciudad: 'Colima', estado: 'Colima', centro: 'Centro Voltika Colima' },

    // ── Chiapas (29-30) ───────────────────────────────────────────────────────
    '29000': { ciudad: 'Tuxtla Gutierrez', estado: 'Chiapas', centro: 'Centro Voltika Tuxtla' },
    '30000': { ciudad: 'Comitan', estado: 'Chiapas', centro: 'Centro Voltika Comitan' },

    // ── Chihuahua (31-33) ─────────────────────────────────────────────────────
    '31000': { ciudad: 'Chihuahua', estado: 'Chihuahua', centro: 'Centro Voltika Chihuahua' },
    '32000': { ciudad: 'Ciudad Juarez', estado: 'Chihuahua', centro: 'Centro Voltika Ciudad Juarez' },
    '33000': { ciudad: 'Delicias', estado: 'Chihuahua', centro: 'Centro Voltika Delicias' },

    // ── Durango (34-35) ───────────────────────────────────────────────────────
    '34000': { ciudad: 'Durango', estado: 'Durango', centro: 'Centro Voltika Durango' },
    '35000': { ciudad: 'Gomez Palacio', estado: 'Durango', centro: 'Centro Voltika Gomez Palacio' },

    // ── Guanajuato (36-38) ────────────────────────────────────────────────────
    '36000': { ciudad: 'Guanajuato', estado: 'Guanajuato', centro: 'Centro Voltika Guanajuato' },
    '37000': { ciudad: 'Leon', estado: 'Guanajuato', centro: 'Centro Voltika Leon' },
    '38000': { ciudad: 'Celaya', estado: 'Guanajuato', centro: 'Centro Voltika Celaya' },

    // ── Guerrero (39-41) ──────────────────────────────────────────────────────
    '39000': { ciudad: 'Chilpancingo', estado: 'Guerrero', centro: 'Centro Voltika Chilpancingo' },
    '39300': { ciudad: 'Acapulco', estado: 'Guerrero', centro: 'Centro Voltika Acapulco' },
    '40000': { ciudad: 'Iguala', estado: 'Guerrero', centro: 'Centro Voltika Iguala' },
    '41000': { ciudad: 'Taxco', estado: 'Guerrero', centro: 'Centro Voltika Taxco' },

    // ── Hidalgo (42-43) ───────────────────────────────────────────────────────
    '42000': { ciudad: 'Pachuca', estado: 'Hidalgo', centro: 'Centro Voltika Pachuca' },
    '43000': { ciudad: 'Huejutla', estado: 'Hidalgo', centro: 'Centro Voltika Huejutla' },

    // ── Jalisco (44-49) ───────────────────────────────────────────────────────
    '44100': { ciudad: 'Guadalajara', estado: 'Jalisco', centro: 'Centro Voltika Guadalajara Centro' },
    '44600': { ciudad: 'Guadalajara', estado: 'Jalisco', centro: 'Centro Voltika Guadalajara Sur' },
    '45050': { ciudad: 'Zapopan', estado: 'Jalisco', centro: 'Centro Voltika Zapopan' },
    '46000': { ciudad: 'Tequila', estado: 'Jalisco', centro: 'Centro Voltika Tequila' },
    '47000': { ciudad: 'San Juan de los Lagos', estado: 'Jalisco', centro: 'Centro Voltika San Juan' },
    '48000': { ciudad: 'Puerto Vallarta', estado: 'Jalisco', centro: 'Centro Voltika Puerto Vallarta' },
    '49000': { ciudad: 'Ciudad Guzman', estado: 'Jalisco', centro: 'Centro Voltika Ciudad Guzman' },

    // ── Estado de Mexico (50-57) ──────────────────────────────────────────────
    '50000': { ciudad: 'Toluca', estado: 'Estado de Mexico', centro: 'Centro Voltika Toluca' },
    '51000': { ciudad: 'Zinacantepec', estado: 'Estado de Mexico', centro: 'Centro Voltika Zinacantepec' },
    '52000': { ciudad: 'Lerma', estado: 'Estado de Mexico', centro: 'Centro Voltika Lerma' },
    '53100': { ciudad: 'Naucalpan', estado: 'Estado de Mexico', centro: 'Centro Voltika Naucalpan' },
    '54000': { ciudad: 'Tlalnepantla', estado: 'Estado de Mexico', centro: 'Centro Voltika Tlalnepantla' },
    '55000': { ciudad: 'Ecatepec', estado: 'Estado de Mexico', centro: 'Centro Voltika Ecatepec' },
    '56000': { ciudad: 'Texcoco', estado: 'Estado de Mexico', centro: 'Centro Voltika Texcoco' },
    '57000': { ciudad: 'Nezahualcoyotl', estado: 'Estado de Mexico', centro: 'Centro Voltika Nezahualcoyotl' },

    // ── Michoacan (58-61) ─────────────────────────────────────────────────────
    '58000': { ciudad: 'Morelia', estado: 'Michoacan', centro: 'Centro Voltika Morelia' },
    '59000': { ciudad: 'Zamora', estado: 'Michoacan', centro: 'Centro Voltika Zamora' },
    '60000': { ciudad: 'Uruapan', estado: 'Michoacan', centro: 'Centro Voltika Uruapan' },
    '61000': { ciudad: 'Patzcuaro', estado: 'Michoacan', centro: 'Centro Voltika Patzcuaro' },

    // ── Morelos (62-63) ───────────────────────────────────────────────────────
    '62000': { ciudad: 'Cuernavaca', estado: 'Morelos', centro: 'Centro Voltika Cuernavaca' },
    '63000': { ciudad: 'Tepic', estado: 'Nayarit', centro: 'Centro Voltika Tepic' },

    // ── Nuevo Leon (64-67) ────────────────────────────────────────────────────
    '64000': { ciudad: 'Monterrey', estado: 'Nuevo Leon', centro: 'Centro Voltika Monterrey Centro' },
    '64700': { ciudad: 'Monterrey', estado: 'Nuevo Leon', centro: 'Centro Voltika Monterrey Sur' },
    '65000': { ciudad: 'Monterrey', estado: 'Nuevo Leon', centro: 'Centro Voltika Monterrey Norte' },
    '66000': { ciudad: 'Santa Catarina', estado: 'Nuevo Leon', centro: 'Centro Voltika Santa Catarina' },
    '66220': { ciudad: 'San Pedro Garza Garcia', estado: 'Nuevo Leon', centro: 'Centro Voltika San Pedro' },
    '67000': { ciudad: 'Guadalupe', estado: 'Nuevo Leon', centro: 'Centro Voltika Guadalupe' },

    // ── Oaxaca (68-71) ────────────────────────────────────────────────────────
    '68000': { ciudad: 'Oaxaca', estado: 'Oaxaca', centro: 'Centro Voltika Oaxaca' },
    '69000': { ciudad: 'Huajuapan', estado: 'Oaxaca', centro: 'Centro Voltika Huajuapan' },
    '70000': { ciudad: 'Juchitan', estado: 'Oaxaca', centro: 'Centro Voltika Juchitan' },
    '71000': { ciudad: 'Santa Cruz Xoxocotlan', estado: 'Oaxaca', centro: 'Centro Voltika Xoxocotlan' },

    // ── Puebla (72-75) ────────────────────────────────────────────────────────
    '72000': { ciudad: 'Puebla', estado: 'Puebla', centro: 'Centro Voltika Puebla' },
    '73000': { ciudad: 'Tulancingo', estado: 'Puebla', centro: 'Centro Voltika Tulancingo' },
    '74000': { ciudad: 'Atlixco', estado: 'Puebla', centro: 'Centro Voltika Atlixco' },
    '75000': { ciudad: 'Tehuacan', estado: 'Puebla', centro: 'Centro Voltika Tehuacan' },

    // ── Queretaro (76) ────────────────────────────────────────────────────────
    '76000': { ciudad: 'Queretaro', estado: 'Queretaro', centro: 'Centro Voltika Queretaro' },

    // ── Quintana Roo (77) ─────────────────────────────────────────────────────
    '77000': { ciudad: 'Chetumal', estado: 'Quintana Roo', centro: 'Centro Voltika Chetumal' },
    '77500': { ciudad: 'Cancun', estado: 'Quintana Roo', centro: 'Centro Voltika Cancun' },
    '77710': { ciudad: 'Playa del Carmen', estado: 'Quintana Roo', centro: 'Centro Voltika Playa del Carmen' },

    // ── San Luis Potosi (78-79) ───────────────────────────────────────────────
    '78000': { ciudad: 'San Luis Potosi', estado: 'San Luis Potosi', centro: 'Centro Voltika SLP' },
    '79000': { ciudad: 'Ciudad Valles', estado: 'San Luis Potosi', centro: 'Centro Voltika Ciudad Valles' },

    // ── Sinaloa (80-82) ───────────────────────────────────────────────────────
    '80000': { ciudad: 'Culiacan', estado: 'Sinaloa', centro: 'Centro Voltika Culiacan' },
    '81000': { ciudad: 'Guasave', estado: 'Sinaloa', centro: 'Centro Voltika Guasave' },
    '82000': { ciudad: 'Mazatlan', estado: 'Sinaloa', centro: 'Centro Voltika Mazatlan' },

    // ── Sonora (83-85) ────────────────────────────────────────────────────────
    '83000': { ciudad: 'Hermosillo', estado: 'Sonora', centro: 'Centro Voltika Hermosillo' },
    '84000': { ciudad: 'Nogales', estado: 'Sonora', centro: 'Centro Voltika Nogales' },
    '85000': { ciudad: 'Ciudad Obregon', estado: 'Sonora', centro: 'Centro Voltika Ciudad Obregon' },

    // ── Tabasco (86) ──────────────────────────────────────────────────────────
    '86000': { ciudad: 'Villahermosa', estado: 'Tabasco', centro: 'Centro Voltika Villahermosa' },

    // ── Tamaulipas (87-89) ────────────────────────────────────────────────────
    '87000': { ciudad: 'Ciudad Victoria', estado: 'Tamaulipas', centro: 'Centro Voltika Ciudad Victoria' },
    '88000': { ciudad: 'Nuevo Laredo', estado: 'Tamaulipas', centro: 'Centro Voltika Nuevo Laredo' },
    '89000': { ciudad: 'Tampico', estado: 'Tamaulipas', centro: 'Centro Voltika Tampico' },

    // ── Tlaxcala (90) ─────────────────────────────────────────────────────────
    '90000': { ciudad: 'Tlaxcala', estado: 'Tlaxcala', centro: 'Centro Voltika Tlaxcala' },

    // ── Veracruz (91-96) ──────────────────────────────────────────────────────
    '91000': { ciudad: 'Xalapa', estado: 'Veracruz', centro: 'Centro Voltika Xalapa' },
    '92000': { ciudad: 'Orizaba', estado: 'Veracruz', centro: 'Centro Voltika Orizaba' },
    '93000': { ciudad: 'Poza Rica', estado: 'Veracruz', centro: 'Centro Voltika Poza Rica' },
    '94000': { ciudad: 'Boca del Rio', estado: 'Veracruz', centro: 'Centro Voltika Boca del Rio' },
    '95000': { ciudad: 'Tierra Blanca', estado: 'Veracruz', centro: 'Centro Voltika Tierra Blanca' },
    '96000': { ciudad: 'Coatzacoalcos', estado: 'Veracruz', centro: 'Centro Voltika Coatzacoalcos' },

    // ── Yucatan (97) ──────────────────────────────────────────────────────────
    '97000': { ciudad: 'Merida', estado: 'Yucatan', centro: 'Centro Voltika Merida' },

    // ── Zacatecas (98-99) ─────────────────────────────────────────────────────
    '98000': { ciudad: 'Zacatecas', estado: 'Zacatecas', centro: 'Centro Voltika Zacatecas' },
    '99000': { ciudad: 'Fresnillo', estado: 'Zacatecas', centro: 'Centro Voltika Fresnillo' },

    _buscar: function(cp) {
        // Exact match
        if (this[cp]) {
            return this[cp];
        }
        // Match by first 3 digits (city-level)
        var prefix3 = cp.substring(0, 3);
        for (var key in this) {
            if (typeof this[key] === 'object' && key.substring(0, 3) === prefix3) {
                return {
                    ciudad: this[key].ciudad,
                    estado: this[key].estado,
                    centro: 'Centro Voltika Autorizado'
                };
            }
        }
        // Match by first 2 digits (state-level)
        var prefix2 = cp.substring(0, 2);
        for (var key2 in this) {
            if (typeof this[key2] === 'object' && key2.substring(0, 2) === prefix2) {
                return {
                    ciudad: this[key2].ciudad,
                    estado: this[key2].estado,
                    centro: 'Centro Voltika Autorizado'
                };
            }
        }
        return null;
    }
};
