<?php
/**
 * Endpoint para actualizar el número de teléfono del usuario
 * Especialmente útil después de registro con Google/Apple
 * 
 * URL: /auth/update_phone.php
 * Método: POST
 * 
 * Body:
 * {
 *   "user_id": 123,        // o "email": "user@example.com"
 *   "phone": "+573001234567"
 * }
 */

require_once '../config/config.php';

try {
    $input = getJsonInput();
    
    // Validar campos requeridos
    if (empty($input['phone'])) {
        sendJsonResponse(false, 'El número de teléfono es requerido');
    }
    
    if (empty($input['user_id']) && empty($input['email'])) {
        sendJsonResponse(false, 'Se requiere user_id o email para identificar al usuario');
    }
    
    $phone = trim($input['phone']);
    
    // Validar formato de teléfono (mínimo 10 dígitos)
    $phoneClean = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phoneClean) < 10) {
        sendJsonResponse(false, 'El número de teléfono debe tener al menos 10 dígitos');
    }
    
    // Conectar a la base de datos
    $database = new Database();
    $db = $database->getConnection();
    
    // Verificar que el teléfono no esté en uso por otro usuario
    $checkQuery = "SELECT id FROM usuarios WHERE telefono = :phone";
    $checkParams = [':phone' => $phone];
    
    // Excluir al usuario actual de la verificación
    if (!empty($input['user_id'])) {
        $checkQuery .= " AND id != :user_id";
        $checkParams[':user_id'] = $input['user_id'];
    } else if (!empty($input['email'])) {
        $checkQuery .= " AND email != :email";
        $checkParams[':email'] = $input['email'];
    }
    
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute($checkParams);
    
    if ($checkStmt->fetch()) {
        sendJsonResponse(false, 'Este número de teléfono ya está registrado con otra cuenta');
    }
    
    // Actualizar el teléfono del usuario
    if (!empty($input['user_id'])) {
        $updateQuery = "UPDATE usuarios SET telefono = :phone, fecha_actualizacion = CURRENT_TIMESTAMP WHERE id = :user_id RETURNING id, email, telefono";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->execute([
            ':phone' => $phone,
            ':user_id' => $input['user_id']
        ]);
    } else {
        $updateQuery = "UPDATE usuarios SET telefono = :phone, fecha_actualizacion = CURRENT_TIMESTAMP WHERE email = :email RETURNING id, email, telefono";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->execute([
            ':phone' => $phone,
            ':email' => $input['email']
        ]);
    }
    
    $result = $updateStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        sendJsonResponse(false, 'Usuario no encontrado');
    }
    
    // Obtener datos completos del usuario actualizado
    $userQuery = "SELECT id, uuid, nombre, apellido, email, telefono, tipo_usuario, foto_perfil, es_verificado, empresa_id
                  FROM usuarios WHERE id = :id";
    $userStmt = $db->prepare($userQuery);
    $userStmt->execute([':id' => $result['id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    sendJsonResponse(true, 'Número de teléfono actualizado correctamente', [
        'user' => $user,
        'requires_phone' => false
    ]);
    
} catch (PDOException $e) {
    error_log("Error de base de datos en update_phone: " . $e->getMessage());
    sendJsonResponse(false, 'Error de base de datos');
} catch (Exception $e) {
    error_log("Error en update_phone: " . $e->getMessage());
    sendJsonResponse(false, 'Error: ' . $e->getMessage());
}
