<?php
/**
 * Script de prueba para crear una solicitud de viaje
 * Simula una solicitud desde la app móvil
 */

require_once __DIR__ . '/config/database.php';

echo "=== TEST: Crear Solicitud de Viaje en PostgreSQL ===\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // 1. Verificar que haya un usuario cliente disponible
    echo "1. Buscando usuario cliente...\n";
    $stmt = $db->query("SELECT id, nombre, email FROM usuarios WHERE tipo_usuario = 'cliente' LIMIT 1");
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        echo "   ❌ No hay usuarios clientes, creando uno de prueba...\n";
        // Crear usuario de prueba
        $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        
        $stmt = $db->prepare("
            INSERT INTO usuarios (uuid, nombre, apellido, email, telefono, hash_contrasena, tipo_usuario)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $uuid,
            'Cliente',
            'Test',
            'cliente.test@viax.co',
            '+573001234567',
            password_hash('test123', PASSWORD_BCRYPT),
            'cliente'
        ]);
        $usuarioId = $db->lastInsertId();
        echo "   ✅ Usuario creado con ID: $usuarioId\n";
    } else {
        $usuarioId = $usuario['id'];
        echo "   ✅ Usuario encontrado: {$usuario['nombre']} (ID: $usuarioId)\n";
    }
    
    // 2. Crear solicitud de viaje
    echo "\n2. Creando solicitud de viaje...\n";
    
    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    
    $stmt = $db->prepare("
        INSERT INTO solicitudes_servicio (
            uuid_solicitud,
            cliente_id, 
            tipo_servicio,
            latitud_recogida, 
            longitud_recogida, 
            direccion_recogida,
            latitud_destino, 
            longitud_destino, 
            direccion_destino,
            distancia_estimada,
            tiempo_estimado,
            estado,
            fecha_creacion,
            solicitado_en
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', NOW(), NOW())
    ");
    
    $stmt->execute([
        $uuid,
        $usuarioId,
        'transporte',
        6.25461830,
        -75.53955670,
        'Carrera 18B, Llanaditas, Medellín',
        6.15767810,
        -75.64338780,
        'La Estrella, Antioquia',
        22.38,
        45
    ]);
    
    $solicitudId = $db->lastInsertId();
    
    echo "   ✅ Solicitud creada exitosamente!\n";
    echo "      ID: $solicitudId\n";
    echo "      UUID: $uuid\n";
    
    // 3. Verificar que se insertó correctamente
    echo "\n3. Verificando solicitud...\n";
    $stmt = $db->prepare("
        SELECT id, uuid_solicitud, cliente_id, tipo_servicio, direccion_recogida, direccion_destino, estado, fecha_creacion
        FROM solicitudes_servicio
        WHERE id = ?
    ");
    $stmt->execute([$solicitudId]);
    $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($solicitud) {
        echo "   ✅ Solicitud verificada:\n";
        print_r($solicitud);
    } else {
        echo "   ❌ No se pudo verificar la solicitud\n";
    }
    
    // 4. Limpiar la solicitud de prueba
    echo "\n4. Limpiando solicitud de prueba...\n";
    $stmt = $db->prepare("DELETE FROM solicitudes_servicio WHERE id = ?");
    $stmt->execute([$solicitudId]);
    echo "   ✅ Solicitud eliminada\n";
    
    echo "\n=== TEST COMPLETADO EXITOSAMENTE ===\n";
    
} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
