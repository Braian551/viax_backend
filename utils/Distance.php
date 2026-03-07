<?php
/**
 * Funciones de distancia para negocio de movilidad.
 */

require_once __DIR__ . '/Haversine.php';

if (!function_exists('distance_between_points_km')) {
    function distance_between_points_km(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        return haversine_km($lat1, $lon1, $lat2, $lon2);
    }
}
