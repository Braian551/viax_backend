<?php
/**
 * Controlador de pagos.
 */

class PaymentController
{
    public function health(): void
    {
        Response::success(['module' => 'payment'], 'OK');
    }
}
