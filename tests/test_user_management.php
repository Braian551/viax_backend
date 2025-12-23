<?php
/**
 * Test script para user_management.php
 */

// Simular request GET
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['admin_id'] = '1';

echo "=== TEST USER MANAGEMENT ===\n\n";
echo "Admin ID: " . $_GET['admin_id'] . "\n";
echo "Método: " . $_SERVER['REQUEST_METHOD'] . "\n\n";

// Incluir el archivo principal
include 'user_management.php';
?>
