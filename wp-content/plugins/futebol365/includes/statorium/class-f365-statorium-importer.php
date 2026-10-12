<?php
/**
 * Statorium → Futebol365 importer (legacy in-plugin module).
 * Only loads when the f365-statorium plugin is inactive (see loader).
 *
 * Writes Statorium data into the SAME structure the legacy Django migration
 * produced. Public numeric IDs: `django_*_id` = OFFSET + statorium_id.
 * API identity: wp_posts.api_source / api_id columns + `statorium_*_id` meta.
 *
 * OWNERSHIP POLICY — old data is never modified unless required:
 *  - NEW entities: created fully, stamped `_f365_source=statorium`.
 *  - LINKED legacy entities (matched by exact title): additive keys only.
 *  - STATORIUM-OWNED entities: fully refreshed on every sync.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

class F365_Statorium_Importer {

	const ID_OFFSET  = 90000000;
	const OPTION     = 'f365_statorium_settings';
	const DB_VERSION = '1';
	const API_SOURCE = 'statorium';

	private const TYPE_POST_TYPE = [
		'season' => 'f365_competition',
		'team'   => 'f365_team',
		'player' => 'f365_player',
		'match'  => 'f365_match',
		'venue'  => 'f365_stadium',
	];

	private const REQUIRED_FILL_KEYS = [
		'django_id',
		'django_player_id',
		'django_match_id',
		'django_stadium_id',
		'django_competition_id',
	];

	private F365_Statorium_API $api;
	private array $map_cache = [];
	private $log_handle = null;
	public array $stats = [];

	public function __construct( ?string $api_key = null ) {
		$settings  = self::settings();
		$key       = $api_key;
		if ( null === $key && defined( 'F365_STATORIUM_API_KEY' ) && F365_STATORIUM_API_KEY ) {
			$key = (string) F365_STATORIUM_API_KEY;
		}
		$this->api = new F365_Statorium_API( $key ?? (string) $settings['api_key'] );
		self::ensure_schema();
	}

	public static function settings(): array {
		$defaults = [
			'api_key'     => '',
			'seasons'     => '',
			'cron'        => 0,
			'update_mode' => 'strict',
		];
		$saved = get_option( self::OPTION, [] );
		return is_array( $saved ) ? array_merge( $defaults, $saved ) : $defaults;
	}

	public function api(): F365_Statorium_API {
		return $this->api;
	}

	public static function pub_id( int $statorium_id ): int {
		return self::ID_OFFSET + $statorium_id;
	}

	private function mode(): string {
		return (string) self::settings()['update_mode'];
	}

	// ── Schema: api_source / api_id columns ─────────────────────────────────

	public static function ensure_schema(): void {
		if ( get_option( 'f365_statorium_db_version' ) ) {
			return; // any version — columns exist
		}
		global $wpdb;

		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->posts}", 0 );
		if ( ! in_array( 'api_source', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->posts} ADD COLUMN api_source VARCHAR(32) NULL DEFAULT NULL" );
		}
		if ( ! in_array( 'api_id', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->posts} ADD COLUMN api_id BIGINT UNSIGNED NULL DEFAULT NULL" );
		}
		$idx = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->posts} WHERE Key_name = 'api_source_id'" );
		if ( ! $idx ) {
			$wpdb->query( "ALTER TABLE {$wpdb->posts} ADD INDEX api_source_id (api_source, api_id)" );
		}

		foreach ( array_keys( self::TYPE_POST_TYPE ) as $type ) {
			$map = json_decode( (string) get_option( "f365_stat_map_{$type}", '{}' ), true );
			foreach ( (array) $map as $stat_id => $wp_id ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->posts} SET api_source = %s, api_id = %d WHERE ID = %d AND api_id IS NULL",
					self::API_SOURCE, (int) $stat_id, (int) $wp_id
				) );
			}
		}

		update_option( 'f365_statorium_db_version', self::DB_VERSION, false );
	}

	private function set_api_columns( int $wp_id, int $stat_id ): void {
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'api_source' => self::API_SOURCE, 'api_id' => $stat_id ], [ 'ID' => $wp_id ] );
		clean_post_cache( $wp_id );
	}

	// ── Season ──────────────────────────────────────────────────────────────

	public function import_season( int $season_id ): int {
		$data = $this->api->season( $season_id );
		if ( is_wp_error( $data ) || empty( $data['season'] ) ) {
			$this->log( "FAIL season={$season_id} — " . ( is_wp_error( $data ) ? $data->get_error_message() : 'empty payload' ) );
			return 0;
		}
		$s     = $data['season'];
		$title = (string) ( $s['fullName'] ?: ( $s['seasonName'] ?? "Season {$season_id}" ) );

		$wp_id = $this->upsert( 'season', $season_id, [ 'post_title' => $title ], $title );
		if ( ! $wp_id ) {
			return 0;
		}
		$legacy = $this->is_legacy( $wp_id );

		$this->write_fields( $wp_id, [
			'season'                => (string) ( $s['seasonName'] ?? '' ),
			'django_competition_id' => $this->pub_or_keep( $wp_id, 'django_competition_id', $season_id ),
			'statorium_season_id'   => $season_id,
			'statorium_league_id'   => (int) ( $s['leagueID'] ?? 0 ),
			'statorium_groups'      => wp_json_encode( $s['groups'] ?? [] ),
		], $legacy );

		if ( ! empty( $s['seasonName'] ) ) {
			$this->set_term_guarded( $wp_id, (string) $s['seasonName'], 'f365_season', $legacy );
		}

		$this->log( "OK season={$season_id} \"{$title}\" → wp_id={$wp_id}" );
		$this->bump( 'seasons' );
		return $wp_id;
	}

	// ── Teams / players ─────────────────────────────────────────────────────

	public function import_teams( int $season_id, bool $with_players = true ): array {
		$data = $this->api->season_matches( $season_id );
		if ( is_wp_error( $data ) || empty( $data['calendar']['matchdays'] ) ) {
			$this->log( "FAIL teams season={$season_id} — cannot load calendar" );
			return [];
		}

		$participants = [];
		foreach ( $data['calendar']['matchdays'] as $md ) {
			foreach ( (array) ( $md['matches'] ?? [] ) as $m ) {
				foreach ( [ 'homeParticipant', 'awayParticipant' ] as $side ) {
					$p = $m[ $side ] ?? null;
					if ( $p && ! empty( $p['participantID'] ) ) {
						$participants[ (int) $p['participantID'] ] = $p;
					}
				}
			}
		}

		$out = [];
		foreach ( $participants as $stat_id => $p ) {
			$wp_id = $this->upsert_team( $stat_id, $p );
			if ( $wp_id ) {
				$out[ $stat_id ] = $wp_id;
				if ( $with_players ) {
					$this->import_team_players( $stat_id, $wp_id, $season_id );
				}
			}
		}

		$this->log( 'SUMMARY teams season=' . $season_id . ' imported=' . count( $out ) );
		return $out;
	}

	private function upsert_team( int $stat_id, array $p ): int {
		$name = $this->pt_name( $p, (string) ( $p['participantName'] ?? "Team {$stat_id}" ) );

		$wp_id = $this->upsert( 'team', $stat_id, [ 'post_title' => $name ], $name );
		if ( ! $wp_id ) {
			return 0;
		}
		$legacy = $this->is_legacy( $wp_id );

		$this->write_fields( $wp_id, [
			'shortname'         => (string) ( $p['teamShortName'] ?? '' ),
			'longname'          => (string) ( $p['participantName'] ?? $name ),
			'team_type'         => 'national',
			'django_id'         => $this->pub_or_keep( $wp_id, 'django_id', $stat_id ),
			'statorium_team_id' => $stat_id,
		], $legacy );

		$this->set_term_guarded( $wp_id, $name, 'f365_country', $legacy );

		if ( ! empty( $p['logo'] ) ) {
			$this->set_thumbnail_guarded( $wp_id, (string) $p['logo'], $name, $legacy );
		}

		$this->bump( 'teams' );
		return $wp_id;
	}

	public function import_team_players( int $team_stat_id, int $team_wp_id, int $season_id ): int {
		$data = $this->api->team( $team_stat_id, $season_id );
		if ( is_wp_error( $data ) || empty( $data['team']['players'] ) ) {
			return 0;
		}

		$count = 0;
		foreach ( (array) $data['team']['players'] as $pl ) {
			if ( empty( $pl['playerID'] ) ) {
				continue;
			}
			if ( $this->upsert_player( (int) $pl['playerID'], $pl, $team_wp_id ) ) {
				$count++;
			}
		}
		$this->log( "OK squad team={$team_stat_id} players={$count}" );
		return $count;
	}

	private function upsert_player( int $stat_id, array $pl, int $team_wp_id ): int {
		$name = (string) ( ( $pl['fullName'] ?? '' ) ?: trim( ( $pl['firstName'] ?? '' ) . ' ' . ( $pl['lastName'] ?? '' ) ) );
		if ( '' === $name ) {
			return 0;
		}

		$wp_id = $this->upsert( 'player', $stat_id, [ 'post_title' => $name ], $name );
		if ( ! $wp_id ) {
			return 0;
		}
		$legacy = $this->is_legacy( $wp_id );

		$info      = (array) ( $pl['additionalInfo'] ?? [] );
		$birthdate = $this->parse_birthdate( (string) ( $info['birthdate'] ?? '' ) );
		$pub       = $this->pub_or_keep( $wp_id, 'django_player_id', $stat_id );

		if ( ! $legacy ) {
			$post = get_post( $wp_id );
			if ( $post && '' === $post->post_name ) {
				wp_update_post( [ 'ID' => $wp_id, 'post_name' => (string) $pub ] );
			}
		}

		$this->write_fields( $wp_id, [
			'firstname'           => (string) ( $pl['firstName'] ?? '' ),
			'lastname'            => (string) ( $pl['lastName'] ?? '' ),
			'shortname'           => (string) ( $pl['shortName'] ?? '' ),
			'fullname'            => $name,
			'birthdate'           => $birthdate,
			'height'              => (string) ( $info['height'] ?? '' ),
			'weight'              => (string) ( $info['weight'] ?? '' ),
			'player_number'       => (string) ( $pl['playerNumber'] ?? '' ),
			'current_team'        => $team_wp_id,
			'django_player_id'    => $pub,
			'statorium_player_id' => $stat_id,
		], $legacy );

		if ( ! empty( $info['position'] ) ) {
			$this->set_term_guarded( $wp_id, $this->map_position( (string) $info['position'] ), 'f365_position', $legacy );
		}
		if ( ! empty( $pl['country']['name'] ) ) {
			$this->set_term_guarded( $wp_id, (string) $pl['country']['name'], 'f365_country', $legacy );
		}
		if ( ! empty( $pl['photo'] ) ) {
			$this->set_thumbnail_guarded( $wp_id, (string) $pl['photo'], $name, $legacy );
		}

		$this->bump( 'players' );
		return $wp_id;
	}

	// ── Matches ─────────────────────────────────────────────────────────────

	public function import_matches( int $season_id ): int {
		$data = $this->api->season_matches( $season_id );
		if ( is_wp_error( $data ) || empty( $data['calendar']['matchdays'] ) ) {
			$this->log( "FAIL matches season={$season_id} — cannot load calendar" );
			return 0;
		}

		$comp_wp_id  = $this->map_resolve( 'season', $season_id ) ?: $this->import_season( $season_id );
		$season_name = (string) ( $data['calendar']['seasonName'] ?? '' );

		$count = 0;
		foreach ( $data['calendar']['matchdays'] as $md ) {
			$round_no = 0;
			if ( preg_match( '/MatchDay\s+(\d+)/i', (string) ( $md['matchdayName'] ?? '' ), $mm ) ) {
				$round_no = (int) $mm[1];
			}
			$phase = $round_no ? '' : (string) ( $md['matchdayName'] ?? '' );

			foreach ( (array) ( $md['matches'] ?? [] ) as $m ) {
				if ( empty( $m['matchID'] ) ) {
					continue;
				}
				if ( $this->upsert_match( (int) $m['matchID'], $m, $comp_wp_id, $season_id, $round_no, $phase, $season_name ) ) {
					$count++;
				}
			}
		}

		$this->log( "SUMMARY matches season={$season_id} upserted={$count}" );
		return $count;
	}

	private function upsert_match( int $stat_id, array $m, int $comp_wp_id, int $season_id, int $round_no, string $phase, string $season_name ): int {
		$home = (array) ( $m['homeParticipant'] ?? [] );
		$away = (array) ( $m['awayParticipant'] ?? [] );

		$home_wp = ! empty( $home['participantID'] ) ? (int) $this->map_resolve( 'team', (int) $home['participantID'] ) : 0;
		$away_wp = ! empty( $away['participantID'] ) ? (int) $this->map_resolve( 'team', (int) $away['participantID'] ) : 0;

		$home_name = $this->pt_name( $home, (string) ( $home['participantName'] ?? '?' ) );
		$away_name = $this->pt_name( $away, (string) ( $away['participantName'] ?? '?' ) );
		$title     = "{$home_name} - {$away_name}";

		$wp_id = $this->upsert( 'match', $stat_id, [ 'post_title' => $title ], '' );
		if ( ! $wp_id ) {
			return 0;
		}
		$legacy = $this->is_legacy( $wp_id );

		$stadium_wp = 0;
		$venue_stat = (int) ( $m['matchVenue']['venueID'] ?? 0 );
		$venue_name = (string) ( $m['matchVenue']['venueName'] ?? '' );
		if ( $venue_stat && $venue_name ) {
			$stadium_wp = $this->upsert_venue( $venue_stat, $venue_name );
		}

		$status_id = (string) ( $m['matchStatus']['statusID'] ?? $m['matchStatus']['value'] ?? '0' );
		$situation = $this->map_status( $status_id );
		$is_extra  = ! empty( $m['extraTime']['is_extra'] ) && '0' !== (string) $m['extraTime']['is_extra'];

		$date_time = trim( ( $m['matchDate'] ?? '' ) . ' ' . ( $m['matchTime'] ?? '' ) );
		if ( $date_time && strlen( $date_time ) <= 16 ) {
			$date_time .= ':00';
		}

		$meta = [
			'date_time'              => $date_time,
			'match_round'            => $round_no ?: 1,
			'phase'                  => $phase,
			'situation'              => $situation,
			'home_score'             => isset( $home['score'] ) && '' !== (string) $home['score'] ? (int) $home['score'] : '',
			'visitor_score'          => isset( $away['score'] ) && '' !== (string) $away['score'] ? (int) $away['score'] : '',
			'home_score_extra'       => $is_extra ? (int) ( $m['extraTime']['home_score'] ?? 0 ) : '',
			'visitor_score_extra'    => $is_extra ? (int) ( $m['extraTime']['away_score'] ?? 0 ) : '',
			'django_match_id'        => $this->pub_or_keep( $wp_id, 'django_match_id', $stat_id ),
			'django_competition_id'  => $comp_wp_id ? (int) get_post_meta( $comp_wp_id, 'django_competition_id', true ) : '',
			'django_home_team_id'    => $home_wp ? (int) get_post_meta( $home_wp, 'django_id', true ) : '',
			'django_visitor_team_id' => $away_wp ? (int) get_post_meta( $away_wp, 'django_id', true ) : '',
			'django_stadium_id'      => $stadium_wp ? (int) get_post_meta( $stadium_wp, 'django_id', true ) : '',
			'statorium_match_id'     => $stat_id,
			'statorium_status_id'    => $status_id,
			'statorium_matchday_id'  => (int) ( $m['matchdayID'] ?? 0 ),
		];

		if ( $comp_wp_id ) {
			$meta['competition'] = $comp_wp_id;
		}
		if ( $home_wp ) {
			$meta['home_team']     = $home_wp;
			$meta['home_teamstep'] = $home_wp;
		}
		if ( $away_wp ) {
			$meta['visitor_team']     = $away_wp;
			$meta['visitor_teamstep'] = $away_wp;
		}
		if ( $stadium_wp ) {
			$meta['stadium'] = $stadium_wp;
		}

		$this->write_fields( $wp_id, $meta, $legacy );

		if ( $situation ) {
			$this->set_term_guarded( $wp_id, $situation, 'f365_match_status', $legacy );
		}
		if ( $season_name ) {
			$this->set_term_guarded( $wp_id, $season_name, 'f365_season', $legacy );
		}

		$this->bump( 'matches' );
		return $wp_id;
	}

	public function upsert_venue( int $stat_id, string $name ): int {
		$existing = $this->map_resolve( 'venue', $stat_id );
		if ( $existing ) {
			return $existing;
		}

		$wp_id = $this->upsert( 'venue', $stat_id, [ 'post_title' => $name ], $name );
		if ( ! $wp_id ) {
			return 0;
		}
		$legacy = $this->is_legacy( $wp_id );

		$this->write_fields( $wp_id, [
			'django_id'          => $this->pub_or_keep( $wp_id, 'django_id', $stat_id ),
			'django_stadium_id'  => $this->pub_or_keep( $wp_id, 'django_stadium_id', $stat_id ),
			'statorium_venue_id' => $stat_id,
		], $legacy );

		$this->bump( 'venues' );
		return $wp_id;
	}

	// ── Live tick ───────────────────────────────────────────────────────────

	public function sync_live(): int {
		$data = $this->api->live_matches();
		if ( is_wp_error( $data ) ) {
			return 0;
		}

		$matches = [];
		foreach ( ( $data['matches'] ?? $data['calendar']['matchdays'] ?? [] ) as $entry ) {
			if ( isset( $entry['matchID'] ) ) {
				$matches[] = $entry;
			} elseif ( isset( $entry['matches'] ) ) {
				foreach ( (array) $entry['matches'] as $m ) {
					$matches[] = $m;
				}
			}
		}

		$updated = 0;
		foreach ( $matches as $m ) {
			$stat_id = (int) ( $m['matchID'] ?? 0 );
			$wp_id   = $stat_id ? $this->map_resolve( 'match', $stat_id ) : null;
			if ( ! $wp_id ) {
				continue;
			}

			$home = (array) ( $m['homeParticipant'] ?? [] );
			$away = (array) ( $m['awayParticipant'] ?? [] );

			update_post_meta( $wp_id, 'home_score', isset( $home['score'] ) ? (int) $home['score'] : '' );
			update_post_meta( $wp_id, 'visitor_score', isset( $away['score'] ) ? (int) $away['score'] : '' );
			$status_id = (string) ( $m['matchStatus']['statusID'] ?? $m['matchStatus']['value'] ?? '-1' );
			update_post_meta( $wp_id, 'statorium_status_id', $status_id );

			$situation = $this->map_status( $status_id );
			if ( $situation ) {
				update_post_meta( $wp_id, 'situation', $situation );
				$this->set_term_guarded( $wp_id, $situation, 'f365_match_status', false );
			}

			$this->purge_match_cache( $wp_id, '1' === $status_id );
			$updated++;
		}

		if ( $updated ) {
			$this->log( "LIVE updated={$updated}" );
		}
		return $updated;
	}

	private function purge_match_cache( int $match_wp_id, bool $finished ): void {
		if ( ! class_exists( 'F365_Cache_Store' ) ) {
			return;
		}
		$pub = (int) get_post_meta( $match_wp_id, 'django_match_id', true );
		if ( $pub ) {
			F365_Cache_Store::purge_prefix( 'jogo/' . $pub );
		}
		F365_Cache_Store::purge_prefix( '_home' );
		F365_Cache_Store::purge_prefix( 'jogos-em-direto' );

		if ( $finished && function_exists( 'f365c_purge_prefixes' ) ) {
			$map = f365c_post_type_purge_map();
			f365c_purge_prefixes( $map['f365_match'] ?? [] );
		}
	}

	// ── Ownership-aware writes ──────────────────────────────────────────────

	public function is_legacy( int $wp_id ): bool {
		global $wpdb;
		$legacy_id = $wpdb->get_var( $wpdb->prepare( "SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d", $wp_id ) );
		if ( (int) $legacy_id > 0 ) {
			return true;
		}
		return 'statorium' !== get_post_meta( $wp_id, '_f365_source', true );
	}

	private function write_fields( int $wp_id, array $meta, bool $legacy ): void {
		$mode = $this->mode();

		foreach ( $meta as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}

			$additive = 0 === strpos( $key, 'statorium_' );

			if ( ! $legacy || 'full' === $mode || $additive ) {
				update_post_meta( $wp_id, $key, $value );
				continue;
			}

			$existing = get_post_meta( $wp_id, $key, true );
			if ( '' !== $existing && null !== $existing ) {
				continue;
			}

			$required = in_array( $key, self::REQUIRED_FILL_KEYS, true );
			if ( $required || 'fill-gaps' === $mode ) {
				update_post_meta( $wp_id, $key, $value );
				$this->log( "FILL wp_id={$wp_id} {$key}=" . ( is_scalar( $value ) ? $value : wp_json_encode( $value ) ) );
			}
		}
	}

	private function set_term_guarded( int $wp_id, string $name, string $taxonomy, bool $legacy ): void {
		$mode = $this->mode();
		if ( $legacy && 'strict' === $mode ) {
			return;
		}
		if ( $legacy && 'fill-gaps' === $mode ) {
			$current = wp_get_object_terms( $wp_id, $taxonomy, [ 'fields' => 'ids' ] );
			if ( ! is_wp_error( $current ) && ! empty( $current ) ) {
				return;
			}
		}
		$this->set_term( $wp_id, $name, $taxonomy );
	}

	private function set_thumbnail_guarded( int $wp_id, string $url, string $title, bool $legacy ): void {
		if ( has_post_thumbnail( $wp_id ) ) {
			return;
		}
		if ( $legacy && 'strict' === $this->mode() ) {
			return;
		}
		$att = $this->sideload( $url, $wp_id, $title );
		if ( $att ) {
			set_post_thumbnail( $wp_id, $att );
			if ( $legacy ) {
				$this->log( "FILL wp_id={$wp_id} thumbnail={$url}" );
			}
		}
	}

	// ── Core upsert / maps ──────────────────────────────────────────────────

	private function upsert( string $type, int $stat_id, array $post_args, string $merge_title ): int {
		$wp_id = $this->map_resolve( $type, $stat_id );

		if ( $wp_id && get_post_status( $wp_id ) ) {
			if ( ! empty( $post_args['post_title'] )
				&& ! $this->is_legacy( $wp_id )
				&& get_the_title( $wp_id ) !== $post_args['post_title'] ) {
				wp_update_post( [ 'ID' => $wp_id, 'post_title' => $post_args['post_title'] ] );
			}
			return $wp_id;
		}

		$post_type = self::TYPE_POST_TYPE[ $type ];

		if ( '' !== $merge_title ) {
			global $wpdb;
			$found = $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				  WHERE post_type = %s AND post_status = 'publish' AND post_title = %s
				  ORDER BY ID ASC LIMIT 1",
				$post_type, $merge_title
			) );
			if ( $found ) {
				$wp_id = (int) $found;
				$this->map_set( $type, $stat_id, $wp_id );
				update_post_meta( $wp_id, '_f365_statorium_linked', 1 );
				$this->log( "LINK {$type} stat_id={$stat_id} → existing wp_id={$wp_id} (\"{$merge_title}\")" );
				return $wp_id;
			}
		}

		$wp_id = wp_insert_post( array_merge( [
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'post_author'    => 1,
			'post_content'   => '',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		], $post_args ), true );

		if ( is_wp_error( $wp_id ) || ! $wp_id ) {
			$this->log( "FAIL {$type} stat_id={$stat_id} — " . ( is_wp_error( $wp_id ) ? $wp_id->get_error_message() : 'insert returned 0' ) );
			return 0;
		}

		update_post_meta( (int) $wp_id, '_f365_source', 'statorium' );
		$this->map_set( $type, $stat_id, (int) $wp_id );
		return (int) $wp_id;
	}

	private function pub_or_keep( int $wp_id, string $meta_key, int $stat_id ): int {
		$existing = (int) get_post_meta( $wp_id, $meta_key, true );
		return $existing > 0 ? $existing : self::pub_id( $stat_id );
	}

	private function set_term( int $wp_id, string $name, string $taxonomy ): void {
		$name = trim( $name );
		if ( '' === $name || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		$term = get_term_by( 'name', $name, $taxonomy );
		if ( ! $term ) {
			$term = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
		}
		if ( ! $term ) {
			$created = wp_insert_term( $name, $taxonomy, [ 'slug' => sanitize_title( $name ) ] );
			if ( is_wp_error( $created ) ) {
				return;
			}
			$term_id = (int) $created['term_id'];
		} else {
			$term_id = (int) $term->term_id;
		}
		wp_set_object_terms( $wp_id, [ $term_id ], $taxonomy, true );
	}

	public function map_resolve( string $type, int $stat_id ): ?int {
		if ( isset( $this->map_cache[ $type ][ (string) $stat_id ] ) ) {
			return (int) $this->map_cache[ $type ][ (string) $stat_id ];
		}

		global $wpdb;
		$wp_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			  WHERE api_source = %s AND api_id = %d AND post_type = %s LIMIT 1",
			self::API_SOURCE, $stat_id, self::TYPE_POST_TYPE[ $type ]
		) );

		if ( $wp_id ) {
			$this->map_cache[ $type ][ (string) $stat_id ] = (int) $wp_id;
			return (int) $wp_id;
		}
		return null;
	}

	public function map_set( string $type, int $stat_id, int $wp_id ): void {
		$this->map_cache[ $type ][ (string) $stat_id ] = $wp_id;
		$this->set_api_columns( $wp_id, $stat_id );

		$decoded = json_decode( (string) get_option( "f365_stat_map_{$type}", '{}' ), true );
		$decoded = is_array( $decoded ) ? $decoded : [];
		$decoded[ (string) $stat_id ] = $wp_id;
		update_option( "f365_stat_map_{$type}", wp_json_encode( $decoded ), false );
	}

	// ── Repair / audit / rollback ───────────────────────────────────────────

	public function repair_misclassified_links(): int {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_f365_source' AND s.meta_value = 'statorium'
			  WHERE p.api_source = %s AND p.legacy_id > 0",
			self::API_SOURCE
		) );
		foreach ( $ids as $id ) {
			delete_post_meta( (int) $id, '_f365_source' );
			update_post_meta( (int) $id, '_f365_statorium_linked', 1 );
			$this->log( "REPAIR wp_id={$id} unstamped _f365_source (linked legacy post)" );
		}
		return count( $ids );
	}

	public function repair_linked_team_shortnames(): int {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, pm.meta_value AS shortname
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} pm  ON pm.post_id  = p.ID AND pm.meta_key  = 'shortname'
			   JOIN {$wpdb->postmeta} st  ON st.post_id  = p.ID AND st.meta_key  = 'statorium_team_id'
			  WHERE p.post_type = 'f365_team' AND p.api_source = %s",
			self::API_SOURCE
		) );

		$fixed = 0;
		foreach ( (array) $rows as $r ) {
			if ( 'statorium' === get_post_meta( (int) $r->ID, '_f365_source', true ) ) {
				continue;
			}
			if ( $r->shortname !== $r->post_title ) {
				update_post_meta( (int) $r->ID, 'shortname', $r->post_title );
				$this->log( "REPAIR wp_id={$r->ID} shortname \"{$r->shortname}\" → \"{$r->post_title}\"" );
				$fixed++;
			}
		}
		return $fixed;
	}

	public static function touched_legacy_posts(): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_type, p.post_title, p.api_id
			   FROM {$wpdb->posts} p
			  WHERE p.api_source = %s
			    AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} s
					 WHERE s.post_id = p.ID AND s.meta_key = '_f365_source' AND s.meta_value = 'statorium'
				)
			  ORDER BY p.post_type, p.post_title",
			self::API_SOURCE
		) );
	}

	public static function rollback(): array {
		global $wpdb;

		$created = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_f365_source' AND s.meta_value = 'statorium'
			  WHERE p.api_source = %s AND ( p.legacy_id IS NULL OR p.legacy_id = 0 )",
			self::API_SOURCE
		) );
		foreach ( $created as $id ) {
			wp_delete_post( (int) $id, true );
		}

		$linked = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE api_source = %s", self::API_SOURCE ) );
		foreach ( $linked as $id ) {
			$id = (int) $id;
			foreach ( [ '_f365_statorium_linked', '_f365_match_method', 'statorium_team_id', 'statorium_player_id', 'statorium_match_id', 'statorium_venue_id', 'statorium_season_id', 'statorium_league_id', 'statorium_groups', 'statorium_status_id', 'statorium_matchday_id' ] as $key ) {
				delete_post_meta( $id, $key );
			}
			$wpdb->update( $wpdb->posts, [ 'api_source' => null, 'api_id' => null ], [ 'ID' => $id ] );
			clean_post_cache( $id );
		}

		foreach ( array_keys( self::TYPE_POST_TYPE ) as $type ) {
			delete_option( "f365_stat_map_{$type}" );
		}

		return [ count( $created ), count( $linked ) ];
	}

	// ── Field helpers ───────────────────────────────────────────────────────

	private function pt_name( array $entity, string $fallback ): string {
		return (string) ( $entity['translations']['pt']['name'] ?? $fallback );
	}

	private function map_status( string $status_id ): string {
		$map = apply_filters( 'f365_statorium_status_map', [
			'1'  => 'finished',
			'2'  => 'delayed',
			'3'  => 'abrogated',
			'-1' => '',
			'0'  => '',
		] );
		return (string) ( $map[ $status_id ] ?? '' );
	}

	private function map_position( string $position ): string {
		$map = apply_filters( 'f365_statorium_position_map', [
			'Goalkeeper' => 'Guarda-Redes',
			'Defender'   => 'Defesa',
			'Midfielder' => 'Médio',
			'Atacker'    => 'Avançado',
			'Attacker'   => 'Avançado',
			'Forward'    => 'Avançado',
		] );
		return (string) ( $map[ $position ] ?? $position );
	}

	private function parse_birthdate( string $raw ): string {
		if ( preg_match( '/(\d{2})-(\d{2})-(\d{4})/', $raw, $m ) ) {
			return "{$m[3]}-{$m[2]}-{$m[1]}";
		}
		return '';
	}

	private function sideload( string $url, int $parent_id, string $title ): int {
		if ( ! $url ) {
			return 0;
		}
		global $wpdb;
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_source_url' AND meta_value=%s LIMIT 1",
			$url
		) );
		if ( $existing ) {
			return (int) $existing;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			$this->log( "IMAGE_FAIL {$url} — " . $tmp->get_error_message() );
			return 0;
		}

		$att_id = media_handle_sideload(
			[ 'name' => basename( (string) parse_url( $url, PHP_URL_PATH ) ) ?: 'image.webp', 'tmp_name' => $tmp ],
			$parent_id,
			$title
		);
		@unlink( $tmp );

		if ( is_wp_error( $att_id ) ) {
			$this->log( "IMAGE_FAIL {$url} — " . $att_id->get_error_message() );
			return 0;
		}
		update_post_meta( $att_id, '_source_url', $url );
		return (int) $att_id;
	}

	// ── Logging ─────────────────────────────────────────────────────────────

	public function log( string $line ): void {
		if ( null === $this->log_handle ) {
			$upload = wp_upload_dir();
			$dir    = trailingslashit( $upload['basedir'] ) . 'f365-logs/';
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
				@file_put_contents( $dir . '.htaccess', "Deny from all\n" );
				@file_put_contents( $dir . 'index.php', "<?php // Silence\n" );
			}
			$this->log_handle = @fopen( $dir . 'f365-statorium-' . date( 'Y-m-d' ) . '.log', 'a' ) ?: false;
		}
		if ( $this->log_handle ) {
			fwrite( $this->log_handle, '[' . date( 'H:i:s' ) . "] {$line}\n" );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::log( $line );
		}
	}

	private function bump( string $key ): void {
		$this->stats[ $key ] = ( $this->stats[ $key ] ?? 0 ) + 1;
	}
}
