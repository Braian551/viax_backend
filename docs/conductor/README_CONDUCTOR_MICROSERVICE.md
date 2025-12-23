# Microservicio de Conductores - API Backend

## 📋 Descripción General

Este microservicio maneja todas las operaciones relacionadas con conductores: perfiles, verificaciones, licencias, vehículos, estadísticas, ganancias y gestión de disponibilidad.

## 🌐 Endpoints

### Base URL
```
http://localhost/pingo/backend/conductor/
```

---

## 🔐 Autenticación

Todos los endpoints requieren un `conductor_id` válido para identificar al conductor.

---

## 📍 Endpoints Disponibles

### 1. Obtener Perfil del Conductor

**GET** `/get_profile.php`

Obtiene el perfil completo del conductor incluyendo licencia y vehículo.

**Parámetros Query:**
```
conductor_id (int, requerido): ID del conductor
```

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "conductor": {
    "id": 1,
    "conductor_id": 123,
    "nombre_completo": "Juan Pérez",
    "telefono": "+51987654321",
    "direccion": "Av. Principal 123",
    "license": {
      "numero": "Q12345678",
      "categoria": "A-IIa",
      "fecha_emision": "2020-01-15",
      "fecha_vencimiento": "2025-01-15",
      "foto_frontal": "url_foto_frontal.jpg",
      "foto_posterior": "url_foto_posterior.jpg"
    },
    "vehicle": {
      "marca": "Honda",
      "modelo": "CG 150",
      "anio": 2021,
      "color": "Rojo",
      "placa": "ABC-123",
      "foto": "url_foto_moto.jpg",
      "tarjeta_propiedad": "url_tarjeta.jpg"
    },
    "aprobado": true,
    "motivo_rechazo": null,
    "fecha_aprobacion": "2023-06-15 10:30:00",
    "fecha_creacion": "2023-06-01 08:00:00",
    "fecha_actualizacion": "2023-12-15 14:20:00"
  }
}
```

---

### 2. Actualizar Perfil

**POST** `/update_profile.php`

Actualiza la información básica del conductor.

**Body (JSON):**
```json
{
  "conductor_id": 123,
  "nombre_completo": "Juan Pérez García",
  "telefono": "+51987654321",
  "direccion": "Av. Principal 456"
}
```

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "message": "Perfil actualizado correctamente",
  "conductor": { /* objeto completo actualizado */ }
}
```

---

### 3. Actualizar Licencia de Conducir

**POST** `/update_license.php`

Actualiza los datos de la licencia de conducir.

**Body (JSON):**
```json
{
  "conductor_id": 123,
  "numero": "Q12345678",
  "categoria": "A-IIa",
  "fecha_emision": "2020-01-15",
  "fecha_vencimiento": "2025-01-15",
  "foto_frontal": "base64_string_or_url",
  "foto_posterior": "base64_string_or_url"
}
```

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "message": "Licencia actualizada correctamente",
  "license": { /* objeto licencia actualizado */ }
}
```

---

### 4. Actualizar Vehículo

**POST** `/update_vehicle.php`

Actualiza la información del vehículo del conductor.

**Body (JSON):**
```json
{
  "conductor_id": 123,
  "marca": "Honda",
  "modelo": "CG 150",
  "anio": 2021,
  "color": "Rojo",
  "placa": "ABC-123",
  "foto": "base64_string_or_url",
  "tarjeta_propiedad": "base64_string_or_url"
}
```

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "message": "Vehículo actualizado correctamente",
  "vehicle": { /* objeto vehículo actualizado */ }
}
```

---

### 5. Enviar para Verificación/Aprobación

**POST** `/submit_verification.php`

Envía el perfil del conductor para revisión y aprobación por un administrador.

**Body (JSON):**
```json
{
  "conductor_id": 123
}
```

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "message": "Perfil enviado para verificación. Recibirás una notificación cuando sea revisado."
}
```

**Respuesta Error (400):**
```json
{
  "success": false,
  "message": "Debes completar todos los datos antes de enviar para verificación"
}
```

---

### 6. Obtener Estado de Verificación

**GET** `/get_verification_status.php`

Obtiene el estado actual de verificación del conductor.

**Parámetros Query:**
```
conductor_id (int, requerido): ID del conductor
```

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "status": {
    "aprobado": true,
    "motivo_rechazo": null,
    "fecha_aprobacion": "2023-06-15 10:30:00",
    "estado": "aprobado" // valores: "pendiente", "aprobado", "rechazado"
  }
}
```

---

### 7. Obtener Estadísticas del Conductor

**GET** `/get_estadisticas.php`

Obtiene las estadísticas generales del conductor.

**Parámetros Query:**
```
conductor_id (int, requerido): ID del conductor
```

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "statistics": {
    "total_viajes": 156,
    "viajes_completados": 150,
    "viajes_cancelados": 6,
    "calificacion_promedio": 4.8,
    "total_calificaciones": 142,
    "ganancia_total": 3420.50,
    "horas_trabajadas": 230.5,
    "distancia_total_km": 2456.8
  }
}
```

---

### 8. Obtener Ganancias

**GET** `/get_ganancias.php`

Obtiene las ganancias del conductor filtradas por período.

**Parámetros Query:**
```
conductor_id (int, requerido): ID del conductor
periodo (string, requerido): 'hoy', 'semana', 'mes', 'custom'
fecha_inicio (string, opcional): Fecha inicio para período 'custom' (YYYY-MM-DD)
fecha_fin (string, opcional): Fecha fin para período 'custom' (YYYY-MM-DD)
```

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "earnings": {
    "periodo": "hoy",
    "total": 125.50,
    "cantidad_viajes": 8,
    "promedio_por_viaje": 15.69,
    "detalles": [
      {
        "viaje_id": 456,
        "fecha": "2024-01-15 08:30:00",
        "origen": "Cercado de Lima",
        "destino": "Miraflores",
        "monto": 18.50,
        "propina": 2.00,
        "total": 20.50
      }
      // ... más viajes
    ]
  }
}
```

---

### 9. Obtener Historial de Viajes

**GET** `/get_historial.php`

Obtiene el historial completo de viajes del conductor.

**Parámetros Query:**
```
conductor_id (int, requerido): ID del conductor
limite (int, opcional): Cantidad de viajes a retornar (default: 50)
pagina (int, opcional): Número de página para paginación (default: 1)
```

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "trips": [
    {
      "id": 789,
      "usuario_id": 45,
      "usuario_nombre": "María López",
      "origen": {
        "direccion": "Av. Arequipa 1234, Lima",
        "latitud": -12.0464,
        "longitud": -77.0428
      },
      "destino": {
        "direccion": "Jirón de la Unión 456, Lima",
        "latitud": -12.0478,
        "longitud": -77.0301
      },
      "fecha_inicio": "2024-01-15 14:30:00",
      "fecha_fin": "2024-01-15 14:55:00",
      "duracion_minutos": 25,
      "distancia_km": 5.8,
      "tarifa": 15.00,
      "propina": 2.00,
      "total": 17.00,
      "estado": "completado",
      "calificacion_usuario": 5,
      "comentario_usuario": "Excelente servicio"
    }
    // ... más viajes
  ],
  "total": 156,
  "pagina_actual": 1,
  "total_paginas": 4
}
```

---

### 10. Obtener Viajes Activos

**GET** `/get_viajes_activos.php`

Obtiene los viajes actualmente en curso o pendientes del conductor.

**Parámetros Query:**
```
conductor_id (int, requerido): ID del conductor
```

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "trips": [
    {
      "id": 890,
      "usuario_id": 67,
      "usuario_nombre": "Carlos Ramírez",
      "usuario_telefono": "+51912345678",
      "origen": {
        "direccion": "Av. La Marina 2000, San Miguel",
        "latitud": -12.0776,
        "longitud": -77.0861
      },
      "destino": {
        "direccion": "Av. Universitaria 1801, San Miguel",
        "latitud": -12.0700,
        "longitud": -77.0828
      },
      "fecha_solicitud": "2024-01-15 16:45:00",
      "estado": "aceptado", // valores: "pendiente", "aceptado", "en_curso"
      "tarifa_estimada": 12.00,
      "distancia_estimada_km": 3.5,
      "tiempo_estimado_minutos": 15
    }
  ]
}
```

---

### 11. Actualizar Disponibilidad

**POST** `/update_availability.php`

Actualiza el estado de disponibilidad del conductor (disponible/no disponible).

**Body (JSON):**
```json
{
  "conductor_id": 123,
  "disponible": 1  // 1 = disponible, 0 = no disponible
}
```

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "message": "Disponibilidad actualizada correctamente",
  "disponible": true
}
```

---

### 12. Actualizar Ubicación en Tiempo Real

**POST** `/update_location.php`

Actualiza la ubicación GPS del conductor en tiempo real.

**Body (JSON):**
```json
{
  "conductor_id": 123,
  "latitud": -12.0464,
  "longitud": -77.0428
}
```

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "message": "Ubicación actualizada correctamente"
}
```

---

## 📊 Códigos de Estado HTTP

| Código | Descripción |
|--------|-------------|
| 200 | Operación exitosa |
| 400 | Solicitud inválida (datos faltantes o incorrectos) |
| 404 | Recurso no encontrado (conductor no existe) |
| 500 | Error interno del servidor |

---

## 🔧 Manejo de Errores

Todas las respuestas de error siguen este formato:

```json
{
  "success": false,
  "message": "Descripción del error",
  "error_code": "CODIGO_ERROR" // opcional
}
```

### Códigos de Error Comunes

- `CONDUCTOR_NOT_FOUND`: Conductor no encontrado
- `INVALID_DATA`: Datos de entrada inválidos
- `PROFILE_INCOMPLETE`: Perfil incompleto para verificación
- `UNAUTHORIZED`: Acceso no autorizado
- `SERVER_ERROR`: Error interno del servidor

---

## 🚀 Migración a Microservicios

### Fase 1 (Actual)
- Endpoints monolíticos en `/pingo/backend/conductor/`
- Conectados a la base de datos `pingo` compartida

### Fase 2 (Planeada)
Estructura propuesta:
```
/api/v1/conductor/
├── profile/
│   ├── GET    /{id}
│   ├── PUT    /{id}
│   └── POST   /{id}/verify
├── license/
│   └── PUT    /{conductor_id}
├── vehicle/
│   └── PUT    /{conductor_id}
├── statistics/
│   └── GET    /{conductor_id}
├── earnings/
│   └── GET    /{conductor_id}
├── trips/
│   ├── GET    /{conductor_id}/history
│   └── GET    /{conductor_id}/active
└── location/
    ├── POST   /{conductor_id}
    └── PUT    /{conductor_id}/availability
```

---

## 📝 Notas de Desarrollo

1. **Validación de Datos**: Todos los endpoints validan los datos de entrada antes de procesarlos
2. **Seguridad**: Implementar autenticación JWT en la Fase 2
3. **Rate Limiting**: Considerar limitar solicitudes por conductor para `/update_location.php`
4. **Caché**: Los datos estadísticos pueden ser cacheados por 5-10 minutos
5. **Logging**: Registrar todas las operaciones críticas (aprobaciones, rechazos, cambios de perfil)

---

## 🧪 Testing

### Pruebas con cURL

**Obtener perfil:**
```bash
curl "http://localhost/pingo/backend/conductor/get_profile.php?conductor_id=123"
```

**Actualizar disponibilidad:**
```bash
curl -X POST http://localhost/pingo/backend/conductor/update_availability.php \
  -H "Content-Type: application/json" \
  -d '{"conductor_id": 123, "disponible": 1}'
```

**Obtener ganancias del día:**
```bash
curl "http://localhost/pingo/backend/conductor/get_ganancias.php?conductor_id=123&periodo=hoy"
```

---

## 📚 Referencias

- [Documentación Clean Architecture](../docs/architecture/CLEAN_ARCHITECTURE.md)
- [Guía de Migración a Microservicios](../docs/architecture/MIGRATION_TO_MICROSERVICES.md)
- [Endpoints de Usuario](../auth/README_USER_MICROSERVICE.md)

---

**Última actualización**: Enero 2024  
**Versión**: 1.0.0  
**Mantenido por**: Equipo Backend Ping Go
