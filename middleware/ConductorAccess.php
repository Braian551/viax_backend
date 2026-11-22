<?php

require_once __DIR__ . '/SessionAccess.php';

final class ConductorAccess
{
    public static function requireOwnId(PDO $db): int
    {
        $user = SessionAccess::requireUser($db);
        $id = (int)$user['id'];
        if ($user['tipo_usuario'] !== 'conductor'
            || (isset($_GET['conductor_id']) && filter_var($_GET['conductor_id'], FILTER_VALIDATE_INT) !== $id)) {
            sendJsonResponse(false, 'Acceso denegado.', [], 403);
        }

        return $id;
    }
}
