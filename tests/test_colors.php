<?php
// Test script for get_colors.php

$url = 'http://localhost/viax/backend/utils/get_colors.php';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";

$data = json_decode($response, true);
if ($data && isset($data['success']) && $data['success'] === true && count($data['data']) > 0) {
    echo "SUCCESS: Colors fetched correctly.\n";
    echo "Count: " . count($data['data']) . "\n";
    echo "First Color: " . $data['data'][0]['nombre'] . " (" . $data['data'][0]['hex_code'] . ")\n";
} else {
    echo "FAILURE: Could not fetch colors.\n";
}
?>
