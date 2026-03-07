<?php
/**
 * Servicio de conductor.
 */

class DriverService
{
    public function __construct(private DriverRepository $driverRepository)
    {
    }

    public function getActiveDriverIds(): array
    {
        return $this->driverRepository->getActiveDriverIds();
    }
}
