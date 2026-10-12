<?php
/**
 * Futebol365 — Live Games: config + CLI shell builder + lazy hydrator (single file).
 *
 * Contents:
 * 1. F365_LiveGames_Config         — table names (incl. relational lookup tables),
 * stats columns, ACF key maps, competition map
 * 2. trait F365_Module_LiveGames   — WP-CLI parent-shell builder (import_live_games),
 * now with RELATIONAL TITLE RESOLUTION
 * 3. F365_LiveGames_Lazy_Hydrator — on-demand hydration; players are a fully
 * decoupled, self-diagnosing, retry-safe pass (ensure_players); now also
 * writes flat competition lookup metadata (name/season/edition)
 * 4. Lifecycle hooks              — template_redirect + load-post.php +
 * save_post_f365_livegames triggers, a "Re-sync" admin action, and an
 * ACF `$_POST['acf']` re-seed guard for the competition lookup fields
 *
 * @package Futebol365
 * @since   2.9.3
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'F365_LiveGames_Config' ) ) {
final class F365_LiveGames_Config {

	/** Legacy (cloned) live-game table names. Adjust here if your prefix differs. */
	const T_MATCH       = 'wp_live_livematch';
	const T_STATS       = 'wp_live_livematchstats';
	const T_COACH       = 'wp_live_livematchcoach';
	const T_FORMATION   = 'wp_live_livematchformation';
	const T_EVENT       = 'wp_live_livematchevent';
	const T_NARRATION   = 'wp_live_livenarration';
	const T_PLAYER      = 'wp_live_livematchplayer';
	const T_PLAYEREVENT = 'wp_live_livematchplayerevent';

	/** Relational lookup tables (cloned to the WP database alongside wp_live_*). */
	const T_TEAM        = 'wp_team_team';
	const T_TEAMSTEP    = 'wp_team_teamstep';
	const T_COMPETITION = 'wp_competition_competition';

	/**
	 * Team-name resolution path. wp_live_livematch stores NULL team-name columns;
	 * the two teams of a match live in the COMPETITION MATCHES table, which holds the 
	 * home / visitor TEAM-STEP ids. Those resolve to a team shortname through the 
	 * teamstep → team lookup tables.
	 */
	const T_COMP_MATCH = 'wp_competition_match';

	/** Accepted column-name variants for the two team-step id columns. */
	public static function home_ts_col_variants(): array {
		return [ 'home_team_id', 'home_team_step_id', 'home_teamstep_id', 'home_step_id' ];
	}
	public static function visitor_ts_col_variants(): array {
		return [ 'visitor_team_id', 'visitor_team_step_id', 'visitor_teamstep_id', 'visitor_step_id' ];
	}

	const POST_TYPE        = 'f365_livegames';
	const PLAYER_POST_TYPE = 'f365_livegame_player';
	const LEGACY_TABLE_TAG = 'wp_live_livematch';

	const COMPETITION_CPT         = 'f365_competition';
	const COMPETITION_LEGACY_META = 'django_competition_id';
	const COMPETITION_REL_META    = 'competition';
	const SEASON_TAXONOMY         = 'f365_season';

	/** CPT rules for matching core game entities */
	const MATCH_CPT         = 'f365_match';
	const MATCH_LEGACY_META = 'django_match_id';
	const MATCH_REL_META    = 'match';

	/** Flat 1:1 statistics columns shared by stats table and post meta. */
	public static function get_stats_cols(): array {
		return [
			'home_ball_possession','home_shots','home_shots_on_goal','home_fouls','home_cautions','home_expulsions',
			'home_corner_kicks','home_offsides','home_saves','home_goals','home_goals_penalty','home_own_goals',
			'home_substitutions','home_yellow_cards','home_double_yellow_cards','home_red_cards','home_throw_ins','home_free_kicks',
			'visitor_ball_possession','visitor_shots','visitor_shots_on_goal','visitor_fouls','visitor_cautions','visitor_expulsions',
			'visitor_corner_kicks','visitor_offsides','visitor_saves','visitor_goals','visitor_goals_penalty','visitor_own_goals',
			'visitor_substitutions','visitor_yellow_cards','visitor_double_yellow_cards','visitor_red_cards','visitor_throw_ins','visitor_free_kicks',
		];
	}

	/** Boolean field names on the LiveMatch row (string 't'/'f' in source). */
	public static function get_match_bool_fields(): array {
		return [ 'is_mapped', 'goal', 'external_audio_narration' ];
	}

	/** Flat competition lookup meta key => ACF field key. */
	public static function competition_meta_map(): array {
		return [
			'live_competition_name'    => 'field_lg_comp_name',
			'live_competition_season'  => 'field_lg_comp_season',
			'live_competition_edition' => 'field_lg_comp_edition',
		];
	}

	/** name => ACF field key for the f365_livegames group. */
	public static function field_key_map(): array {
		$map = [
			'datetime'                 => 'field_lg_datetime',
			'live_status'              => 'field_lg_live_status',
			'is_mapped'                => 'field_lg_is_mapped',
			'goal'                     => 'field_lg_goal',
			'external_audio_narration' => 'field_lg_audio_narration',
			'finished_at'              => 'field_lg_finished_at',
			'home_team_name'           => 'field_lg_home_team_name',
			'visitor_team_name'        => 'field_lg_visitor_team_name',
			'home_score'               => 'field_lg_home_score',
			'visitor_score'            => 'field_lg_visitor_score',
			'home_score_extra'         => 'field_lg_home_score_extra',
			'visitor_score_extra'      => 'field_lg_visitor_score_extra',
			'penalty_home_score'       => 'field_lg_penalty_home_score',
			'penalty_visitor_score'    => 'field_lg_penalty_visitor_score',
			'django_livematch_id'      => 'field_lg_django_id',
			'django_match_id'          => 'field_lg_django_match_id',
			'django_competition_id'    => 'field_lg_django_comp_id',
			'match'                    => 'field_lg_match', // Added ACF Post Object target field mapping
			// repeaters
			'live_coaches'             => 'field_lg_coaches',
			'live_formations'          => 'field_lg_formations',
			'live_events'              => 'field_lg_live_events',
			'live_narrations'          => 'field_f365_live_narrations',
		];
		foreach ( self::get_stats_cols() as $col ) {
			$map[ $col ] = 'field_lg_' . $col;
		}
		foreach ( self::competition_meta_map() as $flat_key => $field_key ) {
			$map[ $flat_key ] = $field_key;
		}
		return $map;
	}

	/** name => ACF field key for the f365_livegame_player group. */
	public static function player_field_key_map(): array {
		return [
			'live_game'             => 'field_lgp_live_game',
			'player_name'           => 'field_lgp_player_name',
			'player_number'         => 'field_lgp_player_number',
			'player_order'          => 'field_lgp_player_order',
			'is_field_player'       => 'field_lgp_is_field',
			'is_home_team'          => 'field_lgp_is_home',
			'django_livematch_id'   => 'field_lgp_django_id',
			'external_player_id'    => 'field_lgp_django_ext_pid',
			'player_team_shortname' => 'field_lgp_team_shortname',
			'player_events'         => 'field_lgp_events',
		];
	}
}
}

/* ============================================================================
 * 2. WP-CLI MIGRATION TRAIT — shell builder only.
 * ========================================================================== */
if ( ! trait_exists( 'F365_Module_LiveGames' ) ) {
trait F365_Module_LiveGames {

	protected function import_live_games(): void {
		global $wpdb;

		$src = F365_LiveGames_Config::T_MATCH;

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $src ) ) !== $src ) {
			WP_CLI::warning( "Source table {$src} not found — nothing to import." );
			return;
		}

		if ( ! $this->dry_run ) {
			$this->lg_ensure_legacy_columns();
		}

		$resolve_ready = $this->lg_resolution_ready();
		if ( $resolve_ready ) {
			WP_CLI::log( 'LiveGames: match-based title resolution ENABLED.' );
		} else {
			WP_CLI::warning( 'LiveGames: Dependency tables missing — titles reconciled lazily later.' );
		}

		wp_suspend_cache_addition( true );
		wp_defer_term_counting( true );

		$post_type = F365_LiveGames_Config::POST_TYPE;
		$tag       = F365_LiveGames_Config::LEGACY_TABLE_TAG;
		$now_gmt   = current_time( 'mysql', true );
		$now_local = current_time( 'mysql' );

		$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$src}" );
		$limit     = ( $this->limit === PHP_INT_MAX ) ? $total : min( $this->limit, $total );
		$offset    = $this->offset;
		$batch     = max( 1, $this->batch );
		$inserted  = 0;
		$skipped   = 0;

		$select_cols = 'id, datetime, competition_id, match_id, home_team_name, visitor_team_name';

		WP_CLI::log( "LiveGames: {$total} source rows; importing up to {$limit}." );

		while ( $offset < $total && $inserted < $limit ) {

			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT {$select_cols} FROM {$src} ORDER BY id ASC LIMIT %d OFFSET %d",
				$batch, $offset
			), ARRAY_A );

			if ( empty( $rows ) ) {
				break;
			}

			$ids = array_map( static fn( $r ) => (int) $r['id'], $rows );
			$existing = [];
			if ( $ids ) {
				$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$sql = $wpdb->prepare(
					"SELECT legacy_id FROM {$wpdb->posts} WHERE post_type = %s AND legacy_table = %s AND legacy_id IN ($ph)",
					array_merge( [ $post_type, $tag ], $ids )
				);
				$existing = array_flip( array_map( 'intval', (array) $wpdb->get_col( $sql ) ) );
			}

			$team_map = [];
			if ( $resolve_ready ) {
				$match_ids = array_map( static fn( $r ) => (int) ( $r['match_id'] ?? 0 ), $rows );
				$team_map  = $this->lg_resolve_titles_for_batch( $match_ids );
			}

			$values   = [];
			$args     = [];
			$row_tpl  = '(%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%s,%d,%s,%s,%d,%s,%d)';

			foreach ( $rows as $lm ) {
				if ( $inserted >= $limit ) {
					break;
				}
				$django_id = (int) $lm['id'];

				if ( isset( $existing[ $django_id ] ) ) {
					$skipped++;
					continue;
				}

				$match_id = (int) ( $lm['match_id'] ?? 0 );
				$resolved = $team_map[ $match_id ] ?? [];

				$home = (string) ( $resolved['home'] ?? '' );
				$vis  = (string) ( $resolved['visitor'] ?? '' );

				if ( $home === '' ) { $home = trim( (string) ( $lm['home_team_name']    ?? '' ) ); }
				if ( $vis  === '' ) { $vis  = trim( (string) ( $lm['visitor_team_name'] ?? '' ) ); }

				if ( $home !== '' || $vis !== '' ) {
					$title = trim( ( $home !== '' ? $home : 'Home Team' ) . ' vs ' . ( $vis !== '' ? $vis : 'Visitor Team' ) );
				} else {
					$title = 'Home Team vs Visitor Team';
				}

				$ts    = $lm['datetime'] ? strtotime( (string) $lm['datetime'] ) : 0;
				$date  = $ts ? date( 'Y-m-d H:i:s', $ts ) : $now_local;
				$gmt   = $ts ? get_gmt_from_date( $date ) : $now_gmt;
				$slug  = 'lm-' . $django_id;
				$guid  = home_url( '/?post_type=' . $post_type . '&legacy_id=' . $django_id );

				if ( $this->dry_run ) {
					$inserted++;
					continue;
				}

				$values[] = $row_tpl;
				array_push(
					$args,
					1, $date, $gmt, '', $title, '', 'publish', 'closed', 'closed', '', $slug, '', '',
					$date, $gmt, '', 0, $guid, 0, $post_type, '', 0, $tag, $django_id
				);

				$inserted++;
			}

			if ( $values ) {
				$cols = '(post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt,'
					. ' post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged,'
					. ' post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order,'
					. ' post_type, post_mime_type, comment_count, legacy_table, legacy_id)';

				$query = "INSERT INTO {$wpdb->posts} {$cols} VALUES " . implode( ',', $values );
				$wpdb->query( $wpdb->prepare( $query, $args ) );
			}

			$offset += $batch;
			wp_cache_flush();
		}

		wp_suspend_cache_addition( false );
		wp_defer_term_counting( false );
		WP_CLI::success( "LiveGames shells complete." );
	}

	private function lg_resolve_titles_for_batch( array $match_ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $match_ids ) ) ) );
		if ( ! $ids ) {
			return [];
		}

		$schema = F365_LiveGames_Lazy_Hydrator::comp_match_schema();
		if ( ! $schema ) {
			return [];
		}

		$cm = $schema['table'];
		$hc = $schema['home'];
		$vc = $schema['visitor'];
		$ts = F365_LiveGames_Config::T_TEAMSTEP;
		$tt = F365_LiveGames_Config::T_TEAM;

		$ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT cm.id AS match_id, th.shortname AS home_name, tv.shortname AS visitor_name
			   FROM {$cm} cm
			   LEFT JOIN {$ts} tsh ON tsh.id = cm.{$hc}
			   LEFT JOIN {$tt} th  ON th.id  = tsh.team_id
			   LEFT JOIN {$ts} tsv ON tsv.id = cm.{$vc}
			   LEFT JOIN {$tt} tv  ON tv.id  = tsv.team_id
			  WHERE cm.id IN ($ph)",
			$ids
		), ARRAY_A );

		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['match_id'] ] = [
				'home'    => trim( (string) ( $r['home_name']    ?? '' ) ),
				'visitor' => trim( (string) ( $r['visitor_name'] ?? '' ) ),
			];
		}
		return $out;
	}

	private function lg_resolution_ready(): bool {
		static $ready = null;
		if ( $ready !== null ) {
			return $ready;
		}
		global $wpdb;
		foreach ( [ F365_LiveGames_Config::T_TEAMSTEP, F365_LiveGames_Config::T_TEAM ] as $tbl ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) {
				return $ready = false;
			}
		}
		return $ready = ( F365_LiveGames_Lazy_Hydrator::comp_match_schema() !== null );
	}

	private function lg_ensure_legacy_columns(): void {
		global $wpdb;

		$cols = (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME IN ('legacy_table','legacy_id')",
			DB_NAME, $wpdb->posts
		) );

		if ( ! in_array( 'legacy_table', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->posts} ADD COLUMN legacy_table VARCHAR(64) NULL DEFAULT NULL" );
		}
		if ( ! in_array( 'legacy_id', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->posts} ADD COLUMN legacy_id BIGINT NULL DEFAULT NULL" );
		}

		$idx = $wpdb->get_var( $wpdb->prepare(
			"SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'f365_legacy' LIMIT 1",
			DB_NAME, $wpdb->posts
		) );
		if ( ! $idx ) {
			$wpdb->query( "ALTER TABLE {$wpdb->posts} ADD INDEX f365_legacy (legacy_table, legacy_id)" );
		}
	}
}
}

/* ============================================================================
 * 3. LAZY HYDRATOR SUBSYSTEM
 * ========================================================================== */
if ( ! class_exists( 'F365_LiveGames_Lazy_Hydrator' ) ) {
final class F365_LiveGames_Lazy_Hydrator {

	const FLAG_KEY         = '_is_hydrated';
	const PLAYERS_FLAG_KEY = '_lg_players_done';
	const LOCK_TTL         = 60;

	const NAMES_FLAG_KEY = '_lg_names_v';
	const NAMES_VERSION  = '2.9.3';

	public static function pg_bool( $val ): int {
		if ( is_bool( $val ) ) { return $val ? 1 : 0; }
		if ( $val === null ) { return 0; }
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
		}
	}

	private static function table_exists( string $table ): bool {
		static $seen = [];
		if ( isset( $seen[ $table ] ) ) { return $seen[ $table ]; }
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $seen[ $table ] = ( $found === $table );
	}

	public static function comp_match_schema(): ?array {
		static $done = false;
		static $resolved = null;
		if ( $done ) { return $resolved; }
		$done = true;

		global $wpdb;
		$home_variants = F365_LiveGames_Config::home_ts_col_variants();
		$vis_variants  = F365_LiveGames_Config::visitor_ts_col_variants();
		$all_variants  = array_merge( $home_variants, $vis_variants );

		$ph   = implode( ',', array_fill( 0, count( $all_variants ), '%s' ) );
		$cols = $wpdb->get_results( $wpdb->prepare(
			"SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND COLUMN_NAME IN ($ph)",
			array_merge( [ DB_NAME ], $all_variants )
		), ARRAY_A );

		$by_table = [];
		foreach ( (array) $cols as $r ) {
			$by_table[ $r['t'] ][] = $r['c'];
		}

		$candidates = array_keys( $by_table );
		usort( $candidates, static function ( $a, $b ) {
			$sa = ( strpos( $a, 'competition_match' ) !== false ) ? 1 : 0;
			$sb = ( strpos( $b, 'competition_match' ) !== false ) ? 1 : 0;
			return $sb <=> $sa;
		} );
		array_unshift( $candidates, F365_LiveGames_Config::T_COMP_MATCH );

		foreach ( $candidates as $tbl ) {
			if ( ! $tbl ) { continue; }
			$tcols = $by_table[ $tbl ] ?? null;
			if ( $tcols === null ) {
				$tcols = (array) $wpdb->get_col( $wpdb->prepare( "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s", DB_NAME, $tbl ) );
			}
			$home = ''; $vis = '';
			foreach ( $home_variants as $hv ) { if ( in_array( $hv, $tcols, true ) ) { $home = $hv; break; } }
			foreach ( $vis_variants as $vv ) { if ( in_array( $vv, $tcols, true ) ) { $vis = $vv; break; } }
			if ( $home !== '' && $vis !== '' && self::table_exists( $tbl ) ) {
				return $resolved = [ 'table' => $tbl, 'home' => $home, 'visitor' => $vis ];
			}
		}
		return $resolved = null;
	}

	public static function resolve_match_teams( int $match_id ): array {
		$empty = [ 'home' => '', 'visitor' => '' ];
		if ( $match_id <= 0 ) { return $empty; }

		static $cache = [];
		if ( isset( $cache[ $match_id ] ) ) { return $cache[ $match_id ]; }

		$schema = self::comp_match_schema();
		if ( ! $schema ) { return $cache[ $match_id ] = $empty; }

		global $wpdb;
		$cm = $schema['table']; $hc = $schema['home']; $vc = $schema['visitor'];
		$ts = F365_LiveGames_Config::T_TEAMSTEP; $tt = F365_LiveGames_Config::T_TEAM;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT th.shortname AS home_name, tv.shortname AS visitor_name
			   FROM {$cm} cm
			   LEFT JOIN {$ts} tsh ON tsh.id = cm.{$hc}
			   LEFT JOIN {$tt} th  ON th.id  = tsh.team_id
			   LEFT JOIN {$ts} tsv ON tsv.id = cm.{$vc}
			   LEFT JOIN {$tt} tv  ON tv.id  = tsv.team_id
			  WHERE cm.id = %d LIMIT 1",
			$match_id
		), ARRAY_A );

		return $cache[ $match_id ] = [
			'home'    => trim( (string) ( $row['home_name']    ?? '' ) ),
			'visitor' => trim( (string) ( $row['visitor_name'] ?? '' ) ),
		];
	}

	public static function rehydrate( int $post_id ): void {
		if ( ! $post_id ) { return; }
		delete_post_meta( $post_id, self::FLAG_KEY );
		delete_post_meta( $post_id, self::PLAYERS_FLAG_KEY );
		self::hydrate( $post_id );
	}

	public static function hydrate( int $wp_post_id ): bool {
		global $wpdb;
		if ( ! $wp_post_id ) { return false; }

		if ( '1' === get_post_meta( $wp_post_id, self::FLAG_KEY, true ) ) {
			self::ensure_core_identity( $wp_post_id );
			self::ensure_players( $wp_post_id );
			return true;
		}

		$lock = 'f365_lg_lock_' . $wp_post_id;
		if ( get_transient( $lock ) ) { return false; }
		set_transient( $lock, 1, self::LOCK_TTL );

		try {
			$post = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_title, post_date, legacy_id, post_type FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $wp_post_id ), ARRAY_A );
			if ( ! $post || $post['post_type'] !== F365_LiveGames_Config::POST_TYPE ) { return false; }

			$legacy_id = (int) $post['legacy_id'] ?: (int) get_post_meta( $wp_post_id, 'django_livematch_id', true );
			if ( ! $legacy_id ) { return false; }

			$match = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . F365_LiveGames_Config::T_MATCH . ' WHERE id = %d LIMIT 1', $legacy_id ), ARRAY_A );
			if ( ! $match ) {
				update_post_meta( $wp_post_id, self::FLAG_KEY, '1' );
				return true;
			}

			$keys = F365_LiveGames_Config::field_key_map();
			$g    = static fn( $k ) => array_key_exists( $k, $match ) ? $match[ $k ] : null;

			$match_id  = (int) ( $g( 'match_id' ) ?? 0 );
			$resolved  = self::resolve_match_teams( $match_id );
			$home_name = $resolved['home'] !== '' ? $resolved['home'] : trim( (string) ( $g( 'home_team_name' )    ?? '' ) );
			$vis_name  = $resolved['visitor'] !== '' ? $resolved['visitor'] : trim( (string) ( $g( 'visitor_team_name' ) ?? '' ) );

			if ( $home_name !== '' || $vis_name !== '' ) {
				$reconciled = trim( ( $home_name !== '' ? $home_name : 'Home Team' ) . ' vs ' . ( $vis_name !== '' ? $vis_name : 'Visitor Team' ) );
			} else {
				$reconciled = 'Home Team vs Visitor Team';
			}
			if ( $reconciled !== '' && $reconciled !== (string) ( $post['post_title'] ?? '' ) ) {
				static $title_writing = false;
				if ( ! $title_writing ) {
					$title_writing = true;
					wp_update_post( [ 'ID' => $wp_post_id, 'post_title' => $reconciled ] );
					$title_writing = false;
				}
			}
			if ( $home_name !== '' || $vis_name !== '' ) {
				update_post_meta( $wp_post_id, self::NAMES_FLAG_KEY, self::NAMES_VERSION );
			}

			// ── BLOCK 1: core match fields ─────────────────────────────────
			$core = [
				'datetime'                 => (string) ( $g( 'datetime' ) ?? '' ),
				'live_status'              => (string) ( $g( 'status' ) ?? '' ),
				'is_mapped'                => self::pg_bool( $g( 'is_mapped' ) ),
				'goal'                     => self::pg_bool( $g( 'goal' ) ),
				'external_audio_narration' => self::pg_bool( $g( 'external_audio_narration' ) ),
				'finished_at'              => (string) ( $g( 'finished_at' ) ?? '' ),
				'home_team_name'           => $home_name,
				'visitor_team_name'        => $vis_name,
				'home_score'               => (string) ( $g( 'home_score' ) ?? '' ),
				'visitor_score'            => (string) ( $g( 'visitor_score' ) ?? '' ),
				'home_score_extra'         => (string) ( $g( 'home_score_extra' ) ?? '' ),
				'visitor_score_extra'      => (string) ( $g( 'visitor_score_extra' ) ?? '' ),
				'penalty_home_score'       => (string) ( $g( 'penalty_home_score' ) ?? '' ),
				'penalty_visitor_score'    => (string) ( $g( 'penalty_visitor_score' ) ?? '' ),
				'django_livematch_id'      => $legacy_id,
				'django_match_id'          => $match_id,
				'django_competition_id'    => (int) ( $g( 'competition_id' ) ?? 0 ),
			];
			foreach ( $core as $name => $val ) {
				self::set( $wp_post_id, $name, $keys[ $name ], $val );
			}

			// ── BLOCK 2: stats ─────────────────────────────────────────────
			self::guard( 'stats', $legacy_id, static function () use ( $wpdb, $match, $wp_post_id, $keys ) {
				$stats_id = isset( $match['stats_id'] ) ? (int) $match['stats_id'] : 0;
				if ( ! $stats_id || ! self::table_exists( F365_LiveGames_Config::T_STATS ) ) { return; }
				$stats = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . F365_LiveGames_Config::T_STATS . ' WHERE id = %d LIMIT 1', $stats_id ), ARRAY_A );
				if ( ! $stats ) { return; }
				foreach ( F365_LiveGames_Config::get_stats_cols() as $col ) {
					if ( array_key_exists( $col, $stats ) && $stats[ $col ] !== null && $stats[ $col ] !== '' ) {
						self::set( $wp_post_id, $col, $keys[ $col ], (int) $stats[ $col ] );
					}
				}
			} );

			// ── BLOCK 3: coaches repeater ──────────────────────────────────
			self::guard( 'coaches', $legacy_id, static function () use ( $wpdb, $legacy_id, $wp_post_id, $keys ) {
				if ( ! self::table_exists( F365_LiveGames_Config::T_COACH ) ) { return; }
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT coach_name, is_home_team FROM ' . F365_LiveGames_Config::T_COACH . ' WHERE live_match_id = %d', $legacy_id ), ARRAY_A );
				if ( ! $rows ) { return; }
				$rep = array_map( static fn( $c ) => [ 'coach_name' => (string) ( $c['coach_name'] ?? '' ), 'is_home_team' => self::pg_bool( $c['is_home_team'] ?? null ) ], $rows );
				self::set( $wp_post_id, 'live_coaches', $keys['live_coaches'], $rep );
			} );

			// ── BLOCK 4: formations repeater ───────────────────────────────
			self::guard( 'formations', $legacy_id, static function () use ( $wpdb, $legacy_id, $wp_post_id, $keys ) {
				if ( ! self::table_exists( F365_LiveGames_Config::T_FORMATION ) ) { return; }
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT formation, is_home_team FROM ' . F365_LiveGames_Config::T_FORMATION . ' WHERE live_match_id = %d', $legacy_id ), ARRAY_A );
				if ( ! $rows ) { return; }
				$rep = array_map( static fn( $f ) => [ 'formation' => (string) ( $f['formation'] ?? '' ), 'is_home_team' => self::pg_bool( $f['is_home_team'] ?? null ) ], $rows );
				self::set( $wp_post_id, 'live_formations', $keys['live_formations'], $rep );
			} );

			// ── BLOCK 5: match events repeater ─────────────────────────────
			self::guard( 'events', $legacy_id, static function () use ( $wpdb, $legacy_id, $wp_post_id, $keys ) {
				if ( ! self::table_exists( F365_LiveGames_Config::T_EVENT ) ) { return; }
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT event_type, player_name, minute, is_home_team FROM ' . F365_LiveGames_Config::T_EVENT . ' WHERE live_match_id = %d ORDER BY minute ASC', $legacy_id ), ARRAY_A );
				if ( ! $rows ) { return; }
				$rep = array_map( static fn( $e ) => [ 'minute' => (int) ( $e['minute'] ?? 0 ), 'event_type' => (string) ( $e['event_type'] ?? '' ), 'player_name' => (string) ( $e['player_name'] ?? '' ), 'is_home_team' => self::pg_bool( $e['is_home_team'] ?? null ) ], $rows );
				self::set( $wp_post_id, 'live_events', $keys['live_events'], $rep );
			} );

			// ── BLOCK 6: narrations repeater ───────────────────────────────
			self::guard( 'narrations', $legacy_id, static function () use ( $wpdb, $legacy_id, $wp_post_id, $keys ) {
				if ( ! self::table_exists( F365_LiveGames_Config::T_NARRATION ) ) { return; }
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT minute, minute_extra, period, event, description, source_description, external_id FROM ' . F365_LiveGames_Config::T_NARRATION . ' WHERE live_match_id = %d ORDER BY id ASC', $legacy_id ), ARRAY_A );
				if ( ! $rows ) { return; }
				$rep = array_map( static fn( $n ) => [ 'minute' => (int) ( $n['minute'] ?? 0 ), 'minute_extra' => (int) ( $n['minute_extra'] ?? 0 ), 'period' => (int) ( $n['period'] ?? 0 ), 'event' => (string) ( $n['event'] ?? '' ), 'description' => (string) ( $n['description'] ?? '' ), 'source_description' => (string) ( $n['source_description'] ?? '' ), 'external_id' => (int) ( $n['external_id'] ?? 0 ) ], $rows );
				self::set( $wp_post_id, 'live_narrations', $keys['live_narrations'], $rep );
			} );

			// ── BLOCK 7: COMPETITION & MATCH RELATIONS (UPDATED & SEPARATED) ──
			self::guard( 'competition', $legacy_id, static function () use ( $wpdb, $match, $wp_post_id, $keys, $match_id ) {
				$comp_id = isset( $match['competition_id'] ) ? (int) $match['competition_id'] : 0;
				
				// 1. Fetch text strings from legacy database if the table exists
				if ( $comp_id && self::table_exists( F365_LiveGames_Config::T_COMPETITION ) ) {
					$comp = $wpdb->get_row( $wpdb->prepare(
						'SELECT name, season, edition FROM ' . F365_LiveGames_Config::T_COMPETITION . ' WHERE id = %d LIMIT 1',
						$comp_id
					), ARRAY_A );

					if ( $comp ) {
						$vals = [
							'live_competition_name'    => (string) ( $comp['name'] ?? '' ),
							'live_competition_season'  => (string) ( $comp['season'] ?? '' ),
							'live_competition_edition' => (string) ( $comp['edition'] ?? '' ),
						];
						foreach ( $vals as $mkey => $mval ) {
							update_post_meta( $wp_post_id, $mkey, $mval );
							self::set( $wp_post_id, $mkey, $keys[ $mkey ], $mval );
						}

						// Set Taxonomy Term for f365_season using edition year
						$edition = trim( (string) ( $comp['edition'] ?? '' ) );
						if ( $edition !== '' && taxonomy_exists( F365_LiveGames_Config::SEASON_TAXONOMY ) ) {
							wp_set_object_terms( $wp_post_id, [ $edition ], F365_LiveGames_Config::SEASON_TAXONOMY, false );
						}
					}
				}

				// 2. Perform WordPress Post-to-Post Linkage for Competition
				if ( $comp_id ) {
					$comp_wp_id = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT pm.post_id 
						   FROM {$wpdb->postmeta} pm
						  INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
						  WHERE pm.meta_key = %s AND pm.meta_value = %d AND p.post_type = %s
						  LIMIT 1",
						F365_LiveGames_Config::COMPETITION_LEGACY_META,
						$comp_id,
						F365_LiveGames_Config::COMPETITION_CPT
					) );
					
					if ( $comp_wp_id ) {
						update_post_meta( $wp_post_id, F365_LiveGames_Config::COMPETITION_REL_META, $comp_wp_id );
					}
				}

				// 3. Perform WordPress Post-to-Post Linkage for Match Obj Field (RESOLVER ADDED)
				if ( $match_id ) {
					$match_wp_id = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT pm.post_id 
						   FROM {$wpdb->postmeta} pm
						  INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
						  WHERE pm.meta_key = %s AND pm.meta_value = %d AND p.post_type = %s
						  LIMIT 1",
						F365_LiveGames_Config::MATCH_LEGACY_META,
						$match_id,
						F365_LiveGames_Config::MATCH_CPT
					) );
					
					if ( $match_wp_id ) {
						// Hydrate through custom router set() to ensure both flat meta and ACF index match up
						self::set( $wp_post_id, F365_LiveGames_Config::MATCH_REL_META, $keys['match'], $match_wp_id );
					}
				}
			} );

			// ── BLOCK 8: child player posts ────────────────────────────────
			self::ensure_players( $wp_post_id );

			// ── BLOCK 9: Yoast SEO ─────────────────────────────────────────
			self::guard( 'seo', $legacy_id, static function () use ( $wp_post_id, $post, $core ) {
				update_post_meta( $wp_post_id, '_yoast_wpseo_title', sprintf( 'Jogo em direto - %s - Futebol 365', $post['post_title'] ) );
				update_post_meta( $wp_post_id, '_yoast_wpseo_metadesc', sprintf( 'O jogo em direto, com as principais ocorrências e estatísticas da partida entre %s e %s', $core['home_team_name'], $core['visitor_team_name'] ) );
			} );

			update_post_meta( $wp_post_id, self::FLAG_KEY, '1' );
			return true;

		} catch ( \Throwable $e ) {
			error_log( '[F365 LiveGames] hydrate fatal: ' . $e->getMessage() );
			return false;
		} finally {
			delete_transient( $lock );
		}
	}

	private static function guard( string $label, int $legacy_id, callable $fn ): void {
		try { $fn(); } catch ( \Throwable $e ) { error_log( "[F365 LiveGames] block '{$label}' failed: " . $e->getMessage() ); }
	}

	public static function ensure_core_identity( int $wp_post_id ): void {
		if ( ! $wp_post_id ) { return; }
		if ( (string) get_post_meta( $wp_post_id, self::NAMES_FLAG_KEY, true ) === self::NAMES_VERSION ) { return; }

		global $wpdb;
		try {
			$post = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_title, legacy_id, post_type FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $wp_post_id ), ARRAY_A );
			if ( ! $post || $post['post_type'] !== F365_LiveGames_Config::POST_TYPE ) { return; }

			$legacy_id = (int) $post['legacy_id'] ?: (int) get_post_meta( $wp_post_id, 'django_livematch_id', true );
			if ( ! $legacy_id ) { return; }

			$match = $wpdb->get_row( $wpdb->prepare( 'SELECT match_id, home_team_name, visitor_team_name FROM ' . F365_LiveGames_Config::T_MATCH . ' WHERE id = %d LIMIT 1', $legacy_id ), ARRAY_A );
			if ( ! $match ) {
				update_post_meta( $wp_post_id, self::NAMES_FLAG_KEY, self::NAMES_VERSION );
				return;
			}

			$keys      = F365_LiveGames_Config::field_key_map();
			$match_id  = (int) ( $match['match_id'] ?? 0 );
			$resolved  = self::resolve_match_teams( $match_id );
			$home_name = $resolved['home'] !== '' ? $resolved['home'] : trim( (string) ( $match['home_team_name']    ?? '' ) );
			$vis_name  = $resolved['visitor'] !== '' ? $resolved['visitor'] : trim( (string) ( $match['visitor_team_name'] ?? '' ) );

			self::set( $wp_post_id, 'home_team_name',    $keys['home_team_name'],    $home_name );
			self::set( $wp_post_id, 'visitor_team_name', $keys['visitor_team_name'], $vis_name );

			if ( $home_name !== '' || $vis_name !== '' ) {
				$reconciled = trim( ( $home_name !== '' ? $home_name : 'Home Team' ) . ' vs ' . ( $vis_name !== '' ? $vis_name : 'Visitor Team' ) );
			} else {
				$reconciled = 'Home Team vs Visitor Team';
			}
			if ( $reconciled !== '' && $reconciled !== (string) ( $post['post_title'] ?? '' ) ) {
				static $title_writing = false;
				if ( ! $title_writing ) {
					$title_writing = true;
					wp_update_post( [ 'ID' => $wp_post_id, 'post_title' => $reconciled ] );
					$title_writing = false;
				}
			}

			if ( $home_name !== '' || $vis_name !== '' ) {
				update_post_meta( $wp_post_id, self::NAMES_FLAG_KEY, self::NAMES_VERSION );
			}
		} catch ( \Throwable $e ) {
			error_log( "[F365 LiveGames] ensure_core_identity failed: " . $e->getMessage() );
		}
	}

	public static function ensure_players( int $parent_wp_id ): bool {
		global $wpdb;
		if ( ! $parent_wp_id ) { return false; }
		if ( '1' === get_post_meta( $parent_wp_id, self::PLAYERS_FLAG_KEY, true ) ) { return true; }

		try {
			$parent = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_date, legacy_id, post_type FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $parent_wp_id ), ARRAY_A );
			if ( ! $parent || $parent['post_type'] !== F365_LiveGames_Config::POST_TYPE ) { return false; }

			$legacy_id = (int) $parent['legacy_id'] ?: (int) get_post_meta( $parent_wp_id, 'django_livematch_id', true );
			if ( ! $legacy_id ) { return false; }

			$player_table = F365_LiveGames_Config::T_PLAYER;
			if ( ! self::table_exists( $player_table ) ) { return false; }

			$home_team = (string) get_post_meta( $parent_wp_id, 'home_team_name', true );
			$vis_team  = (string) get_post_meta( $parent_wp_id, 'visitor_team_name', true );

			$players = $wpdb->get_results( $wpdb->prepare(
				'SELECT id, external_player_id, player_name, player_number, player_order, is_field_player, is_home_team FROM ' . $player_table . ' WHERE live_match_id = %d ORDER BY is_home_team DESC, player_order ASC, id ASC',
				$legacy_id
			), ARRAY_A );

			if ( $wpdb->last_error ) { return false; }
			if ( empty( $players ) ) {
				update_post_meta( $parent_wp_id, self::PLAYERS_FLAG_KEY, '1' );
				return true;
			}

			$pkeys           = F365_LiveGames_Config::player_field_key_map();
			$parent_date     = (string) ( $parent['post_date'] ?: current_time( 'mysql' ) );
			$has_player_evts = self::table_exists( F365_LiveGames_Config::T_PLAYEREVENT );

			foreach ( $players as $p ) {
				$src_id = (int) $p['id'];
				$name   = trim( preg_replace( '/[\x00-\x1F\x7F]/u', '', (string) ( $p['player_name'] ?? '' ) ) );
				$number = trim( (string) ( $p['player_number'] ?? '' ) );
				
				if ( $name === '' ) { $title = 'Player #' . $src_id; }
				elseif ( $number !== '' ) { $title = "({$number}) {$name}"; }
				else { $title = $name; }

				$player_wp_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = 'django_live_livematchplayer_id' AND pm.meta_value = %d AND p.post_type = %s LIMIT 1",
					$src_id, F365_LiveGames_Config::PLAYER_POST_TYPE
				) );

				if ( $player_wp_id ) {
					if ( get_post_field( 'post_title', $player_wp_id ) !== $title ) {
						wp_update_post( [ 'ID' => $player_wp_id, 'post_title' => $title ] );
					}
				} else {
					$res = wp_insert_post( [
						'post_title'     => $title,
						'post_status'    => 'publish',
						'post_type'      => F365_LiveGames_Config::PLAYER_POST_TYPE,
						'post_parent'    => $parent_wp_id,
						'post_date'      => $parent_date,
						'comment_status' => 'closed',
						'ping_status'    => 'closed',
					], true );
					if ( is_wp_error( $res ) ) { continue; }
					$player_wp_id = (int) $res;
				}

				update_post_meta( $player_wp_id, 'django_live_livematchplayer_id', $src_id );

				$is_home        = self::pg_bool( $p['is_home_team'] ?? null );
				$team_shortname = $is_home ? $home_team : $vis_team;

				$pmeta = [
					'live_game'             => $parent_wp_id,
					'player_name'           => $name,
					'player_number'         => (int) ( $p['player_number'] ?? 0 ),
					'player_order'          => (int) ( $p['player_order'] ?? 0 ),
					'is_field_player'       => self::pg_bool( $p['is_field_player'] ?? null ),
					'is_home_team'          => $is_home,
					'external_player_id'    => (int) ( $p['external_player_id'] ?? 0 ),
					'django_livematch_id'   => $legacy_id,
				];
				if ( $team_shortname !== '' ) { $pmeta['player_team_shortname'] = $team_shortname; }

				foreach ( $pmeta as $pn => $pv ) {
					if ( isset( $pkeys[ $pn ] ) ) { self::set( $player_wp_id, $pn, $pkeys[ $pn ], $pv ); }
				}

				if ( $has_player_evts ) {
					$pe = $wpdb->get_results( $wpdb->prepare( 'SELECT event_type, minute FROM ' . F365_LiveGames_Config::T_PLAYEREVENT . ' WHERE live_match_player_id = %d ORDER BY minute ASC, id ASC', $src_id ), ARRAY_A );
					if ( $pe ) {
						$rep = array_map( static fn( $e ) => [ 'minute' => (int) ( $e['minute'] ?? 0 ), 'event_type' => (string) ( $e['event_type'] ?? '' ) ], $pe );
						self::set( $player_wp_id, 'player_events', $pkeys['player_events'], $rep );
					}
				}
			}

			update_post_meta( $parent_wp_id, self::PLAYERS_FLAG_KEY, '1' );
			return true;
		} catch ( \Throwable $e ) {
			error_log( "[F365 LiveGames] ensure_players fatal: " . $e->getMessage() );
			return false;
		}
	}
}
}

/* ============================================================================
 * 4. LIFECYCLE HOOKS
 * ========================================================================== */

/*if ( ! function_exists( 'f365_livegames_maybe_hydrate' ) ) {
	add_action( 'template_redirect', 'f365_livegames_maybe_hydrate' );
	function f365_livegames_maybe_hydrate(): void {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return; }
		if ( ! is_singular( F365_LiveGames_Config::POST_TYPE ) ) { return; }
		$id = get_queried_object_id();
		if ( $id && class_exists( 'F365_LiveGames_Lazy_Hydrator' ) ) {
			F365_LiveGames_Lazy_Hydrator::hydrate( (int) $id );
		}
	}
}

if ( ! function_exists( 'f365_livegames_admin_load' ) ) {
	add_action( 'load-post.php', 'f365_livegames_admin_load' );
	function f365_livegames_admin_load(): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		$post_id = isset( $_REQUEST['post'] ) ? (int) $_REQUEST['post'] : 0;
		if ( ! $post_id || get_post_type( $post_id ) !== F365_LiveGames_Config::POST_TYPE ) { return; }
		if ( class_exists( 'F365_LiveGames_Lazy_Hydrator' ) ) {
			F365_LiveGames_Lazy_Hydrator::hydrate( $post_id );
		}
	}
}

if ( ! function_exists( 'f365_livegames_after_save' ) ) {
	add_action( 'save_post_f365_livegames', 'f365_livegames_after_save', 20, 3 );
	function f365_livegames_after_save( $post_id, $post, $update ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) { return; }
		if ( class_exists( 'F365_LiveGames_Lazy_Hydrator' ) ) {
			F365_LiveGames_Lazy_Hydrator::ensure_players( (int) $post_id );
		}
	}
}

if ( ! function_exists( 'f365_livegames_row_actions' ) ) {
	add_filter( 'post_row_actions', 'f365_livegames_row_actions', 10, 2 );
	function f365_livegames_row_actions( array $actions, WP_Post $post ): array {
		if ( $post->post_type === F365_LiveGames_Config::POST_TYPE && current_user_can( 'edit_post', $post->ID ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=f365_lg_rehydrate&post=' . $post->ID ), 'f365_lg_rehydrate_' . $post->ID );
			$actions['f365_rehydrate'] = '<a href="' . esc_url( $url ) . '">Re-sync from legacy</a>';
		}
		return $actions;
	}
}

if ( ! function_exists( 'f365_livegames_handle_rehydrate' ) ) {
	add_action( 'admin_post_f365_lg_rehydrate', 'f365_livegames_handle_rehydrate' );
	function f365_livegames_handle_rehydrate(): void {
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'f365_lg_rehydrate_' . $post_id );
		if ( class_exists( 'F365_LiveGames_Lazy_Hydrator' ) ) {
			F365_LiveGames_Lazy_Hydrator::rehydrate( $post_id );
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=' . F365_LiveGames_Config::POST_TYPE ) );
		exit;
	}
}

if ( ! function_exists( 'f365_livegames_acf_protect' ) ) {
	add_action( 'save_post_f365_livegames', 'f365_livegames_acf_protect', 1, 1 );
	function f365_livegames_acf_protect( $post_id ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) { return; }
		if ( empty( $_POST['acf'] ) || ! is_array( $_POST['acf'] ) ) { return; }
		if ( get_post_type( $post_id ) !== F365_LiveGames_Config::POST_TYPE ) { return; }

		$keys = F365_LiveGames_Config::field_key_map();

		$reseed = [ 'home_team_name', 'visitor_team_name' ];
		foreach ( F365_LiveGames_Config::competition_meta_map() as $flat_key => $unused ) {
			$reseed[] = $flat_key;
		}
		foreach ( $reseed as $name ) {
			if ( ! isset( $keys[ $name ] ) ) { continue; }
			$field_key = $keys[ $name ];
			$incoming  = $_POST['acf'][ $field_key ] ?? null;
			if ( $incoming === null || $incoming === '' ) {
				$persisted = get_post_meta( $post_id, $name, true );
				if ( $persisted !== '' && $persisted !== null ) {
					$_POST['acf'][ $field_key ] = $persisted;
				}
			}
		}

		$shield = [ 'live_coaches', 'live_formations', 'live_events', 'live_narrations' ];
		foreach ( F365_LiveGames_Config::get_stats_cols() as $col ) { $shield[] = $col; }
		foreach ( $shield as $name ) {
			if ( ! isset( $keys[ $name ] ) ) { continue; }
			$field_key = $keys[ $name ];
			if ( ! array_key_exists( $field_key, $_POST['acf'] ) ) { continue; }
			$incoming = $_POST['acf'][ $field_key ];
			if ( $incoming === null || $incoming === '' || $incoming === [] ) {
				unset( $_POST['acf'][ $field_key ] );
			}
		}
	}
}*/