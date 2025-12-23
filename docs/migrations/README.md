# Migraciones de Base de Datos - PinGo

Este directorio contiene las migraciones SQL para actualizar la base de datos del sistema.

## Orden de Ejecución

Las migraciones deben ejecutarse en orden numérico:

1. `001_create_admin_tables.sql` - Crea tablas para el módulo de administrador

## Cómo Ejecutar una Migración

### Opción 1: MySQL Command Line
```bash
mysql -u root -p pingo < migrations/001_create_admin_tables.sql
```

### Opción 2: phpMyAdmin
1. Abre phpMyAdmin
2. Selecciona la base de datos `pingo`
3. Ve a la pestaña SQL
4. Copia y pega el contenido del archivo de migración
5. Ejecuta la consulta

### Opción 3: Script PHP (Recomendado)
```bash
php migrations/run_migrations.php
```

## Verificar Migraciones Aplicadas

Puedes verificar qué tablas se crearon ejecutando:
```sql
SHOW TABLES LIKE 'logs_auditoria';
SHOW TABLES LIKE 'estadisticas_sistema';
SHOW TABLES LIKE 'configuraciones_app';
SHOW TABLES LIKE 'reportes_usuarios';
```

## Rollback

Si necesitas revertir una migración, cada archivo debe tener su correspondiente archivo de rollback.
Por ejemplo: `001_create_admin_tables_rollback.sql`
