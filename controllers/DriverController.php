<?php
/**
 * Controlador de conductores.
 */

class DriverController
{
    public function health(): void
    {
        Response::success(['module' => 'driver'], 'OK');
    }
}
