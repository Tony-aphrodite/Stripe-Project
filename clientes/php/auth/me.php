<?php
require_once __DIR__ . '/../bootstrap.php';
$cid = $_SESSION['portal_cliente_id'] ?? null;
if (!$cid) portalJsonOut(['authenticated' => false]);
try {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, nombre, apellido_paterno, email, telefono FROM clientes WHERE id = ?");
    $stmt->execute([$cid]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $c = null; }
portalJsonOut(['authenticated' => true, 'cliente' => $c]);
