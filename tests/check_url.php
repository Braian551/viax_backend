<?php
$url = "https://pub-9e36b59ddd8dc8dcc4edc374e6140fda.r2.dev/empresas/2025/12/logo_1766714940_0ed8b85d7427ec91.jpg";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request
$output = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Headers:\n$output\n";
?>
