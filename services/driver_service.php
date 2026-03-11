<?php
/**
 * Servicio de descubrimiento de conductores con Redis GEO.
 */

require_once __DIR__ . '/../config/app.php';

class DriverGeoService
{
    private const GEO_KEY = 'drivers:geo';
    private const GRID_FACTOR = 100; // 0.01 grados

    public static function upsertDriverLocation(int $driverId, float $lat, float $lng, ?float $speed = null): void
    {
        $redis = Cache::redis();
        if (!$redis || $driverId <= 0) {
            return;
        }

        try {
            $redis->rawCommand('GEOADD', self::GEO_KEY, (string)$lng, (string)$lat, (string)$driverId);

            self::updateGridMembership($redis, $driverId, $lat, $lng);

            Cache::set('driver_location:' . $driverId, (string)json_encode([
                'lat' => $lat,
                'lng' => $lng,
                'speed' => $speed,
                'timestamp' => time(),
            ]), 30);
            Cache::sAdd('active_drivers', (string)$driverId);
        } catch (Throwable $e) {
            error_log('[DriverGeoService] GEOADD warning: ' . $e->getMessage());
        }
    }

    public static function setDriverState(int $driverId, string $state): void
    {
        if ($driverId <= 0) {
            return;
        }

        $normalized = strtolower(trim($state));
        if (!in_array($normalized, ['available', 'on_trip', 'offline'], true)) {
            $normalized = 'offline';
        }

        Cache::set('driver:' . $driverId . ':state', $normalized, 120);
    }

    public static function getDriverState(int $driverId): string
    {
        $raw = Cache::get('driver:' . $driverId . ':state');
        if (!is_string($raw) || trim($raw) === '') {
            return 'offline';
        }
        return strtolower(trim($raw));
    }

    /**
     * @return array<int,array{id:int,distance_km:float}>
     */
    public static function searchAvailableNearby(float $lat, float $lng, float $radiusKm = 5.0, int $limit = 20): array
    {
        $redis = Cache::redis();
        if (!$redis) {
            return [];
        }

        try {
            $rows = $redis->rawCommand(
                'GEOSEARCH',
                self::GEO_KEY,
                'FROMLONLAT', (string)$lng, (string)$lat,
                'BYRADIUS', (string)$radiusKm, 'km',
                'WITHDIST',
                'ASC',
                'COUNT', (string)$limit
            );

            if (!is_array($rows)) {
                return [];
            }

            $out = [];
            foreach ($rows as $row) {
                if (!is_array($row) || count($row) < 2) {
                    continue;
                }

                $driverId = (int)$row[0];
                $distanceKm = (float)$row[1];
                if ($driverId <= 0) {
                    continue;
                }

                if (self::getDriverState($driverId) !== 'available') {
                    continue;
                }

                $out[] = [
                    'id' => $driverId,
                    'distance_km' => $distanceKm,
                ];
            }

            return $out;
        } catch (Throwable $e) {
            error_log('[DriverGeoService] GEOSEARCH warning: ' . $e->getMessage());
            return [];
        }
    }

    public static function gridCellKey(float $lat, float $lng): string
    {
        $latIndex = (int)floor($lat * self::GRID_FACTOR);
        $lngIndex = (int)floor($lng * self::GRID_FACTOR);
        return 'grid:' . $latIndex . ':' . $lngIndex;
    }

    public static function zoneCellKey(float $lat, float $lng): string
    {
        $latIndex = (int)floor($lat * self::GRID_FACTOR);
        $lngIndex = (int)floor($lng * self::GRID_FACTOR);
        return 'zone:' . $latIndex . ':' . $lngIndex;
    }

    public static function incrementActiveRequestForCell(string $zoneCell): void
    {
        $redis = Cache::redis();
        if (!$redis) return;

        try {
            $redis->incr($zoneCell . ':active_requests');
            $redis->expire($zoneCell . ':active_requests', 900);
        } catch (Throwable $e) {
            error_log('[DriverGeoService] active_request metric warning: ' . $e->getMessage());
        }
    }

    public static function refreshAvailableDriversForCell(string $zoneCell, int $availableDrivers): void
    {
        $redis = Cache::redis();
        if (!$redis) return;

        $available = max(0, $availableDrivers);
        $key = $zoneCell . ':available_drivers';

        try {
            $redis->setex($key, 120, (string)$available);
        } catch (Throwable $e) {
            error_log('[DriverGeoService] available_driver metric warning: ' . $e->getMessage());
        }
    }

    public static function updateDriverStats(
        int $driverId,
        ?float $acceptanceRate = null,
        ?float $rejectionRate = null,
        ?int $recentTrips = null
    ): void {
        $redis = Cache::redis();
        if (!$redis || $driverId <= 0) {
            return;
        }

        $statsKey = 'driver:' . $driverId . ':stats';
        try {
            if ($acceptanceRate !== null) {
                $redis->hSet($statsKey, 'acceptance_rate', (string)max(0.0, min(1.0, $acceptanceRate)));
            }
            if ($rejectionRate !== null) {
                $redis->hSet($statsKey, 'rejection_rate', (string)max(0.0, min(1.0, $rejectionRate)));
            }
            if ($recentTrips !== null) {
                $redis->hSet($statsKey, 'recent_trips', (string)max(0, $recentTrips));
            }
            $redis->expire($statsKey, 86400);
        } catch (Throwable $e) {
            error_log('[DriverGeoService] updateDriverStats warning: ' . $e->getMessage());
        }
    }

    private static function updateGridMembership($redis, int $driverId, float $lat, float $lng): void
    {
        $newCell = self::gridCellKey($lat, $lng);
        $prevCellKey = 'driver:' . $driverId . ':grid_cell';
        $prevCell = $redis->get($prevCellKey);

        if (is_string($prevCell) && $prevCell !== '' && $prevCell !== $newCell) {
            $redis->sRem($prevCell, (string)$driverId);
            $prevZone = str_replace('grid:', 'zone:', $prevCell);
            $redis->setex($prevZone . ':available_drivers', 120, (string)max(0, (int)$redis->sCard($prevCell)));
        }

        $redis->sAdd($newCell, (string)$driverId);
        $redis->setex($prevCellKey, 300, $newCell);

        $zoneCell = str_replace('grid:', 'zone:', $newCell);
        $redis->setex($zoneCell . ':available_drivers', 120, (string)max(0, (int)$redis->sCard($newCell)));
    }
}
