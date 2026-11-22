<?php
/**
 * unregister_push_token.php
 * Desactiva un token push para un usuario.
 */

$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit();
}

try {
    require_once __DIR__ . '/../middleware/SessionAccess.php';

    $database = new Database();
    $conn = $database->getConnection();

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput ?: '{}', true);
    if (!is_array($input)) {
        $input = [];
    }

    $user = SessionAccess::requireUser($conn);
    $usuarioId = (int)$user['id'];
    SessionAccess::requireOwner($input, $usuarioId);
    $token = is_string($input['token'] ?? null) ? trim($input['token']) : '';

    if ($usuarioId <= 0 || $token === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'usuario_id y token son requeridos']);
        exit();
    }

    $stmt = $conn->prepare('
        UPDATE tokens_push_usuario
        SET activo = FALSE, updated_at = NOW()
        WHERE usuario_id = :usuario_id AND token = :token
    ');

    $stmt->execute([
        ':usuario_id' => $usuarioId,
        ':token' => $token,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Token push desactivado',
        'affected' => $stmt->rowCount(),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error desactivando token push',
    ]);
}
