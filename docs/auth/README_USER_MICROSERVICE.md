# Microservicio de Usuarios - Backend

## Descripción

Microservicio responsable de la autenticación y gestión de usuarios del sistema Ping Go.

## Estructura

```
backend/auth/  (User Microservice)
├── v1/                      # Versión 1 de la API
│   ├── register.php         # POST - Registrar usuario
│   ├── login.php            # POST - Iniciar sesión
│   ├── profile.php          # GET - Obtener perfil
│   ├── profile_update.php   # POST - Actualizar perfil
│   └── check_user.php       # POST - Verificar existencia
├── config/
│   └── config.php           # Configuración del servicio
└── README.md                # Este archivo
```

## Endpoints

### Base URL
- **Desarrollo**: `http://localhost/pingo/backend/auth`
- **Producción**: `https://api.pingo.com/user-service/v1`

### 1. Registrar Usuario

**Endpoint**: `POST /register.php`

**Request Body**:
```json
{
  "name": "Juan",
  "lastName": "Pérez",
  "email": "juan@example.com",
  "phone": "3001234567",
  "password": "123456",
  "address": "Calle 123",
  "latitude": 4.6097,
  "longitude": -74.0817,
  "city": "Bogotá",
  "state": "Cundinamarca",
  "country": "Colombia"
}
```

**Response Success** (200):
```json
{
  "success": true,
  "message": "Usuario registrado correctamente",
  "data": {
    "user": {
      "id": 1,
      "uuid": "user_123",
      "nombre": "Juan",
      "apellido": "Pérez",
      "email": "juan@example.com",
      "telefono": "3001234567",
      "tipo_usuario": "pasajero",
      "creado_en": "2024-10-25 10:30:00"
    },
    "location": {
      "id": 1,
      "usuario_id": 1,
      "latitud": 4.6097,
      "longitud": -74.0817,
      "direccion": "Calle 123",
      "ciudad": "Bogotá",
      "departamento": "Cundinamarca",
      "pais": "Colombia",
      "es_principal": 1
    }
  }
}
```

**Response Error** (200/500):
```json
{
  "success": false,
  "message": "El usuario ya existe"
}
```

### 2. Iniciar Sesión

**Endpoint**: `POST /login.php`

**Request Body**:
```json
{
  "email": "juan@example.com",
  "password": "123456"
}
```

**Response Success** (200):
```json
{
  "success": true,
  "message": "Login exitoso",
  "data": {
    "user": {
      "id": 1,
      "uuid": "user_123",
      "nombre": "Juan",
      "apellido": "Pérez",
      "email": "juan@example.com",
      "telefono": "3001234567",
      "tipo_usuario": "pasajero"
    }
  }
}
```

**Response Error** (200):
```json
{
  "success": false,
  "message": "Contraseña incorrecta"
}
```

### 3. Obtener Perfil

**Endpoint**: `GET /profile.php?userId=1` o `GET /profile.php?email=juan@example.com`

**Response Success** (200):
```json
{
  "success": true,
  "message": "Perfil obtenido correctamente",
  "data": {
    "user": {
      "id": 1,
      "uuid": "user_123",
      "nombre": "Juan",
      "apellido": "Pérez",
      "email": "juan@example.com",
      "telefono": "3001234567",
      "tipo_usuario": "pasajero",
      "creado_en": "2024-10-25 10:30:00"
    },
    "location": {
      "id": 1,
      "usuario_id": 1,
      "latitud": 4.6097,
      "longitud": -74.0817,
      "direccion": "Calle 123",
      "ciudad": "Bogotá",
      "departamento": "Cundinamarca",
      "pais": "Colombia",
      "es_principal": 1
    }
  }
}
```

### 4. Actualizar Perfil/Ubicación

**Endpoint**: `POST /profile_update.php`

**Request Body**:
```json
{
  "userId": 1,
  "name": "Juan Carlos",
  "lastName": "Pérez García",
  "phone": "3009876543",
  "address": "Carrera 45 #67-89",
  "latitude": 4.7110,
  "longitude": -74.0721,
  "city": "Bogotá",
  "state": "Cundinamarca"
}
```

**Response Success** (200):
```json
{
  "success": true,
  "message": "Perfil actualizado correctamente",
  "data": {
    "user": {
      // Usuario actualizado
    },
    "location": {
      // Ubicación actualizada
    }
  }
}
```

### 5. Verificar Usuario

**Endpoint**: `POST /check_user.php`

**Request Body**:
```json
{
  "email": "juan@example.com"
}
```

**Response Success** (200):
```json
{
  "exists": true
}
```

## Códigos de Estado

- **200 OK**: Operación exitosa
- **404 Not Found**: Usuario no encontrado
- **500 Internal Server Error**: Error del servidor

## Base de Datos

### Tablas Utilizadas

#### `usuarios`
```sql
- id: INT PRIMARY KEY
- uuid: VARCHAR(255) UNIQUE
- nombre: VARCHAR(100)
- apellido: VARCHAR(100)
- email: VARCHAR(255) UNIQUE
- telefono: VARCHAR(20)
- hash_contrasena: VARCHAR(255)
- tipo_usuario: ENUM('pasajero', 'conductor', 'admin')
- creado_en: TIMESTAMP
- actualizado_en: TIMESTAMP
```

#### `ubicaciones_usuario`
```sql
- id: INT PRIMARY KEY
- usuario_id: INT FOREIGN KEY
- latitud: DECIMAL(10, 8)
- longitud: DECIMAL(11, 8)
- direccion: TEXT
- ciudad: VARCHAR(100)
- departamento: VARCHAR(100)
- pais: VARCHAR(100)
- codigo_postal: VARCHAR(20)
- es_principal: TINYINT(1)
- creado_en: TIMESTAMP
- actualizado_en: TIMESTAMP
```

## Migración a Microservicios

### Estado Actual
✅ Estructura modular lista
✅ Clean Architecture implementada en Flutter
✅ Endpoints versionados (preparado para v1, v2, etc.)
✅ Misma base de datos (fase 1)

### Próximos Pasos
- [ ] Separar base de datos (`user_db`)
- [ ] Implementar autenticación con JWT
- [ ] Agregar API Gateway
- [ ] Dockerizar servicio
- [ ] Implementar rate limiting
- [ ] Agregar logging centralizado
- [ ] Configurar CI/CD

### Ejemplo Docker (Futuro)

```dockerfile
FROM php:8.1-apache
WORKDIR /var/www/html
COPY . .
RUN docker-php-ext-install pdo pdo_mysql
EXPOSE 8001
CMD ["apache2-foreground"]
```

### Ejemplo docker-compose.yml (Futuro)

```yaml
version: '3.8'
services:
  user-service:
    build: ./backend/auth
    ports:
      - "8001:80"
    environment:
      - DB_HOST=mysql
      - DB_NAME=user_db
      - DB_USER=pingo_user
      - DB_PASS=secret
    depends_on:
      - mysql
  
  mysql:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: user_db
      MYSQL_USER: pingo_user
      MYSQL_PASSWORD: secret
      MYSQL_ROOT_PASSWORD: root_secret
    volumes:
      - user_db_data:/var/lib/mysql

volumes:
  user_db_data:
```

## Seguridad

- ✅ Contraseñas hasheadas con `password_hash()` (bcrypt)
- ✅ Prepared statements (prevención SQL injection)
- ✅ Validación de entrada
- ⏳ JWT tokens (próximamente)
- ⏳ HTTPS obligatorio (producción)
- ⏳ Rate limiting (próximamente)

## Testing

```bash
# Probar registro
curl -X POST http://localhost/pingo/backend/auth/register.php \
  -H "Content-Type: application/json" \
  -d '{"name":"Test","lastName":"User","email":"test@test.com","phone":"123456","password":"123456"}'

# Probar login
curl -X POST http://localhost/pingo/backend/auth/login.php \
  -H "Content-Type: application/json" \
  -d '{"email":"test@test.com","password":"123456"}'
```

## Contacto

- **Equipo**: Ping Go Development Team
- **Versión**: 1.0.0 (Octubre 2025)
- **Documentación**: `/docs/architecture/MIGRATION_TO_MICROSERVICES.md`
