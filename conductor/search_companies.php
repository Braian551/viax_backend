<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $query = isset($_GET['query']) ? trim($_GET['query']) : '';

    // Helper to unaccent PHP string
    function unaccent($str) {
        $unwanted = [
            'á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u',
            'Á'=>'A', 'É'=>'E', 'Í'=>'I', 'Ó'=>'O', 'Ú'=>'U',
            'ñ'=>'n', 'Ñ'=>'N'
        ];
        return strtr($str, $unwanted);
    }
    
    // Helper to convert logo URL to proxy URL
    function convertLogoUrl($logoUrl) {
        if (empty($logoUrl)) {
            return null;
        }
        
        // If already a proxy URL, return as is
        if (strpos($logoUrl, 'r2_proxy.php') !== false) {
            return $logoUrl;
        }
        
        // If direct R2 URL, extract the key
        if (strpos($logoUrl, 'r2.dev/') !== false) {
            $parts = explode('r2.dev/', $logoUrl);
            $logoUrl = end($parts);
        }
        
        // If already a complete URL from another domain, return as is
        if (strpos($logoUrl, 'http://') === 0 || strpos($logoUrl, 'https://') === 0) {
            return $logoUrl;
        }
        
        // Convert relative path to proxy URL
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "$protocol://$host/viax/backend/r2_proxy.php?key=" . urlencode($logoUrl);
    }

    $sql = "SELECT id, nombre, logo_url FROM empresas_transporte WHERE estado = 'activo'";
    
    if (!empty($query)) {
        // Use TRANSLATE in SQL to ignore accents in DB Column
        // Use unaccent() in PHP to ignore accents in Input
        $sql .= " AND (
            translate(nombre, 'áéíóúÁÉÍÓÚñÑ', 'aeiouAEIOUnN') ILIKE :query 
            OR nit ILIKE :query
        )";
    }
    
    $sql .= " ORDER BY nombre ASC LIMIT 20";
    
    $stmt = $db->prepare($sql);
    
    if (!empty($query)) {
        $term = "%" . unaccent($query) . "%";
        $stmt->bindParam(':query', $term);
    }
    
    $stmt->execute();
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert logo URLs
    foreach ($companies as &$company) {
        $company['logo_url'] = convertLogoUrl($company['logo_url']);
    }
    
    echo json_encode(['success' => true, 'data' => $companies]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
