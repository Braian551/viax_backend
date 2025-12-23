<?php
/**
 * Script para verificar el estado actual de los documentos en la base de datos
 */

require_once '../config/database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();

    echo "🔍 Verificando estado actual de documentos en la base de datos...\n\n";

    // Verificar tabla de historial
    echo "1. Registros en documentos_conductor_historial:\n";
    $stmt1 = $pdo->query("SELECT COUNT(*) as total FROM documentos_conductor_historial");
    $result1 = $stmt1->fetch();
    echo "   Total registros: {$result1['total']}\n";

    if ($result1['total'] > 0) {
        $stmt1b = $pdo->query("SELECT id, conductor_id, tipo_documento, estado, fecha_subida FROM documentos_conductor_historial LIMIT 5");
        $historial = $stmt1b->fetchAll();
        echo "   Últimos registros:\n";
        foreach ($historial as $registro) {
            echo "   - ID: {$registro['id']}, Conductor: {$registro['conductor_id']}, Tipo: {$registro['tipo_documento']}, Estado: {$registro['estado']}, Fecha: {$registro['fecha_subida']}\n";
        }
    }
    echo "\n";

    // Verificar URLs en detalles_conductor
    echo "2. URLs de fotos en detalles_conductor:\n";
    $stmt2 = $pdo->query("
        SELECT
            COUNT(*) as total_registros,
            COUNT(licencia_foto_url) as con_licencia,
            COUNT(soat_foto_url) as con_soat,
            COUNT(tecnomecanica_foto_url) as con_tecnomecanica,
            COUNT(tarjeta_propiedad_foto_url) as con_tarjeta,
            COUNT(seguro_foto_url) as con_seguro
        FROM detalles_conductor
    ");
    $result2 = $stmt2->fetch();
    echo "   Total registros en detalles_conductor: {$result2['total_registros']}\n";
    echo "   Registros con licencia_foto_url: {$result2['con_licencia']}\n";
    echo "   Registros con soat_foto_url: {$result2['con_soat']}\n";
    echo "   Registros con tecnomecanica_foto_url: {$result2['con_tecnomecanica']}\n";
    echo "   Registros con tarjeta_propiedad_foto_url: {$result2['con_tarjeta']}\n";
    echo "   Registros con seguro_foto_url: {$result2['con_seguro']}\n";

    if ($result2['total_registros'] > 0 && ($result2['con_licencia'] > 0 || $result2['con_soat'] > 0 || $result2['con_tecnomecanica'] > 0 || $result2['con_tarjeta'] > 0 || $result2['con_seguro'] > 0)) {
        echo "\n   ⚠️  ADVERTENCIA: Aún hay URLs de fotos en la base de datos!\n";
        echo "   Mostrando algunos ejemplos:\n";

        $stmt2b = $pdo->query("
            SELECT id, conductor_id,
                   CASE WHEN licencia_foto_url IS NOT NULL THEN 'SI' ELSE 'NO' END as tiene_licencia,
                   CASE WHEN soat_foto_url IS NOT NULL THEN 'SI' ELSE 'NO' END as tiene_soat,
                   CASE WHEN tecnomecanica_foto_url IS NOT NULL THEN 'SI' ELSE 'NO' END as tiene_tecnomecanica,
                   CASE WHEN tarjeta_propiedad_foto_url IS NOT NULL THEN 'SI' ELSE 'NO' END as tiene_tarjeta,
                   CASE WHEN seguro_foto_url IS NOT NULL THEN 'SI' ELSE 'NO' END as tiene_seguro
            FROM detalles_conductor
            WHERE licencia_foto_url IS NOT NULL
               OR soat_foto_url IS NOT NULL
               OR tecnomecanica_foto_url IS NOT NULL
               OR tarjeta_propiedad_foto_url IS NOT NULL
               OR seguro_foto_url IS NOT NULL
            LIMIT 3
        ");
        $registros = $stmt2b->fetchAll();
        foreach ($registros as $registro) {
            echo "   - Conductor ID {$registro['conductor_id']}: Licencia={$registro['tiene_licencia']}, SOAT={$registro['tiene_soat']}, Tecnomecánica={$registro['tiene_tecnomecanica']}, Tarjeta={$registro['tiene_tarjeta']}, Seguro={$registro['tiene_seguro']}\n";
        }
    } else {
        echo "\n   ✅ Todas las URLs de fotos están limpias (NULL)\n";
    }

    echo "\n";

    // Verificar si hay procesos de verificación pendientes
    echo "3. Verificando procesos de verificación:\n";
    $stmt3 = $pdo->query("
        SELECT estado, COUNT(*) as cantidad
        FROM documentos_conductor_historial
        GROUP BY estado
    ");
    $estados = $stmt3->fetchAll();
    if (count($estados) > 0) {
        foreach ($estados as $estado) {
            echo "   Estado '{$estado['estado']}': {$estado['cantidad']} registros\n";
        }
    } else {
        echo "   No hay procesos de verificación pendientes\n";
    }

    echo "\n🔍 Verificación completada.\n";

} catch (Exception $e) {
    echo "❌ Error durante la verificación: " . $e->getMessage() . "\n";
    exit(1);
}
?>