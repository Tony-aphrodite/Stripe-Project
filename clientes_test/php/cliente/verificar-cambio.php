<?php
/**
 * POST — Verify OTP and apply email/phone change
 * Body: { campo: "email"|"telefono", codigo: "123456" }
 */
require_once __DIR__ . '/../bootstrap.php';
$cid = portalRequireAuth();

$in = portalJsonIn();
$campo  = $in['campo'] ?? '';
$codigo = trim($in['codigo'] ?? '');

if (!in_array($campo, ['email', 'telefono']))
    portalJsonOut(['error' => 'Campo inválido'], 400);
if (!$codigo)
    portalJsonOut(['error' => 'Código requerido'], 400);

$pdo = getDB();

// Find pending OTP
$stmt = $pdo->prepare("SELECT id, nuevo_valor, otp_code, otp_expires FROM portal_cambios_otp
    WHERE cliente_id = ? AND campo = ? AND verificado = 0
    ORDER BY id DESC LIMIT 1");
$stmt->execute([$cid, $campo]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row)
    portalJsonOut(['ok' => false, 'error' => 'No hay solicitud de cambio pendiente']);

if (strtotime($row['otp_expires']) < time())
    portalJsonOut(['ok' => false, 'error' => 'El código ha expirado. Solicita uno nuevo.']);

if ($row['otp_code'] !== $codigo)
    portalJsonOut(['ok' => false, 'error' => 'Código incorrecto']);

// Mark OTP as verified
$pdo->prepare("UPDATE portal_cambios_otp SET verificado = 1 WHERE id = ?")->execute([$row['id']]);

// Apply the change
$nuevoValor = $row['nuevo_valor'];
$pdo->prepare("UPDATE clientes SET $campo = ? WHERE id = ?")->execute([$nuevoValor, $cid]);

portalLog('cambio_verificado', ['campo' => $campo, 'nuevo' => $campo === 'email' ? $nuevoValor : '••••' . substr($nuevoValor, -4)]);

portalJsonOut(['ok' => true, 'campo' => $campo, 'nuevo_valor' => $nuevoValor]);
