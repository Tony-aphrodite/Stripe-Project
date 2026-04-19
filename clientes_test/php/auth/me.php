<?php
require_once __DIR__ . '/../bootstrap.php';
$cid = $_SESSION['portal_cliente_id'] ?? null;
if (!$cid) portalJsonOut(['authenticated' => false]);
try {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, nombre, apellido_paterno, apellido_materno, email, telefono FROM clientes WHERE id = ?");
    $stmt->execute([$cid]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($c) {
        // Compose full display name. Customer brief: header should greet the
        // user with their FULL name, not just "Cliente" or the first word.
        $parts = array_filter([
            trim((string)($c['nombre'] ?? '')),
            trim((string)($c['apellido_paterno'] ?? '')),
            trim((string)($c['apellido_materno'] ?? '')),
        ], 'strlen');
        $c['nombre_completo'] = $parts ? implode(' ', $parts) : '';
    }
} catch (Throwable $e) { $c = null; }
portalJsonOut(['authenticated' => true, 'cliente' => $c]);
