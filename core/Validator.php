<?php
/**
 * Validador básico reutilizable.
 */

class Validator
{
    public static function positiveInt(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : (int) $id;
    }

    public static function intInRange(mixed $value, int $min, int $max, int $default): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => [
                'default' => $default,
                'min_range' => $min,
                'max_range' => $max,
            ],
        ]);

        return $parsed === false ? $default : (int) $parsed;
    }

    public static function cleanHex(mixed $value, int $maxLength = 64): string
    {
        if (!is_string($value)) {
            return '';
        }
        return substr((string) preg_replace('/[^a-f0-9]/i', '', $value), 0, $maxLength);
    }
}
