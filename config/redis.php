<?php
/**
 * Configuración central de Redis para el backend.
 *
 * Esta capa permite activar Redis como cache sin volverlo obligatorio.
 * Si Redis no está instalado o falla, la aplicación sigue operando con BD.
 */

if (!function_exists('getRedisConfig')) {
    /**
     * Obtiene la configuración de Redis desde variables de entorno.
     *
     * Variables soportadas:
     * - REDIS_HOST (default: 127.0.0.1)
     * - REDIS_PORT (default: 6379)
     * - REDIS_TIMEOUT (default: 0.15 segundos)
     * - REDIS_PASSWORD (opcional)
     */
    function getRedisConfig(): array
    {
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);
        $timeout = (float) (getenv('REDIS_TIMEOUT') ?: 0.15);
        $password = getenv('REDIS_PASSWORD');

        return [
            'host' => $host,
            'port' => $port,
            'timeout' => $timeout,
            'password' => $password !== false ? (string) $password : null,
        ];
    }
}
