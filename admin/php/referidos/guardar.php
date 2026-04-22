<?php
/**
 * POST — Create / update / delete a referido (influencer)
 * { accion: 'agregar'|'actualizar'|'eliminar', ... }
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$d = adminJsonIn();
$accion = trim($d['accion'] ?? '');

// Shared helper: upsert comisiones map for a referido. Keys are productos.js
// slugs (m05, pesgo-plus, ...), values are MXN amounts. Empty/zero keys are
// deleted so they don't leak into payout calculations.
$saveComisiones = static function (PDO $pdo, int $refId, $comisiones): void {
    if (!is_array($comisiones)) return;
    $upd = $pdo->prepare(
        "INSERT INTO referido_comisiones (referido_id, modelo_slug, comision_monto) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE comision_monto = VALUES(comision_monto)"
    );
    $del = $pdo->prepare("DELETE FROM referido_comisiones WHERE referido_id = ? AND modelo_slug = ?");
    foreach ($comisiones as $slug => $monto) {
        $slug = strtolower(trim((string)$slug));
        if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9\-]{0,49}$/', $slug)) continue;
        $n = is_numeric($monto) ? (float)$monto : 0;
        if ($n <= 0) { $del->execute([$refId, $slug]); continue; }
        $upd->execute([$refId, $slug, $n]);
    }
};

if ($accion === 'agregar') {
    $nombre = trim($d['nombre'] ?? '');
    $email  = trim($d['email'] ?? '');
    $tel    = trim($d['telefono'] ?? '');
    $codigoCustom = strtoupper(trim($d['codigo'] ?? ''));

    if (!$nombre) adminJsonOut(['ok' => false, 'error' => 'Nombre requerido'], 400);

    if ($codigoCustom !== '') {
        if (!preg_match('/^[A-Z0-9]{3,20}$/', $codigoCustom)) {
            adminJsonOut(['ok' => false, 'error' => 'Código inválido (solo A-Z, 0-9, 3-20 caracteres)'], 400);
        }
        $chk = $pdo->prepare("SELECT id FROM referidos WHERE codigo_referido = ? LIMIT 1");
        $chk->execute([$codigoCustom]);
        if ($chk->fetchColumn()) {
            adminJsonOut(['ok' => false, 'error' => 'Ese código ya está en uso'], 409);
        }
        $codigo = $codigoCustom;
    } else {
        $codigo = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $nombre), 0, 4)) . rand(100, 999);
    }

    $pdo->prepare("INSERT INTO referidos (nombre, email, telefono, codigo_referido) VALUES (?,?,?,?)")
        ->execute([$nombre, $email, $tel, $codigo]);
    $newId = (int)$pdo->lastInsertId();

    $saveComisiones($pdo, $newId, $d['comisiones'] ?? []);

    adminLog('referido_crear', [
        'nombre' => $nombre,
        'codigo' => $codigo,
        'comisiones' => $d['comisiones'] ?? [],
    ]);
    adminJsonOut(['ok' => true, 'id' => $newId, 'codigo' => $codigo]);
}

if ($accion === 'actualizar') {
    $id     = (int)($d['id'] ?? 0);
    $nombre = trim($d['nombre'] ?? '');
    $email  = trim($d['email'] ?? '');
    $tel    = trim($d['telefono'] ?? '');
    $codigoCustom = strtoupper(trim($d['codigo'] ?? ''));

    if (!$id) adminJsonOut(['ok' => false, 'error' => 'ID requerido'], 400);

    if ($codigoCustom !== '') {
        if (!preg_match('/^[A-Z0-9]{3,20}$/', $codigoCustom)) {
            adminJsonOut(['ok' => false, 'error' => 'Código inválido (solo A-Z, 0-9, 3-20 caracteres)'], 400);
        }
        $chk = $pdo->prepare("SELECT id FROM referidos WHERE codigo_referido = ? AND id <> ? LIMIT 1");
        $chk->execute([$codigoCustom, $id]);
        if ($chk->fetchColumn()) {
            adminJsonOut(['ok' => false, 'error' => 'Ese código ya está en uso'], 409);
        }
        $pdo->prepare("UPDATE referidos SET nombre=?, email=?, telefono=?, codigo_referido=? WHERE id=?")
            ->execute([$nombre, $email, $tel, $codigoCustom, $id]);
    } else {
        $pdo->prepare("UPDATE referidos SET nombre=?, email=?, telefono=? WHERE id=?")
            ->execute([$nombre, $email, $tel, $id]);
    }

    $saveComisiones($pdo, $id, $d['comisiones'] ?? []);

    adminLog('referido_actualizar', [
        'id' => $id,
        'comisiones' => $d['comisiones'] ?? [],
    ]);
    adminJsonOut(['ok' => true]);
}

if ($accion === 'eliminar') {
    $id = (int)($d['id'] ?? 0);
    if (!$id) adminJsonOut(['ok' => false, 'error' => 'ID requerido'], 400);

    $pdo->prepare("UPDATE referidos SET activo = 0 WHERE id = ?")->execute([$id]);
    adminLog('referido_eliminar', ['id' => $id]);
    adminJsonOut(['ok' => true]);
}

adminJsonOut(['ok' => false, 'error' => 'Acción no reconocida'], 400);
