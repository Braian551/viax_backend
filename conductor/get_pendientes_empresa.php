<?php
/**
 * API: Obtener conductores pendientes de vinculación a empresa
 * 
 * Devuelve la lista de conductores que están en estado 'pendiente_empresa'
 * y no tienen empresa asignada todavía.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Usar la vista creada en la migración
    $query = "SELECT * FROM conductores_pendientes_vinculacion ORDER BY creado_en DESC";
    $stmt = $db->query($query);
    $conductores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // También obtener estadísticas
    $statsQuery = "SELECT 
                    COUNT(*) as total_pendientes,
                    COUNT(CASE WHEN empresa_solicitada_id IS NOT NULL THEN 1 END) as con_solicitud
                   FROM conductores_pendientes_vinculacion";
    $statsStmt = $db->query($statsQuery);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $conductores,
        'stats' => [
            'total_pendientes' => intval($stats['total_pendientes']),
            'con_solicitud' => intval($stats['con_solicitud']),
            'sin_solicitud' => intval($stats['total_pendientes']) - intval($stats['con_solicitud'])
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
