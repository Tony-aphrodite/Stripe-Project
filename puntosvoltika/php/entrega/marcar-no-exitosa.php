<?php
/**
 * POST — Mark an in-flight delivery as "Unsuccessful" (entrega no exitosa).
 *
 * Bug 5.1 (customer brief 2026-05-08): "If the process is started but not
 * completed, an 'Unsuccessful delivery' button must be available with an
 * observations field to explain the reason."
 *
 * Body: { moto_id, motivo (text, required >= 5 chars) }
 *
 * Effect:
 *   entregas.estado            → 'no_exitosa'
 *   entregas.cancelado_motivo  → motivo
 *   entregas.cancelado_at      → NOW()
 *   inventario_motos.estado    → unchanged (moto remains assignable)
 *
 * Does NOT delete or void OTP / face-verification rows — they stay for
 * audit. The next iniciar.php call creates a fresh entrega row.
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$d = puntoJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
$motivo = trim((string)($d['motivo'] ?? ''));

if (!$motoId)            puntoJsonOut(['error' => 'moto_id requerido'], 400);
if (strlen($motivo) < 5) puntoJsonOut(['error' => 'Describe brevemente el motivo (mínimo 5 caracteres)'], 400);

$pdo = getDB();

// Idempotent — same migration as guardar-paso.php.
try { $pdo->exec("ALTER TABLE entregas ADD COLUMN cancelado_motivo VARCHAR(500) NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE entregas ADD COLUMN cancelado_at DATETIME NULL"); } catch (Throwable $e) {}

// Locate latest entrega row for this moto in this punto.
$q = $pdo->prepare("SELECT e.id, e.estado FROM entregas e
    JOIN inventario_motos m ON m.id = e.moto_id
    WHERE e.moto_id=? AND m.punto_voltika_id=?
    ORDER BY e.freg DESC LIMIT 1");
$q->execute([$motoId, $ctx['punto_id']]);
$row = $q->fetch(PDO::FETCH_ASSOC);
if (!$row) puntoJsonOut(['error' => 'No hay proceso de entrega para esta moto en tu punto'], 404);
if (in_array(strtolower((string)$row['estado']), ['entregada','no_exitosa'], true)) {
    puntoJsonOut(['error' => 'Esta entrega ya está cerrada (estado: ' . $row['estado'] . ')'], 409);
}

$pdo->prepare("UPDATE entregas SET estado='no_exitosa', cancelado_motivo=?, cancelado_at=NOW() WHERE id=?")
    ->execute([$motivo, $row['id']]);

puntoLog('entrega_no_exitosa', [
    'moto_id'      => $motoId,
    'entrega_id'   => $row['id'],
    'motivo'       => $motivo,
]);

puntoJsonOut(['ok' => true, 'entrega_id' => $row['id']]);
