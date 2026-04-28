<?php
/**
 * POST — Open a new escalation (chargeback / PROFECO / dispute / etc.).
 *
 * Tech Spec EN §7 mandates auto-blocks/alerts for:
 *   chargeback        - Stripe webhook on charge.dispute.created
 *   profeco           - manual: ops/legal opens when notified
 *   dispute           - Stripe webhook on charge.dispute.* (alias)
 *   card_fail         - card_fail counter ≥3 from auto-cobro retries
 *   identity_mismatch - delivery flow when INE name ≠ contract name
 *
 * Body: { kind, severity?, cliente_id?, transaccion_id?, moto_id?,
 *         ref_externa?, titulo, detalle?, asignado_a? }
 *
 * Returns: { ok, escalation_id }
 *
 * Side-effect for `chargeback`/`dispute`/`profeco`: subscripcion is
 * paused (estado='disputada') so auto-cobro never charges a customer
 * who already opened a dispute (per spec: "Customer with open dispute
 * → Block all new operations").
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$d = adminJsonIn();
$kind     = trim((string)($d['kind'] ?? ''));
$titulo   = trim((string)($d['titulo'] ?? ''));
$severity = trim((string)($d['severity'] ?? 'critical'));

if (!in_array($kind, ['chargeback','profeco','dispute','card_fail','identity_mismatch'], true)) {
    adminJsonOut(['error' => 'kind inválido'], 400);
}
if ($titulo === '') adminJsonOut(['error' => 'titulo requerido'], 400);

$pdo = getDB();

// Lazy schema (mirrors alertas/listar.php definition).
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS escalations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(40) NOT NULL,
        severity VARCHAR(20) NOT NULL DEFAULT 'critical',
        cliente_id INT NULL,
        transaccion_id INT NULL,
        moto_id INT NULL,
        ref_externa VARCHAR(120) NULL,
        titulo VARCHAR(200) NOT NULL,
        detalle TEXT NULL,
        estado VARCHAR(20) NOT NULL DEFAULT 'open',
        asignado_a VARCHAR(80) NULL,
        notas MEDIUMTEXT NULL,
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        fmod DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        resolved_at DATETIME NULL,
        INDEX idx_estado_kind (estado, kind),
        INDEX idx_cliente (cliente_id),
        INDEX idx_transaccion (transaccion_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) { error_log('escalations table create: ' . $e->getMessage()); }

$pdo->prepare("INSERT INTO escalations
        (kind, severity, cliente_id, transaccion_id, moto_id, ref_externa,
         titulo, detalle, asignado_a)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute([
        $kind,
        $severity ?: 'critical',
        isset($d['cliente_id'])     ? (int)$d['cliente_id']     : null,
        isset($d['transaccion_id']) ? (int)$d['transaccion_id'] : null,
        isset($d['moto_id'])        ? (int)$d['moto_id']        : null,
        isset($d['ref_externa'])    ? substr((string)$d['ref_externa'], 0, 120) : null,
        substr($titulo, 0, 200),
        $d['detalle'] ?? null,
        isset($d['asignado_a']) ? substr((string)$d['asignado_a'], 0, 80) : _defaultAssignee($kind),
    ]);
$id = (int)$pdo->lastInsertId();

// Side-effect: pause the subscription on hard payment-blockers per
// Tech Spec EN §7 ("Customer with open dispute → Block all new operations").
if (in_array($kind, ['chargeback','dispute','profeco'], true)
    && !empty($d['cliente_id'])) {
    try {
        $pdo->prepare("UPDATE subscripciones_credito
                       SET estado = 'disputada'
                       WHERE cliente_id = ?
                         AND estado IN ('activa','pendiente_activacion')")
            ->execute([(int)$d['cliente_id']]);
    } catch (Throwable $e) { error_log('escalations subscription pause: ' . $e->getMessage()); }
}

adminLog('escalation_open', ['id' => $id, 'kind' => $kind, 'titulo' => $titulo]);

adminJsonOut(['ok' => true, 'escalation_id' => $id]);


function _defaultAssignee(string $kind): string {
    switch ($kind) {
        case 'chargeback':
        case 'dispute':         return 'finance';
        case 'profeco':         return 'legal';
        case 'card_fail':       return 'collections';
        case 'identity_mismatch': return 'ops';
        default:                return 'admin';
    }
}
