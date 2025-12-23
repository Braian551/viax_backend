<?php
/**
 * App Configuration API
 * Gestiona configuraciones de la aplicación
 */

require_once '../config/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $input = $_GET;
    } else {
        $input = getJsonInput();
    }

    // Para GET público (app_config publica), no requerir admin_id
    if ($method !== 'GET' || !isset($input['public'])) {
        if (empty($input['admin_id'])) {
            sendJsonResponse(false, 'ID de administrador requerido');
        }

        $checkAdmin = "SELECT tipo_usuario FROM usuarios WHERE id = ? AND tipo_usuario = 'administrador'";
        $stmtCheck = $db->prepare($checkAdmin);
        $stmtCheck->execute([$input['admin_id']]);
        
        if (!$stmtCheck->fetch()) {
            sendJsonResponse(false, 'Acceso denegado');
        }
    }

    switch ($method) {
        case 'GET':
            handleGetConfig($db, $input);
            break;
        
        case 'PUT':
            handleUpdateConfig($db, $input);
            break;
        
        default:
            sendJsonResponse(false, 'Método no permitido');
    }

} catch (Exception $e) {
    error_log("Error en app_config: " . $e->getMessage());
    http_response_code(500);
    sendJsonResponse(false, 'Error: ' . $e->getMessage());
}

function handleGetConfig($db, $input) {
    // Si es consulta pública, solo devolver configs públicas
    if (isset($input['public']) && $input['public'] == 1) {
        $query = "SELECT clave, valor, tipo FROM configuraciones_app WHERE es_publica = 1";
        $stmt = $db->query($query);
    } else {
        // Administrador puede ver todas
        $query = "SELECT * FROM configuraciones_app ORDER BY categoria, clave";
        $stmt = $db->query($query);
    }
    
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convertir a formato más amigable (clave => valor)
    $configMap = [];
    foreach ($configs as $config) {
        $valor = $config['valor'];
        
        // Convertir según tipo
        if ($config['tipo'] === 'number') {
            $valor = is_numeric($valor) ? (float)$valor : $valor;
        } elseif ($config['tipo'] === 'boolean') {
            $valor = filter_var($valor, FILTER_VALIDATE_BOOLEAN);
        } elseif ($config['tipo'] === 'json') {
            $valor = json_decode($valor, true);
        }
        
        $configMap[$config['clave']] = $valor;
    }

    sendJsonResponse(true, 'Configuración obtenida', [
        'config' => $configMap,
        'config_detallada' => $configs
    ]);
}

function handleUpdateConfig($db, $input) {
    if (empty($input['clave']) || !isset($input['valor'])) {
        sendJsonResponse(false, 'Clave y valor son requeridos');
    }

    // Verificar que la configuración existe
    $checkQuery = "SELECT id, tipo FROM configuraciones_app WHERE clave = ?";
    $stmtCheck = $db->prepare($checkQuery);
    $stmtCheck->execute([$input['clave']]);
    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Actualizar
        $query = "UPDATE configuraciones_app SET valor = ?, fecha_actualizacion = CURRENT_TIMESTAMP WHERE clave = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$input['valor'], $input['clave']]);
        $message = 'Configuración actualizada';
    } else {
        // Crear nueva
        $tipo = isset($input['tipo']) ? $input['tipo'] : 'string';
        $categoria = isset($input['categoria']) ? $input['categoria'] : 'sistema';
        $descripcion = isset($input['descripcion']) ? $input['descripcion'] : null;
        $esPublica = isset($input['es_publica']) ? (int)$input['es_publica'] : 0;

        $query = "INSERT INTO configuraciones_app (clave, valor, tipo, categoria, descripcion, es_publica) 
                  VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($query);
        $stmt->execute([$input['clave'], $input['valor'], $tipo, $categoria, $descripcion, $esPublica]);
        $message = 'Configuración creada';
    }

    // Registrar en auditoría
    $logQuery = "INSERT INTO logs_auditoria (usuario_id, accion, entidad, descripcion) 
                 VALUES (?, 'actualizar_config', 'configuraciones_app', ?)";
    $logStmt = $db->prepare($logQuery);
    $logStmt->execute([
        $input['admin_id'],
        "Configuración '{$input['clave']}' actualizada a '{$input['valor']}'"
    ]);

    sendJsonResponse(true, $message);
}
