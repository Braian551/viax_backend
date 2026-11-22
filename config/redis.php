<?php

require_once __DIR__ . '/bootstrap.php';

/** Resolve Redis from the VIAX environment. Local defaults apply to development. */
function getRedisConfig(): ?array
{
    $production = strtolower((string)env_value('APP_ENV', 'development')) === 'production';
    $host = env_value('REDIS_HOST', $production ? null : '127.0.0.1');
    $port = filter_var(
        env_value('REDIS_PORT', $production ? null : '6379'),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 65535]]
    );
    $password = env_value('REDIS_PASSWORD');

    if (!$host || $port === false || ($production && !$password)) {
        return null;
    }

    return [
        'host' => (string)$host,
        'port' => $port,
        'timeout' => (float)env_value('REDIS_TIMEOUT', '0.15'),
        'password' => $password === null ? null : (string)$password,
    ];
}
