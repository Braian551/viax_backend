<?php
/**
 * Servicio de ingestión de tracking (uso interno).
 *
 * Responsabilidades:
 * - Validar puntos GPS recibidos en lote.
 * - Persistir puntos en viaje_tracking_realtime.
 * - Actualizar snapshot y métricas agregadas del viaje.
 * - Refrescar cache Redis para lectura realtime de conductor/viaje.
 */

require_once __DIR__ . '/../../config/app.php';

function processTrackingPoints(PDO $db, int $solicitudId, int $conductorId, array $points): array
{
    if ($solicitudId <= 0 || $conductorId <= 0) {
        throw new Exception('IDs inválidos');
    }

    if (empty($points)) {
        throw new Exception('No hay puntos para procesar');
    }

    $viaje = obtenerViajeTracking($db, $solicitudId);
    validarConductorYEstado($viaje, $conductorId);
    $config = obtenerConfigTarifaTracking($db, $viaje);

    [$lastLat, $lastLng, $lastTiempo, $lastDistKm] = obtenerUltimoEstadoTracking($db, $solicitudId);

    $insertStmt = $db->prepare(
        "INSERT INTO viaje_tracking_realtime (
            solicitud_id,
            conductor_id,
            latitud,
            longitud,
            precision_gps,
            altitud,
            velocidad,
            bearing,
            distancia_acumulada_km,
            tiempo_transcurrido_seg,
            distancia_desde_anterior_m,
            precio_parcial,
            fase_viaje,
            evento,
            timestamp_gps,
            timestamp_servidor
        ) VALUES (
            :solicitud_id,
            :conductor_id,
            :latitud,
            :longitud,
            :precision_gps,
            :altitud,
            :velocidad,
            :bearing,
            :distancia_acumulada_km,
            :tiempo_transcurrido_seg,
            :distancia_desde_anterior,
            :precio_parcial,
            :fase_viaje,
            :evento,
            NOW(),
            NOW()
        )"
    );

    $inserted = 0;
    $skipped = 0;
    $ultimoPrecio = 0.0;
    $ultimoDist = 0.0;
    $ultimoTiempo = 0;
    $ultimoPunto = null;

    foreach ($points as $point) {
        if (!isset($point['latitud'], $point['longitud'], $point['distancia_acumulada_km'], $point['tiempo_transcurrido_seg'])) {
            $skipped++;
            continue;
        }

        $lat = floatval($point['latitud']);
        $lng = floatval($point['longitud']);
        $distAcumuladaKm = floatval($point['distancia_acumulada_km']);
        $tiempoSeg = intval($point['tiempo_transcurrido_seg']);
        $velocidad = isset($point['velocidad']) ? floatval($point['velocidad']) : 0;
        $bearing = isset($point['bearing']) ? floatval($point['bearing']) : 0;
        $precisionGps = isset($point['precision_gps']) ? floatval($point['precision_gps']) : null;
        $altitud = isset($point['altitud']) ? floatval($point['altitud']) : null;
        $faseViaje = isset($point['fase_viaje']) ? strval($point['fase_viaje']) : 'hacia_destino';
        $evento = isset($point['evento']) ? strval($point['evento']) : null;

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            $skipped++;
            continue;
        }

        if ($lastTiempo !== null && $tiempoSeg <= $lastTiempo && empty($evento)) {
            $skipped++;
            continue;
        }

        $distanciaDesdeAnterior = 0.0;
        $deltaTiempoSeg = $lastTiempo !== null ? max(0, $tiempoSeg - $lastTiempo) : 0;
        if ($lastLat !== null && $lastLng !== null) {
            $distanciaDesdeAnterior = calcularDistanciaHaversine($lastLat, $lastLng, $lat, $lng);

            if (esSaltoGpsInvalido($distanciaDesdeAnterior, $deltaTiempoSeg, $precisionGps, $velocidad, $evento)) {
                $skipped++;
                continue;
            }

            if ($distanciaDesdeAnterior < 3.0 && empty($evento)) {
                $skipped++;
                continue;
            }
        }

        $distAcumuladaKm = normalizarDistanciaAcumulada(
            $lastDistKm,
            $distAcumuladaKm,
            $distanciaDesdeAnterior,
            $deltaTiempoSeg
        );

        $precioParcial = calcularPrecioParcialDesdeConfig($config, $distAcumuladaKm, $tiempoSeg);

        $insertStmt->execute([
            ':solicitud_id' => $solicitudId,
            ':conductor_id' => $conductorId,
            ':latitud' => $lat,
            ':longitud' => $lng,
            ':precision_gps' => $precisionGps,
            ':altitud' => $altitud,
            ':velocidad' => $velocidad,
            ':bearing' => $bearing,
            ':distancia_acumulada_km' => $distAcumuladaKm,
            ':tiempo_transcurrido_seg' => $tiempoSeg,
            ':distancia_desde_anterior' => $distanciaDesdeAnterior,
            ':precio_parcial' => $precioParcial,
            ':fase_viaje' => $faseViaje,
            ':evento' => $evento,
        ]);

        $inserted++;
        $ultimoPrecio = $precioParcial;
        $ultimoDist = $distAcumuladaKm;
        $ultimoTiempo = $tiempoSeg;
        $ultimoPunto = [
            'latitud' => $lat,
            'longitud' => $lng,
            'fase_viaje' => $faseViaje,
        ];

        $lastLat = $lat;
        $lastLng = $lng;
        $lastTiempo = $tiempoSeg;
        $lastDistKm = $distAcumuladaKm;
    }

    if ($inserted > 0 && $ultimoPunto !== null) {
        $stmt = $db->prepare(
            "UPDATE detalles_conductor
            SET latitud_actual = :latitud,
                longitud_actual = :longitud,
                ultima_actualizacion = NOW()
            WHERE usuario_id = :conductor_id"
        );
        $stmt->execute([
            ':latitud' => $ultimoPunto['latitud'],
            ':longitud' => $ultimoPunto['longitud'],
            ':conductor_id' => $conductorId,
        ]);

        $stmt = $db->prepare(
            "UPDATE solicitudes_servicio
            SET distancia_recorrida = :distancia,
                tiempo_transcurrido = :tiempo,
                precio_en_tracking = :precio
            WHERE id = :solicitud_id"
        );
        $stmt->execute([
            ':distancia' => $ultimoDist,
            ':tiempo' => $ultimoTiempo,
            ':precio' => $ultimoPrecio,
            ':solicitud_id' => $solicitudId,
        ]);

        upsertTrackingSnapshot(
            $db,
            $solicitudId,
            $conductorId,
            $ultimoPunto['latitud'],
            $ultimoPunto['longitud'],
            $ultimoDist,
            $ultimoTiempo,
            $ultimoPrecio,
            $ultimoPunto['fase_viaje']
        );

        // Refrescar cache de alta frecuencia para APIs realtime.
        refreshRealtimeTrackingCache(
            $solicitudId,
            $conductorId,
            $ultimoPunto['latitud'],
            $ultimoPunto['longitud'],
            $ultimoDist,
            $ultimoTiempo,
            $ultimoPrecio,
            $ultimoPunto['fase_viaje']
        );
    }

    return [
        'inserted' => $inserted,
        'skipped' => $skipped,
        'distancia_acumulada_km' => round($ultimoDist, 3),
        'tiempo_transcurrido_seg' => $ultimoTiempo,
        'precio_parcial' => round($ultimoPrecio, 2),
    ];
}

/**
 * Sincroniza en Redis el estado realtime más reciente del viaje.
 *
 * Este cache acelera:
 * - lectura de ubicación de conductor,
 * - detección de cambios,
 * - paneles de monitoreo en tiempo real.
 */
function refreshRealtimeTrackingCache(
    int $solicitudId,
    int $conductorId,
    float $latitud,
    float $longitud,
    float $distanciaKm,
    int $tiempoSeg,
    float $precioParcial,
    string $faseViaje
): void {
    try {
        $now = time();
        $statePayload = [
            'trip_id' => $solicitudId,
            'lat' => $latitud,
            'lng' => $longitud,
            'timestamp' => gmdate('c', $now),
            'distance_km' => round($distanciaKm, 4),
            'elapsed_time_sec' => $tiempoSeg,
            'price' => round($precioParcial, 2),
            'fase' => $faseViaje,
            'source' => 'tracking_ingest',
        ];

        Cache::set('driver_location:' . $conductorId, (string) json_encode([
            'lat' => $latitud,
            'lng' => $longitud,
            'speed' => null,
            'timestamp' => $now,
        ]), 30);
        Cache::sAdd('active_drivers', (string) $conductorId);

        Cache::set('trip_tracking_latest:' . $solicitudId, (string) json_encode([
            'solicitud_id' => $solicitudId,
            'conductor_id' => $conductorId,
            'latitud' => $latitud,
            'longitud' => $longitud,
            'distancia_km' => $distanciaKm,
            'tiempo_seg' => $tiempoSeg,
            'precio_parcial' => $precioParcial,
            'fase_viaje' => $faseViaje,
            'timestamp' => $now,
        ]), 7200);

        // Estado realtime normalizado para canales push (SSE/WebSocket gateway).
        Cache::set('trip:' . $solicitudId . ':state', (string) json_encode($statePayload), 7200);

        $redis = Cache::redis();
        if ($redis) {
            // Métricas O(1) compactas para pricing/tracking canónico.
            $redis->setex('trip:' . $solicitudId . ':metrics', 7200, json_encode([
                'distance_km' => round($distanciaKm, 4),
                'elapsed_time_sec' => $tiempoSeg,
                'avg_speed_kmh' => $tiempoSeg > 0 ? round(($distanciaKm * 3600) / $tiempoSeg, 2) : 0,
                'price' => round($precioParcial, 2),
                'last_timestamp' => gmdate('c', $now),
                'last_ts' => $now,
                'last_lat' => $latitud,
                'last_lng' => $longitud,
                'phase' => $faseViaje,
            ], JSON_UNESCAPED_UNICODE));

            // Canal de actualización en vivo para pasajeros.
            $redis->publish('trip_updates:' . $solicitudId, json_encode($statePayload, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        // Redis es capa secundaria: no bloquear flujo principal por cache.
        error_log('[tracking_ingest] cache warning: ' . $e->getMessage());
    }
}

function obtenerViajeTracking(PDO $db, int $solicitudId): array
{
    $stmt = $db->prepare(
        "SELECT
            s.id,
            s.estado,
            s.tipo_vehiculo,
            s.empresa_id,
            ac.conductor_id
        FROM solicitudes_servicio s
        LEFT JOIN asignaciones_conductor ac
            ON s.id = ac.solicitud_id
            AND ac.estado IN ('asignado', 'llegado', 'en_curso', 'completado')
        WHERE s.id = :solicitud_id
        LIMIT 1"
    );
    $stmt->execute([':solicitud_id' => $solicitudId]);
    $viaje = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$viaje) {
        throw new Exception('Viaje no encontrado');
    }

    return $viaje;
}

function validarConductorYEstado(array $viaje, int $conductorId): void
{
    if (!empty($viaje['conductor_id']) && intval($viaje['conductor_id']) !== $conductorId) {
        throw new Exception('No autorizado para este viaje');
    }

    $estadosValidos = ['aceptada', 'conductor_llego', 'recogido', 'en_curso', 'en_viaje', 'hacia_destino'];
    if (!in_array($viaje['estado'], $estadosValidos, true)) {
        throw new Exception('Viaje no está en estado válido para tracking');
    }
}

function obtenerConfigTarifaTracking(PDO $db, array $viaje): array
{
    $tipoVehiculo = normalizarTipoVehiculoTracking($viaje['tipo_vehiculo'] ?? 'moto');
    $empresaId = $viaje['empresa_id'] ?? null;
    $config = null;

    $tiposCandidatos = candidatosTipoVehiculoTracking($tipoVehiculo);

    if (!empty($empresaId)) {
        foreach ($tiposCandidatos as $tipo) {
            $stmt = $db->prepare(
                "SELECT tarifa_base, costo_por_km, costo_por_minuto, tarifa_minima
                FROM configuracion_precios
                WHERE empresa_id = :empresa_id AND tipo_vehiculo = :tipo AND activo = 1
                LIMIT 1"
            );
            $stmt->execute([':empresa_id' => $empresaId, ':tipo' => $tipo]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($config) {
                break;
            }
        }
    }

    if (!$config) {
        foreach ($tiposCandidatos as $tipo) {
            $stmt = $db->prepare(
                "SELECT tarifa_base, costo_por_km, costo_por_minuto, tarifa_minima
                FROM configuracion_precios
                WHERE empresa_id IS NULL AND tipo_vehiculo = :tipo AND activo = 1
                LIMIT 1"
            );
            $stmt->execute([':tipo' => $tipo]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($config) {
                break;
            }
        }
    }

    if (!$config) {
        error_log('[tracking_ingest] Sin configuración de precios para solicitud ' . ($viaje['id'] ?? 'N/A') . ', tipo=' . ($viaje['tipo_vehiculo'] ?? 'N/A') . '. Usando fallback seguro.');
        return configTarifaFallback();
    }

    return $config;
}

function normalizarTipoVehiculoTracking(string $tipoVehiculo): string
{
    $normalized = strtolower(trim($tipoVehiculo));
    $aliases = [
        'moto_taxi' => 'mototaxi',
        'moto taxi' => 'mototaxi',
        'motocarro' => 'mototaxi',
        'moto_carga' => 'mototaxi',
        'motorcycle' => 'moto',
        'carro' => 'auto',
        'automovil' => 'carro',
        'car' => 'carro',
    ];

    return $aliases[$normalized] ?? ($normalized !== '' ? $normalized : 'moto');
}

function candidatosTipoVehiculoTracking(string $tipoVehiculo): array
{
    $base = normalizarTipoVehiculoTracking($tipoVehiculo);
    $candidatos = [$base];

    if ($base === 'mototaxi') {
        $candidatos[] = 'moto';
    } elseif ($base === 'auto') {
        $candidatos[] = 'carro';
    } elseif ($base === 'carro') {
        $candidatos[] = 'auto';
    }

    if (!in_array('moto', $candidatos, true)) {
        $candidatos[] = 'moto';
    }

    return array_values(array_unique($candidatos));
}

function configTarifaFallback(): array
{
    return [
        'tarifa_base' => 0,
        'costo_por_km' => 0,
        'costo_por_minuto' => 0,
        'tarifa_minima' => 0,
    ];
}

function obtenerUltimoEstadoTracking(PDO $db, int $solicitudId): array
{
    if (trackingSnapshotTableAvailable($db)) {
        $stmt = $db->prepare(
            "SELECT latitud, longitud, tiempo_transcurrido_seg, distancia_acumulada_km
            FROM viaje_tracking_snapshot
            WHERE solicitud_id = :solicitud_id
            LIMIT 1"
        );
        $stmt->execute([':solicitud_id' => $solicitudId]);
        $snapshot = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($snapshot) {
            return [
                floatval($snapshot['latitud']),
                floatval($snapshot['longitud']),
                intval($snapshot['tiempo_transcurrido_seg']),
                floatval($snapshot['distancia_acumulada_km'] ?? 0),
            ];
        }
    }

    $stmt = $db->prepare(
        "SELECT latitud, longitud, tiempo_transcurrido_seg, distancia_acumulada_km
        FROM viaje_tracking_realtime
        WHERE solicitud_id = :solicitud_id
        ORDER BY timestamp_gps DESC
        LIMIT 1"
    );
    $stmt->execute([':solicitud_id' => $solicitudId]);
    $ultimo = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($ultimo) {
        return [
            floatval($ultimo['latitud']),
            floatval($ultimo['longitud']),
            intval($ultimo['tiempo_transcurrido_seg']),
            floatval($ultimo['distancia_acumulada_km'] ?? 0),
        ];
    }

    return [null, null, null, 0.0];
}

function esSaltoGpsInvalido(float $distanciaMetros, int $deltaTiempoSeg, ?float $precisionGps, float $velocidadKmh, ?string $evento): bool
{
    if (!empty($evento)) {
        return false;
    }

    if ($precisionGps !== null && $precisionGps > 120) {
        return true;
    }

    if ($deltaTiempoSeg <= 0) {
        return $distanciaMetros > 120;
    }

    $velocidadCalculadaKmh = ($distanciaMetros / 1000.0) / ($deltaTiempoSeg / 3600.0);
    $velocidadReferencia = max($velocidadCalculadaKmh, $velocidadKmh);

    if ($velocidadReferencia > 180) {
        return true;
    }

    return $distanciaMetros > 1500 && $deltaTiempoSeg < 8;
}

function normalizarDistanciaAcumulada(float $distAnteriorKm, float $distReportadaKm, float $distanciaDesdeAnteriorM, int $deltaTiempoSeg): float
{
    $distanciaBaseKm = max(0.0, $distAnteriorKm);

    // Nunca permitir regresión del acumulado por paquetes atrasados.
    $normalizadaKm = max($distReportadaKm, $distanciaBaseKm);

    $maxIncByJumpKm = max(0.0, ($distanciaDesdeAnteriorM / 1000.0) + 0.15);
    $maxIncByTimeKm = $deltaTiempoSeg > 0
        ? (($deltaTiempoSeg / 3600.0) * 130.0) + 0.2
        : 0.2;
    $maxIncrementoKm = min($maxIncByJumpKm, $maxIncByTimeKm);
    $maxPermitidaKm = $distanciaBaseKm + $maxIncrementoKm;

    if ($normalizadaKm > $maxPermitidaKm) {
        $normalizadaKm = $maxPermitidaKm;
    }

    return round($normalizadaKm, 6);
}

function upsertTrackingSnapshot(
    PDO $db,
    int $solicitudId,
    int $conductorId,
    float $latitud,
    float $longitud,
    float $distanciaKm,
    int $tiempoSeg,
    float $precioParcial,
    string $faseViaje
): void {
    if (!trackingSnapshotTableAvailable($db)) {
        return;
    }

    $stmt = $db->prepare(
        "INSERT INTO viaje_tracking_snapshot (
            solicitud_id,
            conductor_id,
            latitud,
            longitud,
            distancia_acumulada_km,
            tiempo_transcurrido_seg,
            precio_parcial,
            fase_viaje,
            actualizado_en
        ) VALUES (
            :solicitud_id,
            :conductor_id,
            :latitud,
            :longitud,
            :distancia,
            :tiempo,
            :precio,
            :fase_viaje,
            NOW()
        )
        ON CONFLICT (solicitud_id) DO UPDATE SET
            conductor_id = EXCLUDED.conductor_id,
            latitud = EXCLUDED.latitud,
            longitud = EXCLUDED.longitud,
            distancia_acumulada_km = EXCLUDED.distancia_acumulada_km,
            tiempo_transcurrido_seg = EXCLUDED.tiempo_transcurrido_seg,
            precio_parcial = EXCLUDED.precio_parcial,
            fase_viaje = EXCLUDED.fase_viaje,
            actualizado_en = NOW()"
    );

    $stmt->execute([
        ':solicitud_id' => $solicitudId,
        ':conductor_id' => $conductorId,
        ':latitud' => $latitud,
        ':longitud' => $longitud,
        ':distancia' => $distanciaKm,
        ':tiempo' => $tiempoSeg,
        ':precio' => $precioParcial,
        ':fase_viaje' => $faseViaje,
    ]);
}

function trackingSnapshotTableAvailable(PDO $db): bool
{
    static $cachedResult = null;

    if ($cachedResult !== null) {
        return $cachedResult;
    }

    try {
        $stmt = $db->query("SELECT to_regclass('public.viaje_tracking_snapshot') AS table_name");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $cachedResult = !empty($row['table_name']);
        return $cachedResult;
    } catch (Exception $e) {
        $cachedResult = false;
        return false;
    }
}

function calcularPrecioParcialDesdeConfig(array $config, float $distanciaKm, int $tiempoSeg): float
{
    $tiempoMin = $tiempoSeg / 60.0;

    $precio = floatval($config['tarifa_base']) +
        ($distanciaKm * floatval($config['costo_por_km'])) +
        ($tiempoMin * floatval($config['costo_por_minuto']));

    $tarifaMinima = floatval($config['tarifa_minima'] ?? 0);
    if ($precio < $tarifaMinima) {
        $precio = $tarifaMinima;
    }

    return round($precio, 2);
}

function calcularDistanciaHaversine(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371000;

    $lat1Rad = deg2rad($lat1);
    $lat2Rad = deg2rad($lat2);
    $deltaLat = deg2rad($lat2 - $lat1);
    $deltaLon = deg2rad($lon2 - $lon1);

    $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
        cos($lat1Rad) * cos($lat2Rad) *
        sin($deltaLon / 2) * sin($deltaLon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}
