<?php
// index.php - Entry point for Render deployment
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Get the request URI
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// Remove leading slash
$path = ltrim($path, '/');

// Route to appropriate API endpoints
if ($path === '') {
    // Health check for root path
    http_response_code(200);
    echo json_encode(['status' => 'healthy', 'message' => 'Viax API Backend is running']);
} elseif (strpos($path, 'user/') === 0) {
    $endpoint = substr($path, 5); // Remove 'user/'
    require_once __DIR__ . '/user/' . $endpoint . '.php';
} elseif (strpos($path, 'conductor/') === 0) {
    $endpoint = substr($path, 10); // Remove 'conductor/'
    require_once __DIR__ . '/conductor/' . $endpoint . '.php';
} elseif (strpos($path, 'admin/') === 0) {
    $endpoint = substr($path, 6); // Remove 'admin/'
    require_once __DIR__ . '/admin/' . $endpoint . '.php';
} elseif (strpos($path, 'auth/') === 0) {
    $endpoint = substr($path, 5); // Remove 'auth/'
    require_once __DIR__ . '/auth/' . $endpoint . '.php';
} elseif (strpos($path, 'pricing/') === 0) {
    $endpoint = substr($path, 8); // Remove 'pricing/'
    require_once __DIR__ . '/pricing/' . $endpoint . '.php';
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
?>