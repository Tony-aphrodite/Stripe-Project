<?php
/**
 * Voltika Admin — Cambiar color de la orden de un cliente.
 *
 * Customer brief 2026-05-29: when a customer's color preference changes
 * (e.g. silver M05 → black M05), several tables hold the old value:
 *   - transacciones.color
 *   - subscripciones_credito.color
 *   - clientes (none)
 *   - inventario_motos.color (per VIN — usually unchanged, the physical
 *     moto IS what it is; the assignment may swap to a different VIN)
 *
 * This tool updates the customer-facing color in transacciones + subscripciones
 * so the contract / portal / pagaré show the right value. The physical moto
 * assignment is a SEPARATE concern (see CEDIS panel to swap VIN if needed).
 *
 * Workflow:
 *   1. Search customer (by pedido / email / phone)
 *   2. Preview current values
 *   3. Pick new color
 *   4. Confirm → updates DB + audit log
 *   5. Optionally regenerate contract with new color
 *
 * Auth: admin only.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'search');
$search = trim((string)($_POST['q'] ?? $_GET['q'] ?? ''));
$txId   = (int)($_POST['tx_id'] ?? $_GET['tx_id'] ?? 0);
$newColor = trim((string)($_POST['new_color'] ?? ''));

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Cambiar color</title>';
echo '<style>
body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:880px;margin:0 auto;line-height:1.55;}
h1{font-size:20px;margin:0 0 4px;}
h2{font-size:14px;color:#475569;margin:18px 0 8px;border-bottom:1px solid #cbd5e1;padding-bottom:4px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin-bottom:12px;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th{background:#f1f5f9;padding:7px 9px;text-align:left;font-size:11px;}
td{padding:7px 9px;border-top:1px solid #f1f5f9;}
.lbl{font-weight:600;background:#f8fafc;width:200px;}
.banner{padding:11px 14px;border-radius:8px;font-size:13.5px;margin-bottom:12px;font-weight:600;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.banner-warn{background:#fef3c7;border:1px solid #fcd34d;color:#92400e;}
.banner-err{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;}
input,select{padding:7px 10px;border:1px solid #cbd5e1;border-radius:5px;font-size:13px;}
.btn{padding:8px 16px;background:#039fe1;color:#fff;border:0;border-radius:5px;cursor:pointer;font-weight:600;font-size:13px;text-decoration:none;display:inline-block;}
.btn.warn{background:#d97706;}
.btn.danger{background:#dc2626;}
.btn.ghost{background:#fff;color:#0c2340;border:1px solid #cbd5e1;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11.5px;}
.old{text-decoration:line-through;color:#94a3b8;}
.new{color:#16a34a;font-weight:700;}
</style></head><body>';
echo '<h1>🎨 Cambiar color de la orden</h1>';
echo '<p style="color:#64748b;font-size:12.5px;margin-top:0;">Actualiza transacciones + subscripciones_credito. NO toca inventario_motos (eso requiere reasignar VIN por CEDIS).</p>';

// ── ACTION: APPLY ──
if ($action === 'apply' && $txId > 0 && $newColor !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $st = $pdo->prepare("SELECT id, pedido, nombre, email, telefono, modelo, color FROM transacciones WHERE id = ? LIMIT 1");
        $st->execute([$txId]);
        $tx = $st->fetch(PDO::FETCH_ASSOC);
        if (!$tx) {
            echo '<div class="banner banner-err">Transacción ' . $txId . ' no encontrada.</div></body></html>'; exit;
        }
        $oldColor = (string)($tx['color'] ?? '');
        if (strtolower($oldColor) === strtolower($newColor)) {
            echo '<div class="banner banner-warn">El color ya es <code>' . htmlspecialchars($newColor) . '</code>. Nada que cambiar.</div>';
        } else {
            // 1) transacciones
            $pdo->prepare("UPDATE transacciones SET color = ? WHERE id = ?")->execute([$newColor, $txId]);
            $touchedTx = 1;
            // 2) subscripciones_credito (matched by phone/email + modelo)
            $upd = $pdo->prepare("UPDATE subscripciones_credito SET color = ?
                WHERE (telefono = ? OR email = ?) AND modelo = ?");
            $upd->execute([$newColor, (string)$tx['telefono'], (string)$tx['email'], (string)$tx['modelo']]);
            $touchedSub = $upd->rowCount();
            // 3) Audit log
            if (function_exists('adminLog')) {
                adminLog('cambio_color_orden', [
                    'tx_id'      => $txId,
                    'pedido'     => $tx['pedido'],
                    'cliente'    => $tx['nombre'],
                    'modelo'     => $tx['modelo'],
                    'color_old'  => $oldColor,
                    'color_new'  => $newColor,
                    'tx_rows'    => $touchedTx,
                    'sub_rows'   => $touchedSub,
                    'admin_user' => $_SESSION['admin_user_id'] ?? null,
                ]);
            }
            echo '<div class="banner banner-ok">✓ Color cambiado de <span class="old">' . htmlspecialchars($oldColor ?: '(vacío)') . '</span> → <span class="new">' . htmlspecialchars($newColor) . '</span></div>';
            echo '<div class="card"><strong>Tablas actualizadas:</strong><br>'
               . '· <code>transacciones</code>: ' . $touchedTx . ' fila<br>'
               . '· <code>subscripciones_credito</code>: ' . $touchedSub . ' fila(s)'
               . '</div>';
            echo '<div class="banner banner-warn">⚠ <strong>Para que el cambio aparezca en el contrato y PAGARÉ</strong>, regenera el contrato:<br>'
               . '<a class="btn" href="/admin/php/inventario/regenerar-contrato-credito-once.php?action=reset_and_regen&tx_id=' . $txId . '" target="_blank" style="margin-top:6px;">🔁 Regenerar contrato ahora</a></div>';
        }
        echo '<p><a class="btn ghost" href="?">← Buscar otra orden</a></p>';
        echo '</body></html>'; exit;
    } catch (Throwable $e) {
        echo '<div class="banner banner-err">Error: ' . htmlspecialchars($e->getMessage()) . '</div></body></html>'; exit;
    }
}

// ── SEARCH FORM ──
echo '<form method="get" class="card">';
echo '<label>Buscar orden:</label> '
   . '<input type="text" name="q" value="' . htmlspecialchars($search) . '" placeholder="pedido, email o teléfono" style="width:300px;"> '
   . '<button class="btn">Buscar</button>';
echo '</form>';

if ($search === '') { echo '</body></html>'; exit; }

// ── PREVIEW ──
$st = $pdo->prepare("SELECT id, pedido, nombre, email, telefono, modelo, color, tpago, total, freg
    FROM transacciones
    WHERE pedido = ? OR email = ? OR telefono = ?
    ORDER BY id DESC LIMIT 10");
$st->execute([$search, $search, $search]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo '<div class="banner banner-warn">No se encontraron órdenes para "' . htmlspecialchars($search) . '".</div></body></html>'; exit;
}

foreach ($rows as $tx) {
    echo '<div class="card">';
    echo '<h2>Pedido ' . htmlspecialchars((string)$tx['pedido']) . ' · TX#' . (int)$tx['id'] . '</h2>';
    echo '<table>';
    echo '<tr><td class="lbl">Cliente</td><td>' . htmlspecialchars((string)$tx['nombre']) . '</td></tr>';
    echo '<tr><td class="lbl">Email · Teléfono</td><td>' . htmlspecialchars((string)$tx['email']) . ' · ' . htmlspecialchars((string)$tx['telefono']) . '</td></tr>';
    echo '<tr><td class="lbl">Modelo</td><td>' . htmlspecialchars((string)$tx['modelo']) . '</td></tr>';
    echo '<tr><td class="lbl" style="background:#fef3c7;">Color actual</td><td style="background:#fef3c7;"><strong>' . htmlspecialchars((string)($tx['color'] ?: '(sin color)')) . '</strong></td></tr>';
    echo '<tr><td class="lbl">tpago · total</td><td>' . htmlspecialchars((string)$tx['tpago']) . ' · $' . number_format((float)$tx['total'], 2) . '</td></tr>';
    echo '<tr><td class="lbl">Fecha</td><td>' . htmlspecialchars((string)$tx['freg']) . '</td></tr>';
    echo '</table>';
    echo '<form method="post" style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
    echo '<input type="hidden" name="action" value="apply">';
    echo '<input type="hidden" name="tx_id" value="' . (int)$tx['id'] . '">';
    echo '<label>Nuevo color:</label> ';
    $opts = ['negro','plata','gris','blanco','rojo','azul','verde','amarillo'];
    echo '<select name="new_color" required>';
    echo '<option value="">— elige —</option>';
    foreach ($opts as $o) {
        $sel = (strtolower($o) === strtolower((string)$tx['color'])) ? '' : '';
        echo '<option value="' . $o . '"' . $sel . '>' . $o . '</option>';
    }
    echo '</select>';
    echo ' <button class="btn warn" type="submit" onclick="return confirm(\'¿Cambiar color de ' . htmlspecialchars((string)$tx['color']) . ' al seleccionado? Esto actualiza transacciones + subscripciones.\')">⚙ Cambiar color</button>';
    echo '</form>';
    echo '</div>';
}

echo '</body></html>';
