<?php
/**
 * API: Obtener mensajes de chat
 * Endpoint: GET /chat/get_messages.php
 * 
 * Obtiene todos los mensajes de una solicitud de viaje.
 * Parámetros:
 *   - solicitud_id (required): ID de la solicitud
 *   - usuario_id (required): ID del usuario que consulta (para marcar como leídos)
 *   - desde_id (optional): Obtener solo mensajes después de este ID
 *   - limite (optional): Número máximo de mensajes (default: 50)
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
    $desdeId = $_GET['desde_id'] ?? null;
    $limite = min((int) ($_GET['limite'] ?? 50), 100); // Max 100
    
    if (!$solicitudId) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'solicitud_id es requerido'
        ]);
        exit();
    }
    
    if (!$usuarioId) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'usuario_id es requerido'
        ]);
        exit();
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Construir query base
    $sql = "
        SELECT 
            m.id,
            m.solicitud_id,
            m.remitente_id,
            m.destinatario_id,
            m.tipo_remitente,
            m.mensaje,
            m.tipo_mensaje,
            m.leido,
            m.leido_en,
            m.fecha_creacion,
            u.nombre as remitente_nombre,
            u.foto_perfil as remitente_foto
        FROM mensajes_chat m
        LEFT JOIN usuarios u ON m.remitente_id = u.id
        WHERE m.solicitud_id = ? AND m.activo = true
    ";
    
    $params = [$solicitudId];
    
    // Filtrar por ID si se especifica
    if ($desdeId) {
        $sql .= " AND m.id > ?";
        $params[] = $desdeId;
    }
    
    $sql .= " ORDER BY m.fecha_creacion ASC LIMIT ?";
    $params[] = $limite;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $mensajes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Marcar mensajes como leídos (los que el usuario recibió)
    if (count($mensajes) > 0) {
        $stmtUpdate = $db->prepare("
            UPDATE mensajes_chat 
            SET leido = true, leido_en = CURRENT_TIMESTAMP
            WHERE solicitud_id = ? 
            AND destinatario_id = ? 
            AND leido = false
        ");
        $stmtUpdate->execute([$solicitudId, $usuarioId]);
    }
    
    // Formatear respuesta
    $mensajesFormateados = array_map(function($m) {
        return [
            'id' => (int) $m['id'],
            'solicitud_id' => (int) $m['solicitud_id'],
            'remitente_id' => (int) $m['remitente_id'],
            'destinatario_id' => (int) $m['destinatario_id'],
            'tipo_remitente' => $m['tipo_remitente'],
            'mensaje' => $m['mensaje'],
            'tipo_mensaje' => $m['tipo_mensaje'],
            'leido' => (bool) $m['leido'],
            'leido_en' => $m['leido_en'],
            'fecha_creacion' => $m['fecha_creacion'],
            'remitente' => [
                'nombre' => $m['remitente_nombre'],
                'foto' => $m['remitente_foto']
            ]
        ];
    }, $mensajes);
    
    echo json_encode([
        'success' => true,
        'mensajes' => $mensajesFormateados,
        'total' => count($mensajesFormateados)
    ]);
    
} catch (PDOException $e) {
    error_log("get_messages.php PDO Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error de base de datos'
    ]);
} catch (Exception $e) {
    error_log("get_messages.php Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
