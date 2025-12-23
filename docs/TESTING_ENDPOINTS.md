# Scripts de Prueba para Endpoints de Viajes

## 🧪 Cómo Probar los Endpoints

### 1. Iniciar el Servidor Backend

```bash
cd c:\Flutter\ping_go\pingo\backend
php -S localhost:8000
```

### 2. Preparar Datos de Prueba

Antes de ejecutar las pruebas, asegúrate de tener:

#### Usuario de Prueba
```sql
-- Verificar que existe un usuario tipo 'usuario'
SELECT id, nombre, tipo_usuario FROM usuarios WHERE tipo_usuario = 'usuario' LIMIT 1;
```

#### Conductor de Prueba
```sql
-- Verificar que existe un conductor aprobado y disponible
SELECT 
    u.id, 
    u.nombre, 
    u.disponibilidad,
    u.latitud_actual,
    u.longitud_actual,
    dc.estado_verificacion,
    dc.tipo_vehiculo
FROM usuarios u
INNER JOIN detalles_conductor dc ON u.id = dc.usuario_id
WHERE u.tipo_usuario = 'conductor'
AND dc.estado_verificacion = 'aprobado';
```

#### Actualizar Ubicación del Conductor
```sql
-- Poner al conductor cerca del área de prueba (Medellín)
UPDATE usuarios 
SET latitud_actual = 6.2476, 
    longitud_actual = -75.5658,
    disponibilidad = 1
WHERE id = 7; -- Cambia este ID por el de tu conductor
```

### 3. Ejecutar Pruebas

#### A. Crear Solicitud de Viaje
```bash
php c:\Flutter\ping_go\pingo\backend\user\test_create_request.php
```

**Resultado esperado:**
```json
{
    "success": true,
    "message": "Solicitud creada exitosamente",
    "solicitud_id": 1,
    "conductores_encontrados": 1,
    "conductores": [
        {
            "id": 7,
            "nombre": "Conductor Test",
            "latitud_actual": "6.2476",
            "longitud_actual": "-75.5658",
            "tipo_vehiculo": "moto",
            "distancia": 0.5
        }
    ]
}
```

#### B. Buscar Conductores Cercanos
```bash
php c:\Flutter\ping_go\pingo\backend\user\test_find_drivers.php
```

**Resultado esperado:**
```json
{
    "success": true,
    "total": 1,
    "conductores": [
        {
            "id": 7,
            "nombre": "Conductor Test",
            "telefono": "3001234567",
            "latitud_actual": "6.2476",
            "longitud_actual": "-75.5658",
            "tipo_vehiculo": "moto",
            "marca_vehiculo": "toyota",
            "modelo_vehiculo": "corolla",
            "placa_vehiculo": "3232323",
            "color_vehiculo": "Blanco",
            "calificacion_promedio": "0.00",
            "total_viajes": 0,
            "distancia_km": 0
        }
    ]
}
```

#### C. Obtener Solicitudes Pendientes (Conductor)
```bash
php c:\Flutter\ping_go\pingo\backend\conductor\test_get_requests.php
```

**Resultado esperado:**
```json
{
    "success": true,
    "total": 1,
    "solicitudes": [
        {
            "id": 1,
            "usuario_id": 1,
            "latitud_origen": "6.2476",
            "longitud_origen": "-75.5658",
            "direccion_origen": "Carrera 18B #62-191, Llanaditas",
            "latitud_destino": "6.2001",
            "longitud_destino": "-75.5791",
            "direccion_destino": "La Estrella, Antioquia",
            "tipo_servicio": "viaje",
            "tipo_vehiculo": "moto",
            "distancia_km": "5.20",
            "duracion_minutos": 15,
            "precio_estimado": "12000.00",
            "estado": "pendiente",
            "fecha_solicitud": "2025-10-26 12:00:00",
            "nombre_usuario": "Usuario Test",
            "telefono_usuario": "3001234567",
            "foto_usuario": null,
            "distancia_conductor_origen": 0.5
        }
    ]
}
```

#### D. Aceptar Solicitud (Conductor)
```bash
php c:\Flutter\ping_go\pingo\backend\conductor\test_accept_request.php
```

**Resultado esperado:**
```json
{
    "success": true,
    "message": "Viaje aceptado exitosamente",
    "solicitud_id": 1
}
```

### 4. Verificar en Base de Datos

Después de aceptar un viaje:

```sql
-- Verificar estado de la solicitud
SELECT id, estado, fecha_actualizacion 
FROM solicitudes_servicio 
WHERE id = 1;

-- Verificar asignación
SELECT * FROM asignaciones_conductor WHERE solicitud_id = 1;

-- Verificar disponibilidad del conductor
SELECT id, nombre, disponibilidad 
FROM usuarios 
WHERE id = 7;
```

## ❌ Solución de Errores Comunes

### Error: "Usuario no encontrado"
- Verifica que el `usuario_id` existe en la tabla `usuarios`
- Verifica que el usuario tiene `tipo_usuario = 'usuario'`

### Error: "Conductor no encontrado o no verificado"
- Verifica que el conductor existe y está aprobado:
  ```sql
  SELECT * FROM detalles_conductor WHERE usuario_id = 7;
  ```
- El `estado_verificacion` debe ser `'aprobado'`

### Error: "No se encuentran conductores"
- Verifica que hay conductores con `disponibilidad = 1`
- Verifica que los conductores tienen `latitud_actual` y `longitud_actual` válidos
- Verifica que el `tipo_vehiculo` coincide
- Intenta aumentar el `radio_km`

### Error: "La solicitud ya fue aceptada por otro conductor"
- La solicitud ya no está en estado `'pendiente'`
- Crea una nueva solicitud para probar

### Error de Conexión
- Verifica que el servidor PHP esté corriendo
- Verifica la configuración en `config/database.php`
- Verifica que MySQL esté corriendo

## 🔄 Reiniciar Pruebas

Para hacer pruebas limpias:

```sql
-- Limpiar solicitudes de prueba
DELETE FROM asignaciones_conductor;
DELETE FROM solicitudes_servicio;

-- Resetear disponibilidad del conductor
UPDATE usuarios SET disponibilidad = 1 WHERE tipo_usuario = 'conductor';
```

## 📊 Monitoreo de Pruebas

### Ver Solicitudes Activas
```sql
SELECT 
    s.id,
    s.estado,
    s.tipo_vehiculo,
    s.precio_estimado,
    u.nombre as usuario,
    s.fecha_solicitud
FROM solicitudes_servicio s
INNER JOIN usuarios u ON s.usuario_id = u.id
ORDER BY s.fecha_solicitud DESC
LIMIT 10;
```

### Ver Asignaciones
```sql
SELECT 
    a.id,
    a.solicitud_id,
    a.estado as estado_asignacion,
    c.nombre as conductor,
    s.estado as estado_solicitud,
    a.fecha_asignacion
FROM asignaciones_conductor a
INNER JOIN usuarios c ON a.conductor_id = c.id
INNER JOIN solicitudes_servicio s ON a.solicitud_id = s.id
ORDER BY a.fecha_asignacion DESC;
```

### Ver Conductores Disponibles
```sql
SELECT 
    u.id,
    u.nombre,
    u.disponibilidad,
    u.latitud_actual,
    u.longitud_actual,
    dc.tipo_vehiculo,
    dc.estado_verificacion
FROM usuarios u
INNER JOIN detalles_conductor dc ON u.id = dc.usuario_id
WHERE u.tipo_usuario = 'conductor';
```
