<?php
/**
 * Motor de pricing dinámico.
 */

require_once __DIR__ . '/../config/app.php';

class DynamicPricingService
{
    private const MIN_SURGE = 1.0;
    private const MAX_SURGE = 2.0;
    private const DEFAULT_MIN_ACTIVE_REQUESTS = 5;
    private const DEFAULT_COOLDOWN_SECONDS = 45;

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
        $normalizedZoneKey = trim($zoneKey);
        if ($normalizedZoneKey === '') {
            return self::MIN_SURGE;
        }

        $active = max(0, $activeRequests);
        $available = max(0, $availableDrivers);

        $cooldown = self::surgeCooldownSeconds();
        $metaKey = 'surge_zone_meta:' . $normalizedZoneKey;
        $cachedMetaRaw = Cache::get($metaKey);
        $cachedMeta = is_string($cachedMetaRaw) ? json_decode($cachedMetaRaw, true) : null;
        $nowTs = time();

        if (is_array($cachedMeta) && isset($cachedMeta['updated_at'])) {
            $lastUpdate = (int)$cachedMeta['updated_at'];
            if ($lastUpdate > 0 && ($nowTs - $lastUpdate) < $cooldown) {
                $cachedMultiplier = isset($cachedMeta['multiplier']) ? (float)$cachedMeta['multiplier'] : self::getSurge($normalizedZoneKey);
                return self::normalizeMultiplier($cachedMultiplier);
            }
        }

        $ratio = $available > 0 ? ((float)$active / (float)$available) : ($active > 0 ? 99.0 : 0.0);
        $target = self::resolveSurgeTarget($ratio, $active, $available);
        $previous = self::getSurge($normalizedZoneKey);
        $smoothed = self::smoothSurge($previous, $target);

        $payload = [
            'active_requests' => $active,
            'available_drivers' => $available,
            'ratio' => round($ratio, 4),
            'target' => round($target, 2),
            'previous' => round($previous, 2),
            'multiplier' => round($smoothed, 2),
            'updated_at' => $nowTs,
            'cooldown_seconds' => $cooldown,
            'level' => self::demandLevel($smoothed),
            'message' => self::demandMessage($smoothed),
        ];

        Cache::set('surge_zone:' . $normalizedZoneKey, (string)$smoothed, $cooldown * 2);
        Cache::set($metaKey, json_encode($payload, JSON_UNESCAPED_UNICODE), $cooldown * 2);

        return $smoothed;
    }

    /**
     * Registra una solicitud reciente en Redis para métricas de demanda por zona.
     */
    public static function registerRequestInZone(string $zoneKey, ?int $requestId = null): void
    {
        $normalizedZoneKey = trim($zoneKey);
        if ($normalizedZoneKey === '') {
            return;
        }

        $redis = Cache::redis();
        if (!$redis) {
            return;
        }

        $nowMs = (int)round(microtime(true) * 1000);
        $safeRequestId = $requestId !== null && $requestId > 0 ? $requestId : 0;
        $member = ($safeRequestId > 0 ? ('req:' . $safeRequestId) : 'req')
            . ':' . $nowMs
            . ':' . substr(str_replace('.', '', uniqid('', true)), -8);

        $recentKey = $normalizedZoneKey . ':recent_requests';
        $recentCountKey = $normalizedZoneKey . ':recent_requests_count';
        $activeKey = $normalizedZoneKey . ':active_requests';
        $windowMs = 5 * 60 * 1000;

        try {
            $redis->zAdd($recentKey, $nowMs, $member);
            $redis->zRemRangeByScore($recentKey, 0, $nowMs - $windowMs);
            $redis->expire($recentKey, 900);

            $recentCount = (int)$redis->zCount($recentKey, $nowMs - $windowMs, $nowMs);
            $redis->setex($recentCountKey, 120, (string)max(0, $recentCount));

            $currentActiveRaw = $redis->get($activeKey);
            $currentActive = (is_string($currentActiveRaw) && is_numeric($currentActiveRaw))
                ? (int)$currentActiveRaw
                : 0;
            $redis->setex($activeKey, 120, (string)max($currentActive, $recentCount));
        } catch (Throwable $e) {
            error_log('[DynamicPricingService] registerRequestInZone warning: ' . $e->getMessage());
        }
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
            return self::normalizeMultiplier((float)$cached);
        }

        return self::MIN_SURGE;
    }

    /**
     * Determina el objetivo de surge según ratio oferta/demanda.
     */
    public static function resolveSurgeTarget(float $ratio, int $activeRequests, int $availableDrivers): float
    {
        $minActive = self::minActiveRequestsForSurge();
        if ($activeRequests < $minActive) {
            return self::MIN_SURGE;
        }

        if ($availableDrivers > $activeRequests) {
            return self::MIN_SURGE;
        }

        if ($ratio <= 1.0) {
            return self::MIN_SURGE;
        }

        foreach (self::surgeScaleTable() as $entry) {
            $threshold = (float)($entry['ratio'] ?? 0.0);
            $multiplier = self::normalizeMultiplier((float)($entry['multiplier'] ?? self::MIN_SURGE));
            if ($ratio < $threshold) {
                return $multiplier;
            }
        }

        return self::MAX_SURGE;
    }

    /**
     * Suaviza cambios bruscos para evitar saltos de precio.
     */
    public static function smoothSurge(float $previous, float $current, float $weightPrev = 0.7, float $weightCurrent = 0.3): float
    {
        $prev = self::normalizeMultiplier($previous);
        $curr = self::normalizeMultiplier($current);

        $wp = max(0.0, min(1.0, $weightPrev));
        $wc = max(0.0, min(1.0, $weightCurrent));
        $sum = $wp + $wc;
        if ($sum <= 0.0) {
            return $curr;
        }

        $smoothed = (($prev * $wp) + ($curr * $wc)) / $sum;
        if ($curr <= self::MIN_SURGE && $smoothed < 1.03) {
            return self::MIN_SURGE;
        }

        return self::normalizeMultiplier($smoothed);
    }

    /**
     * Nivel semántico de demanda para UX.
     */
    public static function demandLevel(float $multiplier): string
    {
        $m = self::normalizeMultiplier($multiplier);
        if ($m >= 1.55) {
            return 'alta';
        }
        if ($m >= 1.30) {
            return 'media';
        }
        if ($m > 1.00) {
            return 'leve';
        }
        return 'normal';
    }

    /**
     * Mensaje legible para mostrar al usuario final.
     */
    public static function demandMessage(float $multiplier): string
    {
        $level = self::demandLevel($multiplier);
        if ($level === 'alta') {
            return 'Alta demanda, pocos conductores disponibles';
        }
        if ($level === 'media') {
            return 'Alta demanda en la zona';
        }
        if ($level === 'leve') {
            return 'Demanda ligeramente alta';
        }
        return '';
    }

    private static function normalizeMultiplier(float $multiplier): float
    {
        return min(self::MAX_SURGE, max(self::MIN_SURGE, round($multiplier, 2)));
    }

    private static function minActiveRequestsForSurge(): int
    {
        $value = env_value('SURGE_MIN_ACTIVE_REQUESTS', (string)self::DEFAULT_MIN_ACTIVE_REQUESTS);
        $parsed = intval($value);
        return max(1, $parsed);
    }

    private static function surgeCooldownSeconds(): int
    {
        $value = env_value('SURGE_COOLDOWN_SECONDS', (string)self::DEFAULT_COOLDOWN_SECONDS);
        $parsed = intval($value);
        return max(30, min(120, $parsed));
    }

    /**
     * Tabla progresiva configurable por entorno.
     *
     * Formato JSON esperado en SURGE_SCALE_JSON:
     * [
     *   {"ratio":1.1,"multiplier":1.2},
     *   {"ratio":1.3,"multiplier":1.4},
     *   {"ratio":1.5,"multiplier":1.6},
     *   {"ratio":2.0,"multiplier":2.0}
     * ]
     *
     * Se interpreta como: si ratio < threshold aplica ese multiplicador.
     */
    private static function surgeScaleTable(): array
    {
        $defaultTable = [
            ['ratio' => 1.1, 'multiplier' => 1.2],
            ['ratio' => 1.3, 'multiplier' => 1.4],
            ['ratio' => 1.5, 'multiplier' => 1.6],
            ['ratio' => 2.0, 'multiplier' => 2.0],
        ];

        $raw = env_value('SURGE_SCALE_JSON', '');
        if (!is_string($raw) || trim($raw) === '') {
            return $defaultTable;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded)) {
            return $defaultTable;
        }

        $table = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $ratio = isset($entry['ratio']) ? (float)$entry['ratio'] : 0.0;
            $multiplier = isset($entry['multiplier']) ? (float)$entry['multiplier'] : self::MIN_SURGE;
            if ($ratio <= 0.0) {
                continue;
            }

            $table[] = [
                'ratio' => $ratio,
                'multiplier' => self::normalizeMultiplier($multiplier),
            ];
        }

        if (empty($table)) {
            return $defaultTable;
        }

        usort($table, static function (array $a, array $b): int {
            return ($a['ratio'] <=> $b['ratio']);
        });

        return $table;
    }
}
