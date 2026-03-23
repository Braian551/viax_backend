<?php
/**
 * Servicio de descubrimiento de conductores con Redis GEO.
 */

require_once __DIR__ . '/../config/app.php';

class DriverGeoService
{
    private const GEO_KEY = 'drivers:geo';
    private const GRID_FACTOR = 200; // 0.005 grados (~500m)
    private const AVAILABLE_KEY = 'drivers:available';
    private const AVAILABLE_CITY_PREFIX = 'drivers:available:';
    private const GRID_PREFIX = 'drivers:grid:';
    private const GRID_CITY_PREFIX = 'drivers:grid:';
    private const LAST_GRID_PREFIX = 'drivers:last_grid:';
    private const LAST_GRID_CITY_PREFIX = 'drivers:last_grid_city:';
    private const LAST_CITY_PREFIX = 'drivers:last_city:';
    private const HEARTBEAT_PREFIX = 'driver:heartbeat:';

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

        $redis = Cache::redis();
        if (!$redis) {
            return;
        }

        try {
            $cityId = self::resolveDriverCityId($redis, $driverId);
            if ($normalized === 'available') {
                $redis->sAdd(self::AVAILABLE_KEY, (string)$driverId);
                $redis->sAdd(self::availableSetKey($cityId), (string)$driverId);
                $redis->sAdd('drivers:idle', (string)$driverId);
            } else {
                $redis->sRem(self::AVAILABLE_KEY, (string)$driverId);
                $redis->sRem(self::availableSetKey($cityId), (string)$driverId);
                $redis->sRem('drivers:idle', (string)$driverId);
            }
        } catch (Throwable $e) {
            error_log('[DriverGeoService] setDriverState availability warning: ' . $e->getMessage());
        }
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
        $cityId = self::getCityIdFromCoordinates($lat, $lng);
        $gridCandidates = self::discoverCandidatesByGrid($lat, $lng, $limit, 3, $cityId);
        if (!empty($gridCandidates)) {
            return $gridCandidates;
        }

        // Failsafe Redis GEO fallback.
        return self::searchAvailableNearbyGeo($lat, $lng, $radiusKm, $limit, $cityId);
    }

    /**
     * @return array<int,array{id:int,distance_km:float}>
     */
    public static function discoverCandidatesByGrid(float $lat, float $lng, int $limit = 20, int $maxRadius = 3, ?int $cityId = null): array
    {
        $redis = Cache::redis();
        if (!$redis) {
            return [];
        }

        $cityId = $cityId ?? self::getCityIdFromCoordinates($lat, $lng);

        $centerCell = self::gridCellId($lat, $lng);
        $cacheKey = 'dispatch:grid_cache:c' . $cityId . ':' . str_replace(':', '_', $centerCell) . ':r' . $maxRadius . ':l' . $limit;

        try {
            $cachedRaw = $redis->get($cacheKey);
            $cached = is_string($cachedRaw) ? json_decode($cachedRaw, true) : null;
            if (is_array($cached) && !empty($cached)) {
                return $cached;
            }

            [$centerX, $centerY] = self::gridCellXY($lat, $lng);
            $candidateIds = [];
            $cellIds = [];

            for ($radius = 1; $radius <= max(1, $maxRadius); $radius++) {
                for ($dx = -$radius; $dx <= $radius; $dx++) {
                    for ($dy = -$radius; $dy <= $radius; $dy++) {
                        $cellId = ($centerX + $dx) . ':' . ($centerY + $dy);
                        if (!isset($cellIds[$cellId])) {
                            $cellIds[$cellId] = true;
                        }
                    }
                }
            }

            $orderedCellIds = array_keys($cellIds);
            $responses = [];
            if (method_exists($redis, 'multi') && class_exists('Redis')) {
                $pipe = $redis->multi(\Redis::PIPELINE);
                if ($pipe) {
                    foreach ($orderedCellIds as $cellId) {
                        $pipe->sMembers(self::gridCellKeyByCity($cityId, $cellId));
                        $pipe->sMembers(self::GRID_PREFIX . $cellId);
                    }
                    $responses = $pipe->exec();
                }
            }

            $cursor = 0;
            foreach ($orderedCellIds as $cellId) {
                if (is_array($responses) && !empty($responses)) {
                    $cityMembers = $responses[$cursor] ?? [];
                    $legacyMembers = $responses[$cursor + 1] ?? [];
                    $cursor += 2;
                    $members = (is_array($cityMembers) && !empty($cityMembers))
                        ? $cityMembers
                        : (is_array($legacyMembers) ? $legacyMembers : []);
                } else {
                    $members = $redis->sMembers(self::gridCellKeyByCity($cityId, $cellId));
                    if (!is_array($members) || empty($members)) {
                        $members = $redis->sMembers(self::GRID_PREFIX . $cellId);
                    }
                }

                if (!is_array($members) || empty($members)) {
                    continue;
                }

                foreach ($members as $member) {
                    $driverId = (int)$member;
                    if ($driverId > 0) {
                        $candidateIds[$driverId] = true;
                    }
                }

                if (count($candidateIds) >= $limit) {
                    break;
                }
            }

            if (empty($candidateIds)) {
                return [];
            }

            $out = [];
            foreach (array_keys($candidateIds) as $driverId) {
                if (!self::isDriverAvailable($redis, $driverId, $cityId)) {
                    continue;
                }

                if (!self::hasLiveHeartbeat($redis, $driverId)) {
                    self::removeDriverFromRealtimeIndexes($driverId, $cityId);
                    continue;
                }

                $rawLoc = $redis->get('drivers:location:' . $driverId);
                $loc = is_string($rawLoc) ? json_decode($rawLoc, true) : null;
                if (!is_array($loc) || !isset($loc['lat'], $loc['lng'])) {
                    continue;
                }

                $distanceKm = self::haversineKm($lat, $lng, (float)$loc['lat'], (float)$loc['lng']);
                $out[] = [
                    'id' => $driverId,
                    'distance_km' => $distanceKm,
                ];
            }

            usort($out, static fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
            $out = array_slice($out, 0, $limit);

            $redis->setex($cacheKey, 5, json_encode($out, JSON_UNESCAPED_UNICODE));
            return $out;
        } catch (Throwable $e) {
            error_log('[DriverGeoService] grid discovery warning: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @return array<int,array{id:int,distance_km:float}>
     */
    private static function searchAvailableNearbyGeo(float $lat, float $lng, float $radiusKm = 5.0, int $limit = 20, ?int $cityId = null): array
    {
        $redis = Cache::redis();
        if (!$redis) {
            return [];
        }

        $cityId = $cityId ?? self::getCityIdFromCoordinates($lat, $lng);

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

                if (!self::isDriverAvailable($redis, $driverId, $cityId)) {
                    continue;
                }

                if (!self::hasLiveHeartbeat($redis, $driverId)) {
                    self::removeDriverFromRealtimeIndexes($driverId, $cityId);
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
        return self::GRID_PREFIX . self::gridCellId($lat, $lng);
    }

    public static function getCityIdFromCoordinates(float $lat, float $lng): int
    {
        // Heurística liviana para sharding por ciudad sin dependencia externa.
        // 1=Medellín/Valle de Aburrá, 2=Bogotá, 3=Resto Colombia.
        if ($lat >= 6.05 && $lat <= 6.45 && $lng >= -75.75 && $lng <= -75.45) {
            return 1;
        }
        if ($lat >= 4.45 && $lat <= 4.90 && $lng >= -74.35 && $lng <= -73.95) {
            return 2;
        }
        return 3;
    }

    public static function availableSetKey(?int $cityId = null): string
    {
        if ($cityId === null || $cityId <= 0) {
            return self::AVAILABLE_KEY;
        }
        return self::AVAILABLE_CITY_PREFIX . $cityId;
    }

    public static function gridCellKeyByCity(int $cityId, string $gridId): string
    {
        return self::GRID_CITY_PREFIX . $cityId . ':' . $gridId;
    }

    public static function isDriverAvailable($redis, int $driverId, ?int $cityId = null): bool
    {
        if ($driverId <= 0) {
            return false;
        }

        if ($cityId !== null && $cityId > 0) {
            if ($redis->sIsMember(self::availableSetKey($cityId), (string)$driverId)) {
                return true;
            }
        }

        return $redis->sIsMember(self::AVAILABLE_KEY, (string)$driverId);
    }

    public static function touchDriverHeartbeat(int $driverId, int $ttlSec = 20): void
    {
        $redis = Cache::redis();
        if (!$redis || $driverId <= 0) {
            return;
        }

        $redis->setex(self::HEARTBEAT_PREFIX . $driverId, $ttlSec, (string)time());
    }

    public static function removeDriverFromRealtimeIndexes(int $driverId, ?int $cityId = null): void
    {
        $redis = Cache::redis();
        if (!$redis || $driverId <= 0) {
            return;
        }

        try {
            $redis->sRem(self::AVAILABLE_KEY, (string)$driverId);
            $redis->sRem('drivers:idle', (string)$driverId);

            if ($cityId === null || $cityId <= 0) {
                $cityRaw = $redis->get(self::LAST_CITY_PREFIX . $driverId);
                $cityId = is_string($cityRaw) && is_numeric($cityRaw) ? (int)$cityRaw : null;
            }
            if ($cityId !== null && $cityId > 0) {
                $redis->sRem(self::availableSetKey($cityId), (string)$driverId);
            }

            $legacyGrid = $redis->get(self::LAST_GRID_PREFIX . $driverId);
            if (is_string($legacyGrid) && $legacyGrid !== '') {
                $redis->sRem($legacyGrid, (string)$driverId);
            }

            $cityGrid = $redis->get(self::LAST_GRID_CITY_PREFIX . $driverId);
            if (is_string($cityGrid) && $cityGrid !== '') {
                $redis->sRem($cityGrid, (string)$driverId);
            }

            // Limpieza de participación en caches de zona más recientes.
            $zoneKeys = $redis->keys('dispatch:zone_drivers:*');
            if (is_array($zoneKeys)) {
                foreach ($zoneKeys as $zoneKey) {
                    $redis->zRem((string)$zoneKey, (string)$driverId);
                }
            }
        } catch (Throwable $e) {
            error_log('[DriverGeoService] removeDriverFromRealtimeIndexes warning: ' . $e->getMessage());
        }
    }

    public static function gridIdForCoordinates(float $lat, float $lng): string
    {
        return self::gridCellId($lat, $lng);
    }

    /**
     * @return array{0:float,1:float}
     */
    public static function gridCenterFromId(string $gridId): array
    {
        $parts = explode(':', $gridId);
        if (count($parts) !== 2) {
            return [0.0, 0.0];
        }

        $x = (int)$parts[0];
        $y = (int)$parts[1];

        // Centro de celda para factor 0.01 grados.
        $lat = ($x + 0.5) / self::GRID_FACTOR;
        $lng = ($y + 0.5) / self::GRID_FACTOR;
        return [$lat, $lng];
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
        $cityId = self::getCityIdFromCoordinates($lat, $lng);
        $newCellCity = self::gridCellKeyByCity($cityId, self::gridCellId($lat, $lng));
        $prevCellKey = self::LAST_GRID_PREFIX . $driverId;
        $prevCellCityKey = self::LAST_GRID_CITY_PREFIX . $driverId;
        $prevCityKey = self::LAST_CITY_PREFIX . $driverId;
        $prevCell = $redis->get($prevCellKey);
        $prevCellCity = $redis->get($prevCellCityKey);
        $prevCity = $redis->get($prevCityKey);

        if (is_string($prevCell) && $prevCell !== '' && $prevCell !== $newCell) {
            $redis->sRem($prevCell, (string)$driverId);
            $prevZone = str_replace('grid:', 'zone:', $prevCell);
            $redis->setex($prevZone . ':available_drivers', 120, (string)max(0, (int)$redis->sCard($prevCell)));
        }

        if (is_string($prevCellCity) && $prevCellCity !== '' && $prevCellCity !== $newCellCity) {
            $redis->sRem($prevCellCity, (string)$driverId);
        }

        if (is_string($prevCity) && is_numeric($prevCity) && (int)$prevCity !== $cityId) {
            $redis->sRem(self::availableSetKey((int)$prevCity), (string)$driverId);
        }

        $redis->sAdd($newCell, (string)$driverId);
        $redis->sAdd($newCellCity, (string)$driverId);
        $redis->sAdd(self::AVAILABLE_KEY, (string)$driverId);
        $redis->sAdd(self::availableSetKey($cityId), (string)$driverId);
        $redis->set($prevCellKey, $newCell);
        $redis->set($prevCellCityKey, $newCellCity);
        $redis->set($prevCityKey, (string)$cityId);

        $zoneCell = str_replace('grid:', 'zone:', $newCell);
        $redis->setex($zoneCell . ':available_drivers', 120, (string)max(0, (int)$redis->sCard($newCell)));
    }

    private static function hasLiveHeartbeat($redis, int $driverId): bool
    {
        return (bool)$redis->exists(self::HEARTBEAT_PREFIX . $driverId);
    }

    private static function resolveDriverCityId($redis, int $driverId): ?int
    {
        $cityRaw = $redis->get(self::LAST_CITY_PREFIX . $driverId);
        if (is_string($cityRaw) && is_numeric($cityRaw)) {
            return (int)$cityRaw;
        }

        $rawLoc = $redis->get('drivers:location:' . $driverId);
        $loc = is_string($rawLoc) ? json_decode($rawLoc, true) : null;
        if (is_array($loc) && isset($loc['lat'], $loc['lng'])) {
            return self::getCityIdFromCoordinates((float)$loc['lat'], (float)$loc['lng']);
        }

        return null;
    }

    private static function gridCellId(float $lat, float $lng): string
    {
        [$x, $y] = self::gridCellXY($lat, $lng);
        return $x . ':' . $y;
    }

    /** @return array{0:int,1:int} */
    private static function gridCellXY(float $lat, float $lng): array
    {
        $latIndex = (int)floor($lat * self::GRID_FACTOR);
        $lngIndex = (int)floor($lng * self::GRID_FACTOR);
        return [$latIndex, $lngIndex];
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

/**
 * Facade de compatibilidad para llamadas futuras estilo DriverService::...
 */
class DriverService
{
    public static function getCityIdFromCoordinates(float $lat, float $lng): int
    {
        return DriverGeoService::getCityIdFromCoordinates($lat, $lng);
    }
}
