<?php
require_once 'config/R2Service.php';

$key = 'empresas/2025/12/logo_1766714940_0ed8b85d7427ec91.jpg';

try {
    $r2 = new R2Service();
    echo "Attempting to fetch: $key\n";
    $result = $r2->getFile($key);

    if ($result) {
        echo "Success!\n";
        echo "Content-Type: " . $result['type'] . "\n";
        echo "Size: " . strlen($result['content']) . " bytes\n";
    } else {
        echo "Failed to fetch file (HTTP 404 or other error).\n";
    }
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
?>
