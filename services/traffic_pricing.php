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
    // v2 invalida caches creados con reglas antiguas.
    return 'traffic:holiday:co:v2:' . $dateYmd;
}

/**
 * Convierte cualquier fecha/hora entrante a zona horaria Colombia.
 *
 * Si no logra parsear, retorna "ahora" en Bogota o el fallback indicado.
 */
function trafficToColombiaDateTime($rawDate, ?DateTimeInterface $fallback = null): DateTime
{
    $tzCo = new DateTimeZone('America/Bogota');
    if ($rawDate instanceof DateTimeInterface) {
        $dt = new DateTime($rawDate->format('Y-m-d H:i:s'));
        $dt->setTimezone($tzCo);
        return $dt;
    }

    if (is_string($rawDate) && trim($rawDate) !== '') {
        try {
            $dt = new DateTime(trim($rawDate));
            $dt->setTimezone($tzCo);
            return $dt;
        } catch (Throwable $e) {
            // sigue a fallback
        }
    }

    if ($fallback !== null) {
        $dt = new DateTime($fallback->format('Y-m-d H:i:s'));
        $dt->setTimezone($tzCo);
        return $dt;
    }

    return new DateTime('now', $tzCo);
}

/**
 * Mueve una fecha al lunes siguiente (Ley Emiliani).
 */
function colombiaHolidayMoveToNextMonday(DateTimeInterface $date): DateTime
{
    $dt = new DateTime($date->format('Y-m-d'), new DateTimeZone('America/Bogota'));
    $weekday = intval($dt->format('N')); // 1=lunes ... 7=domingo
    if ($weekday === 1) {
        return $dt;
    }

    $daysToAdd = 8 - $weekday;
    $dt->modify('+' . $daysToAdd . ' day');
    return $dt;
}

/**
 * Retorna festivos oficiales de Colombia para un año (Ley 51 de 1983).
 *
 * Fuente de verdad local para contingencia cuando la API externa falla.
 */
function colombiaLegalHolidaysForYear(int $year): array
{
    $tz = new DateTimeZone('America/Bogota');
    $dates = [];

    $add = static function (DateTimeInterface $d) use (&$dates): void {
        $dates[$d->format('Y-m-d')] = true;
    };

    // Festivos fijos.
    $fixed = [
        "$year-01-01", // Año nuevo
        "$year-05-01", // Dia del trabajo
        "$year-07-20", // Independencia
        "$year-08-07", // Batalla de Boyaca
        "$year-12-08", // Inmaculada Concepcion
        "$year-12-25", // Navidad
    ];
    foreach ($fixed as $f) {
        $add(new DateTime($f, $tz));
    }

    // Emiliani (se trasladan al lunes siguiente).
    $emiliani = [
        "$year-01-06", // Reyes Magos
        "$year-03-19", // San Jose
        "$year-06-29", // San Pedro y San Pablo
        "$year-08-15", // Asuncion de la Virgen
        "$year-10-12", // Dia de la Raza
        "$year-11-01", // Todos los Santos
        "$year-11-11", // Independencia de Cartagena
    ];
    foreach ($emiliani as $e) {
        $add(colombiaHolidayMoveToNextMonday(new DateTime($e, $tz)));
    }

    // Festivos de pascua.
    $easter = new DateTime('@' . easter_date($year));
    $easter->setTimezone($tz);

    // Jueves y Viernes Santo.
    $holyThursday = (clone $easter)->modify('-3 day');
    $holyFriday = (clone $easter)->modify('-2 day');
    $add($holyThursday);
    $add($holyFriday);

    // Ascension, Corpus Christi y Sagrado Corazon (trasladados a lunes).
    $ascension = colombiaHolidayMoveToNextMonday((clone $easter)->modify('+40 day'));
    $corpus = colombiaHolidayMoveToNextMonday((clone $easter)->modify('+61 day'));
    $sagrado = colombiaHolidayMoveToNextMonday((clone $easter)->modify('+68 day'));
    $add($ascension);
    $add($corpus);
    $add($sagrado);

    return array_keys($dates);
}

/**
 * Fallback legal para no bloquear pricing si la API falla.
 *
 * Importante:
 * - Sabado y domingo NO son festivo por defecto en Colombia.
 * - Solo se marcan festivos oficiales de ley.
 */
function trafficLocalHolidayFallback(DateTimeInterface $dt): bool
{
    $dateYmd = $dt->format('Y-m-d');
    $holidays = colombiaLegalHolidaysForYear(intval($dt->format('Y')));
    return in_array($dateYmd, $holidays, true);
}

/**
 * Normaliza diferentes formatos de fecha del API de festivos.
 */
function trafficNormalizeHolidayDateFromApi(array $row): ?string
{
    $candidates = [
        $row['date'] ?? null,
        $row['fecha'] ?? null,
        $row['day'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }

        $value = trim($candidate);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        try {
            $dt = new DateTime($value, new DateTimeZone('America/Bogota'));
            return $dt->format('Y-m-d');
        } catch (Throwable $e) {
            continue;
        }
    }

    return null;
}

/**
 * Consulta API de festivos Colombia, cachea por fecha y retorna bool.
 *
 * API por defecto: https://api-colombia.com/api/v1/Holiday?year=YYYY
 */
function trafficIsHolidayColombia(DateTimeInterface $dt, ?array &$meta = null): bool
{
    $dateYmd = trafficToColombiaDateTime($dt)->format('Y-m-d');
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
                    $candidateDate = trafficNormalizeHolidayDateFromApi($row);
                    if ($candidateDate !== null && $candidateDate === $dateYmd) {
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
