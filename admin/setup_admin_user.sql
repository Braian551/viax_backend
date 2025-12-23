-- Script para configurar usuario administrador de prueba
-- Ejecuta esto en tu base de datos MySQL

USE viax;

-- Verificar usuarios administradores existentes
SELECT id, nombre, apellido, email, tipo_usuario, es_activo 
FROM usuarios 
WHERE tipo_usuario = 'administrador';

-- Si no hay ningún administrador, convierte el usuario con id=1 en administrador
UPDATE usuarios 
SET tipo_usuario = 'administrador', es_activo = 1, actualizado_en = CURRENT_TIMESTAMP
WHERE id = 1;

-- Verificar el cambio
SELECT id, nombre, apellido, email, tipo_usuario, es_activo, es_verificado
FROM usuarios 
WHERE id = 1;

-- Insertar algunos datos de prueba para el dashboard (opcional)

-- Asegurar que hay solicitudes
INSERT INTO solicitudes_servicio (usuario_id, tipo_servicio, estado, precio_estimado, fecha_creacion, actualizado_en)
SELECT 
    1,
    'viaje',
    'completado',
    15000,
    DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 30) DAY),
    DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 30) DAY)
FROM (SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) AS numbers
LIMIT 5
ON DUPLICATE KEY UPDATE id=id;

-- Insertar transacciones de prueba (si la tabla existe)
INSERT IGNORE INTO transacciones (solicitud_id, usuario_id, monto_total, estado, fecha_creacion)
SELECT 
    s.id,
    s.usuario_id,
    s.precio_estimado,
    'completado',
    s.fecha_creacion
FROM solicitudes_servicio s
WHERE s.estado = 'completado'
LIMIT 10;

-- Insertar actividades en logs de auditoría
INSERT INTO logs_auditoria (usuario_id, accion, descripcion, ip_address, user_agent, fecha_creacion)
VALUES
(1, 'dashboard_access', 'Administrador accedió al panel', '127.0.0.1', 'Flutter App', NOW()),
(1, 'user_update', 'Actualizó información de usuario', '127.0.0.1', 'Flutter App', DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(1, 'config_update', 'Modificó configuración del sistema', '127.0.0.1', 'Flutter App', DATE_SUB(NOW(), INTERVAL 2 HOUR));

-- Mostrar resumen de datos
SELECT 
    'Usuarios' as tabla,
    COUNT(*) as total,
    SUM(CASE WHEN tipo_usuario = 'administrador' THEN 1 ELSE 0 END) as administradores,
    SUM(CASE WHEN tipo_usuario = 'cliente' THEN 1 ELSE 0 END) as clientes,
    SUM(CASE WHEN tipo_usuario = 'conductor' THEN 1 ELSE 0 END) as conductores
FROM usuarios

UNION ALL

SELECT 
    'Solicitudes' as tabla,
    COUNT(*) as total,
    SUM(CASE WHEN estado = 'completado' THEN 1 ELSE 0 END) as completadas,
    SUM(CASE WHEN estado = 'en_proceso' THEN 1 ELSE 0 END) as en_proceso,
    SUM(CASE WHEN estado = 'cancelado' THEN 1 ELSE 0 END) as canceladas
FROM solicitudes_servicio

UNION ALL

SELECT 
    'Logs Auditoría' as tabla,
    COUNT(*) as total,
    0 as col2,
    0 as col3,
    0 as col4
FROM logs_auditoria;

-- Mensaje final
SELECT 'Configuración completada. Ahora prueba la app.' as mensaje;
