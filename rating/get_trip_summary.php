<?php
/**
 * Endpoint para obtener resumen de viaje completado.
 * 
 * GET /rating/get_trip_summary.php?solicitud_id=123
 */

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
        throw new Exception('Se requiere solicitud_id');
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Obtener datos completos del viaje incluyendo datos reales de tracking
    $stmt = $db->prepare("
        SELECT 
            s.id,
            s.estado,
            s.direccion_recogida,
            s.direccion_destino,
            s.distancia_estimada,
            s.tiempo_estimado,
            -- Datos reales del viaje
            s.distancia_recorrida,
            s.tiempo_transcurrido,
            s.precio_estimado,
            s.precio_final,
            s.metodo_pago,
            s.pago_confirmado,
            s.fecha_creacion,
            s.completado_en,
            s.cliente_id,
            u_cliente.nombre as cliente_nombre,
            u_cliente.apellido as cliente_apellido,
            u_cliente.telefono as cliente_telefono,
            u_cliente.foto_perfil as cliente_foto,
            ac.conductor_id,
            u_conductor.nombre as conductor_nombre,
            u_conductor.apellido as conductor_apellido,
            u_conductor.telefono as conductor_telefono,
            u_conductor.foto_perfil as conductor_foto,
            dc.calificacion_promedio as conductor_calificacion,
            dc.vehiculo_marca,
            dc.vehiculo_modelo,
            dc.vehiculo_placa,
            dc.vehiculo_color
        FROM solicitudes_servicio s
        INNER JOIN usuarios u_cliente ON s.cliente_id = u_cliente.id
        LEFT JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
        LEFT JOIN usuarios u_conductor ON ac.conductor_id = u_conductor.id
        LEFT JOIN detalles_conductor dc ON ac.conductor_id = dc.usuario_id
        WHERE s.id = ?
    ");
    $stmt->execute([$solicitudId]);
    $viaje = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$viaje) {
        throw new Exception('Viaje no encontrado');
    }
    
    // Calcular duración real si hay timestamps
    $duracionReal = null;
    if ($viaje['tiempo_transcurrido']) {
        // Usar tiempo_transcurrido guardado
        $duracionReal = intval($viaje['tiempo_transcurrido']);
    } elseif ($viaje['completado_en'] && $viaje['fecha_creacion']) {
        // Fallback: calcular desde timestamps
        $inicio = new DateTime($viaje['fecha_creacion']);
        $fin = new DateTime($viaje['completado_en']);
        $diff = $inicio->diff($fin);
        $duracionReal = $diff->i + ($diff->h * 60);
    }
    
    // Usar distancia real o estimada
    $distanciaReal = $viaje['distancia_recorrida'] 
        ? floatval($viaje['distancia_recorrida']) 
        : floatval($viaje['distancia_estimada']);
        
    // Usar precio final guardado o precio estimado
    $precioFinal = ($viaje['precio_final'] && $viaje['precio_final'] > 0)
        ? floatval($viaje['precio_final'])
        : floatval($viaje['precio_estimado']);
    
    // Verificar si ya calificaron
    $stmt = $db->prepare("
        SELECT usuario_calificador_id, usuario_calificado_id, calificacion
        FROM calificaciones
        WHERE solicitud_id = ?
    ");
    $stmt->execute([$solicitudId]);
    $calificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $clienteYaCalificó = false;
    $conductorYaCalificó = false;
    
    foreach ($calificaciones as $cal) {
        if ($cal['usuario_calificador_id'] == $viaje['cliente_id']) {
            $clienteYaCalificó = true;
        }
        if ($viaje['conductor_id'] && $cal['usuario_calificador_id'] == $viaje['conductor_id']) {
            $conductorYaCalificó = true;
        }
    }
    
    // Calcular calificación del cliente desde calificaciones
    $stmt = $db->prepare("SELECT AVG(calificacion) FROM calificaciones WHERE usuario_calificado_id = ?");
    $stmt->execute([$viaje['cliente_id']]);
    $clienteCalificacion = $stmt->fetchColumn() ?? 5.0;
    
    echo json_encode([
        'success' => true,
        'viaje' => [
            'id' => $viaje['id'],
            'estado' => $viaje['estado'],
            'origen' => $viaje['direccion_recogida'],
            'destino' => $viaje['direccion_destino'],
            // Datos reales
            'distancia_km' => $distanciaReal,
            'duracion_minutos' => $duracionReal ?? intval($viaje['tiempo_estimado']),
            'precio' => $precioFinal,
            // Datos estimados para referencia
            'distancia_estimada' => floatval($viaje['distancia_estimada']),
            'duracion_estimada' => intval($viaje['tiempo_estimado']),
            'precio_estimado' => floatval($viaje['precio_estimado']),
            'metodo_pago' => $viaje['metodo_pago'] ?? 'Efectivo',
            'pago_confirmado' => (bool)$viaje['pago_confirmado'],
        ],
        'cliente' => [
            'id' => $viaje['cliente_id'],
            'nombre' => trim($viaje['cliente_nombre'] . ' ' . ($viaje['cliente_apellido'] ?? '')),
            'telefono' => $viaje['cliente_telefono'],
            'calificacion' => floatval($clienteCalificacion),
            'foto' => $viaje['cliente_foto'] ?? null,
        ],
        'conductor' => $viaje['conductor_id'] ? [
            'id' => $viaje['conductor_id'],
            'nombre' => trim($viaje['conductor_nombre'] . ' ' . ($viaje['conductor_apellido'] ?? '')),
            'telefono' => $viaje['conductor_telefono'] ?? null,
            'calificacion' => floatval($viaje['conductor_calificacion'] ?? 5.0),
            'foto' => $viaje['conductor_foto'] ?? null,
            'vehiculo' => [
                'marca' => $viaje['vehiculo_marca'],
                'modelo' => $viaje['vehiculo_modelo'],
                'placa' => $viaje['vehiculo_placa'],
                'color' => $viaje['vehiculo_color'],
            ],
        ] : null,
        'calificaciones' => [
            'cliente_califico' => $clienteYaCalificó,
            'conductor_califico' => $conductorYaCalificó,
        ],
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
