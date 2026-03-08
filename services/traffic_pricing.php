<?php
/**
 * Utilidades de pricing dependientes de trafico y calendario Colombia.
 *
 * Incluye:
 * - Calculo de recargo por ratio de trafico.
 * - Deteccion de festivo en Colombia via API con fallback local.
 * - Deteccion de horario nocturno en zona horaria America/Bogota.
 */

require_once __DIR__ . '/../config/app.php';

function trafficNowInColombia(): DateTime
{
    return new DateTime('now', new DateTimeZone('America/Bogota'));
}

/**
 * Convierte un texto HH:MM o HH:MM:SS a segundos del dia.
 */
function trafficTimeToSeconds(string $time): int
{
    $parts = explode(':', trim($time));
    if (count($parts) < 2) {
        return 0;
    }

    $h = intval($parts[0]);
    $m = intval($parts[1]);
    $s = count($parts) >= 3 ? intval($parts[2]) : 0;

    $h = max(0, min(23, $h));
    $m = max(0, min(59, $m));
    $s = max(0, min(59, $s));

    return ($h * 3600) + ($m * 60) + $s;
}

/**
 * Evalua horario nocturno soportando ventana que cruza medianoche.
 */
function trafficIsNocturnoColombia(DateTimeInterface $dt, string $inicio, string $fin): bool
{
    $nowSec = trafficTimeToSeconds($dt->format('H:i:s'));
    $iniSec = trafficTimeToSeconds($inicio);
    $finSec = trafficTimeToSeconds($fin);

    if ($iniSec <= $finSec) {
        return $nowSec >= $iniSec && $nowSec <= $finSec;
    }

    // Rango cruzando medianoche, ejemplo 22:00 -> 06:00.
    return $nowSec >= $iniSec || $nowSec <= $finSec;
}

/**
 * Reglas de recargo por trafico basadas en ratio:
 * ratio = duracion_con_trafico / duracion_sin_trafico.
 */
function calculateTrafficSurcharge(float $ratio): float
{
    if ($ratio < 1.2) {
        return 0.0;
    }
    if ($ratio <= 1.5) {
        return 10.0;
    }
    return 25.0;
}

function colombiaHolidayCacheKey(string $dateYmd): string
{
    return 'traffic:holiday:co:' . $dateYmd;
}

/**
 * Fallback local basico para no bloquear pricing si la API falla.
 */
function trafficLocalHolidayFallback(DateTimeInterface $dt): bool
{
    $month = intval($dt->format('m'));
    $day = intval($dt->format('d'));

    $fixed = [
        '1-1',
        '5-1',
        '7-20',
        '8-7',
        '12-8',
        '12-25',
    ];

    if (in_array($month . '-' . $day, $fixed, true)) {
        return true;
    }

    // Domingo como recargo dominical/festivo de negocio.
    return intval($dt->format('w')) === 0;
}

/**
 * Consulta API de festivos Colombia, cachea por fecha y retorna bool.
 *
 * API por defecto: https://api-colombia.com/api/v1/Holiday?year=YYYY
 */
function trafficIsHolidayColombia(DateTimeInterface $dt, ?array &$meta = null): bool
{
    $dateYmd = $dt->format('Y-m-d');
    $year = intval($dt->format('Y'));
    $meta = ['source' => 'unknown'];

    $cachedRaw = Cache::get(colombiaHolidayCacheKey($dateYmd));
    if (is_string($cachedRaw) && trim($cachedRaw) !== '') {
        $cached = json_decode($cachedRaw, true);
        if (is_array($cached) && isset($cached['is_holiday'])) {
            $meta['source'] = 'cache';
            return (bool) $cached['is_holiday'];
        }
    }

    $isHoliday = null;

    try {
        $apiBase = rtrim((string) env_value('COLOMBIA_HOLIDAYS_API_URL', 'https://api-colombia.com/api/v1/Holiday'), '/');
        $url = strpos($apiBase, '{year}') !== false
            ? str_replace('{year}', strval($year), $apiBase)
            : $apiBase . '?year=' . $year;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $resp = curl_exec($ch);
        $status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp !== false && $status >= 200 && $status < 300) {
            $rows = json_decode($resp, true);
            if (is_array($rows)) {
                $isHoliday = false;
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $candidate = $row['date'] ?? $row['fecha'] ?? $row['day'] ?? null;
                    if (is_string($candidate) && substr($candidate, 0, 10) === $dateYmd) {
                        $isHoliday = true;
                        break;
                    }
                }
                $meta['source'] = 'colombia_api';
            }
        } else {
            error_log('[traffic_holiday] HTTP ' . $status . ' error=' . $err);
        }
    } catch (Throwable $e) {
        error_log('[traffic_holiday] exception: ' . $e->getMessage());
    }

    if ($isHoliday === null) {
        $isHoliday = trafficLocalHolidayFallback($dt);
        $meta['source'] = 'fallback_local';
    }

    Cache::set(
        colombiaHolidayCacheKey($dateYmd),
        (string) json_encode(['is_holiday' => $isHoliday, 'date' => $dateYmd]),
        86400
    );

    return $isHoliday;
}
