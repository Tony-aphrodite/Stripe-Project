<?php
/**
 * Catálogo: normalización de modelo y color.
 *
 * Coexisten dos configuradores en producción:
 *   - Legacy (Ship.js): manda "Voltika Tromox Pesgo" / "Gris moderno".
 *   - Nuevo (productos.js): manda "Pesgo Plus" / "gris".
 *
 * El admin (inventario_motos / motos-disponibles.php) filtra por EXACT match
 * en "modelo" y "color". Una venta legacy jamás coincide con stock real, por
 * lo que Eduardo Gonzalez Lopez (VK-1776828725 / Voltika Tromox Pesgo / Gris
 * moderno) no podía recibir moto en el panel. Esta capa normaliza AMBOS
 * formatos al código corto que usa el inventario, sin perder información.
 */

if (!function_exists('voltikaNormalizeModelo')) {
    /**
     * Normaliza cualquier representación de modelo al código corto que usa
     * inventario_motos.modelo. Devuelve el input original si no reconoce el
     * valor (no queremos romper entradas desconocidas).
     */
    function voltikaNormalizeModelo($raw): string {
        $v = trim((string)$raw);
        if ($v === '') return '';

        // Strip common legacy prefixes ("Voltika Tromox ", "Voltika ", "Tromox ").
        // Done case-insensitively because the Ship.js strings are Title Case but
        // we've also seen lowercase variants in webhook payloads.
        $stripped = preg_replace(
            '/^(?:voltika\s+tromox\s+|voltika\s+|tromox\s+)/i',
            '',
            $v
        );
        $stripped = trim($stripped);
        $lower    = strtolower($stripped);

        // Canonical map — keys are lowercase "free-text" forms we've seen, values
        // are the short codes in productos.js / inventario_motos.
        //   • keep the known legacy surface ("m05", "pesgo", "mino b") covered
        //   • keep the canonical short codes as identity entries so repeated
        //     calls are idempotent.
        $map = [
            'm03'          => 'M03',
            'm05'          => 'M05',
            'mc10'         => 'MC10 Streetx',
            'mc10 streetx' => 'MC10 Streetx',
            'pesgo'        => 'Pesgo Plus',
            'pesgo plus'   => 'Pesgo Plus',
            'mino'         => 'Mino-B',
            'mino b'       => 'Mino-B',
            'mino-b'       => 'Mino-B',
            'ukko'         => 'Ukko S+',
            'ukko s'       => 'Ukko S+',
            'ukko s+'      => 'Ukko S+',
            'ukko-s'       => 'Ukko S+',
        ];

        if (isset($map[$lower])) return $map[$lower];

        // Unknown value — return trimmed original without legacy prefix so a new
        // model name ("M07") still flows through.
        return $stripped !== '' ? $stripped : $v;
    }
}

if (!function_exists('voltikaNormalizeColor')) {
    /**
     * Normaliza cualquier representación de color al id corto que usa
     * inventario_motos.color (lowercase, una sola palabra cuando aplica).
     */
    function voltikaNormalizeColor($raw): string {
        $v = trim((string)$raw);
        if ($v === '') return '';

        $lower = strtolower($v);

        // Keep explicit descriptors mapped so legacy "Gris moderno" collapses
        // to the plain "gris" used by inventario.
        $map = [
            'gris moderno'  => 'gris',
            'gris cemento'  => 'gris',
            'gris metalico' => 'gris',
            'gris plata'    => 'plata',
            'negro mate'    => 'negro',
            'negro brillo'  => 'negro',
            'negro noche'   => 'negro',
            'verde militar' => 'verde',
            'verde olivo'   => 'verde',
            'azul marino'   => 'azul',
            'azul cielo'    => 'azul',
            'blanco perla'  => 'blanco',
            'naranja neon'  => 'naranja',
        ];
        if (isset($map[$lower])) return $map[$lower];

        // Generic fallback: take first token, lowercase, strip accents-free common
        // descriptors. Colors in productos.js are single lowercase words.
        $first = preg_split('/\s+/', $lower)[0] ?? '';
        $known = ['gris', 'negro', 'plata', 'verde', 'azul', 'blanco', 'naranja', 'rojo', 'amarillo'];
        if (in_array($first, $known, true)) return $first;

        // Unknown color — return the lowercased original so it still groups
        // future "turquesa" / "rosa" variants rather than drifting to title-case.
        return $lower;
    }
}

if (!function_exists('voltikaNormalizeCatalogFields')) {
    /**
     * Convenience: normaliza modelo + color en un array asociativo (in-place
     * friendly). Útil para llamar desde confirmar-orden.php antes del INSERT.
     *
     * @param array $row  Expects keys 'modelo' and 'color' (missing keys ignored).
     * @return array      Same array with both values normalized.
     */
    function voltikaNormalizeCatalogFields(array $row): array {
        if (array_key_exists('modelo', $row)) $row['modelo'] = voltikaNormalizeModelo($row['modelo']);
        if (array_key_exists('color', $row))  $row['color']  = voltikaNormalizeColor($row['color']);
        return $row;
    }
}
