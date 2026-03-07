<?php
/**
 * Utilidades de autenticación reutilizables.
 *
 * Esta versión no altera la lógica actual; solo extrae helpers comunes.
 */

class Auth
{
    /**
     * Obtiene token Bearer del header Authorization.
     */
    public static function bearerToken(): ?string
    {
        $header = Request::header('Authorization');
        if (!$header || stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));
        return self::isPlausibleToken($token) ? $token : null;
    }

    /**
     * Validación básica del formato del token para evitar basura/inyección.
     */
    public static function isPlausibleToken(?string $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }
        if (strlen($token) < 16 || strlen($token) > 512) {
            return false;
        }
        return (bool) preg_match('/^[A-Za-z0-9\-\._~\+\/]+=*$/', $token);
    }

    /**
     * Obtiene sesión desde Redis: user_session:{token}.
     *
     * Redis es opcional; si no está disponible retorna null sin romper flujo.
     */
    public static function getSessionFromCache(string $token): ?array
    {
        if (!self::isPlausibleToken($token) || !class_exists('Cache')) {
            return null;
        }

        $raw = Cache::get('user_session:' . $token);
        if (!$raw) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Guarda sesión mínima en Redis para accesos repetidos de baja latencia.
     */
    public static function putSessionInCache(string $token, array $session, int $ttlSeconds = 3600): bool
    {
        if (!self::isPlausibleToken($token) || !class_exists('Cache')) {
            return false;
        }

        return Cache::set('user_session:' . $token, (string) json_encode($session), $ttlSeconds);
    }
}
