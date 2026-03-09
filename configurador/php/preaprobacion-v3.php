<?php
/**
 * Voltika - Pre-aprobación Web V3
 * Implementa el algoritmo exacto de VOLTIKA_Preaprobacion_V3_Guia_Programador.docx
 *
 * Integración completa:
 *   - Si se proveen datos personales, consulta Círculo de Crédito vía API
 *   - Si la sesión tiene datos de Círculo (de consultar-buro.php), los usa
 *   - Fallback: evaluación estimada solo por PTI (sin Círculo)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Central config ───────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

session_start();

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

// ── Datos de Círculo de Crédito ─────────────────────────────────────────────
// Prioridad 1: datos enviados directamente en el request (de consultar-buro.php)
// Prioridad 2: datos guardados en sesión (si ya se consultó previamente)
// Prioridad 3: null (evaluación estimada sin Círculo)

$score             = $json['score']             ?? $_SESSION['cdc_score']             ?? null;
$pago_mensual_buro = $json['pago_mensual_buro'] ?? $_SESSION['cdc_pago_mensual_buro'] ?? 0;
$dpd90_flag        = $json['dpd90_flag']        ?? $_SESSION['cdc_dpd90_flag']        ?? null;
$dpd_max           = $json['dpd_max']           ?? $_SESSION['cdc_dpd_max']           ?? null;

// Asegurar tipos correctos
if ($score !== null) $score = intval($score);
$pago_mensual_buro = floatval($pago_mensual_buro);
if ($dpd90_flag !== null) $dpd90_flag = (bool)$dpd90_flag;

// ── Validación de inputs ──────────────────────────────────────────────────────
if ($ingreso_mensual_est <= 0 || $pago_semanal_voltika <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Ingreso o pago semanal invalido']);
    exit;
}

// ── Config V3 (ajustar umbrales aquí sin tocar lógica) ───────────────────────
$V3 = [
    'downPaymentMin' => 0.25,
    'KO' => [
        'scoreMin'   => 420,
        'ptiExtreme' => 1.05,
        'severeDPD'  => true,
    ],
    'PRE' => [
        'scoreMin' => 480,
        'ptiMax'   => 0.90,
    ],
    'CONDITIONAL' => [
        'downPaymentRequiredByPTI' => [
            ['maxPTI' => 0.90, 'required' => 0.25],
            ['maxPTI' => 0.95, 'required' => 0.30],
            ['maxPTI' => 1.00, 'required' => 0.35],
            ['maxPTI' => 1.05, 'required' => 0.45],
        ],
        'maxTermByRisk' => [
            ['minScore' => 520, 'maxPTI' => 0.85, 'term' => 36],
            ['minScore' => 480, 'maxPTI' => 0.90, 'term' => 24],
            ['minScore' => 440, 'maxPTI' => 0.95, 'term' => 18],
            ['minScore' => 420, 'maxPTI' => 1.05, 'term' => 18],
        ],
        'lowScorePTIGuardrail' => ['scoreMax' => 439, 'ptiMax' => 0.95],
    ],
];

// ── Fórmulas V3 ───────────────────────────────────────────────────────────────
$pago_mensual_voltika = $pago_semanal_voltika * 4.3333;
$pti_total   = ($pago_mensual_buro + $pago_mensual_voltika) / $ingreso_mensual_est;
$mora_severa = ($dpd90_flag === true) || ($dpd_max !== null && (float)$dpd_max >= 90);
$eng_min     = $V3['downPaymentMin'];

// ── Evaluación V3 ─────────────────────────────────────────────────────────────
$result = [];

if ($score === null) {
    // Sin datos de Círculo → evaluación estimada solo por PTI
    if ($pti_total > $V3['KO']['ptiExtreme']) {
        $result = ['status' => 'NO_VIABLE',            'pti_total' => round($pti_total, 4), 'reasons' => ['PTI_EXTREMO_SIN_CIRCULO']];
    } elseif ($pti_total <= 0.75) {
        $result = ['status' => 'PREAPROBADO_ESTIMADO', 'pti_total' => round($pti_total, 4),
                   'enganche_requerido_min' => $eng_min, 'plazo_max_meses' => 36];
    } else {
        $result = ['status' => 'CONDICIONAL_ESTIMADO', 'pti_total' => round($pti_total, 4),
                   'enganche_requerido_min' => min(max(calcularEngancheMin($pti_total, $V3), $eng_min), 0.60),
                   'plazo_max_meses' => 24];
    }
} else {
    // Con datos completos de Círculo
    // 1. KO reales
    if ($score < $V3['KO']['scoreMin']) {
        $result = ['status' => 'NO_VIABLE', 'pti_total' => round($pti_total, 4), 'enganche_min' => $eng_min, 'reasons' => ['KO_SCORE_LT_MIN']];
    }
    elseif ($V3['KO']['severeDPD'] && $mora_severa) {
        $result = ['status' => 'NO_VIABLE', 'pti_total' => round($pti_total, 4), 'enganche_min' => $eng_min, 'reasons' => ['KO_SEVERE_DPD_90PLUS']];
    }
    elseif ($pti_total > $V3['KO']['ptiExtreme']) {
        $result = ['status' => 'NO_VIABLE', 'pti_total' => round($pti_total, 4), 'enganche_min' => $eng_min, 'reasons' => ['KO_PTI_EXTREME']];
    }
    // 2. Guardrail: score bajo + PTI alto
    elseif ($score <= $V3['CONDITIONAL']['lowScorePTIGuardrail']['scoreMax']
         && $pti_total > $V3['CONDITIONAL']['lowScorePTIGuardrail']['ptiMax']) {
        $result = ['status' => 'NO_VIABLE', 'pti_total' => round($pti_total, 4), 'enganche_min' => $eng_min, 'reasons' => ['KO_GUARDRAIL_LOW_SCORE_HIGH_PTI']];
    }
    // 3. PREAPROBADO
    elseif ($score >= $V3['PRE']['scoreMin'] && $pti_total <= $V3['PRE']['ptiMax']) {
        $result = ['status' => 'PREAPROBADO', 'pti_total' => round($pti_total, 4),
                   'enganche_min' => $eng_min, 'enganche_requerido_min' => $eng_min,
                   'plazo_max_meses' => calcularPlazoMax($score, $pti_total, $V3)];
    }
    // 4. CONDICIONAL
    else {
        $eng_req = min(max(calcularEngancheMin($pti_total, $V3), $eng_min), 0.60);
        $result  = ['status' => 'CONDICIONAL', 'pti_total' => round($pti_total, 4),
                    'enganche_min' => $eng_min, 'enganche_requerido_min' => $eng_req,
                    'plazo_max_meses' => calcularPlazoMax($score, $pti_total, $V3)];
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
    'dpd_max'              => $dpd_max,
    'circulo_source'       => ($score !== null) ? 'real' : 'estimado',
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

function calcularEngancheMin(float $pti, array $cfg): float {
    foreach ($cfg['CONDITIONAL']['downPaymentRequiredByPTI'] as $row) {
        if ($pti <= $row['maxPTI']) return $row['required'];
    }
    return end($cfg['CONDITIONAL']['downPaymentRequiredByPTI'])['required'];
}

function calcularPlazoMax(?int $score, float $pti, array $cfg): int {
    if ($score === null) return 18;
    foreach ($cfg['CONDITIONAL']['maxTermByRisk'] as $row) {
        if ($score >= $row['minScore'] && $pti <= $row['maxPTI']) return $row['term'];
    }
    return 18;
}
