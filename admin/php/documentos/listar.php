<?php
/**
 * GET — List documents per customer or all customers with document status
 * Params: ?cliente_id=&tipo=
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$pdo = getDB();

// Ensure admin documents table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS documentos_cliente (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NOT NULL,
        tipo ENUM('contrato','acta_entrega','factura','carta_factura','seguro','ine','pagare') NOT NULL,
        archivo_url VARCHAR(500) DEFAULT '',
        archivo_nombre VARCHAR(250) DEFAULT '',
        estado ENUM('pendiente','subido','verificado') DEFAULT 'pendiente',
        notas TEXT DEFAULT NULL,
        subido_por INT DEFAULT NULL,
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        factualizado DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_cliente (cliente_id),
        KEY idx_tipo (tipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$clienteId = (int)($_GET['cliente_id'] ?? 0);
$tipo = $_GET['tipo'] ?? '';

if ($clienteId) {
    // Documents for specific customer
    $where = "dc.cliente_id = ?";
    $params = [$clienteId];
    if ($tipo) { $where .= " AND dc.tipo = ?"; $params[] = $tipo; }

    $stmt = $pdo->prepare("SELECT dc.*, c.nombre as cliente_nombre, c.email as cliente_email
        FROM documentos_cliente dc
        LEFT JOIN clientes c ON dc.cliente_id = c.id
        WHERE {$where}
        ORDER BY dc.tipo, dc.freg DESC");
    $stmt->execute($params);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    adminJsonOut(['ok' => true, 'documentos' => $docs]);
} else {
    // Summary: customers with incomplete documents
    $rows = [];
    try {
        $rows = $pdo->query("
            SELECT c.id, c.nombre, c.email, c.telefono,
                   COUNT(dc.id) as docs_subidos,
                   SUM(dc.estado = 'verificado') as docs_verificados,
                   7 as docs_requeridos
            FROM clientes c
            LEFT JOIN documentos_cliente dc ON dc.cliente_id = c.id
            GROUP BY c.id
            ORDER BY docs_subidos ASC, c.nombre ASC
            LIMIT 100
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('documentos/listar: ' . $e->getMessage());
    }

    $tiposDoc = ['contrato','acta_entrega','factura','carta_factura','seguro','ine','pagare'];
    adminJsonOut(['ok' => true, 'clientes' => $rows, 'tipos' => $tiposDoc]);
}
