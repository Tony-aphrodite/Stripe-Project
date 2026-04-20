<?php
/**
 * POST — Wipe all operational data from the live panel.
 *
 * DANGEROUS: This irreversibly TRUNCATEs every table listed in $WIPE_TABLES.
 * It does NOT touch: usuarios (admin accounts), app_config, schema, or any
 * Stripe objects (customers, subscriptions, payment_intents). Use the Stripe
 * Dashboard to reset Stripe-side state separately.
 *
 * Safety gates (all required):
 *  - Admin role (adminRequireAuth(['admin']))
 *  - POST body must contain {"confirm":"BORRAR TODO"} — exact phrase
 *  - Logs who/when/what to logs/reset-live.log
 *
 * Returns {ok:true, wiped:[table=>rows_before, ...]} on success.
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$confirm = trim((string)($in['confirm'] ?? ''));
if ($confirm !== 'BORRAR TODO') {
    adminJsonOut([
        'error' => 'Confirmación inválida. Envía {"confirm":"BORRAR TODO"} para proceder.',
        'code'  => 'CONFIRM_REQUIRED',
    ], 400);
}

$pdo = getDB();

// Tables to wipe. Order matters only for FK-aware engines; TRUNCATE with FK
// checks disabled bypasses that, but we keep a sensible order anyway (leaves
// first, roots last).
$WIPE_TABLES = [
    // Portal / session state
    'portal_auth_log',
    'portal_sesiones',
    'portal_otp',
    'portal_descargas_log',
    'portal_recordatorios_log',
    'portal_preferencias',
    // Ops / logistics
    'actas_entrega',
    'incidencias_entrega',
    'envios',
    'envios_eventos',
    'inventario_checklists',
    'checklist_origen',
    'checklist_punto',
    'inventario_motos',
    // Financial cycle tracking
    'ciclos_pago',
    'transacciones',
    'subscripciones_credito',
    // Customers
    'clientes',
    // Commercial network (safe to re-import)
    'punto_comisiones',
    'puntos_voltika',
];

$logDir = __DIR__ . '/../../../configurador_prueba_test/php/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$logFile = $logDir . '/reset-live.log';

$result = [];
$errors = [];

try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
} catch (Throwable $e) {}

foreach ($WIPE_TABLES as $t) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $t);
    if ($safe !== $t) { $errors[] = "$t: nombre de tabla inválido"; continue; }

    // Count current rows (pre-truncate snapshot for the report)
    $count = null;
    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM `$safe`")->fetchColumn();
    } catch (Throwable $e) {
        // Table doesn't exist — skip silently
        continue;
    }

    try {
        $pdo->exec("TRUNCATE TABLE `$safe`");
        $result[$safe] = $count;
    } catch (Throwable $e) {
        // Fallback to DELETE if TRUNCATE fails (e.g. FK constraint elsewhere)
        try {
            $pdo->exec("DELETE FROM `$safe`");
            // Reset AUTO_INCREMENT too
            try { $pdo->exec("ALTER TABLE `$safe` AUTO_INCREMENT = 1"); } catch (Throwable $e2) {}
            $result[$safe] = $count;
        } catch (Throwable $e2) {
            $errors[$safe] = $e2->getMessage();
        }
    }
}

try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
} catch (Throwable $e) {}

@file_put_contents($logFile, json_encode([
    'ts'      => date('c'),
    'admin'   => $uid,
    'ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
    'wiped'   => $result,
    'errors'  => $errors,
]) . "\n", FILE_APPEND | LOCK_EX);

adminJsonOut([
    'ok'     => true,
    'wiped'  => $result,
    'errors' => $errors,
    'note'   => 'Admin users, app_config y datos de Stripe NO fueron afectados.',
]);
