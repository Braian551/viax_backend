<?php
/**
 * Script de prueba: crear una solicitud con cliente 'braianoquendurango@gmail.com'
 * y hacer que el conductor 'braianoquen2@gmail.com' acepte la solicitud inmediatamente.
 *
 * Instrucciones:
 * 1) Asegúrate de tener la BD y el servidor backend corriendo (opcional: el script puede aceptar directamente en DB si el endpoint no responde).
 * 2) Ajusta los emails si hace falta.
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/plain');

$clienteEmail = 'braianoquendurango@gmail.com';
$conductorEmail = 'braianoquen2@gmail.com';

$database = new Database();
$db = $database->getConnection();

try {
    // Buscar cliente
    $stmt = $db->prepare("SELECT id, nombre FROM usuarios WHERE email = ? AND tipo_usuario = 'cliente' LIMIT 1");
    $stmt->execute([$clienteEmail]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cliente) {
        throw new Exception("Cliente con email $clienteEmail no encontrado");
    }

    echo "✅ Cliente encontrado: {$cliente['nombre']} (ID: {$cliente['id']})\n";

    // Buscar conductor
    $stmt = $db->prepare("SELECT u.id, u.nombre, dc.disponible, dc.latitud_actual, dc.longitud_actual, dc.estado_verificacion
                          FROM usuarios u
                          INNER JOIN detalles_conductor dc ON u.id = dc.usuario_id
                          WHERE u.email = ? AND u.tipo_usuario = 'conductor' LIMIT 1");
    $stmt->execute([$conductorEmail]);
    $conductor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conductor) {
        throw new Exception("Conductor con email $conductorEmail no encontrado");
    }

    echo "✅ Conductor encontrado: {$conductor['nombre']} (ID: {$conductor['id']})\n";

    // Asegurar que el conductor esté aprobado y disponible
    if ($conductor['estado_verificacion'] !== 'aprobado') {
        $stmt = $db->prepare("UPDATE detalles_conductor SET estado_verificacion = 'aprobado' WHERE usuario_id = ?");
        $stmt->execute([$conductor['id']]);
        echo "⚠️  Conductor no aprobado: se marcó como 'aprobado'\n";
    }

    if (!$conductor['disponible']) {
        $stmt = $db->prepare("UPDATE detalles_conductor SET disponible = 1 WHERE usuario_id = ?");
        $stmt->execute([$conductor['id']]);
        echo "⚠️  Conductor marcado como disponible\n";
    }

    // Asegurar ubicación válida
    $lat = $conductor['latitud_actual'] ?: 6.2476; // fallback Medellín
    $lng = $conductor['longitud_actual'] ?: -75.5658;

    // Crear origen dentro del radio (ej: 2 km aprox)
    $latOrigen = $lat + 0.018; // ~2 km
    $lngOrigen = $lng + 0.012; // ~1.3 km

    // Destino cercano
    $latDestino = $latOrigen + 0.035; // ~3.5 km
    $lngDestino = $lngOrigen + 0.030; // ~3 km

    // Insertar solicitud
    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    $stmt = $db->prepare("INSERT INTO solicitudes_servicio (
        uuid_solicitud, cliente_id, latitud_recogida, longitud_recogida, direccion_recogida,
        latitud_destino, longitud_destino, direccion_destino, distancia_estimada, tiempo_estimado,
        tipo_servicio, estado, fecha_creacion, solicitado_en
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");

    $direccionOrigen = 'Punto de Prueba - Origen (cerca del conductor)';
    $direccionDestino = 'Punto de Prueba - Destino';

    $stmt->execute([
        $uuid,
        $cliente['id'],
        $latOrigen,
        $lngOrigen,
        $direccionOrigen,
        $latDestino,
        $lngDestino,
        $direccionDestino,
        7.0,
        20,
        'transporte',
        'pendiente'
    ]);

    $solicitudId = $db->lastInsertId();
    echo "✅ Solicitud creada con ID: $solicitudId (UUID: $uuid)\n";

    // Intentar llamar al endpoint de aceptación primero
    // Puedes pasar URL como argumento: php test_conductor_cerca_acepta.php http://localhost:8000/conductor/accept_trip_request.php
    $acceptUrl = isset($argv[1]) && $argv[1] ? $argv[1] : 'http://localhost:8000/conductor/accept_trip_request.php';
    $alternateUrl = isset($argv[2]) && $argv[2] ? $argv[2] : 'http://localhost/ping_go/backend-deploy/conductor/accept_trip_request.php';
    $data = json_encode([
        'solicitud_id' => (int)$solicitudId,
        'conductor_id' => (int)$conductor['id']
    ]);

    echo "➡️ Llamando al endpoint de aceptación: $acceptUrl ...\n";

    $ch = curl_init($acceptUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response && $httpCode >= 200 && $httpCode < 300) {
        echo "✅ Endpoint responded (HTTP $httpCode):\n";
        echo $response . "\n";
    } else {
        echo "⚠️ Endpoint no disponible o error (HTTP $httpCode). Error: $curlErr\n";
        // Intentar alternateUrl (si distinto)
        if ($alternateUrl && $alternateUrl !== $acceptUrl) {
            echo "➡️ Intentando URL alterna: $alternateUrl ...\n";
            $ch2 = curl_init($alternateUrl);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch2, CURLOPT_POST, true);
            curl_setopt($ch2, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch2, CURLOPT_TIMEOUT, 5);

            $resp2 = curl_exec($ch2);
            $err2 = curl_error($ch2);
            $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);

            if ($resp2 && $httpCode2 >= 200 && $httpCode2 < 300) {
                echo "✅ Endpoint alterno respondió (HTTP $httpCode2):\n";
                echo $resp2 . "\n";
            } else {
                echo "⚠️ Endpoint alterno no disponible o error (HTTP $httpCode2). Error: $err2\n";
                echo "➡️ Ejecutando aceptación en la BD (fallback)...\n";
            }
        } else {
            echo "➡️ Ejecutando aceptación en la BD (fallback)...\n";
        }

        // Realizar aceptación manualmente como el endpoint
        $db->beginTransaction();
        try {
            // Obtener solicitud FOR UPDATE
            $stmt = $db->prepare("SELECT id, estado, cliente_id, tipo_servicio FROM solicitudes_servicio WHERE id = ? FOR UPDATE");
            $stmt->execute([$solicitudId]);
            $s = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$s) throw new Exception('Solicitud no encontrada (fallback)');
            if ($s['estado'] !== 'pendiente') throw new Exception('La solicitud ya fue aceptada');

            // Verificar conductor disponible y aprobado
            $stmt = $db->prepare("SELECT dc.disponible, dc.estado_verificacion FROM detalles_conductor dc WHERE dc.usuario_id = ?");
            $stmt->execute([$conductor['id']]);
            $dc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$dc || $dc['estado_verificacion'] !== 'aprobado') throw new Exception('Conductor no verificado (fallback)');
            if (!$dc['disponible']) throw new Exception('Conductor no disponible (fallback)');

            $stmt = $db->prepare("UPDATE solicitudes_servicio SET estado = 'aceptada', aceptado_en = NOW() WHERE id = ?");
            $stmt->execute([$solicitudId]);

            $stmt = $db->prepare("INSERT INTO asignaciones_conductor (solicitud_id, conductor_id, asignado_en, estado) VALUES (?, ?, NOW(), 'asignado')");
            $stmt->execute([$solicitudId, $conductor['id']]);

            $stmt = $db->prepare("UPDATE detalles_conductor SET disponible = 0 WHERE usuario_id = ?");
            $stmt->execute([$conductor['id']]);

            $db->commit();
            echo "✅ Aceptación ejecutada en BD (fallback).\n";

        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // Verificar estado final en BD
    $stmt = $db->prepare("SELECT id, estado FROM solicitudes_servicio WHERE id = ?");
    $stmt->execute([$solicitudId]);
    $final = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "🔍 Estado final de la solicitud: " . $final['estado'] . " (ID: " . $final['id'] . ")\n";

    // Verificar asignación
    $stmt = $db->prepare("SELECT * FROM asignaciones_conductor WHERE solicitud_id = ?");
    $stmt->execute([$solicitudId]);
    $asign = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "🔍 Asignaciones encontradas: " . count($asign) . "\n";
    foreach ($asign as $a) {
        echo "   - Asign ID: {$a['id']}, Conductor ID: {$a['conductor_id']}, Estado: {$a['estado']}, Fecha: {$a['asignado_en']}\n";
    }

    echo "\n✅ Test completado.\n";

} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " line " . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
}

?>
