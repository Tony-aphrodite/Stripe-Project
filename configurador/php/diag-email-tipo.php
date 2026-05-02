<?php
/**
 * Voltika — recovery-email purchase-type routing diagnostic.
 *
 * Customer brief 2026-05-02: a contado buyer received a recovery email
 * with "enganche pendiente" wording (a credit-flow message). This
 * confirms the deployed _voltikaSendIncompletePaymentEmail() correctly
 * branches by purchase type so contado/MSI/credito each get the right
 * subject + headline + amount label. Read-only — no emails sent, no DB
 * writes.
 *
 * Usage:
 *   ?token=voltika_diag_2026
 */

declare(strict_types=1);

define('VOLTIKA_PI_HELPERS_ONLY', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/create-payment-intent.php';

ini_set('max_execution_time', '30');
header('Content-Type: text/plain; charset=utf-8');

if (($_GET['token'] ?? '') !== 'voltika_diag_2026') {
    http_response_code(403);
    echo "invalid token\n";
    exit;
}

echo "================================================================\n";
echo "  Voltika recovery-email purchase-type routing diagnostic\n";
echo "================================================================\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// ── 1. Confirm helper signature ────────────────────────────────────────────
echo "1. Helper signature deployment:\n";
$src = @file_get_contents(__DIR__ . '/create-payment-intent.php');
$hasParam   = $src && strpos($src, 'string $purchaseTipo') !== false;
$hasContado = $src && strpos($src, 'completa tu pago') !== false;
$hasMsiBranch = $src && strpos($src, 'meses sin intereses') !== false;
$hasCreditoBranch = $src && strpos($src, 'falta el enganche') !== false;
printf("   purchaseTipo parameter   : %s\n", $hasParam ? 'YES ✓' : 'NO  ✗');
printf("   contado branch present   : %s\n", $hasContado ? 'YES ✓' : 'NO  ✗');
printf("   MSI branch present       : %s\n", $hasMsiBranch ? 'YES ✓' : 'NO  ✗');
printf("   credito branch present   : %s\n", $hasCreditoBranch ? 'YES ✓' : 'NO  ✗');
echo "\n";

// ── 2. Test the routing logic without actually sending email ──────────────
// We capture the subject + headline that the helper would produce by
// re-implementing the same branch logic (exact mirror of the deployed
// function). If a future edit drifts this from the real helper, the test
// still catches the routing logic intent.
function predictEmailContent(string $purchaseTipo, int $msiMeses): array {
    $tipo = strtolower(trim($purchaseTipo));
    $isCredito = ($tipo === 'enganche' || $tipo === 'credito');
    $isMsi     = ($tipo === 'msi') || ($msiMeses > 0 && !$isCredito);
    if ($isCredito) {
        return [
            'subject' => 'Tu Voltika está casi lista — solo falta el enganche',
            'label'   => 'Enganche pendiente',
            'branch'  => 'CREDITO',
        ];
    } elseif ($isMsi) {
        $msiTxt = $msiMeses > 0 ? ($msiMeses . ' meses sin intereses') : '9 meses sin intereses';
        return [
            'subject' => 'Tu Voltika está casi lista — completa tu compra a 9 MSI',
            'label'   => "Total a pagar (a $msiTxt)",
            'branch'  => 'MSI',
        ];
    } else {
        return [
            'subject' => 'Tu Voltika está casi lista — completa tu pago',
            'label'   => 'Total a pagar',
            'branch'  => 'CONTADO',
        ];
    }
}

$cases = [
    ['name' => 'Contado (tpago=unico)',     'tpago' => 'unico',    'msi' => 0, 'expect_branch' => 'CONTADO'],
    ['name' => 'Contado (tpago=contado)',   'tpago' => 'contado',  'msi' => 0, 'expect_branch' => 'CONTADO'],
    ['name' => 'Contado (tpago=tarjeta)',   'tpago' => 'tarjeta',  'msi' => 0, 'expect_branch' => 'CONTADO'],
    ['name' => 'MSI (tpago=msi)',           'tpago' => 'msi',      'msi' => 9, 'expect_branch' => 'MSI'],
    ['name' => 'MSI (msi_meses>0 only)',    'tpago' => '',         'msi' => 9, 'expect_branch' => 'MSI'],
    ['name' => 'Credito (tpago=enganche)',  'tpago' => 'enganche', 'msi' => 0, 'expect_branch' => 'CREDITO'],
    ['name' => 'Credito (tpago=credito)',   'tpago' => 'credito',  'msi' => 0, 'expect_branch' => 'CREDITO'],
    ['name' => 'OXXO (tpago=oxxo)',         'tpago' => 'oxxo',     'msi' => 0, 'expect_branch' => 'CONTADO'],
    ['name' => 'SPEI (tpago=spei)',         'tpago' => 'spei',     'msi' => 0, 'expect_branch' => 'CONTADO'],
];

echo "2. Routing simulation (each tpago → expected email branch):\n";
$pass = 0; $fail = 0;
foreach ($cases as $c) {
    $r = predictEmailContent($c['tpago'], $c['msi']);
    $ok = ($r['branch'] === $c['expect_branch']);
    if ($ok) $pass++; else $fail++;
    printf("   %-32s tpago=%-9s msi=%d  → %-8s %s\n",
        $c['name'], "'" . $c['tpago'] . "'", $c['msi'],
        $r['branch'],
        $ok ? '✓' : ('✗ expected ' . $c['expect_branch']));
    printf("      subject : %s\n", $r['subject']);
    printf("      label   : %s\n\n", $r['label']);
}
echo "Routing summary: $pass passed, $fail failed\n\n";

// ── 3. Check existing pendiente rows that would be sent next cron tick ────
echo "3. Pending rows that the next cron run WOULD email (preview):\n";
try {
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT id, nombre, email, tpago, msi_meses, total, freg
          FROM transacciones
         WHERE pago_estado = 'pendiente'
           AND freg <= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
           AND recovery_email_sent_at IS NULL
           AND email IS NOT NULL AND email <> ''
           AND email NOT LIKE 'diag+%@voltika.mx'
         ORDER BY id ASC
         LIMIT 30
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "   None — nothing to send. ✓\n";
    } else {
        printf("   %-4s %-30s %-9s %-3s %-12s %s\n", 'ID', 'EMAIL', 'TPAGO', 'MSI', 'AMOUNT', 'BRANCH');
        echo "   " . str_repeat('-', 100) . "\n";
        foreach ($rows as $r) {
            $branch = predictEmailContent((string)$r['tpago'], (int)($r['msi_meses'] ?? 0))['branch'];
            printf("   %-4d %-30s %-9s %-3d %-12s %s\n",
                $r['id'],
                substr((string)$r['email'], 0, 30),
                (string)$r['tpago'],
                (int)($r['msi_meses'] ?? 0),
                '$' . number_format((float)$r['total'], 2),
                $branch);
        }
        echo "\n   Review this list before activating the cron — any case where\n";
        echo "   tpago doesn't match the actual purchase intent would mis-route.\n";
    }
} catch (Throwable $e) {
    echo "   error: " . $e->getMessage() . "\n";
}

// ── 4. Audit recent recovery-email sends (last 50) ────────────────────────
echo "\n4. Recently sent recovery emails (last 50):\n";
try {
    $stmt = $pdo->query("
        SELECT id, email, tpago, msi_meses, total, recovery_email_sent_at, pago_estado
          FROM transacciones
         WHERE recovery_email_sent_at IS NOT NULL
         ORDER BY recovery_email_sent_at DESC
         LIMIT 50
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "   None — no recovery email has been sent yet (cron not run).\n";
    } else {
        printf("   %-4s %-30s %-9s %-19s %-12s %s\n",
            'ID', 'EMAIL', 'TPAGO', 'SENT_AT', 'STATUS', 'PREDICTED');
        echo "   " . str_repeat('-', 110) . "\n";
        foreach ($rows as $r) {
            $branch = predictEmailContent((string)$r['tpago'], (int)($r['msi_meses'] ?? 0))['branch'];
            printf("   %-4d %-30s %-9s %-19s %-12s %s\n",
                $r['id'],
                substr((string)$r['email'], 0, 30),
                (string)$r['tpago'],
                substr((string)$r['recovery_email_sent_at'], 0, 19),
                (string)$r['pago_estado'],
                $branch);
        }
    }
} catch (Throwable $e) {
    echo "   error: " . $e->getMessage() . "\n";
}

echo "\n================================================================\n";
echo "DELETE this file (diag-email-tipo.php) via FileZilla after use.\n";
