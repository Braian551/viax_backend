<?php
// Script de limpieza forzada de R2 (MEJORADO)
// USO: php force_cleanup_r2.php email=usuario@email.com

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/R2Service.php';

header('Content-Type: application/json');

$email = null;
if (isset($argv[1]) && strpos($argv[1], '=') !== false) {
    parse_str($argv[1], $args);
    if (isset($args['email'])) $email = $args['email'];
}

if (!$email) {
    echo json_encode(['success' => false, 'message' => 'Email requerido']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $r2 = new R2Service();

    // 1. Obtener ID
    $stmt = $db->prepare("SELECT id FROM usuarios WHERE email = :email");
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception("Usuario no encontrado");
    }

    $conductor_id = $user['id'];
    
    // INTENTO 1: Prefijo exacto
    $folderPrefix = "documents/{$conductor_id}/";
    echo "Buscando archivos con prefijo EXACTO: '$folderPrefix'...\n";
    $files = $r2->listObjects($folderPrefix);
    
    // INTENTO 2: Si falla, buscar solo 'documents/' y filtrar (fuerza bruta)
    if (empty($files)) {
        echo "No se encontraron con prefijo exacto. Intentando listar TODO 'documents/' y filtrar...\n";
        $allDocs = $r2->listObjects("documents/");
        
        $files = [];
        foreach ($allDocs as $f) {
            // Verificar si el archivo pertenece a la carpeta del ID
            if (strpos($f, "documents/{$conductor_id}/") === 0) {
                $files[] = $f;
            }
        }
    }

    $count = count($files);
    echo "Encontrados: $count archivos.\n";

    $deletedCount = 0;
    foreach ($files as $fileKey) {
        echo " - Eliminando: $fileKey... ";
        if ($r2->deleteFile($fileKey)) {
            $deletedCount++;
            echo "OK\n";
        } else {
            echo "FALLO\n";
        }
    }

    echo json_encode([
        'success' => true, 
        'message' => "Proceso terminado. $deletedCount archivos eliminados de R2 para usuario $conductor_id."
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
