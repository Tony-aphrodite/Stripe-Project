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
    $piId = $paymentIntent->id ?? '';
    $pmTypes = $paymentIntent->payment_method_types ?? [];
    $amount  = ($paymentIntent->amount ?? 0) / 100; // centavos -> MXN

    webhookLog("payment_intent.succeeded: $piId | method_types: " . implode(',', $pmTypes) . " | amount: $amount MXN");

    // Only process OXXO and SPEI (customer_balance) — card payments are confirmed client-side
    $isOxxo = in_array('oxxo', $pmTypes);
    $isSpei = in_array('customer_balance', $pmTypes);

    if (!$isOxxo && !$isSpei) {
        webhookLog("Skipping card payment $piId — already confirmed client-side");
        return;
    }

    $methodLabel = $isOxxo ? 'OXXO' : 'SPEI';
    webhookLog("Processing $methodLabel payment confirmation for PI: $piId");

    // ── Look up order in transacciones ───────────────────────────────────────
    try {
        $pdo = getDB();

        // Ensure pago_estado column exists (may be missing on older schemas)
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM transacciones")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('pago_estado', $cols, true)) {
                $pdo->exec("ALTER TABLE transacciones ADD COLUMN pago_estado VARCHAR(20) NULL");
            }
        } catch (PDOException $ignore) {}

        $stmt = $pdo->prepare("
            SELECT nombre, email, telefono, modelo, color, ciudad, estado, cp,
                   tpago, precio, total, pedido, stripe_pi
            FROM transacciones
            WHERE stripe_pi = ?
            LIMIT 1
        ");
        $stmt->execute([$piId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($order) {
            webhookLog("Found order in transacciones: pedido #{$order['pedido']} for {$order['email']}");

            // Mark payment as completed now that SPEI/OXXO funds arrived
            try {
                $upd = $pdo->prepare("UPDATE transacciones SET pago_estado = 'pagada' WHERE stripe_pi = ? AND (pago_estado IS NULL OR pago_estado IN ('pendiente',''))");
                $upd->execute([$piId]);
                if ($upd->rowCount() > 0) {
                    webhookLog("Updated pago_estado to 'pagada' for PI $piId");
                }
                // Also update inventario_motos if a bike is already assigned
                $updMoto = $pdo->prepare("UPDATE inventario_motos SET pago_estado = 'pagada' WHERE stripe_pi = ? AND (pago_estado IS NULL OR pago_estado IN ('pendiente',''))");
                $updMoto->execute([$piId]);
            } catch (PDOException $e) {
                webhookLog("pago_estado update error: " . $e->getMessage());
            }

            // Bike assignment is now manual via Admin → Ventas panel
            webhookLog("Pedido VK-{$order['pedido']} awaiting manual bike assignment in admin panel");

            sendConfirmationEmail($order, $methodLabel);
            return;
        }

        // ── Fallback: check pedidos table ────────────────────────────────────
        webhookLog("Not found in transacciones, checking pedidos table...");

        // pedidos table uses pedido_num column, not stripe_pi
        // Try matching by Stripe metadata or receipt_email
        $metadata = $paymentIntent->metadata ?? null;
        $receiptEmail = $paymentIntent->receipt_email ?? '';

        if ($receiptEmail) {
            $stmt = $pdo->prepare("
                SELECT pedido_num AS pedido, nombre, email, telefono, modelo, color,
                       ciudad, estado, cp, metodo AS tpago, total, total AS precio
                FROM pedidos
                WHERE email = ?
                ORDER BY freg DESC
                LIMIT 1
            ");
            $stmt->execute([$receiptEmail]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($order) {
                webhookLog("Found order in pedidos: pedido #{$order['pedido']} for {$order['email']}");
                sendConfirmationEmail($order, $methodLabel);
                return;
            }
        }

        webhookLog("WARNING: No order found for PI $piId in transacciones or pedidos");

    } catch (PDOException $e) {
        webhookLog("DB ERROR: " . $e->getMessage());
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
    $nombre    = $order['nombre']  ?? '';
    $email     = $order['email']   ?? '';
    $modelo    = $order['modelo']  ?? '';
    $color     = $order['color']   ?? '';
    $ciudad    = $order['ciudad']  ?? '';
    $estado    = $order['estado']  ?? '';
    $total     = floatval($order['total'] ?? 0);
    $pedidoNum = $order['pedido']  ?? time();

    if (empty($email)) {
        webhookLog("Cannot send email — no email address for pedido #$pedidoNum");
        return;
    }

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
<h3 style="margin:0 0 12px;font-size:17px;color:#039fe1;">Tu Voltika est&aacute; confirmada.</h3>
<p style="margin:0 0 20px;font-size:14px;color:#555;line-height:1.7;">Gracias por tu compra. Hemos recibido tu pago correctamente y tu orden ya est&aacute; en proceso.</p>
<p style="margin:0 0 24px;font-size:14px;color:#555;line-height:1.7;">A partir de este momento, nuestro equipo dar&aacute; seguimiento a tu entrega para que recibas tu moto de forma segura y sin complicaciones.</p>

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
<p style="font-size:14px;color:#555;line-height:1.7;margin:12px 0 24px;">El tiempo de entrega depende de disponibilidad y log&iacute;stica en tu zona.<br>Tu asesor Voltika te confirmar&aacute; la fecha exacta junto con el punto asignado.</p>

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

    $asunto = 'Tu Voltika est&aacute; confirmada, Orden #' . $pedidoNum;

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
 * Handle ciclos_pago update for subscription payments.
 * Reads subscripcion_id and ciclos from PaymentIntent metadata.
 * Previously handled by a separate portal webhook — now unified here.
 */
function handleCiclosPagoUpdate($paymentIntent) {
    $piId = $paymentIntent->id ?? '';
    $meta = (array)($paymentIntent->metadata ?? []);
    $subId = (int)($meta['subscripcion_id'] ?? 0);
    $ciclos = $meta['ciclos'] ?? '';

    if (!$subId || !$ciclos) return;

    webhookLog("Updating ciclos_pago: subscripcion=$subId, ciclos=$ciclos, PI=$piId");

    try {
        $pdo = getDB();
        $nums = array_map('intval', explode(',', $ciclos));
        $ph = implode(',', array_fill(0, count($nums), '?'));
        $stmt = $pdo->prepare("UPDATE ciclos_pago SET estado = 'paid_auto', stripe_payment_intent = ?
            WHERE subscripcion_id = ? AND semana_num IN ($ph) AND estado NOT IN ('paid_manual','paid_auto')");
        $stmt->execute([$piId, $subId, ...$nums]);
        $updated = $stmt->rowCount();
        webhookLog("ciclos_pago updated: $updated rows for subscripcion=$subId");
    } catch (PDOException $e) {
        webhookLog("ciclos_pago DB ERROR: " . $e->getMessage());
    }
}
