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
header('Access-Control-Allow-Origin: *');
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

require_once '../../config/database.php';

try {
    $solicitud_id = isset($_GET['solicitud_id']) ? intval($_GET['solicitud_id']) : 0;
    $incluir_puntos = isset($_GET['incluir_puntos']) && $_GET['incluir_puntos'] === 'true';
    
    if ($solicitud_id <= 0) {
        throw new Exception('solicitud_id es requerido');
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Obtener el último punto de tracking (datos actuales)
    $stmt = $db->prepare("
        SELECT 
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
    $stmt->execute([':solicitud_id' => $solicitud_id]);
    $ultimo_punto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Obtener resumen del tracking
    $stmt = $db->prepare("
        SELECT 
            distancia_real_km,
            tiempo_real_minutos,
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
    
    // Obtener datos de la solicitud
    $stmt = $db->prepare("
        SELECT 
            s.id,
            s.tipo_servicio,
            s.estado,
            s.distancia_estimada,
            s.tiempo_estimado,
            s.precio_estimado,
            s.precio_final,
            s.distancia_recorrida,
            s.tiempo_transcurrido,
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
    
    // Si hay datos de tracking
    if ($ultimo_punto) {
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
    // Si NO hay tracking pero el viaje está completado, usar datos finales de la solicitud
    else if (in_array($solicitud['estado'], ['completada', 'entregado'])) {
        $distancia_final = floatval($solicitud['distancia_recorrida'] ?? 0);
        // tiempo_transcurrido está en SEGUNDOS (guardado por finalize.php)
        $tiempo_final_seg = intval($solicitud['tiempo_transcurrido'] ?? 0);
        $tiempo_final_min = ceil($tiempo_final_seg / 60);

        $precio_final = floatval($solicitud['precio_final'] ?? $solicitud['precio_estimado']);
        
        $response['tracking_actual'] = [
            'ubicacion' => null,
            'velocidad_kmh' => 0,
            'distancia_km' => $distancia_final,
            'tiempo_segundos' => $tiempo_final_seg,
            'tiempo_minutos' => $tiempo_final_min,
            'precio_actual' => $precio_final,
            'fase' => 'finalizado',
            'ultima_actualizacion' => null
        ];
    }
    
    // Si hay resumen
    if ($resumen) {
        $response['resumen'] = [
            'distancia_total_km' => floatval($resumen['distancia_real_km']),
            'tiempo_total_minutos' => intval($resumen['tiempo_real_minutos']),
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
