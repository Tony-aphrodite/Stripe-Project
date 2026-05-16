<?php
/**
 * Voltika Punto — Round 43 (2026-05-16, Óscar).
 *
 * Emergency in-person OTP reveal for delivery.
 *
 * WHY: SMS provider acceptance ≠ actual carrier delivery. Customer is
 * physically at the punto, ID matches, but their phone never received
 * the SMS (carrier block / phone off / out of service / wrong number
 * variant). Without this endpoint the operator is stuck — they cannot
 * complete the legitimate delivery. The customer brief "We need this
 * today to deliver a moto" (2026-05-16, Óscar) is exactly this case.
 *
 * Trade-off: revealing the OTP server-side does technically bypass the
 * "customer received SMS" check. Mitigations:
 *   ✓ Punto auth required (operator must be logged in).
 *   ✓ Both the reveal + the subsequent verify-otp must come from the
 *     same dealer_id, so a single operator's full action is auditable.
 *   ✓ Reveal does NOT count as "OTP verified" — operator still has to
 *     type the code and verificar-otp.php confirms it.
 *   ✓ Heavy audit log: who, when, which moto, which OTP, justification
 *     text the operator typed. admin_log shows every reveal.
 *
 * POST body:
 *   { moto_id, motivo: "Cliente no recibió SMS — verificación presencial" }
 *
 * Response:
 *   { ok, otp, expires, motivo, dealer_id, audit_id }
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$d       = puntoJsonIn();
$motoId  = (int)($d['moto_id'] ?? 0);
$motivo  = trim((string)($d['motivo'] ?? ''));

if (!$motoId)            puntoJsonOut(['error' => 'moto_id requerido'], 400);
if ($motivo === '')      puntoJsonOut(['error' => 'Motivo requerido (escribe por qué necesitas revelar el código)'], 400);
if (mb_strlen($motivo) < 10) {
    puntoJsonOut(['error' => 'El motivo debe tener al menos 10 caracteres — describe la razón concreta.'], 400);
}
if (mb_strlen($motivo) > 300) {
    puntoJsonOut(['error' => 'Motivo demasiado largo (máx 300 caracteres)'], 400);
}

$pdo = getDB();

// Verify moto belongs to this punto AND has an active OTP we can reveal.
$stmt = $pdo->prepare(
    "SELECT e.id, e.moto_id, e.otp_code, e.otp_expires, e.otp_verified,
            e.cliente_nombre, e.cliente_telefono, e.dealer_id,
            m.punto_voltika_id
       FROM entregas e
       JOIN inventario_motos m ON m.id = e.moto_id
      WHERE e.moto_id = ?
      ORDER BY e.freg DESC LIMIT 1"
);
$stmt->execute([$motoId]);
$ent = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$ent) {
    puntoJsonOut(['error' => 'No hay entrega iniciada para esta moto. Presiona "Enviar código por SMS" primero.'], 404);
}
if ((int)$ent['punto_voltika_id'] !== (int)$ctx['punto_id']) {
    puntoJsonOut(['error' => 'Esta moto no pertenece a tu punto.'], 403);
}
if (empty($ent['otp_code'])) {
    puntoJsonOut(['error' => 'La entrega no tiene un código activo. Reenvía el SMS primero.'], 409);
}
if (!empty($ent['otp_verified'])) {
    // Code already verified — no need to reveal anything. Frontend can advance.
    puntoJsonOut(['ok' => true, 'already_verified' => true]);
}
if (!empty($ent['otp_expires']) && strtotime($ent['otp_expires']) < time()) {
    puntoJsonOut([
        'error' => 'El código expiró. Vuelve a "Enviar código por SMS" para generar uno nuevo.',
        'expired_at' => $ent['otp_expires'],
    ], 409);
}

// Audit log entry — this is the security-relevant record.
$auditId = null;
try {
    // Create the audit table on first use (idempotent).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS entrega_otp_revelaciones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            moto_id INT NOT NULL,
            entrega_id INT NOT NULL,
            dealer_id INT NOT NULL,
            otp_code VARCHAR(8) NOT NULL,
            motivo VARCHAR(300) NOT NULL,
            ip VARCHAR(64) NULL,
            freg DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_moto (moto_id),
            INDEX idx_dealer (dealer_id),
            INDEX idx_freg (freg)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $ins = $pdo->prepare(
        "INSERT INTO entrega_otp_revelaciones
            (moto_id, entrega_id, dealer_id, otp_code, motivo, ip)
         VALUES (?,?,?,?,?,?)"
    );
    $ins->execute([
        $motoId,
        (int)$ent['id'],
        $ctx['user_id'],
        (string)$ent['otp_code'],
        $motivo,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
    $auditId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    error_log('revelar-otp audit: ' . $e->getMessage());
}

// Mirror to admin_log via puntoLog so it appears in the same audit stream.
puntoLog('entrega_otp_revelado', [
    'moto_id'       => $motoId,
    'entrega_id'    => (int)$ent['id'],
    'cliente'       => $ent['cliente_nombre'],
    'telefono'      => $ent['cliente_telefono'],
    'motivo'        => $motivo,
    'audit_id'      => $auditId,
]);

puntoJsonOut([
    'ok'        => true,
    'otp'       => (string)$ent['otp_code'],
    'expires'   => $ent['otp_expires'],
    'cliente'   => $ent['cliente_nombre'],
    'telefono'  => $ent['cliente_telefono'],
    'motivo'    => $motivo,
    'audit_id'  => $auditId,
    'warning'   => 'Este código se reveló por imposibilidad de entrega SMS. Confirma identidad del cliente en persona (INE) antes de continuar. Esta acción quedó registrada.',
]);
