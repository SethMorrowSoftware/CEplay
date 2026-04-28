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

        $db->exec('CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL,
            attempted_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_attempts_ip ON login_attempts(ip_address)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_attempts_time ON login_attempts(attempted_at)');

        // ---- Attractions / Rides ----
        $db->exec('CREATE TABLE IF NOT EXISTS attractions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            type TEXT NOT NULL DEFAULT \'ride\',
            description TEXT DEFAULT \'\',
            location TEXT DEFAULT \'\',
            capacity INTEGER DEFAULT 0,
            min_height_inches INTEGER DEFAULT 0,
            max_height_inches INTEGER DEFAULT 0,
            duration_minutes INTEGER DEFAULT 0,
            status TEXT NOT NULL DEFAULT \'open\',
            centeredge_game_id TEXT DEFAULT NULL,
            requires_wristband INTEGER NOT NULL DEFAULT 0,
            credit_cost INTEGER NOT NULL DEFAULT 0,
            image_url TEXT DEFAULT \'\',
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_attractions_type ON attractions(type)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_attractions_status ON attractions(status)');

        // ---- Maintenance Tracker ----
        $db->exec('CREATE TABLE IF NOT EXISTS maintenance_tickets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            asset_type TEXT NOT NULL DEFAULT \'game\',
            asset_id TEXT NOT NULL,
            asset_name TEXT NOT NULL,
            title TEXT NOT NULL,
            description TEXT DEFAULT \'\',
            priority TEXT NOT NULL DEFAULT \'medium\',
            status TEXT NOT NULL DEFAULT \'open\',
            assigned_to TEXT DEFAULT \'\',
            reported_by INTEGER,
            resolved_by INTEGER,
            resolution_notes TEXT DEFAULT \'\',
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            resolved_at TEXT DEFAULT NULL,
            due_date TEXT DEFAULT NULL,
            FOREIGN KEY (reported_by) REFERENCES admin_users(id),
            FOREIGN KEY (resolved_by) REFERENCES admin_users(id)
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_maint_status ON maintenance_tickets(status)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_maint_priority ON maintenance_tickets(priority)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_maint_asset ON maintenance_tickets(asset_type, asset_id)');

        // ---- Party / Event Bookings ----
        $db->exec('CREATE TABLE IF NOT EXISTS party_packages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT DEFAULT \'\',
            duration_minutes INTEGER NOT NULL DEFAULT 120,
            max_guests INTEGER NOT NULL DEFAULT 20,
            price_cents INTEGER NOT NULL DEFAULT 0,
            includes_food INTEGER NOT NULL DEFAULT 0,
            includes_drinks INTEGER NOT NULL DEFAULT 0,
            game_credits INTEGER NOT NULL DEFAULT 0,
            ride_passes INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS party_bookings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            package_id INTEGER,
            guest_name TEXT NOT NULL,
            guest_phone TEXT DEFAULT \'\',
            guest_email TEXT DEFAULT \'\',
            party_date TEXT NOT NULL,
            start_time TEXT NOT NULL,
            end_time TEXT NOT NULL,
            guest_count INTEGER NOT NULL DEFAULT 10,
            special_requests TEXT DEFAULT \'\',
            status TEXT NOT NULL DEFAULT \'confirmed\',
            deposit_paid INTEGER NOT NULL DEFAULT 0,
            total_price_cents INTEGER NOT NULL DEFAULT 0,
            assigned_area TEXT DEFAULT \'\',
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            FOREIGN KEY (package_id) REFERENCES party_packages(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES admin_users(id)
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_bookings_date ON party_bookings(party_date)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_bookings_status ON party_bookings(status)');

        // ---- Staff Announcements ----
        $db->exec('CREATE TABLE IF NOT EXISTS announcements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            priority TEXT NOT NULL DEFAULT \'normal\',
            category TEXT NOT NULL DEFAULT \'general\',
            is_pinned INTEGER NOT NULL DEFAULT 0,
            expires_at TEXT DEFAULT NULL,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            FOREIGN KEY (created_by) REFERENCES admin_users(id)
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_announce_pinned ON announcements(is_pinned)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_announce_expires ON announcements(expires_at)');
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
