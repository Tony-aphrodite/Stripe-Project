<?php
/**
 * Test 05 — Bug 5.2: OTP must NOT go to email
 *
 * Verifica que voltika-notify.php define la lista emailSkipTipos con
 * 'otp_entrega' y que SOLO esa tipo está en la lista (no afecta a otros).
 */

$file = realpath(__DIR__ . '/../../configurador/php/voltika-notify.php');
$src  = file_get_contents($file);

$fail = 0;
function check(string $label, bool $cond) {
    global $fail;
    if ($cond) { echo "  [PASS] $label\n"; }
    else        { echo "  [FAIL] $label\n"; $fail++; }
}

check('voltika-notify.php existe', $src !== false);
check('emailSkipTipos array está definido', strpos($src, '$emailSkipTipos') !== false);
check("'otp_entrega' está en la lista", (bool) preg_match("/emailSkipTipos\s*=\s*\[\s*'otp_entrega'\s*\]/", $src));
check('hay rama elseif para email regular', strpos($src, 'elseif (!empty($data[\'email\']) && function_exists(\'sendMail\'))') !== false);
check('summary report email_skipped_reason', strpos($src, 'email_skipped_reason') !== false);

// Sanity: ningún otro tipo estandar fue agregado por error.
$otherTipos = ['compra_confirmada_contado_punto', 'moto_enviada', 'moto_recibida', 'acta_firmada'];
foreach ($otherTipos as $t) {
    $pat = "/emailSkipTipos[^;]*'{$t}'/";
    check("'{$t}' NO está en emailSkipTipos", !preg_match($pat, $src));
}

if ($fail === 0) {
    echo "\n  ✓ Bug 5.2 verificado: solo otp_entrega omite email.\n";
    exit(0);
}
echo "\n  ✗ {$fail} check(s) fallaron.\n";
exit(1);
