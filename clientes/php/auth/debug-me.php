<?php
/**
 * Diagnostic — dumps the raw clientes row plus what me.php would return.
 * /clientes/php/auth/debug-me.php
 */
require_once __DIR__ . '/../bootstrap.php';
$cid = $_SESSION['portal_cliente_id'] ?? null;

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><title>debug-me</title>
<style>body{font-family:Segoe UI,Arial,sans-serif;background:#f5f7fa;color:#1a3a5c;padding:24px;}
h1{font-size:20px}table{border-collapse:collapse;width:100%;max-width:800px;font-size:13px}
th,td{padding:8px 12px;border-bottom:1px solid #eef2f5;text-align:left;vertical-align:top}
th{background:#eef2f5;width:240px}.empty{color:#c00;font-style:italic}
code{background:#f7fafc;padding:2px 6px;border-radius:3px}</style></head><body>

<h1>Diagnóstico de me.php</h1>

<?php if (!$cid): ?>
  <p><strong style="color:#c00;">No hay sesión activa.</strong> Inicia sesión primero, luego vuelve a esta página.</p>
<?php else: ?>
  <p>portal_cliente_id (sesión) = <code><?= (int)$cid ?></code></p>

  <h2 style="font-size:16px;margin-top:20px;">Fila completa en <code>clientes</code></h2>
  <?php
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
        $stmt->execute([$cid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $row = ['error' => $e->getMessage()]; }
  ?>
  <table>
    <?php foreach ($row as $k => $v): ?>
      <tr>
        <th><?= htmlspecialchars((string)$k) ?></th>
        <td><?= ($v === null || $v === '') ? '<span class="empty">(vacío / null)</span>' : htmlspecialchars((string)$v) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>

  <h2 style="font-size:16px;margin-top:20px;">Qué devuelve <code>me.php</code> ahora</h2>
  <?php
    require_once __DIR__ . '/me.php';
  ?>
<?php endif; ?>

</body></html>
