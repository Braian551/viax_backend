#!/bin/bash
# Script para desplegar los cambios de timezone en el servidor
# Ejecutar con: ssh root@76.13.114.194 "cd /var/www/viax_backend && bash -s" < deploy_timezone_fix.sh

echo "=== Actualizando backend con fix de timezone ==="

# 1. Hacer backup del config actual
echo "1. Haciendo backup de configuración..."
cp config/database.php config/database.php.bak 2>/dev/null || true

# 2. Verificar que existe timezone.php
if [ -f config/timezone.php ]; then
    echo "✓ timezone.php ya existe"
else
    echo "✗ timezone.php no encontrado - se creará"
fi

# 3. Actualizar desde git
echo "2. Actualizando desde git..."
git fetch origin
git reset --hard origin/main

# 4. Verificar que los archivos se actualizaron
echo "3. Verificando archivos..."
if [ -f config/timezone.php ]; then
    echo "✓ config/timezone.php - OK"
else
    echo "✗ config/timezone.php - FALTANTE"
fi

# 5. Verificar que database.php incluye timezone.php
if grep -q "timezone.php" config/database.php; then
    echo "✓ database.php incluye timezone.php"
else
    echo "⚠ database.php NO incluye timezone.php"
fi

# 6. Verificar sintaxis PHP
echo "4. Verificando sintaxis PHP..."
php -l config/timezone.php
php -l config/database.php

echo ""
echo "=== Despliegue completado ==="
echo "Verifica que la API funciona correctamente."
