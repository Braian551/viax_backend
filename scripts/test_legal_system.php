<?php
/**
 * Módulo de Pruebas Locales (CLI) para Validación Legal
 */

if (isset($argv[1]) && $argv[1] === 'run_case') {
    require_once __DIR__ . '/../config/app.php';
    require_once __DIR__ . '/../middleware/LegalMiddleware.php';
    $userId = (int) $argv[2];
    $role = $argv[3];
    LegalMiddleware::checkLegalAcceptance($userId, $role);
    echo "\n[RESULTADO_FINAL] SUCCESS\n";
    exit(0);
}

echo "==========================================\n";
echo " PRUEBAS FUNCIONALES - SISTEMA LEGAL VIAX \n";
echo "==========================================\n\n";

function runTestCase($name, $userId, $role) {
    echo "➜ Test: $name \n";
    $cmd = "php " . escapeshellarg(__FILE__) . " run_case " . (int)$userId . " " . escapeshellarg($role);
    $output = [];
    $ret = 0;
    exec($cmd . " 2>&1", $output, $ret);
    
    $outStr = implode("\n", $output);
    if (strpos($outStr, '[RESULTADO_FINAL] SUCCESS') !== false) {
        echo "   ✅ ACCESO PERMITIDO\n\n";
    } else {
        echo "   ❌ ACCESO BLOQUEADO (O Error)\n";
        // Imprimir el JSON devuelto o el error
        $lastLine = end($output);
        echo "      Respuesta: $lastLine\n\n";
    }
}

// 1. Prueba usuario inexistente (Sin aceptación)
runTestCase("Usuario sin aceptación (ID 99999)", 99999, "cliente");

// *Para que las pruebas 2 y 3 funcionen, el desarrollador debe ejecutar 
// primero las migraciones y/o llamar al endpoint POST /legal/accept.php

echo "==========================================\n";
echo "\nSiguientes pasos manuales sugeridos:\n";
echo "1. Ejecuta migraciones locales: php scripts/run_migrations.php\n";
echo "2. Llama POST local/legal/accept.php para un ID de prueba.\n";
echo "3. Vuelve a ejecutar este script usando ese ID de prueba.\n";
