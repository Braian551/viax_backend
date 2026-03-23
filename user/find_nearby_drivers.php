<?php
/**
 * Endpoint: Buscar Conductores Cercanos Disponibles
 *
 * Compatibilidad Flutter:
 * - Mantiene request y response actuales.
 * - No cambia nombres de campos ni estructura JSON.
 *
 * Optimización:
 * - Camino primario: Redis (`active_drivers` + `driver_location:*`).
 * - Fallback automático: SQL con Haversine si Redis no tiene datos.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/Distance.php';
require_once __DIR__ . '/../services/driver_service.php';

/** Mapea tipo de vehículo de app al valor esperado en BD. */
function mapVehiculoTipo(string $tipoVehiculo): string
{
    $vehiculoTipoMap = [
        'moto' => 'moto',
        'auto' => 'auto',
        'mototaxi' => 'mototaxi',
        'motocarro' => 'mototaxi',
    ];
    return $vehiculoTipoMap[$tipoVehiculo] ?? 'moto';
}

/**
 * Busca candidatos cercanos en Redis y retorna distancias calculadas en PHP.
 *
 * @return array<int,array{id:int,distancia_km:float,latitud_actual:float,longitud_actual:float}>
 */
function findNearbyFromRedis(float $lat, float $lng, float $radioKm, int $limit): array
{
    $geoCandidates = DriverGeoService::searchAvailableNearby($lat, $lng, $radioKm, $limit);
    if (empty($geoCandidates)) {
        return [];
    }

    $out = [];
    foreach ($geoCandidates as $candidate) {
        $driverId = (int)($candidate['id'] ?? 0);
        if ($driverId <= 0) {
            continue;
        }

        $cached = Cache::get('driver_location:' . $driverId);
        $loc = is_string($cached) ? json_decode($cached, true) : null;
        if (!is_array($loc) || !isset($loc['lat'], $loc['lng'])) {
            continue;
        }

        $out[] = [
            'id' => $driverId,
            'distancia_km' => (float)($candidate['distance_km'] ?? 0),
            'latitud_actual' => (float)$loc['lat'],
            'longitud_actual' => (float)$loc['lng'],
        ];
    }

    return $out;
}

/**
 * Enriquecer IDs de conductores con sus datos de usuario/vehículo desde BD.
 */
function enrichDriversByIds(PDO $db, array $redisCandidates, string $vehiculoTipoBD, ?int $empresaId): array
{
    if (empty($redisCandidates)) {
        return [];
    }

    $ids = array_values(array_unique(array_map(static fn(array $x): int => (int) $x['id'], $redisCandidates)));
    if (empty($ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $query = "
        SELECT
            u.id,
            u.nombre,
            u.apellido,
            u.telefono,
            u.foto_perfil,
            u.empresa_id,
            dc.vehiculo_tipo,
            dc.vehiculo_marca,
            dc.vehiculo_modelo,
            dc.vehiculo_placa,
            dc.vehiculo_color,
            dc.calificacion_promedio,
            dc.total_viajes,
            dc.latitud_actual,
            dc.longitud_actual
        FROM usuarios u
        INNER JOIN detalles_conductor dc ON u.id = dc.usuario_id
        WHERE u.id IN ({$placeholders})
          AND u.tipo_usuario = 'conductor'
          AND u.es_activo = 1
          AND dc.disponible = 1
          AND dc.estado_verificacion = 'aprobado'
          AND dc.vehiculo_tipo = ?
    ";

    $params = $ids;
    $params[] = $vehiculoTipoBD;

    if ($empresaId !== null) {
        $query .= ' AND u.empresa_id = ?';
        $params[] = $empresaId;
    }

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $distById = [];
    foreach ($redisCandidates as $c) {
        $distById[(int) $c['id']] = [
            'distancia_km' => (float) $c['distancia_km'],
            'latitud_actual' => (float) $c['latitud_actual'],
            'longitud_actual' => (float) $c['longitud_actual'],
        ];
    }

    $out = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        if (!isset($distById[$id])) {
            continue;
        }
        $out[] = [
            'id' => $id,
            'nombre' => $r['nombre'],
            'apellido' => $r['apellido'],
            'telefono' => $r['telefono'],
            'foto_perfil' => $r['foto_perfil'],
            'empresa_id' => isset($r['empresa_id']) ? (int) $r['empresa_id'] : null,
            'vehiculo_tipo' => $r['vehiculo_tipo'],
            'vehiculo_marca' => $r['vehiculo_marca'],
            'vehiculo_modelo' => $r['vehiculo_modelo'],
            'vehiculo_placa' => $r['vehiculo_placa'],
            'vehiculo_color' => $r['vehiculo_color'],
            'calificacion_promedio' => $r['calificacion_promedio'] !== null ? (float) $r['calificacion_promedio'] : null,
            'total_viajes' => (int) ($r['total_viajes'] ?? 0),
            'latitud_actual' => (float) $distById[$id]['latitud_actual'],
            'longitud_actual' => (float) $distById[$id]['longitud_actual'],
            'distancia_km' => round((float) $distById[$id]['distancia_km'], 2),
        ];
    }

    usort($out, static fn(array $a, array $b): int => $a['distancia_km'] <=> $b['distancia_km']);
    return array_slice($out, 0, 20);
}

/** Filtra conductores que estén bloqueados bidireccionalmente con el usuario solicitante. */
function appendBlockedFilter(string $query, array &$params, ?int $usuarioId, string $driverColumn = 'u.id'): string
{
    if ($usuarioId === null || $usuarioId <= 0) {
        return $query;
    }

    $query .= "
      AND NOT EXISTS (
          SELECT 1
          FROM blocked_users bu
          WHERE bu.active = true
            AND (
                (bu.user_id = ? AND bu.blocked_user_id = {$driverColumn})
                OR
                (bu.user_id = {$driverColumn} AND bu.blocked_user_id = ?)
            )
      )
    ";
    $params[] = $usuarioId;
    $params[] = $usuarioId;
    return $query;
}

/** Fallback SQL tradicional si no hay data suficiente en Redis. */
function findNearbyFromDb(PDO $db, float $lat, float $lng, string $vehiculoTipoBD, ?int $empresaId, float $radioKm, ?int $usuarioId): array
{
    $query = "
        SELECT
            u.id,
            u.nombre,
            u.apellido,
            u.telefono,
            u.foto_perfil,
            u.empresa_id,
            dc.vehiculo_tipo,
            dc.vehiculo_marca,
            dc.vehiculo_modelo,
            dc.vehiculo_placa,
            dc.vehiculo_color,
            dc.calificacion_promedio,
            dc.total_viajes,
            dc.latitud_actual,
            dc.longitud_actual,
            (6371 * acos(
                cos(radians(?)) * cos(radians(dc.latitud_actual)) *
                cos(radians(dc.longitud_actual) - radians(?)) +
                sin(radians(?)) * sin(radians(dc.latitud_actual))
            )) AS distancia_km
        FROM usuarios u
        INNER JOIN detalles_conductor dc ON u.id = dc.usuario_id
        WHERE u.tipo_usuario = 'conductor'
          AND u.es_activo = 1
          AND dc.disponible = 1
          AND dc.estado_verificacion = 'aprobado'
          AND dc.vehiculo_tipo = ?
          AND dc.latitud_actual IS NOT NULL
          AND dc.longitud_actual IS NOT NULL
          AND (6371 * acos(
                cos(radians(?)) * cos(radians(dc.latitud_actual)) *
                cos(radians(dc.longitud_actual) - radians(?)) +
                sin(radians(?)) * sin(radians(dc.latitud_actual))
            )) <= ?
    ";

    $params = [$lat, $lng, $lat, $vehiculoTipoBD, $lat, $lng, $lat, $radioKm];
    if ($empresaId !== null) {
        $query .= ' AND u.empresa_id = ?';
        $params[] = $empresaId;
    }

    $query = appendBlockedFilter($query, $params, $usuarioId);

    $query .= ' ORDER BY distancia_km ASC LIMIT 20';
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $conductores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static function (array $c): array {
        return [
            'id' => (int) $c['id'],
            'nombre' => $c['nombre'],
            'apellido' => $c['apellido'],
            'telefono' => $c['telefono'],
            'foto_perfil' => $c['foto_perfil'],
            'empresa_id' => isset($c['empresa_id']) ? (int) $c['empresa_id'] : null,
            'vehiculo_tipo' => $c['vehiculo_tipo'],
            'vehiculo_marca' => $c['vehiculo_marca'],
            'vehiculo_modelo' => $c['vehiculo_modelo'],
            'vehiculo_placa' => $c['vehiculo_placa'],
            'vehiculo_color' => $c['vehiculo_color'],
            'calificacion_promedio' => $c['calificacion_promedio'] ? (float) $c['calificacion_promedio'] : null,
            'total_viajes' => (int) ($c['total_viajes'] ?? 0),
            'latitud_actual' => (float) $c['latitud_actual'],
            'longitud_actual' => (float) $c['longitud_actual'],
            'distancia_km' => round((float) $c['distancia_km'], 2),
        ];
    }, $conductores);
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('JSON inválido');
    }

    if (!isset($data['latitud'], $data['longitud'], $data['tipo_vehiculo'])) {
        throw new Exception('Datos requeridos: latitud, longitud, tipo_vehiculo');
    }

    $latitud = (float) $data['latitud'];
    $longitud = (float) $data['longitud'];
    $tipoVehiculo = (string) $data['tipo_vehiculo'];
    $empresaId = isset($data['empresa_id']) ? (int) $data['empresa_id'] : null;
    $usuarioId = isset($data['usuario_id']) ? (int) $data['usuario_id'] : null;
    $radioKm = isset($data['radio_km']) ? (float) $data['radio_km'] : 5.0;
    if ($radioKm <= 0 || $radioKm > 50) {
        $radioKm = 5.0;
    }

    $vehiculoTipoBD = mapVehiculoTipo($tipoVehiculo);

    $database = new Database();
    $db = $database->getConnection();

    // Camino rápido por Redis.
    $redisCandidates = findNearbyFromRedis($latitud, $longitud, $radioKm, 50);
    $conductoresFormateados = enrichDriversByIds($db, $redisCandidates, $vehiculoTipoBD, $empresaId);

    if ($usuarioId !== null && $usuarioId > 0 && !empty($conductoresFormateados)) {
        $ids = array_map(static fn(array $d): int => (int)($d['id'] ?? 0), $conductoresFormateados);
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));

        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = $ids;
            $params[] = $usuarioId;
            $params[] = $usuarioId;

            $stmtBlocked = $db->prepare(
                "
                SELECT u.id
                FROM usuarios u
                WHERE u.id IN ({$placeholders})
                  AND NOT EXISTS (
                      SELECT 1
                      FROM blocked_users bu
                      WHERE bu.active = true
                        AND (
                            (bu.user_id = ? AND bu.blocked_user_id = u.id)
                            OR
                            (bu.user_id = u.id AND bu.blocked_user_id = ?)
                        )
                  )
                "
            );
            $stmtBlocked->execute($params);
            $allowedIds = array_map('intval', $stmtBlocked->fetchAll(PDO::FETCH_COLUMN));
            $allowedSet = array_fill_keys($allowedIds, true);

            $conductoresFormateados = array_values(array_filter(
                $conductoresFormateados,
                static fn(array $driver): bool => isset($allowedSet[(int)($driver['id'] ?? 0)])
            ));
        }
    }

    // Fallback SQL si Redis no entrega resultados suficientes.
    if (empty($conductoresFormateados)) {
        $conductoresFormateados = findNearbyFromDb($db, $latitud, $longitud, $vehiculoTipoBD, $empresaId, $radioKm, $usuarioId);
    }

    echo json_encode([
        'success' => true,
        'total' => count($conductoresFormateados),
        'conductores' => $conductoresFormateados,
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
