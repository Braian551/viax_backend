<?php
/**
 * Script de prueba para dashboard_stats.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Test Dashboard Stats API</h1>";

// Simular petición GET
$_GET['admin_id'] = 1; // ID del admin en tu base de datos

echo "<h2>Parámetros de entrada:</h2>";
echo "<pre>";
print_r($_GET);
echo "</pre>";

echo "<h2>Ejecutando dashboard_stats.php...</h2>";

// Capturar la salida
ob_start();
include 'dashboard_stats.php';
$output = ob_get_clean();

echo "<h2>Respuesta JSON:</h2>";
echo "<pre>";
echo $output;
echo "</pre>";

echo "<h2>Respuesta formateada:</h2>";
$data = json_decode($output, true);
if ($data) {
    echo "<pre>";
    print_r($data);
    echo "</pre>";
} else {
    echo "<p style='color: red;'>Error al decodificar JSON</p>";
    echo "<p>Error: " . json_last_error_msg() . "</p>";
}

echo "<h2>Logs de errores (si existen):</h2>";
$logFile = '../logs/error.log';
if (file_exists($logFile)) {
    $logs = file_get_contents($logFile);
    echo "<pre style='background: #f5f5f5; padding: 10px; overflow-x: auto;'>";
    echo htmlspecialchars(substr($logs, -2000)); // Últimos 2000 caracteres
    echo "</pre>";
} else {
    echo "<p>No se encontró archivo de log</p>";
}
?>
