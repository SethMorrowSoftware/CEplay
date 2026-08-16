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
             'Runs the floor and the automation: full analytics with reader CC payments, card lookup, and group/schedule management. No system settings or user management.',
             '["view_dashboard","view_games","view_tags","view_groups","view_kiosks","view_schedules","view_overrides","analytics","view_revenue","cards","manual_control","overrides_manage","groups_manage","reader_groups_manage","promotions_manage","items_manage","schedules_manage","view_logs"]'],
            ['tech', 'Technician',
             'Keeps machines running: pause/unpause, kiosk actions, overrides, and system settings. No payment figures, card lookup, or group/schedule editing.',
             '["view_dashboard","view_games","view_tags","view_groups","view_kiosks","view_schedules","view_overrides","analytics","manual_control","overrides_manage","settings","users"]'],
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

        // Relabel pass: earlier seeds called card-at-reader charges
        // "revenue" / "sales data", which oversells what the play feed
        // actually measures (reader CC payments only — POS sales never
        // reach this app). The seed is INSERT OR IGNORE, so existing
        // installs keep their stored copy; rewrite it only while it still
        // matches the old text verbatim, so an operator's customized
        // description is never touched and the update is naturally
        // idempotent.
        $roleRelabel = [
            ['manager',
             'Runs the floor and the automation: full analytics with revenue, card lookup, and group/schedule management. No system settings or user management.'],
            ['tech',
             'Keeps machines running: pause/unpause, kiosk actions, overrides, and system settings. No sales data, card lookup, or group/schedule editing.'],
        ];
        foreach ($seedRoles as $r) {
            foreach ($roleRelabel as $old) {
                if ($old[0] !== $r[0]) continue;
                try {
                    self::execute(
                        "UPDATE roles SET description = :p0, updated_at = datetime('now')
                         WHERE slug = :p1 AND description = :p2",
                        [$r[2], $r[0], $old[1]]
                    );
                } catch (Exception $e) {
                    error_log('Role description relabel skipped for ' . $r[0] . ': ' . $e->getMessage());
                }
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

        // One-time migration: reader-group management used to ride on the
        // pause-group permission ('groups_manage'). It is now its own
        // catalog key ('reader_groups_manage') so operators can hand out
        // analytics-area editing separately. Grant the new key to every
        // role that already holds groups_manage (or the wildcard), so
        // splitting the key changes nobody's effective access. Flagged so
        // it runs exactly once and never overrides later role edits.
        try {
            $flag = self::queryOne("SELECT value FROM api_config WHERE key = 'migration_reader_groups_manage_v1'");
            if (!$flag) {
                foreach (self::query('SELECT slug, permissions FROM roles') as $rr) {
                    $perms = json_decode((string)$rr['permissions'], true);
                    if (!is_array($perms)) {
                        continue;
                    }
                    if (in_array('*', $perms, true) || in_array('reader_groups_manage', $perms, true)) {
                        continue;
                    }
                    if (in_array('groups_manage', $perms, true)) {
                        $perms[] = 'reader_groups_manage';
                        self::execute(
                            "UPDATE roles SET permissions = :p0, updated_at = datetime('now') WHERE slug = :p1",
                            [json_encode(array_values($perms)), (string)$rr['slug']]
                        );
                    }
                }
                self::execute(
                    "INSERT OR IGNORE INTO api_config (key, value, encrypted) VALUES ('migration_reader_groups_manage_v1', :p0, 0)",
                    [gmdate('c')]
                );
            }
        } catch (Exception $e) {
            error_log('reader_groups_manage migration skipped: ' . $e->getMessage());
        }

        // One-time migration: promotional-card batch management is its own
        // catalog key ('promotions_manage'), split from the analytics-area
        // editing key ('reader_groups_manage') so operators can hand out promo
        // tracking separately. Grant it to every role that already holds
        // reader_groups_manage (or the wildcard) so introducing the key changes
        // nobody's effective access. Flagged so it runs exactly once.
        try {
            $flag = self::queryOne("SELECT value FROM api_config WHERE key = 'migration_promotions_manage_v1'");
            if (!$flag) {
                foreach (self::query('SELECT slug, permissions FROM roles') as $rr) {
                    $perms = json_decode((string)$rr['permissions'], true);
                    if (!is_array($perms)) {
                        continue;
                    }
                    if (in_array('*', $perms, true) || in_array('promotions_manage', $perms, true)) {
                        continue;
                    }
                    if (in_array('reader_groups_manage', $perms, true)) {
                        $perms[] = 'promotions_manage';
                        self::execute(
                            "UPDATE roles SET permissions = :p0, updated_at = datetime('now') WHERE slug = :p1",
                            [json_encode(array_values($perms)), (string)$rr['slug']]
                        );
                    }
                }
                self::execute(
                    "INSERT OR IGNORE INTO api_config (key, value, encrypted) VALUES ('migration_promotions_manage_v1', :p0, 0)",
                    [gmdate('c')]
                );
            }
        } catch (Exception $e) {
            error_log('promotions_manage migration skipped: ' . $e->getMessage());
        }

        // One-time migration: watched items / deals (the Item Watch page) get
        // their own catalog key ('items_manage'). Grant it to every role that
        // already holds promotions_manage (or the wildcard) — the two are the
        // same kind of job, so introducing the key changes nobody's effective
        // access. Flagged so it runs exactly once.
        try {
            $flag = self::queryOne("SELECT value FROM api_config WHERE key = 'migration_items_manage_v1'");
            if (!$flag) {
                foreach (self::query('SELECT slug, permissions FROM roles') as $rr) {
                    $perms = json_decode((string)$rr['permissions'], true);
                    if (!is_array($perms)) {
                        continue;
                    }
                    if (in_array('*', $perms, true) || in_array('items_manage', $perms, true)) {
                        continue;
                    }
                    if (in_array('promotions_manage', $perms, true)) {
                        $perms[] = 'items_manage';
                        self::execute(
                            "UPDATE roles SET permissions = :p0, updated_at = datetime('now') WHERE slug = :p1",
                            [json_encode(array_values($perms)), (string)$rr['slug']]
                        );
                    }
                }
                self::execute(
                    "INSERT OR IGNORE INTO api_config (key, value, encrypted) VALUES ('migration_items_manage_v1', :p0, 0)",
                    [gmdate('c')]
                );
            }
        } catch (Exception $e) {
            error_log('items_manage migration skipped: ' . $e->getMessage());
        }

        // One-time migration: page-visibility keys (view_dashboard,
        // view_games, view_groups, view_kiosks, view_schedules,
        // view_overrides). These pages used to be visible to every signed-in
        // user; now each is a catalog key so any section can be hidden per
        // role. Grant all six to every existing role (system and custom
        // alike, wildcard skipped) so the upgrade changes nobody's effective
        // access — admins then untick to hide. Flagged so it runs once and
        // never overrides later role edits.
        try {
            $flag = self::queryOne("SELECT value FROM api_config WHERE key = 'migration_view_keys_v1'");
            if (!$flag) {
                $viewKeys = ['view_dashboard', 'view_games', 'view_groups', 'view_kiosks', 'view_schedules', 'view_overrides'];
                foreach (self::query('SELECT slug, permissions FROM roles') as $rr) {
                    $perms = json_decode((string)$rr['permissions'], true);
                    if (!is_array($perms) || in_array('*', $perms, true)) {
                        continue;
                    }
                    $missing = array_values(array_diff($viewKeys, $perms));
                    if ($missing) {
                        $perms = array_values(array_merge($perms, $missing));
                        self::execute(
                            "UPDATE roles SET permissions = :p0, updated_at = datetime('now') WHERE slug = :p1",
                            [json_encode($perms), (string)$rr['slug']]
                        );
                    }
                }
                self::execute(
                    "INSERT OR IGNORE INTO api_config (key, value, encrypted) VALUES ('migration_view_keys_v1', :p0, 0)",
                    [gmdate('c')]
                );
            }
        } catch (Exception $e) {
            error_log('view keys migration skipped: ' . $e->getMessage());
        }

        // One-time migration: the Tag Board page gets its own visibility key
        // ('view_tags'). Grant it to every role that already holds view_games —
        // the board is a phone-sized view of the same game list and actions —
        // so introducing the key changes nobody's effective access. Admins can
        // then untick view_games for a floor-staff role to hand out ONLY the
        // Tag Board. Flagged so it runs once and never overrides later edits.
        try {
            $flag = self::queryOne("SELECT value FROM api_config WHERE key = 'migration_view_tags_v1'");
            if (!$flag) {
                foreach (self::query('SELECT slug, permissions FROM roles') as $rr) {
                    $perms = json_decode((string)$rr['permissions'], true);
                    if (!is_array($perms)) {
                        continue;
                    }
                    if (in_array('*', $perms, true) || in_array('view_tags', $perms, true)) {
                        continue;
                    }
                    if (in_array('view_games', $perms, true)) {
                        $perms[] = 'view_tags';
                        self::execute(
                            "UPDATE roles SET permissions = :p0, updated_at = datetime('now') WHERE slug = :p1",
                            [json_encode(array_values($perms)), (string)$rr['slug']]
                        );
                    }
                }
                // Mirror the per-user grant/deny overrides too, in BOTH
                // directions: a user explicitly DENIED the Games page must
                // not regain the game list through the new key their role
                // just gained, and a user individually GRANTED view_games
                // (role without it) keeps seeing the same game data. Either
                // way the upgrade changes nobody's effective access.
                self::execute(
                    "INSERT OR IGNORE INTO user_permission_overrides (user_id, permission_key, effect)
                     SELECT user_id, 'view_tags', effect FROM user_permission_overrides
                     WHERE permission_key = 'view_games'"
                );
                self::execute(
                    "INSERT OR IGNORE INTO api_config (key, value, encrypted) VALUES ('migration_view_tags_v1', :p0, 0)",
                    [gmdate('c')]
                );
            }
        } catch (Exception $e) {
            error_log('view_tags migration skipped: ' . $e->getMessage());
        }

        // One-time: stamp the Go-Kart Labor queries with the venue's
        // confirmed-working Grafana query (CatNo 108 sales, 'Go-Karts' job
        // join for labor). While the filters were being pinned down, interim
        // variants (facility-wide placeholder, wrong category) got SAVED
        // through the UI — and the on-page "Reset to defaults" can only
        // restore whatever the DEPLOYED code ships, so a stale install kept
        // resurrecting bad queries. This overwrites the stored pair exactly
        // once so a plain update.sh makes the page correct with no UI steps;
        // admin edits AFTER this migration are respected forever.
        // NOTE: v1-v7 below seed/repair the LEGACY config keys `labor_sales_sql`
        // / `labor_labor_sql`, which the live Labor page no longer reads
        // (laborQueries() uses `labor_sales_range_sql` / `labor_labor_range_sql`).
        // They are kept only for historical idempotence on the venue install.
        // The default values come from LABOR_DEFAULT_*_RANGE_SQL in api/labor.php;
        // referencing a non-existent constant here throws an Error (not an
        // Exception) on PHP 8, so every block catches Throwable to keep a config
        // slip from ever bricking initSchema().
        try {
            $flag = self::queryOne("SELECT value FROM api_config WHERE key = 'migration_labor_karting_v1'");
            if (!$flag) {
                require_once __DIR__ . '/../api/labor.php';
                self::execute(
                    "INSERT OR REPLACE INTO api_config (key, value, encrypted) VALUES ('labor_sales_sql', :p0, 0)",
                    [LABOR_DEFAULT_SALES_RANGE_SQL]
                );
                self::execute(
                    "INSERT OR REPLACE INTO api_config (key, value, encrypted) VALUES ('labor_labor_sql', :p0, 0)",
                    [LABOR_DEFAULT_LABOR_RANGE_SQL]
                );
                self::execute(
                    "INSERT OR IGNORE INTO api_config (key, value, encrypted) VALUES ('migration_labor_karting_v1', :p0, 0)",
                    [gmdate('c')]
                );
            }
        } catch (Throwable $e) {
            error_log('labor query migration skipped: ' . $e->getMessage());
        }

        // Follow-up to the migration above: v1 stamped the sales query with
        // an equality date filter, which reads ZERO when ShiftDate carries a
        // time-of-day (observed live). Replace it with the half-open range
        // form — but ONLY if the stored query is still exactly the v1 text,
        // so a hand-tuned query is never clobbered.
        try {
            $flag = self::queryOne("SELECT value FROM api_config WHERE key = 'migration_labor_karting_v2'");
            if (!$flag) {
                require_once __DIR__ . '/../api/labor.php';
                $v1Sales = "SELECT COALESCE(SUM(AmtSold), 0)\nFROM [CenterEdge].[dbo].[Sales]\nWHERE CatNo = 108  /* go-kart sales category */\n  AND ShiftDate = :date";
                $stored = self::queryOne("SELECT value FROM api_config WHERE key = 'labor_sales_sql'");
                if ($stored && trim((string)$stored['value']) === trim($v1Sales)) {
                    self::execute(
                        "INSERT OR REPLACE INTO api_config (key, value, encrypted) VALUES ('labor_sales_sql', :p0, 0)",
                        [LABOR_DEFAULT_SALES_RANGE_SQL]
                    );
                }
                self::execute(
                    "INSERT OR IGNORE INTO api_config (key, value, encrypted) VALUES ('migration_labor_karting_v2', :p0, 0)",
                    [gmdate('c')]
                );
            }
        } catch (Throwable $e) {
            error_log('labor query v2 migration skipped: ' . $e->getMessage());
        }

        // v3: the live connection fingerprint proved go-kart sales live under
        // CatNo 106 (ride-pattern money; 108 reads zero). Upgrade the stored
        // sales query only if it is still exactly a shipped 108 variant —
        // hand-tuned queries are never clobbered.
        try {
            $flag = self::queryOne("SELECT value FROM api_config WHERE key = 'migration_labor_karting_v3'");
            if (!$flag) {
                require_once __DIR__ . '/../api/labor.php';
                $shipped108 = [
                    // v2-stamped range form
                    "SELECT COALESCE(SUM(AmtSold), 0)\nFROM [CenterEdge].[dbo].[Sales]\nWHERE CatNo = 108  /* go-kart sales category */\n  AND ShiftDate >= :date\n  AND ShiftDate < DATEADD(DAY, 1, :date)",
                    // v1-stamped equality form
                    "SELECT COALESCE(SUM(AmtSold), 0)\nFROM [CenterEdge].[dbo].[Sales]\nWHERE CatNo = 108  /* go-kart sales category */\n  AND ShiftDate = :date",
                ];
                $stored = self::queryOne("SELECT value FROM api_config WHERE key = 'labor_sales_sql'");
                if ($stored && in_array(trim((string)$stored['value']), array_map('trim', $shipped108), true)) {
                    self::execute(
                        "INSERT OR REPLACE INTO api_config (key, value, encrypted) VALUES ('labor_sales_sql', :p0, 0)",
                        [LABOR_DEFAULT_SALES_RANGE_SQL]
                    );
                }
                self::execute(
                    "INSERT OR IGNORE INTO api_config (key, value, encrypted) VALUES ('migration_labor_karting_v3', :p0, 0)",
                    [gmdate('c')]
                );
            }
        } catch (Throwable $e) {
            error_log('labor query v3 migration skipped: ' . $e->getMessage());
        }

        // v4: the category-name fingerprint proved 106 = Beverages and
        // 108 = Go Karts with rides posting at AmtSold = 0 (paid at the
        // card reader; no value column exists — Discounts probe ruled it
        // out). The default now counts cash lines as cash and values $0
        // ride lines at QtySold × per-ride price (editable in the query).
        // Upgrade the stored sales query only if it is exactly the shipped
        // v3 (CatNo 106) text; hand-tuned queries are never touched.
        try {
            $flag = self::queryOne("SELECT value FROM api_config WHERE key = 'migration_labor_karting_v4'");
            if (!$flag) {
                require_once __DIR__ . '/../api/labor.php';
                $v3Sales = "SELECT COALESCE(SUM(AmtSold), 0)\nFROM [CenterEdge].[dbo].[Sales]\nWHERE CatNo = 106  /* go-kart sales category */\n  AND ShiftDate >= :date\n  AND ShiftDate < DATEADD(DAY, 1, :date)";
                $stored = self::queryOne("SELECT value FROM api_config WHERE key = 'labor_sales_sql'");
                if ($stored && trim((string)$stored['value']) === trim($v3Sales)) {
                    self::execute(
                        "INSERT OR REPLACE INTO api_config (key, value, encrypted) VALUES ('labor_sales_sql', :p0, 0)",
                        [LABOR_DEFAULT_SALES_RANGE_SQL]
                    );
                }
                self::execute(
                    "INSERT OR IGNORE INTO api_config (key, value, encrypted) VALUES ('migration_labor_karting_v4', :p0, 0)",
                    [gmdate('c')]
                );
            }
        } catch (Throwable $e) {
            error_log('labor query v4 migration skipped: ' . $e->getMessage());
        }

        // v5: ride valuation moved out of SQL — the MSSQL query now carries
        // walk-up cash only, and paid rides (kart swipes MINUS time-pass
        // swipes, which the Sales table cannot distinguish but the app's own
        // reader feed can) are valued at a configurable per-ride price.
        // Upgrade the stored sales query only if it is exactly the shipped
        // v4 (rides-in-SQL) text; hand-tuned queries are never touched.
        try {
            $flag = self::queryOne("SELECT value FROM api_config WHERE key = 'migration_labor_karting_v5'");
            if (!$flag) {
                require_once __DIR__ . '/../api/labor.php';
                $v4Sales = "SELECT COALESCE(SUM(CASE\n    WHEN AmtSold > 0 THEN AmtSold  /* walk-up cash: count the actual cash */\n    /* card-swiped rides post \$0 — value each ride here (EDIT 11.00 to your per-ride price) */\n    ELSE QtySold * 11.00\n  END), 0)\nFROM [CenterEdge].[dbo].[Sales]\nWHERE CatNo = 108  /* Go Karts */\n  AND ShiftDate >= :date\n  AND ShiftDate < DATEADD(DAY, 1, :date)";
                $stored = self::queryOne("SELECT value FROM api_config WHERE key = 'labor_sales_sql'");
                if ($stored && trim((string)$stored['value']) === trim($v4Sales)) {
                    self::execute(
                        "INSERT OR REPLACE INTO api_config (key, value, encrypted) VALUES ('labor_sales_sql', :p0, 0)",
                        [LABOR_DEFAULT_SALES_RANGE_SQL]
                    );
                }
                self::execute(
                    "INSERT OR IGNORE INTO api_config (key, value, encrypted) VALUES ('migration_labor_karting_v5', :p0, 0)",
                    [gmdate('c')]
                );
            }
        } catch (Throwable $e) {
            error_log('labor query v5 migration skipped: ' . $e->getMessage());
        }

        // v6: the division dump found the real bucket — DivNo 808
        // "Go Kart Readers" books the actual dollars spent at the kart
        // readers (one aggregated posting per day). Upgrade the stored
        // sales query only if it is exactly the shipped v5 (cash-only
        // CatNo 108) text; hand-tuned queries are never touched. (The
        // rides × price add-on this migration mentioned has since been
        // removed outright — sales are always the recorded DivNo-808
        // dollars, so there is no estimate left to double count.)
        try {
            $flag = self::queryOne("SELECT value FROM api_config WHERE key = 'migration_labor_karting_v6'");
            if (!$flag) {
                require_once __DIR__ . '/../api/labor.php';
                $v5Sales = "SELECT COALESCE(SUM(CASE WHEN AmtSold > 0 THEN AmtSold ELSE 0 END), 0)\nFROM [CenterEdge].[dbo].[Sales]\nWHERE CatNo = 108  /* Go Karts: walk-up cash only; rides are valued from the reader feed */\n  AND ShiftDate >= :date\n  AND ShiftDate < DATEADD(DAY, 1, :date)";
                $stored = self::queryOne("SELECT value FROM api_config WHERE key = 'labor_sales_sql'");
                if ($stored && trim((string)$stored['value']) === trim($v5Sales)) {
                    self::execute(
                        "INSERT OR REPLACE INTO api_config (key, value, encrypted) VALUES ('labor_sales_sql', :p0, 0)",
                        [LABOR_DEFAULT_SALES_RANGE_SQL]
                    );
                }
                self::execute(
                    "INSERT OR IGNORE INTO api_config (key, value, encrypted) VALUES ('migration_labor_karting_v6', :p0, 0)",
                    [gmdate('c')]
                );
            }
        } catch (Throwable $e) {
            error_log('labor query v6 migration skipped: ' . $e->getMessage());
        }

        // v7: FORCE-stamp the sales query with the DivNo 808 default. The
        // exact-text-match migrations (v1–v6) kept "respecting" queries the
        // operator pasted from chat during the debugging marathon — those
        // differ from shipped defaults only by comment lines, but looked
        // hand-tuned, so the stale Beverages (CatNo 106) query survived
        // every upgrade and the page kept showing drink money. Every saved
        // query on this install originated from chat instructions, never a
        // deliberate customization, so one unconditional stamp is correct —
        // and edits made AFTER this migration are respected again (the flag
        // makes it run exactly once). Labor SQL is untouched: it is verified
        // correct and live.
        try {
            $flag = self::queryOne("SELECT value FROM api_config WHERE key = 'migration_labor_karting_v7'");
            if (!$flag) {
                require_once __DIR__ . '/../api/labor.php';
                self::execute(
                    "INSERT OR REPLACE INTO api_config (key, value, encrypted) VALUES ('labor_sales_sql', :p0, 0)",
                    [LABOR_DEFAULT_SALES_RANGE_SQL]
                );
                self::execute(
                    "INSERT OR IGNORE INTO api_config (key, value, encrypted) VALUES ('migration_labor_karting_v7', :p0, 0)",
                    [gmdate('c')]
                );
            }
        } catch (Throwable $e) {
            error_log('labor query v7 migration skipped: ' . $e->getMessage());
        }

        // One-time cleanup: the Go-Kart Labor "estimate mode" (paid rides ×
        // per-track price added to sales) was removed — the page reports the
        // recorded DivNo-808 dollars and nothing else. Drop its orphaned config
        // rows so a stale `labor_add_ride_value = 1` can't look meaningful to
        // whoever reads api_config next. `labor_reader_group_id` stays: it still
        // selects which area's swipes get COUNTED.
        try {
            $flag = self::queryOne("SELECT value FROM api_config WHERE key = 'migration_labor_drop_ride_estimate_v1'");
            if (!$flag) {
                self::execute(
                    "DELETE FROM api_config WHERE key IN ('labor_add_ride_value', 'labor_price_per_ride', 'labor_ride_prices')"
                );
                self::execute(
                    "INSERT OR IGNORE INTO api_config (key, value, encrypted) VALUES ('migration_labor_drop_ride_estimate_v1', :p0, 0)",
                    [gmdate('c')]
                );
            }
        } catch (Throwable $e) {
            error_log('labor ride-estimate cleanup skipped: ' . $e->getMessage());
        }

        // One-time seed of a "Viewer" role: read-only access to everything
        // that can be read (analytics incl. reader CC figures, card lookup,
        // action log) with no operate/manage/settings keys, so the venue can
        // hand out dashboards without handing out buttons. Seeded as a
        // NORMAL custom role (is_system = 0) so it stays fully editable and
        // even deletable in Settings → Roles; the flag (not INSERT OR
        // IGNORE) guarantees a deliberately deleted or renamed Viewer role
        // is never resurrected on the next boot.
        try {
            $flag = self::queryOne("SELECT value FROM api_config WHERE key = 'seed_viewer_role_v1'");
            if (!$flag) {
                self::execute(
                    'INSERT OR IGNORE INTO roles (slug, name, description, permissions, is_system) VALUES (:p0, :p1, :p2, :p3, 0)',
                    ['viewer', 'Viewer',
                     'Sees everything, changes nothing: full analytics and reporting, card lookup, and the action log — no floor controls, no group/schedule editing, no settings.',
                     '["view_dashboard","view_games","view_tags","view_groups","view_kiosks","view_schedules","view_overrides","analytics","view_revenue","cards","view_logs"]']
                );
                self::execute(
                    "INSERT OR IGNORE INTO api_config (key, value, encrypted) VALUES ('seed_viewer_role_v1', :p0, 0)",
                    [gmdate('c')]
                );
            }
        } catch (Exception $e) {
            error_log('Viewer role seed skipped: ' . $e->getMessage());
        }

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
            time_plays INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            PRIMARY KEY (stat_date, game_id)
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_gds_date ON game_daily_stats(stat_date)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_gds_game ON game_daily_stats(game_id)');

        // Migration: per-day time-pass play counts, powering the analytics
        // "include/exclude time-pass plays" toggle at day grain. Rows rolled
        // up before this column existed keep 0 (the raw rows are long
        // purged, so the split is unknowable for them); the recompute window
        // fills the recent ~28 days on the next nightly run and everything
        // after. time_plays_daily_since records where trustworthy day-grain
        // splits begin, so the UI can say so instead of implying the toggle
        // rewrites deep history.
        try {
            $db->exec('ALTER TABLE game_daily_stats ADD COLUMN time_plays INTEGER NOT NULL DEFAULT 0');
        } catch (Exception $e) {
            // Column already exists — ignore
        }
        try {
            $flag = self::queryOne("SELECT value FROM api_config WHERE key = 'time_plays_daily_since'");
            if (!$flag) {
                // The recompute window reaches ~28 days back from first run.
                self::execute(
                    "INSERT OR IGNORE INTO api_config (key, value, encrypted) VALUES ('time_plays_daily_since', :p0, 0)",
                    [gmdate('Y-m-d', strtotime('-28 days'))]
                );
            }
        } catch (Exception $e) {
            error_log('time_plays_daily_since stamp skipped: ' . $e->getMessage());
        }

        // Permanent per-game, per-hour rollup — same lifecycle as
        // game_daily_stats (recomputed nightly from the raw feed by
        // Scheduler::rollupDailyStats() BEFORE the purge) but at hour grain.
        // Powers the Reader Groups day-of-week × hour-of-day heatmaps
        // ("when is this area busiest?") for windows older than the raw
        // feed's 30-day retention. stat_date/hour are venue-local so cells
        // line up with the wall clock. time_plays is carried so time-pass
        // usage can be reported per area. Retention is bounded (~400 days,
        // see Scheduler::purgeOldData) — enough for year-over-year staffing
        // comparisons without unbounded growth.
        $db->exec('CREATE TABLE IF NOT EXISTS game_hourly_stats (
            stat_date TEXT NOT NULL,
            hour INTEGER NOT NULL,
            game_id TEXT NOT NULL,
            plays INTEGER NOT NULL DEFAULT 0,
            tickets REAL NOT NULL DEFAULT 0,
            cash REAL NOT NULL DEFAULT 0,
            time_plays INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            PRIMARY KEY (stat_date, hour, game_id)
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ghs_date ON game_hourly_stats(stat_date)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ghs_game ON game_hourly_stats(game_id)');
        // Composite for readerHourlyRows(): filters a small set of member
        // game_ids over a date range (a Year covers the full ~400-day rollup),
        // so seek by game_id then range-scan stat_date instead of scanning the
        // whole venue's hourly rows and discarding non-members in PHP.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ghs_game_date ON game_hourly_stats(game_id, stat_date)');

        // Venue-wide daily rollup — the deep-history source for the Analytics
        // OVERVIEW (plays / value played / tickets earned / unique cards per
        // local day). Unlike game_daily_stats, this is NOT per-game and carries
        // REAL money/tickets historically: it's aggregated straight from the POS
        // ledger (PlayerCardTrans) — plays + value from TransType 1 (deductions
        // at readers), tickets from all ValueNo 3, distinct cards that played —
        // none of which depend on the per-game rdrkey mapping. Backfilled once
        // ~2 decades deep and refreshed nightly for the trailing window, both
        // from cron (never a web request). ~one row/day, so a range read is
        // inherently tiny (<=370 rows).
        $db->exec('CREATE TABLE IF NOT EXISTS venue_daily_stats (
            stat_date TEXT NOT NULL,
            plays INTEGER NOT NULL DEFAULT 0,
            value REAL NOT NULL DEFAULT 0,
            tickets REAL NOT NULL DEFAULT 0,
            unique_cards INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            PRIMARY KEY (stat_date)
        )');

        // Reader groups: operator-defined groupings of games/readers used
        // ONLY for analytics (staffing heatmaps, per-area play averages).
        // Deliberately separate from pause_groups — these never pause
        // anything, and a game may belong to any number of reader groups
        // (e.g. "Redemption Wall" and "Front Room" can overlap).
        $db->exec('CREATE TABLE IF NOT EXISTS reader_groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT \'\',
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS reader_group_games (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reader_group_id INTEGER NOT NULL,
            game_id TEXT NOT NULL,
            game_name TEXT NOT NULL DEFAULT \'\',
            FOREIGN KEY (reader_group_id) REFERENCES reader_groups(id) ON DELETE CASCADE,
            UNIQUE (reader_group_id, game_id)
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_rgg_group ON reader_group_games(reader_group_id)');

        // Promotional card batches: operator-defined BLOCKS of giveaway cards,
        // each a contiguous card-number range (card_from .. card_to). Used ONLY
        // for analytics — they never pause anything. Each batch records the
        // campaign details the operator knows (name, date handed out, starting
        // value per card, notes); every performance number (how many came back,
        // reloads, plays, tickets, value) is computed LIVE from the CenterEdge
        // POS card ledger by card-number range, so nothing is duplicated here.
        $db->exec('CREATE TABLE IF NOT EXISTS promo_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            card_from TEXT NOT NULL,
            card_to TEXT NOT NULL,
            giveaway_date TEXT NOT NULL DEFAULT \'\',
            initial_value REAL NOT NULL DEFAULT 0,
            notes TEXT NOT NULL DEFAULT \'\',
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');

        // Watched items / deals (the Item Watch page): operator-pinned POS
        // inventory items, each entry a SET of InvNo values (one item, or every
        // InvNo that makes up a deal). Analytics only — they never pause
        // anything. This table holds ONLY what the operator knows (name, the
        // inventory numbers, an optional grouping tag, the deal's run dates,
        // notes); every sales number (units, dollars, discounts, cost, margin,
        // trend) is computed LIVE from the CenterEdge POS `Sales` table by
        // InvNo, so nothing is duplicated here.
        $db->exec('CREATE TABLE IF NOT EXISTS watch_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            inv_nos TEXT NOT NULL,
            tag TEXT NOT NULL DEFAULT \'\',
            start_date TEXT NOT NULL DEFAULT \'\',
            end_date TEXT NOT NULL DEFAULT \'\',
            notes TEXT NOT NULL DEFAULT \'\',
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');

        // Durable per-card activity ledger for Guest Insights. The raw play
        // feed (game_play_transactions) is only kept ~30 days, so it cannot
        // tell whether a card seen today is genuinely new or a returning
        // guest whose first visit was months ago. This table records each
        // card\'s first- and last-seen local date permanently. Both columns
        // are monotonic (first = MIN, last = MAX), so Scheduler::
        // rollupCardActivity() can recompute from the raw window every night
        // and only ever widen the range — the recompute is idempotent and
        // self-correcting, needing no cursor bookkeeping. Card numbers
        // "000000" (credit-card/cardless plays) and "" are never recorded.
        $db->exec('CREATE TABLE IF NOT EXISTS card_activity (
            card_number TEXT PRIMARY KEY,
            first_seen_date TEXT NOT NULL,
            last_seen_date TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_card_activity_first ON card_activity(first_seen_date)');

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
