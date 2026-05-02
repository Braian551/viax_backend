<?php
header('Content-Type: application/json');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'database' => 'disconnected',
        'message' => 'MÃ©todo no permitido',
    ]);
    exit();
}

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        throw new Exception('No se pudo establecer conexiÃ³n con base de datos');
    }

    $healthStmt = $db->query('SELECT 1');
    if ($healthStmt === false) {
        throw new Exception('La base de datos no respondiÃ³ correctamente');
    }

    echo json_encode([
        'status' => 'success',
        'database' => 'connected',
        'message' => 'Sistema operativo',
        'timestamp' => gmdate('c'),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'database' => 'disconnected',
        'message' => $e->getMessage(),
        'timestamp' => gmdate('c'),
    ]);
}
?>
