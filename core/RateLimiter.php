<?php
/**
 * Rate limiter simple por IP usando Redis.
 *
 * Si Redis no está disponible, permite el request para no romper producción.
 */

class RateLimiter
{
    /**
     * Permite o bloquea según cuota por ventana.
     */
    public static function allow(string $key, int $maxRequests, int $windowSeconds): bool
    {
        $r = Cache::redis();
        if (!$r) {
            return true;
        }

        try {
            $count = (int) $r->incr($key);
            if ($count === 1) {
                $r->expire($key, $windowSeconds);
            }
            return $count <= $maxRequests;
        } catch (Throwable $e) {
            return true;
        }
    }
}
