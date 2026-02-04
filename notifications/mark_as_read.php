<?php
/**
 * mark_as_read.php
 * Marca una o varias notificaciones como leídas
 * 
 * POST Body (JSON):
 * - usuario_id: (requerido) ID del usuario
 * - notification_id: ID de notificación específica (opcional)
 * - notification_ids: Array de IDs (opcional)
 * - mark_all: Marcar todas como leídas (opcional)
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Obtener datos del body
    $input = json_decode(file_get_contents('php://input'), true);
    
    $usuario_id = isset($input['usuario_id']) ? intval($input['usuario_id']) : 0;
    $notification_id = isset($input['notification_id']) ? intval($input['notification_id']) : null;
    $notification_ids = isset($input['notification_ids']) ? $input['notification_ids'] : null;
    $mark_all = isset($input['mark_all']) && $input['mark_all'] === true;
    
    if ($usuario_id <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'usuario_id es requerido'
        ]);
        exit;
    }
    
    $affected = 0;
    
    if ($mark_all) {
        // Marcar todas las notificaciones como leídas
        $query = "
            UPDATE notificaciones_usuario 
            SET leida = TRUE, leida_en = CURRENT_TIMESTAMP
            WHERE usuario_id = :usuario_id 
              AND leida = FALSE 
              AND eliminada = FALSE
        ";
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':usuario_id', $usuario_id, PDO::PARAM_INT);
        $stmt->execute();
        $affected = $stmt->rowCount();
        
    } elseif ($notification_id) {
        // Marcar una notificación específica
        $query = "
            UPDATE notificaciones_usuario 
            SET leida = TRUE, leida_en = CURRENT_TIMESTAMP
            WHERE id = :id 
              AND usuario_id = :usuario_id 
              AND eliminada = FALSE
        ";
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':id', $notification_id, PDO::PARAM_INT);
        $stmt->bindValue(':usuario_id', $usuario_id, PDO::PARAM_INT);
        $stmt->execute();
        $affected = $stmt->rowCount();
        
    } elseif (is_array($notification_ids) && !empty($notification_ids)) {
        // Marcar varias notificaciones
        $ids = array_map('intval', $notification_ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        $query = "
            UPDATE notificaciones_usuario 
            SET leida = TRUE, leida_en = CURRENT_TIMESTAMP
            WHERE id IN ($placeholders) 
              AND usuario_id = ? 
              AND eliminada = FALSE
        ";
        $stmt = $conn->prepare($query);
        
        $paramIndex = 1;
        foreach ($ids as $id) {
            $stmt->bindValue($paramIndex++, $id, PDO::PARAM_INT);
        }
        $stmt->bindValue($paramIndex, $usuario_id, PDO::PARAM_INT);
        $stmt->execute();
        $affected = $stmt->rowCount();
        
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Debe especificar notification_id, notification_ids, o mark_all'
        ]);
        exit;
    }
    
    // Obtener nuevo conteo de no leídas
    $countQuery = "SELECT contar_notificaciones_no_leidas(:usuario_id) as count";
    $countStmt = $conn->prepare($countQuery);
    $countStmt->bindValue(':usuario_id', $usuario_id, PDO::PARAM_INT);
    $countStmt->execute();
    $unreadCount = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    echo json_encode([
        'success' => true,
        'message' => "Se marcaron $affected notificaciones como leídas",
        'affected' => $affected,
        'no_leidas' => (int) $unreadCount
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error de base de datos',
        'details' => $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
