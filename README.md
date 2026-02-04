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

## 📄 Licencia

Propiedad de **Braian Andres Oquendo Durango**.
Todos los derechos reservados.
