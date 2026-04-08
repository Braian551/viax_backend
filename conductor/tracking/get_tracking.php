<?php
/**
 * API: Obtener datos de tracking del viaje
 * Endpoint: conductor/tracking/get_tracking.php
 * Método: GET
 * 
 * Este endpoint retorna los datos de tracking de un viaje.
 * Puede ser usado tanto por el conductor como por el cliente para ver
 * distancia recorrida, tiempo transcurrido y precio actualizado.
 * 
 * Parámetros:
 * - solicitud_id: ID del viaje (requerido)
 * - incluir_puntos: true/false - si incluir todos los puntos GPS (default: false)
 */

header('Content-Type: application/json');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido. Use GET']);
    exit();
}

require_once '../../config/app.php';
require_once __DIR__ . '/tracking_schema_helpers.php';

function parseWaitSeconds($rawValue): int {
    $value = intval($rawValue ?? 0);
    if ($value < 0) return 0;
    if ($value > 25) return 25;
    return $value;
}

function parseSinceTs($rawValue): ?string {
    if (!is_string($rawValue)) return null;
    $clean = trim($rawValue);
    if ($clean === '') return null;
    return substr($clean, 0, 40);
}

function parseEpochToIsoUtc($epoch): ?string {
    $ts = intval($epoch ?? 0);
    if ($ts <= 0) {
        return null;
    }
    return gmdate('c', $ts);
}

function getLatestTrackingPointFromCache(int $solicitudId): ?array {
    $raw = Cache::get('trip_tracking_latest:' . $solicitudId);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        return null;
    }

    if (!isset($parsed['latitud'], $parsed['longitud'])) {
        return null;
    }

    $isoTs = parseEpochToIsoUtc($parsed['timestamp'] ?? null);

    return [
        'latitud' => floatval($parsed['latitud']),
        'longitud' => floatval($parsed['longitud']),
        'velocidad' => 0,
        'distancia_acumulada_km' => floatval($parsed['distancia_km'] ?? 0),
        'tiempo_transcurrido_seg' => intval($parsed['tiempo_seg'] ?? 0),
        'precio_parcial' => floatval($parsed['precio_parcial'] ?? 0),
        'fase_viaje' => $parsed['fase_viaje'] ?? 'hacia_destino',
        'timestamp_gps' => $isoTs,
        '__source' => 'redis',
    ];
}

function getLatestTrackingPointFromMetricsCache(int $solicitudId): ?array {
    $redis = Cache::redis();
    if (!$redis) {
        return null;
    }

    $rawState = Cache::get('trip:' . $solicitudId . ':state');
    $state = is_string($rawState) ? json_decode($rawState, true) : null;
    if (!is_array($state) || !isset($state['lat'], $state['lng'])) {
        return null;
    }

    $rawMetrics = $redis->get('trip:' . $solicitudId . ':metrics');
    $metrics = is_string($rawMetrics) ? json_decode($rawMetrics, true) : null;
    if (!is_array($metrics)) {
        $legacy = $redis->hGetAll('trip:' . $solicitudId . ':metrics');
        if (is_array($legacy) && !empty($legacy)) {
            $metrics = [
                'distance_km' => isset($legacy['distance_total_km']) ? floatval($legacy['distance_total_km']) : 0,
                'elapsed_time_sec' => isset($legacy['elapsed_time_sec']) ? intval($legacy['elapsed_time_sec']) : 0,
                'price' => isset($legacy['price']) ? floatval($legacy['price']) : 0,
            ];
        } else {
            $metrics = [];
        }
    }

    return [
        'latitud' => floatval($state['lat']),
        'longitud' => floatval($state['lng']),
        'velocidad' => isset($state['speed']) ? floatval($state['speed']) : 0,
        'distancia_acumulada_km' => isset($metrics['distance_km']) ? floatval($metrics['distance_km']) : 0,
        'tiempo_transcurrido_seg' => isset($metrics['elapsed_time_sec']) ? intval($metrics['elapsed_time_sec']) : 0,
        'precio_parcial' => isset($metrics['price']) ? floatval($metrics['price']) : 0,
        'fase_viaje' => $state['fase'] ?? 'hacia_destino',
        'timestamp_gps' => $state['timestamp'] ?? gmdate('c'),
        '__source' => 'redis_metrics',
    ];
}

function getLatestTrackingPoint(PDO $db, int $solicitudId): ?array {
    $ultimo_punto = getLatestTrackingPointFromCache($solicitudId);
    if ($ultimo_punto) {
        return $ultimo_punto;
    }

    $metricsPoint = getLatestTrackingPointFromMetricsCache($solicitudId);
    if ($metricsPoint) {
        return $metricsPoint;
    }

    $stmt = $db->prepare("\n        SELECT to_regclass('public.viaje_tracking_snapshot') AS table_name
    ");
    $stmt->execute();
    $snapshot_info = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($snapshot_info['table_name'])) {
        $stmt = $db->prepare("\n            SELECT
                latitud,
                longitud,
                    0::numeric AS velocidad,
                distancia_acumulada_km,
                tiempo_transcurrido_seg,
                precio_parcial,
                fase_viaje,
                actualizado_en AS timestamp_gps
            FROM viaje_tracking_snapshot
            WHERE solicitud_id = :solicitud_id
            LIMIT 1
        ");
        $stmt->execute([':solicitud_id' => $solicitudId]);
        $ultimo_punto = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$ultimo_punto) {
        $stmt = $db->prepare("\n            SELECT
                latitud,
                longitud,
                velocidad,
                distancia_acumulada_km,
                tiempo_transcurrido_seg,
                precio_parcial,
                fase_viaje,
                timestamp_gps
            FROM viaje_tracking_realtime
            WHERE solicitud_id = :solicitud_id
            ORDER BY timestamp_gps DESC
            LIMIT 1
        ");
        $stmt->execute([':solicitud_id' => $solicitudId]);
        $ultimo_punto = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    return $ultimo_punto ?: null;
}

function trackingTimestampToEpoch(?array $trackingPoint): int {
    if (!$trackingPoint || empty($trackingPoint['timestamp_gps'])) {
        return 0;
    }
    $ts = strtotime((string)$trackingPoint['timestamp_gps']);
    return $ts !== false ? $ts : 0;
}

function positiveFloatOrNull($value): ?float {
    if ($value === null || $value === '') {
        return null;
    }
    $parsed = floatval($value);
    return $parsed > 0 ? $parsed : null;
}

function positiveIntOrNull($value): ?int {
    if ($value === null || $value === '') {
        return null;
    }
    $parsed = intval($value);
    return $parsed > 0 ? $parsed : null;
}

function maxFloatOrZero(array $values): float {
    $filtered = array_values(array_filter($values, static fn($value) => $value !== null && $value > 0));
    if (empty($filtered)) {
        return 0.0;
    }
    return (float) max($filtered);
}

function maxIntOrZero(array $values): int {
    $filtered = array_values(array_filter($values, static fn($value) => $value !== null && $value > 0));
    if (empty($filtered)) {
        return 0;
    }
    return (int) max($filtered);
}

function isTerminalTripState(string $estado): bool {
    $normalized = strtolower(trim($estado));
    return in_array($normalized, [
        'completada',
        'completado',
        'finalizada',
        'finalizado',
        'entregado',
        'cancelada',
        'cancelado',
        'rechazada',
        'rechazado',
        'rejected',
    ], true);
}

function nullOrFloat($value): ?float {
    if ($value === null || $value === '') {
        return null;
    }
    return floatval($value);
}

function nullOrInt($value): ?int {
    if ($value === null || $value === '') {
        return null;
    }
    return intval($value);
}

function buildCanonicalTerminalTrackingActual(array $solicitud, ?array $resumen, ?array $ultimoPunto, bool $metricsLocked): array {
    // En estado terminal, las métricas canónicas deben prevalecer para
    // evitar que latencia o polling fuera de orden desalineen cliente/conductor.
    $distanceFinal = nullOrFloat($solicitud['distance_final'] ?? null);
    $durationFinal = nullOrInt($solicitud['duration_final'] ?? null);
    $priceFinalCanonical = nullOrFloat($solicitud['price_final_en'] ?? null);

    if ($distanceFinal === null) {
        $distanceFinal = nullOrFloat($resumen['distancia_real_km'] ?? null)
            ?? nullOrFloat($solicitud['distancia_recorrida'] ?? null)
            ?? nullOrFloat($ultimoPunto['distancia_acumulada_km'] ?? null);
    }

    if ($durationFinal === null) {
        $durationFinal = nullOrInt(isset($resumen['tiempo_real_minutos']) ? intval($resumen['tiempo_real_minutos']) * 60 : null)
            ?? nullOrInt($solicitud['tiempo_transcurrido'] ?? null)
            ?? nullOrInt($ultimoPunto['tiempo_transcurrido_seg'] ?? null);
    }

    if ($priceFinalCanonical === null) {
        $priceFinalCanonical = nullOrFloat($resumen['precio_final_aplicado'] ?? null)
            ?? nullOrFloat($solicitud['precio_final'] ?? null)
            ?? nullOrFloat($ultimoPunto['precio_parcial'] ?? null);
    }

    $distanciaKm = $distanceFinal ?? 0.0;
    $tiempoSegundos = $durationFinal ?? 0;
    $precioActual = $priceFinalCanonical ?? 0.0;

    $ultimaActualizacion = $resumen['actualizado_en']
        ?? ($solicitud['finalized_at'] ?? null)
        ?? $solicitud['completado_en']
        ?? $ultimoPunto['timestamp_gps']
        ?? null;

    error_log('[CanonicalMetricsUsed] trip_id=' . intval($solicitud['id'] ?? 0)
        . ' locked=' . ($metricsLocked ? '1' : '0')
        . ' distance_final=' . $distanciaKm
        . ' duration_final=' . $tiempoSegundos
        . ' price_final=' . $precioActual);

    return [
        'ubicacion' => null,
        'velocidad_kmh' => 0,
        'distancia_km' => $distanciaKm,
        'tiempo_segundos' => $tiempoSegundos,
        'tiempo_minutos' => (int) ceil($tiempoSegundos / 60),
        'precio_actual' => $precioActual,
        'fase' => 'finalizado',
        'ultima_actualizacion' => $ultimaActualizacion,
    ];
}

try {
    $solicitud_id = isset($_GET['solicitud_id']) ? intval($_GET['solicitud_id']) : 0;
    $incluir_puntos = isset($_GET['incluir_puntos']) && $_GET['incluir_puntos'] === 'true';
    $wait_seconds = parseWaitSeconds($_GET['wait_seconds'] ?? 0);
    $since_ts = parseSinceTs($_GET['since_ts'] ?? null);
    
    if ($solicitud_id <= 0) {
        throw new Exception('solicitud_id es requerido');
    }
    
    $database = new Database();
    $db = $database->getConnection();
    // Obtener el último punto y si aplica, esperar cambios (long-polling).
    $ultimo_punto = getLatestTrackingPoint($db, $solicitud_id);

    if ($wait_seconds > 0 && $since_ts !== null) {
        $since_epoch = strtotime($since_ts);
        if ($since_epoch === false) {
            $since_epoch = 0;
        }

        $deadline = microtime(true) + $wait_seconds;
        while (microtime(true) < $deadline) {
            $current_epoch = trackingTimestampToEpoch($ultimo_punto);
            if ($current_epoch > $since_epoch) {
                break;
            }

            usleep(300000);
            $ultimo_punto = getLatestTrackingPoint($db, $solicitud_id);
        }
    }
    // Obtener resumen del tracking
    $stmt = $db->prepare("
        SELECT 
            distancia_real_km,
            tiempo_real_minutos,
            precio_final_aplicado,
            velocidad_promedio_kmh,
            velocidad_maxima_kmh,
            total_puntos_gps,
            tiene_desvio_ruta,
            inicio_viaje_real,
            actualizado_en
        FROM viaje_resumen_tracking
        WHERE solicitud_id = :solicitud_id
    ");
    $stmt->execute([':solicitud_id' => $solicitud_id]);
    $resumen = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $hasMetricsLocked = trackingColumnExists($db, 'solicitudes_servicio', 'metrics_locked');
    $hasDistanceFinal = trackingColumnExists($db, 'solicitudes_servicio', 'distance_final');
    $hasDurationFinal = trackingColumnExists($db, 'solicitudes_servicio', 'duration_final');
    $hasFinalizedAt = trackingColumnExists($db, 'solicitudes_servicio', 'finalized_at');
    $hasPriceFinalEn = trackingColumnExists($db, 'solicitudes_servicio', 'price_final');

    // Obtener datos de la solicitud
    $metricsLockedExpr = $hasMetricsLocked ? 's.metrics_locked' : 'FALSE AS metrics_locked';
    $distanceFinalExpr = $hasDistanceFinal ? 's.distance_final' : 'NULL::numeric AS distance_final';
    $durationFinalExpr = $hasDurationFinal ? 's.duration_final' : 'NULL::integer AS duration_final';
    $finalizedAtExpr = $hasFinalizedAt ? 's.finalized_at' : 'NULL::timestamp AS finalized_at';
    $priceFinalEnExpr = $hasPriceFinalEn ? 's.price_final AS price_final_en' : 'NULL::numeric AS price_final_en';

    $stmt = $db->prepare("
        SELECT 
            s.id,
            s.tipo_servicio,
            s.estado,
            $metricsLockedExpr,
            $distanceFinalExpr,
            $durationFinalExpr,
            $finalizedAtExpr,
            s.distancia_estimada,
            s.tiempo_estimado,
            s.precio_estimado,
            s.precio_final,
            $priceFinalEnExpr,
            s.distancia_recorrida,
            s.tiempo_transcurrido,
            s.completado_en,
            s.direccion_recogida,
            s.direccion_destino,
            s.metodo_pago
        FROM solicitudes_servicio s
        WHERE s.id = :solicitud_id
    ");
    $stmt->execute([':solicitud_id' => $solicitud_id]);
    $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$solicitud) {
        throw new Exception('Viaje no encontrado');
    }
    
    // Preparar respuesta
    $response = [
        'success' => true,
        'meta' => [
            'generated_at' => gmdate('c'),
            'wait_seconds' => $wait_seconds,
            'since_ts' => $since_ts,
        ],
        'solicitud_id' => $solicitud_id,
        'viaje' => [
            'estado' => $solicitud['estado'],
            'tipo_servicio' => $solicitud['tipo_servicio'],
            'origen' => $solicitud['direccion_recogida'],
            'destino' => $solicitud['direccion_destino'],
            'metodo_pago' => $solicitud['metodo_pago']
        ],
        'estimados' => [
            'distancia_km' => floatval($solicitud['distancia_estimada']),
            'tiempo_minutos' => intval($solicitud['tiempo_estimado']),
            'precio' => floatval($solicitud['precio_estimado'])
        ],
        'tracking_actual' => null,
        'resumen' => null,
        'comparacion' => null
    ];
    
    $estadoTerminal = isTerminalTripState((string)($solicitud['estado'] ?? ''));
    $metricsLocked = !empty($solicitud['metrics_locked']) && intval($solicitud['metrics_locked']) === 1;

    // Si el viaje ya terminó, responder siempre con métricas finales canónicas.
    if ($estadoTerminal) {
        $trackingFinal = buildCanonicalTerminalTrackingActual(
            $solicitud,
            $resumen ?: null,
            $ultimo_punto ?: null,
            $metricsLocked
        );
        $response['tracking_actual'] = $trackingFinal;
        $response['meta']['latest_tracking_ts'] = $trackingFinal['ultima_actualizacion'];
        $response['meta']['metrics_locked'] = $metricsLocked;

        // Campos canónicos explícitos.
        $response['status'] = $solicitud['estado'];
        $response['distance_final'] = $trackingFinal['distancia_km'];
        $response['duration_final'] = $trackingFinal['tiempo_segundos'];
        $response['price_final'] = $trackingFinal['precio_actual'];
        $response['metrics_locked'] = $metricsLocked;
        $response['finalized_at'] = $solicitud['finalized_at'] ?? $trackingFinal['ultima_actualizacion'];

        $diff_distancia = $trackingFinal['distancia_km'] - floatval($solicitud['distancia_estimada']);
        $diff_tiempo = $trackingFinal['tiempo_minutos'] - intval($solicitud['tiempo_estimado']);
        $diff_precio = $trackingFinal['precio_actual'] - floatval($solicitud['precio_estimado']);

        $response['comparacion'] = [
            'diferencia_distancia_km' => round($diff_distancia, 2),
            'diferencia_tiempo_min' => $diff_tiempo,
            'diferencia_precio' => round($diff_precio, 2),
            'porcentaje_distancia' => $solicitud['distancia_estimada'] > 0
                ? round(($diff_distancia / floatval($solicitud['distancia_estimada'])) * 100, 1)
                : 0,
            'mensaje' => generarMensajeComparacion($diff_distancia, $diff_tiempo)
        ];
    }
    // Si hay datos de tracking en curso
    else if ($ultimo_punto) {
        $distancia_actual = floatval($ultimo_punto['distancia_acumulada_km']);
        $tiempo_actual_seg = intval($ultimo_punto['tiempo_transcurrido_seg']);
        $tiempo_actual_min = ceil($tiempo_actual_seg / 60);
        $precio_actual = floatval($ultimo_punto['precio_parcial']);
        
        $response['tracking_actual'] = [
            'ubicacion' => [
                'latitud' => floatval($ultimo_punto['latitud']),
                'longitud' => floatval($ultimo_punto['longitud'])
            ],
            'velocidad_kmh' => floatval($ultimo_punto['velocidad']),
            'distancia_km' => $distancia_actual,
            'tiempo_segundos' => $tiempo_actual_seg,
            'tiempo_minutos' => $tiempo_actual_min,
            'precio_actual' => $precio_actual,
            'fase' => $ultimo_punto['fase_viaje'],
            'ultima_actualizacion' => $ultimo_punto['timestamp_gps']
        ];

        $response['meta']['latest_tracking_ts'] = $ultimo_punto['timestamp_gps'];
        if (!empty($ultimo_punto['__source'])) {
            $response['meta']['tracking_source'] = $ultimo_punto['__source'];
        }
        
        // Calcular diferencias con estimados
        $diff_distancia = $distancia_actual - floatval($solicitud['distancia_estimada']);
        $diff_tiempo = $tiempo_actual_min - intval($solicitud['tiempo_estimado']);
        $diff_precio = $precio_actual - floatval($solicitud['precio_estimado']);
        
        $response['comparacion'] = [
            'diferencia_distancia_km' => round($diff_distancia, 2),
            'diferencia_tiempo_min' => $diff_tiempo,
            'diferencia_precio' => round($diff_precio, 2),
            'porcentaje_distancia' => $solicitud['distancia_estimada'] > 0 
                ? round(($diff_distancia / floatval($solicitud['distancia_estimada'])) * 100, 1) 
                : 0,
            'mensaje' => generarMensajeComparacion($diff_distancia, $diff_tiempo)
        ];
    }
    
    // Si hay resumen
    if ($resumen) {
        $response['resumen'] = [
            'distancia_total_km' => floatval($resumen['distancia_real_km']),
            'tiempo_total_minutos' => intval($resumen['tiempo_real_minutos']),
            'precio_final' => floatval($resumen['precio_final_aplicado']),
            'velocidad_promedio_kmh' => floatval($resumen['velocidad_promedio_kmh']),
            'velocidad_maxima_kmh' => floatval($resumen['velocidad_maxima_kmh']),
            'total_puntos_registrados' => intval($resumen['total_puntos_gps']),
            'hubo_desvio' => $resumen['tiene_desvio_ruta'],
            'inicio' => $resumen['inicio_viaje_real'],
            'ultima_actualizacion' => $resumen['actualizado_en']
        ];
    }
    
    // Incluir todos los puntos si se solicita
    if ($incluir_puntos) {
        $stmt = $db->prepare("
            SELECT 
                latitud, longitud, velocidad, bearing,
                distancia_acumulada_km, tiempo_transcurrido_seg,
                precio_parcial, fase_viaje, evento, timestamp_gps
            FROM viaje_tracking_realtime
            WHERE solicitud_id = :solicitud_id
            ORDER BY timestamp_gps ASC
        ");
        $stmt->execute([':solicitud_id' => $solicitud_id]);
        $puntos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $response['puntos'] = array_map(function($p) {
            return [
                'lat' => floatval($p['latitud']),
                'lng' => floatval($p['longitud']),
                'vel' => floatval($p['velocidad']),
                'dist' => floatval($p['distancia_acumulada_km']),
                'tiempo' => intval($p['tiempo_transcurrido_seg']),
                'precio' => floatval($p['precio_parcial']),
                'ts' => $p['timestamp_gps']
            ];
        }, $puntos);
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Genera un mensaje descriptivo sobre la comparación con estimados
 */
function generarMensajeComparacion($diff_km, $diff_min) {
    $mensajes = [];
    
    if ($diff_km > 1) {
        $mensajes[] = "Recorrido " . round($diff_km, 1) . " km más de lo estimado";
    } elseif ($diff_km < -1) {
        $mensajes[] = "Ruta " . round(abs($diff_km), 1) . " km más corta que lo estimado";
    }
    
    if ($diff_min > 5) {
        $mensajes[] = "Viaje tomando $diff_min minutos adicionales";
    } elseif ($diff_min < -5) {
        $mensajes[] = "Llegando " . abs($diff_min) . " minutos antes de lo estimado";
    }
    
    if (empty($mensajes)) {
        return "Viaje dentro de lo estimado";
    }
    
    return implode(". ", $mensajes);
}
