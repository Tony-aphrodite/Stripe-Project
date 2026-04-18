<?php
/**
 * Voltika Portal - Marcar ciclos vencidos como overdue
 * Cron: ejecutar diariamente a medianoche.
 *
 * Actualiza todos los ciclos_pago cuya fecha_vencimiento ya pasó
 * y siguen en estado 'pending' a 'overdue'.
 */
require_once __DIR__ . '/../bootstrap.php';

$pdo = getDB();

$stmt = $pdo->prepare("UPDATE ciclos_pago SET estado = 'overdue'
    WHERE estado = 'pending' AND fecha_vencimiento < CURDATE()");
$stmt->execute();

$updated = $stmt->rowCount();
echo date('Y-m-d H:i:s') . " - Ciclos marcados como overdue: $updated\n";
