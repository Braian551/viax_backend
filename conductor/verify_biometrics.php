<?php
/**
 * Verificación Biométrica Optimizada
 * ===================================
 * 
 * - NO guarda fotos, solo plantillas matemáticas
 * - Comparación rápida con hash + distancia euclidiana
 * - Tabla normalizada para usuarios bloqueados
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

/**
 * Genera hash único de una plantilla para búsqueda rápida O(1)
 */
function generateTemplateHash(array $encoding): string {
    // Usar primeros 16 valores normalizados como fingerprint
    $fingerprint = array_slice($encoding, 0, 16);
    $normalized = array_map(fn($v) => round($v, 4), $fingerprint);
    return hash('sha256', json_encode($normalized));
}

/**
 * Calcula distancia euclidiana entre dos encodings (O(n) donde n=128)
 */
function euclideanDistance(array $enc1, array $enc2): float {
    $sum = 0.0;
    $len = min(count($enc1), count($enc2));
    for ($i = 0; $i < $len; $i++) {
        $diff = $enc1[$i] - $enc2[$i];
        $sum += $diff * $diff;
    }
    return sqrt($sum);
}

/**
 * Limpia archivos temporales
 */
function cleanupTempFiles(array $files): void {
    foreach ($files as $file) {
        if ($file && file_exists($file)) @unlink($file);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["success" => false, "message" => "Método no permitido"]);
    exit;
}

$conductor_id = $_POST['conductor_id'] ?? null;
$tempFiles = [];

if (!$conductor_id || !isset($_FILES['selfie'])) {
    echo json_encode(["success" => false, "message" => "Faltan parámetros"]);
    exit;
}

try {
    // 1. Selfie a archivo temporal
    $selfie_temp = sys_get_temp_dir() . '/selfie_' . $conductor_id . '_' . uniqid() . '.' . 
                   pathinfo($_FILES['selfie']['name'], PATHINFO_EXTENSION);
    
    if (!move_uploaded_file($_FILES['selfie']['tmp_name'], $selfie_temp)) {
        throw new Exception("Error procesando selfie");
    }
    $tempFiles[] = $selfie_temp;

    // 2. Obtener documento de R2
    $stmt = $db->prepare("
        SELECT ruta_archivo FROM documentos_verificacion 
        WHERE conductor_id = :id 
        AND tipo_documento IN ('documento_identidad', 'licencia_conduccion') 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([':id' => $conductor_id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doc) {
        throw new Exception("No hay documento de identidad para comparar");
    }
    
    require_once __DIR__ . '/../config/R2Service.php';
    $r2_key = str_replace('r2_proxy.php?key=', '', $doc['ruta_archivo']);
    
    // Si es ruta R2, descargar a temp
    $id_doc_temp = null;
    if (strpos($r2_key, 'documents/') === 0 || strpos($r2_key, 'imagenes/') === 0) {
        $r2 = new R2Service();
        $id_doc_temp = sys_get_temp_dir() . '/iddoc_' . $conductor_id . '_' . uniqid() . '.' . 
                       pathinfo($r2_key, PATHINFO_EXTENSION);
        
        $content = $r2->getFile($r2_key);
        if (!$content || !isset($content['content'])) {
            throw new Exception("No se pudo descargar documento");
        }
        file_put_contents($id_doc_temp, $content['content']);
        $tempFiles[] = $id_doc_temp;
    } else {
        $id_doc_temp = __DIR__ . '/../' . $doc['ruta_archivo'];
    }

    // 3. Obtener plantillas bloqueadas (consulta optimizada con índice)
    $blocked = [];
    $stmt = $db->prepare("SELECT plantilla FROM plantillas_bloqueadas WHERE activo = true LIMIT 100");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $blocked[] = json_decode($row['plantilla'], true);
    }

    // 4. Ejecutar verificación Python
    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    
    if ($isWindows) {
        // Rutas específicas de Python en Windows (Laragon)
        $pythonPaths = [
            'C:\\laragon\\bin\\python\\python-3.10\\python.exe',
            'C:\\Python310\\python.exe',
            'C:\\Python39\\python.exe',
            'C:\\Users\\' . get_current_user() . '\\AppData\\Local\\Programs\\Python\\Python310\\python.exe',
        ];
        $python = null;
        foreach ($pythonPaths as $p) {
            if (file_exists($p)) {
                $python = $p;
                break;
            }
        }
        if (!$python) $python = 'python'; // Fallback
    } else {
        $python = '/usr/bin/python3';
        if (!file_exists($python)) $python = 'python3';
    }
    
    $script = realpath(__DIR__ . '/../python_services/verify_face.py');
    
    $cmd = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' ' .
           escapeshellarg($selfie_temp) . ' ' . escapeshellarg($id_doc_temp) . ' ' .
           escapeshellarg(json_encode($blocked)) . ' 2>&1';
    
    $output = shell_exec($cmd);
    $result = json_decode($output, true);

    if (!$result || !isset($result['status'])) {
        throw new Exception("Error en servicio biométrico: " . substr($output ?? '', 0, 200));
    }

    $status = $result['status'];
    $encoding = $result['encoding'] ?? null;
    
    // 5. Mapear y guardar resultado
    $db_status = match($status) {
        'verified' => 'verificado',
        'blocked' => 'bloqueado',
        'mismatch' => 'fallido',
        default => 'pendiente'
    };

    if ($status === 'verified' && $encoding) {
        // Guardar plantilla y hash para futuras comparaciones rápidas
        $encoding_json = json_encode($encoding);
        $hash = generateTemplateHash($encoding);
        
        $stmt = $db->prepare("
            UPDATE detalles_conductor SET 
                estado_biometrico = :status,
                plantilla_biometrica = :plantilla,
                fecha_verificacion_biometrica = NOW(),
                estado_aprobacion = 'pendiente',
                estado_verificacion = 'pendiente',
                razon_rechazo = NULL
            WHERE usuario_id = :uid
        ");
        $stmt->execute([
            ':status' => $db_status,
            ':plantilla' => $encoding_json,
            ':uid' => $conductor_id
        ]);
        
        // También resetear solicitud de vinculación si existe y estaba rechazada
        // Primero, eliminar cualquier solicitud rechazada existente
        $stmt = $db->prepare("
            DELETE FROM solicitudes_vinculacion_conductor 
            WHERE conductor_id = :uid AND estado = 'rechazada'
        ");
        $stmt->execute([':uid' => $conductor_id]);
        
        // Luego, verificar si ya existe una solicitud pendiente, si no, crear una nueva
        $stmt = $db->prepare("
            SELECT id FROM solicitudes_vinculacion_conductor 
            WHERE conductor_id = :uid AND estado = 'pendiente'
        ");
        $stmt->execute([':uid' => $conductor_id]);
        $existingPending = $stmt->fetch();
        
        if (!$existingPending) {
            // Obtener el empresa_id del conductor
            $stmt = $db->prepare("SELECT empresa_id FROM usuarios WHERE id = :uid");
            $stmt->execute([':uid' => $conductor_id]);
            $empresa_id = $stmt->fetchColumn();
            
            if ($empresa_id) {
                $stmt = $db->prepare("
                    INSERT INTO solicitudes_vinculacion_conductor (conductor_id, empresa_id, estado, creado_en)
                    VALUES (:uid, :eid, 'pendiente', NOW())
                ");
                $stmt->execute([':uid' => $conductor_id, ':eid' => $empresa_id]);
            }
        }
        
    } elseif ($status === 'blocked') {
        // Agregar a lista de bloqueados si tiene encoding
        $stmt = $db->prepare("UPDATE detalles_conductor SET estado_biometrico = 'bloqueado' WHERE usuario_id = :uid");
        $stmt->execute([':uid' => $conductor_id]);
        
        if ($encoding) {
            $hash = generateTemplateHash($encoding);
            $stmt = $db->prepare("
                INSERT INTO plantillas_bloqueadas (plantilla_hash, plantilla, usuario_origen_id, razon)
                VALUES (:hash, :plantilla, :uid, 'coincide_bloqueado')
                ON CONFLICT (plantilla_hash) WHERE activo = TRUE DO NOTHING
            ");
            // Fallback para MySQL
            try {
                $stmt->execute([':hash' => $hash, ':plantilla' => json_encode($encoding), ':uid' => $conductor_id]);
            } catch (PDOException $e) {
                // MySQL: usar INSERT IGNORE
                $stmt = $db->prepare("
                    INSERT IGNORE INTO plantillas_bloqueadas (plantilla_hash, plantilla, usuario_origen_id, razon)
                    VALUES (:hash, :plantilla, :uid, 'coincide_bloqueado')
                ");
                $stmt->execute([':hash' => $hash, ':plantilla' => json_encode($encoding), ':uid' => $conductor_id]);
            }
        }
    } else {
        $stmt = $db->prepare("UPDATE detalles_conductor SET estado_biometrico = :status WHERE usuario_id = :uid");
        $stmt->execute([':status' => $db_status, ':uid' => $conductor_id]);
    }

    // 6. Limpiar temporales
    cleanupTempFiles($tempFiles);

    echo json_encode([
        "success" => $status === 'verified',
        "message" => $result['message'],
        "biometric_status" => $status
    ]);

} catch (Exception $e) {
    cleanupTempFiles($tempFiles);
    error_log("verify_biometrics error: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
