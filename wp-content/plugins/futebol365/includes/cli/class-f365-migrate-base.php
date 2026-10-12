<?php
/**
 * Futebol365 Migration — Abstract Base
 *
 * Contains all shared state, PostgreSQL helpers, WordPress helpers,
 * the ID-map system, and the logging system used by every module.
 *
 * IMPORTANT: This file never calls WP_CLI::add_command(). That is done
 * exactly once in class-f365-migrate-command.php after all modules load.
 *
 * @package Futebol365
 * @since   2.1.0
 */

defined('ABSPATH') || exit;

if (!(defined('WP_CLI') && WP_CLI)) {
    return;
}

abstract class F365_Migrate_Base
{

    // ── Database Connection ──────────────────────────────────────────────────
    protected F365_DB_Connection $db;

    // ── In-memory ID map cache (django_id → wp_id per type) ─────────────────
    protected array $map_cache = [];

    // ── CLI options ──────────────────────────────────────────────────────────
    protected int $batch = 500;
    protected int $offset = 0;
    protected int $limit = PHP_INT_MAX;
    protected bool $dry_run = false;
    protected bool $force = false;
    protected bool $dirty = false;
    protected array $assoc_args = [];

    // ── Logging ──────────────────────────────────────────────────────────────
    // One file handle per module, opened on first log() call for that module.
    // All handles are opened in the same run (same timestamp stamp).
    /** @var array<string, resource> */
    private array $log_handles = [];
    private string $logs_dir = '';
    private string $run_stamp = '';

    // ════════════════════════════════════════════════════════════════════════
    // OPTION PARSING
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Parse standard CLI flags into object properties.
     * Also initialises the logging system (directory + run timestamp).
     * Call this at the top of __invoke().
     */
    protected function parse_assoc(array $assoc): void
    {
        $this->assoc_args = $assoc;
        $this->dry_run = isset($assoc['dry-run']);

        $this->db = new F365_DB_Connection();
        $this->db->parse_assoc($assoc, $this->dry_run);
        $this->db->set_log_callback(function ($module, $line) {
            $this->log($module, $line);
        });
        $this->batch = (int) ($assoc['batch'] ?? 500);
        $this->offset = (int) ($assoc['offset'] ?? 0);
        $this->limit = isset($assoc['limit']) ? (int) $assoc['limit'] : PHP_INT_MAX;
        $this->dry_run = isset($assoc['dry-run']);
        $this->force = isset($assoc['force']);
        $this->yes = isset($assoc['yes']);
        $this->dirty = isset($assoc['dirty']);

        $this->init_logging();
    }

    // ════════════════════════════════════════════════════════════════════════
    // LOGGING SYSTEM
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Initialise the log directory and run timestamp.
     *
     * Called once from parse_assoc() so the directory is guaranteed to exist
     * before any module runs — even when the plugin was never activated
     * (common in CLI-only workflows where activation hooks never fire).
     *
     * Directory preference order:
     *   1. F365_LOGS_DIR constant (set by main plugin file inside plugin dir)
     *   2. wp-content/uploads/f365-logs/  (always writable fallback)
     */
    private function init_logging(): void
    {
        $this->run_stamp = date('Y-m-d-His');

        if (defined('F365_LOGS_DIR')) {
            $dir = F365_LOGS_DIR;
        } else {
            $upload = wp_upload_dir();
            $dir = trailingslashit($upload['basedir']) . 'f365-logs/';
        }

        // Create directory if absent — works on first CLI run with no activation
        if (!is_dir($dir)) {
            $created = wp_mkdir_p($dir);
            if (!$created) {
                WP_CLI::warning("Could not create log directory: $dir — logs will go to WP debug log only.");
                $this->logs_dir = '';
                return;
            }
            // Protect from direct HTTP access
            file_put_contents($dir . '.htaccess', "Deny from all\n");
            file_put_contents($dir . 'index.php', "<?php // Silence\n");
        }

        if (!is_writable($dir)) {
            WP_CLI::warning("Log directory not writable: $dir — logs will go to WP debug log only.");
            $this->logs_dir = '';
            return;
        }

        $this->logs_dir = $dir;
        WP_CLI::log("✓ Logs directory: {$this->logs_dir}");
    }

    /**
     * Write a line to the per-module log file.
     *
     * Opens the file on the first call for each module (O_APPEND, so concurrent
     * runs don't truncate each other). The file is named:
     *   f365-{module}-{YYYY-MM-DD-HHmmss}.log
     *
     * Also echoes FAIL/IMAGE_FAIL lines as WP_CLI warnings.
     *
     * Usage inside any module method:
     *   $this->log( 'polls', "OK poll_id=42 → wp_id=999" );
     *   $this->log( 'polls', "FAIL poll_id=99 — insert_post returned 0" );
     *   $this->log( 'polls', "SKIP poll_id=7 — already imported" );
     *   $this->log( 'polls', "IMAGE_FAIL poll_id=42 — download_url timed out" );
     *   $this->log( 'polls', "SUMMARY: imported=100 skipped=5 failed=2" );
     */
    protected function log(string $module, string $line): void
    {
        $entry = '[' . date('H:i:s') . "] $line\n";

        // Write to file if logging is available
        if ($this->logs_dir) {
            if (!isset($this->log_handles[$module])) {
                $path = $this->logs_dir . 'f365-' . $module . '-' . $this->run_stamp . '.log';
                $fh = fopen($path, 'a');
                if ($fh === false) {
                    WP_CLI::warning("Cannot open log file: $path");
                    // Store false so we don't retry every line
                    $this->log_handles[$module] = false;
                } else {
                    // Write header on first open
                    fwrite($fh, "=== F365 Migration: $module | " . date('Y-m-d H:i:s') . " ===\n");
                    $this->log_handles[$module] = $fh;
                    WP_CLI::log("  Log: $path");
                }
            }

            if ($this->log_handles[$module]) {
                fwrite($this->log_handles[$module], $entry);
            }
        }

        // Always mirror failures to WP-CLI stderr
        $prefix = strtok($line, ' ');
        if ($prefix === 'FAIL') {
            WP_CLI::warning("[$module] $line");
        }
    }

    /**
     * Close all open log file handles. Call this at the end of __invoke().
     */
    protected function close_logs(): void
    {
        foreach ($this->log_handles as $module => $fh) {
            if ($fh) {
                fwrite($fh, "=== END " . date('Y-m-d H:i:s') . " ===\n");
                fclose($fh);
            }
        }
        $this->log_handles = [];
    }

    // ════════════════════════════════════════════════════════════════════════
    // UTILITY: PostgreSQL boolean parsing
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Convert a PostgreSQL boolean value ('t', 'true', '1', 'y', 'yes') to PHP bool.
     */
    protected function parse_pg_bool( $val ): bool {
        if ( $val === null ) {
            return false;
        }
        if ( is_bool( $val ) ) {
            return $val;
        }
        $s = strtolower( trim( (string) $val ) );
        return in_array( $s, [ 't', 'true', '1', 'y', 'yes' ], true );
    }

    // ════════════════════════════════════════════════════════════════════════
    // POSTGRESQL CONNECTION
    // ════════════════════════════════════════════════════════════════════════

    protected function pg_connect(): void
    {
        $this->db->connect();
    }

    protected function pg_disconnect(): void
    {
        $this->db->disconnect();
    }

    // ════════════════════════════════════════════════════════════════════════
    // POSTGRESQL QUERY HELPERS
    // ════════════════════════════════════════════════════════════════════════

    protected function pg_all(string $sql, array $params = []): array
    {
        return $this->db->all($sql, $params);
    }

    protected function pg_val(string $sql, array $params = []): mixed
    {
        return $this->db->val($sql, $params);
    }

    protected function pg_ids(string $table, string $fk_col, $fk_val, string $id_col): array
    {
        return $this->db->ids($table, $fk_col, $fk_val, $id_col);
    }

    // ════════════════════════════════════════════════════════════════════════
    // WORDPRESS INSERTION / META HELPERS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Insert a WP post, preserving original PostgreSQL dates.
     * Returns WP post ID (int > 0) on success, 0 on failure.
     */
    protected function insert_post(array $args): int
    {
        if ($this->dry_run)
            return rand(99000, 99999);

        $data = array_merge([
            'post_author' => 1,
            'post_status' => 'publish',
            'post_content' => '',
            'post_name' => '',
            'post_parent' => 0,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ], $args);

        // Normalise post_date — preserve original PostgreSQL timestamp
        if (!empty($data['post_date'])) {
            $ts = strtotime($data['post_date']);
            if ($ts) {
                $data['post_date'] = date('Y-m-d H:i:s', $ts);
                if (empty($data['post_modified'])) {
                    $data['post_modified'] = $data['post_date'];
                }
                $data['post_date_gmt'] = get_gmt_from_date($data['post_date']);
                if (empty($data['post_modified_gmt'])) {
                    $data['post_modified_gmt'] = $data['post_date_gmt'];
                }
            } else {
                unset($data['post_date']);
            }
        } else {
            unset($data['post_date']);
        }

        // Normalise post_modified if explicitly provided
        if (!empty($data['post_modified'])) {
            $ts_mod = strtotime($data['post_modified']);
            if ($ts_mod) {
                $data['post_modified'] = date('Y-m-d H:i:s', $ts_mod);
                $data['post_modified_gmt'] = get_gmt_from_date($data['post_modified']);
            }
        }

        if (empty($data['post_name']))
            unset($data['post_name']);

        $wp_id = wp_insert_post($data, true);
        if (is_wp_error($wp_id)) {
            WP_CLI::warning('wp_insert_post error: ' . $wp_id->get_error_message() . ' | ' . wp_json_encode($args));
            return 0;
        }

        // Force post_modified and post_modified_gmt in the database to prevent WP core overrides
        if (!empty($data['post_modified']) && !$this->dry_run) {
            global $wpdb;
            $wpdb->update(
                $wpdb->posts,
                [
                    'post_modified'     => $data['post_modified'],
                    'post_modified_gmt' => $data['post_modified_gmt'],
                ],
                [ 'ID' => $wp_id ]
            );
        }

        return (int) $wp_id;
    }

    /**
     * Bulk-set post meta, skipping null/empty values.
     * ACF repeater arrays are stored serialised; their count key is also set.
     */
    protected function set_meta(int $wp_id, array $meta): void
    {
        if ($this->dry_run || !$wp_id)
            return;
        foreach ($meta as $key => $value) {
            if ($value === null || $value === '')
                continue;
            update_post_meta($wp_id, $key, $value);
            if (is_array($value) && isset($value[0]) && is_array($value[0])) {
                update_post_meta($wp_id, '_' . $key . '_count', count($value));
            }
        }
    }

    /**
     * Assign taxonomy terms by name. Creates missing terms automatically.
     */
    protected function set_terms(int $wp_id, array $names, string $taxonomy): void
    {
        if ($this->dry_run || !$wp_id)
            return;
        $term_ids = array_filter(array_map(
            fn($name) => $this->ensure_term(trim((string) $name), $taxonomy),
            $names
        ));
        if ($term_ids) {
            wp_set_object_terms($wp_id, array_values($term_ids), $taxonomy, false);
        }
    }

    /**
     * Get or create a taxonomy term. Returns term_id, or 0 on failure.
     */
    protected function ensure_term(string $name, string $taxonomy, string $slug = ''): int
    {
        if (!$name)
            return 0;
        
        $slug = $slug ?: sanitize_title($name);

        // 1. Try to find by name
        $existing = get_term_by('name', $name, $taxonomy);
        if ($existing && !is_wp_error($existing)) {
            return (int) $existing->term_id;
        }

        // 2. Try to find by slug (to prevent duplicate slugs in DB)
        if ($slug) {
            $existing_slug = get_term_by('slug', $slug, $taxonomy);
            if ($existing_slug && !is_wp_error($existing_slug)) {
                return (int) $existing_slug->term_id;
            }
        }

        // 3. If not found, insert
        $result = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        if (is_wp_error($result)) {
            $by_slug = get_term_by('slug', $slug, $taxonomy);
            if ($by_slug) {
                return (int) $by_slug->term_id;
            }
            WP_CLI::warning("Term insert failed: $name / $taxonomy — " . $result->get_error_message());
            return 0;
        }
        return (int) $result['term_id'];
    }

    /**
     * Resolve one django FK to a WP post ID and write it as postmeta.
     */
    protected function resolve_single(int $wp_id, string $meta_key, string $dj_meta_key, array $map): void
    {
        if ($this->dry_run)
            return;
        $dj_id = (int) get_post_meta($wp_id, $dj_meta_key, true);
        if (!$dj_id)
            return;
        $resolved = $map[(string) $dj_id] ?? null;
        if ($resolved)
            update_post_meta($wp_id, $meta_key, (int) $resolved);
    }

    /**
     * Resolve an array of django IDs to WP post IDs and write as postmeta.
     */
    protected function resolve_array(int $wp_id, string $meta_key, string $dj_meta_key, array $map): void
    {
        if ($this->dry_run)
            return;
        $raw = get_post_meta($wp_id, $dj_meta_key, true);
        if (!$raw)
            return;
        $dj_ids = is_array($raw) ? $raw : (array) json_decode($raw, true);
        $wp_ids = array_values(array_filter(
            array_map(fn($d) => $map[(string) (int) $d] ?? null, $dj_ids)
        ));
        if ($wp_ids)
            update_post_meta($wp_id, $meta_key, $wp_ids);
    }

    /**
     * Return all WP post IDs for a given post type (excludes auto-drafts).
     */
    protected function get_all_posts_of_type(string $post_type): array
    {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'auto-draft'",
            $post_type
        ));
    }

    // ════════════════════════════════════════════════════════════════════════
    // ID MAP  (django_id → wp_id, persisted in wp_options)
    // ════════════════════════════════════════════════════════════════════════

    protected function already_imported(string $type, $django_id): bool
    {
        return $this->map_resolve($type, $django_id) !== null;
    }

    protected function map_get(string $type): array
    {
        if (!isset($this->map_cache[$type])) {
            $val = get_option("f365_mig_{$type}", '{}');
            if (is_array($val)) {
                $this->map_cache[$type] = $val;          // already decoded, use as-is
            } else {
                $decoded = json_decode((string) $val, true);
                $this->map_cache[$type] = is_array($decoded) ? $decoded : [];
            }
        }
        return $this->map_cache[$type];
    }

    protected function map_save(string $type): void
    {
        if ($this->dry_run)
            return;
        update_option("f365_mig_{$type}", wp_json_encode($this->map_cache[$type] ?? []), false);
    }

    protected function map_set(string $type, $django_id, int $wp_id): void
    {
        $map = $this->map_get($type);
        $key = is_numeric($django_id) ? (string) (int) $django_id : (string) $django_id;
        $map[$key] = $wp_id;
        $this->map_cache[$type] = $map;
    }

    protected function map_resolve(string $type, $django_id): ?int
    {
        if ($django_id === null || $django_id === '' || $django_id === 0)
            return null;
        $key = is_numeric($django_id) ? (string) (int) $django_id : (string) $django_id;
        $map = $this->map_get($type);
        return isset($map[$key]) ? (int) $map[$key] : null;
    }

    // ════════════════════════════════════════════════════════════════════════
    // UTILITIES
    // ════════════════════════════════════════════════════════════════════════

    /** Normalise a PG date value to Y-m-d for ACF date_picker. */
    protected function d(string $v): string
    {
        if (!$v)
            return '';
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : '';
    }

    /**
     * Sideload a remote image into the WP media library.
     * Returns attachment ID or 0. Skips if already sideloaded (_source_url match).
     */
    protected function sideload_image( string $url, int $parent_id = 0, string $title = '', string $module = '', array $post_data = [] ): int
    {
        if (!$url || str_contains($url, 'no-image.jpg'))
            return 0;

        global $wpdb;

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_source_url' AND meta_value=%s LIMIT 1",
            $url
        ));
        if ($existing)
            return (int) $existing;

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Disable SSL verification for local dev environment downloads
        $ssl_filter = function ($args) {
            $args['sslverify'] = false;
            return $args;
        };
        add_filter('http_request_args', $ssl_filter);
        $tmp = download_url($url);
        remove_filter('http_request_args', $ssl_filter);

        if (is_wp_error($tmp)) {
            $response = wp_remote_get($url, [
                'timeout'            => 300,
                'sslverify'          => false,
                'reject_unsafe_urls' => false,
            ]);
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $body = wp_remote_retrieve_body($response);
                $tmp_file = wp_tempnam($url);
                if ($tmp_file && file_put_contents($tmp_file, $body) !== false) {
                    $tmp = $tmp_file;
                }
            }
        }

        if (is_wp_error($tmp)) {
            $msg = "IMAGE_FAIL download failed: $url — " . $tmp->get_error_message();
            if ( $parent_id ) {
                $msg .= " for post_id={$parent_id}";
            }
            $this->log($module, $msg);
            return 0;
        }

        $file_array = [
            'name' => basename(parse_url($url, PHP_URL_PATH)) ?: 'image.jpg',
            'tmp_name' => $tmp,
        ];

        if ( ! empty( $title ) && ! isset( $post_data['post_title'] ) ) {
            $post_data['post_title'] = $title;
        }

        $att_id = media_handle_sideload($file_array, $parent_id, $title, $post_data);
        @unlink($tmp);

        if (is_wp_error($att_id)) {
            $msg = "IMAGE_FAIL sideload failed: $url — " . $att_id->get_error_message();
            if ( $parent_id ) {
                $msg .= " for post_id={$parent_id}";
            }
            $this->log($module, $msg);
            return 0;
        }

        update_post_meta($att_id, '_source_url', $url);
        return (int) $att_id;
    }

    /**
     * Build absolute image URL from a relative Django media path.
     * Uses DJANGO_MEDIA_URL env var, falling back to media.futebol365.pt.
     */
    protected function media_url(string $path): string
    {
        if (!$path)
            return '';
        if (str_starts_with($path, 'http'))
            return $path;
        $base = rtrim(getenv('DJANGO_MEDIA_URL') ?: 'https://media.futebol365.pt', '/');
        return $base . '/' . ltrim($path, '/');
    }
}
