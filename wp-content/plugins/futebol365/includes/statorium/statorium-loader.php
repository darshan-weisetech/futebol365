<?php
/**
 * Statorium integration — legacy in-plugin module (loader).
 *
 * The ACTIVE Statorium integration lives in the dedicated plugin
 * `f365-statorium` (wp-content/plugins/f365-statorium). This module is the
 * original in-plugin build, kept as a fallback and as a workspace for
 * futebol365-specific old/new data logic.
 *
 * Guard: when the f365-statorium plugin is active its classes are already
 * defined (plugins load alphabetically, f365-statorium first) and this module
 * steps aside completely — no class/function collisions. Deactivate the
 * plugin and this module takes over automatically.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

// Permalink Manager must never manage the entity CPTs (numeric rewrite URLs).
// Registered here too so the rule holds even with the f365-statorium plugin
// deactivated. Duplicate registration alongside the plugin is harmless.
add_filter( 'permalink_manager_disabled_post_types', function ( $types ) {
	return array_merge( (array) $types, [ 'f365_competition', 'f365_team', 'f365_player', 'f365_match', 'f365_stadium', 'f365_livegames' ] );
} );

if ( class_exists( 'F365_Statorium_Importer' ) ) {
	// f365-statorium plugin is active — it owns the integration.
	define( 'F365_STATORIUM_LEGACY_MODULE_STANDBY', true );
	return;
}

require_once __DIR__ . '/class-f365-statorium-api.php';
require_once __DIR__ . '/class-f365-statorium-importer.php';
require_once __DIR__ . '/statorium-cron.php';

if ( is_admin() ) {
	require_once __DIR__ . '/statorium-admin.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/class-f365-statorium-command.php';
}
