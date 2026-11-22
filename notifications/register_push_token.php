<?php
/**
 * register_push_token.php
 * Registra o reactiva el token push de un dispositivo para un usuario.
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
    $plataforma = isset($input['plataforma']) ? strtolower(trim($input['plataforma'])) : 'android';
    $deviceId = isset($input['device_id']) ? trim((string) $input['device_id']) : null;
    $deviceName = isset($input['device_name']) ? trim((string) $input['device_name']) : null;

    if ($usuarioId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'usuario_id es requerido']);
        exit();
    }

    if ($token === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'token es requerido']);
        exit();
    }

    $plataformasValidas = ['android', 'ios', 'web'];
    if (!in_array($plataforma, $plataformasValidas, true)) {
        $plataforma = 'android';
    }

    $stmt = $conn->prepare(
        'INSERT INTO tokens_push_usuario
         (usuario_id, token, plataforma, device_id, device_name, activo)
         VALUES (?, ?, ?, ?, ?, TRUE)
         ON CONFLICT (usuario_id, token) DO UPDATE
         SET plataforma = EXCLUDED.plataforma, device_id = EXCLUDED.device_id,
             device_name = EXCLUDED.device_name, activo = TRUE, updated_at = NOW()
         RETURNING id'
    );
    $stmt->execute([$usuarioId, $token, $plataforma, $deviceId, $deviceName]);
    $id = (int)$stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'message' => 'Token push registrado',
        'token_id' => $id,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error registrando token push',
    ]);
}
