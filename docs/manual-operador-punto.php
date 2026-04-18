<?php
/**
 * Voltika — Manual del Operador de Punto (PDF dinámico)
 * Generado con FPDF. Se sirve inline como PDF al acceder a la URL.
 *
 * URL pública: https://voltika.mx/docs/manual-operador-punto.php
 * (también funciona si el servidor tiene rewrite de .pdf → .php)
 */

// Resolver FPDF desde configurador_prueba/php/vendor/fpdf
$fpdfCandidates = [
    __DIR__ . '/../configurador_prueba/php/vendor/fpdf/fpdf.php',
    __DIR__ . '/../configurador_prueba_test/php/vendor/fpdf/fpdf.php',
];
foreach ($fpdfCandidates as $p) { if (file_exists($p)) { require_once $p; break; } }
if (!class_exists('FPDF')) {
    http_response_code(500);
    echo 'FPDF no encontrado en el servidor.';
    exit;
}

/**
 * Convert UTF-8 to ISO-8859-1 for FPDF Latin-1 fonts.
 * Handles Spanish accents, eñe, questions marks, etc.
 */
function _lat($s) {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', (string)$s);
}

class ManualPDF extends FPDF {
    // Voltika brand colors
    protected $colorNavy     = [26, 58, 92];     // #1a3a5c
    protected $colorPrimary  = [3, 159, 225];    // #039fe1
    protected $colorAccent   = [34, 211, 122];   // #22d37a
    protected $colorDanger   = [196, 30, 58];    // #c41e3a
    protected $colorMuted    = [100, 116, 139];  // slate

    public function Header() {
        // Only draw on pages after cover (cover is page 1 with its own layout)
        if ($this->PageNo() === 1) return;
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(...$this->colorNavy);
        $this->Cell(0, 8, _lat('voltika · Manual del Operador de Punto'), 0, 0, 'L');
        $this->SetTextColor(150, 150, 150);
        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 8, _lat('Página ' . $this->PageNo()), 0, 1, 'R');
        $this->SetDrawColor(...$this->colorPrimary);
        $this->SetLineWidth(0.4);
        $this->Line(10, 20, 200, 20);
        $this->Ln(8);
    }

    public function Footer() {
        $this->SetY(-12);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(...$this->colorMuted);
        $this->Cell(0, 5, _lat('Voltika México · voltika.mx · puntos@voltika.mx · Mtech Gears S.A. de C.V.'), 0, 0, 'C');
    }

    public function h1($text) {
        $this->SetFont('Arial', 'B', 18);
        $this->SetTextColor(...$this->colorNavy);
        $this->Cell(0, 10, _lat($text), 0, 1, 'L');
        $this->SetDrawColor(...$this->colorPrimary);
        $this->SetLineWidth(0.8);
        $y = $this->GetY();
        $this->Line(10, $y, 50, $y);
        $this->Ln(6);
    }

    public function h2($text) {
        $this->Ln(2);
        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(...$this->colorPrimary);
        $this->Cell(0, 8, _lat($text), 0, 1, 'L');
        $this->Ln(2);
    }

    public function h3($text) {
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(...$this->colorNavy);
        $this->Cell(0, 6, _lat($text), 0, 1, 'L');
        $this->Ln(1);
    }

    public function para($text) {
        $this->SetFont('Arial', '', 10.5);
        $this->SetTextColor(50, 50, 50);
        $this->MultiCell(0, 5.5, _lat($text));
        $this->Ln(2);
    }

    public function bullet($text) {
        $this->SetFont('Arial', '', 10.5);
        $this->SetTextColor(50, 50, 50);
        $this->Cell(4);
        $this->Cell(4, 5.5, _lat(chr(149)), 0, 0);
        $this->MultiCell(0, 5.5, _lat($text));
    }

    public function numbered($n, $text) {
        $this->SetFont('Arial', 'B', 10.5);
        $this->SetTextColor(...$this->colorPrimary);
        $this->Cell(8, 5.5, $n . '.', 0, 0);
        $this->SetFont('Arial', '', 10.5);
        $this->SetTextColor(50, 50, 50);
        $this->MultiCell(0, 5.5, _lat($text));
    }

    public function notice($title, $body, $kind = 'warn') {
        $bg = $kind === 'danger' ? [255, 237, 237] : ($kind === 'ok' ? [232, 245, 233] : [255, 251, 235]);
        $border = $kind === 'danger' ? $this->colorDanger : ($kind === 'ok' ? [46, 125, 50] : [217, 119, 6]);
        $textCol = $kind === 'danger' ? [153, 27, 27] : ($kind === 'ok' ? [27, 94, 32] : [120, 53, 15]);

        $this->Ln(2);
        $x = $this->GetX(); $y = $this->GetY();
        $this->SetFillColor(...$bg);
        $this->SetDrawColor(...$border);
        $this->SetLineWidth(0.6);

        // Measure height roughly
        $this->SetFont('Arial', 'B', 10.5);
        $titleH = 6;
        $this->SetFont('Arial', '', 10);
        // Compute wrapped body height
        $textWidth = 180;
        $this->SetXY($x + 4, $y + 3);
        // Temporarily write to calculate lines — use MultiCell in a throwaway state
        // Simpler: assume ~15mm block height minimum
        $estimatedLines = max(1, ceil(strlen($body) / 90));
        $bodyH = $estimatedLines * 5.5;
        $blockH = $titleH + $bodyH + 6;

        // Draw block
        $this->SetXY($x, $y);
        $this->Rect($x, $y, 190, $blockH, 'DF');
        $this->SetFillColor(...$border);
        $this->Rect($x, $y, 2, $blockH, 'F');

        $this->SetXY($x + 5, $y + 3);
        $this->SetFont('Arial', 'B', 10.5);
        $this->SetTextColor(...$textCol);
        $this->Cell(185, 6, _lat($title), 0, 1);

        $this->SetX($x + 5);
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(...$textCol);
        $this->MultiCell(185, 5.5, _lat($body));
        $this->Ln(4);
    }

    public function divider() {
        $this->Ln(2);
        $this->SetDrawColor(220, 220, 220);
        $this->SetLineWidth(0.3);
        $y = $this->GetY();
        $this->Line(10, $y, 200, $y);
        $this->Ln(4);
    }
}

// ════════════════════════════════════════════════════════════════════════════
// BUILD THE MANUAL
// ════════════════════════════════════════════════════════════════════════════
$pdf = new ManualPDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

// ── COVER ─────────────────────────────────────────────────────────────────
$pdf->SetFillColor(26, 58, 92);
$pdf->Rect(0, 0, 210, 297, 'F');
$pdf->SetTextColor(255, 255, 255);

// Brand mark
$pdf->SetY(80);
$pdf->SetFont('Arial', 'B', 36);
$pdf->Cell(0, 15, _lat('voltika'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 13);
$pdf->SetTextColor(34, 211, 122);
$pdf->Cell(0, 8, _lat('Red Nacional de Puntos Oficiales'), 0, 1, 'C');

$pdf->Ln(30);
$pdf->SetFont('Arial', 'B', 24);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 12, _lat('Manual del Operador'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 16);
$pdf->Cell(0, 10, _lat('de Punto Voltika'), 0, 1, 'C');

$pdf->Ln(40);
$pdf->SetFont('Arial', '', 11);
$pdf->SetTextColor(200, 220, 240);
$pdf->Cell(0, 6, _lat('Guía paso a paso para el personal autorizado'), 0, 1, 'C');
$pdf->Cell(0, 6, _lat('Última actualización: ' . date('F Y')), 0, 1, 'C');

$pdf->SetY(270);
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(150, 180, 210);
$pdf->Cell(0, 5, _lat('Mtech Gears S.A. de C.V. · voltika.mx · puntos@voltika.mx'), 0, 1, 'C');

// ── PÁGINA 1: ÍNDICE ──────────────────────────────────────────────────────
$pdf->AddPage();
$pdf->h1('Contenido');

$pdf->SetFont('Arial', '', 11);
$pdf->SetTextColor(50, 50, 50);
$toc = [
    '1. Bienvenida',
    '2. Cómo usar el Panel de Operaciones',
    '3. Recepción de motos',
    '4. Proceso de entrega al cliente',
    '5. Tus comisiones',
    '6. Venta por referido',
    '7. Protocolos de emergencia',
    '8. Contacto y soporte',
];
foreach ($toc as $item) {
    $pdf->Cell(0, 7, _lat($item), 0, 1, 'L');
}

// ── SECCIÓN 1: BIENVENIDA ─────────────────────────────────────────────────
$pdf->AddPage();
$pdf->h1('1. Bienvenida');

$pdf->para('Ya eres parte oficial de la red VOLTIKA. Este manual contiene todo lo que necesitas saber para operar tu Punto Voltika correctamente — desde recibir motos desde CEDIS hasta entregarlas a los clientes.');

$pdf->para('Léelo completo antes de realizar tu primera operación. Cada sección incluye instrucciones paso a paso y los protocolos que debes seguir sin excepción.');

$pdf->h2('Tus responsabilidades principales');
$pdf->bullet('Recibir físicamente las motos que CEDIS envía a tu punto.');
$pdf->bullet('Mantener el inventario seguro mientras esté en el punto.');
$pdf->bullet('Ensamblar y validar cada unidad antes de entregarla.');
$pdf->bullet('Entregar al cliente únicamente siguiendo el protocolo oficial.');
$pdf->bullet('Reportar a CEDIS cualquier daño, faltante o incidencia.');

$pdf->notice(
    'IMPORTANTE',
    'Nunca entregues una moto que no aparezca en la lista "Entregar al cliente" de tu panel. Nadie — ni siquiera otro empleado de Voltika — puede pedirte entregar una moto fuera de lista.',
    'danger'
);

// ── SECCIÓN 2: CÓMO USAR EL PANEL ─────────────────────────────────────────
$pdf->AddPage();
$pdf->h1('2. Cómo usar el Panel de Operaciones');

$pdf->para('Tu Panel está disponible en la URL que te fue enviada por correo (generalmente voltika.mx/puntosvoltika/). Accede desde cualquier celular o computadora con tu usuario y contraseña.');

$pdf->h2('Primera vez que entras');
$pdf->numbered('1', 'Abre el Panel en tu navegador.');
$pdf->numbered('2', 'Ingresa el usuario (email) y contraseña que recibiste en el correo de bienvenida.');
$pdf->numbered('3', 'Cambia tu contraseña inmediatamente desde el menú superior → tu nombre → Cambiar contraseña.');
$pdf->numbered('4', 'Confirma tus datos de contacto en tu perfil.');

$pdf->h2('Secciones principales del Panel');
$pdf->h3('Inicio');
$pdf->para('Resumen del punto: inventario total, motos para entrega, envíos pendientes, tus códigos de referido. Puntos de acceso rápido a las acciones más comunes.');

$pdf->h3('Inventario');
$pdf->para('Muestra todas las motos asignadas a tu punto, agrupadas por estado:');
$pdf->bullet('Pendientes de asignación — órdenes con tu código pero sin moto física todavía.');
$pdf->bullet('Por llegar — motos en tránsito desde CEDIS.');
$pdf->bullet('Para entrega — motos recibidas con cliente asignado.');
$pdf->bullet('Disponible para venta — motos de showroom sin cliente.');

$pdf->h3('Recepción');
$pdf->para('Módulo para escanear y validar motos cuando llegan físicamente a tu punto. Ver sección 3.');

$pdf->h3('Entrega al cliente');
$pdf->para('Flujo de 5 pasos para entregar una moto al cliente correcto. Ver sección 4.');

$pdf->h3('Venta por referido');
$pdf->para('Herramienta para registrar ventas directas en tienda (walk-in) y asignar motos de inventario a pedidos online que usaron tu código. Ver sección 6.');

// ── SECCIÓN 3: RECEPCIÓN DE MOTOS ─────────────────────────────────────────
$pdf->AddPage();
$pdf->h1('3. Recepción de motos');

$pdf->para('Cuando CEDIS despacha una moto hacia tu punto, aparece en tu panel como "Por llegar". Al recibirla físicamente, debes registrar la recepción siguiendo estos pasos:');

$pdf->h2('Pasos de la recepción');
$pdf->numbered('1', 'Abre el módulo Recepción en tu panel.');
$pdf->numbered('2', 'Selecciona la moto que está llegando (la verás listada con su VIN y modelo).');
$pdf->numbered('3', 'Escanea el código de barras del VIN en la caja con la cámara de tu celular, o escríbelo manualmente.');
$pdf->numbered('4', 'El sistema verifica que el VIN coincida con el esperado. Si NO coincide, detén el proceso y contacta a CEDIS inmediatamente.');
$pdf->numbered('5', 'Completa el checklist de estado físico: empaque sin daños, componentes completos, batería OK.');
$pdf->numbered('6', 'Toma fotos del empaque, del VIN escaneado, de cualquier daño visible.');
$pdf->numbered('7', 'Confirma la recepción. La moto queda marcada como "Recibida" en tu inventario.');

$pdf->notice(
    'Si detectas un problema',
    'Moto mal empacada, VIN que no coincide, daño visible, componentes faltantes: marca "Retenida" en el sistema y notifica a CEDIS antes de firmar la recepción. No aceptes una moto dañada.',
    'warn'
);

$pdf->h2('Después de la recepción');
$pdf->para('Una vez marcada como "Recibida", la moto queda disponible para el proceso de ensamble en tu punto. El ensamble incluye instalación de base, asiento, manubrio, llanta delantera y espejos, con verificación de torques.');

$pdf->para('Cuando el ensamble está listo, cambia el estado a "Lista para entrega" indicando la fecha estimada de recolección. Esto dispara automáticamente una notificación al cliente con la fecha y los datos del punto.');

// ── SECCIÓN 4: PROCESO DE ENTREGA ─────────────────────────────────────────
$pdf->AddPage();
$pdf->h1('4. Proceso de entrega al cliente');

$pdf->notice(
    'LEE ESTO ANTES DE CADA ENTREGA',
    'Entregar una moto sin completar la validación facial y el OTP en el sistema hace al punto responsable del valor total de la moto. Sin excepciones. Sigue el protocolo aunque el cliente te presione.',
    'danger'
);

$pdf->para('El proceso de entrega consta de 5 pasos obligatorios en el sistema. No puedes saltar pasos ni entregar la moto antes de completarlos todos.');

$pdf->h2('Paso 1: Enviar OTP al cliente');
$pdf->para('Desde el módulo "Entregar al cliente", selecciona la moto del cliente presente. Presiona "Enviar código por SMS". El cliente recibirá un código de 6 dígitos en el teléfono registrado en su orden.');

$pdf->h2('Paso 2: Verificar OTP');
$pdf->para('Pide al cliente que te muestre el código recibido. Ingrésalo en el campo correspondiente. Si el código es incorrecto o caducó, reenvía uno nuevo. Nunca aceptes una "captura de pantalla vieja" — el código debe ser el actual.');

$pdf->h2('Paso 3: Verificación facial e INE');
$pdf->para('Toma una foto del rostro del cliente con su consentimiento. Toma también foto de su INE. Para clientes de crédito, el sistema compara automáticamente con la selfie del expediente. Si las caras no coinciden, no puedes entregar — es requisito legal.');

$pdf->h2('Paso 4: Checklist de la moto');
$pdf->para('Junto con el cliente, verifica:');
$pdf->bullet('VIN de la moto coincide con el de la orden.');
$pdf->bullet('Estado físico correcto, sin daños.');
$pdf->bullet('Unidad completa (llaves, manual, accesorios).');
$pdf->para('Toma fotos de la moto (frente, lateral, trasera). El cliente debe estar presente durante todo este paso.');

$pdf->h2('Paso 5: Firma del ACTA DE ENTREGA');
$pdf->para('El cliente debe ingresar a voltika.mx/clientes desde su celular, revisar el ACTA DE ENTREGA y firmarla digitalmente. Una vez firmada, presiona "Finalizar entrega". La moto queda registrada como entregada en el sistema.');

$pdf->notice(
    'Bajo ninguna circunstancia',
    'No entregues la moto antes de que el cliente firme el ACTA. Si hay algún problema técnico con el portal del cliente, contacta a soporte VOLTIKA antes de proceder.',
    'danger'
);

// ── SECCIÓN 5: TUS COMISIONES ─────────────────────────────────────────────
$pdf->AddPage();
$pdf->h1('5. Tus comisiones');

$pdf->para('Voltika paga comisiones diferenciadas según el tipo de venta que realices. Las comisiones exactas por modelo están definidas en tu contrato de afiliación.');

$pdf->h2('Tipos de comisión');
$pdf->h3('Venta directa en tienda (walk-in)');
$pdf->para('Comisión completa. Corresponde a clientes que llegan físicamente a tu punto y compran una moto de tu inventario en consignación. Tú captas al cliente, cierras la venta y cobras.');

$pdf->h3('Venta por referido — Web Voltika');
$pdf->para('Comisión por referido. Clientes que compran online desde voltika.mx ingresando tu código de referido. El cliente paga directamente a Voltika; tu comisión se abona aparte.');

$pdf->h3('Entrega de pedido asignado');
$pdf->para('Compensación por el servicio logístico. Cuando CEDIS te asigna una moto para entregar a un cliente que no pasó por tu captación.');

$pdf->h2('¿Cómo veo mis comisiones?');
$pdf->para('Todas tus ventas y comisiones devengadas están visibles en tu Panel → sección Comisiones. Se liquidan según el calendario acordado en tu contrato.');

$pdf->para('Si detectas una diferencia en el cálculo, contacta a tu ejecutivo VOLTIKA antes de fin de mes para revisarla.');

// ── SECCIÓN 6: VENTA POR REFERIDO ─────────────────────────────────────────
$pdf->AddPage();
$pdf->h1('6. Venta por referido');

$pdf->para('Tu punto tiene DOS códigos de referido distintos con propósitos diferentes:');

$pdf->h2('Código "Venta directa en tienda"');
$pdf->para('Formato: empieza con PV (por ejemplo PV80D14C). Úsalo cuando el cliente compra físicamente en tu punto. Tú inicias el proceso de venta directa desde tu panel (módulo Venta por referido → Venta directa) y le entregas la moto inmediatamente desde tu inventario de consignación.');

$pdf->h2('Código "Ventas desde la Web Voltika"');
$pdf->para('Formato: empieza con PE (por ejemplo PEB8A55A). Úsalo con clientes potenciales que prefieren comprar desde voltika.mx. El cliente ingresa tu código al hacer el checkout; tú recibes la comisión pero la logística la maneja CEDIS.');

$pdf->h2('Flujo de una venta por referido online');
$pdf->numbered('1', 'Compartes tu código PE con el cliente (por WhatsApp, tarjeta, redes, etc.).');
$pdf->numbered('2', 'Cliente entra a voltika.mx, completa el configurador, ingresa tu código al hacer el pago.');
$pdf->numbered('3', 'La orden aparece en tu panel bajo "Pendientes de asignación" (sección Inventario).');
$pdf->numbered('4', 'CEDIS despacha una moto física que entra a "Por llegar", luego "Recibida", luego "Para entrega".');
$pdf->numbered('5', 'Cuando el cliente llega a recoger, sigues el proceso de entrega normal (sección 4).');

$pdf->h2('Flujo de una venta directa en tienda');
$pdf->numbered('1', 'Cliente llega físicamente a tu punto interesado en una moto.');
$pdf->numbered('2', 'En tu panel, módulo Venta por referido → Venta directa, seleccionas una moto de tu inventario disponible.');
$pdf->numbered('3', 'Registras datos del cliente (nombre, teléfono, email), precio acordado, método de pago.');
$pdf->numbered('4', 'Completas el proceso de entrega (sección 4) con el cliente presente.');

$pdf->notice(
    'Nunca compartas el código erróneo',
    'Si un cliente online ingresa un código PV (de tienda) en lugar de PE (web), la comisión puede no procesarse correctamente. Asegúrate de usar el código correcto según el canal.',
    'warn'
);

// ── SECCIÓN 7: PROTOCOLOS DE EMERGENCIA ────────────────────────────────────
$pdf->AddPage();
$pdf->h1('7. Protocolos de emergencia');

$pdf->h2('Caso 1: Cliente insiste en llevarse la moto sin firmar');
$pdf->para('No entregues la moto. Explícale que el sistema requiere firma digital para protegerlo a él y al punto. Si insiste, contacta a tu ejecutivo VOLTIKA y pasa la llamada.');

$pdf->h2('Caso 2: Alguien pide entregar una moto que no está en tu lista');
$pdf->para('Ni siquiera si dice ser "de CEDIS" o "de Voltika". Nadie puede solicitar una entrega fuera de sistema. Repórtalo a tu ejecutivo inmediatamente como posible intento de fraude.');

$pdf->h2('Caso 3: VIN escaneado no coincide');
$pdf->para('Detén la recepción. Marca la moto como "Retenida" en el sistema. Notifica a CEDIS en menos de 1 hora. No firmes la recepción hasta que CEDIS resuelva la discrepancia.');

$pdf->h2('Caso 4: Daño o robo en el punto');
$pdf->para('Levanta acta con autoridad local (MP o policía). Notifica a Voltika en menos de 4 horas con: foto del daño, copia del acta, VIN afectado. La cobertura del seguro requiere reporte inmediato.');

$pdf->h2('Caso 5: Rostro del cliente NO coincide con su INE o con el expediente de crédito');
$pdf->para('No entregues. Esto es requisito legal — no puedes liberar una moto a crédito a alguien que no es el titular. Pide que se presente el titular, o escala a soporte Voltika para revisión manual.');

$pdf->h2('Caso 6: El cliente pagó pero el sistema dice "pago pendiente"');
$pdf->para('Pide al cliente comprobante de pago (captura del banco o del correo de Stripe). Contacta al ejecutivo VOLTIKA con el número de pedido para que verifique el estado. Si Stripe confirma pago completado, el sistema se actualiza y puedes proceder. Nunca entregues con pago no confirmado.');

// ── SECCIÓN 8: CONTACTO ──────────────────────────────────────────────────
$pdf->AddPage();
$pdf->h1('8. Contacto y soporte');

$pdf->para('Para dudas operativas, capacitación o emergencias:');

$pdf->Ln(4);
$pdf->SetFont('Arial', 'B', 11);
$pdf->SetTextColor(26, 58, 92);
$pdf->Cell(0, 7, _lat('Ejecutivo VOLTIKA asignado'), 0, 1);
$pdf->SetFont('Arial', '', 10.5);
$pdf->SetTextColor(50, 50, 50);
$pdf->MultiCell(0, 5.5, _lat('Tu contacto principal es el ejecutivo VOLTIKA que te afilió. Es quien mejor conoce tu punto y puede darte capacitación por videollamada cuando la necesites.'));
$pdf->Ln(3);

$pdf->SetFont('Arial', 'B', 11);
$pdf->SetTextColor(26, 58, 92);
$pdf->Cell(0, 7, _lat('Soporte general'), 0, 1);
$pdf->SetFont('Arial', '', 10.5);
$pdf->SetTextColor(50, 50, 50);
$pdf->Cell(45, 6, _lat('WhatsApp:'), 0, 0);
$pdf->SetFont('Arial', 'B', 10.5);
$pdf->Cell(0, 6, _lat('557 944 0928'), 0, 1);
$pdf->SetFont('Arial', '', 10.5);
$pdf->Cell(45, 6, _lat('Email:'), 0, 0);
$pdf->SetFont('Arial', 'B', 10.5);
$pdf->Cell(0, 6, _lat('puntos@voltika.mx'), 0, 1);
$pdf->SetFont('Arial', '', 10.5);
$pdf->Cell(45, 6, _lat('Horario:'), 0, 0);
$pdf->SetFont('Arial', 'B', 10.5);
$pdf->Cell(0, 6, _lat('Lunes a Viernes 9:00 - 18:00 hrs'), 0, 1);

$pdf->divider();

$pdf->h2('Canales rápidos según la situación');
$pdf->bullet(_lat('Dudas sobre comisiones → tu ejecutivo VOLTIKA.'));
$pdf->bullet(_lat('Problema técnico con el panel → puntos@voltika.mx.'));
$pdf->bullet(_lat('Emergencia operativa (robo, daño, fraude) → WhatsApp 557 944 0928 inmediatamente.'));
$pdf->bullet(_lat('Discrepancia de VIN en recepción → CEDIS + tu ejecutivo.'));
$pdf->bullet(_lat('Cliente no responde al OTP → reintenta, si persiste contacta soporte.'));

$pdf->divider();

$pdf->SetFont('Arial', 'I', 9.5);
$pdf->SetTextColor(100, 116, 139);
$pdf->MultiCell(0, 5, _lat('Este manual se actualiza periódicamente. La versión vigente siempre está en https://voltika.mx/docs/manual-operador-punto.pdf. Consulta si hay nueva versión cada trimestre.'));

// ════════════════════════════════════════════════════════════════════════════
// OUTPUT
// ════════════════════════════════════════════════════════════════════════════
header_remove('X-Powered-By');
header('Cache-Control: public, max-age=3600');
$pdf->Output('I', 'manual-operador-punto.pdf');
