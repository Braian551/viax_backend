<?php

require_once __DIR__ . '/../middleware/SessionAccess.php';

header('Content-Type: application/json; charset=UTF-8');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$route = is_string($path) ? preg_replace('/\.php$/D', '', substr($path, 5)) : '';
$reads = [
    'empresa/profile', 'empresa/settings',
    'company/dashboard_stats', 'company/conductores_documentos', 'company/platform_debt_context',
    'company/get_debtors', 'company/debt_payment_reports', 'company/reports', 'company/reports_pdf',
    'company/pricing', 'company/get_balance', 'company/drivers',
];

if (!str_starts_with((string)$path, '/api/') || !in_array($route, $reads, true)) {
    sendJsonResponse(false, 'Ruta no encontrada.', [], 404);
}
if (Request::method() !== 'GET') {
    header('Allow: GET');
    sendJsonResponse(false, 'Método no permitido.', [], 405);
}

$db = (new Database())->getConnection();
$user = SessionAccess::requireUser($db);
$companyId = (int)($user['empresa_id'] ?? 0);
if ($user['tipo_usuario'] !== 'empresa' || $companyId < 1) {
    sendJsonResponse(false, 'Acceso denegado.', [], 403);
}
foreach (['empresa_id', 'id'] as $key) {
    if (isset($_GET[$key]) && filter_var($_GET[$key], FILTER_VALIDATE_INT) !== $companyId) {
        sendJsonResponse(false, 'Acceso denegado.', [], 403);
    }
}

$_GET['empresa_id'] = (string)$companyId;
$_GET['user_id'] = (string)$user['id'];
$_REQUEST = $_GET;
if ($route === 'empresa/settings') {
    $_GET['action'] = $_REQUEST['action'] = 'get_settings';
}

$target = dirname(__DIR__) . '/' . $route . '.php';
chdir(dirname($target));
require $target;

