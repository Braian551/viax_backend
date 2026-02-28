#!/bin/bash
set -euo pipefail

# VPS deployment script for backend only

echo "🚀 Starting VPS backend deployment..."

# Install dependencies
composer install --no-dev --optimize-autoloader --no-interaction
composer dump-autoload -o

# Create necessary directories
mkdir -p logs uploads

# Set permissions
chmod 755 logs uploads

# Validate key PHP endpoints before migrations
echo "🧪 Validating PHP syntax..."
php -l conductor/get_demand_zones.php
php -l conductor/get_solicitudes_pendientes.php
php -l conductor/actualizar_disponibilidad.php
echo "✅ PHP syntax validation completed!"

# Run database migrations
echo "📊 Running database migrations..."
php migrations/run_migrations.php
echo "✅ Database migrations completed!"

echo "✅ Backend deployment setup complete!"
echo "🌐 Backend expected at: http://76.13.114.194"