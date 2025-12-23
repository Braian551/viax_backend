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

    // Obtener viajes activos (en_camino, en_progreso, por_iniciar)
    $query = "SELECT 
                s.id,
                s.tipo_servicio,
                s.estado,
                s.precio_estimado,
                s.distancia_km,
                s.duracion_estimada,
                s.fecha_solicitud,
                uo.direccion as origen,
                uo.latitud as origen_lat,
                uo.longitud as origen_lng,
                ud.direccion as destino,
                ud.latitud as destino_lat,
                ud.longitud as destino_lng,
                u.nombre as cliente_nombre,
                u.apellido as cliente_apellido,
                u.telefono as cliente_telefono
              FROM solicitudes_servicio s
              INNER JOIN usuarios u ON s.usuario_id = u.id
              LEFT JOIN ubicaciones_usuario uo ON s.ubicacion_origen_id = uo.id
              LEFT JOIN ubicaciones_usuario ud ON s.ubicacion_destino_id = ud.id
              WHERE s.conductor_id = :conductor_id
              AND s.estado IN ('en_camino', 'en_progreso', 'por_iniciar', 'aceptada')
              ORDER BY s.fecha_solicitud DESC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt->execute();

    $viajes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'viajes' => $viajes,
        'total' => count($viajes),
        'message' => 'Viajes activos obtenidos exitosamente'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'viajes' => []
    ]);
}
?>
