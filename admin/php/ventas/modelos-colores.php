<?php
/**
 * GET — List distinct models and colors available in inventario_motos.
 *
 * Used by the "Editar VK-SC" modal to populate dropdowns when an admin
 * needs to manually set modelo/color on a subscripciones_credito row that
 * was created with empty product context (legacy rows from before Plan G).
 *
 * Response: { ok, modelos: [string], colores: [string], pares: [{modelo,color,disponibles}] }
 *
 * `pares` lets the UI show only valid modelo+color combinations (with
 * actual inventory count) — that way the admin can't select a combo that
 * doesn't exist in the warehouse.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$pdo = getDB();

$modelos = [];
$colores = [];
$pares   = [];

try {
    // Distinct modelos (active inventory only)
    $rows = $pdo->query("
        SELECT DISTINCT modelo
        FROM inventario_motos
        WHERE activo = 1 AND modelo IS NOT NULL AND modelo <> ''
        ORDER BY modelo
    ")->fetchAll(PDO::FETCH_COLUMN);
    $modelos = $rows;

    // Distinct colores
    $rows = $pdo->query("
        SELECT DISTINCT color
        FROM inventario_motos
        WHERE activo = 1 AND color IS NOT NULL AND color <> ''
        ORDER BY color
    ")->fetchAll(PDO::FETCH_COLUMN);
    $colores = $rows;

    // Valid modelo+color pairs with availability (only unassigned units count)
    $rows = $pdo->query("
        SELECT modelo, color, COUNT(*) AS disponibles
        FROM inventario_motos
        WHERE activo = 1
          AND modelo IS NOT NULL AND modelo <> ''
          AND color  IS NOT NULL AND color  <> ''
          AND (pedido_num IS NULL OR pedido_num = '')
          AND (cliente_email IS NULL OR cliente_email = '')
        GROUP BY modelo, color
        ORDER BY modelo, color
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $pares[] = [
            'modelo'       => $r['modelo'],
            'color'        => $r['color'],
            'disponibles'  => (int)$r['disponibles'],
        ];
    }
} catch (Throwable $e) {
    adminJsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
}

adminJsonOut([
    'ok'      => true,
    'modelos' => $modelos,
    'colores' => $colores,
    'pares'   => $pares,
]);
