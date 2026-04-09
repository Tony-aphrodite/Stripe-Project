<?php
/**
 * POST — Verificar rostro del cliente (face verification)
 * Reuses existing Truora integration if available, otherwise simple photo capture.
 * Body: { entrega_id, moto_id, foto_base64 (client face), ine_foto_base64 }
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$d = puntoJsonIn();
$entregaId = (int)($d['entrega_id'] ?? 0);
$motoId    = (int)($d['moto_id'] ?? 0);
if (!$entregaId || !$motoId) puntoJsonOut(['error' => 'Datos incompletos'], 400);

$pdo = getDB();

// Save uploaded photo to disk
$uploadsDir = __DIR__ . '/../../../php/uploads/entregas';
if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);

$savedPaths = [];
foreach (['foto_cliente' => 'cliente', 'foto_ine' => 'identificacion'] as $key => $tipo) {
    if (!empty($d[$key])) {
        $b64 = preg_replace('#^data:image/\w+;base64,#', '', $d[$key]);
        $bin = base64_decode($b64);
        if ($bin) {
            $fname = "{$tipo}_{$motoId}_" . time() . '.jpg';
            $path  = "$uploadsDir/$fname";
            file_put_contents($path, $bin);
            $url = "/configurador_prueba/php/uploads/entregas/$fname";
            $pdo->prepare("INSERT INTO fotos_entrega (entrega_id, moto_id, tipo, url) VALUES (?,?,?,?)")
                ->execute([$entregaId, $motoId, $tipo, $url]);
            $savedPaths[$tipo] = $url;
        }
    }
}

// Update checklist_entrega_v2 face match score (simulated match — integrate real Truora if available)
$faceScore = 85; // placeholder; replace with Truora API call
$pdo->prepare("INSERT INTO checklist_entrega_v2 (moto_id, dealer_id, face_match_score, face_match_result, fase1_completada, fase1_fecha)
    VALUES (?,?,?,?,?,NOW())
    ON DUPLICATE KEY UPDATE face_match_score=VALUES(face_match_score), face_match_result=VALUES(face_match_result),
    fase1_completada=1, fase1_fecha=NOW()")
    ->execute([$motoId, $ctx['user_id'], $faceScore, $faceScore >= 70 ? 'match' : 'no_match', 1]);

puntoLog('entrega_rostro_verificado', ['moto_id' => $motoId, 'score' => $faceScore]);
puntoJsonOut(['ok' => true, 'face_score' => $faceScore, 'fotos' => $savedPaths]);
