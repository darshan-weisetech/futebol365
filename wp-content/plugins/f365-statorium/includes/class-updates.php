<?php
/**
 * Delta sync — polls /updates/{since_ts}/ once the backfill has caught up.
 *
 * Response rows: { elementID, elementName: 'player'|'match'|'team'|…,
 *                  changes, lastUpd: 'Y-m-d H:i:s' (GMT), season_id }
 *
 * Dispatch is table-driven; unknown element types are logged and skipped so
 * new Statorium types can't break the poller.
 *
 * @package F365_Statorium
 */

defined( 'ABSPATH' ) || exit;

class F365_Statorium_Updates {

	private F365_Statorium_Importer $importer;

	public function __construct( ?F365_Statorium_Importer $importer = null ) {
		$this->importer = $importer ?: new F365_Statorium_Importer();
	}

	/**
	 * One poll cycle. Returns a summary array.
	 */
	public function poll(): array {
		$state = F365_Statorium_Backfill::state();
		if ( 'delta' !== $state['mode'] ) {
			return [ 'skipped' => 'not in delta mode (backfill still running)' ];
		}

		$since = (int) $state['last_update_ts'] ?: ( time() - HOUR_IN_SECONDS );
		$rows  = $this->importer->api()->updates( $since );
		if ( is_wp_error( $rows ) ) {
			$this->importer->log( 'FAIL updates poll — ' . $rows->get_error_message() );
			return [ 'error' => $rows->get_error_message() ];
		}

		// Group changed element ids by type; keep the per-element season hint.
		$changed = [];
		$max_ts  = $since;
		foreach ( (array) $rows as $row ) {
			$name = (string) ( $row['elementName'] ?? '' );
			$id   = (int) ( $row['elementID'] ?? 0 );
			if ( ! $name || ! $id ) {
				continue;
			}
			$changed[ $name ][ $id ] = (int) ( $row['season_id'] ?? 0 );
			$ts = strtotime( ( (string) ( $row['lastUpd'] ?? '' ) ) . ' UTC' );
			if ( $ts && $ts > $max_ts ) {
				$max_ts = $ts;
			}
		}

		$summary = [ 'since' => $since, 'changes' => array_map( 'count', $changed ) ];

		foreach ( $changed as $type => $ids ) {
			switch ( $type ) {
				case 'match':
					$this->refresh_matches( $ids );
					break;

				case 'player':
					$this->refresh_players( $ids );
					break;

				case 'team':
				case 'team_players': // squad changed — elementID is the team id
					$this->refresh_teams( $ids );
					break;

				case 'season':
				case 'standings':
					foreach ( array_keys( $ids ) as $season_stat_id ) {
						if ( $this->importer->map_resolve( 'season', $season_stat_id ) ) {
							$this->importer->import_matches( $season_stat_id );
						}
					}
					break;

				case 'season_teams': // participants of a season changed — elementID is the season id
					foreach ( array_keys( $ids ) as $season_stat_id ) {
						if ( $this->importer->map_resolve( 'season', $season_stat_id ) ) {
							$this->importer->import_teams( $season_stat_id, false );
						}
					}
					break;

				default:
					$this->importer->log( 'UPDATES skipped unknown elementName=' . $type . ' ×' . count( $ids ) );
			}
		}

		$state['last_update_ts'] = $max_ts;
		F365_Statorium_Backfill::save_state( $state );

		if ( $summary['changes'] ) {
			$this->importer->log( 'UPDATES ' . wp_json_encode( $summary['changes'] ) . " since={$since}" );
		}
		return $summary;
	}

	private function refresh_matches( array $ids ): void {
		foreach ( $ids as $stat_id => $season_id ) {
			$wp_id = $this->importer->map_resolve( 'match', $stat_id );
			$data  = $this->importer->api()->match( $stat_id, true );
			if ( is_wp_error( $data ) || empty( $data['match'] ) ) {
				continue;
			}
			$data['match']['stages'] = $data['stages'] ?? []; // penalty/extra-time authoritative scores
			$new_id = $this->importer->import_match_from_payload( $data['match'], $season_id );
			if ( $new_id ) {
				$status = (string) ( $data['match']['matchStatus']['statusID'] ?? '0' );
				if ( in_array( $status, [ '1', '-1' ], true ) ) {
					// Finished or live — refresh lineups/events/stats too.
					$this->importer->sync_match_detail( $stat_id, $data['match'] );
				}
				$this->importer->purge_match_cache( $new_id, '1' === $status );
			}
			unset( $wp_id );
		}
	}

	private function refresh_players( array $ids ): void {
		foreach ( $ids as $stat_id => $season_id ) {
			// Only refresh players we already track — unmapped players arrive
			// through squads/lineups, where team context makes matching safe.
			$wp_id = $this->importer->map_resolve( 'player', $stat_id );
			if ( ! $wp_id || ! $season_id ) {
				continue;
			}
			$data = $this->importer->api()->get( "/players/{$stat_id}/", [ 'season_id' => $season_id ], true );
			if ( is_wp_error( $data ) || empty( $data['player'] ) ) {
				continue;
			}
			$team_wp = (int) get_post_meta( $wp_id, 'current_team', true );
			$this->importer->upsert_player( $stat_id, (array) $data['player'], $team_wp );
		}
	}

	private function refresh_teams( array $ids ): void {
		foreach ( $ids as $stat_id => $season_id ) {
			if ( ! $this->importer->map_resolve( 'team', $stat_id ) || ! $season_id ) {
				continue;
			}
			$this->importer->import_team_players( $stat_id, (int) $this->importer->map_resolve( 'team', $stat_id ), $season_id );
		}
	}
}
