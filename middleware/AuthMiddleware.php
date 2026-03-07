<?php
/**
 * Middleware de autenticación para rutas nuevas.
 */

class AuthMiddleware
{
    public static function handle(callable $next): void
    {
        // No modifica la auth actual; solo valida presencia de token.
        $token = Auth::bearerToken();
        if ($token === null || $token === '') {
            Response::error('No autorizado', 401, 'UNAUTHORIZED');
            return;
        }

        $next();
    }
}
