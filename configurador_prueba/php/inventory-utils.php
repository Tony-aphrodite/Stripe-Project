<?php
/**
 * Voltika - Inventory Utility Functions
 * Shared helpers for FIFO moto assignment.
 * Include this file wherever auto-assignment is needed.
 */

/**
 * Find and assign the oldest available moto (FIFO) matching model + color.
 *
 * @param PDO    $pdo
 * @param string $modelo         e.g. "M05"
 * @param string $color          e.g. "negro"
 * @param string $clienteNombre
 * @param string $clienteEmail
 * @param string $clienteTelefono
 * @param string $pedidoNum      e.g. "VK-ABC123"
 * @param string $stripePi       Stripe PaymentIntent ID (optional)
 * @param string $tpago          "contado" | "msi" | "credito"
 * @param float  $total
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
    // Unassigned = no pedido_num set yet, not delivered
    // Priority order: fecha_ingreso_pais ASC (oldest first), then freg ASC
    $stmt = $pdo->prepare("
        SELECT id, vin, modelo, color, estado
        FROM inventario_motos
        WHERE activo = 1
          AND LOWER(TRIM(modelo)) = LOWER(TRIM(?))
          AND LOWER(TRIM(color))  = LOWER(TRIM(?))
          AND estado NOT IN ('entregada', 'retenida')
          AND (pedido_num IS NULL OR pedido_num = '')
          AND (cliente_email IS NULL OR cliente_email = '')
        ORDER BY
            CASE WHEN fecha_ingreso_pais IS NOT NULL THEN 0 ELSE 1 END,
            fecha_ingreso_pais ASC,
            freg ASC
        LIMIT 1
    ");
    $stmt->execute([$modelo, $color]);
    $moto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$moto) {
        return null; // No matching unit available
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
    } catch (PDOException $e) {
        // JSON_ARRAY_APPEND may fail on older MySQL — ignore log error
    }

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
    } catch (PDOException $e) {
        // ventas_log may not exist — ignore
    }

    return $motoId;
}

/**
 * Count available (unassigned) motos by model and color.
 *
 * @param PDO    $pdo
 * @param string $modelo  (optional, empty = all models)
 * @param string $color   (optional, empty = all colors)
 *
 * @return array  [['modelo'=>'M05','color'=>'negro','disponibles'=>3], ...]
 */
function contarDisponibles(PDO $pdo, string $modelo = '', string $color = ''): array {
    $where  = ["activo = 1", "estado NOT IN ('entregada','retenida')",
               "(pedido_num IS NULL OR pedido_num = '')",
               "(cliente_email IS NULL OR cliente_email = '')",
               "vin NOT REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'"];
    $params = [];

    if ($modelo) { $where[] = 'LOWER(TRIM(modelo)) = LOWER(TRIM(?))'; $params[] = $modelo; }
    if ($color)  { $where[] = 'LOWER(TRIM(color))  = LOWER(TRIM(?))'; $params[] = $color; }

    $sql  = "SELECT modelo, color, COUNT(*) AS disponibles
             FROM inventario_motos
             WHERE " . implode(' AND ', $where) . "
             GROUP BY modelo, color
             ORDER BY modelo, color";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
