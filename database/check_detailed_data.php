<?php
/**
 * Script para verificar datos detallados en documentos_conductor_historial
 */

require_once '../config/database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();

    echo "🔍 Verificando datos detallados en documentos_conductor_historial...\n\n";

    // Verificar todos los registros (aunque el count diga 0, por si acaso)
    $stmt1 = $pdo->query("SELECT * FROM documentos_conductor_historial ORDER BY id DESC LIMIT 10");
    $registros = $stmt1->fetchAll(PDO::FETCH_ASSOC);

    echo "Registros encontrados: " . count($registros) . "\n\n";

    if (count($registros) > 0) {
        echo "Detalles de los registros:\n";
        foreach ($registros as $registro) {
            echo "ID: {$registro['id']}\n";
            echo "  Conductor ID: {$registro['conductor_id']}\n";
            echo "  Tipo documento: {$registro['tipo_documento']}\n";
            echo "  URL: " . (strlen($registro['url_documento']) > 50 ? substr($registro['url_documento'], 0, 50) . "..." : $registro['url_documento']) . "\n";
            echo "  Fecha carga: {$registro['fecha_carga']}\n";
            echo "  Activo: {$registro['activo']} (" . ($registro['activo'] ? 'SÍ' : 'NO') . ")\n";
            echo "  Reemplazado en: " . ($registro['reemplazado_en'] ?? 'NULL') . "\n";
            echo "\n";
        }
    } else {
        echo "✅ No hay registros en la tabla documentos_conductor_historial\n";
    }

    // Verificar si hay registros marcados como activos
    $stmt2 = $pdo->query("SELECT COUNT(*) as activos FROM documentos_conductor_historial WHERE activo = 1");
    $activos = $stmt2->fetch()['activos'];
    echo "Registros activos (activo=1): $activos\n";

    // Verificar registros por conductor (si hay algún conductor específico)
    $stmt3 = $pdo->query("SELECT conductor_id, COUNT(*) as total FROM documentos_conductor_historial GROUP BY conductor_id");
    $porConductor = $stmt3->fetchAll(PDO::FETCH_ASSOC);

    if (count($porConductor) > 0) {
        echo "\nDocumentos por conductor:\n";
        foreach ($porConductor as $conductor) {
            echo "  Conductor {$conductor['conductor_id']}: {$conductor['total']} documentos\n";
        }
    }

    echo "\n🔍 Verificación detallada completada.\n";

} catch (Exception $e) {
    echo "❌ Error durante la verificación: " . $e->getMessage() . "\n";
    exit(1);
}
?>