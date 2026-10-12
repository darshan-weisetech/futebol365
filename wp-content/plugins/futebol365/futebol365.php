<?php
/**
 * Plugin Name: Futebol365
 * Plugin URI:  https://futebol365.pt
 * Description: Futebol365 — football portal custom post types, taxonomies, ACF field groups, Yoast SEO, and WP-CLI migration from PostgreSQL.
 * Version:     2.0.0
 * Author:      Futebol365
 * Text Domain: futebol365
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'F365_VERSION',    '2.0.0' );
define( 'F365_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'F365_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'F365_LOGS_DIR',   F365_PLUGIN_DIR . 'logs/' );

// ── Core CPT / Taxonomy / Rewrite ───────────────────────────────────────────
require_once F365_PLUGIN_DIR . 'includes/cpt/register-cpt.php';
require_once F365_PLUGIN_DIR . 'includes/cpt/register-taxonomies.php';
require_once F365_PLUGIN_DIR . 'includes/cpt/register-rewrite-rules.php';
require_once F365_PLUGIN_DIR . 'includes/cli/video-flag-feature.php';
// Legacy Statorium module — stands by automatically while the f365-statorium
// plugin is active; takes over if that plugin is deactivated.
require_once F365_PLUGIN_DIR . 'includes/statorium/statorium-loader.php';

// ── Admin columns ────────────────────────────────────────────────────────────
//require_once F365_PLUGIN_DIR . 'includes/admin/admin-columns.php';

// ── Yoast SEO integration ────────────────────────────────────────────────────
require_once F365_PLUGIN_DIR . 'includes/seo/yoast-seo.php';

// ── Sondagem (poll) module: CPT + rewrite + status meta box + [custom_poll] ──


// ── ACF Field Groups ─────────────────────────────────────────────────────────
if ( function_exists( 'acf_add_local_field_group' ) ) {
    // Existing field groups
    require_once F365_PLUGIN_DIR . 'includes/acf/acf-team.php';
    require_once F365_PLUGIN_DIR . 'includes/acf/acf-person.php';
    require_once F365_PLUGIN_DIR . 'includes/acf/acf-competition.php';
    require_once F365_PLUGIN_DIR . 'includes/acf/acf-federation.php';
    require_once F365_PLUGIN_DIR . 'includes/acf/acf-stadium.php';
    require_once F365_PLUGIN_DIR . 'includes/acf/acf-transfer.php';
    require_once F365_PLUGIN_DIR . 'includes/acf/acf-multimedia.php';
    // New field groups for missing modules
    require_once F365_PLUGIN_DIR . 'includes/acf/acf-newsstand.php';
    require_once F365_PLUGIN_DIR . 'includes/acf/acf-live-streaming.php';
    require_once F365_PLUGIN_DIR . 'includes/acf/acf-ranking.php';
    require_once F365_PLUGIN_DIR . 'includes/acf/acf-news-extended.php';
    require_once F365_PLUGIN_DIR . 'includes/acf/acf-author.php';
    require_once F365_PLUGIN_DIR . 'includes/acf/acf-country.php';
    require_once F365_PLUGIN_DIR . 'includes/acf/acf-livegames.php';
    require_once F365_PLUGIN_DIR . 'includes/acf/acf-sondagem.php';
} else {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>Futebol365:</strong> Advanced Custom Fields (ACF) plugin is required.</p></div>';
    } );
}

// ── WP-CLI Migration Command (only loaded in CLI context) ────────────────────
// A single entry-point file loads the base, all module traits, and registers
// the command exactly once. No WP_CLI::has_command() is used anywhere.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once F365_PLUGIN_DIR . 'includes/cli/class-f365-migrate-command.php';
}

// ── Logs directory creation on activation ────────────────────────────────────
register_activation_hook( __FILE__, 'f365_activate' );
function f365_activate(): void {
    f365_register_cpt();
    f365_register_taxonomies();
    flush_rewrite_rules();

    // Ensure logs directory exists with .htaccess protection
    if ( ! is_dir( F365_LOGS_DIR ) ) {
        wp_mkdir_p( F365_LOGS_DIR );
        file_put_contents( F365_LOGS_DIR . '.htaccess', 'deny from all' );
    }
}

register_deactivation_hook( __FILE__, static function (): void {
    flush_rewrite_rules();
} );

