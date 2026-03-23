#!/bin/bash
set -euo pipefail

# VPS deployment script for backend only

echo "🚀 Starting VPS backend deployment..."

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BACKEND_DIR="$SCRIPT_DIR"

if [ ! -d "$BACKEND_DIR" ]; then
	echo "❌ Backend directory not found: $BACKEND_DIR"
	exit 1
fi

cd "$BACKEND_DIR"

for required_cmd in php redis-cli; do
	if ! command -v "$required_cmd" >/dev/null 2>&1; then
		echo "❌ Missing required command: $required_cmd"
		exit 1
	fi
done

if [ ! -d "scripts" ]; then
	echo "❌ Invalid backend directory: scripts/ not found"
	exit 1
fi

mkdir -p logs uploads
chmod 755 logs uploads

migrations_executed=0
workers_restarted="no"

echo "📥 Using uploaded files only (no Git on production)."

# STEP 2/3: Check pending migrations and run only when required.
echo "📊 Checking pending migrations..."
migration_check_output="$(php scripts/check_pending_migrations.php || true)"
echo "$migration_check_output"

if echo "$migration_check_output" | grep -q "NO_PENDING_MIGRATIONS"; then
	echo "✅ No pending migrations. Skipping migration runner."
elif echo "$migration_check_output" | grep -q "PENDING_MIGRATIONS_FOUND"; then
	echo "📊 Pending migrations detected. Running migration runner..."
	migration_run_output="$(php scripts/run_migrations.php)"
	echo "$migration_run_output"
	migrations_executed="$(echo "$migration_run_output" | sed -n 's/^Migraciones ejecutadas en esta corrida: \([0-9][0-9]*\)$/\1/p' | tail -n 1)"
	if [ -z "$migrations_executed" ]; then
		echo "❌ Could not parse migration execution count. Aborting deployment."
		exit 1
	fi
	echo "✅ Database migrations completed!"
else
	echo "❌ Unexpected output from check_pending_migrations.php"
	exit 1
fi

# STEP 4: Deployment reload logic after migrations.
echo "🔄 Running backend reload logic..."
composer install --no-dev --optimize-autoloader --no-interaction
composer dump-autoload -o

echo "🧪 Validating PHP syntax..."
php -l conductor/get_demand_zones.php
php -l conductor/get_solicitudes_pendientes.php
php -l conductor/actualizar_disponibilidad.php
php -l user/create_trip_request.php
php -l user/get_trip_status.php
php -l user/trip_preview.php
php -l workers/surge_pricing_worker.php
php -l workers/driver_reposition_worker.php
php -l scripts/check_pending_migrations.php
php -l scripts/run_migrations.php
if [ -f scripts/check_legacy_code.php ]; then
	php -l scripts/check_legacy_code.php
else
	echo "ℹ Optional script scripts/check_legacy_code.php not found. Skipping lint."
fi
echo "✅ PHP syntax validation completed!"

# STEP 4.5: Legacy marker scan (warning only).
echo "🧹 Scanning for legacy markers..."
if [ -f scripts/check_legacy_code.php ]; then
	if legacy_output="$(php scripts/check_legacy_code.php 2>&1)"; then
		echo "$legacy_output"
	else
		echo "⚠ Legacy scanner execution failed (non-blocking):"
		echo "$legacy_output"
	fi
else
	echo "ℹ Optional legacy scanner not found. Skipping."
fi

# STEP 5: Redis check before worker restarts.
echo "🧪 Verifying Redis..."
if ! redis-cli ping | grep -q '^PONG$'; then
	echo "❌ Redis health check failed"
	exit 1
fi
echo "✅ Redis verification completed!"

should_restart_workers=false

# STEP 6: Worker restart detection priority.
if [ -n "${DEPLOY_CHANGED_FILES:-}" ]; then
	changed_files="$DEPLOY_CHANGED_FILES"
else
	changed_files=""
fi

if [ -n "$changed_files" ]; then
	echo "🧾 Changed files detected for this deployment:"
	echo "$changed_files"
	if echo "$changed_files" | grep -Eq '^((backend/)?(workers|dispatch|pricing|queue)/)'; then
		should_restart_workers=true
	fi
else
	echo "🧾 No changed-files context detected. Worker restart will be skipped."
fi

echo "🔧 Worker management check (Supervisor recomendado)..."
if [ "$should_restart_workers" = true ] && command -v supervisorctl >/dev/null 2>&1; then
	echo "🔁 Worker-related changes detected. Restarting workers..."
	supervisorctl reread || true
	supervisorctl update || true
	supervisorctl restart viax_dispatch_worker || true
	supervisorctl restart viax_zone_cache_worker || true
	supervisorctl restart viax_surge_pricing_worker || true
	supervisorctl restart viax_driver_reposition_worker || true
    workers_restarted="yes"
elif [ "$should_restart_workers" = true ]; then
	echo "⚠ supervisorctl no disponible; mantener workers con nohup o systemd"
else
	echo "✅ No worker-related changes detected. Skipping worker restart."
fi

# STEP 7: Endpoint smoke tests.
echo "🧪 Running endpoint smoke tests..."
curl -sS -X POST http://127.0.0.1/user/create_trip_request.php -H "Content-Type: application/json" -d "{}" >/dev/null
curl -sS -X POST http://127.0.0.1/conductor/update_trip_status.php -H "Content-Type: application/json" -d "{}" >/dev/null
curl -sS -X POST http://127.0.0.1/user/trip_preview.php -H "Content-Type: application/json" -d "{}" >/dev/null
echo "✅ Endpoint smoke tests completed!"

# STEP 8: Health check.
echo "🩺 Running health check..."
health_http_code="$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1/health.php)"
if [ "$health_http_code" != "200" ]; then
	echo "❌ Health check failed with HTTP $health_http_code"
	exit 1
fi
echo "✅ Health check passed!"

timestamp="$(date '+%Y-%m-%d %H:%M')"
echo "$timestamp commit=upload_only migrations=$migrations_executed workers_restarted=$workers_restarted" >> logs/deploy.log

echo "✅ Backend deployment setup complete!"
echo "🌐 Backend expected at: http://76.13.114.194"