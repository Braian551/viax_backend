<?php
/**
 * Upload de Documentos del Conductor
 * 
 * Endpoint para subir fotos de documentos:
 * - Licencia de conducción
 * - SOAT
 * - Tecnomecánica
 * - Tarjeta de propiedad
 * - Seguro
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

// Configuración de uploads
define('UPLOAD_DIR', __DIR__ . '/../uploads/documentos/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_TYPES', ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'application/pdf']);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp', 'pdf']);

$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

try {
    // Validar método
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }

    // Log de debug
    error_log("Upload request received. POST data: " . print_r($_POST, true));
    error_log("FILES data: " . print_r($_FILES, true));

    // Validar que se recibió un archivo
    if (!isset($_FILES['documento']) || $_FILES['documento']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('No se recibió ningún archivo');
    }

    // Validar parámetros requeridos
    if (!isset($_POST['conductor_id']) || !isset($_POST['tipo_documento'])) {
        throw new Exception('Faltan parámetros requeridos: conductor_id y tipo_documento');
    }

    $conductorId = filter_var($_POST['conductor_id'], FILTER_VALIDATE_INT);
    $tipoDocumento = $_POST['tipo_documento'];

    if (!$conductorId) {
        throw new Exception('ID de conductor inválido');
    }

    // Validar tipo de documento
    $tiposPermitidos = ['licencia', 'soat', 'tecnomecanica', 'tarjeta_propiedad', 'seguro'];
    if (!in_array($tipoDocumento, $tiposPermitidos)) {
        throw new Exception('Tipo de documento inválido. Tipos permitidos: ' . implode(', ', $tiposPermitidos));
    }

    $file = $_FILES['documento'];

    // Validar errores de upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'El archivo excede el tamaño máximo permitido por el servidor',
            UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo permitido',
            UPLOAD_ERR_PARTIAL => 'El archivo se subió parcialmente',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta el directorio temporal',
            UPLOAD_ERR_CANT_WRITE => 'Error al escribir el archivo en disco',
            UPLOAD_ERR_EXTENSION => 'Una extensión de PHP detuvo la subida del archivo'
        ];
        throw new Exception($errorMessages[$file['error']] ?? 'Error desconocido al subir el archivo');
    }

    // Validar tamaño
    if ($file['size'] > MAX_FILE_SIZE) {
        throw new Exception('El archivo excede el tamaño máximo de 5MB');
    }

    // Validar tipo MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, ALLOWED_TYPES)) {
        throw new Exception('Tipo de archivo no permitido. Formatos permitidos: JPG, PNG, WEBP, PDF');
    }

    // Validar extensión
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ALLOWED_EXTENSIONS)) {
        throw new Exception('Extensión de archivo no permitida');
    }

    $db = new Database();
    $db = $db->getConnection();

    // Verificar que el conductor existe
    $stmt = $db->prepare("
        SELECT dc.id 
        FROM detalles_conductor dc
        JOIN usuarios u ON dc.usuario_id = u.id
        WHERE u.id = ? AND u.tipo_usuario = 'conductor'
    ");
    $stmt->execute([$conductorId]);
    
    if (!$stmt->fetch()) {
        throw new Exception('Conductor no encontrado');
    }

    // Crear directorio del conductor si no existe
    $conductorDir = UPLOAD_DIR . 'conductor_' . $conductorId . '/';
    error_log("Intentando crear directorio: $conductorDir");
    
    if (!file_exists($conductorDir)) {
        error_log("Directorio no existe, creando...");
        if (!mkdir($conductorDir, 0755, true)) {
            error_log("Error: No se pudo crear el directorio $conductorDir");
            throw new Exception('No se pudo crear el directorio del conductor');
        } else {
            error_log("Directorio creado exitosamente: $conductorDir");
        }
    } else {
        error_log("Directorio ya existe: $conductorDir");
    }

    // Verificar permisos del directorio
    if (!is_writable($conductorDir)) {
        error_log("Error: Directorio no tiene permisos de escritura: $conductorDir");
        throw new Exception('El directorio no tiene permisos de escritura');
    }

    // Generar nombre único para el archivo
    $fileName = $tipoDocumento . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $filePath = $conductorDir . $fileName;
    $relativeUrl = 'uploads/documentos/conductor_' . $conductorId . '/' . $fileName;

    error_log("Nombre de archivo generado: $fileName");
    error_log("Ruta completa del archivo: $filePath");
    error_log("URL relativa: $relativeUrl");

    // Mover archivo
    error_log("Moviendo archivo desde {$_FILES['documento']['tmp_name']} hacia $filePath");
    if (!move_uploaded_file($_FILES['documento']['tmp_name'], $filePath)) {
        error_log("Error: move_uploaded_file falló");
        throw new Exception('Error al guardar el archivo');
    } else {
        error_log("Archivo movido exitosamente");
        
        // Verificar que el archivo existe después de moverlo
        if (!file_exists($filePath)) {
            error_log("Error: Archivo no existe después de move_uploaded_file");
            throw new Exception('Error al guardar el archivo - no se encontró después de moverlo');
        } else {
            error_log("Archivo verificado en destino. Tamaño: " . filesize($filePath) . " bytes");
        }
    }

    // Actualizar base de datos
    $db->beginTransaction();

    try {
        // Obtener URL anterior si existe
        $columnMap = [
            'licencia' => 'licencia_foto_url',
            'soat' => 'soat_foto_url',
            'tecnomecanica' => 'tecnomecanica_foto_url',
            'tarjeta_propiedad' => 'tarjeta_propiedad_foto_url',
            'seguro' => 'seguro_foto_url'
        ];

        $column = $columnMap[$tipoDocumento];

        // Obtener URL anterior
        $stmt = $db->prepare("SELECT $column FROM detalles_conductor WHERE usuario_id = ?");
        $stmt->execute([$conductorId]);
        $oldData = $stmt->fetch(PDO::FETCH_ASSOC);
        $oldUrl = $oldData[$column] ?? null;

        // Actualizar con nueva URL
        $stmt = $db->prepare("
            UPDATE detalles_conductor 
            SET $column = ?, actualizado_en = NOW()
            WHERE usuario_id = ?
        ");
        $stmt->execute([$relativeUrl, $conductorId]);

        // Guardar en historial
        $stmt = $db->prepare("
            INSERT INTO documentos_conductor_historial 
            (conductor_id, tipo_documento, url_documento, activo) 
            VALUES (?, ?, ?, 1)
        ");
        $stmt->execute([$conductorId, $tipoDocumento, $relativeUrl]);

        // Marcar documento anterior como inactivo si existe
        if ($oldUrl) {
            $stmt = $db->prepare("
                UPDATE documentos_conductor_historial 
                SET activo = 0, reemplazado_en = NOW()
                WHERE conductor_id = ? 
                AND tipo_documento = ? 
                AND url_documento = ?
            ");
            $stmt->execute([$conductorId, $tipoDocumento, $oldUrl]);

            // Eliminar archivo anterior
            $oldFilePath = __DIR__ . '/../' . $oldUrl;
            if (file_exists($oldFilePath)) {
                @unlink($oldFilePath);
            }
        }

        $db->commit();

        $response['success'] = true;
        $response['message'] = 'Documento subido exitosamente';
        $response['data'] = [
            'tipo_documento' => $tipoDocumento,
            'url' => $relativeUrl,
            'conductor_id' => $conductorId,
            'fecha_subida' => date('Y-m-d H:i:s')
        ];

    } catch (Exception $e) {
        $db->rollBack();
        // Eliminar archivo si hubo error en BD
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
        throw $e;
    }

} catch (Exception $e) {
    http_response_code(400);
    $response['message'] = $e->getMessage();
    
    // Log del error
    error_log("Error en upload_documents.php: " . $e->getMessage());
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
