<?php
/**
 * Voltika — Confirmar autopago
 *
 * Called from paso-credito-autopago.js after Stripe.confirmCardSetup() succeeds.
 * Marks the pending subscripciones_credito row as 'active' and stores the
 * payment_method_id so collections can charge the card on the weekly cycle.
 *
 * POST body (JSON):
 *   setupIntentId  — Stripe setup intent id (required)
 *   customerId     — Stripe customer id (required)
 *   paymentMethodId — optional, the pm_xxx returned by Stripe.js after confirm
 *   montoSemanal   — optional, weekly payment amount (decimal)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

$json = json_decode(file_get_contents('php://input'), true);
if (!is_array($json)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Request inválido']);
    exit;
}

$setupIntentId   = trim($json['setupIntentId'] ?? '');
$customerId      = trim($json['customerId'] ?? '');
$paymentMethodId = trim($json['paymentMethodId'] ?? '');
$montoSemanal    = isset($json['montoSemanal']) ? floatval($json['montoSemanal']) : null;

if ($setupIntentId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'setupIntentId requerido']);
    exit;
}

// Skip simulated test ids — they have no real Stripe object behind them
if (strpos($setupIntentId, 'simulated_') === 0) {
    echo json_encode(['ok' => true, 'simulated' => true]);
    exit;
}

try {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        UPDATE subscripciones_credito
        SET status                   = 'active',
            stripe_payment_method_id = COALESCE(NULLIF(:pm, ''), stripe_payment_method_id),
            monto_semanal            = COALESCE(:monto, monto_semanal),
            factivacion              = NOW()
        WHERE stripe_setup_intent_id = :sid
    ");
    $stmt->execute([
        ':pm'    => $paymentMethodId,
        ':monto' => $montoSemanal,
        ':sid'   => $setupIntentId,
    ]);

    $updated = $stmt->rowCount();

    // If the row was never created (create-setup-intent.php was bypassed),
    // create it now so the data isn't lost.
    if ($updated === 0) {
        $insert = $pdo->prepare("
            INSERT INTO subscripciones_credito
                (stripe_customer_id, stripe_setup_intent_id, stripe_payment_method_id,
                 monto_semanal, status, factivacion)
            VALUES (?, ?, ?, ?, 'active', NOW())
            ON DUPLICATE KEY UPDATE
                stripe_payment_method_id = VALUES(stripe_payment_method_id),
                monto_semanal            = VALUES(monto_semanal),
                status                   = 'active',
                factivacion              = NOW()
        ");
        $insert->execute([
            $customerId ?: null,
            $setupIntentId,
            $paymentMethodId ?: null,
            $montoSemanal,
        ]);
    }

    // Auto-create clientes row so the customer can log into the portal
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS clientes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(150) NULL,
            apellido_paterno VARCHAR(100) NULL,
            apellido_materno VARCHAR(100) NULL,
            email VARCHAR(150) NULL,
            telefono VARCHAR(30) NULL,
            rfc VARCHAR(20) NULL,
            freg DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tel (telefono),
            INDEX idx_email (email)
        )");
        $subStmt = $pdo->prepare("SELECT id, nombre, email, telefono, cliente_id FROM subscripciones_credito WHERE stripe_setup_intent_id = ? LIMIT 1");
        $subStmt->execute([$setupIntentId]);
        $subRow = $subStmt->fetch(PDO::FETCH_ASSOC);
        if ($subRow && empty($subRow['cliente_id'])) {
            $telNorm = preg_replace('/\D/', '', $subRow['telefono'] ?? '');
            if (strlen($telNorm) > 10 && substr($telNorm, 0, 2) === '52') $telNorm = substr($telNorm, 2);

            // Check if clientes row already exists
            $existingId = null;
            if ($telNorm) {
                $chk = $pdo->prepare("SELECT id FROM clientes WHERE telefono = ? OR RIGHT(REPLACE(REPLACE(telefono,'+',''),' ',''),10) = ? ORDER BY id DESC LIMIT 1");
                $chk->execute([$telNorm, substr($telNorm, -10)]);
                $existingId = (int)$chk->fetchColumn();
            }
            if (!$existingId && !empty($subRow['email'])) {
                $chk = $pdo->prepare("SELECT id FROM clientes WHERE email = ? ORDER BY id DESC LIMIT 1");
                $chk->execute([$subRow['email']]);
                $existingId = (int)$chk->fetchColumn();
            }

            if ($existingId) {
                $pdo->prepare("UPDATE subscripciones_credito SET cliente_id = ? WHERE id = ?")->execute([$existingId, $subRow['id']]);
            } else {
                $pdo->prepare("INSERT INTO clientes (telefono, email, nombre) VALUES (?, ?, ?)")
                    ->execute([$telNorm ?: null, $subRow['email'] ?: null, $subRow['nombre'] ?: null]);
                $newCid = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE subscripciones_credito SET cliente_id = ? WHERE id = ?")->execute([$newCid, $subRow['id']]);
            }
        }
    } catch (Throwable $e) { error_log('confirmar-autopago clientes sync: ' . $e->getMessage()); }

    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    error_log('Voltika confirmar-autopago error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error']);
}
