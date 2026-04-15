<?php
/**
 * GET — Global search across customers, orders, inventory
 * ?q=search+term
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador','dealer']);

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) adminJsonOut(['error' => 'Busqueda muy corta (min 2 caracteres)'], 400);

$pdo = getDB();
$like = '%' . $q . '%';
$results = ['clientes' => [], 'ordenes' => [], 'inventario' => [], 'creditos' => []];

// ── Customers (clientes table) ──
try {
    $stmt = $pdo->prepare("SELECT id, nombre, email, telefono, fecha_nacimiento, freg
        FROM clientes
        WHERE nombre LIKE ? OR email LIKE ? OR telefono LIKE ?
        ORDER BY freg DESC LIMIT 20");
    $stmt->execute([$like, $like, $like]);
    $results['clientes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ── Orders (transacciones) ──
try {
    $stmt = $pdo->prepare("SELECT id, pedido, nombre, email, telefono, modelo, color,
        tpago as tipo_pago, total as monto, stripe_pi, freg
        FROM transacciones
        WHERE nombre LIKE ? OR email LIKE ? OR telefono LIKE ?
           OR pedido LIKE ? OR stripe_pi LIKE ?
        ORDER BY freg DESC LIMIT 20");
    $stmt->execute([$like, $like, $like, $like, $like]);
    $results['ordenes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ── Inventory (inventario_motos by VIN) ──
try {
    $stmt = $pdo->prepare("SELECT id, vin, vin_display, modelo, color, estado,
        cliente_nombre, cliente_telefono, punto_nombre
        FROM inventario_motos
        WHERE vin LIKE ? OR vin_display LIKE ? OR modelo LIKE ?
           OR cliente_nombre LIKE ? OR cliente_telefono LIKE ? OR num_motor LIKE ?
        ORDER BY freg DESC LIMIT 20");
    $stmt->execute([$like, $like, $like, $like, $like, $like]);
    $results['inventario'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ── Credit subscriptions ──
try {
    $stmt = $pdo->prepare("SELECT s.id, COALESCE(s.nombre, cl.nombre, '') as nombre, s.email, s.telefono, s.modelo, s.color,
        s.monto_semanal, s.plazo_semanas, s.stripe_customer_id, s.freg
        FROM subscripciones_credito s
        LEFT JOIN clientes cl ON s.cliente_id = cl.id
        WHERE s.nombre LIKE ? OR cl.nombre LIKE ? OR s.email LIKE ? OR s.telefono LIKE ?
        ORDER BY s.freg DESC LIMIT 20");
    $stmt->execute([$like, $like, $like, $like]);
    $results['creditos'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$total = count($results['clientes']) + count($results['ordenes'])
       + count($results['inventario']) + count($results['creditos']);

adminJsonOut([
    'ok'       => true,
    'query'    => $q,
    'total'    => $total,
    'results'  => $results,
]);
