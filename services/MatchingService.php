<?php
/**
 * Servicio legacy de matching de conductores cercanos.
 *
 * @deprecated use matching_service.php (RideMatchingService) con USE_NEW_SERVICES=true
 */

require_once __DIR__ . '/../utils/GeoUtils.php';

class MatchingService
{
    /**
     * Obtiene conductores activos de Redis y retorna los más cercanos.
     */
    public function nearestDrivers(float $userLat, float $userLng, float $radiusKm = 6.0, int $limit = 10): array
    {
        $driverIds = Cache::sMembers('active_drivers');
        $candidates = [];

        foreach ($driverIds as $driverIdRaw) {
            $driverId = (int) $driverIdRaw;
            $cached = Cache::get('driver_location:' . $driverId);
            if (!$cached) {
                continue;
            }

            $loc = json_decode((string) $cached, true);
            if (!is_array($loc) || !isset($loc['lat'], $loc['lng'])) {
                continue;
            }

            $lat = (float) $loc['lat'];
            $lng = (float) $loc['lng'];

            if (!is_within_radius_km($userLat, $userLng, $lat, $lng, $radiusKm)) {
                continue;
            }

            $distance = distance_between_points_km($userLat, $userLng, $lat, $lng);
            $candidates[] = [
                'driver_id' => $driverId,
                'distance_km' => $distance,
                'lat' => $lat,
                'lng' => $lng,
            ];
        }

        usort($candidates, static fn(array $a, array $b): int => $a['distance_km'] <=> $b['distance_km']);
        return array_slice($candidates, 0, $limit);
    }
}
