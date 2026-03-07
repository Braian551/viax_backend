<?php
/**
 * Middleware de rate limit por IP.
 */

class RateLimitMiddleware
{
    public static function handle(string $prefix, int $maxRequests, int $windowSeconds, callable $next): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = sprintf('rate_limit:%s:%s', $prefix, $ip);

        if (!RateLimiter::allow($key, $maxRequests, $windowSeconds)) {
            Response::error('Demasiadas solicitudes', 429, 'RATE_LIMIT');
            return;
        }

        $next();
    }
}
