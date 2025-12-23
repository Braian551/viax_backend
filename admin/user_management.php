<?php
/**
 * User Management API
 * CRUD de usuarios para administradores
 */

require_once '../config/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    // Obtener datos según el método
    if ($method === 'GET') {
        $input = $_GET;
    } else {
        $input = getJsonInput();
    }

    // Log para debug
    error_log("user_management.php - Método: $method");
    error_log("user_management.php - Input: " . json_encode($input));

    // Verificar autenticación de administrador
    if (empty($input['admin_id'])) {
        error_log("user_management.php - Error: admin_id vacío");
        sendJsonResponse(false, 'ID de administrador requerido');
        exit();
    }

    $checkAdmin = "SELECT tipo_usuario FROM usuarios WHERE id = ? AND tipo_usuario = 'administrador'";
    $stmtCheck = $db->prepare($checkAdmin);
    $stmtCheck->execute([$input['admin_id']]);
    
    $adminUser = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$adminUser) {
        error_log("user_management.php - Error: Usuario no es administrador");
        sendJsonResponse(false, 'Acceso denegado. Usuario no es administrador.');
        exit();
    }

    error_log("user_management.php - Admin verificado, ejecutando método: $method");

    switch ($method) {
        case 'GET':
            handleGetUsers($db, $input);
            break;
        
        case 'POST':
            handleCreateUser($db, $input);
            break;
        
        case 'PUT':
            handleUpdateUser($db, $input);
            break;
        
        case 'DELETE':
            handleDeleteUser($db, $input);
            break;
        
        default:
            sendJsonResponse(false, 'Método no permitido');
            exit();
    }

} catch (Exception $e) {
    error_log("Error en user_management: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    sendJsonResponse(false, 'Error del servidor: ' . $e->getMessage());
    exit();
}

function handleGetUsers($db, $input) {
    try {
        error_log("handleGetUsers - Iniciando...");
        
        $page = isset($input['page']) ? (int)$input['page'] : 1;
        $perPage = isset($input['per_page']) ? (int)$input['per_page'] : 20;
        $offset = ($page - 1) * $perPage;
        
        $search = isset($input['search']) ? '%' . $input['search'] . '%' : null;
        $tipoUsuario = isset($input['tipo_usuario']) ? $input['tipo_usuario'] : null;
        $esActivo = isset($input['es_activo']) ? (int)$input['es_activo'] : null;

        error_log("handleGetUsers - Filtros: search=$search, tipo=$tipoUsuario, activo=$esActivo");

        // Construir query con filtros
        $whereConditions = [];
        $params = [];

        if ($search) {
            $whereConditions[] = "(nombre LIKE ? OR apellido LIKE ? OR email LIKE ? OR telefono LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        if ($tipoUsuario) {
            $whereConditions[] = "tipo_usuario = ?";
            $params[] = $tipoUsuario;
        }

        if ($esActivo !== null) {
            $whereConditions[] = "es_activo = ?";
            $params[] = $esActivo;
        }

        $whereClause = empty($whereConditions) ? '' : 'WHERE ' . implode(' AND ', $whereConditions);

        // Contar total
        $countQuery = "SELECT COUNT(*) as total FROM usuarios $whereClause";
        error_log("handleGetUsers - Query count: $countQuery");
        error_log("handleGetUsers - Params count: " . json_encode($params));
        
        $stmtCount = $db->prepare($countQuery);
        $stmtCount->execute($params);
        $total = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
        
        error_log("handleGetUsers - Total usuarios: $total");

        // Obtener usuarios
        $query = "SELECT 
            id, uuid, nombre, apellido, email, telefono, 
            tipo_usuario, foto_perfil, es_verificado, es_activo, 
            fecha_registro, fecha_actualizacion
        FROM usuarios 
        $whereClause
        ORDER BY fecha_registro DESC
        LIMIT $perPage OFFSET $offset";
        
        error_log("handleGetUsers - Query: $query");
        error_log("handleGetUsers - Params: " . json_encode($params));
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("handleGetUsers - Usuarios obtenidos: " . count($usuarios));

        sendJsonResponse(true, 'Usuarios obtenidos exitosamente', [
            'usuarios' => $usuarios,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage)
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("handleGetUsers - Error: " . $e->getMessage());
        error_log("handleGetUsers - Stack: " . $e->getTraceAsString());
        sendJsonResponse(false, 'Error al obtener usuarios: ' . $e->getMessage());
    }
}

function handleUpdateUser($db, $input) {
    try {
        error_log("handleUpdateUser - Iniciando con input: " . json_encode($input));
        
        if (empty($input['user_id'])) {
            error_log("handleUpdateUser - Error: user_id vacío");
            sendJsonResponse(false, 'ID de usuario requerido');
            return;
        }

        $updates = [];
        $params = [];

        // Campos actualizables
        $allowedFields = ['nombre', 'apellido', 'telefono', 'tipo_usuario', 'es_activo', 'es_verificado'];
        
        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $updates[] = "$field = ?";
                $params[] = $input[$field];
                error_log("handleUpdateUser - Campo a actualizar: $field = " . $input[$field]);
            }
        }

        if (empty($updates)) {
            error_log("handleUpdateUser - Error: No hay campos para actualizar");
            sendJsonResponse(false, 'No hay campos para actualizar');
            return;
        }

        $params[] = $input['user_id'];
        
        $query = "UPDATE usuarios SET " . implode(', ', $updates) . ", fecha_actualizacion = CURRENT_TIMESTAMP WHERE id = ?";
        error_log("handleUpdateUser - Query: $query");
        error_log("handleUpdateUser - Params: " . json_encode($params));
        
        $stmt = $db->prepare($query);
        $result = $stmt->execute($params);
        
        error_log("handleUpdateUser - Resultado de la actualización: " . ($result ? 'éxito' : 'fallo'));

        // Registrar en auditoría
        $logQuery = "INSERT INTO logs_auditoria (usuario_id, accion, entidad, entidad_id, descripcion) 
                     VALUES (?, 'actualizar_usuario', 'usuarios', ?, ?)";
        $logStmt = $db->prepare($logQuery);
        $logStmt->execute([
            $input['admin_id'],
            $input['user_id'],
            'Administrador actualizó datos del usuario'
        ]);

        error_log("handleUpdateUser - Usuario actualizado exitosamente");
        sendJsonResponse(true, 'Usuario actualizado exitosamente');
    } catch (Exception $e) {
        error_log("handleUpdateUser - Error: " . $e->getMessage());
        error_log("handleUpdateUser - Stack: " . $e->getTraceAsString());
        sendJsonResponse(false, 'Error al actualizar usuario: ' . $e->getMessage());
    }
}

function handleDeleteUser($db, $input) {
    if (empty($input['user_id'])) {
        sendJsonResponse(false, 'ID de usuario requerido');
    }

    // No permitir eliminar administradores
    $checkQuery = "SELECT tipo_usuario FROM usuarios WHERE id = ?";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([$input['user_id']]);
    $user = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($user['tipo_usuario'] === 'administrador') {
        sendJsonResponse(false, 'No se puede eliminar un administrador');
    }

    // Desactivar en lugar de eliminar (soft delete)
    $query = "UPDATE usuarios SET es_activo = 0, fecha_actualizacion = CURRENT_TIMESTAMP WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$input['user_id']]);

    // Registrar en auditoría
    $logQuery = "INSERT INTO logs_auditoria (usuario_id, accion, entidad, entidad_id, descripcion) 
                 VALUES (?, 'desactivar_usuario', 'usuarios', ?, ?)";
    $logStmt = $db->prepare($logQuery);
    $logStmt->execute([
        $input['admin_id'],
        $input['user_id'],
        'Administrador desactivó el usuario'
    ]);

    sendJsonResponse(true, 'Usuario desactivado exitosamente');
}

function handleCreateUser($db, $input) {
    // Validaciones
    $required = ['email', 'password', 'nombre', 'apellido', 'telefono', 'tipo_usuario'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            sendJsonResponse(false, "Campo $field es requerido");
        }
    }

    // Verificar email único
    $checkEmail = "SELECT id FROM usuarios WHERE email = ?";
    $stmtCheck = $db->prepare($checkEmail);
    $stmtCheck->execute([$input['email']]);
    
    if ($stmtCheck->fetch()) {
        sendJsonResponse(false, 'El email ya está registrado');
    }

    $uuid = 'user_' . uniqid('', true);
    $hashedPassword = password_hash($input['password'], PASSWORD_BCRYPT);

    $query = "INSERT INTO usuarios (uuid, nombre, apellido, email, telefono, hash_contrasena, tipo_usuario, es_activo) 
              VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        $uuid,
        $input['nombre'],
        $input['apellido'],
        $input['email'],
        $input['telefono'],
        $hashedPassword,
        $input['tipo_usuario']
    ]);

    $userId = $db->lastInsertId();

    // Registrar en auditoría
    $logQuery = "INSERT INTO logs_auditoria (usuario_id, accion, entidad, entidad_id, descripcion) 
                 VALUES (?, 'crear_usuario', 'usuarios', ?, ?)";
    $logStmt = $db->prepare($logQuery);
    $logStmt->execute([
        $input['admin_id'],
        $userId,
        'Administrador creó nuevo usuario: ' . $input['email']
    ]);

    sendJsonResponse(true, 'Usuario creado exitosamente', ['user_id' => $userId]);
}
