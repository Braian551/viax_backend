<?php
/**
 * Repositorio de usuarios.
 */

class UserRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function getById(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, nombre, apellido, telefono, email FROM usuarios WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
