<?php
/**
 * API: Enviar mensaje de chat
 * Endpoint: POST /chat/send_message.php
 * 
 * Permite a un conductor o cliente enviar un mensaje durante un viaje activo.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

try {
    // Obtener datos del request
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validar campos requeridos
    $requiredFields = ['solicitud_id', 'remitente_id', 'destinatario_id', 'mensaje', 'tipo_remitente'];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "Campo requerido: $field"
            ]);
            exit();
        }
    }
    
    $solicitudId = (int) $input['solicitud_id'];
    $remitenteId = (int) $input['remitente_id'];
    $destinatarioId = (int) $input['destinatario_id'];
    $mensaje = trim($input['mensaje']);
    $tipoRemitente = $input['tipo_remitente'];
    $tipoMensaje = $input['tipo_mensaje'] ?? 'texto';
    
    // Validar tipo_remitente
    if (!in_array($tipoRemitente, ['cliente', 'conductor'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'tipo_remitente debe ser "cliente" o "conductor"'
        ]);
        exit();
    }
    
    // Validar tipo_mensaje
    $tiposPermitidos = ['texto', 'imagen', 'ubicacion', 'audio', 'sistema'];
    if (!in_array($tipoMensaje, $tiposPermitidos)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'tipo_mensaje inválido'
        ]);
        exit();
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Verificar que la solicitud existe y está activa
    $stmtCheck = $db->prepare("
        SELECT id, estado FROM solicitudes_servicio 
        WHERE id = ? AND estado IN ('aceptada', 'conductor_llego', 'en_curso')
    ");
    $stmtCheck->execute([$solicitudId]);
    $solicitud = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$solicitud) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Solicitud no encontrada o no está activa'
        ]);
        exit();
    }
    
    // Insertar mensaje
    $stmt = $db->prepare("
        INSERT INTO mensajes_chat 
        (solicitud_id, remitente_id, destinatario_id, tipo_remitente, mensaje, tipo_mensaje)
        VALUES (?, ?, ?, ?, ?, ?)
        RETURNING id, fecha_creacion
    ");
    
    $stmt->execute([
        $solicitudId,
        $remitenteId,
        $destinatarioId,
        $tipoRemitente,
        $mensaje,
        $tipoMensaje
    ]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'message' => 'Mensaje enviado exitosamente',
        'data' => [
            'id' => (int) $result['id'],
            'solicitud_id' => $solicitudId,
            'remitente_id' => $remitenteId,
            'destinatario_id' => $destinatarioId,
            'tipo_remitente' => $tipoRemitente,
            'mensaje' => $mensaje,
            'tipo_mensaje' => $tipoMensaje,
            'leido' => false,
            'fecha_creacion' => $result['fecha_creacion']
        ]
    ]);
    
} catch (PDOException $e) {
    error_log("send_message.php PDO Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error de base de datos'
    ]);
} catch (Exception $e) {
    error_log("send_message.php Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
