<?php
/**
 * Pause Group Automation — Configuration
 *
 * IMPORTANT: After installation, set ENCRYPTION_KEY to a unique random hex string.
 * Generate one with: php -r "echo bin2hex(random_bytes(32));"
 */

// Encryption key for AES-256-CBC (64 hex chars = 32 bytes)
define('ENCRYPTION_KEY', getenv('PG_ENCRYPTION_KEY') ?: '');

// Database path
define('DB_PATH', __DIR__ . '/data/pause_groups.db');

// Default timezone for schedules
define('DEFAULT_TIMEZONE', 'America/New_York');

// Session lifetime in seconds (2 hours)
define('SESSION_LIFETIME', 7200);

// Debug mode (when true, API 500 responses may include internal error details)
define('APP_DEBUG', filter_var(getenv('PG_APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN));

// Application base path (auto-detected)
define('BASE_PATH', rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/'));

// Lock file for concurrent execution prevention
define('LOCK_FILE', __DIR__ . '/data/.scheduler.lock');

// CenterEdge API defaults
define('API_TIMEOUT', 30);        // seconds
define('TOKEN_MAX_AGE', 1800);    // 30 minutes before proactive re-auth
define('GAMES_PAGE_SIZE', 500);   // games per page when fetching from API
