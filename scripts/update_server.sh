#!/bin/bash
set -euo pipefail

# Script de actualización remota (SSH/SCP) para backend Viax.
# Uso:
#   ./backend/scripts/update_server.sh root@76.13.114.194

HOST="${1:-root@76.13.114.194}"
ROOT_REMOTE="/var/www/viax"

FILES=(
  "backend/services/driver_service.php"
  "backend/services/matching_service.php"
  "backend/services/pickup_eta_service.php"
  "backend/services/places_search_service.php"
  "backend/workers/dispatch_worker.php"
  "backend/workers/zone_cache_worker.php"
  "backend/workers/surge_pricing_worker.php"
  "backend/workers/driver_reposition_worker.php"
  "backend/conductor/driver_auth.php"
  "backend/conductor/heartbeat.php"
  "backend/conductor/actualizar_ubicacion.php"
  "backend/conductor/actualizar_disponibilidad.php"
  "backend/conductor/accept_trip_request.php"
  "backend/conductor/reject_trip_request.php"
  "backend/conductor/update_trip_status.php"
  "backend/conductor/get_pending_requests.php"
  "backend/driver/tracking/update.php"
  "backend/user/create_trip_request.php"
  "backend/user/get_trip_status.php"
  "backend/user/trip_preview.php"
  "backend/user/get_recent_searches.php"
  "backend/user/save_recent_search.php"
  "backend/user/search_places.php"
  "backend/account/delete-request.php"
  "backend/account/reactivate.php"
  "backend/user/services/CompanyService.php"
  "backend/scripts/run_migrations.php"
  "backend/migrations/050_create_recent_searches.sql"
  "backend/migrations/051_performance_indexes.sql"
  "backend/docs/architecture.md"
)

SUPERVISOR_FILES=(
  "deploy/supervisor/dispatch_worker.conf"
  "deploy/supervisor/zone_cache_worker.conf"
  "deploy/supervisor/surge_pricing_worker.conf"
  "deploy/supervisor/driver_reposition_worker.conf"
)

echo "[1/4] Subiendo archivos..."
for file in "${FILES[@]}"; do
  scp "$file" "$HOST:$ROOT_REMOTE/$file"
done

echo "[1.1/4] Subiendo configuraciones de Supervisor..."
for file in "${SUPERVISOR_FILES[@]}"; do
  name="$(basename "$file")"
  scp "$file" "$HOST:/etc/supervisor/conf.d/$name"
done

echo "[2/4] Ejecutando migraciones..."
ssh "$HOST" "rm -rf $ROOT_REMOTE/.git $ROOT_REMOTE/backend/.git || true"
ssh "$HOST" "php $ROOT_REMOTE/backend/scripts/run_migrations.php"

echo "[3/4] Reiniciando workers con Supervisor (fallback nohup)..."
ssh "$HOST" "
  if command -v supervisorctl >/dev/null 2>&1; then
    supervisorctl reread || true
    supervisorctl update || true
    supervisorctl restart viax_dispatch_worker || true
    supervisorctl restart viax_zone_cache_worker || true
    supervisorctl restart viax_surge_pricing_worker || true
    supervisorctl restart viax_driver_reposition_worker || true
  else
    pkill -f '$ROOT_REMOTE/backend/workers/dispatch_worker.php' || true
    nohup php $ROOT_REMOTE/backend/workers/dispatch_worker.php >/dev/null 2>&1 &
    pkill -f '$ROOT_REMOTE/backend/workers/zone_cache_worker.php' || true
    nohup php $ROOT_REMOTE/backend/workers/zone_cache_worker.php >/dev/null 2>&1 &
    pkill -f '$ROOT_REMOTE/backend/workers/surge_pricing_worker.php' || true
    nohup php $ROOT_REMOTE/backend/workers/surge_pricing_worker.php >/dev/null 2>&1 &
    pkill -f '$ROOT_REMOTE/backend/workers/driver_reposition_worker.php' || true
    nohup php $ROOT_REMOTE/backend/workers/driver_reposition_worker.php >/dev/null 2>&1 &
  fi
"

echo "[4/4] Verificando procesos..."
ssh "$HOST" "pgrep -af 'dispatch_worker.php|zone_cache_worker.php|surge_pricing_worker.php|driver_reposition_worker.php' || true"

echo "Actualización completada en $HOST"
