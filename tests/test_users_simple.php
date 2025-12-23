<?php
/**
 * Test simple endpoint
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

try {
    // Probar conexión
    $database = new Database();
    $db = $database->getConnection();
    
    // Obtener admin_id
    $adminId = isset($_GET['admin_id']) ? (int)$_GET['admin_id'] : 0;
    
    if ($adminId === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'admin_id es requerido',
            'received' => $_GET
        ]);
        exit();
    }
    
    // Verificar admin
    $checkAdmin = "SELECT id, nombre, apellido, tipo_usuario FROM usuarios WHERE id = ? AND tipo_usuario = 'administrador'";
    $stmtCheck = $db->prepare($checkAdmin);
    $stmtCheck->execute([$adminId]);
    $admin = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin) {
        echo json_encode([
            'success' => false,
            'message' => 'Usuario no es administrador',
            'admin_id' => $adminId
        ]);
        exit();
    }
    
    // Obtener usuarios
    $query = "SELECT 
        id, uuid, nombre, apellido, email, telefono, 
        tipo_usuario, es_verificado, es_activo, 
        fecha_registro, fecha_actualizacion
    FROM usuarios 
    ORDER BY fecha_registro DESC
    LIMIT 50";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'message' => 'Usuarios obtenidos exitosamente',
        'data' => [
            'admin' => $admin,
            'usuarios' => $usuarios,
            'total' => count($usuarios)
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
?>
