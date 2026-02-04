<?php
/**
 * API Keys Endpoint
 * 
 * Serves API keys securely from server environment variables.
 * Keys should be set in the server's environment, not in code.
 * 
 * Usage:
 *   GET /config/api_keys.php
 *   Returns: { success: true, data: { mapbox_token, tomtom_key, ... } }
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, X-App-Token');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit;
}

try {
    // Load environment variables from .env if available
    $envFile = __DIR__ . '/config/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0) continue; // Skip comments
            if (strpos($line, '=') === false) continue;
            
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            
            if (!empty($key)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }

    // Get API keys from environment variables
    $mapboxToken = getenv('MAPBOX_PUBLIC_TOKEN') ?: $_ENV['MAPBOX_PUBLIC_TOKEN'] ?? '';
    $mapboxSecretToken = getenv('MAPBOX_SECRET_TOKEN') ?: $_ENV['MAPBOX_SECRET_TOKEN'] ?? '';
    $tomtomApiKey = getenv('TOMTOM_API_KEY') ?: $_ENV['TOMTOM_API_KEY'] ?? '';
    $googlePlacesApiKey = getenv('GOOGLE_PLACES_API_KEY') ?: $_ENV['GOOGLE_PLACES_API_KEY'] ?? '';
    $nominatimUserAgent = getenv('NOMINATIM_USER_AGENT') ?: $_ENV['NOMINATIM_USER_AGENT'] ?? 'Viax App';
    $nominatimEmail = getenv('NOMINATIM_EMAIL') ?: $_ENV['NOMINATIM_EMAIL'] ?? '';

    // Check if essential keys are configured
    if (empty($mapboxToken)) {
        // No fallback - keys MUST be set via environment variables
        error_log("ERROR: MAPBOX_PUBLIC_TOKEN not set in environment. Set it in .env file.");
    }

    // Return the keys
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'API keys retrieved successfully',
        'data' => [
            'mapbox_public_token' => $mapboxToken,
            'tomtom_api_key' => $tomtomApiKey,
            'google_places_api_key' => $googlePlacesApiKey,
            'nominatim_user_agent' => $nominatimUserAgent,
            'nominatim_email' => $nominatimEmail,
            // Quota limits (can also be configured via env)
            'mapbox_monthly_request_limit' => (int)(getenv('MAPBOX_MONTHLY_LIMIT') ?: 100000),
            'mapbox_monthly_routing_limit' => (int)(getenv('MAPBOX_ROUTING_LIMIT') ?: 100000),
            'tomtom_daily_request_limit' => (int)(getenv('TOMTOM_DAILY_LIMIT') ?: 2500),
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving API keys: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
