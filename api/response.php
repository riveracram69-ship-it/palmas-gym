<?php
/**
 * api/response.php
 * Standardized API Response Helper Class
 * 
 * Enforces uniform JSON response schema across all endpoints:
 * {
 *   "success": bool,
 *   "message": string,
 *   "data": mixed,
 *   "timestamp": string (ISO-8601)
 * }
 */

class ApiResponse {
    /**
     * Send a standardized JSON response and exit
     */
    public static function json(bool $success, string $message = '', mixed $data = null, int $statusCode = 200, array $extra = []): void {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
        }

        $payload = array_merge([
            'success'   => $success,
            'message'   => $message,
            'data'      => $data,
            'timestamp' => date('c'),
        ], $extra);

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send a success response (HTTP 200)
     */
    public static function success(mixed $data = null, string $message = 'Operation successful', array $extra = []): void {
        self::json(true, $message, $data, 200, $extra);
    }

    /**
     * Send an error response (Default HTTP 400 Bad Request)
     */
    public static function error(string $message, int $statusCode = 400, mixed $data = null, array $extra = []): void {
        self::json(false, $message, $data, $statusCode, $extra);
    }

    /**
     * Send an unauthorized response (HTTP 401)
     */
    public static function unauthorized(string $message = 'Authentication required or session expired.'): void {
        self::json(false, $message, null, 401);
    }

    /**
     * Send a forbidden response (HTTP 403)
     */
    public static function forbidden(string $message = 'Access forbidden.'): void {
        self::json(false, $message, null, 403);
    }

    /**
     * Send a not found response (HTTP 404)
     */
    public static function notFound(string $message = 'Resource not found.'): void {
        self::json(false, $message, null, 404);
    }

    /**
     * Send a rate-limited response (HTTP 429)
     */
    public static function rateLimited(string $message, int $waitSeconds = 0): void {
        if (!headers_sent() && $waitSeconds > 0) {
            header("Retry-After: {$waitSeconds}");
        }
        self::json(false, $message, null, 429, [
            'rate_limited' => true,
            'wait_seconds' => $waitSeconds,
        ]);
    }
}
