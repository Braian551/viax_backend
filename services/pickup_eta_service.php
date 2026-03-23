<?php
/**
 * Servicio de ETA de recogida (pickup) para vista previa de usuario.
 *
 * Modelo:
 * - Distancia conductor -> origen.
 * - Factor de tráfico horario.
 * - Ajuste por idle time del conductor (si está libre hace tiempo, suele aceptar/salir más rápido).
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/roads_snap_service.php';
require_once __DIR__ . '/traffic_service.php';

const PICKUP_ETA_CACHE_TTL_SEC = 60;

class PickupEtaService
{
    /**
     * @param array<int,array<string,mixed>> $rankedDrivers
     * @return array<string,mixed>
     */
    public static function estimateFromRankedDrivers(float $pickupLat, float $pickupLng, array $rankedDrivers): array
    {
        if (empty($rankedDrivers)) {
            return [
                'has_eta' => false,
                'eta_seconds' => null,
                'eta_minutes' => null,
                'traffic_factor' => null,
                'distance_km' => null,
                'driver_id' => null,
            ];
        }

        $best = $rankedDrivers[0];
        $driverId = isset($best['driver_id']) ? (int)$best['driver_id'] : (int)($best['id'] ?? 0);
        if ($driverId <= 0) {
            return [
                'has_eta' => false,
                'eta_seconds' => null,
                'eta_minutes' => null,
                'traffic_factor' => null,
                'distance_km' => null,
                'driver_id' => null,
            ];
        }

        $redis = Cache::redis();
        $driverLocRaw = $redis ? $redis->get('drivers:location:' . $driverId) : null;
        $driverLoc = is_string($driverLocRaw) ? json_decode($driverLocRaw, true) : null;

        $distanceKm = isset($best['distance_km']) ? max(0.05, (float)$best['distance_km']) : 0.05;
        if (is_array($driverLoc) && isset($driverLoc['lat'], $driverLoc['lng'])) {
            $driverLat = (float)$driverLoc['lat'];
            $driverLng = (float)$driverLoc['lng'];

            // Snap a vía para mejorar precisión espacial del ETA.
            [$snapLat, $snapLng] = self::snapPickupRoutePoint($redis, $driverId, $driverLat, $driverLng);

            $distanceKm = max(0.05, self::haversineKm(
                $snapLat,
                $snapLng,
                $pickupLat,
                $pickupLng
            ));

            // ETA predictivo cacheado por ruta para reducir llamadas externas.
            $cachedRouteEta = self::getCachedRouteEta($snapLat, $snapLng, $pickupLat, $pickupLng);
            if (is_array($cachedRouteEta)) {
                return [
                    'has_eta' => true,
                    'eta_seconds' => (int)$cachedRouteEta['eta_seconds'],
                    'eta_minutes' => (int)max(1, ceil(((int)$cachedRouteEta['eta_seconds']) / 60)),
                    'traffic_factor' => (float)($cachedRouteEta['traffic_factor'] ?? 1.0),
                    'distance_km' => round($distanceKm, 3),
                    'driver_id' => $driverId,
                    'idle_seconds' => self::driverIdleSeconds($driverId),
                ];
            }
        }

        $speedKmh = is_array($driverLoc) && isset($driverLoc['speed'])
            ? max(10.0, (float)$driverLoc['speed'])
            : 24.0;

        $baseSeconds = (int)round(($distanceKm / $speedKmh) * 3600.0);
        $trafficFactor = self::trafficFactorNow();

        if (is_array($driverLoc) && isset($driverLoc['lat'], $driverLoc['lng'])) {
            $traffic = trafficGetConditions((float)$driverLoc['lat'], (float)$driverLoc['lng'], $pickupLat, $pickupLng);
            if (isset($traffic['traffic_ratio']) && is_numeric($traffic['traffic_ratio'])) {
                $trafficFactor = max(0.8, min(2.2, (float)$traffic['traffic_ratio']));
            }
        }

        $idleSeconds = self::driverIdleSeconds($driverId);
        $idleBoostSeconds = min(120, (int)round($idleSeconds / 60));

        $etaSeconds = (int)max(60, round(($baseSeconds * $trafficFactor) - $idleBoostSeconds));

        if (is_array($driverLoc) && isset($driverLoc['lat'], $driverLoc['lng'])) {
            self::storeCachedRouteEta((float)$driverLoc['lat'], (float)$driverLoc['lng'], $pickupLat, $pickupLng, $etaSeconds, $trafficFactor);
        }

        return [
            'has_eta' => true,
            'eta_seconds' => $etaSeconds,
            'eta_minutes' => (int)max(1, ceil($etaSeconds / 60)),
            'traffic_factor' => round($trafficFactor, 2),
            'distance_km' => round($distanceKm, 3),
            'driver_id' => $driverId,
            'idle_seconds' => $idleSeconds,
        ];
    }

    /**
     * @return array{0:float,1:float}
     */
    private static function snapPickupRoutePoint($redis, int $driverId, float $lat, float $lng): array
    {
        if (!$redis || $driverId <= 0) {
            return [$lat, $lng];
        }

        try {
            [$snapLat, $snapLng] = RoadsSnapService::snapDriverPoint($redis, 0, $driverId, $lat, $lng);
            return [(float)$snapLat, (float)$snapLng];
        } catch (Throwable $e) {
            return [$lat, $lng];
        }
    }

    private static function routeCacheKey(float $originLat, float $originLng, float $destLat, float $destLng): string
    {
        $hash = sha1(implode('|', [
            round($originLat, 5),
            round($originLng, 5),
            round($destLat, 5),
            round($destLng, 5),
        ]));
        return 'eta:route:' . $hash;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function getCachedRouteEta(float $originLat, float $originLng, float $destLat, float $destLng): ?array
    {
        $raw = Cache::get(self::routeCacheKey($originLat, $originLng, $destLat, $destLng));
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['eta_seconds'])) {
            return null;
        }
        return $decoded;
    }

    private static function storeCachedRouteEta(float $originLat, float $originLng, float $destLat, float $destLng, int $etaSeconds, float $trafficFactor): void
    {
        $payload = [
            'eta_seconds' => max(1, $etaSeconds),
            'traffic_factor' => round($trafficFactor, 3),
            'ts' => gmdate('c'),
        ];

        Cache::set(
            self::routeCacheKey($originLat, $originLng, $destLat, $destLng),
            (string)json_encode($payload, JSON_UNESCAPED_UNICODE),
            PICKUP_ETA_CACHE_TTL_SEC
        );
    }

    private static function trafficFactorNow(): float
    {
        $hour = (int)date('G');

        // Horas pico suaves para evitar sobreestimaciones extremas.
        if (($hour >= 6 && $hour <= 9) || ($hour >= 16 && $hour <= 20)) {
            return 1.25;
        }

        if ($hour >= 22 || $hour <= 4) {
            return 0.9;
        }

        return 1.0;
    }

    private static function driverIdleSeconds(int $driverId): int
    {
        if ($driverId <= 0) {
            return 0;
        }

        $raw = Cache::get('driver:' . $driverId . ':last_trip_end');
        if (!is_string($raw) || !is_numeric($raw)) {
            return 0;
        }

        return max(0, time() - (int)$raw);
    }

    private static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
}
