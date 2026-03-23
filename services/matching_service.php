<?php
/**
 * Motor de matching de conductores.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/driver_service.php';
require_once __DIR__ . '/eta_service.php';

class RideMatchingService
{
    private const DISTANCE_WEIGHT = 0.35;
    private const RATING_WEIGHT = 0.25;
    private const ACCEPTANCE_WEIGHT = 0.25;
    private const CANCELLATION_PENALTY_WEIGHT = 0.15;
    private const IDLE_WEIGHT = 0.05;
    private const STATIC_SCORE_TTL_SEC = 120;

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function rankCandidates(
        PDO $db,
        float $lat,
        float $lng,
        float $radiusKm = 5.0,
        int $limit = 10,
        ?string $vehicleType = null,
        ?int $companyId = null,
        ?int $requestUserId = null
    ): array
    {
        $candidates = DriverGeoService::searchAvailableNearby($lat, $lng, $radiusKm, 60);
        if (empty($candidates)) {
            return [];
        }

        $candidateIds = array_values(array_unique(array_map(static fn($c) => (int)$c['id'], $candidates)));
        return self::rankCandidatesFromIds($db, $lat, $lng, $candidateIds, $limit, $vehicleType, $companyId, $candidates, $requestUserId);
    }

    /**
     * @param array<int,int> $driverIds
     * @param array<int,array{id:int,distance_km:float}>|null $prefetchedCandidates
     * @return array<int,array<string,mixed>>
     */
    public static function rankCandidatesFromIds(
        PDO $db,
        float $lat,
        float $lng,
        array $driverIds,
        int $limit = 10,
        ?string $vehicleType = null,
        ?int $companyId = null,
        ?array $prefetchedCandidates = null,
        ?int $requestUserId = null
    ): array
    {
        if (empty($driverIds)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map(static fn($id) => (int)$id, $driverIds), static fn($id) => $id > 0)));
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $query = "\n            SELECT\n                u.id,\n                u.nombre,\n                u.apellido,\n                u.telefono,\n                u.foto_perfil,\n                u.empresa_id,\n                dc.vehiculo_tipo,\n                dc.calificacion_promedio,\n                COALESCE(dc.calificacion_promedio, 4.5) AS rating,\n                COALESCE(dc.total_viajes, 0) AS total_viajes,\n                COALESCE(dc.viajes_aceptados, 0) AS viajes_aceptados\n            FROM usuarios u\n            INNER JOIN detalles_conductor dc ON dc.usuario_id = u.id\n            WHERE u.id IN ($placeholders)\n              AND u.es_activo = 1\n              AND dc.disponible = 1\n        ";

        $params = $ids;
        if ($vehicleType !== null && trim($vehicleType) !== '') {
            $query .= ' AND dc.vehiculo_tipo = ?';
            $params[] = $vehicleType;
        }
        if ($companyId !== null) {
            $query .= ' AND u.empresa_id = ?';
            $params[] = $companyId;
        }
        if ($requestUserId !== null && $requestUserId > 0) {
            $query .= '
              AND NOT EXISTS (
                  SELECT 1 FROM blocked_users bu
                  WHERE bu.active = true
                    AND (
                        (bu.user_id = ? AND bu.blocked_user_id = u.id)
                        OR
                        (bu.user_id = u.id AND bu.blocked_user_id = ?)
                    )
              )
            ';
            $params[] = $requestUserId;
            $params[] = $requestUserId;
        }

        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $metaById = [];
        foreach ($rows as $r) {
            $total = max(1, (int)$r['total_viajes']);
            $accepted = max(0, (int)$r['viajes_aceptados']);
            $acceptance = min(1.0, $accepted / $total);

            $metaById[(int)$r['id']] = [
                'rating' => (float)$r['rating'],
                'acceptance_rate' => $acceptance,
                'nombre' => $r['nombre'] ?? null,
                'apellido' => $r['apellido'] ?? null,
                'telefono' => $r['telefono'] ?? null,
                'foto_perfil' => $r['foto_perfil'] ?? null,
                'empresa_id' => isset($r['empresa_id']) ? (int)$r['empresa_id'] : null,
                'vehiculo_tipo' => $r['vehiculo_tipo'] ?? null,
                'calificacion_promedio' => $r['calificacion_promedio'] !== null ? (float)$r['calificacion_promedio'] : null,
            ];
        }

        $ranked = [];
        $now = time();
        $redis = Cache::redis();
        $etaComputed = 0;
        $staticScoreByDriver = self::loadStaticScoreBatch($redis, $ids, $metaById);
        $prefetchedById = [];
        if (is_array($prefetchedCandidates)) {
            foreach ($prefetchedCandidates as $candidate) {
                $candidateId = (int)($candidate['id'] ?? 0);
                if ($candidateId > 0) {
                    $prefetchedById[$candidateId] = max(0.001, (float)($candidate['distance_km'] ?? 0.001));
                }
            }
        }

        foreach ($ids as $id) {
            if (!isset($metaById[$id])) {
                continue;
            }

            if ($redis && $redis->exists('driver_offer_lock:' . $id)) {
                continue;
            }
            if ($redis && $redis->exists('driver:cooldown:' . $id)) {
                continue;
            }

            $distance = $prefetchedById[$id] ?? null;
            if ($distance === null) {
                $rawLoc = $redis ? $redis->get('drivers:location:' . $id) : null;
                $loc = is_string($rawLoc) ? json_decode($rawLoc, true) : null;
                if (is_array($loc) && isset($loc['lat'], $loc['lng'])) {
                    $distance = self::haversineKm($lat, $lng, (float)$loc['lat'], (float)$loc['lng']);
                } else {
                    $distance = 999.0;
                }
            }
            $distance = max(0.001, (float)$distance);
            $rating = $metaById[$id]['rating'];
            $acceptance = $metaById[$id]['acceptance_rate'];

            $lastTripEndRaw = Cache::get('driver:' . $id . ':last_trip_end');
            $idleSeconds = is_string($lastTripEndRaw) && is_numeric($lastTripEndRaw)
                ? max(0, $now - (int)$lastTripEndRaw)
                : 0;
            $idleWeight = min(1.0, $idleSeconds / 3600.0);

            $statsRaw = $redis?->hGetAll('driver:' . $id . ':stats');
            $rejectionRate = 0.0;
            $cancelRate = 0.0;
            $recentTrips = 0;
            if (is_array($statsRaw) && !empty($statsRaw)) {
                $rejectionRate = isset($statsRaw['rejection_rate']) ? max(0.0, min(1.0, (float)$statsRaw['rejection_rate'])) : 0.0;
                $cancelRate = isset($statsRaw['cancel_rate']) ? max(0.0, min(1.0, (float)$statsRaw['cancel_rate'])) : 0.0;
                $recentTrips = isset($statsRaw['recent_trips']) ? max(0, (int)$statsRaw['recent_trips']) : 0;
            }

            if ($cancelRate <= 0.0) {
                // Fallback simple con datos agregados existentes.
                $cancelRate = max(0.0, min(1.0, (1.0 - $acceptance) * 0.5));
            }

            $loadPenalty = min(0.35, ($recentTrips / 20.0) + ($rejectionRate * 0.2));

            $driverLocRaw = $redis ? $redis->get('drivers:location:' . $id) : null;
            $driverLoc = is_string($driverLocRaw) ? json_decode($driverLocRaw, true) : null;
            $speedKmh = is_array($driverLoc) && isset($driverLoc['speed']) ? max(5.0, (float)$driverLoc['speed']) : 24.0;

            $etaSec = (int)round(($distance / $speedKmh) * 3600);
            if ($etaComputed < 5 && is_array($driverLoc) && isset($driverLoc['lat'], $driverLoc['lng'])) {
                $etaPayload = EtaService::estimate(
                    (float)$driverLoc['lat'],
                    (float)$driverLoc['lng'],
                    $lat,
                    $lng,
                    'moderate',
                    $speedKmh
                );
                if (isset($etaPayload['eta_seconds'])) {
                    $etaSec = max(1, (int)$etaPayload['eta_seconds']);
                    $etaComputed++;
                }
            }

            // Normalizar ETA en rango [0,1] (mejor ETA => mayor score).
            $etaScore = 1.0 - min(1800.0, (float)$etaSec) / 1800.0;
            $acceptanceScore = max(0.0, min(1.0, $acceptance));
            $ratingScore = max(0.0, min(1.0, $rating / 5.0));
            $idleScore = max(0.0, min(1.0, $idleWeight));

            // Score de distancia normalizado: conductor más cerca => mayor score.
            $distanceScore = max(0.0, 1.0 - min($distance, 10.0) / 10.0);

            // Score estático cacheado en Redis por conductor.
            $staticScore = $staticScoreByDriver[$id] ?? (
                ($ratingScore * self::RATING_WEIGHT) +
                ($acceptanceScore * self::ACCEPTANCE_WEIGHT) -
                ($cancelRate * self::CANCELLATION_PENALTY_WEIGHT)
            );

            // score = distance_weight + rating_weight + acceptance_weight + cancellation_penalty + idle_weight + eta_signal
            $score =
                ($distanceScore * self::DISTANCE_WEIGHT) +
                $staticScore +
                ($idleScore * self::IDLE_WEIGHT) -
                $loadPenalty +
                ($etaScore * 0.10);
            $ranked[] = [
                'driver_id' => $id,
                'id' => $id,
                'distance_km' => $distance,
                'driver_distance' => $distance,
                'eta_seconds' => $etaSec,
                'rating' => $rating,
                'acceptance_rate' => $acceptance,
                'cancel_rate' => $cancelRate,
                'idle_seconds' => $idleSeconds,
                'load_penalty' => round($loadPenalty, 4),
                'score' => $score,
                'offer_timeout_sec' => 10,
                'nombre' => $metaById[$id]['nombre'],
                'apellido' => $metaById[$id]['apellido'],
                'telefono' => $metaById[$id]['telefono'],
                'foto_perfil' => $metaById[$id]['foto_perfil'],
                'empresa_id' => $metaById[$id]['empresa_id'],
                'vehiculo_tipo' => $metaById[$id]['vehiculo_tipo'],
                'calificacion_promedio' => $metaById[$id]['calificacion_promedio'],
            ];
        }

        usort($ranked, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($ranked, 0, $limit);
    }

    private static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    /**
     * Carga en lote los score estáticos desde Redis y rellena faltantes.
     *
     * @param array<int,int> $ids
     * @param array<int,array<string,mixed>> $metaById
     * @return array<int,float>
     */
    private static function loadStaticScoreBatch($redis, array $ids, array $metaById): array
    {
        $result = [];
        if (!$redis || empty($ids)) {
            return $result;
        }

        $keys = array_map(static fn($id) => 'driver:score:' . (int)$id, $ids);
        $rawScores = $redis->mGet($keys);

        foreach ($ids as $index => $driverId) {
            $driverId = (int)$driverId;
            $raw = is_array($rawScores) ? ($rawScores[$index] ?? null) : null;
            if (is_string($raw) && is_numeric($raw)) {
                $result[$driverId] = (float)$raw;
                continue;
            }

            $meta = $metaById[$driverId] ?? null;
            if (!is_array($meta)) {
                continue;
            }

            $ratingScore = max(0.0, min(1.0, ((float)($meta['rating'] ?? 4.5)) / 5.0));
            $acceptanceScore = max(0.0, min(1.0, (float)($meta['acceptance_rate'] ?? 0.0)));
            $cancelPenalty = max(0.0, min(1.0, (1.0 - $acceptanceScore) * 0.5));

            $score =
                ($ratingScore * self::RATING_WEIGHT) +
                ($acceptanceScore * self::ACCEPTANCE_WEIGHT) -
                ($cancelPenalty * self::CANCELLATION_PENALTY_WEIGHT);

            $result[$driverId] = $score;
            $redis->setex('driver:score:' . $driverId, self::STATIC_SCORE_TTL_SEC, (string)round($score, 6));
        }

        return $result;
    }
}
