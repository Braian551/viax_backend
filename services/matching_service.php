<?php
/**
 * Motor de matching de conductores.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/driver_service.php';

class RideMatchingService
{
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
        ?int $companyId = null
    ): array
    {
        $candidates = DriverGeoService::searchAvailableNearby($lat, $lng, $radiusKm, 60);
        if (empty($candidates)) {
            return [];
        }

        $ids = array_values(array_unique(array_map(static fn($c) => (int)$c['id'], $candidates)));
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
        foreach ($candidates as $c) {
            $id = (int)$c['id'];
            if (!isset($metaById[$id])) {
                continue;
            }

            $distance = max(0.001, (float)$c['distance_km']);
            $rating = $metaById[$id]['rating'];
            $acceptance = $metaById[$id]['acceptance_rate'];

            $lastTripEndRaw = Cache::get('driver:' . $id . ':last_trip_end');
            $idleSeconds = is_string($lastTripEndRaw) && is_numeric($lastTripEndRaw)
                ? max(0, $now - (int)$lastTripEndRaw)
                : 0;
            $idleWeight = min(1.0, $idleSeconds / 3600.0);

            $statsRaw = Cache::redis()?->hGetAll('driver:' . $id . ':stats');
            $rejectionRate = 0.0;
            $recentTrips = 0;
            if (is_array($statsRaw) && !empty($statsRaw)) {
                $rejectionRate = isset($statsRaw['rejection_rate']) ? max(0.0, min(1.0, (float)$statsRaw['rejection_rate'])) : 0.0;
                $recentTrips = isset($statsRaw['recent_trips']) ? max(0, (int)$statsRaw['recent_trips']) : 0;
            }

            $loadPenalty = min(0.35, ($recentTrips / 20.0) + ($rejectionRate * 0.2));

            $score = (1.0 / $distance) * 0.5 + ($rating / 5.0) * 0.25 + $acceptance * 0.15 + $idleWeight * 0.10 - $loadPenalty;
            $ranked[] = [
                'driver_id' => $id,
                'id' => $id,
                'distance_km' => $distance,
                'rating' => $rating,
                'acceptance_rate' => $acceptance,
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
}
