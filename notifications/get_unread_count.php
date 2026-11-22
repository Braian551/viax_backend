<?php
/**
 * get_unread_count.php
 * Obtiene el conteo de notificaciones no leídas (para badge)
 * Endpoint optimizado para llamadas frecuentes
 * 
 * Parámetros GET:
 */

$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Cache headers para optimizar
header("Cache-Control: no-cache, must-revalidate");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../middleware/SessionAccess.php';

if (Request::method() !== 'GET') {
    header('Allow: GET');
    sendJsonResponse(false, 'Método no permitido.', [], 405);
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $user = SessionAccess::requireUser($conn);
    $usuario_id = (int)$user['id'];
    SessionAccess::requireOwner($_GET, $usuario_id);
    
    
    // Usar la función optimizada de la BD
    $query = "SELECT contar_notificaciones_no_leidas(:usuario_id) as count";
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':usuario_id', $usuario_id, PDO::PARAM_INT);
    $stmt->execute();
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    echo json_encode([
        'success' => true,
        'count' => (int) $count
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error de base de datos',
        'count' => 0
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'No se pudo gestionar la notificación',
        'count' => 0
    ]);
}
