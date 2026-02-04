<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();

    // Check basic parameters
    if (!isset($_POST['conductor_id']) || !isset($_FILES['image'])) {
        throw new Exception('Faltan datos requeridos (conductor_id o image)');
    }

    $conductor_id = intval($_POST['conductor_id']);
    
    // Validate user exists (don't check tipo_usuario because they are still 'cliente' during registration)
    $stmt = $db->prepare("SELECT id FROM usuarios WHERE id = :id");
    $stmt->bindParam(':id', $conductor_id);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Usuario no encontrado');
    }

    // Handle File Upload using R2
    require_once '../config/R2Service.php';
    
    $file = $_FILES['image'];
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'vehicle/' . $conductor_id . '_' . time() . '.' . $extension;
    
    $r2 = new R2Service();
    // Use the returned URL directly
    $dbPath = $r2->uploadFile($file['tmp_name'], $filename, $file['type']);

    // Update DB with the full URL
    $updateQuery = "UPDATE detalles_conductor SET 
                    foto_vehiculo = :foto_vehiculo,
                    actualizado_en = NOW()
                    WHERE usuario_id = :usuario_id";
                    
    $updateStmt = $db->prepare($updateQuery);
    $updateStmt->bindParam(':foto_vehiculo', $dbPath);
    $updateStmt->bindParam(':usuario_id', $conductor_id);
    $updateStmt->execute();
    
    if ($updateStmt->rowCount() === 0) {
        // Try inserting if not exists (edge case)
        // Usually update_vehicle runs first, but just in case
        $insertQuery = "INSERT INTO detalles_conductor (usuario_id, foto_vehiculo, creado_en) VALUES (:uid, :foto, NOW())";
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->bindParam(':uid', $conductor_id);
        $insertStmt->bindParam(':foto', $dbPath);
        $insertStmt->execute();
    }

    echo json_encode(['success' => true, 'message' => 'Foto subida exitosamente', 'path' => $dbPath]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
