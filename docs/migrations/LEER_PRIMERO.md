# ⚠️ MIGRACIÓN URGENTE REQUERIDA

## 🚨 PROBLEMA ACTUAL

Si estás viendo este error:
```
Error Code: 1054. Unknown column 'es_activo' in 'field list'
AdminService: Error al obtener estadísticas
```

**Necesitas ejecutar la migración 003 INMEDIATAMENTE**.

---

## 📋 Migración 003: Fix Usuarios Columns

### ¿Qué hace esta migración?

Corrige los nombres de columnas en la tabla `usuarios` para que coincidan con lo que espera el backend PHP:

| Columna Antigua | Columna Nueva | Tipo |
|----------------|---------------|------|
| `activo` | `es_activo` | TINYINT(1) |
| `verificado` | `es_verificado` | TINYINT(1) |
| `url_imagen_perfil` | `foto_perfil` | VARCHAR(500) |
| `creado_en` | `fecha_registro` | TIMESTAMP |
| `actualizado_en` | `fecha_actualizacion` | TIMESTAMP |

---

## 🚀 CÓMO EJECUTAR (Elige una opción)

### ✅ Opción 1: MySQL Workbench (MÁS SEGURA)

1. **Abre MySQL Workbench**
2. **Conéctate a tu servidor** (localhost, usuario: root)
3. **Abre el archivo**: `run_migration_003.sql`
   - Ubicación: `c:\Flutter\ping_go\pingo\backend\migrations\run_migration_003.sql`
4. **Ejecuta el script completo** (Ctrl + Shift + Enter)
5. **Verifica la salida** - debe decir "Migración completada"

Este método automáticamente:
- ✅ Crea un backup de tu tabla usuarios
- ✅ Ejecuta la migración
- ✅ Verifica los cambios

### Opción 2: Línea de Comandos

```powershell
# Abre PowerShell como Administrador
cd c:\Flutter\ping_go\pingo\backend\migrations

# Ejecuta la migración (te pedirá la contraseña de MySQL)
mysql -u root -p pingo < 003_fix_usuarios_columns.sql
```

### Opción 3: phpMyAdmin

1. Abre phpMyAdmin en tu navegador
2. Selecciona la base de datos `pingo`
3. Ve a la pestaña **SQL**
4. Abre el archivo `003_fix_usuarios_columns.sql` en un editor
5. Copia TODO el contenido
6. Pégalo en phpMyAdmin
7. Haz clic en **Ejecutar**

---

## ✅ VERIFICACIÓN

Después de ejecutar la migración, verifica que funcionó:

```sql
-- Ejecuta esto en MySQL Workbench o phpMyAdmin
USE pingo;

DESCRIBE usuarios;
```

**Deberías ver estas columnas:**
- ✅ `es_activo`
- ✅ `es_verificado`
- ✅ `foto_perfil`
- ✅ `fecha_registro`
- ✅ `fecha_actualizacion`

**NO deberías ver estas columnas:**
- ❌ `activo`
- ❌ `verificado`
- ❌ `url_imagen_perfil`
- ❌ `creado_en`
- ❌ `actualizado_en`

---

## 🔄 Después de la Migración

1. **Reinicia tu servidor PHP** (si estás usando uno)
2. **Vuelve a ejecutar tu aplicación Flutter**
3. **Verifica que el panel de administración funcione**

---

## 🆘 Si algo sale mal

### Restaurar desde el backup:

```sql
-- Si la migración falló, restaura el backup
USE pingo;

-- Eliminar la tabla modificada
DROP TABLE usuarios;

-- Restaurar desde el backup
RENAME TABLE usuarios_backup_20251023 TO usuarios;
```

---

## 📝 Otros Archivos de Migración

### Orden de Ejecución de Todas las Migraciones:

1. ✅ `001_create_admin_tables.sql` - Tablas de administración
2. ✅ `002_conductor_fields.sql` - Campos de conductor
3. ⚠️ **`003_fix_usuarios_columns.sql`** - **EJECUTAR AHORA**

---

## 🐛 Troubleshooting

### Error: "Access denied for user"
- Verifica tu usuario y contraseña de MySQL
- Asegúrate de tener permisos de ALTER TABLE

### Error: "Column 'es_activo' already exists"
- La migración ya fue ejecutada
- Verifica con `DESCRIBE usuarios;`

### El error persiste después de la migración
1. Reinicia el servidor PHP/Apache
2. Limpia el caché del navegador
3. Verifica los logs en `pingo/backend/logs/`
4. Asegúrate de estar usando la base de datos correcta

### Error: "Table 'usuarios_backup_20251023' already exists"
```sql
-- Elimina el backup anterior
DROP TABLE IF EXISTS usuarios_backup_20251023;
```

---

## 📞 Necesitas Ayuda?

Si tienes problemas:
1. Revisa los mensajes de error completos
2. Verifica la conexión a la base de datos
3. Asegúrate de tener permisos suficientes
4. Consulta los logs del sistema

---

## ⚡ Resumen Rápido

```bash
# EN MYSQL WORKBENCH O LÍNEA DE COMANDOS:
USE pingo;
SOURCE c:/Flutter/ping_go/pingo/backend/migrations/003_fix_usuarios_columns.sql;
```

**¡Eso es todo!** Después de esto, tu aplicación debería funcionar correctamente.
