<?php
/**
 * Helpers geoespaciales para matching y tracking.
 */

require_once __DIR__ . '/Distance.php';

if (!function_exists('is_within_radius_km')) {
    function is_within_radius_km(
        float $originLat,
        float $originLng,
        float $targetLat,
        float $targetLng,
        float $radiusKm
    ): bool {
        return distance_between_points_km($originLat, $originLng, $targetLat, $targetLng) <= $radiusKm;
    }
}
