<?php
/**
 * Servicio de búsqueda de lugares con cache Redis + merge inteligente.
 *
 * Orden de resultados:
 * 1) Búsquedas recientes del usuario.
 * 2) Resultados de Google Places.
 *
 * Reglas:
 * - Elimina duplicados por place_id.
 * - Limita a 10 resultados totales.
 * - Cachea resultados de Google por 5 minutos para reducir costo.
 */

require_once __DIR__ . '/../config/app.php';

class PlacesSearchService
{
    private const CACHE_TTL_SEC = 300;
    private const CACHE_PREFIX = 'places:search:';
    private const FREQUENT_MIN_TRIPS = 5;
    private const FREQUENT_MIN_SPAN_DAYS = 7;
    private const FREQUENT_MIN_UNIQUE_DAYS = 2;
    private const FREQUENT_SCORE_THRESHOLD = 0.22;
    private const FREQUENT_MAX_RECENCY_DAYS = 60.0;

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function searchWithRecent(PDO $db, int $userId, string $query, ?float $lat = null, ?float $lng = null, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            return $userId > 0 ? self::getRecentSearches($db, $userId, $limit) : [];
        }

        $recent = $userId > 0 ? self::searchRecentByText($db, $userId, $query, $limit) : [];
        $google = self::searchGooglePlacesCached($query, $lat, $lng, $limit);

        $merged = [];
        $seen = [];

        foreach ([$recent, $google] as $group) {
            foreach ($group as $item) {
                $placeId = trim((string)($item['place_id'] ?? ''));
                if ($placeId === '') {
                    $placeId = md5(strtolower(trim((string)($item['address'] ?? ''))) . '|' . round((float)($item['lat'] ?? 0), 5) . '|' . round((float)($item['lng'] ?? 0), 5));
                }

                if (isset($seen[$placeId])) {
                    continue;
                }

                $seen[$placeId] = true;
                $merged[] = $item;
                if (count($merged) >= $limit) {
                    break 2;
                }
            }
        }

        return $merged;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function getRecentSearches(PDO $db, int $userId, int $limit = 10): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stmt = $db->prepare(
            "SELECT id, place_name, place_address, place_lat, place_lng, place_id, created_at
             FROM recent_searches
             WHERE user_id = :user_id
             ORDER BY created_at DESC
             LIMIT :lim"
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', max(1, min(50, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $items = array_map(static function (array $r): array {
            return [
                'id' => (int)$r['id'],
                'name' => (string)$r['place_name'],
                'address' => (string)$r['place_address'],
                'lat' => (float)$r['place_lat'],
                'lng' => (float)$r['place_lng'],
                'place_id' => $r['place_id'] ?? null,
                'source' => 'recent',
                'created_at' => $r['created_at'] ?? null,
            ];
        }, $rows);

        $frequentStats = self::buildFrequentDestinationStats($db, $userId);
        if (empty($frequentStats['geo']) && empty($frequentStats['address'])) {
            return $items;
        }

        foreach ($items as $index => $item) {
            $items[$index] = self::attachFrequentDestinationMeta($item, $frequentStats);
        }

        return $items;
    }

    /**
     * @return array{geo: array<string,array<string,mixed>>, address: array<string,array<string,mixed>>}
     */
    private static function buildFrequentDestinationStats(PDO $db, int $userId): array
    {
        $statsByGeo = [];
        $statsByAddress = [];

        if ($userId <= 0) {
            return ['geo' => $statsByGeo, 'address' => $statsByAddress];
        }

        $stmt = $db->prepare(
            "SELECT
                ROUND(CAST(latitud_destino AS numeric), 3) AS lat_bucket,
                ROUND(CAST(longitud_destino AS numeric), 3) AS lng_bucket,
                LOWER(TRIM(COALESCE(direccion_destino, ''))) AS direccion_norm,
                COUNT(*) AS total_viajes,
                COUNT(DISTINCT DATE((COALESCE(completado_en, fecha_creacion) AT TIME ZONE 'America/Bogota'))) AS dias_unicos,
                EXTRACT(EPOCH FROM (MAX(COALESCE(completado_en, fecha_creacion)) - MIN(COALESCE(completado_en, fecha_creacion)))) / 86400.0 AS span_dias,
                EXTRACT(EPOCH FROM (NOW() - MAX(COALESCE(completado_en, fecha_creacion)))) / 86400.0 AS dias_desde_ultimo
            FROM solicitudes_servicio
            WHERE cliente_id = :user_id
              AND LOWER(TRIM(COALESCE(estado, ''))) IN (
                'completada',
                'completado',
                'entregado',
                'finalizada',
                'finalizado'
              )
              AND latitud_destino IS NOT NULL
              AND longitud_destino IS NOT NULL
            GROUP BY lat_bucket, lng_bucket, direccion_norm"
        );
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || empty($rows)) {
            return ['geo' => $statsByGeo, 'address' => $statsByAddress];
        }

        foreach ($rows as $row) {
            $totalTrips = max(0, (int)($row['total_viajes'] ?? 0));
            if ($totalTrips <= 0) {
                continue;
            }

            $uniqueDays = max(0, (int)($row['dias_unicos'] ?? 0));
            $spanDays = max(0.0, (float)($row['span_dias'] ?? 0.0));
            $daysSinceLastTrip = max(0.0, (float)($row['dias_desde_ultimo'] ?? 0.0));

            $frequency = min(1.0, $totalTrips / 10.0);
            $recency = self::computeRecencyScore($daysSinceLastTrip);
            $consistency = $totalTrips > 0
                ? min(1.0, $uniqueDays / max(2.0, min(7.0, (float)$totalTrips)))
                : 0.0;
            $score = $frequency * $recency * $consistency;

            $isFrequent =
                $totalTrips >= self::FREQUENT_MIN_TRIPS
                && $spanDays >= self::FREQUENT_MIN_SPAN_DAYS
                && $uniqueDays >= self::FREQUENT_MIN_UNIQUE_DAYS
                && $consistency >= 0.35
                && $score > self::FREQUENT_SCORE_THRESHOLD;

            $payload = [
                'is_frequent_destination' => $isFrequent,
                'score' => round($score, 6),
                'frequency' => round($frequency, 6),
                'recency' => round($recency, 6),
                'consistency' => round($consistency, 6),
                'total_viajes' => $totalTrips,
                'dias_unicos' => $uniqueDays,
                'span_dias' => round($spanDays, 2),
                'dias_desde_ultimo' => round($daysSinceLastTrip, 2),
                'threshold' => self::FREQUENT_SCORE_THRESHOLD,
            ];

            $geoKey = self::buildGeoKey((float)($row['lat_bucket'] ?? 0), (float)($row['lng_bucket'] ?? 0));
            if ($geoKey !== null) {
                $statsByGeo[$geoKey] = $payload;
            }

            $addressKey = self::normalizeAddressKey((string)($row['direccion_norm'] ?? ''));
            if ($addressKey !== '') {
                if (!isset($statsByAddress[$addressKey])) {
                    $statsByAddress[$addressKey] = $payload;
                } elseif (($payload['score'] ?? 0) > ($statsByAddress[$addressKey]['score'] ?? 0)) {
                    $statsByAddress[$addressKey] = $payload;
                }
            }
        }

        return ['geo' => $statsByGeo, 'address' => $statsByAddress];
    }

    private static function attachFrequentDestinationMeta(array $item, array $stats): array
    {
        $geoStats = is_array($stats['geo'] ?? null) ? $stats['geo'] : [];
        $addressStats = is_array($stats['address'] ?? null) ? $stats['address'] : [];

        $geoKey = self::buildGeoKey(
            isset($item['lat']) ? (float)$item['lat'] : null,
            isset($item['lng']) ? (float)$item['lng'] : null
        );
        $addressKey = self::normalizeAddressKey((string)($item['address'] ?? ''));

        $matched = null;
        if ($geoKey !== null && isset($geoStats[$geoKey])) {
            $matched = $geoStats[$geoKey];
        } elseif ($addressKey !== '' && isset($addressStats[$addressKey])) {
            $matched = $addressStats[$addressKey];
        }

        if (!is_array($matched)) {
            $item['is_frequent_destination'] = false;
            return $item;
        }

        $item['is_frequent_destination'] = (bool)($matched['is_frequent_destination'] ?? false);
        $item['frequent_score'] = round((float)($matched['score'] ?? 0.0), 4);
        $item['frequent_trip_count'] = (int)($matched['total_viajes'] ?? 0);
        $item['frequent_span_days'] = (float)($matched['span_dias'] ?? 0.0);
        $item['frequent_unique_days'] = (int)($matched['dias_unicos'] ?? 0);
        $item['frequent_threshold'] = self::FREQUENT_SCORE_THRESHOLD;
        $item['frequent_components'] = [
            'frequency' => round((float)($matched['frequency'] ?? 0.0), 4),
            'recency' => round((float)($matched['recency'] ?? 0.0), 4),
            'consistency' => round((float)($matched['consistency'] ?? 0.0), 4),
        ];

        if (!empty($item['is_frequent_destination'])) {
            $item['source'] = 'frequent';
        }

        return $item;
    }

    private static function computeRecencyScore(float $daysSinceLastTrip): float
    {
        $normalizedDays = min(self::FREQUENT_MAX_RECENCY_DAYS, max(0.0, $daysSinceLastTrip));
        $decay = $normalizedDays / self::FREQUENT_MAX_RECENCY_DAYS;
        return max(0.3, 1.0 - (0.7 * $decay));
    }

    private static function normalizeAddressKey(string $address): string
    {
        $normalized = strtolower(trim($address));
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        if (!is_string($normalized)) {
            return '';
        }
        return trim($normalized);
    }

    private static function buildGeoKey(?float $lat, ?float $lng): ?string
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        return number_format(round($lat, 3), 3, '.', '') . '|' . number_format(round($lng, 3), 3, '.', '');
    }

    public static function saveRecentSearch(PDO $db, int $userId, string $name, string $address, float $lat, float $lng, ?string $placeId = null): void
    {
        if ($userId <= 0 || trim($name) === '' || trim($address) === '') {
            return;
        }

        $db->beginTransaction();
        try {
            // Evitar duplicados inmediatos de misma ubicación.
            $deleteDup = $db->prepare(
                "DELETE FROM recent_searches
                 WHERE user_id = :user_id
                   AND (
                        (place_id IS NOT NULL AND place_id = :place_id)
                     OR (place_name = :name AND place_address = :address)
                   )"
            );
            $deleteDup->execute([
                ':user_id' => $userId,
                ':place_id' => $placeId,
                ':name' => $name,
                ':address' => $address,
            ]);

            $insert = $db->prepare(
                "INSERT INTO recent_searches (user_id, place_name, place_address, place_lat, place_lng, place_id, created_at)
                 VALUES (:user_id, :name, :address, :lat, :lng, :place_id, NOW())"
            );
            $insert->execute([
                ':user_id' => $userId,
                ':name' => $name,
                ':address' => $address,
                ':lat' => $lat,
                ':lng' => $lng,
                ':place_id' => $placeId,
            ]);

            // Mantener solo las últimas 10 búsquedas.
            $trim = $db->prepare(
                "DELETE FROM recent_searches
                 WHERE user_id = :user_id
                   AND id NOT IN (
                       SELECT id FROM recent_searches
                       WHERE user_id = :user_id
                       ORDER BY created_at DESC
                       LIMIT 10
                   )"
            );
            $trim->execute([':user_id' => $userId]);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function searchRecentByText(PDO $db, int $userId, string $query, int $limit = 10): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stmt = $db->prepare(
            "SELECT id, place_name, place_address, place_lat, place_lng, place_id, created_at
             FROM recent_searches
             WHERE user_id = :user_id
               AND (
                    LOWER(place_name) LIKE LOWER(:q)
                 OR LOWER(place_address) LIKE LOWER(:q)
               )
             ORDER BY created_at DESC
             LIMIT :lim"
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':q', '%' . $query . '%', PDO::PARAM_STR);
        $stmt->bindValue(':lim', max(1, min(30, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        return array_map(static function (array $r): array {
            return [
                'id' => (int)$r['id'],
                'name' => (string)$r['place_name'],
                'address' => (string)$r['place_address'],
                'lat' => (float)$r['place_lat'],
                'lng' => (float)$r['place_lng'],
                'place_id' => $r['place_id'] ?? null,
                'source' => 'recent',
                'created_at' => $r['created_at'] ?? null,
            ];
        }, $rows);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function searchGooglePlacesCached(string $query, ?float $lat, ?float $lng, int $limit = 10): array
    {
        $cacheKey = self::CACHE_PREFIX . md5(strtolower(trim($query)) . '|' . round((float)$lat, 4) . '|' . round((float)$lng, 4) . '|' . $limit);
        $redis = Cache::redis();

        if ($redis) {
            $cachedRaw = $redis->get($cacheKey);
            $cached = is_string($cachedRaw) ? json_decode($cachedRaw, true) : null;
            if (is_array($cached)) {
                return $cached;
            }
        }

        $apiKey = trim((string)env_value('GOOGLE_PLACES_API_KEY', env_value('GOOGLE_MAPS_API_KEY', env_value('GOOGLE_API_KEY', ''))));
        if ($apiKey === '') {
            return [];
        }

        $url = 'https://maps.googleapis.com/maps/api/place/autocomplete/json?input=' . urlencode($query) . '&language=es&components=country:co&key=' . urlencode($apiKey);
        if ($lat !== null && $lng !== null) {
            $url .= '&location=' . $lat . ',' . $lng . '&radius=50000';
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $raw = curl_exec($ch);
        curl_close($ch);

        $json = is_string($raw) ? json_decode($raw, true) : null;
        $predictions = is_array($json) && isset($json['predictions']) && is_array($json['predictions'])
            ? $json['predictions']
            : [];

        $out = [];
        foreach ($predictions as $p) {
            if (count($out) >= $limit) {
                break;
            }

            $name = isset($p['structured_formatting']['main_text'])
                ? (string)$p['structured_formatting']['main_text']
                : (string)($p['description'] ?? '');

            $address = (string)($p['description'] ?? $name);
            $placeId = (string)($p['place_id'] ?? '');

            $latLng = $placeId !== '' ? self::googlePlaceDetailsLatLng($placeId, $apiKey) : null;

            $out[] = [
                'name' => $name,
                'address' => $address,
                'lat' => is_array($latLng) ? (float)$latLng['lat'] : null,
                'lng' => is_array($latLng) ? (float)$latLng['lng'] : null,
                'place_id' => $placeId !== '' ? $placeId : null,
                'source' => 'google',
            ];
        }

        if ($redis) {
            $redis->setex($cacheKey, self::CACHE_TTL_SEC, json_encode($out, JSON_UNESCAPED_UNICODE));
        }

        return $out;
    }

    /**
     * @return array{lat:float,lng:float}|null
     */
    private static function googlePlaceDetailsLatLng(string $placeId, string $apiKey): ?array
    {
        $redis = Cache::redis();
        $cacheKey = 'places:details:' . md5($placeId);

        if ($redis) {
            $cachedRaw = $redis->get($cacheKey);
            $cached = is_string($cachedRaw) ? json_decode($cachedRaw, true) : null;
            if (is_array($cached) && isset($cached['lat'], $cached['lng'])) {
                return [
                    'lat' => (float)$cached['lat'],
                    'lng' => (float)$cached['lng'],
                ];
            }
        }

        $url = 'https://maps.googleapis.com/maps/api/place/details/json?place_id=' . urlencode($placeId) . '&fields=geometry/location&key=' . urlencode($apiKey);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        $raw = curl_exec($ch);
        curl_close($ch);

        $json = is_string($raw) ? json_decode($raw, true) : null;
        $loc = $json['result']['geometry']['location'] ?? null;
        if (!is_array($loc) || !isset($loc['lat'], $loc['lng'])) {
            return null;
        }

        $out = [
            'lat' => (float)$loc['lat'],
            'lng' => (float)$loc['lng'],
        ];

        if ($redis) {
            $redis->setex($cacheKey, self::CACHE_TTL_SEC, json_encode($out, JSON_UNESCAPED_UNICODE));
        }

        return $out;
    }
}
