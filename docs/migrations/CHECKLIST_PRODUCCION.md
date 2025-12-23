# ✅ Checklist de Migración - Producción

## Pre-Migración

### Preparación (1 hora antes)
- [ ] Notificar al equipo sobre la ventana de mantenimiento
- [ ] Verificar que tienes acceso al servidor de base de datos
- [ ] Confirmar credenciales de MySQL (usuario con privilegios ALTER)
- [ ] Backup completo de la base de datos:
  ```bash
  mysqldump -u root -p pingo > backup_completo_$(date +%Y%m%d_%H%M%S).sql
  ```
- [ ] Verificar espacio en disco suficiente (al menos 2x tamaño de tabla usuarios)
- [ ] Tener acceso al panel de control del servidor (cPanel/SSH)

### Validación Local (antes de producción)
- [ ] Migración ejecutada en ambiente de desarrollo
- [ ] Migración ejecutada en ambiente de staging
- [ ] Pruebas de login funcionando
- [ ] Dashboard de admin funcionando
- [ ] Sin errores en logs de PHP
- [ ] Sin errores en logs de MySQL

---

## Durante la Migración

### Paso 1: Backup (5 min)
- [ ] Conectar a la base de datos de producción
- [ ] Crear backup de tabla usuarios:
  ```sql
  USE pingo;
  CREATE TABLE usuarios_backup_20251023 AS SELECT * FROM usuarios;
  ```
- [ ] Verificar que el backup tiene datos:
  ```sql
  SELECT COUNT(*) FROM usuarios_backup_20251023;
  ```
- [ ] Anotar el número de registros: _______________

### Paso 2: Subir Archivos (2 min)
- [ ] Conectar al servidor vía FTP/SFTP
- [ ] Navegar a `/ruta/a/pingo/backend/migrations/`
- [ ] Subir archivo: `003_fix_usuarios_columns.sql`
- [ ] Verificar que el archivo se subió correctamente

### Paso 3: Ejecutar Migración (1 min)

**Opción A - MySQL Command Line:**
- [ ] Conectar vía SSH al servidor
  ```bash
  ssh usuario@servidor.com
  ```
- [ ] Ejecutar migración:
  ```bash
  cd /ruta/a/pingo/backend/migrations
  mysql -u usuario_db -p nombre_db < 003_fix_usuarios_columns.sql
  ```
- [ ] Anotar hora de inicio: _______________
- [ ] Anotar hora de fin: _______________

**Opción B - phpMyAdmin:**
- [ ] Abrir phpMyAdmin
- [ ] Seleccionar base de datos `pingo`
- [ ] Ir a pestaña SQL
- [ ] Copiar contenido de `003_fix_usuarios_columns.sql`
- [ ] Pegar en el editor SQL
- [ ] Hacer clic en "Ejecutar"
- [ ] Captura de pantalla del resultado exitoso

**Opción C - MySQL Workbench:**
- [ ] Abrir MySQL Workbench
- [ ] Conectar al servidor de producción
- [ ] Abrir archivo `003_fix_usuarios_columns.sql`
- [ ] Ejecutar script (Ctrl + Shift + Enter)
- [ ] Verificar salida sin errores

### Paso 4: Verificación Inmediata (2 min)
- [ ] Ejecutar verificación de estructura:
  ```sql
  USE pingo;
  DESCRIBE usuarios;
  ```
- [ ] Confirmar columnas nuevas existen:
  - [ ] `es_activo` ✅
  - [ ] `es_verificado` ✅
  - [ ] `foto_perfil` ✅
  - [ ] `fecha_registro` ✅
  - [ ] `fecha_actualizacion` ✅

- [ ] Confirmar columnas antiguas NO existen:
  - [ ] `activo` ❌
  - [ ] `verificado` ❌
  - [ ] `url_imagen_perfil` ❌
  - [ ] `creado_en` ❌
  - [ ] `actualizado_en` ❌

- [ ] Verificar integridad de datos:
  ```sql
  SELECT COUNT(*) FROM usuarios;
  ```
- [ ] Confirmar que el count coincide con el backup: _______________

- [ ] Probar consulta con nuevas columnas:
  ```sql
  SELECT id, nombre, email, es_activo, fecha_registro FROM usuarios LIMIT 5;
  ```
- [ ] Sin errores ✅

### Paso 5: Reiniciar Servicios (3 min)
- [ ] Reiniciar Apache/Nginx:
  ```bash
  sudo service apache2 restart
  # o
  sudo service nginx restart
  ```
- [ ] Reiniciar PHP-FPM (si aplica):
  ```bash
  sudo service php-fpm restart
  ```
- [ ] Limpiar caché de OPcache (si aplica):
  ```bash
  sudo service php7.4-fpm reload
  ```

---

## Post-Migración

### Validación Funcional (10 min)
- [ ] **Probar Login de Usuario Regular**
  - [ ] Email: _______________
  - [ ] Login exitoso ✅
  - [ ] Perfil carga correctamente ✅

- [ ] **Probar Login de Administrador**
  - [ ] Email: _______________
  - [ ] Login exitoso ✅
  - [ ] Dashboard carga correctamente ✅
  - [ ] Estadísticas se muestran sin error ✅

- [ ] **Probar Gestión de Usuarios**
  - [ ] Lista de usuarios carga ✅
  - [ ] Filtros funcionan ✅
  - [ ] Editar usuario funciona ✅

- [ ] **Probar Registro de Nuevo Usuario**
  - [ ] Crear cuenta de prueba ✅
  - [ ] Usuario aparece en lista ✅
  - [ ] Columnas nuevas tienen valores correctos ✅

### Revisión de Logs (5 min)
- [ ] **Logs de PHP** (sin errores relacionados con usuarios)
  ```bash
  tail -n 100 /var/log/apache2/error.log | grep usuarios
  # o
  tail -n 100 /var/log/php-fpm/error.log
  ```
  - [ ] Sin errores de columnas ✅
  - [ ] Sin errores SQL ✅

- [ ] **Logs de MySQL** (sin errores)
  ```bash
  tail -n 100 /var/log/mysql/error.log
  ```
  - [ ] Sin errores de ALTER TABLE ✅

- [ ] **Logs de Aplicación** (verificar en dashboard admin)
  - [ ] Revisar logs de auditoría ✅
  - [ ] Últimas actividades se registran ✅

### Monitoreo (24 horas)
- [ ] **Hora 1 después de migración**
  - [ ] Revisar logs: _______________
  - [ ] Usuarios activos: _______________
  - [ ] Errores reportados: _______________

- [ ] **Hora 4 después de migración**
  - [ ] Revisar logs: _______________
  - [ ] Usuarios activos: _______________
  - [ ] Errores reportados: _______________

- [ ] **Hora 24 después de migración**
  - [ ] Revisar logs: _______________
  - [ ] Usuarios activos: _______________
  - [ ] Errores reportados: _______________
  - [ ] ✅ Sistema estable

---

## Limpieza (Después de 7 días)

- [ ] Confirmar que no hay problemas reportados
- [ ] Eliminar backup temporal:
  ```sql
  DROP TABLE IF EXISTS usuarios_backup_20251023;
  ```
- [ ] Documentar migración en bitácora del proyecto
- [ ] Actualizar documentación técnica
- [ ] Archivar logs de migración

---

## Plan de Rollback (Solo si hay problemas)

### Si algo sale mal DURANTE la migración:
1. [ ] Detener la migración
2. [ ] Restaurar backup:
   ```sql
   DROP TABLE usuarios;
   RENAME TABLE usuarios_backup_20251023 TO usuarios;
   ```
3. [ ] Verificar que todo volvió a la normalidad
4. [ ] Analizar causa del error
5. [ ] Corregir problema
6. [ ] Programar nueva ventana de mantenimiento

### Si algo sale mal DESPUÉS de la migración:
1. [ ] Documentar el problema específico
2. [ ] Evaluar si es crítico o menor
3. [ ] Si es crítico:
   - [ ] Notificar al equipo
   - [ ] Restaurar backup
   - [ ] Programar corrección
4. [ ] Si es menor:
   - [ ] Crear ticket
   - [ ] Corregir sin revertir migración

---

## Notas y Observaciones

### Hora de inicio: _______________
### Hora de fin: _______________
### Duración total: _______________

### Incidentes:
```
[Registrar cualquier problema o nota importante aquí]





```

### Responsable de la migración:
- **Nombre**: _______________
- **Firma**: _______________
- **Fecha**: _______________

### Aprobación (si aplica):
- **Nombre**: _______________
- **Firma**: _______________
- **Fecha**: _______________

---

## Contactos de Emergencia

- **DBA**: _______________
- **DevOps**: _______________
- **Backend Lead**: _______________
- **Soporte**: _______________

---

## Resultado Final

- [ ] ✅ Migración exitosa sin problemas
- [ ] ⚠️ Migración exitosa con observaciones menores
- [ ] ❌ Migración fallida - rollback ejecutado

### Estado final del sistema:
```
[Describir estado final]





```

---

**Fecha de este checklist**: 23 de Octubre, 2025  
**Versión**: 1.0  
**Proyecto**: PinGo Backend - Migración 003
