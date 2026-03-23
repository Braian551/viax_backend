<?php
/**
 * MIGRACIÓN DE USUARIOS ACTUALES. (Soft Rollout)
 * 
 * Este script asigna 'v1.0' automáticamante a los clientes y conductores actuales 
 * en la bd, para no interrumpir violentamente sus sesiones activas.
 * Cuando viax saque 'v2.0', entonces ahí sí el guard se activará para todos.
 */
require_once __DIR__ . '/../config/app.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $roles = ['cliente', 'conductor', 'empresa'];
    $version = 'v1.0';
    $timestamp = time();
    $ipAddress = '127.0.0.1';
    $deviceId = 'system_migration';
    $secretSalt = getenv('LEGAL_HASH_SECRET') ?: 'viax_default_secret_9988';
    
    $db->beginTransaction();
    $contentHash = hash('sha256', 'Initial legacy terms Viax');
    
    // 1. Insertar el documento inicial V1.0
    $stmt = $db->prepare("INSERT INTO legal_documents (role, version, content_hash, is_active) VALUES (?, ?, ?, ?) ON CONFLICT (role, version) DO NOTHING");
    foreach ($roles as $role) {
        $stmt->execute([$role, $version, $contentHash, true]);
    }
    
    // 2. Insertar aceptación a clientes
    $stmt = $db->query("SELECT id FROM usuarios WHERE tipo_usuario = 'cliente'");
    $usuarios = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Iniciando migración masiva para " . count($usuarios) . " usuarios...\n";
    
    $stmtLog = $db->prepare("
        INSERT INTO legal_acceptance_logs 
        (user_id, role, accepted_version, document_hash, ip_address, device_id, acceptance_hash, accepted_at) 
        VALUES (?, 'cliente', ?, ?, ?, ?, ?, to_timestamp(?))
    ");
    
    $count = 0;
    foreach ($usuarios as $userId) {
        $rawData = "{$userId}|cliente|{$deviceId}|{$ipAddress}|{$version}|{$contentHash}|{$timestamp}|{$secretSalt}";
        $acceptanceHash = hash('sha256', $rawData);
        
        try {
            $stmtLog->execute([$userId, $version, $contentHash, $ipAddress, $deviceId, $acceptanceHash, $timestamp]);
            $count++;
        } catch (PDOException $e) {
           // Omitir si ya tiene
        }
    }
    
    $db->commit();
    echo "¡Migración exitosa! Aislamos a $count clientes para no bloquear sus viajes actuales.\n";
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
    die("Error: " . $e->getMessage() . "\n");
}
