<?php
/**
 * Controlador de viajes.
 */

class TripController
{
    public function health(): void
    {
        Response::success(['module' => 'trip'], 'OK');
    }
}
