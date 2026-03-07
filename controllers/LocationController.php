<?php
/**
 * Controlador de ubicación.
 */

class LocationController
{
    public function health(): void
    {
        Response::success(['module' => 'location'], 'OK');
    }
}
