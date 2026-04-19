<?php
/**
 * POST JSON — Register a delivery incident and notify the customer.
 *
 * Body: { moto_id, mensaje }
 *
 * Creates the `incidencias` table on first use. Generates a human-readable
 * numero_caso (CASO-YYYYMMDD-NNNN) unique per day. Fires the
 * `recepcion_incidencia` rich template (email + WhatsApp + SMS).
 *
 * Response: { ok, numero_caso, incidencia_id }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis','operador']);

$d       = adminJsonIn();
$motoId  = (int)($d['moto_id'] ?? 0);
$mensaje = trim((string)($d['mensaje'] ?? ''));
if (!$motoId)          adminJsonOut(['error' => 'moto_id requerido'], 400);
if ($mensaje === '')   adminJsonOut(['error' => 'mensaje requerido'], 400);

$pdo = getDB();

// ── Ensure `incidencias` table exists (idempotent) ─────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS incidencias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_caso VARCHAR(30) NOT NULL UNIQUE,
    moto_id INT NULL,
    transaccion_id INT NULL,
    cliente_nombre VARCHAR(200) NULL,
    cliente_email VARCHAR(200) NULL,
    cliente_telefono VARCHAR(30) NULL,
    modelo VARCHAR(80) NULL,
    color VARCHAR(50) NULL,
    pedido_num VARCHAR(50) NULL,
    mensaje TEXT NOT NULL,
    estado VARCHAR(20) DEFAULT 'abierto',
    reportado_por INT NULL,
    freg DATETIME DEFAULT CURRENT_TIMESTAMP,
    fcierre DATETIME NULL,
    notas_internas TEXT NULL,
    INDEX idx_moto (moto_id),
    INDEX idx_estado (estado),
    INDEX idx_freg (freg)
)");

// ── Resolve moto + client info for the notification payload ────────────────
$moto = $pdo->prepare("SELECT m.id, m.modelo, m.color, m.pedido_num,
                             m.cliente_nombre, m.cliente_email, m.cliente_telefono, m.cliente_id,
                             m.vin_display AS vin,
                             t.id AS transaccion_id, t.pedido AS tx_pedido
                         FROM inventario_motos m
                    LEFT JOIN transacciones t ON t.stripe_pi = m.stripe_pi
                                              OR CONCAT('VK-', t.pedido) = m.pedido_num
                        WHERE m.id = ?
                        LIMIT 1");
$moto->execute([$motoId]);
$row = $moto->fetch(PDO::FETCH_ASSOC);
if (!$row) adminJsonOut(['error' => 'Moto no encontrada'], 404);

$pedido = $row['tx_pedido'] ?? '';
if (!$pedido && !empty($row['pedido_num']) && str_starts_with($row['pedido_num'], 'VK-')) {
    $pedido = substr($row['pedido_num'], 3);
}

// ── Generate unique numero_caso (CASO-YYYYMMDD-NNNN) ───────────────────────
$datePart = date('Ymd');
$seqStmt  = $pdo->prepare("SELECT COUNT(*) + 1 FROM incidencias WHERE numero_caso LIKE ?");
$seqStmt->execute(["CASO-$datePart-%"]);
$seq      = str_pad((string)((int)$seqStmt->fetchColumn()), 4, '0', STR_PAD_LEFT);
$numeroCaso = "CASO-$datePart-$seq";

// Retry once if that exact numero_caso somehow already exists (extreme edge).
$collisionCheck = $pdo->prepare("SELECT id FROM incidencias WHERE numero_caso = ?");
$collisionCheck->execute([$numeroCaso]);
if ($collisionCheck->fetch()) {
    $numeroCaso = "CASO-$datePart-" . str_pad((string)((int)$seq + random_int(10, 99)), 4, '0', STR_PAD_LEFT);
}

// ── Insert the record ──────────────────────────────────────────────────────
$ins = $pdo->prepare("INSERT INTO incidencias
    (numero_caso, moto_id, transaccion_id, cliente_nombre, cliente_email, cliente_telefono,
     modelo, color, pedido_num, mensaje, estado, reportado_por)
    VALUES (?,?,?,?,?,?,?,?,?,?, 'abierto', ?)");
$ins->execute([
    $numeroCaso, $motoId, $row['transaccion_id'] ?: null,
    $row['cliente_nombre'] ?? '', $row['cliente_email'] ?? '', $row['cliente_telefono'] ?? '',
    $row['modelo'] ?? '', $row['color'] ?? '', $row['pedido_num'] ?? '',
    $mensaje, $uid,
]);
$incId = (int)$pdo->lastInsertId();

adminLog('incidencia_reportada', [
    'incidencia_id' => $incId, 'numero_caso' => $numeroCaso,
    'moto_id' => $motoId, 'mensaje_len' => strlen($mensaje),
]);

// ── Notify the customer ────────────────────────────────────────────────────
$clienteTel   = $row['cliente_telefono'] ?? '';
$clienteEmail = $row['cliente_email']    ?? '';
if ($clienteTel || $clienteEmail) {
    $notifyPath = null;
    foreach ([
        __DIR__ . '/../../../configurador_prueba_test/php/voltika-notify.php',
        __DIR__ . '/../../../configurador_prueba/php/voltika-notify.php',
    ] as $p) {
        if (is_file($p)) { $notifyPath = $p; break; }
    }
    if ($notifyPath) { try { require_once $notifyPath; } catch (Throwable $e) { error_log('notify include: ' . $e->getMessage()); } }

    if (function_exists('voltikaNotify')) {
        $fechaHuman = function_exists('voltikaFormatFechaHuman')
            ? voltikaFormatFechaHuman(date('Y-m-d H:i:s'))
            : date('Y-m-d H:i:s');
        try {
            voltikaNotify('recepcion_incidencia', [
                'cliente_id'    => $row['cliente_id'] ?? null,
                'nombre'        => $row['cliente_nombre'] ?? '',
                'pedido'        => $pedido,
                'modelo'        => $row['modelo'] ?? '',
                'color'         => $row['color']  ?? '',
                'mensaje'       => $mensaje,
                'numero_caso'   => $numeroCaso,
                'fecha_reporte' => $fechaHuman . ' ' . date('H:i'),
                'telefono'      => $clienteTel,
                'email'         => $clienteEmail,
            ]);
        } catch (Throwable $e) { error_log('notify recepcion_incidencia: ' . $e->getMessage()); }
    }
}

adminJsonOut([
    'ok'            => true,
    'numero_caso'   => $numeroCaso,
    'incidencia_id' => $incId,
]);
