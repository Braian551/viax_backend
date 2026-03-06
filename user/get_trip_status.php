<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

function parseWaitSeconds($rawValue): int {
    $value = intval($rawValue ?? 0);
    if ($value < 0) {
        return 0;
    }
    if ($value > 25) {
        return 25;
    }
    return $value;
}

function sanitizeSignature($rawValue): string {
    if (!is_string($rawValue)) {
        return '';
    }
    return substr(trim($rawValue), 0, 120);
}

function clampFloat(float $value, float $min, float $max): float {
    if ($value < $min) {
        return $min;
    }
    if ($value > $max) {
        return $max;
    }
    return $value;
}

function calcularEtaConduccion(float $distanciaKm, ?float $velocidadKmh, string $estado): ?int {
    if ($distanciaKm <= 0) {
        return 0;
    }

    if ($estado === 'conductor_llego') {
        return 1;
    }

    $baseSpeed = 28.0;
    if ($distanciaKm > 8) {
        $baseSpeed = 35.0;
    } elseif ($distanciaKm > 3) {
        $baseSpeed = 30.0;
    } elseif ($distanciaKm < 0.8) {
        $baseSpeed = 18.0;
    }

    if ($velocidadKmh !== null && $velocidadKmh > 2) {
        // Suaviza velocidad instantanea para estabilizar ETA.
        $speed = clampFloat(($velocidadKmh * 0.65) + ($baseSpeed * 0.35), 12.0, 55.0);
    } else {
        $speed = $baseSpeed;
    }

    $etaMinutos = (int)ceil(($distanciaKm / $speed) * 60.0);
    if ($distanciaKm <= 0.2 && $etaMinutos < 1) {
        $etaMinutos = 1;
    }

    if ($etaMinutos < 1) {
        $etaMinutos = 1;
    }
    if ($etaMinutos > 90) {
        $etaMinutos = 90;
    }

    return $etaMinutos;
}

function fetchTripRow(PDO $db, int $solicitudId): ?array {
    $stmt = $db->prepare(
        "
        SELECT
            s.*,
            ac.conductor_id,
            ac.estado as estado_asignacion,
            ac.asignado_en as fecha_asignacion,
            u.nombre as conductor_nombre,
            u.apellido as conductor_apellido,
            u.telefono as conductor_telefono,
            u.foto_perfil as conductor_foto,
            dc.vehiculo_tipo,
            dc.vehiculo_marca,
            dc.vehiculo_modelo,
            dc.vehiculo_placa,
            dc.vehiculo_color,
            dc.calificacion_promedio as conductor_calificacion,
            dc.latitud_actual as conductor_latitud,
            dc.longitud_actual as conductor_longitud,
            vrt.distancia_real_km as tracking_distancia,
            vrt.tiempo_real_minutos as tracking_tiempo,
            vrt.precio_final_aplicado as tracking_precio,
            vts.velocidad as tracking_velocidad_kmh,
            vts.actualizado_en as tracking_actualizado_en,
            EXTRACT(EPOCH FROM (s.completado_en - s.aceptado_en)) / 60 as tiempo_calculado_min
        FROM solicitudes_servicio s
        LEFT JOIN asignaciones_conductor ac
            ON s.id = ac.solicitud_id
            AND ac.estado IN ('asignado', 'llegado', 'en_curso', 'completado')
        LEFT JOIN usuarios u ON ac.conductor_id = u.id
        LEFT JOIN detalles_conductor dc ON u.id = dc.usuario_id
        LEFT JOIN viaje_resumen_tracking vrt ON s.id = vrt.solicitud_id
        LEFT JOIN viaje_tracking_snapshot vts ON s.id = vts.solicitud_id
        WHERE s.id = ?
    "
    );

    $stmt->execute([$solicitudId]);
    $trip = $stmt->fetch(PDO::FETCH_ASSOC);

    return $trip ?: null;
}

function calcularDistanciaYEta(array $trip): array {
    $distanciaConductorKm = null;
    $etaMinutos = null;

    if (!empty($trip['conductor_id']) && !empty($trip['conductor_latitud']) && !empty($trip['conductor_longitud'])) {
        $lat1 = deg2rad((float)$trip['latitud_recogida']);
        $lon1 = deg2rad((float)$trip['longitud_recogida']);
        $lat2 = deg2rad((float)$trip['conductor_latitud']);
        $lon2 = deg2rad((float)$trip['conductor_longitud']);

        $dlat = $lat2 - $lat1;
        $dlon = $lon2 - $lon1;

        $a = sin($dlat / 2) * sin($dlat / 2) + cos($lat1) * cos($lat2) * sin($dlon / 2) * sin($dlon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distanciaConductorKm = 6371 * $c;

        $etaMinutos = calcularEtaConduccion(
            (float)$distanciaConductorKm,
            isset($trip['tracking_velocidad_kmh']) ? (float)$trip['tracking_velocidad_kmh'] : null,
            (string)($trip['estado'] ?? '')
        );
    }

    return [
        'distancia_conductor_km' => $distanciaConductorKm,
        'eta_minutos' => $etaMinutos,
    ];
}

function buildRealtimeSignature(array $trip, ?float $distanciaConductorKm, ?int $etaMinutos): string {
    $normalized = [
        'id' => (int)$trip['id'],
        'estado' => (string)($trip['estado'] ?? ''),
        'lat' => isset($trip['conductor_latitud']) ? round((float)$trip['conductor_latitud'], 5) : null,
        'lng' => isset($trip['conductor_longitud']) ? round((float)$trip['conductor_longitud'], 5) : null,
        'speed' => isset($trip['tracking_velocidad_kmh']) ? round((float)$trip['tracking_velocidad_kmh'], 1) : null,
        'distancia_conductor' => $distanciaConductorKm !== null ? round($distanciaConductorKm, 2) : null,
        'eta' => $etaMinutos,
        'distancia_real' => isset($trip['tracking_distancia']) ? round((float)$trip['tracking_distancia'], 2) : 0,
        'tiempo_seg' => isset($trip['tiempo_transcurrido']) ? (int)$trip['tiempo_transcurrido'] : 0,
        'precio_tracking' => isset($trip['precio_en_tracking']) ? round((float)$trip['precio_en_tracking'], 2) : 0,
    ];

    return sha1(json_encode($normalized));
}

try {
    $solicitudIdRaw = $_GET['solicitud_id'] ?? null;
    $waitSeconds = parseWaitSeconds($_GET['wait_seconds'] ?? 0);
    $sinceSignature = sanitizeSignature($_GET['since_signature'] ?? '');

    if (!$solicitudIdRaw) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'solicitud_id es requerido']);
        exit();
    }

    $solicitudId = intval($solicitudIdRaw);
    if ($solicitudId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'solicitud_id inválido']);
        exit();
    }

    $database = new Database();
    $db = $database->getConnection();

    $trip = fetchTripRow($db, $solicitudId);
    if (!$trip) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Solicitud no encontrada']);
        exit();
    }

    // Long-polling por firma: espera cambios relevantes sin hacer polling agresivo del cliente.
    if ($waitSeconds > 0 && $sinceSignature !== '') {
        $deadline = microtime(true) + $waitSeconds;

        while (microtime(true) < $deadline) {
            $distEtaLoop = calcularDistanciaYEta($trip);
            $signatureLoop = buildRealtimeSignature(
                $trip,
                $distEtaLoop['distancia_conductor_km'],
                $distEtaLoop['eta_minutos']
            );

            if ($signatureLoop !== $sinceSignature) {
                break;
            }

            usleep(350000);

            $updatedTrip = fetchTripRow($db, $solicitudId);
            if (!$updatedTrip) {
                break;
            }
            $trip = $updatedTrip;
        }
    }

    $distEta = calcularDistanciaYEta($trip);
    $distanciaConductorKm = $distEta['distancia_conductor_km'];
    $etaMinutos = $distEta['eta_minutos'];

    $distanciaReal = null;
    $tiempoRealMinutos = null;
    $precioReal = null;

    if (isset($trip['tracking_distancia']) && $trip['tracking_distancia'] > 0) {
        $distanciaReal = (float)$trip['tracking_distancia'];
    } elseif (isset($trip['distancia_recorrida']) && $trip['distancia_recorrida'] > 0) {
        $distanciaReal = (float)$trip['distancia_recorrida'];
    }

    if (isset($trip['tracking_tiempo']) && $trip['tracking_tiempo'] > 0) {
        $tiempoRealMinutos = (int)$trip['tracking_tiempo'];
    } elseif (isset($trip['tiempo_transcurrido']) && $trip['tiempo_transcurrido'] > 0) {
        $tiempoRealMinutos = (int)ceil($trip['tiempo_transcurrido'] / 60);
    } elseif (isset($trip['tiempo_calculado_min']) && $trip['tiempo_calculado_min'] > 0) {
        $tiempoRealMinutos = (int)ceil($trip['tiempo_calculado_min']);
    }

    if (isset($trip['tracking_precio']) && $trip['tracking_precio'] > 0) {
        $precioReal = (float)$trip['tracking_precio'];
    } elseif (isset($trip['precio_final']) && $trip['precio_final'] > 0) {
        $precioReal = (float)$trip['precio_final'];
    }

    $signature = buildRealtimeSignature($trip, $distanciaConductorKm, $etaMinutos);

    echo json_encode([
        'success' => true,
        'meta' => [
            'signature' => $signature,
            'generated_at' => gmdate('c'),
            'wait_seconds' => $waitSeconds,
        ],
        'trip' => [
            'id' => (int)$trip['id'],
            'uuid' => $trip['uuid_solicitud'],
            'estado' => $trip['estado'],
            'tipo_servicio' => $trip['tipo_servicio'],
            'origen' => [
                'latitud' => (float)$trip['latitud_recogida'],
                'longitud' => (float)$trip['longitud_recogida'],
                'direccion' => $trip['direccion_recogida'],
            ],
            'destino' => [
                'latitud' => (float)$trip['latitud_destino'],
                'longitud' => (float)$trip['longitud_destino'],
                'direccion' => $trip['direccion_destino'],
            ],
            'distancia_estimada' => (float)($trip['distancia_estimada'] ?? 0),
            'tiempo_estimado_min' => (int)($trip['tiempo_estimado'] ?? 0),
            'distancia_km' => $distanciaReal ?? (float)($trip['distancia_estimada'] ?? 0),
            'duracion_minutos' => $tiempoRealMinutos ?? (int)($trip['tiempo_estimado'] ?? 0),
            'duracion_segundos' => isset($trip['tiempo_transcurrido']) && $trip['tiempo_transcurrido'] > 0
                ? (int)$trip['tiempo_transcurrido']
                : (($tiempoRealMinutos ?? 0) * 60),
            'fecha_creacion' => to_iso8601($trip['fecha_creacion']),
            'fecha_aceptado' => to_iso8601($trip['aceptado_en'] ?? null),
            'fecha_completado' => to_iso8601($trip['completado_en'] ?? null),
            'distancia_recorrida' => $distanciaReal,
            'tiempo_transcurrido' => $tiempoRealMinutos,
            'tiempo_transcurrido_seg' => isset($trip['tiempo_transcurrido']) && $trip['tiempo_transcurrido'] > 0
                ? (int)$trip['tiempo_transcurrido']
                : (($tiempoRealMinutos ?? 0) * 60),
            'precio_estimado' => (float)($trip['precio_estimado'] ?? 0),
            'precio_final' => $precioReal ?? (float)($trip['precio_estimado'] ?? 0),
            'precio_en_tracking' => isset($trip['precio_en_tracking']) ? (float)$trip['precio_en_tracking'] : null,
            'precio_ajustado_por_tracking' => isset($trip['precio_ajustado_por_tracking']) ? (bool)$trip['precio_ajustado_por_tracking'] : false,
            'conductor' => $trip['conductor_id'] ? [
                'id' => (int)$trip['conductor_id'],
                'nombre' => trim($trip['conductor_nombre'] . ' ' . $trip['conductor_apellido']),
                'telefono' => $trip['conductor_telefono'],
                'foto' => $trip['conductor_foto'],
                'calificacion' => (float)($trip['conductor_calificacion'] ?? 0),
                'vehiculo' => [
                    'tipo' => $trip['vehiculo_tipo'],
                    'marca' => $trip['vehiculo_marca'],
                    'modelo' => $trip['vehiculo_modelo'],
                    'placa' => $trip['vehiculo_placa'],
                    'color' => $trip['vehiculo_color'],
                ],
                'ubicacion' => [
                    'latitud' => (float)$trip['conductor_latitud'],
                    'longitud' => (float)$trip['conductor_longitud'],
                ],
                'distancia_km' => $distanciaConductorKm ? round($distanciaConductorKm, 2) : null,
                'eta_minutos' => $etaMinutos,
            ] : null,
        ],
    ]);
} catch (PDOException $e) {
    error_log('get_trip_status.php PDO Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error de base de datos',
    ]);
} catch (Exception $e) {
    error_log('get_trip_status.php Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
