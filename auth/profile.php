<?php
require_once __DIR__ . '/../middleware/SessionAccess.php';

if (Request::method() !== 'GET') {
    header('Allow: GET');
    sendJsonResponse(false, 'Método no permitido.', [], 405);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $sessionUser = SessionAccess::requireUser($db);
    $userId = (int)$sessionUser['id'];
    if ((isset($_GET['userId']) && filter_var($_GET['userId'], FILTER_VALIDATE_INT) !== $userId)
        || (isset($_GET['email']) && (!is_string($_GET['email']) || strcasecmp($_GET['email'], $sessionUser['email']) !== 0))) {
        sendJsonResponse(false, 'Acceso denegado.', [], 403);
    }

    $stmt = $db->prepare(
        'SELECT id, uuid, nombre, apellido, email, telefono, tipo_usuario, empresa_id,
                foto_perfil, calificacion_promedio, fecha_registro AS creado_en
         FROM usuarios WHERE id = ?'
    );
    $stmt->execute([$userId]);

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
    error_log('VIAX profile: ' . get_class($e));
    sendJsonResponse(false, 'No pudimos consultar el perfil.', [], 500);
}
