<?php
/**
 * Endpoint para crear solicitud de viaje
 * 
 * Incluye:
 * - Idempotencia para evitar solicitudes duplicadas
 * - Validación robusta de datos
 * - Búsqueda de conductores cercanos
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Idempotency-Key, X-Timestamp, X-Nonce, X-Signature, X-Device-Fingerprint, X-Device-Model, X-Device-Platform, X-Integrity-Score, X-Integrity-Warning');

// Handle CLI environment
if (php_sapi_name() === 'cli') {
    $_SERVER['REQUEST_METHOD'] = 'POST';
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/matching_service.php';
require_once __DIR__ . '/../services/eta_service.php';
require_once __DIR__ . '/../services/pickup_eta_service.php';
require_once __DIR__ . '/../services/pricing_service.php';
require_once __DIR__ . '/../services/places_search_service.php';

/** Valida coordenadas geográficas para evitar payloads corruptos. */
function isValidCoordinate(float $lat, float $lng): bool
{
    return $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0;
}

/** Guarda solicitud temporal en Redis para lecturas rápidas/eventuales retries. */
function cacheRideRequest(int $solicitudId, array $data): void
{
    $payload = [
        'solicitud_id' => $solicitudId,
        'usuario_id' => (int) $data['usuario_id'],
        'tipo_servicio' => (string) $data['tipo_servicio'],
        'tipo_vehiculo' => (string) ($data['tipo_vehiculo'] ?? 'moto'),
        'latitud_origen' => (float) $data['latitud_origen'],
        'longitud_origen' => (float) $data['longitud_origen'],
        'latitud_destino' => (float) $data['latitud_destino'],
        'longitud_destino' => (float) $data['longitud_destino'],
        'timestamp' => time(),
    ];

    // TTL corto: solicitud en búsqueda activa inicial.
    Cache::set("ride_request:{$solicitudId}", (string) json_encode($payload), 180);
}

function recentPlaceNameFromAddress(string $address): string
{
    $parts = array_values(array_filter(array_map('trim', explode(',', $address))));
    if (!empty($parts) && $parts[0] !== '') {
        return $parts[0];
    }
    return $address !== '' ? $address : 'Ubicación';
}

try {
    // Read input
    $input = file_get_contents('php://input');
    if (empty($input) && php_sapi_name() === 'cli') {
        $input = file_get_contents('php://stdin');
    }
    
    $data = json_decode($input, true);
    
    // Validar datos requeridos
    $required = ['usuario_id', 'latitud_origen', 'longitud_origen', 'direccion_origen', 
                 'latitud_destino', 'longitud_destino', 'direccion_destino', 
                 'tipo_servicio', 'distancia_km', 'duracion_minutos'];
    
    if (!$data) {
        throw new Exception("No se recibieron datos JSON válidos");
    }

    foreach ($required as $field) {
        if (!isset($data[$field])) {
            throw new Exception("Campo requerido faltante: $field");
        }
    }

    // Validaciones de dominio básicas (sin romper contrato actual).
    $usuarioId = filter_var($data['usuario_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($usuarioId === false) {
        throw new Exception('usuario_id inválido');
    }

    $latOrigen = (float) $data['latitud_origen'];
    $lngOrigen = (float) $data['longitud_origen'];
    $latDestino = (float) $data['latitud_destino'];
    $lngDestino = (float) $data['longitud_destino'];
    if (!isValidCoordinate($latOrigen, $lngOrigen) || !isValidCoordinate($latDestino, $lngDestino)) {
        throw new Exception('Coordenadas de origen/destino inválidas');
    }

    $distanciaKm = (float) $data['distancia_km'];
    $duracionMin = (int) $data['duracion_minutos'];
    if ($distanciaKm < 0 || $duracionMin < 0) {
        throw new Exception('distancia_km y duracion_minutos deben ser no negativos');
    }
    
    $database = new Database();
    $db = $database->getConnection();

    try {
        $redis = Cache::redis();
        if ($redis) {
            $redis->incr('metrics:rides_requested');
        }
    } catch (Throwable $e) {
        // Métrica secundaria, no rompe flujo.
    }
    
    // Obtener clave de idempotencia
    $idempotencyKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? $data['idempotency_key'] ?? null;
    
    // Si hay clave de idempotencia, verificar si ya existe una solicitud reciente
    if ($idempotencyKey) {
        $stmt = $db->prepare("
            SELECT id, estado FROM solicitudes_servicio 
            WHERE cliente_id = :user_id 
            AND last_operation_key = :idem_key
            AND fecha_creacion > NOW() - INTERVAL '5 minutes'
        ");
        $stmt->execute([
            ':user_id' => $usuarioId,
            ':idem_key' => $idempotencyKey
        ]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Retornar la solicitud existente (idempotente)
            echo json_encode([
                'success' => true,
                'message' => 'Solicitud ya creada previamente',
                'solicitud_id' => $existing['id'],
                'idempotent' => true,
                'conductores_encontrados' => 0,
                'conductores' => []
            ]);
            exit();
        }
    }
    
    // Iniciar transacción
    $db->beginTransaction();

    // Verificar que el usuario existe
    $stmt = $db->prepare("SELECT id, nombre FROM usuarios WHERE id = ? AND tipo_usuario = 'cliente'");
    $stmt->execute([$usuarioId]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        throw new Exception('Usuario no encontrado');
    }
    
    // Validar seguridad anti-bypass (HMAC + Fingerprint + Rate Limit)
    require_once __DIR__ . '/../middleware/SecurityMiddleware.php';
    $rawBody = file_get_contents('php://input') ?: '';
    $securityError = SecurityMiddleware::fullSecurityCheck($_SERVER['REQUEST_URI'] ?? '/user/create_trip_request.php', $rawBody);
    if ($securityError !== null) {
        die(json_encode($securityError));
    }
    
    // Validar políticas legales anti-bypass
    require_once __DIR__ . '/../middleware/LegalMiddleware.php';
    LegalMiddleware::checkLegalAcceptance((int)$usuarioId, 'cliente');
    
    // Mapear tipo_servicio
    $tipoServicioMap = [
        'viaje' => 'transporte',
        'paquete' => 'envio_paquete',
        'transporte' => 'transporte',
        'envio_paquete' => 'envio_paquete'
    ];
    $tipoServicio = $tipoServicioMap[$data['tipo_servicio']] ?? 'transporte';
    
    // Generar UUID único
    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    
    // Crear la solicitud de servicio con los campos correctos de la tabla
    $stmt = $db->prepare("
        INSERT INTO solicitudes_servicio (
            uuid_solicitud,
            cliente_id, 
            tipo_servicio,
            tipo_vehiculo,
            empresa_id,
            latitud_recogida, 
            longitud_recogida, 
            direccion_recogida,
            latitud_destino, 
            longitud_destino, 
            direccion_destino,
            distancia_estimada,
            tiempo_estimado,
            precio_estimado,
            metodo_pago,
            estado,
            last_operation_key,
            fecha_creacion,
            solicitado_en
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', ?, NOW(), NOW())
    ");
    
    // Obtener precio si se proporcionó, o usar 0 por defecto
    $precioEstimado = $data['precio_estimado'] ?? $data['precio'] ?? 0;
    $metodoPago = 'efectivo'; // Solo efectivo soportado
    $tipoVehiculo = $data['tipo_vehiculo'] ?? 'moto'; // Por defecto moto
    $empresaId = isset($data['empresa_id']) && $data['empresa_id'] !== null ? intval($data['empresa_id']) : null;
    
    $stmt->execute([
        $uuid,
        $usuarioId,
        $tipoServicio,
        $tipoVehiculo,
        $empresaId,
        $latOrigen,
        $lngOrigen,
        $data['direccion_origen'],
        $latDestino,
        $lngDestino,
        $data['direccion_destino'],
        $distanciaKm,
        $duracionMin,
        $precioEstimado,
        $metodoPago,
        $idempotencyKey // Clave de idempotencia para evitar duplicados
    ]);
    
    $solicitudId = $db->lastInsertId();

    // Insertar paradas intermedias si existen
    if (isset($data['paradas']) && is_array($data['paradas']) && count($data['paradas']) > 0) {
        $stmtParada = $db->prepare("
            INSERT INTO paradas_solicitud (
                solicitud_id,
                latitud,
                longitud,
                direccion,
                orden,
                estado,
                creado_en
            ) VALUES (?, ?, ?, ?, ?, 'pendiente', NOW())
        ");

        foreach ($data['paradas'] as $index => $parada) {
            // Validar datos de la parada
            if (!isset($parada['latitud']) || !isset($parada['longitud']) || !isset($parada['direccion'])) {
                throw new Exception("Datos incompletos en la parada #" . ($index + 1));
            }

            $stmtParada->execute([
                $solicitudId,
                $parada['latitud'],
                $parada['longitud'],
                $parada['direccion'],
                $index + 1 // Orden basado en el índice (1-based)
            ]);
        }
    }

    // Guardado defensivo de historial: asegura recientes incluso si la app no sincroniza.
    try {
        $origenAddress = trim((string)($data['direccion_origen'] ?? ''));
        $destinoAddress = trim((string)($data['direccion_destino'] ?? ''));

        if ($origenAddress !== '') {
            PlacesSearchService::saveRecentSearch(
                $db,
                (int)$usuarioId,
                recentPlaceNameFromAddress($origenAddress),
                $origenAddress,
                (float)$latOrigen,
                (float)$lngOrigen,
                null
            );
        }

        if ($destinoAddress !== '') {
            PlacesSearchService::saveRecentSearch(
                $db,
                (int)$usuarioId,
                recentPlaceNameFromAddress($destinoAddress),
                $destinoAddress,
                (float)$latDestino,
                (float)$lngDestino,
                null
            );
        }
    } catch (Throwable $recentError) {
        error_log('create_trip_request.php recent_search warning: ' . $recentError->getMessage());
    }
    
    // Confirmar transacción
    $db->commit();
    
    // Cache temporal de solicitud para flujo realtime/matching.
    try {
        cacheRideRequest((int) $solicitudId, $data);
    } catch (Throwable $cacheError) {
        error_log('[create_trip_request] cache warning: ' . $cacheError->getMessage());
    }

    // Buscar conductores cercanos disponibles (si se proporciona tipo_vehiculo)
    $conductoresCercanos = [];
    $pickupEtaPreview = [
        'has_eta' => false,
        'eta_seconds' => null,
        'eta_minutes' => null,
    ];
    $eta = [
        'eta_seconds' => null,
        'distance_km' => round($distanciaKm, 3),
        'traffic_level' => 'moderate',
        'source' => 'fallback',
    ];
    $pricingPreview = [
        'base_price' => round((float)$precioEstimado, 2),
        'traffic_factor' => 1.0,
        'surge' => 1.0,
        'final_price' => round((float)$precioEstimado, 2),
        'zone_key' => null,
    ];
    $surgeMultiplier = 1.0;
    $driverDistance = null;

    try {
    if (isset($data['tipo_vehiculo'])) {
        $vehiculoTipoMap = [
            'moto' => 'moto',
            'auto' => 'auto',
            'mototaxi' => 'mototaxi'
        ];
        $vehiculoTipo = $vehiculoTipoMap[$data['tipo_vehiculo']] ?? 'moto';

        $matchingStart = microtime(true);
        $zoneCacheUsed = false;
        $redis = Cache::redis();
        if ($redis) {
            $cityId = DriverGeoService::getCityIdFromCoordinates($latOrigen, $lngOrigen);
            $gridId = DriverGeoService::gridIdForCoordinates($latOrigen, $lngOrigen);
            $zoneGridId = 'c' . $cityId . ':' . $gridId;
            $zoneKey = 'dispatch:zone_drivers:' . $zoneGridId;
            $cachedDrivers = $redis->zRevRange($zoneKey, 0, 19, true);
            if (!is_array($cachedDrivers) || empty($cachedDrivers)) {
                // Fallback para esquema anterior sin ciudad.
                $cachedDrivers = $redis->zRevRange('dispatch:zone_drivers:' . $gridId, 0, 19, true);
            }
            if (is_array($cachedDrivers) && !empty($cachedDrivers)) {
                $driverIds = [];
                foreach ($cachedDrivers as $driverId => $score) {
                    $id = (int)$driverId;
                    if ($id > 0) {
                        $driverIds[] = $id;
                    }
                }

                if (!empty($driverIds)) {
                    $conductoresCercanos = RideMatchingService::rankCandidatesFromIds(
                        $db,
                        $latOrigen,
                        $lngOrigen,
                        $driverIds,
                        10,
                        $vehiculoTipo,
                        $empresaId,
                        null,
                        $usuarioId
                    );
                    $zoneCacheUsed = true;
                    $redis->incr('metrics:zone_cache_hit');
                    $redis->incr('metrics:dispatch_cache_hits');
                }
            }

            if (!$zoneCacheUsed) {
                $redis->incr('metrics:zone_cache_miss');
                $redis->incr('metrics:dispatch_cache_misses');
            }
        }

        if (!$zoneCacheUsed) {
            $conductoresCercanos = RideMatchingService::rankCandidates(
                $db,
                $latOrigen,
                $lngOrigen,
                5.0,
                10,
                $vehiculoTipo,
                $empresaId,
                $usuarioId
            );
        }

        try {
            $redis = Cache::redis();
            if ($redis) {
                $matchingLatencyMs = (int)round((microtime(true) - $matchingStart) * 1000);
                $redis->incrBy('metrics:matching_latency', max(0, $matchingLatencyMs));
                $redis->incr('metrics:matching_latency_count');
                if ($matchingLatencyMs > 100) {
                    $redis->incr('metrics:matching_latency_over_100ms');
                }
            }
        } catch (Throwable $e) {}

        // ETA de recogida para mejorar UX de confirmación (campo aditivo, no rompe contrato).
        $pickupEtaPreview = PickupEtaService::estimateFromRankedDrivers($latOrigen, $lngOrigen, $conductoresCercanos);

        try {
            $activeStmt = $db->query("SELECT COUNT(*) FROM solicitudes_servicio WHERE estado IN ('pendiente', 'aceptada', 'asignado')");
            $activeRequests = (int)$activeStmt->fetchColumn();
            $availableDrivers = max(1, count($conductoresCercanos));
            $zoneKey = DynamicPricingService::zoneKey($latOrigen, $lngOrigen);
            DynamicPricingService::updateZoneDemand($zoneKey, $activeRequests, $availableDrivers);
        } catch (Throwable $e) {}
    }

    $eta = EtaService::estimate(
        $latOrigen,
        $lngOrigen,
        $latDestino,
        $lngDestino,
        'moderate',
        null
    );

    $pricingPreview = DynamicPricingService::calculate([
        'distance_km' => $distanciaKm,
        'time_min' => $duracionMin,
        'base_fare' => (float)($data['base_fare'] ?? 5000),
        'per_km_rate' => (float)($data['per_km_rate'] ?? 1800),
        'per_min_rate' => (float)($data['per_min_rate'] ?? 250),
        'avg_speed_kmh' => 28,
        'current_speed_kmh' => 24,
        'lat' => $latOrigen,
        'lng' => $lngOrigen,
    ]);

    if (!empty($conductoresCercanos)) {
        $first = $conductoresCercanos[0];
        if (isset($first['distance_km'])) {
            $driverDistance = round((float)$first['distance_km'], 2);
        } elseif (isset($first['driver_distance'])) {
            $driverDistance = round((float)$first['driver_distance'], 2);
        }
    }

    try {
        $redis = Cache::redis();
        if ($redis) {
            $gridId = DriverGeoService::gridIdForCoordinates($latOrigen, $lngOrigen);
            $surgeRaw = $redis->get('surge:grid:' . $gridId);
            $surgePayload = is_string($surgeRaw) ? json_decode($surgeRaw, true) : null;
            if (is_array($surgePayload) && isset($surgePayload['multiplier'])) {
                $surgeMultiplier = max(1.0, (float)$surgePayload['multiplier']);
            }
        }
    } catch (Throwable $e) {}
    } catch (Throwable $postCommitError) {
        error_log('[create_trip_request] post-commit enrichment warning: ' . $postCommitError->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Solicitud creada exitosamente',
        'solicitud_id' => $solicitudId,
        'conductores_encontrados' => count($conductoresCercanos),
        'conductores' => $conductoresCercanos,
        'pickup_eta' => $pickupEtaPreview,
        'pickup_eta_minutes' => $pickupEtaPreview['eta_minutes'] ?? null,
        'surge_multiplier' => round($surgeMultiplier, 2),
        'driver_distance' => $driverDistance,
        'eta' => $eta,
        'pricing_preview' => $pricingPreview,
    ]);

    try {
        $redis = Cache::redis();
        if ($redis) {
            $redis->lPush('dispatch:trip_queue', (string)$solicitudId);
            $redis->lPush('ride_requests_queue', (string)$solicitudId);
            $redis->setex('ride:' . $solicitudId . ':radius', 600, '2');
            $redis->setex('trip:active:' . $solicitudId, 7200, json_encode([
                'status' => 'requested',
                'created_at' => gmdate('c'),
                'cliente_id' => (int)$usuarioId,
                'empresa_id' => $empresaId,
            ], JSON_UNESCAPED_UNICODE));
            $driverIds = array_values(array_map(static fn($x) => (string)($x['driver_id'] ?? $x['id'] ?? ''), $conductoresCercanos));
            $driverIds = array_values(array_filter($driverIds, static fn($x) => $x !== ''));
            if (!empty($driverIds)) {
                $redis->setex('ride:' . $solicitudId . ':drivers', 600, json_encode($driverIds, JSON_UNESCAPED_UNICODE));
            }

            $zoneKey = DriverGeoService::zoneCellKey($latOrigen, $lngOrigen);
            // Hotspots con ventana móvil de 10 minutos.
            $bucketTs = gmdate('YmdHi');
            $bucketKey = 'dispatch:hotspots:bucket:' . $bucketTs;
            $redis->hIncrBy($bucketKey, $zoneKey, 1);
            $redis->expire($bucketKey, 700);

            $score10m = 0;
            for ($i = 0; $i < 10; $i++) {
                $ts = gmdate('YmdHi', time() - ($i * 60));
                $score10m += (int)$redis->hGet('dispatch:hotspots:bucket:' . $ts, $zoneKey);
            }

            $redis->zAdd('dispatch:hotspots:zset', $score10m, $zoneKey);
            $redis->expire('dispatch:hotspots:zset', 3600);
            $hotspots = $redis->zRevRange('dispatch:hotspots:zset', 0, 19, true);
            $redis->setex('dispatch:hotspots', 60, json_encode($hotspots, JSON_UNESCAPED_UNICODE));

            $redis->incr('metrics:dispatch_requests');
        }
    } catch (Throwable $e) {
        error_log('[create_trip_request] dispatch queue warning: ' . $e->getMessage());
    }
    
} catch (Throwable $e) {
    // Revertir transacción en caso de error
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[create_trip_request] fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    
    if (php_sapi_name() !== 'cli') {
        http_response_code(400);
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
