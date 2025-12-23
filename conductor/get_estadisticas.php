<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Accept');

require_once '../config/database.php';

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
    
    // Contar viajes de hoy
    $query_viajes = "SELECT COUNT(*) as viajes_hoy
                     FROM solicitudes_servicio
                     WHERE conductor_id = :conductor_id
                     AND DATE(fecha_solicitud) = :hoy
                     AND estado IN ('completada', 'en_progreso', 'aceptada')";
    
    $stmt_viajes = $db->prepare($query_viajes);
    $stmt_viajes->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_viajes->bindParam(':hoy', $hoy, PDO::PARAM_STR);
    $stmt_viajes->execute();
    $viajes_hoy = $stmt_viajes->fetch(PDO::FETCH_ASSOC)['viajes_hoy'];

    // Calcular ganancias de hoy
    $query_ganancias = "SELECT COALESCE(SUM(t.monto_conductor), 0) as ganancias_hoy
                        FROM transacciones t
                        INNER JOIN solicitudes_servicio s ON t.solicitud_id = s.id
                        WHERE t.conductor_id = :conductor_id
                        AND DATE(t.fecha_transaccion) = :hoy
                        AND t.estado = 'completada'";
    
    $stmt_ganancias = $db->prepare($query_ganancias);
    $stmt_ganancias->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_ganancias->bindParam(':hoy', $hoy, PDO::PARAM_STR);
    $stmt_ganancias->execute();
    $ganancias_hoy = $stmt_ganancias->fetch(PDO::FETCH_ASSOC)['ganancias_hoy'];

    // Calcular horas trabajadas hoy (aproximado por duración de viajes)
    $query_horas = "SELECT COALESCE(SUM(duracion_estimada), 0) as minutos_hoy
                    FROM solicitudes_servicio
                    WHERE conductor_id = :conductor_id
                    AND DATE(fecha_solicitud) = :hoy
                    AND estado = 'completada'";
    
    $stmt_horas = $db->prepare($query_horas);
    $stmt_horas->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_horas->bindParam(':hoy', $hoy, PDO::PARAM_STR);
    $stmt_horas->execute();
    $minutos_hoy = $stmt_horas->fetch(PDO::FETCH_ASSOC)['minutos_hoy'];
    $horas_hoy = round($minutos_hoy / 60, 1);

    // Obtener calificación promedio
    $query_calificacion = "SELECT COALESCE(AVG(c.calificacion), 0) as calificacion_promedio
                           FROM calificaciones c
                           INNER JOIN solicitudes_servicio s ON c.solicitud_id = s.id
                           WHERE s.conductor_id = :conductor_id";
    
    $stmt_calificacion = $db->prepare($query_calificacion);
    $stmt_calificacion->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_calificacion->execute();
    $calificacion = $stmt_calificacion->fetch(PDO::FETCH_ASSOC)['calificacion_promedio'];

    echo json_encode([
        'success' => true,
        'estadisticas' => [
            'viajes_hoy' => intval($viajes_hoy),
            'ganancias_hoy' => floatval($ganancias_hoy),
            'horas_hoy' => floatval($horas_hoy),
            'calificacion_promedio' => round(floatval($calificacion), 1)
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
