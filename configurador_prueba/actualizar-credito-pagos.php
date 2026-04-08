<?php
/**
 * Voltika — Create pagos_credito table for credit payment tracking
 * Run once: ?key=voltika_pagos_credito_2026
 */
$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika_pagos_credito_2026') { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/php/config.php';
header('Content-Type: text/html; charset=UTF-8');
$pdo = getDB();

$sqls = [
    "CREATE TABLE IF NOT EXISTS pagos_credito (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        moto_id         INT,
        transaccion_id  INT,
        cliente_nombre  VARCHAR(200),
        cliente_email   VARCHAR(200),
        cliente_telefono VARCHAR(30),
        pedido_num      VARCHAR(50),
        modelo          VARCHAR(100),
        color           VARCHAR(50),
        precio_total    DECIMAL(12,2) DEFAULT 0,
        enganche        DECIMAL(12,2) DEFAULT 0,
        monto_financiado DECIMAL(12,2) DEFAULT 0,
        plazo_meses     INT DEFAULT 12,
        pago_semanal    DECIMAL(12,2) DEFAULT 0,
        semanas_total   INT DEFAULT 52,
        semanas_pagadas INT DEFAULT 0,
        monto_pagado    DECIMAL(12,2) DEFAULT 0,
        monto_restante  DECIMAL(12,2) DEFAULT 0,
        estado_credito  ENUM('activo','completado','moroso','cancelado') DEFAULT 'activo',
        stripe_customer_id VARCHAR(100),
        stripe_subscription_id VARCHAR(100),
        proximo_pago    DATE,
        ultimo_pago     DATE,
        freg            DATETIME DEFAULT CURRENT_TIMESTAMP,
        fmod            DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS pagos_credito_historial (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        credito_id      INT NOT NULL,
        semana_num      INT,
        monto           DECIMAL(12,2),
        estado          ENUM('pagado','pendiente','fallido','reembolsado') DEFAULT 'pendiente',
        metodo          VARCHAR(50),
        stripe_pi       VARCHAR(100),
        fecha_programada DATE,
        fecha_pago      DATETIME,
        notas           TEXT,
        freg            DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

echo '<h1>Voltika — Credit Payment Tables</h1>';
foreach ($sqls as $sql) {
    try {
        $pdo->exec($sql);
        echo '<p style="color:green;">✅ OK</p>';
    } catch (PDOException $e) {
        echo '<p style="color:red;">❌ ' . $e->getMessage() . '</p>';
    }
}
echo '<hr><p style="color:#C62828;">⚠️ Eliminar después de ejecutar.</p>';
