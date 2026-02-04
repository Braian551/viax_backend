<?php
// Script de depuración para verificar R2 GET
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/R2Service.php';

header('Content-Type: text/plain'); // Plain text for debug output

$email = $_GET['email'] ?? ($argv[1] ?? 'braianoquendurango@gmail.com');

// 1. Obtener ID
$database = new Database();
$db = $database->getConnection();
$stmt = $db->prepare("SELECT id FROM usuarios WHERE email = :email");
$stmt->bindParam(':email', $email);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) die("Usuario no encontrado: $email\n");
$id = $user['id'];
$prefix = "documents/$id/";

echo "User ID: $id\n";
echo "Listing files in '$prefix'...\n";

$r2 = new R2Service();
$files = $r2->listObjects($prefix);

if (empty($files)) {
    // Try raw listing to be sure
    echo "Empty listObjects result. Trying debugList...\n";
    echo $r2->debugList($prefix);
    exit;
}

echo "Found " . count($files) . " files.\n";
foreach ($files as $f) {
    echo " - $f\n";
}

// 2. Try fetching the first file
$firstFile = $files[0];
echo "\nAttempting to FETCH first file: $firstFile\n";

try {
    $result = $r2->getFile($firstFile);
    if ($result) {
        echo "SUCCESS!\n";
        echo "Type: " . $result['type'] . "\n";
        echo "Size: " . strlen($result['content']) . " bytes\n";
    } else {
        echo "FAILED to get file content (getFile returned false).\n";
        // Try to verify if it's a signature error by manually constructing cached url or similar? 
        // Or we can assume R2Service getFile logic needs check if listObjects needed fix.
    }
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}
?>
