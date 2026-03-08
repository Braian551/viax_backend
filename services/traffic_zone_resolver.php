<?php
/**
 * Resolver de zonas geograficas para trafico.
 *
 * Convierte coordenadas reales en una llave de zona discreta para permitir
 * reutilizacion de calculos de trafico entre viajes cercanos.
 */

/**
 * Resuelve precision de zonas (2 o 3 decimales) desde entorno.
 */
function trafficZonePrecision(): int
{
    $raw = intval(env_value('TRAFFIC_ZONE_PRECISION', 2));
    if ($raw < 2) {
        return 2;
    }
    if ($raw > 3) {
        return 3;
    }
    return $raw;
}

/**
 * Construye la llave de zona para un punto.
 *
 * Ejemplo: 6.24_-75.57
 */
function trafficResolveZoneKey(float $lat, float $lng, ?int $precision = null): string
{
    $p = $precision ?? trafficZonePrecision();
    $zoneLat = round($lat, $p);
    $zoneLng = round($lng, $p);
    return $zoneLat . '_' . $zoneLng;
}

/**
 * Construye metadatos de zona para un origen y destino.
 */
function trafficResolveZonePair(float $originLat, float $originLng, float $destLat, float $destLng): array
{
    $originZone = trafficResolveZoneKey($originLat, $originLng);
    $destZone = trafficResolveZoneKey($destLat, $destLng);

    return [
        'origin_zone_key' => $originZone,
        'destination_zone_key' => $destZone,
        'zone_pair_key' => $originZone . '__' . $destZone,
    ];
}
