<?php
require_once __DIR__ . '/config/database.php';

$db = (new Database())->getConnection();

echo "=== Conductores en la BD ===\n\n";

$stmt = $db->query("
    SELECT 
        u.id, 
        u.nombre, 
        u.apellido,
        u.fecha_registro,
        dc.total_viajes, 
        dc.calificacion_promedio,
        dc.total_calificaciones
    FROM usuarios u 
    LEFT JOIN detalles_conductor dc ON u.id = dc.usuario_id 
    WHERE u.tipo_usuario = 'conductor' 
    ORDER BY u.id DESC 
    LIMIT 15
");

$conductores = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($conductores as $c) {
    echo "ID: {$c['id']} | {$c['nombre']} {$c['apellido']}\n";
    echo "   Viajes: {$c['total_viajes']} | Rating: {$c['calificacion_promedio']} | Calificaciones: {$c['total_calificaciones']}\n";
    echo "   Registro: {$c['fecha_registro']}\n\n";
}
