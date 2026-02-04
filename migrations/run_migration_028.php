<?php
/**
 * Migración 028: Conductores Obligatoriamente Vinculados a Empresa
 * 
 * Este script ejecuta la migración que:
 * - Elimina la opción de conductor independiente
 * - Crea sistema de solicitudes de vinculación a empresas
 * - Suspende conductores sin empresa hasta que se vinculen
 */

require_once '../config/database.php';

echo "========================================\n";
echo "Migración 028: Conductor Vinculado a Empresa\n";
echo "========================================\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Leer el archivo SQL
    $sqlFile = __DIR__ . '/028_require_empresa_conductor.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("Archivo de migración no encontrado: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    echo "📄 Leyendo archivo de migración...\n";
    
    // Verificar conductores sin empresa antes de migrar
    $checkQuery = "SELECT COUNT(*) as total FROM usuarios WHERE tipo_usuario = 'conductor' AND empresa_id IS NULL";
    $checkStmt = $db->query($checkQuery);
    $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
    $conductoresSinEmpresa = $result['total'] ?? 0;
    
    echo "📊 Conductores sin empresa actual: $conductoresSinEmpresa\n";
    
    if ($conductoresSinEmpresa > 0) {
        echo "⚠️  ADVERTENCIA: Estos conductores serán suspendidos hasta vincularse a una empresa.\n\n";
    }
    
    // Ejecutar migración
    echo "🚀 Ejecutando migración...\n\n";
    
    $db->exec($sql);
    
    echo "✅ Migración ejecutada exitosamente!\n\n";
    
    // Verificar resultados
    echo "📋 Verificando resultados:\n";
    echo "─────────────────────────\n";
    
    // 1. Verificar tabla solicitudes_vinculacion_conductor
    $tableCheck = $db->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'solicitudes_vinculacion_conductor')");
    $tableExists = $tableCheck->fetchColumn();
    echo ($tableExists ? "✅" : "❌") . " Tabla solicitudes_vinculacion_conductor\n";
    
    // 2. Verificar constraint
    $constraintCheck = $db->query("SELECT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'chk_conductor_empresa_required')");
    $constraintExists = $constraintCheck->fetchColumn();
    echo ($constraintExists ? "✅" : "❌") . " Constraint chk_conductor_empresa_required\n";
    
    // 3. Verificar vista
    $viewCheck = $db->query("SELECT EXISTS (SELECT FROM information_schema.views WHERE table_name = 'conductores_pendientes_vinculacion')");
    $viewExists = $viewCheck->fetchColumn();
    echo ($viewExists ? "✅" : "❌") . " Vista conductores_pendientes_vinculacion\n";
    
    // 4. Contar conductores suspendidos
    $suspendedQuery = "SELECT COUNT(*) as total FROM usuarios WHERE tipo_usuario = 'conductor' AND estado_vinculacion = 'pendiente_empresa'";
    $suspendedStmt = $db->query($suspendedQuery);
    $suspendedResult = $suspendedStmt->fetch(PDO::FETCH_ASSOC);
    $conductoresSuspendidos = $suspendedResult['total'] ?? 0;
    
    echo "\n📊 Conductores en estado 'pendiente_empresa': $conductoresSuspendidos\n";
    
    // 5. Verificar funciones
    $funcCheck = $db->query("SELECT proname FROM pg_proc WHERE proname IN ('aprobar_vinculacion_conductor', 'rechazar_vinculacion_conductor')");
    $funciones = $funcCheck->fetchAll(PDO::FETCH_COLUMN);
    echo "✅ Funciones creadas: " . implode(', ', $funciones) . "\n";
    
    echo "\n========================================\n";
    echo "✅ MIGRACIÓN COMPLETADA EXITOSAMENTE\n";
    echo "========================================\n";
    
    echo "\n📝 PRÓXIMOS PASOS:\n";
    echo "1. Actualizar backend para requerir empresa_id en registro\n";
    echo "2. Actualizar frontend para eliminar opción 'Independiente'\n";
    echo "3. Notificar a conductores sin empresa que deben vincularse\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "\nDetalles del error:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
?>
