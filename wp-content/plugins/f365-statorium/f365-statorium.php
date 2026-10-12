<?php
/**
 * Plugin Name:       F365 Statorium Sync
 * Description:       Statorium Stats API integration for Futebol365 — historical backfill, entity matching against existing data, delta sync via the /updates endpoint, configurable cron. API key comes from the F365_STATORIUM_API_KEY constant in wp-config.php.
 * Version:           1.0.0
 * Author:            Weisetech
 * Requires PHP:      8.1
 * Text Domain:       f365-statorium
 */

defined( 'ABSPATH' ) || exit;

define( 'F365_STAT_VERSION', '1.0.0' );
define( 'F365_STAT_DIR', plugin_dir_path( __FILE__ ) );

/*
 * Permalink Manager must never manage the legacy entity CPTs — their URLs are
 * numeric rewrite rules (/jogador/{id}/ …) and legacy posts carry no PM URIs.
 * Without this, PM auto-generates slug URIs (jogador/salem-abdullah) for every
 * created post and canonical-redirects the numeric URLs away. Registered
 * before the collision guard so it applies in every load order.
 */
add_filter( 'permalink_manager_disabled_post_types', function ( $types ) {
	return array_merge( (array) $types, [ 'f365_competition', 'f365_team', 'f365_player', 'f365_match', 'f365_stadium', 'f365_livegames' ] );
} );

/*
 * Load-order collision guard.
 *
 * The futebol365 plugin ships a legacy fallback copy of this integration
 * (includes/statorium/) that loads itself whenever these classes are absent.
 * On every NORMAL request this plugin loads first (plugins load
 * alphabetically) and the fallback stands aside. But on the request that
 * ACTIVATES this plugin while futebol365 is already active, the active
 * plugins load before this file is included — the fallback has already
 * defined the same class names, and redeclaring them here would fatal.
 *
 * In that one request the legacy module simply keeps ownership; the real
 * activation tasks are flagged and executed on the next request, when this
 * plugin loads first. No manual deactivate/reactivate dance needed.
 */
if ( class_exists( 'F365_Statorium_Importer', false ) ) {
	update_option( 'f365_statorium_pending_activation', 1, false );
	return;
}

require_once F365_STAT_DIR . 'includes/class-api.php';
require_once F365_STAT_DIR . 'includes/class-matcher.php';
require_once F365_STAT_DIR . 'includes/leagues.php';
require_once F365_STAT_DIR . 'includes/class-tables.php';
require_once F365_STAT_DIR . 'includes/ticker.php';
require_once F365_STAT_DIR . 'includes/class-importer.php';
require_once F365_STAT_DIR . 'includes/class-backfill.php';
require_once F365_STAT_DIR . 'includes/class-updates.php';
require_once F365_STAT_DIR . 'includes/cron.php';

if ( is_admin() ) {
	require_once F365_STAT_DIR . 'includes/admin.php';
	require_once F365_STAT_DIR . 'includes/admin-data.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once F365_STAT_DIR . 'includes/class-command.php';
}

// "Settings" shortcut on the Plugins list screen.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( array $links ): array {
	array_unshift(
		$links,
		'<a href="' . esc_url( admin_url( 'options-general.php?page=f365-statorium' ) ) . '">Settings</a>'
	);
	return $links;
} );

function f365_statorium_activate(): void {
	F365_Statorium_Importer::ensure_schema();

	// Persist the shipped defaults on first activation so a fresh install
	// (clone/new environment) carries the full production configuration.
	if ( false === get_option( F365_Statorium_Importer::OPTION, false ) ) {
		update_option( F365_Statorium_Importer::OPTION, F365_Statorium_Importer::settings(), false );
	}

	f365_statorium_reschedule_cron();
}

register_activation_hook( __FILE__, 'f365_statorium_activate' );

// Activation tasks deferred by the collision guard above run here, on the
// first request where this plugin loads first and owns its classes.
add_action( 'init', function () {
	if ( get_option( 'f365_statorium_pending_activation' ) ) {
		delete_option( 'f365_statorium_pending_activation' );
		f365_statorium_activate();
	}
}, 5 );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'f365_statorium_sync' );
	wp_clear_scheduled_hook( 'f365_statorium_tick' );
} );
