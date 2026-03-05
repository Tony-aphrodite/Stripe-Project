/* ==========================================================================
   Voltika - Product Catalog
   Fuente: voltika_precios.pdf (Feb 2026)
   precioMSI = precio total MSI / 9 meses
   ========================================================================== */

var VOLTIKA_PRODUCTOS = {
    modelos: [
        {
            id: 'm05',
            nombre: 'M05',
            subtitulo: 'Ideal para ciudad y carretera',
            badge: 'MAS VENDIDO',
            autonomia: 100,
            velocidad: 90,
            precioContado: 48260,
            precioSemanal: 626,
            precioMSI: Math.round(53500 / 9),   // $5,944/mes
            precioMSITotal: 53500,
            msiMeses: 9,
            tieneMSI: true,
            enganchePorcentaje: 0.30,
            colores: [
                { id: 'negro', nombre: 'Negro', hex: '#1A1A1A' },
                { id: 'gris',  nombre: 'Gris',  hex: '#A0A0A0' },
                { id: 'plata', nombre: 'Plata', hex: '#C0C0C0' }
            ],
            colorDefault: 'negro',
            orden: 1
        },
        {
            id: 'm03',
            nombre: 'M03',
            subtitulo: 'Alternativa accesible',
            badge: null,
            autonomia: 80,
            velocidad: 75,
            precioContado: 36900,
            precioSemanal: 490,
            precioMSI: Math.round(46800 / 9),   // $5,200/mes
            precioMSITotal: 46800,
            msiMeses: 9,
            tieneMSI: true,
            enganchePorcentaje: 0.30,
            colores: [
                { id: 'negro', nombre: 'Negro', hex: '#1A1A1A' },
                { id: 'gris',  nombre: 'Gris',  hex: '#A0A0A0' },
                { id: 'plata', nombre: 'Plata', hex: '#C0C0C0' }
            ],
            colorDefault: 'negro',
            orden: 2
        },
        {
            id: 'ukko-s',
            nombre: 'Ukko S+',
            subtitulo: 'Premium, mas potencia',
            badge: null,
            autonomia: 150,
            velocidad: 120,
            precioContado: 89900,
            precioSemanal: 1200,
            precioMSI: Math.round(105990 / 9),  // $11,777/mes
            precioMSITotal: 105990,
            msiMeses: 9,
            tieneMSI: true,
            enganchePorcentaje: 0.30,
            colores: [
                { id: 'negro',   nombre: 'Negro',   hex: '#1A1A1A' },
                { id: 'gris',    nombre: 'Gris',    hex: '#A0A0A0' },
                { id: 'azul',    nombre: 'Azul',    hex: '#1E6FBF' },
                { id: 'naranja', nombre: 'Naranja', hex: '#E87722' }
            ],
            colorDefault: 'negro',
            orden: 3
        },
        {
            id: 'mc10',
            nombre: 'MC10 Streetx',
            subtitulo: 'Premium multiproposito',
            badge: null,
            autonomia: 130,
            velocidad: 110,
            precioContado: 142700,
            precioSemanal: 1900,
            precioMSI: Math.round(109900 / 9),  // $12,211/mes
            precioMSITotal: 109900,
            msiMeses: 9,
            tieneMSI: true,
            enganchePorcentaje: 0.30,
            colores: [
                { id: 'negro', nombre: 'Negro', hex: '#1A1A1A' },
                { id: 'gris',  nombre: 'Gris',  hex: '#A0A0A0' }
            ],
            colorDefault: 'negro',
            orden: 4
        },
        {
            id: 'pesgo-plus',
            nombre: 'Pesgo Plus',
            subtitulo: 'Ciudad inteligente',
            badge: null,
            autonomia: 60,
            velocidad: 55,
            precioContado: 36600,
            precioSemanal: 490,
            precioMSI: null,          // Sin opcion MSI segun precios oficiales
            precioMSITotal: null,
            msiMeses: 9,
            tieneMSI: false,
            enganchePorcentaje: 0.30,
            colores: [
                { id: 'negro', nombre: 'Negro', hex: '#1A1A1A' },
                { id: 'gris',  nombre: 'Gris',  hex: '#A0A0A0' },
                { id: 'azul',  nombre: 'Azul',  hex: '#1E6FBF' },
                { id: 'plata', nombre: 'Plata', hex: '#C0C0C0' }
            ],
            colorDefault: 'negro',
            orden: 5
        },
        {
            id: 'mino',
            nombre: 'Mino-B',
            subtitulo: 'Compacta y versatil',
            badge: null,
            autonomia: null,          // TODO: confirmar con cliente
            velocidad: null,          // TODO: confirmar con cliente
            precioContado: 36600,
            precioSemanal: 490,
            precioMSI: Math.round(39320 / 9),   // $4,369/mes
            precioMSITotal: 39320,
            msiMeses: 9,
            tieneMSI: true,
            enganchePorcentaje: 0.30,
            colores: [
                { id: 'gris',  nombre: 'Gris',  hex: '#A0A0A0' },
                { id: 'azul',  nombre: 'Azul',  hex: '#1E6FBF' },
                { id: 'verde', nombre: 'Verde', hex: '#2E8B57' }
            ],
            colorDefault: 'gris',
            orden: 6
        }
    ],

    config: {
        costoLogistico: 1800,
        creditoFleteIncluido: true,
        entregaDiasHabiles: '7-10',
        contactoHoras: '24-48',

        credito: {
            tasaAnual: 0.60,
            pagosPorAno: 52,
            iva: 0.16,
            plazoDefaultMeses: 12,
            pagosDefault: 52,
            engancheMinimo: 0.25          // V3: minimo 25%
        }
    }
};
