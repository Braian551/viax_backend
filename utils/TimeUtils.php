<?php
/**
 * Utilidades de tiempo para backend de movilidad.
 */

if (!function_exists('utc_iso_now')) {
    function utc_iso_now(): string
    {
        return gmdate('c');
    }
}

if (!function_exists('minutes_between')) {
    function minutes_between(int $fromUnix, int $toUnix): int
    {
        if ($toUnix <= $fromUnix) {
            return 0;
        }
        return (int) floor(($toUnix - $fromUnix) / 60);
    }
}
