<?php
/**
 * Script para limpiar TODOS los datos de documentos en detalles_conductor
 */

require_once '../config/database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();

    echo "🧹 Limpiando TODOS los datos de documentos en detalles_conductor...\n\n";

    // Verificar datos actuales antes de limpiar
    echo "1. Datos actuales antes de limpiar:\n";
    $stmt1 = $pdo->query("SELECT * FROM detalles_conductor LIMIT 1");
    $data = $stmt1->fetch(PDO::FETCH_ASSOC);

    if ($data) {
        echo "   Campos relacionados con documentos:\n";

        // Campos de licencia
        echo "   📄 LICENCIA:\n";
        echo "   - licencia_conduccion: " . ($data['licencia_conduccion'] ?? 'NULL') . "\n";
        echo "   - licencia_vencimiento: " . ($data['licencia_vencimiento'] ?? 'NULL') . "\n";
        echo "   - licencia_expedicion: " . ($data['licencia_expedicion'] ?? 'NULL') . "\n";
        echo "   - licencia_categoria: " . ($data['licencia_categoria'] ?? 'NULL') . "\n";
        echo "   - licencia_foto_url: " . ($data['licencia_foto_url'] ?? 'NULL') . "\n";

        // Campos de vehículo
        echo "   🚗 VEHÍCULO:\n";
        echo "   - vehiculo_tipo: " . ($data['vehiculo_tipo'] ?? 'NULL') . "\n";
        echo "   - vehiculo_marca: " . ($data['vehiculo_marca'] ?? 'NULL') . "\n";
        echo "   - vehiculo_modelo: " . ($data['vehiculo_modelo'] ?? 'NULL') . "\n";
        echo "   - vehiculo_anio: " . ($data['vehiculo_anio'] ?? 'NULL') . "\n";
        echo "   - vehiculo_color: " . ($data['vehiculo_color'] ?? 'NULL') . "\n";
        echo "   - vehiculo_placa: " . ($data['vehiculo_placa'] ?? 'NULL') . "\n";

        // Campos de seguro
        echo "   🛡️ SEGURO:\n";
        echo "   - aseguradora: " . ($data['aseguradora'] ?? 'NULL') . "\n";
        echo "   - numero_poliza_seguro: " . ($data['numero_poliza_seguro'] ?? 'NULL') . "\n";
        echo "   - vencimiento_seguro: " . ($data['vencimiento_seguro'] ?? 'NULL') . "\n";
        echo "   - seguro_foto_url: " . ($data['seguro_foto_url'] ?? 'NULL') . "\n";

        // Campos de SOAT
        echo "   📋 SOAT:\n";
        echo "   - soat_numero: " . ($data['soat_numero'] ?? 'NULL') . "\n";
        echo "   - soat_vencimiento: " . ($data['soat_vencimiento'] ?? 'NULL') . "\n";
        echo "   - soat_foto_url: " . ($data['soat_foto_url'] ?? 'NULL') . "\n";

        // Campos de tecnomecánica
        echo "   🔧 TECNOMECÁNICA:\n";
        echo "   - tecnomecanica_numero: " . ($data['tecnomecanica_numero'] ?? 'NULL') . "\n";
        echo "   - tecnomecanica_vencimiento: " . ($data['tecnomecanica_vencimiento'] ?? 'NULL') . "\n";
        echo "   - tecnomecanica_foto_url: " . ($data['tecnomecanica_foto_url'] ?? 'NULL') . "\n";

        // Campos de tarjeta de propiedad
        echo "   📄 TARJETA DE PROPIEDAD:\n";
        echo "   - tarjeta_propiedad_numero: " . ($data['tarjeta_propiedad_numero'] ?? 'NULL') . "\n";
        echo "   - tarjeta_propiedad_foto_url: " . ($data['tarjeta_propiedad_foto_url'] ?? 'NULL') . "\n";

    } else {
        echo "   No hay datos en detalles_conductor\n";
    }
    echo "\n";

    // Limpiar TODOS los campos de documentos (respetando restricciones NOT NULL)
    echo "2. Limpiando TODOS los campos de documentos...\n";
    $stmt2 = $pdo->prepare("
        UPDATE detalles_conductor SET
            -- Licencia (NOT NULL fields necesitan valores por defecto)
            licencia_conduccion = '',
            licencia_vencimiento = CURDATE(),
            licencia_expedicion = NULL,
            licencia_categoria = 'C1',
            licencia_foto_url = NULL,

            -- Vehículo (NOT NULL fields necesitan valores por defecto)
            vehiculo_tipo = 'moto',
            vehiculo_marca = '',
            vehiculo_modelo = '',
            vehiculo_anio = NULL,
            vehiculo_color = '',
            vehiculo_placa = '',

            -- Seguro
            aseguradora = NULL,
            numero_poliza_seguro = NULL,
            vencimiento_seguro = NULL,
            seguro_foto_url = NULL,

            -- SOAT
            soat_numero = NULL,
            soat_vencimiento = NULL,
            soat_foto_url = NULL,

            -- Tecnomecánica
            tecnomecanica_numero = NULL,
            tecnomecanica_vencimiento = NULL,
            tecnomecanica_foto_url = NULL,

            -- Tarjeta de propiedad
            tarjeta_propiedad_numero = NULL,
            tarjeta_propiedad_foto_url = NULL,

            -- Estados
            estado_verificacion = 'pendiente',
            estado_aprobacion = 'pendiente',
            aprobado = 0
    ");
    $result2 = $stmt2->execute();
    $updatedCount = $stmt2->rowCount();
    echo "   ✅ Limpiados datos de documentos en $updatedCount registro(s)\n\n";

    // Verificar después de limpiar
    echo "3. Verificación después de limpiar:\n";
    $stmt3 = $pdo->query("SELECT * FROM detalles_conductor LIMIT 1");
    $dataAfter = $stmt3->fetch(PDO::FETCH_ASSOC);

    if ($dataAfter) {
        $camposLimpios = 0;
        $totalCampos = 0;

        // Verificar campos de documentos
        $documentFields = [
            'licencia_conduccion', 'licencia_vencimiento', 'licencia_expedicion', 'licencia_foto_url',
            'vehiculo_tipo', 'vehiculo_marca', 'vehiculo_modelo', 'vehiculo_anio', 'vehiculo_color', 'vehiculo_placa',
            'aseguradora', 'numero_poliza_seguro', 'vencimiento_seguro', 'seguro_foto_url',
            'soat_numero', 'soat_vencimiento', 'soat_foto_url',
            'tecnomecanica_numero', 'tecnomecanica_vencimiento', 'tecnomecanica_foto_url',
            'tarjeta_propiedad_numero', 'tarjeta_propiedad_foto_url'
        ];

        foreach ($documentFields as $field) {
            $totalCampos++;
            if ($dataAfter[$field] === null) {
                $camposLimpios++;
            }
        }

        echo "   Campos de documentos: $camposLimpios/$totalCampos limpios\n";
        echo "   Estado verificación: " . ($dataAfter['estado_verificacion'] ?? 'NULL') . "\n";
        echo "   Estado aprobación: " . ($dataAfter['estado_aprobacion'] ?? 'NULL') . "\n";
        echo "   Aprobado: " . ($dataAfter['aprobado'] ?? 'NULL') . "\n";

        if ($camposLimpios == $totalCampos) {
            echo "\n   ✅ ¡LIMPIEZA COMPLETA! Todos los campos de documentos están vacíos.\n";
        } else {
            echo "\n   ⚠️  Algunos campos aún tienen datos.\n";
        }
    }

    echo "\n🎉 Limpieza de datos de documentos completada.\n";
    echo "   La aplicación debería mostrar que no hay documentos registrados.\n";

} catch (Exception $e) {
    echo "❌ Error durante la limpieza: " . $e->getMessage() . "\n";
    exit(1);
}
?>