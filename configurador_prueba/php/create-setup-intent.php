<?php
/**
 * Voltika Configurador - Crear SetupIntent en Stripe
 * For recurring payment card registration (no charge now)
 * Receives customer info, creates SetupIntent, returns clientSecret
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

// ── Stripe SDK ────────────────────────────────────────────────────────────────
$stripePhpPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($stripePhpPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Stripe SDK no encontrado. Ejecuta: cd php && composer install']);
    exit;
}
require_once $stripePhpPath;

if (!STRIPE_SECRET_KEY || STRIPE_SECRET_KEY === 'sk_test_PLACEHOLDER') {
    http_response_code(500);
    echo json_encode(['error' => 'Stripe secret key no configurada en .env']);
    exit;
}

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// ── Read request ──────────────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);
$nombre   = isset($input['nombre'])   ? trim($input['nombre'])   : '';
$email    = isset($input['email'])    ? trim($input['email'])    : '';
$telefono = isset($input['telefono']) ? preg_replace('/\D/', '', trim($input['telefono'])) : '';
// Normalize: strip leading 52 country code if present
if (strlen($telefono) > 10 && substr($telefono, 0, 2) === '52') $telefono = substr($telefono, 2);

// Product context — persisted on the pending subscripciones_credito row so
// the admin dashboard doesn't see orphans with modelo/color = "-".
$modelo        = isset($input['modelo'])        ? trim($input['modelo']) : '';
$color         = isset($input['color'])         ? trim($input['color'])  : '';
$precioContado = isset($input['precioContado']) ? floatval($input['precioContado']) : 0;
$plazoMeses    = isset($input['plazoMeses'])    ? intval($input['plazoMeses']) : 0;
$montoSemanal  = isset($input['montoSemanal'])  ? floatval($input['montoSemanal']) : 0;

try {
    // Create or find Stripe Customer
    $customerParams = [
        'name'  => $nombre,
        'phone' => $telefono ? '+52' . $telefono : null,
        'metadata' => [
            'source' => 'voltika_configurador',
            'tipo'   => 'autopago_semanal'
        ]
    ];
    if ($email) {
        $customerParams['email'] = $email;
    }

    $customer = \Stripe\Customer::create($customerParams);

    // Create SetupIntent (saves card for future charges, no charge now)
    // Stripe metadata doubles as recovery source: Plan G's enriquecer-vksc.php
    // reads these fields back when a subscripciones_credito row has empty
    // modelo/color/precio (e.g. legacy rows created before this backfill).
    $setupIntent = \Stripe\SetupIntent::create([
        'customer'             => $customer->id,
        'payment_method_types' => ['card'],
        'usage'                => 'off_session',  // For recurring charges
        'metadata'             => [
            'nombre'         => $nombre,
            'email'          => $email,
            'telefono'       => $telefono,
            'tipo'           => 'autopago_credito_voltika',
            'modelo'         => $modelo,
            'color'          => $color,
            'precio_contado' => (string)$precioContado,
            'plazo_meses'    => (string)$plazoMeses,
            'monto_semanal'  => (string)$montoSemanal,
        ]
    ]);

    // Persist a pending subscription row so we can track abandonment.
    // Confirmed → 'active' from confirmar-autopago.php after Stripe accepts the card.
    saveSubscripcionPending([
        'nombre'           => $nombre,
        'email'            => $email,
        'telefono'         => $telefono,
        'stripe_customer'  => $customer->id,
        'stripe_setup_id'  => $setupIntent->id,
        'modelo'           => $modelo,
        'color'            => $color,
        'precio_contado'   => $precioContado,
        'plazo_meses'      => $plazoMeses,
        'monto_semanal'    => $montoSemanal,
    ]);

    echo json_encode([
        'ok'           => true,
        'clientSecret' => $setupIntent->client_secret,
        'customerId'   => $customer->id,
        'setupIntentId'=> $setupIntent->id
    ]);

} catch (\Stripe\Exception\ApiErrorException $e) {
    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Error interno: ' . $e->getMessage()
    ]);
}

/**
 * Create the subscripciones_credito table if missing and insert a 'pending' row.
 * The row is updated to 'active' once the customer's card is confirmed in
 * confirmar-autopago.php. Idempotent on stripe_setup_intent_id.
 */
function saveSubscripcionPending(array $data): void {
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS subscripciones_credito (
            id                       INT AUTO_INCREMENT PRIMARY KEY,
            nombre                   VARCHAR(200),
            email                    VARCHAR(200),
            telefono                 VARCHAR(30),
            stripe_customer_id       VARCHAR(100),
            stripe_setup_intent_id   VARCHAR(100) UNIQUE,
            stripe_payment_method_id VARCHAR(100) NULL,
            estado                   VARCHAR(30) DEFAULT 'pendiente',
            monto_semanal            DECIMAL(12,2) NULL,
            inventario_moto_id       INT NULL,
            freg                     DATETIME DEFAULT CURRENT_TIMESTAMP,
            factivacion              DATETIME NULL,
            INDEX idx_email    (email),
            INDEX idx_estado   (estado),
            INDEX idx_customer (stripe_customer_id)
        )");
        // Backfill optional columns on legacy tables
        $existing = $pdo->query("SHOW COLUMNS FROM subscripciones_credito")->fetchAll(PDO::FETCH_COLUMN);
        $ensure = [
            'modelo'         => "ALTER TABLE subscripciones_credito ADD COLUMN modelo VARCHAR(200) NULL",
            'color'          => "ALTER TABLE subscripciones_credito ADD COLUMN color VARCHAR(50) NULL",
            'precio_contado' => "ALTER TABLE subscripciones_credito ADD COLUMN precio_contado DECIMAL(12,2) NULL",
            'plazo_meses'    => "ALTER TABLE subscripciones_credito ADD COLUMN plazo_meses INT NULL",
        ];
        foreach ($ensure as $col => $sql) {
            if (!in_array($col, $existing, true)) {
                try { $pdo->exec($sql); } catch (Throwable $e) { /* noop */ }
            }
        }
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO subscripciones_credito
                (nombre, email, telefono, stripe_customer_id, stripe_setup_intent_id, estado,
                 modelo, color, precio_contado, plazo_meses, monto_semanal)
            VALUES (?, ?, ?, ?, ?, 'pendiente',
                    ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['nombre'] ?: '',
            $data['email']  ?: '',
            $data['telefono'] ?: '',
            $data['stripe_customer'],
            $data['stripe_setup_id'],
            $data['modelo']         ?: '',
            $data['color']          ?: '',
            $data['precio_contado'] ?: 0,
            $data['plazo_meses']    ?: 0,
            $data['monto_semanal']  ?: 0,
        ]);
    } catch (PDOException $e) {
        error_log('Voltika subscripciones_credito pending error: ' . $e->getMessage());
    }
}
