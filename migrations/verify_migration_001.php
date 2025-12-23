<?php
/**
 * Verificación de la migración 001
 */
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    echo "=== Verificando migración 001 ===\n\n";

    // Tablas creadas
    $tablesToCheck = ['conductores_favoritos', 'historial_confianza'];
    foreach ($tablesToCheck as $table) {
        $stmt = $db->prepare("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :table)");
        $stmt->execute(['table' => $table]);
        $exists = $stmt->fetchColumn();
        echo "Tabla '$table': " . ($exists ? 'EXISTS' : 'MISSING') . "\n";
    }

    echo "\n=== Índices relevantes ===\n";
    $indexes = ['idx_conductor_favoritos','idx_es_favorito','idx_conductor_confianza','idx_score_confianza','idx_zona_frecuente','idx_confianza_score_viajes'];
    foreach ($indexes as $idx) {
        $stmt = $db->prepare("SELECT EXISTS (SELECT 1 FROM pg_indexes WHERE schemaname='public' AND indexname = :idx)");
        $stmt->execute(['idx' => $idx]);
        $exists = $stmt->fetchColumn();
        echo "Índice '$idx': " . ($exists ? 'EXISTS' : 'MISSING') . "\n";
    }

    echo "\n=== Trigger 'trg_historial_confianza_actualizado_en' ===\n";
    $stmt = $db->query("SELECT tgname, tgtype::text FROM pg_trigger WHERE tgname = 'trg_historial_confianza_actualizado_en'");
    $trigger = $stmt->fetchAll();
    if (count($trigger) > 0) {
        echo "Trigger 'trg_historial_confianza_actualizado_en' found.\n";
    } else {
        echo "Trigger 'trg_historial_confianza_actualizado_en' missing.\n";
    }

    echo "\n=== Vista conductores_confianza_ranking ===\n";
    $stmt = $db->prepare("SELECT EXISTS(SELECT 1 FROM information_schema.views WHERE table_schema = 'public' AND table_name = 'conductores_confianza_ranking')");
    $stmt->execute();
    echo "Vista 'conductores_confianza_ranking': " . ($stmt->fetchColumn() ? 'EXISTS' : 'MISSING') . "\n";

    echo "\n✅ Verificación completa.\n";

} catch (PDOException $e) {
    echo "❌ Error en la verificación: " . $e->getMessage() . "\n";
    exit(1);
}

?>
