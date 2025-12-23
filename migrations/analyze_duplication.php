<?php
/**
 * Script para verificar duplicación de información en tablas de documentos
 */

require_once '../config/database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();

    echo "🔍 Verificando duplicación de información en tablas de documentos...\n\n";

    // Verificar estructura completa de ambas tablas
    echo "1. Estructura de detalles_conductor (campos de documentos):\n";
    $stmt1 = $pdo->query("DESCRIBE detalles_conductor");
    $columns1 = $stmt1->fetchAll(PDO::FETCH_ASSOC);

    $documentFields = [];
    foreach ($columns1 as $column) {
        if (strpos($column['Field'], '_foto_url') !== false ||
            strpos($column['Field'], 'estado') !== false ||
            strpos($column['Field'], 'verif') !== false) {
            $documentFields[] = $column;
            echo "   - {$column['Field']}: {$column['Type']} ({$column['Null']})";
            if ($column['Default'] !== null) echo " Default: {$column['Default']}";
            echo "\n";
        }
    }
    echo "\n";

    echo "2. Estructura de documentos_conductor_historial:\n";
    $stmt2 = $pdo->query("DESCRIBE documentos_conductor_historial");
    $columns2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    foreach ($columns2 as $column) {
        echo "   - {$column['Field']}: {$column['Type']} ({$column['Null']})";
        if ($column['Key'] == 'PRI') echo " PRIMARY KEY";
        if ($column['Key'] == 'MUL') echo " FOREIGN KEY";
        if ($column['Default'] !== null) echo " Default: {$column['Default']}";
        echo "\n";
    }
    echo "\n";

    // Verificar datos actuales
    echo "3. Datos actuales en ambas tablas:\n";

    // En detalles_conductor
    $stmt3 = $pdo->query("SELECT COUNT(*) as total FROM detalles_conductor");
    $totalDetalles = $stmt3->fetch()['total'];
    echo "   detalles_conductor: $totalDetalles registros\n";

    $stmt3b = $pdo->query("
        SELECT
            id, usuario_id,
            CASE WHEN licencia_foto_url IS NOT NULL THEN 'SI' ELSE 'NO' END as tiene_licencia,
            CASE WHEN soat_foto_url IS NOT NULL THEN 'SI' ELSE 'NO' END as tiene_soat,
            CASE WHEN tecnomecanica_foto_url IS NOT NULL THEN 'SI' ELSE 'NO' END as tiene_tecnomecanica,
            CASE WHEN tarjeta_propiedad_foto_url IS NOT NULL THEN 'SI' ELSE 'NO' END as tiene_tarjeta,
            CASE WHEN seguro_foto_url IS NOT NULL THEN 'SI' ELSE 'NO' END as tiene_seguro,
            estado_verificacion
        FROM detalles_conductor
    ");
    $detallesData = $stmt3b->fetchAll(PDO::FETCH_ASSOC);

    if (count($detallesData) > 0) {
        echo "   Detalles de documentos en detalles_conductor:\n";
        foreach ($detallesData as $detalle) {
            echo "   - Conductor {$detalle['usuario_id']}: Licencia={$detalle['tiene_licencia']}, SOAT={$detalle['tiene_soat']}, Tecnomecánica={$detalle['tiene_tecnomecanica']}, Tarjeta={$detalle['tiene_tarjeta']}, Seguro={$detalle['tiene_seguro']} (Estado: {$detalle['estado_verificacion']})\n";
        }
    }
    echo "\n";

    // En documentos_conductor_historial
    $stmt4 = $pdo->query("SELECT COUNT(*) as total FROM documentos_conductor_historial");
    $totalHistorial = $stmt4->fetch()['total'];
    echo "   documentos_conductor_historial: $totalHistorial registros\n";

    if ($totalHistorial > 0) {
        $stmt4b = $pdo->query("
            SELECT id, conductor_id, tipo_documento, url_documento, activo, fecha_carga
            FROM documentos_conductor_historial
            ORDER BY conductor_id, tipo_documento
        ");
        $historialData = $stmt4b->fetchAll(PDO::FETCH_ASSOC);

        echo "   Detalles de documentos en historial:\n";
        foreach ($historialData as $historial) {
            $urlPreview = strlen($historial['url_documento']) > 30 ?
                substr($historial['url_documento'], 0, 30) . "..." :
                $historial['url_documento'];
            echo "   - ID {$historial['id']}: Conductor {$historial['conductor_id']}, Tipo: {$historial['tipo_documento']}, Activo: {$historial['activo']}, URL: $urlPreview\n";
        }
    }
    echo "\n";

    // Análisis de duplicación
    echo "4. Análisis de duplicación:\n";

    if ($totalDetalles > 0 && $totalHistorial > 0) {
        echo "   ⚠️  POSIBLE DUPLICACIÓN DETECTADA:\n";
        echo "   - detalles_conductor almacena URLs directas por tipo de documento\n";
        echo "   - documentos_conductor_historial almacena un historial completo con versiones\n";
        echo "   - Ambos pueden contener la misma información pero con diferentes propósitos\n\n";

        // Verificar si hay conflictos
        $stmt5 = $pdo->query("
            SELECT dc.usuario_id,
                   CASE WHEN dc.licencia_foto_url IS NOT NULL THEN 1 ELSE 0 END as dc_tiene_licencia,
                   COUNT(CASE WHEN dch.tipo_documento = 'licencia' AND dch.activo = 1 THEN 1 END) as hist_tiene_licencia,
                   CASE WHEN dc.soat_foto_url IS NOT NULL THEN 1 ELSE 0 END as dc_tiene_soat,
                   COUNT(CASE WHEN dch.tipo_documento = 'soat' AND dch.activo = 1 THEN 1 END) as hist_tiene_soat
            FROM detalles_conductor dc
            LEFT JOIN documentos_conductor_historial dch ON dc.usuario_id = dch.conductor_id
            GROUP BY dc.usuario_id, dc.licencia_foto_url, dc.soat_foto_url
        ");
        $comparacion = $stmt5->fetchAll(PDO::FETCH_ASSOC);

        foreach ($comparacion as $comp) {
            $conflictos = [];
            if ($comp['dc_tiene_licencia'] != $comp['hist_tiene_licencia']) {
                $conflictos[] = "Licencia (DC: {$comp['dc_tiene_licencia']}, Hist: {$comp['hist_tiene_licencia']})";
            }
            if ($comp['dc_tiene_soat'] != $comp['hist_tiene_soat']) {
                $conflictos[] = "SOAT (DC: {$comp['dc_tiene_soat']}, Hist: {$comp['hist_tiene_soat']})";
            }

            if (!empty($conflictos)) {
                echo "   🚨 CONFLICTO en Conductor {$comp['usuario_id']}: " . implode(', ', $conflictos) . "\n";
            }
        }

        if (empty($comparacion) || !array_filter($comparacion, fn($c) => !empty($conflictos))) {
            echo "   ✅ No se encontraron conflictos directos entre las tablas\n";
        }

    } elseif ($totalDetalles > 0 && $totalHistorial == 0) {
        echo "   ✅ Solo detalles_conductor tiene datos (historial vacío)\n";
    } elseif ($totalDetalles == 0 && $totalHistorial > 0) {
        echo "   ✅ Solo documentos_conductor_historial tiene datos\n";
    } else {
        echo "   ✅ Ambas tablas están vacías\n";
    }

    echo "\n🔍 Análisis completado.\n";

} catch (Exception $e) {
    echo "❌ Error durante el análisis: " . $e->getMessage() . "\n";
    exit(1);
}
?>