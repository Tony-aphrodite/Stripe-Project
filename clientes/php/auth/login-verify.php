<?php
require_once __DIR__ . '/../bootstrap.php';

$in = portalJsonIn();
$tel = portalNormPhone($in['telefono'] ?? '');
$cod = preg_replace('/\D/', '', $in['codigo'] ?? '');

// Try DB first (authoritative), then session (fast path)
$pdo = getDB();
$otp = null;
try {
    $stmt = $pdo->prepare("SELECT codigo, cliente_id, expira FROM portal_otp WHERE telefono=? LIMIT 1");
    $stmt->execute([$tel]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $otp = [
            'codigo'     => $row['codigo'],
            'telefono'   => $tel,
            'cliente_id' => (int)$row['cliente_id'],
            'expira'     => (int)$row['expira'],
        ];
    }
} catch (Throwable $e) { error_log('portal_otp read: ' . $e->getMessage()); }

if (!$otp) {
    $otp = $_SESSION['portal_otp_login'] ?? null;
}

if (!$otp || $otp['telefono'] !== $tel || time() > ($otp['expira'] ?? 0)) {
    portalLog('login_verify', ['telefono' => $tel, 'success' => 0, 'detalle' => 'otp_expirado']);
    portalJsonOut(['error' => 'Código expirado. Solicita uno nuevo.'], 400);
}
if ($otp['codigo'] !== $cod) {
    portalLog('login_verify', ['telefono' => $tel, 'success' => 0, 'detalle' => 'otp_invalido']);
    portalJsonOut(['error' => 'Código incorrecto.'], 400);
}

$_SESSION['portal_cliente_id'] = (int)$otp['cliente_id'];
$_SESSION['portal_login_at']   = time();
unset($_SESSION['portal_otp_login']);
try { $pdo->prepare("DELETE FROM portal_otp WHERE telefono=?")->execute([$tel]); } catch (Throwable $e) {}

// Persist session row
try {
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO portal_sesiones (id, cliente_id, ip, user_agent)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE last_seen = CURRENT_TIMESTAMP");
    $stmt->execute([
        session_id(),
        (int)$otp['cliente_id'],
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
} catch (Throwable $e) { error_log($e->getMessage()); }

portalLog('login_verify', [
    'telefono' => $tel,
    'cliente_id' => (int)$otp['cliente_id'],
    'success' => 1,
]);

// Load cliente for frontend — same column-probing + name-fallback logic as
// me.php so the JS state always lands with a usable name. Without this the
// frontend showed "¡Hola, Cliente!" right after login until a page reload.
function lvIsPlaceholderName(?string $n): bool {
    $n = trim((string)$n);
    if ($n === '') return true;
    $low = mb_strtolower($n);
    foreach (['cliente', 'cliente voltika', 'sin nombre', 'n/a', 'none'] as $bad) {
        if ($low === $bad) return true;
    }
    return false;
}

$cli = null;
$cidLogin = (int)$otp['cliente_id'];
try {
    $cols = $pdo->query("SHOW COLUMNS FROM clientes")->fetchAll(PDO::FETCH_COLUMN);
    $colSet = array_flip($cols);
    $select = ['id', 'nombre', 'email', 'telefono'];
    if (isset($colSet['apellido_paterno'])) $select[] = 'apellido_paterno';
    if (isset($colSet['apellido_materno'])) $select[] = 'apellido_materno';

    $stmt = $pdo->prepare("SELECT " . implode(',', $select) . " FROM clientes WHERE id=? LIMIT 1");
    $stmt->execute([$cidLogin]);
    $cli = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($cli && lvIsPlaceholderName($cli['nombre'] ?? null)) {
        $real = null;
        $em  = $cli['email']    ?? null;
        $tel2 = $cli['telefono'] ?? null;

        try {
            $q = $pdo->prepare("SELECT nombre FROM subscripciones_credito
                                 WHERE (cliente_id = ?
                                     OR (? <> '' AND email = ?)
                                     OR (? <> '' AND telefono = ?))
                                   AND nombre IS NOT NULL AND TRIM(nombre) <> ''
                              ORDER BY id DESC LIMIT 1");
            $q->execute([$cidLogin, $em ?: '', $em ?: '', $tel2 ?: '', $tel2 ?: '']);
            $cand = trim((string)($q->fetchColumn() ?: ''));
            if ($cand && !lvIsPlaceholderName($cand)) $real = $cand;
        } catch (Throwable $e) {}

        if (!$real && ($em || $tel2)) {
            try {
                $q = $pdo->prepare("SELECT nombre FROM transacciones
                                     WHERE ((? <> '' AND email = ?) OR (? <> '' AND telefono = ?))
                                       AND nombre IS NOT NULL AND TRIM(nombre) <> ''
                                  ORDER BY id DESC LIMIT 1");
                $q->execute([$em ?: '', $em ?: '', $tel2 ?: '', $tel2 ?: '']);
                $cand = trim((string)($q->fetchColumn() ?: ''));
                if ($cand && !lvIsPlaceholderName($cand)) $real = $cand;
            } catch (Throwable $e) {}
        }
        if (!$real && ($em || $tel2)) {
            try {
                $q = $pdo->prepare("SELECT cliente_nombre FROM inventario_motos
                                     WHERE ((? <> '' AND cliente_email = ?) OR (? <> '' AND cliente_telefono = ?))
                                       AND cliente_nombre IS NOT NULL AND TRIM(cliente_nombre) <> ''
                                  ORDER BY id DESC LIMIT 1");
                $q->execute([$em ?: '', $em ?: '', $tel2 ?: '', $tel2 ?: '']);
                $cand = trim((string)($q->fetchColumn() ?: ''));
                if ($cand && !lvIsPlaceholderName($cand)) $real = $cand;
            } catch (Throwable $e) {}
        }
        if ($real) {
            $cli['nombre'] = $real;
            try { $pdo->prepare("UPDATE clientes SET nombre = ? WHERE id = ?")->execute([$real, $cidLogin]); }
            catch (Throwable $e) {}
        }
    }

    if ($cli) {
        $parts = array_filter([
            trim((string)($cli['nombre'] ?? '')),
            trim((string)($cli['apellido_paterno'] ?? '')),
            trim((string)($cli['apellido_materno'] ?? '')),
        ], 'strlen');
        $cli['nombre_completo'] = $parts ? implode(' ', $parts) : '';
    }
} catch (Throwable $e) { error_log('login-verify cliente: ' . $e->getMessage()); }

portalJsonOut([
    'ok' => true,
    'status' => 'ok',
    'cliente_id' => $cidLogin,
    'cliente' => $cli,
]);
