<?php
/**
 * GET/POST — Poll ACTA signing + OTP verification status for a moto.
 *
 * Used by step 5 of the delivery wizard so punto staff sees real-time
 * confirmation of the customer's ACTA signature instead of guessing when
 * to click "Finalizar entrega". Cheap enough to call every 5 s.
 *
 * Body/query: { moto_id }
 * Returns:
 *   acta_firmada  (0|1)
 *   acta_fecha    (datetime string or null)
 *   firma_nombre  (who signed, or null)
 *   otp_verified  (0|1) — needed by finalizar.php too
 *   ready         (bool) — true when both ACTA + OTP are ready
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$motoId = (int)($_GET['moto_id'] ?? 0);
if (!$motoId) {
    $d = puntoJsonIn();
    $motoId = (int)($d['moto_id'] ?? 0);
}
if (!$motoId) puntoJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

// Make sure the ACTA columns exist — in case no one has ever signed yet
// on this DB, the lazy ALTER from firmar-acta.php hasn't run. Without this
// the SELECT below 500s on a fresh environment.
foreach ([
    "ALTER TABLE inventario_motos ADD COLUMN cliente_acta_firmada TINYINT(1) DEFAULT 0",
    "ALTER TABLE inventario_motos ADD COLUMN cliente_acta_fecha DATETIME NULL",
    "ALTER TABLE inventario_motos ADD COLUMN cliente_acta_firma VARCHAR(150) NULL",
] as $sql) {
    try { $pdo->exec($sql); } catch (Throwable $e) { /* already exists */ }
}

// Scope by punto so a staff member can't poll someone else's moto.
$stmt = $pdo->prepare("SELECT cliente_acta_firmada, cliente_acta_fecha, cliente_acta_firma
    FROM inventario_motos WHERE id = ? AND punto_voltika_id = ? LIMIT 1");
$stmt->execute([$motoId, $ctx['punto_id']]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) puntoJsonOut(['error' => 'Moto no encontrada en este punto'], 404);

$otpStmt = $pdo->prepare("SELECT otp_verified FROM entregas
    WHERE moto_id = ? ORDER BY freg DESC LIMIT 1");
$otpStmt->execute([$motoId]);
$otpVerified = (int)($otpStmt->fetchColumn() ?: 0);

$actaFirmada = (int)($row['cliente_acta_firmada'] ?? 0);

puntoJsonOut([
    'ok'            => true,
    'moto_id'       => $motoId,
    'acta_firmada'  => $actaFirmada,
    'acta_fecha'    => $row['cliente_acta_fecha'] ?: null,
    'firma_nombre'  => $row['cliente_acta_firma'] ?: null,
    'otp_verified'  => $otpVerified,
    'ready'         => ($actaFirmada === 1 && $otpVerified === 1),
]);
