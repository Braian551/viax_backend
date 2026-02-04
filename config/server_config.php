<?php
/**
 * Server Configuration Helper
 * Loads SERVER_URL from .env for generating absolute URLs
 */

// Load .env file
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue; // Skip comments
        if (strpos($line, '=') === false) continue;
        
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        
        if (!isset($_ENV[$key])) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

/**
 * Get the server URL from environment
 * @return string The base server URL
 */
function getServerUrl() {
    return getenv('SERVER_URL') ?: 'http://localhost/viax/backend';
}

/**
 * Build a full URL for R2 proxy
 * @param string $key The R2 storage key
 * @return string Full URL to access the resource
 */
function getR2ProxyUrl($key) {
    $serverUrl = getServerUrl();
    return $serverUrl . '/r2_proxy.php?key=' . urlencode($key);
}
?>
