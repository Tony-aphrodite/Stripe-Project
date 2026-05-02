<?php
/**
 * Voltika — preaprobacion-v3.php fix verification.
 *
 * Customer brief 2026-05-01: validate the two new branches added to V3:
 *   FIX 1 — CDC responded without score      → CONDICIONAL_ESTIMADO 40%/18
 *   FIX 2 — Low score + excellent PTI        → CONDICIONAL 55%/12
 *
 * Tests against the 4 reference cases supplied by the customer:
 *   1. Gabriela Luviano  — score=null, PTI ~26%, person_found=true   (FIX 1)
 *   2. Ulises Oro        — score=null, PTI ~10%, person_found=true   (FIX 1)
 *   3. Oscar Limón       — score=409, PTI 6%                         (FIX 2)
 *   4. Kevin/Sandra      — score=373                                 (KO)
 *
 * Calls our own endpoint (live preaprobacion-v3.php) and inspects the
 * JSON response. Test rows are tagged `diag+...@voltika.mx` and cleaned
 * up automatically at the end.
 *
 * Usage:
 *   ?token=voltika_diag_2026
 *
 * Delete this file via FileZilla after diagnosis.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

ini_set('max_execution_time', '60');
header('Content-Type: text/plain; charset=utf-8');

if (($_GET['token'] ?? '') !== 'voltika_diag_2026') {
    http_response_code(403);
    echo "invalid token\n";
    exit;
}

echo "================================================================\n";
echo "  Voltika preaprobacion-v3 fix verification\n";
echo "================================================================\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

$selfBase = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'voltika.mx') . dirname($_SERVER['REQUEST_URI'] ?? '/configurador/php/');
$selfBase = preg_replace('#/+$#', '', $selfBase);
$endpoint = $selfBase . '/preaprobacion-v3.php';

// ── Test cases ─────────────────────────────────────────────────────────────
// Each scenario sends the inputs preaprobacion-v3.php would receive in the
// real flow — including score / pago_mensual_buro / dpd flags / person_found.
$diagSuffix = date('Ymd-His');
$tests = [
    [
        'label'    => 'Case 1: Gabriela Luviano (null score, PTI ~26%)',
        'fix'      => 'FIX 1',
        'expect'   => [
            'status'                 => 'CONDICIONAL_ESTIMADO',
            'enganche_requerido_min' => 0.40,
            'plazo_max_meses'        => 18,
            'circulo_source'         => 'cdc_sin_score',
        ],
        'payload' => [
            'nombre'              => 'DIAG Gabriela',
            'apellido_paterno'    => 'Luviano',
            'apellido_materno'    => 'Test',
            'email'               => "diag+gabriela-$diagSuffix@voltika.mx",
            'telefono'            => '5500000001',
            'modelo'              => 'M05',
            'precio_contado'      => 48260,
            'ingreso_mensual_est' => 15000,
            'pago_semanal_voltika'=> 900,        // ~26% PTI
            'enganche_pct'        => 0.30,
            'plazo_meses'         => 24,
            'score'               => null,
            'pago_mensual_buro'   => 0,
            'dpd90_flag'          => false,
            'dpd_max'             => 0,
            'person_found'        => true,
        ],
    ],
    [
        'label'    => 'Case 2: Ulises Oro (null score, PTI ~10%)',
        'fix'      => 'FIX 1',
        'expect'   => [
            'status'                 => 'CONDICIONAL_ESTIMADO',
            'enganche_requerido_min' => 0.40,
            'plazo_max_meses'        => 18,
            'circulo_source'         => 'cdc_sin_score',
        ],
        'payload' => [
            'nombre'              => 'DIAG Ulises',
            'apellido_paterno'    => 'Oro',
            'apellido_materno'    => 'Test',
            'email'               => "diag+ulises-$diagSuffix@voltika.mx",
            'telefono'            => '5500000002',
            'modelo'              => 'M05',
            'precio_contado'      => 48260,
            'ingreso_mensual_est' => 30000,
            'pago_semanal_voltika'=> 700,        // ~10% PTI
            'enganche_pct'        => 0.30,
            'plazo_meses'         => 24,
            'score'               => null,
            'pago_mensual_buro'   => 0,
            'dpd90_flag'          => false,
            'dpd_max'             => 0,
            'person_found'        => true,
        ],
    ],
    [
        'label'    => 'Case 3: Oscar Limón (score=409, PTI 6%)',
        'fix'      => 'FIX 2',
        'expect'   => [
            'status'                 => 'CONDICIONAL',
            'enganche_requerido_min' => 0.55,
            'plazo_max_meses'        => 12,
            'circulo_source'         => 'score_bajo_pti_excelente',
        ],
        'payload' => [
            'nombre'              => 'DIAG Oscar',
            'apellido_paterno'    => 'Limon',
            'apellido_materno'    => 'Test',
            'email'               => "diag+oscar-$diagSuffix@voltika.mx",
            'telefono'            => '5500000003',
            'modelo'              => 'M05',
            'precio_contado'      => 48260,
            'ingreso_mensual_est' => 40001,
            'pago_semanal_voltika'=> 554,        // 554*4.33/40001 ≈ 6% PTI
            'enganche_pct'        => 0.30,
            'plazo_meses'         => 36,
            'score'               => 409,
            'pago_mensual_buro'   => 0,
            'dpd90_flag'          => false,
            'dpd_max'             => 0,
            'person_found'        => true,
        ],
    ],
    [
        'label'    => 'Case 4: Kevin/Sandra (score=373)',
        'fix'      => 'KO check',
        'expect'   => [
            'status' => 'NO_VIABLE',
            'reason_contains' => 'KO_SCORE_LT_MIN',
        ],
        'payload' => [
            'nombre'              => 'DIAG Kevin',
            'apellido_paterno'    => 'Sandra',
            'apellido_materno'    => 'Test',
            'email'               => "diag+kevin-$diagSuffix@voltika.mx",
            'telefono'            => '5500000004',
            'modelo'              => 'M05',
            'precio_contado'      => 48260,
            'ingreso_mensual_est' => 15000,
            'pago_semanal_voltika'=> 600,
            'enganche_pct'        => 0.30,
            'plazo_meses'         => 24,
            'score'               => 373,
            'pago_mensual_buro'   => 0,
            'dpd90_flag'          => false,
            'dpd_max'             => 0,
            'person_found'        => true,
        ],
    ],
];

$pass = 0; $fail = 0;
foreach ($tests as $t) {
    echo "──────────────────────────────────────────────────────────────\n";
    echo $t['label'] . " [" . $t['fix'] . "]\n";

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($t['payload']),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        echo "  CURL ERROR: $err\n";
        $fail++; continue;
    }
    if ($code !== 200) {
        echo "  HTTP $code\n  body: " . substr($resp, 0, 300) . "\n";
        $fail++; continue;
    }

    $j = json_decode((string)$resp, true);
    if (!is_array($j)) {
        echo "  invalid JSON: " . substr($resp, 0, 300) . "\n";
        $fail++; continue;
    }

    // Print received
    printf("  status                 : %s\n", $j['status'] ?? '?');
    if (isset($j['enganche_requerido_min'])) printf("  enganche_requerido_min : %s\n", $j['enganche_requerido_min']);
    if (isset($j['plazo_max_meses']))        printf("  plazo_max_meses        : %s\n", $j['plazo_max_meses']);
    if (isset($j['circulo_source']))         printf("  circulo_source         : %s\n", $j['circulo_source']);
    if (!empty($j['reasons']))                printf("  reasons                : %s\n", implode(',', (array)$j['reasons']));
    if (!empty($j['mensaje']))                printf("  mensaje                : %s\n", substr($j['mensaje'], 0, 100));

    // Compare expected vs actual
    $ok = true;
    foreach ($t['expect'] as $k => $v) {
        if ($k === 'reason_contains') {
            $found = !empty($j['reasons']) && in_array($v, (array)$j['reasons'], true);
            if (!$found) { $ok = false; echo "  ✗ expected reason contains '$v' — got: " . implode(',', (array)($j['reasons'] ?? [])) . "\n"; }
        } else {
            $actual = $j[$k] ?? null;
            // Tolerant numeric comparison
            if (is_numeric($v) && is_numeric($actual)) {
                if (abs((float)$actual - (float)$v) > 0.0001) { $ok = false; echo "  ✗ $k expected=$v actual=$actual\n"; }
            } else {
                if ((string)$actual !== (string)$v) { $ok = false; echo "  ✗ $k expected=$v actual=" . (string)$actual . "\n"; }
            }
        }
    }
    if ($ok) {
        echo "  ┌──────────┐\n";
        echo "  │  PASS ✓  │\n";
        echo "  └──────────┘\n";
        $pass++;
    } else {
        echo "  ┌──────────┐\n";
        echo "  │  FAIL ✗  │\n";
        echo "  └──────────┘\n";
        $fail++;
    }
}

echo "\n==================================================================\n";
echo "Summary: $pass passed, $fail failed (of " . count($tests) . " tests)\n";
echo "==================================================================\n\n";

// ── Cleanup test rows ──────────────────────────────────────────────────────
echo "Cleanup:\n";
try {
    $pdo = getDB();
    $del = $pdo->prepare("DELETE FROM preaprobaciones WHERE email LIKE 'diag+%@voltika.mx'");
    $del->execute();
    echo "  removed " . $del->rowCount() . " diag rows from preaprobaciones\n";
} catch (Throwable $e) {
    echo "  cleanup error: " . $e->getMessage() . "\n";
}

echo "\nDELETE this file (diag-preaprobacion-fixes.php) via FileZilla after use.\n";
