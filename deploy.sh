#!/bin/bash
# VPS deployment script for backend only

echo "🚀 Starting VPS backend deployment..."

# Install dependencies
composer install --no-dev --optimize-autoloader

# Create necessary directories
mkdir -p logs uploads

# Set permissions
chmod 755 logs uploads

# Run database migrations
echo "📊 Running database migrations..."
php migrations/run_migrations.php
echo "✅ Database migrations completed!"

echo "✅ Backend deployment setup complete!"
echo "🌐 Backend expected at: http://76.13.114.194"