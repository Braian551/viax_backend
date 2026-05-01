<?php
/**
 * Cache de aplicación con fallback seguro.
 *
 * Redis se usa como acelerador de lectura/escritura. Si no está disponible,
 * los métodos fallan en silencio controlado para no romper el flujo principal.
 */

class Cache
{
    private static ?object $redis = null;
    private static bool $attempted = false;

    /** Intenta abrir conexión a Redis una sola vez por request. */
    public static function redis(): ?object
    {
        if (self::$attempted) {
            return self::$redis;
        }
        self::$attempted = true;

        if (!class_exists('Redis')) {
            return null;
        }

        $cfg = function_exists('getRedisConfig') ? getRedisConfig() : [
            'host' => '127.0.0.1',
            'port' => 6379,
            'timeout' => 0.15,
            'password' => null,
        ];

        try {
            $redisClass = 'Redis';
            $client = new $redisClass();
            $ok = $client->connect($cfg['host'], (int) $cfg['port'], (float) $cfg['timeout']);
            if (!$ok) {
                return null;
            }

            if (!empty($cfg['password'])) {
                $client->auth((string) $cfg['password']);
            }

            self::$redis = $client;
            return self::$redis;
        } catch (Throwable $e) {
            error_log('Cache Redis warning: ' . $e->getMessage());
            return null;
        }
    }

    /** Obtiene valor por clave. */
    public static function get(string $key): mixed
    {
        $r = self::redis();
        if (!$r) {
            return null;
        }

        try {
            $value = $r->get($key);
            return $value === false ? null : $value;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Guarda valor serializado en Redis con TTL opcional. */
    public static function set(string $key, string $value, int $ttlSeconds = 0): bool
    {
        $r = self::redis();
        if (!$r) {
            return false;
        }

        try {
            if ($ttlSeconds > 0) {
                return (bool) $r->setex($key, $ttlSeconds, $value);
            }
            return (bool) $r->set($key, $value);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Agrega miembro a un set. */
    public static function sAdd(string $key, string $member): bool
    {
        $r = self::redis();
        if (!$r) {
            return false;
        }

        try {
            return (bool) $r->sAdd($key, $member);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Lista miembros de un set. */
    public static function sMembers(string $key): array
    {
        $r = self::redis();
        if (!$r) {
            return [];
        }

        try {
            $values = $r->sMembers($key);
            return is_array($values) ? $values : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Elimina una clave de Redis. */
    public static function delete(string $key): bool
    {
        $r = self::redis();
        if (!$r) {
            return false;
        }

        try {
            return (int)$r->del($key) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}
