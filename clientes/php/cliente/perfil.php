<?php
require_once __DIR__ . '/../bootstrap.php';
$cid = portalRequireAuth();
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $c = null; $sub = null;
    try {
        $stmt = $pdo->prepare("SELECT id, nombre, apellido_paterno, apellido_materno, email, telefono, fecha_nacimiento FROM clientes WHERE id = ?");
        $stmt->execute([$cid]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { error_log('perfil cliente: ' . $e->getMessage()); }

    try {
        $stmt = $pdo->prepare("SELECT modelo, color, serie, fecha_entrega FROM subscripciones_credito WHERE cliente_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$cid]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { error_log('perfil sub: ' . $e->getMessage()); }

    $pref = ['notif_email'=>1,'notif_whatsapp'=>1,'notif_sms'=>1,'idioma'=>'es'];
    try {
        $stmt = $pdo->prepare("SELECT notif_email, notif_whatsapp, notif_sms, idioma FROM portal_preferencias WHERE cliente_id = ?");
        $stmt->execute([$cid]);
        $pref = $stmt->fetch(PDO::FETCH_ASSOC) ?: $pref;
    } catch (Throwable $e) {}

    portalJsonOut(['cliente' => $c, 'moto' => $sub, 'preferencias' => $pref]);
}

// POST — update allowed fields
$in = portalJsonIn();
$allowed = [];
if (isset($in['email'])) {
    $email = strtolower(trim($in['email']));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) portalJsonOut(['error' => 'Correo inválido'], 400);
    $allowed['email'] = $email;
}
if (!empty($allowed)) {
    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($allowed)));
    $stmt = $pdo->prepare("UPDATE clientes SET $sets WHERE id = ?");
    $stmt->execute([...array_values($allowed), $cid]);
    portalLog('profile_update', ['success' => 1, 'detalle' => implode(',', array_keys($allowed))]);
}

if (isset($in['preferencias']) && is_array($in['preferencias'])) {
    $p = $in['preferencias'];
    $stmt = $pdo->prepare("INSERT INTO portal_preferencias (cliente_id, notif_email, notif_whatsapp, notif_sms, idioma)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE notif_email = VALUES(notif_email), notif_whatsapp = VALUES(notif_whatsapp),
            notif_sms = VALUES(notif_sms), idioma = VALUES(idioma)");
    $stmt->execute([
        $cid,
        !empty($p['notif_email']) ? 1 : 0,
        !empty($p['notif_whatsapp']) ? 1 : 0,
        !empty($p['notif_sms']) ? 1 : 0,
        $p['idioma'] ?? 'es',
    ]);
}

portalJsonOut(['status' => 'ok']);
