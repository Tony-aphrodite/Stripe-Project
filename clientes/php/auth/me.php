<?php
require_once __DIR__ . '/../bootstrap.php';
$cid = $_SESSION['portal_cliente_id'] ?? null;
if (!$cid) portalJsonOut(['authenticated' => false]);

// A "real" name is one we want to show to the user. Anything generic like
// "Cliente" / "Cliente Voltika" / "Sin nombre" is treated as missing and
// triggers a lookup in subscripciones_credito → transacciones → inventario.
function meIsPlaceholderName(?string $n): bool {
    $n = trim((string)$n);
    if ($n === '') return true;
    $low = mb_strtolower($n);
    foreach (['cliente', 'cliente voltika', 'sin nombre', 'n/a', 'none'] as $bad) {
        if ($low === $bad) return true;
    }
    return false;
}

try {
    $pdo = getDB();

    // The clientes schema varies between deployments — some have separate
    // apellido_paterno / apellido_materno columns, others store the full name
    // inside `nombre`. Probe before SELECT so a missing column never tanks
    // the whole row (which would log the user out).
    $cols = $pdo->query("SHOW COLUMNS FROM clientes")->fetchAll(PDO::FETCH_COLUMN);
    $colSet = array_flip($cols);
    $select = ['id', 'nombre', 'email', 'telefono'];
    if (isset($colSet['apellido_paterno'])) $select[] = 'apellido_paterno';
    if (isset($colSet['apellido_materno'])) $select[] = 'apellido_materno';

    $stmt = $pdo->prepare("SELECT " . implode(',', $select) . " FROM clientes WHERE id = ?");
    $stmt->execute([$cid]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($c) {
        // If the canonical clientes.nombre is missing or a placeholder, look
        // in related tables for the real name the user typed during checkout.
        if (meIsPlaceholderName($c['nombre'] ?? null)) {
            $real = null;
            $tel = $c['telefono'] ?? null;
            $em  = $c['email']    ?? null;

            // 1) subscripciones_credito (credit customers — most common)
            try {
                $q = $pdo->prepare("SELECT nombre FROM subscripciones_credito
                                     WHERE (cliente_id = ?
                                         OR (? <> '' AND email = ?)
                                         OR (? <> '' AND telefono = ?))
                                       AND nombre IS NOT NULL AND TRIM(nombre) <> ''
                                  ORDER BY id DESC LIMIT 1");
                $q->execute([$cid, $em ?: '', $em ?: '', $tel ?: '', $tel ?: '']);
                $cand = trim((string)($q->fetchColumn() ?: ''));
                if ($cand && !meIsPlaceholderName($cand)) $real = $cand;
            } catch (Throwable $e) {}

            // 2) transacciones (contado / msi / oxxo / spei)
            if (!$real && ($em || $tel)) {
                try {
                    $q = $pdo->prepare("SELECT nombre FROM transacciones
                                         WHERE ((? <> '' AND email = ?) OR (? <> '' AND telefono = ?))
                                           AND nombre IS NOT NULL AND TRIM(nombre) <> ''
                                      ORDER BY id DESC LIMIT 1");
                    $q->execute([$em ?: '', $em ?: '', $tel ?: '', $tel ?: '']);
                    $cand = trim((string)($q->fetchColumn() ?: ''));
                    if ($cand && !meIsPlaceholderName($cand)) $real = $cand;
                } catch (Throwable $e) {}
            }

            // 3) inventario_motos (entrega data)
            if (!$real && ($em || $tel)) {
                try {
                    $q = $pdo->prepare("SELECT cliente_nombre FROM inventario_motos
                                         WHERE ((? <> '' AND cliente_email = ?) OR (? <> '' AND cliente_telefono = ?))
                                           AND cliente_nombre IS NOT NULL AND TRIM(cliente_nombre) <> ''
                                      ORDER BY id DESC LIMIT 1");
                    $q->execute([$em ?: '', $em ?: '', $tel ?: '', $tel ?: '']);
                    $cand = trim((string)($q->fetchColumn() ?: ''));
                    if ($cand && !meIsPlaceholderName($cand)) $real = $cand;
                } catch (Throwable $e) {}
            }

            // Persist back to clientes so future lookups skip the fallback chain.
            if ($real) {
                $c['nombre'] = $real;
                try {
                    $pdo->prepare("UPDATE clientes SET nombre = ? WHERE id = ?")
                       ->execute([$real, $cid]);
                } catch (Throwable $e) {}
            }
        }

        // Compose the display name. If the deployment uses split fields, join
        // them. Otherwise the full name is already in `nombre` — use as-is.
        $parts = array_filter([
            trim((string)($c['nombre'] ?? '')),
            trim((string)($c['apellido_paterno'] ?? '')),
            trim((string)($c['apellido_materno'] ?? '')),
        ], 'strlen');
        $c['nombre_completo'] = $parts ? implode(' ', $parts) : '';
    }
} catch (Throwable $e) {
    error_log('me.php: ' . $e->getMessage());
    $c = null;
}

portalJsonOut(['authenticated' => true, 'cliente' => $c]);
