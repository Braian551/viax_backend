<?php
/**
 * Guard canónico de pricing para asegurar que el cobro final nunca sea menor al estimado.
 *
 * Regla canónica:
 * - tracking inválido => precio_final = precio_estimado
 * - tracking válido => precio_final = max(precio_estimado, precio_dinamico)
 *
 * Incluye telemetría estructurada para auditoría y alertas operativas.
 */

declare(strict_types=1);

if (!function_exists('canonicalPricingAsFloat')) {
    function canonicalPricingAsFloat($value, float $default = 0.0): float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }
        return (float)$value;
    }
}

if (!function_exists('canonicalPricingAsInt')) {
    function canonicalPricingAsInt($value, int $default = 0): int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }
        return (int)$value;
    }
}

if (!function_exists('canonicalPricingTrackingValid')) {
    function canonicalPricingTrackingValid(?float $distanceKm, ?int $durationSec): bool
    {
        return ($distanceKm !== null && $distanceKm > 0.1)
            && ($durationSec !== null && $durationSec > 30);
    }
}

if (!function_exists('canonicalPricingNormalizeCop')) {
    function canonicalPricingNormalizeCop(float $amount, float $step = 100.0): float
    {
        $amount = max(0.0, $amount);
        if ($step <= 0) {
            return round($amount, 2);
        }

        // Redondeo hacia arriba para no violar el piso canónico por redondeo.
        return ceil($amount / $step) * $step;
    }
}

if (!function_exists('canonicalPricingAuditLog')) {
    function canonicalPricingAuditLog(?object $redis, array $payload): void
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return;
        }

        error_log('[pricing_audit] ' . $encoded);

        if (!$redis) {
            return;
        }

        try {
            $tripId = isset($payload['trip_id']) ? (int)$payload['trip_id'] : 0;
            if ($tripId > 0) {
                $redis->setex('trip:' . $tripId . ':pricing_audit:last', 86400, $encoded);
                $redis->lPush('trip:' . $tripId . ':pricing_audit:history', $encoded);
                $redis->lTrim('trip:' . $tripId . ':pricing_audit:history', 0, 49);
                $redis->expire('trip:' . $tripId . ':pricing_audit:history', 86400);
            }
        } catch (Throwable $e) {
            error_log('[pricing_audit] redis_error=' . $e->getMessage());
        }
    }
}

if (!function_exists('canonicalPricingAlert')) {
    function canonicalPricingAlert(?object $redis, array $payload): void
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return;
        }

        error_log('[pricing_alert] ' . $encoded);

        if (!$redis) {
            return;
        }

        try {
            $tripId = isset($payload['trip_id']) ? (int)$payload['trip_id'] : 0;
            $redis->incr('metrics:pricing_alerts_total');
            $redis->incr('metrics:pricing_alerts:' . ($payload['severity'] ?? 'critical'));
            $redis->publish('alerts:pricing', $encoded);

            if ($tripId > 0) {
                $redis->setex('trip:' . $tripId . ':pricing_alert:last', 86400, $encoded);
            }
        } catch (Throwable $e) {
            error_log('[pricing_alert] redis_error=' . $e->getMessage());
        }
    }
}

if (!function_exists('canonicalPricingPersistRedisLock')) {
    function canonicalPricingPersistRedisLock(
        ?object $redis,
        int $tripId,
        float $finalPrice,
        string $ruleApplied,
        bool $trackingValid,
        int $ttlSec = 86400
    ): void {
        if (!$redis || $tripId <= 0) {
            return;
        }

        try {
            $redis->setex('trip:' . $tripId . ':price_locked', $ttlSec, '1');
            $redis->setex('trip:' . $tripId . ':price_final_canonical', $ttlSec, (string)round($finalPrice, 2));
            $redis->setex('trip:' . $tripId . ':pricing_rule_applied', $ttlSec, $ruleApplied);
            $redis->setex('trip:' . $tripId . ':tracking_valido', $ttlSec, $trackingValid ? '1' : '0');
        } catch (Throwable $e) {
            error_log('[pricing_lock] redis_error=' . $e->getMessage());
        }
    }
}

if (!function_exists('canonicalPricingResolve')) {
    function canonicalPricingResolve(
        float $dynamicPrice,
        float $estimatedPrice,
        bool $trackingValid,
        array $options = []
    ): array {
        $dynamic = max(0.0, $dynamicPrice);
        $estimate = max(0.0, $estimatedPrice);
        $source = isset($options['source']) ? (string)$options['source'] : 'unknown';
        $tripId = isset($options['trip_id']) ? (int)$options['trip_id'] : 0;
        $actorId = isset($options['actor_id']) ? (int)$options['actor_id'] : null;
        $emitAudit = !isset($options['emit_audit']) || (bool)$options['emit_audit'];
        $emitAlerts = !isset($options['emit_alerts']) || (bool)$options['emit_alerts'];
        $normalizeStep = isset($options['normalize_step']) ? (float)$options['normalize_step'] : 100.0;
        $redis = (isset($options['redis']) && is_object($options['redis'])) ? $options['redis'] : null;
        $extra = isset($options['extra']) && is_array($options['extra']) ? $options['extra'] : [];

        $rule = 'tracking_valid_dynamic_no_estimate';
        $floorApplied = false;
        $attemptedViolation = false;

        if ($estimate > 0.0) {
            if (!$trackingValid) {
                $rawFinal = $estimate;
                $rule = 'tracking_invalid_estimate_lock';
                $floorApplied = true;
            } else {
                if ($dynamic < $estimate) {
                    $attemptedViolation = true;
                    $floorApplied = true;
                    $rule = 'tracking_valid_floor_guard';
                } else {
                    $rule = 'tracking_valid_dynamic';
                }
                $rawFinal = max($dynamic, $estimate);
            }
        } else {
            $rawFinal = max(0.0, $dynamic);
            $rule = $trackingValid
                ? 'tracking_valid_dynamic_no_estimate'
                : 'tracking_invalid_no_estimate';
        }

        $final = canonicalPricingNormalizeCop($rawFinal, $normalizeStep);

        // Defensa final de seguridad.
        if ($estimate > 0.0 && $final < $estimate) {
            $attemptedViolation = true;
            $floorApplied = true;
            $rule = 'critical_floor_post_normalization';
            $final = canonicalPricingNormalizeCop($estimate, $normalizeStep);
            if ($final < $estimate) {
                $final = $estimate;
            }
        }

        $payload = [
            'trip_id' => $tripId,
            'actor_id' => $actorId,
            'source' => $source,
            'dynamic_price' => round($dynamic, 2),
            'estimated_price' => round($estimate, 2),
            'final_price' => round($final, 2),
            'tracking_valid' => $trackingValid,
            'rule' => $rule,
            'floor_applied' => $floorApplied,
            'attempted_violation' => $attemptedViolation,
            'timestamp' => gmdate('c'),
            'extra' => $extra,
        ];

        if ($emitAudit || $attemptedViolation) {
            canonicalPricingAuditLog($redis, $payload);
        }

        if ($emitAlerts && $attemptedViolation) {
            canonicalPricingAlert($redis, [
                'trip_id' => $tripId,
                'source' => $source,
                'severity' => 'critical',
                'code' => 'price_below_estimate_blocked',
                'estimated_price' => round($estimate, 2),
                'attempted_dynamic_price' => round($dynamic, 2),
                'final_price' => round($final, 2),
                'rule' => $rule,
                'timestamp' => gmdate('c'),
            ]);
        }

        return [
            'final_price' => (float)$final,
            'rule' => $rule,
            'floor_applied' => $floorApplied,
            'attempted_violation' => $attemptedViolation,
            'tracking_valid' => $trackingValid,
        ];
    }
}
