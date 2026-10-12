<?php
/**
 * Historical backfill — walks /todaymatches/ day by day from a start date
 * (default: first day of the current month) until it reaches today, then
 * flips the state machine to 'delta' mode (the /updates/ poller takes over).
 *
 * State option `f365_statorium_state`:
 *   mode           'backfill' | 'delta'
 *   cursor         'Y-m-d'  next day to process
 *   last_update_ts int      GMT unix ts for the /updates/ poller
 *
 * Resumable: each processed day advances the cursor immediately.
 *
 * @package F365_Statorium
 */

defined( 'ABSPATH' ) || exit;

class F365_Statorium_Backfill {

	const STATE = 'f365_statorium_state';

	public static function state(): array {
		$defaults = [
			'mode'           => 'backfill',
			'cursor'         => gmdate( 'Y-m-01' ), // start of current month
			'last_update_ts' => 0,
		];
		$saved = get_option( self::STATE, [] );
		return is_array( $saved ) ? array_merge( $defaults, $saved ) : $defaults;
	}

	public static function save_state( array $state ): void {
		update_option( self::STATE, $state, false );
	}

	public static function set_cursor( string $date ): void {
		$state           = self::state();
		$state['mode']   = 'backfill';
		$state['cursor'] = gmdate( 'Y-m-d', strtotime( $date ) );
		self::save_state( $state );
	}

	private F365_Statorium_Importer $importer;

	public function __construct( ?F365_Statorium_Importer $importer = null ) {
		$this->importer = $importer ?: new F365_Statorium_Importer();
	}

	/**
	 * Process up to $max_days from the cursor. Returns a summary array.
	 * Detail-fetches finished matches when $with_details (lineups ensure
	 * players via the matcher — name+country+shirt per the mapping spec).
	 */
	public function run_days( int $max_days = 7, bool $with_details = false ): array {
		$state = self::state();
		if ( 'backfill' !== $state['mode'] ) {
			return [ 'mode' => $state['mode'], 'days' => 0, 'note' => 'already in delta mode' ];
		}

		$today   = gmdate( 'Y-m-d' );
		$summary = [ 'mode' => 'backfill', 'days' => 0, 'matches' => 0, 'from' => $state['cursor'] ];

		for ( $i = 0; $i < $max_days; $i++ ) {
			$cursor = $state['cursor'];

			if ( $cursor > $today ) {
				// Caught up → switch to delta mode.
				$state['mode']           = 'delta';
				$state['last_update_ts'] = time();
				self::save_state( $state );
				$this->importer->log( 'BACKFILL complete — switched to delta mode (/updates/ poller active)' );
				$summary['mode'] = 'delta';
				break;
			}

			$summary['matches'] += $this->process_day( $cursor, $with_details );
			$summary['days']++;

			$state['cursor'] = gmdate( 'Y-m-d', strtotime( $cursor . ' +1 day' ) );
			self::save_state( $state );
		}

		$summary['cursor'] = self::state()['cursor'];
		return $summary;
	}

	/**
	 * Process ONE specific date (default: today) without touching the
	 * backfill cursor or the state machine — used by `wp f365-statorium today`.
	 */
	public function run_date( string $date = '', bool $with_details = false ): int {
		$date = $date ? gmdate( 'Y-m-d', strtotime( $date ) ) : gmdate( 'Y-m-d' );
		return $this->process_day( $date, $with_details );
	}

	private function process_day( string $date, bool $with_details ): int {
		$data = $this->importer->api()->today_matches( $date );
		if ( is_wp_error( $data ) ) {
			$this->importer->log( "FAIL backfill day={$date} — " . $data->get_error_message() );
			return 0;
		}

		$matches = (array) ( $data['matches'] ?? [] );
		if ( ! $matches ) {
			$this->importer->log( "DAY {$date}: no matches" );
			return 0;
		}

		$count = 0;
		foreach ( $matches as $m ) {
			$wp_id = $this->importer->import_match_from_payload( $m );
			if ( $wp_id ) {
				$count++;

				$status = (string) ( $m['matchStatus']['statusID'] ?? $m['matchStatus']['value'] ?? '0' );
				if ( $with_details && '1' === $status && ! empty( $m['matchID'] ) ) {
					$this->import_match_details( (int) $m['matchID'] );
				}
			}
			usleep( 250000 ); // be polite to the API
		}

		$this->importer->log( "DAY {$date}: {$count} match(es) processed" );
		return $count;
	}

	/**
	 * Fetch /matches/{id} and ensure lineup players exist (matcher decides
	 * link vs create vs review). Full lineup/event meta mapping lands in the
	 * match-detail phase; here we guarantee the player records.
	 */
	private function import_match_details( int $match_stat_id ): void {
		$data = $this->importer->api()->match( $match_stat_id );
		if ( is_wp_error( $data ) || empty( $data['match'] ) ) {
			return;
		}
		$match = $data['match'];
		$match['stages'] = $data['stages'] ?? []; // penalty/extra-time authoritative scores

		foreach ( [ 'homeParticipant', 'awayParticipant' ] as $side ) {
			$p = (array) ( $match[ $side ] ?? [] );
			if ( empty( $p['participantID'] ) ) {
				continue;
			}
			$team_wp = $this->importer->map_resolve( 'team', (int) $p['participantID'] );
			if ( ! $team_wp ) {
				continue;
			}
			foreach ( [ 'lineup', 'bench' ] as $group ) {
				foreach ( (array) ( $p['squad'][ $group ] ?? [] ) as $pl ) {
					if ( ! empty( $pl['playerID'] ) ) {
						// Adapt the lineup player shape to the squad player shape.
						$pl['fullName'] = $pl['fullName'] ?? ( $pl['playerFullName'] ?? '' );
						$pl['photo']    = $pl['photo'] ?? ( $pl['playerPhoto'] ?? '' );
						$this->importer->upsert_player( (int) $pl['playerID'], $pl, $team_wp );
					}
				}
			}
		}

		// Full detail into the mirror tables (lineups, events, stats).
		$this->importer->sync_match_detail( $match_stat_id, $match );
	}
}
