<?php
/**
 * Script para resetear el estado de verificación de conductores
 * Cambia el estado de "en_revision" a "pendiente" para permitir nueva subida de documentos
 */

require_once '../config/database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();

    echo "🔄 Reseteando estado de verificación de conductores...\n\n";

    // Verificar estado actual
    echo "1. Estado actual en detalles_conductor:\n";
    $stmt1 = $pdo->query("
        SELECT
            estado_verificacion,
            COUNT(*) as cantidad
        FROM detalles_conductor
        GROUP BY estado_verificacion
    ");
    $estados = $stmt1->fetchAll(PDO::FETCH_ASSOC);

    if (count($estados) > 0) {
        foreach ($estados as $estado) {
            echo "   Estado '{$estado['estado_verificacion']}': {$estado['cantidad']} registros\n";
        }
    } else {
        echo "   No hay registros en detalles_conductor\n";
    }
    echo "\n";

    // Cambiar estado de "en_revision" a "pendiente"
    echo "2. Cambiando estado de 'en_revision' a 'pendiente'...\n";
    $stmt2 = $pdo->prepare("
        UPDATE detalles_conductor
        SET estado_verificacion = 'pendiente'
        WHERE estado_verificacion = 'en_revision'
    ");
    $result2 = $stmt2->execute();
    $updatedCount = $stmt2->rowCount();
    echo "   ✅ Actualizados $updatedCount registros\n\n";

    // Verificar estado después del cambio
    echo "3. Estado después del cambio:\n";
    $stmt3 = $pdo->query("
        SELECT
            estado_verificacion,
            COUNT(*) as cantidad
        FROM detalles_conductor
        GROUP BY estado_verificacion
    ");
    $estadosFinal = $stmt3->fetchAll(PDO::FETCH_ASSOC);

    if (count($estadosFinal) > 0) {
        foreach ($estadosFinal as $estado) {
            echo "   Estado '{$estado['estado_verificacion']}': {$estado['cantidad']} registros\n";
        }
    }

    echo "\n🎉 ¡Estado de verificación reseteado exitosamente!\n";
    echo "   Los conductores ahora pueden volver a subir documentos con la nueva lógica.\n";

} catch (Exception $e) {
    echo "❌ Error durante el reseteo: " . $e->getMessage() . "\n";
    exit(1);
}
?>