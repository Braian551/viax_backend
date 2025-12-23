<?php
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "=== Ejecutando migración 006 manualmente ===\n\n";
    
    // 1. Agregar columnas
    echo "1. Agregando columnas de fotos...\n";
    
    $columns = [
        "ALTER TABLE `detalles_conductor` ADD COLUMN `licencia_foto_url` VARCHAR(500) NULL COMMENT 'Ruta de la foto de la licencia' AFTER `licencia_categoria`",
        "ALTER TABLE `detalles_conductor` ADD COLUMN `soat_foto_url` VARCHAR(500) NULL COMMENT 'Ruta de la foto del SOAT' AFTER `soat_vencimiento`",
        "ALTER TABLE `detalles_conductor` ADD COLUMN `tecnomecanica_foto_url` VARCHAR(500) NULL COMMENT 'Ruta de la foto de la tecnomecánica' AFTER `tecnomecanica_vencimiento`",
        "ALTER TABLE `detalles_conductor` ADD COLUMN `tarjeta_propiedad_foto_url` VARCHAR(500) NULL COMMENT 'Ruta de la foto de la tarjeta de propiedad' AFTER `tarjeta_propiedad_numero`",
        "ALTER TABLE `detalles_conductor` ADD COLUMN `seguro_foto_url` VARCHAR(500) NULL COMMENT 'Ruta de la foto del seguro' AFTER `vencimiento_seguro`"
    ];
    
    foreach($columns as $sql) {
        try {
            $db->exec($sql);
            echo "   ✓ Columna agregada\n";
        } catch(PDOException $e) {
            if(strpos($e->getMessage(), 'Duplicate column') !== false) {
                echo "   ⚠ Columna ya existe\n";
            } else {
                throw $e;
            }
        }
    }
    
    // 2. Crear tabla historial
    echo "\n2. Creando tabla documentos_conductor_historial...\n";
    
    $createTable = "CREATE TABLE IF NOT EXISTS `documentos_conductor_historial` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `conductor_id` BIGINT UNSIGNED NOT NULL,
      `tipo_documento` ENUM('licencia', 'soat', 'tecnomecanica', 'tarjeta_propiedad', 'seguro') NOT NULL,
      `url_documento` VARCHAR(500) NOT NULL,
      `fecha_carga` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `activo` TINYINT(1) DEFAULT 1 COMMENT '1 si es el documento actual, 0 si fue reemplazado',
      `reemplazado_en` TIMESTAMP NULL DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_conductor_tipo` (`conductor_id`, `tipo_documento`),
      KEY `idx_fecha_carga` (`fecha_carga`),
      KEY `idx_activo` (`activo`),
      CONSTRAINT `fk_doc_historial_conductor` FOREIGN KEY (`conductor_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Historial de documentos subidos por conductores'";
    
    $db->exec($createTable);
    echo "   ✓ Tabla creada\n";
    
    echo "\n=== ✅ Migración completada exitosamente ===\n";
    
} catch(Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
