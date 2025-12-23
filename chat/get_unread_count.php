<?php
/**
 * API: Obtener mensajes no leídos
 * Endpoint: GET /chat/get_unread_count.php
 * 
 * Obtiene el conteo de mensajes no leídos para un usuario en una solicitud.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

try {
    $solicitudId = $_GET['solicitud_id'] ?? null;
    $usuarioId = $_GET['usuario_id'] ?? null;
    
    if (!$solicitudId || !$usuarioId) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'solicitud_id y usuario_id son requeridos'
        ]);
        exit();
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Contar mensajes no leídos dirigidos al usuario
    $stmt = $db->prepare("
        SELECT COUNT(*) as no_leidos
        FROM mensajes_chat 
        WHERE solicitud_id = ? 
        AND destinatario_id = ? 
        AND leido = false 
        AND activo = true
    ");
    $stmt->execute([$solicitudId, $usuarioId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Obtener el último mensaje
    $stmtLast = $db->prepare("
        SELECT m.*, u.nombre as remitente_nombre
        FROM mensajes_chat m
        LEFT JOIN usuarios u ON m.remitente_id = u.id
        WHERE m.solicitud_id = ? AND m.activo = true
        ORDER BY m.fecha_creacion DESC
        LIMIT 1
    ");
    $stmtLast->execute([$solicitudId]);
    $ultimoMensaje = $stmtLast->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'no_leidos' => (int) $result['no_leidos'],
        'ultimo_mensaje' => $ultimoMensaje ? [
            'id' => (int) $ultimoMensaje['id'],
            'mensaje' => $ultimoMensaje['mensaje'],
            'tipo_remitente' => $ultimoMensaje['tipo_remitente'],
            'remitente_nombre' => $ultimoMensaje['remitente_nombre'],
            'fecha_creacion' => $ultimoMensaje['fecha_creacion']
        ] : null
    ]);
    
} catch (PDOException $e) {
    error_log("get_unread_count.php PDO Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error de base de datos'
    ]);
} catch (Exception $e) {
    error_log("get_unread_count.php Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
