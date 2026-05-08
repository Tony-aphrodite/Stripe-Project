<?php
/**
 * Test 03 — Bug 1.1: Engine number validation logic
 *
 * Verifica la lógica de comparación num_motor (case-insensitive, ignora
 * espacios y guiones) sin tocar la DB real. Reproduce la rama del
 * guardar-origen.php que decide si bloquear o permitir.
 */

function bug11_compareEngine(string $oficial, string $typed): array {
    $o = strtoupper(preg_replace('/[\s\-]/', '', (string)$oficial));
    $t = strtoupper(preg_replace('/[\s\-]/', '', (string)$typed));
    if ($o === '' && $t !== '') {
        // Backward-compat: oficial vacío → permitir (no bloquea flujos legacy).
        return ['ok' => true, 'reason' => 'oficial_empty_skip'];
    }
    if ($o === $t) return ['ok' => true, 'reason' => 'match'];
    return ['ok' => false, 'reason' => 'mismatch'];
}

$cases = [
    // [oficial, typed, expected_ok, label]
    ['ABC123',         'ABC123',          true,  'exact match'],
    ['ABC123',         'abc123',          true,  'case-insensitive'],
    ['ABC-123',        'ABC123',          true,  'dash stripped'],
    ['ABC 123',        'ABC123',          true,  'space stripped'],
    ['  ABC123  ',     'ABC123',          true,  'whitespace tolerated'],
    ['ABC123',         'XYZ999',          false, 'real mismatch'],
    ['',               'ABC123',          true,  'oficial empty (legacy moto)'],
    ['ABC123',         '',                false, 'typed empty when oficial set'],
    ['VK-ENG-001',     'vk eng 001',      true,  'multi-format equivalence'],
];

$fail = 0;
foreach ($cases as $c) {
    [$o, $t, $exp, $label] = $c;
    $r = bug11_compareEngine($o, $t);
    $got = $r['ok'];
    if ($got === $exp) {
        echo "  [PASS] $label  ($o vs $t → " . ($got ? 'ok' : 'block') . ")\n";
    } else {
        echo "  [FAIL] $label  expected " . ($exp?'ok':'block') . " got " . ($got?'ok':'block') . " ($o vs $t)\n";
        $fail++;
    }
}

if ($fail === 0) {
    echo "\n  ✓ Todos los casos de comparación num_motor pasan.\n";
    exit(0);
}
echo "\n  ✗ {$fail} caso(s) fallaron.\n";
exit(1);
