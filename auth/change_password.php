<?php
/**
 * change_password.php
 * 
 * Endpoint for changing user password.
 * Works with any user type (empresa, admin, conductor, cliente).
 * 
 * Handles the Google OAuth paradox: users without passwords can SET a new one,
 * users with passwords must VERIFY their current password first.
 * 
 * Actions:
 *   - check_status: Returns if user has a password and their auth provider
 *   - change_password: Changes password (requires current if has one)
 *   - set_password: Sets password for OAuth users without one
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../config/database.php';
require_once 'services/AuthService.php';
require_once '../utils/Mailer.php';

function generateVerificationCode(): string {
    return strval(random_int(1000, 9999));
}

function getUserById(PDO $db, int $userId): array {
    $stmt = $db->prepare("SELECT id, email, nombre FROM usuarios WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception("Usuario no encontrado");
    }

    return $user;
}

function validatePasswordChangeCode(PDO $db, string $email, string $code): int {
    $stmt = $db->prepare("\n        SELECT id\n        FROM verification_codes\n        WHERE email = ?\n          AND code = ?\n          AND used = 0\n          AND expires_at > NOW()\n        ORDER BY created_at DESC\n        LIMIT 1\n    ");
    $stmt->execute([$email, $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception("Código inválido o expirado");
    }

    return intval($row['id']);
}

try {
    // Initialize
    $database = new Database();
    $db = $database->getConnection();
    $authService = new AuthService($db);
    
    // Parse input
    $input = json_decode(file_get_contents("php://input"), true);
    if (empty($input)) {
        $input = $_REQUEST;
    }
    
    // Validate user_id
    $userId = $input['user_id'] ?? null;
    if (empty($userId)) {
        throw new Exception("ID de usuario requerido");
    }
    
    // Determine action
    $action = $input['action'] ?? 'check_status';
    
    switch ($action) {
        case 'check_status':
            // Check if user has password and their auth provider
            $status = $authService->checkPasswordStatus($userId);
            echo json_encode([
                'success' => true,
                'message' => 'Estado verificado',
                'data' => $status
            ]);
            break;
            
        case 'change_password':
            // User wants to change their password
            $currentPassword = $input['current_password'] ?? null;
            $newPassword = $input['new_password'] ?? null;
            
            if (empty($newPassword)) {
                throw new Exception("Nueva contraseña requerida");
            }
            
            $authService->changePassword($userId, $currentPassword, $newPassword);
            
            echo json_encode([
                'success' => true,
                'message' => 'Contraseña actualizada exitosamente'
            ]);
            break;

        case 'request_change_code':
            $user = getUserById($db, intval($userId));
            $code = generateVerificationCode();

            $nextIdStmt = $db->query("SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM verification_codes");
            $nextIdRow = $nextIdStmt->fetch(PDO::FETCH_ASSOC);
            $nextId = intval($nextIdRow['next_id'] ?? 1);

            $insertCodeStmt = $db->prepare("\n                INSERT INTO verification_codes (id, email, code, created_at, expires_at, used)\n                VALUES (?, ?, ?, NOW(), NOW() + INTERVAL '10 minutes', 0)\n            ");
            $insertCodeStmt->execute([$nextId, $user['email'], $code]);

            $sent = Mailer::sendPasswordRecoveryCode(
                $user['email'],
                $user['nombre'] ?? 'Usuario',
                $code
            );

            if (!$sent) {
                throw new Exception("No se pudo enviar el código de verificación");
            }

            echo json_encode([
                'success' => true,
                'message' => 'Código de verificación enviado al correo'
            ]);
            break;

        case 'change_password_with_code':
            $currentPassword = $input['current_password'] ?? null;
            $newPassword = $input['new_password'] ?? null;
            $verificationCode = $input['verification_code'] ?? null;
            $isSettingNew = isset($input['is_setting_new']) && (
                $input['is_setting_new'] === true ||
                $input['is_setting_new'] === 1 ||
                $input['is_setting_new'] === '1' ||
                $input['is_setting_new'] === 'true'
            );

            if (empty($newPassword)) {
                throw new Exception("Nueva contraseña requerida");
            }

            if (empty($verificationCode)) {
                throw new Exception("Código de verificación requerido");
            }

            $user = getUserById($db, intval($userId));
            $verificationId = validatePasswordChangeCode($db, $user['email'], strval($verificationCode));

            $authService->setPassword($userId, $newPassword);

            $consumeCodeStmt = $db->prepare("UPDATE verification_codes SET used = 1 WHERE id = ?");
            $consumeCodeStmt->execute([$verificationId]);

            echo json_encode([
                'success' => true,
                'message' => 'Contraseña actualizada exitosamente'
            ]);
            break;

        case 'verify_change_code':
            $verificationCode = $input['verification_code'] ?? null;

            if (empty($verificationCode)) {
                throw new Exception("Código de verificación requerido");
            }

            $user = getUserById($db, intval($userId));
            validatePasswordChangeCode($db, $user['email'], strval($verificationCode));

            echo json_encode([
                'success' => true,
                'message' => 'Código validado'
            ]);
            break;
            
        case 'set_password':
            // For OAuth users setting password for first time
            $newPassword = $input['new_password'] ?? null;
            
            if (empty($newPassword)) {
                throw new Exception("Nueva contraseña requerida");
            }
            
            $authService->setPassword($userId, $newPassword);
            
            echo json_encode([
                'success' => true,
                'message' => 'Contraseña establecida exitosamente'
            ]);
            break;
            
        default:
            throw new Exception("Acción no válida: $action");
    }
    
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
