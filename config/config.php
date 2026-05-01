<?php
// backend/config/config.php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/RateLimiter.php';
require_once __DIR__ . '/../core/Feature.php';

error_reporting(E_ALL);
ini_set('display_errors', env_value('APP_ENV', 'production') === 'production' ? '0' : '1');

if (!function_exists('viax_allowed_origins')) {
    /**
     * @return array<int,string>
     */
    function viax_allowed_origins(): array
    {
        $allowed = [
            'https://viaxcol.online',
            'https://www.viaxcol.online',
        ];

        $appEnv = strtolower(trim((string)env_value('APP_ENV', 'production')));
        if ($appEnv !== 'production') {
            $allowed[] = 'capacitor://localhost';
            $allowed[] = 'http://localhost:3000';
        }

        return $allowed;
    }
}

if (!function_exists('viax_apply_cors_headers')) {
    function viax_apply_cors_headers(): void
    {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Idempotency-Key, X-Timestamp, X-Nonce, X-Signature, X-Device-Fingerprint, X-Device-Model, X-Device-Platform, X-Integrity-Score, X-Integrity-Warning');
        header('Vary: Origin');

        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        $allowedOrigins = viax_allowed_origins();
        $originAllowed = $origin !== '' && in_array($origin, $allowedOrigins, true);
        if ($originAllowed) {
            header('Access-Control-Allow-Origin: ' . $origin);
        }

        $strictCors = Feature::enabled('strict_cors_block', env_value('APP_ENV', 'production') === 'production');
        $isOptions = isset($_SERVER['REQUEST_METHOD']) && strtoupper((string)$_SERVER['REQUEST_METHOD']) === 'OPTIONS';
        $hasOriginHeader = $origin !== '';

        if ($strictCors && $hasOriginHeader && !$originAllowed) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'CORS blocked']);
            exit;
        }

        if ($isOptions) {
            http_response_code(200);
            exit;
        }
    }
}

if (!function_exists('viax_apply_global_rate_limit')) {
    function viax_apply_global_rate_limit(): void
    {
        $enabled = env_value('GLOBAL_RATE_LIMIT_ENABLED', '1');
        $normalized = strtolower(trim((string)$enabled));
        if (!in_array($normalized, ['1', 'true', 'yes', 'on', 't', 'si', 's'], true)) {
            return;
        }

        $userId = trim((string)(
            $_SERVER['HTTP_X_USER_ID']
            ?? $_SERVER['HTTP_X_AUTH_USER_ID']
            ?? $_REQUEST['user_id']
            ?? ''
        ));
        $identity = $userId !== '' ? $userId : (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        if (!RateLimiter::check($identity, 100, 60, 'global_rate_limit')) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Rate limit exceeded']);
            exit;
        }
    }
}

viax_apply_cors_headers();
viax_apply_global_rate_limit();

require_once 'database.php';

function getJsonInput(): array
{
    $raw = file_get_contents('php://input');
    $input = json_decode((string)$raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'JSON invalido']);
        exit;
    }

    return is_array($input) ? $input : [];
}

function sendJsonResponse($success, $message, $data = [], $httpStatus = 200, $errorCode = null): void
{
    http_response_code((int)$httpStatus);

    $response = ['success' => (bool)$success, 'message' => (string)$message];
    if (!$success && !empty($errorCode)) {
        $response['error_code'] = $errorCode;
    }
    if (!empty($data)) {
        $response['data'] = $data;
    }

    echo json_encode($response);
    exit;
}
