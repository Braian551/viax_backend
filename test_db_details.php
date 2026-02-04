<?php
// Test script for debugging purposes
require_once __DIR__ . '/config/database.php';

echo "Testing Company Details Query...\n";

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $empresaId = 1;
    echo "Querying for empresa_id: $empresaId\n";

    $empresaQuery = "
        SELECT 
            e.id,
            e.nombre,
            e.logo_url,
            e.verificada,
            e.descripcion,
            e.creado_en,
            ec.telefono,
            ec.email,
            ec.municipio,
            ec.departamento,
            em.total_conductores,
            em.conductores_activos,
            em.total_viajes_completados,
            em.calificacion_promedio,
            em.total_calificaciones
        FROM empresas_transporte e
        LEFT JOIN empresas_contacto ec ON e.id = ec.empresa_id
        LEFT JOIN empresas_metricas em ON e.id = em.empresa_id
        WHERE e.id = :empresa_id
    ";
    
    $stmt = $conn->prepare($empresaQuery);
    $stmt->execute(['empresa_id' => $empresaId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo "SUCCESS! Found empresa:\n";
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "Not found.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
