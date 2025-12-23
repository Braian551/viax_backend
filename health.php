<?php
// Script simple de verificación para Railway
header('Content-Type: application/json');

try {
    $response = [
        'status' => 'ok',
        'message' => 'Backend is working',
        'php_version' => phpversion(),
        'timestamp' => date('Y-m-d H:i:s'),
        'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown'
    ];

    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>