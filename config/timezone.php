<?php
/**
 * Configuración de zona horaria para el backend
 * 
 * Este archivo centraliza el manejo de timestamps para garantizar
 * consistencia entre el servidor (UTC) y los clientes (hora local).
 * 
 * IMPORTANTE: El servidor SIEMPRE trabaja en UTC internamente.
 * Los timestamps se envían al cliente en formato ISO8601 con 'Z' (UTC)
 * para que el cliente pueda convertirlos a su hora local.
 */

// Establecer zona horaria del servidor a UTC
date_default_timezone_set('UTC');

/**
 * Clase de utilidades para manejo de timestamps
 */
class TimezoneUtils {
    
    /**
     * Formato ISO8601 con indicador UTC
     * Este formato es reconocido universalmente y facilita la conversión en clientes
     */
    const FORMAT_ISO8601_UTC = 'Y-m-d\TH:i:s\Z';
    
    /**
     * Formato para PostgreSQL
     */
    const FORMAT_POSTGRES = 'Y-m-d H:i:s';
    
    /**
     * Formato solo fecha
     */
    const FORMAT_DATE_ONLY = 'Y-m-d';
    
    /**
     * Obtiene el timestamp actual en formato UTC ISO8601
     * 
     * @return string Timestamp en formato '2026-02-03T18:30:00Z'
     */
    public static function nowUtc(): string {
        return gmdate(self::FORMAT_ISO8601_UTC);
    }
    
    /**
     * Obtiene el timestamp actual para PostgreSQL (UTC)
     * 
     * @return string Timestamp en formato '2026-02-03 18:30:00'
     */
    public static function nowForDb(): string {
        return gmdate(self::FORMAT_POSTGRES);
    }
    
    /**
     * Obtiene la fecha actual (sin hora) en UTC
     * 
     * @return string Fecha en formato '2026-02-03'
     */
    public static function todayUtc(): string {
        return gmdate(self::FORMAT_DATE_ONLY);
    }
    
    /**
     * Convierte un timestamp de la base de datos a formato ISO8601 UTC
     * 
     * @param string|null $dbTimestamp Timestamp de PostgreSQL (sin timezone)
     * @return string|null Timestamp en formato ISO8601 con Z, o null si no es válido
     */
    public static function dbToIso8601(?string $dbTimestamp): ?string {
        if (empty($dbTimestamp)) {
            return null;
        }
        
        try {
            // Crear DateTime asumiendo que el timestamp de DB está en UTC
            $dt = new DateTime($dbTimestamp, new DateTimeZone('UTC'));
            return $dt->format(self::FORMAT_ISO8601_UTC);
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Convierte un timestamp ISO8601 del cliente a formato para DB
     * 
     * @param string|null $isoTimestamp Timestamp ISO8601 del cliente
     * @return string|null Timestamp para PostgreSQL, o null si no es válido
     */
    public static function isoToDb(?string $isoTimestamp): ?string {
        if (empty($isoTimestamp)) {
            return null;
        }
        
        try {
            $dt = new DateTime($isoTimestamp);
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format(self::FORMAT_POSTGRES);
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Formatea un array de registros, convirtiendo campos de fecha a ISO8601
     * 
     * @param array $records Array de registros de la base de datos
     * @param array $dateFields Lista de campos que contienen timestamps
     * @return array Registros con timestamps convertidos
     */
    public static function formatDateFields(array $records, array $dateFields): array {
        if (empty($records)) {
            return $records;
        }
        
        // Si es un solo registro (no array de arrays)
        if (isset($records[array_key_first($records)]) && !is_array($records[array_key_first($records)])) {
            return self::formatSingleRecord($records, $dateFields);
        }
        
        // Array de registros
        return array_map(function($record) use ($dateFields) {
            return self::formatSingleRecord($record, $dateFields);
        }, $records);
    }
    
    /**
     * Formatea un solo registro, convirtiendo campos de fecha a ISO8601
     * 
     * @param array $record Registro de la base de datos
     * @param array $dateFields Lista de campos que contienen timestamps
     * @return array Registro con timestamps convertidos
     */
    public static function formatSingleRecord(array $record, array $dateFields): array {
        foreach ($dateFields as $field) {
            if (isset($record[$field]) && $record[$field] !== null) {
                $record[$field] = self::dbToIso8601($record[$field]);
            }
        }
        return $record;
    }
    
    /**
     * Obtiene la diferencia de horas para una zona horaria específica
     * 
     * @param string $timezone Nombre de la zona horaria (ej: 'America/Bogota')
     * @return int Offset en horas respecto a UTC
     */
    public static function getTimezoneOffset(string $timezone): int {
        try {
            $tz = new DateTimeZone($timezone);
            $now = new DateTime('now', $tz);
            return $tz->getOffset($now) / 3600;
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Lista de campos de fecha comunes en la aplicación
     * Útil para llamadas genéricas a formatDateFields
     */
    const COMMON_DATE_FIELDS = [
        'fecha_creacion',
        'fecha_registro',
        'fecha_solicitud',
        'fecha_aceptado',
        'fecha_completado',
        'solicitado_en',
        'aceptado_en',
        'completado_en',
        'cancelado_en',
        'inicio_viaje_real',
        'fin_viaje_real',
        'fecha_ultima_verificacion',
        'ultima_actualizacion',
        'creado_en',
        'actualizado_en',
        'timestamp',
    ];
}

/**
 * Función helper para obtener timestamp UTC actual
 * 
 * @return string Timestamp en formato ISO8601 UTC
 */
function utc_now(): string {
    return TimezoneUtils::nowUtc();
}

/**
 * Función helper para obtener timestamp para DB
 * 
 * @return string Timestamp para PostgreSQL
 */
function db_now(): string {
    return TimezoneUtils::nowForDb();
}

/**
 * Función helper para convertir timestamp de DB a ISO8601
 * 
 * @param string|null $dbTimestamp
 * @return string|null
 */
function to_iso8601(?string $dbTimestamp): ?string {
    return TimezoneUtils::dbToIso8601($dbTimestamp);
}
