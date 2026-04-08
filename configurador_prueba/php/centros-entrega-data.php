<?php
/**
 * Voltika — Centros de Entrega (PHP mirror of js/data/centros-entrega3.js)
 *
 * Provides PHP-side access to delivery point data so backend code
 * (notifications, emails, etc.) can look up punto details by ID.
 *
 * IMPORTANT: This file must stay in sync with js/data/centros-entrega3.js.
 * If a punto is added/edited in the JS file, update it here as well.
 *
 * Usage:
 *   require_once __DIR__ . '/centros-entrega-data.php';
 *   $punto = obtenerPuntoPorId('godike-motors');
 *   echo $punto['nombre'];        // "Godike Motors"
 *   echo $punto['horarios'];      // "Lunes a Viernes 10:00..."
 *   echo $punto['link_maps'];     // auto-generated Google Maps URL
 */

if (!function_exists('obtenerCentrosVoltika')) {

    /**
     * Returns the full list of Voltika delivery points.
     * Mirror of VOLTIKA_CENTROS in centros-entrega3.js.
     */
    function obtenerCentrosVoltika(): array {
        return [
            [
                'id'         => 'voltika-center-santa-fe',
                'nombre'     => 'Voltika Center Santa Fe',
                'direccion'  => 'Ernesto J. Piper 9',
                'ubicacion'  => 'Santa Fe – CDMX',
                'colonia'    => 'Paseos de las Lomas, Álvaro Obregón',
                'cp'         => '01330',
                'ciudad'     => 'Ciudad de México',
                'estado'     => 'CDMX',
                'horarios'   => 'Atención por WhatsApp o cita previa',
                'telefono'   => '+52 55 1341 6370',
                'tipo'       => 'center',
            ],
            [
                'id'         => 'godike-motors',
                'nombre'     => 'Godike Motors',
                'direccion'  => 'Av. Ermita Iztapalapa 2453',
                'ubicacion'  => 'Iztapalapa, Ciudad de México',
                'colonia'    => '',
                'cp'         => '09820',
                'ciudad'     => 'Ciudad de México',
                'estado'     => 'CDMX',
                'horarios'   => 'Lunes a Viernes 10:00 a 18:30 · Sábado 11:00 a 14:00 · Domingo cerrado',
                'telefono'   => '',
                'tipo'       => 'certificado',
            ],
            [
                'id'         => 'garage-mushu',
                'nombre'     => 'Garage Mushu',
                'direccion'  => 'Av. Central 502',
                'ubicacion'  => 'Las Américas, Ecatepec',
                'colonia'    => '',
                'cp'         => '55076',
                'ciudad'     => 'Ecatepec de Morelos',
                'estado'     => 'México',
                'horarios'   => 'Lunes a Viernes 10:00 a 18:00',
                'telefono'   => '',
                'tipo'       => 'certificado',
            ],
            [
                'id'         => 'race-moto-taller-tlalpan',
                'nombre'     => 'Race Moto Taller',
                'direccion'  => 'Carretera Federal a Cuernavaca #5595',
                'ubicacion'  => 'Tlalpan – CDMX',
                'colonia'    => 'Col. San Pedro Mártir, Alcaldía Tlalpan',
                'cp'         => '14650',
                'ciudad'     => 'Ciudad de México',
                'estado'     => 'Distrito Federal',
                'horarios'   => 'L-V: 10:00 a 18:00 · Sábado: 10:00 a 14:00 · Domingo: Cerrado',
                'telefono'   => '',
                'tipo'       => 'certificado',
            ],
            [
                'id'         => 'moto-centro-chihuahua',
                'nombre'     => 'Moto Centro',
                'direccion'  => 'Tecnológico 1103',
                'ubicacion'  => 'Chihuahua',
                'colonia'    => 'Col. Santo Niño',
                'cp'         => '31200',
                'ciudad'     => 'Chihuahua',
                'estado'     => 'Chihuahua',
                'horarios'   => 'L-V: 9:00 a 13:00 y 15:00 a 18:00 · Sábado: 9:00 a 13:00 · Domingo: Cerrado',
                'telefono'   => '',
                'tipo'       => 'certificado',
            ],
        ];
    }

    /**
     * Look up a punto by ID. Returns null if not found.
     * The returned array also includes:
     *   - 'direccion_completa': single-line full address
     *   - 'link_maps':          Google Maps search URL for the address
     */
    function obtenerPuntoPorId(string $puntoId): ?array {
        if ($puntoId === '') return null;

        foreach (obtenerCentrosVoltika() as $punto) {
            if ($punto['id'] === $puntoId) {
                $punto['direccion_completa'] = construirDireccionCompleta($punto);
                $punto['link_maps']          = generarLinkMaps($punto);
                return $punto;
            }
        }
        return null;
    }

    /**
     * Look up a punto by name (fuzzy, case-insensitive).
     * Used as fallback when only punto_nombre is stored, not punto_id.
     */
    function obtenerPuntoPorNombre(string $puntoNombre): ?array {
        if ($puntoNombre === '') return null;
        $needle = mb_strtolower(trim($puntoNombre));

        foreach (obtenerCentrosVoltika() as $punto) {
            if (mb_strtolower($punto['nombre']) === $needle) {
                $punto['direccion_completa'] = construirDireccionCompleta($punto);
                $punto['link_maps']          = generarLinkMaps($punto);
                return $punto;
            }
        }
        return null;
    }

    /**
     * Build a single-line address string from a punto record.
     */
    function construirDireccionCompleta(array $punto): string {
        $partes = array_filter([
            $punto['direccion'] ?? '',
            $punto['colonia']   ?? '',
            $punto['ciudad']    ?? '',
            $punto['estado']    ?? '',
            !empty($punto['cp']) ? 'CP ' . $punto['cp'] : '',
        ]);
        return implode(', ', $partes);
    }

    /**
     * Generate a Google Maps search URL from a punto's address.
     */
    function generarLinkMaps(array $punto): string {
        $direccion = construirDireccionCompleta($punto);
        if ($direccion === '') return '';
        return 'https://www.google.com/maps/search/?api=1&query=' . urlencode($direccion);
    }
}
