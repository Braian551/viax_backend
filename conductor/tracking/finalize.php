<?php
/**
 * API: Finalizar tracking y calcular precio final
 * Endpoint: conductor/tracking/finalize.php
 * Método: POST
 * 
 * Este endpoint se llama cuando el viaje termina para:
 * 1. Cerrar el tracking
 * 2. Calcular el precio final basado en distancia/tiempo REAL
 * 3. Calcular TODOS los recargos: nocturno, hora pico, festivo, espera
 * 4. Aplicar la comisión REAL de la empresa
 * 5. Actualizar la solicitud con los valores finales
 * 6. Retornar el desglose completo del precio
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/tracking_schema_helpers.php';
require_once __DIR__ . '/../../services/traffic_zone_resolver.php';
require_once __DIR__ . '/../../services/traffic_cache.php';
require_once __DIR__ . '/../../services/traffic_pricing.php';
require_once __DIR__ . '/../../services/traffic_service.php';

function normalizarTipoVehiculoTracking($tipoVehiculo) {
    $normalized = strtolower(trim((string)$tipoVehiculo));
    $aliases = [
        'mototaxi' => 'moto',
        'motocarro' => 'moto',
        'moto_carga' => 'moto',
        'motorcycle' => 'moto',
        'auto' => 'carro',
        'automovil' => 'carro',
        'car' => 'carro',
    ];

    if (isset($aliases[$normalized])) {
        return $aliases[$normalized];
    }

    return $normalized !== '' ? $normalized : 'moto';
}

function configPreciosFallbackFinalize() {
    return [
        'id' => null,
        'tarifa_base' => 0,
        'costo_por_km' => 0,
        'costo_por_minuto' => 0,
        'tarifa_minima' => 0,
        'tarifa_maxima' => null,
        'comision_plataforma' => 0,
        'recargo_hora_pico' => 0,
        'hora_pico_inicio_manana' => '07:00:00',
        'hora_pico_fin_manana' => '09:00:00',
        'hora_pico_inicio_tarde' => '17:00:00',
        'hora_pico_fin_tarde' => '19:00:00',
        'recargo_nocturno' => 0,
        'hora_nocturna_inicio' => '22:00:00',
        'hora_nocturna_fin' => '06:00:00',
        'recargo_festivo' => 0,
        'umbral_km_descuento' => 15,
        'descuento_distancia_larga' => 0,
        'tiempo_espera_gratis' => 3,
        'costo_tiempo_espera' => 0,
        '__fallback' => true,
    ];
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

function formatDateTimeCoAmPm(DateTimeInterface $dateTime): string {
    $ampm = strtolower($dateTime->format('a')) === 'am' ? 'a. m.' : 'p. m.';
    return $dateTime->format('d/m/Y h:i') . ' ' . $ampm;
}

function resolveCanonicalFinalMetrics(array $viaje, ?array $ultimoTracking, ?array $redisMetrics, ?float $bodyDistanciaKm, ?int $bodyTiempoSeg): array {
    $distanciaReal = maxFloatOrZero([
        positiveFloatOrNull($redisMetrics['distance_km'] ?? null),
        positiveFloatOrNull($bodyDistanciaKm),
        positiveFloatOrNull($ultimoTracking['distancia_acumulada_km'] ?? null),
        positiveFloatOrNull($viaje['distancia_recorrida'] ?? null),
    ]);

    $tiempoRealSeg = maxIntOrZero([
        positiveIntOrNull($redisMetrics['elapsed_time_sec'] ?? null),
        positiveIntOrNull($bodyTiempoSeg),
        positiveIntOrNull($ultimoTracking['tiempo_transcurrido_seg'] ?? null),
        positiveIntOrNull($viaje['tiempo_transcurrido'] ?? null),
    ]);

    return [
        'distancia_real_km' => $distanciaReal,
        'tiempo_real_seg' => $tiempoRealSeg,
    ];
}

function readCanonicalMetricsFromRedis(int $solicitudId): ?array {
    $redis = Cache::redis();
    if (!$redis) {
        return null;
    }

    try {
        $raw = $redis->get('trip:' . $solicitudId . ':metrics');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $legacy = $redis->hGetAll('trip:' . $solicitudId . ':metrics');
        if (is_array($legacy) && !empty($legacy)) {
            return [
                'distance_km' => isset($legacy['distance_total_km']) ? floatval($legacy['distance_total_km']) : 0,
                'elapsed_time_sec' => isset($legacy['elapsed_time_sec']) ? intval($legacy['elapsed_time_sec']) : 0,
            ];
        }
    } catch (Throwable $e) {
        error_log('[finalize] redis metrics warning: ' . $e->getMessage());
    }

    return null;
}

function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earthRadius = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
        * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

function fetchGpsRowsForReconciliation(PDO $db, int $solicitudId): array {
    if (trackingTableExists($db, 'trip_tracking_points')) {
        $stmt = $db->prepare(" 
            SELECT
                lat,
                lng,
                speed,
                accuracy,
                timestamp
            FROM trip_tracking_points
            WHERE trip_id = :solicitud_id
            ORDER BY timestamp ASC
        ");
        $stmt->execute([':solicitud_id' => $solicitudId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $stmt = $db->prepare(" 
        SELECT
            latitud AS lat,
            longitud AS lng,
            velocidad AS speed,
            precision_gps AS accuracy,
            COALESCE(timestamp_gps, timestamp_servidor) AS timestamp
        FROM viaje_tracking_realtime
        WHERE solicitud_id = :solicitud_id
        ORDER BY COALESCE(timestamp_gps, timestamp_servidor) ASC
    ");
    $stmt->execute([':solicitud_id' => $solicitudId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function reconcileDistanceKmFromGps(PDO $db, int $solicitudId, float $fallbackKm): array {
    $rows = fetchGpsRowsForReconciliation($db, $solicitudId);
    if (empty($rows)) {
        return [
            'distance_km' => max(0.0, $fallbackKm),
            'accepted_points' => 0,
            'rejected_points' => 0,
        ];
    }

    $distanceMeters = 0.0;
    $accepted = 0;
    $rejected = 0;
    $prev = null;

    foreach ($rows as $row) {
        $lat = floatval($row['lat'] ?? 0);
        $lng = floatval($row['lng'] ?? 0);
        $speed = isset($row['speed']) ? floatval($row['speed']) : 0.0;
        $accuracy = isset($row['accuracy']) ? floatval($row['accuracy']) : 0.0;
        $timestamp = strtotime((string)($row['timestamp'] ?? ''));

        if ($timestamp === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            $rejected++;
            continue;
        }

        // Se descarta baja calidad GPS para no inflar métricas con ruido.
        if ($accuracy > 100) {
            $rejected++;
            continue;
        }

        // Se descarta velocidad físicamente improbable para viajes urbanos.
        if ($speed > 150) {
            $rejected++;
            continue;
        }

        $current = [
            'lat' => $lat,
            'lng' => $lng,
            'ts' => $timestamp,
        ];

        if ($prev !== null) {
            $deltaSec = max(0, $current['ts'] - $prev['ts']);
            $jumpMeters = haversineMeters($prev['lat'], $prev['lng'], $current['lat'], $current['lng']);

            // Salto imposible: >200m en menos de 2s indica punto corrupto/atrasado.
            if ($deltaSec < 2 && $jumpMeters > 200) {
                $rejected++;
                continue;
            }

            // Descarta saltos con velocidad implausible aunque vengan con velocidad GPS en 0.
            if ($deltaSec > 0) {
                $computedSpeedKmh = ($jumpMeters / 1000.0) / ($deltaSec / 3600.0);
                if ($computedSpeedKmh > 180) {
                    $rejected++;
                    continue;
                }
            } elseif ($jumpMeters > 120) {
                $rejected++;
                continue;
            }

            // Aun con delta mayor, un salto muy grande en poco tiempo suele ser GPS drift.
            if ($deltaSec <= 30 && $jumpMeters > 1000) {
                $rejected++;
                continue;
            }

            // Duplicado o jitter ínfimo, no suma distancia.
            if ($jumpMeters < 1.0) {
                $accepted++;
                $prev = $current;
                continue;
            }

            $distanceMeters += $jumpMeters;
        }

        $accepted++;
        $prev = $current;
    }

    $distanceKm = $distanceMeters / 1000.0;

    return [
        // Monotónico: nunca bajar por latencia o pérdida parcial de puntos.
        'distance_km' => max($distanceKm, $fallbackKm, 0.0),
        'accepted_points' => $accepted,
        'rejected_points' => $rejected,
    ];
}

function resolveDurationSegFromServer(PDO $db, int $solicitudId, int $fallbackSeg): int {
    if (trackingTableExists($db, 'trip_tracking_points')) {
        $stmt = $db->prepare(" 
            SELECT
                MIN(timestamp) AS ts_inicio,
                MAX(timestamp) AS ts_fin
            FROM trip_tracking_points
            WHERE trip_id = :solicitud_id
        ");
        $stmt->execute([':solicitud_id' => $solicitudId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $ini = strtotime((string)($row['ts_inicio'] ?? ''));
        $fin = strtotime((string)($row['ts_fin'] ?? ''));
        if ($ini !== false && $fin !== false && $fin >= $ini) {
            return max($fallbackSeg, $fin - $ini, 0);
        }
    }

    $stmt = $db->prepare(" 
        SELECT
            MIN(COALESCE(timestamp_gps, timestamp_servidor)) AS ts_inicio,
            MAX(COALESCE(timestamp_gps, timestamp_servidor)) AS ts_fin
        FROM viaje_tracking_realtime
        WHERE solicitud_id = :solicitud_id
    ");
    $stmt->execute([':solicitud_id' => $solicitudId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $ini = strtotime((string)($row['ts_inicio'] ?? ''));
    $fin = strtotime((string)($row['ts_fin'] ?? ''));

    if ($ini !== false && $fin !== false && $fin >= $ini) {
        return max($fallbackSeg, $fin - $ini, 0);
    }

    return max(0, $fallbackSeg);
}

function clampDistanceByDuration(float $distanceKm, int $durationSeg): float {
    // IMPORTANTE:
    // Evita que un salto de GPS o latencia termine bloqueando métricas imposibles.
    // Regla conservadora: máximo 130 km/h + margen pequeño.
    if ($durationSeg <= 0) {
        return min($distanceKm, 0.2);
    }

    $maxDistanceKm = (($durationSeg / 3600.0) * 130.0) + 0.2;
    return min($distanceKm, max(0.0, $maxDistanceKm));
}

function buildLockedSummaryResponse(PDO $db, int $solicitudId, int $conductorId): array {
    $stmt = $db->prepare(" 
        SELECT
            s.id,
            s.precio_final,
            s.distancia_recorrida,
            s.tiempo_transcurrido,
            s.precio_estimado,
            s.distancia_estimada,
            s.tiempo_estimado,
            s.empresa_id,
            s.tipo_vehiculo,
            vrt.precio_final_aplicado,
            vrt.distancia_real_km,
            vrt.tiempo_real_minutos,
            vrt.actualizado_en
        FROM solicitudes_servicio s
        LEFT JOIN viaje_resumen_tracking vrt ON vrt.solicitud_id = s.id
        WHERE s.id = :solicitud_id
        LIMIT 1
    ");
    $stmt->execute([':solicitud_id' => $solicitudId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $precioFinal = maxFloatOrZero([
        positiveFloatOrNull($row['precio_final_aplicado'] ?? null),
        positiveFloatOrNull($row['precio_final'] ?? null),
        positiveFloatOrNull($row['precio_estimado'] ?? null),
    ]);

    $distanciaReal = maxFloatOrZero([
        positiveFloatOrNull($row['distancia_real_km'] ?? null),
        positiveFloatOrNull($row['distancia_recorrida'] ?? null),
    ]);

    $tiempoRealSeg = maxIntOrZero([
        positiveIntOrNull(isset($row['tiempo_real_minutos']) ? intval($row['tiempo_real_minutos']) * 60 : null),
        positiveIntOrNull($row['tiempo_transcurrido'] ?? null),
    ]);

    $tiempoRealMin = (int) ceil($tiempoRealSeg / 60);

    return [
        'success' => true,
        'message' => 'Métricas finales ya estaban congeladas',
        'precio_final' => $precioFinal,
        'tracking' => [
            'distancia_real_km' => round($distanciaReal, 2),
            'tiempo_real_min' => $tiempoRealMin,
            'tiempo_real_seg' => $tiempoRealSeg,
            'distancia_estimada_km' => floatval($row['distancia_estimada'] ?? 0),
            'tiempo_estimado_min' => intval($row['tiempo_estimado'] ?? 0),
        ],
        'comparacion_precio' => [
            'precio_estimado' => floatval($row['precio_estimado'] ?? 0),
            'precio_final' => $precioFinal,
            'diferencia' => $precioFinal - floatval($row['precio_estimado'] ?? 0),
        ],
        'meta' => [
            'metrics_locked' => true,
            'empresa_id' => $row['empresa_id'] ?? null,
            'config_precios_id' => null,
            'tipo_vehiculo' => $row['tipo_vehiculo'] ?? 'moto',
            'conductor_id' => $conductorId,
        ],
    ];
}

function obtenerConfigPreciosTrackingFinalize(PDO $db, $empresaId, $tipoVehiculoRaw) {
    $tipoNormalizado = normalizarTipoVehiculoTracking($tipoVehiculoRaw);
    $tiposCandidatos = array_values(array_unique([$tipoNormalizado, 'moto']));

    foreach ($tiposCandidatos as $tipo) {
        if ($empresaId) {
            $stmt = $db->prepare(" 
                SELECT 
                    id,
                    tarifa_base,
                    costo_por_km,
                    costo_por_minuto,
                    tarifa_minima,
                    tarifa_maxima,
                    comision_plataforma,
                    recargo_hora_pico,
                    hora_pico_inicio_manana,
                    hora_pico_fin_manana,
                    hora_pico_inicio_tarde,
                    hora_pico_fin_tarde,
                    recargo_nocturno,
                    hora_nocturna_inicio,
                    hora_nocturna_fin,
                    recargo_festivo,
                    umbral_km_descuento,
                    descuento_distancia_larga,
                    tiempo_espera_gratis,
                    costo_tiempo_espera
                FROM configuracion_precios 
                WHERE empresa_id = :empresa_id AND tipo_vehiculo = :tipo AND activo = 1
                LIMIT 1
            ");
            $stmt->execute([':empresa_id' => $empresaId, ':tipo' => $tipo]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($config) {
                $config['__fallback'] = false;
                return $config;
            }
        }

        $stmt = $db->prepare(" 
            SELECT 
                id,
                tarifa_base,
                costo_por_km,
                costo_por_minuto,
                tarifa_minima,
                tarifa_maxima,
                comision_plataforma,
                recargo_hora_pico,
                hora_pico_inicio_manana,
                hora_pico_fin_manana,
                hora_pico_inicio_tarde,
                hora_pico_fin_tarde,
                recargo_nocturno,
                hora_nocturna_inicio,
                hora_nocturna_fin,
                recargo_festivo,
                umbral_km_descuento,
                descuento_distancia_larga,
                tiempo_espera_gratis,
                costo_tiempo_espera
            FROM configuracion_precios 
            WHERE empresa_id IS NULL AND tipo_vehiculo = :tipo AND activo = 1
            LIMIT 1
        ");
        $stmt->execute([':tipo' => $tipo]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($config) {
            $config['__fallback'] = false;
            return $config;
        }
    }

    return configPreciosFallbackFinalize();
}

try {
    if (isset($GLOBALS['__trip_finalize_payload_bridge']) && is_array($GLOBALS['__trip_finalize_payload_bridge'])) {
        $input = $GLOBALS['__trip_finalize_payload_bridge'];
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
    }
    if (!is_array($input)) {
        throw new Exception('JSON inválido');
    }
    
    // Validar campos requeridos
    $solicitud_id = isset($input['solicitud_id']) ? intval($input['solicitud_id']) : 0;
    $conductor_id = isset($input['conductor_id']) ? intval($input['conductor_id']) : 0;
    
    // Valores finales del tracking (enviados por la app del conductor)
    $distancia_final_km = isset($input['distancia_final_km']) ? floatval($input['distancia_final_km']) : null;
    $tiempo_final_seg = isset($input['tiempo_final_seg']) ? intval($input['tiempo_final_seg']) : null;
    // Tiempo de espera adicional (si el cliente se demoró)
    $tiempo_espera_min = isset($input['tiempo_espera_min']) ? intval($input['tiempo_espera_min']) : 0;
    
    if ($solicitud_id <= 0 || $conductor_id <= 0) {
        throw new Exception('solicitud_id y conductor_id son requeridos');
    }
    
    $database = new Database();
    $db = $database->getConnection();

    $hasMetricsLocked = trackingColumnExists($db, 'solicitudes_servicio', 'metrics_locked');
    $hasDistanceFinal = trackingColumnExists($db, 'solicitudes_servicio', 'distance_final');
    $hasDurationFinal = trackingColumnExists($db, 'solicitudes_servicio', 'duration_final');
    $hasCompletedAt = trackingColumnExists($db, 'solicitudes_servicio', 'completed_at');
    $hasPriceFinalEn = trackingColumnExists($db, 'solicitudes_servicio', 'price_final');
    $hasGpsPointsCount = trackingColumnExists($db, 'solicitudes_servicio', 'gps_points_count');
    
    $db->beginTransaction();
    
    // Obtener datos del viaje
    $metricsLockedExpr = $hasMetricsLocked
        ? 'COALESCE(s.metrics_locked, FALSE) AS metrics_locked,'
        : 'FALSE AS metrics_locked,';

    $distanceFinalExpr = $hasDistanceFinal
        ? 's.distance_final,'
        : 'NULL::numeric AS distance_final,';

    $durationFinalExpr = $hasDurationFinal
        ? 's.duration_final,'
        : 'NULL::integer AS duration_final,';

    $completedAtExpr = $hasCompletedAt
        ? 's.completed_at,'
        : 'NULL::timestamp AS completed_at,';

    $priceFinalEnExpr = $hasPriceFinalEn
        ? 's.price_final,'
        : 'NULL::numeric AS price_final_en,';

    $gpsPointsCountExpr = $hasGpsPointsCount
        ? 's.gps_points_count,'
        : 'NULL::integer AS gps_points_count,';

    $stmt = $db->prepare(" 
        SELECT 
            s.id,
            s.tipo_servicio,
            s.tipo_vehiculo,
            s.empresa_id,
            s.estado,
            $metricsLockedExpr
            s.distancia_estimada,
            s.tiempo_estimado,
            s.precio_estimado,
            s.distancia_recorrida,
            s.tiempo_transcurrido,
            $distanceFinalExpr
            $durationFinalExpr
            $priceFinalEnExpr
            $completedAtExpr
            $gpsPointsCountExpr
            s.solicitado_en,
            s.latitud_recogida,
            s.longitud_recogida,
            s.latitud_destino,
            s.longitud_destino
        FROM solicitudes_servicio s
        WHERE s.id = :solicitud_id
        FOR UPDATE
    ");
    $stmt->execute([':solicitud_id' => $solicitud_id]);
    $viaje = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$viaje) {
        throw new Exception('Viaje no encontrado');
    }

    if (!empty($viaje['metrics_locked']) && intval($viaje['metrics_locked']) === 1) {
        $db->rollBack();
        echo json_encode(buildLockedSummaryResponse($db, $solicitud_id, $conductor_id));
        exit();
    }
    
    // Obtener el último punto de tracking para valores más precisos
        $ultimo_tracking = null;

        $stmt = $db->prepare("
            SELECT to_regclass('public.viaje_tracking_snapshot') AS table_name
        ");
        $stmt->execute();
        $snapshot_info = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!empty($snapshot_info['table_name'])) {
            $stmt = $db->prepare("
                SELECT
                    distancia_acumulada_km,
                    tiempo_transcurrido_seg,
                    precio_parcial,
                    actualizado_en AS timestamp_gps
                FROM viaje_tracking_snapshot
                WHERE solicitud_id = :solicitud_id
                LIMIT 1
            ");
            $stmt->execute([':solicitud_id' => $solicitud_id]);
            $ultimo_tracking = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$ultimo_tracking) {
            $stmt = $db->prepare("
                SELECT 
                    distancia_acumulada_km,
                    tiempo_transcurrido_seg,
                    precio_parcial,
                    timestamp_gps
                FROM viaje_tracking_realtime
                WHERE solicitud_id = :solicitud_id
                ORDER BY timestamp_gps DESC
                LIMIT 1
            ");
            $stmt->execute([':solicitud_id' => $solicitud_id]);
            $ultimo_tracking = $stmt->fetch(PDO::FETCH_ASSOC);
        }

    
    // Resolver valores finales canónicos con criterio monotónico.
    // Esto evita que un snapshot atrasado o un batch tardío pise el valor real final.
    $redisMetrics = readCanonicalMetricsFromRedis($solicitud_id);

    $metricasFinales = resolveCanonicalFinalMetrics(
        $viaje,
        $ultimo_tracking ?: null,
        $redisMetrics,
        $distancia_final_km,
        $tiempo_final_seg
    );

    // Reconciliación robusta por GPS (fuente de verdad backend).
    $reconciliacion = reconcileDistanceKmFromGps(
        $db,
        $solicitud_id,
        $metricasFinales['distancia_real_km']
    );

    $distanciaBody = floatval($metricasFinales['distancia_real_km'] ?? 0);
    $distanciaReconciliada = floatval($reconciliacion['distance_km'] ?? 0);
    $gpsAceptados = intval($reconciliacion['accepted_points'] ?? 0);

    // Duración basada en timestamps del servidor para eliminar sesgo de latencia cliente.
    $tiempo_real_seg = resolveDurationSegFromServer(
        $db,
        $solicitud_id,
        $metricasFinales['tiempo_real_seg']
    );

    // Si hay trazas GPS suficientes, la reconciliación del servidor manda.
    // Si no hay trazas, usar fallback pero con candado de plausibilidad temporal.
    if ($gpsAceptados >= 2) {
        $distancia_real = $distanciaReconciliada;
    } else {
        $distancia_real = max($distanciaBody, $distanciaReconciliada);
    }

    $distanciaClamped = clampDistanceByDuration($distancia_real, $tiempo_real_seg);
    if ($distanciaClamped < $distancia_real) {
        error_log('[TripFinalize] Distancia clamped por plausibilidad trip_id=' . $solicitud_id
            . ' raw=' . $distancia_real
            . ' clamped=' . $distanciaClamped
            . ' duration_seg=' . $tiempo_real_seg
            . ' gps_accepted=' . $gpsAceptados);
        $distancia_real = $distanciaClamped;
    }

    if ($distancia_real <= 0 && $distancia_final_km !== null && $distancia_final_km > 0) {
        $distancia_real = clampDistanceByDuration((float) $distancia_final_km, $tiempo_real_seg);
    }

    if ($tiempo_real_seg <= 0 && $tiempo_final_seg !== null && $tiempo_final_seg > 0) {
        $tiempo_real_seg = (int) $tiempo_final_seg;
    }
    
    $tiempo_real_min = ceil($tiempo_real_seg / 60);
    
    // =====================================================
    // OBTENER CONFIGURACIÓN DE PRECIOS COMPLETA
    // =====================================================
    $empresa_id = $viaje['empresa_id'];
    $config = null;
    $config_precios_id = null;
    $comision_admin_porcentaje = 0; // Comisión que el admin cobra a la empresa
    
    // Obtener comisión del admin sobre la empresa (si aplica)
    if ($empresa_id) {
        $stmt = $db->prepare("SELECT comision_admin_porcentaje FROM empresas_transporte WHERE id = :id");
        $stmt->execute([':id' => $empresa_id]);
        $empresa_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($empresa_data) {
            $comision_admin_porcentaje = floatval($empresa_data['comision_admin_porcentaje'] ?? 0);
        }
    }
    
    $config = obtenerConfigPreciosTrackingFinalize(
        $db,
        $empresa_id,
        $viaje['tipo_vehiculo'] ?? 'moto'
    );

    if (!empty($config['__fallback'])) {
        error_log('[tracking_finalize] Sin configuración de precios para solicitud ' . $solicitud_id . ', tipo=' . ($viaje['tipo_vehiculo'] ?? 'N/A') . '. Se aplicó fallback seguro.');
    }
    
    $config_precios_id = isset($config['id']) ? intval($config['id']) : null;
    
    // =====================================================
    // CALCULAR PRECIO FINAL CON TODOS LOS COMPONENTES
    // =====================================================
    
    // 1. Componentes base
    $tarifa_base = floatval($config['tarifa_base']);
    $precio_distancia = $distancia_real * floatval($config['costo_por_km']);
    $precio_tiempo = $tiempo_real_min * floatval($config['costo_por_minuto']);
    
    $subtotal_sin_recargos = $tarifa_base + $precio_distancia + $precio_tiempo;
    
    // 2. Descuento por distancia larga
    $descuento_distancia_larga = 0;
    $umbral_km = floatval($config['umbral_km_descuento'] ?? 15);
    if ($distancia_real >= $umbral_km) {
        $descuento_distancia_larga = $subtotal_sin_recargos * (floatval($config['descuento_distancia_larga'] ?? 0) / 100);
    }
    
    $subtotal_con_descuento = $subtotal_sin_recargos - $descuento_distancia_larga;
    
    // 3. Tiempo de espera (fuera del tiempo gratis)
    $tiempo_espera_gratis = intval($config['tiempo_espera_gratis'] ?? 3);
    $tiempo_espera_cobrable = max(0, $tiempo_espera_min - $tiempo_espera_gratis);
    $recargo_espera = $tiempo_espera_cobrable * floatval($config['costo_tiempo_espera'] ?? 0);
    
    // 4. Determinar recargos con contexto Colombia + trafico real por zonas.
    // Usamos el timestamp mas cercano al fin real del viaje, no el "ahora" del servidor.
    $fechaReferenciaRaw = $ultimo_tracking['timestamp_gps']
        ?? $ultimo_tracking['actualizado_en']
        ?? null;
    $fecha_colombia = trafficToColombiaDateTime($fechaReferenciaRaw, trafficNowInColombia());
    $h_noc_ini = (string) ($config['hora_nocturna_inicio'] ?? '22:00:00');
    $h_noc_fin = (string) ($config['hora_nocturna_fin'] ?? '06:00:00');

    $holidayMeta = [];
    $es_festivo_legal = trafficIsHolidayColombia($fecha_colombia, $holidayMeta);
    $es_dominical = trafficIsSundayColombia($fecha_colombia);
    $aplicarDominicalComoFestivo = trafficShouldApplySundayAsHoliday();
    $es_festivo = $es_festivo_legal || ($aplicarDominicalComoFestivo && $es_dominical);
    $es_nocturno = trafficIsNocturnoColombia($fecha_colombia, $h_noc_ini, $h_noc_fin);

    $originLat = isset($viaje['latitud_recogida']) ? floatval($viaje['latitud_recogida']) : 0.0;
    $originLng = isset($viaje['longitud_recogida']) ? floatval($viaje['longitud_recogida']) : 0.0;
    $destLat = isset($viaje['latitud_destino']) ? floatval($viaje['latitud_destino']) : 0.0;
    $destLng = isset($viaje['longitud_destino']) ? floatval($viaje['longitud_destino']) : 0.0;

    $trafico = trafficGetConditions($originLat, $originLng, $destLat, $destLng);

    $porcentaje_recargo_festivo = $es_festivo ? floatval($config['recargo_festivo'] ?? 0) : 0.0;
    $porcentaje_recargo_nocturno = $es_nocturno ? floatval($config['recargo_nocturno'] ?? 0) : 0.0;
    $porcentaje_recargo_trafico = calculateTrafficSurcharge(floatval($trafico['traffic_ratio'] ?? 1.0));

    // Fallback operativo: si no hay dato real de Routes (por ejemplo, API key faltante)
    // aplicamos la franja configurada de hora pico para no perder el recargo.
    $fuenteTrafico = strtolower(trim(strval($trafico['source'] ?? 'unknown')));
    $horaActualBogota = $fecha_colombia->format('H:i:s');
    $hPicoIniM = (string) ($config['hora_pico_inicio_manana'] ?? '07:00:00');
    $hPicoFinM = (string) ($config['hora_pico_fin_manana'] ?? '09:00:00');
    $hPicoIniT = (string) ($config['hora_pico_inicio_tarde'] ?? '17:00:00');
    $hPicoFinT = (string) ($config['hora_pico_fin_tarde'] ?? '19:00:00');
    $recargoHoraPicoConfig = floatval($config['recargo_hora_pico'] ?? 0);

    $horaSec = trafficTimeToSeconds($horaActualBogota);
    $esPicoManana = $horaSec >= trafficTimeToSeconds($hPicoIniM)
        && $horaSec <= trafficTimeToSeconds($hPicoFinM);
    $esPicoTarde = $horaSec >= trafficTimeToSeconds($hPicoIniT)
        && $horaSec <= trafficTimeToSeconds($hPicoFinT);

    $traficoInconcluso = in_array($fuenteTrafico, [
        'no_api_key',
        'api_error',
        'invalid_response',
        'throttled_lock',
        'cache_historical_no_key',
    ], true);

    if ($traficoInconcluso && $porcentaje_recargo_trafico <= 0 && $recargoHoraPicoConfig > 0 && ($esPicoManana || $esPicoTarde)) {
        $porcentaje_recargo_trafico = $recargoHoraPicoConfig;
        $trafico['source'] = 'fallback_config_schedule';
        $trafico['traffic_level'] = $esPicoManana ? 'hora_pico_manana' : 'hora_pico_tarde';
        $trafico['peak_traffic'] = true;
    }

    $recargo_festivo = $porcentaje_recargo_festivo > 0
        ? $subtotal_con_descuento * ($porcentaje_recargo_festivo / 100)
        : 0.0;
    $recargo_nocturno = $porcentaje_recargo_nocturno > 0
        ? $subtotal_con_descuento * ($porcentaje_recargo_nocturno / 100)
        : 0.0;
    $recargo_hora_pico = $porcentaje_recargo_trafico > 0
        ? $subtotal_con_descuento * ($porcentaje_recargo_trafico / 100)
        : 0.0;

    $tiposRecargo = [];
    if ($recargo_festivo > 0) {
        if ($es_festivo_legal) {
            $tiposRecargo[] = 'festivo';
        } elseif ($es_dominical && $aplicarDominicalComoFestivo) {
            $tiposRecargo[] = 'dominical';
        } else {
            $tiposRecargo[] = 'festivo';
        }
    }
    if ($recargo_nocturno > 0) {
        $tiposRecargo[] = 'nocturno';
    }
    if ($recargo_hora_pico > 0) {
        $tiposRecargo[] = 'hora_pico_' . strval($trafico['traffic_level'] ?? 'moderado');
    }

    $tipo_recargo = empty($tiposRecargo) ? 'normal' : implode('+', $tiposRecargo);
    $recargo_porcentaje = $porcentaje_recargo_festivo + $porcentaje_recargo_nocturno + $porcentaje_recargo_trafico;
    
    // 5. Sumar todos los recargos
    $total_recargos = $recargo_nocturno + $recargo_hora_pico + $recargo_festivo + $recargo_espera;
    
    // 6. Precio total antes de límites
    $precio_total = $subtotal_con_descuento + $total_recargos;
    
    // 7. Aplicar tarifa mínima
    $tarifa_minima = floatval($config['tarifa_minima']);
    $aplico_tarifa_minima = false;
    if ($precio_total < $tarifa_minima) {
        $precio_total = $tarifa_minima;
        $aplico_tarifa_minima = true;
    }
    
    // 8. Aplicar tarifa máxima si existe
    if ($config['tarifa_maxima'] !== null && $config['tarifa_maxima'] > 0) {
        $tarifa_maxima = floatval($config['tarifa_maxima']);
        if ($precio_total > $tarifa_maxima) {
            $precio_total = $tarifa_maxima;
        }
    }
    
    // 9. Redondear a 100 COP más cercano (típico en Colombia)
    $precio_final = round($precio_total / 100) * 100;
    
    // 10. Calcular comisión de la EMPRESA al conductor
    // Esta es la comisión que la empresa cobra a sus conductores
    $comision_plataforma_porcentaje = floatval($config['comision_plataforma']);
    $comision_plataforma_valor = $precio_final * ($comision_plataforma_porcentaje / 100);
    $ganancia_conductor = $precio_final - $comision_plataforma_valor;
    
    // 11. Calcular comisión del ADMIN sobre lo que gana la empresa
    // Esta es la comisión que el admin (VIAX) cobra a las empresas de transporte
    // Se calcula sobre la comisión que la empresa cobró al conductor
    $comision_admin_valor = $comision_plataforma_valor * ($comision_admin_porcentaje / 100);
    $ganancia_empresa = $comision_plataforma_valor - $comision_admin_valor;
    
    // =====================================================
    // DETECTAR DESVÍOS SIGNIFICATIVOS
    // =====================================================
    
    $distancia_estimada = floatval($viaje['distancia_estimada']);
    $diferencia_distancia = $distancia_real - $distancia_estimada;
    $porcentaje_desvio = $distancia_estimada > 0 
        ? ($diferencia_distancia / $distancia_estimada) * 100 
        : 0;
    
    $tuvo_desvio = abs($porcentaje_desvio) > 20;
    
    // =====================================================
    // CREAR OBJETO JSON DE DESGLOSE COMPLETO
    // =====================================================
    
    $desglose_json = json_encode([
        'tarifa_base' => round($tarifa_base, 2),
        'precio_distancia' => round($precio_distancia, 2),
        'precio_tiempo' => round($precio_tiempo, 2),
        'subtotal_sin_recargos' => round($subtotal_sin_recargos, 2),
        'descuento_distancia_larga' => round($descuento_distancia_larga, 2),
        'subtotal_con_descuento' => round($subtotal_con_descuento, 2),
        'recargo_nocturno' => round($recargo_nocturno, 2),
        'recargo_hora_pico' => round($recargo_hora_pico, 2),
        'recargo_festivo' => round($recargo_festivo, 2),
        'recargo_espera' => round($recargo_espera, 2),
        'tiempo_espera_min' => $tiempo_espera_cobrable,
        'total_recargos' => round($total_recargos, 2),
        'tipo_recargo' => $tipo_recargo,
        'recargo_porcentaje' => $recargo_porcentaje,
        'porcentaje_recargo_festivo' => round($porcentaje_recargo_festivo, 2),
        'porcentaje_recargo_nocturno' => round($porcentaje_recargo_nocturno, 2),
        'porcentaje_recargo_trafico' => round($porcentaje_recargo_trafico, 2),
        'contexto_colombia' => [
            'fecha_hora_bogota' => formatDateTimeCoAmPm($fecha_colombia),
            'fecha_hora_bogota_24h' => $fecha_colombia->format('Y-m-d H:i:s'),
            'es_festivo' => $es_festivo,
            'es_festivo_legal' => $es_festivo_legal,
            'es_dominical' => $es_dominical,
            'aplica_dominical_como_festivo' => $aplicarDominicalComoFestivo,
            'fuente_festivo' => $holidayMeta['source'] ?? 'desconocida',
            'url_api_festivos' => $holidayMeta['url'] ?? null,
            'es_nocturno' => $es_nocturno,
            'horario_nocturno' => [
                'inicio' => $h_noc_ini,
                'fin' => $h_noc_fin,
            ],
        ],
        'trafico' => [
            'origin_zone_key' => $trafico['origin_zone_key'] ?? null,
            'destination_zone_key' => $trafico['destination_zone_key'] ?? null,
            'distance_meters' => intval($trafico['distance_meters'] ?? 0),
            'duration_seconds' => intval($trafico['duration_seconds'] ?? 0),
            'static_duration_seconds' => intval($trafico['static_duration_seconds'] ?? 0),
            'traffic_ratio' => round(floatval($trafico['traffic_ratio'] ?? 1.0), 4),
            'traffic_level' => $trafico['traffic_level'] ?? 'normal',
            'peak_traffic' => !empty($trafico['peak_traffic']),
            'source' => $trafico['source'] ?? 'unknown',
            'api_called' => !empty($trafico['api_called']),
        ],
        'aplico_tarifa_minima' => $aplico_tarifa_minima,
        'precio_antes_redondeo' => round($precio_total, 2),
        'precio_final' => $precio_final,
        // Comisión de la empresa al conductor
        'comision_plataforma_porcentaje' => $comision_plataforma_porcentaje,
        'comision_plataforma_valor' => round($comision_plataforma_valor, 2),
        'ganancia_conductor' => round($ganancia_conductor, 2),
        // Comisión del admin a la empresa
        'comision_admin_porcentaje' => $comision_admin_porcentaje,
        'comision_admin_valor' => round($comision_admin_valor, 2),
        'ganancia_empresa' => round($ganancia_empresa, 2),
        // Datos del viaje
        'distancia_km' => round($distancia_real, 2),
        'tiempo_min' => $tiempo_real_min,
        'config_precios_id' => $config_precios_id,
        'empresa_id' => $empresa_id
    ]);
    
    // =====================================================
    // ACTUALIZAR RESUMEN DE TRACKING CON DESGLOSE COMPLETO
    // =====================================================
    
    $tiempo_estimado_min = intval($viaje['tiempo_estimado']);
    $diff_tiempo_min = $tiempo_real_min - $tiempo_estimado_min;
    
    $stmt = $db->prepare("
        INSERT INTO viaje_resumen_tracking (
            solicitud_id,
            distancia_real_km,
            tiempo_real_minutos,
            distancia_estimada_km,
            tiempo_estimado_minutos,
            diferencia_distancia_km,
            diferencia_tiempo_min,
            porcentaje_desvio_distancia,
            precio_estimado,
            precio_final_calculado,
            precio_final_aplicado,
            tiene_desvio_ruta,
            fin_viaje_real,
            actualizado_en,
            -- Nuevas columnas de desglose
            tarifa_base,
            precio_distancia,
            precio_tiempo,
            recargo_nocturno,
            recargo_hora_pico,
            recargo_festivo,
            recargo_espera,
            tiempo_espera_min,
            descuento_distancia_larga,
            subtotal_sin_recargos,
            total_recargos,
            tipo_recargo,
            aplico_tarifa_minima,
            -- Comisión empresa al conductor
            comision_plataforma_porcentaje,
            comision_plataforma_valor,
            ganancia_conductor,
            -- Comisión admin a la empresa
            comision_admin_porcentaje,
            comision_admin_valor,
            ganancia_empresa,
            -- Referencias
            empresa_id,
            config_precios_id
        ) VALUES (
            :solicitud_id,
            :distancia_real,
            :tiempo_real,
            :distancia_estimada,
            :tiempo_estimado,
            :diff_distancia,
            :diff_tiempo,
            :porcentaje_desvio,
            :precio_estimado,
            :precio_calculado,
            :precio_aplicado,
            :tuvo_desvio,
            NOW(),
            NOW(),
            :tarifa_base,
            :precio_distancia,
            :precio_tiempo,
            :recargo_nocturno,
            :recargo_hora_pico,
            :recargo_festivo,
            :recargo_espera,
            :tiempo_espera_cobrable,
            :descuento_distancia,
            :subtotal_sin_recargos,
            :total_recargos,
            :tipo_recargo,
            :aplico_tarifa_minima,
            :comision_porcentaje,
            :comision_valor,
            :ganancia_conductor,
            :comision_admin_porcentaje,
            :comision_admin_valor,
            :ganancia_empresa,
            :empresa_id,
            :config_precios_id
        )
        ON CONFLICT (solicitud_id) DO UPDATE SET
            distancia_real_km = EXCLUDED.distancia_real_km,
            tiempo_real_minutos = EXCLUDED.tiempo_real_minutos,
            distancia_estimada_km = EXCLUDED.distancia_estimada_km,
            tiempo_estimado_minutos = EXCLUDED.tiempo_estimado_minutos,
            diferencia_distancia_km = EXCLUDED.diferencia_distancia_km,
            diferencia_tiempo_min = EXCLUDED.diferencia_tiempo_min,
            porcentaje_desvio_distancia = EXCLUDED.porcentaje_desvio_distancia,
            precio_estimado = EXCLUDED.precio_estimado,
            precio_final_calculado = EXCLUDED.precio_final_calculado,
            precio_final_aplicado = EXCLUDED.precio_final_aplicado,
            tiene_desvio_ruta = EXCLUDED.tiene_desvio_ruta,
            fin_viaje_real = NOW(),
            actualizado_en = NOW(),
            tarifa_base = EXCLUDED.tarifa_base,
            precio_distancia = EXCLUDED.precio_distancia,
            precio_tiempo = EXCLUDED.precio_tiempo,
            recargo_nocturno = EXCLUDED.recargo_nocturno,
            recargo_hora_pico = EXCLUDED.recargo_hora_pico,
            recargo_festivo = EXCLUDED.recargo_festivo,
            recargo_espera = EXCLUDED.recargo_espera,
            tiempo_espera_min = EXCLUDED.tiempo_espera_min,
            descuento_distancia_larga = EXCLUDED.descuento_distancia_larga,
            subtotal_sin_recargos = EXCLUDED.subtotal_sin_recargos,
            total_recargos = EXCLUDED.total_recargos,
            tipo_recargo = EXCLUDED.tipo_recargo,
            aplico_tarifa_minima = EXCLUDED.aplico_tarifa_minima,
            comision_plataforma_porcentaje = EXCLUDED.comision_plataforma_porcentaje,
            comision_plataforma_valor = EXCLUDED.comision_plataforma_valor,
            ganancia_conductor = EXCLUDED.ganancia_conductor,
            comision_admin_porcentaje = EXCLUDED.comision_admin_porcentaje,
            comision_admin_valor = EXCLUDED.comision_admin_valor,
            ganancia_empresa = EXCLUDED.ganancia_empresa,
            empresa_id = EXCLUDED.empresa_id,
            config_precios_id = EXCLUDED.config_precios_id
    ");
    
    $stmt->execute([
        ':solicitud_id' => $solicitud_id,
        ':distancia_real' => $distancia_real,
        ':tiempo_real' => $tiempo_real_min,
        ':distancia_estimada' => $distancia_estimada,
        ':tiempo_estimado' => $tiempo_estimado_min,
        ':diff_distancia' => $diferencia_distancia,
        ':diff_tiempo' => $diff_tiempo_min,
        ':porcentaje_desvio' => $porcentaje_desvio,
        ':precio_estimado' => floatval($viaje['precio_estimado']),
        ':precio_calculado' => $precio_final,
        ':precio_aplicado' => $precio_final,
        ':tuvo_desvio' => $tuvo_desvio ? 1 : 0,
        ':tarifa_base' => $tarifa_base,
        ':precio_distancia' => $precio_distancia,
        ':precio_tiempo' => $precio_tiempo,
        ':recargo_nocturno' => $recargo_nocturno,
        ':recargo_hora_pico' => $recargo_hora_pico,
        ':recargo_festivo' => $recargo_festivo,
        ':recargo_espera' => $recargo_espera,
        ':tiempo_espera_cobrable' => $tiempo_espera_cobrable,
        ':descuento_distancia' => $descuento_distancia_larga,
        ':subtotal_sin_recargos' => $subtotal_sin_recargos,
        ':total_recargos' => $total_recargos,
        ':tipo_recargo' => $tipo_recargo,
        ':aplico_tarifa_minima' => $aplico_tarifa_minima ? 1 : 0,
        ':comision_porcentaje' => $comision_plataforma_porcentaje,
        ':comision_valor' => $comision_plataforma_valor,
        ':ganancia_conductor' => $ganancia_conductor,
        ':comision_admin_porcentaje' => $comision_admin_porcentaje,
        ':comision_admin_valor' => $comision_admin_valor,
        ':ganancia_empresa' => $ganancia_empresa,
        ':empresa_id' => $empresa_id,
        ':config_precios_id' => $config_precios_id
    ]);
    
    // =====================================================
    // ACTUALIZAR SALDO PENDIENTE DE LA EMPRESA CON ADMIN
    // =====================================================
    // La empresa debe al admin la comision_admin_valor de cada viaje
    // MODIFICACION: Se comenta esto porque el cobro se hace ahora al REGISTRAR EL PAGO del conductor.
    /*
    if ($empresa_id && $comision_admin_valor > 0) {
        // Obtener saldo actual de la empresa
        $stmtSaldo = $db->prepare("SELECT saldo_pendiente FROM empresas_transporte WHERE id = :id FOR UPDATE");
        $stmtSaldo->execute([':id' => $empresa_id]);
        $saldo_actual = floatval($stmtSaldo->fetchColumn() ?? 0);
        $nuevo_saldo = $saldo_actual + $comision_admin_valor;
        
        // Actualizar saldo pendiente
        $stmtUpdate = $db->prepare("UPDATE empresas_transporte SET saldo_pendiente = :nuevo_saldo, actualizado_en = NOW() WHERE id = :id");
        $stmtUpdate->execute([':nuevo_saldo' => $nuevo_saldo, ':id' => $empresa_id]);
        
        // Registrar movimiento en pagos_empresas (cargo por comisión del viaje)
        $stmtMovimiento = $db->prepare("
            INSERT INTO pagos_empresas (empresa_id, monto, tipo, descripcion, viaje_id, saldo_anterior, saldo_nuevo, creado_en)
            VALUES (:empresa_id, :monto, 'cargo', :descripcion, :viaje_id, :saldo_anterior, :saldo_nuevo, NOW())
        ");
        $stmtMovimiento->execute([
            ':empresa_id' => $empresa_id,
            ':monto' => $comision_admin_valor,
            ':descripcion' => "Comisión viaje #$solicitud_id ({$comision_admin_porcentaje}%)",
            ':viaje_id' => $solicitud_id,
            ':saldo_anterior' => $saldo_actual,
            ':saldo_nuevo' => $nuevo_saldo
        ]);
    }
    */
    
    // =====================================================
    // ACTUALIZAR SOLICITUD Y CONGELAR MÉTRICAS FINALES
    // =====================================================
    
    $updateSet = [
        'pago_confirmado = true',
        'pago_confirmado_en = NOW()',
        'precio_final = :precio_final',
        'distancia_recorrida = :distancia',
        'tiempo_transcurrido = :tiempo',
        'precio_ajustado_por_tracking = TRUE',
        'tuvo_desvio_ruta = :tuvo_desvio',
        'desglose_precio = :desglose_json',
    ];

    if ($hasDistanceFinal) {
        $updateSet[] = 'distance_final = :distance_final';
    }
    if ($hasDurationFinal) {
        $updateSet[] = 'duration_final = :duration_final';
    }
    if ($hasPriceFinalEn) {
        $updateSet[] = 'price_final = :price_final_en';
    }
    if ($hasCompletedAt) {
        $updateSet[] = 'completed_at = COALESCE(completed_at, NOW())';
    }
    if ($hasMetricsLocked) {
        $updateSet[] = 'metrics_locked = TRUE';
    }
    if ($hasGpsPointsCount) {
        $updateSet[] = 'gps_points_count = :gps_points_count';
    }

    $stmt = $db->prepare(
        "UPDATE solicitudes_servicio SET
            " . implode(",\n            ", $updateSet) . "
        WHERE id = :solicitud_id"
    );
    
    $paramsSolicitud = [
        ':precio_final' => $precio_final,
        ':distancia' => $distancia_real,
        ':tiempo' => $tiempo_real_seg,
        ':tuvo_desvio' => $tuvo_desvio ? 1 : 0,
        ':desglose_json' => $desglose_json,
        ':solicitud_id' => $solicitud_id,
    ];

    if ($hasDistanceFinal) {
        $paramsSolicitud[':distance_final'] = $distancia_real;
    }
    if ($hasDurationFinal) {
        $paramsSolicitud[':duration_final'] = $tiempo_real_seg;
    }
    if ($hasPriceFinalEn) {
        $paramsSolicitud[':price_final_en'] = $precio_final;
    }
    if ($hasGpsPointsCount) {
        $paramsSolicitud[':gps_points_count'] = intval($reconciliacion['accepted_points'] ?? 0);
    }

    $stmt->execute($paramsSolicitud);

    // Blindaje de consistencia: si por cualquier motivo falló update_trip_status.php,
    // dejamos el viaje formalmente cerrado aquí también para no quedar "en viaje".
    $stmt = $db->prepare(" 
        UPDATE solicitudes_servicio
        SET
            estado = 'completada',
            completado_en = COALESCE(completado_en, NOW()),
            entregado_en = COALESCE(entregado_en, NOW())
        WHERE id = :solicitud_id
    ");
    $stmt->execute([':solicitud_id' => $solicitud_id]);

    $stmt = $db->prepare(" 
        UPDATE asignaciones_conductor
        SET estado = 'completado'
        WHERE solicitud_id = :solicitud_id
          AND conductor_id = :conductor_id
    ");
    $stmt->execute([
        ':solicitud_id' => $solicitud_id,
        ':conductor_id' => $conductor_id,
    ]);

    $stmt = $db->prepare(" 
        UPDATE detalles_conductor
        SET disponible = 1
        WHERE usuario_id = :conductor_id
    ");
    $stmt->execute([':conductor_id' => $conductor_id]);
    
    $db->commit();

    // Cerrar cache temporal de solicitud y publicar resumen final de tracking.
    try {
        Cache::set('ride_request:' . $solicitud_id, '{}', 1);
        Cache::set('trip_tracking_latest:' . $solicitud_id, (string) json_encode([
            'solicitud_id' => $solicitud_id,
            'conductor_id' => $conductor_id,
            'distancia_km' => round($distancia_real, 3),
            'tiempo_seg' => $tiempo_real_seg,
            'precio_final' => $precio_final,
            'estado' => 'trip_completed',
            'timestamp' => time(),
        ]), 300);

        $redis = Cache::redis();
        if ($redis) {
            $redis->del('trip:' . $solicitud_id . ':state');
            $redis->del('trip:' . $solicitud_id . ':metrics');
            $redis->del('trip:' . $solicitud_id . ':anomalies');
        }
    } catch (Throwable $cacheError) {
        error_log('finalize.php cache warning: ' . $cacheError->getMessage());
    }
    
    // =====================================================
    // RESPUESTA CON DESGLOSE COMPLETO
    // =====================================================
    
    $response = [
        'success' => true,
        'message' => 'Tracking finalizado y precio calculado',
        'precio_final' => $precio_final,
        'desglose' => [
            'tarifa_base' => round($tarifa_base, 2),
            'precio_distancia' => round($precio_distancia, 2),
            'precio_tiempo' => round($precio_tiempo, 2),
            'subtotal_sin_recargos' => round($subtotal_sin_recargos, 2),
            'descuento_distancia_larga' => round($descuento_distancia_larga, 2),
            'recargo_nocturno' => round($recargo_nocturno, 2),
            'recargo_hora_pico' => round($recargo_hora_pico, 2),
            'recargo_festivo' => round($recargo_festivo, 2),
            'recargo_espera' => round($recargo_espera, 2),
            'tiempo_espera_min' => $tiempo_espera_cobrable,
            'total_recargos' => round($total_recargos, 2),
            'tipo_recargo' => $tipo_recargo,
            'recargo_porcentaje' => $recargo_porcentaje,
            'porcentaje_recargo_festivo' => round($porcentaje_recargo_festivo, 2),
            'porcentaje_recargo_nocturno' => round($porcentaje_recargo_nocturno, 2),
            'porcentaje_recargo_trafico' => round($porcentaje_recargo_trafico, 2),
            'aplico_tarifa_minima' => $aplico_tarifa_minima,
            'precio_antes_redondeo' => round($precio_total, 2),
            'precio_final' => $precio_final,
            'contexto_colombia' => [
                'fecha_hora_bogota' => formatDateTimeCoAmPm($fecha_colombia),
                'fecha_hora_bogota_24h' => $fecha_colombia->format('Y-m-d H:i:s'),
                'es_festivo' => $es_festivo,
                'es_festivo_legal' => $es_festivo_legal,
                'es_dominical' => $es_dominical,
                'aplica_dominical_como_festivo' => $aplicarDominicalComoFestivo,
                'fuente_festivo' => $holidayMeta['source'] ?? 'desconocida',
                'url_api_festivos' => $holidayMeta['url'] ?? null,
                'es_nocturno' => $es_nocturno,
            ],
            'trafico' => [
                'traffic_ratio' => round(floatval($trafico['traffic_ratio'] ?? 1.0), 4),
                'traffic_level' => $trafico['traffic_level'] ?? 'normal',
                'source' => $trafico['source'] ?? 'unknown',
            ],
        ],
        'tracking' => [
            'distancia_real_km' => round($distancia_real, 2),
            'tiempo_real_min' => $tiempo_real_min,
            'tiempo_real_seg' => $tiempo_real_seg,
            'distancia_estimada_km' => $distancia_estimada,
            'tiempo_estimado_min' => $tiempo_estimado_min,
            'gps_puntos_validos' => intval($reconciliacion['accepted_points'] ?? 0),
            'gps_puntos_descartados' => intval($reconciliacion['rejected_points'] ?? 0),
        ],
        'diferencias' => [
            'diferencia_distancia_km' => round($diferencia_distancia, 2),
            'diferencia_tiempo_min' => $diff_tiempo_min,
            'porcentaje_desvio' => round($porcentaje_desvio, 1),
            'tuvo_desvio_significativo' => $tuvo_desvio
        ],
        'comisiones' => [
            'comision_plataforma_porcentaje' => $comision_plataforma_porcentaje,
            'comision_plataforma_valor' => round($comision_plataforma_valor, 2),
            'ganancia_conductor' => round($ganancia_conductor, 2)
        ],
        'comparacion_precio' => [
            'precio_estimado' => floatval($viaje['precio_estimado']),
            'precio_final' => $precio_final,
            'diferencia' => $precio_final - floatval($viaje['precio_estimado'])
        ],
        'meta' => [
            'empresa_id' => $empresa_id,
            'config_precios_id' => $config_precios_id,
            'tipo_vehiculo' => $viaje['tipo_vehiculo'] ?? 'moto',
            'metrics_locked' => $hasMetricsLocked ? true : null,
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
