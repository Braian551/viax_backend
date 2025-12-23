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

    // Ajustado a códigos de 4 dígitos (antes 6)
    // Ajustado a códigos de 4 dígitos (antes 6)
    if (!$email || strlen($code) !== 4 || empty($userName)) {
        sendJsonResponse(false, 'Datos incompletos o invalidos (se esperan 4 dígitos)');
    }

    // Verificar que las dependencias estén disponibles
    $vendorPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($vendorPath)) {
        throw new Exception("Dependencias no encontradas. Vendor path: $vendorPath");
    }

    require $vendorPath;

    // Verificar que PHPMailer esté disponible
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        throw new Exception("PHPMailer no está disponible");
    }

    // MODO PRUEBAS: Simular envío exitoso para testing
    // En producción, descomenta las líneas de PHPMailer abajo
    error_log("Email simulation - To: $email, Code: $code, User: $userName");
    sendJsonResponse(true, 'Codigo enviado correctamente (modo pruebas)');

    /*
    // CONFIGURACIÓN REAL PARA PRODUCCIÓN:
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'braianoquendurango@gmail.com';
    $mail->Password = 'nvok ghfu usmp apmc';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    $mail->setFrom('braianoquendurango@gmail.com', 'Viax');
    $mail->addAddress($email, $userName);
    $mail->isHTML(true);
    $mail->Subject = 'Tu codigo de verificacion Viax';
    $mail->Body = "<h2>Hola $userName,</h2><p>Tu codigo de verificacion para Viax es:</p><h1 style='color: #39FF14; font-size: 32px; text-align: center;'>$code</h1><p>Este codigo expirara en 10 minutos.</p><p>Saludos,<br>El equipo de Viax</p>";
    $mail->AltBody = "Hola $userName,\n\nTu codigo de verificacion para Viax es: $code\n\nEste codigo expirara en 10 minutos.\n\nSaludos,\nEl equipo de Viax";

    if ($mail->send()) {
        sendJsonResponse(true, 'Codigo enviado correctamente');
    } else {
        throw new Exception("Error al enviar email: " . $mail->ErrorInfo);
    }
    */

} catch (Exception $e) {
    error_log("Email service error: " . $e->getMessage());
    http_response_code(500);
    sendJsonResponse(false, 'Error: ' . $e->getMessage());
}