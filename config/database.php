<?php
/**
 * Beauty-php-ai — Database connection
 * Configuration for the MySQL / MariaDB database.
 */

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');

/**
 * Live database name. Anything that mutates data must never default to this.
 */
define('DB_LIVE_NAME', 'beauty_php_ai');

/**
 * BEAUTY_DB_NAME lets the CLI test suite point at a throwaway database
 * (e.g. a fresh-install copy) without editing this file.
 *
 * Safety rule added after a test run silently wrote to the live database:
 * when BEAUTY_DB_NAME is set we also require BEAUTY_DB_ALLOW_WRITE=1 before
 * any write-capable connection is handed out. A test that forgets the second
 * variable gets a read-only handle instead of damaging live data, and a
 * missing/incorrect BEAUTY_DB_NAME cannot fall back to the live database
 * during a write because writes are blocked outright.
 */
define('DB_NAME', getenv('BEAUTY_DB_NAME') ?: DB_LIVE_NAME);
define('DB_WRITES_ENABLED', getenv('BEAUTY_DB_ALLOW_WRITE') === '1');

/**
 * True when this process is allowed to modify data.
 * Web requests against the live site are always allowed; CLI tooling must
 * opt in explicitly so that a test harness cannot corrupt live records.
 */
function db_writes_allowed(): bool
{
    if (PHP_SAPI === 'cli') {
        return DB_WRITES_ENABLED;
    }

    return true;
}

/**
 * Refuse a write against the live database from the CLI unless explicitly
 * permitted. Called by every write helper as a last line of defence.
 *
 * @throws RuntimeException
 */
function db_guard_write(string $operation = 'write'): void
{
    if (db_writes_allowed()) {
        return;
    }

    $msg = sprintf(
        'Blocked %s: CLI processes may only write when BEAUTY_DB_ALLOW_WRITE=1. '
        . 'Current database is "%s". Refusing to modify live data.',
        $operation,
        DB_NAME
    );

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg . PHP_EOL);
        exit(3);
    }

    throw new RuntimeException($msg);
}

/**
 * Returns a shared PDO connection (singleton).
 *
 * @return PDO
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                $options
            );
        } catch (PDOException $e) {
            http_response_code(500);
            exit('Database connection failed. Please check config/database.php and make sure MySQL is running.');
        }

        /*
         * Server-enforced read-only mode.
         *
         * db_guard_write() is opt-in: a helper that forgets to call it, or a
         * raw db()->exec(...), would previously write happily. That is exactly
         * how a CLI run reached the live database. So the refusal now lives in
         * the database SERVER (a read-only session), which rejects INSERT,
         * UPDATE, DELETE, DROP and ALTER alike, regardless of what the calling
         * code intended.
         *
         * Web requests are unaffected and remain writable, so the real site
         * keeps working. Only the CLI must present the explicit write token.
         */
        if (PHP_SAPI === 'cli' && !db_writes_allowed()) {
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');

            fwrite(STDERR, sprintf(
                '[db] "%s" opened READ-ONLY (CLI without BEAUTY_DB_ALLOW_WRITE=1). '
                . 'Writes are refused by the server.' . PHP_EOL,
                DB_NAME
            ));
        }
    }

    return $pdo;
}