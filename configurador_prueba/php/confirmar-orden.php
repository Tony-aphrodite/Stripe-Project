<?php
/**
 * Voltika Configurador - Confirmar orden post-pago
 * Guarda la orden en DB y envia email de confirmacion al cliente
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
require_once __DIR__ . '/master-bootstrap.php';
voltikaEnsureSchema();

// ── Request ───────────────────────────────────────────────────────────────────
$json = json_decode(file_get_contents('php://input'), true);

if (!$json) {
    http_response_code(400);
    echo json_encode(['error' => 'Request invalido']);
    exit;
}

$paymentIntentId = $json['paymentIntentId'] ?? '';
// Default to 'contado' (single payment). Legacy value 'unico' is normalized
// here for consistency with the admin panel, reports and client portal.
$pagoTipo        = $json['pagoTipo']        ?? 'contado';
if ($pagoTipo === 'unico') $pagoTipo = 'contado';
$nombre          = $json['nombre']          ?? '';
$email           = $json['email']           ?? '';
$telefono        = $json['telefono']        ?? '';
// Normalize modelo/color at the entrance: the legacy Ship.js configurador
// posts "Voltika Tromox Pesgo" / "Gris moderno" while the new one posts
// "Pesgo Plus" / "gris". Inventario_motos only stores the short codes, so
// without this step any legacy-origin order cannot be matched to stock.
$modelo          = voltikaNormalizeModelo($json['modelo'] ?? '');
$color           = voltikaNormalizeColor($json['color']  ?? '');

// ── Guard against phantom orders (customer report 2026-04-23) ────────────
// Orders VK-2604-0011/0012/0013 appeared in admin with blank
// Cliente/Modelo/Color because a payment-intent with empty metadata still
// reached this endpoint and was inserted as an empty row. Require at
// least nombre AND modelo — without both the row is meaningless downstream
// (admin can't identify customer, inventory can't match the bike). This
// fails closed so the frontend has to send real data or nothing.
$nombreTrim = trim((string)$nombre);
if ($nombreTrim === '' || $modelo === '') {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'error'  => 'datos_incompletos',
        'message'=> 'Faltan datos obligatorios (nombre y modelo).',
        'received' => [
            'nombre' => $nombreTrim !== '',
            'modelo' => $modelo     !== '',
            'color'  => $json['color'] ?? null,
        ],
    ]);
    // Log to truora_query_log-equivalent for debugging
    try {
        $pdoLog = getDB();
        @$pdoLog->exec("CREATE TABLE IF NOT EXISTS confirmar_orden_rechazos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reason VARCHAR(100),
            payload TEXT,
            freg DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdoLog->prepare("INSERT INTO confirmar_orden_rechazos (reason, payload) VALUES (?, ?)")
               ->execute(['datos_incompletos', substr(json_encode($json), 0, 4000)]);
    } catch (Throwable $e) { error_log('confirmar-orden log rechazo: ' . $e->getMessage()); }
    exit;
}
$ciudad          = $json['ciudad']          ?? '';
$estado          = $json['estado']          ?? '';
$cp              = $json['cp']              ?? '';
$total           = floatval($json['total']  ?? 0);
$msiPago         = floatval($json['msiPago'] ?? 0);
$msiMeses        = intval($json['msiMeses'] ?? 9);
$asesoriaPlacas  = !empty($json['asesoriaPlacas']) ? 'Sí' : 'No';
$seguroQualitas  = !empty($json['seguroQualitas']) ? 'Sí' : 'No';
// Persisted as int flags (Sí/No is only used for the email body below)
$asesoriaPlacasInt = !empty($json['asesoriaPlacas']) ? 1 : 0;
$seguroQualitasInt = !empty($json['seguroQualitas']) ? 1 : 0;
// Selected delivery point (set in paso3-delivery.js → state.centroEntrega)
$puntoId     = trim($json['punto_id']     ?? '');
$puntoNombre = trim($json['punto_nombre'] ?? '');
$puntoTipo   = trim($json['punto_tipo']   ?? '');
// CODIGO REFERIDO (validated in paso2-color.js via validar-referido.php)
$codigoReferido = strtoupper(trim($json['codigo_referido'] ?? ''));
$referidoId     = isset($json['referido_id']) && $json['referido_id'] !== null ? intval($json['referido_id']) : null;
$referidoTipo   = trim($json['referido_tipo'] ?? ''); // 'referido' | 'punto' | ''

// ─ Contract acceptance metadata (only meaningful for non-credit purchases:
//   contado / msi / spei / oxxo). The data feeds the Contrato Voltika
//   Contado v3 PDF generated below. Captured server-side so the values
//   can't be tampered with from the client. The optional fields (otp_*,
//   geolocation, terms_*) come from the client when the user confirmed
//   the checkout; the rest is taken from $_SERVER.
$apellidoPaterno   = trim((string)($json['apellidoPaterno'] ?? ''));
$apellidoMaterno   = trim((string)($json['apellidoMaterno'] ?? ''));
$customerCp        = trim((string)($json['cp'] ?? '')) ?: trim((string)($json['codigoPostal'] ?? ''));
$contratoOtpOk     = !empty($json['otpValidated']) ? 1 : 0;
$contratoTermsAt   = trim((string)($json['termsAcceptedAt'] ?? ''));
$contratoGeo       = trim((string)($json['geolocation'] ?? ''));
$contratoIp        = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if ($contratoIp !== '' && strpos($contratoIp, ',') !== false) {
    // X-Forwarded-For can be a list — keep the first (original client) entry only.
    $contratoIp = trim(explode(',', $contratoIp)[0]);
}
$contratoUa        = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
// Acceptance timestamp prefers the client-supplied UTC time (when checkbox
// was actually ticked). Fall back to NOW() so a missing field never stops
// the contract from being generated.
$contratoAcceptedAt = $contratoTermsAt !== ''
    ? gmdate('Y-m-d H:i:s', strtotime($contratoTermsAt) ?: time())
    : gmdate('Y-m-d H:i:s');

// ─ Purchase case number per dashboards_diagrams.pdf ─────────────────────────
//   CASE 1-A — no referido code, no point selected (punto_id = 'centro-cercano')
//   CASE 1-B — no referido code, user picked a point in the configurador
//   CASE 3   — referido code present (influencer or point), general sale (online)
//   CASE 4   — sale from a point's showroom inventory (handled by puntosvoltika/php/asignar/referido.php)
// The PDF has no "CASE 2" — the YES/NO point-selector branch inside CASE 1
// is a sub-flow of CASE 1.
$caso = 1;
if ($codigoReferido !== '') {
    $caso = 3;
}

// CASE 3 — if the referido code is a PUNTO code (not influencer), auto-assign
// the order to that point. Per the PDF, the order should land on the Point
// Panel as "completed sale via referral, pending motorcycle assignment" without
// any manual point selection step.
$puntoVoltikaId = null;
if ($caso === 3 && $referidoTipo === 'punto' && $referidoId) {
    try {
        $pdoTmp = getDB();
        $pStmt = $pdoTmp->prepare("SELECT id, nombre FROM puntos_voltika WHERE id = ? AND activo = 1 LIMIT 1");
        $pStmt->execute([(int)$referidoId]);
        $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
        if ($pRow) {
            $puntoVoltikaId = (int)$pRow['id'];
            // Override any stale puntoId/puntoNombre with the referral point
            $puntoId     = 'punto-' . $puntoVoltikaId;
            $puntoNombre = $pRow['nombre'];
        }
    } catch (Throwable $e) {
        error_log('confirmar-orden CASE3 punto lookup: ' . $e->getMessage());
    }
}

// Entropy-enriched pedido number — plain time() collides when two users
// confirm within the same second. Four-hex-char entropy (16 bits) was not
// enough in practice (duplicates observed when the same client retried the
// flow multiple times within a single second). Eight hex chars (32 bits) makes
// collisions statistically impossible even under heavy retry bursts.
$pedidoNum = time() . '-' . bin2hex(random_bytes(4));
$fecha     = date('Y-m-d H:i');

// Folio de contrato — now generated BEFORE the DB insert so it can be
// persisted. Previously it was only computed for the email body, which
// meant customers who referenced the folio (e.g. "VK-20260411-LEO") could
// not be located in the database.
$folioContrato = $json['folioContrato']
    ?? ('VK-' . date('Ymd') . '-' . strtoupper(substr($nombre, 0, 3)));

// ── Guardar en BD ─────────────────────────────────────────────────────────────
$dbSaveOk = false;
$dbSaveErr = '';
try {
    $pdo = getDB();

    // ── Ensure UNIQUE INDEX on stripe_pi + pedido (DB-level dedup) ─────────
    // Final line of defense against duplicate orders. If the admin already
    // ran the cleanup tool, these ALTERs succeed and from now on MySQL
    // physically rejects duplicate INSERTs. If duplicates still exist the
    // ALTER throws — we catch, log, and rely on the GET_LOCK layer below.
    try {
        $idxCheck = $pdo->query("SHOW INDEX FROM transacciones WHERE Key_name='uniq_stripe_pi'")->fetchAll();
        if (!$idxCheck) {
            $pdo->exec("ALTER TABLE transacciones ADD UNIQUE INDEX uniq_stripe_pi (stripe_pi)");
            error_log('confirmar-orden: UNIQUE INDEX uniq_stripe_pi created');
        }
    } catch (Throwable $e) {
        error_log('confirmar-orden UNIQUE INDEX stripe_pi skipped: ' . $e->getMessage());
    }
    try {
        $idxPedido = $pdo->query("SHOW INDEX FROM transacciones WHERE Key_name='uniq_pedido'")->fetchAll();
        if (!$idxPedido) {
            $pdo->exec("ALTER TABLE transacciones ADD UNIQUE INDEX uniq_pedido (pedido)");
            error_log('confirmar-orden: UNIQUE INDEX uniq_pedido created');
        }
    } catch (Throwable $e) {
        error_log('confirmar-orden UNIQUE INDEX pedido skipped: ' . $e->getMessage());
    }

    // ── Idempotency guard (race-safe) ──────────────────────────────────────
    // If the user clicks CONTINUAR COMPRA more than once (e.g. after a
    // factura-validation bounce, a network retry, or a double-tap), the same
    // Stripe PaymentIntent can arrive 2+ times in quick succession. A plain
    // SELECT-then-INSERT leaks a race where 3 concurrent requests all pass
    // the duplicate check before any commits. We serialize using MySQL
    // GET_LOCK keyed on the PaymentIntent id so only one request at a time
    // can run the check + insert for a given order.
    $confirmLock = null;
    if (!empty($paymentIntentId)) {
        $confirmLock = 'vk_confirm_' . substr($paymentIntentId, 0, 60);
        try {
            $lockStmt = $pdo->prepare("SELECT GET_LOCK(?, 10)");
            $lockStmt->execute([$confirmLock]);
            $acquired = (int)$lockStmt->fetchColumn();
            if ($acquired !== 1) {
                // Couldn't acquire lock in 10s — fall through without dedup
                // rather than block the user. Duplicate risk remains but
                // extremely unlikely given 10s timeout.
                error_log('confirmar-orden GET_LOCK timeout for ' . $confirmLock);
                $confirmLock = null;
            } else {
                $dupStmt = $pdo->prepare("SELECT id, pedido FROM transacciones WHERE stripe_pi = ? ORDER BY id ASC LIMIT 1");
                $dupStmt->execute([$paymentIntentId]);
                $dupRow = $dupStmt->fetch(PDO::FETCH_ASSOC);
                if ($dupRow) {
                    // Release lock before exit
                    $pdo->prepare("SELECT RELEASE_LOCK(?)")->execute([$confirmLock]);
                    echo json_encode([
                        'status'     => 'ok',
                        'pedido'     => $dupRow['pedido'],
                        'emailSent'  => false,
                        'db_saved'   => true,
                        'db_warning' => null,
                        'deduped'    => true,
                    ]);
                    exit;
                }
                // Hold the lock through INSERT below so concurrent requests
                // wait and observe the newly-created row on their turn.
            }
        } catch (Throwable $e) {
            error_log('confirmar-orden dedup check: ' . $e->getMessage());
            $confirmLock = null;
        }
    }

    // Recovery table: any orphan orders we couldn't write to `transacciones`
    // land here so the admin dashboard can recover them manually. Previously
    // a failed INSERT was swallowed silently, making the sale invisible.
    $pdo->exec("CREATE TABLE IF NOT EXISTS transacciones_errores (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        nombre    VARCHAR(200),
        email     VARCHAR(200),
        telefono  VARCHAR(30),
        modelo    VARCHAR(200),
        color     VARCHAR(100),
        total     DECIMAL(12,2),
        stripe_pi VARCHAR(100),
        payload   TEXT,
        error_msg TEXT,
        freg      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS transacciones (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        nombre     VARCHAR(200),
        email      VARCHAR(200),
        telefono   VARCHAR(30),
        modelo     VARCHAR(200),
        color      VARCHAR(100),
        ciudad     VARCHAR(100),
        estado     VARCHAR(100),
        cp         VARCHAR(10),
        tpago      VARCHAR(50),
        precio     DECIMAL(12,2),
        total      DECIMAL(12,2),
        freg       DATETIME DEFAULT CURRENT_TIMESTAMP,
        pedido     VARCHAR(20),
        stripe_pi  VARCHAR(100),
        asesoria_placas TINYINT(1) NOT NULL DEFAULT 0,
        seguro_qualitas TINYINT(1) NOT NULL DEFAULT 0,
        punto_id        VARCHAR(80)  NULL,
        punto_nombre    VARCHAR(200) NULL,
        msi_meses       INT          NULL,
        msi_pago        DECIMAL(12,2) NULL,
        referido        VARCHAR(40)  NULL,
        referido_id     INT          NULL,
        referido_tipo   VARCHAR(20)  NULL,
        caso            TINYINT      NULL,
        folio_contrato  VARCHAR(40)  NULL,
        INDEX idx_folio (folio_contrato)
    )");
    // Backfill columns on pre-existing tables
    ensureTransaccionesColumns($pdo);

    // Determine initial pago_estado by QUERYING STRIPE for the real status.
    // Historic bug (reported by customer 2026-04-18): this code used to blindly
    // write 'pagada' for any card tpago without verifying with Stripe, so any
    // client that posted a paymentIntentId whose status was still
    // requires_action / processing / requires_payment_method ended up as
    // 'Pagado' in the admin panel even though the payment never cleared.
    //
    // Now we retrieve the PaymentIntent server-side and map its status:
    //   succeeded             → 'pagada'  (card fully cleared)
    //   processing            → 'pendiente' (SPEI/OXXO funds in transit,
    //                                        or card 3DS async processing)
    //   requires_action /
    //   requires_confirmation /
    //   requires_payment_method →  'pendiente' (user didn't complete auth)
    //   canceled              → 'fallido'
    //   anything else         → 'pendiente' (conservative default)
    //
    // Credit/enganche/parcial keep their semantic 'parcial' irrespective of
    // the Stripe status because only the enganche is captured up front.
    $pagoEstadoInit = 'pendiente';
    $stripeRealStatus = null;
    if (in_array($pagoTipo, ['credito', 'enganche', 'parcial'], true)) {
        $pagoEstadoInit = 'parcial';
    } elseif (!empty($paymentIntentId) && defined('STRIPE_SECRET_KEY') && STRIPE_SECRET_KEY) {
        // Direct REST call (lightweight — no SDK dependency required here)
        $ch = curl_init('https://api.stripe.com/v1/payment_intents/' . urlencode($paymentIntentId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . STRIPE_SECRET_KEY],
            CURLOPT_TIMEOUT        => 12,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200 && ($data = json_decode($resp, true)) && isset($data['status'])) {
            $stripeRealStatus = $data['status'];
            switch ($stripeRealStatus) {
                case 'succeeded':
                    $pagoEstadoInit = 'pagada';
                    break;
                case 'canceled':
                    $pagoEstadoInit = 'fallido';
                    break;
                case 'processing':
                case 'requires_action':
                case 'requires_confirmation':
                case 'requires_payment_method':
                case 'requires_capture':
                default:
                    $pagoEstadoInit = 'pendiente';
            }
        } else {
            // Stripe unreachable / wrong key → be conservative
            error_log('confirmar-orden: Stripe retrieve failed for PI ' . $paymentIntentId . ' httpCode=' . $code);
            $pagoEstadoInit = 'pendiente';
        }
    } elseif (empty($paymentIntentId)) {
        // Client didn't send a paymentIntentId for a card flow — clearly not paid
        $pagoEstadoInit = 'pendiente';
    }

    // SPEI/OXXO explicit override: these are always 'pendiente' at this step
    // (funds don't arrive immediately), regardless of what Stripe reports.
    if (in_array($pagoTipo, ['spei', 'oxxo'], true)) {
        $pagoEstadoInit = 'pendiente';
    }

    // ── Anti-fraud guard: identity-vs-credit-bureau cross-check ──────────
    // Customer brief 2026-04-30: even though the SPA advances to enganche
    // immediately on Truora success (so the user does not get stuck), a
    // mismatch between the CURP that Truora extracted from the INE and
    // the CURP we used for the credit bureau check (CDC) MUST stop the
    // order from being created. truora-webhook.php and
    // truora-status.php's API fallback both populate
    // verificaciones_identidad.curp_match; we read it here and refuse if
    // it is 0 (mismatch confirmed). curp_match=NULL is treated as
    // "unknown" → allow, since a permanent block on null would punish
    // legitimate users when the webhook is slow or the Truora API
    // response shape doesn't include the CURP field we look for.
    $isCreditFlow = in_array($pagoTipo, ['credito', 'enganche', 'parcial'], true)
                 || (($json['metodoPago'] ?? '') === 'credito');
    if ($isCreditFlow && $telefono) {
        try {
            $identStmt = $pdo->prepare("SELECT id, curp_match, expected_curp, verified_curp,
                    name_match, expected_name, verified_name,
                    truora_account_id, truora_process_id
                FROM verificaciones_identidad
                WHERE telefono = ?
                ORDER BY id DESC LIMIT 1");
            $identStmt->execute([$telefono]);
            $ident = $identStmt->fetch(PDO::FETCH_ASSOC);
            // Brief #4 (2026-04-30): refuse on name_match=0 too (was only
            // curp_match=0 before). Both fields are populated by either the
            // webhook OR the API fallback in truora-status.php, so by the
            // time the user clicks pay, at least one of them is set when
            // an actual mismatch exists.
            $curpMismatch = $ident && isset($ident['curp_match']) && (int)$ident['curp_match'] === 0;
            $nameMismatch = $ident && isset($ident['name_match']) && (int)$ident['name_match'] === 0;
            if ($curpMismatch || $nameMismatch) {
                $rejectReason = $nameMismatch && !$curpMismatch
                    ? 'identity_name_mismatch'
                    : 'identity_curp_mismatch';
                // Log the rejection for admin forensics.
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS confirmar_orden_rechazos (
                        id BIGINT AUTO_INCREMENT PRIMARY KEY,
                        reason VARCHAR(80) NULL,
                        payload MEDIUMTEXT NULL,
                        rejected_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )");
                    $pdo->prepare("INSERT INTO confirmar_orden_rechazos (reason, payload) VALUES (?, ?)")
                        ->execute([
                            $rejectReason,
                            json_encode([
                                'telefono'           => $telefono,
                                'identity_id'        => $ident['id'],
                                'expected_curp'      => $ident['expected_curp'],
                                'verified_curp'      => $ident['verified_curp'],
                                'expected_name'      => $ident['expected_name'],
                                'verified_name'      => $ident['verified_name'],
                                'curp_match'         => $ident['curp_match'],
                                'name_match'         => $ident['name_match'],
                                'truora_process_id'  => $ident['truora_process_id'],
                                'stripe_pi'          => $paymentIntentId,
                            ], JSON_UNESCAPED_UNICODE),
                        ]);
                } catch (Throwable $e) { error_log('rejection log: ' . $e->getMessage()); }
                http_response_code(403);
                echo json_encode([
                    'ok'    => false,
                    'error' => 'identity_mismatch',
                    'message' => 'La identidad verificada no coincide con la información del estudio de crédito. ' .
                                 'Por seguridad, esta orden no puede completarse. Regresa al inicio y usa la misma información que aparece en tu INE.',
                ]);
                exit;
            }
        } catch (Throwable $e) {
            // Non-fatal — log and continue. Better to let a legit order
            // through than to block everyone if the lookup itself errors.
            error_log('confirmar-orden CURP guard query failed: ' . $e->getMessage());
        }
    }

    // INSERT IGNORE so if UNIQUE INDEX catches a duplicate stripe_pi the row
    // is silently skipped (we fetch the existing pedido afterwards). Without
    // IGNORE, a UNIQUE violation would throw and land in transacciones_errores.
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO transacciones
            (nombre, email, telefono, modelo, color, ciudad, estado, cp, tpago,
             precio, total, freg, pedido, stripe_pi,
             asesoria_placas, seguro_qualitas, punto_id, punto_nombre,
             msi_meses, msi_pago, referido, referido_id, referido_tipo, caso,
             folio_contrato, pago_estado, environment)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    // Defensive defaults — pass '' / 0 instead of NULL for optional columns.
    // If the production schema still has legacy NOT NULL constraints (the
    // ensureTransaccionesColumns() MODIFY above should fix them, but we
    // double-protect here) the INSERT still succeeds.
    $stmt->execute([
        $nombre ?: '',
        $email ?: '',
        $telefono ?: '',
        $modelo ?: '',
        $color ?: '',
        $ciudad ?: '',
        $estado ?: '',
        $cp ?: '',
        $pagoTipo ?: 'contado',
        $pagoTipo === 'msi' ? $msiPago : $total,
        $total,
        $fecha,
        $pedidoNum,
        // stripe_pi: use NULL instead of '' so MySQL UNIQUE INDEX allows
        // multiple empty/missing values (UNIQUE treats each NULL as distinct).
        !empty($paymentIntentId) ? $paymentIntentId : null,
        $asesoriaPlacasInt,
        $seguroQualitasInt,
        $puntoId ?: '',
        $puntoNombre ?: '',
        $pagoTipo === 'msi' ? $msiMeses : 0,
        $pagoTipo === 'msi' ? $msiPago  : 0,
        $codigoReferido ?: '',
        $referidoId ?: 0,
        $referidoTipo ?: '',
        $caso ?: 1,
        $folioContrato ?: '',
        $pagoEstadoInit,
        defined('APP_ENV') ? APP_ENV : 'test',
    ]);
    $dbSaveOk = true;

    // If INSERT IGNORE silently dropped the row due to UNIQUE stripe_pi
    // collision, rowCount() is 0. Fetch the existing pedido and return it so
    // the client still gets a consistent response.
    if ($stmt->rowCount() === 0 && !empty($paymentIntentId)) {
        try {
            $existingStmt = $pdo->prepare("SELECT pedido FROM transacciones WHERE stripe_pi = ? LIMIT 1");
            $existingStmt->execute([$paymentIntentId]);
            $existingPedido = $existingStmt->fetchColumn();
            if ($existingPedido) {
                $pedidoNum = $existingPedido; // use the original pedido for downstream email/notify
                error_log('confirmar-orden: duplicate stripe_pi silently ignored, reusing pedido ' . $pedidoNum);
            }
        } catch (Throwable $e) {
            error_log('confirmar-orden dedup fetch: ' . $e->getMessage());
        }
    }

    // Per dashboards_diagrams.pdf: a purchase NEVER creates a motorcycle in
    // inventario_motos. Physical bikes are added by CEDIS when they arrive at
    // the warehouse. The CEDIS admin then manually assigns an existing bike to
    // the order via the Ventas → Asignar flow. The auto-INSERT that was here
    // created phantom VINs ("VK-M05-{pedido}") that made every order look
    // "assigned" even though no real bike existed — violating CASE 1/3 rules.

    // Bump referido counter if a valid referido_id was provided (tipo='referido')
    if ($referidoId && $referidoTipo === 'referido') {
        try {
            $pdo->prepare("
                UPDATE referidos
                SET ventas_count = ventas_count + 1
                WHERE id = ? AND activo = 1
            ")->execute([$referidoId]);
        } catch (PDOException $e) {
            error_log('Voltika referidos counter error: ' . $e->getMessage());
        }

        // Auto-calc influencer commission: look up the fixed MXN amount the
        // admin configured per model in `referido_comisiones` and write it
        // to comisiones_log so the Referidos dashboard stops showing $0.00.
        // Silent miss if the admin hasn't configured this model — no fake
        // defaults, payouts are always explicit.
        try {
            $slug = strtolower(preg_replace('/[^a-z0-9\-]+/i', '-', (string)$modelo));
            $slug = trim(preg_replace('/-+/', '-', $slug), '-');
            if ($slug !== '') {
                $stmt = $pdo->prepare("SELECT comision_monto FROM referido_comisiones WHERE referido_id = ? AND modelo_slug = ? LIMIT 1");
                $stmt->execute([$referidoId, $slug]);
                $comMonto = (float)($stmt->fetchColumn() ?: 0);
                if ($comMonto > 0) {
                    $pdo->prepare("
                        INSERT INTO comisiones_log
                            (punto_id, referido_id, pedido_num, modelo, monto_venta, comision_pct, comision_monto, tipo)
                        VALUES (NULL, ?, ?, ?, ?, NULL, ?, 'venta')
                    ")->execute([$referidoId, $pedidoNum, $modelo, $total, $comMonto]);
                }
            }
        } catch (PDOException $e) {
            error_log('Voltika referido comision auto-calc error: ' . $e->getMessage());
        }
    }

    // CASE 3 (dashboards_diagrams.pdf): the order was placed with a punto's
    // CODIGO REFERIDO for a general sale — bump the punto's ventas_count so it
    // shows up in punto reports alongside influencer referrals.
    if ($puntoVoltikaId && $referidoTipo === 'punto') {
        try {
            $pvCols = $pdo->query("SHOW COLUMNS FROM puntos_voltika")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('ventas_count', $pvCols, true)) {
                $pdo->exec("ALTER TABLE puntos_voltika ADD COLUMN ventas_count INT DEFAULT 0");
            }
            $pdo->prepare("
                UPDATE puntos_voltika
                SET ventas_count = COALESCE(ventas_count, 0) + 1
                WHERE id = ? AND activo = 1
            ")->execute([$puntoVoltikaId]);
        } catch (PDOException $e) {
            error_log('Voltika punto ventas_count error: ' . $e->getMessage());
        }
    }

    // ── Auto-calculate commission for punto referido sales ──
    // Priority: fixed monto (comision_venta_monto) over percentage (comision_venta_pct).
    // The customer's Puntos template (Z~AF columns) uses fixed MXN amounts per
    // model, so monto is the primary path; pct is kept for backward compat.
    if ($puntoVoltikaId && $referidoTipo === 'punto' && $total > 0) {
        try {
            $mStmt = $pdo->prepare("SELECT id FROM modelos WHERE nombre = ? LIMIT 1");
            $mStmt->execute([$modelo]);
            $modeloDbId = $mStmt->fetchColumn();

            if ($modeloDbId) {
                $cStmt = $pdo->prepare("
                    SELECT comision_venta_pct, comision_venta_monto FROM punto_comisiones
                    WHERE punto_id = ? AND modelo_id = ?
                ");
                $cStmt->execute([$puntoVoltikaId, $modeloDbId]);
                $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);

                $comPct   = $cRow ? (float)($cRow['comision_venta_pct']   ?? 0) : 0;
                $comFixed = $cRow ? (float)($cRow['comision_venta_monto'] ?? 0) : 0;

                if ($comFixed > 0) {
                    $comMonto = round($comFixed, 2);
                    $pdo->prepare("
                        INSERT INTO comisiones_log
                            (punto_id, referido_id, pedido_num, modelo, monto_venta, comision_pct, comision_monto, tipo)
                        VALUES (?, NULL, ?, ?, ?, NULL, ?, 'venta')
                    ")->execute([$puntoVoltikaId, $pedidoNum, $modelo, $total, $comMonto]);
                } elseif ($comPct > 0) {
                    $comMonto = round($total * $comPct / 100, 2);
                    $pdo->prepare("
                        INSERT INTO comisiones_log
                            (punto_id, referido_id, pedido_num, modelo, monto_venta, comision_pct, comision_monto, tipo)
                        VALUES (?, NULL, ?, ?, ?, ?, ?, 'venta')
                    ")->execute([$puntoVoltikaId, $pedidoNum, $modelo, $total, $comPct, $comMonto]);
                }
            }
        } catch (Throwable $e) {
            error_log('Voltika comision auto-calc error: ' . $e->getMessage());
        }
    }

} catch (PDOException $e) {
    // El pago ya se capturó en Stripe, así que NO podemos abortar sin dejar
    // una orden huérfana. Escribimos la orden a `transacciones_errores` para
    // que el admin la vea en el dashboard de ventas (ver admin/php/ventas/listar.php).
    $dbSaveErr = $e->getMessage();
    error_log('Voltika DB error: ' . $dbSaveErr);
    try {
        $errPdo = getDB();
        $errStmt = $errPdo->prepare("
            INSERT INTO transacciones_errores
                (nombre, email, telefono, modelo, color, total, stripe_pi, payload, error_msg)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $errStmt->execute([
            $nombre, $email, $telefono, $modelo, $color, $total,
            $paymentIntentId, json_encode($json), $dbSaveErr,
        ]);
    } catch (Throwable $e2) {
        error_log('Voltika transacciones_errores fallback failed: ' . $e2->getMessage());
    }
}

// Release the idempotency lock so concurrent requests queued behind us can
// run their dedup SELECT and find the row we just inserted.
if (!empty($confirmLock)) {
    try {
        $pdo->prepare("SELECT RELEASE_LOCK(?)")->execute([$confirmLock]);
    } catch (Throwable $e) { /* non-fatal */ }
}

// ── Enviar email de confirmacion ──────────────────────────────────────────────
$enganchePct    = floatval($json['enganchePct'] ?? 0);
$plazoMeses     = intval($json['plazoMeses'] ?? 36);
$pagoSemanal    = floatval($json['pagoSemanal'] ?? 0);
// $folioContrato already initialized near the top (before DB insert).
$metodoPago     = $json['metodoPago'] ?? $pagoTipo;
$esCredito      = ($pagoTipo === 'enganche' || $metodoPago === 'credito');

if ($pagoTipo === 'msi') {
    $pagoDescripcion = $msiMeses . ' pagos de $' . number_format($msiPago, 0, '.', ',') . ' MXN sin intereses';
    $montoFormateado = '$' . number_format($msiPago, 0, '.', ',') . ' MXN';
} else {
    $pagoDescripcion = 'Pago de Contado';
    $montoFormateado = '$' . number_format($total, 0, '.', ',') . ' MXN';
}
$engancheFormateado = '$' . number_format($total, 0, '.', ',') . ' MXN';
$pagoSemanalFormateado = '$' . number_format($pagoSemanal, 0, '.', ',') . ' MXN';
$plazoTexto = $plazoMeses . ' meses (' . round($plazoMeses * 4.33) . ' semanas)';
$whatsapp = '+52 55 1341 6370';
$n = htmlspecialchars($nombre);
$m = htmlspecialchars($modelo);
$c = htmlspecialchars($color);
$cd = htmlspecialchars($ciudad) . ($estado ? ', ' . htmlspecialchars($estado) : '');

$td = 'style="padding:10px 16px;border-bottom:1px solid #E5E7EB;font-size:14px;"';
$tdl = 'style="padding:10px 16px;border-bottom:1px solid #E5E7EB;font-size:14px;color:#6B7280;"';
$section = 'style="margin:0 0 8px;padding:12px 0 6px;font-size:16px;font-weight:800;color:#1a3a5c;border-bottom:2px solid #039fe1;"';

// Determine if customer selected a specific punto
$tienePunto = ($puntoNombre !== '' && $puntoTipo !== 'cercano');

$cuerpo = '<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tu Voltika está confirmada</title></head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;">
<tr><td align="center" style="padding:24px 12px;">
<table width="620" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;max-width:620px;width:100%;box-shadow:0 2px 12px rgba(0,0,0,0.08);">

<!-- Header -->
<tr><td style="background:linear-gradient(135deg,#1a3a5c,#039fe1);padding:28px;text-align:center;">
<img src="https://www.voltika.mx/configurador_prueba/img/voltika_logo_h_white.svg" alt="Voltika" style="height:44px;width:auto;display:block;margin:0 auto;">
<p style="margin:6px 0 0;font-size:13px;color:rgba(255,255,255,0.8);">Movilidad eléctrica inteligente</p>
</td></tr>

<!-- Body -->
<tr><td style="padding:28px;">

<h2 style="margin:0 0 8px;font-size:20px;color:#1a3a5c;">Hola, ' . htmlspecialchars($nombre) . ' 👋</h2>
' . ($tienePunto
    ? '<h3 style="margin:0 0 12px;font-size:17px;color:#039fe1;">Tu compra ha sido confirmada correctamente.</h3>
<p style="margin:0 0 24px;font-size:14px;color:#555;line-height:1.7;">Tu Voltika ya está en proceso.</p>'
    : '<h3 style="margin:0 0 12px;font-size:17px;color:#039fe1;">Tu Voltika está confirmada.</h3>
<p style="margin:0 0 20px;font-size:14px;color:#555;line-height:1.7;">Gracias por tu compra. Hemos recibido tu pago correctamente y tu orden ya está en proceso.</p>
<p style="margin:0 0 24px;font-size:14px;color:#555;line-height:1.7;">A partir de este momento, nuestro equipo dará seguimiento a tu entrega para que recibas tu moto de forma segura y sin complicaciones.</p>') . '

<!-- DETALLE DE TU COMPRA -->
<div ' . $section . '>DETALLE DE TU COMPRA</div>
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
<tr><td ' . $tdl . '>Cliente</td><td ' . $td . '><strong>' . $n . '</strong></td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Número de orden</td><td ' . $td . '><strong>#' . $pedidoNum . '</strong></td></tr>
<tr><td ' . $tdl . '>Modelo</td><td ' . $td . '>' . $m . '</td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Color</td><td ' . $td . '>' . $c . '</td></tr>
<tr><td ' . $tdl . '>Ciudad de entrega</td><td ' . $td . '>' . $cd . '</td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Monto pagado</td><td ' . $td . '><strong style="color:#039fe1;">' . $montoFormateado . '</strong></td></tr>
<tr><td ' . $tdl . '>Método de pago</td><td ' . $td . '>' . htmlspecialchars($pagoDescripcion) . '</td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Asesoría para placas</td><td ' . $td . '>' . $asesoriaPlacas . '</td></tr>
<tr><td ' . $tdl . '>Seguro Qualitas</td><td ' . $td . '>' . $seguroQualitas . '</td></tr>
</table>
<p style="font-size:10px;color:#999;line-height:1.5;margin:6px 0 16px;">Voltika solo sugiere gestores y seguros de terceros. No es responsable por su servicio, tiempos, costos ni cobertura. La contratación es responsabilidad del cliente.</p>

' . ($tienePunto ? '
<!-- PUNTO CONFIRMADO -->
<div ' . $section . '>PUNTO DE ENTREGA CONFIRMADO</div>
<div style="background:#E8F4FD;border-radius:10px;padding:16px;margin:12px 0 24px;border:1.5px solid #B3D4FC;">
<p style="margin:0 0 6px;font-size:14px;color:#555;">Tu punto de entrega ha sido registrado correctamente:</p>
<p style="margin:0 0 4px;font-size:15px;font-weight:700;color:#1a3a5c;">👉 ' . htmlspecialchars($puntoNombre) . '</p>
<p style="margin:0 0 10px;font-size:15px;font-weight:700;color:#1a3a5c;">👉 ' . $cd . '</p>
<p style="margin:0;font-size:13px;color:#555;">Tu punto de entrega ya está confirmado. No es necesario realizar ningún cambio ni contacto adicional.</p>
</div>

<!-- QUÉ SIGUE -->
<div ' . $section . '>¿QUÉ SIGUE CON TU VOLTIKA?</div>
<div style="margin-bottom:24px;font-size:14px;color:#555;line-height:1.8;">
<p style="margin:12px 0 4px;"><strong style="color:#1a3a5c;">✅ 1. Preparación de tu moto</strong></p>
<p style="margin:0 0 4px;">Estamos preparando tu Voltika para enviarla al punto que seleccionaste.</p>
<p style="margin:0 0 12px;">Esto incluye: revisión completa, preparación logística y envío seguro.</p>
<p style="margin:0 0 4px;"><strong style="color:#1a3a5c;">🚚 2. Envío al punto de entrega</strong></p>
<p style="margin:0 0 4px;">Tu moto será enviada directamente al punto seleccionado.</p>
<p style="margin:0 0 12px;">📩 Te notificaremos por correo y WhatsApp cuando tu moto llegue al punto.</p>
<p style="margin:0 0 4px;"><strong style="color:#1a3a5c;">🔧 3. Preparación en sitio</strong></p>
<p style="margin:0 0 4px;">Una vez que tu moto llegue: se realiza revisión final y se deja lista para entrega.</p>
<p style="margin:0 0 12px;">📩 Te avisaremos nuevamente cuando esté lista para recogerla.</p>
<p style="margin:0 0 4px;"><strong style="color:#1a3a5c;">🏍️ 4. Entrega de tu Voltika</strong></p>
<p style="margin:0;">Cuando recibas el aviso final: acudes al punto seleccionado, validas tu identidad y recibes tu moto lista para rodar.</p>
</div>

<!-- CUÁNDO RECIBO -->
<div ' . $section . '>¿CUÁNDO RECIBO MI VOLTIKA?</div>
<div style="font-size:14px;color:#555;line-height:1.7;margin:12px 0 24px;">
<p style="margin:0 0 8px;">El tiempo de entrega depende de la disponibilidad y logística en tu zona.</p>
<p style="margin:0 0 4px;">👉 No necesitas hacer nada.</p>
<p style="margin:0;">👉 Nosotros te mantendremos informado en cada etapa.</p>
</div>'
: '
<!-- QUÉ SIGUE -->
<div ' . $section . '>¿QUÉ SIGUE?</div>
<div style="margin-bottom:24px;font-size:14px;color:#555;line-height:1.8;">
<p style="margin:12px 0 4px;"><strong style="color:#1a3a5c;">1. Asignación de punto de entrega</strong></p>
<p style="margin:0 0 12px;">En menos de 48 horas te confirmaremos el punto Voltika autorizado más cercano a tu ubicación.</p>
<p style="margin:0 0 4px;"><strong style="color:#1a3a5c;">2. Confirmación de entrega</strong></p>
<p style="margin:0 0 12px;">Recibirás por correo y WhatsApp los datos del punto, dirección y fecha estimada.</p>
<p style="margin:0 0 4px;"><strong style="color:#1a3a5c;">3. Entrega de tu Voltika</strong></p>
<p style="margin:0;">Acudes al punto asignado, validas tu identidad y recibes tu moto lista para rodar.</p>
</div>

<!-- CUÁNDO RECIBO -->
<div ' . $section . '>¿CUÁNDO RECIBO MI VOLTIKA?</div>
<p style="font-size:14px;color:#555;line-height:1.7;margin:12px 0 24px;">El tiempo de entrega depende de disponibilidad y logística en tu zona.<br>Tu asesor Voltika te confirmará la fecha exacta junto con el punto asignado.</p>') . '

<!-- ENTREGA SEGURA -->
<div ' . $section . '>ENTREGA SEGURA (IMPORTANTE)</div>
<div style="background:#FFF8E1;border-radius:8px;padding:16px;margin:12px 0 24px;border-left:4px solid #FFB300;">
<p style="margin:0 0 10px;font-size:14px;color:#333;font-weight:700;">🔒 Tu número celular es tu llave de entrega.</p>
<p style="margin:0 0 8px;font-size:13px;color:#555;">Para recibir tu Voltika deberás:</p>
<ul style="margin:0 0 10px;padding-left:20px;font-size:13px;color:#555;line-height:1.8;">
<li>Tener acceso a tu número registrado</li>
<li>Validar un código de seguridad (OTP)</li>
<li>Presentar identificación oficial</li>
<li>Confirmar datos de tu compra</li>
</ul>
<p style="margin:0;font-size:13px;color:#555;">Para garantizar una entrega segura, podremos solicitar información adicional como apellidos o confirmación de tu orden.</p>
</div>

<!-- INFO PAGO -->
<div ' . $section . '>INFORMACIÓN SOBRE TU PAGO</div>
<p style="font-size:14px;color:#555;line-height:1.7;margin:12px 0;">Tu compra ha sido procesada correctamente.</p>
' . ($pagoTipo === 'msi' ? '<p style="font-size:13px;color:#555;line-height:1.7;margin:0 0 24px;">En caso de meses sin intereses:<br>• Tu banco aplicará los cargos mensuales correspondientes<br>• Podrás ver los cargos reflejados en tu estado de cuenta</p>' : '<p style="margin:0 0 24px;"></p>') . '

<!-- CAMBIO DE DATOS -->
<div ' . $section . '>CAMBIO DE DATOS</div>
<p style="font-size:14px;color:#555;line-height:1.7;margin:12px 0 24px;">Si necesitas actualizar tu número telefónico o ciudad de entrega, debes solicitarlo antes de la asignación de tu punto de entrega.</p>

<!-- SOPORTE -->
<div ' . $section . '>SOPORTE Y ATENCIÓN</div>
<p style="font-size:14px;color:#555;margin:12px 0 8px;">Estamos contigo en todo momento.</p>
<p style="font-size:14px;margin:0 0 4px;">📱 WhatsApp: <a href="https://wa.me/5213416370" style="color:#039fe1;font-weight:700;">' . $whatsapp . '</a></p>
<p style="font-size:14px;margin:0 0 24px;">📧 Correo: <a href="mailto:redes@voltika.mx" style="color:#039fe1;font-weight:700;">redes@voltika.mx</a></p>

<!-- TÉRMINOS -->
<div style="background:#F5F5F5;border-radius:8px;padding:16px;margin-top:8px;">
<p style="font-size:12px;color:#888;margin:0 0 6px;">Tu compra está protegida bajo nuestros:</p>
<p style="font-size:12px;margin:0 0 4px;"><a href="https://voltika.mx/docs/tyc_2026.pdf" style="color:#039fe1;">Términos y Condiciones</a></p>
<p style="font-size:12px;margin:0 0 8px;"><a href="https://voltika.mx/docs/privacidad_2026.pdf" style="color:#039fe1;">Aviso de Privacidad</a></p>
<p style="font-size:11px;color:#aaa;margin:0;">Al completar tu compra aceptaste nuestros Términos y Condiciones y Aviso de Privacidad.</p>
</div>

</td></tr>

<!-- Footer -->
<tr><td style="background:#1a3a5c;padding:20px 28px;text-align:center;">
<img src="https://www.voltika.mx/configurador_prueba/img/goelectric.svg" alt="GO electric" style="height:28px;width:auto;margin-bottom:8px;">
<p style="margin:0 0 4px;font-size:14px;font-weight:700;color:#fff;">Voltika México</p>
<p style="margin:0;font-size:12px;color:rgba(255,255,255,0.6);">Movilidad eléctrica inteligente · Mtech Gears, S.A. de C.V.</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>';

// ── Crédito email template ────────────────────────────────────────────────────
if ($esCredito) {
    $asunto = $tienePunto
        ? 'Tu Voltika ya está en proceso 🚀 Orden #' . $pedidoNum
        : 'Tu Voltika está confirmada a crédito, Orden #' . $pedidoNum;

    $cuerpo = '<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tu Voltika está confirmada a crédito</title></head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;">
<tr><td align="center" style="padding:24px 12px;">
<table width="620" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;max-width:620px;width:100%;box-shadow:0 2px 12px rgba(0,0,0,0.08);">

<tr><td style="background:linear-gradient(135deg,#1a3a5c,#039fe1);padding:28px;text-align:center;">
<img src="https://www.voltika.mx/configurador_prueba/img/voltika_logo_h_white.svg" alt="Voltika" style="height:44px;width:auto;display:block;margin:0 auto;">
<p style="margin:6px 0 0;font-size:13px;color:rgba(255,255,255,0.8);">Movilidad eléctrica inteligente</p>
</td></tr>

<tr><td style="padding:28px;">

<h2 style="margin:0 0 8px;font-size:20px;color:#1a3a5c;">Hola, ' . htmlspecialchars($nombre) . ' 👋</h2>
' . ($tienePunto
    ? '<h3 style="margin:0 0 12px;font-size:17px;color:#039fe1;">Tu compra ha sido confirmada correctamente.</h3>
<p style="margin:0 0 24px;font-size:14px;color:#555;line-height:1.7;">Tu Voltika ya está en proceso.</p>'
    : '<h3 style="margin:0 0 12px;font-size:17px;color:#039fe1;">Tu Voltika está confirmada.</h3>
<p style="margin:0 0 20px;font-size:14px;color:#555;line-height:1.7;">Gracias por tu compra. Tu crédito Voltika ha sido aprobado y tu orden ya está en proceso.</p>
<p style="margin:0 0 24px;font-size:14px;color:#555;line-height:1.7;">A partir de este momento, nuestro equipo dará seguimiento a tu entrega paso a paso para que recibas tu moto de forma segura y sin complicaciones.</p>') . '

<div ' . $section . '>DETALLE DE TU COMPRA</div>
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
<tr><td ' . $tdl . '>Cliente</td><td ' . $td . '><strong>' . $n . '</strong></td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Número de orden</td><td ' . $td . '><strong>#' . $pedidoNum . '</strong></td></tr>
<tr><td ' . $tdl . '>Modelo</td><td ' . $td . '>' . $m . '</td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Color</td><td ' . $td . '>' . $c . '</td></tr>
<tr><td ' . $tdl . '>Ciudad de entrega</td><td ' . $td . '>' . $cd . '</td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Enganche pagado</td><td ' . $td . '><strong style="color:#039fe1;">' . $engancheFormateado . '</strong></td></tr>
<tr><td ' . $tdl . '>Pago semanal</td><td ' . $td . '><strong style="color:#039fe1;">' . $pagoSemanalFormateado . '</strong></td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Plazo</td><td ' . $td . '>' . $plazoTexto . '</td></tr>
<tr><td ' . $tdl . '>Folio de Contrato</td><td ' . $td . '><strong>' . htmlspecialchars($folioContrato) . '</strong></td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Asesoría para placas</td><td ' . $td . '>' . $asesoriaPlacas . '</td></tr>
<tr><td ' . $tdl . '>Seguro Qualitas</td><td ' . $td . '>' . $seguroQualitas . '</td></tr>
</table>
<p style="font-size:10px;color:#999;line-height:1.5;margin:6px 0 16px;">Voltika solo sugiere gestores y seguros de terceros. No es responsable por su servicio, tiempos, costos ni cobertura. La contratación es responsabilidad del cliente.</p>

' . ($tienePunto ? '
<!-- PUNTO CONFIRMADO -->
<div ' . $section . '>PUNTO DE ENTREGA CONFIRMADO</div>
<div style="background:#E8F4FD;border-radius:10px;padding:16px;margin:12px 0 24px;border:1.5px solid #B3D4FC;">
<p style="margin:0 0 6px;font-size:14px;color:#555;">Tu punto de entrega ha sido registrado correctamente:</p>
<p style="margin:0 0 4px;font-size:15px;font-weight:700;color:#1a3a5c;">👉 ' . htmlspecialchars($puntoNombre) . '</p>
<p style="margin:0 0 10px;font-size:15px;font-weight:700;color:#1a3a5c;">👉 ' . $cd . '</p>
<p style="margin:0;font-size:13px;color:#555;">Tu punto de entrega ya está confirmado. No es necesario realizar ningún cambio ni contacto adicional.</p>
</div>

<div ' . $section . '>¿QUÉ SIGUE CON TU VOLTIKA?</div>
<div style="margin-bottom:24px;font-size:14px;color:#555;line-height:1.8;">
<p style="margin:12px 0 4px;"><strong style="color:#1a3a5c;">✅ 1. Preparación de tu moto</strong></p>
<p style="margin:0 0 4px;">Estamos preparando tu Voltika para enviarla al punto que seleccionaste.</p>
<p style="margin:0 0 12px;">Esto incluye: revisión completa, preparación logística y envío seguro.</p>
<p style="margin:0 0 4px;"><strong style="color:#1a3a5c;">🚚 2. Envío al punto de entrega</strong></p>
<p style="margin:0 0 4px;">Tu moto será enviada directamente al punto seleccionado.</p>
<p style="margin:0 0 12px;">📩 Te notificaremos por correo y WhatsApp cuando tu moto llegue al punto.</p>
<p style="margin:0 0 4px;"><strong style="color:#1a3a5c;">🔧 3. Preparación en sitio</strong></p>
<p style="margin:0 0 4px;">Una vez que tu moto llegue: se realiza revisión final y se deja lista para entrega.</p>
<p style="margin:0 0 12px;">📩 Te avisaremos nuevamente cuando esté lista para recogerla.</p>
<p style="margin:0 0 4px;"><strong style="color:#1a3a5c;">🏍️ 4. Entrega de tu Voltika</strong></p>
<p style="margin:0;">Cuando recibas el aviso final: acudes al punto seleccionado, validas tu identidad y recibes tu moto lista para rodar.</p>
</div>

<div ' . $section . '>¿CUÁNDO RECIBO MI VOLTIKA?</div>
<div style="font-size:14px;color:#555;line-height:1.7;margin:12px 0 24px;">
<p style="margin:0 0 8px;">El tiempo de entrega depende de la disponibilidad y logística en tu zona.</p>
<p style="margin:0 0 4px;">👉 No necesitas hacer nada.</p>
<p style="margin:0;">👉 Nosotros te mantendremos informado en cada etapa.</p>
</div>'
: '
<div ' . $section . '>¿QUÉ SIGUE?</div>
<div style="margin-bottom:24px;font-size:14px;color:#555;line-height:1.8;">
<p style="margin:12px 0 4px;"><strong style="color:#1a3a5c;">1. Asignación de punto de entrega</strong></p>
<p style="margin:0 0 12px;">En menos de 48 horas te confirmaremos el punto Voltika autorizado más cercano a tu ubicación.</p>
<p style="margin:0 0 4px;"><strong style="color:#1a3a5c;">2. Confirmación de entrega</strong></p>
<p style="margin:0 0 12px;">Recibirás por correo y WhatsApp los datos del punto, dirección y fecha estimada.</p>
<p style="margin:0 0 4px;"><strong style="color:#1a3a5c;">3. Entrega de tu Voltika</strong></p>
<p style="margin:0;">Acudes al punto asignado, validas tu identidad y recibes tu moto lista para rodar.</p>
</div>

<div ' . $section . '>¿CUÁNDO RECIBO MI VOLTIKA?</div>
<p style="font-size:14px;color:#555;line-height:1.7;margin:12px 0 24px;">El tiempo de entrega depende de disponibilidad y logística en tu zona.<br>Tu asesor Voltika te confirmará la fecha exacta junto con el punto asignado.</p>') . '

<div ' . $section . '>ENTREGA SEGURA (IMPORTANTE)</div>
<div style="background:#FFF8E1;border-radius:8px;padding:16px;margin:12px 0 24px;border-left:4px solid #FFB300;">
<p style="margin:0 0 10px;font-size:14px;color:#333;font-weight:700;">🔒 Tu número celular es tu llave de entrega.</p>
<p style="margin:0 0 8px;font-size:13px;color:#555;">Para recibir tu Voltika deberás:</p>
<ul style="margin:0 0 10px;padding-left:20px;font-size:13px;color:#555;line-height:1.8;">
<li>Tener acceso a tu número registrado</li>
<li>Validar un código de seguridad (OTP)</li>
<li>Presentar identificación oficial</li>
<li>Confirmar datos de tu compra</li>
</ul>
<p style="margin:0;font-size:13px;color:#555;">Para garantizar una entrega segura, podremos solicitar información adicional como apellidos o confirmación de tu orden.</p>
</div>

<div ' . $section . '>INFORMACIÓN SOBRE TU CRÉDITO</div>
<p style="font-size:14px;color:#555;line-height:1.7;margin:12px 0 8px;">Tu crédito Voltika se gestiona mediante cargos automáticos con el método de pago registrado.</p>
<ul style="margin:0 0 24px;padding-left:20px;font-size:13px;color:#555;line-height:1.8;">
<li>Mantén saldo disponible para evitar interrupciones</li>
<li>Podrás consultar y gestionar tu crédito con nuestro equipo</li>
<li>Las condiciones completas de tu financiamiento están definidas en tu contrato</li>
</ul>

<div ' . $section . '>CAMBIO DE DATOS</div>
<p style="font-size:14px;color:#555;line-height:1.7;margin:12px 0 24px;">Si necesitas actualizar tu número telefónico, ciudad de entrega o método de pago, debes solicitarlo antes de la asignación de tu punto o previo a la gestión de tu crédito.</p>

<div ' . $section . '>SOPORTE Y ATENCIÓN</div>
<p style="font-size:14px;color:#555;margin:12px 0 8px;">Estamos contigo en todo momento.</p>
<p style="font-size:14px;margin:0 0 4px;">📱 WhatsApp: <a href="https://wa.me/5213416370" style="color:#039fe1;font-weight:700;">' . $whatsapp . '</a></p>
<p style="font-size:14px;margin:0 0 24px;">📧 Correo: <a href="mailto:redes@voltika.mx" style="color:#039fe1;font-weight:700;">redes@voltika.mx</a></p>

<div style="background:#F5F5F5;border-radius:8px;padding:16px;margin-top:8px;">
<p style="font-size:12px;color:#888;margin:0 0 6px;">Tu crédito y compra están protegidos bajo nuestros:</p>
<p style="font-size:12px;margin:0 0 4px;"><a href="https://voltika.mx/docs/tyc_2026.pdf" style="color:#039fe1;">Términos y Condiciones</a></p>
<p style="font-size:12px;margin:0 0 8px;"><a href="https://voltika.mx/docs/privacidad_2026.pdf" style="color:#039fe1;">Aviso de Privacidad</a></p>
<p style="font-size:11px;color:#aaa;margin:0;">Al completar tu compra aceptaste nuestros Términos y Condiciones y Aviso de Privacidad.</p>
</div>

<div style="margin-top:14px;padding:10px 12px;border-top:1px dashed #E5E7EB;">
<p style="font-size:10px;color:#9CA3AF;margin:0 0 2px;font-family:Menlo,Consolas,monospace;">Ref. interna (soporte): ' . htmlspecialchars($paymentIntentId ?: 'n/a') . '</p>
<p style="font-size:10px;color:#9CA3AF;margin:0;font-family:Menlo,Consolas,monospace;">Pedido: ' . htmlspecialchars($pedidoNum) . ' · Folio: ' . htmlspecialchars($folioContrato) . '</p>
</div>

</td></tr>

<tr><td style="background:#1a3a5c;padding:20px 28px;text-align:center;">
<img src="https://www.voltika.mx/configurador_prueba/img/goelectric.svg" alt="GO electric" style="height:28px;width:auto;margin-bottom:8px;">
<p style="margin:0 0 4px;font-size:14px;font-weight:700;color:#fff;">Voltika México</p>
<p style="margin:0;font-size:12px;color:rgba(255,255,255,0.6);">Movilidad eléctrica inteligente · Mtech Gears, S.A. de C.V.</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>';

} else {
    $asunto = $tienePunto
        ? 'Tu Voltika ya está en proceso 🚀 Orden #' . $pedidoNum
        : 'Tu Voltika está confirmada, Orden #' . $pedidoNum;
}

// Legacy inline HTML email ($cuerpo) is no longer sent directly —
// voltikaNotify('compra_confirmada_*') below handles email + WhatsApp + SMS
// from a single rich template (customer-authored 4-way variants).
// Keep the $cuerpo block above intact for reference / rollback only.
$emailSent = false;

// ── Post-purchase notification (email + WhatsApp + SMS, 4-way) ──────────────
require_once __DIR__ . '/voltika-notify.php';

// Resolve punto address + maps link for the new templates
$direccionPunto = '';
$linkMaps       = '';
if ($tienePunto) {
    try {
        $pdoTmp = getDB();
        $ps = $pdoTmp->prepare("SELECT direccion, ciudad, estado FROM puntos_voltika WHERE nombre = ? AND activo = 1 LIMIT 1");
        $ps->execute([$puntoNombre]);
        $pRow = $ps->fetch(PDO::FETCH_ASSOC);
        if ($pRow) {
            $direccionPunto = trim($pRow['direccion'] ?? '');
            $addr = $puntoNombre . ($direccionPunto ? ', ' . $direccionPunto : '');
            if ($pRow['ciudad']) $addr .= ', ' . $pRow['ciudad'];
            if ($pRow['estado']) $addr .= ', ' . $pRow['estado'];
            $linkMaps = 'https://maps.google.com/?q=' . urlencode($addr);
        }
    } catch (Throwable $e) { error_log('confirmar-orden punto lookup: ' . $e->getMessage()); }
}

// Estimated delivery — 10 days from today unless transacción provides one
$fechaEstimada = date('j/n/Y', strtotime('+10 days'));

// Weekly payment (credit only) — looked up from subscripciones_credito
$montoSemanal = '';
if ($esCredito) {
    try {
        $pdoTmp = getDB();
        $ss = $pdoTmp->prepare("SELECT monto_semanal FROM subscripciones_credito WHERE email = ? OR telefono = ? ORDER BY id DESC LIMIT 1");
        $ss->execute([$email, $telefono]);
        $ms = $ss->fetchColumn();
        if ($ms) $montoSemanal = number_format((float)$ms, 2);
    } catch (Throwable $e) {}
}

// Resolve the short customer-facing code VK-YYMM-NNNN. Lookup by stripe_pi
// (most reliable — fresh webhook) or by pedido if stripe_pi unavailable.
$pedidoCorto = '';
try {
    $pdoTmp = getDB();
    $txId = 0;
    if (!empty($paymentIntentId)) {
        $q = $pdoTmp->prepare("SELECT id FROM transacciones WHERE stripe_pi=? ORDER BY id DESC LIMIT 1");
        $q->execute([$paymentIntentId]);
        $txId = (int)($q->fetchColumn() ?: 0);
    }
    if (!$txId && !empty($pedidoNum)) {
        $q = $pdoTmp->prepare("SELECT id FROM transacciones WHERE pedido=? ORDER BY id DESC LIMIT 1");
        $q->execute([$pedidoNum]);
        $txId = (int)($q->fetchColumn() ?: 0);
    }
    if ($txId && function_exists('voltikaResolvePedidoCorto')) {
        $pedidoCorto = voltikaResolvePedidoCorto($pdoTmp, $txId);
    }
} catch (Throwable $e) { error_log('confirmar-orden pedido_corto: ' . $e->getMessage()); }
if (!$pedidoCorto) $pedidoCorto = 'VK-' . $pedidoNum; // safe fallback

$notifyData = [
    'pedido'          => $pedidoNum,
    'pedido_corto'    => $pedidoCorto,
    'nombre'          => $nombre,
    'modelo'          => $modelo,
    'color'           => $color,
    'punto'           => $puntoNombre,
    'ciudad'          => $ciudad . ($estado ? ', ' . $estado : ''),
    'direccion_punto' => $direccionPunto,
    'link_maps'       => $linkMaps,
    'fecha_estimada'  => $fechaEstimada,
    'monto_semanal'   => $montoSemanal,
    'telefono'        => $telefono,
    'email'           => $email,
    'whatsapp'        => $telefono,
    'cliente_id'      => null,
];

// Dispatch to one of the 4 purchase-confirmation templates
$tplKey = 'compra_confirmada_'
        . ($esCredito ? 'credito' : 'contado')
        . ($tienePunto ? '_punto' : '_sin_punto');
voltikaNotify($tplKey, $notifyData);
$emailSent = !empty($email);

// Flag the row so stripe-webhook.php (which may arrive later for the same
// PaymentIntent) knows notifications were already dispatched here, avoiding
// duplicate emails/WhatsApps. The column is lazy-created on webhook side.
if (!empty($paymentIntentId)) {
    try {
        $pdo = getDB();
        $cols = $pdo->query("SHOW COLUMNS FROM transacciones")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('notif_sent_at', $cols, true)) {
            $pdo->exec("ALTER TABLE transacciones ADD COLUMN notif_sent_at DATETIME NULL");
        }
        $pdo->prepare("UPDATE transacciones SET notif_sent_at = NOW() WHERE stripe_pi = ? AND notif_sent_at IS NULL")
            ->execute([$paymentIntentId]);
    } catch (Throwable $e) {
        error_log('confirmar-orden notif_sent_at update: ' . $e->getMessage());
    }
}

// MSG 1B/1C/1D — delayed 5 minutes based on purchase type
if ($esCredito) {
    voltikaNotifyDelayed('portal_plazos', $notifyData, 300);
} elseif ($pagoTipo === 'msi') {
    voltikaNotifyDelayed('portal_msi', $notifyData, 300);
} else {
    voltikaNotifyDelayed('portal_contado', $notifyData, 300);
}

// ── Contrato de Compraventa al Contado (PDF + email + DB record) ────────
// Customer brief 2026-04-28: every 100 %-payment purchase (contado / msi /
// spei / oxxo) gets a personalised contract auto-generated server-side
// from the Contrato Voltika Contado v3 template. Cincel signature is NOT
// invoked here — acceptance comes from checkbox + OTP + payment per
// Cláusula Tercera of the contract itself. The credit/financed flow
// continues to use generar-contrato-pdf.php with its own template.
$contratoUrl   = null;
$contratoToken = null;
if (!$esCredito) {
    require_once __DIR__ . '/contrato-contado.php';
    try {
        $pdoCC = getDB();
        contratoContadoEnsureSchema($pdoCC);

        $fullName = trim(implode(' ', array_filter([
            $nombre,
            $apellidoPaterno,
            $apellidoMaterno,
        ])));
        if ($fullName === '') $fullName = $nombre;

        // Payment reference: Stripe PI for card/MSI/SPEI/OXXO; falls back to
        // the internal pedido number when Stripe didn't return one
        // (e.g. SPEI flows that bypass create-payment-intent).
        $paymentReference = $paymentIntentId ?: $pedidoNum;

        // vehicle_year — current year as a sane default; can be overridden
        // by future requests once inventario_motos exposes año-modelo.
        $vehicleYear = isset($json['modelo_anio']) && (int)$json['modelo_anio']
            ? (int)$json['modelo_anio']
            : (int)date('Y');

        $contratoData = [
            // pedido = internal id (used for filename, URL token, DB lookup);
            // folio = customer-facing identifier (VK-YYYYMMDD-XXX) shown
            // inside the contract body as the binding folio.
            'pedido'                  => $pedidoNum,
            'folio'                   => $folioContrato ?: $pedidoNum,
            'contract_date'           => date('d/m/Y'),
            'customer_full_name'      => $fullName,
            'customer_email'          => $email,
            'customer_phone'          => $telefono,
            'customer_zip'            => $customerCp,
            'vehicle_model'           => $modelo,
            'vehicle_color'           => $color,
            'vehicle_year'            => $vehicleYear,
            'vehicle_price'           => $total,
            // Logistics cost only applies to MSI per Cláusula Segunda.
            'logistics_cost'          => $pagoTipo === 'msi' ? floatval($json['costoLogistico'] ?? 0) : 0,
            'total_amount'            => $pagoTipo === 'msi' ? ($total + floatval($json['costoLogistico'] ?? 0)) : $total,
            'payment_method'          => $pagoTipo,
            'payment_reference'       => $paymentReference,
            'payment_date'            => date('d/m/Y H:i'),
            'estimated_delivery_date' => $fechaEstimada,
            'acceptance_timestamp'    => $contratoAcceptedAt,
            'acceptance_ip'           => $contratoIp,
            'acceptance_user_agent'   => $contratoUa,
            'acceptance_geolocation'  => $contratoGeo,
            'otp_validated'           => $contratoOtpOk,
        ];

        $genResult = contratoContadoGenerate($contratoData);
        if ($genResult['ok']) {
            $relPath       = contratoContadoRelativePath($pedidoNum);
            $contratoToken = contratoContadoDownloadToken($pedidoNum, (string)$paymentIntentId);
            $contratoUrl   = 'php/descargar-contrato.php?pedido=' . urlencode($pedidoNum)
                           . '&token=' . urlencode($contratoToken);
            $contratoHash  = $genResult['hash'] ?? null;

            // Lazy-add hash column for older installs (Tech Spec EN §6).
            try {
                $cols = $pdoCC->query("SHOW COLUMNS FROM transacciones LIKE 'contrato_pdf_hash'")->fetch();
                if (!$cols) $pdoCC->exec("ALTER TABLE transacciones ADD COLUMN contrato_pdf_hash CHAR(64) NULL");
            } catch (Throwable $e) { /* non-fatal */ }

            // Persist the path + hash + acceptance metadata so admin and
            // the customer portal can re-download / re-verify later.
            try {
                $pdoCC->prepare("UPDATE transacciones
                        SET contrato_pdf_path      = ?,
                            contrato_pdf_hash      = ?,
                            contrato_aceptado_at   = ?,
                            contrato_aceptado_ip   = ?,
                            contrato_aceptado_ua   = ?,
                            contrato_geolocation   = ?,
                            contrato_otp_validated = ?
                        WHERE pedido = ?
                        ORDER BY id DESC LIMIT 1")
                    ->execute([
                        $relPath,
                        $contratoHash,
                        $contratoAcceptedAt,
                        $contratoIp,
                        $contratoUa,
                        $contratoGeo,
                        $contratoOtpOk,
                        $pedidoNum,
                    ]);
            } catch (Throwable $e) {
                error_log('confirmar-orden contrato persist: ' . $e->getMessage());
            }

            // Email PDF as attachment — DISABLED by default per customer
            // brief 2026-04-30: "Don't send any additional email to
            // customer with contract." The PDF is already downloadable
            // from the success screen, so the email is redundant.
            // To re-enable in the future set SEND_CONTRACT_EMAIL=1 in .env.
            $sendContractEmail = strtolower((string)(getenv('SEND_CONTRACT_EMAIL') ?: '0'));
            $sendContractEmail = in_array($sendContractEmail, ['1','true','yes','on'], true);
            if ($sendContractEmail && !empty($email)) {
                try {
                    contratoContadoSendEmail($contratoData, $genResult['path']);
                } catch (Throwable $e) {
                    error_log('confirmar-orden contrato email: ' . $e->getMessage());
                }
            }
        } else {
            error_log('confirmar-orden contrato generate failed: ' . ($genResult['error'] ?? 'n/a'));
        }
    } catch (Throwable $e) {
        error_log('confirmar-orden contrato block: ' . $e->getMessage());
    }
}

// ── Admin alerts for Servicios adicionales ──────────────────────────────
// When the client opts in for license-plate advisory or Quálitas insurance,
// notify the Voltika admin so they can follow up manually. The admin number
// is the same used elsewhere in the project (Voltika owner WhatsApp).
$ADMIN_WA    = defined('VOLTIKA_ADMIN_WHATSAPP') ? VOLTIKA_ADMIN_WHATSAPP : '+5214421198928';
$ADMIN_EMAIL = defined('VOLTIKA_ADMIN_EMAIL')    ? VOLTIKA_ADMIN_EMAIL    : 'admin@voltika.mx';

if ($asesoriaPlacasInt === 1) {
    // Route to the state-specific gestor de placas if one is registered
    // (see admin → Gestores de placas). Fallback to Voltika admin if no
    // gestor is assigned for this estado.
    $placasTel   = $ADMIN_WA;
    $placasWa    = $ADMIN_WA;
    $placasEmail = $ADMIN_EMAIL;
    try {
        $pdoG = getDB();
        // Ensure table exists (idempotent) so the lookup doesn't fatal on fresh schemas.
        $pdoG->exec("CREATE TABLE IF NOT EXISTS gestores_placas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            estado_mx VARCHAR(100) NOT NULL,
            nombre VARCHAR(200) NOT NULL,
            telefono VARCHAR(30) DEFAULT NULL,
            email VARCHAR(200) DEFAULT NULL,
            whatsapp VARCHAR(30) DEFAULT NULL,
            notas TEXT DEFAULT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            freg DATETIME DEFAULT CURRENT_TIMESTAMP,
            fmod DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_estado (estado_mx, activo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $gStmt = $pdoG->prepare("SELECT nombre, telefono, email, whatsapp
            FROM gestores_placas
            WHERE estado_mx = ? AND activo = 1
            ORDER BY id DESC LIMIT 1");
        $gStmt->execute([$estado]);
        $g = $gStmt->fetch(PDO::FETCH_ASSOC);
        if ($g) {
            if (!empty($g['whatsapp'])) $placasWa  = $g['whatsapp'];
            elseif (!empty($g['telefono'])) $placasWa = $g['telefono'];
            if (!empty($g['telefono'])) $placasTel   = $g['telefono'];
            if (!empty($g['email']))    $placasEmail = $g['email'];
        }
    } catch (Throwable $e) { error_log('gestor placas lookup: ' . $e->getMessage()); }

    voltikaNotify('admin_extras_placas', [
        'pedido'           => $pedidoNum,
        'nombre'           => $nombre,
        'telefono_cliente' => $telefono,
        'estado_mx'        => $estado,
        'ciudad'           => $ciudad,
        'modelo'           => $modelo,
        'telefono'         => $placasTel,
        'whatsapp'         => $placasWa,
        'email'            => $placasEmail,
        'cliente_id'       => null,
    ]);
}

if ($seguroQualitasInt === 1) {
    voltikaNotify('admin_extras_seguro', [
        'pedido'           => $pedidoNum,
        'nombre'           => $nombre,
        'telefono_cliente' => $telefono,
        'modelo'           => $modelo,
        'color'            => $color,
        'telefono'         => $ADMIN_WA,
        'whatsapp'         => $ADMIN_WA,
        'email'            => $ADMIN_EMAIL,
        'cliente_id'       => null,
    ]);
}

echo json_encode([
    'status'        => $dbSaveOk ? 'ok' : 'ok_warn',
    'pedido'        => $pedidoNum,
    'emailSent'     => $emailSent,
    'db_saved'      => $dbSaveOk,
    'db_warning'    => $dbSaveOk ? null : 'La orden quedó registrada en recuperación. Contactar soporte con número de pedido.',
    'contrato_url'  => $contratoUrl   ?? null,
    'contrato_token'=> $contratoToken ?? null,
]);

// ═════════════════════════════════════════════════════════════════════════════
// Helpers
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Backfill missing columns on `transacciones` so old installs pick up the new
 * fields without a manual migration.
 */
function ensureTransaccionesColumns(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $cols = [
        'asesoria_placas' => "ADD COLUMN asesoria_placas TINYINT(1) NOT NULL DEFAULT 0 AFTER stripe_pi",
        'seguro_qualitas' => "ADD COLUMN seguro_qualitas TINYINT(1) NOT NULL DEFAULT 0 AFTER asesoria_placas",
        'punto_id'        => "ADD COLUMN punto_id        VARCHAR(80)   NULL AFTER seguro_qualitas",
        'punto_nombre'    => "ADD COLUMN punto_nombre    VARCHAR(200)  NULL AFTER punto_id",
        'msi_meses'       => "ADD COLUMN msi_meses       INT           NULL AFTER punto_nombre",
        'msi_pago'        => "ADD COLUMN msi_pago        DECIMAL(12,2) NULL AFTER msi_meses",
        'referido'        => "ADD COLUMN referido        VARCHAR(40)   NULL AFTER msi_pago",
        'referido_id'     => "ADD COLUMN referido_id     INT           NULL AFTER referido",
        'referido_tipo'   => "ADD COLUMN referido_tipo   VARCHAR(20)   NULL AFTER referido_id",
        'caso'            => "ADD COLUMN caso            TINYINT       NULL AFTER referido_tipo",
        'folio_contrato'  => "ADD COLUMN folio_contrato  VARCHAR(40)   NULL AFTER caso",
        'pago_estado'     => "ADD COLUMN pago_estado     VARCHAR(20)   NULL AFTER folio_contrato",
        'fecha_estimada_entrega' => "ADD COLUMN fecha_estimada_entrega DATE NULL AFTER pago_estado",
    ];
    try {
        $existing = $pdo->query("SHOW COLUMNS FROM transacciones")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cols as $name => $alter) {
            if (!in_array($name, $existing, true)) {
                try { $pdo->exec("ALTER TABLE transacciones " . $alter); }
                catch (PDOException $e) { error_log('ensureTransaccionesColumns(' . $name . '): ' . $e->getMessage()); }
            }
        }
    } catch (PDOException $e) {
        error_log('ensureTransaccionesColumns: ' . $e->getMessage());
    }

    // ── Fix legacy NOT NULL constraints on optional columns ────────────────
    // Without this, any INSERT that passes NULL (e.g. an order with no
    // referido code) hits SQLSTATE[23000] 1048 and lands in transacciones_errores.
    // Observed in production screenshot: 17 orphan orders caused by
    // "Column 'referido' cannot be null".
    $nullableFixes = [
        'referido'       => "MODIFY COLUMN referido       VARCHAR(40)   NULL DEFAULT NULL",
        'referido_id'    => "MODIFY COLUMN referido_id    INT           NULL DEFAULT NULL",
        'referido_tipo'  => "MODIFY COLUMN referido_tipo  VARCHAR(20)   NULL DEFAULT NULL",
        'punto_id'       => "MODIFY COLUMN punto_id       VARCHAR(80)   NULL DEFAULT NULL",
        'punto_nombre'   => "MODIFY COLUMN punto_nombre   VARCHAR(200)  NULL DEFAULT NULL",
        'msi_meses'      => "MODIFY COLUMN msi_meses      INT           NULL DEFAULT NULL",
        'msi_pago'       => "MODIFY COLUMN msi_pago       DECIMAL(12,2) NULL DEFAULT NULL",
        'caso'           => "MODIFY COLUMN caso           TINYINT       NULL DEFAULT NULL",
        'folio_contrato' => "MODIFY COLUMN folio_contrato VARCHAR(40)   NULL DEFAULT NULL",
        'ciudad'         => "MODIFY COLUMN ciudad         VARCHAR(100)  NULL DEFAULT NULL",
        'estado'         => "MODIFY COLUMN estado         VARCHAR(100)  NULL DEFAULT NULL",
        'cp'              => "MODIFY COLUMN cp              VARCHAR(10)   NULL DEFAULT NULL",
    ];
    try {
        $meta = $pdo->query("SHOW COLUMNS FROM transacciones")->fetchAll(PDO::FETCH_ASSOC);
        $notNullCols = [];
        foreach ($meta as $c) {
            if (($c['Null'] ?? 'YES') === 'NO') {
                $notNullCols[$c['Field']] = true;
            }
        }
        foreach ($nullableFixes as $name => $alter) {
            if (isset($notNullCols[$name])) {
                try {
                    $pdo->exec("ALTER TABLE transacciones " . $alter);
                    error_log("ensureTransaccionesColumns: relaxed NOT NULL on {$name}");
                } catch (PDOException $e) {
                    error_log('ensureTransaccionesColumns MODIFY(' . $name . '): ' . $e->getMessage());
                }
            }
        }
    } catch (PDOException $e) {
        error_log('ensureTransaccionesColumns nullable scan: ' . $e->getMessage());
    }
}
