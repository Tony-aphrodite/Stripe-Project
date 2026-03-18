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
            badge: 'MAS VENDIDO',
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
            engancheMinimo: 0.25
        }
    }
};
