<?php
// Script para verificar dependencias de PHPMailer en Railway
header('Content-Type: text/plain; charset=utf-8');

echo "Verificando dependencias de PHPMailer...\n\n";

$vendorPath = __DIR__ . '/vendor/autoload.php';
echo "1. Verificando vendor/autoload.php: ";
if (file_exists($vendorPath)) {
    echo "✓ Existe\n";
    require $vendorPath;

    echo "2. Verificando clase PHPMailer: ";
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        echo "✓ Disponible\n";

        echo "3. Verificando clase Exception: ";
        if (class_exists('PHPMailer\PHPMailer\Exception')) {
            echo "✓ Disponible\n";

            echo "4. Probando creación de instancia PHPMailer: ";
            try {
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                echo "✓ Exitosa\n";
            } catch (Exception $e) {
                echo "✗ Error: " . $e->getMessage() . "\n";
            }
        } else {
            echo "✗ No disponible\n";
        }
    } else {
        echo "✗ No disponible\n";
    }
} else {
    echo "✗ No existe\n";
    echo "   Ruta buscada: $vendorPath\n";
}

echo "\n5. Información del sistema:\n";
echo "   PHP Version: " . phpversion() . "\n";
echo "   Directorio actual: " . __DIR__ . "\n";
echo "   Archivos en directorio vendor/: " . (is_dir(__DIR__ . '/vendor') ? "Existe" : "No existe") . "\n";

if (is_dir(__DIR__ . '/vendor')) {
    $files = scandir(__DIR__ . '/vendor');
    echo "   Contenido de vendor/: " . implode(', ', array_filter($files, function($f) { return !in_array($f, ['.', '..']); })) . "\n";
}
?>