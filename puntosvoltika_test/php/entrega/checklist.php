<?php
/**
 * POST — Guardar checklist de entrega (fase3 + fotos moto)
 * Body: { moto_id, vin_coincide, estado_fisico_ok, sin_danos, unidad_completa, fotos_moto:[base64] }
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$d = puntoJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
if (!$motoId) puntoJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

// Step-order guard — per dashboards_diagrams.pdf (Delivery process step 5),
// the delivery checklist can only be filled AFTER step 3 (OTP verified) and
// step 4 (user verification / face check). Both must be recorded in the
// entregas / checklist_entrega_v2 tables.
$guard = $pdo->prepare("SELECT otp_verified FROM entregas WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$guard->execute([$motoId]);
$otpOk = (int)($guard->fetchColumn() ?: 0);
if (!$otpOk) {
    puntoJsonOut(['error' => 'OTP del cliente no verificado — no se puede llenar el checklist'], 409);
}
$guard = $pdo->prepare("SELECT fase1_completada FROM checklist_entrega_v2 WHERE moto_id=? ORDER BY id DESC LIMIT 1");
$guard->execute([$motoId]);
$faceOk = (int)($guard->fetchColumn() ?: 0);
if (!$faceOk) {
    puntoJsonOut(['error' => 'Verificación facial pendiente — no se puede llenar el checklist'], 409);
}

// Save moto photos
$uploadsDir = __DIR__ . '/../../../configurador_prueba/php/uploads/entregas';
if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);

$entregaStmt = $pdo->prepare("SELECT id FROM entregas WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$entregaStmt->execute([$motoId]);
$entregaId = (int)($entregaStmt->fetchColumn() ?: 0);

if (!empty($d['fotos_moto']) && is_array($d['fotos_moto'])) {
    $tipos = ['moto_frente','moto_lateral','moto_trasera','otra'];
    foreach ($d['fotos_moto'] as $i => $b64) {
        $bin = base64_decode(preg_replace('#^data:image/\w+;base64,#','',$b64));
        if ($bin) {
            $tipo = $tipos[$i] ?? 'otra';
            $fname = "{$tipo}_{$motoId}_" . time() . "_$i.jpg";
            file_put_contents("$uploadsDir/$fname", $bin);
            $url = "/configurador_prueba/php/uploads/entregas/$fname";
            $pdo->prepare("INSERT INTO fotos_entrega (entrega_id, moto_id, tipo, url) VALUES (?,?,?,?)")
                ->execute([$entregaId, $motoId, $tipo, $url]);
        }
    }
}

// Update checklist_entrega_v2 fase3 (unit)
$pdo->prepare("INSERT INTO checklist_entrega_v2 (moto_id, dealer_id, vin_coincide, estado_fisico_ok, sin_danos, unidad_completa, fase3_completada, fase3_fecha)
    VALUES (?,?,?,?,?,?,1,NOW())
    ON DUPLICATE KEY UPDATE vin_coincide=VALUES(vin_coincide), estado_fisico_ok=VALUES(estado_fisico_ok),
        sin_danos=VALUES(sin_danos), unidad_completa=VALUES(unidad_completa), fase3_completada=1, fase3_fecha=NOW()")
    ->execute([
        $motoId, $ctx['user_id'],
        (int)($d['vin_coincide'] ?? 0),
        (int)($d['estado_fisico_ok'] ?? 0),
        (int)($d['sin_danos'] ?? 0),
        (int)($d['unidad_completa'] ?? 0)
    ]);

puntoLog('entrega_checklist', ['moto_id' => $motoId]);
puntoJsonOut(['ok' => true]);
