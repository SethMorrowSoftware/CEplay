<?php
/**
 * SQLite database singleton with schema initialization and query helpers.
 */

require_once __DIR__ . '/../config.php';

class DB {
    private static ?SQLite3 $instance = null;

    public static function getInstance(): SQLite3 {
        if (self::$instance === null) {
            $dir = dirname(DB_PATH);
            if (!is_dir($dir)) {
                mkdir($dir, 0770, true);
            }

            self::$instance = new SQLite3(DB_PATH);
            self::$instance->enableExceptions(true);
            self::$instance->busyTimeout(30000);
            self::$instance->exec('PRAGMA journal_mode=WAL');
            self::$instance->exec('PRAGMA foreign_keys=ON');
            self::$instance->exec('PRAGMA synchronous=NORMAL');

            self::initSchema();
        }
        return self::$instance;
    }

    private static function initSchema(): void {
        $db = self::$instance;

        $db->exec('CREATE TABLE IF NOT EXISTS admin_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            display_name TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS api_config (
            key TEXT PRIMARY KEY,
            value TEXT,
            encrypted INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS pause_groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT DEFAULT \'\',
            is_active INTEGER NOT NULL DEFAULT 1,
            manual_override_action TEXT DEFAULT NULL,
            manual_override_at TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');

        // Migration: add manual override columns to existing databases
        try {
            $db->exec('ALTER TABLE pause_groups ADD COLUMN manual_override_action TEXT DEFAULT NULL');
        } catch (Exception $e) {
            // Column already exists — ignore
        }
        try {
            $db->exec('ALTER TABLE pause_groups ADD COLUMN manual_override_at TEXT DEFAULT NULL');
        } catch (Exception $e) {
            // Column already exists — ignore
        }

        $db->exec('CREATE TABLE IF NOT EXISTS pause_group_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pause_group_id INTEGER NOT NULL,
            category_id INTEGER NOT NULL,
            category_name TEXT NOT NULL DEFAULT \'\',
            FOREIGN KEY (pause_group_id) REFERENCES pause_groups(id) ON DELETE CASCADE
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_pgc_group ON pause_group_categories(pause_group_id)');

        $db->exec('CREATE TABLE IF NOT EXISTS pause_group_games (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pause_group_id INTEGER NOT NULL,
            game_id TEXT NOT NULL,
            game_name TEXT NOT NULL DEFAULT \'\',
            FOREIGN KEY (pause_group_id) REFERENCES pause_groups(id) ON DELETE CASCADE
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_pgg_group ON pause_group_games(pause_group_id)');

        // Kiosks linked to a pause group, paused/unpaused alongside the group's games.
        $db->exec('CREATE TABLE IF NOT EXISTS pause_group_kiosks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pause_group_id INTEGER NOT NULL,
            kiosk_id TEXT NOT NULL,
            kiosk_name TEXT NOT NULL DEFAULT \'\',
            FOREIGN KEY (pause_group_id) REFERENCES pause_groups(id) ON DELETE CASCADE
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_pgk_group ON pause_group_kiosks(pause_group_id)');

        $db->exec('CREATE TABLE IF NOT EXISTS schedules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pause_group_id INTEGER NOT NULL,
            day_of_week INTEGER NOT NULL,
            start_time TEXT NOT NULL,
            end_time TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            FOREIGN KEY (pause_group_id) REFERENCES pause_groups(id) ON DELETE CASCADE
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_sched_group ON schedules(pause_group_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_sched_day ON schedules(day_of_week)');

        $db->exec('CREATE TABLE IF NOT EXISTS schedule_overrides (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pause_group_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            action TEXT NOT NULL,
            start_datetime TEXT NOT NULL,
            end_datetime TEXT NOT NULL,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            FOREIGN KEY (pause_group_id) REFERENCES pause_groups(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES admin_users(id)
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_override_group ON schedule_overrides(pause_group_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_override_dates ON schedule_overrides(start_datetime, end_datetime)');

        $db->exec('CREATE TABLE IF NOT EXISTS action_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp TEXT NOT NULL DEFAULT (datetime(\'now\')),
            source TEXT NOT NULL,
            action TEXT NOT NULL,
            pause_group_id INTEGER,
            game_id TEXT,
            game_name TEXT,
            details TEXT,
            success INTEGER NOT NULL DEFAULT 1,
            error_message TEXT,
            FOREIGN KEY (pause_group_id) REFERENCES pause_groups(id) ON DELETE SET NULL
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_log_timestamp ON action_log(timestamp)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_log_source ON action_log(source)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_log_group ON action_log(pause_group_id)');

        $db->exec('CREATE TABLE IF NOT EXISTS scheduled_actions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pause_group_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            scheduled_time TEXT NOT NULL,
            scheduled_date TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT \'schedule\',
            at_job_id TEXT,
            executed INTEGER NOT NULL DEFAULT 0,
            executed_at TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            FOREIGN KEY (pause_group_id) REFERENCES pause_groups(id) ON DELETE CASCADE
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_sa_date ON scheduled_actions(scheduled_date)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_sa_executed ON scheduled_actions(executed)');

        $db->exec('CREATE TABLE IF NOT EXISTS game_state_cache (
            game_id TEXT PRIMARY KEY,
            game_name TEXT NOT NULL,
            operation_status TEXT NOT NULL DEFAULT \'enabled\',
            categories TEXT DEFAULT \'[]\',
            last_synced_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');

        // Cache of kiosks pulled from the CenterEdge `/kiosks` endpoint.
        // operation_status mirrors the API's GameOperationStatus enum
        // (enabled | paused | outOfService) and may be empty when the
        // device reports "unknown" (per spec, such kiosks must NOT be
        // pause-controlled via the PATCH /kiosks endpoint).
        $db->exec('CREATE TABLE IF NOT EXISTS kiosk_state_cache (
            kiosk_id TEXT PRIMARY KEY,
            kiosk_name TEXT NOT NULL,
            operation_status TEXT NOT NULL DEFAULT \'\',
            categories TEXT DEFAULT \'[]\',
            supported_actions TEXT DEFAULT \'[]\',
            last_synced_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL,
            attempted_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_attempts_ip ON login_attempts(ip_address)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_attempts_time ON login_attempts(attempted_at)');

        // One-time cleanup of tables from removed feature modules.
        // The card/parties/maintenance/etc. modules were dropped to scope this
        // app to pause-groups + kiosks only. DROP IF EXISTS is safe to run on
        // every boot — it's a no-op once the tables are gone.
        foreach ([
            'attractions',
            'maintenance_tickets',
            'party_bookings',
            'party_packages',
            'announcements',
        ] as $obsoleteTable) {
            try {
                $db->exec("DROP TABLE IF EXISTS $obsoleteTable");
            } catch (Exception $e) {
                error_log("Failed to drop obsolete table $obsoleteTable: " . $e->getMessage());
            }
        }
    }

    /**
     * Execute a parameterized SELECT query, return all rows as associative arrays.
     */
    public static function query(string $sql, array $params = []): array {
        $db = self::getInstance();
        $stmt = $db->prepare($sql);
        self::bindParams($stmt, $params);
        $result = $stmt->execute();

        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        $result->finalize();
        $stmt->close();
        return $rows;
    }

    /**
     * Execute a parameterized SELECT query, return first row or null.
     */
    public static function queryOne(string $sql, array $params = []): ?array {
        $db = self::getInstance();
        $stmt = $db->prepare($sql);
        self::bindParams($stmt, $params);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize();
        $stmt->close();
        return $row === false ? null : $row;
    }

    /**
     * Execute a parameterized INSERT/UPDATE/DELETE query, return affected row count.
     */
    public static function execute(string $sql, array $params = []): int {
        $db = self::getInstance();
        $stmt = $db->prepare($sql);
        self::bindParams($stmt, $params);
        $result = $stmt->execute();
        $result->finalize();
        $changes = $db->changes();
        $stmt->close();
        return $changes;
    }

    /**
     * Return the last inserted row ID.
     */
    public static function lastInsertId(): int {
        return (int) self::getInstance()->lastInsertRowID();
    }

    /**
     * Bind parameters to a prepared statement.
     * Supports positional (:p0, :p1, ...) binding from an indexed array.
     */
    private static function bindParams(SQLite3Stmt $stmt, array $params): void {
        foreach ($params as $i => $value) {
            $key = ':p' . $i;
            if ($value === null) {
                $stmt->bindValue($key, null, SQLITE3_NULL);
            } elseif (is_int($value)) {
                $stmt->bindValue($key, $value, SQLITE3_INTEGER);
            } elseif (is_float($value)) {
                $stmt->bindValue($key, $value, SQLITE3_FLOAT);
            } else {
                $stmt->bindValue($key, (string) $value, SQLITE3_TEXT);
            }
        }
    }

    /**
     * Build a parameterized WHERE clause from conditions.
     * Returns [$whereClause, $params] where params use :p0, :p1, ... keys.
     */
    public static function buildWhere(array $conditions, int $startIdx = 0): array {
        $clauses = [];
        $params = [];
        $idx = $startIdx;
        foreach ($conditions as $column => $value) {
            $clauses[] = "$column = :p$idx";
            $params[$idx] = $value;
            $idx++;
        }
        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
        return [$where, $params];
    }

    /**
     * Get a config value from the api_config table.
     */
    public static function getConfig(string $key): ?string {
        $row = self::queryOne(
            'SELECT value, encrypted FROM api_config WHERE key = :p0',
            [$key]
        );
        if (!$row) {
            return null;
        }
        if ($row['encrypted']) {
            if (!class_exists('Crypto')) {
                error_log("getConfig('$key'): value is encrypted but Crypto class is not available");
                return null;
            }
            return Crypto::decrypt($row['value']);
        }
        return $row['value'];
    }

    /**
     * Write an audit log entry for administrative actions.
     * Lightweight wrapper around action_log for tracking non-game actions.
     */
    public static function auditLog(string $source, string $action, ?int $userId = null, ?array $details = null): void {
        try {
            $payload = $details ?? [];
            if ($userId !== null) {
                $payload['actor_user_id'] = $userId;
            }
            self::execute(
                'INSERT INTO action_log (source, action, success, details)
                 VALUES (:p0, :p1, 1, :p2)',
                [$source, $action, !empty($payload) ? json_encode($payload) : null]
            );
        } catch (Exception $e) {
            error_log("Audit log failed: $source/$action — " . $e->getMessage());
        }
    }

    /**
     * Set a config value in the api_config table.
     */
    public static function setConfig(string $key, ?string $value, bool $encrypt = false): void {
        $storedValue = $value;
        if ($encrypt && $value !== null && $value !== '' && class_exists('Crypto')) {
            $storedValue = Crypto::encrypt($value);
        }
        self::execute(
            'INSERT INTO api_config (key, value, encrypted, updated_at)
             VALUES (:p0, :p1, :p2, datetime(\'now\'))
             ON CONFLICT(key) DO UPDATE SET value = :p1, encrypted = :p2, updated_at = datetime(\'now\')',
            [$key, $storedValue, $encrypt ? 1 : 0]
        );
    }
}
