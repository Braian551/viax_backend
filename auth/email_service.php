<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE, PUT');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

function getJsonInput() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'JSON invalido']);
        exit;
    }
    return $input;
}

function sendJsonResponse($success, $message, $data = []) {
    $response = ['success' => $success, 'message' => $message];
    if (!empty($data)) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    exit;
}

try {
    $input = getJsonInput();
    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $code = $input['code'] ?? '';
    $userName = $input['userName'] ?? '';
    $type = $input['type'] ?? 'verification'; // 'verification' o 'password_recovery'

    // Validar datos de entrada
    if (!$email || strlen($code) !== 4 || empty($userName)) {
        sendJsonResponse(false, 'Datos incompletos o invalidos (se esperan 4 dígitos)');
    }

    // Usar el componente Mailer reutilizable
    require_once __DIR__ . '/../utils/Mailer.php';

    // Enviar según el tipo
    if ($type === 'password_recovery') {
        $enviado = Mailer::sendPasswordRecoveryCode($email, $userName, $code);
    } else {
        $enviado = Mailer::sendVerificationCode($email, $userName, $code);
    }

    if ($enviado) {
        sendJsonResponse(true, 'Correo enviado correctamente');
    } else {
        throw new Exception("El servicio de correo falló al enviar.");
    }

} catch (Exception $e) {
    error_log("Email service error: " . $e->getMessage());
    http_response_code(500);
    sendJsonResponse(false, 'Error: ' . $e->getMessage());
}