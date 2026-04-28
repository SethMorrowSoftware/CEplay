<?php
/**
 * Input validation/sanitization helpers.
 * All methods throw RuntimeException on validation failure with a descriptive message.
 */

class Validator {
    /**
     * Validate and return a required non-empty string.
     */
    public static function requireString(array $data, string $field, int $maxLen = 255): string {
        if (!isset($data[$field]) || !is_string($data[$field]) || trim($data[$field]) === '') {
            throw new RuntimeException("Field '$field' is required.");
        }
        $value = trim($data[$field]);
        if (mb_strlen($value) > $maxLen) {
            throw new RuntimeException("Field '$field' must not exceed $maxLen characters.");
        }
        return $value;
    }

    /**
     * Validate and return an optional string (may be null or empty).
     */
    public static function optionalString(array $data, string $field, int $maxLen = 1000): string {
        if (!isset($data[$field]) || !is_string($data[$field])) {
            return '';
        }
        $value = trim($data[$field]);
        if (mb_strlen($value) > $maxLen) {
            throw new RuntimeException("Field '$field' must not exceed $maxLen characters.");
        }
        return $value;
    }

    /**
     * Validate and return a required integer.
     */
    public static function requireInt(array $data, string $field, ?int $min = null, ?int $max = null): int {
        if (!isset($data[$field]) || (!is_int($data[$field]) && (!is_numeric($data[$field]) || intval($data[$field]) != $data[$field]))) {
            throw new RuntimeException("Field '$field' must be an integer.");
        }
        $value = (int) $data[$field];
        if ($min !== null && $value < $min) {
            throw new RuntimeException("Field '$field' must be at least $min.");
        }
        if ($max !== null && $value > $max) {
            throw new RuntimeException("Field '$field' must not exceed $max.");
        }
        return $value;
    }

    /**
     * Validate and return an optional integer.
     */
    public static function optionalInt(array $data, string $field, ?int $min = null, ?int $max = null): ?int {
        if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
            return null;
        }
        return self::requireInt($data, $field, $min, $max);
    }

    /**
     * Validate a time string in HH:MM format (00:00 - 23:59).
     */
    public static function requireTime(array $data, string $field): string {
        $value = self::requireString($data, $field, 5);
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value)) {
            throw new RuntimeException("Field '$field' must be a valid time in HH:MM format (00:00 - 23:59).");
        }
        return $value;
    }

    /**
     * Validate a datetime string in YYYY-MM-DD HH:MM format.
     */
    public static function requireDatetime(array $data, string $field): string {
        $value = self::requireString($data, $field, 16);
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
            throw new RuntimeException("Field '$field' must be in YYYY-MM-DD HH:MM format.");
        }
        $dt = DateTime::createFromFormat('Y-m-d H:i', $value);
        if (!$dt || $dt->format('Y-m-d H:i') !== $value) {
            throw new RuntimeException("Field '$field' is not a valid date/time.");
        }
        return $value;
    }

    /**
     * Validate a date string in YYYY-MM-DD format.
     */
    public static function requireDate(array $data, string $field): string {
        $value = self::requireString($data, $field, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new RuntimeException("Field '$field' must be in YYYY-MM-DD format.");
        }
        $dt = DateTime::createFromFormat('Y-m-d', $value);
        if (!$dt || $dt->format('Y-m-d') !== $value) {
            throw new RuntimeException("Field '$field' is not a valid date.");
        }
        return $value;
    }

    /**
     * Validate day of week (0=Sunday through 6=Saturday).
     */
    public static function requireDayOfWeek(array $data, string $field): int {
        return self::requireInt($data, $field, 0, 6);
    }

    /**
     * Validate enum value against allowed values.
     */
    public static function requireEnum(array $data, string $field, array $allowed): string {
        $value = self::requireString($data, $field);
        if (!in_array($value, $allowed, true)) {
            $list = implode(', ', $allowed);
            throw new RuntimeException("Field '$field' must be one of: $list.");
        }
        return $value;
    }

    /**
     * Validate and return an array of integers.
     */
    public static function requireIntArray(array $data, string $field): array {
        if (!isset($data[$field]) || !is_array($data[$field])) {
            throw new RuntimeException("Field '$field' must be an array.");
        }
        $result = [];
        foreach ($data[$field] as $i => $v) {
            if (!is_int($v) && !ctype_digit(strval($v))) {
                throw new RuntimeException("Each item in '$field' must be an integer.");
            }
            $result[] = (int) $v;
        }
        return $result;
    }

    /**
     * Validate and return an optional array of integers (returns empty array if missing).
     */
    public static function optionalIntArray(array $data, string $field): array {
        if (!isset($data[$field]) || !is_array($data[$field])) {
            return [];
        }
        return self::requireIntArray($data, $field);
    }

    /**
     * Validate and return an array of non-empty strings.
     */
    public static function requireStringArray(array $data, string $field): array {
        if (!isset($data[$field]) || !is_array($data[$field])) {
            throw new RuntimeException("Field '$field' must be an array.");
        }
        $result = [];
        foreach ($data[$field] as $v) {
            if (!is_string($v) || trim($v) === '') {
                throw new RuntimeException("Each item in '$field' must be a non-empty string.");
            }
            $result[] = trim($v);
        }
        return $result;
    }

    /**
     * Validate and return an optional array of strings (returns empty array if missing).
     */
    public static function optionalStringArray(array $data, string $field): array {
        if (!isset($data[$field]) || !is_array($data[$field])) {
            return [];
        }
        return self::requireStringArray($data, $field);
    }

    /**
     * Validate a URL.
     */
    public static function requireUrl(array $data, string $field): string {
        $value = self::requireString($data, $field, 500);
        $value = rtrim($value, '/');
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            throw new RuntimeException("Field '$field' must be a valid URL.");
        }
        return $value;
    }

    /**
     * Parse pagination parameters, returns [page, perPage, offset].
     */
    public static function pagination(array $data, int $defaultPerPage = 50, int $maxPerPage = 200): array {
        $page = max(1, (int)($data['page'] ?? 1));
        $perPage = min($maxPerPage, max(1, (int)($data['per_page'] ?? $defaultPerPage)));
        $offset = ($page - 1) * $perPage;
        return [$page, $perPage, $offset];
    }
}
