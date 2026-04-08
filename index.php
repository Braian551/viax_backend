<?php
// index.php - Entry point for backend deployment

require_once __DIR__ . '/config/bootstrap.php';

$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$allowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Vary: Origin');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
$path = ltrim((string)parse_url($requestUri, PHP_URL_PATH), '/');

$appEnv = strtolower(trim((string)env_value('APP_ENV', 'production')));
if ($appEnv !== 'production') {
    file_put_contents('debug_path.log', "Original Path: [{$path}]\n", FILE_APPEND);
}

if (strpos($path, 'viax/backend/') === 0) {
    $path = substr($path, strlen('viax/backend/'));
    if ($appEnv !== 'production') {
        file_put_contents('debug_path.log', "Normalized Path: [{$path}]\n", FILE_APPEND);
    }
}

if ($path === '') {
    http_response_code(200);
    echo json_encode(['status' => 'healthy', 'message' => 'Viax API Backend is running']);
} elseif (strpos($path, 'user/') === 0) {
    $endpoint = preg_replace('/\.php$/', '', substr($path, 5));
    require_once __DIR__ . '/user/' . $endpoint . '.php';
} elseif (strpos($path, 'conductor/') === 0) {
    $endpoint = preg_replace('/\.php$/', '', substr($path, 10));
    if (strpos($endpoint, 'conductor/') === 0) {
        $endpoint = substr($endpoint, 10);
    }
    require_once __DIR__ . '/conductor/' . $endpoint . '.php';
} elseif (strpos($path, 'admin/') === 0) {
    $endpoint = preg_replace('/\.php$/', '', substr($path, 6));
    require_once __DIR__ . '/admin/' . $endpoint . '.php';
} elseif (strpos($path, 'auth/') === 0) {
    $endpoint = preg_replace('/\.php$/', '', substr($path, 5));
    require_once __DIR__ . '/auth/' . $endpoint . '.php';
} elseif (strpos($path, 'account/') === 0) {
    $endpoint = preg_replace('/\.php$/', '', substr($path, 8));
    require_once __DIR__ . '/account/' . $endpoint . '.php';
} elseif (strpos($path, 'notifications/') === 0) {
    $endpoint = preg_replace('/\.php$/', '', substr($path, 14));
    require_once __DIR__ . '/notifications/' . $endpoint . '.php';
} elseif (strpos($path, 'pricing/') === 0) {
    $endpoint = preg_replace('/\.php$/', '', substr($path, 8));
    require_once __DIR__ . '/pricing/' . $endpoint . '.php';
} elseif (strpos($path, 'legal/') === 0) {
    $endpoint = preg_replace('/\.php$/', '', substr($path, 6));
    require_once __DIR__ . '/legal/' . $endpoint . '.php';
} elseif (strpos($path, 'location_sharing/') === 0) {
    $endpoint = preg_replace('/\.php$/', '', substr($path, 17));
    require_once __DIR__ . '/location_sharing/' . $endpoint . '.php';
} elseif (
    $path === 'get_api_keys' ||
    $path === 'get_api_keys.php' ||
    $path === 'config/api_keys' ||
    $path === 'config/api_keys.php'
) {
    require_once __DIR__ . '/get_api_keys.php';
} elseif (strpos($path, 'utils/') !== false) {
    $parts = explode('/', $path);
    $endpoint = end($parts);
    require_once __DIR__ . '/utils/' . $endpoint;
    exit;
} elseif ($path === 'verify_system') {
    require_once __DIR__ . '/verify_system.php';
} elseif ($path === 'health') {
    require_once __DIR__ . '/health.php';
} elseif ($path === 'check_phpmailer') {
    require_once __DIR__ . '/check_phpmailer.php';
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Endpoint not found', 'path' => $path]);
}
