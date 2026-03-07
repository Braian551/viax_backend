<?php
/**
 * Helper de respuesta HTTP uniforme para controladores.
 *
 * Mantiene compatibilidad con el contrato JSON existente.
 */

class Response
{
    public static function json(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($payload);
    }

    public static function success(array $data = [], string $message = 'OK', int $statusCode = 200): void
    {
        self::json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }

    public static function error(string $message, int $statusCode = 400, ?string $errorCode = null): void
    {
        $payload = [
            'success' => false,
            'message' => $message,
            'error' => $message,
        ];

        if ($errorCode !== null) {
            $payload['error_code'] = $errorCode;
        }

        self::json($payload, $statusCode);
    }
}
