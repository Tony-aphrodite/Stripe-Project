/* ==========================================================================
   Voltika - Centros de Entrega (Delivery Points)
   Real delivery locations where customers pick up their Voltika.
   Add new centers here — the app will match them by CP prefix.
   ========================================================================== */

var VOLTIKA_CENTROS = [
    // tipo 'center' = Voltika Center (exhibici\u00f3n, pruebas de manejo, entrega, servicio, refacciones)
    {
        id: 'voltika-center-santa-fe',
        nombre: 'Voltika Center Santa Fe',
        direccion: 'Ernesto J. Piper 9',
        ubicacion: 'Santa Fe \u2013 CDMX',
        colonia: 'Paseos de las Lomas, \u00c1lvaro Obreg\u00f3n',
        cp: '01330',
        ciudad: 'Ciudad de M\u00e9xico',
        estado: 'CDMX',
        horarios: 'Atenci\u00f3n por WhatsApp o cita previa',
        autorizado: true,
        tipo: 'center',
        tags: ['Exhibici\u00f3n', 'Pruebas de manejo', 'Entrega', 'Servicio t\u00e9cnico', 'Refacciones'],
        servicios: [
            'Exhibici\u00f3n de motos Voltika',
            'Pruebas de manejo disponibles',
            'Entrega y activaci\u00f3n de tu moto',
            'Servicio t\u00e9cnico especializado',
            'Refacciones originales Voltika'
        ],
        zonas: ['01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16'],
        descripcion: 'Entrega en CDMX y todo M\u00e9xico'
    },
    // tipo 'certificado' = Punto Voltika certificado (exhibici\u00f3n, entrega, servicio)
    {
        id: 'godike-motors',
        nombre: 'Godike Motors',
        direccion: 'Av. Ermita Iztapalapa 2453',
        ubicacion: 'Iztapalapa, Ciudad de M\u00e9xico',
        cp: '09820',
        ciudad: 'Ciudad de Mexico',
        estado: 'CDMX',
        horarios: 'Lunes a Viernes 10:00am a 18:30hrs, S\u00e1bado 11:00am a 14:00hrs, Domingo cerrado',
        autorizado: true,
        tipo: 'certificado',
        zonas: ['01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16'],
        tags: ['Exhibici\u00f3n', 'Entrega', 'Servicio t\u00e9cnico'],
        descripcion: 'Entrega y soporte t\u00e9cnico autorizados por Voltika.'
    },
    {
        id: 'garage-mushu',
        nombre: 'Garage Mushu',
        direccion: 'Av. Central 502',
        ubicacion: 'Las Am\u00e9ricas, Ecatepec',
        cp: '55076',
        ciudad: 'Ecatepec de Morelos',
        estado: 'M\u00e9xico',
        horarios: 'Lunes a Viernes 10:00am a 18:00hrs',
        autorizado: true,
        tipo: 'certificado',
        zonas: ['55'],
        tags: ['Exhibici\u00f3n', 'Entrega', 'Servicio t\u00e9cnico'],
        descripcion: 'Entrega y soporte t\u00e9cnico autorizados por Voltika.'
    },
    {
        id: 'race-moto-taller-tlalpan',
        nombre: 'Race Moto Taller',
        direccion: 'Carretera Federal a Cuernavaca #5595',
        ubicacion: 'Tlalpan \u2013 CDMX',
        colonia: 'Col. San Pedro M\u00e1rtir, Alcald\u00eda Tlalpan',
        cp: '14650',
        ciudad: 'Ciudad de M\u00e9xico',
        estado: 'Distrito Federal',
        horarios: 'L-V: 10am a 6pm \u00b7 S\u00e1bado: 10am a 2pm \u00b7 Domingo: Cerrado',
        autorizado: true,
        tipo: 'certificado',
        zonas: ['14','16','10','12'],
        tags: ['Entrega', 'Servicio t\u00e9cnico'],
        descripcion: 'Entrega y soporte t\u00e9cnico autorizados por Voltika.'
    },
    {
        id: 'moto-centro-chihuahua',
        nombre: 'Moto Centro',
        direccion: 'Tecnol\u00f3gico 1103',
        ubicacion: 'Chihuahua',
        colonia: 'Col. Santo Ni\u00f1o',
        cp: '31200',
        ciudad: 'Chihuahua',
        estado: 'Chihuahua',
        horarios: 'L-V: 9am a 1pm y 3pm a 6pm \u00b7 S\u00e1bado: 9am a 1pm \u00b7 Domingo: Cerrado',
        autorizado: true,
        tipo: 'certificado',
        zonas: ['31','32','33'],
        tags: ['Entrega', 'Servicio t\u00e9cnico'],
        descripcion: 'Entrega y soporte t\u00e9cnico autorizados por Voltika.'
    }
];

/* Estado name normalization map for matching */
var _ESTADO_ALIAS = {
    'Ciudad de M\u00e9xico': 'CDMX',
    'Ciudad de Mexico': 'CDMX',
    'Distrito Federal': 'CDMX',
    'Estado de M\u00e9xico': 'M\u00e9xico',
    'Estado de Mexico': 'M\u00e9xico',
    'Mexico': 'M\u00e9xico'
};

function _normEstado(e) {
    if (!e) return '';
    return _ESTADO_ALIAS[e] || e;
}

/* Utility: find matching centers by estado (state) */
VOLTIKA_CENTROS.buscar = function(cp, estado) {
    if (!estado && (!cp || cp.length < 2)) return [];

    // If estado provided, match by estado
    if (estado) {
        var normEst = _normEstado(estado);
        var results = [];
        for (var i = 0; i < this.length; i++) {
            var centro = this[i];
            var centroEst = _normEstado(centro.estado);
            if (centroEst === normEst) {
                results.push(centro);
            }
        }
        // Sort by tipo priority: center > certificado > entrega
        var tipoPriority = { 'center': 0, 'certificado': 1, 'entrega': 2 };
        results.sort(function(a, b) {
            var pa = tipoPriority[a.tipo] !== undefined ? tipoPriority[a.tipo] : 3;
            var pb = tipoPriority[b.tipo] !== undefined ? tipoPriority[b.tipo] : 3;
            return pa - pb;
        });
        return results;
    }

    // Fallback: CP prefix match (legacy)
    var prefix2 = cp.substring(0, 2);
    var results2 = [];
    for (var k = 0; k < this.length; k++) {
        var c = this[k];
        if (c.zonas && c.zonas.length) {
            for (var j = 0; j < c.zonas.length; j++) {
                if (c.zonas[j] === prefix2) { results2.push(c); break; }
            }
        } else {
            if (c.cp.substring(0, 2) === prefix2) results2.push(c);
        }
    }
    return results2;
};
