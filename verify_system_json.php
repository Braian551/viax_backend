<?php
// verify_system_json.php - Endpoint JSON para verificación del sistema
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $response = [
        'status' => 'success',
        'timestamp' => date('Y-m-d H:i:s'),
        'database' => 'connected',
        'system_check' => []
    ];

    // Verificar tablas principales
    $tables = ['usuarios', 'detalles_conductor', 'solicitudes_servicio'];
    foreach ($tables as $table) {
        try {
            $stmt = $db->query("SELECT COUNT(*) as count FROM $table");
            $count = $stmt->fetchColumn();
            $response['system_check'][] = [
                'component' => $table,
                'status' => 'ok',
                'records' => (int)$count
            ];
        } catch (Exception $e) {
            $response['system_check'][] = [
                'component' => $table,
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }

    // Verificar conductores disponibles
    try {
        $stmt = $db->query("
            SELECT COUNT(*) as count
            FROM usuarios u
            INNER JOIN detalles_conductor dc ON u.id = dc.usuario_id
            WHERE u.tipo_usuario = 'conductor'
            AND u.disponibilidad = 1
            AND dc.estado_verificacion = 'aprobado'
        ");
        $availableDrivers = $stmt->fetchColumn();
        $response['available_drivers'] = (int)$availableDrivers;
    } catch (Exception $e) {
        $response['available_drivers'] = 0;
        $response['driver_error'] = $e->getMessage();
    }

    // Verificar solicitudes pendientes
    try {
        $stmt = $db->query("SELECT COUNT(*) as count FROM solicitudes_servicio WHERE estado = 'pendiente'");
        $pendingRequests = $stmt->fetchColumn();
        $response['pending_requests'] = (int)$pendingRequests;
    } catch (Exception $e) {
        $response['pending_requests'] = 0;
        $response['request_error'] = $e->getMessage();
    }

    http_response_code(200);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed: ' . $e->getMessage(),
        'database' => 'disconnected'
    ]);
}
?>