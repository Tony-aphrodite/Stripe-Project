<?php
/**
 * Voltika Portal - Bootstrap
 * Shared entry point for all portal endpoints.
 * Reuses configurador's config.php (DB, SMTP, SMS, Stripe, .env).
 */

// Reuse master bootstrap (DB, SMTP, SMS, Stripe, Truora, .env + all table schemas)
require_once __DIR__ . '/../../configurador_prueba_test/php/master-bootstrap.php';
voltikaEnsureSchema();

// ── CORS / JSON defaults ────────────────────────────────────────────────────
// Only set JSON content-type when this is an API request (not when included from index.php)
$isApiRequest = (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'index.php');
if (!headers_sent()) {
    if ($isApiRequest) {
        header('Content-Type: application/json');
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Session — shared portal session (different name to not clash with configurador)
if (session_status() === PHP_SESSION_NONE) {
    session_name('VOLTIKA_PORTAL');
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── Tables (idempotent auto-create) ─────────────────────────────────────────
function portalEnsureTables(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo = getDB();

        $pdo->exec("CREATE TABLE IF NOT EXISTS portal_auth_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cliente_id INT NULL,
            evento VARCHAR(40) NOT NULL,
            telefono VARCHAR(20) NULL,
            email VARCHAR(150) NULL,
            old_phone VARCHAR(20) NULL,
            new_phone VARCHAR(20) NULL,
            validation_method VARCHAR(40) NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            success TINYINT(1) DEFAULT 0,
            detalle VARCHAR(255) NULL,
            freg DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cliente (cliente_id),
            INDEX idx_evento (evento)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS portal_sesiones (
            id VARCHAR(128) PRIMARY KEY,
            cliente_id INT NOT NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cliente (cliente_id)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS ciclos_pago (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subscripcion_id INT NOT NULL,
            cliente_id INT NULL,
            semana_num INT NOT NULL,
            fecha_vencimiento DATE NOT NULL,
            monto DECIMAL(10,2) NOT NULL,
            estado ENUM('pending','paid_manual','paid_auto','overdue','skipped') DEFAULT 'pending',
            transaccion_id INT NULL,
            stripe_payment_intent VARCHAR(100) NULL,
            origen VARCHAR(30) NULL,
            freg DATETIME DEFAULT CURRENT_TIMESTAMP,
            fupd DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sub_semana (subscripcion_id, semana_num),
            INDEX idx_cliente (cliente_id),
            INDEX idx_estado (estado),
            INDEX idx_venc (fecha_vencimiento)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS portal_descargas_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cliente_id INT NOT NULL,
            doc_type VARCHAR(50) NOT NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            freg DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cliente (cliente_id)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS portal_preferencias (
            cliente_id INT PRIMARY KEY,
            notif_email TINYINT(1) DEFAULT 1,
            notif_whatsapp TINYINT(1) DEFAULT 1,
            notif_sms TINYINT(1) DEFAULT 1,
            idioma VARCHAR(5) DEFAULT 'es',
            fupd DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS portal_recordatorios_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cliente_id INT NOT NULL,
            ciclo_id INT NULL,
            tipo VARCHAR(20) NOT NULL,
            canal VARCHAR(20) NOT NULL,
            estado VARCHAR(20) DEFAULT 'sent',
            freg DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cliente (cliente_id),
            INDEX idx_ciclo (ciclo_id)
        )");

        // Ensure clientes table exists minimally (won't conflict if already present)
        $pdo->exec("CREATE TABLE IF NOT EXISTS clientes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(150) NULL,
            apellido_paterno VARCHAR(100) NULL,
            apellido_materno VARCHAR(100) NULL,
            email VARCHAR(150) NULL,
            telefono VARCHAR(20) NULL,
            fecha_nacimiento DATE NULL,
            freg DATETIME DEFAULT CURRENT_TIMESTAMP,
            fupd DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_telefono (telefono),
            INDEX idx_email (email)
        )");

        // Ensure subscripciones_credito has minimum columns expected by portal
        $pdo->exec("CREATE TABLE IF NOT EXISTS subscripciones_credito (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cliente_id INT NULL,
            telefono VARCHAR(20) NULL,
            email VARCHAR(150) NULL,
            modelo VARCHAR(200) NULL,
            color VARCHAR(50) NULL,
            serie VARCHAR(100) NULL,
            precio_contado DECIMAL(12,2) NULL,
            monto_semanal DECIMAL(10,2) NULL,
            plazo_meses INT NULL,
            plazo_semanas INT NULL,
            fecha_inicio DATE NULL,
            fecha_entrega DATE NULL,
            stripe_customer_id VARCHAR(100) NULL,
            stripe_payment_method_id VARCHAR(100) NULL,
            estado VARCHAR(30) DEFAULT 'activa',
            freg DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cliente (cliente_id),
            INDEX idx_telefono (telefono)
        )");

        // ── Upgrade legacy subscripciones_credito (Stripe flow schema) ─────
        // Add any columns the portal needs but that are missing on older tables
        $existing = [];
        try { $existing = $pdo->query("SHOW COLUMNS FROM subscripciones_credito")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
        $migrations = [
            'cliente_id'     => "ALTER TABLE subscripciones_credito ADD COLUMN cliente_id INT NULL",
            'modelo'         => "ALTER TABLE subscripciones_credito ADD COLUMN modelo VARCHAR(200) NULL",
            'color'          => "ALTER TABLE subscripciones_credito ADD COLUMN color VARCHAR(50) NULL",
            'serie'          => "ALTER TABLE subscripciones_credito ADD COLUMN serie VARCHAR(100) NULL",
            'precio_contado' => "ALTER TABLE subscripciones_credito ADD COLUMN precio_contado DECIMAL(12,2) NULL",
            'plazo_meses'    => "ALTER TABLE subscripciones_credito ADD COLUMN plazo_meses INT NULL",
            'plazo_semanas'  => "ALTER TABLE subscripciones_credito ADD COLUMN plazo_semanas INT NULL",
            'fecha_inicio'   => "ALTER TABLE subscripciones_credito ADD COLUMN fecha_inicio DATE NULL",
            'fecha_entrega'  => "ALTER TABLE subscripciones_credito ADD COLUMN fecha_entrega DATE NULL",
            'estado'         => "ALTER TABLE subscripciones_credito ADD COLUMN estado VARCHAR(30) DEFAULT 'activa'",
        ];
        foreach ($migrations as $col => $sql) {
            if (!in_array($col, $existing, true)) {
                try { $pdo->exec($sql); } catch (Throwable $e) { error_log("portal migrate {$col}: " . $e->getMessage()); }
            }
        }

        // If legacy 'status' column exists but estado doesn't have data yet,
        // mirror status into estado so portal queries find active subscriptions
        try {
            if (in_array('status', $existing, true)) {
                $pdo->exec("UPDATE subscripciones_credito
                    SET estado = CASE
                        WHEN status IN ('active','activa') THEN 'activa'
                        WHEN status IN ('cancelled','canceled','cancelada') THEN 'cancelada'
                        WHEN status IN ('completed','liquidada') THEN 'liquidada'
                        ELSE COALESCE(estado, 'activa')
                    END
                    WHERE estado IS NULL OR estado = ''");
            }
        } catch (Throwable $e) {}

        // Backfill cliente_id from telefono for rows that still have NULL
        try {
            $pdo->exec("UPDATE subscripciones_credito s
                JOIN clientes c ON c.telefono = s.telefono
                SET s.cliente_id = c.id
                WHERE s.cliente_id IS NULL AND s.telefono IS NOT NULL");
        } catch (Throwable $e) {}

    } catch (Throwable $e) {
        error_log('portalEnsureTables: ' . $e->getMessage());
    }
}
portalEnsureTables();

// ── Helpers ─────────────────────────────────────────────────────────────────
function portalJsonIn(): array {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function portalJsonOut($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function portalLog(string $evento, array $extra = []): void {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("INSERT INTO portal_auth_log
            (cliente_id, evento, telefono, email, old_phone, new_phone, validation_method, ip, user_agent, success, detalle)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $extra['cliente_id'] ?? ($_SESSION['portal_cliente_id'] ?? null),
            $evento,
            $extra['telefono'] ?? null,
            $extra['email'] ?? null,
            $extra['old_phone'] ?? null,
            $extra['new_phone'] ?? null,
            $extra['validation_method'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            !empty($extra['success']) ? 1 : 0,
            $extra['detalle'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('portalLog: ' . $e->getMessage());
    }
}

function portalRequireAuth(): int {
    $cid = $_SESSION['portal_cliente_id'] ?? null;
    if (!$cid) portalJsonOut(['error' => 'No autenticado', 'code' => 'AUTH_REQUIRED'], 401);
    return (int)$cid;
}

function portalNormPhone(string $raw): string {
    $p = preg_replace('/\D/', '', $raw);
    // Strip leading 52 (Mexico country code) if present and length > 10
    if (strlen($p) > 10 && substr($p, 0, 2) === '52') $p = substr($p, 2);
    return $p;
}

function portalGenOTP(): string {
    return str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

function portalSendSMS(string $telefono, string $mensaje): array {
    $ch = curl_init('https://api.smsmasivos.com.mx/sms/send');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'apikey: ' . SMSMASIVOS_API_KEY,
        'Content-Type: application/x-www-form-urlencoded',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'message' => $mensaje,
        'numbers' => $telefono,
        'country_code' => '52',
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return [
        'ok' => !$err && $code >= 200 && $code < 300 && !empty($data['success']),
        'httpCode' => $code,
        'response' => $resp,
    ];
}

/**
 * Find cliente by phone. Falls back to subscripciones_credito if clientes
 * table has no row with that telefono (common in early deployments).
 */
function portalFindClienteByPhone(string $tel): ?array {
    $pdo = getDB();
    // Exact match first
    $stmt = $pdo->prepare("SELECT * FROM clientes WHERE telefono = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$tel]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($c) return $c;

    // Normalized match (last 10 digits) — handles +52, 52 prefix differences
    if (strlen($tel) >= 10) {
        $last10 = substr($tel, -10);
        $stmt = $pdo->prepare("SELECT * FROM clientes WHERE RIGHT(REPLACE(REPLACE(telefono,'+',''),' ',''), 10) = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$last10]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($c) return $c;
    }

    // Fallback: try to hydrate from subscripciones_credito
    $stmt = $pdo->prepare("SELECT * FROM subscripciones_credito WHERE telefono = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$tel]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);

    // Normalized fallback for subscripciones_credito too
    if (!$sub && strlen($tel) >= 10) {
        $last10 = substr($tel, -10);
        $stmt = $pdo->prepare("SELECT * FROM subscripciones_credito WHERE RIGHT(REPLACE(REPLACE(telefono,'+',''),' ',''), 10) = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$last10]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$sub) return null;

    // Upsert minimal cliente row — try to find the name from inventario_motos or transacciones
    $nombre = null;
    $nStmt = $pdo->prepare("SELECT cliente_nombre FROM inventario_motos WHERE cliente_telefono = ? AND cliente_nombre IS NOT NULL AND cliente_nombre != '' ORDER BY id DESC LIMIT 1");
    $nStmt->execute([$tel]);
    $nombre = ($nStmt->fetchColumn()) ?: null;
    if (!$nombre) {
        $nStmt = $pdo->prepare("SELECT nombre FROM transacciones WHERE telefono = ? AND nombre IS NOT NULL AND nombre != '' ORDER BY id DESC LIMIT 1");
        $nStmt->execute([$tel]);
        $nombre = ($nStmt->fetchColumn()) ?: null;
    }

    $stmt = $pdo->prepare("INSERT INTO clientes (telefono, email, nombre) VALUES (?, ?, ?)");
    $stmt->execute([$tel, $sub['email'] ?? null, $nombre]);
    $cid = (int)$pdo->lastInsertId();
    // Link the subscription if not already linked
    $pdo->prepare("UPDATE subscripciones_credito SET cliente_id = ? WHERE id = ? AND (cliente_id IS NULL OR cliente_id = 0)")
        ->execute([$cid, $sub['id']]);
    return ['id' => $cid, 'telefono' => $tel, 'email' => $sub['email'] ?? null, 'nombre' => $nombre];
}

function portalFindClienteByEmail(string $email): ?array {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM clientes WHERE email = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$email]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($c) return $c;
    $stmt = $pdo->prepare("SELECT * FROM subscripciones_credito WHERE email = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$email]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sub) return null;

    $nombre = null;
    $nStmt = $pdo->prepare("SELECT cliente_nombre FROM inventario_motos WHERE cliente_email = ? AND cliente_nombre IS NOT NULL AND cliente_nombre != '' ORDER BY id DESC LIMIT 1");
    $nStmt->execute([$email]);
    $nombre = ($nStmt->fetchColumn()) ?: null;
    if (!$nombre) {
        $nStmt = $pdo->prepare("SELECT nombre FROM transacciones WHERE email = ? AND nombre IS NOT NULL AND nombre != '' ORDER BY id DESC LIMIT 1");
        $nStmt->execute([$email]);
        $nombre = ($nStmt->fetchColumn()) ?: null;
    }

    $stmt = $pdo->prepare("INSERT INTO clientes (telefono, email, nombre) VALUES (?, ?, ?)");
    $stmt->execute([$sub['telefono'] ?? null, $email, $nombre]);
    $cid = (int)$pdo->lastInsertId();
    return ['id' => $cid, 'telefono' => $sub['telefono'] ?? null, 'email' => $email, 'nombre' => $nombre];
}

/**
 * Compute account state for a cliente's active subscription.
 * Returns: [state, subscripcion, proximoCiclo, progreso]
 */
function portalComputeAccountState(int $clienteId): array {
    $pdo = getDB();
    $sub = null;
    try {
        // Try by cliente_id first
        $stmt = $pdo->prepare("SELECT * FROM subscripciones_credito
            WHERE cliente_id = ?
            AND (estado IS NULL OR estado NOT IN ('cancelada','liquidada'))
            ORDER BY id DESC LIMIT 1");
        $stmt->execute([$clienteId]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { error_log('portalComputeAccountState sub: ' . $e->getMessage()); }

    // Fallback: lookup by telefono from clientes table
    if (!$sub) {
        try {
            $stmt = $pdo->prepare("SELECT s.* FROM subscripciones_credito s
                JOIN clientes c ON c.telefono = s.telefono
                WHERE c.id = ?
                AND (s.estado IS NULL OR s.estado NOT IN ('cancelada','liquidada'))
                ORDER BY s.id DESC LIMIT 1");
            $stmt->execute([$clienteId]);
            $sub = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { error_log('portalComputeAccountState fallback: ' . $e->getMessage()); }
    }

    if (!$sub) {
        return [
            'state' => 'no_subscription', 'subscripcion' => null, 'proximoCiclo' => null,
            'progreso' => 0, 'total_ciclos' => 0, 'ciclos_pagados' => 0,
        ];
    }

    try { portalEnsureCiclos($sub); } catch (Throwable $e) { error_log('ensureCiclos: ' . $e->getMessage()); }

    // Find next pending or overdue cycle
    $next = null; $tot = 0; $pag = 0;
    try {
        $stmt = $pdo->prepare("SELECT * FROM ciclos_pago
            WHERE subscripcion_id = ? AND estado IN ('pending','overdue')
            ORDER BY fecha_vencimiento ASC LIMIT 1");
        $stmt->execute([$sub['id']]);
        $next = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $tot = (int)$pdo->query("SELECT COUNT(*) FROM ciclos_pago WHERE subscripcion_id = " . (int)$sub['id'])->fetchColumn();
        $pag = (int)$pdo->query("SELECT COUNT(*) FROM ciclos_pago WHERE subscripcion_id = " . (int)$sub['id'] . " AND estado IN ('paid_manual','paid_auto')")->fetchColumn();
    } catch (Throwable $e) { error_log('portalComputeAccountState ciclos: ' . $e->getMessage()); }
    $progreso = $tot > 0 ? round(($pag / $tot) * 100, 1) : 0;

    $state = 'account_current';
    if ($next) {
        $today = strtotime(date('Y-m-d'));
        $venc  = strtotime($next['fecha_vencimiento']);
        $diff  = ($venc - $today) / 86400;
        if ($next['estado'] === 'overdue' || $diff < 0) $state = 'payment_overdue';
        elseif ($diff == 0) $state = 'payment_due_today';
        elseif ($diff <= 2) $state = 'payment_due_soon';
        else $state = 'account_current';
    }

    return [
        'state' => $state,
        'subscripcion' => $sub,
        'proximoCiclo' => $next ?: null,
        'progreso' => $progreso,
        'total_ciclos' => $tot,
        'ciclos_pagados' => $pag,
    ];
}

/**
 * Ensure ciclos_pago rows exist for the full lifetime of a subscription.
 * Idempotent — creates missing weeks only.
 */
function portalEnsureCiclos(array $sub): void {
    $pdo = getDB();
    $subId = (int)$sub['id'];
    $monto = (float)($sub['monto_semanal'] ?? 0);
    if ($monto <= 0) return;

    $semanas = (int)($sub['plazo_semanas'] ?? 0);
    if ($semanas <= 0) {
        $meses = (int)($sub['plazo_meses'] ?? 12);
        $semanas = (int)round($meses * 4.3333);
    }
    if ($semanas <= 0) return;

    // Weekly payment countdown starts ONLY when the motorcycle is delivered.
    // Before delivery (fecha_inicio NULL, fecha_entrega NULL) we don't generate
    // any ciclos — the historical freg fallback was causing phantom "pending"
    // payments to appear in the client portal before the customer even had
    // their bike.
    $inicio = $sub['fecha_inicio'] ?? $sub['fecha_entrega'] ?? null;
    if (!$inicio) return;
    $inicioTs = strtotime(substr($inicio, 0, 10));
    if (!$inicioTs) return;

    $stmt = $pdo->prepare("SELECT MAX(semana_num) FROM ciclos_pago WHERE subscripcion_id = ?");
    $stmt->execute([$subId]);
    $max = (int)$stmt->fetchColumn();

    if ($max >= $semanas) return;

    $ins = $pdo->prepare("INSERT IGNORE INTO ciclos_pago
        (subscripcion_id, cliente_id, semana_num, fecha_vencimiento, monto, estado)
        VALUES (?, ?, ?, ?, ?, 'pending')");

    for ($n = $max + 1; $n <= $semanas; $n++) {
        $venc = date('Y-m-d', strtotime("+$n week", $inicioTs));
        $ins->execute([$subId, $sub['cliente_id'] ?? null, $n, $venc, $monto]);
    }

    // Mark past pending cycles as overdue
    $pdo->prepare("UPDATE ciclos_pago SET estado = 'overdue'
        WHERE subscripcion_id = ? AND estado = 'pending' AND fecha_vencimiento < CURDATE()")
        ->execute([$subId]);
}
