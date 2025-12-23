<?php
require_once '../config/config.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) sendJsonResponse(false, 'No input provided');

    // Require user identification
    $userId = isset($input['userId']) ? intval($input['userId']) : null;
    $email = isset($input['email']) ? trim($input['email']) : null;

    if (!$userId && !$email) {
        sendJsonResponse(false, 'Se requiere userId o email');
    }

    // Location fields (optional, but at least direccion or lat/lng recommended)
    $direccion = isset($input['address']) ? trim($input['address']) : null;
    $latitud = null;
    $longitud = null;
    if (isset($input['lat'])) $latitud = $input['lat'];
    if (isset($input['lng'])) $longitud = $input['lng'];
    if (isset($input['latitude'])) $latitud = $input['latitude'];
    if (isset($input['longitude'])) $longitud = $input['longitude'];
    $ciudad = isset($input['city']) ? $input['city'] : null;
    $departamento = isset($input['state']) ? $input['state'] : null;
    $pais = isset($input['country']) ? $input['country'] : 'Colombia';
    $codigo_postal = isset($input['postal_code']) ? $input['postal_code'] : null;
    $es_principal = isset($input['is_primary']) ? ($input['is_primary'] ? 1 : 0) : 1;

    // Resolve user id from email if needed
    if (!$userId && $email) {
        $q = "SELECT id FROM usuarios WHERE email = ? LIMIT 1";
        $stmt = $db->prepare($q);
        $stmt->execute([$email]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u) sendJsonResponse(false, 'Usuario no encontrado por email');
        $userId = $u['id'];
    }

    // If there's already a primary location, update it; else insert a new one
    $q = "SELECT id FROM ubicaciones_usuario WHERE usuario_id = ? AND es_principal = 1 LIMIT 1";
    $stmt = $db->prepare($q);
    $stmt->execute([$userId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $query = "
            UPDATE ubicaciones_usuario SET latitud = ?, longitud = ?, direccion = ?, ciudad = ?, departamento = ?, pais = ?, codigo_postal = ?, actualizado_en = NOW()
            WHERE id = ?
        ";
        $stmt = $db->prepare($query);
        $stmt->execute([
            $latitud,
            $longitud,
            $direccion,
            $ciudad,
            $departamento,
            $pais,
            $codigo_postal,
            $existing['id']
        ]);

        sendJsonResponse(true, 'Ubicación actualizada', ['locationId' => $existing['id']]);
    } else {
        $query = "
            INSERT INTO ubicaciones_usuario (usuario_id, latitud, longitud, direccion, ciudad, departamento, pais, codigo_postal, es_principal, creado_en)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ";
        $stmt = $db->prepare($query);
        $stmt->execute([
            $userId,
            $latitud,
            $longitud,
            $direccion,
            $ciudad,
            $departamento,
            $pais,
            $codigo_postal,
            $es_principal
        ]);
        $id = $db->lastInsertId();
        sendJsonResponse(true, 'Ubicación guardada', ['locationId' => $id]);
    }

} catch (Exception $e) {
    http_response_code(500);
    sendJsonResponse(false, 'Error: ' . $e->getMessage());
}

?>
