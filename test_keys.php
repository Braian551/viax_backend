<?php
// Test script to verify .env loading and API keys
$envFile = __DIR__ . '/config/.env';
echo "Checking .env file at: $envFile\n";

if (file_exists($envFile)) {
    echo ".env file EXISTS.\n";
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        echo "Found Key: $key\n";
        if ($key === 'MAPBOX_PUBLIC_TOKEN') {
             echo "MAPBOX_PUBLIC_TOKEN VALUE: " . substr($value, 0, 5) . "...\n";
        }
    }
} else {
    echo ".env file NOT FOUND.\n";
}
