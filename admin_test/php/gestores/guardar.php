<?php
/**
 * POST — Create or update a gestor de placas.
 *
 * Body: { id?, estado_mx, nombre, telefono?, email?, whatsapp?, notas?, activo? }
 *   - id present → UPDATE
 *   - id absent  → INSERT
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$d = adminJsonIn();
$id         = (int)($d['id'] ?? 0);
$estado     = trim($d['estado_mx'] ?? '');
$nombre     = trim($d['nombre']    ?? '');
$telefono   = trim($d['telefono']  ?? '');
$email      = trim($d['email']     ?? '');
$whatsapp   = trim($d['whatsapp']  ?? '');
$notas      = trim($d['notas']     ?? '');
$activo     = isset($d['activo']) ? (int)(!!$d['activo']) : 1;

if ($estado === '' || $nombre === '') {
    adminJsonOut(['error' => 'estado_mx y nombre son requeridos'], 400);
}
if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    adminJsonOut(['error' => 'Email inválido'], 400);
}

$pdo = getDB();

if ($id > 0) {
    $pdo->prepare("UPDATE gestores_placas
        SET estado_mx=?, nombre=?, telefono=?, email=?, whatsapp=?, notas=?, activo=?
        WHERE id=?")
      ->execute([$estado, $nombre, $telefono ?: null, $email ?: null, $whatsapp ?: null, $notas ?: null, $activo, $id]);
    adminLog('gestor_placas_update', ['id' => $id, 'estado' => $estado, 'nombre' => $nombre]);
    adminJsonOut(['ok' => true, 'id' => $id]);
}

$pdo->prepare("INSERT INTO gestores_placas
    (estado_mx, nombre, telefono, email, whatsapp, notas, activo)
    VALUES (?,?,?,?,?,?,?)")
  ->execute([$estado, $nombre, $telefono ?: null, $email ?: null, $whatsapp ?: null, $notas ?: null, $activo]);
$newId = (int)$pdo->lastInsertId();

adminLog('gestor_placas_create', ['id' => $newId, 'estado' => $estado, 'nombre' => $nombre]);
adminJsonOut(['ok' => true, 'id' => $newId]);
