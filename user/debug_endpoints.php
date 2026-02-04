<?php
// Suppress warnings to get clean JSON
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

// Mock $_GET parameters
$_GET['usuario_id'] = 276;
$_GET['page'] = 1;
$_GET['limit'] = 20;

// Capture output of trip history
ob_start();
include 'get_trip_history.php';
$history_output = ob_get_clean();

// Decode to check validity
$json = json_decode($history_output, true);
$history_status = "UNKNOWN";
$trips_count = 0;

if ($json === null) {
    $history_status = "INVALID JSON (" . substr($history_output, 0, 50) . "...)";
} else {
    $history_status = $json['success'] ? 'SUCCESS' : 'FAILURE: ' . ($json['error'] ?? 'Unknown');
    $trips_count = count($json['viajes'] ?? []);
}

// Check Payment Summary
$_GET['usuario_id'] = 276;
unset($_GET['page']);
unset($_GET['limit']);

ob_start();
include 'get_payment_summary.php';
$summary_output = ob_get_clean();

$json_summary = json_decode($summary_output, true);
$summary_status = "UNKNOWN";
$total_paid = 0;

if ($json_summary === null) {
    $summary_status = "INVALID JSON (" . substr($summary_output, 0, 50) . "...)";
} else {
    $summary_status = $json_summary['success'] ? 'SUCCESS' : 'FAILURE: ' . ($json_summary['error'] ?? 'Unknown');
    $total_paid = $json_summary['total_pagado'] ?? -1;
}

// Output Report
echo "--- DEBUG REPORT FOR USER 276 ---\n";
echo "Trip History Endpoint: $history_status\n";
echo "Trips Found: $trips_count\n";
if ($trips_count > 0) {
    echo "First Trip ID: " . $json['viajes'][0]['id'] . "\n";
    echo "First Trip Conductor: " . $json['viajes'][0]['conductor_nombre'] . "\n";
} else {
    echo "Raw History Output (First 100 chars): " . substr($history_output, 0, 100) . "\n";
}

echo "\nPayment Summary Endpoint: $summary_status\n";
echo "Total Paid: $total_paid\n";
if ($json_summary) {
    echo "Total Viajes: " . ($json_summary['total_viajes'] ?? 'N/A') . "\n";
}
?>
