<?php
/**
 * Utilidad Haversine reutilizable para cálculos de distancia.
 */

if (!function_exists('haversine_km')) {
    function haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $lat1 = deg2rad($lat1);
        $lon1 = deg2rad($lon1);
        $lat2 = deg2rad($lat2);
        $lon2 = deg2rad($lon2);

        $dlat = $lat2 - $lat1;
        $dlon = $lon2 - $lon1;
        $a = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlon / 2) ** 2;

        return 6371.0 * 2.0 * atan2(sqrt($a), sqrt(1.0 - $a));
    }
}
