<?php

require_once __DIR__ . '/../middleware/SessionAccess.php';

header('Content-Type: application/json; charset=UTF-8');
if (Request::method() !== 'GET') {
    header('Allow: GET');
    sendJsonResponse(false, 'Método no permitido.', [], 405);
}
try {
    $db = (new Database())->getConnection();
    $user = SessionAccess::requireUser($db);
    $resolvedUserId = (int)$user['id'];
    if (isset($_GET['userId']) && filter_var($_GET['userId'], FILTER_VALIDATE_INT) !== $resolvedUserId) {
        sendJsonResponse(false, 'Acceso denegado.', [], 403);
    }

    $stmt = $db->prepare(
        "SELECT id, usuario_id, tipo_guardado, nombre_guardado, latitud, longitud, direccion,
                ciudad, departamento, pais, codigo_postal, creado_en, actualizado_en
           FROM ubicaciones_usuario
          WHERE usuario_id = ?
            AND tipo_guardado IN ('home', 'work', 'favorite')
          ORDER BY CASE tipo_guardado
                    WHEN 'home' THEN 0
                    WHEN 'work' THEN 1
                    ELSE 2
                   END,
                   COALESCE(actualizado_en, creado_en) DESC,
                   id DESC"
    );
    $stmt->execute([$resolvedUserId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $home = null;
    $work = null;
    $favorites = [];

    foreach ($rows as $row) {
        $type = (string) ($row['tipo_guardado'] ?? '');
        if ($type === 'home' && $home === null) {
            $home = $row;
            continue;
        }
        if ($type === 'work' && $work === null) {
            $work = $row;
            continue;
        }
        if ($type === 'favorite') {
            $favorites[] = $row;
        }
    }

    sendJsonResponse(true, 'Direcciones guardadas obtenidas', [
        'home' => $home,
        'work' => $work,
        'favorites' => $favorites,
    ]);
} catch (Throwable $error) {
    error_log('VIAX saved places: ' . get_class($error));
    sendJsonResponse(false, 'No pudimos consultar las direcciones guardadas.', [], 503);
}
