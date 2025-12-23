# Backend PingGo - Arquitectura de Microservicios

## 📋 Descripción

Backend de PingGo organizado en **microservicios modulares** para facilitar el escalamiento, mantenimiento y desarrollo paralelo.

---

## 🏗️ Estructura de Microservicios

```
backend/
├── auth/                          ✅ Microservicio de Autenticación y Usuarios
│   ├── check_user.php            → Verificar si usuario existe
│   ├── email_service.php         → Envío de correos (verificación)
│   ├── login.php                 → Iniciar sesión
│   ├── profile.php               → Obtener perfil de usuario
│   ├── profile_update.php        → Actualizar perfil/ubicación
│   ├── register.php              → Registrar nuevo usuario
│   ├── verify_code.php           → Verificar código de email
│   └── README_USER_MICROSERVICE.md
│
├── conductor/                     ✅ Microservicio de Conductores
│   ├── actualizar_disponibilidad.php  → Cambiar disponibilidad
│   ├── actualizar_ubicacion.php       → Actualizar GPS
│   ├── get_estadisticas.php           → Estadísticas del conductor
│   ├── get_ganancias.php              → Ganancias por periodo
│   ├── get_historial.php              → Historial de viajes
│   ├── get_info.php                   → Info completa del conductor
│   ├── get_profile.php                → Perfil de conductor
│   ├── get_viajes_activos.php         → Viajes en curso
│   ├── submit_verification.php        → Enviar para verificación
│   ├── update_license.php             → Actualizar licencia
│   ├── update_profile.php             → Actualizar perfil
│   ├── update_vehicle.php             → Actualizar vehículo
│   └── README_CONDUCTOR_MICROSERVICE.md
│
├── admin/                         ✅ Microservicio de Administración
│   ├── app_config.php            → Configuración de la app
│   ├── audit_logs.php            → Logs de auditoría
│   ├── dashboard_stats.php       → Estadísticas del dashboard
│   ├── user_management.php       → Gestión de usuarios
│   └── DEBUG_ADMIN.md
│
├── config/                        🔧 Configuración Compartida
│   ├── config.php                → Configuración general
│   └── database.php              → Conexión a base de datos
│
└── migrations/                    📦 Migraciones de Base de Datos
    ├── 001_create_admin_tables.sql
    ├── 002_conductor_fields.sql
    ├── 003_fix_usuarios_columns.sql
    └── README.md
```

---

## 🎯 Microservicios

### 1. Auth Service (`/auth`)

**Responsabilidad**: Autenticación, registro y gestión de usuarios.

**Endpoints principales**:
- `POST /auth/register.php` - Registrar usuario
- `POST /auth/login.php` - Iniciar sesión
- `GET /auth/profile.php` - Obtener perfil
- `POST /auth/profile_update.php` - Actualizar perfil/ubicación
- `POST /auth/check_user.php` - Verificar existencia
- `POST /auth/email_service.php` - Enviar código por email
- `POST /auth/verify_code.php` - Verificar código de email

**Base de datos**: Tabla `usuarios`, `direcciones_usuarios`

📚 [Documentación completa](./auth/README_USER_MICROSERVICE.md)

---

### 2. Conductor Service (`/conductor`)

**Responsabilidad**: Gestión de conductores, vehículos y viajes.

**Endpoints principales**:
- `GET /conductor/get_profile.php` - Perfil completo
- `POST /conductor/update_profile.php` - Actualizar perfil
- `POST /conductor/update_license.php` - Actualizar licencia
- `POST /conductor/update_vehicle.php` - Actualizar vehículo
- `GET /conductor/get_historial.php` - Historial de viajes
- `GET /conductor/get_ganancias.php` - Ganancias
- `POST /conductor/actualizar_disponibilidad.php` - Estado disponible/ocupado
- `POST /conductor/actualizar_ubicacion.php` - Ubicación GPS

**Base de datos**: Tablas `conductores`, `vehiculos`, `licencias`, `viajes`

📚 [Documentación completa](./conductor/README_CONDUCTOR_MICROSERVICE.md)

---

### 3. Admin Service (`/admin`)

**Responsabilidad**: Panel administrativo y gestión del sistema.

**Endpoints principales**:
- `GET /admin/dashboard_stats.php` - Estadísticas generales
- `GET /admin/user_management.php` - Gestión de usuarios
- `GET /admin/audit_logs.php` - Logs de auditoría
- `GET /admin/app_config.php` - Configuración de la app

**Base de datos**: Tablas `admins`, `audit_logs`, `app_config`

📚 [Documentación de debug](./admin/DEBUG_ADMIN.md)

---

## 🚀 Cómo Usar

### Desarrollo Local

**Base URL**: `http://10.0.2.2/pingo/backend` (Android Emulator)  
**Base URL**: `http://localhost/pingo/backend` (Navegador/Postman)

#### Ejemplos de uso:

```bash
# Login
curl -X POST http://localhost/pingo/backend/auth/login.php \
  -H "Content-Type: application/json" \
  -d '{"email":"test@test.com","password":"123456"}'

# Perfil de conductor
curl http://localhost/pingo/backend/conductor/get_profile.php?conductor_id=1

# Stats de admin
curl http://localhost/pingo/backend/admin/dashboard_stats.php?admin_id=1
```

---

## 🔧 Configuración

### Base de Datos

Configurar en `config/database.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'pingo');
define('DB_USER', 'root');
define('DB_PASS', 'root');
```

### Variables de Entorno

```php
// config/config.php
define('ENV', 'development'); // development, staging, production
define('DEBUG_MODE', true);
define('API_VERSION', 'v1');
```

---

## 📦 Migraciones

Ejecutar migraciones en orden:

```bash
# 1. Crear tablas de admin
mysql -u root -p pingo < migrations/001_create_admin_tables.sql

# 2. Agregar campos de conductor
mysql -u root -p pingo < migrations/002_conductor_fields.sql

# 3. Fix columnas de usuarios
mysql -u root -p pingo < migrations/003_fix_usuarios_columns.sql
```

📚 [Guía completa de migraciones](./migrations/README.md)

---

## 🧪 Testing

### Postman Collection

Importar colección de Postman:
- Auth endpoints: [auth_collection.json]
- Conductor endpoints: [conductor_collection.json]
- Admin endpoints: [admin_collection.json]

### Tests PHP

```bash
# Instalar PHPUnit
composer install

# Ejecutar tests
./vendor/bin/phpunit tests/
```

---

## 🔐 Seguridad

### Headers Requeridos

```
Content-Type: application/json
Accept: application/json
```

### Autenticación

- **Actual**: Sin tokens (desarrollo)
- **Próximo**: JWT tokens en header `Authorization: Bearer <token>`

### CORS

Configurado en cada endpoint PHP:
```php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
```

---

## 📊 Respuestas Estándar

### Éxito

```json
{
  "success": true,
  "message": "Operación exitosa",
  "data": { ... }
}
```

### Error

```json
{
  "success": false,
  "message": "Descripción del error",
  "error": "Detalles técnicos"
}
```

---

## 🎯 Migración a Producción

### Cambios Necesarios

1. **URLs**: Cambiar base URL en Flutter (`AppConfig`)
2. **Base de datos**: Usar credenciales de producción
3. **CORS**: Restringir orígenes permitidos
4. **JWT**: Implementar autenticación con tokens
5. **HTTPS**: Usar certificados SSL
6. **Logs**: Configurar logging a archivos

### Deploy Recomendado

```
API Gateway (nginx/kong)
    ↓
┌─────────────┬──────────────┬──────────────┐
│ Auth        │ Conductor    │ Admin        │
│ Service     │ Service      │ Service      │
│ (Port 8001) │ (Port 8002)  │ (Port 8003)  │
└─────────────┴──────────────┴──────────────┘
```

---

## 🔄 Roadmap

### Fase 1: Modularización ✅
- [x] Separar código en microservicios
- [x] Documentar cada servicio
- [x] Centralizar configuración

### Fase 2: Preparación
- [ ] Implementar JWT
- [ ] Agregar rate limiting
- [ ] Tests unitarios e integración
- [ ] CI/CD pipeline

### Fase 3: Separación Real
- [ ] Servidores separados por servicio
- [ ] API Gateway
- [ ] Bases de datos separadas
- [ ] Monitoreo (ELK, Prometheus)

---

## 📚 Documentación Adicional

- [Clean Architecture Flutter](../../docs/architecture/CLEAN_ARCHITECTURE.md)
- [Guía de Migración a Microservicios](../../docs/architecture/MIGRATION_TO_MICROSERVICES.md)
- [Limpieza de Microservicios](../../docs/architecture/MICROSERVICES_CLEANUP.md)
- [Guía Rápida de Rutas](../../docs/architecture/GUIA_RAPIDA_RUTAS.md)

---

## 🤝 Contribuir

1. Cada microservicio debe tener su README
2. Documentar endpoints con ejemplos
3. Usar el formato de respuesta estándar
4. Agregar validaciones y manejo de errores
5. Actualizar migraciones cuando cambies BD

---

## 📞 Soporte

- **Documentación completa**: `/docs/architecture/`
- **Issues**: GitHub Issues
- **Equipo**: Ping Go Development Team

---

**Última actualización**: Octubre 2025  
**Versión**: 1.0.0  
**Estado**: ✅ Microservicios organizados y documentados
