<?php
/**
 * Router mínimo para migración progresiva.
 *
 * Permite mapear rutas sin romper el router existente en index.php.
 */

class Router
{
    private array $getRoutes = [];

    public function get(string $path, callable $handler): void
    {
        $this->getRoutes[$path] = $handler;
    }

    public function dispatch(string $path, string $method): bool
    {
        if ($method === 'GET' && isset($this->getRoutes[$path])) {
            ($this->getRoutes[$path])();
            return true;
        }
        return false;
    }
}
