<?php
/**
 * Script para poblar datos dummy para la empresa Elite
 * Uso: php seed_elite_data.php
 */

require_once __DIR__ . '/../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    echo "🚀 Iniciando seeding para Elite...\n";

    // 1. Obtener ID de Elite
    $stmt = $conn->prepare("SELECT id FROM empresas_transporte WHERE nombre LIKE 'Elite%' LIMIT 1");
    $stmt->execute();
    $elite = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$elite) {
        throw new Exception("No se encontró la empresa Elite.");
    }

    $empresaId = $elite['id'];
    echo "✅ Empresa Elite encontrada (ID: $empresaId)\n";

    // 2. Crear Conductores Dummy
    $nombres = ['Carlos', 'Juan', 'Andres', 'Felipe', 'Luis'];
    $apellidos = ['Perez', 'Gomez', 'Rodriguez', 'Lopez', 'Martinez'];
    $conductorIds = [];

    echo "Creating drivers...\n";
    foreach ($nombres as $i => $nombre) {
        // Deterministic email
        $email = strtolower($nombre) . "." . strtolower($apellidos[$i]) . "@elite-test.com";

        $uid = null;

        // Check if exists
        $check = $conn->prepare("SELECT id FROM usuarios WHERE email = ?");
        $check->execute([$email]);
        if ($row = $check->fetch()) {
            $uid = $row['id'];
            echo "   User $email exists (ID: $uid)\n";
        } else {
            // Insert usuario
            $uuid = uniqid('user_', true);
            $stmt = $conn->prepare("
                INSERT INTO usuarios (uuid, nombre, apellido, email, telefono, hash_contrasena, tipo_usuario, empresa_id, es_activo)
                VALUES (?, ?, ?, ?, ?, 'dummy_hash', 'conductor', ?, 1)
            ");
            $phone = '300' . sprintf('%07d', $i * 123456); // Deterministic phone too
            $stmt->execute([$uuid, $nombre, $apellidos[$i], $email, $phone, $empresaId]);
            $uid = $conn->lastInsertId();
            echo "   Created user $email (ID: $uid)\n";
        }
        
        $conductorIds[] = $uid;

        // Check if detalles_conductor exists
        $checkDet = $conn->prepare("SELECT usuario_id FROM detalles_conductor WHERE usuario_id = ?");
        $checkDet->execute([$uid]);
        if (!$checkDet->fetch()) {
            // Insert detalles_conductor
            $placa = 'ABC' . sprintf('%03d', $i + 100);
            $licencia = 'LIC' . sprintf('%05d', $i + 10000);
            $stmt = $conn->prepare("
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
                    longitud_actual,
                    calificacion_promedio
                ) VALUES (?, 'moto', 'Honda', 'CBR', ?, 'Negro', 2022, ?, '2030-01-01', 'aprobado', 1, 6.2518, -75.5636, 4.5)
            ");
            $stmt->execute([$uid, $placa, $licencia]);
            echo "   Created details for $uid\n";
        }
    }
    echo "✅ " . count($conductorIds) . " conductores asegurados.\n";

    // 3. Crear Viajes y Calificaciones
    // Be idempotent: Check if we have enough trips for these drivers?
    // Or just insert more because more trips is fine.
    
    echo "Creating trips and ratings...\n";
    $viajesCount = 0;
    foreach ($conductorIds as $cid) {
        $numViajes = rand(5, 8);
        for ($j = 0; $j < $numViajes; $j++) {
             // Insert solicitud (viaje)
             // Use minimal required fields?
             // From test script: uuid_solicitud is required?
             // get_trip_history uses 'solicitudes_servicio'.
             // Let's inspect test script again. It explicitly inserts uuid_solicitud.
             
            $uuidSol = uniqid('trip_', true);
            $stmt = $conn->prepare("
                INSERT INTO solicitudes_servicio (
                    uuid_solicitud, 
                    cliente_id, 
                    estado, 
                    latitud_recogida, 
                    longitud_recogida, 
                    direccion_recogida, 
                    latitud_destino, 
                    longitud_destino, 
                    direccion_destino, 
                    precio_final, 
                    completado_en, 
                    fecha_creacion, 
                    solicitado_en, 
                    tipo_servicio,
                    distancia_estimada,
                    tiempo_estimado
                ) VALUES (?, 1, 'completada', 6.2518, -75.5636, 'Calle 10', 6.2530, -75.5640, 'Carrera 43', 15000, NOW(), NOW(), NOW(), 'transporte', 2.5, 15)
            ");
            $stmt->execute([$uuidSol]);
            $sid = $conn->lastInsertId();

            // Insert asignacion confirmada
            // Check if triggers handle logic? Better explicit.
            $stmt = $conn->prepare("
                INSERT INTO asignaciones_conductor (solicitud_id, conductor_id, estado)
                VALUES (?, ?, 'asignado')
            ");
            $stmt->execute([$sid, $cid]);

            // Insert calificación
            $rating = rand(4, 5); // 4 or 5 (smallint)
            $stmt = $conn->prepare("
                INSERT INTO calificaciones (solicitud_id, usuario_calificador_id, usuario_calificado_id, calificacion, comentarios, creado_en)
                VALUES (?, 1, ?, ?, 'Excelente servicio', NOW())
            ");
            $stmt->execute([$sid, $cid, $rating]);
            
            $viajesCount++;
        }
    }
    echo "✅ $viajesCount viajes nuevos insertados.\n";
    echo "🎉 Seeding completado exitosamente para Elite.\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
