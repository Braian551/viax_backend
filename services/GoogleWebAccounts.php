<?php

final class GoogleWebAccounts
{
    private const PROFILE = 'id, uuid, nombre, apellido, email, telefono, tipo_usuario, empresa_id, status, es_activo';

    public static function resolve(PDO $db, array $verified): array
    {
        $email = strtolower($verified['email']);
        $lookup = $db->prepare('SELECT ' . self::PROFILE . ' FROM usuarios WHERE google_id = ?');
        $lookup->execute([$verified['sub']]);
        $user = $lookup->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            // The initial bindings predate the strict Web verification. Check their original email too.
            if (strtolower($user['email']) !== $email) {
                throw new DomainException('Identidad de Google distinta a la vinculada.');
            }
            if ($user['status'] !== 'active' || (int)$user['es_activo'] !== 1) {
                throw new DomainException('Tu cuenta no está activa.');
            }
            if ($user['tipo_usuario'] === 'empresa') {
                $company = $db->prepare('SELECT estado FROM empresas_transporte WHERE id = ?');
                $company->execute([$user['empresa_id']]);
                if ($company->fetchColumn() !== 'activo') {
                    throw new DomainException('Tu empresa aún no ha sido aprobada.');
                }
            }
            return $user;
        }

        if (!str_ends_with($email, '@gmail.com') && empty($verified['hd'])) {
            throw new DomainException('Usa una cuenta Gmail o Google Workspace para registrarte con Google.');
        }
        $sameEmail = $db->prepare('SELECT 1 FROM usuarios WHERE lower(email) = ?');
        $sameEmail->execute([$email]);
        if ($sameEmail->fetchColumn()) {
            throw new DomainException('Ya existe una cuenta con ese correo. Inicia sesión con su método original.');
        }

        $insert = $db->prepare(
            "INSERT INTO usuarios (uuid, nombre, apellido, email, hash_contrasena, google_id, tipo_usuario, es_verificado, auth_provider)
             VALUES (gen_random_uuid()::text, :first_name, :last_name, :email, :password, :subject, 'cliente', 1, 'google')
             ON CONFLICT DO NOTHING RETURNING " . self::PROFILE
        );
        $insert->execute([
            'first_name' => self::name($verified['given_name'] ?? null, 'Usuario'),
            'last_name' => self::name($verified['family_name'] ?? null, ''),
            'email' => $email,
            'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'subject' => $verified['sub'],
        ]);
        $user = $insert->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new DomainException('Ya existe una cuenta con ese correo. Vuelve a iniciar sesión.');
        }
        return $user;
    }

    private static function name(mixed $value, string $fallback): string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 60) : $fallback;
    }
}
