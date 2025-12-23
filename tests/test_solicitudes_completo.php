<?php
/**
 * Script de prueba completo para el flujo de solicitudes
 * 1. Crea un usuario de prueba si no existe
 * 2. Crea un conductor de prueba si no existe
 * 3. Inserta una solicitud de prueba
 * 4. Consulta solicitudes pendientes desde el punto de vista del conductor
 */

require_once '../config/database.php';

echo "==========================================================\n";
echo "🧪 TEST COMPLETO DE SOLICITUDES DE VIAJE\n";
echo "==========================================================\n\n";

$database = new Database();
$db = $database->getConnection();

try {
    // ==========================================
    // PASO 1: Verificar/Crear usuario de prueba
    // ==========================================
    echo "📝 PASO 1: Verificando usuario de prueba...\n";
    
    $stmt = $db->prepare("SELECT id, nombre FROM usuarios WHERE email = ?");
    $stmt->execute(['usuario.prueba@test.com']);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        echo "   ➡️ Creando usuario de prueba...\n";
        
        // Generar UUID
        $uuidUsuario = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        
        $stmt = $db->prepare("
            INSERT INTO usuarios (uuid, nombre, apellido, email, telefono, hash_contrasena, tipo_usuario, es_activo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $uuidUsuario,
            'Usuario', 
            'Prueba', 
            'usuario.prueba@test.com',
            '+573001234567',
            password_hash('password123', PASSWORD_BCRYPT),
            'cliente',
            1
        ]);
        $usuarioId = $db->lastInsertId();
        echo "   ✅ Usuario creado con ID: $usuarioId\n";
    } else {
        $usuarioId = $usuario['id'];
        echo "   ✅ Usuario encontrado: {$usuario['nombre']} (ID: $usuarioId)\n";
    }
    
    // ==========================================
    // PASO 2: Verificar/Crear conductor de prueba
    // ==========================================
    echo "\n📝 PASO 2: Verificando conductor de prueba...\n";
    
    $stmt = $db->prepare("SELECT id, nombre FROM usuarios WHERE email = ?");
    $stmt->execute(['conductor.prueba@test.com']);
    $conductor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$conductor) {
        echo "   ➡️ Creando conductor de prueba...\n";
        
        // Generar UUID
        $uuidConductor = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        
        $stmt = $db->prepare("
            INSERT INTO usuarios (uuid, nombre, apellido, email, telefono, hash_contrasena, tipo_usuario, es_activo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $uuidConductor,
            'Conductor', 
            'Prueba', 
            'conductor.prueba@test.com',
            '+573009876543',
            password_hash('password123', PASSWORD_BCRYPT),
            'conductor',
            1
        ]);
        $conductorId = $db->lastInsertId();
        
        // Crear detalles del conductor
        $stmt = $db->prepare("
            INSERT INTO detalles_conductor (
                usuario_id, 
                vehiculo_tipo, 
                vehiculo_marca, 
                vehiculo_modelo,
                vehiculo_placa,
                vehiculo_color,
                vehiculo_anio,
                licencia_conduccion,
                licencia_vencimiento,
                estado_verificacion,
                disponible,
                latitud_actual,
                longitud_actual
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $conductorId,
            'moto',
            'Honda',
            'CBR 250',
            'ABC123',
            'Rojo',
            2020,
            'LIC123456',
            '2026-12-31', // Fecha de vencimiento
            'aprobado',
            1,
            4.6097, // Bogotá - Cerca de Calle 100
            -74.0817
        ]);
        
        echo "   ✅ Conductor creado con ID: $conductorId\n";
    } else {
        $conductorId = $conductor['id'];
        echo "   ✅ Conductor encontrado: {$conductor['nombre']} (ID: $conductorId)\n";
        
        // Actualizar ubicación y disponibilidad del conductor
        $stmt = $db->prepare("
            UPDATE detalles_conductor 
            SET latitud_actual = ?, 
                longitud_actual = ?, 
                disponible = 1,
                ultima_actualizacion = NOW()
            WHERE usuario_id = ?
        ");
        $stmt->execute([4.6097, -74.0817, $conductorId]);
        echo "   ✅ Ubicación actualizada: Lat 4.6097, Lng -74.0817\n";
    }
    
    // ==========================================
    // PASO 3: Limpiar solicitudes antiguas de prueba
    // ==========================================
    echo "\n📝 PASO 3: Limpiando solicitudes antiguas...\n";
    
    $stmt = $db->prepare("
        DELETE FROM solicitudes_servicio 
        WHERE cliente_id = ? 
        AND estado = 'pendiente'
        AND fecha_creacion < DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute([$usuarioId]);
    $eliminadas = $stmt->rowCount();
    echo "   ✅ Eliminadas $eliminadas solicitudes antiguas\n";
    
    // ==========================================
    // PASO 4: Crear solicitud de prueba
    // ==========================================
    echo "\n📝 PASO 4: Creando solicitud de prueba...\n";
    
    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    
    // Coordenadas cercanas al conductor (aproximadamente 1-2 km de distancia)
    $latitudOrigen = 4.6150;
    $longitudOrigen = -74.0850;
    $latitudDestino = 4.6250;
    $longitudDestino = -74.0950;
    
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
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    
    $stmt->execute([
        $uuid,
        $usuarioId,
        'transporte',
        $latitudOrigen,
        $longitudOrigen,
        'Calle 100 #15-20, Bogotá (Origen de Prueba)',
        $latitudDestino,
        $longitudDestino,
        'Calle 72 #10-30, Bogotá (Destino de Prueba)',
        5.5, // km
        18,  // minutos
        'pendiente'
    ]);
    
    $solicitudId = $db->lastInsertId();
    
    echo "   ✅ Solicitud creada exitosamente!\n";
    echo "   🆔 ID: $solicitudId\n";
    echo "   🔑 UUID: $uuid\n";
    echo "   👤 Cliente: Usuario Prueba (ID: $usuarioId)\n";
    echo "   📍 Origen: Lat $latitudOrigen, Lng $longitudOrigen\n";
    echo "   📍 Destino: Lat $latitudDestino, Lng $longitudDestino\n";
    echo "   📏 Distancia: 5.5 km\n";
    echo "   ⏱️  Tiempo: 18 min\n";
    
    // ==========================================
    // PASO 5: Consultar solicitudes como conductor
    // ==========================================
    echo "\n📝 PASO 5: Consultando solicitudes pendientes...\n";
    
    $latConductor = 4.6097;
    $lngConductor = -74.0817;
    $radioKm = 10.0;
    
    $stmt = $db->prepare("
        SELECT 
            s.id,
            s.cliente_id,
            s.uuid_solicitud,
            s.latitud_recogida,
            s.longitud_recogida,
            s.direccion_recogida,
            s.latitud_destino,
            s.longitud_destino,
            s.direccion_destino,
            s.tipo_servicio,
            s.distancia_estimada,
            s.tiempo_estimado,
            s.estado,
            COALESCE(s.solicitado_en, s.fecha_creacion) as fecha_solicitud,
            u.nombre as nombre_usuario,
            u.telefono as telefono_usuario,
            (6371 * acos(
                cos(radians(?)) * cos(radians(s.latitud_recogida)) *
                cos(radians(s.longitud_recogida) - radians(?)) +
                sin(radians(?)) * sin(radians(s.latitud_recogida))
            )) AS distancia_conductor_origen
        FROM solicitudes_servicio s
        INNER JOIN usuarios u ON s.cliente_id = u.id
        WHERE s.estado = 'pendiente'
        AND s.tipo_servicio = 'transporte'
        AND COALESCE(s.solicitado_en, s.fecha_creacion) >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        HAVING distancia_conductor_origen <= ?
        ORDER BY distancia_conductor_origen ASC
    ");
    
    $stmt->execute([
        $latConductor,
        $lngConductor,
        $latConductor,
        $radioKm
    ]);
    
    $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "   📊 Parámetros de búsqueda:\n";
    echo "      - Conductor: Lat $latConductor, Lng $lngConductor\n";
    echo "      - Radio: $radioKm km\n";
    echo "\n   ✅ Solicitudes encontradas: " . count($solicitudes) . "\n\n";
    
    if (count($solicitudes) > 0) {
        foreach ($solicitudes as $index => $sol) {
            echo "   📋 Solicitud #" . ($index + 1) . ":\n";
            echo "      🆔 ID: {$sol['id']}\n";
            echo "      🔑 UUID: {$sol['uuid_solicitud']}\n";
            echo "      👤 Cliente: {$sol['nombre_usuario']} (ID: {$sol['cliente_id']})\n";
            echo "      📞 Teléfono: {$sol['telefono_usuario']}\n";
            echo "      📍 Origen: {$sol['direccion_recogida']}\n";
            echo "      📍 Destino: {$sol['direccion_destino']}\n";
            echo "      📏 Distancia del viaje: {$sol['distancia_estimada']} km\n";
            echo "      🚗 Distancia conductor-origen: " . round($sol['distancia_conductor_origen'], 2) . " km\n";
            echo "      ⏱️  Tiempo estimado: {$sol['tiempo_estimado']} min\n";
            echo "      📅 Solicitado: {$sol['fecha_solicitud']}\n";
            echo "      ✅ Estado: {$sol['estado']}\n\n";
        }
    } else {
        echo "   ⚠️  No se encontraron solicitudes en el radio especificado\n";
        echo "   💡 Verificando si hay solicitudes en la BD sin filtro de distancia...\n\n";
        
        // Verificar todas las solicitudes pendientes
        $stmt = $db->prepare("
            SELECT COUNT(*) as total 
            FROM solicitudes_servicio 
            WHERE estado = 'pendiente'
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "      📊 Total solicitudes pendientes en BD: {$result['total']}\n";
        
        // Verificar solicitudes recientes
        $stmt = $db->prepare("
            SELECT COUNT(*) as total 
            FROM solicitudes_servicio 
            WHERE estado = 'pendiente'
            AND COALESCE(solicitado_en, fecha_creacion) >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "      📊 Solicitudes pendientes (últimos 15 min): {$result['total']}\n";
    }
    
    // ==========================================
    // RESUMEN FINAL
    // ==========================================
    echo "\n==========================================================\n";
    echo "✅ TEST COMPLETADO EXITOSAMENTE\n";
    echo "==========================================================\n";
    echo "📊 RESUMEN:\n";
    echo "   - Usuario ID: $usuarioId\n";
    echo "   - Conductor ID: $conductorId\n";
    echo "   - Solicitud creada ID: $solicitudId\n";
    echo "   - Solicitudes encontradas: " . count($solicitudes) . "\n";
    echo "==========================================================\n";
    
} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "📍 En: " . $e->getFile() . " línea " . $e->getLine() . "\n";
}
