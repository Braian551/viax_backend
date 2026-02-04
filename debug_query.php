<?php
require_once __DIR__ . '/config/database.php';
$db = (new Database())->getConnection();

$empresa_id = 1;
$incluir_solicitudes = true;

// Build the exact query from get_conductores_documentos.php
$where_clauses = [];
if ($incluir_solicitudes) {
    $where_clauses[] = "((u.tipo_usuario = 'conductor' AND u.empresa_id = :empresa_id) OR u.id IN (SELECT conductor_id FROM solicitudes_vinculacion_conductor WHERE empresa_id = :empresa_id2))";
} else {
    $where_clauses[] = "u.tipo_usuario = 'conductor' AND u.empresa_id = :empresa_id";
}
$where_sql = implode(' AND ', $where_clauses);

$sql = "SELECT 
            u.id, u.email, u.tipo_usuario, sv.estado as estado_solicitud, sv.id as solicitud_id
        FROM usuarios u
        LEFT JOIN detalles_conductor dc ON u.id = dc.usuario_id
        LEFT JOIN (
            SELECT DISTINCT ON (conductor_id) *
            FROM solicitudes_vinculacion_conductor
            WHERE empresa_id = :empresa_filter
            ORDER BY conductor_id, 
                CASE WHEN estado = 'pendiente' THEN 1 WHEN estado = 'rechazada' THEN 2 ELSE 3 END ASC,
                creado_en DESC
        ) sv ON u.id = sv.conductor_id
        WHERE $where_sql
        ORDER BY 
            CASE WHEN sv.id IS NOT NULL THEN 0 ELSE 1 END ASC,
            u.fecha_registro DESC
        LIMIT 50";

echo "Executing SQL:\n$sql\n\n";

$stmt = $db->prepare($sql);
$stmt->bindValue(':empresa_filter', $empresa_id, PDO::PARAM_INT);
$stmt->bindValue(':empresa_id', $empresa_id, PDO::PARAM_INT);
if ($incluir_solicitudes) {
    $stmt->bindValue(':empresa_id2', $empresa_id, PDO::PARAM_INT);
}
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Results count: " . count($results) . "\n";
foreach ($results as $row) {
    echo "ID: {$row['id']}, Email: {$row['email']}, Tipo: {$row['tipo_usuario']}, Solicitud: {$row['estado_solicitud']}\n";
}

?>
