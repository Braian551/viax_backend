<?php

require_once __DIR__ . '/config/R2Service.php';

$key = $_GET['key'] ?? null;
if (!is_string($key) || $key === '') {
    http_response_code(400);
    exit;
}

// The anonymous image proxy serves public avatars, vehicle photos and company logos.
$publicImage = '~\A(?:profile/\d+_\d+|vehicle/\d+_\d+|empresas/(?:logos/logo_[a-f0-9]+|\d{4}/\d{2}/logo_\d+_[a-f0-9]+))\.(?:jpe?g|png|gif|webp|avif)\z~i';
if (!preg_match($publicImage, $key)) {
    http_response_code(404);
    exit;
}

try {
    $file = (new R2Service())->getFile($key);
    $safeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
    if (!$file || !in_array($file['type'], $safeTypes, true)) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: ' . $file['type']);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=86400');
    echo $file['content'];
} catch (Throwable $error) {
    error_log('VIAX R2 image request failed: ' . get_class($error));
    http_response_code(502);
}
