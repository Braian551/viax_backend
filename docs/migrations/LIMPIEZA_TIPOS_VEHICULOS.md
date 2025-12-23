# Limpieza de Tipos de Vehículos - Solo Moto

## Resumen de Cambios

Se ha simplificado el sistema para trabajar únicamente con **motos**, eliminando los siguientes tipos de vehículos:
- ❌ Carro
- ❌ Moto Carga  
- ❌ Carro Carga

## Cambios Realizados

### 1. Base de Datos
- ✅ Eliminadas todas las configuraciones de precios para tipos no utilizados
- ✅ Consolidadas configuraciones duplicadas de moto (dejando solo 1 configuración activa)
- ✅ Modificada la columna `tipo_vehiculo` para aceptar solo `ENUM('moto')`
- ✅ Actualizada la migración SQL 007 para crear solo configuración de moto

### 2. Frontend (Flutter)
- ✅ Eliminados tipos de vehículo no utilizados de `pricing_management_screen.dart`
- ✅ Reemplazados emojis por iconos de Material Design
- ✅ Interfaz optimizada para mostrar solo configuración de moto

### 3. Iconos Utilizados

Los emojis han sido reemplazados por iconos nativos de Material Design:

| Sección | Emoji Anterior | Icono Nuevo |
|---------|----------------|-------------|
| Tarifas Base | 💰 | `Icons.attach_money_rounded` |
| Costos por Distancia | 📏 | `Icons.straighten_rounded` |
| Recargos | ⚡ | `Icons.trending_up_rounded` |
| Descuentos | 🎁 | `Icons.local_offer_rounded` |
| Comisiones | 💳 | `Icons.credit_card_rounded` |
| Límites de Distancia | 🛣️ | `Icons.route_rounded` |
| Tiempo de Espera | ⏱️ | `Icons.timer_rounded` |
| Tipo Vehículo (Moto) | 🏍️ | `Icons.two_wheeler_rounded` |

## Scripts de Limpieza Creados

1. **`cleanup_vehicle_types.php`**
   - Elimina configuraciones de carro, moto_carga y carro_carga
   - Actualiza el ENUM de tipo_vehiculo
   - Ubicación: `pingo/backend/migrations/`

2. **`consolidate_moto_config.php`**
   - Consolida configuraciones duplicadas de moto
   - Mantiene solo la más reciente
   - Ubicación: `pingo/backend/migrations/`

3. **`verify_configs.php`**
   - Verifica las configuraciones actuales
   - Muestra resumen de precios activos
   - Ubicación: `pingo/backend/migrations/`

## Estado Actual de la Base de Datos

```
ID: 5
Tipo de Vehículo: moto
Estado: ACTIVO
Tarifa Base: $4000.00
Costo por Km: $2000.00
Tarifa Mínima: $6000.00
```

## Cómo Ejecutar la Limpieza (Si es necesario)

```bash
# 1. Eliminar tipos no utilizados
cd pingo/backend/migrations
php cleanup_vehicle_types.php

# 2. Consolidar duplicados de moto
php consolidate_moto_config.php

# 3. Verificar resultado
php verify_configs.php
```

## Migración para Nuevas Instalaciones

Para nuevas instalaciones, usar el archivo actualizado:
```
pingo/backend/migrations/007_create_configuracion_precios_moto_only.sql
```

Este archivo:
- Solo crea configuración para tipo 'moto'
- Define el ENUM con solo 'moto'
- Inserta 1 configuración por defecto

## Verificación

Para verificar que todo funciona correctamente:

1. **Backend**: Ejecutar `verify_configs.php`
2. **Frontend**: Abrir la pantalla de gestión de precios en la app
3. **Resultado esperado**: Solo debe aparecer una tarjeta para "Moto"

## Notas Importantes

⚠️ **Importante**: Si en el futuro se requiere agregar otros tipos de vehículos:
1. Modificar el ENUM en la tabla `configuracion_precios`
2. Actualizar los mapas en `pricing_management_screen.dart`
3. Insertar nuevas configuraciones de precios

## Archivos Modificados

### Backend
- `pingo/backend/migrations/cleanup_vehicle_types.php` (nuevo)
- `pingo/backend/migrations/consolidate_moto_config.php` (nuevo)
- `pingo/backend/migrations/verify_configs.php` (nuevo)
- `pingo/backend/migrations/007_create_configuracion_precios_moto_only.sql` (nuevo)

### Frontend
- `lib/src/features/admin/presentation/screens/pricing_management_screen.dart` (modificado)

## Fecha de Cambios

**Fecha**: 26 de Octubre de 2025  
**Versión**: 2.0 (Solo Moto)
