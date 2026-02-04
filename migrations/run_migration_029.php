<?php
/**
 * Migración 029: Normalización de Empresas de Transporte
 * 
 * Este script ejecuta la migración que normaliza la tabla empresas_transporte
 * en múltiples tablas relacionadas siguiendo principios de arquitectura limpia.
 */

require_once '../config/database.php';

echo "========================================\n";
echo "Migración 029: Normalización Empresas\n";
echo "========================================\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Leer el archivo SQL
    $sqlFile = __DIR__ . '/029_normalize_empresas_transporte.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("Archivo de migración no encontrado: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    echo "📄 Leyendo archivo de migración...\n";
    
    // Verificar empresas existentes antes de migrar
    $checkQuery = "SELECT COUNT(*) as total FROM empresas_transporte";
    $checkStmt = $db->query($checkQuery);
    $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
    $totalEmpresas = $result['total'] ?? 0;
    
    echo "📊 Empresas existentes: $totalEmpresas\n\n";
    
    // Ejecutar migración
    echo "🚀 Ejecutando migración...\n\n";
    
    $db->exec($sql);
    
    echo "✅ Migración ejecutada exitosamente!\n\n";
    
    // Verificar resultados
    echo "📋 Verificando resultados:\n";
    echo "─────────────────────────\n";
    
    // 1. Verificar tablas creadas
    $tablas = ['empresas_contacto', 'empresas_representante', 'empresas_metricas', 'empresas_configuracion'];
    foreach ($tablas as $tabla) {
        $tableCheck = $db->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = '$tabla')");
        $tableExists = $tableCheck->fetchColumn();
        echo ($tableExists ? "✅" : "❌") . " Tabla $tabla\n";
    }
    
    // 2. Verificar vista
    $viewCheck = $db->query("SELECT EXISTS (SELECT FROM information_schema.views WHERE table_name = 'v_empresas_completas')");
    $viewExists = $viewCheck->fetchColumn();
    echo ($viewExists ? "✅" : "❌") . " Vista v_empresas_completas\n";
    
    // 3. Verificar datos migrados
    echo "\n📊 Datos migrados:\n";
    
    $contactoCount = $db->query("SELECT COUNT(*) FROM empresas_contacto")->fetchColumn();
    echo "   - Contactos: $contactoCount\n";
    
    $representanteCount = $db->query("SELECT COUNT(*) FROM empresas_representante")->fetchColumn();
    echo "   - Representantes: $representanteCount\n";
    
    $metricasCount = $db->query("SELECT COUNT(*) FROM empresas_metricas")->fetchColumn();
    echo "   - Métricas: $metricasCount\n";
    
    $configCount = $db->query("SELECT COUNT(*) FROM empresas_configuracion")->fetchColumn();
    echo "   - Configuraciones: $configCount\n";
    
    // 4. Verificar función
    $funcCheck = $db->query("SELECT proname FROM pg_proc WHERE proname = 'get_empresa_stats'");
    $funcion = $funcCheck->fetch(PDO::FETCH_COLUMN);
    echo "\n" . ($funcion ? "✅" : "❌") . " Función get_empresa_stats\n";
    
    echo "\n========================================\n";
    echo "✅ MIGRACIÓN COMPLETADA EXITOSAMENTE\n";
    echo "========================================\n";
    
    echo "\n📝 ESTRUCTURA NORMALIZADA:\n";
    echo "   empresas_transporte → Datos básicos\n";
    echo "   empresas_contacto → Info de contacto\n";
    echo "   empresas_representante → Rep. legal\n";
    echo "   empresas_metricas → Estadísticas\n";
    echo "   empresas_configuracion → Config. operativa\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "\nDetalles del error:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
?>
