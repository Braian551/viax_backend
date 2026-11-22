<?php
/**
 * User Management API
 * CRUD de usuarios para administradores
 */

require_once __DIR__ . '/../middleware/SessionAccess.php';

$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $db = (new Database())->getConnection();
    SessionAccess::requireAdmin($db);
    if (Request::method() !== 'GET') {
        header('Allow: GET');
        sendJsonResponse(false, 'Operación no disponible.', [], 405);
    }
    $input = $_GET;

    handleGetUsers($db, $input);
} catch (Throwable $error) {
    error_log('VIAX users listing: ' . get_class($error));
    sendJsonResponse(false, 'No se pudo cargar la lista de usuarios.', [], 500);
}

function handleGetUsers($db, $input) {
    try {


        $page = isset($input['page']) ? max(1, min(10000, (int)$input['page'])) : 1;
        $perPage = isset($input['per_page']) ? max(1, min(100, (int)$input['per_page'])) : 20;
        $offset = ($page - 1) * $perPage;

        $search = isset($input['search']) ? '%' . $input['search'] . '%' : null;
        $tipoUsuario = isset($input['tipo_usuario']) ? $input['tipo_usuario'] : null;
        $esActivo = isset($input['es_activo']) ? (int)$input['es_activo'] : null;


        // Construir query con filtros
        $whereConditions = [];
        $params = [];

        if ($search) {
            $whereConditions[] = "(u.nombre LIKE ? OR u.apellido LIKE ? OR u.email LIKE ? OR u.telefono LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        if ($tipoUsuario) {
            $whereConditions[] = "u.tipo_usuario = ?";
            $params[] = $tipoUsuario;
        }

        if ($esActivo !== null) {
            $whereConditions[] = "u.es_activo = ?";
            $params[] = $esActivo;
        }

        $whereClause = empty($whereConditions) ? '' : 'WHERE ' . implode(' AND ', $whereConditions);

        // Contar total
        $countQuery = "SELECT COUNT(*) as total FROM usuarios u $whereClause";



        $stmtCount = $db->prepare($countQuery);
        $stmtCount->execute($params);
        $total = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];


        // Obtener usuarios
        // Update query to include company info
        $query = "SELECT 
            u.id, u.uuid, u.nombre, u.apellido, u.email, u.telefono, 
            u.tipo_usuario, u.foto_perfil, u.es_verificado, u.es_activo, 
            u.fecha_registro, u.fecha_actualizacion,
            u.empresa_id, e.nombre as empresa_nombre
        FROM usuarios u
        LEFT JOIN empresas_transporte e ON u.empresa_id = e.id
        $whereClause
        ORDER BY u.fecha_registro DESC
        LIMIT $perPage OFFSET $offset";



        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($usuarios)) {

        }

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


        throw $e;
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
        $allowedFields = ['nombre', 'apellido', 'telefono', 'tipo_usuario', 'es_activo', 'es_verificado', 'empresa_id'];
        
        $allowedRoles = ['cliente', 'conductor', 'administrador', 'empresa', 'soporte_tecnico'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                if ($field === 'tipo_usuario' && !in_array($input[$field], $allowedRoles, true)) {
                    sendJsonResponse(false, 'Rol de usuario no permitido');
                    return;
                }
                $updates[] = "$field = ?";
                $params[] = $input[$field];
                error_log("handleUpdateUser - Campo a actualizar: $field = " . json_encode($input[$field]));
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

    $allowedRoles = ['cliente', 'conductor', 'administrador', 'empresa', 'soporte_tecnico'];
    if (!in_array($input['tipo_usuario'], $allowedRoles, true)) {
        sendJsonResponse(false, 'Rol de usuario no permitido');
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
