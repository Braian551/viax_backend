<?php
header('Content-Type: application/json');

try {
    // Verificar si la función mail() está disponible
    if (!function_exists('mail')) {
        echo json_encode(['success' => false, 'message' => 'Funcion mail() no disponible']);
        exit;
    }

    // Intentar enviar un email de prueba
    $result = mail('test@example.com', 'Test', 'Test message', 'From: test@test.com');
    
    echo json_encode([
        'success' => true, 
        'message' => 'Funcion mail() disponible',
        'mail_result' => $result ? 'true' : 'false'
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>