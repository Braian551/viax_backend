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

$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
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
require_once __DIR__ . '/_support_auth.php';

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
    $prioridad = isset($data['prioridad']) ? trim((string) $data['prioridad']) : 'normal';
    
    // Validaciones
    if ($usuario_id <= 0) {
        supportJsonError('usuario_id es requerido', 400);
    }
    
    if ($categoria_id <= 0) {
        supportJsonError('categoria_id es requerido', 400);
    }
    
    if (empty($asunto)) {
        supportJsonError('asunto es requerido', 400);
    }

    $allowedPriorities = ['baja', 'normal', 'alta', 'urgente'];
    if (!in_array($prioridad, $allowedPriorities, true)) {
        supportJsonError('prioridad no válida', 400);
    }

    $actor = supportGetActor($conn, $usuario_id);
    if (!$actor) {
        supportJsonError('Usuario no encontrado', 404);
    }

    // Control anti-spam: máximo 5 tickets abiertos y evitar duplicado exacto en 5 minutos.
    $openStmt = $conn->prepare("SELECT COUNT(*) FROM tickets_soporte WHERE usuario_id = :usuario_id AND estado IN ('abierto','en_progreso','esperando_usuario')");
    $openStmt->bindValue(':usuario_id', $usuario_id, PDO::PARAM_INT);
    $openStmt->execute();
    $openCount = (int) $openStmt->fetchColumn();
    if ($openCount >= 5) {
        supportJsonError('Ya tienes demasiados tickets abiertos. Espera respuesta o cierra uno antes de crear otro.', 429);
    }

    $dupStmt = $conn->prepare("\n        SELECT id\n        FROM tickets_soporte\n        WHERE usuario_id = :usuario_id\n          AND categoria_id = :categoria_id\n          AND LOWER(asunto) = LOWER(:asunto)\n          AND created_at > NOW() - INTERVAL '5 minutes'\n        LIMIT 1\n    ");
    $dupStmt->bindValue(':usuario_id', $usuario_id, PDO::PARAM_INT);
    $dupStmt->bindValue(':categoria_id', $categoria_id, PDO::PARAM_INT);
    $dupStmt->bindValue(':asunto', $asunto);
    $dupStmt->execute();
    if ($dupStmt->fetch(PDO::FETCH_ASSOC)) {
        supportJsonError('Ya existe un ticket reciente con este asunto. Evita enviar duplicados.', 409);
    }
    
    // Generar número de ticket temporal (se actualizará por trigger)
    $tempNumero = 'TKT-' . date('Ymd') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
    
    // Insertar ticket
    $query = "
        INSERT INTO tickets_soporte (numero_ticket, usuario_id, categoria_id, asunto, descripcion, viaje_id, prioridad)
        VALUES (:numero_ticket, :usuario_id, :categoria_id, :asunto, :descripcion, :viaje_id, :prioridad)
        RETURNING id, numero_ticket, prioridad
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':numero_ticket', $tempNumero);
    $stmt->bindValue(':usuario_id', $usuario_id, PDO::PARAM_INT);
    $stmt->bindValue(':categoria_id', $categoria_id, PDO::PARAM_INT);
    $stmt->bindValue(':asunto', $asunto);
    $stmt->bindValue(':descripcion', $descripcion);
    $stmt->bindValue(':viaje_id', $viaje_id, $viaje_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':prioridad', $prioridad);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $ticket_id = $result['id'];
    $numero_ticket = $result['numero_ticket'];

    supportInsertTicketLog(
        $conn,
        (int) $ticket_id,
        $usuario_id,
        'ticket_creado',
        [
            'estado_nuevo' => 'abierto',
            'prioridad_nueva' => $prioridad,
            'metadata' => [
                'categoria_id' => $categoria_id,
                'viaje_id' => $viaje_id,
            ],
        ]
    );

    $creatorName = supportActorDisplayName($actor);
    $agentsToNotify = supportAgentIds($conn, $usuario_id);
    foreach ($agentsToNotify as $agentId) {
        supportNotifyUser(
            $agentId,
            'Nuevo ticket de soporte',
            $creatorName . ' abrio el ticket ' . $numero_ticket . ': ' . $asunto,
            (int) $ticket_id,
            [
                'module' => 'support',
                'action' => 'ticket_created',
                'ticket_id' => (int) $ticket_id,
                'numero_ticket' => $numero_ticket,
                'prioridad' => $prioridad,
                'usuario_id' => $usuario_id,
            ]
        );
    }
    
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
            'estado' => 'abierto',
            'prioridad' => $prioridad
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al crear ticket: ' . $e->getMessage()
    ]);
}
