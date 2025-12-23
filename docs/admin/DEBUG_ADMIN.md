# Debug del Módulo Admin

## 🔍 Pasos para diagnosticar problemas

### 1. Verificar que el usuario es administrador

Ejecuta en MySQL:
```sql
SELECT id, nombre, email, tipo_usuario FROM usuarios WHERE tipo_usuario = 'administrador';
```

Si no hay ningún administrador, crea uno:
```sql
UPDATE usuarios SET tipo_usuario = 'administrador' WHERE id = 1;
```

### 2. Probar el endpoint directamente

Abre en tu navegador (reemplaza el ID con el de tu admin):
```
http://localhost/pingo/backend/admin/test_dashboard.php
```

O prueba directamente:
```
http://localhost/pingo/backend/admin/dashboard_stats.php?admin_id=1
```

### 3. Verificar logs de PHP

Los logs se guardan en:
- `pingo/backend/logs/error.log` (si existe)
- Logs de Apache/PHP según tu configuración de servidor

### 4. Verificar en Flutter

Revisa la consola de Flutter/Android Studio para ver los logs:
```
AdminService: Obteniendo estadísticas para admin_id: X
AdminService: URL completa: ...
AdminService: Status Code: ...
AdminService: Response Body: ...
```

### 5. Verificar conectividad

Desde el emulador Android, verifica que puedas acceder:
- `http://10.0.2.2/pingo/backend/admin/dashboard_stats.php?admin_id=1`

Si usas dispositivo físico, usa la IP de tu PC:
- `http://192.168.X.X/pingo/backend/admin/dashboard_stats.php?admin_id=1`

## 🛠️ Soluciones comunes

### Error: "No se pudieron cargar las estadísticas"

**Causa 1:** Usuario no es administrador
```sql
UPDATE usuarios SET tipo_usuario = 'administrador' WHERE id = 1;
```

**Causa 2:** Problemas de conexión
- Verifica que Apache/XAMPP esté corriendo
- Verifica la URL en `admin_service.dart` (línea 5)
- Para emulador usa: `http://10.0.2.2/pingo/backend/admin`
- Para dispositivo físico usa: `http://TU_IP_LOCAL/pingo/backend/admin`

**Causa 3:** Error en la base de datos
- Verifica que todas las tablas existan
- Ejecuta las migraciones si es necesario

### Pantalla negra / Sin datos

La app ahora muestra datos por defecto (ceros) si hay error. 
Revisa los logs de Flutter y el test_dashboard.php para ver el error real.

## 📊 Datos de prueba

Si quieres ver la interfaz con datos, ejecuta:

```sql
-- Insertar usuarios de prueba
INSERT INTO usuarios (id_usuario, nombre, apellido, email, telefono, password_hash, tipo_usuario, es_activo) 
VALUES 
('test_cliente_1', 'Juan', 'Pérez', 'juan@test.com', '3001234567', '$2y$10$test', 'cliente', 1),
('test_conductor_1', 'Pedro', 'García', 'pedro@test.com', '3007654321', '$2y$10$test', 'conductor', 1);

-- Insertar solicitudes de prueba
INSERT INTO solicitudes_servicio (id_usuario, tipo_servicio, estado, precio_estimado, fecha_creacion)
VALUES 
(1, 'viaje', 'completado', 15000, NOW()),
(1, 'paquete', 'completado', 8000, NOW()),
(1, 'viaje', 'en_proceso', 12000, NOW());
```

## ✅ Checklist de verificación

- [ ] Apache/XAMPP corriendo
- [ ] Base de datos `pingo` existe y está conectada
- [ ] Usuario administrador existe (tipo_usuario = 'administrador')
- [ ] URL correcta en `admin_service.dart`
- [ ] test_dashboard.php muestra JSON válido
- [ ] Logs de Flutter muestran respuesta del servidor
