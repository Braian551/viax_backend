<?php
/**
 * Motor de pricing dinámico.
 */

require_once __DIR__ . '/../config/app.php';

class DynamicPricingService
{
    public static function nowColombia(): DateTime
    {
        if (function_exists('now_colombia')) {
            return now_colombia();
        }

        return new DateTime('now', new DateTimeZone('America/Bogota'));
    }

    public static function isNightTime(DateTimeInterface $dateTime): bool
    {
        $hour = (int)$dateTime->format('H');
        return $hour >= 21 || $hour < 6;
    }

    public static function calculate(array $params): array
    {
        $distanceKm = max(0.0, (float)($params['distance_km'] ?? 0));
        $timeMin = max(0.0, (float)($params['time_min'] ?? 0));

        $baseFare = (float)($params['base_fare'] ?? 5000.0);
        $perKmRate = (float)($params['per_km_rate'] ?? 1800.0);
        $perMinRate = (float)($params['per_min_rate'] ?? 250.0);

        $avgSpeed = max(5.0, (float)($params['avg_speed_kmh'] ?? 28.0));
        $currentSpeed = max(5.0, (float)($params['current_speed_kmh'] ?? $avgSpeed));
        $trafficFactor = min(2.5, max(1.0, $avgSpeed / $currentSpeed));

        $zoneKey = self::zoneKey((float)($params['lat'] ?? 0), (float)($params['lng'] ?? 0));
        $surge = self::getSurge($zoneKey);
        $nowColombia = self::nowColombia();
        $isNight = self::isNightTime($nowColombia);
        $nightMultiplier = (float)($params['night_multiplier'] ?? env_value('PRICING_NIGHT_MULTIPLIER', '1.0'));
        if ($nightMultiplier < 1.0) {
            $nightMultiplier = 1.0;
        }
        $nightFactor = $isNight ? $nightMultiplier : 1.0;

        $basePrice = $baseFare + ($distanceKm * $perKmRate) + ($timeMin * $perMinRate);
        $finalPrice = round($basePrice * $surge * $trafficFactor * $nightFactor, 2);

        return [
            'base_price' => round($basePrice, 2),
            'traffic_factor' => round($trafficFactor, 4),
            'surge' => round($surge, 4),
            'night_factor' => round($nightFactor, 4),
            'is_night' => $isNight,
            'colombia_time' => $nowColombia->format('c'),
            'final_price' => $finalPrice,
            'zone_key' => $zoneKey,
        ];
    }

    public static function updateZoneDemand(string $zoneKey, int $activeRequests, int $availableDrivers): float
    {
        $availableDrivers = max(1, $availableDrivers);
        $surge = (float)$activeRequests / (float)$availableDrivers;
        $surge = min(3.0, max(1.0, $surge));

        Cache::set('surge_zone:' . $zoneKey, (string)$surge, 30);
        return $surge;
    }

    public static function zoneKey(float $lat, float $lng): string
    {
        $latIndex = (int)floor($lat * 100);
        $lngIndex = (int)floor($lng * 100);
        return 'zone:' . $latIndex . ':' . $lngIndex;
    }

    private static function getSurge(string $zoneKey): float
    {
        $cached = Cache::get('surge_zone:' . $zoneKey);
        if (is_string($cached) && is_numeric($cached)) {
            return min(3.0, max(1.0, (float)$cached));
        }

        return 1.0;
    }
}
