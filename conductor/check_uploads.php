<?php
/**
 * Script de verificación del directorio de uploads
 */

echo "=== VERIFICACIÓN DEL DIRECTORIO DE UPLOADS ===\n\n";

// Definir rutas
$uploadDir = __DIR__ . '/../uploads/documentos/';
$testFile = $uploadDir . 'test_write.txt';

echo "Directorio de uploads: $uploadDir\n";

// Verificar si el directorio existe
if (!file_exists($uploadDir)) {
    echo "❌ El directorio NO existe\n";
    echo "Intentando crear...\n";

    if (mkdir($uploadDir, 0755, true)) {
        echo "✅ Directorio creado exitosamente\n";
    } else {
        echo "❌ Error al crear el directorio\n";
        exit(1);
    }
} else {
    echo "✅ El directorio existe\n";
}

// Verificar permisos
if (is_writable($uploadDir)) {
    echo "✅ El directorio tiene permisos de escritura\n";
} else {
    echo "❌ El directorio NO tiene permisos de escritura\n";
    echo "Permisos actuales: " . substr(sprintf('%o', fileperms($uploadDir)), -4) . "\n";
}

// Probar escritura
echo "\nProbando escritura...\n";
if (file_put_contents($testFile, 'Test content ' . date('Y-m-d H:i:s'))) {
    echo "✅ Se pudo escribir en el directorio\n";
    unlink($testFile); // Limpiar archivo de prueba
    echo "✅ Archivo de prueba eliminado\n";
} else {
    echo "❌ No se pudo escribir en el directorio\n";
}

// Verificar configuración de PHP
echo "\n=== CONFIGURACIÓN DE PHP ===\n";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "post_max_size: " . ini_get('post_max_size') . "\n";
echo "max_file_uploads: " . ini_get('max_file_uploads') . "\n";
echo "file_uploads: " . (ini_get('file_uploads') ? 'enabled' : 'disabled') . "\n";
echo "upload_tmp_dir: " . (ini_get('upload_tmp_dir') ?: 'default') . "\n";

echo "\n=== VERIFICACIÓN COMPLETA ===\n";
?>