<?php
/**
 * Voltika — REPUVE adapter (Public Vehicle Registry notice).
 *
 * Tech Spec EN §5.7 + Cláusula Décima Novena of v5 contract:
 *   "Voltika is required to file purchase/sale notice within next
 *    business day after invoicing. Customer can consult their
 *    registration at https://www.repuve.gob.mx
 *    Article 23 of LRPV (Public Vehicle Registry Law)."
 *
 * REPUVE accepts purchase/sale notices via the SSP (Secretaría de
 * Seguridad Pública) integration channel. Operators registered with
 * REPUVE receive credentials to file via web service or batch.
 *
 * Configuration (config.php / env):
 *   REPUVE_API_URL       = web service endpoint (production or sandbox)
 *   REPUVE_API_KEY       = operator key issued by SSP
 *   REPUVE_OPERATOR_RFC  = MGE230316KA2 (Voltika)
 *
 * If credentials are missing, this module operates in queue mode:
 * notices are written to repuve_avisos with estado='pendiente'. The
 * cron (`admin/cron/cfdi-repuve.php`) retries on the next run.
 *
 * Public functions:
 *   repuveEnsureSchema(PDO)
 *   repuveFilePurchaseNotice(array): array
 *   repuveConsultarVehiculo(string $vin): array
 */

require_once __DIR__ . '/config.php';

function repuveEnsureSchema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS repuve_avisos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaccion_id INT NULL,
            moto_id        INT NULL,
            cfdi_uuid      VARCHAR(64) NULL,
            vin            VARCHAR(40) NOT NULL,
            tipo_aviso     VARCHAR(40) NOT NULL DEFAULT 'compraventa',
            rfc_adquirente VARCHAR(20) NOT NULL,
            nombre_adquirente VARCHAR(200) NOT NULL,
            domicilio      VARCHAR(255) NULL,
            fecha_operacion DATE NOT NULL,
            folio_repuve   VARCHAR(80) NULL,
            estado         VARCHAR(20) NOT NULL DEFAULT 'pendiente',
              -- pendiente | enviado | aceptado | rechazado | error
            response_raw   MEDIUMTEXT NULL,
            error_msg      TEXT NULL,
            freg           DATETIME DEFAULT CURRENT_TIMESTAMP,
            fenviado       DATETIME NULL,
            INDEX idx_estado (estado),
            INDEX idx_vin (vin),
            INDEX idx_transaccion (transaccion_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('repuveEnsureSchema: ' . $e->getMessage());
    }
}

/**
 * File a purchase/sale notice with REPUVE.
 *
 * Required keys in $datos:
 *   transaccion_id, moto_id, cfdi_uuid, vin, rfc_adquirente,
 *   nombre_adquirente, domicilio, fecha_operacion (YYYY-MM-DD)
 *
 * Returns: ['ok' => bool, 'folio_repuve' => string|null, 'estado' => string,
 *           'aviso_id' => int, 'error' => string|null]
 */
function repuveFilePurchaseNotice(array $datos): array {
    $pdo = getDB();
    repuveEnsureSchema($pdo);

    if (empty($datos['vin']) || empty($datos['rfc_adquirente'])) {
        return ['ok' => false, 'error' => 'vin y rfc_adquirente son requeridos'];
    }

    // Idempotency — never file twice for the same VIN+transaction.
    if (!empty($datos['transaccion_id'])) {
        $stmt = $pdo->prepare("SELECT id, folio_repuve, estado FROM repuve_avisos
                               WHERE transaccion_id = ? AND vin = ?
                                 AND estado IN ('pendiente','enviado','aceptado')
                               LIMIT 1");
        $stmt->execute([(int)$datos['transaccion_id'], $datos['vin']]);
        if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return [
                'ok' => true, 'folio_repuve' => $r['folio_repuve'],
                'estado' => $r['estado'], 'aviso_id' => (int)$r['id'], 'duplicate' => true,
            ];
        }
    }

    // Persist queue row first.
    $insert = $pdo->prepare("INSERT INTO repuve_avisos
            (transaccion_id, moto_id, cfdi_uuid, vin, tipo_aviso,
             rfc_adquirente, nombre_adquirente, domicilio, fecha_operacion, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente')");
    $insert->execute([
        $datos['transaccion_id']    ?? null,
        $datos['moto_id']           ?? null,
        $datos['cfdi_uuid']         ?? null,
        $datos['vin'],
        $datos['tipo_aviso']        ?? 'compraventa',
        $datos['rfc_adquirente'],
        mb_strtoupper($datos['nombre_adquirente'] ?? ''),
        $datos['domicilio']         ?? null,
        $datos['fecha_operacion']   ?? date('Y-m-d'),
    ]);
    $avisoId = (int)$pdo->lastInsertId();

    $apiUrl = getenv('REPUVE_API_URL') ?: (defined('REPUVE_API_URL') ? REPUVE_API_URL : '');
    $apiKey = getenv('REPUVE_API_KEY') ?: (defined('REPUVE_API_KEY') ? REPUVE_API_KEY : '');

    if (!$apiUrl || !$apiKey) {
        return [
            'ok' => true, 'folio_repuve' => null, 'estado' => 'pendiente',
            'aviso_id' => $avisoId,
            'note' => 'REPUVE_API_URL/KEY no configurados — aviso encolado.',
        ];
    }

    // Real call. Payload format per SSP REPUVE web service spec.
    // The real schema is XML SOAP-like, but most operators expose a JSON
    // bridge via their integrator. We post a generic JSON envelope and
    // let the operator transform to whatever format SSP requires.
    $rfcOperador = getenv('REPUVE_OPERATOR_RFC') ?: (defined('REPUVE_OPERATOR_RFC') ? REPUVE_OPERATOR_RFC : 'MGE230316KA2');
    $body = [
        'tipo_aviso'    => $datos['tipo_aviso'] ?? 'compraventa',
        'operador_rfc'  => $rfcOperador,
        'vehiculo' => [
            'vin'           => $datos['vin'],
            'marca'         => 'VOLTIKA',
            'submarca'      => 'TROMOX',
            'modelo'        => $datos['vehicle_model'] ?? '',
            'tipo'          => 'Motocicleta eléctrica',
            'anio'          => (int)($datos['vehicle_year'] ?? date('Y')),
        ],
        'adquirente' => [
            'rfc'       => $datos['rfc_adquirente'],
            'nombre'    => $datos['nombre_adquirente'] ?? '',
            'domicilio' => $datos['domicilio'] ?? '',
        ],
        'operacion' => [
            'fecha'      => $datos['fecha_operacion'] ?? date('Y-m-d'),
            'cfdi_uuid'  => $datos['cfdi_uuid'] ?? null,
        ],
    ];

    $ch = curl_init(rtrim($apiUrl, '/') . '/avisos');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 60,
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        $pdo->prepare("UPDATE repuve_avisos SET estado = 'error', error_msg = ? WHERE id = ?")
            ->execute([substr('curl: ' . $curlErr, 0, 1000), $avisoId]);
        return ['ok' => false, 'aviso_id' => $avisoId, 'error' => 'curl: ' . $curlErr];
    }

    $resp = json_decode($raw, true) ?: [];
    if ($httpCode < 200 || $httpCode >= 300) {
        $msg = $resp['message'] ?? ('HTTP ' . $httpCode);
        $pdo->prepare("UPDATE repuve_avisos SET estado = 'error', error_msg = ?, response_raw = ? WHERE id = ?")
            ->execute([substr($msg, 0, 1000), substr($raw, 0, 5000), $avisoId]);
        return ['ok' => false, 'aviso_id' => $avisoId, 'error' => $msg];
    }

    $folio = $resp['folio'] ?? ($resp['folio_repuve'] ?? null);
    $estado = $resp['estado'] ?? 'enviado';

    $pdo->prepare("UPDATE repuve_avisos
                   SET folio_repuve = ?, estado = ?, response_raw = ?, fenviado = NOW()
                   WHERE id = ?")
        ->execute([$folio, $estado, substr($raw, 0, 5000), $avisoId]);

    return [
        'ok' => true, 'folio_repuve' => $folio, 'estado' => $estado,
        'aviso_id' => $avisoId, 'raw' => $resp,
    ];
}

function repuveConsultarVehiculo(string $vin): array {
    $pdo = getDB();
    repuveEnsureSchema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM repuve_avisos
                           WHERE vin = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$vin]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? ['ok' => true, 'aviso' => $row] : ['ok' => false, 'error' => 'sin avisos para este VIN'];
}
