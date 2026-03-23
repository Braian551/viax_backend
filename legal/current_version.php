<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/app.php';

try {
    $role = $_GET['role'] ?? 'cliente';
    if ($role === 'admin') {
        $role = 'administrador';
    }
    
    // Validate rol
    if (!in_array($role, ['cliente', 'conductor', 'empresa', 'soporte_tecnico', 'administrador'], true)) {
        throw new Exception('Invalid role specified');
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    $stmt = $db->prepare("SELECT version, content_hash FROM legal_documents WHERE role = ? AND is_active = true ORDER BY published_at DESC LIMIT 1");
    $stmt->execute([$role]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($doc) {
        echo json_encode([
            'success' => true,
            'role' => $role,
            'current_version' => $doc['version'],
            'content_hash' => $doc['content_hash']
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'role' => $role,
            'current_version' => 'v1.0',
            'content_hash' => ''
        ]);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
