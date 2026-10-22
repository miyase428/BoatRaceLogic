<?php

declare(strict_types=1);

/** Browser-session CSRF boundary for the manual official exhibition action. */
final class OfficialExhibitionManualRequest
{
    public const TOKEN_KEY = 'official_exhibition_manual_csrf';

    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
            ]);
        }
        $token = $_SESSION[self::TOKEN_KEY] ?? null;
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::TOKEN_KEY] = $token;
        }
        return $token;
    }

    public static function validatePost(string $token): bool
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return false;
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
            ]);
        }
        $expected = $_SESSION[self::TOKEN_KEY] ?? null;
        return is_string($expected) && preg_match('/^[a-f0-9]{64}$/', $expected) === 1
            && hash_equals($expected, $token);
    }

    /**
     * Persist the CSRF token and release PHP's file-session lock before the
     * controller makes a localhost request carrying the same session ID.
     */
    public static function closeForInternalRequest(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}
