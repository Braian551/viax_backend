<?php
/**
 * register_push_token.php
 * Registra o reactiva el token push de un dispositivo para un usuario.
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

    // Upsert compatible (evita depender de ON CONFLICT cuando hay drift de esquema)
    $updateStmt = $conn->prepare('
        UPDATE tokens_push_usuario
        SET plataforma = :plataforma,
            device_id = :device_id,
            device_name = :device_name,
            activo = TRUE,
            updated_at = NOW()
        WHERE usuario_id = :usuario_id AND token = :token
        RETURNING id
    ');

    $params = [
        ':usuario_id' => $usuarioId,
        ':token' => $token,
        ':plataforma' => $plataforma,
        ':device_id' => $deviceId,
        ':device_name' => $deviceName,
    ];

    $updateStmt->execute($params);
    $updatedRow = $updateStmt->fetch(PDO::FETCH_ASSOC);

    if ($updatedRow && isset($updatedRow['id'])) {
        $id = (int)$updatedRow['id'];
    } else {
        $insertStmt = $conn->prepare('
            INSERT INTO tokens_push_usuario (usuario_id, token, plataforma, device_id, device_name, activo)
            VALUES (:usuario_id, :token, :plataforma, :device_id, :device_name, TRUE)
            RETURNING id
        ');
        $insertStmt->execute($params);
        $id = (int)($insertStmt->fetch(PDO::FETCH_ASSOC)['id'] ?? 0);
    }

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
        'details' => $e->getMessage(),
    ]);
}
