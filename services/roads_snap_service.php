<?php

require_once __DIR__ . '/../config/app.php';

class RoadsSnapService
{
    private const CACHE_TTL_SECONDS = 600;
    private const POINTS_WINDOW = 5;
    private const MAX_ROUTE_DEVIATION_METERS = 20.0;
    private const SNAP_THROTTLE_SECONDS = 3;

    public static function snapDriverPoint($redis, int $tripId, int $driverId, float $lat, float $lng): array
    {
        $cacheKey = self::cacheKey($lat, $lng);
        if ($redis) {
            try {
                $cached = $redis->get($cacheKey);
                $decoded = self::decodeJsonSafe($cached);
                if (is_array($decoded) && isset($decoded['lat'], $decoded['lng'])) {
                    return [floatval($decoded['lat']), floatval($decoded['lng']), 'cache'];
                }
            } catch (Throwable $e) {
                // Continue without cache.
            }
        }

        if ($redis && self::isDriverThrottled($redis, $driverId)) {
            $lastKey = 'roads_last_snap_point:' . $driverId;
            try {
                $lastRaw = $redis->get($lastKey);
                $last = self::decodeJsonSafe($lastRaw);
                if (is_array($last) && isset($last['lat'], $last['lng'])) {
                    return [floatval($last['lat']), floatval($last['lng']), 'throttle_reuse'];
                }
            } catch (Throwable $e) {
                // Continue with normal path.
            }
        }

        $points = self::rememberRecentPoint($redis, $driverId, $lat, $lng);
        $apiKey = self::resolveGoogleMapsApiKey();

        if ($apiKey !== '' && !empty($points)) {
            $snapped = self::callGoogleRoads($apiKey, $points);
            if ($snapped !== null) {
                [$snapLat, $snapLng] = $snapped;
                self::writeCache($redis, $cacheKey, $snapLat, $snapLng, 'google_roads');
                self::rememberLastSnap($redis, $driverId, $snapLat, $snapLng);
                return [$snapLat, $snapLng, 'roads_api'];
            }
        }

        $fallback = self::fallbackProjectToRoute($redis, $tripId, $lat, $lng);
        if ($fallback !== null) {
            [$projLat, $projLng] = $fallback;
            self::writeCache($redis, $cacheKey, $projLat, $projLng, 'route_projection');
            self::rememberLastSnap($redis, $driverId, $projLat, $projLng);
            return [$projLat, $projLng, 'route_projection'];
        }

        self::rememberLastSnap($redis, $driverId, $lat, $lng);

        return [$lat, $lng, $apiKey === '' ? 'raw_no_key' : 'raw_api_fail'];
    }

    private static function rememberRecentPoint($redis, int $driverId, float $lat, float $lng): array
    {
        if (!$redis) {
            return [[ 'lat' => $lat, 'lng' => $lng ]];
        }

        $listKey = 'driver:' . $driverId . ':roads_points';
        try {
            $redis->rPush($listKey, json_encode([
                'lat' => round($lat, 6),
                'lng' => round($lng, 6),
            ], JSON_UNESCAPED_UNICODE));
            $redis->lTrim($listKey, -self::POINTS_WINDOW, -1);
            $redis->expire($listKey, self::CACHE_TTL_SECONDS);

            $raw = $redis->lRange($listKey, 0, -1);
            $points = [];
            foreach ($raw as $item) {
                $decoded = self::decodeJsonSafe($item);
                if (is_array($decoded) && isset($decoded['lat'], $decoded['lng'])) {
                    $points[] = [
                        'lat' => floatval($decoded['lat']),
                        'lng' => floatval($decoded['lng']),
                    ];
                }
            }

            if (!empty($points)) {
                return $points;
            }
        } catch (Throwable $e) {
            // Ignore and fallback to single point.
        }

        return [[ 'lat' => $lat, 'lng' => $lng ]];
    }

    private static function isDriverThrottled($redis, int $driverId): bool
    {
        if (!$redis || $driverId <= 0) {
            return false;
        }

        $key = 'roads_last_snap:' . $driverId;
        try {
            $lastTs = intval($redis->get($key) ?: 0);
            $now = time();
            if ($lastTs > 0 && ($now - $lastTs) < self::SNAP_THROTTLE_SECONDS) {
                return true;
            }
            $redis->setex($key, self::CACHE_TTL_SECONDS, (string)$now);
        } catch (Throwable $e) {
            return false;
        }

        return false;
    }

    private static function rememberLastSnap($redis, int $driverId, float $lat, float $lng): void
    {
        if (!$redis || $driverId <= 0) {
            return;
        }

        try {
            $redis->setex('roads_last_snap_point:' . $driverId, self::CACHE_TTL_SECONDS, json_encode([
                'lat' => $lat,
                'lng' => $lng,
            ], JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            // Ignore secondary cache failures.
        }
    }

    private static function callGoogleRoads(string $apiKey, array $points): ?array
    {
        if (empty($points)) {
            return null;
        }

        $path = [];
        foreach ($points as $p) {
            $path[] = $p['lat'] . ',' . $p['lng'];
        }

        $url = 'https://roads.googleapis.com/v1/snapToRoads?path=' .
            rawurlencode(implode('|', $path)) .
            '&interpolate=true&key=' . rawurlencode($apiKey);

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 1.2,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        $decoded = self::decodeJsonSafe($raw);
        if (!is_array($decoded) || empty($decoded['snappedPoints']) || !is_array($decoded['snappedPoints'])) {
            return null;
        }

        $last = end($decoded['snappedPoints']);
        $loc = $last['location'] ?? null;
        if (!is_array($loc) || !isset($loc['latitude'], $loc['longitude'])) {
            return null;
        }

        return [floatval($loc['latitude']), floatval($loc['longitude'])];
    }

    private static function fallbackProjectToRoute($redis, int $tripId, float $lat, float $lng): ?array
    {
        if (!$redis) {
            return null;
        }

        try {
            $rawRoute = $redis->get('trip:' . $tripId . ':route_polyline');
            $route = self::decodeJsonSafe($rawRoute);
            if (!is_array($route) || count($route) < 2) {
                return null;
            }

            $point = [ 'lat' => $lat, 'lng' => $lng ];
            $best = null;
            $bestDistance = INF;

            for ($i = 0; $i < count($route) - 1; $i++) {
                $a = $route[$i];
                $b = $route[$i + 1];
                if (!isset($a['lat'], $a['lng'], $b['lat'], $b['lng'])) {
                    continue;
                }

                $proj = self::projectPointOnSegment($point, $a, $b);
                $d = self::haversineMeters($lat, $lng, $proj['lat'], $proj['lng']);
                if ($d < $bestDistance) {
                    $bestDistance = $d;
                    $best = $proj;
                }
            }

            if ($best === null) {
                return null;
            }

            if ($bestDistance > self::MAX_ROUTE_DEVIATION_METERS) {
                return null;
            }

            return [floatval($best['lat']), floatval($best['lng'])];
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function projectPointOnSegment(array $p, array $a, array $b): array
    {
        $ax = $a['lng'];
        $ay = $a['lat'];
        $bx = $b['lng'];
        $by = $b['lat'];
        $px = $p['lng'];
        $py = $p['lat'];

        $abx = $bx - $ax;
        $aby = $by - $ay;
        $ab2 = $abx * $abx + $aby * $aby;

        if ($ab2 <= 0.0) {
            return ['lat' => $ay, 'lng' => $ax];
        }

        $apx = $px - $ax;
        $apy = $py - $ay;
        $t = ($apx * $abx + $apy * $aby) / $ab2;
        if ($t < 0.0) $t = 0.0;
        if ($t > 1.0) $t = 1.0;

        return [
            'lat' => $ay + ($aby * $t),
            'lng' => $ax + ($abx * $t),
        ];
    }

    private static function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000.0;

        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLon = deg2rad($lon2 - $lon1);

        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
            cos($lat1Rad) * cos($lat2Rad) * sin($deltaLon / 2) * sin($deltaLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    private static function writeCache($redis, string $cacheKey, float $lat, float $lng, string $source): void
    {
        if (!$redis) {
            return;
        }

        try {
            $redis->setex($cacheKey, self::CACHE_TTL_SECONDS, json_encode([
                'lat' => $lat,
                'lng' => $lng,
                'source' => $source,
            ], JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            // Ignore cache errors.
        }
    }

    private static function resolveGoogleMapsApiKey(): string
    {
        $candidates = [
            'GOOGLE_MAPS_API_KEY',
            'GOOGLE_PLACES_API_KEY',
            'GOOGLE_API_KEY',
        ];

        foreach ($candidates as $name) {
            $value = trim((string) env_value($name, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function cacheKey(float $lat, float $lng): string
    {
        return 'roads_tile:' . round($lat, 4) . ':' . round($lng, 4);
    }

    private static function decodeJsonSafe($raw): ?array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
