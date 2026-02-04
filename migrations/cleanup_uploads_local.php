<?php
/**
 * Script de limpieza de uploads locales
 * =====================================
 * 
 * Este script elimina cualquier carpeta 'uploads' local que pueda existir
 * en el backend. TODOS los archivos deben estar en Cloudflare R2.
 * 
 * EJECUTAR CON PRECAUCIÓN - Elimina archivos permanentemente.
 */

require_once __DIR__ . '/../config/database.php';

echo "============================================\n";
echo "  LIMPIEZA DE CARPETA UPLOADS LOCAL\n";
echo "============================================\n\n";

$uploadsDir = __DIR__ . '/../uploads';

// Función para eliminar directorio recursivamente
function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = array_diff(scandir($dir), array('.', '..'));
    
    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            @unlink($path);
        }
    }
    
    return @rmdir($dir);
}

// Verificar si existe la carpeta uploads
if (file_exists($uploadsDir)) {
    echo "⚠️  Se encontró carpeta uploads en: $uploadsDir\n\n";
    
    // Listar contenido antes de eliminar
    echo "Contenido encontrado:\n";
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    $count = 0;
    foreach ($iterator as $file) {
        $count++;
        echo "  - " . $file->getPathname() . "\n";
        if ($count > 50) {
            echo "  ... (más archivos)\n";
            break;
        }
    }
    
    echo "\n¿Desea eliminar esta carpeta? [AUTOMÁTICO - SÍ]\n";
    
    // Eliminar
    if (deleteDirectory($uploadsDir)) {
        echo "\n✅ Carpeta uploads eliminada exitosamente\n";
    } else {
        echo "\n❌ Error al eliminar la carpeta. Puede requerir permisos adicionales.\n";
        echo "   Elimine manualmente: $uploadsDir\n";
    }
    
} else {
    echo "✅ No existe carpeta uploads local - Correcto!\n";
}

// Verificar BD para referencias a uploads locales
echo "\n\n============================================\n";
echo "  VERIFICANDO REFERENCIAS EN BASE DE DATOS\n";
echo "============================================\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Buscar referencias a 'uploads/' en detalles_conductor
    $columns = [
        'licencia_foto_url',
        'soat_foto_url', 
        'tecnomecanica_foto_url',
        'tarjeta_propiedad_foto_url',
        'seguro_foto_url',
        'foto_vehiculo'
    ];
    
    $found = false;
    
    foreach ($columns as $col) {
        $query = "SELECT COUNT(*) as count FROM detalles_conductor WHERE $col LIKE 'uploads/%' OR $col LIKE '../uploads/%'";
        $stmt = $db->query($query);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            echo "⚠️  Encontradas {$result['count']} referencias a uploads en columna $col\n";
            $found = true;
        }
    }
    
    // Buscar en documentos_verificacion
    $query = "SELECT COUNT(*) as count FROM documentos_verificacion WHERE ruta_archivo LIKE 'uploads/%' OR ruta_archivo LIKE '../uploads/%'";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['count'] > 0) {
        echo "⚠️  Encontradas {$result['count']} referencias a uploads en documentos_verificacion\n";
        $found = true;
    }
    
    if (!$found) {
        echo "✅ No se encontraron referencias a uploads locales en la BD\n";
    } else {
        echo "\nEjecute 'cleanup_legacy_images.php' para limpiar estas referencias.\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error al verificar BD: " . $e->getMessage() . "\n";
}

echo "\n============================================\n";
echo "  VERIFICACIÓN COMPLETADA\n";
echo "============================================\n";
echo "\n📌 Recordatorio: Todos los archivos deben estar en Cloudflare R2\n";
echo "📌 Las fotos biométricas NO se guardan, solo plantillas en BD\n";
?>
