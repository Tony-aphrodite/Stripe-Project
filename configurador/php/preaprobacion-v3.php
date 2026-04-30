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

// Customer info (for admin lead tracking + self-scoring fallback)
$nombre               = trim($json['nombre']           ?? '');
$apellidoPaterno      = trim($json['apellido_paterno'] ?? '');
$apellidoMaterno      = trim($json['apellido_materno'] ?? '');
$telefono             = trim($json['telefono']         ?? '');
$fechaNacimiento      = $json['fecha_nacimiento']      ?? '';
$email                = trim(strtolower($json['email'] ?? ''));
$cp                   = $json['cp']                    ?? '';
$ciudadCust           = trim($json['ciudad']           ?? '');
$estadoCust           = trim($json['estado']           ?? '');
$truoraOk             = !empty($json['truora_ok']);

// ── Datos de Círculo de Crédito ─────────────────────────────────────────────
// Prioridad 1: datos enviados directamente en el request (de consultar-buro.php)
// Prioridad 2: datos guardados en sesión (si ya se consultó previamente)
// Prioridad 3: null (evaluación estimada sin Círculo)

$score             = $json['score']             ?? $_SESSION['cdc_score']             ?? null;
$pago_mensual_buro = $json['pago_mensual_buro'] ?? $_SESSION['cdc_pago_mensual_buro'] ?? 0;
$dpd90_flag        = $json['dpd90_flag']        ?? $_SESSION['cdc_dpd90_flag']        ?? null;
$dpd_max           = $json['dpd_max']           ?? $_SESSION['cdc_dpd_max']           ?? null;

// Tri-state CDC identity flag — see consultar-buro.php:
//   true  → CDC found the persona (even if score is null for thin file)
//   false → CDC returned 404.1 "no existe" — block approval, identity invalid
//   null  → CDC unreachable / not consulted — fall back to self-score
// Accept either the POST body (when frontend forwards it) or session.
$person_found = array_key_exists('person_found', $json)
    ? ($json['person_found'] === null ? null : (bool)$json['person_found'])
    : ($_SESSION['cdc_person_found'] ?? null);

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

// HARD KO — CDC explicitly confirmed the identity does NOT exist (404.1).
// Without this gate, a fake persona could pass through the self-scoring
// fallback because "score=null" is indistinguishable from "thin file" or
// "CDC outage". Customer report 2026-04-23: "entered false information and
// they were accepted". Must reject BEFORE any scoring logic runs.
if ($person_found === false) {
    $result = [
        'status'     => 'NO_VIABLE',
        'pti_total'  => round($pti_total, 4),
        'reasons'    => ['IDENTIDAD_NO_ENCONTRADA_EN_CDC'],
        'mensaje'    => 'La persona no aparece en el Buró de Crédito. No es posible otorgar crédito a identidades que no se pueden verificar.',
    ];
} elseif ($score === null) {
    // POLICY C (customer brief 2026-04-26): Option B's blanket NO_VIABLE
    // for null-score applicants killed conversions. Voltika's true risk
    // exposure is small when enganche is high enough — Voltika collects
    // upfront and retains repossession rights on the asset. Combined with
    // mandatory Truora identity verification (INE + biometric + RENAPO)
    // downstream, fraud risk is contained.
    //
    // Threshold: 50% enganche + 12-month max plazo. Anything below the
    // threshold still NO_VIABLE, but we tell the user EXACTLY what to do
    // (raise enganche to 50%) rather than leaving them at a dead end.
    $highEngancheThreshold = 0.50;
    $highEngPlazoMax       = 12;
    if ($enganche_pct >= $highEngancheThreshold) {
        $result = [
            'status'                 => 'CONDICIONAL_ESTIMADO',
            'pti_total'              => round($pti_total, 4),
            'enganche_min'           => $highEngancheThreshold,
            'enganche_requerido_min' => $highEngancheThreshold,
            'plazo_max_meses'        => $highEngPlazoMax,
            'reasons'                => ['SIN_SCORE_APROBADO_POR_ENGANCHE_ALTO'],
            'mensaje'                => 'Aprobación condicional: por cumplir con un enganche elevado tu solicitud avanza. La verificación de identidad es obligatoria.',
            'person_found'           => $person_found,
        ];
    } else {
        $mensaje = 'No obtuvimos tu historial crediticio. Para continuar, sube tu enganche al ' . round($highEngancheThreshold * 100) . '% — esto reduce el riesgo y permite avanzar sin score.';
        if ($person_found === true) {
            $mensaje .= ' También puedes contactar a un asesor: ventas@voltika.com.mx';
        }
        $result = [
            'status'                       => 'NO_VIABLE',
            'pti_total'                    => round($pti_total, 4),
            'reasons'                      => ['SIN_SCORE_RECOMIENDA_AUMENTAR_ENGANCHE'],
            'mensaje'                      => $mensaje,
            'enganche_min_para_continuar'  => $highEngancheThreshold,
            'plazo_max_para_continuar'     => $highEngPlazoMax,
            'person_found'                 => $person_found,
        ];
    }
} else {
    // Con datos completos de Círculo
    // 1. KO reales
    if ($score < $V3['KO']['scoreMin']) {
        // Customer brief 2026-04-26 v2: REJECTED is REJECTED. Removed the
        // 60%-enganche escape from earlier Policy C — low score now goes
        // straight to NO_VIABLE and the user is routed to alternative
        // payment options (contado / MSI) on the credito-pago screen.
        $result = [
            'status'    => 'NO_VIABLE',
            'pti_total' => round($pti_total, 4),
            'enganche_min' => $eng_min,
            'reasons'   => ['KO_SCORE_LT_MIN'],
            'mensaje'   => 'Tu score crediticio actual no permite aprobación. Puedes pagar al contado o con 9 MSI sin intereses.',
        ];
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
    // 4. CONDICIONAL — customer brief 2026-04-26 v2: simplified to a
    // single hard threshold (50% enganche, 12-month plazo). Replaces the
    // PTI-tiered downPaymentRequiredByPTI table which produced confusing
    // variable values (25/30/35/45%) and inconsistent plazo caps. The
    // single 50%/12 rule keeps Voltika's risk uniformly low across all
    // CONDICIONAL applicants and matches the messaging on the unified
    // credito-pago screen.
    else {
        $result  = ['status' => 'CONDICIONAL', 'pti_total' => round($pti_total, 4),
                    'enganche_min' => $eng_min, 'enganche_requerido_min' => 0.50,
                    'plazo_max_meses' => 12];
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

// ── Guardar en BD (customer info + decision for admin lead tracking) ────────
try {
    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS preaprobaciones (
        id                   INT AUTO_INCREMENT PRIMARY KEY,
        nombre               VARCHAR(200),
        apellido_paterno     VARCHAR(100),
        apellido_materno     VARCHAR(100),
        email                VARCHAR(200),
        telefono             VARCHAR(30),
        fecha_nacimiento     VARCHAR(20),
        cp                   VARCHAR(10),
        ciudad               VARCHAR(100),
        estado               VARCHAR(50),
        modelo               VARCHAR(200),
        precio_contado       DECIMAL(12,2),
        ingreso_mensual      DECIMAL(12,2),
        pago_semanal         DECIMAL(10,2),
        pago_mensual         DECIMAL(10,2),
        pago_mensual_buro    DECIMAL(12,2),
        pti_total            DECIMAL(8,4),
        score                INT,
        synth_score          INT,
        dpd90_flag           TINYINT(1),
        dpd_max              INT,
        circulo_source       VARCHAR(20),
        enganche_pct         DECIMAL(5,2),
        plazo_meses          INT,
        status               VARCHAR(40),
        enganche_requerido   DECIMAL(5,2),
        plazo_max            INT,
        truora_ok            TINYINT(1),
        seguimiento          VARCHAR(40) DEFAULT 'nuevo',
        notas_admin          TEXT,
        freg                 DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_seguimiento (seguimiento),
        INDEX idx_email (email),
        INDEX idx_freg (freg)
    )");
    // Idempotent: add new columns if old schema exists
    $existing = [];
    try { foreach ($pdo->query("SHOW COLUMNS FROM preaprobaciones") as $c) $existing[$c['Field']] = true; } catch (Throwable $e) {}
    $newCols = [
        'nombre' => 'VARCHAR(200) NULL', 'apellido_paterno' => 'VARCHAR(100) NULL',
        'apellido_materno' => 'VARCHAR(100) NULL', 'email' => 'VARCHAR(200) NULL',
        'telefono' => 'VARCHAR(30) NULL', 'fecha_nacimiento' => 'VARCHAR(20) NULL',
        'cp' => 'VARCHAR(10) NULL', 'ciudad' => 'VARCHAR(100) NULL', 'estado' => 'VARCHAR(50) NULL',
        'precio_contado' => 'DECIMAL(12,2) NULL', 'synth_score' => 'INT NULL',
        'truora_ok' => 'TINYINT(1) NULL', 'seguimiento' => "VARCHAR(40) DEFAULT 'nuevo'",
        'notas_admin' => 'TEXT NULL',
    ];
    foreach ($newCols as $col => $def) {
        if (!isset($existing[$col])) {
            try { $pdo->exec("ALTER TABLE preaprobaciones ADD COLUMN $col $def"); } catch (Throwable $e) {}
        }
    }

    // Server-side dedup (customer brief 2026-04-25): if the same applicant
    // (email + telefono + modelo + status) was already logged in the last
    // 5 minutes, treat this as an accidental re-submission and skip the
    // INSERT. Client-side guard in paso-credito-consentimiento.js is the
    // primary defense; this is a safety net for any future caller that
    // bypasses the client guard. The decision result is still returned to
    // the caller unchanged.
    $skipInsert = false;
    if ($email !== '' || $telefono !== '') {
        try {
            $check = $pdo->prepare("
                SELECT id FROM preaprobaciones
                 WHERE email = ? AND telefono = ? AND modelo = ? AND status = ?
                   AND freg >= (NOW() - INTERVAL 5 MINUTE)
                 LIMIT 1
            ");
            $check->execute([$email, $telefono, $log['modelo'], $log['status']]);
            if ($check->fetchColumn()) {
                $skipInsert = true;
                error_log('Voltika preaprobacion dedup: skipped duplicate for ' . $email . ' / ' . $telefono);
            }
        } catch (Throwable $e) { /* if the dedup query fails, fall through to INSERT */ }
    }

    if (!$skipInsert) {
        $stmt = $pdo->prepare("
            INSERT INTO preaprobaciones
                (nombre, apellido_paterno, apellido_materno, email, telefono,
                 fecha_nacimiento, cp, ciudad, estado,
                 modelo, precio_contado, ingreso_mensual, pago_semanal, pago_mensual,
                 pago_mensual_buro, pti_total, score, synth_score, dpd90_flag, dpd_max,
                 circulo_source, enganche_pct, plazo_meses, status,
                 enganche_requerido, plazo_max, truora_ok)
            VALUES (?,?,?,?,?, ?,?,?,?, ?,?,?,?,?, ?,?,?,?,?,?, ?,?,?,?, ?,?,?)
        ");
        $stmt->execute([
            $nombre, $apellidoPaterno, $apellidoMaterno, $email, $telefono,
            $fechaNacimiento, $cp, $ciudadCust, $estadoCust,
            $log['modelo'], $precio_contado, $log['ingreso_mensual_est'], $log['pago_semanal_voltika'], $log['pago_mensual_voltika'],
            $log['pago_mensual_buro'], $log['pti_total'], $log['score'], ($result['synth_score'] ?? null),
            $dpd90_flag ? 1 : 0, $dpd_max,
            $log['circulo_source'], $log['enganche_pct'], $log['plazo_meses'], $log['status'],
            $log['enganche_requerido'], $log['plazo_max'], $truoraOk ? 1 : 0,
        ]);
    }
} catch (PDOException $e) {
    error_log('Voltika preaprobaciones DB error: ' . $e->getMessage());
}

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

/**
 * Calculate age from a YYYY-MM-DD birthdate. Returns null if invalid.
 */
function vkCalcEdad(string $fechaNac): ?int {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fechaNac, $m)) return null;
    try {
        $birth = new DateTimeImmutable($fechaNac);
        $now   = new DateTimeImmutable('today');
        return (int)$now->diff($birth)->y;
    } catch (Throwable $e) { return null; }
}

/**
 * Check if email belongs to a customer with prior successful Stripe payments.
 * Returns true only if at least 1 completed transaction exists.
 */
function vkIsRepeatCustomer(string $email): bool {
    if ($email === '') return false;
    try {
        $pdo = getDB();
        // transacciones table may have different schema variants — try common ones
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transacciones
            WHERE LOWER(email) = ?
              AND (estado = 'completed' OR estado = 'paid' OR estado = 'pagado')");
        $stmt->execute([$email]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        // Table may not exist or column may be named differently — silently
        // return false (no boost) instead of throwing.
        return false;
    }
}

/**
 * Build a synthetic credit score (range 300-850 simulating CDC FICO range)
 * from the signals available without a credit bureau call.
 *
 * Baseline 500. Adjustments are conservative — designed to mirror real
 * FICO distribution where most thin-file applicants land 500-650.
 */
function vkSyntheticScore(array $signals): int {
    $score = 500;

    // Age bonus — established adults (25-55) lowest historical default rate
    $edad = $signals['edad'] ?? null;
    if ($edad !== null) {
        if ($edad >= 25 && $edad <= 55) $score += 40;
        elseif ($edad >= 56 && $edad <= 65) $score += 20;
        elseif ($edad >= 18 && $edad <= 24) $score += 0;  // young = neutral, more risk
    }

    // Income trust — extremes are suspicious or risky
    $ingreso = $signals['ingreso'] ?? 0;
    if ($ingreso >= 10000 && $ingreso <= 80000) $score += 30;       // sweet spot
    elseif ($ingreso > 80000 && $ingreso <= 200000) $score += 20;   // high but plausible
    elseif ($ingreso > 200000) $score -= 20;                         // suspicious / unverifiable
    elseif ($ingreso < 5000) $score -= 30;                           // too low for our products

    // PTI (Pago-To-Income) — most predictive single signal
    $pti = $signals['pti'] ?? 1;
    if ($pti <= 0.20)      $score += 80;
    elseif ($pti <= 0.30)  $score += 50;
    elseif ($pti <= 0.50)  $score += 20;
    elseif ($pti <= 0.75)  $score += 0;
    elseif ($pti <= 0.90)  $score -= 20;
    else                   $score -= 40;

    // Enganche — skin in the game
    $eng = $signals['enganche_pct'] ?? 0.30;
    if ($eng >= 0.50)       $score += 60;
    elseif ($eng >= 0.40)   $score += 40;
    elseif ($eng >= 0.30)   $score += 20;
    elseif ($eng < 0.20)    $score -= 30;

    // Identity verification (Truora) — bonus if passed, small penalty if not
    // (admin will verify manually before final approval if Truora is down)
    if (!empty($signals['truora_ok'])) $score += 30;
    else                                $score -= 20;

    // Repeat customer — strongest positive signal we have
    if (!empty($signals['es_repeticion'])) $score += 80;

    // Clamp to FICO range
    return max(300, min(850, $score));
}
