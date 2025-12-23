<?php
/**
 * get_payment_summary.php
 * Obtiene el resumen de pagos/gastos de un usuario
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
    $fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : null;
    $fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : null;
    
    if ($usuario_id <= 0) {
        echo json_encode([
            'success' => false,
            'error' => 'usuario_id es requerido'
        ]);
        exit;
    }
    
    // Construir condiciones
    $whereClause = "WHERE usuario_id = :usuario_id AND estado IN ('completada', 'entregado')";
    $params = [':usuario_id' => $usuario_id];
    
    if ($fecha_inicio) {
        $whereClause .= " AND fecha_solicitud >= :fecha_inicio";
        $params[':fecha_inicio'] = $fecha_inicio;
    }
    
    if ($fecha_fin) {
        $whereClause .= " AND fecha_solicitud <= :fecha_fin";
        $params[':fecha_fin'] = $fecha_fin;
    }
    
    // Query para obtener totales
    $query = "
        SELECT 
            COALESCE(SUM(COALESCE(precio_final, precio_estimado, 0)), 0) as total_pagado,
            COUNT(*) as total_viajes,
            COALESCE(AVG(COALESCE(precio_final, precio_estimado, 0)), 0) as promedio_por_viaje
        FROM solicitudes_servicio
        $whereClause
    ";
    
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // También obtener conteo por estado para estadísticas adicionales
    $statsQuery = "
        SELECT 
            estado,
            COUNT(*) as cantidad
        FROM solicitudes_servicio
        WHERE usuario_id = :usuario_id
        GROUP BY estado
    ";
    
    $statsStmt = $conn->prepare($statsQuery);
    $statsStmt->bindValue(':usuario_id', $usuario_id);
    $statsStmt->execute();
    $estadisticas = $statsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stats = [];
    foreach ($estadisticas as $stat) {
        $stats[$stat['estado']] = (int)$stat['cantidad'];
    }
    
    echo json_encode([
        'success' => true,
        'total_pagado' => (float)$result['total_pagado'],
        'total_viajes' => (int)$result['total_viajes'],
        'promedio_por_viaje' => round((float)$result['promedio_por_viaje'], 0),
        'estadisticas_por_estado' => $stats
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error del servidor: ' . $e->getMessage()
    ]);
}
?>
