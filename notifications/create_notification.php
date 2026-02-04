<?php
/**
 * create_notification.php
 * Crea una nueva notificación para un usuario
 * 
 * POST Body (JSON):
 * - usuario_id: (requerido) ID del usuario destinatario
 * - tipo: (requerido) Código del tipo de notificación
 * - titulo: (requerido) Título de la notificación
 * - mensaje: (requerido) Mensaje de la notificación
 * - referencia_tipo: Tipo de entidad relacionada (opcional)
 * - referencia_id: ID de la entidad relacionada (opcional)
 * - data: Datos adicionales en JSON (opcional)
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
    $tipo = isset($input['tipo']) ? trim($input['tipo']) : '';
    $titulo = isset($input['titulo']) ? trim($input['titulo']) : '';
    $mensaje = isset($input['mensaje']) ? trim($input['mensaje']) : '';
    $referencia_tipo = isset($input['referencia_tipo']) ? trim($input['referencia_tipo']) : null;
    $referencia_id = isset($input['referencia_id']) ? intval($input['referencia_id']) : null;
    $data = isset($input['data']) ? $input['data'] : [];
    
    // Validaciones
    if ($usuario_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'usuario_id es requerido']);
        exit;
    }
    
    if (empty($tipo)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'tipo es requerido']);
        exit;
    }
    
    if (empty($titulo)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'titulo es requerido']);
        exit;
    }
    
    if (empty($mensaje)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'mensaje es requerido']);
        exit;
    }
    
    // Usar la función de BD para crear la notificación
    $query = "SELECT crear_notificacion(:usuario_id, :tipo, :titulo, :mensaje, :ref_tipo, :ref_id, :data) as id";
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':usuario_id', $usuario_id, PDO::PARAM_INT);
    $stmt->bindValue(':tipo', $tipo);
    $stmt->bindValue(':titulo', $titulo);
    $stmt->bindValue(':mensaje', $mensaje);
    $stmt->bindValue(':ref_tipo', $referencia_tipo);
    $stmt->bindValue(':ref_id', $referencia_id, $referencia_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':data', json_encode($data));
    $stmt->execute();
    
    $notification_id = $stmt->fetch(PDO::FETCH_ASSOC)['id'];
    
    // Obtener la notificación creada
    $getQuery = "
        SELECT 
            n.id,
            n.titulo,
            n.mensaje,
            n.leida,
            n.referencia_tipo,
            n.referencia_id,
            n.data,
            n.created_at,
            t.codigo as tipo,
            t.nombre as tipo_nombre,
            t.icono as tipo_icono,
            t.color as tipo_color
        FROM notificaciones_usuario n
        INNER JOIN tipos_notificacion t ON n.tipo_id = t.id
        WHERE n.id = :id
    ";
    $getStmt = $conn->prepare($getQuery);
    $getStmt->bindValue(':id', $notification_id, PDO::PARAM_INT);
    $getStmt->execute();
    $notification = $getStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($notification) {
        $notification['leida'] = false;
        $notification['data'] = json_decode($notification['data'], true) ?? [];
    }
    
    // Obtener nuevo conteo de no leídas
    $countQuery = "SELECT contar_notificaciones_no_leidas(:usuario_id) as count";
    $countStmt = $conn->prepare($countQuery);
    $countStmt->bindValue(':usuario_id', $usuario_id, PDO::PARAM_INT);
    $countStmt->execute();
    $unreadCount = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    echo json_encode([
        'success' => true,
        'message' => 'Notificación creada exitosamente',
        'notification' => $notification,
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
