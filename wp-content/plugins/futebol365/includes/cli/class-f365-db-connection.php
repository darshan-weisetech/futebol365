<?php
/**
 * Futebol365 Migration — PostgreSQL Database Connection
 *
 * Dedicated class to manage the PostgreSQL connection and execute queries.
 *
 * @package Futebol365
 * @since   2.1.0
 */

defined('ABSPATH') || exit;

if (!(defined('WP_CLI') && WP_CLI)) {
    return;
}

class F365_DB_Connection
{
    // ── PostgreSQL connection handle ─────────────────────────────────────────
    protected $pg;

    // ── CLI options ──────────────────────────────────────────────────────────
    protected string $pg_host = 'localhost';
    protected string $pg_port = '5432';
    protected string $pg_name = 'futebol365';
    protected string $pg_user = 'postgres';
//    protected string $pg_pass = '0nX63GffEFjKOzs3';
    protected string $pg_pass = '';
    protected bool $dry_run = false;

    // ── Logger instance/callback ─────────────────────────────────────────────
    /**
     * @var callable
     */
    protected $log_callback;

    /**
     * Parse database CLI arguments.
     */
    public function parse_assoc(array $assoc, bool $dry_run = false): void
    {
        $this->pg_host = $assoc['pg-host'] ?? getenv('PG_HOST') ?: 'localhost';
        $this->pg_port = $assoc['pg-port'] ?? getenv('PG_PORT') ?: '5432';
        $this->pg_name = $assoc['pg-name'] ?? getenv('PG_NAME') ?: 'futebol365';
        $this->pg_user = $assoc['pg-user'] ?? getenv('PG_USER') ?: 'postgres';
        $this->pg_pass = $assoc['pg-pass'] ?? getenv('PG_PASSWORD') ?: '';
        $this->dry_run = $dry_run;
    }

    /**
     * Set a callback to handle error logging.
     * The callback should have the signature: function(string $module, string $line)
     */
    public function set_log_callback(callable $callback): void
    {
        $this->log_callback = $callback;
    }

    protected function log(string $module, string $line): void
    {
        if ($this->log_callback) {
            call_user_func($this->log_callback, $module, $line);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // POSTGRESQL CONNECTION
    // ════════════════════════════════════════════════════════════════════════

    public function connect(): void
    {
        if (!extension_loaded('pgsql')) {
            WP_CLI::error('PHP pgsql extension is not loaded. Install php-pgsql.');
        }
        $dsn = sprintf(
            'host=%s port=%s dbname=%s user=%s password=%s',
            $this->pg_host,
            $this->pg_port,
            $this->pg_name,
            $this->pg_user,
            $this->pg_pass
        );
        $this->pg = @pg_connect($dsn);
        if (!$this->pg) {
            WP_CLI::error("Cannot connect to PostgreSQL: {$this->pg_name}@{$this->pg_host}");
        }
        WP_CLI::log("✓ Connected to PostgreSQL: {$this->pg_name}@{$this->pg_host}");
        if ($this->dry_run) {
            WP_CLI::log('⚠ DRY-RUN mode — nothing will be written.');
        }
    }

    public function disconnect(): void
    {
        if ($this->pg) {
            pg_close($this->pg);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // POSTGRESQL QUERY HELPERS
    // ════════════════════════════════════════════════════════════════════════

    public function all(string $sql, array $params = []): array
    {
        $result = $params
            ? pg_query_params($this->pg, $sql, $params)
            : pg_query($this->pg, $sql);
        if (!$result) {
            $err = pg_last_error($this->pg);
            WP_CLI::warning("PG error: $err | SQL: $sql");
            $this->log('pg_errors', "PG_ERROR: $err | SQL: $sql");
            return [];
        }
        return pg_fetch_all($result) ?: [];
    }

    public function val(string $sql, array $params = []): mixed
    {
        $rows = $this->all($sql, $params);
        if (empty($rows[0]))
            return null;
        return $rows[0][array_key_first($rows[0])] ?? null;
    }

    public function ids(string $table, string $fk_col, $fk_val, string $id_col): array
    {
        $rows = $this->all(
            "SELECT $id_col FROM $table WHERE $fk_col = \$1 ORDER BY $id_col",
            [$fk_val]
        );
        return array_column($rows, $id_col);
    }
}
