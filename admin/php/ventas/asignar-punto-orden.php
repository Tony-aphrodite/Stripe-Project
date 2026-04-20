<?php
/**
 * POST JSON — Assign a delivery punto to an order placed with
 * "Centro Voltika cercano". Updates transacciones and fires the
 * `punto_asignado` rich notification (email + WhatsApp + SMS).
 *
 * Body: { transaccion_id, punto_id }
 *
 * Response: { ok, punto: {...}, pedido_corto, notify: {...} }
 *
 * Distinct from /admin/php/inventario/asignar-punto.php which links a
 * physical moto to a punto (creates an envío). This endpoint only sets the
 * order's preferred punto so the Envíos flow can pick it up.
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d     = adminJsonIn();
$txId  = (int)($d['transaccion_id'] ?? 0);
$pId   = (int)($d['punto_id'] ?? 0);
if (!$txId) adminJsonOut(['error' => 'transaccion_id requerido'], 400);
if (!$pId)  adminJsonOut(['error' => 'punto_id requerido'], 400);

$pdo = getDB();

// Verify punto + order
$pStmt = $pdo->prepare("SELECT * FROM puntos_voltika WHERE id=? AND activo=1");
$pStmt->execute([$pId]);
$punto = $pStmt->fetch(PDO::FETCH_ASSOC);
if (!$punto) adminJsonOut(['error' => 'Punto no encontrado o inactivo'], 404);

$tStmt = $pdo->prepare("SELECT * FROM transacciones WHERE id=? LIMIT 1");
$tStmt->execute([$txId]);
$tx = $tStmt->fetch(PDO::FETCH_ASSOC);
if (!$tx) adminJsonOut(['error' => 'Transacción no encontrada'], 404);

// Ensure the punto_nota column exists (idempotent)
try {
    $cols = $pdo->query("SHOW COLUMNS FROM transacciones")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('punto_nota', $cols, true)) {
        $pdo->exec("ALTER TABLE transacciones ADD COLUMN punto_nota TEXT NULL");
    }
} catch (Throwable $e) { error_log('asignar-punto-orden ensure column: ' . $e->getMessage()); }

// Update transacciones
$nota = 'Asignado manualmente por admin uid=' . $uid . ' el ' . date('Y-m-d H:i:s');
$pdo->prepare("UPDATE transacciones SET punto_id=?, punto_nombre=?, punto_nota=? WHERE id=?")
   ->execute([(string)$punto['id'], $punto['nombre'], $nota, $txId]);

adminLog('orden_punto_asignar', [
    'tx_id' => $txId, 'punto_id' => $pId, 'punto_nombre' => $punto['nombre'],
]);

// ── Notify the customer with the rich punto_asignado template ───────────────
$notifyResult = ['skipped' => true, 'reason' => 'no contact info'];
$clienteTel   = $tx['telefono'] ?? '';
$clienteEmail = $tx['email']    ?? '';

if ($clienteTel || $clienteEmail) {
    $notifyPath = null;
    foreach ([
        __DIR__ . '/../../../configurador_prueba_test/php/voltika-notify.php',
        __DIR__ . '/../../../configurador_prueba/php/voltika-notify.php',
    ] as $p) {
        if (is_file($p)) { $notifyPath = $p; break; }
    }
    if ($notifyPath) { try { require_once $notifyPath; } catch (Throwable $e) { error_log('notify include: ' . $e->getMessage()); } }

    if (function_exists('voltikaNotify')) {
        try {
            // Resolve display fields
            $direccionPunto = trim(($punto['direccion'] ?? '')
                . ($punto['colonia'] ? ', ' . $punto['colonia'] : '')
                . ($punto['cp']      ? ' CP ' . $punto['cp']   : ''));
            if (!$direccionPunto) $direccionPunto = $punto['calle_numero'] ?? '';

            $linkMaps = function_exists('voltikaBuildMapsLink')
                ? voltikaBuildMapsLink($direccionPunto, $punto['ciudad'] ?? '',
                    isset($punto['lat']) ? (float)$punto['lat'] : null,
                    isset($punto['lng']) ? (float)$punto['lng'] : null)
                : 'https://voltika.mx/clientes/';

            // Estimated delivery — 10 days from today (matches confirmar-orden default)
            $fechaIso   = date('Y-m-d', strtotime('+10 days'));
            $fechaHuman = function_exists('voltikaFormatFechaHuman')
                ? voltikaFormatFechaHuman($fechaIso)
                : $fechaIso;

            $pedidoCorto = function_exists('voltikaResolvePedidoCorto')
                ? voltikaResolvePedidoCorto($pdo, $txId)
                : 'VK-' . ($tx['pedido'] ?? '');

            $notifyResult = voltikaNotify('punto_asignado', [
                'cliente_id'      => null,
                'nombre'          => $tx['nombre'] ?? '',
                'pedido'          => $tx['pedido'] ?? '',
                'pedido_corto'    => $pedidoCorto,
                'modelo'          => $tx['modelo'] ?? '',
                'color'           => $tx['color']  ?? '',
                'punto'           => $punto['nombre'],
                'ciudad'          => $punto['ciudad'] ?? '',
                'direccion_punto' => $direccionPunto,
                'link_maps'       => $linkMaps,
                'fecha'           => $fechaHuman, // legacy alias
                'fecha_estimada'  => $fechaHuman,
                'telefono'        => $clienteTel,
                'email'           => $clienteEmail,
                'whatsapp'        => $clienteTel,
            ]);
        } catch (Throwable $e) {
            error_log('notify punto_asignado: ' . $e->getMessage());
            $notifyResult = ['error' => $e->getMessage()];
        }
    } else {
        $notifyResult = ['skipped' => true, 'reason' => 'voltikaNotify unavailable'];
    }
}

adminJsonOut([
    'ok'           => true,
    'tx_id'        => $txId,
    'punto'        => [
        'id'     => (int)$punto['id'],
        'nombre' => $punto['nombre'],
        'ciudad' => $punto['ciudad'] ?? '',
        'estado' => $punto['estado'] ?? '',
    ],
    'pedido_corto' => $pedidoCorto ?? null,
    'notify'       => $notifyResult,
]);
