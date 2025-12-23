# MIGRACIÓN 004 - Fix fecha_creacion Columns

## Problema Detectado
El error reportado es:
```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'fecha_creacion' in 'field list'
```

## Causa
Las tablas `solicitudes_servicio` y `transacciones` usan diferentes nombres de columnas:
- `solicitudes_servicio` tiene `solicitado_en` en lugar de `fecha_creacion`
- `transacciones` tiene `fecha_transaccion` en lugar de `fecha_creacion`

Pero el código PHP en `dashboard_stats.php` espera la columna `fecha_creacion`.

## Solución
Agregar la columna `fecha_creacion` a ambas tablas y copiar los datos existentes.

## Archivos Creados
1. `004_fix_fecha_creacion_columns.sql` - Migración principal
2. `run_migration_004.sql` - Script ejecutable directo para MySQL
3. `ejecutar_migraciones.bat` - Script batch para Windows

## Cómo Ejecutar

### Opción 1: Usar el script batch (Windows)
```cmd
cd c:\Flutter\ping_go\pingo\backend\migrations
ejecutar_migraciones.bat
```

### Opción 2: Ejecutar PHP directamente
```cmd
cd c:\Flutter\ping_go\pingo\backend
php migrations/run_migrations.php
```

### Opción 3: MySQL Workbench o línea de comandos
Ejecuta el archivo `run_migration_004.sql` directamente:
```bash
mysql -u root -p pingo < migrations/run_migration_004.sql
```

O copia y pega el contenido en MySQL Workbench.

## Verificación
Después de ejecutar la migración, verifica que las columnas existan:
```sql
DESCRIBE solicitudes_servicio;
DESCRIBE transacciones;
```

Ambas tablas deben tener ahora la columna `fecha_creacion`.

## Cambios Realizados
- ✅ Agregada columna `fecha_creacion` a `solicitudes_servicio`
- ✅ Agregada columna `fecha_creacion` a `transacciones`
- ✅ Datos existentes copiados de `solicitado_en` y `fecha_transaccion` respectivamente
- ✅ Script de migración automática creado
- ✅ Script batch para Windows creado

## Notas
- La columna original (`solicitado_en` y `fecha_transaccion`) se mantiene por compatibilidad
- Los nuevos registros tendrán automáticamente `fecha_creacion` por el DEFAULT CURRENT_TIMESTAMP
- No se requieren cambios en el código PHP
