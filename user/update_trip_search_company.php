<?php
/**
 * Endpoint: actualizar empresa objetivo durante busqueda de conductor.
 *
 * Comportamiento:
 * - Modo empresa: filtra candidatos por la empresa seleccionada.
 * - Modo azar: mezcla candidatos cercanos sin filtrar por empresa.
 * - Refresca cola Redis `ride:{id}:drivers` para UX progresiva en polling/SSE.
 *
 * Compatibilidad:
 * - Mantiene el contrato de respuesta existente (`success`, `message`, `empresa`).
 * - Solo agrega campos aditivos (`search_mode`, `drivers_found`, `queue_refreshed`).
 */

header('Content-Type: application/json');
$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$allowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit();
}
if ($requestMethod !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Metodo no permitido',
    ]);
    exit();
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/matching_service.php';

function normalizeVehicleTypeForSearch($rawType): string
{
    $normalized = strtolower(trim((string)$rawType));
    $aliases = [
        'moto_taxi' => 'mototaxi',
        'moto taxi' => 'mototaxi',
        'motocarro' => 'mototaxi',
        'motorcycle' => 'moto',
        'carro' => 'auto',
        'automovil' => 'auto',
        'car' => 'auto',
    ];

    if (isset($aliases[$normalized])) {
        return $aliases[$normalized];
    }

    return $normalized !== '' ? $normalized : 'moto';
}

function clearTripDriverStatuses($redis, int $solicitudId): void
{
    $patterns = [
        'ride:' . $solicitudId . ':driver:*:status',
        'ride:' . $solicitudId . ':driver:*:status:meta',
    ];

    foreach ($patterns as $pattern) {
        $cursor = null;
        do {
            $scan = $redis->scan($cursor, $pattern, 100);
            if ($scan === false || !is_array($scan) || count($scan) < 2) {
                break;
            }

            $cursor = $scan[0];
            $keys = $scan[1];
            if (is_array($keys) && !empty($keys)) {
                foreach ($keys as $key) {
                    if (is_string($key) && $key !== '') {
                        $redis->del($key);
                    }
                }
            }
        } while ((string)$cursor !== '0');
    }
}

/**
 * @return array{drivers_found:int,driver_ids:array<int,int>,search_mode:string,queue_refreshed:bool}
 */
function refreshSearchQueue(PDO $db, int $solicitudId, ?int $empresaId, bool $modeChanged = true): array
{
    $stmtTrip = $db->prepare("
        SELECT
            id,
            cliente_id,
            estado,
            latitud_recogida,
            longitud_recogida,
            tipo_vehiculo
        FROM solicitudes_servicio
        WHERE id = :solicitud_id
        LIMIT 1
    ");
    $stmtTrip->execute([':solicitud_id' => $solicitudId]);
    $trip = $stmtTrip->fetch(PDO::FETCH_ASSOC);

    $searchMode = $empresaId !== null ? 'empresa' : 'azar';
    if (!$trip) {
        return [
            'drivers_found' => 0,
            'driver_ids' => [],
            'search_mode' => $searchMode,
            'queue_refreshed' => false,
        ];
    }

    $estado = strtolower(trim((string)($trip['estado'] ?? '')));
    if (!in_array($estado, ['pendiente', 'requested'], true)) {
        return [
            'drivers_found' => 0,
            'driver_ids' => [],
            'search_mode' => $searchMode,
            'queue_refreshed' => false,
        ];
    }

    $lat = (float)($trip['latitud_recogida'] ?? 0.0);
    $lng = (float)($trip['longitud_recogida'] ?? 0.0);
    $requestUserId = (int)($trip['cliente_id'] ?? 0);
    $vehicleType = normalizeVehicleTypeForSearch($trip['tipo_vehiculo'] ?? 'moto');

    $ranked = RideMatchingService::rankCandidates(
        $db,
        $lat,
        $lng,
        8.0,
        12,
        $vehicleType,
        $empresaId,
        $requestUserId > 0 ? $requestUserId : null
    );

    $driverIds = [];
    foreach ($ranked as $candidate) {
        $driverId = (int)($candidate['driver_id'] ?? $candidate['id'] ?? 0);
        if ($driverId > 0) {
            $driverIds[] = $driverId;
        }
    }
    $driverIds = array_values(array_unique($driverIds));

    $queueRefreshed = false;
    $redis = Cache::redis();
    if ($redis) {
        $queueKey = 'ride:' . $solicitudId . ':drivers';
        $queueKeyCanonical = 'ride:' . $solicitudId . ':drivers_queue';
        $offerKey = 'ride:' . $solicitudId . ':current_offer';
        $currentDriverKey = 'ride:' . $solicitudId . ':current_driver';
        $queueVersionKey = 'ride:' . $solicitudId . ':queue_version';
        $invalidateKey = 'ride:' . $solicitudId . ':queue_invalidated_at';

        $existingRaw = $redis->get($queueKey);
        $existingQueue = is_string($existingRaw) ? json_decode($existingRaw, true) : [];
        if (!is_array($existingQueue)) {
            $existingQueue = [];
        }
        $existingDriverIds = array_values(array_filter(array_map(
            static fn($id) => (int)$id,
            $existingQueue
        ), static fn($id) => $id > 0));
        $queueChanged = ($existingDriverIds !== $driverIds);

        if ($modeChanged) {
            $redis->del($queueKey);
            $redis->del($queueKeyCanonical);
            $redis->del($offerKey);
            $redis->del($currentDriverKey);
            clearTripDriverStatuses($redis, $solicitudId);
        }

        if ($modeChanged || $queueChanged) {
            if (!empty($driverIds)) {
                $payload = json_encode($driverIds, JSON_UNESCAPED_UNICODE);
                $redis->setex($queueKey, 600, $payload);
                $redis->setex($queueKeyCanonical, 600, $payload);
            } else {
                $redis->del($queueKey);
                $redis->del($queueKeyCanonical);
            }
            $redis->setex('ride:' . $solicitudId . ':matching_status', 180, 'searching');
        }

        if ($modeChanged) {
            $redis->incr($queueVersionKey);
            $redis->setex($invalidateKey, 600, (string)time());
        }

        $activeKey = 'trip:active:' . $solicitudId;
        $activeRaw = $redis->get($activeKey);
        $activePayload = is_string($activeRaw) ? json_decode($activeRaw, true) : [];
        if (!is_array($activePayload)) {
            $activePayload = [];
        }
        $activePayload['status'] = 'requested';
        $activePayload['empresa_id'] = $empresaId;
        $activePayload['search_mode'] = $searchMode;
        $activePayload['updated_at'] = function_exists('now_colombia')
            ? now_colombia()->format('c')
            : (new DateTime('now', new DateTimeZone('America/Bogota')))->format('c');
        $redis->setex($activeKey, 7200, json_encode($activePayload, JSON_UNESCAPED_UNICODE));

        $queueRefreshed = $queueChanged || $modeChanged;
    }

    return [
        'drivers_found' => count($driverIds),
        'driver_ids' => $driverIds,
        'search_mode' => $searchMode,
        'queue_refreshed' => $queueRefreshed,
    ];
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('JSON invalido');
    }

    $solicitudId = isset($data['solicitud_id']) ? intval($data['solicitud_id']) : 0;
    $clienteId = isset($data['cliente_id']) ? intval($data['cliente_id']) : 0;
    $empresaId = array_key_exists('empresa_id', $data) && $data['empresa_id'] !== null
        ? intval($data['empresa_id'])
        : null;

    if ($solicitudId <= 0 || $clienteId <= 0) {
        throw new Exception('solicitud_id y cliente_id son requeridos');
    }

    $database = new Database();
    $db = $database->getConnection();
    $db->beginTransaction();

    $stmtSolicitud = $db->prepare("
        SELECT id, cliente_id, estado, empresa_id
        FROM solicitudes_servicio
        WHERE id = :solicitud_id
        FOR UPDATE
    ");
    $stmtSolicitud->execute([':solicitud_id' => $solicitudId]);
    $solicitud = $stmtSolicitud->fetch(PDO::FETCH_ASSOC);

    if (!$solicitud) {
        throw new Exception('Solicitud no encontrada');
    }

    if ((int)$solicitud['cliente_id'] !== $clienteId) {
        throw new Exception('No autorizado para modificar esta solicitud');
    }

    $estado = strtolower(trim((string)($solicitud['estado'] ?? '')));
    if (!in_array($estado, ['pendiente', 'requested'], true)) {
        throw new Exception('Solo se puede cambiar empresa en solicitudes pendientes');
    }

    $currentEmpresaId = isset($solicitud['empresa_id']) && $solicitud['empresa_id'] !== null
        ? (int)$solicitud['empresa_id']
        : null;
    $modeChanged = ($currentEmpresaId !== $empresaId);

    $empresaInfo = null;
    if ($empresaId !== null) {
        if ($empresaId <= 0) {
            throw new Exception('empresa_id invalido');
        }

        $stmtEmpresa = $db->prepare("
            SELECT id, nombre, logo_url
            FROM empresas_transporte
            WHERE id = :empresa_id
              AND estado = 'activo'
              AND verificada = true
            LIMIT 1
        ");
        $stmtEmpresa->execute([':empresa_id' => $empresaId]);
        $empresaInfo = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);

        if (!$empresaInfo) {
            throw new Exception('Empresa no valida para busqueda');
        }
    }

    if ($modeChanged) {
        $stmtUpdate = $db->prepare("
            UPDATE solicitudes_servicio
            SET empresa_id = :empresa_id
            WHERE id = :solicitud_id
        ");
        $stmtUpdate->bindValue(':empresa_id', $empresaId, $empresaId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmtUpdate->bindValue(':solicitud_id', $solicitudId, PDO::PARAM_INT);
        $stmtUpdate->execute();
    }

    $db->commit();

    $searchMeta = $modeChanged
        ? refreshSearchQueue($db, $solicitudId, $empresaId, true)
        : [
            'drivers_found' => 0,
            'driver_ids' => [],
            'search_mode' => $empresaId === null ? 'azar' : 'empresa',
            'queue_refreshed' => false,
        ];

    echo json_encode([
        'success' => true,
        'message' => $empresaId === null
            ? 'Busqueda cambiada a modo azar'
            : 'Empresa de busqueda actualizada',
        'solicitud_id' => $solicitudId,
        'search_mode' => $searchMeta['search_mode'],
        'drivers_found' => $searchMeta['drivers_found'],
        'queue_refreshed' => $searchMeta['queue_refreshed'],
        'empresa' => $empresaInfo ? [
            'id' => (int)$empresaInfo['id'],
            'nombre' => $empresaInfo['nombre'],
            'logo_url' => $empresaInfo['logo_url'],
        ] : null,
    ]);
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[update_trip_search_company] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
    ]);
}
