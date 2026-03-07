<?php
/**
 * Registro de rutas para migración progresiva.
 *
 * Nota: el router principal actual sigue en index.php para mantener
 * compatibilidad total. Este archivo prepara la transición modular.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Router.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../controllers/TripController.php';

$router = new Router();
$tripController = new TripController();

$router->get('/health/trip', [$tripController, 'health']);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = Request::method();

if (!$router->dispatch($path, $method)) {
    Response::error('Ruta no encontrada', 404, 'NOT_FOUND');
}
