<?php
/**
 * Servicio legacy de conductor.
 *
 * @deprecated use driver_service.php (DriverGeoService) con USE_NEW_SERVICES=true
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
