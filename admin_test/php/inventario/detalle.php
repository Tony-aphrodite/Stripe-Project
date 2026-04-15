<?php
/**
 * GET ?id= — Full detail of a single moto (for modal view)
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$id = (int)($_GET['id'] ?? 0);
if (!$id) adminJsonOut(['error' => 'ID requerido'], 400);

$pdo = getDB();

// Moto
$stmt = $pdo->prepare("SELECT m.*, pv.nombre AS punto_voltika_nombre,
    DATEDIFF(CURDATE(), COALESCE(m.fecha_estado, m.freg)) AS dias_en_estado,
    CASE WHEN m.punto_voltika_id IS NOT NULL AND m.estado NOT IN ('entregada','por_llegar','retenida')
         THEN DATEDIFF(CURDATE(), COALESCE(m.fecha_estado, m.freg)) ELSE NULL END AS dias_en_punto
    FROM inventario_motos m LEFT JOIN puntos_voltika pv ON pv.id=m.punto_voltika_id WHERE m.id=?");
$stmt->execute([$id]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) adminJsonOut(['error' => 'Moto no encontrada'], 404);

// Checklists
$co = $pdo->prepare("SELECT * FROM checklist_origen WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$co->execute([$id]);

$ce = $pdo->prepare("SELECT * FROM checklist_ensamble WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$ce->execute([$id]);

$cd = $pdo->prepare("SELECT * FROM checklist_entrega_v2 WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$cd->execute([$id]);

// Envíos
$env = $pdo->prepare("SELECT e.*, pv.nombre AS punto_nombre FROM envios e
    LEFT JOIN puntos_voltika pv ON pv.id=e.punto_destino_id WHERE e.moto_id=? ORDER BY e.freg DESC");
$env->execute([$id]);

// Entrega
$ent = $pdo->prepare("SELECT * FROM entregas WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$ent->execute([$id]);

// Transacción/pedido linked
$tx = $pdo->prepare("SELECT * FROM transacciones WHERE stripe_pi=? LIMIT 1");
$tx->execute([$moto['stripe_pi'] ?? '']);

adminJsonOut([
    'moto' => $moto,
    'checklist_origen' => $co->fetch(PDO::FETCH_ASSOC) ?: null,
    'checklist_ensamble' => $ce->fetch(PDO::FETCH_ASSOC) ?: null,
    'checklist_entrega' => $cd->fetch(PDO::FETCH_ASSOC) ?: null,
    'envios' => $env->fetchAll(PDO::FETCH_ASSOC),
    'entrega' => $ent->fetch(PDO::FETCH_ASSOC) ?: null,
    'transaccion' => $tx->fetch(PDO::FETCH_ASSOC) ?: null,
]);
