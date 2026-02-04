<?php
/**
 * test_notifications.php
 * Script de prueba para el sistema de notificaciones
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/config/database.php';

echo "==============================================\n";
echo "TEST: Sistema de Notificaciones\n";
echo "==============================================\n\n";

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "✓ Conexión establecida\n\n";
    
    // Obtener un usuario de prueba
    $userQuery = "SELECT id, nombre FROM usuarios LIMIT 1";
    $userStmt = $conn->query($userQuery);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "⚠ No se encontraron usuarios. Creando notificaciones para usuario_id = 1\n";
        $userId = 1;
    } else {
        $userId = $user['id'];
        echo "📧 Usuario de prueba: {$user['nombre']} (ID: $userId)\n\n";
    }
    
    // Crear notificaciones de prueba
    echo "Creando notificaciones de prueba...\n\n";
    
    $notificaciones = [
        [
            'tipo' => 'trip_accepted',
            'titulo' => '¡Conductor en camino!',
            'mensaje' => 'Juan Carlos ha aceptado tu solicitud de viaje. Llegará en aproximadamente 5 minutos.',
        ],
        [
            'tipo' => 'trip_completed',
            'titulo' => 'Viaje completado',
            'mensaje' => 'Tu viaje ha finalizado exitosamente. ¡Gracias por usar Viax!',
        ],
        [
            'tipo' => 'payment_received',
            'titulo' => 'Pago confirmado',
            'mensaje' => 'El pago de $25.000 ha sido procesado correctamente.',
        ],
        [
            'tipo' => 'promo',
            'titulo' => '🎉 ¡Oferta especial!',
            'mensaje' => 'Obtén 20% de descuento en tu próximo viaje. Código: VIAX20',
        ],
        [
            'tipo' => 'rating_received',
            'titulo' => 'Nueva calificación',
            'mensaje' => 'Has recibido una calificación de 5 estrellas. ¡Excelente!',
        ],
        [
            'tipo' => 'system',
            'titulo' => 'Actualización disponible',
            'mensaje' => 'Una nueva versión de Viax está disponible. Actualiza para obtener las últimas funciones.',
        ],
    ];
    
    foreach ($notificaciones as $notif) {
        $query = "SELECT crear_notificacion(:usuario_id, :tipo, :titulo, :mensaje, NULL, NULL, '{}') as id";
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':usuario_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':tipo', $notif['tipo']);
        $stmt->bindValue(':titulo', $notif['titulo']);
        $stmt->bindValue(':mensaje', $notif['mensaje']);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "  ✓ Creada: {$notif['titulo']} (ID: {$result['id']})\n";
    }
    
    echo "\n";
    
    // Verificar conteo
    $countQuery = "SELECT contar_notificaciones_no_leidas(:usuario_id) as count";
    $countStmt = $conn->prepare($countQuery);
    $countStmt->bindValue(':usuario_id', $userId, PDO::PARAM_INT);
    $countStmt->execute();
    $count = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    echo "📊 Total no leídas: $count\n\n";
    
    // Listar notificaciones
    echo "Listando notificaciones del usuario...\n\n";
    
    $listQuery = "
        SELECT 
            n.id,
            n.titulo,
            n.leida,
            t.codigo as tipo,
            t.color,
            n.created_at
        FROM notificaciones_usuario n
        INNER JOIN tipos_notificacion t ON n.tipo_id = t.id
        WHERE n.usuario_id = :usuario_id AND n.eliminada = FALSE
        ORDER BY n.created_at DESC
        LIMIT 10
    ";
    $listStmt = $conn->prepare($listQuery);
    $listStmt->bindValue(':usuario_id', $userId, PDO::PARAM_INT);
    $listStmt->execute();
    
    while ($row = $listStmt->fetch(PDO::FETCH_ASSOC)) {
        $status = $row['leida'] ? '✓' : '○';
        echo "  $status [{$row['tipo']}] {$row['titulo']}\n";
    }
    
    echo "\n==============================================\n";
    echo "✓ TEST COMPLETADO\n";
    echo "==============================================\n";
    
    // Retornar JSON para pruebas API
    echo "\n\nJSON Response:\n";
    echo json_encode([
        'success' => true,
        'usuario_id' => $userId,
        'notificaciones_creadas' => count($notificaciones),
        'no_leidas' => (int) $count
    ], JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    echo "\n✗ Error de base de datos: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
}
