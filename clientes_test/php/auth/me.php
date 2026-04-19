<?php
require_once __DIR__ . '/../bootstrap.php';
$cid = $_SESSION['portal_cliente_id'] ?? null;
if (!$cid) portalJsonOut(['authenticated' => false]);

try {
    $pdo = getDB();

    // The clientes schema varies between deployments — some have separate
    // apellido_paterno / apellido_materno columns, others store the full name
    // inside `nombre`. Probe before SELECT so a missing column never tanks
    // the whole row (which would log the user out).
    $cols = $pdo->query("SHOW COLUMNS FROM clientes")->fetchAll(PDO::FETCH_COLUMN);
    $colSet = array_flip($cols);
    $select = ['id', 'nombre', 'email', 'telefono'];
    if (isset($colSet['apellido_paterno'])) $select[] = 'apellido_paterno';
    if (isset($colSet['apellido_materno'])) $select[] = 'apellido_materno';

    $stmt = $pdo->prepare("SELECT " . implode(',', $select) . " FROM clientes WHERE id = ?");
    $stmt->execute([$cid]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($c) {
        // Compose the display name. If the deployment uses split fields, join
        // them. Otherwise the full name is already in `nombre` — use as-is.
        $parts = array_filter([
            trim((string)($c['nombre'] ?? '')),
            trim((string)($c['apellido_paterno'] ?? '')),
            trim((string)($c['apellido_materno'] ?? '')),
        ], 'strlen');
        $c['nombre_completo'] = $parts ? implode(' ', $parts) : '';
    }
} catch (Throwable $e) {
    error_log('me.php: ' . $e->getMessage());
    $c = null;
}

portalJsonOut(['authenticated' => true, 'cliente' => $c]);
