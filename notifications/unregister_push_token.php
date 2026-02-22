<?php
/**
 * unregister_push_token.php
 * Desactiva un token push para un usuario.
 */

header('Access-Control-Allow-Origin: *');
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
    require_once __DIR__ . '/../config/database.php';

    $database = new Database();
    $conn = $database->getConnection();

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput ?: '{}', true);
    if (!is_array($input)) {
        $input = [];
    }

    $usuarioId = isset($input['usuario_id']) ? intval($input['usuario_id']) : 0;
    $token = isset($input['token']) ? trim($input['token']) : '';

    if ($usuarioId <= 0 || $token === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'usuario_id y token son requeridos']);
        exit();
    }

    // Asegurar existencia de tabla para evitar fallos por migraciones faltantes
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tokens_push_usuario (
            id SERIAL PRIMARY KEY,
            usuario_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            plataforma VARCHAR(20) NOT NULL DEFAULT 'android',
            device_id VARCHAR(255),
            device_name VARCHAR(255),
            activo BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(usuario_id, token)
        )
    ");

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
        'details' => $e->getMessage(),
    ]);
}
