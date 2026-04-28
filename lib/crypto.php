<?php
/**
 * Authenticated AES-256-CBC encryption/decryption for credentials at rest.
 * Uses HMAC-SHA256 for integrity verification (encrypt-then-MAC).
 * Backward-compatible: decrypts old data without HMAC gracefully.
 */

require_once __DIR__ . '/../config.php';

class Crypto {
    private static string $cipher = 'aes-256-cbc';
    private static int $hmacLen = 32; // SHA-256 produces 32 bytes

    /**
     * Encrypt plaintext using AES-256-CBC + HMAC-SHA256 (encrypt-then-MAC).
     * Returns base64(hmac + iv + ciphertext).
     */
    public static function encrypt(string $plaintext): string {
        $key = self::getKey();
        $encKey = self::deriveSubKey($key, 'enc');
        $macKey = self::deriveSubKey($key, 'mac');

        $ivLen = openssl_cipher_iv_length(self::$cipher);
        $iv = random_bytes($ivLen); // Always CSPRNG, unlike openssl_random_pseudo_bytes
        $ciphertext = openssl_encrypt($plaintext, self::$cipher, $encKey, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed');
        }

        $payload = $iv . $ciphertext;
        $hmac = hash_hmac('sha256', $payload, $macKey, true);

        return base64_encode($hmac . $payload);
    }

    /**
     * Decrypt a value previously encrypted with encrypt().
     * Backward-compatible: if data lacks HMAC prefix (old format), falls back
     * to legacy decryption and logs a notice.
     * Returns plaintext.
     */
    public static function decrypt(string $encrypted): string {
        $key = self::getKey();
        $data = base64_decode($encrypted, true);
        if ($data === false) {
            throw new RuntimeException('Decryption failed: invalid base64');
        }

        $ivLen = openssl_cipher_iv_length(self::$cipher);

        // New format: hmac(32) + iv(16) + ciphertext
        // Old format: iv(16) + ciphertext
        // Try HMAC-verified decryption first
        if (strlen($data) >= self::$hmacLen + $ivLen + 1) {
            $result = self::decryptAuthenticated($data, $key, $ivLen);
            if ($result !== null) {
                return $result;
            }
        }

        // Fall back to legacy (unauthenticated) decryption for old data.
        // Log a warning so admins know to re-save credentials to upgrade encryption.
        error_log('Crypto: decrypting legacy (pre-HMAC) data — re-save credentials to upgrade');
        return self::decryptLegacy($data, $key, $ivLen);
    }

    /**
     * Attempt authenticated decryption (new format with HMAC).
     */
    private static function decryptAuthenticated(string $data, string $key, int $ivLen): ?string {
        $encKey = self::deriveSubKey($key, 'enc');
        $macKey = self::deriveSubKey($key, 'mac');

        $hmac = substr($data, 0, self::$hmacLen);
        $payload = substr($data, self::$hmacLen);

        $expectedHmac = hash_hmac('sha256', $payload, $macKey, true);
        if (!hash_equals($expectedHmac, $hmac)) {
            return null; // HMAC mismatch — might be old format
        }

        $iv = substr($payload, 0, $ivLen);
        $ciphertext = substr($payload, $ivLen);
        $plaintext = openssl_decrypt($ciphertext, self::$cipher, $encKey, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed: HMAC valid but decryption error');
        }
        return $plaintext;
    }

    /**
     * Legacy decryption without HMAC (for data encrypted before this upgrade).
     */
    private static function decryptLegacy(string $data, string $key, int $ivLen): string {
        if (strlen($data) < $ivLen) {
            throw new RuntimeException('Decryption failed: data too short');
        }
        $iv = substr($data, 0, $ivLen);
        $ciphertext = substr($data, $ivLen);
        $plaintext = openssl_decrypt($ciphertext, self::$cipher, $key, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed: wrong key or corrupted data');
        }
        return $plaintext;
    }

    /**
     * Derive separate sub-keys for encryption and MAC from the master key.
     * Uses HKDF-like derivation via HMAC.
     */
    private static function deriveSubKey(string $masterKey, string $purpose): string {
        return hash_hmac('sha256', $purpose, $masterKey, true);
    }

    /**
     * Derive binary key from the hex ENCRYPTION_KEY constant.
     */
    private static function getKey(): string {
        $hexKey = ENCRYPTION_KEY;
        if (empty($hexKey)) {
            throw new RuntimeException('ENCRYPTION_KEY is not configured. Run install.php or set PG_ENCRYPTION_KEY env var.');
        }
        $key = hex2bin($hexKey);
        if ($key === false || strlen($key) < 16) {
            throw new RuntimeException('ENCRYPTION_KEY must be a valid hex string of at least 32 hex characters (16 bytes).');
        }
        // Pad or truncate to 32 bytes for AES-256
        if (strlen($key) < 32) {
            $key = hash('sha256', $key, true);
        } else {
            $key = substr($key, 0, 32);
        }
        return $key;
    }
}
