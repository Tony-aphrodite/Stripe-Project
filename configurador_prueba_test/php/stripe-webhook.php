<?php
/**
 * Voltika - Stripe Webhook Handler
 * Handles asynchronous payment events (OXXO, SPEI confirmations, failures).
 * Endpoint URL to register in Stripe Dashboard:
 *   https://your-domain.com/configurador/php/stripe-webhook.php
 */

// ── Central config ───────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inventory-utils.php';

// ── Stripe SDK ───────────────────────────────────────────────────────────────
require_once __DIR__ . '/vendor/autoload.php';

// ── Logging helper ───────────────────────────────────────────────────────────
$webhookLogFile = __DIR__ . '/logs/webhook.log';

function webhookLog($message) {
    global $webhookLogFile;
    $ts = date('Y-m-d H:i:s');
    file_put_contents($webhookLogFile, "[$ts] $message\n", FILE_APPEND | LOCK_EX);
}

// ── Read raw body for signature verification ─────────────────────────────────
$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (empty($payload)) {
    http_response_code(400);
    webhookLog('ERROR: Empty payload received');
    exit;
}

// ── Verify Stripe signature ──────────────────────────────────────────────────
try {
    $event = \Stripe\Webhook::constructEvent(
        $payload,
        $sigHeader,
        STRIPE_WEBHOOK_SECRET
    );
} catch (\UnexpectedValueException $e) {
    http_response_code(400);
    webhookLog('ERROR: Invalid payload - ' . $e->getMessage());
    exit;
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    webhookLog('ERROR: Invalid signature - ' . $e->getMessage());
    exit;
}

// ── Log the event ────────────────────────────────────────────────────────────
$eventType = $event->type ?? 'unknown';
$eventId   = $event->id ?? 'no-id';
webhookLog("Event received: $eventType (ID: $eventId)");

// ── Route by event type ──────────────────────────────────────────────────────
switch ($eventType) {

    case 'payment_intent.succeeded':
        handlePaymentSucceeded($event->data->object);
        break;

    case 'payment_intent.payment_failed':
        handlePaymentFailed($event->data->object);
        break;

    case 'payment_intent.created':
        handlePaymentPending($event->data->object);
        break;

    // ── Tech Spec EN §7 — chargeback / dispute auto-escalation ─────────
    case 'charge.dispute.created':
    case 'charge.dispute.funds_withdrawn':
        handleChargebackOpened($event->data->object);
        break;
    case 'charge.dispute.closed':
    case 'charge.dispute.funds_reinstated':
        handleChargebackResolved($event->data->object);
        break;

    default:
        webhookLog("Unhandled event type: $eventType");
        break;
}

// ── Also handle subscription/ciclos_pago updates for ALL succeeded payments ──
if ($eventType === 'payment_intent.succeeded') {
    handleCiclosPagoUpdate($event->data->object);
}

// ── Always return 200 to Stripe ──────────────────────────────────────────────
http_response_code(200);
echo json_encode(['received' => true]);
exit;


// ═══════════════════════════════════════════════════════════════════════════════
// HANDLERS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Handle payment_intent.succeeded
 * For OXXO/SPEI payments, look up order and send confirmation email.
 */
function handlePaymentSucceeded($paymentIntent) {
    $piId    = $paymentIntent->id ?? '';
    $pmTypes = $paymentIntent->payment_method_types ?? [];
    $amount  = ($paymentIntent->amount ?? 0) / 100; // centavos -> MXN
    $email   = $paymentIntent->receipt_email ?? '';
    $meta    = (array)($paymentIntent->metadata ?? []);
    if (!$email) $email = $meta['email'] ?? '';

    $isOxxo = in_array('oxxo', $pmTypes);
    $isSpei = in_array('customer_balance', $pmTypes);
    $isCard = !$isOxxo && !$isSpei;
    $methodLabel = $isOxxo ? 'OXXO' : ($isSpei ? 'SPEI' : 'CARD');

    webhookLog("payment_intent.succeeded: $piId | $methodLabel | amount: $amount MXN | email: $email");

    try {
        $pdo = getDB();

        // Ensure auxiliary columns exist (pago_estado + notif_sent_at) so both
        // writers (confirmar-orden.php and this webhook) can coordinate.
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM transacciones")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('pago_estado',   $cols, true)) $pdo->exec("ALTER TABLE transacciones ADD COLUMN pago_estado VARCHAR(20) NULL");
            if (!in_array('notif_sent_at', $cols, true)) $pdo->exec("ALTER TABLE transacciones ADD COLUMN notif_sent_at DATETIME NULL");
        } catch (PDOException $ignore) {}

        // ── Tier 1: exact match by stripe_pi ────────────────────────────────
        $stmt = $pdo->prepare("
            SELECT id, nombre, email, telefono, modelo, color, ciudad, estado, cp,
                   tpago, precio, total, pedido, stripe_pi, punto_nombre, punto_id,
                   pago_estado, notif_sent_at, fecha_estimada_entrega
            FROM transacciones
            WHERE stripe_pi = ?
            LIMIT 1
        ");
        $stmt->execute([$piId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        $matchMode = $order ? 'stripe_pi' : null;

        // ── Tier 2: recovery — row created without stripe_pi (client POST lost) ─
        // Card payments have a known failure mode: stripe SDK returns succeeded
        // on the client but the AJAX to confirmar-orden.php drops (network,
        // browser close). The row may or may not exist. Match the most recent
        // pending row with the same email and amount (±$1) from the last 2
        // days.
        if (!$order && $email) {
            $stmt = $pdo->prepare("
                SELECT id, nombre, email, telefono, modelo, color, ciudad, estado, cp,
                       tpago, precio, total, pedido, stripe_pi, punto_nombre, punto_id,
                       pago_estado, notif_sent_at, fecha_estimada_entrega
                FROM transacciones
                WHERE email = ?
                  AND (stripe_pi IS NULL OR stripe_pi = '')
                  AND ABS(total - ?) < 1
                  AND freg > DATE_SUB(NOW(), INTERVAL 2 DAY)
                ORDER BY freg DESC
                LIMIT 1
            ");
            $stmt->execute([$email, $amount]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($order) {
                $matchMode = 'email+amount';
                try {
                    $pdo->prepare("UPDATE transacciones SET stripe_pi = ? WHERE id = ?")
                        ->execute([$piId, $order['id']]);
                    $order['stripe_pi'] = $piId;
                    webhookLog("Recovered PI $piId → pedido #{$order['pedido']} (orphan row matched by $email + $amount)");
                } catch (PDOException $e) {
                    webhookLog("stripe_pi recovery update error: " . $e->getMessage());
                }
            }
        }

        // ── Tier 3: no row exists — create from PI metadata ─────────────────
        // Last resort safety net: confirmar-orden.php never ran. Build a row
        // from PaymentIntent metadata so admin can still see the order and
        // the customer receives their confirmation.
        if (!$order) {
            $order = createOrderFromMetadata($pdo, $paymentIntent);
            if ($order) {
                $matchMode = 'created_from_metadata';
                webhookLog("Created recovery row for PI $piId → pedido #{$order['pedido']} (no existing row matched)");
            }
        }

        if (!$order) {
            webhookLog("WARNING: No order could be matched or created for PI $piId (email: $email amount: $amount)");
            return;
        }

        // ── Mark payment as paid in transacciones + inventario_motos ────────
        // For credit flows (enganche/parcial) keep 'parcial' semantics — only
        // the enganche amount was captured.
        $tpago = strtolower(trim($order['tpago'] ?? ''));
        $targetEstado = in_array($tpago, ['credito', 'enganche', 'parcial'], true) ? 'parcial' : 'pagada';

        try {
            $upd = $pdo->prepare("UPDATE transacciones SET pago_estado = ? WHERE stripe_pi = ? AND (pago_estado IS NULL OR pago_estado IN ('pendiente',''))");
            $upd->execute([$targetEstado, $piId]);
            if ($upd->rowCount() > 0) {
                webhookLog("Updated pago_estado → '$targetEstado' via webhook (match: $matchMode)");
                // Refresh local copy so notification path sees updated state
                $order['pago_estado'] = $targetEstado;
            }
            // Mirror to inventario_motos if the bike is already linked
            $updMoto = $pdo->prepare("UPDATE inventario_motos SET pago_estado = ? WHERE stripe_pi = ? AND (pago_estado IS NULL OR pago_estado IN ('pendiente',''))");
            $updMoto->execute([$targetEstado, $piId]);
        } catch (PDOException $e) {
            webhookLog("pago_estado update error: " . $e->getMessage());
        }

        // ── Send notification only if confirmar-orden.php didn't already ───
        // notif_sent_at prevents the duplicate-email bug when both code paths
        // run successfully (client AJAX + webhook arriving in parallel).
        if (empty($order['notif_sent_at'])) {
            sendPurchaseNotifications($order);
            try {
                $pdo->prepare("UPDATE transacciones SET notif_sent_at = NOW() WHERE id = ? AND notif_sent_at IS NULL")
                    ->execute([$order['id']]);
            } catch (PDOException $e) {
                webhookLog("notif_sent_at flag error: " . $e->getMessage());
            }
        } else {
            webhookLog("Notification already sent by confirmar-orden.php at {$order['notif_sent_at']} — skip (webhook idempotent)");
        }

    } catch (PDOException $e) {
        webhookLog("DB ERROR: " . $e->getMessage());
    }
}

/**
 * Last-resort recovery: create a transacciones row straight from the Stripe
 * PaymentIntent. Used when confirmar-orden.php was never called for this PI
 * (e.g., customer closed browser right after Stripe SDK returned success).
 *
 * Returns the newly-created row (or null on failure). Caller must still
 * handle notification + inventario_motos linking.
 */
function createOrderFromMetadata(PDO $pdo, $paymentIntent) {
    $piId   = $paymentIntent->id ?? '';
    $amount = ($paymentIntent->amount ?? 0) / 100;
    $meta   = (array)($paymentIntent->metadata ?? []);
    $email  = $paymentIntent->receipt_email ?? ($meta['email'] ?? '');

    // Stricter guard: customer report 2026-04-23 showed VK-2604-0011/0012/0013
    // appearing in admin with blank Cliente/Modelo/Color — a webhook-built
    // recovery row with sparse metadata. Require nombre AND modelo to rebuild;
    // anything less is a useless phantom that forces manual admin cleanup.
    $metaNombre = trim((string)($meta['nombre'] ?? ''));
    $metaModelo = trim((string)($meta['modelo'] ?? ''));
    if ($metaNombre === '' || $metaModelo === '') {
        webhookLog("createOrderFromMetadata: insufficient metadata to rebuild order (nombre='{$metaNombre}' modelo='{$metaModelo}') — skip, logging for repair");
        try {
            @$pdo->exec("CREATE TABLE IF NOT EXISTS stripe_webhook_phantom (
                id INT AUTO_INCREMENT PRIMARY KEY,
                stripe_pi VARCHAR(100),
                email VARCHAR(200),
                amount DECIMAL(12,2),
                metadata TEXT,
                freg DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->prepare("INSERT INTO stripe_webhook_phantom (stripe_pi, email, amount, metadata) VALUES (?,?,?,?)")
                ->execute([$piId, $email, $amount, substr(json_encode($meta), 0, 4000)]);
        } catch (Throwable $e) { webhookLog("phantom log error: " . $e->getMessage()); }
        return null;
    }

    $tpago = $meta['tpago'] ?? $meta['method'] ?? 'contado';
    $targetEstado = in_array(strtolower($tpago), ['credito', 'enganche', 'parcial'], true) ? 'parcial' : 'pagada';
    $pedidoNum = (int)(microtime(true) * 1000) % 100000000; // short numeric pedido
    $env = defined('APP_ENV') ? APP_ENV : 'test';

    try {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO transacciones
                (nombre, email, telefono, modelo, color, ciudad, estado, cp, tpago,
                 precio, total, freg, pedido, stripe_pi,
                 punto_id, punto_nombre, pago_estado, environment)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            trim(($meta['nombre'] ?? '') . ' ' . ($meta['apellidos'] ?? '')),
            $email,
            $meta['telefono']  ?? '',
            $meta['modelo']    ?? '',
            $meta['color']     ?? '',
            $meta['ciudad']    ?? '',
            $meta['estado']    ?? '',
            $meta['cp']        ?? '',
            $tpago,
            $amount,
            $amount,
            $pedidoNum,
            $piId,
            $meta['punto_id']     ?? '',
            $meta['punto_nombre'] ?? '',
            $targetEstado,
            $env,
        ]);

        // Fetch back (INSERT IGNORE may silently skip if race with confirmar-orden.php)
        $row = $pdo->prepare("SELECT id, nombre, email, telefono, modelo, color, ciudad, estado, cp,
                                     tpago, precio, total, pedido, stripe_pi, punto_nombre, punto_id,
                                     pago_estado, notif_sent_at, fecha_estimada_entrega
                              FROM transacciones WHERE stripe_pi = ? LIMIT 1");
        $row->execute([$piId]);
        return $row->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        webhookLog("createOrderFromMetadata INSERT error: " . $e->getMessage());
        return null;
    }
}

/**
 * Handle payment_intent.payment_failed — log only, no email.
 */
function handlePaymentFailed($paymentIntent) {
    $piId = $paymentIntent->id ?? '';
    $pmTypes = $paymentIntent->payment_method_types ?? [];
    $lastError = $paymentIntent->last_payment_error->message ?? 'unknown';

    webhookLog("payment_intent.payment_failed: $piId | method_types: " . implode(',', $pmTypes) . " | error: $lastError");
}

/**
 * Send confirmation email using the same template as confirmar-orden.php (contado).
 */
function sendConfirmationEmail($order, $methodLabel) {
    $nombre      = $order['nombre']       ?? '';
    $email       = $order['email']        ?? '';
    $modelo      = $order['modelo']       ?? '';
    $color       = $order['color']        ?? '';
    $ciudad      = $order['ciudad']       ?? '';
    $estado      = $order['estado']       ?? '';
    $total       = floatval($order['total'] ?? 0);
    $pedidoNum   = $order['pedido']       ?? time();
    $puntoNombre = trim($order['punto_nombre'] ?? '');

    if (empty($email)) {
        webhookLog("Cannot send email — no email address for pedido #$pedidoNum");
        return;
    }

    $tienePunto = ($puntoNombre !== '');
    $montoFormateado = '$' . number_format($total, 0, '.', ',') . ' MXN';
    $pagoDescripcion = "Pago $methodLabel de $montoFormateado";
    $whatsapp = '+52 55 1341 6370';

    $n  = htmlspecialchars($nombre);
    $m  = htmlspecialchars($modelo);
    $c  = htmlspecialchars($color);
    $cd = htmlspecialchars($ciudad) . ($estado ? ', ' . htmlspecialchars($estado) : '');

    $td      = 'style="padding:10px 16px;border-bottom:1px solid #E5E7EB;font-size:14px;"';
    $tdl     = 'style="padding:10px 16px;border-bottom:1px solid #E5E7EB;font-size:14px;color:#6B7280;"';
    $section = 'style="margin:0 0 8px;padding:12px 0 6px;font-size:16px;font-weight:800;color:#1a3a5c;border-bottom:2px solid #039fe1;"';

    $cuerpo = '<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tu Voltika est&aacute; confirmada</title></head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;">
<tr><td align="center" style="padding:24px 12px;">
<table width="620" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;max-width:620px;width:100%;box-shadow:0 2px 12px rgba(0,0,0,0.08);">

<!-- Header -->
<tr><td style="background:linear-gradient(135deg,#1a3a5c,#039fe1);padding:28px;text-align:center;">
<img src="https://www.voltika.mx/configurador_prueba/img/voltika_logo_h_white.svg" alt="Voltika" style="height:44px;width:auto;display:block;margin:0 auto;">
<p style="margin:6px 0 0;font-size:13px;color:rgba(255,255,255,0.8);">Movilidad el&eacute;ctrica inteligente</p>
</td></tr>

<!-- Body -->
<tr><td style="padding:28px;">

<h2 style="margin:0 0 8px;font-size:20px;color:#1a3a5c;">Hola, ' . htmlspecialchars($nombre) . ' 👋</h2>
' . ($tienePunto
    ? '<h3 style="margin:0 0 12px;font-size:17px;color:#039fe1;">Tu compra ha sido confirmada correctamente.</h3>
<p style="margin:0 0 24px;font-size:14px;color:#555;line-height:1.7;">Tu Voltika ya est&aacute; en proceso.</p>'
    : '<h3 style="margin:0 0 12px;font-size:17px;color:#039fe1;">Tu Voltika est&aacute; confirmada.</h3>
<p style="margin:0 0 20px;font-size:14px;color:#555;line-height:1.7;">Gracias por tu compra. Hemos recibido tu pago correctamente y tu orden ya est&aacute; en proceso.</p>
<p style="margin:0 0 24px;font-size:14px;color:#555;line-height:1.7;">A partir de este momento, nuestro equipo dar&aacute; seguimiento a tu entrega para que recibas tu moto de forma segura y sin complicaciones.</p>') . '

<!-- DETALLE DE TU COMPRA -->
<div ' . $section . '>DETALLE DE TU COMPRA</div>
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
<tr><td ' . $tdl . '>Cliente</td><td ' . $td . '><strong>' . $n . '</strong></td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>N&uacute;mero de orden</td><td ' . $td . '><strong>#' . $pedidoNum . '</strong></td></tr>
<tr><td ' . $tdl . '>Modelo</td><td ' . $td . '>' . $m . '</td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Color</td><td ' . $td . '>' . $c . '</td></tr>
<tr><td ' . $tdl . '>Ciudad de entrega</td><td ' . $td . '>' . $cd . '</td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Monto pagado</td><td ' . $td . '><strong style="color:#039fe1;">' . $montoFormateado . '</strong></td></tr>
<tr><td ' . $tdl . '>M&eacute;todo de pago</td><td ' . $td . '>' . htmlspecialchars($pagoDescripcion) . '</td></tr>
</table>

' . ($tienePunto ? '
<!-- PUNTO CONFIRMADO -->
<div ' . $section . '>PUNTO DE ENTREGA CONFIRMADO</div>
<div style="background:#E8F4FD;border-radius:10px;padding:16px;margin:12px 0 24px;border:1.5px solid #B3D4FC;">
<p style="margin:0 0 6px;font-size:14px;color:#555;">Tu punto de entrega ha sido registrado correctamente:</p>
<p style="margin:0 0 4px;font-size:15px;font-weight:700;color:#1a3a5c;">&#128073; ' . htmlspecialchars($puntoNombre) . '</p>
<p style="margin:0 0 10px;font-size:15px;font-weight:700;color:#1a3a5c;">&#128073; ' . $cd . '</p>
<p style="margin:0;font-size:13px;color:#555;">Tu punto de entrega ya est&aacute; confirmado. No es necesario realizar ning&uacute;n cambio ni contacto adicional.</p>
</div>

<div ' . $section . '>&iquest;QU&Eacute; SIGUE CON TU VOLTIKA?</div>
<div style="margin-bottom:24px;font-size:14px;color:#555;line-height:1.8;">
<p style="margin:12px 0 4px;"><strong style="color:#1a3a5c;">&#9989; 1. Preparaci&oacute;n de tu moto</strong></p>
<p style="margin:0 0 4px;">Estamos preparando tu Voltika para enviarla al punto que seleccionaste.</p>
<p style="margin:0 0 12px;">Esto incluye: revisi&oacute;n completa, preparaci&oacute;n log&iacute;stica y env&iacute;o seguro.</p>
<p style="margin:0 0 4px;"><strong style="color:#1a3a5c;">&#128666; 2. Env&iacute;o al punto de entrega</strong></p>
<p style="margin:0 0 4px;">Tu moto ser&aacute; enviada directamente al punto seleccionado.</p>
<p style="margin:0 0 12px;">&#128233; Te notificaremos por correo y WhatsApp cuando tu moto llegue al punto.</p>
<p style="margin:0 0 4px;"><strong style="color:#1a3a5c;">&#128295; 3. Preparaci&oacute;n en sitio</strong></p>
<p style="margin:0 0 4px;">Una vez que tu moto llegue: se realiza revisi&oacute;n final y se deja lista para entrega.</p>
<p style="margin:0 0 12px;">&#128233; Te avisaremos nuevamente cuando est&eacute; lista para recogerla.</p>
<p style="margin:0 0 4px;"><strong style="color:#1a3a5c;">&#127949; 4. Entrega de tu Voltika</strong></p>
<p style="margin:0;">Cuando recibas el aviso final: acudes al punto seleccionado, validas tu identidad y recibes tu moto lista para rodar.</p>
</div>

<div ' . $section . '>&iquest;CU&Aacute;NDO RECIBO MI VOLTIKA?</div>
<div style="font-size:14px;color:#555;line-height:1.7;margin:12px 0 24px;">
<p style="margin:0 0 8px;">El tiempo de entrega depende de la disponibilidad y log&iacute;stica en tu zona.</p>
<p style="margin:0 0 4px;">&#128073; No necesitas hacer nada.</p>
<p style="margin:0;">&#128073; Nosotros te mantendremos informado en cada etapa.</p>
</div>'
: '
<!-- QUE SIGUE -->
<div ' . $section . '>&iquest;QU&Eacute; SIGUE?</div>
<div style="margin-bottom:24px;font-size:14px;color:#555;line-height:1.8;">
<p style="margin:12px 0 4px;"><strong style="color:#1a3a5c;">1. Asignaci&oacute;n de punto de entrega</strong></p>
<p style="margin:0 0 12px;">En menos de 48 horas te confirmaremos el punto Voltika autorizado m&aacute;s cercano a tu ubicaci&oacute;n.</p>
<p style="margin:0 0 4px;"><strong style="color:#1a3a5c;">2. Confirmaci&oacute;n de entrega</strong></p>
<p style="margin:0 0 12px;">Recibir&aacute;s por correo y WhatsApp los datos del punto, direcci&oacute;n y fecha estimada.</p>
<p style="margin:0 0 4px;"><strong style="color:#1a3a5c;">3. Entrega de tu Voltika</strong></p>
<p style="margin:0;">Acudes al punto asignado, validas tu identidad y recibes tu moto lista para rodar.</p>
</div>

<!-- CUANDO RECIBO -->
<div ' . $section . '>&iquest;CU&Aacute;NDO RECIBO MI VOLTIKA?</div>
<p style="font-size:14px;color:#555;line-height:1.7;margin:12px 0 24px;">El tiempo de entrega depende de disponibilidad y log&iacute;stica en tu zona.<br>Tu asesor Voltika te confirmar&aacute; la fecha exacta junto con el punto asignado.</p>') . '

<!-- ENTREGA SEGURA -->
<div ' . $section . '>ENTREGA SEGURA (IMPORTANTE)</div>
<div style="background:#FFF8E1;border-radius:8px;padding:16px;margin:12px 0 24px;border-left:4px solid #FFB300;">
<p style="margin:0 0 10px;font-size:14px;color:#333;font-weight:700;">&#128274; Tu n&uacute;mero celular es tu llave de entrega.</p>
<p style="margin:0 0 8px;font-size:13px;color:#555;">Para recibir tu Voltika deber&aacute;s:</p>
<ul style="margin:0 0 10px;padding-left:20px;font-size:13px;color:#555;line-height:1.8;">
<li>Tener acceso a tu n&uacute;mero registrado</li>
<li>Validar un c&oacute;digo de seguridad (OTP)</li>
<li>Presentar identificaci&oacute;n oficial</li>
<li>Confirmar datos de tu compra</li>
</ul>
<p style="margin:0;font-size:13px;color:#555;">Para garantizar una entrega segura, podremos solicitar informaci&oacute;n adicional como apellidos o confirmaci&oacute;n de tu orden.</p>
</div>

<!-- INFO PAGO -->
<div ' . $section . '>INFORMACI&Oacute;N SOBRE TU PAGO</div>
<p style="font-size:14px;color:#555;line-height:1.7;margin:12px 0;">Tu compra ha sido procesada correctamente.</p>
<p style="margin:0 0 24px;"></p>

<!-- CAMBIO DE DATOS -->
<div ' . $section . '>CAMBIO DE DATOS</div>
<p style="font-size:14px;color:#555;line-height:1.7;margin:12px 0 24px;">Si necesitas actualizar tu n&uacute;mero telef&oacute;nico o ciudad de entrega, debes solicitarlo antes de la asignaci&oacute;n de tu punto de entrega.</p>

<!-- SOPORTE -->
<div ' . $section . '>SOPORTE Y ATENCI&Oacute;N</div>
<p style="font-size:14px;color:#555;margin:12px 0 8px;">Estamos contigo en todo momento.</p>
<p style="font-size:14px;margin:0 0 4px;">&#128241; WhatsApp: <a href="https://wa.me/5213416370" style="color:#039fe1;font-weight:700;">' . $whatsapp . '</a></p>
<p style="font-size:14px;margin:0 0 24px;">&#128231; Correo: <a href="mailto:redes@voltika.mx" style="color:#039fe1;font-weight:700;">redes@voltika.mx</a></p>

<!-- TERMINOS -->
<div style="background:#F5F5F5;border-radius:8px;padding:16px;margin-top:8px;">
<p style="font-size:12px;color:#888;margin:0 0 6px;">Tu compra est&aacute; protegida bajo nuestros:</p>
<p style="font-size:12px;margin:0 0 4px;"><a href="https://voltika.mx/docs/tyc_2026.pdf" style="color:#039fe1;">T&eacute;rminos y Condiciones</a></p>
<p style="font-size:12px;margin:0 0 8px;"><a href="https://voltika.mx/docs/privacidad_2026.pdf" style="color:#039fe1;">Aviso de Privacidad</a></p>
<p style="font-size:11px;color:#aaa;margin:0;">Al completar tu compra aceptaste nuestros T&eacute;rminos y Condiciones y Aviso de Privacidad.</p>
</div>

</td></tr>

<!-- Footer -->
<tr><td style="background:#1a3a5c;padding:20px 28px;text-align:center;">
<img src="https://www.voltika.mx/configurador_prueba/img/goelectric.svg" alt="GO electric" style="height:28px;width:auto;margin-bottom:8px;">
<p style="margin:0 0 4px;font-size:14px;font-weight:700;color:#fff;">Voltika M&eacute;xico</p>
<p style="margin:0;font-size:12px;color:rgba(255,255,255,0.6);">Movilidad el&eacute;ctrica inteligente &middot; Mtech Gears, S.A. de C.V.</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>';

    $asunto = $tienePunto
        ? 'Tu Voltika ya est&aacute; en proceso &#128640; Orden #' . $pedidoNum
        : 'Tu Voltika est&aacute; confirmada, Orden #' . $pedidoNum;

    try {
        $sent = sendMail($email, $nombre, $asunto, $cuerpo);
        webhookLog($sent
            ? "Confirmation email SENT to $email for pedido #$pedidoNum ($methodLabel)"
            : "Confirmation email FAILED for $email pedido #$pedidoNum ($methodLabel)"
        );
    } catch (Exception $e) {
        webhookLog("Email exception for pedido #$pedidoNum: " . $e->getMessage());
    }
}

/**
 * Handle payment_intent.created — mark ciclo as pending_manual for OXXO/SPEI.
 * This prevents auto-cobro from charging the card while awaiting OXXO/SPEI acreditation.
 */
function handlePaymentPending($paymentIntent) {
    $piId = $paymentIntent->id ?? '';
    $pmTypes = $paymentIntent->payment_method_types ?? [];

    $isOxxo = in_array('oxxo', $pmTypes);
    $isSpei = in_array('customer_balance', $pmTypes);

    if (!$isOxxo && !$isSpei) return;

    $meta = (array)($paymentIntent->metadata ?? []);
    $cicloId = (int)($meta['ciclo_id'] ?? 0);

    if (!$cicloId) {
        webhookLog("payment_intent.created for OXXO/SPEI but no ciclo_id in metadata: $piId");
        return;
    }

    webhookLog("Marking ciclo #$cicloId as pending_manual (OXXO/SPEI payment pending): $piId");

    try {
        $pdo = getDB();
        $pdo->prepare("
            UPDATE ciclos_pago
            SET estado = 'pending_manual', stripe_payment_intent = ?
            WHERE id = ? AND estado IN ('pending','overdue')
        ")->execute([$piId, $cicloId]);
    } catch (PDOException $e) {
        webhookLog("pending_manual update error: " . $e->getMessage());
    }
}

/**
 * Unified post-purchase notification dispatcher (email + WhatsApp + SMS).
 *
 * Routes to one of 4 `compra_confirmada_*` templates based on the two axes:
 *   - tpago  → contado/MSI/unico vs credito/enganche/parcial
 *   - punto  → preselected delivery point vs pending assignment
 *
 * Each template ships its own rich email_html + WhatsApp body + SMS variant
 * (see voltikaBuildCompraTemplate() in voltika-notify.php). This replaces the
 * old split between sendConfirmationEmail() (email-only) and
 * sendPurchaseWhatsApp() (WA+SMS), which caused duplicate email sends and
 * inconsistent wording across channels.
 */
function sendPurchaseNotifications($order) {
    try {
        require_once __DIR__ . '/voltika-notify.php';

        $puntoNombre = trim($order['punto_nombre'] ?? '');
        $tienePunto  = ($puntoNombre !== '' && ($order['punto_id'] ?? '') !== 'centro-cercano');
        $ciudadFull  = ($order['ciudad'] ?? '') . (($order['estado'] ?? '') ? ', ' . $order['estado'] : '');
        $tpago       = $order['tpago'] ?? 'contado';
        if ($tpago === 'unico') $tpago = 'contado';
        $esCredito   = in_array($tpago, ['credito', 'enganche', 'parcial'], true);

        // Resolve punto details + delivery ETA for the new template variables
        $direccionPunto = '';
        $linkMaps       = '';
        if ($tienePunto) {
            try {
                $pdo = getDB();
                $ps = $pdo->prepare("SELECT direccion, ciudad, estado FROM puntos_voltika WHERE nombre = ? AND activo = 1 LIMIT 1");
                $ps->execute([$puntoNombre]);
                $pRow = $ps->fetch(PDO::FETCH_ASSOC);
                if ($pRow) {
                    $direccionPunto = trim($pRow['direccion'] ?? '');
                    $mapsAddr = $puntoNombre . ($direccionPunto ? ', ' . $direccionPunto : '');
                    if ($pRow['ciudad']) $mapsAddr .= ', ' . $pRow['ciudad'];
                    if ($pRow['estado']) $mapsAddr .= ', ' . $pRow['estado'];
                    $linkMaps = 'https://maps.google.com/?q=' . urlencode($mapsAddr);
                }
            } catch (Throwable $e) { webhookLog('punto lookup: ' . $e->getMessage()); }
        }

        // Estimated delivery date — use order field if set, otherwise today+10 days.
        $fechaEstimada = '';
        if (!empty($order['fecha_estimada_entrega'])) {
            $ts = strtotime($order['fecha_estimada_entrega']);
            if ($ts) $fechaEstimada = date('j/n/Y', $ts);
        }
        if (!$fechaEstimada) {
            $fechaEstimada = date('j/n/Y', strtotime('+10 days'));
        }

        // Weekly payment amount for credit orders (if we can resolve it).
        $montoSemanal = '';
        if ($esCredito) {
            try {
                $pdo = getDB();
                $ss = $pdo->prepare("SELECT monto_semanal FROM subscripciones_credito
                    WHERE email = ? OR telefono = ? ORDER BY id DESC LIMIT 1");
                $ss->execute([$order['email'] ?? '', $order['telefono'] ?? '']);
                $ms = $ss->fetchColumn();
                if ($ms) $montoSemanal = number_format((float)$ms, 2);
            } catch (Throwable $e) {}
        }

        $notifyData = [
            'pedido'          => $order['pedido'] ?? '',
            'nombre'          => $order['nombre'] ?? '',
            'modelo'          => $order['modelo'] ?? '',
            'color'           => $order['color']  ?? '',
            'punto'           => $puntoNombre,
            'ciudad'          => $ciudadFull,
            'direccion_punto' => $direccionPunto,
            'link_maps'       => $linkMaps,
            'fecha_estimada'  => $fechaEstimada,
            'monto_semanal'   => $montoSemanal,
            'telefono'        => $order['telefono'] ?? '',
            'email'           => $order['email']    ?? '',
            'whatsapp'        => $order['telefono'] ?? '',
            'cliente_id'      => null,
        ];

        // Route to one of the 4 purchase-confirmation templates
        $tpl = 'compra_confirmada_'
             . ($esCredito ? 'credito' : 'contado')
             . ($tienePunto ? '_punto' : '_sin_punto');
        voltikaNotify($tpl, $notifyData);

        // Delayed portal-access message (5 min after purchase confirmation).
        // Customer-facing wording per 2026-04-19 brief — handled by
        // portal_contado / portal_msi / portal_plazos.
        if ($esCredito) {
            voltikaNotifyDelayed('portal_plazos', $notifyData, 300);
        } elseif ($tpago === 'msi') {
            voltikaNotifyDelayed('portal_msi', $notifyData, 300);
        } else {
            voltikaNotifyDelayed('portal_contado', $notifyData, 300);
        }

        webhookLog("Purchase notification [$tpl] sent for " . ($order['email'] ?? 'unknown'));
    } catch (Throwable $e) {
        webhookLog("Purchase notification error: " . $e->getMessage());
    }
}

/**
 * Handle ciclos_pago update for subscription payments.
 * Reads subscripcion_id and ciclos from PaymentIntent metadata.
 * Previously handled by a separate portal webhook — now unified here.
 *
 * Determines payment type from metadata:
 *   tipo=auto_cobro → paid_auto (from cron)
 *   otherwise       → paid_manual (OXXO, SPEI, manual card, advance payment)
 */
function handleCiclosPagoUpdate($paymentIntent) {
    $piId = $paymentIntent->id ?? '';
    $meta = (array)($paymentIntent->metadata ?? []);
    $subId  = (int)($meta['subscripcion_id'] ?? 0);
    $ciclos = $meta['ciclos'] ?? '';

    // Also handle single ciclo_id from manual payment flows
    $cicloId = (int)($meta['ciclo_id'] ?? 0);
    if (!$subId && !$ciclos && !$cicloId) return;

    $tipo = ($meta['tipo'] ?? '') === 'auto_cobro' ? 'paid_auto' : 'paid_manual';

    // Single ciclo update (from manual payment / OXXO / SPEI)
    if ($cicloId && !$ciclos) {
        webhookLog("Updating single ciclo #$cicloId to $tipo, PI=$piId");
        try {
            $pdo = getDB();
            $pdo->prepare("UPDATE ciclos_pago SET estado = ?, stripe_payment_intent = ?, fecha_pago = NOW()
                WHERE id = ? AND estado NOT IN ('paid_manual','paid_auto')")
                ->execute([$tipo, $piId, $cicloId]);
            webhookLog("ciclo #$cicloId updated to $tipo");
        } catch (PDOException $e) {
            webhookLog("ciclo update DB ERROR: " . $e->getMessage());
        }
        return;
    }

    // Batch ciclos update (from auto-cobro or multi-week payment)
    if (!$subId || !$ciclos) return;

    webhookLog("Updating ciclos_pago: subscripcion=$subId, ciclos=$ciclos, PI=$piId, tipo=$tipo");

    try {
        $pdo = getDB();
        $nums = array_map('intval', explode(',', $ciclos));
        $ph = implode(',', array_fill(0, count($nums), '?'));
        $stmt = $pdo->prepare("UPDATE ciclos_pago SET estado = ?, stripe_payment_intent = ?, fecha_pago = NOW()
            WHERE subscripcion_id = ? AND semana_num IN ($ph) AND estado NOT IN ('paid_manual','paid_auto')");
        $stmt->execute([$tipo, $piId, $subId, ...$nums]);
        $updated = $stmt->rowCount();
        webhookLog("ciclos_pago updated: $updated rows for subscripcion=$subId");
    } catch (PDOException $e) {
        webhookLog("ciclos_pago DB ERROR: " . $e->getMessage());
    }
}


// ── Tech Spec EN §7 — Chargeback / dispute escalation handlers ──────────
// Stripe fires charge.dispute.* on the original charge (not the
// PaymentIntent). We resolve back to our customer + order via
// transacciones.stripe_pi (charge → PI lookup is implicit because we
// store the PI). On chargeback: create an escalation row and freeze the
// related subscription. On resolution: close the escalation; the
// admin/php/escalations/actualizar.php side-effect resumes the
// subscription if no other dispute is open.

function handleChargebackOpened($dispute): void {
    $disputeId = $dispute->id ?? '';
    $piId      = $dispute->payment_intent ?? ($dispute->charge ?? '');
    $reason    = $dispute->reason ?? 'unknown';
    $amount    = ($dispute->amount ?? 0) / 100;
    $status    = $dispute->status ?? 'needs_response';
    webhookLog("dispute opened: $disputeId · PI=$piId · reason=$reason · status=$status");

    try {
        $pdo = getDB();
        _ensureEscalationsTable($pdo);

        // Resolve customer + order from PI
        $clienteId = null; $transId = null; $email = ''; $nombre = '';
        if ($piId) {
            $st = $pdo->prepare("SELECT id, email, nombre FROM transacciones
                                 WHERE stripe_pi = ? ORDER BY id DESC LIMIT 1");
            $st->execute([$piId]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $transId = (int)$row['id'];
                $email   = (string)$row['email'];
                $nombre  = (string)$row['nombre'];
                if ($email) {
                    $cs = $pdo->prepare("SELECT id FROM clientes WHERE email = ? ORDER BY id DESC LIMIT 1");
                    $cs->execute([$email]);
                    $clienteId = (int)($cs->fetchColumn() ?: 0) ?: null;
                }
            }
        }

        // Idempotency — if an open escalation already exists for this dispute,
        // bail out (Stripe can re-send the same event during retries).
        $exists = $pdo->prepare("SELECT id FROM escalations
                                  WHERE kind IN ('chargeback','dispute')
                                    AND ref_externa = ?
                                    AND estado IN ('open','in_progress')
                                  LIMIT 1");
        $exists->execute([$disputeId]);
        if ($exists->fetchColumn()) {
            webhookLog("dispute $disputeId already escalated — skipping");
            return;
        }

        $pdo->prepare("INSERT INTO escalations
                (kind, severity, cliente_id, transaccion_id, ref_externa,
                 titulo, detalle, asignado_a)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                'chargeback', 'critical', $clienteId, $transId, $disputeId,
                'Contracargo Stripe' . ($nombre ? ' — ' . $nombre : ''),
                "Razón: $reason · Monto: $" . number_format($amount, 2) . " MXN · Estado Stripe: $status · PI: $piId",
                'finance',
            ]);

        // Freeze related subscription so auto-cobro stops charging the
        // disputed customer (per spec: open dispute = block all new ops).
        if ($clienteId) {
            $pdo->prepare("UPDATE subscripciones_credito
                           SET estado = 'disputada'
                           WHERE cliente_id = ? AND estado IN ('activa','pendiente_activacion')")
                ->execute([$clienteId]);
        }

        // ── Auto-attach the Defense Dossier to the Stripe dispute ──────
        // Customer brief 2026-04-29: "we need the system to defend
        // disputed customer payments automatically". When a chargeback
        // arrives we must respond with evidence within 7 days; do it
        // immediately while the data is fresh.
        if ($transId) {
            try {
                $motoStmt = $pdo->prepare("SELECT id FROM inventario_motos WHERE transaccion_id = ? LIMIT 1");
                $motoStmt->execute([$transId]);
                $motoId = (int)($motoStmt->fetchColumn() ?: 0);
                if ($motoId) {
                    require_once __DIR__ . '/dossier-defensa.php';
                    $r = dossierBuild($motoId, [
                        'motivo' => 'chargeback_response',
                        'stripe_dispute_id' => $disputeId,
                    ]);
                    if ($r['ok']) {
                        webhookLog("dossier built for dispute $disputeId · id={$r['dossier_id']} · pdf={$r['pdf_path']}");
                        // Try to attach the master PDF to the Stripe dispute.
                        $attached = _stripeAttachDossierToDispute($disputeId, $r['pdf_path'], $pdo);
                        if ($attached['ok']) {
                            $pdo->prepare("UPDATE dossiers_defensa
                                           SET enviado_a_stripe = 1, enviado_at = NOW()
                                           WHERE id = ?")
                                ->execute([$r['dossier_id']]);
                            webhookLog("dossier auto-attached to Stripe dispute $disputeId");
                        } else {
                            webhookLog("dossier auto-attach failed: " . $attached['error']);
                        }
                    } else {
                        webhookLog("dossier build failed for dispute $disputeId: " . ($r['error'] ?? 'unknown'));
                    }
                }
            } catch (Throwable $e) {
                webhookLog("dossier auto-attach error: " . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        webhookLog("chargeback handler error: " . $e->getMessage());
    }
}

/**
 * Attach the Defense Dossier master PDF to a Stripe dispute.
 *
 * Stripe Disputes API flow:
 *   1. POST /v1/files (purpose=dispute_evidence) → returns file id
 *   2. POST /v1/disputes/{id} with evidence[uncategorized_file]=fileId
 *      and evidence_details[submit]=false (we let admin click Submit
 *      manually after reviewing what we attached).
 *
 * Falls back gracefully if STRIPE_SECRET_KEY is missing.
 */
function _stripeAttachDossierToDispute(string $disputeId, string $relPdfPath, PDO $pdo): array {
    $stripeKey = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : (getenv('STRIPE_SECRET_KEY') ?: '');
    if (!$stripeKey) return ['ok' => false, 'error' => 'STRIPE_SECRET_KEY ausente'];

    $absPdf = __DIR__ . '/../' . ltrim($relPdfPath, '/');
    if (!file_exists($absPdf)) return ['ok' => false, 'error' => 'PDF dossier no encontrado: ' . $absPdf];

    // Step 1: upload as a Stripe File with purpose=dispute_evidence.
    // Files API uses files.stripe.com with multipart form-data.
    $cfile = new CURLFile($absPdf, 'application/pdf', 'voltika_dossier.pdf');
    $ch = curl_init('https://files.stripe.com/v1/files');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $stripeKey . ':',
        CURLOPT_HTTPHEADER     => ['Stripe-Account: '],
        CURLOPT_POSTFIELDS     => ['purpose' => 'dispute_evidence', 'file' => $cfile],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err || $code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => "Stripe file upload HTTP $code · $err · " . substr($raw, 0, 300)];
    }
    $fileResp = json_decode($raw, true) ?: [];
    $fileId = $fileResp['id'] ?? null;
    if (!$fileId) return ['ok' => false, 'error' => 'Stripe file id ausente en respuesta'];

    // Step 2: PATCH the dispute with the evidence file.
    $body = http_build_query([
        'evidence[uncategorized_file]'  => $fileId,
        'evidence[uncategorized_text]'  => 'Voltika Defense Dossier — manifest SHA-256 + Cincel NOM-151 timestamp. ZIP pack with all signed contracts, identity validation (Truora INE+selfie), CFDI 4.0, REPUVE notice, OTP-validated delivery acta + pagaré, IP/UA/geolocation evidence, full notification + payment logs. See attached PDF.',
        'evidence_details[submit]'     => 'false',
    ]);
    $ch = curl_init('https://api.stripe.com/v1/disputes/' . urlencode($disputeId));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $stripeKey . ':',
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw2 = curl_exec($ch);
    $code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err2  = curl_error($ch);
    curl_close($ch);
    if ($err2 || $code2 < 200 || $code2 >= 300) {
        return ['ok' => false, 'error' => "Stripe dispute update HTTP $code2 · " . substr($raw2, 0, 300)];
    }
    return ['ok' => true, 'file_id' => $fileId];
}

function handleChargebackResolved($dispute): void {
    $disputeId = $dispute->id ?? '';
    $status    = $dispute->status ?? 'lost';
    webhookLog("dispute resolved: $disputeId · status=$status");

    try {
        $pdo = getDB();
        _ensureEscalationsTable($pdo);

        // Mark every open escalation for this dispute as resolved/closed.
        // Stripe `status` won/lost determines outcome wording.
        $newEstado = $status === 'won' ? 'resolved' : 'closed';
        $row = $pdo->prepare("SELECT id, cliente_id FROM escalations
                              WHERE ref_externa = ?
                                AND kind IN ('chargeback','dispute')
                                AND estado IN ('open','in_progress')");
        $row->execute([$disputeId]);
        $escalations = $row->fetchAll(PDO::FETCH_ASSOC);
        foreach ($escalations as $esc) {
            $pdo->prepare("UPDATE escalations
                           SET estado = ?, resolved_at = NOW(),
                               notas = COALESCE(notas, '') || ?
                           WHERE id = ?")
                ->execute([
                    $newEstado,
                    "\n[" . date('Y-m-d H:i') . "] Stripe dispute closed with status=$status",
                    (int)$esc['id'],
                ]);
            // If no other open dispute exists for the customer, resume billing.
            if (!empty($esc['cliente_id'])) {
                $still = $pdo->prepare("SELECT COUNT(*) FROM escalations
                    WHERE cliente_id = ?
                      AND kind IN ('chargeback','dispute','profeco')
                      AND estado IN ('open','in_progress')");
                $still->execute([(int)$esc['cliente_id']]);
                if ((int)$still->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE subscripciones_credito
                                   SET estado = 'activa'
                                   WHERE cliente_id = ? AND estado = 'disputada'")
                        ->execute([(int)$esc['cliente_id']]);
                }
            }
        }
    } catch (Throwable $e) {
        webhookLog("chargeback resolution handler error: " . $e->getMessage());
    }
}

function _ensureEscalationsTable(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS escalations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(40) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'critical',
            cliente_id INT NULL,
            transaccion_id INT NULL,
            moto_id INT NULL,
            ref_externa VARCHAR(120) NULL,
            titulo VARCHAR(200) NOT NULL,
            detalle TEXT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'open',
            asignado_a VARCHAR(80) NULL,
            notas MEDIUMTEXT NULL,
            freg DATETIME DEFAULT CURRENT_TIMESTAMP,
            fmod DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL,
            INDEX idx_estado_kind (estado, kind),
            INDEX idx_cliente (cliente_id),
            INDEX idx_transaccion (transaccion_id),
            INDEX idx_ref (ref_externa)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { webhookLog('escalations table create: ' . $e->getMessage()); }
}
