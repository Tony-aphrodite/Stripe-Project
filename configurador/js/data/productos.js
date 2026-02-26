/* ==========================================================================
   Voltika - Product Catalog
   Single source of truth for all product data
   To update prices or add models, edit only this file
   ========================================================================== */

var VOLTIKA_PRODUCTOS = {
    modelos: [
        {
            id: 'm05',
            nombre: 'M05',
            subtitulo: 'Ideal para ciudad y carretera',
            badge: 'Mas vendido',
            autonomia: 100,
            velocidad: 90,
            precioContado: 48000,
            precioSemanal: 626,
            precioMSI: 5333,
            msiMeses: 9,
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
            precioContado: 39900,
            precioSemanal: 530,
            precioMSI: 4433,
            msiMeses: 9,
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
            id: 'mino',
            nombre: 'Mino',
            subtitulo: 'Compacta y urbana',
            badge: null,
            autonomia: 70,
            velocidad: 60,
            precioContado: 41820,
            precioSemanal: 560,
            precioMSI: 4647,
            msiMeses: 9,
            enganchePorcentaje: 0.30,
            colores: [
                { id: 'azul',  nombre: 'Azul',  hex: '#1E6FBF' },
                { id: 'verde', nombre: 'Verde', hex: '#3DAA5E' },
                { id: 'gris',  nombre: 'Gris',  hex: '#A0A0A0' }
            ],
            colorDefault: 'azul',
            orden: 3
        },
        {
            id: 'ukko-s',
            nombre: 'UKKO-S',
            subtitulo: 'Premium, mas potencia',
            badge: null,
            autonomia: 150,
            velocidad: 120,
            precioContado: 89900,
            precioSemanal: 1200,
            precioMSI: 9989,
            msiMeses: 9,
            enganchePorcentaje: 0.30,
            colores: [
                { id: 'negro',   nombre: 'Negro',   hex: '#1A1A1A' },
                { id: 'gris',    nombre: 'Gris',    hex: '#A0A0A0' },
                { id: 'azul',    nombre: 'Azul',    hex: '#1E6FBF' },
                { id: 'naranja', nombre: 'Naranja', hex: '#E87722' }
            ],
            colorDefault: 'negro',
            orden: 4
        },
        {
            id: 'mc10',
            nombre: 'MC10 Streetx',
            subtitulo: 'Premium multiproposito',
            badge: null,
            autonomia: 130,
            velocidad: 110,
            precioContado: 109900,
            precioSemanal: 1465,
            precioMSI: 12211,
            msiMeses: 9,
            enganchePorcentaje: 0.30,
            colores: [
                { id: 'negro', nombre: 'Negro', hex: '#1A1A1A' },
                { id: 'gris',  nombre: 'Gris',  hex: '#A0A0A0' }
            ],
            colorDefault: 'negro',
            orden: 5
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
            precioMSI: 4067,
            msiMeses: 9,
            enganchePorcentaje: 0.30,
            colores: [
                { id: 'negro', nombre: 'Negro', hex: '#1A1A1A' },
                { id: 'gris',  nombre: 'Gris',  hex: '#A0A0A0' },
                { id: 'azul',  nombre: 'Azul',  hex: '#1E6FBF' },
                { id: 'plata', nombre: 'Plata', hex: '#C0C0C0' }
            ],
            colorDefault: 'negro',
            orden: 6
        }
    ],

    config: {
        costoLogistico: 3700,
        creditoFleteIncluido: true,
        entregaDiasHabiles: '7-10',
        contactoHoras: '24-48',

        credito: {
            tasaAnual: 0.60,
            pagosPorAno: 52,
            iva: 0.16,
            plazoDefaultMeses: 12,
            pagosDefault: 52,
            engancheMinimo: 0.30
        }
    }
};
