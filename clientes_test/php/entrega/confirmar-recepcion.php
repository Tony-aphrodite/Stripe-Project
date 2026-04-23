<?php
/**
 * Portal Clientes — Confirmar recepción de la moto
 * After Punto Voltika has marked the delivery as 'entregada',
 * the client can confirm they received the moto in good condition.
 * Optional incidencia flag + comments.
 */
require_once __DIR__ . '/../bootstrap.php';

$cid = portalRequireAuth();
$in  = portalJsonIn();
$motoId = (int)($in['moto_id'] ?? 0);
$incidencia = !empty($in['incidencia']) ? 1 : 0;
$comentario = trim((string)($in['comentario'] ?? ''));

if (!$motoId) portalJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

try {
    // Ownership accepts cliente_id OR cliente_telefono/email match; see
    // portalFindOwnedMoto() in bootstrap.php for rationale.
    $moto = portalFindOwnedMoto($cid, $motoId);
    if (!$moto) portalJsonOut(['error' => 'Moto no encontrada'], 404);

    // Ensure columns
    try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cliente_recepcion_ok TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cliente_recepcion_fecha DATETIME NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cliente_recepcion_incidencia TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cliente_recepcion_comentario TEXT NULL"); } catch (Throwable $e) {}

    $pdo->prepare("UPDATE inventario_motos
        SET cliente_recepcion_ok = 1,
            cliente_recepcion_fecha = NOW(),
            cliente_recepcion_incidencia = ?,
            cliente_recepcion_comentario = ?
        WHERE id = ?")
        ->execute([$incidencia, $comentario ?: null, $motoId]);

    portalLog('recepcion_confirmada', [
        'cliente_id' => $cid, 'success' => 1,
        'detalle' => "moto=$motoId incidencia=$incidencia",
    ]);

    // Notify on incidencia so ops team can react
    if ($incidencia) {
        try {
            require_once __DIR__ . '/../../../configurador_prueba_test/php/voltika-notify.php';
            voltikaNotify('recepcion_incidencia', [
                'cliente_id' => $cid,
                'nombre'     => '',
                'modelo'     => $moto['modelo'] ?? '',
                'mensaje'    => $comentario ?: '(sin comentario)',
                'telefono'   => $moto['cliente_telefono'] ?? '',
                'email'      => $moto['cliente_email'] ?? '',
            ]);
        } catch (Throwable $e) { error_log('notify recepcion_incidencia: ' . $e->getMessage()); }
    }

    portalJsonOut([
        'ok' => true,
        'mensaje' => $incidencia
            ? 'Recepción confirmada con incidencia — te contactaremos pronto'
            : '¡Bienvenido a la familia Voltika!',
    ]);
} catch (Throwable $e) {
    error_log('entrega/confirmar-recepcion: ' . $e->getMessage());
    portalJsonOut(['error' => 'Error interno'], 500);
}
