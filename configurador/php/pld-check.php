<?php
/**
 * Voltika — PLD / AML check against Mexican government sources.
 *
 * Customer brief 2026-05-04: legal/AML obligation requires every
 * applicant to be checked against money-laundering and terrorism
 * watchlists before approval. Until now the dashboard relied on
 * CDC's own hawkAlerts data — limited coverage and not authoritative.
 *
 * This file consolidates a multi-source PLD/AML check:
 *   1. Bloqueadas SHCP/CNBV (free, official Mexico)
 *      Source: gob.mx publishes the "Lista de Personas Bloqueadas"
 *      under SHCP/UIF authority. Integrators typically scrape the
 *      JSON published at https://www.gob.mx/shcp (snapshot updated
 *      irregularly) — we cache locally and grep against the applicant's
 *      full name + CURP + RFC.
 *   2. OFAC SDN (United States Treasury, free)
 *      https://www.treasury.gov/ofac/downloads/sdn.xml — scraped
 *      and cached locally. International coverage worth a free hit.
 *   3. CDC hawkAlerts (already integrated in extractPreaprobacionData)
 *      Cross-checked separately — this file does NOT call CDC, it
 *      reads the cached pld_match flag from consultas_buro.
 *
 * Usage:
 *   $r = pldCheck([
 *       'nombre' => 'Carlos',
 *       'apellido_paterno' => 'Cerón',
 *       'apellido_materno' => 'Galindo',
 *       'curp'    => 'CEGC...',
 *       'rfc'     => '',
 *   ]);
 *   if ($r['match']) { ...block... }
 *
 * Returns:
 *   {
 *     match:        bool   — any list flagged this person,
 *     sources:      [],    — list of source names that matched
 *     details:      [],    — per-source match info (name, list, score),
 *     checked_at:   ISO8601,
 *     cache_age_days: int  — how stale the local list snapshot is
 *   }
 *
 * IMPORTANT: This file ships with EMPTY local caches. The cron at
 * cron-pld-refresh.php (also in this directory) downloads + parses
 * the lists and writes them under php/pld-cache/. Without that cron
 * running, every check returns match=false and a `cache_age_days=
 * 99999` flag so the dashboard can warn the admin.
 */

declare(strict_types=1);

const PLD_CACHE_DIR = __DIR__ . '/pld-cache';
const PLD_CACHE_FILES = [
    'shcp_uif' => 'shcp_uif_personas_bloqueadas.json',
    'ofac_sdn' => 'ofac_sdn.json',
];

/**
 * Run the full PLD check for an applicant.
 */
function pldCheck(array $applicant): array {
    $sources = [];
    $details = [];
    $match   = false;
    $oldestSnapshot = 0;   // cache age = age of the OLDEST source we have

    // Build the searchable strings once. Match is case-insensitive,
    // accent-insensitive, and on full-name (nombre + apellidos).
    $fullName = trim(implode(' ', array_filter([
        $applicant['nombre']           ?? '',
        $applicant['apellido_paterno'] ?? '',
        $applicant['apellido_materno'] ?? '',
    ])));
    $needles = [
        'name' => pldNormalise($fullName),
        'curp' => strtoupper(trim($applicant['curp'] ?? '')),
        'rfc'  => strtoupper(trim($applicant['rfc']  ?? '')),
    ];

    foreach (PLD_CACHE_FILES as $sourceKey => $filename) {
        $path = PLD_CACHE_DIR . '/' . $filename;
        if (!file_exists($path) || !is_readable($path)) {
            // Snapshot missing — record as stale, treat as no-match
            // (we won't block silently when our data is gone).
            $details[$sourceKey] = ['status' => 'cache_missing'];
            $oldestSnapshot = max($oldestSnapshot, 99999);
            continue;
        }
        $age = (int) floor((time() - filemtime($path)) / 86400);
        $oldestSnapshot = max($oldestSnapshot, $age);

        $list = @json_decode((string) file_get_contents($path), true);
        if (!is_array($list)) {
            $details[$sourceKey] = ['status' => 'cache_corrupt', 'age_days' => $age];
            continue;
        }

        $hit = pldSearchList($list, $needles);
        $details[$sourceKey] = [
            'status'   => $hit ? 'MATCH' : 'clear',
            'age_days' => $age,
            'hit'      => $hit ?: null,
        ];
        if ($hit) {
            $match = true;
            $sources[] = $sourceKey;
        }
    }

    return [
        'match'           => $match,
        'sources'         => $sources,
        'details'         => $details,
        'checked_at'      => date('c'),
        'cache_age_days'  => $oldestSnapshot,
    ];
}

/**
 * Search a list (array of {name, curp?, rfc?, list_name}) for any
 * row matching the applicant's identifiers. Returns the matched row
 * or null.
 */
function pldSearchList(array $list, array $needles): ?array {
    foreach ($list as $row) {
        // CURP match is the strongest signal (unique per person).
        if (!empty($needles['curp']) && !empty($row['curp']) && strtoupper($row['curp']) === $needles['curp']) {
            return $row;
        }
        // RFC: treat the same way.
        if (!empty($needles['rfc']) && !empty($row['rfc']) && strtoupper($row['rfc']) === $needles['rfc']) {
            return $row;
        }
        // Full-name match: normalise both sides and compare.
        if (!empty($needles['name']) && !empty($row['name'])) {
            if (pldNormalise($row['name']) === $needles['name']) {
                return $row;
            }
        }
    }
    return null;
}

/**
 * Normalise a string for fuzzy comparison: lowercase, strip accents,
 * collapse whitespace, drop punctuation. Names from the watchlists
 * vary in capitalisation/diacritics so this is a low bar that still
 * avoids false positives from punctuation differences.
 */
function pldNormalise(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    // Strip accents (NFD normalise + remove combining marks). Falls
    // back to a manual table when intl extension is missing.
    if (class_exists('Normalizer')) {
        $s = Normalizer::normalize($s, Normalizer::FORM_D) ?: $s;
        $s = preg_replace('/\p{Mn}+/u', '', $s) ?: $s;
    } else {
        $s = strtr($s, [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u',
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','Ü'=>'U',
        ]);
    }
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s) ?: $s;
    $s = preg_replace('/\s+/', ' ', $s) ?: $s;
    return trim($s);
}
