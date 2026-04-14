<?php
/**
 * Voltika - Inventory Utility Functions
 * Shared helpers for FIFO moto assignment.
 * Include this file wherever auto-assignment is needed.
 */

/**
 * Find and assign the oldest available moto (FIFO) matching model + color.
 *
 * Only considers motos that:
 * - Have completed checklist_origen
 * - Are not sale-locked (bloqueado_venta = 0)
 *
 * @return int|null  moto_id if assigned, null if no match found
 */
function asignarMotoFIFO(
    PDO $pdo,
    string $modelo,
    string $color,
    string $clienteNombre,
    string $clienteEmail,
    string $clienteTelefono,
    string $pedidoNum,
    string $stripePi = '',
    string $tpago = 'contado',
    float  $total  = 0
): ?int {

    // ── Find best FIFO match ─────────────────────────────────────────────────
    // Must have: completed origin checklist, not sale-locked
    $stmt = $pdo->prepare("
        SELECT m.id, m.vin, m.modelo, m.color, m.estado
        FROM inventario_motos m
        WHERE m.activo = 1
          AND LOWER(TRIM(m.modelo)) = LOWER(TRIM(?))
          AND LOWER(TRIM(m.color))  = LOWER(TRIM(?))
          AND m.estado IN ('recibida', 'lista_para_entrega')
          AND (m.pedido_num IS NULL OR m.pedido_num = '')
          AND (m.cliente_email IS NULL OR m.cliente_email = '')
          AND (m.bloqueado_venta = 0 OR m.bloqueado_venta IS NULL)
          AND EXISTS (
              SELECT 1 FROM checklist_origen co
              WHERE co.moto_id = m.id AND co.completado = 1
          )
        ORDER BY
            CASE WHEN m.fecha_ingreso_pais IS NOT NULL THEN 0 ELSE 1 END,
            m.fecha_ingreso_pais ASC,
            m.freg ASC
        LIMIT 1
    ");
    $stmt->execute([$modelo, $color]);
    $moto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$moto) {
        return null;
    }

    $motoId = (int)$moto['id'];

    // ── Assign customer to moto ──────────────────────────────────────────────
    $updateStmt = $pdo->prepare("
        UPDATE inventario_motos SET
            cliente_nombre   = ?,
            cliente_email    = ?,
            cliente_telefono = ?,
            pedido_num       = ?,
            stripe_pi        = ?,
            pago_estado      = 'pagada',
            fecha_estado     = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([
        $clienteNombre,
        $clienteEmail,
        $clienteTelefono,
        $pedidoNum,
        $stripePi ?: null,
        $motoId,
    ]);

    // ── Log the assignment ───────────────────────────────────────────────────
    $logEntry = json_encode([
        'estado'    => $moto['estado'],
        'accion'    => 'asignacion_automatica',
        'dealer'    => 'sistema',
        'timestamp' => date('Y-m-d H:i:s'),
        'notas'     => "Asignado automáticamente a $clienteNombre — Pedido $pedidoNum",
    ], JSON_UNESCAPED_UNICODE);

    try {
        $pdo->prepare("
            UPDATE inventario_motos
            SET log_estados = JSON_ARRAY_APPEND(
                IFNULL(log_estados, '[]'), '$', CAST(? AS JSON)
            )
            WHERE id = ?
        ")->execute([$logEntry, $motoId]);
    } catch (PDOException $e) {}

    // ── Register in ventas_log ───────────────────────────────────────────────
    try {
        $pdo->prepare("
            INSERT INTO ventas_log
                (moto_id, tipo, cliente_nombre, cliente_email, cliente_telefono,
                 pedido_num, modelo, color, vin, monto, notas)
            VALUES (?, 'entrega_voltika', ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $motoId,
            $clienteNombre,
            $clienteEmail,
            $clienteTelefono,
            $pedidoNum,
            $moto['modelo'],
            $moto['color'],
            $moto['vin'],
            $total ?: null,
            "Asignación automática FIFO — $tpago",
        ]);
    } catch (PDOException $e) {}

    return $motoId;
}

/**
 * Count available (unassigned) motos by model and color.
 * Only counts motos with completed origin checklist and not sale-locked.
 *
 * @return array  [['modelo'=>'M05','color'=>'negro','disponibles'=>3], ...]
 */
function contarDisponibles(PDO $pdo, string $modelo = '', string $color = ''): array {
    $where  = ["m.activo = 1", "m.estado IN ('recibida','lista_para_entrega')",
               "(m.pedido_num IS NULL OR m.pedido_num = '')",
               "(m.cliente_email IS NULL OR m.cliente_email = '')",
               "m.vin NOT REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'",
               "(m.bloqueado_venta = 0 OR m.bloqueado_venta IS NULL)",
               "EXISTS (SELECT 1 FROM checklist_origen co WHERE co.moto_id = m.id AND co.completado = 1)"];
    $params = [];

    if ($modelo) { $where[] = 'LOWER(TRIM(m.modelo)) = LOWER(TRIM(?))'; $params[] = $modelo; }
    if ($color)  { $where[] = 'LOWER(TRIM(m.color))  = LOWER(TRIM(?))'; $params[] = $color; }

    $sql  = "SELECT m.modelo, m.color, COUNT(*) AS disponibles
             FROM inventario_motos m
             WHERE " . implode(' AND ', $where) . "
             GROUP BY m.modelo, m.color
             ORDER BY m.modelo, m.color";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
