<?php
/**
 * Script all-in-one para diagnosticar y solucionar problemas con solicitudes
 * 
 * Este script:
 * 1. Verifica si hay conductores aprobados en la BD
 * 2. Verifica si hay solicitudes pendientes
 * 3. Crea una nueva solicitud cerca de un conductor disponible
 * 4. Consulta las solicitudes pendientes para ese conductor
 */

require_once '../config/database.php';

echo "==========================================================\n";
echo "🔧 DIAGNÓSTICO COMPLETO - SISTEMA DE SOLICITUDES\n";
echo "==========================================================\n\n";

$database = new Database();
$db = $database->getConnection();

try {
    // ==========================================
    // PASO 1: Verificar conductores aprobados y disponibles
    // ==========================================
    echo "📝 PASO 1: Verificando conductores aprobados...\n";
    
    $stmt = $db->prepare("
        SELECT 
            u.id,
            u.nombre,
            u.email,
            dc.estado_verificacion,
            dc.disponible,
            dc.latitud_actual,
            dc.longitud_actual,
            dc.vehiculo_tipo
        FROM usuarios u
        INNER JOIN detalles_conductor dc ON u.id = dc.usuario_id
        WHERE u.tipo_usuario = 'conductor'
        ORDER BY dc.estado_verificacion DESC, dc.disponible DESC
    ");
    $stmt->execute();
    $conductores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "   📊 Total conductores en sistema: " . count($conductores) . "\n\n";
    
    $conductorAprobadoDisponible = null;
    foreach ($conductores as $conductor) {
        $estadoIcon = $conductor['estado_verificacion'] === 'aprobado' ? '✅' : '⏳';
        $disponibleIcon = $conductor['disponible'] ? '🟢' : '🔴';
        $ubicIcon = ($conductor['latitud_actual'] && $conductor['longitud_actual']) ? '📍' : '❌';
        
        echo "   $estadoIcon Conductor ID {$conductor['id']}: {$conductor['nombre']}\n";
        echo "      - Email: {$conductor['email']}\n";
        echo "      - Estado: {$conductor['estado_verificacion']}\n";
        echo "      - Disponible: " . ($conductor['disponible'] ? 'Sí' : 'No') . " $disponibleIcon\n";
        echo "      - Ubicación: ";
        
        if ($conductor['latitud_actual'] && $conductor['longitud_actual']) {
            echo "{$conductor['latitud_actual']}, {$conductor['longitud_actual']} $ubicIcon\n";
        } else {
            echo "No registrada $ubicIcon\n";
        }
        echo "      - Vehículo: {$conductor['vehiculo_tipo']}\n\n";
        
        // Guardar el primer conductor aprobado y disponible con ubicación
        if (!$conductorAprobadoDisponible && 
            $conductor['estado_verificacion'] === 'aprobado' && 
            $conductor['disponible'] && 
            $conductor['latitud_actual'] && 
            $conductor['longitud_actual']) {
            $conductorAprobadoDisponible = $conductor;
        }
    }
    
    if (!$conductorAprobadoDisponible) {
        echo "⚠️  No hay conductores aprobados, disponibles y con ubicación\n";
        echo "💡 Sugerencia: Activa un conductor en la app primero\n\n";
        exit(1);
    }
    
    echo "✅ Conductor seleccionado para prueba:\n";
    echo "   ID: {$conductorAprobadoDisponible['id']}\n";
    echo "   Nombre: {$conductorAprobadoDisponible['nombre']}\n";
    echo "   Ubicación: {$conductorAprobadoDisponible['latitud_actual']}, {$conductorAprobadoDisponible['longitud_actual']}\n\n";
    
    // ==========================================
    // PASO 2: Verificar solicitudes pendientes actuales
    // ==========================================
    echo "📝 PASO 2: Verificando solicitudes pendientes actuales...\n";
    
    $stmt = $db->prepare("
        SELECT COUNT(*) as total
        FROM solicitudes_servicio
        WHERE estado = 'pendiente'
    ");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "   📊 Solicitudes pendientes en BD: {$result['total']}\n\n";
    
    // ==========================================
    // PASO 3: Limpiar solicitudes viejas de prueba
    // ==========================================
    echo "📝 PASO 3: Limpiando solicitudes antiguas de prueba...\n";
    
    $stmt = $db->prepare("
        DELETE FROM solicitudes_servicio
        WHERE estado = 'pendiente'
        AND fecha_creacion < NOW() - INTERVAL '1 hour'
    ");
    $stmt->execute();
    $eliminadas = $stmt->rowCount();
    echo "   🗑️  Eliminadas: $eliminadas solicitudes\n\n";
    
    // ==========================================
    // PASO 4: Crear solicitud cerca del conductor
    // ==========================================
    echo "📝 PASO 4: Creando solicitud de prueba cerca del conductor...\n";
    
    // Verificar/crear usuario de prueba
    $stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ?");
    $stmt->execute(['usuario.test@ping.go']);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        $uuidUsuario = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
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
            $uuidUsuario,
            'Usuario',
            'Test',
            'usuario.test@ping.go',
            '+573000000000',
            password_hash('test123', PASSWORD_BCRYPT),
            'cliente'
        ]);
        $usuarioId = $db->lastInsertId();
        echo "   ✅ Usuario de prueba creado (ID: $usuarioId)\n";
    } else {
        $usuarioId = $usuario['id'];
        echo "   ✅ Usando usuario existente (ID: $usuarioId)\n";
    }
    
    // Calcular ubicaciones cercanas al conductor (aprox 0.5-1 km)
    $latConductor = floatval($conductorAprobadoDisponible['latitud_actual']);
    $lngConductor = floatval($conductorAprobadoDisponible['longitud_actual']);
    
    // Origen: un poco al norte del conductor (~500m)
    $latOrigen = $latConductor + 0.0045;
    $lngOrigen = $lngConductor;
    
    // Destino: un poco más al norte (~2km del origen)
    $latDestino = $latOrigen + 0.018;
    $lngDestino = $lngOrigen + 0.009;
    
    $uuidSolicitud = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
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
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    
    $stmt->execute([
        $uuidSolicitud,
        $usuarioId,
        'transporte',
        $latOrigen,
        $lngOrigen,
        'TEST - Direccion de Prueba - Origen (cerca del conductor)',
        $latDestino,
        $lngDestino,
        'TEST - Direccion de Prueba - Destino',
        3.5, // km
        12,  // minutos
        'pendiente'
    ]);
    
    $solicitudId = $db->lastInsertId();
    
    echo "   ✅ Solicitud creada exitosamente!\n";
    echo "      - ID: $solicitudId\n";
    echo "      - UUID: $uuidSolicitud\n";
    echo "      - Origen: Lat $latOrigen, Lng $lngOrigen\n";
    echo "      - Destino: Lat $latDestino, Lng $lngDestino\n\n";
    
    // Calcular distancia del conductor al origen
    $distanciaConductorOrigen = 6371 * acos(
        cos(deg2rad($latConductor)) * cos(deg2rad($latOrigen)) *
        cos(deg2rad($lngOrigen) - deg2rad($lngConductor)) +
        sin(deg2rad($latConductor)) * sin(deg2rad($latOrigen))
    );
    
    echo "   📏 Distancia conductor -> origen: " . round($distanciaConductorOrigen, 2) . " km\n\n";
    
    // ==========================================
    // PASO 5: Consultar solicitudes como lo haría la app
    // ==========================================
    echo "📝 PASO 5: Consultando solicitudes como lo hace la app...\n";
    
    $radioKm = 5.0;
    
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
        AND COALESCE(s.solicitado_en, s.fecha_creacion) >= NOW() - INTERVAL '15 minutes'
        AND (6371 * acos(
            cos(radians(?)) * cos(radians(s.latitud_recogida)) *
            cos(radians(s.longitud_recogida) - radians(?)) +
            sin(radians(?)) * sin(radians(s.latitud_recogida))
        )) <= ?
        ORDER BY distancia_conductor_origen ASC
    ");
    
    $stmt->execute([
        $latConductor,
        $lngConductor,
        $latConductor,
        $latConductor,
        $lngConductor,
        $latConductor,
        $radioKm
    ]);
    
    $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "   📊 Parámetros de consulta:\n";
    echo "      - Conductor Lat: $latConductor\n";
    echo "      - Conductor Lng: $lngConductor\n";
    echo "      - Radio: $radioKm km\n\n";
    
    echo "   ✅ Solicitudes encontradas: " . count($solicitudes) . "\n\n";
    
    if (count($solicitudes) > 0) {
        foreach ($solicitudes as $index => $sol) {
            echo "   📋 Solicitud #" . ($index + 1) . ":\n";
            echo "      🆔 ID: {$sol['id']}\n";
            echo "      👤 Cliente: {$sol['nombre_usuario']}\n";
            echo "      📍 Origen: {$sol['direccion_recogida']}\n";
            echo "      📍 Destino: {$sol['direccion_destino']}\n";
            echo "      📏 Distancia viaje: {$sol['distancia_estimada']} km\n";
            echo "      🚗 Distancia conductor->origen: " . round($sol['distancia_conductor_origen'], 2) . " km\n";
            echo "      ⏱️  Tiempo: {$sol['tiempo_estimado']} min\n";
            echo "      📅 Fecha: {$sol['fecha_solicitud']}\n\n";
        }
        
        echo "==========================================================\n";
        echo "✅ ¡ÉXITO! El sistema está funcionando correctamente\n";
        echo "==========================================================\n\n";
        
        echo "💡 PRÓXIMOS PASOS:\n";
        echo "   1. Abre la app Flutter en el emulador\n";
        echo "   2. Inicia sesión como conductor ID: {$conductorAprobadoDisponible['id']}\n";
        echo "   3. Activa el modo 'En línea'\n";
        echo "   4. La app debería mostrar la solicitud ID: $solicitudId\n\n";
        
        echo "🔍 DATOS PARA LA APP:\n";
        echo "   - Conductor ID: {$conductorAprobadoDisponible['id']}\n";
        echo "   - Email: {$conductorAprobadoDisponible['email']}\n";
        echo "   - Solicitud ID: $solicitudId\n\n";
        
    } else {
        echo "⚠️  PROBLEMA: No se encontraron solicitudes\n\n";
        
        echo "🔍 DIAGNÓSTICO:\n";
        
        // Verificar si la solicitud existe
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM solicitudes_servicio WHERE id = ?");
        $stmt->execute([$solicitudId]);
        $exists = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($exists['total'] > 0) {
            echo "   ✅ La solicitud existe en la BD\n";
            echo "   ❌ Pero no se encuentra en el radio de búsqueda\n";
            echo "   💡 La distancia calculada fue: " . round($distanciaConductorOrigen, 2) . " km\n";
            echo "   💡 El radio de búsqueda es: $radioKm km\n\n";
            
            if ($distanciaConductorOrigen > $radioKm) {
                echo "   ⚠️  PROBLEMA IDENTIFICADO: La solicitud está fuera del radio\n";
                echo "   💡 SOLUCIÓN: Aumentar el radio de búsqueda o ajustar ubicaciones\n\n";
            }
        } else {
            echo "   ❌ La solicitud no se insertó correctamente\n\n";
        }
    }
    
} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "📍 En: " . $e->getFile() . " línea " . $e->getLine() . "\n\n";
}
