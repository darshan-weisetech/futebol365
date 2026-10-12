<?php
/**
 * WP-CLI command: wp f365-statorium <subcommand> (legacy in-plugin module).
 * Only loads when the f365-statorium plugin is inactive (see loader).
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

class F365_Statorium_Command {

	public function __invoke( array $args, array $assoc ): void {
		$sub      = $args[0] ?? 'status';
		$season   = (int) ( $assoc['season'] ?? 0 );
		$importer = new F365_Statorium_Importer( $assoc['api-key'] ?? null );

		switch ( $sub ) {
			case 'leagues':
				$data = $importer->api()->leagues();
				if ( is_wp_error( $data ) ) {
					WP_CLI::error( $data->get_error_message() );
				}
				foreach ( (array) ( $data['leagues'] ?? [] ) as $l ) {
					WP_CLI::log( sprintf( '%5d  %s', $l['id'], $l['name'] ) );
					$detail = $importer->api()->get( '/leagues/' . (int) $l['id'] . '/' );
					foreach ( (array) ( $detail['league']['seasons'] ?? [] ) as $s ) {
						WP_CLI::log( sprintf( '       season %-6d %s', $s['seasonID'], $s['seasonName'] ) );
					}
				}
				break;

			case 'season':
				$this->need_season( $season );
				$wp_id = $importer->import_season( $season );
				$wp_id ? WP_CLI::success( "Season {$season} → competition wp_id={$wp_id}" ) : WP_CLI::error( 'Import failed — see log.' );
				break;

			case 'teams':
				$this->need_season( $season );
				// WP-CLI turns --no-players into [ 'players' => false ].
				$map = $importer->import_teams( $season, (bool) ( $assoc['players'] ?? true ) );
				WP_CLI::success( count( $map ) . ' teams processed. ' . $this->fmt_stats( $importer ) );
				break;

			case 'matches':
				$this->need_season( $season );
				$n = $importer->import_matches( $season );
				WP_CLI::success( "{$n} matches upserted. " . $this->fmt_stats( $importer ) );
				break;

			case 'all':
				$this->need_season( $season );
				$importer->import_season( $season );
				$importer->import_teams( $season, (bool) ( $assoc['players'] ?? true ) );
				$importer->import_matches( $season );
				WP_CLI::success( 'Full sync done. ' . $this->fmt_stats( $importer ) );
				break;

			case 'live':
				$n = $importer->sync_live();
				WP_CLI::success( "{$n} live matches updated." );
				break;

			case 'touched':
				$rows = F365_Statorium_Importer::touched_legacy_posts();
				if ( ! $rows ) {
					WP_CLI::success( 'No legacy posts have been linked.' );
					break;
				}
				foreach ( $rows as $r ) {
					WP_CLI::log( sprintf( '%-18s wp_id=%-8d api_id=%-8d %s', $r->post_type, $r->ID, $r->api_id, $r->post_title ) );
				}
				WP_CLI::success( count( $rows ) . ' linked legacy post(s).' );
				break;

			case 'repair-teams':
				$unstamped = $importer->repair_misclassified_links();
				$fixed     = $importer->repair_linked_team_shortnames();
				WP_CLI::success( "{$unstamped} misclassified link(s) corrected, {$fixed} linked team shortname(s) restored from post_title." );
				break;

			case 'rollback':
				if ( ! isset( $assoc['yes'] ) ) {
					WP_CLI::error( 'This deletes ALL Statorium-created posts and unlinks legacy posts. Re-run with --yes to confirm.' );
				}
				[ $deleted, $unlinked ] = F365_Statorium_Importer::rollback();
				WP_CLI::success( "Rollback done: {$deleted} created post(s) deleted, {$unlinked} post(s) unlinked. Legacy data untouched." );
				break;

			case 'status':
			default:
				$settings = F365_Statorium_Importer::settings();
				$key      = defined( 'F365_STATORIUM_API_KEY' ) && F365_STATORIUM_API_KEY ? F365_STATORIUM_API_KEY : (string) $settings['api_key'];
				WP_CLI::log( 'Module:   legacy in-plugin (f365-statorium plugin inactive)' );
				WP_CLI::log( 'API key:  ' . ( $key ? substr( $key, 0, 6 ) . '…' : '(not set)' ) );
				WP_CLI::log( 'Seasons:  ' . ( $settings['seasons'] ?: '(none)' ) );
				WP_CLI::log( 'Cron:     ' . ( $settings['cron'] ? 'enabled' : 'disabled' ) );
				foreach ( [ 'season', 'team', 'player', 'match', 'venue' ] as $type ) {
					$map = json_decode( (string) get_option( "f365_stat_map_{$type}", '{}' ), true );
					WP_CLI::log( sprintf( '%-8s mapped: %d', $type, is_array( $map ) ? count( $map ) : 0 ) );
				}
				break;
		}
	}

	private function need_season( int $season ): void {
		if ( ! $season ) {
			WP_CLI::error( 'Missing --season=<statorium season id> (e.g. --season=614 for World Cup 2026).' );
		}
	}

	private function fmt_stats( F365_Statorium_Importer $importer ): string {
		return $importer->stats ? wp_json_encode( $importer->stats ) : '';
	}
}

WP_CLI::add_command( 'f365-statorium', 'F365_Statorium_Command' );
