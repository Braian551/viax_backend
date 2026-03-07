<?php
/**
 * Controlador de usuarios.
 */

class UserController
{
    public function health(): void
    {
        Response::success(['module' => 'user'], 'OK');
    }
}
