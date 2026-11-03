<?php
declare(strict_types=1);

/**
 * Simple Rate Limiter for API endpoints
 * Uses file-based storage (for simplicity, can be replaced with Redis in production)
 */
final class RateLimiter
{
    private const STORAGE_DIR = __DIR__ . '/../storage/rate_limit/';
    private const DEFAULT_LIMIT = 60; // requests per window
    private const DEFAULT_WINDOW = 60; // seconds

    public static function init(): void
    {
        if (!is_dir(self::STORAGE_DIR)) {
            mkdir(self::STORAGE_DIR, 0755, true);
        }
    }

    /**
     * Check and record a request for a given key
     * Returns array: [allowed (bool), remaining (int), reset_time (int)]
     */
    public static function check(string $key, int $limit = self::DEFAULT_LIMIT, int $window = self::DEFAULT_WINDOW): array
    {
        self::init();

        $file = self::STORAGE_DIR . md5($key) . '.json';
        $now = time();
        $windowStart = $now - $window;

        $data = ['requests' => []];
        if (file_exists($file)) {
            $content = @file_get_contents($file);
            if ($content) {
                $data = json_decode($content, true) ?? ['requests' => []];
            }
        }

        // Clean old requests outside the window
        $data['requests'] = array_filter($data['requests'], fn($ts) => $ts > $windowStart);
        $data['requests'] = array_values($data['requests']);

        $currentCount = count($data['requests']);
        $remaining = max(0, $limit - $currentCount);
        $allowed = $currentCount < $limit;

        if ($allowed) {
            $data['requests'][] = $now;
            @file_put_contents($file, json_encode($data), LOCK_EX);
        }

        // Reset time = oldest request + window, or now + window if no requests
        $resetTime = $data['requests'] ? ($data['requests'][0] + $window) : ($now + $window);

        return [$allowed, $remaining, $resetTime];
    }

    /**
     * Middleware function to enforce rate limiting on an endpoint
     */
    public static function enforce(string $identifier, int $limit = self::DEFAULT_LIMIT, int $window = self::DEFAULT_WINDOW): void
    {
        // Use IP + endpoint as key
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = "{$identifier}:{$ip}";

        [$allowed, $remaining, $resetTime] = self::check($key, $limit, $window);

        // Set rate limit headers
        header("X-RateLimit-Limit: {$limit}");
        header("X-RateLimit-Remaining: {$remaining}");
        header("X-RateLimit-Reset: {$resetTime}");

        if (!$allowed) {
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status' => 'error',
                'message' => 'Rate limit exceeded. Please slow down your requests.',
                'retry_after' => max(1, $resetTime - time())
            ]);
            exit;
        }
    }
}