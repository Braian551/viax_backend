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
    $fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-d');
    $fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');

    if ($conductor_id <= 0) {
        throw new Exception('ID de conductor inválido');
    }

    // Calcular ganancias totales en el período
    $query_total = "SELECT 
                     COALESCE(SUM(t.monto_conductor), 0) as total_ganancias,
                     COUNT(DISTINCT t.solicitud_id) as total_viajes
                    FROM transacciones t
                    WHERE t.conductor_id = :conductor_id
                    AND DATE(t.fecha_transaccion) BETWEEN :fecha_inicio AND :fecha_fin
                    AND t.estado = 'completada'";
    
    $stmt_total = $db->prepare($query_total);
    $stmt_total->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_total->bindParam(':fecha_inicio', $fecha_inicio, PDO::PARAM_STR);
    $stmt_total->bindParam(':fecha_fin', $fecha_fin, PDO::PARAM_STR);
    $stmt_total->execute();
    $totales = $stmt_total->fetch(PDO::FETCH_ASSOC);

    // Ganancias por día
    $query_diario = "SELECT 
                      DATE(t.fecha_transaccion) as fecha,
                      COALESCE(SUM(t.monto_conductor), 0) as ganancias,
                      COUNT(DISTINCT t.solicitud_id) as viajes
                     FROM transacciones t
                     WHERE t.conductor_id = :conductor_id
                     AND DATE(t.fecha_transaccion) BETWEEN :fecha_inicio AND :fecha_fin
                     AND t.estado = 'completada'
                     GROUP BY DATE(t.fecha_transaccion)
                     ORDER BY fecha DESC";
    
    $stmt_diario = $db->prepare($query_diario);
    $stmt_diario->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_diario->bindParam(':fecha_inicio', $fecha_inicio, PDO::PARAM_STR);
    $stmt_diario->bindParam(':fecha_fin', $fecha_fin, PDO::PARAM_STR);
    $stmt_diario->execute();
    $ganancias_diarias = $stmt_diario->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'ganancias' => [
            'total' => floatval($totales['total_ganancias']),
            'total_viajes' => intval($totales['total_viajes']),
            'promedio_por_viaje' => $totales['total_viajes'] > 0 
                ? round(floatval($totales['total_ganancias']) / intval($totales['total_viajes']), 2)
                : 0,
            'desglose_diario' => $ganancias_diarias
        ],
        'periodo' => [
            'inicio' => $fecha_inicio,
            'fin' => $fecha_fin
        ],
        'message' => 'Ganancias obtenidas exitosamente'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
