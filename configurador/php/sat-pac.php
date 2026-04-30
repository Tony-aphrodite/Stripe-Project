<?php
/**
 * Voltika — SAT/PAC adapter (CFDI 4.0 issuance + cancellation).
 *
 * Tech Spec EN §5.6 mandates "CFDI 4.0 must be issued at the moment of
 * operation. Use a SAT-authorized PAC (Proveedor Autorizado de
 * Certificación). Vehicle invoices have special considerations — DO NOT
 * cancel without legal authorization."
 *
 * This module is a thin, pluggable adapter — the actual PAC call goes to
 * whoever Voltika contracts (Facturama, Konesh, Solución Factible, etc.).
 * The adapter exposes a stable interface so swapping providers later is
 * a one-file change.
 *
 * Configuration (config.php / env):
 *   PAC_PROVIDER      = 'facturama' | 'konesh' | 'noop'  (default: noop)
 *   PAC_API_URL       = base URL of the PAC REST API
 *   PAC_API_USER      = API username
 *   PAC_API_PASSWORD  = API password / token
 *   PAC_RFC_EMISOR    = Voltika RFC (default: MGE230316KA2)
 *   PAC_REGIMEN       = 601 (General Ley Personas Morales)
 *   PAC_LUGAR_EXP     = ZIP code of the issuing place (default: 11510)
 *
 * If PAC_PROVIDER is unset or 'noop', satEmitirCFDI() runs in queue-only
 * mode: writes the request to cfdi_emitidos with estado='pendiente_pac'
 * so the cron picks it up the next time real credentials are configured.
 *
 * Public functions:
 *   satEnsureSchema(PDO)                    - lazy-create cfdi_emitidos
 *   satEmitirCFDI(array $datos): array      - emit a CFDI 4.0
 *   satCancelarCFDI(string $uuid, ...): array (legal-restricted)
 *   satConsultarCFDI(string $uuid): array
 */

require_once __DIR__ . '/config.php';

// ─────────────────────────────────────────────────────────────────────────
// Schema
// ─────────────────────────────────────────────────────────────────────────

function satEnsureSchema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cfdi_emitidos (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            transaccion_id  INT NULL,
            moto_id         INT NULL,
            uuid            VARCHAR(64)  NULL UNIQUE,
            folio           VARCHAR(40)  NULL,
            serie           VARCHAR(20)  NULL,
            rfc_receptor    VARCHAR(20)  NOT NULL,
            nombre_receptor VARCHAR(200) NOT NULL,
            uso_cfdi        VARCHAR(10)  NOT NULL DEFAULT 'G03',
            metodo_pago     VARCHAR(10)  NOT NULL DEFAULT 'PUE',
            forma_pago      VARCHAR(10)  NOT NULL DEFAULT '04',
            subtotal        DECIMAL(12,2) NOT NULL,
            iva             DECIMAL(12,2) NOT NULL,
            total           DECIMAL(12,2) NOT NULL,
            xml_path        VARCHAR(255) NULL,
            pdf_path        VARCHAR(255) NULL,
            estado          VARCHAR(30)  NOT NULL DEFAULT 'pendiente_pac',
              -- pendiente_pac | emitido | cancelado | error
            pac_provider    VARCHAR(40)  NULL,
            pac_response    MEDIUMTEXT   NULL,
            error_msg       TEXT NULL,
            freg            DATETIME DEFAULT CURRENT_TIMESTAMP,
            femitido        DATETIME NULL,
            fcancelado      DATETIME NULL,
            INDEX idx_estado (estado),
            INDEX idx_transaccion (transaccion_id),
            INDEX idx_moto (moto_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('satEnsureSchema: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Public API
// ─────────────────────────────────────────────────────────────────────────

/**
 * Emit a CFDI 4.0 invoice for an order.
 *
 * Required keys in $datos:
 *   transaccion_id   - internal transacciones.id
 *   moto_id          - inventario_motos.id (for vehicle line item)
 *   rfc_receptor     - customer RFC (XAXX010101000 if generic)
 *   nombre_receptor  - customer legal name
 *   subtotal         - amount before IVA
 *   iva              - 16% IVA amount
 *   total            - subtotal + iva
 *   forma_pago       - SAT code: 01=efectivo, 03=transferencia, 04=tarjeta crédito,
 *                      28=tarjeta débito, 99=por definir
 *   metodo_pago      - 'PUE' (one-shot) or 'PPD' (parcialidades / credit)
 *   uso_cfdi         - 'G03' (gastos en general) | 'D08' (others)
 *   descripcion      - line description (default: model + color + VIN)
 *   vehicle_model    - for the line item
 *   vehicle_color    - for the line item
 *   vin              - for the line item
 *
 * Returns: ['ok' => bool, 'uuid' => string|null, 'folio' => string|null,
 *           'estado' => string, 'error' => string|null, 'cfdi_id' => int]
 */
function satEmitirCFDI(array $datos): array {
    $provider = strtolower(getenv('PAC_PROVIDER') ?: (defined('PAC_PROVIDER') ? PAC_PROVIDER : 'noop'));

    $pdo = getDB();
    satEnsureSchema($pdo);

    // Idempotency — never emit twice for the same transaction.
    if (!empty($datos['transaccion_id'])) {
        $stmt = $pdo->prepare("SELECT id, uuid, estado FROM cfdi_emitidos
                               WHERE transaccion_id = ? AND estado IN ('emitido','pendiente_pac')
                               LIMIT 1");
        $stmt->execute([(int)$datos['transaccion_id']]);
        if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return [
                'ok' => true, 'uuid' => $r['uuid'], 'estado' => $r['estado'],
                'cfdi_id' => (int)$r['id'], 'duplicate' => true,
            ];
        }
    }

    // Persist the request first (queue-style) so we always have a record
    // even if the PAC API call fails or no provider is configured.
    $insert = $pdo->prepare("INSERT INTO cfdi_emitidos
            (transaccion_id, moto_id, rfc_receptor, nombre_receptor,
             uso_cfdi, metodo_pago, forma_pago,
             subtotal, iva, total, pac_provider, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente_pac')");
    $insert->execute([
        $datos['transaccion_id']   ?? null,
        $datos['moto_id']          ?? null,
        $datos['rfc_receptor']     ?? 'XAXX010101000',
        $datos['nombre_receptor']  ?? 'PUBLICO EN GENERAL',
        $datos['uso_cfdi']         ?? 'G03',
        $datos['metodo_pago']      ?? 'PUE',
        $datos['forma_pago']       ?? '99',
        floatval($datos['subtotal'] ?? 0),
        floatval($datos['iva']      ?? 0),
        floatval($datos['total']    ?? 0),
        $provider,
    ]);
    $cfdiId = (int)$pdo->lastInsertId();

    // Queue-only mode — no PAC configured yet. Cron will pick this up.
    if ($provider === 'noop' || $provider === '') {
        return [
            'ok' => true, 'uuid' => null, 'folio' => null,
            'estado' => 'pendiente_pac', 'cfdi_id' => $cfdiId,
            'note' => 'PAC_PROVIDER no configurado — CFDI encolado para emisión posterior.',
        ];
    }

    // Dispatch to provider-specific adapter.
    $result = match ($provider) {
        'facturama' => _satFacturamaEmit($datos),
        'konesh'    => _satKoneshEmit($datos),
        default     => ['ok' => false, 'error' => "PAC_PROVIDER '{$provider}' no soportado"],
    };

    // Persist the outcome.
    if ($result['ok']) {
        $pdo->prepare("UPDATE cfdi_emitidos
                       SET uuid = ?, folio = ?, serie = ?, xml_path = ?, pdf_path = ?,
                           pac_response = ?, estado = 'emitido', femitido = NOW()
                       WHERE id = ?")
            ->execute([
                $result['uuid']   ?? null,
                $result['folio']  ?? null,
                $result['serie']  ?? null,
                $result['xml_path'] ?? null,
                $result['pdf_path'] ?? null,
                isset($result['raw']) ? json_encode($result['raw'], JSON_UNESCAPED_UNICODE) : null,
                $cfdiId,
            ]);
    } else {
        $pdo->prepare("UPDATE cfdi_emitidos
                       SET error_msg = ?, estado = 'error'
                       WHERE id = ?")
            ->execute([substr((string)($result['error'] ?? 'unknown'), 0, 1000), $cfdiId]);
    }

    return array_merge(['cfdi_id' => $cfdiId], $result);
}

/**
 * Cancel a CFDI. Per Tech Spec EN §5.6, vehicle invoices have special
 * considerations and cancellation requires legal authorization. This
 * function logs intent but ALWAYS sets estado='cancelacion_solicitada'
 * and refuses to call the PAC unless $forceLegalApproved=true.
 */
function satCancelarCFDI(string $uuid, string $motivo = '02', bool $forceLegalApproved = false): array {
    $pdo = getDB();
    satEnsureSchema($pdo);

    $stmt = $pdo->prepare("SELECT * FROM cfdi_emitidos WHERE uuid = ? LIMIT 1");
    $stmt->execute([$uuid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['ok' => false, 'error' => 'CFDI no encontrado'];
    if ($row['estado'] === 'cancelado') return ['ok' => true, 'duplicate' => true];

    if (!$forceLegalApproved) {
        $pdo->prepare("UPDATE cfdi_emitidos SET estado = 'cancelacion_solicitada',
                       error_msg = CONCAT(IFNULL(error_msg,''), ?, NOW())
                       WHERE id = ?")
            ->execute(["\nCancelación solicitada — pendiente aprobación legal: ", (int)$row['id']]);
        return [
            'ok' => false,
            'error' => 'Cancelación de CFDI vehicular requiere aprobación legal. '
                . 'Marcada como pendiente.',
            'estado' => 'cancelacion_solicitada',
        ];
    }

    $provider = strtolower(getenv('PAC_PROVIDER') ?: (defined('PAC_PROVIDER') ? PAC_PROVIDER : 'noop'));
    $result = match ($provider) {
        'facturama' => _satFacturamaCancel($uuid, $motivo),
        'konesh'    => _satKoneshCancel($uuid, $motivo),
        default     => ['ok' => false, 'error' => "Provider '{$provider}' no soportado para cancelación"],
    };

    if ($result['ok']) {
        $pdo->prepare("UPDATE cfdi_emitidos SET estado = 'cancelado', fcancelado = NOW() WHERE id = ?")
            ->execute([(int)$row['id']]);
    }
    return $result;
}

function satConsultarCFDI(string $uuid): array {
    $pdo = getDB();
    satEnsureSchema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM cfdi_emitidos WHERE uuid = ? LIMIT 1");
    $stmt->execute([$uuid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? ['ok' => true, 'cfdi' => $row] : ['ok' => false, 'error' => 'no encontrado'];
}

// ─────────────────────────────────────────────────────────────────────────
// Provider-specific adapters
// ─────────────────────────────────────────────────────────────────────────

/**
 * Facturama (https://api.facturama.mx/) adapter.
 * Authentication: Basic auth with username/password.
 * Endpoint: POST /3/cfdis with CFDI 4.0 JSON body.
 */
function _satFacturamaEmit(array $datos): array {
    $url   = rtrim(getenv('PAC_API_URL') ?: (defined('PAC_API_URL') ? PAC_API_URL : 'https://api.facturama.mx'), '/');
    $user  = getenv('PAC_API_USER')     ?: (defined('PAC_API_USER')     ? PAC_API_USER     : '');
    $pass  = getenv('PAC_API_PASSWORD') ?: (defined('PAC_API_PASSWORD') ? PAC_API_PASSWORD : '');
    $rfcEm = getenv('PAC_RFC_EMISOR')   ?: (defined('PAC_RFC_EMISOR')   ? PAC_RFC_EMISOR   : 'MGE230316KA2');
    $reg   = getenv('PAC_REGIMEN')      ?: (defined('PAC_REGIMEN')      ? PAC_REGIMEN      : '601');
    $lugar = getenv('PAC_LUGAR_EXP')    ?: (defined('PAC_LUGAR_EXP')    ? PAC_LUGAR_EXP    : '11510');

    if (!$user || !$pass) return ['ok' => false, 'error' => 'PAC_API_USER/PASSWORD no configurados'];

    $body = [
        'NameId'                => '1',
        'Folio'                 => date('YmdHis'),
        'CfdiType'              => 'I',  // Ingreso
        'PaymentForm'           => $datos['forma_pago']  ?? '99',
        'PaymentMethod'         => $datos['metodo_pago'] ?? 'PUE',
        'ExpeditionPlace'       => $lugar,
        'Issuer'                => [
            'FiscalRegime' => $reg,
            'Rfc'          => $rfcEm,
            'Name'         => 'MTECH GEARS',
        ],
        'Receiver' => [
            'Rfc'                  => $datos['rfc_receptor']    ?? 'XAXX010101000',
            'Name'                 => mb_strtoupper($datos['nombre_receptor'] ?? 'PUBLICO EN GENERAL'),
            'CfdiUse'              => $datos['uso_cfdi'] ?? 'G03',
            'FiscalRegime'         => $datos['regimen_receptor'] ?? '616',  // Sin obligaciones fiscales
            'TaxZipCode'           => $datos['zip_receptor'] ?? $lugar,
        ],
        'Items' => [[
            'ProductCode'  => $datos['clave_prod_serv'] ?? '25101503', // Motocicletas eléctricas
            'IdentificationNumber' => $datos['vin'] ?? '',
            'Description'  => $datos['descripcion']
                ?? trim('Motocicleta eléctrica Voltika ' . ($datos['vehicle_model'] ?? '')
                    . ' ' . ($datos['vehicle_color'] ?? '')
                    . ($datos['vin'] ? ' VIN: ' . $datos['vin'] : '')),
            'Unit'         => 'Pieza',
            'UnitCode'     => 'H87',
            'UnitPrice'    => floatval($datos['subtotal'] ?? 0),
            'Quantity'     => 1,
            'Subtotal'     => floatval($datos['subtotal'] ?? 0),
            'Total'        => floatval($datos['total']    ?? 0),
            'Taxes'        => [[
                'Total'    => floatval($datos['iva'] ?? 0),
                'Name'     => 'IVA',
                'Base'     => floatval($datos['subtotal'] ?? 0),
                'Rate'     => 0.16,
                'IsRetention' => false,
            ]],
        ]],
    ];

    $ch = curl_init($url . '/3/cfdis');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $user . ':' . $pass,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 45,
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) return ['ok' => false, 'error' => 'curl: ' . $curlErr];
    $resp = json_decode($raw, true) ?: [];

    if ($httpCode < 200 || $httpCode >= 300) {
        $msg = $resp['Message'] ?? ('HTTP ' . $httpCode);
        return ['ok' => false, 'error' => $msg, 'raw' => $resp];
    }

    return [
        'ok'      => true,
        'uuid'    => $resp['Complement']['TaxStamp']['Uuid'] ?? ($resp['Id'] ?? null),
        'folio'   => $resp['Folio']  ?? null,
        'serie'   => $resp['Serie']  ?? null,
        'xml_path'=> $resp['Id'] ? ('cfdi/' . $resp['Id'] . '.xml') : null,
        'pdf_path'=> $resp['Id'] ? ('cfdi/' . $resp['Id'] . '.pdf') : null,
        'raw'     => $resp,
    ];
}

function _satFacturamaCancel(string $uuid, string $motivo): array {
    $url   = rtrim(getenv('PAC_API_URL') ?: (defined('PAC_API_URL') ? PAC_API_URL : 'https://api.facturama.mx'), '/');
    $user  = getenv('PAC_API_USER')     ?: (defined('PAC_API_USER')     ? PAC_API_USER     : '');
    $pass  = getenv('PAC_API_PASSWORD') ?: (defined('PAC_API_PASSWORD') ? PAC_API_PASSWORD : '');

    if (!$user || !$pass) return ['ok' => false, 'error' => 'PAC_API_USER/PASSWORD no configurados'];

    $ch = curl_init($url . '/3/cfdis/' . urlencode($uuid) . '?motive=' . urlencode($motivo));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $user . ':' . $pass,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'ok'    => $httpCode >= 200 && $httpCode < 300,
        'raw'   => json_decode($raw, true),
        'error' => $httpCode >= 400 ? ('HTTP ' . $httpCode . ': ' . $raw) : null,
    ];
}

function _satKoneshEmit(array $datos): array {
    return ['ok' => false, 'error' => 'Konesh adapter no implementado todavía. Usar PAC_PROVIDER=facturama o configurar manualmente.'];
}

function _satKoneshCancel(string $uuid, string $motivo): array {
    return ['ok' => false, 'error' => 'Konesh adapter no implementado todavía.'];
}
