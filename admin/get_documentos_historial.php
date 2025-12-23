<?php
// Suprimir warnings y notices
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';

// Crear conexión mysqli
$conn = new mysqli('localhost', 'root', 'root', 'viax');
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error de conexión a la base de datos: ' . $conn->connect_error
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$conn->set_charset("utf8");

try {
    // Validar método
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Método no permitido');
    }

    // Obtener parámetros
    $admin_id = isset($_GET['admin_id']) ? intval($_GET['admin_id']) : 0;
    $conductor_id = isset($_GET['conductor_id']) ? intval($_GET['conductor_id']) : 0;

    // Validar admin_id
    if ($admin_id <= 0) {
        throw new Exception('ID de administrador inválido');
    }

    // Validar conductor_id
    if ($conductor_id <= 0) {
        throw new Exception('ID de conductor inválido');
    }

    // Verificar que es admin
    $stmt = $conn->prepare("SELECT tipo_usuario FROM usuarios WHERE id = ? AND tipo_usuario = 'administrador'");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Acceso denegado. Solo administradores pueden ver historial de documentos.'
        ]);
        exit;
    }

    // Verificar que el conductor existe
    $stmt = $conn->prepare("SELECT id FROM usuarios WHERE id = ? AND tipo_usuario = 'conductor'");
    $stmt->bind_param("i", $conductor_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Conductor no encontrado'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Obtener historial de documentos
    $sql = "SELECT 
                dch.id,
                dch.conductor_id,
                dch.tipo_documento,
                dch.url_documento as ruta_archivo,
                dch.fecha_carga as fecha_subida,
                dch.activo,
                dch.reemplazado_en,
                u.nombre,
                u.apellido,
                u.email
            FROM documentos_conductor_historial dch
            INNER JOIN usuarios u ON dch.conductor_id = u.id
            WHERE dch.conductor_id = ?
            ORDER BY dch.fecha_carga DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Error al preparar consulta: ' . $conn->error);
    }
    
    $stmt->bind_param("i", $conductor_id);
    if (!$stmt->execute()) {
        throw new Exception('Error al ejecutar consulta: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();

    $historial = [];
    while ($row = $result->fetch_assoc()) {
        // Determinar el estado basado en el campo 'activo'
        $estado = intval($row['activo']) === 1 ? 'aprobado' : 'reemplazado';
        
        $historial[] = [
            'id' => intval($row['id']),
            'conductor_id' => intval($row['conductor_id']),
            'conductor_nombre' => trim(($row['nombre'] ?? '') . ' ' . ($row['apellido'] ?? '')),
            'conductor_email' => $row['email'] ?? '',
            'tipo_documento' => $row['tipo_documento'] ?? '',
            'ruta_archivo' => $row['ruta_archivo'] ?? '',
            'fecha_subida' => $row['fecha_subida'] ?? '',
            'estado' => $estado,
            'activo' => intval($row['activo']),
            'reemplazado_en' => $row['reemplazado_en'] ?? null,
            'motivo_rechazo' => null, // Esta tabla no tiene motivo de rechazo
            'numero_documento' => null,
            'fecha_vencimiento' => null,
        ];
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => count($historial) > 0 
            ? 'Historial de documentos obtenido exitosamente' 
            : 'No hay historial de documentos para este conductor',
        'data' => [
            'historial' => $historial,
            'total' => count($historial),
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
