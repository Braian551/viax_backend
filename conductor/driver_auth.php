<?php
/**
 * Helper de sesión para endpoints de conductor.
 *
 * Objetivo:
 * - Evitar "ghost drivers" con sesión Redis de 12h.
 * - Mantener compatibilidad con clientes legacy (modo no estricto).
 */

require_once __DIR__ . '/../config/app.php';

const DRIVER_SESSION_TTL_SEC = 43200; // 12 horas

/**
 * Lee un posible token de sesión desde headers o payload.
 */
function driverSessionTokenFromRequest(?array $payload = null): ?string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $token = $headers['X-Driver-Session'] ?? $headers['x-driver-session'] ?? null;

    if ((!is_string($token) || trim($token) === '') && is_array($payload)) {
        $token = $payload['driver_session'] ?? null;
    }

    $token = is_string($token) ? trim($token) : '';
    return $token !== '' ? $token : null;
}

/**
 * Refresca o crea sesión de conductor en Redis.
 */
function touchDriverSession(int $driverId, ?string $sessionToken = null): string
{
    $redis = Cache::redis();
    $key = 'driver:session:' . $driverId;

    $token = $sessionToken;
    if ($token === null || $token === '') {
        $token = 'sess_' . $driverId . '_' . bin2hex(random_bytes(8));
    }

    if ($redis) {
        $redis->setex($key, DRIVER_SESSION_TTL_SEC, $token);
    }

    return $token;
}

/**
 * Valida sesión del conductor.
 *
 * - strict=true: exige sesión válida previamente creada.
 * - strict=false: bootstrap para clientes legacy sin romper compatibilidad.
 *
 * @return array{ok:bool,message:string,session_token:?string}
 */
function validateDriverSession(int $driverId, ?string $sessionToken = null, bool $strict = false): array
{
    if ($driverId <= 0) {
        return [
            'ok' => false,
            'message' => 'ID de conductor inválido',
            'session_token' => null,
        ];
    }

    $redis = Cache::redis();
    $key = 'driver:session:' . $driverId;

    if (!$redis) {
        // Fallback defensivo: no bloquear operación por indisponibilidad temporal de Redis.
        return [
            'ok' => true,
            'message' => 'Redis no disponible; validación degradada',
            'session_token' => $sessionToken,
        ];
    }

    $current = $redis->get($key);
    if (!is_string($current) || trim($current) === '') {
        if ($strict) {
            return [
                'ok' => false,
                'message' => 'Sesión de conductor no válida o expirada',
                'session_token' => null,
            ];
        }

        $newToken = touchDriverSession($driverId, $sessionToken);
        return [
            'ok' => true,
            'message' => 'Sesión inicializada',
            'session_token' => $newToken,
        ];
    }

    if (is_string($sessionToken) && trim($sessionToken) !== '' && hash_equals($current, $sessionToken) === false) {
        return [
            'ok' => false,
            'message' => 'Token de sesión inválido',
            'session_token' => null,
        ];
    }

    $redis->expire($key, DRIVER_SESSION_TTL_SEC);

    return [
        'ok' => true,
        'message' => 'Sesión válida',
        'session_token' => $current,
    ];
}
