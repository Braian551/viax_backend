<?php
// backend/admin/get_documentos_historial.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    // Validar método
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Método no permitido');
    }

    // Obtener parámetros
    $admin_id = isset($_GET['admin_id']) ? intval($_GET['admin_id']) : 0;
    $conductor_id = isset($_GET['conductor_id']) ? intval($_GET['conductor_id']) : 0;

    if ($admin_id <= 0 || $conductor_id <= 0) {
        throw new Exception('IDs inválidos');
    }

    // Verificar que es admin (PostgreSQL syntax)
    $stmt = $db->prepare("SELECT tipo_usuario FROM usuarios WHERE id = :id AND tipo_usuario = 'administrador'");
    $stmt->bindParam(':id', $admin_id);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Acceso denegado.'
        ]);
        exit;
    }

    // Verificar que el conductor existe en detalles_conductor
    // (usuario_id puede ser cualquier tipo de usuario que tenga detalles de conductor)
    $stmt = $db->prepare("SELECT dc.id, dc.usuario_id, u.nombre, u.apellido 
                          FROM detalles_conductor dc 
                          INNER JOIN usuarios u ON dc.usuario_id = u.id 
                          WHERE dc.usuario_id = :usuario_id");
    $stmt->bindParam(':usuario_id', $conductor_id);
    $stmt->execute();
    
    $conductor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conductor) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => "No se encontraron detalles de conductor para usuario ID $conductor_id"]);
        exit;
    }

    // Obtener historial
    // Postgres doesn't need quotes for identifiers usually, unless reserved keywords.
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
            WHERE dch.conductor_id = :conductor_id
            ORDER BY dch.fecha_carga DESC";

    $stmt = $db->prepare($sql);
    $stmt->bindParam(':conductor_id', $conductor_id);
    $stmt->execute();

    $historial = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
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
        ];
    }

    echo json_encode([
        'success' => true,
        'message' => 'Historial obtenido',
        'data' => [
            'historial' => $historial,
            'total' => count($historial)
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
