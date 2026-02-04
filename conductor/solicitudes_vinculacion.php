<?php
/**
 * API: Gestión de Solicitudes de Vinculación de Conductores a Empresas
 * 
 * Endpoints:
 * GET: Listar solicitudes (por empresa o conductor)
 * POST: Crear solicitud de vinculación
 * PUT: Aprobar/Rechazar solicitud
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();

    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            handleGet($db);
            break;
        case 'POST':
            handlePost($db);
            break;
        case 'PUT':
            handlePut($db);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * GET: Listar solicitudes
 * Params: 
 *   - empresa_id: Solicitudes para una empresa específica
 *   - conductor_id: Solicitudes de un conductor específico
 *   - estado: Filtrar por estado (pendiente, aprobada, rechazada)
 */
function handleGet($db) {
    $empresaId = isset($_GET['empresa_id']) ? intval($_GET['empresa_id']) : null;
    $conductorId = isset($_GET['conductor_id']) ? intval($_GET['conductor_id']) : null;
    $estado = isset($_GET['estado']) ? $_GET['estado'] : null;

    $query = "SELECT 
                sv.id,
                sv.conductor_id,
                sv.empresa_id,
                sv.estado,
                sv.mensaje_conductor,
                sv.respuesta_empresa,
                sv.creado_en,
                sv.procesado_en,
                u.nombre AS conductor_nombre,
                u.apellido AS conductor_apellido,
                u.email AS conductor_email,
                u.telefono AS conductor_telefono,
                u.foto_perfil AS conductor_foto,
                dc.vehiculo_tipo,
                dc.vehiculo_marca,
                dc.vehiculo_modelo,
                dc.vehiculo_placa,
                et.nombre AS empresa_nombre,
                et.logo_url AS empresa_logo
              FROM solicitudes_vinculacion_conductor sv
              INNER JOIN usuarios u ON sv.conductor_id = u.id
              LEFT JOIN detalles_conductor dc ON sv.conductor_id = dc.usuario_id
              INNER JOIN empresas_transporte et ON sv.empresa_id = et.id
              WHERE 1=1";
    
    $params = [];
    
    if ($empresaId) {
        $query .= " AND sv.empresa_id = :empresa_id";
        $params[':empresa_id'] = $empresaId;
    }
    
    if ($conductorId) {
        $query .= " AND sv.conductor_id = :conductor_id";
        $params[':conductor_id'] = $conductorId;
    }
    
    if ($estado) {
        $query .= " AND sv.estado = :estado";
        $params[':estado'] = $estado;
    }
    
    $query .= " ORDER BY sv.creado_en DESC";
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $solicitudes,
        'count' => count($solicitudes)
    ]);
}

/**
 * POST: Crear nueva solicitud de vinculación
 */
function handlePost($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Datos inválidos');
    }
    
    $conductorId = isset($input['conductor_id']) ? intval($input['conductor_id']) : 0;
    $empresaId = isset($input['empresa_id']) ? intval($input['empresa_id']) : 0;
    $mensaje = isset($input['mensaje']) ? trim($input['mensaje']) : '';
    
    if ($conductorId <= 0) {
        throw new Exception('ID de conductor inválido');
    }
    
    if ($empresaId <= 0) {
        throw new Exception('Debes seleccionar una empresa de transporte');
    }
    
    // Verificar que el conductor existe y es tipo conductor
    $checkConductorQuery = "SELECT id, tipo_usuario, empresa_id FROM usuarios WHERE id = :id";
    $checkStmt = $db->prepare($checkConductorQuery);
    $checkStmt->bindParam(':id', $conductorId, PDO::PARAM_INT);
    $checkStmt->execute();
    $conductor = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$conductor || $conductor['tipo_usuario'] !== 'conductor') {
        throw new Exception('Usuario no es conductor');
    }
    
    // Verificar si ya está vinculado a una empresa
    if ($conductor['empresa_id']) {
        throw new Exception('Ya estás vinculado a una empresa. Debes desvincularte primero.');
    }
    
    // Verificar que la empresa existe y está activa
    $checkEmpresaQuery = "SELECT id, estado, nombre FROM empresas_transporte WHERE id = :id";
    $checkEmpresaStmt = $db->prepare($checkEmpresaQuery);
    $checkEmpresaStmt->bindParam(':id', $empresaId, PDO::PARAM_INT);
    $checkEmpresaStmt->execute();
    $empresa = $checkEmpresaStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$empresa) {
        throw new Exception('Empresa no encontrada');
    }
    
    if ($empresa['estado'] !== 'activo') {
        throw new Exception('La empresa no está activa');
    }
    
    // Verificar si ya hay una solicitud pendiente
    $checkSolicitudQuery = "SELECT id FROM solicitudes_vinculacion_conductor 
                            WHERE conductor_id = :conductor_id 
                            AND empresa_id = :empresa_id 
                            AND estado = 'pendiente'";
    $checkSolicitudStmt = $db->prepare($checkSolicitudQuery);
    $checkSolicitudStmt->bindParam(':conductor_id', $conductorId, PDO::PARAM_INT);
    $checkSolicitudStmt->bindParam(':empresa_id', $empresaId, PDO::PARAM_INT);
    $checkSolicitudStmt->execute();
    
    if ($checkSolicitudStmt->rowCount() > 0) {
        throw new Exception('Ya tienes una solicitud pendiente para esta empresa');
    }
    
    // Crear solicitud
    $insertQuery = "INSERT INTO solicitudes_vinculacion_conductor 
                    (conductor_id, empresa_id, estado, mensaje_conductor, creado_en)
                    VALUES (:conductor_id, :empresa_id, 'pendiente', :mensaje, NOW())
                    RETURNING id";
    
    $insertStmt = $db->prepare($insertQuery);
    $insertStmt->bindParam(':conductor_id', $conductorId, PDO::PARAM_INT);
    $insertStmt->bindParam(':empresa_id', $empresaId, PDO::PARAM_INT);
    $insertStmt->bindParam(':mensaje', $mensaje, PDO::PARAM_STR);
    $insertStmt->execute();
    
    $solicitudId = $insertStmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'message' => 'Solicitud enviada a ' . $empresa['nombre'] . '. Te notificaremos cuando respondan.',
        'solicitud_id' => $solicitudId
    ]);
}

/**
 * PUT: Aprobar o rechazar solicitud
 */
function handlePut($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Datos inválidos');
    }
    
    $solicitudId = isset($input['solicitud_id']) ? intval($input['solicitud_id']) : 0;
    $accion = isset($input['accion']) ? $input['accion'] : ''; // 'aprobar' o 'rechazar'
    $procesadoPor = isset($input['procesado_por']) ? intval($input['procesado_por']) : 0;
    $respuesta = isset($input['respuesta']) ? trim($input['respuesta']) : '';
    
    if ($solicitudId <= 0) {
        throw new Exception('ID de solicitud inválido');
    }
    
    if (!in_array($accion, ['aprobar', 'rechazar'])) {
        throw new Exception('Acción inválida. Use "aprobar" o "rechazar"');
    }
    
    $db->beginTransaction();
    
    try {
        if ($accion === 'aprobar') {
            // Usar función SQL para aprobar
            $query = "SELECT aprobar_vinculacion_conductor(:solicitud_id, :procesado_por) as resultado";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':solicitud_id', $solicitudId, PDO::PARAM_INT);
            $stmt->bindParam(':procesado_por', $procesadoPor, PDO::PARAM_INT);
            $stmt->execute();
            
            $resultado = json_decode($stmt->fetchColumn(), true);
            
            if (!$resultado['success']) {
                throw new Exception($resultado['message']);
            }
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Conductor vinculado exitosamente',
                'conductor_id' => $resultado['conductor_id'],
                'empresa_id' => $resultado['empresa_id']
            ]);
            
        } else {
            // Rechazar
            $query = "SELECT rechazar_vinculacion_conductor(:solicitud_id, :procesado_por, :razon) as resultado";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':solicitud_id', $solicitudId, PDO::PARAM_INT);
            $stmt->bindParam(':procesado_por', $procesadoPor, PDO::PARAM_INT);
            $stmt->bindParam(':razon', $respuesta, PDO::PARAM_STR);
            $stmt->execute();
            
            $resultado = json_decode($stmt->fetchColumn(), true);
            
            if (!$resultado['success']) {
                throw new Exception($resultado['message']);
            }
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Solicitud rechazada'
            ]);
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}
?>
