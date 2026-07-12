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
            role TEXT NOT NULL DEFAULT \'admin\',
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');

        // Migration: existing installations created the table without the role
        // column. Adding it idempotently — pre-existing users default to
        // 'admin' so no one loses access during the upgrade.
        try {
            $db->exec('ALTER TABLE admin_users ADD COLUMN role TEXT NOT NULL DEFAULT \'admin\'');
        } catch (Exception $e) {
            // Column already exists — ignore
        }
        // Defensive: any rows that somehow ended up with NULL/empty role
        // (e.g. from a partial earlier migration) are normalized to 'admin'.
        try {
            $db->exec("UPDATE admin_users SET role = 'admin' WHERE role IS NULL OR role = ''");
        } catch (Exception $e) {
            // Ignore if table is empty or column doesn't exist yet
        }

        $db->exec('CREATE TABLE IF NOT EXISTS api_config (
            key TEXT PRIMARY KEY,
            value TEXT,
            encrypted INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');

        // Role definitions. The three system roles (admin/manager/tech) are
        // seeded once with INSERT OR IGNORE so an operator\'s later permission
        // edits survive restarts; custom roles are added via /api/roles.
        // permissions is a JSON array of keys from Auth::PERMISSIONS. The
        // admin role\'s stored list is ignored — admin bypasses permission
        // checks in code and is locked against editing/deletion so an
        // installation can never lock itself out.
        $db->exec('CREATE TABLE IF NOT EXISTS roles (
            slug TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT \'\',
            permissions TEXT NOT NULL DEFAULT \'[]\',
            is_system INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');
        // Seeds mirror the pre-RBAC hardcoded ACCESS_MATRIX + money scrub, so
        // upgrading changes nobody\'s effective access.
        $seedRoles = [
            ['admin', 'Administrator', 'Full access to every feature, including settings, user and role management.',
             '["*"]'],
            ['manager', 'Manager',
             'Runs the floor and the automation: full analytics with revenue, card lookup, and group/schedule management. No system settings or user management.',
             '["analytics","view_revenue","cards","manual_control","overrides_manage","groups_manage","schedules_manage","view_logs"]'],
            ['tech', 'Technician',
             'Keeps machines running: pause/unpause, kiosk actions, overrides, and system settings. No sales data, card lookup, or group/schedule editing.',
             '["analytics","manual_control","overrides_manage","settings","users"]'],
        ];
        foreach ($seedRoles as $r) {
            try {
                $stmt = $db->prepare('INSERT OR IGNORE INTO roles (slug, name, description, permissions, is_system)
                                      VALUES (:p0, :p1, :p2, :p3, 1)');
                $stmt->bindValue(':p0', $r[0], SQLITE3_TEXT);
                $stmt->bindValue(':p1', $r[1], SQLITE3_TEXT);
                $stmt->bindValue(':p2', $r[2], SQLITE3_TEXT);
                $stmt->bindValue(':p3', $r[3], SQLITE3_TEXT);
                $stmt->execute();
                $stmt->close();
            } catch (Exception $e) {
                error_log('Role seed failed for ' . $r[0] . ': ' . $e->getMessage());
            }
        }

        // Per-user permission overrides: a grant/deny layer on TOP of the
        // user's role. Effective permissions = (role perms UNION grants) MINUS
        // denies, resolved in Auth::permissionsForUser(). Admin-role accounts
        // are absolute and ignore denies. One row per (user, permission);
        // ON DELETE CASCADE cleans up when the user is removed.
        $db->exec('CREATE TABLE IF NOT EXISTS user_permission_overrides (
            user_id INTEGER NOT NULL,
            permission_key TEXT NOT NULL,
            effect TEXT NOT NULL CHECK (effect IN (\'grant\', \'deny\')),
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            PRIMARY KEY (user_id, permission_key),
            FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_upo_user ON user_permission_overrides(user_id)');

        // One-time migration: the Action Log (GET /api/logs) used to be
        // readable by ANY authenticated user, leaking card numbers, usernames,
        // and IPs. It is now gated by the new 'view_logs' permission. To avoid
        // yanking access from people who could already see that data, grant
        // 'view_logs' to every role that already holds 'cards' (they can
        // already view card numbers, so this is no new exposure). Roles without
        // card access (e.g. tech) correctly lose the incidental log visibility.
        // Guarded by a flag so it runs exactly once and never overrides an
        // admin who later edits the role in Settings.
        try {
            $flag = self::queryOne("SELECT value FROM api_config WHERE key = 'migration_view_logs_v1'");
            if (!$flag) {
                foreach (self::query('SELECT slug, permissions FROM roles') as $rr) {
                    $perms = json_decode((string)$rr['permissions'], true);
                    if (!is_array($perms)) {
                        continue;
                    }
                    if (in_array('*', $perms, true) || in_array('view_logs', $perms, true)) {
                        continue; // wildcard already covers it, or already granted
                    }
                    if (in_array('cards', $perms, true)) {
                        $perms[] = 'view_logs';
                        self::execute(
                            "UPDATE roles SET permissions = :p0, updated_at = datetime('now') WHERE slug = :p1",
                            [json_encode(array_values($perms)), (string)$rr['slug']]
                        );
                    }
                }
                self::execute(
                    "INSERT OR IGNORE INTO api_config (key, value, encrypted) VALUES ('migration_view_logs_v1', :p0, 0)",
                    [gmdate('c')]
                );
            }
        } catch (Exception $e) {
            error_log('view_logs migration skipped: ' . $e->getMessage());
        }

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

        // Migration: cache supportedActions + virtualPlayEnabled so the game
        // detail modal can render from the cache. The live GET /games/{id}
        // endpoint is OPTIONAL per spec (capabilities.games.getSingleGame)
        // and many card systems 404 it — persisting these here means game
        // detail works on every system, not just ones that support it.
        foreach ([
            'ALTER TABLE game_state_cache ADD COLUMN supported_actions TEXT DEFAULT \'[]\'',
            'ALTER TABLE game_state_cache ADD COLUMN virtual_play_enabled INTEGER NOT NULL DEFAULT 0',
        ] as $gscMigration) {
            try {
                $db->exec($gscMigration);
            } catch (Exception $e) {
                // Column already exists — ignore
            }
        }

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

        // Pending retries for game/kiosk pause/unpause actions that failed
        // because the asset was in use (or any other transient per-asset
        // error). The watchdog re-attempts these once per cycle and gives up
        // after max_attempts. UNIQUE(asset_type, asset_id) means a newer
        // intent supersedes any older pending retry via UPSERT.
        $db->exec('CREATE TABLE IF NOT EXISTS action_retries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            asset_type TEXT NOT NULL,
            asset_id TEXT NOT NULL,
            desired_status TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT \'unknown\',
            pause_group_id INTEGER,
            attempts INTEGER NOT NULL DEFAULT 0,
            max_attempts INTEGER NOT NULL DEFAULT 10,
            last_attempted_at TEXT,
            last_error TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            UNIQUE(asset_type, asset_id),
            FOREIGN KEY (pause_group_id) REFERENCES pause_groups(id) ON DELETE SET NULL
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ar_asset ON action_retries(asset_type, asset_id)');

        $db->exec('CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL,
            attempted_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_attempts_ip ON login_attempts(ip_address)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_attempts_time ON login_attempts(attempted_at)');

        // Local cache of the CenterEdge game-play transaction stream. We poll
        // /games/transactions on the watchdog cron and append into this table
        // using INSERT OR IGNORE on transaction_id so duplicate fetches are
        // safe. This powers the live activity feed and top-games widget.
        // The checkpoint (last processed transaction ID per feed) is stored
        // in api_config under "game_tx_last_id_<feedName>".
        $db->exec('CREATE TABLE IF NOT EXISTS game_play_transactions (
            transaction_id INTEGER NOT NULL,
            feed_name TEXT NOT NULL DEFAULT \'default\',
            card_number TEXT NOT NULL DEFAULT \'\',
            type TEXT NOT NULL DEFAULT \'\',
            game_id TEXT NOT NULL DEFAULT \'\',
            game_description TEXT DEFAULT \'\',
            transaction_time TEXT NOT NULL DEFAULT \'\',
            regular_points REAL NOT NULL DEFAULT 0,
            bonus_points REAL NOT NULL DEFAULT 0,
            redemption_tickets REAL NOT NULL DEFAULT 0,
            cash_amount REAL NOT NULL DEFAULT 0,
            used_time_play INTEGER NOT NULL DEFAULT 0,
            used_play_privilege INTEGER NOT NULL DEFAULT 0,
            raw_payload TEXT,
            fetched_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            PRIMARY KEY (feed_name, transaction_id)
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_gpt_time ON game_play_transactions(transaction_time DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_gpt_game ON game_play_transactions(game_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_gpt_card ON game_play_transactions(card_number)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_gpt_fetched ON game_play_transactions(fetched_at DESC)');

        // Migration: direct credit-card payment fields on the play feed
        // (amount.creditCard + creditCardDetails brand/last-4 per spec).
        // Older builds dropped these at ingest, under-counting revenue for
        // plays paid by bank card at the reader. Cardholder name / approval
        // code are deliberately NOT given columns — they live only in
        // raw_payload, which no API endpoint returns.
        foreach ([
            'ALTER TABLE game_play_transactions ADD COLUMN credit_card_amount REAL NOT NULL DEFAULT 0',
            "ALTER TABLE game_play_transactions ADD COLUMN cc_card_type TEXT NOT NULL DEFAULT ''",
            "ALTER TABLE game_play_transactions ADD COLUMN cc_last4 TEXT NOT NULL DEFAULT ''",
        ] as $gptMigration) {
            try {
                $db->exec($gptMigration);
            } catch (Exception $e) {
                // Column already exists — ignore
            }
        }

        // One-time migration: rewrite transaction_time to canonical UTC
        // "YYYY-MM-DDTHH:MM:SSZ". CenterEdge delivers these with a local
        // offset (e.g. "...T15:00:00.000-04:00") and older builds stored that
        // verbatim, but every hour/today/week window compares the column
        // lexically against a cutoff string — mixed formats made the
        // dashboard's last-hour stats come back empty. New rows are
        // normalized at ingest (CenterEdgeClient::normalizeTransactionTime);
        // this pass fixes rows cached before the upgrade. SQLite's datetime()
        // understands both "Z" and "±HH:MM" suffixes, and re-normalizing an
        // already-normalized row is a no-op, so this is idempotent. Flagged
        // so it runs exactly once.
        try {
            $flag = self::queryOne("SELECT value FROM api_config WHERE key = 'migration_gpt_time_utc_v1'");
            if (!$flag) {
                $db->exec("UPDATE game_play_transactions
                           SET transaction_time = strftime('%Y-%m-%dT%H:%M:%SZ', transaction_time)
                           WHERE transaction_time != ''
                             AND datetime(transaction_time) IS NOT NULL");
                self::execute(
                    "INSERT OR IGNORE INTO api_config (key, value, encrypted) VALUES ('migration_gpt_time_utc_v1', :p0, 0)",
                    [gmdate('c')]
                );
            }
        } catch (Exception $e) {
            error_log('transaction_time UTC migration skipped: ' . $e->getMessage());
        }

        // Local cache of the CenterEdge system-transaction feed: card merges
        // and value expirations initiated inside the card system itself.
        // Polled by the watchdog when capabilities report
        // systemTransactionReporting.isSupported, with the checkpoint stored
        // in api_config under "system_tx_last_id". Expired value from
        // removeValue adjustments is flattened into columns so breakage
        // reporting is a plain SUM; the full payload stays in raw_payload.
        $db->exec('CREATE TABLE IF NOT EXISTS system_transactions (
            transaction_id INTEGER PRIMARY KEY,
            type TEXT NOT NULL DEFAULT \'\',
            transaction_time TEXT NOT NULL DEFAULT \'\',
            card_number TEXT NOT NULL DEFAULT \'\',
            source_card_number TEXT NOT NULL DEFAULT \'\',
            destination_card_number TEXT NOT NULL DEFAULT \'\',
            expired_regular REAL NOT NULL DEFAULT 0,
            expired_bonus REAL NOT NULL DEFAULT 0,
            expired_tickets REAL NOT NULL DEFAULT 0,
            is_wiped INTEGER NOT NULL DEFAULT 0,
            raw_payload TEXT,
            fetched_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_systx_time ON system_transactions(transaction_time DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_systx_type ON system_transactions(type)');

        // Permanent per-game, per-day rollup of the raw play feed. The raw
        // game_play_transactions table is a short rolling window (see the
        // purge in Scheduler::purgeOldData) so the live feed stays cheap, but
        // longer-term reporting (by month / by year) needs history that
        // outlives that window. Scheduler::rollupDailyStats() recomputes each
        // recent local day from the raw feed and UPSERTs it here BEFORE the
        // raw rows are purged, so these summaries are retained indefinitely.
        // stat_date is the venue-local calendar date (YYYY-MM-DD) the plays
        // fell on, matching how the analytics/performance pages bin time.
        $db->exec('CREATE TABLE IF NOT EXISTS game_daily_stats (
            stat_date TEXT NOT NULL,
            game_id TEXT NOT NULL,
            game_name TEXT NOT NULL DEFAULT \'\',
            plays INTEGER NOT NULL DEFAULT 0,
            tickets REAL NOT NULL DEFAULT 0,
            cash REAL NOT NULL DEFAULT 0,
            regular_points REAL NOT NULL DEFAULT 0,
            bonus_points REAL NOT NULL DEFAULT 0,
            unique_cards INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            PRIMARY KEY (stat_date, game_id)
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_gds_date ON game_daily_stats(stat_date)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_gds_game ON game_daily_stats(game_id)');

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
     *
     * $success + $pauseGroupId are populated so the Action Log page's own
     * success and group filters work for administrative events too — the
     * older version hard-coded success=1 and never set pause_group_id, so
     * failures and group-scoped admin actions were invisible to those filters.
     */
    public static function auditLog(
        string $source,
        string $action,
        ?int $userId = null,
        ?array $details = null,
        bool $success = true,
        ?int $pauseGroupId = null
    ): void {
        try {
            $payload = $details ?? [];
            if ($userId !== null) {
                $payload['actor_user_id'] = $userId;
            }
            self::execute(
                'INSERT INTO action_log (source, action, pause_group_id, success, details)
                 VALUES (:p0, :p1, :p2, :p3, :p4)',
                [
                    $source,
                    $action,
                    $pauseGroupId,
                    $success ? 1 : 0,
                    !empty($payload) ? json_encode($payload) : null,
                ]
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
