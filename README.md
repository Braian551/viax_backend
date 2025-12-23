# PingGo Backend

Backend API para la aplicación PingGo.

## 🚀 Deployment en Railway

### Opción 1: Repo Separado (Recomendado)

1. **Crear nuevo repositorio en GitHub:**
   - Ve a GitHub y crea un nuevo repo llamado `pinggo-backend`
   - No inicialices con README

2. **Subir solo el backend:**
   ```bash
   # Desde la carpeta backend-deploy
   git init
   git add .
   git commit -m "Initial backend deployment"
   git branch -M main
   git remote add origin https://github.com/Braian551/pinggo-backend.git
   git push -u origin main
   ```

3. **Deploy en Railway:**
   - Conecta el repo `pinggo-backend`
   - Railway detectará automáticamente la configuración
   - Agrega una base de datos MySQL

### Opción 2: Usar el repo completo con configuración específica

Si prefieres usar el repo completo, Railway usará la configuración en `railway.json` y `nixpacks.toml` para construir solo la carpeta `pingo/backend`.

## 📊 Base de Datos

El backend incluye migraciones automáticas que se ejecutan durante el deployment.

## 🔗 Endpoints

- `GET /verify_system` - Verificación del sistema
- `POST /user/create_trip_request` - Crear solicitud de viaje
- `GET /user/check_solicitudes` - Verificar solicitudes
- `POST /conductor/...` - Endpoints para conductores
- `POST /auth/...` - Endpoints de autenticación

## ⚙️ Variables de Entorno

Railway configura automáticamente:
- `MYSQLHOST`
- `MYSQLDATABASE`
- `MYSQLUSER`
- `MYSQLPASSWORD`