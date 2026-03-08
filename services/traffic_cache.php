<?php
/**
 * Capa de cache para trafico por par de zonas.
 *
 * Estrategia:
 * - Cache caliente de 5 minutos para minimizar llamadas a Google Routes.
 * - Cache historico de 24 horas para fallback ante errores de red/API.
 */

require_once __DIR__ . '/../config/app.php';

function trafficCacheTtlSeconds(): int
{
    return max(60, intval(env_value('TRAFFIC_CACHE_TTL', 300)));
}

function trafficHistoryTtlSeconds(): int
{
    return max(600, intval(env_value('TRAFFIC_HISTORY_TTL', 86400)));
}

function trafficCacheKey(string $zonePairKey): string
{
    return 'traffic:zone_pair:' . $zonePairKey;
}

function trafficHistoryKey(string $zonePairKey): string
{
    return 'traffic:zone_pair_history:' . $zonePairKey;
}

function trafficLockKey(string $zonePairKey): string
{
    return 'traffic:zone_pair_lock:' . $zonePairKey;
}

/**
 * Obtiene cache vigente (TTL vivo).
 */
function trafficCacheGetFresh(string $zonePairKey): ?array
{
    $raw = Cache::get(trafficCacheKey($zonePairKey));
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Obtiene cache historico (puede ser mas antiguo, pero util para fallback).
 */
function trafficCacheGetHistorical(string $zonePairKey): ?array
{
    $raw = Cache::get(trafficHistoryKey($zonePairKey));
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Guarda resultado en cache caliente y en cache historico.
 */
function trafficCacheStore(string $zonePairKey, array $payload): void
{
    $encoded = json_encode($payload);
    if (!is_string($encoded) || $encoded === '') {
        return;
    }

    Cache::set(trafficCacheKey($zonePairKey), $encoded, trafficCacheTtlSeconds());
    Cache::set(trafficHistoryKey($zonePairKey), $encoded, trafficHistoryTtlSeconds());
}

/**
 * Lock distribuido corto para evitar thundering herd por mismo par de zonas.
 */
function trafficCacheTryAcquireLock(string $zonePairKey, int $ttlSeconds = 15): bool
{
    try {
        $redis = Cache::redis();
        if (!$redis) {
            // Sin Redis no hay lock distribuido; permitimos continuar.
            return true;
        }

        $result = $redis->set(
            trafficLockKey($zonePairKey),
            '1',
            ['nx', 'ex' => max(3, $ttlSeconds)]
        );

        return $result === true || $result === 'OK';
    } catch (Throwable $e) {
        return true;
    }
}
