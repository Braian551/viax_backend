<?php
/**
 * create_ticket.php
 * Crea un nuevo ticket de soporte
 * 
 * POST params:
 * - usuario_id: (required) ID del usuario
 * - categoria_id: (required) ID de la categoría
 * - asunto: (required) Asunto del ticket
 * - descripcion: (optional) Descripción detallada
 * - viaje_id: (optional) ID del viaje relacionado
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
    $data = json_decode(file_get_contents("php://input"), true);
    
    $usuario_id = isset($data['usuario_id']) ? intval($data['usuario_id']) : 0;
    $categoria_id = isset($data['categoria_id']) ? intval($data['categoria_id']) : 0;
    $asunto = isset($data['asunto']) ? trim($data['asunto']) : '';
    $descripcion = isset($data['descripcion']) ? trim($data['descripcion']) : null;
    $viaje_id = isset($data['viaje_id']) ? intval($data['viaje_id']) : null;
    
    // Validaciones
    if ($usuario_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'usuario_id es requerido']);
        exit();
    }
    
    if ($categoria_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'categoria_id es requerido']);
        exit();
    }
    
    if (empty($asunto)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'asunto es requerido']);
        exit();
    }
    
    // Generar número de ticket temporal (se actualizará por trigger)
    $tempNumero = 'TKT-' . date('Ymd') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
    
    // Insertar ticket
    $query = "
        INSERT INTO tickets_soporte (numero_ticket, usuario_id, categoria_id, asunto, descripcion, viaje_id)
        VALUES (:numero_ticket, :usuario_id, :categoria_id, :asunto, :descripcion, :viaje_id)
        RETURNING id, numero_ticket
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':numero_ticket', $tempNumero);
    $stmt->bindValue(':usuario_id', $usuario_id, PDO::PARAM_INT);
    $stmt->bindValue(':categoria_id', $categoria_id, PDO::PARAM_INT);
    $stmt->bindValue(':asunto', $asunto);
    $stmt->bindValue(':descripcion', $descripcion);
    $stmt->bindValue(':viaje_id', $viaje_id, $viaje_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $ticket_id = $result['id'];
    $numero_ticket = $result['numero_ticket'];
    
    // Si hay descripción, crear el primer mensaje
    if (!empty($descripcion)) {
        $msgQuery = "
            INSERT INTO mensajes_ticket (ticket_id, remitente_id, es_agente, mensaje)
            VALUES (:ticket_id, :remitente_id, FALSE, :mensaje)
        ";
        $msgStmt = $conn->prepare($msgQuery);
        $msgStmt->bindValue(':ticket_id', $ticket_id, PDO::PARAM_INT);
        $msgStmt->bindValue(':remitente_id', $usuario_id, PDO::PARAM_INT);
        $msgStmt->bindValue(':mensaje', $descripcion);
        $msgStmt->execute();
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Ticket creado exitosamente',
        'ticket' => [
            'id' => $ticket_id,
            'numero_ticket' => $numero_ticket,
            'asunto' => $asunto,
            'estado' => 'abierto'
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al crear ticket: ' . $e->getMessage()
    ]);
}
