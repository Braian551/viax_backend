<?php
// Simulate GET request parameters
$_GET['action'] = 'list';
$_SERVER['REQUEST_METHOD'] = 'GET';

// Capture output
ob_start();
include '../admin/empresas.php';
$output = ob_get_clean();

echo "=== RAW OUTPUT START ===\n";
echo $output;
echo "\n=== RAW OUTPUT END ===\n";
?>
