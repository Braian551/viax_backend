<?php

header('Content-Type: application/json; charset=UTF-8');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/SensitiveDataCrypto.php';
require_once __DIR__ . '/../utils/FinancialAccessControl.php';

try {
    $actorUserId = intval($_GET['actor_user_id'] ?? 0);
    $resource = trim((string) ($_GET['resource'] ?? ''));
    $resourceId = intval($_GET['resource_id'] ?? 0);

    if ($actorUserId <= 0 || $resource === '') {
        throw new Exception('actor_user_id y resource son requeridos');
    }

    $database = new Database();
    $db = $database->getConnection();

    $actor = FinancialAccessControl::getActorById($db, $actorUserId);
    if (!$actor) {
        FinancialAccessControl::audit($db, $actorUserId, 'desconocido', $resource, $resourceId ?: null, false, 'actor_no_existe');
        throw new Exception('Actor no válido');
    }

    $actorRole = (string) ($actor['tipo_usuario'] ?? 'desconocido');
    $accountPlain = null;
    $allowed = false;
    $resolvedResourceId = null;

    if ($resource === 'admin_bank') {
        $stmt = $db->prepare('SELECT id, numero_cuenta FROM admin_configuracion_banco ORDER BY actualizado_en DESC NULLS LAST, id DESC LIMIT 1');
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception('No hay cuenta administrativa configurada');
        }

        $resolvedResourceId = (int) $row['id'];
        $allowed = FinancialAccessControl::canViewAdminBank($actor);
        if ($allowed) {
            $accountPlain = decryptSensitiveData($row['numero_cuenta'] ?? null);
        }
    } elseif ($resource === 'empresa_bank') {
        $empresaId = $resourceId;
        if ($empresaId <= 0) {
            throw new Exception('resource_id es requerido para empresa_bank');
        }

        $stmt = $db->prepare('SELECT empresa_id, numero_cuenta FROM empresas_configuracion WHERE empresa_id = :empresa_id LIMIT 1');
        $stmt->execute([':empresa_id' => $empresaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception('No hay cuenta empresarial configurada');
        }

        $resolvedResourceId = (int) $row['empresa_id'];
        $allowed = FinancialAccessControl::canViewEmpresaBank($actor, $empresaId);
        if ($allowed) {
            $accountPlain = decryptSensitiveData($row['numero_cuenta'] ?? null);
        }
    } else {
        throw new Exception('resource no soportado');
    }

    FinancialAccessControl::audit(
        $db,
        $actorUserId,
        $actorRole,
        $resource,
        $resolvedResourceId,
        $allowed,
        $allowed ? 'ok' : 'acceso_denegado_por_rol'
    );

    if (!$allowed) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'No autorizado para ver esta cuenta']);
        exit();
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'resource' => $resource,
            'resource_id' => $resolvedResourceId,
            'account_number' => $accountPlain,
            'account_number_masked' => maskSensitiveAccount($accountPlain),
            'expires_in_seconds' => 20,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
