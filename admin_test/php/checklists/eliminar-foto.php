<?php
/**
 * POST — Remove a photo from a checklist
 * Body: { checklist_tipo, moto_id, campo, url }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$tipo   = $d['checklist_tipo'] ?? '';
$motoId = (int)($d['moto_id'] ?? 0);
$campo  = $d['campo'] ?? '';
$url    = $d['url'] ?? '';

if (!$motoId || !$tipo || !$campo || !$url) {
    adminJsonOut(['ok' => false, 'error' => 'Parámetros incompletos'], 400);
}

$tableMap = ['origen' => 'checklist_origen', 'ensamble' => 'checklist_ensamble', 'entrega' => 'checklist_entrega_v2'];
$table = $tableMap[$tipo] ?? '';
if (!$table) adminJsonOut(['ok' => false, 'error' => 'Tipo inválido'], 400);

$pdo = getDB();
$stmt = $pdo->prepare("SELECT id, completado, $campo FROM $table WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$stmt->execute([$motoId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) adminJsonOut(['ok' => false, 'error' => 'Checklist no encontrado'], 404);
if ($row['completado']) adminJsonOut(['ok' => false, 'error' => 'Checklist completado, no se puede modificar'], 403);

$fotos = json_decode($row[$campo] ?: '[]', true) ?: [];
$fotos = array_values(array_filter($fotos, function($f) use ($url) { return $f !== $url; }));

$pdo->prepare("UPDATE $table SET $campo=? WHERE id=?")->execute([
    json_encode($fotos, JSON_UNESCAPED_UNICODE),
    $row['id']
]);

// Delete file from disk — extract filename from proxy URL or direct path
$fname = $url;
if (strpos($url, 'serve-foto.php?f=') !== false) {
    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $qp);
    $fname = $qp['f'] ?? '';
}
$fname = basename($fname); // security: only filename, no path traversal
if ($fname) {
    $filePath = __DIR__ . '/../../uploads/checklists/' . $fname;
    if (file_exists($filePath)) {
        @unlink($filePath);
    } else {
        // Fallback: try legacy temp directory
        $legacyPath = sys_get_temp_dir() . '/voltika_checklists/' . $fname;
        if (file_exists($legacyPath)) @unlink($legacyPath);
    }
}

adminLog('checklist_foto_eliminar', ['tipo' => $tipo, 'moto_id' => $motoId, 'campo' => $campo, 'url' => $url]);
adminJsonOut(['ok' => true]);
