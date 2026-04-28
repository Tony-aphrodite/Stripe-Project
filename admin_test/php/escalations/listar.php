<?php
/**
 * GET — List escalations (filtered by estado).
 * Query: ?estado=open|in_progress|resolved|closed (default: open + in_progress)
 *        ?kind=chargeback|profeco|...
 *        ?limit=50
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$pdo = getDB();

// Lazy create so this endpoint works on a fresh schema.
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
        INDEX idx_estado_kind (estado, kind)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$estado = trim((string)($_GET['estado'] ?? ''));
$kind   = trim((string)($_GET['kind']   ?? ''));
$limit  = max(1, min(500, (int)($_GET['limit'] ?? 100)));

$where = [];
$params = [];
if ($estado !== '') {
    $where[] = "estado = ?";
    $params[] = $estado;
} else {
    $where[] = "estado IN ('open','in_progress')";
}
if ($kind !== '') {
    $where[] = "kind = ?";
    $params[] = $kind;
}
$sql = "SELECT id, kind, severity, cliente_id, transaccion_id, moto_id,
               ref_externa, titulo, detalle, estado, asignado_a, notas,
               freg, fmod, resolved_at
        FROM escalations
        WHERE " . implode(' AND ', $where) . "
        ORDER BY (estado='open') DESC, freg DESC
        LIMIT " . $limit;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

adminJsonOut(['ok' => true, 'total' => count($rows), 'escalations' => $rows]);
