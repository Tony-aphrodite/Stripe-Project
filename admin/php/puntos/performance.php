<?php
/**
 * GET — Points performance metrics
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$pdo = getDB();
$today = date('Y-m-d');

$safeAll = function($sql, $params = []) use ($pdo) {
    try { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { error_log('puntos/performance: ' . $e->getMessage()); return []; }
};

// Get all active puntos
$puntos = $safeAll("SELECT id, nombre, ciudad, tipo, capacidad, activo FROM puntos_voltika ORDER BY nombre");

// Enrich with performance data
foreach ($puntos as &$p) {
    $pid = $p['id'];
    $pNombre = $p['nombre'];

    // Inventory at this punto
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM inventario_motos WHERE punto_voltika_id=? AND estado IN ('recibida','lista_para_entrega')");
        $st->execute([$pid]);
        $p['inventario_disponible'] = (int)$st->fetchColumn();
    } catch (Throwable $e) { $p['inventario_disponible'] = 0; }

    // Deliveries completed
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM inventario_motos WHERE punto_voltika_id=? AND estado='entregada'");
        $st->execute([$pid]);
        $p['entregas_completadas'] = (int)$st->fetchColumn();
    } catch (Throwable $e) { $p['entregas_completadas'] = 0; }

    // Pending deliveries
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM inventario_motos WHERE punto_voltika_id=? AND estado IN ('lista_para_entrega','por_validar_entrega')");
        $st->execute([$pid]);
        $p['entregas_pendientes'] = (int)$st->fetchColumn();
    } catch (Throwable $e) { $p['entregas_pendientes'] = 0; }

    // Sales this month (via ventas_log or transacciones)
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM inventario_motos WHERE punto_voltika_id=? AND estado='entregada' AND fecha_estado >= DATE_FORMAT(?, '%Y-%m-01')");
        $st->execute([$pid, $today]);
        $p['ventas_mes'] = (int)$st->fetchColumn();
    } catch (Throwable $e) { $p['ventas_mes'] = 0; }

    // Incoming shipments
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM envios WHERE punto_destino_id=? AND estado IN ('lista_para_enviar','enviada')");
        $st->execute([$pid]);
        $p['envios_pendientes'] = (int)$st->fetchColumn();
    } catch (Throwable $e) { $p['envios_pendientes'] = 0; }
}

// Ensure incidencias table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS incidencias_punto (
        id INT AUTO_INCREMENT PRIMARY KEY,
        punto_id INT NOT NULL,
        tipo ENUM('queja','averia','faltante','otro') DEFAULT 'otro',
        descripcion TEXT NOT NULL,
        estado ENUM('abierta','en_proceso','resuelta') DEFAULT 'abierta',
        reportado_por INT DEFAULT NULL,
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_punto (punto_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// Get recent incidents
$incidencias = $safeAll("SELECT ip.*, pv.nombre as punto_nombre
    FROM incidencias_punto ip
    LEFT JOIN puntos_voltika pv ON ip.punto_id = pv.id
    ORDER BY ip.freg DESC LIMIT 50");

adminJsonOut([
    'ok'          => true,
    'puntos'      => $puntos,
    'incidencias' => $incidencias,
]);
