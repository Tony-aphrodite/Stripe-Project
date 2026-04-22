<?php
/**
 * GET  — preview: rows en transacciones cuyos modelo/color cambiarían al aplicar
 *        voltikaNormalizeModelo/Color (para mostrar al admin antes de commit).
 * POST — ejecuta la actualización. Requiere rol admin (no cedis).
 *
 * Motivación: ventas reales del configurador legacy (Ship.js) persisten
 * "Voltika Tromox Pesgo" / "Gris moderno" en transacciones.modelo/color.
 * El admin filtra inventario por exact match, por lo que esos pedidos nunca
 * encuentran moto asignable. Este endpoint los normaliza al código corto en
 * bloque y deja trazabilidad en admin_log.
 */
require_once __DIR__ . '/../bootstrap.php';

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminId = adminRequireAuth(['admin']); // Solo admin puede commit
    $body    = adminJsonIn();
    $confirm = !empty($body['confirm']);
    if (!$confirm) {
        adminJsonOut(['error' => 'Debes enviar { "confirm": true } para aplicar los cambios'], 400);
    }
} else {
    adminRequireAuth(['admin', 'cedis']); // Cedis también puede preview
}

// ── Pull all transacciones that might need normalization ─────────────────
// Pequeña optimización: solo considera rows cuyo modelo/color claramente
// parece "legacy" (contiene espacio o keyword Voltika/Tromox/moderno/etc).
// Sin el filtro, scanearíamos toda la tabla cada vez que alguien abre el preview.
$stmt = $pdo->query("
    SELECT id, pedido, nombre, modelo, color, freg
    FROM transacciones
    WHERE (
        modelo REGEXP 'voltika|tromox|[[:space:]]'
        OR color  REGEXP '[[:space:]]|moderno|cemento|mate|brillo|militar|marino|cielo|perla|olivo'
        OR LOWER(color) != color
    )
    ORDER BY freg DESC
    LIMIT 500
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$changes = [];
foreach ($rows as $r) {
    $newModelo = voltikaNormalizeModelo($r['modelo'] ?? '');
    $newColor  = voltikaNormalizeColor($r['color']  ?? '');
    $changedM  = ($newModelo !== ($r['modelo'] ?? ''));
    $changedC  = ($newColor  !== ($r['color']  ?? ''));
    if (!$changedM && !$changedC) continue;

    $changes[] = [
        'id'          => (int)$r['id'],
        'pedido'      => $r['pedido'],
        'nombre'      => $r['nombre'],
        'modelo_old'  => $r['modelo'],
        'modelo_new'  => $newModelo,
        'color_old'   => $r['color'],
        'color_new'   => $newColor,
        'changed_m'   => $changedM,
        'changed_c'   => $changedC,
        'freg'        => $r['freg'],
    ];
}

// ── POST: apply ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($changes)) {
        adminJsonOut([
            'ok'      => true,
            'applied' => 0,
            'message' => 'No hay filas para normalizar — todas las transacciones ya tienen valores canónicos.',
        ]);
    }

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare("UPDATE transacciones SET modelo = ?, color = ? WHERE id = ?");
        foreach ($changes as $c) {
            $upd->execute([$c['modelo_new'], $c['color_new'], $c['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        adminJsonOut(['error' => 'Error al aplicar: ' . $e->getMessage()], 500);
    }

    // Audit log (sampled — full diff would bloat admin_log for big batches)
    adminLog('normalizar_catalogo', [
        'applied' => count($changes),
        'sample'  => array_slice($changes, 0, 10),
    ]);

    adminJsonOut([
        'ok'      => true,
        'applied' => count($changes),
        'changes' => $changes,
        'message' => 'Se normalizaron ' . count($changes) . ' registros. Ahora aparecerán motos disponibles para estos pedidos.',
    ]);
}

// ── GET: preview ─────────────────────────────────────────────────────────
adminJsonOut([
    'ok'             => true,
    'preview'        => true,
    'candidates'     => count($rows),
    'to_change'      => count($changes),
    'changes'        => $changes,
    'message'        => count($changes) === 0
        ? 'No se detectaron registros con valores legacy. Nada que normalizar.'
        : 'Se encontraron ' . count($changes) . ' registros que serían normalizados. Revisa la lista y confirma para aplicar.',
]);
