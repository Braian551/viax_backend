<?php
/**
 * Quick test for create_share endpoint
 */
$data = json_encode(['user_id' => 1, 'expires_minutes' => 30]);

$ch = curl_init('http://localhost/location_sharing/create_share.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$result = curl_exec($ch);
curl_close($ch);

echo $result . "\n";
