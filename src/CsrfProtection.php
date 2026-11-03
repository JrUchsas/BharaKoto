<?php
declare(strict_types=1);

/**
 * CSRF Protection Helper for BharaKoto
 */
final class CsrfProtection
{
    private const TOKEN_NAME = 'csrf_token';
    private const TOKEN_LENGTH = 32;
    private const TOKEN_TTL = 3600; // 1 hour

    /**
     * Generate and store a new CSRF token in session
     */
    public static function generate(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        $_SESSION[self::TOKEN_NAME] = [
            'token' => $token,
            'expires' => time() + self::TOKEN_TTL,
        ];

        return $token;
    }

    /**
     * Get the current CSRF token (generate if not exists)
     */
    public static function getToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION[self::TOKEN_NAME]) || $_SESSION[self::TOKEN_NAME]['expires'] < time()) {
            return self::generate();
        }

        return $_SESSION[self::TOKEN_NAME]['token'];
    }

    /**
     * Validate a CSRF token from request
     */
    public static function validate(?string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!$token || empty($_SESSION[self::TOKEN_NAME])) {
            return false;
        }

        $stored = $_SESSION[self::TOKEN_NAME];

        if ($stored['expires'] < time()) {
            unset($_SESSION[self::TOKEN_NAME]);
            return false;
        }

        // Constant-time comparison to prevent timing attacks
        return hash_equals($stored['token'], $token);
    }

    /**
     * Validate token from POST body or header, throw on failure
     */
    public static function requireValid(): void
    {
        $token = $_POST[self::TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        if (!self::validate($token)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid or expired CSRF token. Please refresh the page and try again.'
            ]);
            exit;
        }
    }

    /**
     * Output hidden input field for forms
     */
    public static function inputField(): string
    {
        $token = self::getToken();
        return sprintf('<input type="hidden" name="%s" value="%s" />', self::TOKEN_NAME, htmlspecialchars($token, ENT_QUOTES));
    }

    /**
     * Output meta tag for AJAX requests
     */
    public static function metaTag(): string
    {
        $token = self::getToken();
        return sprintf('<meta name="csrf-token" content="%s" />', htmlspecialchars($token, ENT_QUOTES));
    }
}