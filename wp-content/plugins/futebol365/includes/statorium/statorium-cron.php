<?php
/**
 * Statorium — WP-Cron schedules (legacy in-plugin module).
 * Only loads when the f365-statorium plugin is inactive (see loader).
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'cron_schedules', function ( array $schedules ): array {
	$schedules['f365stat_2min'] = [
		'interval' => 120,
		'display'  => 'Every 2 minutes (Statorium live ticker)',
	];
	return $schedules;
} );

function f365_statorium_reschedule_cron(): void {
	$enabled = ! empty( F365_Statorium_Importer::settings()['cron'] );

	if ( $enabled ) {
		if ( ! wp_next_scheduled( 'f365_statorium_hourly' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'f365_statorium_hourly' );
		}
		if ( ! wp_next_scheduled( 'f365_statorium_tick' ) ) {
			wp_schedule_event( time() + 120, 'f365stat_2min', 'f365_statorium_tick' );
		}
	} else {
		wp_clear_scheduled_hook( 'f365_statorium_hourly' );
		wp_clear_scheduled_hook( 'f365_statorium_tick' );
	}
}

add_action( 'f365_statorium_hourly', function () {
	$settings = F365_Statorium_Importer::settings();
	$seasons  = array_filter( array_map( 'intval', explode( ',', (string) $settings['seasons'] ) ) );
	if ( ! $seasons || empty( $settings['api_key'] ) ) {
		return;
	}

	$importer = new F365_Statorium_Importer();
	foreach ( $seasons as $season_id ) {
		$importer->import_matches( $season_id );
	}
} );

add_action( 'f365_statorium_tick', function () {
	$settings = F365_Statorium_Importer::settings();
	if ( empty( $settings['api_key'] ) ) {
		return;
	}

	global $wpdb;
	$due = $wpdb->get_var(
		"SELECT COUNT(*)
		   FROM {$wpdb->postmeta} src
		   JOIN {$wpdb->postmeta} dt ON dt.post_id = src.post_id AND dt.meta_key = 'date_time'
		  WHERE src.meta_key = 'statorium_match_id'
		    AND dt.meta_value BETWEEN DATE_SUB( UTC_TIMESTAMP(), INTERVAL 4 HOUR ) AND DATE_ADD( UTC_TIMESTAMP(), INTERVAL 15 MINUTE )"
	);
	if ( ! $due ) {
		return;
	}

	( new F365_Statorium_Importer() )->sync_live();
} );
