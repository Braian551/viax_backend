<?php

header('Content-Type: application/json');
header('Cache-Control: no-store');

$checks = ['postgresql' => false, 'redis' => false];
try {
    require_once __DIR__ . '/config/app.php';
    $checks['postgresql'] = (int)(new Database())->getConnection()->query('SELECT 1')->fetchColumn() === 1;
} catch (Throwable $error) {
    error_log('VIAX PostgreSQL health check failed: ' . get_class($error));
}

try {
    $checks['redis'] = Cache::redis()?->ping() === true;
} catch (Throwable $error) {
    error_log('VIAX Redis health check failed: ' . get_class($error));
}

$healthy = !in_array(false, $checks, true);
http_response_code($healthy ? 200 : 503);
// Older app builds call this exact alias; it exposes only a database status.
if (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) === '/api/scripts/verify_system_json.php') {
    echo json_encode([
        'status' => $healthy ? 'success' : 'error',
        'database' => $checks['postgresql'] ? 'connected' : 'unavailable',
    ]);
    return;
}
echo json_encode([
    'status' => $healthy ? 'ok' : 'error',
    'checks' => $checks,
]);
