<?php
/**
 * Minimal Microsoft SQL Server client for the Go-Kart Labor report.
 *
 * Connects to the venue's CenterEdge MSSQL database (LAN) using whichever
 * PDO driver the host PHP actually has, tried in order: sqlsrv (Microsoft,
 * typical on Windows), dblib (FreeTDS, typical on Linux), odbc. Read-only
 * by design: every query must pass a SELECT-only guard before it runs, so a
 * typo'd or malicious admin-entered statement can't mutate the POS database.
 * Use a read-only SQL account anyway — the guard is a seatbelt, not a cage.
 *
 * Connection settings live in api_config (password encrypted via Crypto,
 * same treatment as the CenterEdge API credentials).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';

class MssqlClient
{
    public const CONFIG_KEYS = [
        'mssql_host'     => false, // encrypted?
        'mssql_port'     => false,
        'mssql_database' => false,
        'mssql_username' => false,
        'mssql_password' => true,
    ];

    /** Seconds before a CONNECT/login attempt gives up (fail fast if SQL is down). */
    private const TIMEOUT = 8;

    /**
     * Default per-query timeout (seconds) for interactive report/probe queries.
     * Generous enough for a legitimate year-range GROUP BY, but bounded so a
     * slow/contended query fails fast instead of holding a PHP-FPM worker (five
     * of those saturate the default pool and the whole app stops answering).
     */
    private const QUERY_TIMEOUT = 20;

    /** @var PDO|null */
    private $pdo = null;

    /** @var string Driver actually used (sqlsrv|dblib|odbc). */
    private $driver = '';

    /**
     * Per-instance query timeout (seconds). Defaults to QUERY_TIMEOUT for
     * interactive report/probe queries; a long-running maintenance job (e.g. the
     * one-time card_activity backfill's per-year GROUP BY over PlayerCardTrans,
     * which runs from cron — not a web worker) can raise it via setTimeout()
     * BEFORE the first query. It is enforced as a real query timeout at connect.
     */
    private $timeout = self::QUERY_TIMEOUT;

    /**
     * Which usable PDO MSSQL drivers this PHP build offers, in preference
     * order. Empty array = host needs an extension installed.
     */
    public static function availableDrivers(): array
    {
        $have = PDO::getAvailableDrivers();
        return array_values(array_intersect(['sqlsrv', 'dblib', 'odbc'], $have));
    }

    /** Stored connection settings (password stays encrypted; flag only). */
    public static function settings(): array
    {
        return [
            'host'         => DB::getConfig('mssql_host') ?: '',
            'port'         => DB::getConfig('mssql_port') ?: '1433',
            'database'     => DB::getConfig('mssql_database') ?: 'CenterEdge',
            'username'     => DB::getConfig('mssql_username') ?: '',
            'has_password' => DB::getConfig('mssql_password') !== null && DB::getConfig('mssql_password') !== '',
            'drivers'      => self::availableDrivers(),
        ];
    }

    public static function isConfigured(): bool
    {
        $s = self::settings();
        return $s['host'] !== '' && $s['username'] !== '' && $s['has_password'];
    }

    /**
     * Reject anything that isn't a single plain SELECT. Strips block and
     * line comments first, so a statement hidden behind comment tricks
     * can't sneak past the keyword checks.
     */
    public static function assertReadOnly(string $sql): void
    {
        $clean = preg_replace('!/\*.*?\*/!s', ' ', $sql);
        $clean = preg_replace('/--[^\r\n]*/', ' ', $clean);
        $clean = trim($clean);
        if ($clean === '') {
            throw new RuntimeException('Query is empty.');
        }
        if (strpos($clean, ';') !== false) {
            throw new RuntimeException('Query must be a single statement (no semicolons).');
        }
        if (!preg_match('/^(SELECT|WITH)\b/i', $clean)) {
            throw new RuntimeException('Query must start with SELECT.');
        }
        if (preg_match('/\b(INSERT|UPDATE|DELETE|MERGE|DROP|ALTER|CREATE|TRUNCATE|EXEC|EXECUTE|GRANT|REVOKE|INTO)\b/i', $clean, $m)) {
            throw new RuntimeException('Query contains a forbidden keyword: ' . strtoupper($m[1]));
        }
    }

    /**
     * Substitute the :date placeholder with a validated ISO date literal.
     * Inlining (rather than binding) sidesteps named-parameter quirks in
     * dblib/odbc; the strict format check makes it injection-proof.
     */
    public static function bindDate(string $sql, string $date): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('Invalid date: ' . $date);
        }
        if (strpos($sql, ':date') === false) {
            throw new RuntimeException('Query must contain the :date placeholder.');
        }
        return str_replace(':date', "'" . $date . "'", $sql);
    }

    /**
     * Substitute the :from and :to placeholders with validated ISO date
     * literals (inclusive day range). Same inlining rationale as bindDate.
     */
    public static function bindRange(string $sql, string $from, string $to): string
    {
        foreach (['from' => $from, 'to' => $to] as $name => $val) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                throw new RuntimeException('Invalid date: ' . $val);
            }
            if (strpos($sql, ':' . $name) === false) {
                throw new RuntimeException('Query must contain the :' . $name . ' placeholder.');
            }
        }
        return str_replace([':from', ':to'], ["'" . $from . "'", "'" . $to . "'"], $sql);
    }

    /**
     * Substitute the :cardfrom / :cardto placeholders with validated integer
     * literals (an inclusive card-NUMBER range, for the Promotional Cards
     * report). Card numbers are whole numbers, so these are inlined as bare
     * numeric literals (no quotes) — the strict digit check makes it
     * injection-proof, same rationale as bindDate/bindRange.
     */
    public static function bindCardRange(string $sql, string $from, string $to): string
    {
        foreach (['cardfrom' => $from, 'cardto' => $to] as $name => $val) {
            if (!preg_match('/^\d{1,18}$/', (string)$val)) {
                throw new RuntimeException('Card range bounds must be whole numbers (up to 18 digits).');
            }
            if (strpos($sql, ':' . $name) === false) {
                throw new RuntimeException('Query must contain the :' . $name . ' placeholder.');
            }
        }
        return str_replace([':cardfrom', ':cardto'], [(string)$from, (string)$to], $sql);
    }

    /**
     * Substitute a placeholder with a comma-separated list of validated
     * integer literals — for an `IN (:invnos)` filter over a set the operator
     * picked (the Item Watch page's inventory numbers). Same inlining
     * rationale as bindDate/bindRange/bindCardRange: the strict digit check
     * makes it injection-proof, and an empty list is rejected rather than
     * silently producing `IN ()` (a syntax error) or, worse, an unfiltered scan.
     *
     * @param string   $placeholder e.g. ':invnos' (leading colon optional)
     * @param string[] $values      whole numbers, de-duplicated in place
     */
    public static function bindIntList(string $sql, string $placeholder, array $values): string
    {
        $name = ltrim($placeholder, ':');
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name)) {
            throw new RuntimeException('Invalid placeholder name: ' . $placeholder);
        }
        if (strpos($sql, ':' . $name) === false) {
            throw new RuntimeException('Query must contain the :' . $name . ' placeholder.');
        }
        $clean = [];
        foreach ($values as $v) {
            $s = trim((string)$v);
            if (!preg_match('/^\d{1,18}$/', $s)) {
                throw new RuntimeException('List values must be whole numbers (up to 18 digits).');
            }
            // Normalize leading zeros without an int cast, so the check above
            // stays the only size constraint (0075 and 75 are one value).
            $s = ltrim($s, '0');
            $clean[$s === '' ? '0' : $s] = true;
        }
        if (!$clean) {
            throw new RuntimeException('The :' . $name . ' list cannot be empty.');
        }
        return str_replace(':' . $name, implode(',', array_keys($clean)), $sql);
    }

    public function connect(): void
    {
        if ($this->pdo !== null) {
            return;
        }
        $s = self::settings();
        if (!self::isConfigured()) {
            throw new RuntimeException('MSSQL connection is not configured yet (Settings on the Go-Kart Labor page).');
        }
        $drivers = self::availableDrivers();
        if (!$drivers) {
            throw new RuntimeException(
                'No MSSQL PDO driver is installed in this PHP runtime. On a container host '
                . '(Fedora CoreOS etc.) the driver must be added to the app\'s container image — '
                . 'see docs/MSSQL_DRIVER.md in the repo for a ready-made Containerfile. '
                . 'Bare installs: pdo_sqlsrv (Microsoft), pdo_dblib (FreeTDS), or pdo_odbc.'
            );
        }
        $password = Crypto::decrypt((string)DB::getConfig('mssql_password'));
        $host = $s['host'];
        $port = (string)((int)$s['port'] ?: 1433);
        $db   = $s['database'];

        // Every DSN we're willing to try, in preference order. FreeTDS gets
        // an explicit TDS 7.4 protocol version — without it some builds
        // default to a pre-2008 dialect where DATE/TIME types and
        // CAST(GETDATE() AS DATE) misbehave. ODBC tries msodbcsql 18 then 17.
        $candidates = [];
        foreach ($drivers as $driver) {
            switch ($driver) {
                case 'sqlsrv':
                    $candidates[] = ['sqlsrv',
                        "sqlsrv:Server={$host},{$port};Database={$db};LoginTimeout=" . self::TIMEOUT
                        . ';TrustServerCertificate=1'];
                    break;
                case 'dblib':
                    $candidates[] = ['dblib', "dblib:host={$host}:{$port};dbname={$db};version=7.4;charset=UTF-8"];
                    $candidates[] = ['dblib', "dblib:host={$host}:{$port};dbname={$db}"];
                    break;
                default: // odbc
                    foreach (['18', '17'] as $v) {
                        $candidates[] = ['odbc',
                            'odbc:Driver={ODBC Driver ' . $v . ' for SQL Server};Server=' . $host . ',' . $port
                            . ';Database=' . $db . ';TrustServerCertificate=yes'];
                    }
            }
        }

        $lastError = '';
        foreach ($candidates as [$driver, $dsn]) {
            try {
                // ATTR_TIMEOUT is only a CONNECT/login timeout on most drivers,
                // NOT a query timeout — a slow SELECT can otherwise run for
                // minutes and wedge a PHP-FPM worker (a handful of those and the
                // whole app stops answering). Set the driver-specific *query*
                // timeout too, so any query fails fast instead. Guarded by
                // defined() because the constants exist only for their driver.
                $opts = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => self::TIMEOUT, // connect/login: fail fast if SQL is down
                ];
                if ($driver === 'dblib' && defined('PDO::DBLIB_ATTR_QUERY_TIMEOUT')) {
                    $opts[PDO::DBLIB_ATTR_QUERY_TIMEOUT] = $this->timeout;
                } elseif ($driver === 'sqlsrv' && defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
                    $opts[PDO::SQLSRV_ATTR_QUERY_TIMEOUT] = $this->timeout;
                }
                $pdo = new PDO($dsn, $s['username'], $password, $opts);
                // Belt-and-suspenders: also set it post-connect where the driver
                // accepts it as a settable attribute (ignored if it doesn't).
                if ($driver === 'dblib' && defined('PDO::DBLIB_ATTR_QUERY_TIMEOUT')) {
                    try { $pdo->setAttribute(PDO::DBLIB_ATTR_QUERY_TIMEOUT, $this->timeout); } catch (Exception $e) {}
                } elseif ($driver === 'sqlsrv' && defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
                    try { $pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, $this->timeout); } catch (Exception $e) {}
                }
                $this->pdo = $pdo;
                $this->driver = $driver;
                return;
            } catch (Exception $e) {
                $lastError = '[' . $driver . '] ' . $e->getMessage();
            }
        }
        throw new RuntimeException('Could not connect to MSSQL: ' . $lastError);
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * Raise (or lower) the query timeout for this instance. Must be called
     * before the first query, since the value is applied when the PDO
     * connection is opened. Intended for one-time maintenance jobs; leave the
     * default for interactive report queries.
     */
    public function setTimeout(int $seconds): void
    {
        $this->timeout = max(1, $seconds);
    }

    /**
     * Run a guarded SELECT and return the first column of the first row
     * as a float (NULL → 0.0). The shape every labor/sales query has.
     */
    public function scalar(string $sql): float
    {
        self::assertReadOnly($sql);
        $this->connect();
        $stmt = $this->pdo->query($sql);
        $val = $stmt->fetchColumn();
        return $val === null || $val === false ? 0.0 : (float)$val;
    }

    /**
     * Run a guarded SELECT and return the first column of the first row as
     * a trimmed string (diagnostics: server names, dates, counts).
     */
    public function scalarText(string $sql): string
    {
        self::assertReadOnly($sql);
        $this->connect();
        $val = $this->pdo->query($sql)->fetchColumn();
        return $val === null || $val === false ? '' : trim((string)$val);
    }

    /** Run a guarded SELECT returning up to $limit rows (for the test button). */
    public function rows(string $sql, int $limit = 5): array
    {
        self::assertReadOnly($sql);
        $this->connect();
        $stmt = $this->pdo->query($sql);
        $out = [];
        while (count($out) < $limit && ($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $out[] = $row;
        }
        return $out;
    }
}
