<?php
/**
 * Endpoint: Toggle Conductor Favorito
 * 
 * POST /user/toggle_favorite_driver.php
 * Body: { "usuario_id": int, "conductor_id": int }
 * 
 * Marca o desmarca un conductor como favorito para el usuario
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
require_once __DIR__ . '/../confianza/ConfianzaService.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validar datos requeridos
    if (!isset($data['usuario_id']) || !isset($data['conductor_id'])) {
        throw new Exception('Datos requeridos: usuario_id, conductor_id');
    }
    
    $usuarioId = (int)$data['usuario_id'];
    $conductorId = (int)$data['conductor_id'];
    
    // Verificar que el usuario existe y es cliente
    $database = new Database();
    $db = $database->getConnection();
    
    $stmt = $db->prepare("SELECT id FROM usuarios WHERE id = ? AND tipo_usuario = 'cliente'");
    $stmt->execute([$usuarioId]);
    if (!$stmt->fetch()) {
        throw new Exception('Usuario no encontrado');
    }
    
    // Verificar que el conductor existe y es conductor aprobado
    $stmt = $db->prepare("
        SELECT u.id 
        FROM usuarios u 
        INNER JOIN detalles_conductor dc ON u.id = dc.usuario_id
        WHERE u.id = ? AND u.tipo_usuario = 'conductor' AND dc.estado_verificacion = 'aprobado'
    ");
    $stmt->execute([$conductorId]);
    if (!$stmt->fetch()) {
        throw new Exception('Conductor no encontrado o no verificado');
    }
    
    // Toggle favorito
    $confianzaService = new ConfianzaService();
    $resultado = $confianzaService->toggleFavorito($usuarioId, $conductorId);
    
    echo json_encode([
        'success' => true,
        'es_favorito' => $resultado['es_favorito'],
        'message' => $resultado['es_favorito'] 
            ? 'Conductor agregado a favoritos' 
            : 'Conductor removido de favoritos'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
