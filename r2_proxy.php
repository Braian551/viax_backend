<?php
require_once 'config/R2Service.php';

$key = $_GET['key'] ?? null;

if (!$key) {
    header("HTTP/1.0 400 Bad Request");
    echo "Missing key";
    exit;
}

try {
    $r2 = new R2Service();
    $result = $r2->getFile($key);

    if ($result) {
        header("Content-Type: " . $result['type']);
        header("Cache-Control: public, max-age=86400"); // Cache for 1 day
        echo $result['content'];
    } else {
        header("HTTP/1.0 404 Not Found");
        echo "File not found";
    }
} catch (Exception $e) {
    header("HTTP/1.0 500 Internal Server Error");
    echo "Error: " . $e->getMessage();
}

