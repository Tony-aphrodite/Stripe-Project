<?php
/**
 * One-shot migration: add missing columns to inventario_motos.
 * Safe to run multiple times — checks IF NOT EXISTS via SHOW COLUMNS.
 * DELETE this file after running.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();

// Columns to add (name => SQL definition)
$columnsToAdd = [
    'anio_modelo'          => "VARCHAR(10) NULL AFTER color",
    'num_motor'            => "VARCHAR(50) NULL AFTER anio_modelo",
    'potencia'             => "VARCHAR(50) NULL AFTER num_motor",
    'config_baterias'      => "ENUM('1','2') DEFAULT '1' AFTER potencia",
    'descripcion'          => "TEXT NULL AFTER config_baterias",
    'hecho_en'             => "VARCHAR(100) NULL AFTER descripcion",
    'num_pedimento'        => "VARCHAR(50) NULL AFTER hecho_en",
    'fecha_ingreso_pais'   => "DATE NULL AFTER num_pedimento",
    'aduana'               => "VARCHAR(100) NULL AFTER fecha_ingreso_pais",
    'cedis_origen'         => "VARCHAR(100) NULL AFTER aduana",
    'punto_voltika_id'     => "INT NULL AFTER dealer_id",
    'stripe_pi'            => "VARCHAR(200) NULL AFTER pedido_num",
    'fecha_estimada_llegada' => "DATE NULL AFTER fecha_llegada",
    'fecha_entrega_estimada' => "DATE NULL AFTER fecha_estimada_llegada",
    'recepcion_completada' => "TINYINT DEFAULT 0 AFTER dias_en_paso",
    'num_factura'          => "VARCHAR(50) NULL AFTER num_pedimento",
    'posicion_inventario'  => "VARCHAR(20) NULL AFTER cedis_origen",
    'fecha_entrada_almacen'=> "DATE NULL AFTER fecha_ingreso_pais",
    'fecha_salida_almacen' => "DATE NULL AFTER fecha_entrada_almacen",
];

// Get existing columns
$existing = [];
foreach ($pdo->query("SHOW COLUMNS FROM inventario_motos")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $existing[] = $c['Field'];
}

$added = [];
$skipped = [];
$errors = [];

foreach ($columnsToAdd as $col => $def) {
    if (in_array($col, $existing, true)) {
        $skipped[] = $col;
        continue;
    }
    try {
        $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN $col $def");
        $added[] = $col;
    } catch (Throwable $e) {
        $errors[] = ['column' => $col, 'error' => $e->getMessage()];
    }
}

// Also fix pago_estado default from 'pagada' to 'pendiente' for new bikes
try {
    $pdo->exec("ALTER TABLE inventario_motos MODIFY COLUMN pago_estado ENUM('pagada','pendiente','parcial') DEFAULT 'pendiente'");
} catch (Throwable $e) {
    $errors[] = ['column' => 'pago_estado (default)', 'error' => $e->getMessage()];
}

adminJsonOut([
    'ok'      => empty($errors),
    'added'   => $added,
    'skipped' => $skipped,
    'errors'  => $errors,
    'total_columns_now' => count($existing) + count($added),
]);
