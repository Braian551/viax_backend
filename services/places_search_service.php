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
