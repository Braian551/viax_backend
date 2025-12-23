<?php
require_once '../config/config.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    // Obtener parámetros (GET)
    $userId = isset($_GET['userId']) ? intval($_GET['userId']) : null;
    $email = isset($_GET['email']) ? trim($_GET['email']) : null;

    if (!$userId && !$email) {
        sendJsonResponse(false, 'Se requiere userId o email');
    }

    if ($userId) {
        $q = "SELECT id, uuid, nombre, apellido, email, telefono, tipo_usuario, fecha_registro as creado_en FROM usuarios WHERE id = ? LIMIT 1";
        $stmt = $db->prepare($q);
        $stmt->execute([$userId]);
    } else {
        $q = "SELECT id, uuid, nombre, apellido, email, telefono, tipo_usuario, fecha_registro as creado_en FROM usuarios WHERE email = ? LIMIT 1";
        $stmt = $db->prepare($q);
        $stmt->execute([$email]);
    }

    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        sendJsonResponse(false, 'Usuario no encontrado');
    }

    // Obtener ubicación principal
    $q = "SELECT id, latitud, longitud, direccion, ciudad, departamento, pais, codigo_postal, es_principal FROM ubicaciones_usuario WHERE usuario_id = ? ORDER BY es_principal DESC, creado_en DESC LIMIT 1";
    $stmt = $db->prepare($q);
    $stmt->execute([$user['id']]);
    $location = $stmt->fetch(PDO::FETCH_ASSOC);

    sendJsonResponse(true, 'Perfil obtenido', ['user' => $user, 'location' => $location]);

} catch (Exception $e) {
    http_response_code(500);
    sendJsonResponse(false, 'Error: ' . $e->getMessage());
}

?>
