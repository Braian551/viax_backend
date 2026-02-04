<?php
/**
 * Endpoint para actualizar perfil de usuario (nombre, apellido, foto)
 * 
 * Acepta:
 * - POST multipart/form-data con:
 *   - user_id (required): ID del usuario
 *   - nombre (optional): Nuevo nombre
 *   - apellido (optional): Nuevo apellido
 *   - foto (optional): Archivo de imagen de perfil
 * 
 * Almacena las imágenes en Cloudflare R2
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/R2Service.php';

$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido. Use POST.');
    }

    // Obtener user_id (puede venir como form-data o JSON)
    $userId = null;
    $nombre = null;
    $apellido = null;

    // Intentar obtener datos de form-data (multipart)
    if (isset($_POST['user_id'])) {
        $userId = intval($_POST['user_id']);
        $nombre = isset($_POST['nombre']) ? trim($_POST['nombre']) : null;
        $apellido = isset($_POST['apellido']) ? trim($_POST['apellido']) : null;
    } else {
        // Intentar obtener de JSON body (si no hay archivo)
        $input = file_get_contents('php://input');
        if (!empty($input)) {
            $data = json_decode($input, true);
            if ($data) {
                $userId = isset($data['user_id']) ? intval($data['user_id']) : null;
                $nombre = isset($data['nombre']) ? trim($data['nombre']) : null;
                $apellido = isset($data['apellido']) ? trim($data['apellido']) : null;
            }
        }
    }

    if (!$userId || $userId <= 0) {
        throw new Exception('ID de usuario requerido.');
    }

    // Conectar a base de datos
    $database = new Database();
    $db = $database->getConnection();

    // Verificar que el usuario existe
    $checkUser = $db->prepare("SELECT id, foto_perfil FROM usuarios WHERE id = :id");
    $checkUser->bindParam(':id', $userId, PDO::PARAM_INT);
    $checkUser->execute();
    
    if ($checkUser->rowCount() === 0) {
        throw new Exception('Usuario no encontrado.');
    }

    $currentUser = $checkUser->fetch(PDO::FETCH_ASSOC);
    $oldFotoKey = $currentUser['foto_perfil'];

    // Preparar campos a actualizar
    $fieldsToUpdate = [];
    $params = [':id' => $userId];

    if ($nombre !== null && $nombre !== '') {
        $fieldsToUpdate[] = 'nombre = :nombre';
        $params[':nombre'] = $nombre;
    }

    if ($apellido !== null && $apellido !== '') {
        $fieldsToUpdate[] = 'apellido = :apellido';
        $params[':apellido'] = $apellido;
    }

    // Procesar imagen de perfil
    $newFotoKey = null;
    $shouldDeletePhoto = false;

    // Verificar si se solicitó eliminar la foto via POST field o JSON
    if (isset($_POST['delete_foto']) && $_POST['delete_foto'] === 'true') {
        $shouldDeletePhoto = true;
    } else if (isset($data) && isset($data['delete_foto']) && $data['delete_foto'] === true) {
        $shouldDeletePhoto = true;
    }

    if ($shouldDeletePhoto) {
        // Si se pide borrar, marcamos para actualizar a NULL
        $fieldsToUpdate[] = 'foto_perfil = NULL';
        
        // Y eliminamos de R2 si existe
        if ($oldFotoKey && strpos($oldFotoKey, 'profile/') === 0) {
            try {
                $r2 = new R2Service();
                $r2->deleteFile($oldFotoKey);
            } catch (Exception $e) {
                error_log("Error eliminando foto (solicitud borrado): " . $e->getMessage());
            }
        }
    } else if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        // ... (lógica existente de subida)
        $file = $_FILES['foto'];
        
        // Validar tipo de archivo
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes)) {
            throw new Exception('Tipo de archivo no permitido. Use JPG, PNG o WebP.');
        }

        // Validar tamaño (máximo 5MB)
        $maxSize = 5 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            throw new Exception('El archivo es demasiado grande. Máximo 5MB.');
        }

        // Obtener extensión
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'])) {
            // Usar extensión basada en mime type
            $mimeToExt = [
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp'
            ];
            $extension = $mimeToExt[$mimeType] ?? 'jpg';
        }

        // Generar nombre único para R2
        $timestamp = time();
        $filename = "profile/{$userId}_{$timestamp}.{$extension}";

        // Subir a R2
        $r2 = new R2Service();
        $newFotoKey = $r2->uploadFile($file['tmp_name'], $filename, $mimeType);

        $fieldsToUpdate[] = 'foto_perfil = :foto_perfil';
        $params[':foto_perfil'] = $newFotoKey;

        // Eliminar foto anterior de R2 si existe y es diferente
        if ($oldFotoKey && $oldFotoKey !== $newFotoKey && strpos($oldFotoKey, 'profile/') === 0) {
            try {
                $r2->deleteFile($oldFotoKey);
            } catch (Exception $e) {
                // Log error but don't fail the request
                error_log("Error eliminando foto anterior: " . $e->getMessage());
            }
        }
    }

    // Si no hay campos para actualizar
    if (empty($fieldsToUpdate)) {
        throw new Exception('No se proporcionaron datos para actualizar.');
    }

    // Agregar fecha de actualización
    $fieldsToUpdate[] = 'fecha_actualizacion = NOW()';

    // Ejecutar UPDATE
    $sql = "UPDATE usuarios SET " . implode(', ', $fieldsToUpdate) . " WHERE id = :id";
    $stmt = $db->prepare($sql);
    
    foreach ($params as $key => $value) {
        if ($key === ':id') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
    }

    if (!$stmt->execute()) {
        throw new Exception('Error al actualizar el perfil en la base de datos.');
    }

    // Obtener datos actualizados
    $getUpdated = $db->prepare("SELECT id, nombre, apellido, email, telefono, foto_perfil, tipo_usuario FROM usuarios WHERE id = :id");
    $getUpdated->bindParam(':id', $userId, PDO::PARAM_INT);
    $getUpdated->execute();
    $updatedUser = $getUpdated->fetch(PDO::FETCH_ASSOC);

    $response['success'] = true;
    $response['message'] = 'Perfil actualizado correctamente.';
    $response['data'] = [
        'user' => $updatedUser,
        'foto_key' => $newFotoKey ?? $oldFotoKey
    ];

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
?>
