/* ==========================================================================
   Voltika - Product Catalog
   Updated: Mar 2026 (boss-verified prices)
   precioMSI = precioContado / 9 (sin intereses)
   ========================================================================== */

var VOLTIKA_PRODUCTOS = {
    modelos: [
        {
            id: 'm05',
            nombre: 'M05',
            subtitulo: 'Ideal para ciudad y carretera',
            badge: 'Modelo m\u00e1s vendido',
            autonomia: 90,
            velocidad: 75,
            precioContado: 48260,
            precioSemanal: 554,
            precioMSI: Math.round(48260 / 9),
            precioMSITotal: 48260,
            msiMeses: 9,
            tieneMSI: true,
            enganchePorcentaje: 0.25,
            colores: [
                { id: 'gris',  nombre: 'Gris',  hex: '#A0A0A0' },
                { id: 'negro', nombre: 'Negro', hex: '#1A1A1A' },
                { id: 'plata', nombre: 'Plata', hex: '#C0C0C0' }
            ],
            colorDefault: 'gris',
            enInventario: true,
            orden: 1
        },
        {
            id: 'm03',
            nombre: 'M03',
            subtitulo: 'Alternativa accesible',
            badge: null,
            autonomia: 90,
            velocidad: 60,
            precioContado: 39900,
            precioSemanal: 458,
            precioMSI: Math.round(39900 / 9),
            precioMSITotal: 39900,
            msiMeses: 9,
            tieneMSI: true,
            enganchePorcentaje: 0.25,
            colores: [
                { id: 'negro', nombre: 'Negro', hex: '#1A1A1A' },
                { id: 'gris',  nombre: 'Gris',  hex: '#A0A0A0' },
                { id: 'plata', nombre: 'Plata', hex: '#C0C0C0' }
            ],
            colorDefault: 'negro',
            enInventario: true,
            orden: 2
        },
        {
            id: 'ukko-s',
            nombre: 'Ukko S+',
            subtitulo: 'Premium, mas potencia',
            badge: null,
            autonomia: 130,
            velocidad: 95,
            precioContado: 89900,
            precioSemanal: 1032,
            precioMSI: Math.round(89900 / 9),
            precioMSITotal: 89900,
            msiMeses: 9,
            tieneMSI: true,
            enganchePorcentaje: 0.25,
            colores: [
                { id: 'gris',    nombre: 'Gris',    hex: '#A0A0A0' },
                { id: 'negro',   nombre: 'Negro',   hex: '#1A1A1A' },
                { id: 'azul',    nombre: 'Azul',    hex: '#1E6FBF' },
                { id: 'naranja', nombre: 'Naranja', hex: '#E87722' }
            ],
            colorDefault: 'gris',
            enInventario: true,
            orden: 3
        },
        {
            id: 'mc10',
            nombre: 'MC10 Streetx',
            subtitulo: 'Premium multiproposito',
            badge: null,
            autonomia: 130,
            velocidad: 95,
            precioContado: 109900,
            precioSemanal: 1261,
            precioMSI: Math.round(109900 / 9),
            precioMSITotal: 109900,
            msiMeses: 9,
            tieneMSI: true,
            enganchePorcentaje: 0.25,
            colores: [
                { id: 'negro', nombre: 'Negro', hex: '#1A1A1A' },
                { id: 'gris',  nombre: 'Gris',  hex: '#A0A0A0' }
            ],
            colorDefault: 'negro',
            enInventario: true,
            orden: 4
        },
        {
            id: 'pesgo-plus',
            nombre: 'Pesgo Plus',
            subtitulo: 'Ciudad inteligente',
            badge: null,
            autonomia: 80,
            velocidad: 60,
            precioContado: 36600,
            precioSemanal: 420,
            precioMSI: Math.round(36600 / 9),
            precioMSITotal: 36600,
            msiMeses: 9,
            tieneMSI: true,
            enganchePorcentaje: 0.25,
            colores: [
                { id: 'negro',  nombre: 'Negro',  hex: '#1A1A1A' },
                { id: 'gris',   nombre: 'Gris',   hex: '#A0A0A0' },
                { id: 'azul',   nombre: 'Azul',   hex: '#1E6FBF' },
                { id: 'plata',  nombre: 'Plata',  hex: '#C0C0C0' },
                { id: 'blanco', nombre: 'Blanco', hex: '#F5F5F5' }
            ],
            colorDefault: 'negro',
            enInventario: true,
            orden: 5
        },
        {
            id: 'mino',
            nombre: 'Mino-B',
            subtitulo: 'Compacta y versatil',
            badge: null,
            autonomia: 90,
            velocidad: 60,
            precioContado: 41820,
            precioSemanal: 480,
            precioMSI: Math.round(41820 / 9),
            precioMSITotal: 41820,
            msiMeses: 9,
            tieneMSI: true,
            enganchePorcentaje: 0.25,
            colores: [
                { id: 'gris',  nombre: 'Gris',  hex: '#A0A0A0' },
                { id: 'azul',  nombre: 'Azul',  hex: '#1E6FBF' },
                { id: 'verde', nombre: 'Verde', hex: '#2E8B57' }
            ],
            colorDefault: 'gris',
            enInventario: true,
            orden: 6
        }
    ],

    config: {
        costoLogistico: 1800, // fallback default
        creditoFleteIncluido: true,

        // Shipping cost by estado: [normal, mc10]
        envio: {
            'Aguascalientes':               [1800, 2200],
            'Baja California':              [5550, 6550],
            'Baja California Sur':          [6000, 7850],
            'Campeche':                     [3950, 5550],
            'Chiapas':                      [3950, 5550],
            'Chihuahua':                    [3950, 4850],
            'Ciudad de M\u00e9xico':        [0, 0],
            'Ciudad de Mexico':             [0, 0],
            'CDMX':                         [0, 0],
            'Distrito Federal':             [0, 0],
            'Coahuila de Zaragoza':         [3950, 4850],
            'Coahuila':                     [3950, 4850],
            'Colima':                       [3950, 4850],
            'Durango':                      [2800, 4850],
            'Guanajuato':                   [2400, 2850],
            'Guerrero':                     [2800, 3450],
            'Hidalgo':                      [1800, 2200],
            'Jalisco':                      [2400, 2850],
            'M\u00e9xico':                  [1800, 0],
            'Mexico':                       [1800, 0],
            'Estado de M\u00e9xico':        [1800, 0],
            'Estado de Mexico':             [1800, 0],
            'Michoac\u00e1n de Ocampo':     [2800, 3850],
            'Michoac\u00e1n':               [2800, 3850],
            'Michoacan':                    [2800, 3850],
            'Morelos':                      [1800, 2200],
            'Nayarit':                      [2800, 3850],
            'Nuevo Le\u00f3n':              [1800, 2800],
            'Nuevo Leon':                   [1800, 2800],
            'Oaxaca':                       [4800, 5500],
            'Puebla':                       [1800, 2200],
            'Quer\u00e9taro':              [1800, 2200],
            'Queretaro':                    [1800, 2200],
            'Quintana Roo':                 [4800, 5500],
            'San Luis Potos\u00ed':         [2400, 2850],
            'San Luis Potosi':              [2400, 2850],
            'Sinaloa':                      [4800, 5500],
            'Sonora':                       [4800, 5500],
            'Tabasco':                      [3850, 4550],
            'Tamaulipas':                   [3850, 4200],
            'Tlaxcala':                     [1800, 2200],
            'Veracruz de Ignacio de la Llave': [3800, 4200],
            'Veracruz':                     [3800, 4200],
            'Yucat\u00e1n':                [4800, 5500],
            'Yucatan':                      [4800, 5500],
            'Zacatecas':                    [2400, 4200]
        },
        entregaDiasHabiles: '7-10',
        entregaDiasInventario: 15,    // days when in stock
        entregaDiasSinInventario: 70, // days when out of stock
        contactoHoras: '24-48',

        credito: {
            tasaAnual: 0.60,
            pagosPorAno: 52,
            iva: 0.16,
            plazoDefaultMeses: 12,
            pagosDefault: 52,
            engancheMinimo: 0.25
        }
    }
};
