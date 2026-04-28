<?php
/**
 * CSRF token generation and validation.
 * Tokens are stored in $_SESSION and validated via X-CSRF-Token header.
 */

class CSRF {
    private static string $sessionKey = 'csrf_token';

    /**
     * Generate a new CSRF token and store in session.
     * Returns the token string.
     */
    public static function generate(): string {
        $token = bin2hex(random_bytes(32));
        $_SESSION[self::$sessionKey] = $token;
        return $token;
    }

    /**
     * Get the current token from session, or null if not set.
     */
    public static function getToken(): ?string {
        return $_SESSION[self::$sessionKey] ?? null;
    }

    /**
     * Validate the X-CSRF-Token header against the session token.
     * Uses timing-safe hash_equals() comparison.
     */
    public static function validate(): bool {
        $sessionToken = self::getToken();
        if ($sessionToken === null) {
            return false;
        }
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($headerToken === '') {
            return false;
        }
        return hash_equals($sessionToken, $headerToken);
    }
}
