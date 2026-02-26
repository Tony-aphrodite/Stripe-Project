<?php
/**
 * Voltika - Pre-aprobación Web V3
 * Implementa el algoritmo exacto de VOLTIKA_Preaprobacion_V3_Guia_Programador.docx
 *
 * Fase actual: evaluación estimada (sin Círculo de Crédito).
 * Cuando Truora + Círculo estén integrados, reemplazar $score, $pago_mensual_buro
 * y $dpd90_flag con los valores reales de la API.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Request ───────────────────────────────────────────────────────────────────
$json = json_decode(file_get_contents('php://input'), true);

if (!$json) {
    http_response_code(400);
    echo json_encode(['error' => 'Request invalido']);
    exit;
}

$ingreso_mensual_est  = floatval($json['ingreso_mensual_est']  ?? 0);
$pago_semanal_voltika = floatval($json['pago_semanal_voltika'] ?? 0);
$enganche_pct         = floatval($json['enganche_pct']         ?? 0.30);
$plazo_meses          = intval($json['plazo_meses']            ?? 12);
$precio_contado       = floatval($json['precio_contado']       ?? 0);
$modelo               = $json['modelo'] ?? '';

// ── Datos de Círculo de Crédito (pendiente integración Truora) ────────────────
// TODO: Obtener de la sesión después de verificación Truora
$score             = null;   // null = sin dato de Círculo aún
$pago_mensual_buro = 0;      // 0 conservador hasta tener dato real
$dpd90_flag        = null;   // null = no verificado
$dpd_max           = null;

// ── Validación de inputs ──────────────────────────────────────────────────────
if ($ingreso_mensual_est <= 0 || $pago_semanal_voltika <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Ingreso o pago semanal invalido']);
    exit;
}

// ── Fórmulas V3 ───────────────────────────────────────────────────────────────
// Mensualización: pago_mensual_voltika = pago_semanal * 4.3333 (52/12)
$pago_mensual_voltika = $pago_semanal_voltika * 4.3333;
$pti_total = ($pago_mensual_buro + $pago_mensual_voltika) / $ingreso_mensual_est;

// Mora severa
$mora_severa = ($dpd90_flag === true) || ($dpd_max !== null && $dpd_max >= 90);

// ── Evaluación V3 ─────────────────────────────────────────────────────────────
$result = [];

if ($score === null) {
    // Sin datos de Círculo → evaluación estimada solo por PTI
    if ($pti_total > 1.05) {
        $result = [
            'status'   => 'NO_VIABLE',
            'pti_total'=> round($pti_total, 4),
            'reasons'  => ['PTI_EXTREMO_SIN_CIRCULO']
        ];
    } elseif ($pti_total <= 0.75) {
        $result = [
            'status'            => 'PREAPROBADO_ESTIMADO',
            'pti_total'         => round($pti_total, 4),
            'plazo_max_meses'   => 36
        ];
    } else {
        $result = [
            'status'                 => 'CONDICIONAL_ESTIMADO',
            'pti_total'              => round($pti_total, 4),
            'enganche_requerido_min' => calcularEngancheMin($pti_total),
            'plazo_max_meses'        => 24
        ];
    }
} else {
    // Con datos completos de Círculo
    // 1. KO reales → NO_VIABLE
    if ($score < 420 || $mora_severa || $pti_total > 1.05) {
        $result = [
            'status'   => 'NO_VIABLE',
            'pti_total'=> round($pti_total, 4),
            'reasons'  => ['KO_REAL']
        ];
    }
    // 2. Guardrail → NO_VIABLE
    elseif ($score <= 439 && $pti_total > 0.95) {
        $result = [
            'status'   => 'NO_VIABLE',
            'pti_total'=> round($pti_total, 4),
            'reasons'  => ['GUARDRAIL']
        ];
    }
    // 3. PREAPROBADO
    elseif ($score >= 480 && $pti_total <= 0.90) {
        $result = [
            'status'          => 'PREAPROBADO',
            'pti_total'       => round($pti_total, 4),
            'plazo_max_meses' => calcularPlazoMax($score, $pti_total)
        ];
    }
    // 4. CONDICIONAL (todo lo demás)
    else {
        $result = [
            'status'                 => 'CONDICIONAL',
            'pti_total'              => round($pti_total, 4),
            'enganche_requerido_min' => calcularEngancheMin($pti_total),
            'plazo_max_meses'        => calcularPlazoMax($score, $pti_total)
        ];
    }
}

// ── Logging (guardar para calibración) ───────────────────────────────────────
$log = [
    'timestamp'            => date('c'),
    'modelo'               => $modelo,
    'ingreso_mensual_est'  => $ingreso_mensual_est,
    'pago_semanal_voltika' => $pago_semanal_voltika,
    'pago_mensual_voltika' => round($pago_mensual_voltika, 2),
    'pago_mensual_buro'    => $pago_mensual_buro,
    'pti_total'            => round($pti_total, 4),
    'score'                => $score,
    'dpd90_flag'           => $dpd90_flag,
    'enganche_pct'         => $enganche_pct,
    'plazo_meses'          => $plazo_meses,
    'status'               => $result['status'],
    'enganche_requerido'   => $result['enganche_requerido_min'] ?? null,
    'plazo_max'            => $result['plazo_max_meses'] ?? null
];

$logFile = __DIR__ . '/logs/preaprobacion.log';
if (!is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}
file_put_contents($logFile, json_encode($log) . "\n", FILE_APPEND | LOCK_EX);

echo json_encode($result);

// ── Funciones auxiliares ──────────────────────────────────────────────────────

/**
 * Enganche mínimo requerido por PTI (tabla V3)
 */
function calcularEngancheMin(float $pti): float {
    if ($pti <= 0.90) return 0.25;
    if ($pti <= 0.95) return 0.30;
    if ($pti <= 1.00) return 0.35;
    return 0.45;
}

/**
 * Plazo máximo permitido por score + PTI (tabla V3)
 */
function calcularPlazoMax(?int $score, float $pti): int {
    if ($score === null) return 18;
    if ($score >= 520 && $pti <= 0.85) return 36;
    if ($score >= 480 && $pti <= 0.90) return 24;
    if ($score >= 440 && $pti <= 0.95) return 18;
    if ($score >= 420 && $pti <= 1.05) return 18;
    return 18;
}
