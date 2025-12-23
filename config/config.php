<?php
// backend/config/config.php

// Habilitar reporte de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Headers para CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE, PUT');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Manejar preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Incluir la conexión a la base de datos
require_once 'database.php';

// Función para obtener el input JSON
function getJsonInput() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'JSON inválido']);
        exit;
    }
    
    return $input;
}

// Función para enviar respuestas JSON
function sendJsonResponse($success, $message, $data = []) {
    $response = ['success' => $success, 'message' => $message];
    
    if (!empty($data)) {
        $response['data'] = $data;
    }
    
    echo json_encode($response);
    exit;
}
?>