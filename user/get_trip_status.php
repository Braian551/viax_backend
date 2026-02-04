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

try {
    $solicitudId = $_GET['solicitud_id'] ?? null;
    
    if (!$solicitudId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'solicitud_id es requerido']);
        exit();
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Query simplificada primero para verificar que la solicitud existe
    $stmtCheck = $db->prepare("SELECT id, estado FROM solicitudes_servicio WHERE id = ?");
    $stmtCheck->execute([$solicitudId]);
    $check = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$check) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Solicitud no encontrada']);
        exit();
    }
    
    // Obtener información completa de la solicitud con datos del conductor asignado
    // También obtener datos de tracking real si existen
    $stmt = $db->prepare("
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
            -- Datos de tracking real
            vrt.distancia_real_km as tracking_distancia,
            vrt.tiempo_real_minutos as tracking_tiempo,
            vrt.precio_final_aplicado as tracking_precio,
            -- Calcular tiempo desde timestamps si no hay tracking
            EXTRACT(EPOCH FROM (s.completado_en - s.aceptado_en)) / 60 as tiempo_calculado_min
        FROM solicitudes_servicio s
        LEFT JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id AND ac.estado IN ('asignado', 'llegado', 'en_curso', 'completado')
        LEFT JOIN usuarios u ON ac.conductor_id = u.id
        LEFT JOIN detalles_conductor dc ON u.id = dc.usuario_id
        LEFT JOIN viaje_resumen_tracking vrt ON s.id = vrt.solicitud_id
        WHERE s.id = ?
    ");
    
    $stmt->execute([$solicitudId]);
    $trip = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calcular distancia si hay conductor con ubicación
    $distancia_conductor_km = null;
    $eta_minutos = null;
    
    if ($trip['conductor_id'] && $trip['conductor_latitud'] && $trip['conductor_longitud']) {
        // Calcular distancia usando fórmula Haversine
        $lat1 = deg2rad($trip['latitud_recogida']);
        $lon1 = deg2rad($trip['longitud_recogida']);
        $lat2 = deg2rad($trip['conductor_latitud']);
        $lon2 = deg2rad($trip['conductor_longitud']);
        
        $dlat = $lat2 - $lat1;
        $dlon = $lon2 - $lon1;
        
        $a = sin($dlat/2) * sin($dlat/2) + cos($lat1) * cos($lat2) * sin($dlon/2) * sin($dlon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $distancia_conductor_km = 6371 * $c;
        
        // Estimación simple: 30 km/h promedio en ciudad
        $eta_minutos = round(($distancia_conductor_km / 30) * 60);
    }
    
    // Calcular datos reales usando prioridad: tracking > columnas > timestamps > estimados
    $distanciaReal = null;
    $tiempoRealMinutos = null;
    $precioReal = null;
    
    // Distancia: prioridad tracking > distancia_recorrida > estimada
    if (isset($trip['tracking_distancia']) && $trip['tracking_distancia'] > 0) {
        $distanciaReal = (float)$trip['tracking_distancia'];
    } elseif (isset($trip['distancia_recorrida']) && $trip['distancia_recorrida'] > 0) {
        $distanciaReal = (float)$trip['distancia_recorrida'];
    }
    
    // Tiempo: prioridad tracking > tiempo_transcurrido > calculado desde timestamps
    // NOTA: tiempo_transcurrido está en SEGUNDOS (guardado por finalize.php)
    // tracking_tiempo está en MINUTOS (calculado en resumen)
    if (isset($trip['tracking_tiempo']) && $trip['tracking_tiempo'] > 0) {
        $tiempoRealMinutos = (int)$trip['tracking_tiempo'];
    } elseif (isset($trip['tiempo_transcurrido']) && $trip['tiempo_transcurrido'] > 0) {
        // tiempo_transcurrido está en SEGUNDOS, convertir a minutos
        $tiempoRealMinutos = (int)ceil($trip['tiempo_transcurrido'] / 60);
    } elseif (isset($trip['tiempo_calculado_min']) && $trip['tiempo_calculado_min'] > 0) {
        $tiempoRealMinutos = (int)ceil($trip['tiempo_calculado_min']);
    }

    
    // Precio: prioridad tracking > precio_final
    if (isset($trip['tracking_precio']) && $trip['tracking_precio'] > 0) {
        $precioReal = (float)$trip['tracking_precio'];
    } elseif (isset($trip['precio_final']) && $trip['precio_final'] > 0) {
        $precioReal = (float)$trip['precio_final'];
    }

    echo json_encode([
        'success' => true,
        'trip' => [
            'id' => (int)$trip['id'],
            'uuid' => $trip['uuid_solicitud'],
            'estado' => $trip['estado'],
            'tipo_servicio' => $trip['tipo_servicio'],
            'origen' => [
                'latitud' => (float)$trip['latitud_recogida'],
                'longitud' => (float)$trip['longitud_recogida'],
                'direccion' => $trip['direccion_recogida']
            ],
            'destino' => [
                'latitud' => (float)$trip['latitud_destino'],
                'longitud' => (float)$trip['longitud_destino'],
                'direccion' => $trip['direccion_destino']
            ],
            // Datos estimados (siempre disponibles)
            'distancia_estimada' => (float)($trip['distancia_estimada'] ?? 0),
            'tiempo_estimado_min' => (int)($trip['tiempo_estimado'] ?? 0),
            // Datos reales (con fallback a estimados)
            'distancia_km' => $distanciaReal ?? (float)($trip['distancia_estimada'] ?? 0),
            'duracion_minutos' => $tiempoRealMinutos ?? (int)($trip['tiempo_estimado'] ?? 0),
            // NUEVO: tiempo en segundos exactos (para precisión en viajes cortos)
            'duracion_segundos' => isset($trip['tiempo_transcurrido']) && $trip['tiempo_transcurrido'] > 0 
                ? (int)$trip['tiempo_transcurrido'] 
                : (($tiempoRealMinutos ?? 0) * 60),
            'fecha_creacion' => to_iso8601($trip['fecha_creacion']),
            'fecha_aceptado' => to_iso8601($trip['aceptado_en'] ?? null),
            'fecha_completado' => to_iso8601($trip['completado_en'] ?? null),
            // Datos de tracking en tiempo real - SIEMPRE devolver el mejor valor disponible
            'distancia_recorrida' => $distanciaReal,
            'tiempo_transcurrido' => $tiempoRealMinutos,
            // NUEVO: segundos exactos para compatibilidad
            'tiempo_transcurrido_seg' => isset($trip['tiempo_transcurrido']) && $trip['tiempo_transcurrido'] > 0 
                ? (int)$trip['tiempo_transcurrido'] 
                : (($tiempoRealMinutos ?? 0) * 60),
            'precio_estimado' => (float)($trip['precio_estimado'] ?? 0),
            // Precio final: usar tracking > precio_final > precio_estimado
            'precio_final' => $precioReal ?? (float)($trip['precio_estimado'] ?? 0),

            // precio_en_tracking es el precio parcial calculado durante el viaje
            'precio_en_tracking' => isset($trip['precio_en_tracking']) ? (float)$trip['precio_en_tracking'] : null,
            // precio_ajustado_por_tracking es un boolean, indica si el precio fue calculado con tracking real
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
                    'color' => $trip['vehiculo_color']
                ],
                'ubicacion' => [
                    'latitud' => (float)$trip['conductor_latitud'],
                    'longitud' => (float)$trip['conductor_longitud']
                ],
                'distancia_km' => $distancia_conductor_km ? round($distancia_conductor_km, 2) : null,
                'eta_minutos' => $eta_minutos
            ] : null
        ]
    ]);
    
} catch (PDOException $e) {
    error_log("get_trip_status.php PDO Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error de base de datos'
    ]);
} catch (Exception $e) {
    error_log("get_trip_status.php Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
