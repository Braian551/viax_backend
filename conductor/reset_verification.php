<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $email = 'braianoquendurango@gmail.com';
    
    // Get user ID
    $stmt = $db->prepare("SELECT id FROM usuarios WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        die(json_encode(['success' => false, 'message' => 'Usuario no encontrado']));
    }
    
    $userId = $user['id'];
    
    $db->beginTransaction();
    
    // --- DELETE R2 FILES START ---
    require_once __DIR__ . '/../config/R2Service.php';
    $r2 = new R2Service();
    $r2_prefix = 'r2_proxy.php?key=';

    // 1. Get files from detalles_conductor
    // Select all potential file columns to delete from R2
    $fileColsQuery = "SELECT licencia_foto_url, seguro_foto_url, soat_foto_url, tecnomecanica_foto_url, tarjeta_propiedad_foto_url, foto_vehiculo FROM detalles_conductor WHERE usuario_id = :uid";
    $stmt = $db->prepare($fileColsQuery);
    $stmt->execute(['uid' => $userId]);
    $detalles = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($detalles) {
        foreach ($detalles as $col => $path) {
            if (!empty($path)) {
                $key = null;
                if (strpos($path, $r2_prefix) !== false) {
                    $key = str_replace($r2_prefix, '', $path);
                } elseif (strpos($path, 'documents/') === 0) {
                     $key = $path;
                }
                
                if ($key) {
                    $r2->deleteFile($key);
                    // echo "Deleted R2 file: $key <br>"; // Silent for API response cleanliness
                }
            }
        }
    }

    // 2. Get files from documentos_verificacion (ID photos, selfies - although verify_biometrics saves temp, the record stores path)
    $stmt = $db->prepare("SELECT ruta_archivo FROM documentos_verificacion WHERE conductor_id = :uid");
    $stmt->execute(['uid' => $userId]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($docs as $doc) {
        $path = $doc['ruta_archivo'];
        if (!empty($path)) {
             $key = null;
            if (strpos($path, $r2_prefix) !== false) {
                $key = str_replace($r2_prefix, '', $path);
            } elseif (strpos($path, 'documents/') === 0) {
                $key = $path;
            }
            
            if ($key) {
                 $r2->deleteFile($key);
            }
        }
    }
    // --- DELETE R2 FILES END ---
    
    // 1. Reset details - DELETE the row to completely reset
    $resetQuery = "DELETE FROM detalles_conductor WHERE usuario_id = :uid";
    $stmt = $db->prepare($resetQuery);
    $stmt->execute(['uid' => $userId]);
    
    // Also delete from documentos_verificacion
    $queryDocs = "DELETE FROM documentos_verificacion WHERE conductor_id = :uid";
    $stmtDocs = $db->prepare($queryDocs);
    $stmtDocs->execute(['uid' => $userId]);
    
    // 2. Clear history
    $deleteHist = "DELETE FROM documentos_conductor_historial WHERE conductor_id = :uid";
    $stmt = $db->prepare($deleteHist);
    $stmt->execute(['uid' => $userId]);
    
    // 3. Reset user status
    // Ensure we reset types to client to allow 'new driver' flow
    $resetUser = "UPDATE usuarios SET tipo_usuario = 'cliente', es_verificado = 0, es_activo = 1 WHERE id = :uid";
    $stmt = $db->prepare($resetUser);
    $stmt->execute(['uid' => $userId]);
    
    $db->commit();
    
    echo json_encode(['success' => true, 'message' => 'Usuario reseteado correctamente']);
    
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
