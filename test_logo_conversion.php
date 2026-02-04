<?php
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

// Helper function to convert logo URL to R2 proxy format
function convertLogoUrl($logoUrl) {
    if (empty($logoUrl)) {
        return null;
    }
    
    // If already a full URL, extract the key
    if (strpos($logoUrl, 'http') === 0) {
        // Already a full URL, return as is
        return $logoUrl;
    }
    
    // Convert relative path to R2 proxy URL
    return getR2ProxyUrl($logoUrl);
}

$stmt = $conn->query('SELECT id, nombre, logo_url FROM empresas_transporte ORDER BY id');
$empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== Logos en DB vs URL Convertida ===\n\n";
foreach ($empresas as $e) {
    $original = $e['logo_url'] ?? '(null)';
    $converted = convertLogoUrl($e['logo_url']);
    echo $e['nombre'] . ":\n";
    echo "  Original:  " . $original . "\n";
    echo "  Convertida: " . ($converted ?? '(null)') . "\n\n";
}
