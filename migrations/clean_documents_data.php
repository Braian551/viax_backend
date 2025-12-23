<?php
/**
 * Script para limpiar datos de documentos de conductores
 * Elimina registros existentes y prepara para nueva lógica
 */

require_once '../config/database.php';

try {
    // Conectar a la base de datos usando la clase Database
    $database = new Database();
    $pdo = $database->getConnection();

    echo "🧹 Iniciando limpieza de datos de documentos...\n\n";

    // 1. Limpiar tabla de historial de documentos
    echo "1. Eliminando registros del historial de documentos...\n";
    $stmt1 = $pdo->prepare("DELETE FROM documentos_conductor_historial");
    $result1 = $stmt1->execute();
    $deletedCount = $stmt1->rowCount();
    echo "   ✅ Eliminados $deletedCount registros del historial\n\n";

    // 2. Limpiar URLs de fotos en detalles_conductor
    echo "2. Limpiando URLs de fotos en detalles_conductor...\n";
    $stmt2 = $pdo->prepare("
        UPDATE detalles_conductor SET
            licencia_foto_url = NULL,
            soat_foto_url = NULL,
            tecnomecanica_foto_url = NULL,
            tarjeta_propiedad_foto_url = NULL,
            seguro_foto_url = NULL
    ");
    $result2 = $stmt2->execute();
    $updatedCount = $stmt2->rowCount();
    echo "   ✅ Limpiadas URLs de fotos en $updatedCount registros\n\n";

    // 3. Resetear auto-incremento
    echo "3. Reseteando contador auto-incremento...\n";
    $stmt3 = $pdo->prepare("ALTER TABLE documentos_conductor_historial AUTO_INCREMENT = 1");
    $result3 = $stmt3->execute();
    echo "   ✅ Contador auto-incremento reseteado\n\n";

    echo "🎉 ¡Limpieza completada exitosamente!\n";
    echo "   La base de datos está lista para la nueva lógica de subida de documentos.\n";

} catch (PDOException $e) {
    echo "❌ Error durante la limpieza: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Error general: " . $e->getMessage() . "\n";
    exit(1);
}
?>