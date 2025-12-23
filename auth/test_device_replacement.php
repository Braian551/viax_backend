<?php
/**
 * Script de prueba para verificar la lógica de reemplazo de dispositivos
 * Simula múltiples logins desde diferentes dispositivos para un usuario
 */

require_once '../config/config.php';

echo "=== TEST: Sistema de Reemplazo de Dispositivo Único ===\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Usuario de prueba
    $testEmail = 'test_device@viax.com';
    
    // Buscar o crear usuario de prueba
    $stmt = $db->prepare('SELECT id FROM usuarios WHERE email = ? LIMIT 1');
    $stmt->execute([$testEmail]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "⚠ Usuario de prueba no encontrado. Creando...\n";
        $uuid = uniqid('test_', true);
        $password = password_hash('test123', PASSWORD_DEFAULT);
        $ins = $db->prepare('INSERT INTO usuarios (uuid, nombre, apellido, email, telefono, hash_contrasena, tipo_usuario) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([$uuid, 'Test', 'Device', $testEmail, '9999999999', $password, 'cliente']);
        $userId = $db->lastInsertId();
        echo "✓ Usuario de prueba creado: ID = $userId\n\n";
    } else {
        $userId = $user['id'];
        echo "✓ Usuario encontrado: ID = $userId, Email = $testEmail\n\n";
    }
    
    // Asegurar tabla existe
    try {
        $db->query("SELECT 1 FROM user_devices LIMIT 1");
    } catch (Exception $e) {
        echo "❌ Tabla user_devices no existe. Ejecuta las migraciones primero.\n";
        exit(1);
    }
    
    // Limpiar dispositivos previos del usuario de prueba
    $db->prepare('DELETE FROM user_devices WHERE user_id = ?')->execute([$userId]);
    echo "✓ Dispositivos previos limpiados\n\n";
    
    // Simular dispositivos
    $device1 = 'test-device-uuid-111';
    $device2 = 'test-device-uuid-222';
    $device3 = 'test-device-uuid-333';
    
    // === ESCENARIO 1: Login desde Dispositivo 1 ===
    echo "--- ESCENARIO 1: Login desde Dispositivo 1 ---\n";
    
    // Insertar dispositivo 1
    $ins = $db->prepare('INSERT INTO user_devices (user_id, device_uuid, trusted) VALUES (?, ?, 0)');
    $ins->execute([$userId, $device1]);
    $dev1Id = $db->lastInsertId();
    
    // Simular login exitoso (invalidar otros + marcar este como confiable)
    $db->prepare('UPDATE user_devices SET trusted = 0 WHERE user_id = ?')->execute([$userId]);
    $db->prepare('UPDATE user_devices SET trusted = 1, fail_attempts = 0, last_seen = NOW() WHERE id = ?')->execute([$dev1Id]);
    
    // Verificar estado
    $check = $db->prepare('SELECT device_uuid, trusted, fail_attempts FROM user_devices WHERE user_id = ? ORDER BY device_uuid');
    $check->execute([$userId]);
    $devices = $check->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Dispositivos después del login:\n";
    foreach ($devices as $d) {
        $status = $d['trusted'] == 1 ? '✓ CONFIABLE' : '✗ No confiable';
        echo "  - {$d['device_uuid']}: $status (intentos: {$d['fail_attempts']})\n";
    }
    echo "\n";
    
    // === ESCENARIO 2: Login desde Dispositivo 2 ===
    echo "--- ESCENARIO 2: Login desde Dispositivo 2 (debe invalidar Dispositivo 1) ---\n";
    
    // Insertar dispositivo 2
    $ins = $db->prepare('INSERT INTO user_devices (user_id, device_uuid, trusted) VALUES (?, ?, 0)');
    $ins->execute([$userId, $device2]);
    $dev2Id = $db->lastInsertId();
    
    // Simular login exitoso
    $db->prepare('UPDATE user_devices SET trusted = 0 WHERE user_id = ?')->execute([$userId]);
    $db->prepare('UPDATE user_devices SET trusted = 1, fail_attempts = 0, last_seen = NOW() WHERE id = ?')->execute([$dev2Id]);
    
    // Verificar estado
    $check->execute([$userId]);
    $devices = $check->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Dispositivos después del login:\n";
    foreach ($devices as $d) {
        $status = $d['trusted'] == 1 ? '✓ CONFIABLE' : '✗ No confiable';
        echo "  - {$d['device_uuid']}: $status (intentos: {$d['fail_attempts']})\n";
    }
    echo "\n";
    
    // === ESCENARIO 3: Intentar acceder desde Dispositivo 1 nuevamente ===
    echo "--- ESCENARIO 3: Volver a Dispositivo 1 (debe detectarse como no confiable) ---\n";
    
    // Simular check_device.php para dispositivo 1
    $chk = $db->prepare('SELECT id, trusted, fail_attempts, locked_until FROM user_devices WHERE user_id = ? AND device_uuid = ? LIMIT 1');
    $chk->execute([$userId, $device1]);
    $dev1Check = $chk->fetch(PDO::FETCH_ASSOC);
    
    if ($dev1Check['trusted'] == 1) {
        echo "❌ ERROR: Dispositivo 1 todavía es confiable (debería ser NO confiable)\n";
    } else {
        echo "✓ Correcto: Dispositivo 1 ya NO es confiable\n";
        echo "  → El usuario necesitará verificación por código para usarlo nuevamente\n";
    }
    echo "\n";
    
    // === ESCENARIO 4: Login desde Dispositivo 3 ===
    echo "--- ESCENARIO 4: Login desde Dispositivo 3 (debe invalidar Dispositivo 2) ---\n";
    
    // Insertar dispositivo 3
    $ins = $db->prepare('INSERT INTO user_devices (user_id, device_uuid, trusted) VALUES (?, ?, 0)');
    $ins->execute([$userId, $device3]);
    $dev3Id = $db->lastInsertId();
    
    // Simular login exitoso
    $db->prepare('UPDATE user_devices SET trusted = 0 WHERE user_id = ?')->execute([$userId]);
    $db->prepare('UPDATE user_devices SET trusted = 1, fail_attempts = 0, last_seen = NOW() WHERE id = ?')->execute([$dev3Id]);
    
    // Verificar estado final
    $check->execute([$userId]);
    $devices = $check->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Estado FINAL de todos los dispositivos:\n";
    foreach ($devices as $d) {
        $status = $d['trusted'] == 1 ? '✓ CONFIABLE' : '✗ No confiable';
        echo "  - {$d['device_uuid']}: $status (intentos: {$d['fail_attempts']})\n";
    }
    
    // Contar dispositivos confiables
    $trusted = array_filter($devices, fn($d) => $d['trusted'] == 1);
    $trustedCount = count($trusted);
    
    echo "\n";
    if ($trustedCount === 1) {
        echo "✓✓✓ TEST EXITOSO: Solo hay 1 dispositivo confiable (el último usado)\n";
    } else {
        echo "❌ ERROR: Hay $trustedCount dispositivos confiables (debería ser solo 1)\n";
    }
    
    // Limpiar
    echo "\n--- Limpieza ---\n";
    $db->prepare('DELETE FROM user_devices WHERE user_id = ?')->execute([$userId]);
    echo "✓ Dispositivos de prueba eliminados\n";
    
    echo "\n=== TEST COMPLETADO ===\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
