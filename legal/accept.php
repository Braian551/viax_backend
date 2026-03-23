<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/app.php';

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    $required = ['user_id', 'role', 'version', 'device_id'];
    foreach ($required as $field) {
        if (empty($data[$field])) throw new Exception("Required field: $field");
    }
    
    $userId = (int) $data['user_id'];
    $role = $data['role'];
    if ($role === 'admin') {
        $role = 'administrador';
    }
    $version = $data['version'];
    $deviceId = $data['device_id'];
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $timestamp = time();
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Validar version
    $stmt = $db->prepare("SELECT content_hash FROM legal_documents WHERE role = ? AND version = ? AND is_active = true");
    $stmt->execute([$role, $version]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    $contentHash = null;
    if ($doc && !empty($doc['content_hash'])) {
        $contentHash = (string)$doc['content_hash'];
    } else {
        // Compatibilidad: si no hay documento activo cargado en DB, permitimos
        // aceptación de la versión enviada para no bloquear onboarding.
        $stmtRoleHasDocs = $db->prepare("SELECT COUNT(*) FROM legal_documents WHERE role = ? AND is_active = true");
        $stmtRoleHasDocs->execute([$role]);
        $activeDocsCount = (int)$stmtRoleHasDocs->fetchColumn();

        if ($activeDocsCount > 0) {
            throw new Exception("Versión legal inválida o inactiva.");
        }

        $contentHash = hash('sha256', "fallback_legal|{$role}|{$version}");
    }
    
    // SHA-256 inmutable
    $secretSalt = getenv('LEGAL_HASH_SECRET') ?: 'viax_default_secret_9988';
    $rawData = "{$userId}|{$role}|{$deviceId}|{$ipAddress}|{$version}|{$contentHash}|{$timestamp}|{$secretSalt}";
    $acceptanceHash = hash('sha256', $rawData);
    
    $stmt = $db->prepare("
        INSERT INTO legal_acceptance_logs 
        (user_id, role, accepted_version, document_hash, ip_address, device_id, acceptance_hash, accepted_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, to_timestamp(?))
    ");
    $stmt->execute([$userId, $role, $version, $contentHash, $ipAddress, $deviceId, $acceptanceHash, $timestamp]);
    
    if (class_exists('Cache')) {
        $redis = Cache::redis();
        if ($redis) {
            $redis->set("legal_accepted:{$role}:{$userId}", $version);
        }
    }
    
    echo json_encode([
        'success' => true,
        'accepted' => true,
        'acceptance_hash' => $acceptanceHash
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
