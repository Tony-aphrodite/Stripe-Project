<?php
/**
 * POST — One-shot inventory cleanup after the customer's first import.
 * Fixes color names to match the frontend IDs (productos.js) and flags
 * non-sellable units (spare parts, under repair, office-only, sold).
 *
 * Safe to run multiple times — each UPDATE is idempotent.
 * Returns counts of rows changed per step.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$result = [];

$colorMap = [
    'negra'  => 'negro',
    'blue'   => 'azul',
    'white'  => 'blanco',
    'black'  => 'negro',
    'gray'   => 'gris',
    'grey'   => 'gris',
    'silver' => 'plata',
    'green'  => 'verde',
    'orange' => 'naranja',
    'yellow' => 'amarillo',
    'red'    => 'rojo',
];
foreach ($colorMap as $from => $to) {
    try {
        $stmt = $pdo->prepare("UPDATE inventario_motos SET color = ? WHERE LOWER(TRIM(color)) = ?");
        $stmt->execute([$to, $from]);
        if ($stmt->rowCount() > 0) $result['color_' . $from . '_to_' . $to] = $stmt->rowCount();
    } catch (Throwable $e) {
        $result['error_color_' . $from] = $e->getMessage();
    }
}

// Modelo name normalization — must match frontend productos.js names exactly
// or the configurador inventory lookup returns 0 for every color.
$modeloMap = [
    ["%pesgo plus%",    'Pesgo Plus'],
    ["%mino%",          'Mino-B'],
    ["UKKO S",          'Ukko S+'],
    ["%ukko%",          'Ukko S+'],
    ["MC10",            'MC10 Streetx'],
    ["%voltika tromox m05%", 'M05'],
    ["%voltika tromox m03%", 'M03'],
];
foreach ($modeloMap as [$pattern, $target]) {
    try {
        $op = strpos($pattern, '%') !== false ? 'LIKE' : '=';
        $stmt = $pdo->prepare("UPDATE inventario_motos SET modelo = ?
            WHERE $op = ? COLLATE utf8mb4_general_ci AND modelo != ?");
        // The above uses a placeholder twice — simpler to inline:
        $sql = ($op === 'LIKE')
            ? "UPDATE inventario_motos SET modelo = ? WHERE LOWER(modelo) LIKE LOWER(?) AND modelo != ?"
            : "UPDATE inventario_motos SET modelo = ? WHERE LOWER(modelo) = LOWER(?) AND modelo != ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$target, $pattern, $target]);
        if ($stmt->rowCount() > 0) $result['modelo_' . preg_replace('/[^a-z0-9]/i', '_', $pattern) . '_to_' . $target] = $stmt->rowCount();
    } catch (Throwable $e) {
        $result['error_modelo_' . preg_replace('/[^a-z0-9]/i', '_', $pattern)] = $e->getMessage();
    }
}

// Flag non-sellable units by scanning posicion_inventario + notas for markers.
try {
    $sql = "UPDATE inventario_motos
            SET bloqueado_venta = 1
            WHERE bloqueado_venta = 0
              AND (
                LOWER(COALESCE(posicion_inventario, '')) REGEXP 'repuestos|reparacion|reparación|sin eje|oficina'
                OR LOWER(COALESCE(notas, ''))             REGEXP 'repuestos|reparacion|reparación|sin eje|oficina'
              )";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $result['bloqueados_por_marcador'] = $stmt->rowCount();
} catch (Throwable $e) {
    $result['error_bloqueados_marcador'] = $e->getMessage();
}

// Flag units whose estado is already 'vendida' (in case import set this).
try {
    $stmt = $pdo->prepare("UPDATE inventario_motos SET bloqueado_venta = 1
        WHERE bloqueado_venta = 0 AND LOWER(COALESCE(estado, '')) IN ('vendida','entregada')");
    $stmt->execute();
    $result['bloqueados_por_estado_vendida'] = $stmt->rowCount();
} catch (Throwable $e) {
    $result['error_bloqueados_estado'] = $e->getMessage();
}

// Count current sellable inventory grouped by modelo+color
try {
    $sql = "SELECT modelo, color, COUNT(*) AS total
            FROM inventario_motos
            WHERE activo = 1
              AND (bloqueado_venta = 0 OR bloqueado_venta IS NULL)
              AND estado NOT IN ('entregada','retenida')
              AND (pedido_num IS NULL OR pedido_num = '')
            GROUP BY modelo, color ORDER BY modelo, color";
    $result['inventario_vendible'] = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $result['error_conteo'] = $e->getMessage();
}

adminJsonOut(['ok' => true, 'resultado' => $result]);
