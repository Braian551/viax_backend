<?php
/**
 * Bootstrap liviano de la aplicación.
 *
 * Centraliza includes de configuración para evitar includes repetidos
 * y facilitar migración a arquitectura modular.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/timezone.php';
require_once __DIR__ . '/redis.php';
require_once __DIR__ . '/../core/Cache.php';
