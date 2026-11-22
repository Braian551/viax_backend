<?php

require_once __DIR__ . '/../services/LegalAcceptance.php';

class LegalMiddleware
{
    public static function checkLegalAcceptance(int $userId, string $role): void
    {
        try {
            $role = LegalAcceptance::role($role);
            if ($role === null) {
                sendJsonResponse(false, 'Rol inválido.', [], 400);
            }
            $db = (new Database())->getConnection();
            $document = LegalAcceptance::current($db, $role);
            if ($document['enforced'] && !LegalAcceptance::status($db, $userId, $role, $document)['accepted_current_version']) {
                http_response_code(403);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'error' => 'LEGAL_VERSION_EXPIRED',
                    'message' => 'Acepta la versión vigente de nuestros términos y políticas.',
                    'requiresUpdate' => true,
                ]);
                exit;
            }
        } catch (Throwable $error) {
            error_log('VIAX legal enforcement: ' . get_class($error));
            sendJsonResponse(false, 'No pudimos validar el estado legal.', [], 503);
        }
    }
}
