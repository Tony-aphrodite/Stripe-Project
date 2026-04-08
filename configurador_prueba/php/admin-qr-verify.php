<?php
/**
 * Voltika Admin - Verificar QR de pedido o motocicleta
 * POST { tipo: 'pedido'|'moto', codigo }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-auth.php';

$dealer = requireDealerAuth();

$json   = json_decode(file_get_contents('php://input'), true) ?? [];
$tipo   = trim($json['tipo']   ?? '');
$codigo = trim($json['codigo'] ?? '');

if (!$tipo || !$codigo) {
    http_response_code(400);
    echo json_encode(['error' => 'tipo y codigo requeridos']);
    exit;
}

try {
    $pdo = getDB();

    // ── QR de Pedido ─────────────────────────────────────────────────────────
    if ($tipo === 'pedido') {
        // Format: VK-PEDIDO:{pedido_num} or just the pedido_num
        $pedidoNum = $codigo;
        if (strpos($codigo, 'VK-PEDIDO:') === 0) {
            $parts = explode(':', $codigo, 3);
            $pedidoNum = $parts[1] ?? $codigo;
        }

        // Search in inventario_motos
        $stmt = $pdo->prepare("
            SELECT id, vin_display, modelo, color, cliente_nombre, estado, pago_estado
            FROM inventario_motos
            WHERE pedido_num = ? AND dealer_id = ? AND activo = 1
            LIMIT 1
        ");
        $stmt->execute([$pedidoNum, $dealer['id']]);
        $moto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$moto) {
            // Try in transacciones/pedidos (historical orders)
            $stmt2 = $pdo->prepare("SELECT pedido, nombre, modelo, color FROM transacciones WHERE pedido = ? LIMIT 1");
            $stmt2->execute([$pedidoNum]);
            $order = $stmt2->fetch(PDO::FETCH_ASSOC);

            if ($order) {
                echo json_encode([
                    'ok'   => true,
                    'tipo' => 'pedido',
                    'data' => [
                        'pedido_num'    => $order['pedido'],
                        'cliente_nombre'=> $order['nombre'],
                        'modelo'        => $order['modelo'],
                        'color'         => $order['color'],
                        'en_inventario' => false,
                    ],
                    'mensaje' => 'Pedido encontrado en historial de ventas',
                ]);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Pedido no encontrado en este punto']);
            }
            exit;
        }

        echo json_encode([
            'ok'   => true,
            'tipo' => 'pedido',
            'moto_id' => (int)$moto['id'],
            'data' => [
                'pedido_num'    => $pedidoNum,
                'cliente_nombre'=> $moto['cliente_nombre'],
                'modelo'        => $moto['modelo'],
                'color'         => $moto['color'],
                'vin_display'   => $moto['vin_display'],
                'estado'        => $moto['estado'],
                'pago_estado'   => $moto['pago_estado'],
                'en_inventario' => true,
            ],
            'mensaje' => 'Pedido verificado correctamente',
        ]);
        exit;
    }

    // ── QR de Moto ───────────────────────────────────────────────────────────
    if ($tipo === 'moto') {
        // Format: VK-MOTO:{vin} or just the VIN
        $vin = $codigo;
        if (strpos($codigo, 'VK-MOTO:') === 0) {
            $parts = explode(':', $codigo, 3);
            $vin   = $parts[1] ?? $codigo;
        }

        $stmt = $pdo->prepare("
            SELECT id, vin_display, modelo, color, cliente_nombre, estado, pago_estado, pedido_num
            FROM inventario_motos
            WHERE vin = ? AND dealer_id = ? AND activo = 1
            LIMIT 1
        ");
        $stmt->execute([$vin, $dealer['id']]);
        $moto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$moto) {
            echo json_encode(['ok' => false, 'error' => 'Motocicleta no encontrada en este punto']);
            exit;
        }

        // Mark QR moto as verified in checklist if exists
        $pdo->prepare("
            UPDATE checklist_entrega SET qr_moto_ok = 1
            WHERE moto_id = ? ORDER BY id DESC LIMIT 1
        ")->execute([$moto['id']]);

        echo json_encode([
            'ok'      => true,
            'tipo'    => 'moto',
            'moto_id' => (int)$moto['id'],
            'data'    => [
                'vin_display'   => $moto['vin_display'],
                'modelo'        => $moto['modelo'],
                'color'         => $moto['color'],
                'cliente_nombre'=> $moto['cliente_nombre'],
                'estado'        => $moto['estado'],
                'pedido_num'    => $moto['pedido_num'],
            ],
            'mensaje' => 'Motocicleta verificada correctamente',
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'tipo inválido']);

} catch (PDOException $e) {
    error_log('Voltika qr-verify error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error interno']);
}
