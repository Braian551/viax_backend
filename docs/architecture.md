# Arquitectura Backend Viax (Despacho y Búsqueda)

## 1) Sistema de despacho

El flujo de despacho sigue una arquitectura asíncrona orientada a Redis:

1. `user/create_trip_request.php` crea la solicitud y la encola en `dispatch:trip_queue`.
2. `workers/dispatch_worker.php` consume cola, selecciona candidatos y envía ofertas por lotes.
3. Conductores responden por canales/colas de respuesta (`trip:responses:*`).
4. Se toma lock de viaje y se confirma asignación.

### Características activas

- Score ponderado de conductor (ETA, rating, aceptación, idle).
- Cooldown de rechazo/ignorado por conductor.
- Dedupe de ofertas por viaje.
- Límite máximo de ofertas por viaje.
- Métricas Redis de latencia, cache-hit/miss y aceptación.
- Pipeline Redis para lecturas masivas de celdas y score cacheado por conductor.

### Índice espacial de conductores (500m)

Para evitar escaneo global de conductores:

- Celda aproximada: 0.005 grados (
	~500m).
- Formato de celda: `grid:{lat_cell}:{lng_cell}`.
- Índice principal: `drivers:grid:{grid_id}` (SET, sin TTL).
- Disponibilidad global: `drivers:available` (SET).
- Disponibilidad para reposicionamiento: `drivers:idle` (SET).

Actualización por ubicación de conductor:

1. Calcular nueva celda.
2. Remover del grid anterior.
3. Agregar al nuevo grid.
4. Actualizar `drivers:geo` para fallback radial.

Ejemplo:

- `SADD drivers:grid:625:-7554 842`
- `SREM drivers:grid:625:-7553 842`

### Fallback GEO en Redis

Si el grid no devuelve candidatos suficientes, se usa índice GEO:

- Clave: `drivers:geo`
- Escritura: `GEOADD drivers:geo lng lat driver_id`
- Lectura fallback: `GEOSEARCH ... BYRADIUS ... WITHDIST`

## 2) Zone Cache Worker

`workers/zone_cache_worker.php` recalcula cada 2 segundos un top de conductores por grid activo:

- Clave: `dispatch:zone_drivers:c{city_id}:{grid_id}`
- Estructura: ZSET (`member=driver_id`, `score=driver_score`)
- TTL: 10 segundos

El `dispatch_worker` primero consulta esta cache (fast path). Si está vacía, aplica fallback al descubrimiento por grid/GEO.

## 3) Heartbeat y sesión de conductor

### Sesión

- Clave: `driver:session:{driver_id}`
- TTL: 12 horas
- Se valida en endpoints críticos de conductor para evitar sesiones fantasma.

### Heartbeat

- Clave: `driver:heartbeat:{driver_id}`
- TTL: 20 segundos
- Endpoint: `conductor/heartbeat.php`
- La app debe enviar heartbeat cada 10 segundos.

Si el heartbeat no existe, la lógica de descubrimiento limpia al conductor de índices de disponibilidad/grid/cache para evitar asignaciones inválidas.

## 4) Redis sharding por ciudad

Para escalar multi-ciudad:

- Disponibles por ciudad: `drivers:available:{city_id}`
- Grid por ciudad: `drivers:grid:{city_id}:{grid_id}`
- Compatibilidad: se mantienen claves legacy globales para no romper clientes/consumidores actuales.

## 5) Búsqueda profesional (tipo Uber/DiDi)

### Historial reciente

Tabla `recent_searches` (máximo 10 por usuario):

- `user/get_recent_searches.php`
- `user/save_recent_search.php`

### Búsqueda inteligente

Endpoint `user/search_places.php`:

1. recientes
2. Google Places

Con deduplicación por `place_id` y límite de 10 resultados.

### Cache de Places

- Clave: `places:search:{hash}`
- TTL: 5 minutos

Reduce llamadas a Google y costo operativo.

## 6) Observabilidad y objetivos

### Métricas clave

- `metrics:dispatch_cache_hits`
- `metrics:dispatch_cache_misses`
- `metrics:dispatch_latency_ms`
- `metrics:drivers_scanned`
- `metrics:offers_sent`
- `metrics:driver_reposition_loop_count`
- `metrics:surge_loop_count`
- `metrics:matching_latency`

### Targets

- Dispatch assignment objetivo < 50 ms (p50 en ruta caliente)
- Driver search < 30 ms
- Zone cache refresh = 2 s

## 7) Ranking inteligente de dispatch

El ranking no usa solo distancia lineal. Se combina:

- Distancia normalizada.
- Rating histórico del conductor.
- Tasa de aceptación.
- Penalización por cancelación/rechazo.
- Señal de idle y ETA.

El score estático por conductor se cachea en:

- `driver:score:{driver_id}` (TTL corto, recalculable).

Esto reduce CPU/latencia en cada ciclo de selección.

## 8) Operación con Supervisor

Configs sugeridas en `deploy/supervisor/`:

- `dispatch_worker.conf`
- `zone_cache_worker.conf`

Comandos típicos:

```bash
sudo apt-get update && sudo apt-get install -y supervisor
sudo cp /var/www/viax/deploy/supervisor/*.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

Si Supervisor no está instalado, se mantiene fallback a `nohup` para continuidad operativa.

## 9) Surge pricing por hotspot (nuevo)

Se añadió `workers/surge_pricing_worker.php` para calcular multiplicador dinámico por grid cada 2 segundos:

- Fuente de demanda: buckets rolling de 10 minutos (`dispatch:hotspots:bucket:{YmdHi}`).
- Fuente de oferta: conductores disponibles y con heartbeat vigente por grid.
- Salida: `surge:grid:{grid_id}` (JSON, TTL 30s).
- Heartbeat worker: `dispatch:surge_worker:last_heartbeat`.
- Métricas: `metrics:surge_loop_count`, `metrics:surge_loop_ms`.

Regla de negocio:

- No se altera la tarifa base de empresa en BD.
- El multiplicador se aplica en tiempo real al precio de previsualización (respuesta de `get_companies_by_municipality.php` vía `CompanyService`).

## 10) Hotspots predictivos 10 minutos

En `user/create_trip_request.php` ahora se registra demanda por minuto y se consolida score rolling de 10 minutos:

- Bucket por minuto: `dispatch:hotspots:bucket:{YmdHi}` (HASH).
- Campo del bucket: `zone:{grid_id}`.
- Score agregado: `dispatch:hotspots:zset`.

Esto evita sesgos de picos antiguos y mejora la reacción de surge en zonas activas recientes.

## 11) Reposicionamiento de conductores (nuevo)

Se añadió `workers/driver_reposition_worker.php` con ciclo de 60s:

1. Lee top hotspots de `dispatch:hotspots:zset`.
2. Evalúa conductores en `drivers:idle`.
3. Selecciona hotspot cercano por conductor.
4. Emite sugerencia tipo `driver_reposition`.

Canales/colas de salida:

- `notifications:queue`
- `notifications:drivers:{driver_id}`

## 12) ETA predictivo (nuevo)

La estimación de pickup combina:

- Snap a vía (Google Roads API).
- Tráfico en tiempo real (Google Routes/Traffic).
- Ajuste por velocidad y estado de conductor.

Cache de ruta:

- `eta:route:{hash}` (TTL 60s)

Campos API aditivos:

- `pickup_eta_minutes`
- `driver_distance`
- `surge_multiplier`

## 13) Integración Flutter (búsqueda y preview)

Flutter quedó integrado sin romper layout existente:

- Búsqueda: `MapRemoteDataSource` consume `user/search_places.php`.
- Recientes: carga/guardado con `user/get_recent_searches.php` y `user/save_recent_search.php`.
- Preview: se muestra `pickup_eta` estimado por opción de vehículo, `driverDistance` y multiplicador de surge.

## 14) Supervisor recomendado (actualizado)

Además de los workers existentes, incluir:

- `surge_pricing_worker.conf`
- `driver_reposition_worker.conf`

Flujo recomendado:

```bash
sudo cp /var/www/viax/deploy/supervisor/*.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart viax_dispatch_worker
sudo supervisorctl restart viax_zone_cache_worker
sudo supervisorctl restart viax_surge_pricing_worker
sudo supervisorctl restart viax_driver_reposition_worker
```
