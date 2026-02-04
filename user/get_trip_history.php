<?php
/**
 * get_trip_history.php
 * Obtiene el historial de viajes de un usuario con DESGLOSE COMPLETO de precios
 * 
 * Este endpoint incluye:
 * - Todos los recargos aplicados (festivo, hora pico, nocturno, espera)
 * - Desglose detallado del precio final
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Obtener parámetros
    $usuario_id = isset($_GET['usuario_id']) ? intval($_GET['usuario_id']) : 0;
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 20;
    $estado = isset($_GET['estado']) ? $_GET['estado'] : null;
    $fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : null;
    $fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : null;
    
    if ($usuario_id <= 0) {
        echo json_encode([
            'success' => false,
            'error' => 'usuario_id es requerido'
        ]);
        exit;
    }
    
    $offset = ($page - 1) * $limit;
    
    // Construir query base
    $whereClause = "WHERE ss.cliente_id = :usuario_id";
    $params = [':usuario_id' => $usuario_id];
    
    // Filtro por estado
    if ($estado && $estado !== 'all') {
        if ($estado === 'completada') {
            $whereClause .= " AND ss.estado IN ('completada', 'entregado')";
        } else {
            $whereClause .= " AND ss.estado = :estado";
            $params[':estado'] = $estado;
        }
    }
    // Filtro por fecha
    if ($fecha_inicio) {
        $whereClause .= " AND DATE(COALESCE(ss.solicitado_en, ss.fecha_creacion)) >= :fecha_inicio";
        $params[':fecha_inicio'] = $fecha_inicio;
    }

    if ($fecha_fin) {
        $whereClause .= " AND DATE(COALESCE(ss.solicitado_en, ss.fecha_creacion)) <= :fecha_fin";
        $params[':fecha_fin'] = $fecha_fin;
    }
    
    // Obtener total de registros
    $countQuery = "SELECT COUNT(*) as total FROM solicitudes_servicio ss $whereClause";
    $countStmt = $conn->prepare($countQuery);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalRecords / $limit);
    
    // Query principal con JOIN a tracking para DESGLOSE COMPLETO
    $query = "
        SELECT 
            ss.id,
            COALESCE(ss.tipo_servicio, 'transporte') as tipo_servicio,
            ss.tipo_vehiculo,
            ss.estado,
            COALESCE(ss.direccion_recogida, '') as origen,
            COALESCE(ss.direccion_destino, '') as destino,
            ss.distancia_estimada,
            ss.tiempo_estimado,
            -- Usar datos reales del tracking
            COALESCE(vrt.distancia_real_km, ss.distancia_recorrida, ss.distancia_estimada) as distancia_real,
            COALESCE(vrt.tiempo_real_minutos, ss.tiempo_transcurrido, ss.tiempo_estimado) as tiempo_real,
            COALESCE(ss.precio_estimado, 0) as precio_estimado,
            COALESCE(vrt.precio_final_aplicado, ss.precio_final, ss.precio_estimado, 0) as precio_final,
            COALESCE(ss.metodo_pago, 'efectivo') as metodo_pago,
            COALESCE(ss.pago_confirmado, false) as pago_confirmado,
            COALESCE(ss.solicitado_en, ss.fecha_creacion) as fecha_solicitud,
            ss.completado_en as fecha_completado,
            ss.aceptado_en as fecha_aceptado,
            -- Campo JSON con desglose
            ss.desglose_precio,
            -- Desglose del tracking
            vrt.tarifa_base,
            vrt.precio_distancia,
            vrt.precio_tiempo,
            vrt.recargo_nocturno,
            vrt.recargo_hora_pico,
            vrt.recargo_festivo,
            vrt.recargo_espera,
            vrt.tiempo_espera_min,
            -- Conductor
            ac.conductor_id,
            u.nombre as conductor_nombre,
            u.apellido as conductor_apellido,
            u.foto_perfil as conductor_foto,
            u.telefono as conductor_telefono,
            COALESCE(dc.calificacion_promedio, 0) as calificacion_conductor,
            COALESCE(dc.vehiculo_placa, '') as conductor_placa,
            COALESCE(dc.vehiculo_marca, '') as conductor_marca,
            COALESCE(dc.vehiculo_modelo, '') as conductor_modelo,
            COALESCE(dc.vehiculo_color, '') as conductor_color,
            -- Calificación dada
            cal.calificacion as calificacion_dada,
            cal.comentarios as comentario_dado
        FROM solicitudes_servicio ss
        LEFT JOIN viaje_resumen_tracking vrt ON ss.id = vrt.solicitud_id
        LEFT JOIN asignaciones_conductor ac ON ss.id = ac.solicitud_id AND ac.estado IN ('aceptada', 'completada', 'completado')
        LEFT JOIN detalles_conductor dc ON ac.conductor_id = dc.usuario_id
        LEFT JOIN usuarios u ON ac.conductor_id = u.id
        LEFT JOIN calificaciones cal ON cal.solicitud_id = ss.id AND cal.usuario_calificador_id = ss.cliente_id
        $whereClause
        ORDER BY COALESCE(ss.solicitado_en, ss.fecha_creacion) DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $viajes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear datos con DESGLOSE COMPLETO
    $viajesFormateados = array_map(function($viaje) {
        $precioFinal = ($viaje['precio_final'] && $viaje['precio_final'] > 0) 
            ? (float)$viaje['precio_final'] 
            : (float)$viaje['precio_estimado'];
        
        // Construir desglose de precio
        $desglose = null;
        
        // Primero intentar obtener del campo JSON
        if (!empty($viaje['desglose_precio'])) {
            $desgloseJson = is_string($viaje['desglose_precio']) 
                ? json_decode($viaje['desglose_precio'], true) 
                : $viaje['desglose_precio'];
            if ($desgloseJson && is_array($desgloseJson)) {
                $desglose = $desgloseJson;
            }
        }
        
        // Si no hay desglose en JSON, construir desde columnas del tracking
        if (!$desglose && isset($viaje['tarifa_base']) && $viaje['tarifa_base'] !== null) {
            $desglose = [
                'tarifa_base' => round((float)($viaje['tarifa_base'] ?? 0), 0),
                'precio_distancia' => round((float)($viaje['precio_distancia'] ?? 0), 0),
                'precio_tiempo' => round((float)($viaje['precio_tiempo'] ?? 0), 0),
                'recargo_nocturno' => round((float)($viaje['recargo_nocturno'] ?? 0), 0),
                'recargo_hora_pico' => round((float)($viaje['recargo_hora_pico'] ?? 0), 0),
                'recargo_festivo' => round((float)($viaje['recargo_festivo'] ?? 0), 0),
                'recargo_espera' => round((float)($viaje['recargo_espera'] ?? 0), 0),
                'tiempo_espera_minutos' => (float)($viaje['tiempo_espera_min'] ?? 0),
                'precio_final' => round($precioFinal, 0)
            ];
        }
            
        return [
            'id' => (int)$viaje['id'],
            'tipo_servicio' => $viaje['tipo_servicio'] ?? 'transporte',
            'tipo_vehiculo' => $viaje['tipo_vehiculo'] ?? null,
            'estado' => $viaje['estado'],
            'origen' => $viaje['origen'] ?? '',
            'destino' => $viaje['destino'] ?? '',
            // Datos reales
            'distancia_km' => $viaje['distancia_real'] ? round((float)$viaje['distancia_real'], 2) : null,
            'duracion_minutos' => $viaje['tiempo_real'] ? (int)$viaje['tiempo_real'] : null,
            // Estimados para referencia
            'distancia_estimada' => $viaje['distancia_estimada'] ? (float)$viaje['distancia_estimada'] : null,
            'duracion_estimada' => $viaje['tiempo_estimado'] ? (int)$viaje['tiempo_estimado'] : null,
            // Precios
            'precio_estimado' => round((float)$viaje['precio_estimado'], 0),
            'precio_final' => round($precioFinal, 0),
            // DESGLOSE COMPLETO DEL PRECIO
            'desglose_precio' => $desglose,
            // Pago
            'metodo_pago' => $viaje['metodo_pago'],
            'pago_confirmado' => (bool)$viaje['pago_confirmado'],
            // Fechas - Convertir a ISO8601 UTC para que el cliente convierta a hora local
            'fecha_solicitud' => to_iso8601($viaje['fecha_solicitud']),
            'fecha_aceptado' => to_iso8601($viaje['fecha_aceptado'] ?? null),
            'fecha_completado' => to_iso8601($viaje['fecha_completado']),
            // Conductor
            'conductor_id' => isset($viaje['conductor_id']) ? (int)$viaje['conductor_id'] : null,
            'conductor_nombre' => $viaje['conductor_nombre'],
            'conductor_apellido' => $viaje['conductor_apellido'],
            'conductor_foto' => $viaje['conductor_foto'],
            'conductor_telefono' => $viaje['conductor_telefono'] ?? null,
            'calificacion_conductor' => $viaje['calificacion_conductor'] ? (float)$viaje['calificacion_conductor'] : null,
            // Vehículo
            'vehiculo' => [
                'placa' => $viaje['conductor_placa'] ?? null,
                'marca' => $viaje['conductor_marca'] ?? null,
                'modelo' => $viaje['conductor_modelo'] ?? null,
                'color' => $viaje['conductor_color'] ?? null
            ],
            // Calificación dada
            'calificacion_dada' => $viaje['calificacion_dada'] ? (int)$viaje['calificacion_dada'] : null,
            'comentario_dado' => $viaje['comentario_dado'],
        ];
    }, $viajes);
    
    echo json_encode([
        'success' => true,
        'viajes' => $viajesFormateados,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_records' => (int)$totalRecords,
            'per_page' => $limit
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error del servidor: ' . $e->getMessage()
    ]);
}
?>
