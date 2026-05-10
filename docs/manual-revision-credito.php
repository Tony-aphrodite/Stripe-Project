<?php
/**
 * Voltika — Manual de Revisión Manual de Crédito (PDF dinámico)
 * Generado con FPDF. Se sirve inline como PDF al acceder a la URL.
 *
 * URL pública: https://voltika.mx/docs/manual-revision-credito.php
 *
 * Audiencia: equipo de revisión (admin, cedis, operador con permiso de
 * preaprobaciones). Documenta la pantalla
 *   Admin → Preaprobaciones → (clic en cualquier solicitud)
 *
 * Esta pantalla es donde el equipo decide aprobar / condicionar /
 * ofrecer contado / rechazar cada solicitud de crédito Voltika.
 */

// Resolver FPDF desde configurador/php/vendor/fpdf
$fpdfCandidates = [
    __DIR__ . '/../configurador/php/vendor/fpdf/fpdf.php',
    __DIR__ . '/../configurador_prueba_test/php/vendor/fpdf/fpdf.php',
];
foreach ($fpdfCandidates as $p) { if (file_exists($p)) { require_once $p; break; } }
if (!class_exists('FPDF')) {
    http_response_code(500);
    echo 'FPDF no encontrado en el servidor.';
    exit;
}

/**
 * Convert UTF-8 to ISO-8859-1 for FPDF Latin-1 fonts. Handles Spanish
 * accents, eñe, question marks, etc. Bullet glyph (chr(149)) is preserved.
 */
function _lat($s) {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', (string)$s);
}

class ManualCreditoPDF extends FPDF {
    // Voltika brand colors
    protected $colorNavy     = [26, 58, 92];     // #1a3a5c
    protected $colorPrimary  = [3, 159, 225];    // #039fe1
    protected $colorAccent   = [34, 211, 122];   // #22d37a
    protected $colorDanger   = [196, 30, 58];    // #c41e3a
    protected $colorWarn     = [217, 119, 6];    // amber
    protected $colorMuted    = [100, 116, 139];  // slate

    public function Header() {
        if ($this->PageNo() === 1) return;
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(...$this->colorNavy);
        $this->Cell(0, 8, _lat('voltika · Manual de Revisión Manual de Crédito'), 0, 0, 'L');
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
        $this->Cell(0, 5, _lat('Voltika México · voltika.mx · creditos@voltika.mx · Mtech Gears S.A. de C.V.'), 0, 0, 'C');
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
        $bg     = $kind === 'danger' ? [255, 237, 237] : ($kind === 'ok' ? [232, 245, 233] : [255, 251, 235]);
        $border = $kind === 'danger' ? $this->colorDanger : ($kind === 'ok' ? [46, 125, 50] : $this->colorWarn);
        $textCol= $kind === 'danger' ? [153, 27, 27] : ($kind === 'ok' ? [27, 94, 32] : [120, 53, 15]);

        $this->Ln(2);
        $x = $this->GetX(); $y = $this->GetY();
        $this->SetFillColor(...$bg);
        $this->SetDrawColor(...$border);
        $this->SetLineWidth(0.6);

        $estimatedLines = max(1, ceil(strlen($body) / 90));
        $blockH = 6 + ($estimatedLines * 5.5) + 6;

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

    public function table2col($headers, $rows, $widths = [60, 130]) {
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(255, 255, 255);
        $this->SetFillColor(...$this->colorNavy);
        foreach ($headers as $i => $h) {
            $this->Cell($widths[$i], 7, _lat($h), 1, 0, 'L', true);
        }
        $this->Ln();
        $this->SetFont('Arial', '', 9.5);
        $this->SetTextColor(50, 50, 50);
        $alt = false;
        foreach ($rows as $row) {
            if ($alt) { $this->SetFillColor(245, 247, 250); }
            else      { $this->SetFillColor(255, 255, 255); }
            // Use MultiCell-like behaviour: compute the height needed for the longest cell
            $startY = $this->GetY();
            $startX = $this->GetX();
            // Render col 0
            $this->MultiCell($widths[0], 5.5, _lat($row[0]), 1, 'L', $alt);
            $endY0 = $this->GetY();
            $usedH = $endY0 - $startY;
            // Render col 1 at same height
            $this->SetXY($startX + $widths[0], $startY);
            $this->MultiCell($widths[1], 5.5, _lat($row[1]), 1, 'L', $alt);
            $endY1 = $this->GetY();
            // Align next row to whichever is lowest
            $this->SetY(max($endY0, $endY1));
            $alt = !$alt;
        }
        $this->Ln(3);
    }

    public function table3col($headers, $rows, $widths = [40, 70, 80]) {
        $this->SetFont('Arial', 'B', 9.5);
        $this->SetTextColor(255, 255, 255);
        $this->SetFillColor(...$this->colorNavy);
        foreach ($headers as $i => $h) {
            $this->Cell($widths[$i], 7, _lat($h), 1, 0, 'L', true);
        }
        $this->Ln();
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(50, 50, 50);
        $alt = false;
        foreach ($rows as $row) {
            $startY = $this->GetY();
            $startX = $this->GetX();
            $maxY = $startY;
            foreach ($row as $i => $cell) {
                $this->SetXY($startX + array_sum(array_slice($widths, 0, $i)), $startY);
                $this->MultiCell($widths[$i], 5.5, _lat($cell), 1, 'L', $alt);
                if ($this->GetY() > $maxY) $maxY = $this->GetY();
            }
            $this->SetY($maxY);
            $alt = !$alt;
        }
        $this->Ln(3);
    }

    public function cover() {
        $this->AddPage();
        $this->SetFillColor(...$this->colorNavy);
        $this->Rect(0, 0, 210, 297, 'F');

        // Brand
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 36);
        $this->SetXY(20, 90);
        $this->Cell(0, 14, 'voltika', 0, 1);

        $this->SetFont('Arial', '', 12);
        $this->SetTextColor(...$this->colorPrimary);
        $this->SetX(20);
        $this->Cell(0, 6, _lat('Movilidad eléctrica inteligente'), 0, 1);

        $this->Ln(20);
        $this->SetFont('Arial', 'B', 24);
        $this->SetTextColor(255, 255, 255);
        $this->SetX(20);
        $this->MultiCell(170, 12, _lat('Manual de Revisión Manual de Crédito'));

        $this->Ln(6);
        $this->SetFont('Arial', '', 13);
        $this->SetTextColor(200, 230, 255);
        $this->SetX(20);
        $this->MultiCell(170, 7,
            _lat('Cómo aprobar, condicionar, ofrecer contado o rechazar una '
               . 'solicitud de crédito desde el panel de administración.'));

        // Bottom block
        $this->SetXY(20, 250);
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(180, 200, 220);
        $this->Cell(0, 5, _lat('Versión 2026-05-09  ·  Voltika México  ·  Mtech Gears S.A. de C.V.'), 0, 1);
        $this->SetX(20);
        $this->Cell(0, 5, _lat('Audiencia: equipo de revisión de crédito (admin, cedis, operador)'), 0, 1);
    }
}

// Build the document
$pdf = new ManualCreditoPDF('P', 'mm', 'A4');
$pdf->SetMargins(10, 14, 10);
$pdf->SetAutoPageBreak(true, 14);

$pdf->cover();

// ─────────────────────────────────────────────────────────────────────────
// 1. ¿Qué es esta pantalla?
// ─────────────────────────────────────────────────────────────────────────
$pdf->AddPage();
$pdf->h1('1. ¿Qué muestra esta pantalla?');

$pdf->para(
    'Cuando un cliente solicita crédito Voltika desde el configurador, el '
  . 'sistema realiza tres pasos automáticos antes de pedirte una decisión:'
);
$pdf->numbered(1, 'Consulta el Buró de Crédito (Círculo de Crédito) y obtiene su historial.');
$pdf->numbered(2, 'Calcula el PTI (Pago a Ingreso) sumando deuda actual + el pago Voltika que solicita.');
$pdf->numbered(3, 'Recomienda una decisión: PREAPROBADO, CONDICIONAL o NO_VIABLE.');

$pdf->Ln(2);
$pdf->para(
    'Esta pantalla es la última etapa: te muestra todos los datos relevantes '
  . 'y te entrega la decisión final. Tienes la última palabra. El sistema '
  . 'sugiere; tú decides.'
);

$pdf->notice(
    'Regla de oro',
    'Nunca apruebes plazos sin revisar primero el PTI total y el Score. '
  . 'El sistema bloquea los casos extremos automáticamente, pero los '
  . 'casos limítrofes dependen de tu juicio.',
    'ok'
);

// ─────────────────────────────────────────────────────────────────────────
// 2. Cómo leer la recomendación del sistema
// ─────────────────────────────────────────────────────────────────────────
$pdf->h1('2. Cómo leer la recomendación del sistema');

$pdf->h2('Los tres status posibles');
$pdf->table3col(
    ['Status', 'Cuándo aparece', 'Acción típica'],
    [
        ['PREAPROBADO',  'Score saludable, PTI bajo (<35%), sin morosidad activa.',                                    'Aprobar plazos con los términos sugeridos por el sistema.'],
        ['CONDICIONAL',  'Score medio o PTI moderado. Puede aprobar pero con condiciones más estrictas.',              'Aprobar con enganche aumentado y/o plazo reducido.'],
        ['NO_VIABLE',    'Score bajo, PTI alto, sobreendeudamiento o morosidad significativa.',                        'Sistema sugiere rechazar o sólo ofrecer contado. Override manual posible bajo confirmación.'],
    ],
    [40, 75, 75]
);

$pdf->h2('Datos clave en la cabecera');
$pdf->bullet('Score: rango 300-850. Por debajo de 420 se considera bajo.');
$pdf->bullet('PTI total (CDC + Voltika): porcentaje del ingreso comprometido en deuda. <35% saludable, >=50% bloqueo automático.');
$pdf->bullet('Pago Voltika mensual: lo que el cliente pagaría con los términos solicitados.');
$pdf->bullet('Truora ID: estado de verificación de identidad. Si está rechazado, NO se puede aprobar plazos (bloqueo de cumplimiento).');
$pdf->bullet('Source: origen del cálculo (cdc_real, score_bajo_pti_excel, fallback, etc.). Te indica qué motor decidió la categoría.');

$pdf->notice(
    'PTI > 50%',
    'Cuando el PTI combinado supera el 50%, los botones de aprobación se '
  . 'desactivan automáticamente. No se puede hacer override desde esta '
  . 'pantalla. Es un bloqueo duro de riesgo.',
    'danger'
);

// ─────────────────────────────────────────────────────────────────────────
// 3. La barra "Ajuste manual de la oferta"
// ─────────────────────────────────────────────────────────────────────────
$pdf->AddPage();
$pdf->h1('3. La barra de Ajuste Manual de la Oferta');

$pdf->para(
    'Es la sección azul debajo del recuadro de recomendación. Te permite '
  . 'ajustar los términos del crédito antes de enviar la oferta al cliente. '
  . 'Aparece en todos los casos excepto cuando hay un bloqueo duro (PLD, '
  . 'DPD90, Truora rechazado, PTI >= 50%).'
);

$pdf->h2('Las tres líneas comparativas');
$pdf->para(
    'Arriba de la barra siempre verás tres etiquetas que te dan contexto:'
);
$pdf->bullet('Cliente solicitó: lo que el cliente pidió originalmente en el formulario.');
$pdf->bullet('Sistema sugiere: lo que el algoritmo recomienda con base en su perfil de riesgo.');
$pdf->bullet('Vas a enviar: lo que tú vas a ofrecer (cambia en vivo al mover los controles).');

$pdf->h2('Los controles');
$pdf->bullet('Slider de Enganche: rango 25% - 80% en pasos de 5%.');
$pdf->bullet('Plazo: 12, 18, 24 ó 36 meses (dropdown).');
$pdf->bullet('Calculadora en vivo: monto financiado, pago semanal y pago mensual se actualizan al instante.');

$pdf->h2('Los dos botones azules');
$pdf->table2col(
    ['Botón', 'Qué hace y cuándo usarlo'],
    [
        ['Probar (no enviar)',
         'Abre en pestaña nueva la pantalla que vería el cliente con esos '
       . 'términos. NO envía email ni SMS, NO guarda nada. Úsalo para '
       . 'verificar visualmente cómo se ve la oferta antes de enviarla.'],
        ['Enviar oferta (válida 48h)',
         'Guarda los valores y manda email + SMS al cliente con un enlace '
       . 'personalizado de 48 horas. Úsalo cuando ya estás seguro de los '
       . 'términos y quieres que el cliente acepte/rechace en línea.'],
    ]
);

$pdf->notice(
    'Override sobre status NO_VIABLE',
    'Si el sistema recomendaba rechazar (NO_VIABLE) y aun así envías la '
  . 'oferta, te aparecerá un diálogo de confirmación. Tu decisión queda '
  . 'registrada en la nota como "OVERRIDE manual" con timestamp para '
  . 'auditoría posterior.',
    'warn'
);

// ─────────────────────────────────────────────────────────────────────────
// 4. Los 4 botones de decisión final
// ─────────────────────────────────────────────────────────────────────────
$pdf->h1('4. Los 4 botones de decisión final');

$pdf->para(
    'Debajo de la barra de ajuste hay cuatro botones grandes. Cada uno '
  . 'representa una decisión distinta:'
);

$pdf->table3col(
    ['Botón', 'Qué hace', 'Cuándo usarlo'],
    [
        ['Aprobar Plazos',
         'Marca la solicitud como aprobada con los valores actuales del '
       . 'slider/dropdown. Registra la nota con timestamp y los términos.',
         'Ya hablaste con el cliente y aceptó. O quieres aprobar internamente '
       . 'sin enviar oferta personalizada.'],
        ['Ofrecer Contado',
         'Le ofrecemos al cliente solo pago contado, sin financiamiento.',
         'No aprobamos crédito pero el cliente podría pagar al contado.'],
        ['9 MSI Sin Intereses',
         'Le ofrecemos la promoción de 9 meses sin intereses con tarjeta.',
         'Cliente con buen perfil de tarjeta. NO usar si está sobreendeudado '
       . '(su tarjeta probablemente no pasa).'],
        ['Rechazar',
         'Marca la solicitud como rechazada. El status pasa a NO_VIABLE.',
         'El cliente no califica para ningún esquema. Acción definitiva.'],
    ],
    [40, 75, 75]
);

// ─────────────────────────────────────────────────────────────────────────
// 5. Bloqueos duros vs blandos
// ─────────────────────────────────────────────────────────────────────────
$pdf->AddPage();
$pdf->h1('5. Bloqueos duros vs bloqueos blandos');

$pdf->h2('Bloqueos DUROS — sin override desde esta pantalla');
$pdf->para('Cuando alguno de estos aplica, los botones de aprobación se desactivan:');
$pdf->bullet('PLD MATCH: cliente aparece en la lista negra antiterrorismo SAT/UIF. Solo Rechazar disponible.');
$pdf->bullet('DPD90 activo: el cliente está actualmente moroso 90+ días en otra cuenta de su Buró.');
$pdf->bullet('Truora rechazado: la verificación de identidad fue rechazada (selfie + INE no coinciden, INE falsa, etc.).');
$pdf->bullet('PTI >= 50%: más de la mitad de su ingreso ya está comprometida en deuda.');

$pdf->notice(
    'Si ves estos bloqueos, NO insistas',
    'Los bloqueos duros son por cumplimiento legal (PLD/SAT/UIF) o por '
  . 'riesgo extremo. Forzarlos expone a la empresa a sanciones o pérdidas. '
  . 'Si crees que el caso amerita revisión, escala al supervisor.',
    'danger'
);

$pdf->h2('Bloqueos BLANDOS — admin puede hacer override con confirmación');
$pdf->para('En estos casos los botones siguen activos pero te pedirán confirmar la acción:');
$pdf->bullet('Score bajo (<420) sin DPD90 ni PLD.');
$pdf->bullet('Status NO_VIABLE por sobreendeudamiento moderado o morosidad histórica (no actual).');
$pdf->bullet('Combinaciones marginales de Score + PTI.');

$pdf->para(
    'En estos casos verás un diálogo: "El sistema recomienda rechazar… '
  . '¿estás seguro?" — al confirmar, tu decisión queda registrada como '
  . '"OVERRIDE manual" en la nota, con tu usuario y la marca de tiempo.'
);

// ─────────────────────────────────────────────────────────────────────────
// 6. Sección de Seguimiento de venta
// ─────────────────────────────────────────────────────────────────────────
$pdf->h1('6. Sección de Seguimiento de venta');

$pdf->para(
    'Debajo de los botones de decisión está la sección de seguimiento, '
  . 'que te ayuda a llevar el control comercial del caso:'
);

$pdf->table2col(
    ['Estado', 'Cuándo marcarlo'],
    [
        ['Nuevo',       'Recién entrada, aún no contactada por nadie del equipo.'],
        ['Contactado',  'Ya hablaste o escribiste al cliente.'],
        ['Vendido',     'El cliente firmó el contrato y cerró la venta.'],
        ['Descartado',  'El cliente perdió interés o no responde después de varios intentos.'],
    ]
);

$pdf->h3('Notas');
$pdf->para(
    'Las notas son apend-only: cada acción que tomes (Aprobar Plazos, '
  . 'Enviar link de Truora, Rechazar, etc.) agrega una línea con timestamp '
  . 'automáticamente. Puedes agregar tus propias observaciones manualmente '
  . 'en el cuadro de texto y guardarlas con "Guardar cambios".'
);

// ─────────────────────────────────────────────────────────────────────────
// 7. Botones de la parte inferior
// ─────────────────────────────────────────────────────────────────────────
$pdf->AddPage();
$pdf->h1('7. Botones de la parte inferior');

$pdf->table2col(
    ['Botón', 'Acción'],
    [
        ['Enviar link de Truora',
         'Si Truora aparece "No iniciado", esto manda email + SMS al cliente '
       . 'con el enlace de verificación de identidad. El enlace es válido '
       . '7 días. El cliente NO tiene que volver a llenar el formulario.'],
        ['Enviar a Ventas para cobro',
         'Promueve la solicitud al equipo de Ventas. Solo está activo si el '
       . 'cliente cumple los tres prerequisitos: CDC verificado + CURP '
       . 'válido + Truora aprobado. Si está gris, pasa el cursor encima '
       . 'para ver qué falta.'],
        ['Archivar',
         'Oculta la solicitud del listado pero mantiene los datos en la '
       . 'base de datos. Útil para casos cerrados que no quieres ver pero '
       . 'tampoco eliminar.'],
        ['Eliminar',
         'BORRADO PERMANENTE. Solo disponible para usuarios con rol admin. '
       . 'Requiere escribir literalmente la palabra "ELIMINAR" para '
       . 'confirmar. No se puede deshacer.'],
        ['Cerrar',
         'Cierra la ventana sin guardar cambios pendientes en Seguimiento '
       . 'o Notas. Los botones grandes (Aprobar / Contado / MSI / Rechazar) '
       . 'ya se guardaron automáticamente al hacer clic.'],
        ['Guardar cambios',
         'Guarda los cambios manuales hechos en Seguimiento + Notas. NO es '
       . 'necesario para los 4 botones de decisión grandes (esos guardan '
       . 'automáticamente).'],
    ]
);

// ─────────────────────────────────────────────────────────────────────────
// 8. Caso práctico
// ─────────────────────────────────────────────────────────────────────────
$pdf->h1('8. Caso práctico');

$pdf->para('Supongamos que abres una solicitud y ves estos datos:');
$pdf->bullet('Ingreso mensual: $15,000 MXN');
$pdf->bullet('Enganche solicitado: 25% ($12,065) - sistema requiere 50%');
$pdf->bullet('Plazo solicitado: 12 meses');
$pdf->bullet('Pago Voltika mensual con esos términos: $4,207');
$pdf->bullet('PTI total: 28%');
$pdf->bullet('Score: 417 (morosidad significativa en historial)');
$pdf->bullet('Status: CONDICIONAL');

$pdf->h3('Cómo decides');

$pdf->numbered(1,
    'Mira la calculadora: con 50% / 12 meses, el pago mensual baja a $2,804. '
  . 'Sobre un ingreso de $15,000, eso es 18.7% del ingreso — muy saludable.'
);
$pdf->numbered(2,
    'Score 417 es bajo, así que el sistema pide enganche más alto para '
  . 'mitigar riesgo. Aceptable.'
);
$pdf->numbered(3,
    'PTI total 28% está dentro del rango saludable. Confirma que el cliente '
  . 'puede absorber el pago sin estresarse.'
);
$pdf->numbered(4,
    'Decide: si quieres que el cliente acepte/rechace por sí mismo en línea, '
  . 'verifica visualmente con "Probar" y luego clic en "Enviar oferta". '
  . 'Si ya hablaste verbalmente con el cliente y aceptó, clic directo en '
  . '"Aprobar Plazos" — la nota queda como '
  . '"Aprobar plazos: 50% / 12 meses" con timestamp.'
);
$pdf->numbered(5,
    'Cambia "Seguimiento" a Contactado o Vendido según corresponda y haz '
  . 'clic en "Guardar cambios".'
);

// ─────────────────────────────────────────────────────────────────────────
// 9. Reglas rápidas
// ─────────────────────────────────────────────────────────────────────────
$pdf->AddPage();
$pdf->h1('9. Reglas rápidas de oro');

$pdf->h2('Lo que SÍ debes hacer');
$pdf->bullet('Siempre revisar PTI antes de aprobar. Si supera 35% combinado, sé conservador.');
$pdf->bullet('Aumentar el enganche antes que reducir el plazo: protege más a Voltika ante impago.');
$pdf->bullet('Verificar visualmente con "Probar" antes de "Enviar oferta" en casos limítrofes.');
$pdf->bullet('Documentar en Notas cuando hagas un override de la recomendación del sistema.');
$pdf->bullet('Cambiar el Seguimiento al estado correcto al cerrar el caso.');

$pdf->h2('Lo que NO debes hacer');
$pdf->bullet('Aprobar plazos con Truora rechazado — bloqueo de cumplimiento, sin excepciones.');
$pdf->bullet('Insistir con casos PLD MATCH — bloqueo SAT/UIF, riesgo legal serio.');
$pdf->bullet('Ofrecer 9 MSI a clientes sobreendeudados — su tarjeta probablemente está saturada.');
$pdf->bullet('Eliminar permanentemente sin estar 100% seguro — usa Archivar para casos dudosos.');
$pdf->bullet('Aprobar sin documentar el motivo del override en la nota.');

// ─────────────────────────────────────────────────────────────────────────
// 10. Soporte
// ─────────────────────────────────────────────────────────────────────────
$pdf->h1('10. Soporte');

$pdf->para(
    'Cualquier duda, escríbenos al equipo técnico con esta información:'
);
$pdf->bullet('ID de la preaprobación (visible en la URL de la solicitud).');
$pdf->bullet('Captura de pantalla del caso.');
$pdf->bullet('Lo que esperabas vs lo que pasó.');

$pdf->Ln(4);
$pdf->para('Email: creditos@voltika.mx');
$pdf->para('Equipo técnico: voltika.mx');

$pdf->Ln(8);
$pdf->SetFont('Arial', 'I', 9);
$pdf->SetTextColor(...$pdf->colorMuted ?? [100, 116, 139]);
$pdf->Cell(0, 5, _lat('Manual generado dinámicamente. Última revisión: ' . date('Y-m-d')), 0, 1, 'C');

// Output inline
$pdf->Output('I', 'manual-revision-credito-voltika.pdf');
