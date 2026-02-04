<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/R2Service.php';

$response = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['file']) && isset($_POST['conductor_id']) && isset($_POST['tipo_documento'])) {
        $conductor_id = $_POST['conductor_id'];
        $tipo_documento = $_POST['tipo_documento'];
        $file = $_FILES['file'];

        try {
            $extension = pathinfo($file["name"], PATHINFO_EXTENSION);
            $filename = 'documents/' . $conductor_id . '/' . $tipo_documento . '_' . time() . '.' . $extension;
            
            $r2 = new R2Service();
            $relativeUrl = $r2->uploadFile($file['tmp_name'], $filename, $file['type']);

            $database = new Database();
            $db = $database->getConnection();

            $query = "INSERT INTO documentos_verificacion (conductor_id, tipo_documento, ruta_archivo, estado) VALUES (:conductor_id, :tipo_documento, :ruta_archivo, 'pendiente')";
            $stmt = $db->prepare($query);
            $stmt->bindParam(":conductor_id", $conductor_id);
            $stmt->bindParam(":tipo_documento", $tipo_documento);
            $stmt->bindParam(":ruta_archivo", $relativeUrl);
            
            if ($stmt->execute()) {
                // ACTUALIZAR TAMBIÉN LA TABLA PRINCIPAL (detalles_conductor)
                // Esto es crucial para que el admin vea la foto actual en el perfil
                $columna_foto = '';
                switch ($tipo_documento) {
                    case 'licencia_conduccion':
                        $columna_foto = 'licencia_foto_url';
                        break;
                    case 'soat':
                        $columna_foto = 'soat_foto_url';
                        break;
                    case 'tecnomecanica':
                        $columna_foto = 'tecnomecanica_foto_url';
                        break;
                    case 'tarjeta_propiedad':
                        $columna_foto = 'tarjeta_propiedad_foto_url';
                        break;
                    case 'seguro_contractual':
                        $columna_foto = 'seguro_foto_url';
                        break;
                }

                if (!empty($columna_foto)) {
                    // Usamos usuario_id = conductor_id (el ID que viene del frontend es el ID de usuario)
                    // Primero verificamos si existe el registro
                    $check = $db->prepare("SELECT id FROM detalles_conductor WHERE usuario_id = :uid");
                    $check->bindParam(":uid", $conductor_id);
                    $check->execute();
                    
                    if ($check->rowCount() > 0) {
                        $update = $db->prepare("UPDATE detalles_conductor SET $columna_foto = :ruta, estado_verificacion = 'en_revision' WHERE usuario_id = :uid");
                        $update->bindParam(":ruta", $relativeUrl);
                        $update->bindParam(":uid", $conductor_id);
                        $update->execute();
                    } else {
                        // Si no existe (raro si ya pasó pasos anteriores, pero por seguridad), lo creamos
                        $insert = $db->prepare("INSERT INTO detalles_conductor (usuario_id, $columna_foto, estado_verificacion) VALUES (:uid, :ruta, 'en_revision')");
                        $insert->bindParam(":uid", $conductor_id);
                        $insert->bindParam(":ruta", $relativeUrl);
                        $insert->execute();
                    }
                }

                $response['success'] = true;
                $response['message'] = "Documento subido y perfil actualizado correctamente.";
                $response['path'] = $relativeUrl;
            } else {
                $response['success'] = false;
                $response['message'] = "Error al guardar en base de datos.";
            }

        } catch (Exception $e) {
            $response['success'] = false;
            $response['message'] = "Error R2/DB: " . $e->getMessage();
        }
    } else {
        $response['success'] = false;
        $response['message'] = "Datos incompletos.";
    }
} else {
    $response['success'] = false;
    $response['message'] = "Método no permitido.";
}

echo json_encode($response);
?>
