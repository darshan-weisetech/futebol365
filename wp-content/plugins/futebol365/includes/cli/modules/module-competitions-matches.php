<?php
/**
 * Migration modules: Competitions & Matches — v2.3.0 (WordPress-native model).
 *
 * NO custom tables. Everything is stored with core WordPress primitives:
 * - Competition / Match             → CPTs (scalar + 1:1 data in post meta)
 * - Phases / Participants / Standings → small bounded ACF repeaters on the
 * competition post (none scales with the whole dataset)
 * - Referees / Coaches              → small bounded ACF repeaters on the match
 * - Player appearances (lineups)   → f365_match_player CPT, ONE post per row
 * - Events for an appearance        → tiny ACF repeater on that player post
 *
 * Relationships are stored as resolved WordPress post IDs (so ACF post_object
 * fields show the linked post and the front-end can join through core meta).
 *
 * Steps (register in F365_Migrate_Command::get_steps()):
 * 'competitions'  → import_competitions()   (CPT + phases + participants repeaters)
 * 'matches'       → import_matches()        (CPT + 1:1 stats meta, batched)
 * 'match_details' → import_match_details()  (match_player posts + events + referees + coaches, batched)
 * 'standings'     → compute_standings()     (materialise standings repeater; run AFTER resolve_fk)
 *
 * All steps are idempotent (keyed on Django id) and honour
 * --batch / --offset / --limit / --dry-run / --force.
 *
 * @package Futebol365
 * @since   2.3.0
 */

defined( 'ABSPATH' ) || exit;

/* ═══════════════════════════════════════════════════════════════════════════
 * 1. LAZY MATCH HYDRATION SERVICE  (runtime — front-end, admin, CLI warm-up)
 * ═══════════════════════════════════════════════════════════════════════════ */
if ( ! class_exists( 'F365_Match_Hydrator' ) ) {
final class F365_Match_Hydrator {

	const POST_TYPE         = 'f365_match';
	const PLAYER_POST_TYPE  = 'f365_match_player';

	const FLAG_KEY          = '_f365_match_hydrated';   // parent sealed
	const PLAYERS_FLAG_KEY  = '_f365_match_players_done';// child pass sealed
	const LOCK_TTL          = 60;                        // seconds

	const MATCH_LEGACY_TABLE  = 'wp_competition_match';
	const PLAYER_LEGACY_TABLE = 'competition_matchplayer';

	/** Per-request resolver cache: [meta_key][django_id] => wp_id. */
	private static array $resolve_cache = [];

	// ── Key maps (use the EXISTING ACF keys from acf-competition.php) ─────────

	/** name => ACF field key for the f365_match group (stats + repeaters). */
	public static function match_field_keys(): array {
		$map = [
			'referees'     => 'field_m_referees',
			'coaches'      => 'field_m_coaches',
		];
		foreach ( self::stats_cols() as $col ) {
			$map[ $col ] = self::stats_key( $col );
		}
		return $map;
	}

	/** name => ACF field key for the f365_match_player group. */
	public static function player_field_keys(): array {
		return [
			'match'                     => 'field_mp_match',
			'competition'               => 'field_mp_comp',
			'player'                    => 'field_mp_player',
			'team'                      => 'field_mp_team',
			'shirt_number'              => 'field_mp_shirt',
			'position'                  => 'field_mp_pos',
			'minutes'                   => 'field_mp_minutes',
			'is_titular'                => 'field_mp_titular',
			'is_in'                     => 'field_mp_in',
			'is_out'                    => 'field_mp_out',
			'is_in_minute'              => 'field_mp_in_min',
			'is_out_minute'             => 'field_mp_out_min',
			'goals'                     => 'field_mp_goals',
			'goals_penalty'             => 'field_mp_goals_p',
			'own_goals'                 => 'field_mp_owngoal',
			'yellow_card'               => 'field_mp_yellow',
			'double_yellow_card'        => 'field_mp_dyellow',
			'red_card'                  => 'field_mp_red',
			'yellow_card_minute'        => 'field_mp_yellow_card_minute',
			'double_yellow_card_minute' => 'field_mp_double_yellow_card_minute',
			'red_card_minute'           => 'field_mp_red_card_minute',
			'events'                    => 'field_mp_events',
		];
	}

	/** Flat stat column names (wp_competition_matchstats, minus id). */
	public static function stats_cols(): array {
		return [
			'home_ball_possession', 'home_shots', 'home_shots_on_goal', 'home_fouls',
			'home_cautions', 'home_expulsions', 'home_corner_kicks', 'home_offsides',
			'home_saves', 'home_throw_ins', 'home_free_kicks',
			'visitor_ball_possession', 'visitor_shots', 'visitor_shots_on_goal', 'visitor_fouls',
			'visitor_cautions', 'visitor_expulsions', 'visitor_corner_kicks', 'visitor_offsides',
			'visitor_saves', 'visitor_throw_ins', 'visitor_free_kicks',
		];
	}

	/** Map a stat column name to its ACF field key (field_ms_*). */
	private static function stats_key( string $col ): string {
		static $m = [
			'home_ball_possession' => 'field_ms_home_poss',   'home_shots' => 'field_ms_home_shots',
			'home_shots_on_goal'   => 'field_ms_home_sog',     'home_fouls' => 'field_ms_home_fouls',
			'home_cautions'        => 'field_ms_home_yellow',  'home_expulsions' => 'field_ms_home_red',
			'home_corner_kicks'    => 'field_ms_home_corner',  'home_offsides' => 'field_ms_home_offside',
			'home_saves'           => 'field_ms_home_saves',   'home_throw_ins' => 'field_ms_home_throw',
			'home_free_kicks'      => 'field_ms_home_free',
			'visitor_ball_possession' => 'field_ms_vis_poss',  'visitor_shots' => 'field_ms_vis_shots',
			'visitor_shots_on_goal'   => 'field_ms_vis_sog',   'visitor_fouls' => 'field_ms_vis_fouls',
			'visitor_cautions'        => 'field_ms_vis_yellow','visitor_expulsions' => 'field_ms_vis_red',
			'visitor_corner_kicks'    => 'field_ms_vis_corner','visitor_offsides' => 'field_ms_vis_offside',
			'visitor_saves'           => 'field_ms_vis_saves', 'visitor_throw_ins' => 'field_ms_vis_throw',
			'visitor_free_kicks'      => 'field_ms_vis_free',
		];
		return $m[ $col ] ?? ( 'field_ms_' . $col );
	}

	private static function log( string $msg ): void {
		error_log( '[F365 Match] ' . $msg );
	}

	public static function pg_bool( $val ): int {
		if ( is_bool( $val ) ) {
			return $val ? 1 : 0;
		}
		if ( $val === null ) {
			return 0;
		}
		$s = strtolower( trim( (string) $val ) );
		return in_array( $s, [ 't', 'true', '1', 'y', 'yes' ], true ) ? 1 : 0;
	}

	private static function set( int $post_id, string $name, string $key, $value ): void {
		$is_array = is_array( $value );
		
		if ( function_exists( 'update_field' ) ) {
			update_field( $key, $value, $post_id );
			if ( ! $is_array ) {
				update_post_meta( $post_id, $name, $value );
			}
		} else {
			update_post_meta( $post_id, $name, $value );
			update_post_meta( $post_id, '_' . $name, $key );
			
			if ( $is_array ) {
				update_post_meta( $post_id, '_' . $name . '_count', count( $value ) );
			}
		}
		
		clean_post_cache( $post_id );
	}

	private static function guard( string $label, int $legacy_id, callable $fn ): void {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			error_log( "[F365 Match] block '{$label}' failed for legacy_id={$legacy_id}: " . $e->getMessage() );
		}
	}

	// ── Public entry points ───────────────────────────────────────────────────

	public static function rehydrate( int $match_wp ): void {
		if ( ! $match_wp ) {
			return;
		}
		delete_post_meta( $match_wp, self::FLAG_KEY );
		delete_post_meta( $match_wp, self::PLAYERS_FLAG_KEY );
		self::hydrate( $match_wp );
	}

	public static function hydrate( int $match_wp ): bool {
		global $wpdb;

		if ( ! $match_wp ) {
			return false;
		}

		if ( '1' === get_post_meta( $match_wp, self::FLAG_KEY, true ) ) {
			self::ensure_appearances( $match_wp );
			return true;
		}

		$lock = 'f365_match_lock_' . $match_wp;
		if ( get_transient( $lock ) ) {
			return false;
		}
		set_transient( $lock, 1, self::LOCK_TTL );

		try {
			if ( get_post_type( $match_wp ) !== self::POST_TYPE ) {
				return false;
			}

			$legacy_id = self::match_legacy_id( $match_wp );
			if ( ! $legacy_id ) {
				update_post_meta( $match_wp, self::FLAG_KEY, '1' );
				return true;
			}

			$keys    = self::match_field_keys();
			$comp_wp = (int) get_post_meta( $match_wp, 'competition', true );

			$detail = self::fetch_match_detail( $legacy_id );

			self::guard( 'teams', $legacy_id, static function () use ( $match_wp, $keys, $legacy_id ) {
				global $wpdb;

				$mt  = $wpdb->prefix . self::MATCH_LEGACY_TABLE;
				$tst = $wpdb->prefix . 'team_teamstep';

				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT 
							m.home_team_id as home_teamstep_id,
							ts_home.team_id as home_real_team_id,
							m.visitor_team_id as visitor_teamstep_id,
							ts_visitor.team_id as visitor_real_team_id
						FROM {$mt} m
						LEFT JOIN {$tst} ts_home ON ts_home.id = m.home_team_id
						LEFT JOIN {$tst} ts_visitor ON ts_visitor.id = m.visitor_team_id
						WHERE m.id = %d LIMIT 1",
						$legacy_id
					),
					ARRAY_A
				);

				if ( ! is_array( $row ) ) {
					return;
				}

				$home_team_id        = (int) ( $row['home_real_team_id'] ?? 0 );
				$visitor_team_id     = (int) ( $row['visitor_real_team_id'] ?? 0 );
				$home_teamstep_id    = (int) ( $row['home_teamstep_id'] ?? 0 );
				$visitor_teamstep_id = (int) ( $row['visitor_teamstep_id'] ?? 0 );

				$team_map = self::resolve_ids( 'f365_team', 'django_team_id', [ $home_team_id, $visitor_team_id ] );
				$teamstep_map = self::resolve_ids( 'f365_teamstep', 'django_teamstep_id', [ $home_teamstep_id, $visitor_teamstep_id ] );

				$home_teamstep_wp    = (int) ( $teamstep_map[ $home_teamstep_id ] ?? 0 );
				$visitor_teamstep_wp = (int) ( $teamstep_map[ $visitor_teamstep_id ] ?? 0 );

				if ( $home_teamstep_wp ) {
					self::set( $match_wp, 'home_teamstep', 'field_m_home_team', $home_teamstep_wp );
				}
				if ( $visitor_teamstep_wp ) {
					self::set( $match_wp, 'visitor_teamstep', 'field_m_visitor_team', $visitor_teamstep_wp );
				}
			} );

			self::guard( 'stats', $legacy_id, static function () use ( $match_wp, $keys, $detail ) {
				$row = $detail['stats'];
				if ( ! is_array( $row ) ) {
					return;
				}
				foreach ( self::stats_cols() as $col ) {
					if ( array_key_exists( $col, $row ) && $row[ $col ] !== null && $row[ $col ] !== '' ) {
						self::set( $match_wp, $col, $keys[ $col ], (int) $row[ $col ] );
					}
				}
			} );

			self::guard( 'referees', $legacy_id, static function () use ( $match_wp, $keys, $detail ) {
				$rows = $detail['referees'];
				if ( ! $rows ) {
					return;
				}
				$ref_map = self::resolve_many( 'django_referee_id', 'f365_referee',
					array_map( static fn( $r ) => (int) ( $r['referee_id'] ?? 0 ), $rows ) );
				$rep = array_map( static fn( $r ) => [
					'referee'        => (int) ( $ref_map[ (int) ( $r['referee_id'] ?? 0 ) ] ?? 0 ),
					'position'       => (string) ( $r['position'] ?? '' ),
					'classification' => (string) ( $r['classification'] ?? '' ),
					'order'          => (int) ( $r['order'] ?? 0 ),
				], $rows );
				self::set( $match_wp, 'referees', $keys['referees'], $rep );
			} );

			self::guard( 'coaches', $legacy_id, static function () use ( $match_wp, $keys, $detail ) {
				$rows = $detail['coaches'];
				if ( ! $rows ) {
					return;
				}
				$coach_map = self::resolve_many( 'django_coach_id', 'f365_coach',
					array_map( static fn( $c ) => (int) ( $c['coach_id'] ?? 0 ), $rows ) );
				$rep = array_map( static fn( $c ) => [
					'coach'      => (int) ( $coach_map[ (int) ( $c['coach_id'] ?? 0 ) ] ?? 0 ),
					'is_home'    => self::pg_bool( $c['is_home_team'] ?? null ),
					'coach_type' => (string) ( $c['coach_type'] ?? '' ),
					'order'      => (int) ( $c['order'] ?? 0 ),
				], $rows );
				self::set( $match_wp, 'coaches', $keys['coaches'], $rep );
				update_post_meta( $match_wp, 'competition_matchcoach_json', wp_json_encode( array_map( static fn( $c ) => [
					'coach_id'     => (int) ( $c['coach_id'] ?? 0 ),
					'is_home_team' => self::pg_bool( $c['is_home_team'] ?? null ),
					'coach_type'   => (string) ( $c['coach_type'] ?? '' ),
					'order'        => (int) ( $c['order'] ?? 0 ),
				], $rows ) ) );
			} );

			self::guard( 'formations', $legacy_id, static function () use ( $match_wp, $detail ) {
				$rows = $detail['formations'];
				if ( ! $rows ) {
					return;
				}
				$data = array_map( static fn( $f ) => [
					'formation'    => (string) ( $f['formation'] ?? '' ),
					'is_home_team' => self::pg_bool( $f['is_home_team'] ?? null ),
				], $rows );

				self::set( $match_wp, 'formations', 'field_m_matchformation', $data );
			} );

			self::process_appearances( $match_wp, $legacy_id, $detail['players'], $comp_wp );

			update_post_meta( $match_wp, self::FLAG_KEY, '1' );
			return true;

		} catch ( \Throwable $e ) {
			error_log( '[F365 Match] hydrate(' . $match_wp . ') fatal: ' . $e->getMessage() );
			return false;
		} finally {
			delete_transient( $lock );
		}
	}

	public static function ensure_appearances( int $match_wp ): bool {
		if ( ! $match_wp ) {
			return false;
		}
		if ( '1' === get_post_meta( $match_wp, self::PLAYERS_FLAG_KEY, true ) ) {
			return true;
		}
		$legacy_id = self::match_legacy_id( $match_wp );
		if ( ! $legacy_id ) {
			self::log( "ensure_appearances: match wp_id={$match_wp} has no legacy_id / django_match_id — cannot import appearances." );
			return false;
		}
		$comp_wp = (int) get_post_meta( $match_wp, 'competition', true );
		$players = self::fetch_match_detail( $legacy_id, true )['players'];
		return self::process_appearances( $match_wp, $legacy_id, $players, $comp_wp );
	}

	private static function process_appearances( int $match_wp, int $legacy_id, $players, int $comp_wp ): bool {
		$players = is_array( $players ) ? $players : [];

		$total = count( $players );
		if ( ! $total ) {
			update_post_meta( $match_wp, self::PLAYERS_FLAG_KEY, '1' );
			self::log( "process_appearances: match wp_id={$match_wp} legacy_id={$legacy_id} | source_rows=0 — sealed." );
			return true;
		}

		$pkeys      = self::player_field_keys();
		$match_date = (string) ( get_post_field( 'post_date', $match_wp ) ?: current_time( 'mysql' ) );

		$via_person = [];
		$player_map = self::resolve_players(
			array_map( static fn( $p ) => (int) ( $p['player_id'] ?? 0 ), $players ),
			$via_person
		);
		$team_map = self::resolve_ids( 'f365_team', 'django_team_id',
			array_map( static fn( $p ) => (int) ( $p['team_id'] ?? 0 ), $players ) );

		$created = $reused = $linked_now = $still_unlinked = $skipped_insert = 0;
		$unresolved_pids = [];

		foreach ( $players as $p ) {
			$legacy_mp = (int) ( $p['id'] ?? 0 );
			if ( ! $legacy_mp ) {
				continue;
			}
			$pid       = (int) ( $p['player_id'] ?? 0 ); 
			$tid       = (int) ( $p['team_id'] ?? 0 );
			$player_wp = (int) ( $player_map[ $pid ] ?? 0 );
			$team_wp   = (int) ( $team_map[ $tid ] ?? 0 );

			$title = $player_wp ? ( get_the_title( $player_wp ) ?: ( 'Appearance #' . $legacy_mp ) ) : ( 'Appearance #' . $legacy_mp );

			$was_created = false;
			$mp_wp = self::find_or_create_appearance( $legacy_mp, $match_wp, $player_wp, $title, $match_date, $was_created );
			if ( ! $mp_wp ) {
				$skipped_insert++;
				continue;
			}
			$was_created ? $created++ : $reused++;

			$built = metadata_exists( 'post', $mp_wp, '_f365_mp_built' );

			if ( ! $built ) {
				$meta = [
					'match'                     => $match_wp,
					'competition'               => $comp_wp,
					'shirt_number'              => (int) ( $p['shirt_number'] ?? 0 ),
					'position'                  => (string) ( $p['position'] ?? '' ),
					'minutes'                   => (int) ( $p['minutes'] ?? 0 ),
					'is_titular'                => self::pg_bool( $p['is_titular'] ?? null ),
					'is_in'                     => self::pg_bool( $p['is_in'] ?? null ),
					'is_out'                    => self::pg_bool( $p['is_out'] ?? null ),
					'is_in_minute'              => self::nullint( $p['is_in_minute'] ?? null ),
					'is_out_minute'             => self::nullint( $p['is_out_minute'] ?? null ),
					'goals'                     => (int) ( $p['goals'] ?? 0 ),
					'goals_penalty'             => (int) ( $p['goals_penalty'] ?? 0 ),
					'own_goals'                 => (int) ( $p['own_goals'] ?? 0 ),
					'yellow_card'               => self::pg_bool( $p['yellow_card'] ?? null ),
					'double_yellow_card'        => self::pg_bool( $p['double_yellow_card'] ?? null ),
					'red_card'                  => self::pg_bool( $p['red_card'] ?? null ),
					'yellow_card_minute'        => self::nullint( $p['yellow_card_minute'] ?? null ),
					'double_yellow_card_minute' => self::nullint( $p['double_yellow_card_minute'] ?? null ),
					'red_card_minute'           => self::nullint( $p['red_card_minute'] ?? null ),
				];
				if ( $player_wp ) {
					$meta['player'] = $player_wp;
				}
				if ( $team_wp ) {
					$meta['team'] = $team_wp;
				}
				$classification = $p['classification'] ?? null;
				if ( $classification !== null && $classification !== '' ) {
					$meta['classification'] = $classification;
				}

				update_post_meta( $mp_wp, 'django_matchplayer_id', $legacy_mp );
				update_post_meta( $mp_wp, 'django_player_id', $pid );
				update_post_meta( $mp_wp, 'django_team_id', $tid );

				foreach ( $meta as $name => $val ) {
					if ( $val === null ) {
						continue;
					}
					if ( isset( $pkeys[ $name ] ) ) {
						self::set( $mp_wp, $name, $pkeys[ $name ], $val );
					} else {
						update_post_meta( $mp_wp, $name, $val );
					}
				}

				if ( ! empty( $p['position'] ) && taxonomy_exists( 'f365_player_position' ) ) {
					wp_set_object_terms( $mp_wp, [ (string) $p['position'] ], 'f365_player_position', false );
				}

				$events = ( ! empty( $p['events'] ) && is_array( $p['events'] ) ) ? $p['events'] : [];
				if ( $events ) {
					$rep = array_map( static fn( $ev ) => [
						'event_type'  => (string) ( $ev['event_type'] ?? '' ),
						'minute'      => (int) ( $ev['minute'] ?? 0 ),
						'description' => (string) ( $ev['description'] ?? '' ),
					], $events );
					self::set( $mp_wp, 'events', $pkeys['events'], $rep );
				}

				update_post_meta( $mp_wp, '_f365_mp_built', 1 );
			} else {
				if ( $player_wp && (int) get_post_meta( $mp_wp, 'player', true ) <= 0 ) {
					self::set( $mp_wp, 'player', $pkeys['player'], $player_wp );
					$linked_now++;
				}
				if ( $team_wp && (int) get_post_meta( $mp_wp, 'team', true ) <= 0 ) {
					self::set( $mp_wp, 'team', $pkeys['team'], $team_wp );
				}
			}

			if ( $player_wp <= 0 ) {
				$still_unlinked++;
				$unresolved_pids[ $pid ] = true;
			}
		}

		self::log( sprintf(
			'process_appearances: match wp_id=%d | source_rows=%d created=%d unlinked=%d',
			$match_wp, $total, $created, $still_unlinked
		) );

		if ( $still_unlinked > 0 || $skipped_insert > 0 ) {
			return false;
		}
		update_post_meta( $match_wp, self::PLAYERS_FLAG_KEY, '1' );
		return true;
	}

	private static function fetch_match_detail( int $legacy_id, bool $players_only = false ): array {
		global $wpdb;

		$out = [ 'stats' => null, 'referees' => [], 'coaches' => [], 'formations' => [], 'players' => [] ];
		if ( ! $legacy_id ) {
			return $out;
		}

		$pfx = $wpdb->prefix;
		$tm  = $pfx . 'competition_match';
		$ts  = $pfx . 'competition_matchstats';
		$tr  = $pfx . 'competition_matchreferee';
		$tc  = $pfx . 'competition_matchcoach';
		$tf  = $pfx . 'competition_matchformation';
		$tp  = $pfx . 'competition_matchplayer';
		$te  = $pfx . 'competition_matchplayerevent';

		if ( ! self::table_exists( $tp ) ) {
			return $out;
		}

		$stat_pairs = [];
		foreach ( self::stats_cols() as $c ) {
			$stat_pairs[] = "'" . $c . "', s.`" . $c . "`";
		}
		$stat_obj = implode( ', ', $stat_pairs );

		$players_sub =
			"(SELECT JSON_ARRAYAGG(JSON_OBJECT("
			. "'id', p.id, 'team_id', p.team_id, 'player_id', p.player_id, "
			. "'position', p.position, 'minutes', p.minutes, 'classification', p.classification, "
			. "'is_titular', p.is_titular, 'is_in', p.is_in, 'is_out', p.is_out, "
			. "'is_in_minute', p.is_in_minute, 'is_out_minute', p.is_out_minute, "
			. "'goals', p.goals, 'goals_penalty', p.goals_penalty, 'own_goals', p.own_goals, "
			. "'yellow_card', p.yellow_card, 'double_yellow_card', p.double_yellow_card, 'red_card', p.red_card, "
			. "'yellow_card_minute', p.yellow_card_minute, 'double_yellow_card_minute', p.double_yellow_card_minute, "
			. "'red_card_minute', p.red_card_minute, 'shirt_number', p.shirt_number, "
			. "'events', (SELECT JSON_ARRAYAGG(JSON_OBJECT('event_type', e.event_type, 'minute', e.minute, 'description', e.description)) "
			. "FROM {$te} e WHERE e.matchplayer_id = p.id) "
			. ")) FROM {$tp} p WHERE p.match_id = m.id) AS players";

		if ( $players_only ) {
			$sql = "SELECT {$players_sub} FROM {$tm} m WHERE m.id = %d";
		} else {
			$sql =
				"SELECT "
				. "(SELECT JSON_OBJECT({$stat_obj}) FROM {$ts} s WHERE s.id = m.stats_id) AS stats, "
				. "(SELECT JSON_ARRAYAGG(JSON_OBJECT('referee_id', r.referee_id, 'position', r.position, 'classification', r.classification, 'order', r.`order`)) FROM {$tr} r WHERE r.match_id = m.id) AS referees, "
				. "(SELECT JSON_ARRAYAGG(JSON_OBJECT('coach_id', c.coach_id, 'is_home_team', c.is_home_team, 'coach_type', c.coach_type, 'order', c.`order`)) FROM {$tc} c WHERE c.match_id = m.id) AS coaches, "
				. "(SELECT JSON_ARRAYAGG(JSON_OBJECT('formation', f.formation, 'is_home_team', f.is_home_team)) FROM {$tf} f WHERE f.match_id = m.id) AS formations, "
				. "{$players_sub} "
				. "FROM {$tm} m WHERE m.id = %d";
		}

		$row = $wpdb->get_row( $wpdb->prepare( $sql, $legacy_id ), ARRAY_A );

		if ( $wpdb->last_error || null === $row ) {
			return self::fetch_match_detail_fallback( $legacy_id, $players_only );
		}

		$dec = static function ( $v ) {
			if ( null === $v || '' === $v ) {
				return null;
			}
			$d = json_decode( (string) $v, true );
			return is_array( $d ) ? $d : null;
		};

		if ( ! $players_only ) {
			$out['stats']      = $dec( $row['stats'] ?? null );
			$out['referees']   = $dec( $row['referees'] ?? null ) ?: [];
			$out['coaches']    = $dec( $row['coaches'] ?? null ) ?: [];
			$out['formations'] = $dec( $row['formations'] ?? null ) ?: [];
			usort( $out['referees'], static fn( $a, $b ) => ( (int) ( $a['order'] ?? 0 ) ) <=> ( (int) ( $b['order'] ?? 0 ) ) );
			usort( $out['coaches'], static fn( $a, $b ) => ( (int) ( $a['order'] ?? 0 ) ) <=> ( (int) ( $b['order'] ?? 0 ) ) );
		}

		$players = $dec( $row['players'] ?? null ) ?: [];
		usort( $players, static fn( $a, $b ) => ( (int) ( $a['id'] ?? 0 ) ) <=> ( (int) ( $b['id'] ?? 0 ) ) );
		foreach ( $players as &$pp ) {
			$pp['events'] = ( ! empty( $pp['events'] ) && is_array( $pp['events'] ) ) ? $pp['events'] : [];
			usort( $pp['events'], static fn( $a, $b ) => ( (int) ( $a['minute'] ?? 0 ) ) <=> ( (int) ( $b['minute'] ?? 0 ) ) );
		}
		unset( $pp );
		$out['players'] = $players;

		return $out;
	}

	private static function fetch_match_detail_fallback( int $legacy_id, bool $players_only = false ): array {
		global $wpdb;

		$out = [ 'stats' => null, 'referees' => [], 'coaches' => [], 'formations' => [], 'players' => [] ];
		$pfx = $wpdb->prefix;

		if ( ! $players_only ) {
			$tm        = $pfx . 'competition_match';
			$ts        = $pfx . 'competition_matchstats';
			$stats_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT stats_id FROM {$tm} WHERE id = %d", $legacy_id ) );
			if ( $stats_id && self::table_exists( $ts ) ) {
				$out['stats'] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ts} WHERE id = %d LIMIT 1", $stats_id ), ARRAY_A ) ?: null;
			}
			$tr = $pfx . 'competition_matchreferee';
			if ( self::table_exists( $tr ) ) {
				$out['referees'] = $wpdb->get_results( $wpdb->prepare( "SELECT referee_id, position, classification, `order` FROM {$tr} WHERE match_id = %d ORDER BY `order` ASC, id ASC", $legacy_id ), ARRAY_A ) ?: [];
			}
			$tc = $pfx . 'competition_matchcoach';
			if ( self::table_exists( $tc ) ) {
				$out['coaches'] = $wpdb->get_results( $wpdb->prepare( "SELECT coach_id, is_home_team, coach_type, `order` FROM {$tc} WHERE match_id = %d ORDER BY `order` ASC, id ASC", $legacy_id ), ARRAY_A ) ?: [];
			}
			$tf = $pfx . 'competition_matchformation';
			if ( self::table_exists( $tf ) ) {
				$out['formations'] = $wpdb->get_results( $wpdb->prepare( "SELECT formation, is_home_team FROM {$tf} WHERE match_id = %d ORDER BY id ASC", $legacy_id ), ARRAY_A ) ?: [];
			}
		}

		$tp = $pfx . 'competition_matchplayer';
		if ( self::table_exists( $tp ) ) {
			$players = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, team_id, player_id, position, minutes, classification,
				        is_titular, is_in, is_out, is_in_minute, is_out_minute,
				        goals, goals_penalty, own_goals,
				        yellow_card, double_yellow_card, red_card,
				        yellow_card_minute, double_yellow_card_minute, red_card_minute,
				        shirt_number
				   FROM {$tp} WHERE match_id = %d ORDER BY id ASC",
				$legacy_id
			), ARRAY_A ) ?: [];
			if ( $players ) {
				$events = self::load_events( wp_list_pluck( $players, 'id' ) );
				foreach ( $players as &$pp ) {
					$pp['events'] = $events[ (int) $pp['id'] ] ?? [];
				}
				unset( $pp );
			}
			$out['players'] = $players;
		}

		return $out;
	}

	private static function find_or_create_appearance( int $legacy_mp, int $match_wp, int $player_wp, string $title, string $match_date, bool &$was_created = false ): int {
		global $wpdb;
		$was_created = false;

		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			  WHERE legacy_table = %s AND legacy_id = %d AND post_type = %s LIMIT 1",
			self::PLAYER_LEGACY_TABLE, $legacy_mp, self::PLAYER_POST_TYPE
		) );
		if ( $existing ) {
			return $existing;
		}

		$res = wp_insert_post( [
			'post_type'      => self::PLAYER_POST_TYPE,
			'post_title'     => $title,
			'post_status'    => 'publish',
			'post_author'    => 1,
			'post_date'      => $match_date ?: '',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		], true );
		if ( is_wp_error( $res ) || ! $res ) {
			return 0;
		}
		$mp_wp = (int) $res;
		$was_created = true;
		self::stamp_legacy( $mp_wp, self::PLAYER_LEGACY_TABLE, $legacy_mp );
		return $mp_wp;
	}

	private static function stamp_legacy( int $post_id, string $table, int $legacy_id ): void {
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'legacy_table' => $table, 'legacy_id' => $legacy_id ], [ 'ID' => $post_id ] );
		clean_post_cache( $post_id );
	}

	private static function match_legacy_id( int $match_wp ): int {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d AND legacy_table = %s LIMIT 1",
			$match_wp, self::MATCH_LEGACY_TABLE
		) );
		if ( $id ) {
			return $id;
		}
		return (int) get_post_meta( $match_wp, 'django_match_id', true );
	}

	private static function load_events( array $mp_ids ): array {
		global $wpdb;
		$mp_ids = array_values( array_unique( array_filter( array_map( 'intval', $mp_ids ) ) ) );
		if ( ! $mp_ids ) {
			return [];
		}
		$t = $wpdb->prefix . 'competition_matchplayerevent';
		if ( ! self::table_exists( $t ) ) {
			return [];
		}
		$in   = implode( ',', $mp_ids );
		$rows = $wpdb->get_results(
			"SELECT matchplayer_id, event_type, minute, description FROM {$t}
			  WHERE matchplayer_id IN ($in) ORDER BY minute ASC, id ASC",
			ARRAY_A
		);
		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['matchplayer_id'] ][] = $row;
		}
		return $out;
	}

	private static function resolve_players( array $player_ids, array &$via_person = [] ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $player_ids ) ) ) );
		if ( ! $ids ) {
			return [];
		}
		$in  = implode( ',', $ids );
		$out = [];

		$rows = $wpdb->get_results(
			"SELECT pm.meta_value AS did, pm.post_id AS wp
			   FROM {$wpdb->postmeta} pm
			   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'f365_player'
			  WHERE pm.meta_key = 'django_player_id' AND pm.meta_value IN ($in)",
			ARRAY_A
		);
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['did'] ] = (int) $r['wp'];
		}

		$missing = array_values( array_diff( $ids, array_keys( $out ) ) );
		if ( $missing ) {
			$in2  = implode( ',', array_map( 'intval', $missing ) );
			$rows = $wpdb->get_results(
				"SELECT pm.meta_value AS did, MIN(pm.post_id) AS wp
				   FROM {$wpdb->postmeta} pm
				   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'f365_player'
				  WHERE pm.meta_key = 'django_person_id' AND pm.meta_value IN ($in2)
				  GROUP BY pm.meta_value",
				ARRAY_A
			);
			foreach ( (array) $rows as $r ) {
				$out[ (int) $r['did'] ]        = (int) $r['wp'];
				$via_person[ (int) $r['did'] ] = true;
			}
		}

		return $out;
	}

	private static function resolve_many( string $meta_key, string $post_type, array $django_ids ): array {
		global $wpdb;

		if ( ! isset( self::$resolve_cache[ $meta_key ] ) ) {
			self::$resolve_cache[ $meta_key ] = [];
		}
		$cache  = &self::$resolve_cache[ $meta_key ];
		$result = [];
		$need   = [];

		foreach ( $django_ids as $did ) {
			$did = (int) $did;
			if ( ! $did ) {
				continue;
			}
			if ( isset( $cache[ $did ] ) ) {
				$result[ $did ] = $cache[ $did ];
			} else {
				$need[ $did ] = true;
			}
		}

		if ( $need ) {
			$in   = implode( ',', array_map( 'intval', array_keys( $need ) ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT pm.meta_value AS django_id, pm.post_id AS wp_id
				   FROM {$wpdb->postmeta} pm
				   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = %s
				  WHERE pm.meta_key = %s AND pm.meta_value IN ($in)",
				$post_type, $meta_key
			), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$did            = (int) $row['django_id'];
				$wp_id          = (int) $row['wp_id'];
				$cache[ $did ]  = $wp_id;
				$result[ $did ] = $wp_id;
				unset( $need[ $did ] );
			}
			foreach ( array_keys( $need ) as $miss ) {
				$cache[ $miss ] = 0;
			}
		}

		return $result;
	}

	public static function resolve_ids( string $post_type, string $meta_key, array $ids ): array {
		return self::resolve_many( $meta_key, $post_type, $ids );
	}

	private static function nullint( $v ): ?int {
		return ( $v === null || $v === '' ) ? null : (int) $v;
	}

	private static function table_exists( string $table ): bool {
		static $seen = [];
		if ( isset( $seen[ $table ] ) ) {
			return $seen[ $table ];
		}
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $seen[ $table ] = ( $found === $table );
	}
}
}

/* ═══════════════════════════════════════════════════════════════════════════
 * 2. LIFECYCLE HOOKS
 * ═══════════════════════════════════════════════════════════════════════════ */

if ( ! function_exists( 'f365_match_maybe_hydrate' ) ) {
	add_action( 'template_redirect', 'f365_match_maybe_hydrate' );
	function f365_match_maybe_hydrate(): void {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! is_singular( F365_Match_Hydrator::POST_TYPE ) ) {
			return;
		}
		$id = get_queried_object_id();
		if ( $id && class_exists( 'F365_Match_Hydrator' ) ) {
			F365_Match_Hydrator::hydrate( (int) $id );
		}
	}
}

/* ═══════════════════════════════════════════════════════════════════════════
 * 3. WP-CLI MIGRATION TRAITS (Memory Optimized for 1 Million+ Rows)
 * ═══════════════════════════════════════════════════════════════════════════ */

trait F365_Module_Competitions {

	protected function import_competitions(): void {
		global $wpdb;
		$rows = $this->pg_all( '
			SELECT comp.*, c.shortname AS country_name
			FROM competition_competition comp
			LEFT JOIN country_country c ON c.id = comp.country_id
			ORDER BY comp.edition DESC, comp.id
		' );

		$n = $skip = 0;
		
		foreach ( $rows as $r ) {
			if ( $n >= $this->limit ) break;
			$django_id = (int) $r['id'];

			$wp_id = $this->upsert_post(
				'f365_competition', $django_id, 'django_competition_id',
				[
					'post_title'  => $r['name'] ?? ( 'Competition #' . $django_id ),
					'post_status' => ( $r['is_available'] ?? false ) ? 'publish' : 'draft',
					'post_date'   => $r['begin'] ? date( 'Y-m-d H:i:s', strtotime( $r['begin'] ) ) : '',
				],
				$skip
			);
			if ( ! $wp_id ) continue;

			$this->set_meta( $wp_id, [
				'edition'                => $r['edition'] ?? '',
				'season'                 => $r['season']  ?? '',
				'begin'                  => $this->d( $r['begin'] ?? '' ),
				'end'                    => $this->d( $r['end'] ?? '' ),
				'local'                  => $r['local'] ?? '',
				'notes'                  => $r['notes'] ?? '',
				'victory_points'         => (int) ( $r['victory_points']    ?? 3 ),
				'draw_points'            => (int) ( $r['draw_points']       ?? 1 ),
				'defeat_points'          => (int) ( $r['defeat_points']     ?? 0 ),
				'rise_places'            => (int) ( $r['rise_places']       ?? 0 ),
				'relegation_places'      => (int) ( $r['relegation_places'] ?? 0 ),
				'statistics_start'       => (int) ( $r['statistics_start']  ?? 0 ),
				'is_home_visitor'        => $r['is_home_visitor'] ? 1 : 0,
				'is_available'           => $r['is_available']    ? 1 : 0,
				'is_highlight'           => $r['is_highlight']    ? 1 : 0,
				'is_closed'              => $r['is_closed']       ? 1 : 0,
				'apontador_tmp'			 => $r['apontador_tmp'],
				'django_competition_id'  => $django_id,
				'django_fed_comp_id'     => (int) ( $r['federation_competition_id'] ?? 0 ),
				'django_first_place_ts'  => (int) ( $r['first_place_teamstep_id']   ?? 0 ),
				'django_second_place_ts' => (int) ( $r['second_place_teamstep_id']  ?? 0 ),
			] );

			if ( $r['country_name'] ) $this->set_terms( $wp_id, [ $r['country_name'] ], 'f365_country' );
			if ( $r['edition'] )      $this->set_terms( $wp_id, [ $r['edition'] ],      'f365_season' );
			
			if ( ! empty( $r['federation_competition_id'] ) ) {
				$fed_wp = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='django_fed_comp_id' AND meta_value=%d LIMIT 1",
					(int) $r['federation_competition_id']
				) );
				if ( $fed_wp ) {
					update_post_meta( $wp_id, 'federation_competition', $fed_wp );
				}
			}

			if ( ! empty( $r['first_place_teamstep_id'] ) ) {
				$ts_wp = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='django_teamstep_id' AND meta_value=%d LIMIT 1",
					(int) $r['first_place_teamstep_id']
				) );
				if ( $ts_wp ) {
					update_post_meta( $wp_id, 'first_place_team', $ts_wp );
				}
			}

			if ( ! empty( $r['second_place_teamstep_id'] ) ) {
				$ts_wp = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='django_teamstep_id' AND meta_value=%d LIMIT 1",
					(int) $r['second_place_teamstep_id']
				) );
				if ( $ts_wp ) {
					update_post_meta( $wp_id, 'second_place_team', $ts_wp );
				}
			}

			$phases = $this->pg_all( 'SELECT * FROM competition_competitionphase WHERE competition_id = $1 ORDER BY "order"', [ $django_id ] );
			$this->set_repeater( $wp_id, 'phases', array_map( fn( $ph ) => [
				'django_id'   => (int) $ph['id'],
				'phase_order' => (int) ( $ph['order'] ?? 0 ),
				'name'        => $ph['name'] ?? '',
				'phase_type'  => $ph['phase_type'] ?? '',
			], $phases ) );
			update_post_meta( $wp_id, 'competition_phases_json', wp_json_encode( $phases ) );

			$parts = $this->pg_all( '
				SELECT par.*, ts.team_id AS real_team_id
				FROM competition_participant par
				JOIN team_teamstep ts ON ts.id = par.team_id
				WHERE par.competition_id = $1
			', [ $django_id ] );

			$processed_parts = [];
			foreach ( $parts as $par ) {
				$team_wp = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='django_team_id' AND meta_value=%d LIMIT 1",
					(int) $par['real_team_id']
				) );
				$processed_parts[] = [
					'team'                => $team_wp,
					'group_phase_one'     => $par['group_phase_one']   ?? '',
					'group_phase_two'     => $par['group_phase_two']   ?? '',
					'group_phase_three'   => $par['group_phase_three'] ?? '',
					'django_teamstep_id'  => (int) $par['team_id'],
				];
			}
			$this->set_repeater( $wp_id, 'participants', $processed_parts );
			update_post_meta( $wp_id, 'participants_json', wp_json_encode( $parts ) );

			// Process Winners dynamically via database values
			$winners = $this->pg_all( '
				SELECT w."order", w.competition_phase_name, w."group",
				       w.first_place_teamstep_id, w.second_place_teamstep_id,
				       ts1.team_id AS first_real_id, ts2.team_id AS second_real_id
				FROM competition_competitionphasewinners w
				LEFT JOIN team_teamstep ts1 ON ts1.id = w.first_place_teamstep_id
				LEFT JOIN team_teamstep ts2 ON ts2.id = w.second_place_teamstep_id
				WHERE w.competition_id = $1
				ORDER BY w."order"
			', [ $django_id ] );

			$processed_winners = [];
			foreach ( $winners as $w ) {
				$t1_wp = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='django_team_id' AND meta_value=%d LIMIT 1", (int) $w['first_real_id'] ) );
				$t2_wp = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='django_team_id' AND meta_value=%d LIMIT 1", (int) $w['second_real_id'] ) );
				$processed_winners[] = [
					'phase_name'          => $w['competition_phase_name'] ?? '',
					'group'               => $w['group'] ?? '',
					'first_place_team'    => $t1_wp,
					'second_place_team'   => $t2_wp,
					'order'               => (int) ( $w['order'] ?? 0 ),
					'django_first_ts_id'  => (int) ( $w['first_place_teamstep_id'] ?? 0 ),
					'django_second_ts_id' => (int) ( $w['second_place_teamstep_id'] ?? 0 ),
				];
			}
			$this->set_repeater( $wp_id, 'phase_winners', $processed_winners );
			update_post_meta( $wp_id, 'phase_winners_json', wp_json_encode( $winners ) );

			$places = $this->pg_all( 'SELECT * FROM competition_competitionplace WHERE competition_id = $1 ORDER BY position', [ $django_id ] );
			$this->set_repeater( $wp_id, 'competition_places', array_map( fn( $pl ) => [
				'position'    => (int) $pl['position'],
				'phase'       => $pl['phase'] ?? '',
				'group'       => $pl['group'] ?? '',
				'color'       => $pl['color'] ?? '',
				'description' => $pl['description'] ?? '',
			], $places ) );
			update_post_meta( $wp_id, 'competition_places_json', wp_json_encode( $places ) );

			$order_rules = $this->pg_all( 'SELECT "order", order_method, last_round_only FROM competition_competitionclassificationorder WHERE competition_id = $1 ORDER BY "order"', [ $django_id ] );
			$this->set_repeater( $wp_id, 'classification_order', array_map( fn( $o ) => [
				'order'           => (int) ( $o['order'] ?? 0 ),
				'order_method'    => $o['order_method'] ?? '',
				'last_round_only' => $o['last_round_only'] ? 1 : 0,
			], $order_rules ) );
			update_post_meta( $wp_id, 'classification_order_json', wp_json_encode( $order_rules ) );

			$offset_rows = $this->pg_all( '
				SELECT o.team_id, o.phase, o."group", o.offset, ts.team_id AS real_team_id
				   FROM competition_classificationoffset o
				   LEFT JOIN team_teamstep ts ON ts.id = o.team_id
				  WHERE o.competition_id = $1', [ $django_id ] );

			$processed_offsets = [];
			foreach ( $offset_rows as $o ) {
				$t_wp = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='django_team_id' AND meta_value=%d LIMIT 1", (int) $o['real_team_id'] ) );
				$processed_offsets[] = [
					'team'               => $t_wp,
					'phase'              => $o['phase'] ?? '',
					'group'              => $o['group'] ?? '',
					'offset'             => (int) ( $o['offset'] ?? 0 ),
					'django_teamstep_id' => (int) $o['team_id'],
				];
			}
			$this->set_repeater( $wp_id, 'classification_offsets', $processed_offsets );
			update_post_meta( $wp_id, 'classification_offsets_json', wp_json_encode( $offset_rows ) );

			$n++;
		}

		WP_CLI::success( "Competitions: {$n} imported, {$skip} skipped." );
	}
}

trait F365_Module_CM_Helpers {

	protected function nullint( $v ): ?int {
		return ( $v === null || $v === '' ) ? null : (int) $v;
	}

	protected function add_dirty_competitions( array $comp_ids ): void {
		if ( $this->dry_run || ! $comp_ids ) return;
		$set = get_option( 'f365_standings_dirty', [] );
		if ( ! is_array( $set ) ) $set = [];
		foreach ( $comp_ids as $id ) {
			$id = (int) $id;
			if ( $id ) $set[ $id ] = 1;
		}
		update_option( 'f365_standings_dirty', $set, false );
	}

	protected function get_dirty_competitions(): array {
		$set = get_option( 'f365_standings_dirty', [] );
		if ( ! is_array( $set ) ) return [];
		return array_map( 'intval', array_keys( $set ) );
	}

	protected function clear_dirty_competitions( array $comp_ids = [] ): void {
		if ( $this->dry_run ) return;
		if ( ! $comp_ids ) {
			delete_option( 'f365_standings_dirty' );
			return;
		}
		$set = get_option( 'f365_standings_dirty', [] );
		if ( ! is_array( $set ) || ! $set ) return;
		foreach ( $comp_ids as $id ) {
			unset( $set[ (int) $id ] );
		}
		if ( $set ) {
			update_option( 'f365_standings_dirty', $set, false );
		} else {
			delete_option( 'f365_standings_dirty' );
		}
	}

	protected function set_repeater( int $post_id, string $field, array $rows ): void {
		if ( $this->dry_run ) return;
		if ( function_exists( 'update_field' ) ) {
			update_field( $field, $rows, $post_id );
		} else {
			update_post_meta( $post_id, $field, $rows );
		}
	}

	protected function upsert_post( string $post_type, int $django_id, string $meta_key, array $core, int &$skip ): int {
		global $wpdb;

		$wp_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id
			 WHERE pm.meta_key=%s AND pm.meta_value=%d AND p.post_type=%s LIMIT 1",
			$meta_key, $django_id, $post_type
		) );

		if ( $wp_id ) {
			if ( ! $this->force ) { $skip++; }
			if ( ! $this->dry_run ) {
				wp_update_post( array_merge( [ 'ID' => $wp_id ], array_intersect_key( $core, array_flip( [ 'post_title', 'post_status' ] ) ) ) );
			}
			return $wp_id;
		}

		return (int) $this->insert_post( array_merge( [ 'post_type' => $post_type ], $core ) );
	}
}

trait F365_Module_Matches {

	protected function import_matches(): void {
		global $wpdb;

		$mt  = $wpdb->prefix . 'competition_match';
		$ct  = $wpdb->prefix . 'competition_competition';
		$tst = $wpdb->prefix . 'team_teamstep';
		$tt  = $wpdb->prefix . 'team_team';
		
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $mt ) ) !== $mt ) {
			WP_CLI::error( "Source table {$mt} not found." );
			return;
		}

		// Ensure isolation setting for parallel concurrency
		$wpdb->query( "SET SESSION innodb_lock_wait_timeout = 50" );

		$cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->posts}" );
		if ( ! in_array( 'legacy_table', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->posts} ADD COLUMN legacy_table VARCHAR(64) NULL DEFAULT NULL" );
		}
		if ( ! in_array( 'legacy_id', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->posts} ADD COLUMN legacy_id BIGINT NULL DEFAULT NULL" );
		}
		if ( empty( $wpdb->get_results( "SHOW INDEX FROM {$wpdb->posts} WHERE Key_name='f365_legacy'" ) ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->posts} ADD INDEX f365_legacy (legacy_table, legacy_id)" );
		}

		wp_suspend_cache_addition( true );
		wp_defer_term_counting( true );
		if ( function_exists( 'wp_defer_comment_counting' ) ) {
			wp_defer_comment_counting( true );
		}

		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$mt}" );
		$limit  = ( ! empty( $this->limit ) && $this->limit !== PHP_INT_MAX ) ? (int) $this->limit : $total;
		$offset = ( ! empty( $this->offset ) ) ? (int) $this->offset : 0;
		$batch  = $this->batch > 0 ? (int) $this->batch : 1000;

		WP_CLI::log( "Parallel Worker Spawned: offset={$offset} limit={$limit} | batch={$batch}" );

		$managed_keys = [
			'date_time', 'match_round', 'phase', 'competition_phase_id',
			'group_home_team', 'group_visitor_team', 'group_phase_round', 'leg',
			'situation', 'home_score', 'visitor_score', 'home_score_extra',
			'visitor_score_extra', 'penalty_home_score', 'penalty_visitor_score',
			'duration', 'assistance', 'observations',
			'django_match_id', 'django_competition_id', 'django_home_team_id',
			'django_visitor_team_id', 'django_stadium_id', 'django_phase_id',
			'django_stats_id', 'competition', 'stadium',
			'home_team', 'visitor_team', 'home_teamstep', 'visitor_teamstep',
			'_yoast_wpseo_title', '_yoast_wpseo_metadesc',
		];

		$dirty_comps = [];
		$n = $skip = $updated = $failed = 0;
		$cursor    = $offset;
		$remaining = $limit;

		$comp_meta_cache = [];

		while ( $remaining > 0 ) {
			$chunk = min( $batch, $remaining );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$mt} ORDER BY id ASC LIMIT %d OFFSET %d",
				$chunk, $cursor
			), ARRAY_A );

			if ( empty( $rows ) ) {
				break;
			}

			$comp_ids = $teamstep_ids = $stadium_ids = [];
			foreach ( $rows as $m ) {
				if ( ! empty( $m['competition_id'] ) )  { $comp_ids[]    = (int) $m['competition_id']; }
				if ( ! empty( $m['home_team_id'] ) )    { $teamstep_ids[] = (int) $m['home_team_id']; }
				if ( ! empty( $m['visitor_team_id'] ) ) { $teamstep_ids[] = (int) $m['visitor_team_id']; }
				if ( ! empty( $m['stadium_id'] ) )      { $stadium_ids[] = (int) $m['stadium_id']; }
			}

			$competition_map = F365_Match_Hydrator::resolve_ids( 'f365_competition', 'django_competition_id', $comp_ids );
			$stadium_map     = F365_Match_Hydrator::resolve_ids( 'f365_stadium',      'django_stadium_id',      $stadium_ids );
			$teamstep_map    = F365_Match_Hydrator::resolve_ids( 'f365_teamstep',     'django_teamstep_id',     $teamstep_ids );

			$team_names = [];
			$teamstep_to_real_team_legacy_ids = [];
			$real_team_legacy_ids = [];
			
			$unique_ts_ids = array_values( array_unique( array_filter( $teamstep_ids ) ) );
			if ( ! empty( $unique_ts_ids ) ) {
				$ts_in_clause = implode( ',', $unique_ts_ids );
				$name_results = $wpdb->get_results( "
					SELECT ts.id AS teamstep_id, ts.team_id AS real_team_id, t.shortname
					FROM {$tst} ts
					INNER JOIN {$tt} t ON ts.team_id = t.id
					WHERE ts.id IN ($ts_in_clause)
				", ARRAY_A );
				
				foreach ( (array) $name_results as $nr ) {
					$ts_id = (int) $nr['teamstep_id'];
					$t_id  = (int) $nr['real_team_id'];
					
					$team_names[ $ts_id ] = (string) $nr['shortname'];
					$teamstep_to_real_team_legacy_ids[ $ts_id ] = $t_id;
					$real_team_legacy_ids[] = $t_id;
				}
			}

			if ( ! empty( $real_team_legacy_ids ) ) {
				$team_map = F365_Match_Hydrator::resolve_ids( 'f365_team', 'django_team_id', array_unique( $real_team_legacy_ids ) );
			} else {
				$team_map = [];
			}

			$django_match_ids = array_map( fn( $m ) => (int) $m['id'], $rows );
			$existing_matches = [];
			if ( ! empty( $django_match_ids ) ) {
				$dm_in_clause = implode( ',', $django_match_ids );
				foreach ( (array) $wpdb->get_results(
					"SELECT ID AS post_id, legacy_id AS meta_value FROM {$wpdb->posts}
					 WHERE legacy_table = 'wp_competition_match' AND legacy_id IN ($dm_in_clause)",
					ARRAY_A
				) as $er ) {
					$existing_matches[ (int) $er['meta_value'] ] = (int) $er['post_id'];
				}
			}

			$posts_to_insert = [];
			$update_ids      = []; 
			$rows_by_id      = [];

			foreach ( $rows as $m ) {
				$django_id = (int) $m['id'];
				$rows_by_id[ $django_id ] = $m;

				$home_ts_id    = ! empty( $m['home_team_id'] )    ? (int) $m['home_team_id']    : 0;
				$visitor_ts_id = ! empty( $m['visitor_team_id'] ) ? (int) $m['visitor_team_id'] : 0;
				$home_team_name    = $team_names[ $home_ts_id ] ?? '';
				$visitor_team_name = $team_names[ $visitor_ts_id ] ?? '';

				if ( $home_team_name !== '' || $visitor_team_name !== '' ) {
					$title = trim( ( $home_team_name !== '' ? $home_team_name : 'Home' ) . ' vs ' . ( $visitor_team_name !== '' ? $visitor_team_name : 'Visitor' ) );
				} else {
					$title = 'Match #' . $django_id;
				}

				$post_status = $this->match_situation_to_status( $m['situation'] ?? '' );
				$ts        = ! empty( $m['date_time'] ) ? strtotime( (string) $m['date_time'] ) : false;
				$post_date = $ts ? date( 'Y-m-d H:i:s', $ts ) : '1970-01-01 00:00:00';

				$competition_wp_id = ! empty( $m['competition_id'] ) ? (int) ( $competition_map[ (int) $m['competition_id'] ] ?? 0 ) : 0;
				if ( $competition_wp_id ) { $dirty_comps[ $competition_wp_id ] = true; }

				$wp_id = $existing_matches[ $django_id ] ?? 0;
				if ( $wp_id ) {
					if ( ! $this->force ) {
						$skip++;
						continue;
					}
					$update_ids[ $django_id ] = $wp_id;
				} else {
					$posts_to_insert[] = [
						'django_id'   => $django_id,
						'post_title'  => $title,
						'post_status' => $post_status,
						'post_date'   => $post_date,
						'post_name'   => 'f365-match-' . $django_id,
					];
				}
			}

			// ── CONCURRENCY SAFE RETRY LOOP FOR TRANSACTIONS ──
			$attempts = 0;
			$success  = false;

			while ( $attempts < 5 && ! $success ) {
				$attempts++;
				$wpdb->query( 'START TRANSACTION' );
				try {
					$resolved = []; 

					if ( $posts_to_insert ) {
						$cols_sql = '(post_author, post_date, post_date_gmt, post_content, post_title, '
							. 'post_excerpt, post_status, comment_status, ping_status, post_password, '
							. 'post_name, to_ping, pinged, post_modified, post_modified_gmt, '
							. 'post_content_filtered, post_parent, guid, menu_order, post_type, '
							. 'post_mime_type, comment_count, legacy_table, legacy_id)';
						$row_ph = '(%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%s,%d,%s,%s,%d,%s,%d)';

						foreach ( array_chunk( $posts_to_insert, 500 ) as $piece ) {
							$ph = $va = [];
							foreach ( $piece as $p ) {
								$pd  = $p['post_date'];
								$gmt = get_gmt_from_date( $pd ) ?: $pd;
								$ph[] = $row_ph;
								array_push( $va,
									1, $pd, $gmt, '', $p['post_title'], '', $p['post_status'],
									'closed', 'closed', '', $p['post_name'], '', '', $pd, $gmt,
									'', 0, '', 0, 'f365_match', '', 0, 'wp_competition_match', (int) $p['django_id']
								);
							}
							$sql = "INSERT INTO {$wpdb->posts} {$cols_sql} VALUES " . implode( ',', $ph );
							if ( $wpdb->query( $wpdb->prepare( $sql, $va ) ) === false ) {
								throw new \RuntimeException( 'wp_posts INSERT failed: ' . $wpdb->last_error );
							}
						}

						$ins_ids   = implode( ',', array_map( fn( $p ) => (int) $p['django_id'], $posts_to_insert ) );
						$back_rows = $wpdb->get_results(
							"SELECT ID, legacy_id FROM {$wpdb->posts} WHERE legacy_table = 'wp_competition_match' AND legacy_id IN ($ins_ids)",
							ARRAY_A
						);
						foreach ( (array) $back_rows as $ncr ) {
							$d_id = (int) $ncr['legacy_id'];
							$w_id = (int) $ncr['ID'];
							$resolved[ $d_id ]        = $w_id;
							$existing_matches[ $d_id ] = $w_id;
							$n++;
						}
					}

					if ( $update_ids ) {
						$now     = current_time( 'mysql' );
						$now_gmt = current_time( 'mysql', 1 );
						foreach ( $update_ids as $d_id => $w_id ) {
							$m  = $rows_by_id[ $d_id ];
							$hn = ! empty( $m['home_team_id'] )    ? ( $team_names[ (int) $m['home_team_id'] ]    ?? '' ) : '';
							$vn = ! empty( $m['visitor_team_id'] ) ? ( $team_names[ (int) $m['visitor_team_id'] ] ?? '' ) : '';
							$ttl = ( $hn !== '' || $vn !== '' )
								? trim( ( $hn !== '' ? $hn : 'Home' ) . ' vs ' . ( $vn !== '' ? $vn : 'Visitor' ) )
								: 'Match #' . $d_id;
							$st = $this->match_situation_to_status( $m['situation'] ?? '' );
							if ( $wpdb->query( $wpdb->prepare(
								"UPDATE {$wpdb->posts} SET post_title=%s, post_status=%s, post_modified=%s, post_modified_gmt=%s, legacy_table='wp_competition_match', legacy_id=%d WHERE ID=%d",
								$ttl, $st, $now, $now_gmt, $d_id, $w_id
							) ) === false ) {
								throw new \RuntimeException( 'wp_posts UPDATE failed: ' . $wpdb->last_error );
							}
							$resolved[ $d_id ] = $w_id;
							$updated++;
						}
						$up_in    = implode( ',', array_map( 'intval', $update_ids ) );
						$keys_in  = "'" . implode( "','", array_map( 'esc_sql', $managed_keys ) ) . "'";
						if ( $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($up_in) AND meta_key IN ($keys_in)" ) === false ) {
							throw new \RuntimeException( 'postmeta wipe failed: ' . $wpdb->last_error );
						}
					}

					$meta_to_insert = [];
					$tax_term_relationships = [];

					foreach ( $resolved as $d_id => $w_id ) {
						$m  = $rows_by_id[ $d_id ];
						$competition_wp_id = ! empty( $m['competition_id'] ) ? (int) ( $competition_map[ (int) $m['competition_id'] ] ?? 0 ) : 0;
						$home_team_wp    = ! empty( $m['home_team_id'] )    ? (int) ( $teamstep_map[ (int) $m['home_team_id'] ]    ?? 0 ) : 0;
						$visitor_team_wp = ! empty( $m['visitor_team_id'] ) ? (int) ( $teamstep_map[ (int) $m['visitor_team_id'] ] ?? 0 ) : 0;

						$meta = [
							'date_time'               => $m['date_time'] ?? '',
							'match_round'             => (int) ( $m['match_round'] ?? 1 ),
							'phase'                   => $m['phase'] ?? '',
							'competition_phase_id'    => (int) ( $m['competition_phase_id'] ?? 0 ),
							'group_home_team'         => $m['group_home_team'] ?? '',
							'group_visitor_team'      => $m['group_visitor_team'] ?? '',
							'group_phase_round'       => $m['group_phase_round'] ?? '',
							'leg'                     => $m['leg'] ?? '',
							'situation'               => $m['situation'] ?? '',
							'home_score'              => ( isset( $m['home_score'] )    && $m['home_score']    !== null ) ? (int) $m['home_score']    : '',
							'visitor_score'           => ( isset( $m['visitor_score'] ) && $m['visitor_score'] !== null ) ? (int) $m['visitor_score'] : '',
							'home_score_extra'        => $m['home_score_extra'] ?? '',
							'visitor_score_extra'     => $m['visitor_score_extra'] ?? '',
							'penalty_home_score'      => $m['penalty_home_score'] ?? '',
							'penalty_visitor_score'   => $m['penalty_visitor_score'] ?? '',
							'duration'                => $m['duration'] ?? '',
							'assistance'              => $m['assistance'] ?? '',
							'observations'            => $m['observations'] ?? '',
							'django_match_id'         => $d_id,
							'django_competition_id'   => (int) ( $m['competition_id'] ?? 0 ),
							'django_home_team_id'     => (int) ( $m['home_team_id'] ?? 0 ),
							'django_visitor_team_id'  => (int) ( $m['visitor_team_id'] ?? 0 ),
							'django_stadium_id'       => (int) ( $m['stadium_id'] ?? 0 ),
							'django_phase_id'         => (int) ( $m['competition_phase_id'] ?? 0 ),
							'django_stats_id'         => (int) ( $m['stats_id'] ?? 0 ),
						];
						foreach ( $meta as $mk => $mv ) {
							$meta_to_insert[] = [ 'post_id' => $w_id, 'key' => $mk, 'val' => $mv ];
						}

						if ( ! empty( $m['situation'] ) ) {
							$term_id = $this->get_term_id_by_slug_or_create( $m['situation'], 'f365_match_status' );
							if ( $term_id ) { $tax_term_relationships[] = [ 'object_id' => $w_id, 'term_taxonomy_id' => $term_id ]; }
						}

						if ( $competition_wp_id ) {
							$meta_to_insert[] = [ 'post_id' => $w_id, 'key' => 'competition', 'val' => $competition_wp_id ];
						}

						$season = '';
						$competition_name = '';
						if ( $competition_wp_id ) {
							if ( ! isset( $comp_meta_cache[ $competition_wp_id ] ) ) {
								$comp_meta_cache[ $competition_wp_id ] = [
									'season' => (string) get_post_meta( $competition_wp_id, 'season', true ),
									'name'   => (string) get_the_title( $competition_wp_id ),
								];
							}
							$season           = $comp_meta_cache[ $competition_wp_id ]['season'];
							$competition_name = $comp_meta_cache[ $competition_wp_id ]['name'];
						}
						if ( $season === '' && ! empty( $m['competition_id'] ) ) {
							$season = (string) $wpdb->get_var( $wpdb->prepare( "SELECT season FROM {$ct} WHERE id = %d", (int) $m['competition_id'] ) );
						}
						if ( $season !== '' ) {
							$term_id = $this->get_term_id_by_slug_or_create( $season, 'f365_season' );
							if ( $term_id ) { $tax_term_relationships[] = [ 'object_id' => $w_id, 'term_taxonomy_id' => $term_id ]; }
						}

						if ( $home_team_wp ) {
							$meta_to_insert[] = [ 'post_id' => $w_id, 'key' => 'home_teamstep', 'val' => $home_team_wp ];
							$home_ts_legacy_id = ! empty( $m['home_team_id'] ) ? (int) $m['home_team_id'] : 0;
							$home_team_legacy_id = $teamstep_to_real_team_legacy_ids[ $home_ts_legacy_id ] ?? 0;
							$home_real_team_wp   = $team_map[ $home_team_legacy_id ] ?? 0;
							
							if ( $home_real_team_wp ) {
								$meta_to_insert[] = [ 'post_id' => $w_id, 'key' => 'home_team', 'val' => $home_real_team_wp ];
							}
						}

						if ( $visitor_team_wp ) {
							$meta_to_insert[] = [ 'post_id' => $w_id, 'key' => 'visitor_teamstep', 'val' => $visitor_team_wp ];
							$visitor_ts_legacy_id = ! empty( $m['visitor_team_id'] ) ? (int) $m['visitor_team_id'] : 0;
							$visitor_team_legacy_id = $teamstep_to_real_team_legacy_ids[ $visitor_ts_legacy_id ] ?? 0;
							$visitor_real_team_wp   = $team_map[ $visitor_team_legacy_id ] ?? 0;
							
							if ( $visitor_real_team_wp ) {
								$meta_to_insert[] = [ 'post_id' => $w_id, 'key' => 'visitor_team', 'val' => $visitor_real_team_wp ];
							}
						}

						if ( ! empty( $m['stadium_id'] ) ) {
							$stadium_wp = (int) ( $stadium_map[ (int) $m['stadium_id'] ] ?? 0 );
							if ( $stadium_wp ) {
								$meta_to_insert[] = [ 'post_id' => $w_id, 'key' => 'stadium', 'val' => $stadium_wp ];
							}
						}

						$home_team_name    = ! empty( $m['home_team_id'] )    ? ( $team_names[ (int) $m['home_team_id'] ]    ?? '' ) : '';
						$visitor_team_name = ! empty( $m['visitor_team_id'] ) ? ( $team_names[ (int) $m['visitor_team_id'] ] ?? '' ) : '';
						$result = '';
						if ( $meta['home_score'] !== '' && $meta['visitor_score'] !== '' ) {
							$result = $meta['home_score'] . '-' . $meta['visitor_score'];
						}
						$seo_title = trim( sprintf( '%s %s %s - Ficha do Jogo - %s %s - Futebol 365', $home_team_name, $result, $visitor_team_name, $competition_name, $season ) );
						$seo_desc  = sprintf( 'A ficha do jogo, com as principais ocorrências e estatísticas da partida entre %s e %s - %s %s.', $home_team_name, $visitor_team_name, $competition_name, $season );
						$meta_to_insert[] = [ 'post_id' => $w_id, 'key' => '_yoast_wpseo_title', 'val' => $seo_title ];
						$meta_to_insert[] = [ 'post_id' => $w_id, 'key' => '_yoast_wpseo_metadesc', 'val' => $seo_desc ];
					}

					if ( $meta_to_insert ) {
						foreach ( array_chunk( $meta_to_insert, 1000 ) as $piece ) {
							$ph = $va = [];
							foreach ( $piece as $mi ) {
								$ph[] = '(%d,%s,%s)';
								$va[] = (int) $mi['post_id'];
								$va[] = (string) $mi['key'];
								$va[] = (string) $mi['val'];
							}
							$sql = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $ph );
							if ( $wpdb->query( $wpdb->prepare( $sql, $va ) ) === false ) {
								throw new \RuntimeException( 'wp_postmeta INSERT failed: ' . $wpdb->last_error );
							}
						}
					}

					if ( $tax_term_relationships ) {
						foreach ( array_chunk( $tax_term_relationships, 1000 ) as $piece ) {
							$ph = $va = [];
							foreach ( $piece as $tr ) {
								$ph[] = '(%d,%d,0)';
								$va[] = (int) $tr['object_id'];
								$va[] = (int) $tr['term_taxonomy_id'];
							}
							$sql = "INSERT IGNORE INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order) VALUES " . implode( ',', $ph );
							if ( $wpdb->query( $wpdb->prepare( $sql, $va ) ) === false ) {
								throw new \RuntimeException( 'wp_term_relationships INSERT failed: ' . $wpdb->last_error );
							}
						}
					}

					$wpdb->query( 'COMMIT' );
					$success = true;
				} catch ( \Throwable $e ) {
					$wpdb->query( 'ROLLBACK' );
					
					// Detect deadlock / lock wait warnings, apply randomized sleep window, allow up to 5 attempts
					if ( str_contains( $e->getMessage(), 'Deadlock' ) || str_contains( $e->getMessage(), 'Lock wait' ) ) {
						usleep( rand( 50000, 150000 ) ); 
						continue; 
					}

					$failed += count( $posts_to_insert ) + count( $update_ids );
					$n      -= count( $posts_to_insert ); 
					if ( $n < 0 ) { $n = 0; }
					WP_CLI::warning( "Batch at offset {$cursor} rolled back permanently: " . $e->getMessage() );
					break; 
				}
			}

			$got        = count( $rows );
			$cursor    += $got;
			$remaining -= $got;

			if ( $got < $chunk ) {
				break;
			}

			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			} else {
				global $wp_object_cache;
				if ( is_object( $wp_object_cache ) && method_exists( $wp_object_cache, 'flush' ) ) {
					$wp_object_cache->flush();
				}
			}
		}

		if ( $dirty_comps ) {
			try {
				$this->add_dirty_competitions( array_keys( $dirty_comps ) );
			} catch ( \Exception $e ) {
			}
		}

		if ( function_exists( 'wp_defer_comment_counting' ) ) {
			wp_defer_comment_counting( false );
		}
		wp_defer_term_counting( false );
		wp_suspend_cache_addition( false );

		WP_CLI::log( "Batch execution done at offset {$offset}." );
	}

	private function get_term_id_by_slug_or_create( string $term_name, string $taxonomy ): int {
		global $wpdb;
		$slug = sanitize_title( $term_name );
		
		$term_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT tt.term_taxonomy_id FROM {$wpdb->terms} t 
			 INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id 
			 WHERE t.slug = %s AND tt.taxonomy = %s LIMIT 1",
			$slug, $taxonomy
		) );

		if ( $term_id ) {
			return $term_id;
		}

		$term = get_term_by( 'name', $term_name, $taxonomy );
		if ( $term ) {
			return (int) $term->term_taxonomy_id;
		}

		$new_term = wp_insert_term( $term_name, $taxonomy, [ 'slug' => $slug ] );
		if ( ! is_wp_error( $new_term ) && isset( $new_term['term_taxonomy_id'] ) ) {
			return (int) $new_term['term_taxonomy_id'];
		}

		return 0;
	}

    protected function process_match_details_json( int $match_wp, array $match_row = [], array $maps = [] ): void {
        if ( $this->dry_run || ! $match_wp ) {
            return;
        }
        if ( class_exists( 'F365_Match_Hydrator' ) ) {
            F365_Match_Hydrator::rehydrate( $match_wp );
        }
    }

    protected function import_match_details(): void {
        WP_CLI::log( 'match_details: no-op — match detail hydrated on demand by F365_Match_Hydrator.' );
    }

    protected function compute_standings(): void {
        global $wpdb;

        if ( $this->dirty ) {
            $comp_ids = $this->get_dirty_competitions();
            if ( ! $comp_ids ) {
                WP_CLI::success( 'Standings: no competitions marked dirty.' );
                return;
            }
        } else {
            $comp_ids = $this->get_all_posts_of_type( 'f365_competition' );
        }

        $total_comps = count($comp_ids);
        $progress = WP_CLI\Utils\make_progress_bar( 'Standings', $total_comps );
        $done = 0;
        $done_ids = [];

        $comp_ids_placeholders = implode( ',', array_map( 'intval', $comp_ids ) );
        if ( empty( $comp_ids_placeholders ) ) {
            WP_CLI::error( 'No active competitions discovered.' );
            return;
        }

        $raw_matches = $wpdb->get_results( "
            SELECT 
                cm.meta_value AS competition_id,
                ht.meta_value AS home_team, 
                vt.meta_value AS visitor_team,
                hs.meta_value AS home_score, 
                vs.meta_value AS visitor_score,
                gh.meta_value AS grp
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} cm ON cm.post_id = p.ID AND cm.meta_key = 'competition'
            INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = 'situation' AND st.meta_value = 'finished'
            LEFT  JOIN {$wpdb->postmeta} ht ON ht.post_id = p.ID AND ht.meta_key = 'home_team'
            LEFT  JOIN {$wpdb->postmeta} vt ON vt.post_id = p.ID AND vt.meta_key = 'visitor_team'
            LEFT  JOIN {$wpdb->postmeta} hs ON hs.post_id = p.ID AND hs.meta_key = 'home_score'
            LEFT  JOIN {$wpdb->postmeta} vs ON vs.post_id = p.ID AND vs.meta_key = 'visitor_score'
            LEFT  JOIN {$wpdb->postmeta} gh ON gh.post_id = p.ID AND gh.meta_key = 'group_home_team'
            WHERE p.post_type = 'f365_match' AND cm.meta_value IN ($comp_ids_placeholders)
        " );

        $matches_by_comp = [];
        foreach ( $raw_matches as $m ) {
            $matches_by_comp[ (int) $m->competition_id ][] = $m;
        }
        unset($raw_matches);

        foreach ( $comp_ids as $comp_id ) {
            $progress->tick();
            $comp_id = (int) $comp_id;
            $done_ids[] = $comp_id;

            $comp_matches = $matches_by_comp[ $comp_id ] ?? [];
            if ( ! $comp_matches ) { 
                continue; 
            }

            $vp = (int) ( get_post_meta( $comp_id, 'victory_points', true ) ?: 3 );
            $dp = (int) ( get_post_meta( $comp_id, 'draw_points', true ) ?: 1 );

            $offsets = $this->classification_offsets( $comp_id );
            $rules   = $this->classification_order( $comp_id );

            $table  = [];
            $scoped = [];

            foreach ( $comp_matches as $mm ) {
                if ( $mm->home_score === null || $mm->home_score === '' || $mm->visitor_score === null || $mm->visitor_score === '' ) continue;
                $hg = (int) $mm->home_score; $vg = (int) $mm->visitor_score;
                $grp = (string) ( $mm->grp ?? '' );
                $home = (int) $mm->home_team; $vis = (int) $mm->visitor_team;
                if ( ! $home || ! $vis ) continue;

                foreach ( [ '', $grp ] as $g ) {
                    $scoped[ $g ][] = [ 'home' => $home, 'visitor' => $vis, 'hg' => $hg, 'vg' => $vg ];
                    foreach ( [ $home, $vis ] as $tid ) {
                        if ( ! isset( $table[ $g ][ $tid ] ) ) {
                            $table[ $g ][ $tid ] = [ 'J'=>0,'V'=>0,'E'=>0,'D'=>0,'GM'=>0,'GS'=>0,'P'=>0 ];
                        }
                    }
                    $table[ $g ][ $home ]['J']++; $table[ $g ][ $vis ]['J']++;
                    $table[ $g ][ $home ]['GM'] += $hg; $table[ $g ][ $home ]['GS'] += $vg;
                    $table[ $g ][ $vis ]['GM']  += $vg; $table[ $g ][ $vis ]['GS']  += $hg;
                    if ( $hg > $vg ) {
                        $table[ $g ][ $home ]['V']++; $table[ $g ][ $home ]['P'] += $vp;
                        $table[ $g ][ $vis ]['D']++;
                    } elseif ( $hg < $vg ) {
                        $table[ $g ][ $vis ]['V']++; $table[ $g ][ $vis ]['P'] += $vp;
                        $table[ $g ][ $home ]['D']++;
                    } else {
                        $table[ $g ][ $home ]['E']++; $table[ $g ][ $home ]['P'] += $dp;
                        $table[ $g ][ $vis ]['E']++;  $table[ $g ][ $vis ]['P']  += $dp;
                    }
                    if ( $grp === '' ) break;
                }
            }

            foreach ( $offsets as $off ) {
                $tid = (int) $off->team_id;
                if ( ! $tid ) continue;
                foreach ( $table as $g => $teams ) {
                    if ( $off->group !== '' && $off->group !== $g ) continue;
                    if ( isset( $table[ $g ][ $tid ] ) ) { $table[ $g ][ $tid ]['P'] += (int) $off->offset; }
                }
            }

            $rows = [];
            foreach ( $table as $grp => $teams ) {
                if ( $rules ) {
                    $by_points = [];
                    foreach ( $teams as $tid => $s ) { $by_points[ (int) $s['P'] ][] = (int) $tid; }
                    krsort( $by_points );
                    $ordered = [];
                    foreach ( $by_points as $cluster ) {
                        foreach ( $this->cluster_sort( $cluster, $teams, $scoped[ $grp ] ?? [], $rules, $vp, $dp ) as $tid ) {
                            $ordered[] = (int) $tid;
                        }
                    }
                } else {
                    $ordered = array_keys( $teams );
                    usort( $ordered, fn( $a, $b ) =>
                        [ $teams[$b]['P'], $teams[$b]['GM']-$teams[$b]['GS'], $teams[$b]['GM'] ]
                        <=> [ $teams[$a]['P'], $teams[$a]['GM']-$teams[$a]['GS'], $teams[$a]['GM'] ]
                    );
                }

                $pos = 0;
                foreach ( $ordered as $tid ) {
                    $s = $teams[ $tid ];
                    $pos++;
                    $rows[] = [
                        'team'          => (int) $tid,
                        'group'         => (string) $grp,
                        'position'      => $pos,
                        'played'        => (int)$s['J'],
                        'won'           => (int)$s['V'],
                        'drawn'         => (int)$s['E'],
                        'lost'          => (int)$s['D'],
                        'goals_for'     => (int)$s['GM'],
                        'goals_against' => (int)$s['GS'],
                        'goal_diff'     => (int) ( $s['GM'] - $s['GS'] ),
                        'points'        => (int) $s['P'],
                        'color'         => '',
                    ];
                }
            }

            $this->set_repeater( $comp_id, 'standings', $rows );
            $done++;
		}

		$progress->finish();

		if ( $this->dirty && $done_ids ) {
			$this->clear_dirty_competitions( $done_ids );
		}

		WP_CLI::success( "Standings: {$done} competitions computed." );
	}

	private function match_situation_to_status( string $situation ): string {
		return str_contains(strtolower($situation), 'abrogate') ? 'draft' : 'publish';
	}
}