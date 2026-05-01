<?php
/**
 * Middleware de autenticación para rutas nuevas.
 */

class AuthMiddleware
{
    public static function handle(callable $next): void
    {
        // No modifica la auth actual; valida token + rate limit global.
        $token = Auth::bearerToken();
        if ($token === null || $token === '') {
            Response::error('No autorizado', 401, 'UNAUTHORIZED');
            return;
        }

        $session = Auth::getSessionFromCache($token);
        $identity = is_array($session) && isset($session['user_id'])
            ? (string)$session['user_id']
            : (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        if (class_exists('RateLimiter') && !RateLimiter::check($identity, 100, 60, 'auth_middleware')) {
            Response::error('Demasiadas solicitudes', 429, 'RATE_LIMIT_EXCEEDED');
            return;
        }

        $next();
    }
}
