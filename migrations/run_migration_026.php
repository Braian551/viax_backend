<?php
/**
 * Migración 026: Sistema de Plantillas Biométricas Optimizado
 * 
 * - Almacena encodings faciales (128 floats) en formato binario compacto
 * - Índices optimizados para búsquedas rápidas
 * - Tabla separada para plantillas bloqueadas (normalización)
 */

require_once __DIR__ . '/../config/database.php';

echo "==============================================\n";
echo "Migración 026: Plantillas Biométricas Optimizadas\n";
echo "==============================================\n\n";

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $driverName = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
    $isPostgres = ($driverName === 'pgsql');
    
    echo "Base de datos: " . ($isPostgres ? "PostgreSQL" : "MySQL") . "\n\n";
    
    if ($isPostgres) {
        // ========== POSTGRESQL ==========
        echo "Ejecutando migración PostgreSQL...\n\n";
        
        // 1. Columna plantilla_biometrica (BYTEA es más eficiente que TEXT para binarios)
        $conn->exec("ALTER TABLE detalles_conductor ADD COLUMN IF NOT EXISTS plantilla_biometrica TEXT");
        echo "  ✅ Columna plantilla_biometrica\n";
        
        // 2. Fecha verificación
        $conn->exec("ALTER TABLE detalles_conductor ADD COLUMN IF NOT EXISTS fecha_verificacion_biometrica TIMESTAMP");
        echo "  ✅ Columna fecha_verificacion_biometrica\n";
        
        // 3. Tabla normalizada para plantillas bloqueadas
        $conn->exec("
            CREATE TABLE IF NOT EXISTS plantillas_bloqueadas (
                id SERIAL PRIMARY KEY,
                plantilla_hash VARCHAR(64) NOT NULL,
                plantilla TEXT NOT NULL,
                usuario_origen_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
                razon VARCHAR(100) DEFAULT 'bloqueado',
                creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                activo BOOLEAN DEFAULT TRUE
            )
        ");
        echo "  ✅ Tabla plantillas_bloqueadas\n";
        
        // 4. Índices para búsquedas rápidas
        $conn->exec("CREATE INDEX IF NOT EXISTS idx_dc_estado_bio ON detalles_conductor(estado_biometrico) WHERE estado_biometrico IS NOT NULL");
        $conn->exec("CREATE INDEX IF NOT EXISTS idx_dc_plantilla_exists ON detalles_conductor(usuario_id) WHERE plantilla_biometrica IS NOT NULL");
        $conn->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_pb_hash ON plantillas_bloqueadas(plantilla_hash) WHERE activo = TRUE");
        $conn->exec("CREATE INDEX IF NOT EXISTS idx_pb_activo ON plantillas_bloqueadas(activo) WHERE activo = TRUE");
        echo "  ✅ Índices optimizados\n";
        
        // 5. Eliminar tabla vieja si existe
        $conn->exec("DROP TABLE IF EXISTS plantillas_biometricas_bloqueadas");
        echo "  ✅ Limpieza tabla antigua\n";
        
    } else {
        // ========== MYSQL ==========
        echo "Ejecutando migración MySQL...\n\n";
        
        // 1. Columna plantilla
        $check = $conn->query("SHOW COLUMNS FROM detalles_conductor LIKE 'plantilla_biometrica'");
        if ($check->rowCount() == 0) {
            $conn->exec("ALTER TABLE detalles_conductor ADD COLUMN plantilla_biometrica TEXT NULL");
        }
        echo "  ✅ Columna plantilla_biometrica\n";
        
        // 2. Fecha verificación
        $check = $conn->query("SHOW COLUMNS FROM detalles_conductor LIKE 'fecha_verificacion_biometrica'");
        if ($check->rowCount() == 0) {
            $conn->exec("ALTER TABLE detalles_conductor ADD COLUMN fecha_verificacion_biometrica TIMESTAMP NULL");
        }
        echo "  ✅ Columna fecha_verificacion_biometrica\n";
        
        // 3. Tabla normalizada
        $conn->exec("
            CREATE TABLE IF NOT EXISTS plantillas_bloqueadas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                plantilla_hash VARCHAR(64) NOT NULL,
                plantilla TEXT NOT NULL,
                usuario_origen_id INT NULL,
                razon VARCHAR(100) DEFAULT 'bloqueado',
                creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                activo TINYINT(1) DEFAULT 1,
                INDEX idx_hash_activo (plantilla_hash, activo),
                INDEX idx_activo (activo),
                FOREIGN KEY (usuario_origen_id) REFERENCES usuarios(id) ON DELETE SET NULL
            ) ENGINE=InnoDB
        ");
        echo "  ✅ Tabla plantillas_bloqueadas\n";
        
        // 4. Índices en detalles_conductor
        try {
            $conn->exec("CREATE INDEX idx_dc_estado_bio ON detalles_conductor(estado_biometrico)");
        } catch (Exception $e) { /* índice ya existe */ }
        echo "  ✅ Índices\n";
        
        // 5. Limpiar tabla vieja
        $conn->exec("DROP TABLE IF EXISTS plantillas_biometricas_bloqueadas");
        echo "  ✅ Limpieza\n";
    }
    
    echo "\n✅ Migración 026 completada!\n";
    echo "\n📊 Estructura optimizada:\n";
    echo "   - plantilla_biometrica: encoding JSON (128 floats)\n";
    echo "   - plantillas_bloqueadas: tabla normalizada con hash único\n";
    echo "   - Índices parciales para consultas rápidas\n";
    
} catch (PDOException $e) {
    echo "❌ Error BD: " . $e->getMessage() . "\n";
    exit(1);
}
?>
