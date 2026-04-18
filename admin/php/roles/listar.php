<?php
/**
 * GET — List users and their roles, with permission matrix
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();

// Add role column if missing
try {
    $cols = $pdo->query("SHOW COLUMNS FROM dealer_usuarios")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('permisos', $cols)) {
        $pdo->exec("ALTER TABLE dealer_usuarios ADD COLUMN permisos JSON DEFAULT NULL");
    }
} catch (Throwable $e) {}

$usuarios = [];
try {
    $usuarios = $pdo->query("SELECT id, nombre, email, rol, punto_id, punto_nombre, activo, permisos, freg
        FROM dealer_usuarios ORDER BY rol, nombre")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('roles/listar: ' . $e->getMessage()); }

$puntos = [];
try {
    $puntos = $pdo->query("SELECT id, nombre, ciudad, telefono, codigo_venta, tipo FROM puntos_voltika
        WHERE activo=1 ORDER BY nombre, id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('roles/listar puntos: ' . $e->getMessage()); }

// Define the permission matrix
$rolesDisponibles = ['admin','dealer','cedis','operador','cobranza','documentos','logistica'];
$modulosDisponibles = ['dashboard','ventas','inventario','envios','pagos','cobranza','puntos',
    'checklists','buro','analytics','alertas','reportes','modelos','precios','documentos','buscar','roles'];

$matrizDefault = [
    'admin'      => $modulosDisponibles,
    'dealer'     => ['dashboard','ventas','buscar'],
    'cedis'      => ['dashboard','ventas','inventario','envios','pagos','cobranza','checklists','buscar'],
    'operador'   => ['dashboard','ventas','inventario','envios','pagos','buscar'],
    'cobranza'   => ['dashboard','pagos','cobranza','buscar','reportes'],
    'documentos' => ['dashboard','documentos','buscar'],
    'logistica'  => ['dashboard','inventario','envios','puntos','checklists','buscar'],
];

adminJsonOut([
    'ok'       => true,
    'usuarios' => $usuarios,
    'puntos'   => $puntos,
    'roles'    => $rolesDisponibles,
    'modulos'  => $modulosDisponibles,
    'matriz'   => $matrizDefault,
]);
