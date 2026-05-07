<?php
// Customer brief 2026-05-07 (items 6/7/8): unified support contact
// info — was returning placeholder test data (55 0000 0000) that any
// caller would forward to a non-existent line. Now serves the real
// WhatsApp chatbot + corporate office details that match the Ayuda /
// Cuenta / Mi Voltika screens.
require_once __DIR__ . '/../bootstrap.php';
portalJsonOut([
    'whatsapp'         => 'https://api.whatsapp.com/send?phone=5215513416370',
    'whatsapp_human'   => '+52 55 1341 6370',
    'telefono'         => '+52 55 1341 6370',
    'oficinas_corp'    => '55 5557 9619',
    'email'            => 'ventas@voltika.mx',
    'horario'          => 'Lunes a Viernes de 9:00 am a 6:00 pm.',
    'horario_titulo'   => 'Horario de atención en días hábiles',
]);
