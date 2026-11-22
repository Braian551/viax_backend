<?php

require_once __DIR__ . '/../middleware/SessionAccess.php';

try {
    $db = (new Database())->getConnection();
    SessionAccess::requireAdmin($db);
    if (Request::method() !== 'GET') {
        header('Allow: GET');
        sendJsonResponse(false, 'Los anuncios están disponibles en modo consulta.', [], 405);
    }
    $value = $db->query("SELECT valor FROM configuraciones_app WHERE clave = 'app_remote_announcements'")->fetchColumn();
    $config = $value === false ? null : json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR);
    sendJsonResponse(true, 'Anuncios obtenidos.', [
        'config' => ['app_remote_announcements' => $config],
        'can_manage' => false,
    ]);
} catch (Throwable $error) {
    error_log('VIAX announcement listing: ' . get_class($error));
    sendJsonResponse(false, 'No se pudieron cargar los anuncios.', [], 500);
}
