<?php
// Test script para debuggear login
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');

require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo json_encode([
        'step' => 'db_connected',
        'success' => true
    ]);
    echo "\n";
    
    // Verificar tabla user_devices
    try {
        $result = $db->query('SELECT COUNT(*) as cnt FROM user_devices');
        $count = $result->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'step' => 'user_devices_table',
            'exists' => true,
            'count' => $count['cnt']
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'step' => 'user_devices_table',
            'exists' => false,
            'error' => $e->getMessage()
        ]);
    }
    echo "\n";
    
    // Test con un usuario
    $testEmail = 'test@test.com'; // Cambiar por un email real
    $stmt = $db->prepare('SELECT id, email, hash_contrasena FROM usuarios LIMIT 1');
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo json_encode([
            'step' => 'user_found',
            'user_id' => $user['id'],
            'email' => $user['email'],
            'has_password_hash' => !empty($user['hash_contrasena']),
            'hash_length' => strlen($user['hash_contrasena'] ?? '')
        ]);
    } else {
        echo json_encode([
            'step' => 'no_users_in_db'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
?>
