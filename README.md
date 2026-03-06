# Viax Backend

Backend API para la aplicación Viax.

## 🚀 Deployment en Producción (VPS)

### Información del Servidor
- **IP**: `76.13.114.194`
- **OS**: Ubuntu 24.04 LTS
- **Web Server**: Nginx
- **PHP**: 8.3
- **Database**: PostgreSQL 17

### Estructura de Directorios
```
/var/www/viax/backend/
├── auth/           # Microservicio de Autenticación
├── conductor/      # Microservicio de Conductores
├── admin/          # Microservicio de Administración
├── config/         # Configuración (DB, API Keys)
├── migrations/     # Migraciones de Base de Datos
└── logs/           # Logs del sistema
```

## 🔗 Endpoints Principales

- `/health.php` - Verificación de estado del sistema
- `/auth/login.php` - Inicio de sesión
- `/conductor/actualizar_disponibilidad.php` - Actualización de estado
- `/user/create_trip_request.php` - Solicitud de viajes
- `/conductor/vehicle_catalog.php` - Catálogo de marcas/modelos por tipo de vehículo (Colombia + vPIC)

## 🛠️ Comandos Útiles

```bash
# Verificar estado de servicios
sudo systemctl status nginx
sudo systemctl status php8.3-fpm

# Pull de cambios desde GitHub
cd /var/www/viax/backend
git pull origin main

# Ver logs de errores
tail -f /var/log/nginx/error.log
```

## ✉️ Configuración de Correo (SMTP seguro)

El envío de correos ahora usa un servicio centralizado en `services/EmailService.php`.

### Variables de entorno requeridas

Crear archivo `.env` en la raíz de `backend/` (puedes basarte en `.env.example`):

```env
APP_ENV=production
MAIL_PROVIDER=smtp
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=tu_correo_sistema@gmail.com
SMTP_PASS=tu_app_password_de_gmail
SMTP_FROM_EMAIL=tu_correo_sistema@gmail.com
SMTP_FROM_NAME=Viax
RATE_LIMIT_IP_PER_HOUR=15
RATE_LIMIT_USER_PER_HOUR=5
RATE_LIMIT_BLOCK_MINUTES=120
RATE_LIMIT_SECRET=usa-una-cadena-larga-aleatoria
```

### Seguridad

- Nunca hardcodear credenciales SMTP en archivos PHP.
- Logs de correo y abuso quedan en `storage/logs/email.log`.
- El acceso web a `storage/` y `storage/logs/` está bloqueado con `.htaccess`.

## 📄 Licencia

Propiedad de **Braian Andres Oquendo Durango**.
Todos los derechos reservados.
