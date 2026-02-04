<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Accept');

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $conductor_id = isset($_GET['conductor_id']) ? intval($_GET['conductor_id']) : 0;

    if ($conductor_id <= 0) {
        throw new Exception('ID de conductor inválido');
    }

    // Estadísticas del día actual
    $hoy = date('Y-m-d');
    
    // Contar viajes de hoy (usando asignaciones_conductor porque conductor_id en solicitudes puede estar vacío)
    $query_viajes = "SELECT COUNT(DISTINCT s.id) as viajes_hoy
                     FROM solicitudes_servicio s
                     JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
                     WHERE ac.conductor_id = :conductor_id
                     AND DATE(s.fecha_creacion) = :hoy
                     AND s.estado IN ('completada', 'en_curso', 'aceptada', 'conductor_llego')";
    
    $stmt_viajes = $db->prepare($query_viajes);
    $stmt_viajes->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_viajes->bindParam(':hoy', $hoy, PDO::PARAM_STR);
    $stmt_viajes->execute();
    $viajes_hoy = $stmt_viajes->fetch(PDO::FETCH_ASSOC)['viajes_hoy'];

    // Calcular ganancias de hoy (usando precio_final o precio_estimado)
    $query_ganancias = "SELECT COALESCE(SUM(COALESCE(s.precio_final, s.precio_estimado, 0)), 0) as ganancias_hoy
                        FROM solicitudes_servicio s
                        JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
                        WHERE ac.conductor_id = :conductor_id
                        AND DATE(s.fecha_creacion) = :hoy
                        AND s.estado = 'completada'";
    
    $stmt_ganancias = $db->prepare($query_ganancias);
    $stmt_ganancias->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_ganancias->bindParam(':hoy', $hoy, PDO::PARAM_STR);
    $stmt_ganancias->execute();
    $ganancias_hoy = $stmt_ganancias->fetch(PDO::FETCH_ASSOC)['ganancias_hoy'];

    // Calcular horas trabajadas hoy (aproximado por duracion de viajes)
    $query_horas = "SELECT COALESCE(SUM(s.tiempo_estimado), 0) as minutos_hoy
                    FROM solicitudes_servicio s
                    JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
                    WHERE ac.conductor_id = :conductor_id
                    AND DATE(s.fecha_creacion) = :hoy
                    AND s.estado = 'completada'";
    
    $stmt_horas = $db->prepare($query_horas);
    $stmt_horas->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_horas->bindParam(':hoy', $hoy, PDO::PARAM_STR);
    $stmt_horas->execute();
    $minutos_hoy = $stmt_horas->fetch(PDO::FETCH_ASSOC)['minutos_hoy'];
    $horas_hoy = round($minutos_hoy / 60, 1);

    // === ESTADÍSTICAS TOTALES (para el perfil) ===
    
    // Obtener datos de detalles_conductor
    $query_detalles = "SELECT 
                          COALESCE(dc.total_viajes, 0) as total_viajes,
                          COALESCE(dc.calificacion_promedio, 0) as calificacion_promedio,
                          COALESCE(dc.total_calificaciones, 0) as total_calificaciones,
                          COALESCE(dc.ganancias_totales, 0) as ganancias_totales,
                          dc.creado_en as fecha_registro_conductor
                       FROM detalles_conductor dc
                       WHERE dc.usuario_id = :conductor_id";
    
    $stmt_detalles = $db->prepare($query_detalles);
    $stmt_detalles->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_detalles->execute();
    $detalles = $stmt_detalles->fetch(PDO::FETCH_ASSOC);
    
    // Si no hay datos en detalles_conductor, calcular desde solicitudes
    if (!$detalles) {
        // Contar viajes totales completados
        $query_viajes_totales = "SELECT COUNT(*) as viajes_totales
                                  FROM solicitudes_servicio
                                  WHERE conductor_id = :conductor_id
                                  AND estado = 'completada'";
        
        $stmt_viajes_totales = $db->prepare($query_viajes_totales);
        $stmt_viajes_totales->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
        $stmt_viajes_totales->execute();
        $viajes_totales = $stmt_viajes_totales->fetch(PDO::FETCH_ASSOC)['viajes_totales'];
        
        // Calificaciones totales
        $query_calificaciones = "SELECT 
                                    COUNT(*) as total_calificaciones,
                                    COALESCE(AVG(calificacion), 0) as calificacion_promedio
                                 FROM calificaciones
                                 WHERE usuario_calificado_id = :conductor_id";
        
        $stmt_calificaciones = $db->prepare($query_calificaciones);
        $stmt_calificaciones->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
        $stmt_calificaciones->execute();
        $calificaciones = $stmt_calificaciones->fetch(PDO::FETCH_ASSOC);
        
        $detalles = [
            'total_viajes' => $viajes_totales,
            'calificacion_promedio' => round(floatval($calificaciones['calificacion_promedio']), 2),
            'total_calificaciones' => $calificaciones['total_calificaciones'],
            'ganancias_totales' => 0,
            'fecha_registro_conductor' => null
        ];
    }
    
    // Obtener fecha de registro del usuario
    $query_usuario = "SELECT fecha_registro FROM usuarios WHERE id = :conductor_id";
    $stmt_usuario = $db->prepare($query_usuario);
    $stmt_usuario->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_usuario->execute();
    $usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC);
    $fecha_registro = $usuario ? $usuario['fecha_registro'] : null;

    echo json_encode([
        'success' => true,
        'estadisticas' => [
            // Estadísticas del día
            'viajes_hoy' => intval($viajes_hoy),
            'ganancias_hoy' => floatval($ganancias_hoy),
            'horas_hoy' => floatval($horas_hoy),
            
            // Estadísticas totales (para el perfil)
            'viajes_totales' => intval($detalles['total_viajes']),
            'calificacion_promedio' => round(floatval($detalles['calificacion_promedio']), 2),
            'total_calificaciones' => intval($detalles['total_calificaciones']),
            'ganancias_totales' => floatval($detalles['ganancias_totales']),
            'fecha_registro' => $fecha_registro
        ],
        'message' => 'Estadísticas obtenidas exitosamente'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
