<?php
/**
 * Upload de Documentos del Conductor
 * 
 * Soporta imágenes (jpg, jpeg, png, webp) y PDFs
 * Organiza archivos en Cloudflare R2:
 * - imagenes/documents/{conductor_id}/{tipo}_{timestamp}.{ext}
 * - pdfs/documents/{conductor_id}/{tipo}_{timestamp}.pdf
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
require_once __DIR__ . '/../config/R2Service.php';

$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }

    if (!isset($_FILES['documento']) || $_FILES['documento']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('No se recibió ningún archivo');
    }

    if (!isset($_POST['conductor_id']) || !isset($_POST['tipo_documento'])) {
        throw new Exception('Faltan parámetros requeridos');
    }

    $conductorId = filter_var($_POST['conductor_id'], FILTER_VALIDATE_INT);
    $tipoDocumento = $_POST['tipo_documento'];

    if (!$conductorId) {
        throw new Exception('ID de conductor inválido');
    }

    $tiposPermitidos = ['licencia', 'soat', 'tecnomecanica', 'tarjeta_propiedad', 'seguro'];
    if (!in_array($tipoDocumento, $tiposPermitidos)) {
        throw new Exception('Tipo de documento inválido');
    }

    $file = $_FILES['documento'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $mimeType = $file['type'];
    
    // Validar extensiones permitidas (imágenes y PDF)
    $extensionesPermitidas = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
    if (!in_array($extension, $extensionesPermitidas)) {
        throw new Exception('Tipo de archivo no permitido. Use: ' . implode(', ', $extensionesPermitidas));
    }
    
    // Validar tamaño máximo (10MB para PDFs, 5MB para imágenes)
    $maxSize = ($extension === 'pdf') ? 10 * 1024 * 1024 : 5 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        $maxMB = $maxSize / 1024 / 1024;
        throw new Exception("El archivo excede el tamaño máximo de {$maxMB}MB");
    }
    
    // Determinar si es imagen o PDF y carpeta correspondiente
    $esImagen = in_array($extension, ['jpg', 'jpeg', 'png', 'webp']);
    $tipoArchivo = $esImagen ? 'imagen' : 'pdf';
    $carpeta = $esImagen ? 'imagenes' : 'pdfs';
    
    // Construir ruta en R2: {carpeta}/documents/{conductor_id}/{tipo}_{timestamp}.{ext}
    $timestamp = time();
    $filename = "{$carpeta}/documents/{$conductorId}/{$tipoDocumento}_{$timestamp}.{$extension}";
    
    // Subir a R2
    $r2 = new R2Service();
    $relativeUrl = $r2->uploadFile($file['tmp_name'], $filename, $mimeType);

    $db = new Database();
    $db = $db->getConnection();
    
    $db->beginTransaction();

    try {
        $columnMap = [
            'licencia' => 'licencia_foto_url',
            'soat' => 'soat_foto_url',
            'tecnomecanica' => 'tecnomecanica_foto_url',
            'tarjeta_propiedad' => 'tarjeta_propiedad_foto_url',
            'seguro' => 'seguro_foto_url'
        ];
        
        $columnTipoMap = [
            'licencia' => 'licencia_tipo_archivo',
            'soat' => 'soat_tipo_archivo',
            'tecnomecanica' => 'tecnomecanica_tipo_archivo',
            'tarjeta_propiedad' => 'tarjeta_propiedad_tipo_archivo',
            'seguro' => 'seguro_tipo_archivo'
        ];
        
        $column = $columnMap[$tipoDocumento];
        $columnTipo = $columnTipoMap[$tipoDocumento];

        // Verificar si existe columna tipo_archivo (puede no existir si no se ejecutó migración)
        $checkColumn = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'detalles_conductor' AND column_name = '{$columnTipo}'");
        $tieneColumnaTipo = $checkColumn->rowCount() > 0;

        // Update details - actualizar URL y tipo de archivo si existe la columna
        if ($tieneColumnaTipo) {
            $stmt = $db->prepare("UPDATE detalles_conductor SET $column = ?, $columnTipo = ?, actualizado_en = NOW() WHERE usuario_id = ?");
            $stmt->execute([$relativeUrl, $tipoArchivo, $conductorId]);
        } else {
            $stmt = $db->prepare("UPDATE detalles_conductor SET $column = ?, actualizado_en = NOW() WHERE usuario_id = ?");
            $stmt->execute([$relativeUrl, $conductorId]);
        }

        // History - verificar si tiene columnas nuevas
        $checkHistorial = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'documentos_conductor_historial' AND column_name = 'tipo_archivo'");
        $historialTieneTipo = $checkHistorial->rowCount() > 0;
        
        if ($historialTieneTipo) {
            $stmt = $db->prepare("INSERT INTO documentos_conductor_historial 
                (conductor_id, tipo_documento, url_documento, tipo_archivo, nombre_archivo_original, tamanio_archivo, activo, verificado_por_admin) 
                VALUES (?, ?, ?, ?, ?, ?, 1, 0)");
            $stmt->execute([
                $conductorId, 
                $tipoDocumento, 
                $relativeUrl, 
                $tipoArchivo,
                $file['name'],
                $file['size']
            ]);
        } else {
            $stmt = $db->prepare("INSERT INTO documentos_conductor_historial 
                (conductor_id, tipo_documento, url_documento, activo, verificado_por_admin) 
                VALUES (?, ?, ?, 1, 0)");
            $stmt->execute([$conductorId, $tipoDocumento, $relativeUrl]);
        }

        $db->commit();

        $response['success'] = true;
        $response['message'] = 'Documento subido exitosamente';
        $response['data'] = [
            'url' => $relativeUrl,
            'tipo_archivo' => $tipoArchivo,
            'carpeta' => $carpeta,
            'nombre_original' => $file['name'],
            'tamanio' => $file['size']
        ];

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    http_response_code(400);
    $response['message'] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>
