<?php
/**
 * Motor ETA con cache + corrección histórica.
 */

require_once __DIR__ . '/../config/app.php';

class EtaService
{
    public static function estimate(
        float $originLat,
        float $originLng,
        float $destLat,
        float $destLng,
        ?string $trafficLevel = null,
        ?float $historicalSpeedKmh = null
    ): array {
        $originHash = substr(sha1(round($originLat, 4) . ',' . round($originLng, 4)), 0, 12);
        $destHash = substr(sha1(round($destLat, 4) . ',' . round($destLng, 4)), 0, 12);
        $cacheKey = 'eta_cache:' . $originHash . ':' . $destHash;

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && trim($cached) !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                $decoded['source'] = 'cache';
                return $decoded;
            }
        }

        $distanceKm = self::haversineKm($originLat, $originLng, $destLat, $destLng);
        $routeDurationSec = self::googleRoutesDuration($originLat, $originLng, $destLat, $destLng);

        $hour = (int)date('G');
        $segment = substr(sha1(round($originLat, 3) . ',' . round($originLng, 3) . '|' . round($destLat, 3) . ',' . round($destLng, 3)), 0, 12);
        $roadSpeedKey = 'road_speed:' . $segment . ':' . $hour;

        $historicalKmh = $historicalSpeedKmh;
        if ($historicalKmh === null) {
            $histRaw = Cache::get($roadSpeedKey);
            if (is_string($histRaw) && is_numeric($histRaw)) {
                $historicalKmh = max(5.0, (float)$histRaw);
            }
        }

        $fallbackKmh = $historicalKmh ?? self::defaultSpeedByTraffic($trafficLevel ?? 'normal');
        $fallbackDurationSec = (int)round(($distanceKm / max(5.0, $fallbackKmh)) * 3600);

        $etaSec = $routeDurationSec > 0 ? $routeDurationSec : $fallbackDurationSec;
        if ($historicalKmh !== null && $routeDurationSec > 0) {
            $routeKmh = ($distanceKm > 0 && $routeDurationSec > 0)
                ? ($distanceKm / ($routeDurationSec / 3600.0))
                : $historicalKmh;
            $correction = $routeKmh > 0 ? ($historicalKmh / $routeKmh) : 1.0;
            $correction = min(1.35, max(0.75, $correction));
            $etaSec = (int)round($routeDurationSec * $correction);
        }

        $payload = [
            'eta_seconds' => max(30, $etaSec),
            'distance_km' => round($distanceKm, 3),
            'traffic_level' => $trafficLevel ?? 'normal',
            'historical_speed_kmh' => $historicalKmh,
            'source' => $routeDurationSec > 0 ? 'google_routes' : 'fallback_speed',
            'created_at' => gmdate('c'),
        ];

        Cache::set($cacheKey, (string)json_encode($payload, JSON_UNESCAPED_UNICODE), 60);
        return $payload;
    }

    private static function googleRoutesDuration(float $oLat, float $oLng, float $dLat, float $dLng): int
    {
        $apiKey = trim((string)env_value('GOOGLE_MAPS_API_KEY', ''));
        if ($apiKey === '') {
            $apiKey = trim((string)env_value('GOOGLE_API_KEY', ''));
        }
        if ($apiKey === '') {
            return 0;
        }

        $url = 'https://routes.googleapis.com/directions/v2:computeRoutes';
        $body = [
            'origin' => ['location' => ['latLng' => ['latitude' => $oLat, 'longitude' => $oLng]]],
            'destination' => ['location' => ['latLng' => ['latitude' => $dLat, 'longitude' => $dLng]]],
            'travelMode' => 'DRIVE',
            'routingPreference' => 'TRAFFIC_AWARE',
        ];

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n" .
                    'X-Goog-Api-Key: ' . $apiKey . "\r\n" .
                    "X-Goog-FieldMask: routes.duration\r\n",
                'content' => json_encode($body, JSON_UNESCAPED_UNICODE),
                'timeout' => 1.8,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return 0;
        }

        $durationRaw = $decoded['routes'][0]['duration'] ?? null;
        if (!is_string($durationRaw) || !str_ends_with($durationRaw, 's')) {
            return 0;
        }

        return (int)round((float)substr($durationRaw, 0, -1));
    }

    private static function defaultSpeedByTraffic(string $trafficLevel): float
    {
        $normalized = strtolower(trim($trafficLevel));
        return match ($normalized) {
            'heavy' => 18.0,
            'moderate' => 26.0,
            default => 34.0,
        };
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
