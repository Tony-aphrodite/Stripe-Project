<?php
/**
 * Voltika — Shared Truora API helpers.
 *
 * Used by both truora-webhook.php (to enrich webhook data) and
 * truora-status.php (as a self-healing fallback when the webhook
 * never arrives — customer report 2026-04-29: webhook config in
 * Truora dashboard kept misfiring, leaving customers stuck on
 * "Validando concordancia…" forever).
 *
 * Functions:
 *   truoraFetchProcessDetails($processId): ?array
 *     - GET /v1/processes/<id> (and 2 fallback shapes) with the
 *       account-level API key. Logs every attempt to truora_fetch_log.
 *
 *   truoraExtractCurp($details): ?string
 *     - Walk a payload looking for an 18-char CURP-shaped value in any
 *       common field name (national_id_number, curp, etc.) at any
 *       nesting depth.
 */

require_once __DIR__ . '/config.php';

if (!function_exists('truoraFetchProcessDetails')) {
function truoraFetchProcessDetails(string $processId): ?array {
    if (!defined('TRUORA_API_KEY') || !TRUORA_API_KEY) return null;
    if (!defined('TRUORA_IDENTITY_API_URL')) define('TRUORA_IDENTITY_API_URL', 'https://api.identity.truora.com');

    $candidates = [
        TRUORA_IDENTITY_API_URL . '/v1/processes/' . urlencode($processId),
        TRUORA_IDENTITY_API_URL . '/v1/identity/' . urlencode($processId),
        TRUORA_IDENTITY_API_URL . '/v1/processes/' . urlencode($processId) . '/result',
    ];

    $first200 = null;

    foreach ($candidates as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_HTTPHEADER     => [
                'Truora-API-Key: ' . TRUORA_API_KEY,
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        try {
            $pdo = getDB();
            $pdo->exec("CREATE TABLE IF NOT EXISTS truora_fetch_log (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                process_id VARCHAR(64) NULL,
                url VARCHAR(255) NULL,
                http_code INT NULL,
                response MEDIUMTEXT NULL,
                curl_err VARCHAR(500) NULL,
                fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_process (process_id)
            )");
            $pdo->prepare("INSERT INTO truora_fetch_log
                    (process_id, url, http_code, response, curl_err)
                VALUES (?, ?, ?, ?, ?)")
                ->execute([
                    $processId, $url, $code,
                    substr((string)$body, 0, 8000),
                    substr((string)$err, 0, 500),
                ]);
        } catch (Throwable $e) {}

        if ($code >= 200 && $code < 300 && $body) {
            $arr = json_decode((string)$body, true);
            if (is_array($arr) && !empty($arr) && $first200 === null) $first200 = $arr;
        }
    }
    return $first200;
}
}

if (!function_exists('truoraExtractCurp')) {
function truoraExtractCurp(?array $details): ?string {
    if (!is_array($details)) return null;

    $candidates = [];

    foreach (['national_id_number', 'curp', 'document_id', 'identification_number'] as $k) {
        if (!empty($details[$k]) && is_string($details[$k])) $candidates[] = $details[$k];
    }
    foreach (['person_information', 'document', 'identity', 'result'] as $section) {
        if (!empty($details[$section]) && is_array($details[$section])) {
            foreach (['national_id_number', 'curp', 'document_id', 'identification_number'] as $k) {
                if (!empty($details[$section][$k]) && is_string($details[$section][$k])) {
                    $candidates[] = $details[$section][$k];
                }
            }
        }
    }
    if (!empty($details['validations']) && is_array($details['validations'])) {
        foreach ($details['validations'] as $v) {
            if (!is_array($v)) continue;
            foreach ($v as $val) {
                if (is_string($val) && preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/i', $val)) {
                    $candidates[] = $val;
                }
            }
        }
    }
    $stack = [$details];
    while ($stack) {
        $node = array_pop($stack);
        if (is_array($node)) {
            foreach ($node as $v) {
                if (is_array($v)) $stack[] = $v;
                elseif (is_string($v) && preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/i', $v)) {
                    $candidates[] = $v;
                }
            }
        }
    }
    foreach ($candidates as $c) {
        $c = strtoupper(trim($c));
        if (preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/', $c)) return $c;
    }
    return null;
}
}

if (!function_exists('truoraExtractStatus')) {
/**
 * Find Truora's process-level status from any payload shape:
 *   { status: "valid" | "invalid" | "pending" | "in_progress" | ... }
 *   { result: "valid" | ... }
 *   { process_status: "..." }
 * Returns the raw string or null.
 */
function truoraExtractStatus(?array $details): ?string {
    if (!is_array($details)) return null;
    foreach (['status', 'result', 'process_status', 'final_status'] as $k) {
        if (!empty($details[$k]) && is_string($details[$k])) return strtolower(trim($details[$k]));
    }
    return null;
}
}
