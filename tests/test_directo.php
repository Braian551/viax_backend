<?php
/**
 * Test directo del endpoint (simulando petición HTTP)
 */

// Simular variables de servidor
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_GET['conductor_id'] = '7';
$_GET['page'] = '1';
$_GET['limit'] = '20';

// Capturar output
ob_start();

// Incluir el archivo
include 'get_historial.php';

// Obtener output
$output = ob_get_clean();

// Mostrar resultado
echo "========================================\n";
echo "TEST DIRECTO DE get_historial.php\n";
echo "========================================\n\n";

echo "Output:\n";
echo $output;
echo "\n\n";

// Decodificar JSON
$data = json_decode($output, true);

if ($data) {
    echo "JSON válido: ✅\n";
    echo "Success: " . ($data['success'] ? '✅' : '❌') . "\n";
    
    if (isset($data['message'])) {
        echo "Message: {$data['message']}\n";
    }
    
    if (isset($data['viajes'])) {
        echo "Total viajes: " . count($data['viajes']) . "\n";
    }
} else {
    echo "JSON inválido: ❌\n";
}

echo "\n========================================\n";
