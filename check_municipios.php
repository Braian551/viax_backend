<?php
require_once __DIR__ . '/config/database.php';

$db = (new Database())->getConnection();

$query = "SELECT 
    e.id, 
    e.nombre, 
    e.municipio as mun_principal,
    e.departamento as dep_principal,
    ec.municipio as mun_contacto,
    ec.departamento as dep_contacto
FROM empresas_transporte e 
LEFT JOIN empresas_contacto ec ON e.id = ec.empresa_id 
WHERE e.estado = 'activo' 
ORDER BY e.nombre";

$stmt = $db->query($query);
$empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($empresas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
