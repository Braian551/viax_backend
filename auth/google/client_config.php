<?php
/**
 * Endpoint para obtener la configuración pública de Google OAuth
 * Solo expone los client IDs públicos necesarios para la app
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

// Cargar configuración
$googleConfig = require_once __DIR__ . '/../../config/google_oauth.php';

// Solo exponer información pública necesaria para la app
$publicConfig = [
    'success' => true,
    'config' => [
        'web_client_id' => $googleConfig['web']['client_id'],
        'mobile_client_id' => $googleConfig['mobile']['client_id'],
        'project_id' => $googleConfig['mobile']['project_id']
    ]
];

echo json_encode($publicConfig);
