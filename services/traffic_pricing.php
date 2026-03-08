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

function colombiaHolidayYearCacheKey(int $year): string
{
    return 'traffic:holiday:co:year:v1:' . $year;
}

/**
 * Politica de negocio: domingo puede cobrarse como recargo dominical/festivo.
 */
function trafficShouldApplySundayAsHoliday(): bool
{
    $raw = strtolower(trim((string) env_value('APPLY_SUNDAY_AS_HOLIDAY_SURCHARGE', '1')));
    return in_array($raw, ['1', 'true', 'yes', 'si'], true);
}

/**
 * Detecta si la fecha cae en domingo en Colombia.
 */
function trafficIsSundayColombia(DateTimeInterface $dt): bool
{
    $co = trafficToColombiaDateTime($dt);
    return intval($co->format('w')) === 0;
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
 * Construye lista de endpoints candidatos para festivos por año.
 */
function trafficHolidayApiCandidateUrls(int $year): array
{
    $candidates = [];

    $fromEnv = trim((string) env_value('COLOMBIA_HOLIDAYS_API_URL', ''));
    if ($fromEnv !== '') {
        if (strpos($fromEnv, '{year}') !== false) {
            $candidates[] = str_replace('{year}', strval($year), $fromEnv);
        } else {
            $candidates[] = rtrim($fromEnv, '/') . '?year=' . $year;
        }
    }

    // API Colombia (variantes por posibles diferencias de case/ruta).
    $candidates[] = 'https://api-colombia.com/api/v1/Holiday?year=' . $year;
    $candidates[] = 'https://api-colombia.com/api/v1/holiday?year=' . $year;

    // Fallback global robusto de festivos oficiales por pais.
    $candidates[] = 'https://date.nager.at/api/v3/PublicHolidays/' . $year . '/CO';

    return array_values(array_unique($candidates));
}

/**
 * Descarga calendario anual de festivos desde API externa.
 *
 * Retorna null si no se logra obtener un arreglo valido.
 */
function trafficFetchHolidayDatesFromApi(int $year, ?array &$meta = null): ?array
{
    $meta = ['source' => null, 'url' => null];
    $urls = trafficHolidayApiCandidateUrls($year);

    foreach ($urls as $url) {
        try {
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

            if ($resp === false || $status < 200 || $status >= 300) {
                error_log('[traffic_holiday] HTTP ' . $status . ' url=' . $url . ' error=' . $err);
                continue;
            }

            $decoded = json_decode($resp, true);
            if (!is_array($decoded)) {
                continue;
            }

            // Algunos APIs envuelven en { data: [...] }.
            $rows = $decoded;
            if (isset($decoded['data']) && is_array($decoded['data'])) {
                $rows = $decoded['data'];
            }

            $dates = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $candidateDate = trafficNormalizeHolidayDateFromApi($row);
                if ($candidateDate !== null && preg_match('/^' . $year . '-\d{2}-\d{2}$/', $candidateDate) === 1) {
                    $dates[$candidateDate] = true;
                }
            }

            if (!empty($dates)) {
                $meta['source'] = 'colombia_api';
                $meta['url'] = $url;
                return array_keys($dates);
            }
        } catch (Throwable $e) {
            error_log('[traffic_holiday] exception url=' . $url . ' msg=' . $e->getMessage());
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
    $coDate = trafficToColombiaDateTime($dt);
    $dateYmd = $coDate->format('Y-m-d');
    $year = intval($coDate->format('Y'));
    $meta = ['source' => 'unknown'];

    // Cache anual prioritaria para reducir llamadas y cubrir todo el calendario.
    $yearCacheRaw = Cache::get(colombiaHolidayYearCacheKey($year));
    if (is_string($yearCacheRaw) && trim($yearCacheRaw) !== '') {
        $yearCache = json_decode($yearCacheRaw, true);
        if (is_array($yearCache) && isset($yearCache['dates']) && is_array($yearCache['dates'])) {
            $meta['source'] = $yearCache['source'] ?? 'cache_year';
            return in_array($dateYmd, $yearCache['dates'], true);
        }
    }

    $cachedRaw = Cache::get(colombiaHolidayCacheKey($dateYmd));
    if (is_string($cachedRaw) && trim($cachedRaw) !== '') {
        $cached = json_decode($cachedRaw, true);
        if (is_array($cached) && isset($cached['is_holiday'])) {
            $meta['source'] = 'cache';
            return (bool) $cached['is_holiday'];
        }
    }

    $isHoliday = null;

    $apiMeta = [];
    $apiDates = trafficFetchHolidayDatesFromApi($year, $apiMeta);
    if (is_array($apiDates)) {
        $isHoliday = in_array($dateYmd, $apiDates, true);
        $meta['source'] = $apiMeta['source'] ?? 'colombia_api';
        if (!empty($apiMeta['url'])) {
            $meta['url'] = $apiMeta['url'];
        }

        Cache::set(
            colombiaHolidayYearCacheKey($year),
            (string) json_encode([
                'source' => $meta['source'],
                'url' => $meta['url'] ?? null,
                'year' => $year,
                'dates' => array_values(array_unique($apiDates)),
            ]),
            86400
        );
    }

    if ($isHoliday === null) {
        $fallbackDates = colombiaLegalHolidaysForYear($year);
        $isHoliday = in_array($dateYmd, $fallbackDates, true);
        $meta['source'] = 'fallback_local';

        Cache::set(
            colombiaHolidayYearCacheKey($year),
            (string) json_encode([
                'source' => 'fallback_local',
                'year' => $year,
                'dates' => $fallbackDates,
            ]),
            86400
        );
    }

    Cache::set(
        colombiaHolidayCacheKey($dateYmd),
        (string) json_encode(['is_holiday' => $isHoliday, 'date' => $dateYmd]),
        86400
    );

    return $isHoliday;
}
