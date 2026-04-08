<?php
/**
 * Rate limiter simple por IP usando Redis.
 *
 * Si Redis no está disponible, permite el request para no romper producción.
 */
require_once __DIR__ . '/Cache.php';

class RateLimiter
{
    /** @var array<string,array{count:int,expires_at:int}> */
    private static array $fallbackCounters = [];
    private static int $lastRedisWarningAt = 0;

    /**
     * Permite o bloquea según cuota por ventana.
     */
    public static function allow(string $key, int $maxRequests, int $windowSeconds): bool
    {
        $r = Cache::redis();
        if (!$r) {
            self::logRedisUnavailable('redis_unavailable');
            return self::allowWithLocalFallback($key, $maxRequests, $windowSeconds);
        }

        try {
            $count = (int) $r->incr($key);
            if ($count === 1) {
                $r->expire($key, $windowSeconds);
            }
            return $count <= $maxRequests;
        } catch (Throwable $e) {
            self::logRedisUnavailable('redis_exception', $e->getMessage());
            return self::allowWithLocalFallback($key, $maxRequests, $windowSeconds);
        }
    }

    /**
     * Alias semantico para limite global por identidad.
     *
     * @param string|int $identity
     */
    public static function check($identity, int $maxRequests = 100, int $windowSeconds = 60, string $scope = 'global'): bool
    {
        $normalized = trim((string)$identity);
        if ($normalized === '') {
            $normalized = 'anonymous';
        }

        $key = 'rate_limit:' . $scope . ':' . $normalized;
        return self::allow($key, $maxRequests, $windowSeconds);
    }

    private static function allowWithLocalFallback(string $key, int $maxRequests, int $windowSeconds): bool
    {
        $now = time();
        $safeMax = max(1, $maxRequests);
        $safeWindow = max(10, $windowSeconds);

        if (!isset(self::$fallbackCounters[$key]) || self::$fallbackCounters[$key]['expires_at'] <= $now) {
            self::$fallbackCounters[$key] = [
                'count' => 1,
                'expires_at' => $now + $safeWindow,
            ];
            return true;
        }

        self::$fallbackCounters[$key]['count']++;
        return self::$fallbackCounters[$key]['count'] <= $safeMax;
    }

    private static function logRedisUnavailable(string $event, ?string $details = null): void
    {
        $now = time();
        // Evita inundar logs cuando Redis esté intermitente.
        if (($now - self::$lastRedisWarningAt) < 60) {
            return;
        }
        self::$lastRedisWarningAt = $now;

        $payload = [
            'component' => 'RateLimiter',
            'event' => $event,
            'fallback' => 'local_memory',
        ];
        if ($details !== null && $details !== '') {
            $payload['details'] = substr($details, 0, 180);
        }

        error_log('[security][rate_limiter] ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
