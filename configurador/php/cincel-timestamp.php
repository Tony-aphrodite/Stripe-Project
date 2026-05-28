<?php
/**
 * Voltika — Cincel NOM-151 Timestamp Integration (Round 71, 2026-05-23).
 *
 * Customer brief (Óscar): "We only need the timestamp from Cincel" —
 * NOM-151 cryptographic timestamps applied to documents we already
 * sign locally with autograph. This module is the single source of
 * truth for that integration:
 *
 *   • cincelGetJWT()                  → cached JWT for authenticated calls
 *   • cincelTimestampExists($hash)    → public GET endpoint, no auth/credits
 *   • cincelCreateTimestamp($hash)    → authenticated POST, consumes 1 credit
 *   • cincelGetOrCreateTimestamp($pdfPath) → end-to-end helper
 *   • cincelEnsureSchema($pdo)        → idempotent DB columns for storage
 *
 * Flow per Cincel official docs (GUIA_USO_API_CINCEL.pdf):
 *   1. POST /v3/timestamps with Bearer JWT + body {"hash":"<sha256>"}
 *      → creates the NOM-151 timestamp.
 *   2. GET /v3/timestamps/{hash}   (no auth required)
 *      → returns {"bitcoin":"<url>","timestamp":"<url>","nom151":"<url>"}
 *      with downloadable certificate URLs.
 *
 * NOTE: we use idempotency-by-hash. If a document hash already has a
 * timestamp at Cincel (from a prior call or another tenant), we don't
 * re-create it — we just fetch the existing certificates. This avoids
 * double-charging the customer's c.Doc credit balance.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ─────────────────────────────────────────────────────────────────────────
// JWT acquisition + caching
// ─────────────────────────────────────────────────────────────────────────

/**
 * Returns a valid Cincel JWT, refreshing from /v3/tokens/jwt when the
 * cached one is missing or older than CINCEL_JWT_TTL seconds.
 *
 * The cache lives in /tmp (or system temp) as a tiny JSON file. JWTs
 * issued by Cincel typically last several hours; we re-auth every
 * 4 hours to stay well within the lifetime.
 *
 * Returns null on auth failure. Callers must handle null.
 */
function cincelGetJWT(bool $forceRefresh = false): ?string {
    static $memo = null;
    if (!$forceRefresh && $memo !== null) return $memo;

    $cacheFile = sys_get_temp_dir() . '/voltika-cincel-jwt.json';
    $ttlSeconds = defined('CINCEL_JWT_TTL') ? (int)CINCEL_JWT_TTL : (4 * 3600);

    if (!$forceRefresh && is_file($cacheFile)) {
        $cached = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['jwt']) && !empty($cached['fetched_at'])
            && (time() - (int)$cached['fetched_at']) < $ttlSeconds) {
            $memo = (string)$cached['jwt'];
            return $memo;
        }
    }

    $apiUrl  = defined('CINCEL_API_URL')  ? rtrim(CINCEL_API_URL, '/')
             : 'https://api.cincel.digital/v3';
    $email   = defined('CINCEL_EMAIL')    ? CINCEL_EMAIL    : '';
    $passwd  = defined('CINCEL_PASSWORD') ? CINCEL_PASSWORD : '';
    if ($email === '' || $passwd === '') {
        error_log('cincelGetJWT: CINCEL_EMAIL/PASSWORD not configured');
        return null;
    }

    // Strip /v3 suffix if present so we can compose /v3/tokens/jwt cleanly.
    $rootHost = preg_replace('#/v\d+$#', '', $apiUrl) ?: 'https://api.cincel.digital';
    $url = $rootHost . '/v3/tokens/jwt';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERPWD        => $email . ':' . $passwd,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        error_log("cincelGetJWT: HTTP $code ($err): " . substr((string)$raw, 0, 400));
        return null;
    }

    // Parse JWT from response — Cincel may return a bare string or wrapped JSON.
    $jwt = null;
    $parsed = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($parsed)) {
        foreach (['token','jwt','access_token','accessToken'] as $k) {
            if (!empty($parsed[$k]) && is_string($parsed[$k])) { $jwt = $parsed[$k]; break; }
        }
    }
    if (!$jwt && is_string($raw)) {
        $trim = trim($raw, " \t\n\r\0\x0B\"");
        if (substr_count($trim, '.') === 2
            && preg_match('#^[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$#', $trim)) {
            $jwt = $trim;
        }
    }
    if (!$jwt) {
        error_log('cincelGetJWT: no JWT field in response: ' . substr((string)$raw, 0, 400));
        return null;
    }

    // Cache + memoize.
    @file_put_contents($cacheFile, json_encode([
        'jwt'        => $jwt,
        'fetched_at' => time(),
        'expires_in_s' => $ttlSeconds,
    ]));
    @chmod($cacheFile, 0640);
    $memo = $jwt;
    return $jwt;
}

// ─────────────────────────────────────────────────────────────────────────
// Timestamp query / creation
// ─────────────────────────────────────────────────────────────────────────

/**
 * Returns the existing NOM-151 timestamp for a given SHA-256 hash, if any.
 * Public endpoint — no auth, no credits.
 *
 * Returns null when no timestamp exists for the hash (HTTP 404), OR an
 * associative array with: { 'bitcoin' => filename, 'timestamp' => filename,
 * 'nom151' => filename } when a timestamp exists.
 */
function cincelTimestampExists(string $hash): ?array {
    $hash = strtolower(trim($hash));
    if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
        error_log('cincelTimestampExists: invalid sha256: ' . $hash);
        return null;
    }
    $apiUrl  = defined('CINCEL_API_URL') ? rtrim(CINCEL_API_URL, '/') : 'https://api.cincel.digital/v3';
    $rootHost = preg_replace('#/v\d+$#', '', $apiUrl) ?: 'https://api.cincel.digital';
    $url = $rootHost . '/v3/timestamps/' . $hash;

    // Cincel changed this endpoint to require JWT auth (was public before).
    // Without the Bearer token Cincel returns 404; with it, 300 + JSON body.
    $jwt = cincelGetJWT();
    $headers = ['Accept: application/json'];
    if ($jwt) $headers[] = 'Authorization: Bearer ' . $jwt;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 404) return null;
    if ($code < 200 || $code >= 400) {
        error_log("cincelTimestampExists: HTTP $code for hash $hash: " . substr((string)$raw, 0, 200));
        return null;
    }
    $parsed = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($parsed)) return null;
    return $parsed;
}

/**
 * Creates a NOM-151 timestamp for the given hash. Authenticated POST,
 * consumes 1 c.Doc credit at Cincel.
 *
 * Returns [ 'ok'=>true, 'timestamp'=>{bitcoin,timestamp,nom151}, 'created'=>true ]
 * on success, or [ 'ok'=>false, 'error'=>'...', 'http'=>code, 'body'=>... ] on failure.
 */
function cincelCreateTimestamp(string $hash): array {
    $hash = strtolower(trim($hash));
    if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
        return ['ok' => false, 'error' => 'sha256 inválido', 'hash' => $hash];
    }
    $jwt = cincelGetJWT();
    if (!$jwt) {
        return ['ok' => false, 'error' => 'No se pudo obtener JWT de Cincel (verifica CINCEL_EMAIL / CINCEL_PASSWORD).'];
    }

    $apiUrl  = defined('CINCEL_API_URL') ? rtrim(CINCEL_API_URL, '/') : 'https://api.cincel.digital/v3';
    $rootHost = preg_replace('#/v\d+$#', '', $apiUrl) ?: 'https://api.cincel.digital';
    $url = $rootHost . '/v3/timestamps';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $jwt,
        ],
        CURLOPT_POSTFIELDS     => json_encode(['hash' => $hash]),
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    // Refresh JWT once if 401 (token expired) and retry.
    if ($code === 401) {
        $jwt = cincelGetJWT(true);
        if ($jwt) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $jwt,
                ],
                CURLOPT_POSTFIELDS     => json_encode(['hash' => $hash]),
            ]);
            $raw  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
        }
    }

    $parsed = is_string($raw) ? json_decode($raw, true) : null;

    if ($code >= 200 && $code < 300) {
        return [
            'ok'         => true,
            'created'    => true,
            'hash'       => $hash,
            'timestamp'  => is_array($parsed) ? $parsed : ['raw' => $raw],
            'http'       => $code,
        ];
    }

    // Some APIs return 409 / 422 when the timestamp already exists for that
    // hash — try to retrieve via GET instead of failing.
    if ($code === 409 || $code === 422) {
        $existing = cincelTimestampExists($hash);
        if ($existing) {
            return [
                'ok'         => true,
                'created'    => false,
                'already'    => true,
                'hash'       => $hash,
                'timestamp'  => $existing,
            ];
        }
    }

    return [
        'ok'    => false,
        'error' => 'Cincel rechazó la creación del timestamp.',
        'hash'  => $hash,
        'http'  => $code,
        'body'  => $parsed ?: substr((string)$raw, 0, 600),
        'curl_err' => $err ?: null,
    ];
}

/**
 * End-to-end: given a PDF path, return its NOM-151 timestamp,
 * creating one if it doesn't exist yet.
 *
 * Returns: [
 *   'ok' => true,
 *   'hash' => '...',
 *   'timestamp' => { bitcoin: '...', timestamp: '...', nom151: '...' },
 *   'already' => bool,   // true if Cincel had it already (no credit consumed)
 *   'created' => bool,   // true if we just created it (1 credit consumed)
 * ]
 *
 * Or on failure: [ 'ok' => false, 'error' => '...', ... ]
 */
function cincelGetOrCreateTimestamp(string $pdfPath): array {
    if (!is_file($pdfPath)) {
        return ['ok' => false, 'error' => 'PDF no encontrado: ' . $pdfPath];
    }
    $hash = hash_file('sha256', $pdfPath);
    if (!$hash) {
        return ['ok' => false, 'error' => 'No se pudo hashear el PDF'];
    }

    // Idempotent path: see if Cincel already has a timestamp for this hash.
    $existing = cincelTimestampExists($hash);
    if ($existing) {
        return [
            'ok'         => true,
            'hash'       => $hash,
            'timestamp'  => $existing,
            'already'    => true,
            'created'    => false,
        ];
    }

    // Need to create — POST to Cincel.
    $result = cincelCreateTimestamp($hash);
    if (!$result['ok']) return $result;
    return $result + ['hash' => $hash];
}

// ─────────────────────────────────────────────────────────────────────────
// DB persistence
// ─────────────────────────────────────────────────────────────────────────

/**
 * Idempotently ensure the cincel_timestamps audit table + the optional
 * transacciones.cincel_timestamp_hash column exist.
 */
function cincelEnsureSchema(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cincel_timestamps (
            id                 INT AUTO_INCREMENT PRIMARY KEY,
            transaccion_id     INT NULL,
            pdf_path           VARCHAR(512) NULL,
            pdf_hash_sha256    CHAR(64) NOT NULL,
            bitcoin_file       VARCHAR(255) NULL,
            timestamp_file     VARCHAR(255) NULL,
            nom151_file        VARCHAR(255) NULL,
            raw_response       TEXT NULL,
            already_existed    TINYINT(1) NOT NULL DEFAULT 0,
            freg               DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_hash (pdf_hash_sha256),
            INDEX idx_tx       (transaccion_id),
            INDEX idx_freg     (freg)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('cincelEnsureSchema cincel_timestamps: ' . $e->getMessage()); }

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM transacciones LIKE 'cincel_timestamp_hash'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE transacciones ADD COLUMN cincel_timestamp_hash CHAR(64) NULL,
                                                    ADD INDEX idx_cincel_ts (cincel_timestamp_hash)");
        }
    } catch (Throwable $e) { /* non-fatal */ }
}

/**
 * Persist a timestamp result to cincel_timestamps + transacciones.
 *
 * Returns the row id of the inserted/updated cincel_timestamps row,
 * or null on failure.
 */
function cincelSaveTimestamp(PDO $pdo, array $result, ?int $transaccionId = null, ?string $pdfPath = null): ?int {
    if (empty($result['ok']) || empty($result['hash'])) return null;
    cincelEnsureSchema($pdo);
    $ts = $result['timestamp'] ?? [];
    try {
        $stmt = $pdo->prepare("INSERT INTO cincel_timestamps
            (transaccion_id, pdf_path, pdf_hash_sha256,
             bitcoin_file, timestamp_file, nom151_file,
             raw_response, already_existed)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                bitcoin_file   = VALUES(bitcoin_file),
                timestamp_file = VALUES(timestamp_file),
                nom151_file    = VALUES(nom151_file),
                raw_response   = VALUES(raw_response),
                transaccion_id = COALESCE(transaccion_id, VALUES(transaccion_id))");
        $stmt->execute([
            $transaccionId,
            $pdfPath,
            $result['hash'],
            $ts['bitcoin']   ?? null,
            $ts['timestamp'] ?? null,
            $ts['nom151']    ?? null,
            json_encode($ts, JSON_UNESCAPED_SLASHES),
            !empty($result['already']) ? 1 : 0,
        ]);
        $id = (int)$pdo->lastInsertId();
        if ($transaccionId) {
            try {
                $pdo->prepare("UPDATE transacciones SET cincel_timestamp_hash = ? WHERE id = ?")
                    ->execute([$result['hash'], $transaccionId]);
            } catch (Throwable $e) { /* col may not exist on legacy schemas */ }
        }
        return $id;
    } catch (Throwable $e) {
        error_log('cincelSaveTimestamp: ' . $e->getMessage());
        return null;
    }
}
