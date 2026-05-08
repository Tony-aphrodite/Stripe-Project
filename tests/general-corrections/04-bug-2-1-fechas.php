<?php
/**
 * Test 04 — Bug 2.1: ETA >= fecha_envio validation logic
 *
 * Reproduce la regla server-side de cambiar-estado.php:
 * - Si llegan AMBAS y eta < fenv → reject.
 * - Si solo llega una, comparar contra el valor existente.
 */

function bug21_validate(string $etaIn, string $fenvIn, ?string $existingEta = null, ?string $existingFenv = null): array {
    if ($etaIn !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $etaIn))   return ['ok'=>false,'reason'=>'eta_format'];
    if ($fenvIn !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fenvIn)) return ['ok'=>false,'reason'=>'fenv_format'];

    if ($etaIn !== '' && $fenvIn !== '' && $etaIn < $fenvIn) {
        return ['ok'=>false, 'reason'=>'eta_lt_fenv'];
    }
    $effectiveEta  = $etaIn  !== '' ? $etaIn  : ($existingEta  ?? '');
    $effectiveFenv = $fenvIn !== '' ? $fenvIn : ($existingFenv ?? '');
    if ($effectiveEta && $effectiveFenv && $effectiveEta < $effectiveFenv) {
        return ['ok'=>false, 'reason'=>'effective_eta_lt_fenv'];
    }
    return ['ok'=>true];
}

$cases = [
    // [eta, fenv, existingEta, existingFenv, expected_ok, label]
    ['2026-05-15', '2026-05-10', null, null, true,  'eta after fenv'],
    ['2026-05-10', '2026-05-15', null, null, false, 'eta before fenv'],
    ['2026-05-10', '2026-05-10', null, null, true,  'same day'],
    ['', '',                     null, null, true,  'both empty'],
    ['2026-05-15', '',           null, '2026-05-20', false, 'eta < existing fenv'],
    ['2026-05-25', '',           null, '2026-05-20', true,  'eta > existing fenv'],
    ['', '2026-05-15',           '2026-05-10', null, false, 'fenv > existing eta'],
    ['', '2026-05-05',           '2026-05-10', null, true,  'fenv < existing eta — allowed'],
    ['INVALID', '2026-05-10',    null, null, false, 'invalid eta format'],
];

$fail = 0;
foreach ($cases as $c) {
    [$eta, $fenv, $exEta, $exFenv, $exp, $label] = $c;
    $r = bug21_validate($eta, $fenv, $exEta, $exFenv);
    $got = $r['ok'];
    if ($got === $exp) {
        echo "  [PASS] $label\n";
    } else {
        echo "  [FAIL] $label — expected " . ($exp?'ok':'reject') . " got " . ($got?'ok':'reject') . " (reason=" . ($r['reason'] ?? '-') . ")\n";
        $fail++;
    }
}

if ($fail === 0) {
    echo "\n  ✓ Todos los casos de validación de fechas pasan.\n";
    exit(0);
}
echo "\n  ✗ {$fail} caso(s) fallaron.\n";
exit(1);
