@echo off
echo ================================================
echo   INSTALANDO SISTEMA DE PRECIOS - PING GO
echo ================================================
echo.

echo Ejecutando migracion SQL...
mysql -u root -p pingo < "c:\Flutter\ping_go\pingo\backend\migrations\007_create_configuracion_precios.sql"

if %ERRORLEVEL% EQU 0 (
    echo.
    echo ================================================
    echo   MIGRACION COMPLETADA CON EXITO
    echo ================================================
    echo.
    echo Verificando instalacion...
    echo.
    php run_migration_007.php
) else (
    echo.
    echo ================================================
    echo   ERROR EN LA MIGRACION
    echo ================================================
    echo.
    echo Por favor verifica:
    echo 1. MySQL esta corriendo
    echo 2. La contrasena de root es correcta
    echo 3. La base de datos 'pingo' existe
)

pause
