<?php

require_once __DIR__ . '/../config/bootstrap.php';

const SENSITIVE_ENC_PREFIX = 'encv1:';

/**
 * Obtiene la clave de cifrado de datos sensibles desde entorno.
 */
function getSensitiveDataKey(): string
{
    $key = trim((string) env_value('SENSITIVE_DATA_KEY', ''));
    if ($key === '') {
        throw new RuntimeException('SENSITIVE_DATA_KEY no está configurada en el entorno');
    }

    return $key;
}

/**
 * Detecta si un valor parece estar cifrado con el esquema actual.
 */
function isSensitiveDataEncrypted(?string $value): bool
{
    if (!is_string($value) || $value === '') {
        return false;
    }

    return str_starts_with($value, SENSITIVE_ENC_PREFIX);
}

/**
 * Cifra un valor usando AES-256-CBC y devuelve un payload versionado.
 */
function encryptSensitiveData($value): ?string
{
    if ($value === null) {
        return null;
    }

    $plain = trim((string) $value);
    if ($plain === '') {
        return $plain;
    }

    if (isSensitiveDataEncrypted($plain)) {
        return $plain;
    }

    $key = hash('sha256', getSensitiveDataKey(), true);
    $iv = random_bytes(16);
    $cipherRaw = openssl_encrypt(
        $plain,
        'AES-256-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($cipherRaw === false) {
        throw new RuntimeException('No se pudo cifrar el dato sensible');
    }

    return SENSITIVE_ENC_PREFIX . base64_encode($iv . $cipherRaw);
}

/**
 * Descifra un valor cifrado. Si llega en plano, lo devuelve tal cual para compatibilidad legacy.
 */
function decryptSensitiveData($encryptedValue): ?string
{
    if ($encryptedValue === null) {
        return null;
    }

    $value = (string) $encryptedValue;
    if ($value === '') {
        return $value;
    }

    if (!isSensitiveDataEncrypted($value)) {
        return $value;
    }

    $payload = substr($value, strlen(SENSITIVE_ENC_PREFIX));
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) <= 16) {
        throw new RuntimeException('Payload cifrado inválido');
    }

    $iv = substr($raw, 0, 16);
    $cipherRaw = substr($raw, 16);
    $key = hash('sha256', getSensitiveDataKey(), true);

    $plain = openssl_decrypt(
        $cipherRaw,
        'AES-256-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($plain === false) {
        throw new RuntimeException('No se pudo descifrar el dato sensible');
    }

    return $plain;
}

/**
 * Enmascara una cuenta dejando visibles solo los últimos 4 dígitos.
 */
function maskSensitiveAccount(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $raw = trim((string) $value);
    if ($raw === '') {
        return $raw;
    }

    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') {
        return '******';
    }

    $suffix = strlen($digits) > 4 ? substr($digits, -4) : $digits;
    return '******' . $suffix;
}
