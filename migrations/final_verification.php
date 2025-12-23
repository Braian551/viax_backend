<?php
/**
 * Script para verificar el estado final después de la limpieza
 */

require_once '../config/database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();

    echo "🔍 Verificando estado final después de la limpieza...\n\n";

    $stmt = $pdo->query("SELECT * FROM detalles_conductor LIMIT 1");
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($data) {
        echo "📄 LICENCIA:\n";
        echo "   - Número: '" . ($data['licencia_conduccion'] ?? 'NULL') . "'\n";
        echo "   - Vencimiento: " . ($data['licencia_vencimiento'] ?? 'NULL') . "\n";
        echo "   - Categoría: " . ($data['licencia_categoria'] ?? 'NULL') . "\n";
        echo "   - Foto URL: " . ($data['licencia_foto_url'] ?? 'NULL') . "\n";

        echo "🚗 VEHÍCULO:\n";
        echo "   - Tipo: " . ($data['vehiculo_tipo'] ?? 'NULL') . "\n";
        echo "   - Marca: '" . ($data['vehiculo_marca'] ?? 'NULL') . "'\n";
        echo "   - Modelo: '" . ($data['vehiculo_modelo'] ?? 'NULL') . "'\n";
        echo "   - Placa: '" . ($data['vehiculo_placa'] ?? 'NULL') . "'\n";
        echo "   - Foto URL: " . ($data['vehiculo_foto_url'] ?? 'NULL') . "\n";

        echo "🛡️ SEGURO:\n";
        echo "   - Aseguradora: " . ($data['aseguradora'] ?? 'NULL') . "\n";
        echo "   - Póliza: " . ($data['numero_poliza_seguro'] ?? 'NULL') . "\n";
        echo "   - Foto URL: " . ($data['seguro_foto_url'] ?? 'NULL') . "\n";

        echo "📋 SOAT:\n";
        echo "   - Número: " . ($data['soat_numero'] ?? 'NULL') . "\n";
        echo "   - Foto URL: " . ($data['soat_foto_url'] ?? 'NULL') . "\n";

        echo "🔧 TECNOMECÁNICA:\n";
        echo "   - Número: " . ($data['tecnomecanica_numero'] ?? 'NULL') . "\n";
        echo "   - Foto URL: " . ($data['tecnomecanica_foto_url'] ?? 'NULL') . "\n";

        echo "📄 TARJETA DE PROPIEDAD:\n";
        echo "   - Número: " . ($data['tarjeta_propiedad_numero'] ?? 'NULL') . "\n";
        echo "   - Foto URL: " . ($data['tarjeta_propiedad_foto_url'] ?? 'NULL') . "\n";

        echo "\n📊 ANÁLISIS PARA LA APLICACIÓN:\n";

        // Verificar si la aplicación consideraría estos datos como "registrados"
        $documentosRegistrados = [];

        if (!empty(trim($data['licencia_conduccion'] ?? ''))) {
            $documentosRegistrados[] = "Licencia";
        }
        if (!empty(trim($data['vehiculo_marca'] ?? '')) || !empty(trim($data['vehiculo_modelo'] ?? ''))) {
            $documentosRegistrados[] = "Vehículo";
        }
        if (!empty($data['aseguradora']) || !empty($data['numero_poliza_seguro'])) {
            $documentosRegistrados[] = "Seguro";
        }
        if (!empty($data['soat_numero'])) {
            $documentosRegistrados[] = "SOAT";
        }
        if (!empty($data['tecnomecanica_numero'])) {
            $documentosRegistrados[] = "Tecnomecánica";
        }
        if (!empty($data['tarjeta_propiedad_numero'])) {
            $documentosRegistrados[] = "Tarjeta de Propiedad";
        }

        if (empty($documentosRegistrados)) {
            echo "   ✅ LA APLICACIÓN DEBERÍA MOSTRAR: 'No hay documentos registrados'\n";
            echo "   ✅ Los campos están vacíos o tienen solo valores por defecto\n";
        } else {
            echo "   ⚠️  LA APLICACIÓN AÚN PODRÍA MOSTRAR: " . implode(', ', $documentosRegistrados) . "\n";
        }

        echo "\n🔄 ESTADO DE VERIFICACIÓN:\n";
        echo "   - Estado verificación: " . ($data['estado_verificacion'] ?? 'NULL') . "\n";
        echo "   - Estado aprobación: " . ($data['estado_aprobacion'] ?? 'NULL') . "\n";
        echo "   - Aprobado: " . ($data['aprobado'] ?? 'NULL') . "\n";

    } else {
        echo "No hay datos en detalles_conductor\n";
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>