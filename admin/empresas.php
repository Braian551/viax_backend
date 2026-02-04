<?php
/**
 * Empresas de Transporte - API Endpoints
 * 
 * Este archivo gestiona todas las operaciones CRUD para empresas de transporte.
 * 
 * Endpoints:
 * - GET    ?action=list         - Listar todas las empresas
 * - GET    ?action=get&id=X     - Obtener una empresa por ID
 * - POST   action=create        - Crear nueva empresa
 * - POST   action=update        - Actualizar empresa existente
 * - POST   action=delete        - Eliminar empresa (soft delete)
 * - POST   action=toggle_status - Cambiar estado de empresa
 */

error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable display_errors to return clean JSON
ini_set('log_errors', 1);

// Handle fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_COMPILE_ERROR)) {
        if (ob_get_length()) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Error fatal: ' . $error['message'],
            'debug_file' => basename($error['file']),
            'debug_line' => $error['line']
        ]);
        exit;
    }
});

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/config.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Determinar la acción
    $action = $_GET['action'] ?? $_POST['action'] ?? null;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $contentType = $_SERVER["CONTENT_TYPE"] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $input = getJsonInput();
        } else {
            $input = $_POST;
        }
        $action = $action ?? $input['action'] ?? null;
    }
    
    switch ($action) {
        case 'list':
            listEmpresas($db);
            break;
        case 'get':
            getEmpresa($db);
            break;
        case 'create':
            createEmpresa($db, $input);
            break;
        case 'update':
            updateEmpresa($db, $input);
            break;
        case 'reject':
            rejectEmpresa($db, $input);
            break;
        case 'delete':
            deleteEmpresa($db, $input);
            break;
        case 'toggle_status':
            toggleEmpresaStatus($db, $input);
            break;
        case 'approve':
            approveEmpresa($db, $input);
            break;
        case 'get_stats':
            getEmpresaStats($db);
            break;
        default:
            // Si no se especifica acción, listar empresas
            listEmpresas($db);
    }
    
} catch (Exception $e) {
    error_log("Error en empresas.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error' => $e->getMessage()
    ]);
}

/**
 * Listar todas las empresas con filtros opcionales
 */
function listEmpresas($db) {
    $estado = $_GET['estado'] ?? null;
    $municipio = $_GET['municipio'] ?? null;
    $search = $_GET['search'] ?? null;
    $page = intval($_GET['page'] ?? 1);
    $limit = intval($_GET['limit'] ?? 50);
    $offset = ($page - 1) * $limit;
    
    $whereConditions = [];
    $params = [];
    
    if ($estado) {
        $whereConditions[] = "estado = ?";
        $params[] = $estado;
    }
    
    if ($municipio) {
        $whereConditions[] = "municipio ILIKE ?";
        $params[] = "%$municipio%";
    }
    
    if ($search) {
        $whereConditions[] = "(nombre ILIKE ? OR nit ILIKE ? OR razon_social ILIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $whereClause = '';
    if (!empty($whereConditions)) {
        $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    }
    
    // Contar total
    $countQuery = "SELECT COUNT(*) as total FROM empresas_transporte $whereClause";
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Obtener empresas
    $query = "SELECT 
                e.*,
                u.nombre as creador_nombre
              FROM empresas_transporte e
              LEFT JOIN usuarios u ON e.creado_por = u.id
              $whereClause
              ORDER BY e.creado_en DESC
              LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Procesar datos de respuesta
    foreach ($empresas as &$empresa) {
        // Tipos de vehículo
        if ($empresa['tipos_vehiculo']) {
            $empresa['tipos_vehiculo'] = pgArrayToPhp($empresa['tipos_vehiculo']);
        } else {
            $empresa['tipos_vehiculo'] = [];
        }
        
        // Convertir logo_url relativo a absoluto usando r2_proxy.php
        if (!empty($empresa['logo_url']) && strpos($empresa['logo_url'], 'http') !== 0) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            // Asumiendo que r2_proxy.php está en la raíz de backend/
            $baseDir = dirname($_SERVER['PHP_SELF'], 2); // /backend
            $empresa['logo_url'] = "$protocol://$host$baseDir/r2_proxy.php?key=" . urlencode($empresa['logo_url']);
        }
    }
    
    echo json_encode([
        'success' => true,
        'empresas' => $empresas,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => intval($total),
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

/**
 * Obtener una empresa por ID
 */
function getEmpresa($db) {
    $id = intval($_GET['id'] ?? 0);
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID de empresa requerido']);
        return;
    }
    
    $query = "SELECT 
                e.*,
                u.nombre as creador_nombre,
                v.nombre as verificador_nombre
              FROM empresas_transporte e
              LEFT JOIN usuarios u ON e.creado_por = u.id
              LEFT JOIN usuarios v ON e.verificado_por = v.id
              WHERE e.id = ?";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$id]);
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$empresa) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Empresa no encontrada']);
        return;
    }
    
    // Procesar tipos_vehiculo
    if ($empresa['tipos_vehiculo']) {
        $empresa['tipos_vehiculo'] = pgArrayToPhp($empresa['tipos_vehiculo']);
    } else {
        $empresa['tipos_vehiculo'] = [];
    }
    
    // Convertir logo_url relativo a absoluto usando r2_proxy.php
    if (!empty($empresa['logo_url']) && strpos($empresa['logo_url'], 'http') !== 0) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $baseDir = dirname($_SERVER['PHP_SELF'], 2); // /backend
        $empresa['logo_url'] = "$protocol://$host$baseDir/r2_proxy.php?key=" . urlencode($empresa['logo_url']);
    }
    
    // Obtener conductores de la empresa
    $conductoresQuery = "SELECT id, nombre, telefono, email, calificacion_promedio 
                         FROM usuarios 
                         WHERE empresa_id = ? AND tipo_usuario = 'conductor'
                         ORDER BY nombre";
    $conductoresStmt = $db->prepare($conductoresQuery);
    $conductoresStmt->execute([$id]);
    $empresa['conductores'] = $conductoresStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'empresa' => $empresa
    ]);
}

/**
 * Crear nueva empresa
 */
/**
 * Crear nueva empresa usando EmpresaService para consistencia
 */
function createEmpresa($db, $input) {
    // Verificar admin
    $adminId = $input['admin_id'] ?? null;
    if ($adminId) {
        $checkAdmin = $db->prepare("SELECT id FROM usuarios WHERE id = ? AND tipo_usuario = 'administrador'");
        $checkAdmin->execute([$adminId]);
        if (!$checkAdmin->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Solo administradores pueden crear empresas']);
            return;
        }
    }
    
    try {
        require_once __DIR__ . '/../empresa/services/EmpresaService.php';
        $service = new EmpresaService($db);
        
        // Mapear campos del formulario de Admin a lo que espera el Servicio
        $serviceInput = $input;
        $serviceInput['nombre_empresa'] = $input['nombre']; // Servicio espera nombre_empresa
        
        // Generar contraseña si no se proporcionó (aunque el formulario debería obligarla)
        if (empty($serviceInput['password'])) {
            $serviceInput['password'] = bin2hex(random_bytes(8));
        }
        
        // Ejecutar registro
        $result = $service->processRegistration($serviceInput);
        
        // Enviar notificaciones (PDF, Email)
        if (isset($result['notification_context'])) {
            // Nota: Esto puede tomar tiempo. En un entorno ideal, usar colas.
            $service->sendNotifications($result['notification_context']);
        }
        
        // Log de auditoría adicional para Admin
        logAuditAction($db, $adminId, 'empresa_creada_admin', 'empresas_transporte', $result['data']['empresa_id'], [
            'nombre' => $input['nombre'],
            'nit' => $input['nit'] ?? null
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Empresa creada exitosamente con credenciales y notificaciones enviadas.',
            'empresa_id' => $result['data']['empresa_id']
        ]);
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

/**
 * Actualizar empresa existente
 */
function updateEmpresa($db, $input) {
    $empresaId = intval($input['id'] ?? 0);
    
    if (!$empresaId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID de empresa requerido']);
        return;
    }
    
    // Verificar que la empresa existe y obtener TODOS los datos actuales para comparación
    $checkEmpresa = $db->prepare("SELECT * FROM empresas_transporte WHERE id = ?");
    $checkEmpresa->execute([$empresaId]);
    $currentEmpresa = $checkEmpresa->fetch(PDO::FETCH_ASSOC);

    if (!$currentEmpresa) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Empresa no encontrada']);
        return;
    }
    
    // Verificar NIT único si se cambia
    if (!empty($input['nit'])) {
        $checkNit = $db->prepare("SELECT id FROM empresas_transporte WHERE nit = ? AND id != ?");
        $checkNit->execute([$input['nit'], $empresaId]);
        if ($checkNit->fetch()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ya existe otra empresa con este NIT']);
            return;
        }
    }
    
    // Preparar tipos_vehiculo
    $tiposVehiculo = null;
    if (isset($input['tipos_vehiculo'])) {
        $vehiculos = $input['tipos_vehiculo'];
        if (is_string($vehiculos)) {
            $decoded = json_decode($vehiculos, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $vehiculos = $decoded;
            } else {
                $vehiculos = explode(',', $vehiculos);
            }
        }
        
        if (is_array($vehiculos)) {
            $tiposVehiculo = phpArrayToPg($vehiculos);
        }
    }
    
    // Manejar subida de logo
    $uploadedLogo = handleLogoUpload();
    if ($uploadedLogo) {
        $input['logo_url'] = $uploadedLogo;
    }
    
    // Construir query dinámico solo con campos proporcionados
    $updates = [];
    $params = [];
    
    $campos = [
        'nombre', 'nit', 'razon_social', 'email', 'telefono', 'telefono_secundario',
        'direccion', 'municipio', 'departamento', 'representante_nombre',
        'representante_telefono', 'representante_email', 'logo_url', 
        'descripcion', 'estado', 'notas_admin'
    ];
    
    foreach ($campos as $campo) {
        if (isset($input[$campo])) {
            $updates[] = "$campo = ?";
            $params[] = $input[$campo];
        }
    }
    
    // Manejar tipos_vehiculo por separado
    if ($tiposVehiculo !== null) {
        $updates[] = "tipos_vehiculo = ?";
        $params[] = $tiposVehiculo;
    }
    
    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No hay campos para actualizar']);
        return;
    }
    
    // Enviar correo si el estado cambia (activo/inactivo)
    if (isset($input['estado']) && $input['estado'] !== $currentEmpresa['estado']) {
        $newState = $input['estado'];
        if ($newState === 'activo' || $newState === 'inactivo') {
            $toEmail = $currentEmpresa['representante_email'] ?: $currentEmpresa['email'];
            $toName = $currentEmpresa['representante_nombre'];
            $currentEmpresa['nombre_empresa'] = $currentEmpresa['nombre'];
            
            try {
                require_once __DIR__ . '/../utils/Mailer.php';
                Mailer::sendCompanyStatusChangeEmail($toEmail, $toName, $currentEmpresa, $newState);
            } catch (Exception $e) {
                error_log("Error enviando email de cambio de estado: " . $e->getMessage());
            }
        }
    }

    $params[] = $empresaId;
    
    $query = "UPDATE empresas_transporte SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    
    // --- Notificación de Edición (campos modificados) ---
    $fieldLabels = [
        'nombre' => 'Nombre',
        'nit' => 'NIT',
        'razon_social' => 'Razón Social',
        'email' => 'Email Empresa',
        'telefono' => 'Teléfono',
        'telefono_secundario' => 'Teléfono Secundario',
        'direccion' => 'Dirección',
        'municipio' => 'Municipio',
        'departamento' => 'Departamento',
        'representante_nombre' => 'Representante Legal',
        'representante_telefono' => 'Teléfono Representante',
        'representante_email' => 'Email Representante',
        'descripcion' => 'Descripción',
    ];
    
    $changes = [];
    foreach ($fieldLabels as $field => $label) {
        if (isset($input[$field]) && $input[$field] != $currentEmpresa[$field]) {
            $changes[] = [
                'campo' => $label,
                'anterior' => $currentEmpresa[$field] ?? '(vacío)',
                'nuevo' => $input[$field] ?? '(vacío)'
            ];
        }
    }
    
    if (!empty($changes)) {
        try {
            $toEmail = $currentEmpresa['representante_email'] ?: $currentEmpresa['email'];
            $toName = $currentEmpresa['representante_nombre'];
            $currentEmpresa['nombre_empresa'] = $currentEmpresa['nombre'];
            
            require_once __DIR__ . '/../utils/Mailer.php';
            Mailer::sendCompanyEditedEmail($toEmail, $toName, $currentEmpresa, $changes);
        } catch (Exception $e) {
            error_log("Error enviando email de edición: " . $e->getMessage());
        }
    }
    
    // Si se actualizó el municipio, sincronizar con empresas_contacto y zona_operacion
    if (isset($input['municipio']) || isset($input['departamento'])) {
        $nuevoMunicipio = $input['municipio'] ?? $currentEmpresa['municipio'];
        $nuevoDepartamento = $input['departamento'] ?? $currentEmpresa['departamento'];
        
        // Actualizar empresas_contacto
        $checkContacto = $db->prepare("SELECT id FROM empresas_contacto WHERE empresa_id = ?");
        $checkContacto->execute([$empresaId]);
        
        if ($checkContacto->fetch()) {
            $updateContacto = $db->prepare("
                UPDATE empresas_contacto 
                SET municipio = ?, departamento = ?, actualizado_en = NOW() 
                WHERE empresa_id = ?
            ");
            $updateContacto->execute([$nuevoMunicipio, $nuevoDepartamento, $empresaId]);
        } else {
            // Crear registro de contacto si no existe
            $insertContacto = $db->prepare("
                INSERT INTO empresas_contacto (empresa_id, municipio, departamento, creado_en) 
                VALUES (?, ?, ?, NOW())
            ");
            $insertContacto->execute([$empresaId, $nuevoMunicipio, $nuevoDepartamento]);
        }
        
        // Sincronizar zona de operación
        configureZonaOperacionEmpresa($db, $empresaId, $nuevoMunicipio, $nuevoDepartamento);
    }
    
    // Log de auditoría
    $adminId = $input['admin_id'] ?? null;
    logAuditAction($db, $adminId, 'empresa_actualizada', 'empresas_transporte', $empresaId, $input);
    
    echo json_encode([
        'success' => true,
        'message' => 'Empresa actualizada exitosamente'
    ]);
}

/**
 * Rechazar empresa: envía email con motivo y elimina definitivamente.
 */
function rejectEmpresa($db, $input) {
    $empresaId = intval($input['id'] ?? 0);
    $adminId = $input['admin_id'] ?? null;
    $reason = trim($input['motivo'] ?? '');

    if (!$empresaId || empty($reason)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID y motivo requeridos']);
        return;
    }

    // 1. Obtener datos
    $empresaQuery = $db->prepare("SELECT * FROM empresas_transporte WHERE id = ?");
    $empresaQuery->execute([$empresaId]);
    $empresa = $empresaQuery->fetch(PDO::FETCH_ASSOC);

    if (!$empresa) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Empresa no encontrada']);
        return;
    }

    // 2. Verificar conductores (No se debe poder eliminar/rechazar si ya tiene operación)
    if (checkConductores($db, $empresaId)) {
        return; 
    }

    // 3. Enviar correo
    // Intentar buscar email representante si el genérico no es específico
    $toEmail = $empresa['representante_email'] ?: $empresa['email'];
    $toName = $empresa['representante_nombre'];
    
    // Mapear nombre para el template de email (que espera nombre_empresa)
    $empresa['nombre_empresa'] = $empresa['nombre'];
    
    try {
        require_once __DIR__ . '/../utils/Mailer.php';
        Mailer::sendCompanyRejectedEmail($toEmail, $toName, $empresa, $reason);
    } catch (Exception $e) {
        error_log("Error enviando email de rechazo: " . $e->getMessage());
        // Continuamos con la eliminación aunque falle el email (o podríamos detenerlo)
        // Decisión: Continuar para no bloquear al admin, pero loguear.
    }

    // 4. Ejecutar eliminación
    try {
        $result = executeEmpresaDeletion($db, $empresaId, $empresa, $adminId, 'empresa_rechazada');
        
        echo json_encode([
            'success' => true,
            'message' => "Empresa rechazada y eliminada. Email enviado."
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

/**
 * Eliminar empresa DEFINITIVAMENTE (hard delete)
 */
function deleteEmpresa($db, $input) {
    $empresaId = intval($input['id'] ?? 0);
    $adminId = $input['admin_id'] ?? null;
    
    if (!$empresaId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID de empresa requerido']);
        return;
    }
    
    $empresaQuery = $db->prepare("SELECT * FROM empresas_transporte WHERE id = ?");
    $empresaQuery->execute([$empresaId]);
    $empresa = $empresaQuery->fetch(PDO::FETCH_ASSOC);
    
    if (!$empresa) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Empresa no encontrada']);
        return;
    }
    
    if (checkConductores($db, $empresaId)) {
        return;
    }
    
    // Enviar correo de notificación antes de eliminar (Requisito UI/UX)
    // Se realiza antes de borrar para tener acceso a los datos (logo, nombre, etc.)
    $toEmail = $empresa['representante_email'] ?: $empresa['email'];
    $toName = $empresa['representante_nombre'];
    $empresa['nombre_empresa'] = $empresa['nombre']; // Fix for email template variable
    
    try {
        require_once __DIR__ . '/../utils/Mailer.php';
        Mailer::sendCompanyDeletedEmail($toEmail, $toName, $empresa);
    } catch (Exception $e) {
        error_log("Error enviando email de eliminación: " . $e->getMessage());
        // Continuamos con la eliminación aunque falle el email
    }
    
    try {
        $result = executeEmpresaDeletion($db, $empresaId, $empresa, $adminId, 'empresa_eliminada_permanente');
        
        echo json_encode([
            'success' => true,
            'message' => "Empresa eliminada permanentemente. {$result['users']} usuario(s) eliminado(s)." . ($result['logo'] ? ' Logo borrado.' : '')
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// Helper para verificar conductores
function checkConductores($db, $empresaId) {
    $check = $db->prepare("SELECT COUNT(*) as total FROM usuarios WHERE empresa_id = ? AND tipo_usuario = 'conductor'");
    $check->execute([$empresaId]);
    $count = $check->fetch(PDO::FETCH_ASSOC)['total'];
    
    if ($count > 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => "No se puede eliminar la empresa porque tiene $count conductor(es) asociado(s)."
        ]);
        return true;
    }
    return false;
}

// Helper centralizado de eliminación
function executeEmpresaDeletion($db, $empresaId, $empresa, $adminId, $auditAction) {
    try {
        $db->beginTransaction();
        
        // Desvincular usuarios creadores/verificadores
        $unlinkUsers = $db->prepare("UPDATE empresas_transporte SET creado_por = NULL, verificado_por = NULL WHERE id = ?");
        $unlinkUsers->execute([$empresaId]);
        
        // Eliminar usuarios tipo 'empresa'
        $deleteUsers = $db->prepare("DELETE FROM usuarios WHERE empresa_id = ? AND tipo_usuario = 'empresa'");
        $deleteUsers->execute([$empresaId]);
        $deletedUsersCount = $deleteUsers->rowCount();
        
        // Eliminar logo R2
        $logoDeleted = false;
        if (!empty($empresa['logo_url'])) {
            try {
                require_once __DIR__ . '/../config/R2Service.php';
                $r2 = new R2Service();
                $logoKey = $empresa['logo_url'];
                
                if (strpos($logoKey, 'r2_proxy.php?key=') !== false) {
                    parse_str(parse_url($logoKey, PHP_URL_QUERY), $params);
                    $logoKey = $params['key'] ?? $logoKey;
                }
                
                if (strpos($logoKey, 'http') === 0) {
                    $parsed = parse_url($logoKey);
                    $logoKey = ltrim($parsed['path'] ?? '', '/');
                }
                
                if (!empty($logoKey)) {
                    $logoDeleted = $r2->deleteFile($logoKey);
                }
            } catch (Exception $e) {
                error_log("R2 delete warning: " . $e->getMessage());
            }
        }
        
        // Eliminar empresa
        $deleteEmpresa = $db->prepare("DELETE FROM empresas_transporte WHERE id = ?");
        $deleteEmpresa->execute([$empresaId]);
        
        // Auditoría
        logAuditAction($db, $adminId, $auditAction, 'empresas_transporte', $empresaId, [
            'nombre' => $empresa['nombre'],
            'usuarios_eliminados' => $deletedUsersCount,
            'logo_eliminado' => $logoDeleted
        ]);
        
        $db->commit();
        
        return ['users' => $deletedUsersCount, 'logo' => $logoDeleted];
        
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}


/**
 * Cambiar estado de empresa (activar/desactivar)
 */
function toggleEmpresaStatus($db, $input) {
    $empresaId = intval($input['id'] ?? 0);
    $nuevoEstado = $input['estado'] ?? null;
    
    if (!$empresaId || !$nuevoEstado) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID y estado son requeridos']);
        return;
    }
    
    $estadosValidos = ['activo', 'inactivo', 'suspendido', 'pendiente'];
    if (!in_array($nuevoEstado, $estadosValidos)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Estado no válido']);
        return;
    }
    
    $query = "UPDATE empresas_transporte SET estado = ? WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$nuevoEstado, $empresaId]);
    
    // Enviar notificación por correo
    if ($nuevoEstado === 'activo' || $nuevoEstado === 'inactivo') {
        try {
            $empresaQuery = $db->prepare("SELECT nombre, email, representante_nombre, representante_email, logo_url FROM empresas_transporte WHERE id = ?");
            $empresaQuery->execute([$empresaId]);
            $empresaData = $empresaQuery->fetch(PDO::FETCH_ASSOC);
            
            if ($empresaData) {
                $empresaData['nombre_empresa'] = $empresaData['nombre'];
                $toEmail = $empresaData['representante_email'] ?: $empresaData['email'];
                $toName = $empresaData['representante_nombre'];
                
                require_once __DIR__ . '/../utils/Mailer.php';
                Mailer::sendCompanyStatusChangeEmail($toEmail, $toName, $empresaData, $nuevoEstado);
            }
        } catch (Exception $e) {
            error_log("Error enviando email toggle status: " . $e->getMessage());
        }
    }
    
    // Log de auditoría
    $adminId = $input['admin_id'] ?? null;
    logAuditAction($db, $adminId, 'empresa_estado_cambiado', 'empresas_transporte', $empresaId, [
        'nuevo_estado' => $nuevoEstado
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => "Estado de la empresa cambiado a '$nuevoEstado'"
    ]);
}

/**
 * Aprobar solicitud de empresa pendiente
 */
function approveEmpresa($db, $input) {
    $empresaId = intval($input['id'] ?? 0);
    $adminId = intval($input['admin_id'] ?? 0);
    
    if (!$empresaId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID de empresa requerido']);
        return;
    }
    
    // Obtener información de la empresa incluyendo el municipio de contacto
    $query = "SELECT e.*, 
                     ec.municipio as contacto_municipio,
                     ec.departamento as contacto_departamento,
                     u.email as usuario_email, 
                     u.nombre as usuario_nombre 
              FROM empresas_transporte e
              LEFT JOIN empresas_contacto ec ON ec.empresa_id = e.id
              LEFT JOIN usuarios u ON u.empresa_id = e.id AND u.tipo_usuario = 'empresa'
              WHERE e.id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$empresaId]);
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$empresa) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Empresa no encontrada']);
        return;
    }
    
    if ($empresa['estado'] !== 'pendiente') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'La empresa no está en estado pendiente']);
        return;
    }
    
    // Actualizar estado a activo y marcar como verificada
    $updateQuery = "UPDATE empresas_transporte 
                    SET estado = 'activo', verificada = true, fecha_verificacion = NOW(), verificado_por = ?
                    WHERE id = ?";
    $updateStmt = $db->prepare($updateQuery);
    $updateStmt->execute([$adminId, $empresaId]);
    
    // Habilitar tipos de vehículo por defecto si no existen
    enableDefaultVehicleTypesForEmpresa($db, $empresaId, $adminId);
    
    // Configurar zona de operación automáticamente basada en el municipio de la empresa
    configureZonaOperacionEmpresa($db, $empresaId, $empresa['contacto_municipio'], $empresa['contacto_departamento']);
    
    // Log de auditoría
    logAuditAction($db, $adminId, 'empresa_aprobada', 'empresas_transporte', $empresaId, [
        'nombre' => $empresa['nombre'],
        'email' => $empresa['email'],
        'municipio' => $empresa['contacto_municipio']
    ]);
    
    // Enviar notificaciones de aprobación usando Servicio (Email con diseño + copia al personal)
    try {
        require_once __DIR__ . '/../empresa/services/EmpresaService.php';
        $service = new EmpresaService($db);
        
        // Preparar datos para el servicio
        $empresaData = $empresa;
        // Fallbacks para email y representante si faltan en la tabla empresas pero están en usuarios
        if (empty($empresaData['email']) && !empty($empresaData['usuario_email'])) {
            $empresaData['email'] = $empresaData['usuario_email'];
        }
        if (empty($empresaData['representante_nombre']) && !empty($empresaData['usuario_nombre'])) {
            $empresaData['representante_nombre'] = $empresaData['usuario_nombre'];
        }
        // Usuario email como personal email si es diferente
        if (!empty($empresaData['usuario_email']) && $empresaData['usuario_email'] !== $empresaData['email']) {
            $empresaData['representante_email'] = $empresaData['usuario_email'];
        }

        $service->sendApprovalNotifications($empresaData);
        
    } catch (Exception $e) {
        // No fallar la aprobación si falla el email, solo loguear
        error_log("Error enviando notificaciones de aprobación: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Empresa aprobada exitosamente. Se ha notificado al representante.'
    ]);
}



/**
 * Enviar email de aprobación de empresa
 */
function sendApprovalEmail($email, $nombreEmpresa, $representante) {
    try {
        $vendorPath = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($vendorPath)) {
            error_log("Vendor autoload no encontrado para enviar email de aprobación");
            return;
        }
        require_once $vendorPath;
        
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            error_log("PHPMailer no disponible");
            return;
        }
        
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'viaxoficialcol@gmail.com';
        $mail->Password = 'filz vqel gadn kugb';
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        
        $mail->setFrom('viaxoficialcol@gmail.com', 'Viax');
        $mail->addAddress($email, $representante);
        
        $mail->isHTML(true);
        $mail->Subject = "✅ ¡Tu empresa ha sido aprobada! - {$nombreEmpresa}";
        
        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <div style='background: linear-gradient(135deg, #4CAF50 0%, #388E3C 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;'>
                <h1 style='color: white; margin: 0;'>✅ ¡Felicidades!</h1>
                <p style='color: white; margin: 10px 0 0 0;'>Tu empresa ha sido aprobada</p>
            </div>
            <div style='padding: 30px; background: #f9f9f9; border-radius: 0 0 10px 10px;'>
                <p style='font-size: 16px;'>Hola <strong>{$representante}</strong>,</p>
                <p>Nos complace informarte que <strong>{$nombreEmpresa}</strong> ha sido aprobada y verificada en Viax.</p>
                <div style='background: #e8f5e9; border: 1px solid #4CAF50; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center;'>
                    <p style='margin: 0; color: #2e7d32; font-size: 18px;'><strong>¡Tu cuenta ya está activa!</strong></p>
                </div>
                <p>Ahora puedes:</p>
                <ul style='line-height: 1.8;'>
                    <li>✅ Iniciar sesión en la aplicación</li>
                    <li>✅ Agregar y gestionar tus conductores</li>
                    <li>✅ Ver estadísticas y reportes</li>
                    <li>✅ Administrar tu flota de vehículos</li>
                </ul>
                <hr style='border: none; border-top: 1px solid #ddd; margin: 20px 0;'>
                <p style='color: #666; font-size: 12px; text-align: center;'>
                    ¿Tienes preguntas? Contáctanos a viaxoficialcol@gmail.com<br>
                    © 2026 Viax - Todos los derechos reservados
                </p>
            </div>
        </div>";
        
        $mail->AltBody = "Hola {$representante},\n\n¡Felicidades! Tu empresa {$nombreEmpresa} ha sido aprobada en Viax.\n\nYa puedes iniciar sesión y comenzar a gestionar tu flota.\n\nSaludos,\nEquipo Viax";
        
        $mail->send();
        error_log("Email de aprobación enviado a: {$email}");
        
    } catch (\Exception $e) {
        error_log("Error enviando email de aprobación: " . $e->getMessage());
    }
}

/**
 * Enviar email de rechazo de empresa
 */
function sendRejectionEmail($email, $nombreEmpresa, $representante, $motivo) {
    try {
        $vendorPath = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($vendorPath)) {
            return;
        }
        require_once $vendorPath;
        
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            return;
        }
        
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'viaxoficialcol@gmail.com';
        $mail->Password = 'filz vqel gadn kugb';
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        
        $mail->setFrom('viaxoficialcol@gmail.com', 'Viax');
        $mail->addAddress($email, $representante);
        
        $mail->isHTML(true);
        $mail->Subject = "Información sobre tu solicitud - {$nombreEmpresa}";
        
        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <div style='background: linear-gradient(135deg, #757575 0%, #616161 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;'>
                <h1 style='color: white; margin: 0;'>Actualización de tu Solicitud</h1>
            </div>
            <div style='padding: 30px; background: #f9f9f9; border-radius: 0 0 10px 10px;'>
                <p style='font-size: 16px;'>Hola <strong>{$representante}</strong>,</p>
                <p>Lamentamos informarte que después de revisar tu solicitud para <strong>{$nombreEmpresa}</strong>, no hemos podido aprobarla en este momento.</p>
                <div style='background: #fff3e0; border: 1px solid #ff9800; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                    <p style='margin: 0; color: #e65100;'><strong>Motivo:</strong></p>
                    <p style='margin: 10px 0 0 0; color: #333;'>{$motivo}</p>
                </div>
                <p>Si crees que esto es un error o deseas proporcionar información adicional, no dudes en contactarnos.</p>
                <p>También puedes intentar registrarte nuevamente corrigiendo los aspectos mencionados.</p>
                <hr style='border: none; border-top: 1px solid #ddd; margin: 20px 0;'>
                <p style='color: #666; font-size: 12px; text-align: center;'>
                    Contáctanos a viaxoficialcol@gmail.com<br>
                    © 2026 Viax - Todos los derechos reservados
                </p>
            </div>
        </div>";
        
        $mail->AltBody = "Hola {$representante},\n\nLamentamos informarte que tu solicitud para {$nombreEmpresa} no ha sido aprobada.\n\nMotivo: {$motivo}\n\nPuedes contactarnos para más información.\n\nSaludos,\nEquipo Viax";
        
        $mail->send();
        error_log("Email de rechazo enviado a: {$email}");
        
    } catch (\Exception $e) {
        error_log("Error enviando email de rechazo: " . $e->getMessage());
    }
}

/**
 * Obtener estadísticas de empresas
 */
function getEmpresaStats($db) {
    $query = "SELECT 
                COUNT(*) as total_empresas,
                SUM(CASE WHEN estado = 'activo' THEN 1 ELSE 0 END) as activas,
                SUM(CASE WHEN estado = 'inactivo' THEN 1 ELSE 0 END) as inactivas,
                SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendientes,
                SUM(CASE WHEN verificada = true THEN 1 ELSE 0 END) as verificadas,
                SUM(total_conductores) as total_conductores,
                SUM(total_viajes_completados) as total_viajes
              FROM empresas_transporte
              WHERE estado != 'eliminado'";
    
    $stmt = $db->query($query);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'stats' => $stats
    ]);
}

/**
 * Convertir array PostgreSQL a array PHP
 */
function pgArrayToPhp($pgArray) {
    if (empty($pgArray) || $pgArray === '{}') {
        return [];
    }
    
    // Remover llaves y dividir por coma
    $pgArray = trim($pgArray, '{}');
    if (empty($pgArray)) {
        return [];
    }
    
    // Manejar comillas en elementos
    $result = [];
    $items = str_getcsv($pgArray);
    foreach ($items as $item) {
        $result[] = trim($item, '"');
    }
    
    return $result;
}

/**
 * Convertir array PHP a array PostgreSQL
 */
function phpArrayToPg($phpArray) {
    if (empty($phpArray)) {
        return '{}';
    }
    
    $escaped = array_map(function($item) {
        return '"' . str_replace('"', '\\"', $item) . '"';
    }, $phpArray);
    
    return '{' . implode(',', $escaped) . '}';
}

/**
 * Registrar acción en log de auditoría
 */
function logAuditAction($db, $adminId, $action, $tabla, $registroId, $detalles) {
    try {
        // Verificar si la tabla de auditoría existe
        $checkTable = $db->query("SELECT EXISTS (
            SELECT FROM information_schema.tables 
            WHERE table_name = 'audit_logs'
        )");
        $exists = $checkTable->fetchColumn();
        
        if (!$exists) {
            error_log("Tabla audit_logs no existe, saltando log de auditoría");
            return;
        }
        
        $query = "INSERT INTO audit_logs (admin_id, action, tabla_afectada, registro_id, detalles, ip_address) 
                  VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($query);
        $stmt->execute([
            $adminId,
            $action,
            $tabla,
            $registroId,
            json_encode($detalles),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        error_log("Error al registrar auditoría: " . $e->getMessage());
    }
}

/**
 * Habilitar tipos de vehículo por defecto para una empresa
 */
function enableDefaultVehicleTypesForEmpresa($db, $empresaId, $adminId = null) {
    try {
        // Verificar si existe la tabla normalizada
        $checkTable = $db->query("SELECT EXISTS (
            SELECT FROM information_schema.tables 
            WHERE table_schema = 'public' AND table_name = 'empresa_tipos_vehiculo'
        )");
        $tableExists = $checkTable->fetchColumn();
        
        if (!$tableExists) {
            error_log("Tabla empresa_tipos_vehiculo no existe, saltando habilitación automática");
            return 0;
        }
        
        // Obtener los tipos de vehículo seleccionados por la empresa
        $stmt = $db->prepare("SELECT tipos_vehiculo FROM empresas_transporte WHERE id = ?");
        $stmt->execute([$empresaId]);
        $tiposRaw = $stmt->fetchColumn();
        
        if (empty($tiposRaw)) {
            error_log("Empresa $empresaId no tiene tipos de vehículo seleccionados");
            return 0;
        }

        // Convertir array de Postgres o cadena JSON a array PHP
        $tiposSeleccionados = [];
        if (substr($tiposRaw, 0, 1) === '{') {
            // Postgres array format {moto,carro}
             $tiposSeleccionados = pgArrayToPhp($tiposRaw);
        } else {
             // JSON or comma separated
            $decoded = json_decode($tiposRaw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $tiposSeleccionados = $decoded;
            } else {
                $tiposSeleccionados = explode(',', $tiposRaw);
            }
        }

        // Limpiar
        $tiposSeleccionados = array_map(function($t) { return trim($t, '" '); }, $tiposSeleccionados);
        $tiposSeleccionados = array_filter($tiposSeleccionados);
        
        if (empty($tiposSeleccionados)) {
            error_log("No se pudieron procesar tipos de vehículo para empresa $empresaId");
            return 0;
        }
        
        // Habilitar CADA tipo seleccionado
        $insertStmt = $db->prepare("
            INSERT INTO empresa_tipos_vehiculo 
                (empresa_id, tipo_vehiculo_codigo, activo, fecha_activacion, activado_por)
            VALUES (?, ?, true, NOW(), ?)
            ON CONFLICT (empresa_id, tipo_vehiculo_codigo) 
            DO UPDATE SET 
                activo = true, 
                fecha_activacion = NOW(),
                activado_por = COALESCE(EXCLUDED.activado_por, empresa_tipos_vehiculo.activado_por)
        ");
        
        $count = 0;
        foreach ($tiposSeleccionados as $tipo) {
            $insertStmt->execute([$empresaId, $tipo, $adminId]);
            $count++;
        }
        
        error_log("Habilitados $count tipos de vehículo seleccionados para empresa $empresaId: " . implode(',', $tiposSeleccionados));
        return $count;
        
    } catch (Exception $e) {
        error_log("Error habilitando tipos de vehículo: " . $e->getMessage());
        return 0;
    }
}

/**
 * Validar y guardar logo de empresa
 */
function handleLogoUpload() {
    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES['logo'];
    
    // Validar errores
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error en la subida del archivo: ' . $file['error']);
    }

    // Validar tamaño (5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('El archivo excede el tamaño máximo permitido (5MB)');
    }

    // Validar tipo
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        throw new Exception('Tipo de archivo no permitido. Solo se permiten JPG, PNG y WEBP.');
    }

    // Estructura R2: empresas/YYYY/MM/
    $year = date('Y');
    $month = date('m');
    
    // Nombre único
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = "empresas/$year/$month/logo_" . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    
    try {
        require_once __DIR__ . '/../config/R2Service.php';
        $r2 = new R2Service();
        $url = $r2->uploadFile($file['tmp_name'], $filename, $mimeType);
        return $url;
    } catch (Exception $e) {
        throw new Exception('Error subiendo a R2: ' . $e->getMessage());
    }
}
/**
 * Configura la zona de operación de una empresa basada en su municipio
 * Esta función se llama automáticamente al aprobar una empresa
 */
function configureZonaOperacionEmpresa($db, $empresaId, $municipio, $departamento = null) {
    try {
        if (empty($municipio)) {
            error_log("configureZonaOperacionEmpresa: Municipio vacío para empresa $empresaId");
            return false;
        }
        
        // Normalizar municipio (quitar espacios extra)
        $municipio = trim($municipio);
        
        // Verificar si ya existe configuración
        $checkQuery = "SELECT id, zona_operacion FROM empresas_configuracion WHERE empresa_id = ?";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->execute([$empresaId]);
        $config = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        // Parsear zona actual si existe
        $zonaActual = [];
        if ($config && $config['zona_operacion']) {
            $zonaStr = trim($config['zona_operacion'], '{}');
            if (!empty($zonaStr)) {
                $zonaActual = array_map(function($z) {
                    return trim(trim($z), '"\'');
                }, explode(',', $zonaStr));
            }
        }
        
        // Agregar municipio si no existe ya
        $municipioLower = strtolower($municipio);
        $yaExiste = false;
        foreach ($zonaActual as $zona) {
            if (strtolower(trim($zona)) === $municipioLower) {
                $yaExiste = true;
                break;
            }
        }
        
        if (!$yaExiste) {
            $zonaActual[] = $municipio;
        }
        
        // Opcionalmente agregar el departamento como zona de cobertura amplia
        if ($departamento && !empty($departamento)) {
            $departamento = trim($departamento);
            $depLower = strtolower($departamento);
            $depExiste = false;
            foreach ($zonaActual as $zona) {
                if (strtolower(trim($zona)) === $depLower) {
                    $depExiste = true;
                    break;
                }
            }
            if (!$depExiste) {
                $zonaActual[] = $departamento;
            }
        }
        
        // Formatear para PostgreSQL array
        $zonaPostgres = '{' . implode(',', array_map(function($m) {
            return '"' . str_replace('"', '\"', trim($m)) . '"';
        }, array_unique(array_filter($zonaActual)))) . '}';
        
        if ($config) {
            // Actualizar existente
            $updateQuery = "UPDATE empresas_configuracion 
                           SET zona_operacion = :zona, actualizado_en = NOW() 
                           WHERE empresa_id = :empresa_id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':zona', $zonaPostgres);
            $updateStmt->bindParam(':empresa_id', $empresaId, PDO::PARAM_INT);
            $updateStmt->execute();
        } else {
            // Crear nueva configuración
            $insertQuery = "INSERT INTO empresas_configuracion (empresa_id, zona_operacion, creado_en) 
                           VALUES (:empresa_id, :zona, NOW())";
            $insertStmt = $db->prepare($insertQuery);
            $insertStmt->bindParam(':empresa_id', $empresaId, PDO::PARAM_INT);
            $insertStmt->bindParam(':zona', $zonaPostgres);
            $insertStmt->execute();
        }
        
        error_log("configureZonaOperacionEmpresa: Zona configurada para empresa $empresaId: " . implode(', ', $zonaActual));
        return true;
        
    } catch (Exception $e) {
        error_log("Error configurando zona de operación: " . $e->getMessage());
        return false;
    }
}

/**
 * Actualiza la zona de operación cuando se edita una empresa
 * Sincroniza el municipio de contacto con la zona de operación
 */
function syncZonaOperacionConMunicipio($db, $empresaId) {
    try {
        // Obtener municipio actual de contacto
        $query = "SELECT municipio, departamento FROM empresas_contacto WHERE empresa_id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$empresaId]);
        $contacto = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($contacto && !empty($contacto['municipio'])) {
            return configureZonaOperacionEmpresa($db, $empresaId, $contacto['municipio'], $contacto['departamento']);
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Error sincronizando zona de operación: " . $e->getMessage());
        return false;
    }
}