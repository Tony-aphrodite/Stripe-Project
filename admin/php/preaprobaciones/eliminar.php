<?php
/**
 * POST — Delete or archive credit application records.
 *
 * Two modes:
 *   { "id": N, "modo": "archivar" }      → soft delete (seguimiento=archivado)
 *   { "id": N, "modo": "eliminar" }      → hard delete (only admin role, audit log)
 *   { "ids": [N,M], "modo": "..." }      → bulk
 */
require_once __DIR__ . '/../bootstrap.php';

$in   = adminJsonIn();
$modo = trim($in['modo'] ?? '');
$ids  = isset($in['ids']) && is_array($in['ids']) ? $in['ids'] : (isset($in['id']) ? [$in['id']] : []);
$ids  = array_values(array_filter(array_map('intval', $ids), function($v){ return $v > 0; }));

if (empty($ids))                                  adminJsonOut(['error'=>'IDs requeridos'], 400);
if (!in_array($modo, ['archivar','eliminar'], true)) adminJsonOut(['error'=>'modo inválido (archivar|eliminar)'], 400);

// archivar: any role with admin/cedis/operador. eliminar: ONLY admin.
$uid = adminRequireAuth($modo === 'eliminar' ? ['admin'] : ['admin','cedis','operador']);

try {
    $pdo = getDB();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    if ($modo === 'archivar') {
        $stmt = $pdo->prepare("UPDATE preaprobaciones SET seguimiento='archivado' WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $afectados = $stmt->rowCount();
    } else { // eliminar
        // Audit log first (even if delete fails)
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS preaprobaciones_audit (
                id INT AUTO_INCREMENT PRIMARY KEY,
                accion VARCHAR(40), usuario_id INT,
                preaprobacion_id INT,
                snapshot MEDIUMTEXT,
                freg DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            $snap = $pdo->prepare("SELECT * FROM preaprobaciones WHERE id IN ($placeholders)");
            $snap->execute($ids);
            $rowsForAudit = $snap->fetchAll(PDO::FETCH_ASSOC);
            $aud = $pdo->prepare("INSERT INTO preaprobaciones_audit (accion, usuario_id, preaprobacion_id, snapshot) VALUES (?,?,?,?)");
            foreach ($rowsForAudit as $r) {
                $aud->execute(['delete', $uid, $r['id'], json_encode($r, JSON_UNESCAPED_UNICODE)]);
            }
        } catch (Throwable $e) {}

        $stmt = $pdo->prepare("DELETE FROM preaprobaciones WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $afectados = $stmt->rowCount();
    }

    adminJsonOut(['ok' => true, 'modo' => $modo, 'afectados' => $afectados]);
} catch (Throwable $e) {
    adminJsonOut(['error' => $e->getMessage()], 500);
}
