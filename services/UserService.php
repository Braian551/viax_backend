<?php
/**
 * Servicio de usuario.
 */

class UserService
{
    public function __construct(private UserRepository $userRepository)
    {
    }

    public function getProfile(int $userId): ?array
    {
        return $this->userRepository->getById($userId);
    }
}
