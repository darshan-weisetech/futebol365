<?php
/**
 * Statorium → Futebol365 importer (plugin edition).
 *
 * Same structure conventions as the legacy Django migration:
 *  - public URL ids: `django_*_id` = OFFSET + statorium_id (URLs resolve as-is)
 *  - api identity: wp_posts.api_source / api_id columns + `statorium_*_id` meta
 *  - ownership policy: legacy posts are LINKED (additive keys only) — see
 *    write_fields(); posts we created (`_f365_source=statorium`) are refreshed.
 *  - existing-record detection: F365_Statorium_Matcher (normalized name +
 *    country/birthdate/shirt-number scoring; ambiguity → review queue).
 *
 * API key: F365_STATORIUM_API_KEY in wp-config.php (settings field fallback).
 * Logs:    wp-content/uploads/f365-logs/f365-statorium-YYYY-MM-DD.log
 *
 * @package F365_Statorium
 */

defined( 'ABSPATH' ) || exit;

class F365_Statorium_Importer {

	const ID_OFFSET  = 90000000;
	const OPTION     = 'f365_statorium_settings';
	const DB_VERSION = '8';
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
	private F365_Statorium_Matcher $matcher;
	private ?F365_Statorium_Tables $tables = null;
	private array $map_cache = [];
	private $log_handle = null;
	public array $stats = [];
	public bool $dry_run = false;

	public function __construct( ?string $api_key = null ) {
		$this->api     = new F365_Statorium_API( $api_key ?? self::api_key() );
		$this->matcher = new F365_Statorium_Matcher( fn( $line ) => $this->log( $line ) );
		self::ensure_schema();
	}

	/** wp-config constant wins; the settings field is a fallback. */
	public static function api_key(): string {
		if ( defined( 'F365_STATORIUM_API_KEY' ) && F365_STATORIUM_API_KEY ) {
			return (string) F365_STATORIUM_API_KEY;
		}
		return (string) self::settings()['api_key'];
	}

	public static function settings(): array {
		// Shipped defaults = the production configuration, so a fresh install
		// (clone/new environment) starts working without manual setup.
		$defaults = [
			'api_key'       => '',
			'seasons'       => '614',
			'cron'          => 0,        // production runs system-CLI cron, not WP-Cron
			'cron_interval' => 'hourly', // f365stat_30min | hourly | f365stat_2h | f365stat_6h | twicedaily | daily
			'update_mode'   => 'fill-gaps',
			'review_mode'   => 'auto',   // unattended — code decides, queue is audit-only
			'ticker_ui'     => 1,        // header match ticker (4-tab bar)
			'hide_empty_tabs' => 0,      // hide a ticker tab entirely when it has no matches
		];
		$saved    = get_option( self::OPTION, [] );
		$settings = is_array( $saved ) ? array_merge( $defaults, $saved ) : $defaults;

		// Policy is fixed to fill-gaps (Strict/Full disabled per project
		// decision); the filter is the only escape hatch.
		$settings['update_mode'] = apply_filters( 'f365_statorium_update_mode', 'fill-gaps' );

		return $settings;
	}

	public function api(): F365_Statorium_API {
		return $this->api;
	}

	public function matcher(): F365_Statorium_Matcher {
		return $this->matcher;
	}

	public function tables(): F365_Statorium_Tables {
		if ( null === $this->tables ) {
			$this->tables = new F365_Statorium_Tables( $this );
		}
		return $this->tables;
	}

	public static function pub_id( int $statorium_id ): int {
		return self::ID_OFFSET + $statorium_id;
	}

	private function mode(): string {
		return (string) self::settings()['update_mode'];
	}

	// ════════════════════════════════════════════════════════════════════════
	// SCHEMA
	// ════════════════════════════════════════════════════════════════════════

	public static function ensure_schema(): void {
		global $wpdb;

		// No version short-circuit — every step below runs on every call
		// (every F365_Statorium_Importer instantiation: every sync, every
		// CLI command). The version option alone was proven unreliable as
		// an "already migrated" signal: a database can carry a stale-but-
		// matching value (e.g. wp_posts reset/reimported separately from
		// wp_options) while the columns genuinely don't exist, and the old
		// guard returned immediately forever without creating them. All
		// steps here are idempotent (existence checks, INSERT IGNORE-style
		// WHERE-guarded UPDATEs, DELETEs on already-absent rows), so
		// running them unconditionally is safe — just not free; this does
		// mean a SHOW COLUMNS/SHOW INDEX plus several guarded UPDATE/DELETE
		// queries against wp_posts run on every sync/tick, not just once.

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

		// Backfill columns from any pre-column option maps (older builds) —
		// also the recovery path for the stale-version/missing-columns case
		// above: the columns were just (re)created empty, but the real
		// stat_id→wp_id mapping already exists in these options.
		foreach ( array_keys( self::TYPE_POST_TYPE ) as $type ) {
			$map = json_decode( (string) get_option( "f365_stat_map_{$type}", '{}' ), true );
			foreach ( (array) $map as $stat_id => $wp_id ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->posts} SET api_source = %s, api_id = %d WHERE ID = %d AND api_id IS NULL",
					self::API_SOURCE, (int) $stat_id, (int) $wp_id
				) );
			}
		}

		F365_Statorium_Matcher::ensure_review_table();

		// v3: created posts need wp_posts.legacy_id/legacy_table — the URL
		// resolvers read the column, not postmeta. Backfill from meta.
		$legacy_map = [
			'f365_team'        => [ 'django_id', 'team_team' ],
			'f365_player'      => [ 'django_player_id', 'person_player' ],
			'f365_match'       => [ 'django_match_id', 'competition_match' ],
			'f365_stadium'     => [ 'django_id', 'stadium_stadium' ],
			'f365_competition' => [ 'django_competition_id', 'competition_competition' ],
		];
		foreach ( $legacy_map as $post_type => [ $meta_key, $table ] ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->posts} p
				   JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				   JOIN {$wpdb->postmeta} s  ON s.post_id  = p.ID AND s.meta_key = '_f365_source' AND s.meta_value = 'statorium'
				    SET p.legacy_id = CAST( pm.meta_value AS UNSIGNED ), p.legacy_table = %s
				  WHERE p.post_type = %s AND p.api_source = %s
				    AND ( p.legacy_id IS NULL OR p.legacy_id = 0 )",
				$meta_key, $table, $post_type, self::API_SOURCE
			) );
		}

		// v4: mark transfer mirror rows we insert (the transfers admin page and
		// future audits need to tell ours from legacy Django rows).
		$ttable = $wpdb->prefix . 'transfer_transfer';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ttable}'" ) ) {
			$tcols = $wpdb->get_col( "SHOW COLUMNS FROM {$ttable}", 0 );
			if ( ! in_array( 'api_source', $tcols, true ) ) {
				$wpdb->query( "ALTER TABLE {$ttable} ADD COLUMN api_source VARCHAR(32) NULL DEFAULT NULL, ADD COLUMN api_created_at DATETIME NULL DEFAULT NULL, ADD INDEX api_source (api_source)" );
			}
		}

		// v5: drop redundant statorium_* audit metas — identity lives in the
		// indexed api_source/api_id COLUMNS; these duplicated them and were
		// never read. Kept: statorium_status_id, statorium_detail_synced,
		// statorium_venue_synced (functional sync state). ALSO kept:
		// statorium_league_id — that "never read" claim was wrong for this
		// one key: import_season() writes it and the Statorium Data →
		// Competition Duplicates audit reads it (find_legacy_competition()
		// needs a league id), and merge_competition() copies it forward.
		// This whole step used to only run once (gated behind the
		// db_version check); once that gate was removed so schema/column
		// repair could run unconditionally every call, this DELETE started
		// firing on every single sync/CLI call too — wiping the value back
		// to 0 as fast as it could ever be written, so the duplicates audit
		// permanently read "league 0" and could never resolve a legacy
		// match for any newly-created competition.
		$wpdb->query(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (
				'statorium_team_id','statorium_player_id','statorium_match_id',
				'statorium_venue_id','statorium_season_id',
				'statorium_groups','statorium_matchday_id','statorium_departed'
			)"
		);

		// v6: purge Permalink Manager URIs from the entity CPTs — PM is now
		// disabled for them (filter in the bootstrap), but URIs it generated
		// for earlier imports still hijack the numeric URLs.
		$uris = get_option( 'permalink-manager-uris', null );
		if ( is_array( $uris ) && $uris ) {
			$cpt_ids = $wpdb->get_col(
				"SELECT ID FROM {$wpdb->posts}
				  WHERE post_type IN ('f365_competition','f365_team','f365_player','f365_match','f365_stadium')
				    AND ID IN (" . implode( ',', array_map( 'intval', array_keys( $uris ) ) ) . ')'
			);
			if ( $cpt_ids ) {
				foreach ( $cpt_ids as $pid ) {
					unset( $uris[ (int) $pid ], $uris[ (string) $pid ] );
				}
				update_option( 'permalink-manager-uris', $uris, false );
			}
		}

		// v7: earlier builds tagged created players with PT position terms
		// (Guarda-Redes/Defesa/Médio/Avançado) — duplicates of the legacy
		// lowercase-english terms the theme and /jogador/posicao/ pages use.
		// Merge each PT term into its legacy twin and delete it.
		foreach ( [
			'Guarda-Redes' => 'goalkeeper',
			'Defesa'       => 'defender',
			'Médio'        => 'midfielder',
			'Avançado'     => 'forward',
		] as $pt_name => $en_name ) {
			$pt_term = get_term_by( 'name', $pt_name, 'f365_position' );
			$en_term = get_term_by( 'name', $en_name, 'f365_position' );
			if ( ! $pt_term || is_wp_error( $pt_term ) ) {
				continue;
			}
			if ( ! $en_term || is_wp_error( $en_term ) ) {
				$new     = wp_insert_term( $en_name, 'f365_position' );
				$en_term = is_wp_error( $new ) ? null : get_term( (int) $new['term_id'], 'f365_position' );
			}
			if ( ! $en_term ) {
				continue;
			}
			foreach ( get_objects_in_term( (int) $pt_term->term_id, 'f365_position' ) as $obj_id ) {
				wp_set_object_terms( (int) $obj_id, [ (int) $en_term->term_id ], 'f365_position', true );
			}
			wp_delete_term( (int) $pt_term->term_id, 'f365_position' );
		}

		// v8: audit log for the Statorium Data → Merge Duplicates admin page
		// (manual, admin-picked merges — distinct from the automatic dedup
		// sweeps, which only log to the file log). Titles are captured at
		// merge time, not looked up later, because the source post no longer
		// exists once merge_team()/merge_player()/merge_stadium() deletes it.
		$log_table = $wpdb->prefix . 'f365_stat_merge_log';
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$log_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entity_type VARCHAR(20) NOT NULL,
			source_wp_id BIGINT UNSIGNED NOT NULL,
			source_title VARCHAR(255) NOT NULL DEFAULT '',
			target_wp_id BIGINT UNSIGNED NOT NULL,
			target_title VARCHAR(255) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL,
			user_login VARCHAR(60) NOT NULL DEFAULT '',
			merged_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY entity_type (entity_type),
			KEY merged_at (merged_at)
		) {$wpdb->get_charset_collate()}" );

		update_option( 'f365_statorium_db_version', self::DB_VERSION, false );
	}

	/**
	 * Records one admin-driven manual merge (Statorium Data → Merge
	 * Duplicates page) for the audit trail. Called by the admin-post.php
	 * handler AFTER a successful merge_team()/merge_player()/merge_stadium()
	 * call, with titles captured before the merge ran (the source post is
	 * deleted by the merge itself).
	 */
	public static function record_manual_merge( string $type, int $source_wp_id, string $source_title, int $target_wp_id, string $target_title, int $user_id, string $user_login ): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'f365_stat_merge_log', [
			'entity_type'  => $type,
			'source_wp_id' => $source_wp_id,
			'source_title' => $source_title,
			'target_wp_id' => $target_wp_id,
			'target_title' => $target_title,
			'user_id'      => $user_id,
			'user_login'   => $user_login,
			'merged_at'    => current_time( 'mysql' ),
		] );
	}

	private function set_api_columns( int $wp_id, int $stat_id ): void {
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'api_source' => self::API_SOURCE, 'api_id' => $stat_id ], [ 'ID' => $wp_id ] );
		clean_post_cache( $wp_id );
	}

	// ════════════════════════════════════════════════════════════════════════
	// SEASON → f365_competition
	// ════════════════════════════════════════════════════════════════════════

	/**
	 * Find the LEGACY competition this Statorium season represents — the
	 * robust identity is federation + edition + name (via mirror tables; names
	 * like "Premier League" repeat across 200+ countries, so name alone is
	 * unsafe). Returns the legacy competition WP post id, or 0.
	 */
	public function find_legacy_competition( int $league_id, string $season_name, string $full_name ): int {
		if ( ! $league_id || ! function_exists( 'f365_statorium_league_entry' ) ) {
			return 0;
		}
		$entry = f365_statorium_league_entry( $league_id );
		if ( ! $entry ) {
			return 0;
		}

		$fed_wp = (int) ( $entry['federation_wp'] ?? 0 );
		global $wpdb;
		$fed_mirror = $fed_wp
			? ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d", $fed_wp ) )
				?: (int) get_post_meta( $fed_wp, 'django_id', true ) )
			: 0;
		if ( ! $fed_mirror ) {
			return 0;
		}

		// canon() (normalize + alias-table resolve), not plain normalize() —
		// confirmed live: the legacy Django data itself names the SAME
		// competition differently across mirror tables (fedcomp "Liga dos
		// Campeões" vs every edition row "Liga Campeões"), which a bare
		// string comparison can never reconcile. See name_aliases().
		$names = array_filter( array_unique( array_map(
			[ 'F365_Statorium_Matcher', 'canon' ],
			[ (string) ( $entry['legacy_name'] ?? '' ), $full_name ]
		) ) );
		if ( ! $names ) {
			return 0;
		}

		// Edition candidates: '2026-27' → also try '2026'.
		$editions = array_unique( array_filter( [ $season_name, substr( $season_name, 0, 4 ) ] ) );

		$candidates = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.name, c.edition, c.season
			   FROM {$wpdb->prefix}competition_competition c
			   JOIN {$wpdb->prefix}federation_federationcompetition fc ON fc.id = c.federation_competition_id
			  WHERE fc.federation_id = %d AND c.id < %d
			    AND c.edition IN ('" . implode( "','", array_map( 'esc_sql', $editions ) ) . "')",
			$fed_mirror, self::ID_OFFSET
		) );

		foreach ( (array) $candidates as $c ) {
			if ( in_array( F365_Statorium_Matcher::canon( (string) $c->name ), $names, true ) ) {
				$wp_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'django_competition_id' AND meta_value = %s LIMIT 1",
					(string) $c->id
				) );
				if ( $wp_id ) {
					$this->log( "LINK season league={$league_id} \"{$full_name}\" → legacy competition {$c->id} \"{$c->name}\" (wp_id={$wp_id}) via federation+edition+name" );
					return $wp_id;
				}
			}
		}
		return 0;
	}

	/**
	 * Finds a pre-existing LEGACY fedcomp (federation_federationcompetition
	 * — the league-level entity /competicao/{id}/ URLs key on, spanning every
	 * edition) for this league, independent of whether any specific edition
	 * has a matching competition_competition row. find_legacy_competition()
	 * above only matches a specific season/year — for a league's first-ever
	 * Statorium-tracked edition (e.g. 2026-27, freshly onboarded) there is
	 * legitimately no matching EDITION row yet, but the LEAGUE ITSELF
	 * (Premier League, Ligue 1, …) almost always already exists as a fedcomp
	 * from the original Django migration, with decades of history. Without
	 * this check, import_season()'s create path unconditionally minted a
	 * brand-new fedcomp via self::pub_id($league_id) for every such season —
	 * confirmed live: England's Premier League 2026-27 landed on a fresh
	 * fedcomp id, completely orphaned from the real one (with rows back to
	 * 1889), instead of extending it. Every one of the ten newly-onboarded
	 * club leagues hit this identically, since the failure mode is the same
	 * regardless of league.
	 */
	public function find_legacy_fedcomp( int $league_id, string $full_name ): int {
		if ( ! $league_id || ! function_exists( 'f365_statorium_league_entry' ) ) {
			return 0;
		}
		$entry = f365_statorium_league_entry( $league_id );
		if ( ! $entry ) {
			return 0;
		}

		$fed_wp = (int) ( $entry['federation_wp'] ?? 0 );
		global $wpdb;
		$fed_mirror = $fed_wp
			? ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d", $fed_wp ) )
				?: (int) get_post_meta( $fed_wp, 'django_id', true ) )
			: 0;
		if ( ! $fed_mirror ) {
			return 0;
		}

		$names = array_filter( array_unique( array_map(
			[ 'F365_Statorium_Matcher', 'normalize' ],
			[ (string) ( $entry['legacy_name'] ?? '' ), $full_name ]
		) ) );
		if ( ! $names ) {
			return 0;
		}

		$candidates = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, shortname, longname FROM {$wpdb->prefix}federation_federationcompetition
			  WHERE federation_id = %d AND id < %d",
			$fed_mirror, self::ID_OFFSET
		) );
		foreach ( (array) $candidates as $c ) {
			if ( in_array( F365_Statorium_Matcher::normalize( (string) $c->shortname ), $names, true )
				|| in_array( F365_Statorium_Matcher::normalize( (string) $c->longname ), $names, true ) ) {
				$this->log( "LINK fedcomp league={$league_id} \"{$full_name}\" → legacy fedcomp {$c->id} \"{$c->shortname}\" via federation+name" );
				return (int) $c->id;
			}
		}

		// Fedcomp-level shortname/longname miss ≠ no legacy fedcomp exists —
		// confirmed live for two leagues: Spain's fedcomp is named the
		// traditional "Primera División" while every edition row underneath
		// it is consistently "La Liga" (the modern brand our config uses);
		// Portugal's fedcomp is the stable "Primeira Liga" while edition rows
		// carry whichever sponsor was current that year ("Liga Portugal
		// Betclic" / "…Bwin" / …). Neither matches at the fedcomp level, so
		// also check any EDITION-level competition_competition.name under
		// this federation (any year) before concluding nothing exists.
		$edition_names = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT c.federation_competition_id, c.name
			   FROM {$wpdb->prefix}competition_competition c
			  WHERE c.federation_competition_id IN (
			      SELECT id FROM {$wpdb->prefix}federation_federationcompetition
			       WHERE federation_id = %d AND id < %d
			  )",
			$fed_mirror, self::ID_OFFSET
		) );
		foreach ( (array) $edition_names as $e ) {
			if ( in_array( F365_Statorium_Matcher::normalize( (string) $e->name ), $names, true ) ) {
				$this->log( "LINK fedcomp league={$league_id} \"{$full_name}\" → legacy fedcomp {$e->federation_competition_id} via federation+edition-name \"{$e->name}\"" );
				return (int) $e->federation_competition_id;
			}
		}
		return 0;
	}

	/**
	 * Statorium sends the 2-digit end year ("2026-27"); every legacy season
	 * string — the mirror tables' `season` column, every f365_season term —
	 * is always the full 4-digit form ("2026-2027"). Expands arithmetically
	 * (start year + 1) rather than naively gluing on the start year's
	 * century, so a century/millennium rollover still comes out right
	 * ("1999-00" → "1999-2000"). Anything not matching the 2-digit-suffix
	 * shape (already-full strings, single-year seasons like "2019", unknown
	 * formats) passes through unchanged — idempotent, safe to call on a
	 * value that's already been through it.
	 */
	private static function expand_season_name( string $season ): string {
		if ( preg_match( '/^(\d{4})-(\d{2})(\s.*)?$/', trim( $season ), $m ) ) {
			return $m[1] . '-' . ( (int) $m[1] + 1 ) . ( $m[3] ?? '' );
		}
		return $season;
	}

	/** Inverse of expand_season_name() — "2026-2027" → "2026-27". Empty string if $season isn't in the full 4-digit form. */
	private static function short_season_name( string $season ): string {
		if ( preg_match( '/^(\d{2})(\d{2})-\d{2}(\d{2})(\s.*)?$/', trim( $season ), $m ) ) {
			return $m[1] . $m[2] . '-' . $m[3] . ( $m[4] ?? '' );
		}
		return '';
	}

	/**
	 * wp_set_object_terms() appends rather than replaces, so a post that
	 * already carried the pre-normalization short-form f365_season term
	 * ("2026-27") ends up with BOTH it and the correct full-form one
	 * ("2026-2027") attached once expand_season_name() starts being used.
	 * Detaches the stale one if present — created posts only, never touches
	 * a legacy post's term assignments.
	 */
	private function detach_stale_season_term( int $wp_id, string $season_name, bool $legacy ): void {
		if ( $legacy || $this->dry_run ) {
			return;
		}
		$short = self::short_season_name( $season_name );
		if ( ! $short ) {
			return;
		}
		$stale = get_term_by( 'name', $short, 'f365_season' );
		if ( $stale && ! is_wp_error( $stale ) ) {
			wp_remove_object_terms( $wp_id, (int) $stale->term_id, 'f365_season' );
		}
	}

	public function import_season( int $season_id ): int {
		$data = $this->api->season( $season_id );
		if ( is_wp_error( $data ) || empty( $data['season'] ) ) {
			$this->log( "FAIL season={$season_id} — " . ( is_wp_error( $data ) ? $data->get_error_message() : 'empty payload' ) );
			return 0;
		}
		$s           = $data['season'];
		$title       = (string) ( $s['fullName'] ?: ( $s['seasonName'] ?? "Season {$season_id}" ) );
		$league_id   = (int) ( $s['leagueID'] ?? 0 );
		$season_name = self::expand_season_name( (string) ( $s['seasonName'] ?? '' ) );

		// Self-heal the league map before relying on it — see
		// f365_statorium_ensure_league_map()'s docblock: without this, a
		// fresh Scenario-B install where import_season() runs before
		// `leagues-map` (or before any full `sync` cycle has onboarded
		// leagues) silently fails to link EVERY league to its legacy
		// competition, creating a duplicate for each one instead.
		if ( ! $this->dry_run && function_exists( 'f365_statorium_ensure_league_map' ) ) {
			f365_statorium_ensure_league_map( $this, $league_id );
		}

		// Auto-link to the legacy competition when one exists (prevents the
		// duplicate-competition problem on fresh installs/clones).
		if ( ! $this->map_resolve( 'season', $season_id ) && ! $this->dry_run ) {
			$legacy_wp = $this->find_legacy_competition( $league_id, $season_name, $title );
			if ( $legacy_wp ) {
				$this->map_set( 'season', $season_id, $legacy_wp );
				update_post_meta( $legacy_wp, '_f365_statorium_linked', 1 );
			}
		}

		// Subscription gate: /updates/ (and other feeds) reference content
		// outside the subscribed leagues — only leagues present in the league
		// map may create competitions. Already-imported seasons still refresh.
		if ( ! $this->map_resolve( 'season', $season_id )
			&& function_exists( 'f365_statorium_league_entry' )
			&& f365_statorium_league_map()
			&& ! f365_statorium_league_entry( $league_id ) ) {
			// /updates/ fires many events per unsubscribed season — log once per run.
			static $skip_logged = [];
			if ( ! isset( $skip_logged[ $season_id ] ) ) {
				$skip_logged[ $season_id ] = true;
				$this->log( "SKIP season={$season_id} \"{$title}\" — league {$league_id} not in subscription map" );
			}
			self::remember_unsubscribed_season( $season_id, $league_id, $title );
			return 0;
		}

		$wp_id = $this->upsert( 'season', $season_id, [ 'post_title' => $title ], [
			'names'  => [ $title ],
			'season' => $season_name,
		] );
		if ( ! $wp_id ) {
			return 0;
		}
		$legacy = $this->is_legacy( $wp_id );

		// Internal identity aid, not user-facing content — no reason to
		// gate it through the legacy fill-gap machinery. Needed by the
		// Competition Duplicates audit (find_legacy_competition() takes a
		// league id) and by merge_competition()'s copy-forward step.
		if ( $league_id ) {
			update_post_meta( $wp_id, 'statorium_league_id', $league_id );
		}

		$comp_pub = $this->pub_or_keep( $wp_id, 'django_competition_id', $season_id );
		$this->write_fields( $wp_id, [
			'season'                => $season_name,
			'django_competition_id' => $comp_pub,
		], $legacy );
		if ( ! $legacy ) {
			$this->set_legacy_columns( $wp_id, $comp_pub, 'competition_competition' );
		}

		if ( $season_name ) {
			$this->set_term_guarded( $wp_id, $season_name, 'f365_season', $legacy );
			$this->detach_stale_season_term( $wp_id, $season_name, $legacy );
		}

		// League → federation link.
		$league_id = (int) ( $s['leagueID'] ?? 0 );
		if ( $league_id && function_exists( 'f365_statorium_league_entry' ) ) {
			$entry      = f365_statorium_league_entry( $league_id );
			$fed_wp     = (int) ( $entry['federation_wp'] ?? 0 );
			$fed_mirror = 0;
			if ( $fed_wp ) {
				global $wpdb;
				$fed_mirror = (int) $wpdb->get_var( $wpdb->prepare( "SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d", $fed_wp ) )
					?: (int) get_post_meta( $fed_wp, 'django_id', true );
			}

			// The federation entity ("Inglaterra", "Espanha", …) carries its
			// own country_id on its federation_federation mirror row —
			// domestic leagues resolve to exactly one country this way.
			// Continental federations (UEFA) have no single country and
			// correctly yield 0 here, so a Champions League competition
			// doesn't get stamped with a wrong national flag.
			global $wpdb;
			$country_id = $fed_mirror ? (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT country_id FROM {$wpdb->prefix}federation_federation WHERE id = %d", $fed_mirror
			) ) : 0;

			// Competition-type layer: /competicao/{fed_comp_id}/?edition= is the
			// site's canonical URL scheme. Only CREATED competitions need our
			// fedcomp row — legacy-linked ones already have their own. But
			// "created competition" (no matching EDITION row — expected for
			// a league's first-ever tracked season) does not mean the LEAGUE
			// itself is new: reuse an existing legacy fedcomp when one
			// matches, so a brand-new 2026-27 season extends the league's
			// real decades-deep history instead of forking a parallel,
			// permanently-orphaned entity (see find_legacy_fedcomp()).
			$extra = [];
			if ( $comp_pub >= self::ID_OFFSET ) {
				$fedcomp_pub = $this->find_legacy_fedcomp( $league_id, $title ) ?: self::pub_id( $league_id );
				$this->tables()->sync_fedcomp_row(
					$fedcomp_pub,
					(string) ( $entry['name'] ?? $title ),
					$fed_mirror,
					(string) ( $entry['scope'] ?? 'national_championship' )
				);
				$this->tables()->sync_competition_row( $comp_pub, $title, $season_name, $fedcomp_pub, $country_id );
				$extra['federation_competition_id'] = $fedcomp_pub;

				// Distinct from federation_competition_id above (a raw pub
				// integer, for building /competicao/{id}/ URLs) — this is the
				// ACF post_object relationship to the actual f365_fed_comp
				// ("Competition Type") post. The header template falls back
				// to THIS post's linked federation's thumbnail for a logo
				// when the competition has neither its own featured image
				// nor a resolvable country flag (continental competitions
				// with no single country, mainly).
				$fedcomp_wp = $this->ensure_fedcomp_post( $fedcomp_pub, (string) ( $entry['name'] ?? $title ), $fed_wp );
				if ( $fedcomp_wp ) {
					$extra['federation_competition'] = $fedcomp_wp;
				}
			}
			if ( $fed_wp ) {
				$extra['federation']           = $fed_wp;
				$extra['django_federation_id'] = $fed_mirror;
			}
			$this->write_fields( $wp_id, $extra, $legacy );

			// The competition HEADER template reads the WP POST's own
			// f365_country term (get_the_terms(), not the mirror row's
			// country_id) to pick a flag — assign it here too, or a
			// brand-new competition renders with no flag at all despite the
			// mirror table now correctly carrying the country.
			if ( $country_id ) {
				$country_term_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = 'country_country_id' AND meta_value = %s LIMIT 1",
					(string) $country_id
				) );
				if ( $country_term_id ) {
					$country_term = get_term( $country_term_id, 'f365_country' );
					if ( $country_term && ! is_wp_error( $country_term ) ) {
						$this->set_term_guarded( $wp_id, $country_term->name, 'f365_country', $legacy );
					}
				}
			}
		}

		$this->log( "OK season={$season_id} \"{$title}\" → wp_id={$wp_id}" );
		$this->bump( 'seasons' );
		return $wp_id;
	}

	/**
	 * Find-or-create the f365_fed_comp ("Competition Type") post for a
	 * FEDCOMP pub id. Legacy-migrated leagues already have one from the
	 * original Django import (django_fed_comp_id meta, set by the one-time
	 * migration CLI in the futebol365 plugin) — reused here, never
	 * duplicated. A genuinely new league (no legacy fedcomp — see
	 * find_legacy_fedcomp()) has no such post yet, so one is created here,
	 * carrying the same federation link the competition post itself gets.
	 */
	private function ensure_fedcomp_post( int $fedcomp_pub, string $name, int $fed_wp ): int {
		if ( ! $fedcomp_pub || $this->dry_run || ! post_type_exists( 'f365_fed_comp' ) ) {
			return 0;
		}
		global $wpdb;
		$wp_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'django_fed_comp_id' AND meta_value = %s LIMIT 1",
			(string) $fedcomp_pub
		) );
		if ( ! $wp_id ) {
			$new_id = wp_insert_post( [
				'post_type'   => 'f365_fed_comp',
				'post_status' => 'publish',
				'post_title'  => $name,
				'post_author' => 1,
			], true );
			if ( is_wp_error( $new_id ) || ! $new_id ) {
				return 0;
			}
			$wp_id = (int) $new_id;
			update_post_meta( $wp_id, 'django_fed_comp_id', $fedcomp_pub );
			$this->log( "FEDCOMP_POST created wp_id={$wp_id} \"{$name}\" for fedcomp_pub={$fedcomp_pub}" );
		}

		// Gap-fill only — never overwrite an existing (legacy-migrated)
		// federation link.
		if ( $fed_wp && ! get_post_meta( $wp_id, 'federation', true ) ) {
			$acf_keys = $this->acf_field_keys( 'f365_fed_comp' );
			update_post_meta( $wp_id, 'federation', $fed_wp );
			if ( isset( $acf_keys['federation'] ) ) {
				update_post_meta( $wp_id, '_federation', $acf_keys['federation'] );
			}
		}
		return $wp_id;
	}

	/** Register a season id in the synced list (auto-discovery from backfill). */
	public function remember_season( int $season_id ): void {
		$settings = self::settings();
		$list     = array_filter( array_map( 'intval', explode( ',', (string) $settings['seasons'] ) ) );
		if ( ! in_array( $season_id, $list, true ) ) {
			$list[]              = $season_id;
			$settings['seasons'] = implode( ',', $list );
			update_option( self::OPTION, $settings, false );
			$this->log( "DISCOVERED season={$season_id} added to sync list" );
		}
	}

	const UNMAPPED_SEASONS_OPTION = 'f365_statorium_unmapped_seasons';

	/**
	 * Records why a season was skipped for subscription reasons, so the
	 * Settings checklist and the Statorium Data → Seasons page can tell
	 * "genuinely pending" apart from "permanently blocked on this API key" —
	 * without this, a season whose league will never be subscribed (e.g. a
	 * World Cup edition on a club-leagues-only key) shows as an actionable
	 * ⏳ pending item forever, and "Run now" just repeats the same skip.
	 */
	public static function remember_unsubscribed_season( int $season_id, int $league_id, string $title ): void {
		$map                       = get_option( self::UNMAPPED_SEASONS_OPTION, [] );
		$map                       = is_array( $map ) ? $map : [];
		$map[ (string) $season_id ] = [ 'league_id' => $league_id, 'title' => $title ];
		update_option( self::UNMAPPED_SEASONS_OPTION, $map, false );
	}

	/**
	 * [] when the season isn't known-blocked (never skipped, or its league
	 * has since been subscribed — re-checked live against the CURRENT map
	 * rather than trusting the stale skip record). Otherwise the recorded
	 * league_id/title.
	 */
	public static function season_unsubscribed_league( int $season_id ): array {
		$map   = get_option( self::UNMAPPED_SEASONS_OPTION, [] );
		$entry = is_array( $map ) ? ( $map[ (string) $season_id ] ?? null ) : null;
		if ( ! $entry ) {
			return [];
		}
		if ( function_exists( 'f365_statorium_league_entry' ) && f365_statorium_league_entry( (int) $entry['league_id'] ) ) {
			return []; // subscribed since — stale record, no longer blocked
		}
		return $entry;
	}

	// ════════════════════════════════════════════════════════════════════════
	// TEAMS / PLAYERS
	// ════════════════════════════════════════════════════════════════════════

	public function import_teams( int $season_id, bool $with_players = true ): array {
		// Unlike import_season(), this hits /season_matches/ directly and
		// has no subscription gate of its own — without this check, a
		// season already known-blocked (see import_season()) would waste an
		// API call and log a raw FAIL every single run instead of a clean
		// skip, forever, on a key that will never resolve it.
		if ( ! $this->map_resolve( 'season', $season_id ) && self::season_unsubscribed_league( $season_id ) ) {
			$this->log( "SKIP teams season={$season_id} — league not in subscription map" );
			return [];
		}
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

		[ $team_type, $country_hint ] = $this->season_team_context( $season_id );

		// A representative match date for this season, so import_team_players()
		// can write the FULL roster into team_squad under the right edition —
		// see that method's docblock for why this matters.
		$season_date = '';
		foreach ( $data['calendar']['matchdays'] as $md ) {
			$first = ( (array) ( $md['matches'] ?? [] ) )[0] ?? null;
			if ( $first && ! empty( $first['matchDate'] ) ) {
				$season_date = (string) $first['matchDate'];
				break;
			}
		}

		$out = [];
		foreach ( $participants as $stat_id => $p ) {
			$wp_id = $this->upsert_team( $stat_id, $p, $team_type, $country_hint );
			if ( $wp_id ) {
				$out[ $stat_id ] = $wp_id;
				if ( $with_players ) {
					$this->import_team_players( $stat_id, $wp_id, $season_id, $season_date );
				}
			}
		}

		$this->log( 'SUMMARY teams season=' . $season_id . ' imported=' . count( $out ) );

		// Self-heal: fold any accidentally-created duplicates into legacy teams.
		$this->auto_merge_duplicate_teams();

		return $out;
	}

	public function upsert_team( int $stat_id, array $p, string $team_type = 'national', string $pt_country_hint = '' ): int {
		$name = $this->pt_name( $p, (string) ( $p['participantName'] ?? "Team {$stat_id}" ) );

		$signals = [
			'names'     => array_filter( [ $name, (string) ( $p['participantName'] ?? '' ), (string) ( $p['middleName'] ?? '' ) ] ),
			'team_type' => $team_type,
		];
		if ( 'national' === $team_type ) {
			$signals['country'] = $name; // a national team's country IS its name
		} elseif ( '' !== $pt_country_hint ) {
			// A domestic-league club's country IS its league's country: scores
			// the right legacy candidate up AND vetoes cross-country namesakes
			// (Statorium "CS Marítimo" [Primeira Liga] once exact-matched and
			// auto-linked the legacy "CS Marítimo" [Venezuela]).
			$signals['country'] = $pt_country_hint;
		}

		$wp_id = $this->upsert( 'team', $stat_id, [ 'post_title' => $name ], $signals );
		if ( ! $wp_id ) {
			return 0;
		}
		$legacy = $this->is_legacy( $wp_id );

		$team_pub = $this->pub_or_keep( $wp_id, 'django_id', $stat_id );
		$this->write_fields( $wp_id, [
			'shortname' => (string) ( $p['teamShortName'] ?? '' ),
			'longname'  => (string) ( $p['participantName'] ?? $name ),
			'team_type' => $team_type,
			'django_id' => $team_pub,
		], $legacy );
		if ( ! $legacy ) {
			$this->set_legacy_columns( $wp_id, $team_pub, 'team_team' );
		}

		// Country term: nationals only, and only when the name actually
		// resolves to a known country — a club mislabeled 'national' (the
		// pre-scope-derivation default) once minted a bogus "Gil Vicente FC"
		// country term from its own name.
		if ( 'national' === $team_type && $this->tables()->country_id_by_name( $name ) ) {
			$this->set_term_guarded( $wp_id, $name, 'f365_country', $legacy );
		}

		if ( ! empty( $p['logo'] ) ) {
			$this->set_thumbnail_guarded( $wp_id, (string) $p['logo'], $name, $legacy );

			// The theme renders team logos from the static Django media path
			// /media/images/logotipo/100x100/{public_team_id}.gif (6+ templates).
			// Write the physical file when missing — additive, never overwrites.
			$pub = (int) get_post_meta( $wp_id, 'django_id', true );
			if ( $pub ) {
				$this->save_media_image( 'images/logotipo/100x100/' . $pub . '.gif', (string) $p['logo'], 100, 100 );
			}
		}

		// Mirror row (team pages / transfers page join it).
		if ( ! $this->dry_run ) {
			$this->tables()->sync_team_row( $team_pub, $name, (string) ( $p['teamShortName'] ?? '' ), $team_type, $pt_country_hint );
		}

		$this->bump( 'teams' );
		return $wp_id;
	}

	/**
	 * League context for a season: [ team_type, pt_country_hint ] for its
	 * participant teams. Scope decides the type (international_national_team /
	 * international_friendly_nationals → national; every club scope → club);
	 * for domestic leagues (national_… / regional_… scopes) the hint is the
	 * league federation's post title, which for domestic FAs IS the PT country name
	 * ("Portugal", "Inglaterra"). International club competitions get no hint
	 * — a UCL club's country is not derivable from the league. Season lookup
	 * is transient-cached; resolved pairs memoized per request.
	 */
	private array $season_ctx_cache = [];

	public function season_team_context( int $season_id ): array {
		if ( isset( $this->season_ctx_cache[ $season_id ] ) ) {
			return $this->season_ctx_cache[ $season_id ];
		}
		$type = 'national'; // pre-club-league behavior for unknown leagues
		$hint = '';
		$data = $this->api->season( $season_id );
		if ( ! is_wp_error( $data ) && ! empty( $data['season']['leagueID'] ) && function_exists( 'f365_statorium_league_entry' ) ) {
			$entry = f365_statorium_league_entry( (int) $data['season']['leagueID'] );
			$scope = (string) ( $entry['scope'] ?? '' );
			if ( '' !== $scope && ! in_array( $scope, [ 'international_national_team', 'international_friendly_nationals' ], true ) ) {
				$type = 'club';
				if ( 0 === strpos( $scope, 'national_' ) || 0 === strpos( $scope, 'regional_' ) ) {
					$fed_wp = (int) ( $entry['federation_wp'] ?? 0 );
					$hint   = $fed_wp ? (string) get_the_title( $fed_wp ) : '';
				}
			}
		}
		return $this->season_ctx_cache[ $season_id ] = [ $type, $hint ];
	}

	// ════════════════════════════════════════════════════════════════════════
	// ODDS  (premium add-on — `include=odds` → betting.bookmakers, 1x2)
	// ════════════════════════════════════════════════════════════════════════

	const ODDS_OPTION = 'f365_statorium_odds';

	/**
	 * Fetch 1x2 odds for matches in the betting window (kickoff within the
	 * next 48h, or started less than 3h ago) and store them in one option
	 * keyed by PUBLIC match id — the ticker reads the whole map in a single
	 * get_option. Entries for long-finished matches are pruned.
	 * Returns [updated, pruned]. Pass a stat id to force one match (testing).
	 */
	public function sync_odds( int $only_stat_id = 0 ): array {
		global $wpdb;

		if ( $only_stat_id ) {
			$targets = $wpdb->get_results( $wpdb->prepare(
				"SELECT api_id, legacy_id FROM {$wpdb->posts}
				  WHERE api_source = %s AND post_type = 'f365_match' AND api_id = %d",
				self::API_SOURCE, $only_stat_id
			) );
		} else {
			$targets = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.api_id, p.legacy_id FROM {$wpdb->posts} p
				   JOIN {$wpdb->postmeta} dt ON dt.post_id = p.ID AND dt.meta_key = 'date_time'
				  WHERE p.api_source = %s AND p.post_type = 'f365_match' AND p.api_id IS NOT NULL
				    AND dt.meta_value BETWEEN DATE_SUB( UTC_TIMESTAMP(), INTERVAL 3 HOUR )
				                          AND DATE_ADD( UTC_TIMESTAMP(), INTERVAL 48 HOUR )",
				self::API_SOURCE
			) );
		}

		$map     = (array) get_option( self::ODDS_OPTION, [] );
		$updated = 0;

		foreach ( $targets as $t ) {
			$data = $this->api->match( (int) $t->api_id, true, true );
			if ( is_wp_error( $data ) ) {
				continue;
			}
			$books = (array) ( $data['betting']['bookmakers'] ?? [] );
			if ( ! $books ) {
				continue;
			}
			// Preferred bookmaker order; else first available.
			$book = $books['betclick'] ?? $books['bet365'] ?? reset( $books );
			$vals = [];
			foreach ( (array) ( $book['odds']['values'] ?? [] ) as $v ) {
				$vals[ strtolower( (string) $v['value'] ) ] = (float) $v['odd'];
			}
			if ( ! isset( $vals['home'], $vals['draw'], $vals['away'] ) ) {
				continue;
			}
			$map[ (int) $t->legacy_id ] = [
				'1'  => $vals['home'],
				'x'  => $vals['draw'],
				'2'  => $vals['away'],
				'bk' => (string) ( $book['title'] ?? '' ),
				'ts' => time(),
			];
			$updated++;
			usleep( 200000 );
		}

		// Prune entries not refreshed for 4+ days (match long gone).
		$pruned = 0;
		foreach ( $map as $pub => $entry ) {
			if ( ( $entry['ts'] ?? 0 ) < time() - 4 * DAY_IN_SECONDS ) {
				unset( $map[ $pub ] );
				$pruned++;
			}
		}
		update_option( self::ODDS_OPTION, $map, false );
		if ( $updated ) {
			$this->log( "ODDS updated for {$updated} match(es), pruned {$pruned}." );
		}
		return [ $updated, $pruned ];
	}

	// ════════════════════════════════════════════════════════════════════════
	// TRANSFERS  (/transfers/ — global rolling feed, delivered 2026-07)
	// ════════════════════════════════════════════════════════════════════════

	/** Football season edition for a date (July starts the new season). */
	public static function edition_for_date( string $date ): string {
		$ts = strtotime( $date );
		return (string) ( (int) gmdate( 'n', $ts ) >= 7 ? gmdate( 'Y', $ts ) : (int) gmdate( 'Y', $ts ) - 1 );
	}

	/**
	 * Import the /transfers/ feed into the legacy structure the /transferencias/
	 * page reads: wp_transfer_transfer rows (player, origin/destination
	 * teamsteps, edition, type D/E, value, date). Players/teams resolve through
	 * the matcher; unknown ones are created (clubs as team_type=club).
	 * Deduped by (player, date, destination). Returns [imported, skipped].
	 */
	public function import_transfers(): array {
		$data = $this->api->get( '/transfers/', [], true );
		if ( is_wp_error( $data ) || empty( $data['transfers'] ) ) {
			return [ 0, 0 ];
		}
		global $wpdb;

		$type_map = apply_filters( 'f365_statorium_transfer_type_map', [
			'Transfer' => 'D',
			'Loan'     => 'E',
			'End loan' => 'D',
			'Unknown'  => 'D',
		] );

		$imported = 0;
		$skipped  = 0;

		foreach ( (array) $data['transfers'] as $t ) {
			$date       = (string) ( $t['date'] ?? '' );
			$player_sid = (int) ( $t['player']['id'] ?? 0 );
			$from       = (array) ( $t['fromTeam'] ?? [] );
			$to         = (array) ( $t['toTeam'] ?? [] );
			if ( ! $date || ! $player_sid || empty( $from['id'] ) || empty( $to['id'] ) ) {
				$skipped++;
				continue;
			}

			// Teams — clubs unless already known otherwise.
			$resolve_team = function ( array $team ): int {
				$sid = (int) ( $team['id'] ?? 0 );
				$wp  = $this->map_resolve( 'team', $sid );
				if ( ! $wp ) {
					$wp = $this->upsert_team( $sid, [
						'participantID'   => $sid,
						'participantName' => (string) ( $team['name'] ?? '' ),
						'teamShortName'   => (string) ( $team['shortName'] ?? '' ),
						'middleName'      => (string) ( $team['middleName'] ?? '' ),
						'logo'            => (string) ( $team['emblem'] ?? '' ),
					], 'club' );
				}
				return (int) $wp;
			};
			$from_wp = $resolve_team( $from );
			$to_wp   = $resolve_team( $to );
			if ( ! $from_wp || ! $to_wp ) {
				$skipped++;
				continue;
			}

			// Player (name + photo only in this feed — matcher decides).
			$player_wp = $this->map_resolve( 'player', $player_sid )
				?: $this->upsert_player( $player_sid, [
					'playerID' => $player_sid,
					'fullName' => (string) ( $t['player']['fullName'] ?? $t['player']['name'] ?? '' ),
					'shortName' => (string) ( $t['player']['shortName'] ?? '' ),
					'homeName' => (string) ( $t['player']['homeName'] ?? '' ),
					'photo'    => (string) ( $t['player']['photo'] ?? '' ),
				], $to_wp );
			if ( ! $player_wp ) {
				$skipped++; // ambiguous player awaiting auto/create decision
				continue;
			}
			$player_pub = (int) get_post_meta( $player_wp, 'django_player_id', true ) ?: self::pub_id( $player_sid );

			$edition = self::edition_for_date( $date );
			$from_ts = $this->tables()->ensure_teamstep( (int) get_post_meta( $from_wp, 'django_id', true ), $edition );
			$to_ts   = $this->tables()->ensure_teamstep( (int) get_post_meta( $to_wp, 'django_id', true ), $edition );

			// Dedupe on the natural key. Rows inserted before the api_source
			// column existed get stamped here while still in the feed window.
			$exists = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, api_source FROM {$wpdb->prefix}transfer_transfer
				  WHERE player_id = %d AND transfer_date = %s AND team_destination_id = %d LIMIT 1",
				$player_pub, $date, $to_ts
			) );
			if ( $exists ) {
				if ( null === $exists->api_source ) {
					$wpdb->update(
						$wpdb->prefix . 'transfer_transfer',
						[ 'api_source' => self::API_SOURCE, 'api_created_at' => current_time( 'mysql' ) ],
						[ 'id' => (int) $exists->id ]
					);
				}
				$skipped++;
				continue;
			}

			$fee   = ( $t['details']['fee'] ?? 0 );
			$value = ( is_numeric( $fee ) && $fee > 0 ) ? (string) $fee : '';
			$type  = (string) ( $type_map[ (string) ( $t['details']['type'] ?? 'Unknown' ) ] ?? 'D' );

			$wpdb->query( $wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}transfer_transfer
					(player_id, team_origin_id, team_destination_id, edition, transfer_type, value, transfer_date, shirt_number, api_source, api_created_at)
				 VALUES (%d, %d, %d, %s, %s, %s, %s, 0, %s, %s)",
				$player_pub, $from_ts, $to_ts, $edition, $type, $value, $date, self::API_SOURCE, current_time( 'mysql' )
			) );
			$imported++;
			$this->log( "TRANSFER {$date} " . ( $t['player']['name'] ?? $player_sid ) . ": " . ( $from['name'] ?? '?' ) . ' → ' . ( $to['name'] ?? '?' ) . " ({$type})" );
		}

		if ( $imported && class_exists( 'F365_Cache_Store' ) ) {
			F365_Cache_Store::purge_prefix( 'transferencias' );
		}
		$this->log( "SUMMARY transfers imported={$imported} skipped={$skipped}" );
		return [ $imported, $skipped ];
	}

	/**
	 * Save a remote image into the legacy /media/ tree (Django convention the
	 * theme templates read directly). Skips existing files. Tries to convert/
	 * resize via the WP image editor; falls back to raw bytes (browsers sniff
	 * the real format regardless of the extension).
	 */
	public function save_media_image( string $relative_path, string $url, int $width = 0, int $height = 0 ): bool {
		if ( $this->dry_run || '' === $url ) {
			return false;
		}

		// Hardening: writes are confined to /media/ — reject traversal and
		// non-image extensions even though all callers build the path from
		// integer ids.
		if ( false !== strpos( $relative_path, '..' ) || ! preg_match( '/\.(gif|jpe?g|png|webp)$/i', $relative_path ) ) {
			return false;
		}

		$target = trailingslashit( ABSPATH ) . 'media/' . ltrim( $relative_path, '/' );
		if ( file_exists( $target ) ) {
			return true;
		}
		if ( ! wp_mkdir_p( dirname( $target ) ) ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			$this->log( "IMAGE_FAIL media {$relative_path} — " . $tmp->get_error_message() );
			return false;
		}

		$saved  = false;
		$editor = wp_get_image_editor( $tmp );
		if ( ! is_wp_error( $editor ) ) {
			if ( $width && $height ) {
				$editor->resize( $width, $height, false );
			}
			$ext    = strtolower( pathinfo( $target, PATHINFO_EXTENSION ) );
			$mime   = 'gif' === $ext ? 'image/gif' : ( 'png' === $ext ? 'image/png' : 'image/jpeg' );
			$result = $editor->save( $target, $mime );
			$saved  = ! is_wp_error( $result );
		}
		if ( ! $saved ) {
			$saved = (bool) @copy( $tmp, $target ); // raw bytes fallback
		}
		@unlink( $tmp );

		if ( $saved ) {
			$this->log( "MEDIA saved {$relative_path}" );
		}
		return $saved;
	}

	/**
	 * $season_date: any real match date from this season (import_teams()
	 * passes one) — used only to compute the teamstep edition below.
	 *
	 * team_squad (what the Plantel/squad page actually reads — see
	 * f365-team-plantel.php) is populated ELSEWHERE, by rebuild_squads(),
	 * strictly from competition_matchplayer rows — i.e. only players who
	 * have actually APPEARED in a detail-synced match. This function
	 * upserts every player on the FULL roster this endpoint returns
	 * (confirmed live: 36 for a real team), but until today never wrote a
	 * single team_squad row itself — a player who hasn't featured yet this
	 * season (new signing, fringe/reserve player, or simply whose matches
	 * haven't had detail-sync run) was a fully real, upserted player entity
	 * that NEVER appeared on their own team's squad list, no matter how
	 * many times sync ran, because nothing ever created their base squad
	 * row for rebuild_squads() to layer competition stats onto later.
	 * Explicitly ensures the base row for every roster player now — games/
	 * goals are intentionally left at 0 here (comp_pub=0 short-circuits
	 * ensure_squad() before any team_squadcompetition write); rebuild_
	 * squads() fills those in per-competition once real match data exists,
	 * exactly as it already does today.
	 */
	public function import_team_players( int $team_stat_id, int $team_wp_id, int $season_id, string $season_date = '' ): int {
		global $wpdb;
		$data = $this->api->team( $team_stat_id, $season_id );
		if ( is_wp_error( $data ) || empty( $data['team'] ) ) {
			return 0;
		}

		$this->enrich_team_profile( $team_wp_id, (array) $data['team'] );

		$team_pub = (int) get_post_meta( $team_wp_id, 'django_id', true );
		$edition  = $season_date ? self::edition_for_date( $season_date ) : '';
		$teamstep = ( $team_pub && $edition && ! $this->dry_run ) ? $this->tables()->ensure_teamstep( $team_pub, $edition ) : 0;

		$count        = 0;
		$current_pubs = [];
		foreach ( (array) ( $data['team']['players'] ?? [] ) as $pl ) {
			if ( empty( $pl['playerID'] ) ) {
				continue;
			}
			if ( ! $this->upsert_player( (int) $pl['playerID'], $pl, $team_wp_id ) ) {
				continue;
			}
			$count++;
			if ( $teamstep ) {
				$player_wp = $this->map_resolve( 'player', (int) $pl['playerID'] );
				$player_pub = $player_wp ? (int) get_post_meta( $player_wp, 'django_player_id', true ) : 0;
				if ( $player_pub ) {
					$current_pubs[] = $player_pub;
					$info = (array) ( $pl['additionalInfo'] ?? [] );
					$this->tables()->ensure_squad(
						$player_pub, $teamstep, $edition,
						(string) ( $info['position'] ?? '' ), 0,
						(string) ( $pl['playerNumber'] ?? '' ), 0, 0
					);

					// This roster IS the player's current club per Statorium
					// (real case found live: a player transferred/loaned
					// mid-season carried a stale order=0 "primary" row at
					// their PREVIOUS club, while their new team's row sat at
					// order=1 — invisible to the Plantel page's "AND order=0"
					// filter even though the player upserted successfully and
					// ensure_squad() correctly created/found their row here).
					// A player can only be primary at one club per edition —
					// demote any other order=0 row for this player+edition,
					// then make sure THIS row is order=0.
					$wpdb->query( $wpdb->prepare(
						"UPDATE {$wpdb->prefix}team_squad SET `order` = 1
						  WHERE player_id = %d AND edition = %s AND team_id != %d AND `order` = 0",
						$player_pub, $edition, $teamstep
					) );
					$wpdb->query( $wpdb->prepare(
						"UPDATE {$wpdb->prefix}team_squad SET `order` = 0 WHERE player_id = %d AND team_id = %d",
						$player_pub, $teamstep
					) );
				}
			}
		}

		// Demote (never delete — team_squadcompetition rows carry real
		// historical games/goals other pages still read) any player still
		// marked order=0 on THIS exact teamstep+edition but no longer in
		// the CURRENT roster this call just fetched — left on a free
		// transfer, loan ended, contract terminated. Without this, a
		// departed player stays visible on the Plantel page forever
		// alongside the real current squad, since nothing else ever
		// revisits an existing order=0 row once written (found live: 3
		// extra order=0 rows beyond the 36 real current players).
		if ( $teamstep && $current_pubs ) {
			$in = implode( ',', array_map( 'intval', $current_pubs ) );
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}team_squad SET `order` = 1
				  WHERE team_id = %d AND edition = %s AND `order` = 0 AND player_id NOT IN ({$in})",
				$teamstep, $edition
			) );
		}

		$this->log( "OK squad team={$team_stat_id} players={$count}" );
		return $count;
	}

	/**
	 * Team profile enrichment from /teams/{id}: city, foundation year, home
	 * venue, coach name. National teams return these empty; club teams (when
	 * the league package activates) populate them. Fill-gaps policy applies —
	 * postmeta + the mirror team_team row (our rows only for the mirror).
	 */
	public function enrich_team_profile( int $team_wp_id, array $t ): void {
		if ( $this->dry_run || ! $team_wp_id ) {
			return;
		}
		global $wpdb;

		$info    = (array) ( $t['additionalInfo'] ?? [] );
		$city    = trim( (string) ( $t['city'] ?? '' ) );
		$founded = trim( (string) ( $info['founded'] ?? '' ) );
		$coach   = trim( (string) ( $info['coach'] ?? '' ) );
		$legacy  = $this->is_legacy( $team_wp_id );
		$pub     = (int) get_post_meta( $team_wp_id, 'django_id', true );

		$meta = [];
		if ( '' !== $city ) {
			$meta['city'] = $city;
		}
		if ( '' !== $founded ) {
			// Statorium sends "DD-MM-YYYY  (age)" (e.g. "07-06-1914  (112)"),
			// same shape as birthdate — NOT a plain year, despite the field
			// name. A bare 4-digit year is also accepted defensively.
			$meta['foundation'] = preg_match( '/^\d{4}$/', $founded )
				? $founded . '-01-01'
				: self::parse_statorium_date( $founded );
		}

		// The team header + Info tab read city/foundation from the MIRROR
		// row directly, not the ACF postmeta below — without this the
		// fields stay blank on the frontend regardless of $meta.
		if ( $pub && ! $this->dry_run ) {
			$this->tables()->update_team_profile_row( $pub, $city, (string) ( $meta['foundation'] ?? '' ) );
		}

		// Home venue → stadium entity + link.
		$venue_id   = (int) ( $t['homeVenue']['id'] ?? 0 );
		$venue_name = trim( (string) ( $t['homeVenue']['name'] ?? '' ) );
		$stadium_wp = 0;
		if ( $venue_id && '' !== $venue_name ) {
			$stadium_wp = $this->upsert_venue( $venue_id, $venue_name );
			if ( $stadium_wp ) {
				$meta['stadium'] = $stadium_wp;
				// STADIUM QUIRK (see upsert_venue()): legacy stadium posts
				// carry their public id ONLY in django_stadium_id, never
				// django_id — reading django_id here silently returned 0 for
				// any team whose home venue matched a legacy-only stadium,
				// skipping the team_team.stadium_id link entirely even
				// though the stadium itself resolved correctly.
				$meta['django_stadium_id'] = (int) get_post_meta( $stadium_wp, 'django_stadium_id', true )
					?: (int) get_post_meta( $stadium_wp, 'django_id', true );

				// Mirror home stadium — the competition Estadios page derives
				// CHAMPIONSHIP stadiums from team_team.stadium_id (per
				// participant), so club teams need it. Gap-fill only: a
				// legacy team's existing stadium is never overwritten.
				if ( ! $this->dry_run && $meta['django_stadium_id'] ) {
					global $wpdb;
					$team_pub = (int) get_post_meta( $team_wp_id, 'django_id', true );
					if ( $team_pub ) {
						$wpdb->query( $wpdb->prepare(
							"UPDATE {$wpdb->prefix}team_team SET stadium_id = %d
							  WHERE id = %d AND ( stadium_id IS NULL OR stadium_id = 0 )",
							(int) $meta['django_stadium_id'], $team_pub
						) );
					}
				}
			}
		}

		// CLUB country_id/federation_id: a national team's got resolved at
		// row-creation time (its name IS the country — see sync_team_row);
		// a club's country is derived from its home venue (just enriched
		// above, if a stadium was found). Prefer the stadium's OWN already-
		// resolved country_id (a real FK, no parsing needed) over re-
		// deriving from its free-text address — verified live that a
		// stadium can carry a correct country_id while its address is just
		// a bare street ("Olympialaan 74", no city/country segment at all)
		// or empty outright, which derive_country_from_address() can never
		// parse a country out of no matter how good its logic is. This was
		// silently leaving country_id/federation_id at 0 for clubs whose
		// stadium happened to have this shape, even though the answer was
		// sitting right there on the stadium row. Falls back to the address
		// parse only when the stadium's own country_id is unset. Best-
		// effort either way: genuinely unresolvable cases are skipped,
		// never guessed.
		if ( ! $this->dry_run && $pub ) {
			global $wpdb;
			$is_club = 'club' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT team_type FROM {$wpdb->prefix}team_team WHERE id = %d", $pub ) );
			if ( $is_club ) {
				$pt_country  = '';
				$stadium_pub = (int) ( $meta['django_stadium_id'] ?? 0 );
				if ( $stadium_pub ) {
					$stadium = $wpdb->get_row( $wpdb->prepare(
						"SELECT address, country_id FROM {$wpdb->prefix}stadium_stadium WHERE id = %d", $stadium_pub
					) );
					if ( $stadium && $stadium->country_id ) {
						$pt_country = (string) $wpdb->get_var( $wpdb->prepare(
							"SELECT shortname FROM {$wpdb->prefix}country_country WHERE id = %d", (int) $stadium->country_id
						) );
					}
					if ( ! $pt_country && $stadium && $stadium->address ) {
						$pt_country = self::derive_country_from_address( (string) $stadium->address );
					}
				}
				if ( $pt_country ) {
					$country_id    = $this->tables()->country_id_by_name( $pt_country );
					$federation_id = $this->tables()->federation_id_for_country( $pt_country, false );
					$this->tables()->update_team_country_federation( $pub, $country_id, $federation_id );
				}
			}
		}

		if ( $meta ) {
			$this->write_fields( $team_wp_id, $meta, $legacy );
		}
		// NOTE: mirror city/foundation are written earlier in this method via
		// update_team_profile_row() (fill-gap, created AND legacy); stadium_id
		// is gap-filled just above alongside the stadium entity link. Both
		// superseded an old unconditional-overwrite / created-only block that
		// used to live here (and never actually wrote foundation — same
		// broken 4-digit-year regex the postmeta assignment had).

		// Coach name → the teamstep's tmp_coach (what the team header shows);
		// fill only when empty, never overwrite legacy staff data. Also
		// registers the coach as a proper team_teamstepcoach/person_coach
		// entity — a DIFFERENT table the Plantel "Equipa Técnica" section
		// and Histórico page read; without this the coach's name showed on
		// the header but the dedicated staff section stayed empty even
		// though the API supplied it.
		if ( '' !== $coach ) {
			$edition   = (string) ( get_post_meta( $team_wp_id, 'statorium_last_edition', true ) ?: gmdate( 'Y' ) );
			$latest_ts = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}team_teamstep WHERE team_id = %d ORDER BY ( edition + 0 ) DESC LIMIT 1",
				$pub
			) );
			if ( $latest_ts ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->prefix}team_teamstep
					    SET tmp_coach = %s
					  WHERE id = %d AND ( tmp_coach IS NULL OR tmp_coach = '' )",
					$coach, $latest_ts
				) );
				$this->tables()->ensure_teamstepcoach( $latest_ts, $edition, $coach );
			}
		}
	}

	public function upsert_player( int $stat_id, array $pl, int $team_wp_id ): int {
		$name = (string) ( ( $pl['fullName'] ?? '' ) ?: trim( ( $pl['firstName'] ?? '' ) . ' ' . ( $pl['lastName'] ?? '' ) ) );
		if ( '' === $name ) {
			return 0;
		}

		$info      = (array) ( $pl['additionalInfo'] ?? [] );
		$birthdate = $this->parse_birthdate( (string) ( $info['birthdate'] ?? '' ) );

		// Mapping signals per spec: name + country + shirt number (+ birthdate).
		$wp_id = $this->upsert( 'player', $stat_id, [ 'post_title' => $name ], [
			'names'     => array_filter( [ $name, (string) ( $pl['homeName'] ?? '' ) ] ),
			'country'   => self::country_pt( (string) ( $pl['country']['name'] ?? '' ) ),
			'birthdate' => $birthdate,
			'shirt'     => (string) ( $pl['playerNumber'] ?? '' ),
		] );
		if ( ! $wp_id ) {
			return 0;
		}
		$legacy = $this->is_legacy( $wp_id );
		$pub    = $this->pub_or_keep( $wp_id, 'django_player_id', $stat_id );

		// Legacy persons-module convention: post_name = the public numeric id.
		// WP auto-slugs from the title on insert, which makes the canonical URL
		// /jogador/{title-slug}/ and redirects the numeric URL away — force the
		// numeric slug on posts we own (legacy slugs untouched).
		if ( ! $legacy && ! $this->dry_run ) {
			$post = get_post( $wp_id );
			if ( $post && (string) $pub !== $post->post_name ) {
				wp_update_post( [ 'ID' => $wp_id, 'post_name' => (string) $pub ] );
			}
			// wp_update_post can re-trigger Permalink Manager's URI generator.
			$this->strip_permalink_manager_uri( $wp_id );
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
		], $legacy );
		if ( ! $legacy ) {
			$this->set_legacy_columns( $wp_id, $pub, 'person_player' );
		}

		if ( ! empty( $info['position'] ) ) {
			$this->set_term_guarded( $wp_id, $this->map_position( (string) $info['position'] ), 'f365_position', $legacy );
		}
		// Country: prefer the language-proof mapped id (see `countries` CLI),
		// fall back to the PT-translated name. Same ownership policy as
		// set_term_guarded: owned/full → set; legacy fill-gaps → only when
		// empty; legacy strict → never.
		$country_term = ! empty( $pl['country']['id'] ) ? self::country_term_by_stat_id( (int) $pl['country']['id'] ) : 0;
		if ( $country_term && ! $this->dry_run ) {
			$mode  = $this->mode();
			$allow = ! $legacy || 'full' === $mode;
			if ( ! $allow && 'fill-gaps' === $mode ) {
				$current = wp_get_object_terms( $wp_id, 'f365_country', [ 'fields' => 'ids' ] );
				$allow   = ! is_wp_error( $current ) && empty( $current );
			}
			if ( $allow ) {
				wp_set_object_terms( $wp_id, [ $country_term ], 'f365_country', true );
			}
		} elseif ( ! empty( $pl['country']['name'] ) ) {
			$this->set_term_guarded( $wp_id, self::country_pt( (string) $pl['country']['name'] ), 'f365_country', $legacy );
		}
		if ( ! empty( $pl['photo'] ) ) {
			$this->set_thumbnail_guarded( $wp_id, (string) $pl['photo'], $name, $legacy );
		}

		// Mirror person rows (player pages read these) with nationality + birthdate.
		if ( ! $this->dry_run ) {
			$nationality = ! empty( $pl['country']['name'] )
				? $this->tables()->country_id_by_name( self::country_pt( (string) $pl['country']['name'] ) )
				: 0;
			$this->tables()->sync_person_rows(
				$pub, $name, (string) ( $pl['shortName'] ?? $name ),
				(string) ( $info['position'] ?? '' ), $birthdate,
				(string) ( $info['height'] ?? '' ), (string) ( $info['weight'] ?? '' ),
				$nationality
			);
		}

		$this->bump( 'players' );
		return $wp_id;
	}

	// ════════════════════════════════════════════════════════════════════════
	// MATCHES
	// ════════════════════════════════════════════════════════════════════════

	public function import_matches( int $season_id ): int {
		// Same subscription short-circuit as import_teams() — /season_matches/
		// has no gate of its own, so a known-blocked season would otherwise
		// waste an API call and log a raw FAIL every run instead of a clean skip.
		if ( ! $this->map_resolve( 'season', $season_id ) && self::season_unsubscribed_league( $season_id ) ) {
			$this->log( "SKIP matches season={$season_id} — league not in subscription map" );
			return 0;
		}
		$data = $this->api->season_matches( $season_id );
		if ( is_wp_error( $data ) || empty( $data['calendar']['matchdays'] ) ) {
			$this->log( "FAIL matches season={$season_id} — cannot load calendar" );
			return 0;
		}

		$season_name = self::expand_season_name( (string) ( $data['calendar']['seasonName'] ?? '' ) );

		// UEFA's 3 club competitions (Champions/Europa/Conference League) use
		// a single round-robin "League Phase" since 2024-25 instead of named
		// groups, so it never hits sync_match_row()'s group_home/group_away
		// branch — and Statorium names its rounds "MatchDay N", identical to
		// any ordinary league, so the matchday NAME alone carries no signal
		// of which format it belongs to. Only the competition's own identity
		// (via its league's config_key) can tell them apart; confirmed live
		// this was leaving competition_phase_id NULL for the whole League
		// Phase, dropping the "Fase de Liga" prefix on the calendar page.
		$is_league_phase = false;
		$comp_wp         = $this->map_resolve( 'season', $season_id );
		$comp_league_id  = $comp_wp ? (int) get_post_meta( $comp_wp, 'statorium_league_id', true ) : 0;
		if ( $comp_league_id && function_exists( 'f365_statorium_league_entry' ) ) {
			$entry            = f365_statorium_league_entry( $comp_league_id );
			$is_league_phase  = in_array( (string) ( $entry['config_key'] ?? '' ), [ 'champions-league', 'europa-league', 'conference-league' ], true );
		}

		$count = 0;
		foreach ( $data['calendar']['matchdays'] as $md ) {
			$round_no = 0;
			if ( preg_match( '/MatchDay\s+(\d+)/i', (string) ( $md['matchdayName'] ?? '' ), $mm ) ) {
				$round_no = (int) $mm[1];
			}
			if ( $round_no && $is_league_phase ) {
				$phase = 'league_phase';
			} else {
				$phase = $round_no ? '' : $this->map_phase( (string) ( $md['matchdayName'] ?? '' ) );
			}

			foreach ( (array) ( $md['matches'] ?? [] ) as $m ) {
				if ( empty( $m['matchID'] ) ) {
					continue;
				}
				if ( $this->import_match_from_payload( $m, $season_id, $season_name, $round_no, $phase ) ) {
					$count++;
				}
			}
		}

		$this->log( "SUMMARY matches season={$season_id} upserted={$count}" );

		// Legacy-linked competition: fold our freshly-imported rows into any
		// pre-loaded legacy fixtures (same pairing the Mundial merge used).
		$comp_wp  = $this->map_resolve( 'season', $season_id );
		$comp_pub = $comp_wp ? (int) get_post_meta( $comp_wp, 'django_competition_id', true ) : 0;
		if ( $comp_pub && $comp_pub < self::ID_OFFSET ) {
			$paired = $this->repair_group_pairing( $comp_pub );
			$this->repair_fixture_team_twins( $comp_pub ); // duplicate clubs inferred from fixtures
			$paired += $this->repair_duplicate_fixtures( $comp_pub ); // completed legacy fixtures (historical seasons)
			if ( $paired ) {
				$this->log( "AUTOPAIR season={$season_id}: {$paired} fixture(s) folded into legacy rows" );
			}
		}
		if ( $comp_pub ) {
			// Knockout stages need sequential rounds after the group rounds
			// (the calendário sections by round) — the API sends none.
			$this->repair_knockout_rounds( $comp_pub );
		}

		return $count;
	}

	/**
	 * Upsert one match from a calendar / todaymatches / live payload.
	 * Ensures its season and both teams first (used by the day-cursor backfill,
	 * where matches arrive one by one with their own seasonID).
	 */
	public function import_match_from_payload( array $m, int $season_id = 0, string $season_name = '', int $round_no = 0, string $phase = '' ): int {
		$stat_id = (int) ( $m['matchID'] ?? 0 );
		if ( ! $stat_id ) {
			return 0;
		}

		$season_id = $season_id ?: (int) ( $m['seasonID'] ?? 0 );
		if ( ! $season_id ) {
			$this->log( "SKIP match={$stat_id} — no season id in payload" );
			return 0;
		}

		$comp_wp_id = $this->map_resolve( 'season', $season_id );
		if ( ! $comp_wp_id ) {
			$comp_wp_id = $this->import_season( $season_id );
			if ( ! $comp_wp_id ) {
				// Unsubscribed league (or season fetch failed) — do not
				// create orphan matches/teams for it.
				return 0;
			}
			$this->remember_season( $season_id );
		}

		// Ensure both teams (payloads carry full participant objects). The
		// league scope decides club vs national (and the country hint) — the
		// old blanket 'national' default typed every club-league team as a
		// national side.
		$home = (array) ( $m['homeParticipant'] ?? [] );
		$away = (array) ( $m['awayParticipant'] ?? [] );

		[ $team_type, $country_hint ] = $this->season_team_context( $season_id );

		$home_wp = 0;
		$away_wp = 0;
		if ( ! empty( $home['participantID'] ) ) {
			$home_wp = $this->map_resolve( 'team', (int) $home['participantID'] )
				?: $this->upsert_team( (int) $home['participantID'], $home, $team_type, $country_hint );
		}
		if ( ! empty( $away['participantID'] ) ) {
			$away_wp = $this->map_resolve( 'team', (int) $away['participantID'] )
				?: $this->upsert_team( (int) $away['participantID'], $away, $team_type, $country_hint );
		}

		// Prefer the site's own team names (linked legacy posts keep their
		// pt-PT titles, e.g. "Chéquia" not Statorium's pt-BR "Tchéquia").
		$home_name = $home_wp ? get_the_title( $home_wp ) : $this->pt_name( $home, (string) ( $home['participantName'] ?? '?' ) );
		$away_name = $away_wp ? get_the_title( $away_wp ) : $this->pt_name( $away, (string) ( $away['participantName'] ?? '?' ) );
		$title     = "{$home_name} - {$away_name}";

		$wp_id = $this->upsert( 'match', $stat_id, [ 'post_title' => $title ], null );
		if ( ! $wp_id ) {
			return 0;
		}
		$legacy = $this->is_legacy( $wp_id );

		$stadium_wp = 0;
		// Payload shapes differ: the season calendar sends
		// matchVenue{venueID, venueName}; /todaymatches/ sends only
		// matchVenue{value: <id>} with NO name — resolve it via /venues/{id}/
		// (cached) so day-based syncs create/link stadiums too.
		$venue_stat = (int) ( $m['matchVenue']['venueID'] ?? $m['matchVenue']['value'] ?? 0 );
		$venue_name = trim( (string) ( $m['matchVenue']['venueName'] ?? '' ) );
		if ( $venue_stat && '' === $venue_name ) {
			$vd = $this->api->venue( $venue_stat );
			if ( ! is_wp_error( $vd ) ) {
				$venue_name = trim( (string) ( $vd['venue']['venueName'] ?? '' ) );
			}
		}
		if ( $venue_stat && '' !== $venue_name ) {
			$stadium_wp = $this->upsert_venue( $venue_stat, $venue_name );
		}

		// todaymatches uses matchStatus.value; the calendar uses matchStatus.statusID.
		$status_id = (string) ( $m['matchStatus']['statusID'] ?? $m['matchStatus']['value'] ?? '0' );
		$situation = $this->map_status( $status_id );
		$is_extra  = ! empty( $m['extraTime']['is_extra'] ) && '0' !== (string) $m['extraTime']['is_extra'];

		$date_time = trim( ( $m['matchDate'] ?? '' ) . ' ' . ( $m['matchTime'] ?? '' ) );
		if ( $date_time && strlen( $date_time ) <= 16 ) {
			$date_time .= ':00';
		}

		$meta = [
			'date_time'              => $date_time,
			'situation'              => $situation,
			'home_score'             => isset( $home['score'] ) && '' !== (string) $home['score'] ? (int) $home['score'] : '',
			'visitor_score'          => isset( $away['score'] ) && '' !== (string) $away['score'] ? (int) $away['score'] : '',
			'home_score_extra'       => $is_extra ? (int) ( $m['extraTime']['home_score'] ?? 0 ) : '',
			'visitor_score_extra'    => $is_extra ? (int) ( $m['extraTime']['away_score'] ?? 0 ) : '',
			'django_match_id'        => $this->pub_or_keep( $wp_id, 'django_match_id', $stat_id ),
			'django_competition_id'  => $comp_wp_id ? (int) get_post_meta( $comp_wp_id, 'django_competition_id', true ) : '',
			'django_home_team_id'    => $home_wp ? (int) get_post_meta( $home_wp, 'django_id', true ) : '',
			'django_visitor_team_id' => $away_wp ? (int) get_post_meta( $away_wp, 'django_id', true ) : '',
			// STADIUM QUIRK (see upsert_venue()): legacy stadium posts carry
			// their public id ONLY in django_stadium_id, never django_id —
			// reading django_id here silently returned 0 (never ''  since
			// the ternary's guard was $stadium_wp only) for a match played
			// at a legacy-only stadium, dropping both the match's own
			// stadium_id and the stadium_stadium mirror sync below.
			'django_stadium_id'      => $stadium_wp
				? ( (int) get_post_meta( $stadium_wp, 'django_stadium_id', true ) ?: (int) get_post_meta( $stadium_wp, 'django_id', true ) )
				: '',
			'statorium_status_id'    => $status_id,
		];

		// Round/phase only when known — day-based payloads don't carry them, and
		// overwriting a previously-correct round with a default would lose data.
		if ( $round_no ) {
			$meta['match_round'] = $round_no;
		}
		if ( '' !== $phase ) {
			$meta['phase'] = $phase;
		}

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
		if ( ! $legacy ) {
			$this->set_legacy_columns( $wp_id, (int) $meta['django_match_id'], 'competition_match' );
		}

		if ( $situation ) {
			$this->set_term_guarded( $wp_id, $situation, 'f365_match_status', $legacy );
		}
		$season_name = self::expand_season_name( $season_name ?: (string) get_post_meta( $comp_wp_id, 'season', true ) );
		if ( $season_name ) {
			$this->set_term_guarded( $wp_id, $season_name, 'f365_season', $legacy );
			$this->detach_stale_season_term( $wp_id, $season_name, $legacy );
		}

		// ── Mirror tables — the frontend reads these, not postmeta. ──────────
		if ( ! $this->dry_run && $comp_wp_id && $home_wp && $away_wp ) {
			$comp_pub  = (int) $meta['django_competition_id'];
			$home_pub  = (int) $meta['django_home_team_id'];
			$away_pub  = (int) $meta['django_visitor_team_id'];
			$stad_pub  = (int) ( $meta['django_stadium_id'] ?? 0 );

			if ( $comp_pub && $home_pub && $away_pub ) {
				$this->tables()->sync_competition_row( $comp_pub, get_the_title( $comp_wp_id ), $season_name );
				$this->tables()->sync_team_row( $home_pub, $home_name, (string) ( $home['teamShortName'] ?? '' ) );
				$this->tables()->sync_team_row( $away_pub, $away_name, (string) ( $away['teamShortName'] ?? '' ) );
				if ( $stad_pub && $venue_name ) {
					$this->tables()->sync_stadium_row( $stad_pub, $venue_name );
				}

				// Group columns (PT names) — group stage only.
				$group_home = '';
				$group_away = '';
				if ( '' === $phase ) {
					$group_home = ! empty( $home['participantID'] ) ? $this->season_group_of( $season_id, (int) $home['participantID'] ) : '';
					$group_away = ! empty( $away['participantID'] ) ? $this->season_group_of( $season_id, (int) $away['participantID'] ) : '';
				}

				$this->tables()->sync_match_row( [
					'pub_id'              => (int) $meta['django_match_id'],
					'comp_pub_id'         => $comp_pub,
					'comp_name'           => get_the_title( $comp_wp_id ),
					'season'              => $season_name,
					'round'               => (int) ( $meta['match_round'] ?? 1 ),
					'phase'               => (string) ( $meta['phase'] ?? '' ),
					'group_home'          => $group_home,
					'group_visitor'       => $group_away,
					'date_time'           => $date_time,
					'home_team_pub'       => $home_pub,
					'home_name'           => $home_name,
					'away_team_pub'       => $away_pub,
					'away_name'           => $away_name,
					'home_score'          => $meta['home_score'],
					'visitor_score'       => $meta['visitor_score'],
					'home_score_extra'    => $meta['home_score_extra'],
					'visitor_score_extra' => $meta['visitor_score_extra'],
					'leg'                 => '',
					'situation'           => $situation,
					'stadium_pub'         => $stad_pub,
					'attendance'          => '',
					'duration'            => '',
				] );
			}

			// /jogos-em-direto/ coverage: header row + backing post so the
			// match appears in the live archive (scheduled now, live later).
			$this->ensure_livegame( $wp_id );
		}

		$this->bump( 'matches' );
		return $wp_id;
	}

	/**
	 * Fetch /matches/{id} and write full detail (lineups, events, stats) into
	 * the mirror tables. $payload can be passed to skip the API call.
	 */
	public function sync_match_detail( int $stat_id, ?array $payload = null ): bool {
		$wp_id = $this->map_resolve( 'match', $stat_id );
		if ( ! $wp_id ) {
			return false; // base match must be imported first (season-gated)
		}

		if ( null === $payload ) {
			$data = $this->api->match( $stat_id, true );
			if ( is_wp_error( $data ) || empty( $data['match'] ) ) {
				$this->log( "FAIL detail match={$stat_id} — cannot fetch" );
				return false;
			}
			$payload = $data['match'];
			$payload['stages'] = $data['stages'] ?? []; // top-level sibling of `match` — penalty/extra-time authoritative scores
		}

		$pub = (int) get_post_meta( $wp_id, 'django_match_id', true ) ?: self::pub_id( $stat_id );

		$this->tables()->sync_match_detail( $pub, $payload, function ( int $player_stat_id ): int {
			$player_wp = $this->map_resolve( 'player', $player_stat_id );
			if ( $player_wp ) {
				$meta = (int) get_post_meta( $player_wp, 'django_player_id', true );
				if ( $meta ) {
					return $meta;
				}
			}
			return self::pub_id( $player_stat_id );
		} );

		// Referee crew — the API defines the fields but sends them empty so
		// far ("referee assitant" sic is theirs). Captured as additive meta
		// the moment Statorium populates them; entity/display wiring can
		// follow once real data exists. Attendance is already written to the
		// mirror `assistance` column by the tables layer.
		$info = (array) ( $payload['additionalInfo'] ?? [] );
		foreach ( [ 'referee' => 'statorium_referee', 'referee assitant' => 'statorium_referee_assistant', 'fourth official' => 'statorium_fourth_official' ] as $field => $meta_key ) {
			$name = trim( (string) ( $info[ $field ] ?? '' ) );
			if ( '' !== $name ) {
				update_post_meta( $wp_id, $meta_key, $name );
				$this->log( "REFEREE match={$stat_id} {$meta_key}=\"{$name}\"" );
			}
		}

		update_post_meta( $wp_id, 'statorium_detail_synced', gmdate( 'c' ) );
		$this->bump( 'details' );
		return true;
	}

	/**
	 * Stadium name variants: legacy names drop the generic word ("SoFi",
	 * "Azteca", "Hard Rock") while the API sends "SoFi Stadium" /
	 * "Estadio Azteca" — matching must try both forms.
	 */
	public static function stadium_name_variants( string $name ): array {
		$variants = [ $name ];
		$stripped = trim( preg_replace( '/\b(est[áa]dio|stadium|estadio)\b/iu', '', $name ) );
		$stripped = trim( preg_replace( '/\s+/', ' ', $stripped ) );
		if ( '' !== $stripped && mb_strlen( $stripped ) >= 3 && strcasecmp( $stripped, $name ) !== 0 ) {
			$variants[] = $stripped;
		}
		return $variants;
	}

	public function upsert_venue( int $stat_id, string $name ): int {
		$existing = $this->map_resolve( 'venue', $stat_id );
		if ( $existing ) {
			return $existing;
		}

		$wp_id = $this->upsert( 'venue', $stat_id, [ 'post_title' => $name ], [ 'names' => self::stadium_name_variants( $name ) ] );
		if ( ! $wp_id ) {
			return 0;
		}
		$legacy = $this->is_legacy( $wp_id );

		// STADIUM QUIRK: legacy stadium posts carry their public id ONLY in
		// `django_stadium_id` (no django_id meta). Resolving through
		// django_id first used to stamp a fake OFFSET pub onto linked legacy
		// posts AND spawn a parallel mirror row — one pub, both keys.
		$venue_pub = $this->pub_or_keep( $wp_id, 'django_stadium_id', $stat_id );
		$this->write_fields( $wp_id, [
			'django_id'         => $venue_pub,
			'django_stadium_id' => $venue_pub,
		], $legacy );
		if ( ! $legacy ) {
			$this->set_legacy_columns( $wp_id, $venue_pub, 'stadium_stadium' );
		}

		// Guarantee the mirror row exists BEFORE enrich_venue(), which only
		// ever UPDATEs it — enrich_venue is also skipped entirely on repeat
		// calls (statorium_venue_synced), so without this INSERT a venue
		// discovered only via a team's homeVenue (never through a match,
		// which is the only other path that creates this row) has no
		// wp_stadium_stadium row at all and every frontend page reading it
		// directly (team header "Estádio:", the stadium's own /estadio/
		// page) renders blank. INSERT IGNORE — harmless if the match path
		// already created it.
		if ( ! $legacy && ! $this->dry_run ) {
			$this->tables()->sync_stadium_row( $venue_pub, $name );
		}

		$this->enrich_venue( $stat_id );

		$this->bump( 'venues' );
		return $wp_id;
	}

	/**
	 * Fetch /venues/{id}/ and enrich the stadium: photo (featured image + the
	 * static /media/images/stadium/{pub}.jpg the hero template reads),
	 * capacity/address/city/coordinates into postmeta and the mirror row
	 * (created rows only — legacy rows stay untouched in strict mode).
	 */
	public function enrich_venue( int $stat_id ): bool {
		$wp_id = $this->map_resolve( 'venue', $stat_id );
		if ( ! $wp_id || $this->dry_run ) {
			return false;
		}
		if ( get_post_meta( $wp_id, 'statorium_venue_synced', true ) ) {
			return true;
		}

		$data = $this->api->venue( $stat_id );
		if ( is_wp_error( $data ) || empty( $data['venue'] ) ) {
			return false;
		}
		$v      = $data['venue'];
		$info   = (array) ( $v['additionalInfo'] ?? [] );
		$legacy = $this->is_legacy( $wp_id );
		$pub    = (int) get_post_meta( $wp_id, 'django_id', true ) ?: self::pub_id( $stat_id );

		$opened = trim( (string) ( $info['opened'] ?? '' ) );
		if ( preg_match( '/^\d{4}$/', $opened ) ) {
			$opened .= '-01-01'; // year-only → date the templates can format
		}

		// venueCity is empty on most payloads (verified live); no reliable
		// derivation from the address exists (see derive_country_from_address's
		// sibling docblock) so city just stays whatever the API sends.
		$address    = (string) ( $v['venueAddress'] ?? '' );
		$city       = (string) ( $v['venueCity'] ?? '' );
		$pt_country = self::derive_country_from_address( $address );

		$this->write_fields( $wp_id, [
			'capacity'  => (string) ( $info['capacity'] ?? '' ),
			'address'   => $address,
			'city'      => $city,
			'latitude'  => (string) ( $v['venueCoordX'] ?? '' ),
			'longitude' => (string) ( $v['venueCoordY'] ?? '' ),
			'opening'   => $opened,
		], $legacy );

		// f365_country term — the stadium hero header's flag/country-name
		// line reads THIS (get_the_terms, not postmeta/mirror), and no
		// import path ever assigned it to a stadium post (only teams and
		// players got it) — every created stadium's hero was silently
		// missing its flag and country name.
		if ( $pt_country ) {
			$this->set_term_guarded( $wp_id, $pt_country, 'f365_country', $legacy );
		}

		// Mirror row columns — the stadium page and info tab read THESE, not
		// the postmeta above. Our rows: refreshed freely. Legacy rows:
		// gap-fill per column (a legacy stadium with an empty capacity or
		// city stays blank on the frontend forever otherwise; existing
		// values are never overwritten — fill-gaps policy).
		global $wpdb;
		$country_id = $pt_country ? $this->tables()->country_id_by_name( $pt_country ) : 0;

		if ( $pub >= self::ID_OFFSET ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}stadium_stadium
				    SET capacity = %d, city = %s, address = %s, latitude = %s, longitude = %s,
				        country_id = " . ( $country_id ?: 'country_id' ) . ',
				        opening = ' . ( '' !== $opened ? '%s' : 'opening' ) . '
				  WHERE id = %d',
				...array_merge(
					[
						(int) ( $info['capacity'] ?? 0 ), $city, $address,
						(string) ( $v['venueCoordX'] ?? '' ), (string) ( $v['venueCoordY'] ?? '' ),
					],
					'' !== $opened ? [ $opened, $pub ] : [ $pub ]
				)
			) );
		} elseif ( $pub > 0 ) {
			foreach ( [
				'capacity'  => (int) ( $info['capacity'] ?? 0 ),
				'city'      => $city,
				'address'   => $address,
				'latitude'  => (string) ( $v['venueCoordX'] ?? '' ),
				'longitude' => (string) ( $v['venueCoordY'] ?? '' ),
			] as $col => $val ) {
				if ( '' === (string) $val || 0 === $val ) {
					continue;
				}
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->prefix}stadium_stadium SET {$col} = %s
					  WHERE id = %d AND ( {$col} IS NULL OR {$col} = '' OR {$col} = '0' )",
					(string) $val, $pub
				) );
			}
			if ( '' !== $opened ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->prefix}stadium_stadium SET opening = %s WHERE id = %d AND opening IS NULL",
					$opened, $pub
				) );
			}
			if ( $country_id ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->prefix}stadium_stadium SET country_id = %d
					  WHERE id = %d AND ( country_id IS NULL OR country_id = 0 )",
					$country_id, $pub
				) );
			}
		}

		if ( ! empty( $v['photo'] ) ) {
			$this->set_thumbnail_guarded( $wp_id, (string) $v['photo'], get_the_title( $wp_id ), $legacy );
			// Hero template reads /media/images/stadium/{public_id}.jpg.
			$this->save_media_image( 'images/stadium/' . $pub . '.jpg', (string) $v['photo'] );
		}

		update_post_meta( $wp_id, 'statorium_venue_synced', gmdate( 'c' ) );
		$this->log( "VENUE enriched stat_id={$stat_id} wp_id={$wp_id}" );
		return true;
	}

	/**
	 * Per-sync heal: stadiums whose mirror row is still missing capacity or
	 * address despite enrich_venue() having already run once. Unlike
	 * players/teams (heal_player_profiles()/heal_team_profiles()), stadiums
	 * had NO recheck sweep at all — enrich_venue() only ever fires once, from
	 * upsert_venue() at creation time, and its own `statorium_venue_synced`
	 * flag then blocks every future attempt permanently. If that one attempt
	 * landed on a transient empty/partial API response (confirmed live: a
	 * stadium's real capacity/address were sitting in the API the whole
	 * time, just not returned at the moment of the original import), the
	 * stadium page stays incomplete forever with no way to self-correct.
	 * Clears the flag and retries, throttled the same way as the player/team
	 * heals — the flag's own ISO-8601 timestamp doubles as the "last
	 * checked" marker, so a stadium the API genuinely has nothing for isn't
	 * re-fetched every cycle either.
	 */
	public function heal_stadiums(): int {
		global $wpdb;
		// No batch cap — the API has no rate limit worth throttling for, so
		// every pending stadium is processed in this one call rather than
		// trickling 25/cycle across many syncs.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			   JOIN {$wpdb->prefix}stadium_stadium s ON s.id = p.legacy_id
			  WHERE p.post_type = 'f365_stadium' AND p.post_status = 'publish'
			    AND p.api_source = 'statorium' AND p.api_id IS NOT NULL
			    AND ( s.capacity IS NULL OR s.capacity = 0 OR s.address IS NULL OR s.address = '' )
			    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} ck
			                      WHERE ck.post_id = p.ID AND ck.meta_key = 'statorium_venue_synced'
			                        AND ck.meta_value > %s )",
			gmdate( 'c', time() - 7 * DAY_IN_SECONDS )
		) );
		$done = 0;
		foreach ( $ids as $pid ) {
			$pid  = (int) $pid;
			$stat = self::api_id_of( $pid );
			if ( ! $stat ) {
				continue;
			}
			delete_post_meta( $pid, 'statorium_venue_synced' ); // allow enrich_venue() to run again
			if ( $this->enrich_venue( $stat ) ) {
				$done++;
			}
			usleep( 200000 );
		}
		if ( $done ) {
			$this->log( "SHEAL enriched {$done} stadium(s) previously missing capacity/address" );
		}
		return $done;
	}

	// ════════════════════════════════════════════════════════════════════════
	// LIVE TICK
	// ════════════════════════════════════════════════════════════════════════

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

			// /jogos-em-direto/ live page: status vocabulary is '-1'
			// scheduled, minute-as-number while in play, RF finished. Prefer
			// the API's matchTicker minute (premium) over the computed one.
			$pub = $this->ensure_livegame( $wp_id );
			if ( $pub ) {
				if ( '1' === $status_id || 'finished' === $situation ) {
					$live_status = 'RF';
				} else {
					$ticker = trim( (string) ( $m['matchTicker'] ?? '' ) );
					if ( '' !== $ticker ) {
						$live_status = $ticker;
					} else {
						$kick    = strtotime( (string) get_post_meta( $wp_id, 'date_time', true ) . ' UTC' );
						$minutes = $kick ? (int) floor( ( time() - $kick ) / 60 ) : 0;
						$live_status = $minutes > 45 && $minutes < 60 ? 'HT' : (string) max( 1, min( 120, $minutes ) );
					}
				}
				$this->tables()->update_livematch(
					$pub,
					$live_status,
					isset( $home['score'] ) ? $home['score'] : null,
					isset( $away['score'] ) ? $away['score'] : null
				);
			}

			// /matches/live/ is deliberately a thin per-match summary (score,
			// minute, status) — the provider's own guidance is that it isn't
			// meant to carry full data for many concurrent matches, and
			// events/lineups/stats need the per-match /matches/{id} call.
			// Previously we only made that call once a match's
			// statorium_status_id flipped to '1' (finished), so the live
			// Events/Ficha/Estatísticas tabs stayed empty for the whole
			// match. Call it here too, every tick, for every match still in
			// the live feed — sync_match_detail() wipes+rebuilds the detail
			// rows per call, so repeating it each tick is idempotent and
			// keeps the live tabs current while play is in progress. This
			// also covers the finish transition (the last tick where a match
			// still appears live already sees situation === 'finished'), so
			// the separate 'details' backlog step now only has to catch
			// matches that finished without ever passing through here (tick
			// disabled, cron down, etc.).
			$this->sync_match_detail( $stat_id );

			$this->purge_match_cache( $wp_id, '1' === $status_id );
			$updated++;
		}

		if ( $updated ) {
			$this->log( "LIVE updated={$updated}" );
		}
		return $updated;
	}

	/**
	 * Knockout matches must carry SEQUENTIAL match_round numbers continuing
	 * after the group rounds (legacy convention, e.g. Mundial 2022: groups
	 * 1–3, Oitavos 4, Quartos 5, Meias 6, Terceiro 7, Final 8) — the
	 * calendário page sections/paginates by round, so identical rounds
	 * collapse every knockout stage into one section. The API sends no round
	 * for knockout matches, so derive it: max group round + elimination
	 * phases in their `order`. Returns rows updated.
	 */
	public function repair_knockout_rounds( int $comp_pub ): int {
		global $wpdb;
		$px = $wpdb->prefix;

		$group_max = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(m.match_round) FROM {$px}competition_match m
			   JOIN {$px}competition_competitionphase ph ON ph.id = m.competition_phase_id AND ph.phase_type <> 'elimination'
			  WHERE m.competition_id = %d",
			$comp_pub
		) );

		$phases = $wpdb->get_results( $wpdb->prepare(
			"SELECT id FROM {$px}competition_competitionphase
			  WHERE competition_id = %d AND phase_type = 'elimination' ORDER BY `order` ASC",
			$comp_pub
		) );
		if ( ! $phases ) {
			return 0;
		}

		$round   = max( 0, $group_max );
		$updated = 0;
		foreach ( $phases as $ph ) {
			$round++;
			$updated += (int) $wpdb->query( $wpdb->prepare(
				"UPDATE {$px}competition_match SET match_round = %d
				  WHERE competition_id = %d AND competition_phase_id = %d AND ( match_round IS NULL OR match_round <> %d )",
				$round, $comp_pub, (int) $ph->id, $round
			) );
		}
		if ( $updated ) {
			$this->log( "ROUNDS repaired {$updated} knockout match(es) in competition {$comp_pub} (sequence continues after group round {$group_max})" );
			if ( class_exists( 'F365_Cache_Store' ) ) {
				F365_Cache_Store::purge_prefix( 'competicao' );
			}
		}
		return $updated;
	}

	/**
	 * Close finished seasons: the API has NO winner/closed concept (verified —
	 * season payload carries stages/participants/points only), so the Winners
	 * page columns are DERIVED from the Final: winner by penalties, else
	 * extra time, else regulation. Sets first/second_place_teamstep_id +
	 * is_closed='t' (fill-gap: an already-closed competition is untouched).
	 * Runs in every sync; returns how many competitions were closed.
	 *
	 * Bug fixed 2026-08-06: was filtered to `p.api_source = 'statorium'`,
	 * which nearly disabled this entirely — a season resolved by name-match
	 * into a pre-existing LEGACY competition post (find_legacy_competition(),
	 * the common case) never gets api_source/api_id set on that post itself,
	 * even though its matches are genuinely Statorium-synced (confirmed: only
	 * 11 of 12,125 linked f365_competition posts carried api_source=
	 * 'statorium'; the sync log had never once recorded a "FINALIZE
	 * competition" line, only its round-robin sibling below). This is the
	 * exact phenomenon finalize_league_competitions() already documents and
	 * works around for itself — that fix just never made it here, so no
	 * knockout competition's winner was ever recorded and Team Palmarés
	 * (INNER JOINs on first_place_teamstep_id) stayed empty for every team,
	 * created teams most visibly since a created team's only possible wins
	 * are in Statorium-era competitions. The real safety gates remain
	 * is_closed / first_place_teamstep_id below plus requiring a genuinely
	 * finished 'Final' phase match — any published f365_competition post is
	 * eligible, matching the sibling method's own reasoning.
	 */
	public function finalize_competitions(): int {
		global $wpdb;
		$px    = $wpdb->prefix;
		$comps = $wpdb->get_col(
			"SELECT DISTINCT c.id FROM {$wpdb->posts} p
			   JOIN {$px}competition_competition c ON c.id = p.legacy_id
			  WHERE p.post_type = 'f365_competition' AND p.post_status = 'publish'
			    AND ( c.is_closed IS NULL OR c.is_closed = 'f' )
			    AND ( c.first_place_teamstep_id IS NULL OR c.first_place_teamstep_id = 0 )"
		);

		$closed = 0;
		foreach ( $comps as $comp_pub ) {
			$final = $wpdb->get_row( $wpdb->prepare(
				"SELECT m.home_team_id, m.visitor_team_id, m.home_score, m.visitor_score,
				        m.home_score_extra, m.visitor_score_extra, m.penalty_home_score, m.penalty_visitor_score
				   FROM {$px}competition_match m
				   JOIN {$px}competition_competitionphase ph ON ph.id = m.competition_phase_id AND ph.name = 'Final'
				  WHERE m.competition_id = %d AND m.situation = 'finished'
				  ORDER BY m.date_time DESC LIMIT 1",
				(int) $comp_pub
			) );
			if ( ! $final || null === $final->home_score || '' === (string) $final->home_score ) {
				continue; // no finished Final yet — league seasons close via their own final round later
			}

			$h = (int) $final->home_score;
			$v = (int) $final->visitor_score;
			if ( null !== $final->penalty_home_score && '' !== (string) $final->penalty_home_score ) {
				$h = (int) $final->penalty_home_score;
				$v = (int) $final->penalty_visitor_score;
			} elseif ( null !== $final->home_score_extra && '' !== (string) $final->home_score_extra ) {
				$h = (int) $final->home_score_extra;
				$v = (int) $final->visitor_score_extra;
			}
			if ( $h === $v ) {
				continue; // cannot decide a winner from the data — leave open
			}

			$first  = $h > $v ? (int) $final->home_team_id : (int) $final->visitor_team_id;
			$second = $h > $v ? (int) $final->visitor_team_id : (int) $final->home_team_id;

			$wpdb->query( $wpdb->prepare(
				"UPDATE {$px}competition_competition
				    SET first_place_teamstep_id = %d, second_place_teamstep_id = %d, is_closed = 't'
				  WHERE id = %d AND ( first_place_teamstep_id IS NULL OR first_place_teamstep_id = 0 )",
				$first, $second, (int) $comp_pub
			) );
			$this->log( "FINALIZE competition {$comp_pub}: 1st teamstep {$first}, 2nd {$second}, is_closed=t (derived from the Final)" );
			$closed++;
		}

		if ( $closed && class_exists( 'F365_Cache_Store' ) ) {
			F365_Cache_Store::purge_prefix( 'competicao' );
		}
		return $closed;
	}

	/**
	 * Close finished LEAGUE seasons — the counterpart finalize_competitions()
	 * doesn't cover. That method only closes competitions with a finished
	 * "Final" phase match (knockout tournaments); a round-robin league like
	 * Serie A has no Final, so it was never closed and the Winners page
	 * stayed empty even for a fully-played, fully-finished season. There is
	 * no stored standings table anywhere in the mirror schema — the
	 * Classificação page computes it live from competition_match rows every
	 * request (points/goal-difference/head-to-head, per-competition
	 * victory_points/draw_points, classificationoffset adjustments, and an
	 * optional competitionclassificationorder tiebreak sequence) — so this
	 * replicates that same algorithm (f365_cls_calculate_table() in
	 * f365-competition-classificacao.php) rather than inventing a simpler
	 * one, specifically so the derived winner always agrees with whatever
	 * position 1 the Classificação table itself would show. Only acts once
	 * every match in the season is 'finished' (no scheduled/pending ones
	 * left) and the competition has no 'Final' phase at all — the presence
	 * of a Final is what routes a competition through finalize_competitions()
	 * instead, so a competition is only ever eligible for exactly one of the
	 * two paths.
	 */
	public function finalize_league_competitions(): int {
		global $wpdb;
		$px    = $wpdb->prefix;
		// Unlike finalize_competitions(), deliberately NOT filtered to
		// p.api_source = 'statorium' — a season resolved by name-match into
		// a pre-existing legacy competition post (find_legacy_competition())
		// never gets api_source/api_id set on that post itself, even though
		// its matches are genuinely being synced from Statorium (Serie A
		// 2025-26 is exactly this case: matches fully synced, WP post still
		// api_source=NULL). Any published f365_competition post is enough —
		// the real safety gates are is_closed/first_place_teamstep_id below.
		$comps = $wpdb->get_results(
			"SELECT DISTINCT c.id, c.victory_points, c.draw_points FROM {$wpdb->posts} p
			   JOIN {$px}competition_competition c ON c.id = p.legacy_id
			  WHERE p.post_type = 'f365_competition' AND p.post_status = 'publish'
			    AND ( c.is_closed IS NULL OR c.is_closed = 'f' )
			    AND ( c.first_place_teamstep_id IS NULL OR c.first_place_teamstep_id = 0 )
			    AND NOT EXISTS ( SELECT 1 FROM {$px}competition_competitionphase ph WHERE ph.competition_id = c.id AND ph.name = 'Final' )
			    AND EXISTS ( SELECT 1 FROM {$px}competition_match m WHERE m.competition_id = c.id )
			    AND NOT EXISTS ( SELECT 1 FROM {$px}competition_match m WHERE m.competition_id = c.id AND ( m.situation IS NULL OR m.situation != 'finished' ) )"
		);

		$closed = 0;
		foreach ( $comps as $c ) {
			$table = $this->compute_league_table(
				(int) $c->id,
				(int) ( $c->victory_points ?: 3 ),
				(int) ( $c->draw_points ?: 1 )
			);
			if ( count( $table ) < 2 ) {
				continue; // not enough participants to declare 1st/2nd
			}

			$first  = (int) $table[0]['teamstep_id'];
			$second = (int) $table[1]['teamstep_id'];

			$wpdb->query( $wpdb->prepare(
				"UPDATE {$px}competition_competition
				    SET first_place_teamstep_id = %d, second_place_teamstep_id = %d, is_closed = 't'
				  WHERE id = %d AND ( first_place_teamstep_id IS NULL OR first_place_teamstep_id = 0 )",
				$first, $second, (int) $c->id
			) );
			$this->log( "FINALIZE league competition {$c->id}: 1st teamstep {$first}, 2nd {$second}, is_closed=t (derived from final standings table)" );
			$closed++;
		}

		if ( $closed && class_exists( 'F365_Cache_Store' ) ) {
			F365_Cache_Store::purge_prefix( 'competicao' );
		}
		return $closed;
	}

	/** Final table for one league competition, sorted 1st→last. Mirrors
	 * f365_cls_calculate_table()'s points/tiebreak logic (no phase/group
	 * scoping — a whole-season table, matching what finalize_league_
	 * competitions() needs).
	 */
	private function compute_league_table( int $competition_id, int $victory_points, int $draw_points ): array {
		global $wpdb;
		$px = $wpdb->prefix;

		$matches = $wpdb->get_results( $wpdb->prepare(
			"SELECT home_team_id, visitor_team_id, home_score, visitor_score
			   FROM {$px}competition_match
			  WHERE competition_id = %d AND home_score IS NOT NULL AND visitor_score IS NOT NULL",
			$competition_id
		), ARRAY_A );

		$rows = [];
		foreach ( $matches as $m ) {
			$home    = (int) $m['home_team_id'];
			$visitor = (int) $m['visitor_team_id'];
			if ( ! $home || ! $visitor ) {
				continue;
			}
			$hs = (int) $m['home_score'];
			$vs = (int) $m['visitor_score'];

			foreach ( [ $home, $visitor ] as $tid ) {
				if ( ! isset( $rows[ $tid ] ) ) {
					$rows[ $tid ] = [ 'teamstep_id' => $tid, 'V' => 0, 'GM' => 0, 'GS' => 0, 'DG' => 0, 'P' => 0 ];
				}
			}
			$rows[ $home ]['GM']    += $hs;
			$rows[ $home ]['GS']    += $vs;
			$rows[ $visitor ]['GM'] += $vs;
			$rows[ $visitor ]['GS'] += $hs;

			if ( $hs > $vs ) {
				$rows[ $home ]['V']++;
				$rows[ $home ]['P'] += $victory_points;
			} elseif ( $hs < $vs ) {
				$rows[ $visitor ]['V']++;
				$rows[ $visitor ]['P'] += $victory_points;
			} else {
				$rows[ $home ]['P']    += $draw_points;
				$rows[ $visitor ]['P'] += $draw_points;
			}
		}

		// Point deductions/bonuses — same table the Classificação page applies.
		$offsets = $wpdb->get_results( $wpdb->prepare(
			"SELECT team_id, `offset` FROM {$px}competition_classificationoffset WHERE competition_id = %d",
			$competition_id
		), ARRAY_A );
		foreach ( $offsets as $o ) {
			$tid = (int) $o['team_id'];
			if ( isset( $rows[ $tid ] ) ) {
				$rows[ $tid ]['P'] += (int) $o['offset'];
			}
		}

		foreach ( $rows as &$r ) {
			$r['DG'] = $r['GM'] - $r['GS'];
		}
		unset( $r );

		$orders = $wpdb->get_col( $wpdb->prepare(
			"SELECT order_method FROM {$px}competition_competitionclassificationorder WHERE competition_id = %d ORDER BY `order` ASC, id ASC",
			$competition_id
		) );
		if ( ! $orders ) {
			$orders = [ 'goal_difference', 'victories', 'scored_goals' ];
		}

		$rows = array_values( $rows );
		usort( $rows, function ( $a, $b ) use ( $orders, $matches ) {
			if ( $a['P'] !== $b['P'] ) {
				return $b['P'] <=> $a['P'];
			}
			foreach ( $orders as $method ) {
				$cmp = $this->compare_league_rows( $a, $b, $method, $matches );
				if ( 0 !== $cmp ) {
					return $cmp;
				}
			}
			// True tie even after every configured tiebreak (points, GD,
			// wins, goals scored, head-to-head) — deliberately not chasing
			// the legacy fixture-order fallback the Classificação page uses
			// for this last-resort case; a real season reaching an exact tie
			// this deep at the very top of the table is not a case worth
			// the extra complexity to replicate byte-for-byte.
			return $a['teamstep_id'] <=> $b['teamstep_id'];
		} );

		return $rows;
	}

	/** One tiebreak comparison, matching f365_cls_compare_rows()'s method set. */
	private function compare_league_rows( array $a, array $b, string $method, array $matches ): int {
		switch ( $method ) {
			case 'points_between':
				$av = $this->league_head_to_head( (int) $a['teamstep_id'], (int) $b['teamstep_id'], $matches, 'points' );
				$bv = $this->league_head_to_head( (int) $b['teamstep_id'], (int) $a['teamstep_id'], $matches, 'points' );
				break;
			case 'goal_difference_between':
				$av = $this->league_head_to_head( (int) $a['teamstep_id'], (int) $b['teamstep_id'], $matches, 'goal_difference' );
				$bv = $this->league_head_to_head( (int) $b['teamstep_id'], (int) $a['teamstep_id'], $matches, 'goal_difference' );
				break;
			case 'visitor_goals_between':
				$av = $this->league_head_to_head( (int) $a['teamstep_id'], (int) $b['teamstep_id'], $matches, 'visitor_goals' );
				$bv = $this->league_head_to_head( (int) $b['teamstep_id'], (int) $a['teamstep_id'], $matches, 'visitor_goals' );
				break;
			case 'victories':
				$av = (int) $a['V'];
				$bv = (int) $b['V'];
				break;
			case 'scored_goals':
				$av = (int) $a['GM'];
				$bv = (int) $b['GM'];
				break;
			case 'goal_difference':
			default:
				$av = (int) $a['DG'];
				$bv = (int) $b['DG'];
				break;
		}
		return $av === $bv ? 0 : $bv <=> $av;
	}

	/** Head-to-head aggregate between two teamsteps, matching f365_cls_head_to_head(). */
	private function league_head_to_head( int $teamstep_id, int $opponent_id, array $matches, string $metric ): int {
		$value = 0;
		foreach ( $matches as $m ) {
			$home       = (int) $m['home_team_id'];
			$visitor    = (int) $m['visitor_team_id'];
			$is_home    = $home === $teamstep_id && $visitor === $opponent_id;
			$is_visitor = $visitor === $teamstep_id && $home === $opponent_id;
			if ( ! $is_home && ! $is_visitor ) {
				continue;
			}
			$for     = $is_home ? (int) $m['home_score'] : (int) $m['visitor_score'];
			$against = $is_home ? (int) $m['visitor_score'] : (int) $m['home_score'];
			if ( 'points' === $metric ) {
				$value += $for > $against ? 3 : ( $for === $against ? 1 : 0 );
			} elseif ( 'goal_difference' === $metric ) {
				$value += $for - $against;
			} elseif ( 'visitor_goals' === $metric && $is_visitor ) {
				$value += $for;
			}
		}
		return $value;
	}

	/**
	 * Auto-compute top-scorer award rankings (/rank-jogadores/) at season
	 * close. Built for "Portugal: Melhor Marcador" (stale since 2022 —
	 * editorial upkeep stopped); the filter maps ranking-type longnames to
	 * league config keys so other leagues' top-scorer awards can join later.
	 * Like the Winners page, the ranking is DERIVED — Statorium has no
	 * awards concept: one position-1 row per completed edition, goals as
	 * points, ties all inserted at position 1. Editorial rows always win —
	 * an edition already present is never touched.
	 *
	 * Correctness gates (a wrong number on an award page is worse than a
	 * stale one — skip loudly, never guess):
	 *  - full calendar imported: matches >= n*(n-1) over the participants
	 *  - nothing pending: every match finished/abrogated, none 'delayed' or
	 *    scheduled, last kickoff 7+ days in the past
	 *  - event data complete: SUM(player goals)+SUM(own goals) must equal
	 *    the scoreboard total. Legacy pre-Statorium seasons fail this (the
	 *    migrated 2024-25 events count Gyökeres 27 vs the real 39) and stay
	 *    editorial; importing the historical season + details from the API
	 *    (`all --season=X` then `details`) heals them into eligibility.
	 *
	 * Returns the number of ranking rows inserted.
	 */
	public function finalize_topscorer_rankings(): int {
		global $wpdb;
		$px = $wpdb->prefix;

		$type_table = $px . 'ranking_playerrankingtype';
		$rank_table = $px . 'ranking_playerranking';
		if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$rank_table}'" ) || ! function_exists( 'f365_statorium_league_map' ) ) {
			return 0;
		}

		$awards   = apply_filters( 'f365_statorium_topscorer_rankings', [
			'Portugal: Melhor Marcador' => 'primeira-liga',
		] );
		$config   = function_exists( 'f365_statorium_league_config' ) ? f365_statorium_league_config() : [];
		$inserted = 0;

		foreach ( $awards as $longname => $config_key ) {
			$type_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$type_table} WHERE longname = %s LIMIT 1", $longname
			) );
			if ( ! $type_id || empty( $config[ $config_key ] ) ) {
				continue;
			}

			// The league's competitions live under two possible fedcomp ids:
			// the legacy one (all sponsor-name editions share it — resolved
			// through the config's legacy_name) and ours (OFFSET + league id,
			// used when no legacy skeleton existed to link to).
			$fedcomps = [];
			$legacy_fedcomp = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT federation_competition_id FROM {$px}competition_competition
				  WHERE name = %s AND id < %d AND federation_competition_id IS NOT NULL
				  ORDER BY id DESC LIMIT 1",
				(string) $config[ $config_key ]['legacy_name'], self::ID_OFFSET
			) );
			if ( $legacy_fedcomp ) {
				$fedcomps[] = $legacy_fedcomp;
			}
			foreach ( f365_statorium_league_map() as $league_id => $entry ) {
				if ( ( $entry['config_key'] ?? '' ) === $config_key ) {
					$fedcomps[] = self::pub_id( (int) $league_id );
				}
			}
			if ( ! $fedcomps ) {
				continue;
			}

			// Only editions NEWER than the ranking's latest row are candidates:
			// the automation appends going forward, everything at or before the
			// editorial frontier stays editorial (and permanently-ineligible
			// ancient seasons would otherwise log a skip every sync). Healing a
			// recent gap (2023/2024: import the historical season + details)
			// works as long as it lands before a newer edition does.
			$latest = (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT MAX(edition) FROM {$rank_table} WHERE ranking_type_id = %d AND edition REGEXP '^[0-9]{4}$'",
				$type_id
			) );

			$fed_in = implode( ',', array_map( 'intval', array_unique( $fedcomps ) ) );
			$comps  = $wpdb->get_results( $wpdb->prepare(
				"SELECT c.id, c.edition, c.season FROM {$px}competition_competition c
				  WHERE c.federation_competition_id IN ({$fed_in})
				    AND c.edition REGEXP '^[0-9]{4}$'
				    AND ( %s = '' OR c.edition + 0 > %d )
				    AND NOT EXISTS ( SELECT 1 FROM {$rank_table} r
				                      WHERE r.ranking_type_id = %d AND r.edition = c.edition )",
				$latest, (int) $latest, $type_id
			) );

			foreach ( $comps as $c ) {
				$cid = (int) $c->id;

				$m = $wpdb->get_row( $wpdb->prepare(
					"SELECT COUNT(*) AS total,
					        SUM( situation = 'finished' ) AS finished,
					        SUM( situation NOT IN ('finished','abrogated') OR situation IS NULL ) AS pending,
					        MAX( date_time ) AS last_dt,
					        SUM( CASE WHEN situation = 'finished' THEN home_score + visitor_score ELSE 0 END ) AS score_goals
					   FROM {$px}competition_match WHERE competition_id = %d", $cid
				) );
				$participants = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$px}competition_participant WHERE competition_id = %d", $cid
				) );

				if ( ! $m || ! (int) $m->finished || (int) $m->pending
					|| $participants < 2 || (int) $m->total < $participants * ( $participants - 1 )
					|| strtotime( (string) $m->last_dt ) > time() - 7 * DAY_IN_SECONDS ) {
					continue; // season not complete (or still in progress) — no log, this is the normal state
				}

				$ev = $wpdb->get_row( $wpdb->prepare(
					"SELECT COALESCE(SUM(mp.goals),0) + COALESCE(SUM(mp.own_goals),0) AS event_goals
					   FROM {$px}competition_matchplayer mp
					   JOIN {$px}competition_match m ON m.id = mp.match_id
					  WHERE m.competition_id = %d", $cid
				) );
				if ( ! $ev || (int) $ev->event_goals !== (int) $m->score_goals ) {
					$this->log( "RANKING skip \"{$longname}\" edition={$c->edition}: event goals " . (int) ( $ev->event_goals ?? 0 ) . " != scoreboard {$m->score_goals} (comp {$cid}) — incomplete event data, left editorial" );
					continue;
				}

				$scorers = $wpdb->get_results( $wpdb->prepare(
					"SELECT mp.player_id, SUM(mp.goals) AS goals
					   FROM {$px}competition_matchplayer mp
					   JOIN {$px}competition_match m ON m.id = mp.match_id
					  WHERE m.competition_id = %d
					  GROUP BY mp.player_id HAVING goals > 0
					  ORDER BY goals DESC LIMIT 5", $cid
				) );
				if ( ! $scorers ) {
					continue;
				}
				$top     = (int) $scorers[0]->goals;
				$winners = array_filter( $scorers, fn( $s ) => (int) $s->goals === $top );

				// Ranking rows use the full "2024-2025" season form; created
				// competitions carry "2026-27" — derive from the edition.
				$season = preg_match( '/^\d{4}-\d{4}$/', (string) $c->season )
					? (string) $c->season
					: $c->edition . '-' . ( (int) $c->edition + 1 );

				foreach ( $winners as $w ) {
					if ( $this->dry_run ) {
						$inserted++;
						continue;
					}
					$wpdb->query( $wpdb->prepare(
						"INSERT INTO {$rank_table} (edition, position, points, player_id, season, ranking_type_id)
						 VALUES (%s, 1, %d, %d, %s, %d)",
						(string) $c->edition, $top, (int) $w->player_id, $season, $type_id
					) );
					$inserted++;
					$this->log( "RANKING \"{$longname}\" edition={$c->edition}: player {$w->player_id} with {$top} goals (comp {$cid}, derived at season close)" );
				}
			}
		}

		if ( $inserted && ! $this->dry_run && class_exists( 'F365_Cache_Store' ) ) {
			F365_Cache_Store::purge_prefix( 'rank-jogadores' );
		}
		return $inserted;
	}

	/**
	 * Enrich a sparse player from /players/{id}/ (showstat) — needed for
	 * transfers-feed creations, whose payload carries only name + photo (no
	 * country, no birthdate → zero-date display, no age, no flag). Fills
	 * post fields, position + country terms, photo and the person mirror
	 * rows. Fill-gaps as always. Returns true when the profile was found.
	 */
	public function enrich_player_profile( int $wp_id ): bool {
		$stat_id = self::api_id_of( $wp_id );
		if ( ! $stat_id ) {
			return false;
		}
		// Throttle marker: players whose API profile genuinely lacks a DOB
		// would otherwise be re-fetched every heal cycle forever.
		update_post_meta( $wp_id, '_statorium_profile_checked', time() );
		$data = $this->api->get( "/players/{$stat_id}/", [] );
		if ( is_wp_error( $data ) || empty( $data['player'] ) ) {
			return false;
		}
		$pl   = (array) $data['player'];
		$info = (array) ( $pl['additionalInfo'] ?? [] );

		$birthdate = $this->parse_birthdate( (string) ( $info['birthdate'] ?? '' ) );
		$legacy    = $this->is_legacy( $wp_id );

		$this->write_fields( $wp_id, [
			'firstname'     => (string) ( $pl['firstName'] ?? '' ),
			'lastname'      => (string) ( $pl['lastName'] ?? '' ),
			'shortname'     => (string) ( $pl['shortName'] ?? '' ),
			'birthdate'     => $birthdate,
			'height'        => (string) ( $info['height'] ?? '' ),
			'weight'        => (string) ( $info['weight'] ?? '' ),
			'player_number' => (string) ( $pl['playerNumber'] ?? '' ),
		], $legacy );

		if ( ! empty( $info['position'] ) ) {
			$this->set_term_guarded( $wp_id, $this->map_position( (string) $info['position'] ), 'f365_position', $legacy );
		}

		$country_en = (string) ( $pl['country']['name'] ?? '' );
		if ( '' !== $country_en ) {
			$term = self::country_term_by_stat_id( (int) ( $pl['country']['id'] ?? 0 ) );
			if ( $term ) {
				wp_set_object_terms( $wp_id, [ (int) $term ], 'f365_country', false );
			} else {
				$this->set_term_guarded( $wp_id, self::country_pt( $country_en ), 'f365_country', $legacy );
			}
		}
		if ( ! empty( $pl['photo'] ) ) {
			$this->set_thumbnail_guarded( $wp_id, (string) $pl['photo'], get_the_title( $wp_id ), $legacy );
		}

		// Person mirror rows: gap-fills empty/zero-date values.
		$pub = (int) get_post_meta( $wp_id, 'django_player_id', true );
		if ( $pub && ! $this->dry_run ) {
			$name = (string) ( $pl['fullName'] ?? get_the_title( $wp_id ) );
			$this->tables()->sync_person_rows(
				$pub, $name, (string) ( $pl['shortName'] ?? $name ),
				(string) ( $info['position'] ?? '' ), $birthdate,
				(string) ( $info['height'] ?? '' ), (string) ( $info['weight'] ?? '' ),
				'' !== $country_en ? $this->tables()->country_id_by_name( self::country_pt( $country_en ) ) : 0
			);
		}
		$this->log( "PENRICH player wp_id={$wp_id} stat={$stat_id}: birthdate=" . ( $birthdate ?: '—' ) . " country=" . ( $country_en ?: '—' ) );
		return true;
	}

	/**
	 * Per-sync heal: created clubs (transfers feed) whose profile was never
	 * enriched — /teams/{id}/ works WITHOUT a season and returns city +
	 * home venue, which fill the team info tab and link the stadium.
	 */
	public function heal_team_profiles(): int {
		global $wpdb;
		// MIRROR-driven selection (not postmeta) — same class of bug already
		// fixed for players: a team that got `city` filled by an earlier
		// (older or partial) run is otherwise permanently skipped even when
		// its stadium was never resolved, because "has city" was being used
		// as the sole "already enriched" signal. Also covers LINKED legacy
		// clubs, not just created ones — a legacy club with no mirror
		// stadium_id benefits from enrichment exactly the same way.
		// No batch cap — the API has no rate limit worth throttling for, so
		// every pending team is processed in this one call.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			   JOIN {$wpdb->prefix}team_team t ON t.id = p.legacy_id
			  WHERE p.post_type = 'f365_team' AND p.post_status = 'publish'
			    AND p.api_source = 'statorium' AND p.api_id IS NOT NULL
			    AND ( t.city IS NULL OR t.city = ''
			          OR ( t.team_type = 'club' AND ( t.stadium_id IS NULL OR t.stadium_id = 0
			                                       OR t.country_id IS NULL OR t.country_id = 0
			                                       OR t.federation_id IS NULL OR t.federation_id = 0 ) ) )
			    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} ck
			                      WHERE ck.post_id = p.ID AND ck.meta_key = '_statorium_profile_checked'
			                        AND CAST( ck.meta_value AS UNSIGNED ) > %d )",
			time() - 7 * DAY_IN_SECONDS
		) );
		$done = 0;
		foreach ( $ids as $pid ) {
			$pid = (int) $pid;
			update_post_meta( $pid, '_statorium_profile_checked', time() );
			$stat = self::api_id_of( $pid );
			if ( ! $stat ) {
				continue;
			}
			$d = $this->api->get( "/teams/{$stat}/", [] );
			if ( is_wp_error( $d ) || empty( $d['team'] ) ) {
				continue;
			}
			$this->enrich_team_profile( $pid, (array) $d['team'] );
			$this->log( "TENRICH team wp_id={$pid} stat={$stat}" );
			$done++;
			usleep( 200000 );
		}
		if ( $done ) {
			$this->log( "THEAL enriched {$done} sparse team profile(s)" );
		}
		return $done;
	}

	/**
	 * Per-sync heal: created players still missing a birthdate (sparse
	 * transfers-feed creations) get their profile fetched. Bounded batch.
	 */
	public function heal_player_profiles(): int {
		global $wpdb;
		// Mirror-driven selection: any api player (created OR linked) whose
		// person mirror row lacks a birthdate OR height/weight — the mirror
		// is what the frontend reads (dados-pessoais shows person_player
		// columns, not postmeta), and filling EMPTY legacy values is within
		// the fill-gaps policy.
		// No batch cap — the API has no rate limit worth throttling for, so
		// every pending player is processed in this one call.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			   LEFT JOIN {$wpdb->prefix}person_player pl ON pl.id = p.legacy_id
			   LEFT JOIN {$wpdb->prefix}person_person pr ON pr.id = pl.person_id
			  WHERE p.post_type = 'f365_player' AND p.post_status = 'publish'
			    AND p.api_source = 'statorium' AND p.api_id IS NOT NULL
			    AND ( pr.id IS NULL OR pr.birth_date IS NULL OR CAST( pr.birth_date AS CHAR ) = '0000-00-00'
			          OR pl.height IS NULL OR pl.height = 0 OR pl.weight IS NULL OR pl.weight = 0
			          OR pr.nationality_id IS NULL OR pr.nationality_id = 0 )
			    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} ck
			                      WHERE ck.post_id = p.ID AND ck.meta_key = '_statorium_profile_checked'
			                        AND CAST( ck.meta_value AS UNSIGNED ) > %d )",
			time() - 7 * DAY_IN_SECONDS
		) );
		$done = 0;
		foreach ( $ids as $pid ) {
			if ( $this->enrich_player_profile( (int) $pid ) ) {
				$done++;
			}
			usleep( 200000 );
		}
		if ( $done ) {
			$this->log( "PHEAL enriched {$done} sparse player profile(s)" );
		}
		return $done;
	}

	/**
	 * Fill a livematch's Events / Estatísticas / Ficha lineups from data
	 * already in the mirror tables (no API calls) — used at live-row
	 * creation, by the per-sync heal sweep, and by the live-backfill CLI.
	 */
	public function fill_livegame_from_mirror( int $live_id, int $pub ): void {
		global $wpdb;
		$events = array_map(
			fn( $r ) => [ $r->event_type, (string) $r->player, (int) $r->minute, 't' === $r->is_home ],
			$wpdb->get_results( $wpdb->prepare(
				"SELECT e.event_type, e.minute, pr.shortname AS player,
				        IF( mp.team_id = m.home_team_id, 't', 'f' ) AS is_home
				   FROM {$wpdb->prefix}competition_matchplayerevent e
				   JOIN {$wpdb->prefix}competition_matchplayer mp ON mp.id = e.matchplayer_id
				   JOIN {$wpdb->prefix}competition_match m ON m.id = mp.match_id
				   LEFT JOIN {$wpdb->prefix}person_player pl ON pl.id = mp.player_id
				   LEFT JOIN {$wpdb->prefix}person_person pr ON pr.id = pl.person_id
				  WHERE mp.match_id = %d ORDER BY e.minute",
				$pub
			) )
		);
		$this->tables()->sync_livematch_events( $live_id, $events );
		$this->tables()->sync_livematch_stats( $live_id, $pub );
		$this->tables()->sync_livematch_players( $live_id, $pub );
	}

	/**
	 * Per-sync heal: live rows whose match already has detail data in the
	 * mirror but whose live Ficha is still empty (created before the detail
	 * synced, or by an older build) get filled. Bounded batch per run.
	 */
	public function heal_live_details( int $batch = 50 ): int {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT lm.id AS live_id, lm.match_id AS pub
			   FROM {$wpdb->prefix}live_livematch lm
			   JOIN {$wpdb->posts} p ON p.legacy_id = lm.match_id AND p.post_type = 'f365_match' AND p.api_source = 'statorium'
			  WHERE EXISTS ( SELECT 1 FROM {$wpdb->prefix}competition_matchplayer mp WHERE mp.match_id = lm.match_id )
			    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->prefix}live_livematchplayer lp WHERE lp.live_match_id = lm.id )
			  LIMIT %d",
			$batch
		) );
		foreach ( $rows as $r ) {
			$this->fill_livegame_from_mirror( (int) $r->live_id, (int) $r->pub );
		}
		if ( $rows ) {
			$this->log( 'LIVEHEAL filled ' . count( $rows ) . ' livematch(es) from mirror data' );
		}
		return count( $rows );
	}

	/**
	 * Ensure the /jogos-em-direto/ backing rows for a match: the
	 * wp_live_livematch header row and its f365_livegames post (URL key =
	 * live row id via the legacy_id column). Finished matches get their
	 * final score/status immediately so the live archive shows history.
	 * Returns the match PUBLIC id (0 when unresolvable).
	 */
	public function ensure_livegame( int $match_wp_id ): int {
		global $wpdb;
		if ( $this->dry_run ) {
			return 0;
		}
		$pub = (int) get_post_meta( $match_wp_id, 'django_match_id', true );
		if ( ! $pub ) {
			return 0;
		}
		$m = $wpdb->get_row( $wpdb->prepare(
			"SELECT m.competition_id, m.date_time, m.situation, m.home_score, m.visitor_score,
			        m.home_score_extra, m.visitor_score_extra, m.penalty_home_score, m.penalty_visitor_score,
			        m.home_team_display_name hn, m.visitor_team_display_name vn
			   FROM {$wpdb->prefix}competition_match m WHERE m.id = %d",
			$pub
		) );
		if ( ! $m ) {
			return 0;
		}

		$live_id = $this->tables()->ensure_livematch( $pub, (int) $m->competition_id, (string) $m->date_time, (string) $m->hn, (string) $m->vn );
		if ( ! $live_id ) {
			return 0;
		}

		if ( 'finished' === (string) $m->situation ) {
			$this->tables()->update_livematch(
				$pub, 'RF', $m->home_score, $m->visitor_score,
				(string) $m->home_score_extra, (string) $m->visitor_score_extra,
				(string) $m->penalty_home_score, (string) $m->penalty_visitor_score
			);
		}

		// Backing post — the rewrite resolves /jogos-em-direto/{live_id}/ via
		// wp_posts.legacy_id + legacy_table='wp_live_livematch'.
		$post_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'f365_livegames' AND legacy_id = %d AND legacy_table = 'wp_live_livematch' LIMIT 1",
			$live_id
		) );
		if ( ! $post_id ) {
			$post_id = wp_insert_post( [
				'post_type'      => 'f365_livegames',
				'post_status'    => 'publish',
				'post_title'     => trim( ( $m->hn ?: 'Home' ) . ' vs ' . ( $m->vn ?: 'Away' ) ),
				'post_author'    => 1,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			] );
			if ( $post_id && ! is_wp_error( $post_id ) ) {
				$wpdb->update( $wpdb->posts, [ 'legacy_id' => $live_id, 'legacy_table' => 'wp_live_livematch' ], [ 'ID' => (int) $post_id ] );
				update_post_meta( (int) $post_id, '_f365_source', 'statorium' );
				add_post_meta( (int) $post_id, '_statorium_synced_at', current_time( 'mysql' ), true );
				$this->strip_permalink_manager_uri( (int) $post_id );
				clean_post_cache( (int) $post_id );
			}
		}
		return $pub;
	}

	public function purge_match_cache( int $match_wp_id, bool $finished ): void {
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

	// ════════════════════════════════════════════════════════════════════════
	// OWNERSHIP-AWARE WRITES  (identical policy to previous build)
	// ════════════════════════════════════════════════════════════════════════

	public function is_legacy( int $wp_id ): bool {
		global $wpdb;
		$legacy_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d", $wp_id ) );
		// Created posts also carry legacy_id (the URL resolvers read that
		// column), but always in the OFFSET range — only sub-offset ids mean
		// genuine migrated data.
		if ( $legacy_id > 0 && $legacy_id < self::ID_OFFSET ) {
			return true;
		}
		return 'statorium' !== get_post_meta( $wp_id, '_f365_source', true );
	}

	/**
	 * Write wp_posts.legacy_id/legacy_table for a post WE created — the URL
	 * resolvers (team-rewrite & friends) read the column, not postmeta.
	 * Never touches rows that already have a legacy_id.
	 */
	private function set_legacy_columns( int $wp_id, int $pub, string $table ): void {
		if ( $this->dry_run || ! $pub ) {
			return;
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->posts} SET legacy_id = %d, legacy_table = %s
			  WHERE ID = %d AND ( legacy_id IS NULL OR legacy_id = 0 )",
			$pub, $table, $wp_id
		) );
		clean_post_cache( $wp_id );
	}

	private array $acf_keys_cache = [];

	/**
	 * meta name → ACF field key for a post type. Templates read via ACF
	 * (get_fields()), which only sees values whose `_name` companion meta
	 * holds the field key.
	 */
	private function acf_field_keys( string $post_type ): array {
		if ( isset( $this->acf_keys_cache[ $post_type ] ) ) {
			return $this->acf_keys_cache[ $post_type ];
		}
		$map = [];
		if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
			foreach ( (array) acf_get_field_groups( [ 'post_type' => $post_type ] ) as $group ) {
				foreach ( (array) acf_get_fields( $group ) as $field ) {
					if ( ! empty( $field['name'] ) && ! empty( $field['key'] ) ) {
						$map[ $field['name'] ] = $field['key'];
					}
				}
			}
		}
		return $this->acf_keys_cache[ $post_type ] = $map;
	}

	private function write_fields( int $wp_id, array $meta, bool $legacy ): void {
		if ( $this->dry_run ) {
			return;
		}
		$mode     = $this->mode();
		$acf_keys = $this->acf_field_keys( (string) get_post_type( $wp_id ) );

		$write = function ( string $key, $value ) use ( $wp_id, $acf_keys ): void {
			update_post_meta( $wp_id, $key, $value );
			if ( isset( $acf_keys[ $key ] ) ) {
				update_post_meta( $wp_id, '_' . $key, $acf_keys[ $key ] );
			}
		};

		foreach ( $meta as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}

			$additive = 0 === strpos( $key, 'statorium_' );

			if ( ! $legacy || 'full' === $mode || $additive ) {
				$write( $key, $value );
				continue;
			}

			$existing = get_post_meta( $wp_id, $key, true );
			if ( '' !== $existing && null !== $existing ) {
				continue;
			}

			$required = in_array( $key, self::REQUIRED_FILL_KEYS, true );
			if ( $required || 'fill-gaps' === $mode ) {
				$write( $key, $value );
				$this->log( "FILL wp_id={$wp_id} {$key}=" . ( is_scalar( $value ) ? $value : wp_json_encode( $value ) ) );
			}
		}
	}

	private function set_term_guarded( int $wp_id, string $name, string $taxonomy, bool $legacy ): void {
		if ( $this->dry_run ) {
			return;
		}
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
		if ( $this->dry_run || has_post_thumbnail( $wp_id ) ) {
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

	// ════════════════════════════════════════════════════════════════════════
	// CORE UPSERT — api columns → matcher → create
	// ════════════════════════════════════════════════════════════════════════

	private function upsert( string $type, int $stat_id, array $post_args, ?array $match_signals ): int {
		$wp_id = $this->map_resolve( $type, $stat_id );

		if ( $wp_id && get_post_status( $wp_id ) ) {
			if ( ! $this->dry_run
				&& ! empty( $post_args['post_title'] )
				&& ! $this->is_legacy( $wp_id )
				&& get_the_title( $wp_id ) !== $post_args['post_title'] ) {
				wp_update_post( [ 'ID' => $wp_id, 'post_title' => $post_args['post_title'] ] );
			}
			return $wp_id;
		}

		$post_type = self::TYPE_POST_TYPE[ $type ];

		if ( null !== $match_signals ) {
			$found = $this->matcher->find( $type, $post_type, $stat_id, $match_signals );

			if ( 'link' === $found['verdict'] ) {
				$wp_id = (int) $found['wp_id'];
				if ( ! $this->dry_run ) {
					$this->map_set( $type, $stat_id, $wp_id );
					update_post_meta( $wp_id, '_f365_statorium_linked', 1 );
					update_post_meta( $wp_id, '_f365_match_method', $found['method'] . ' (score ' . $found['score'] . ')' );
					add_post_meta( $wp_id, '_statorium_synced_at', current_time( 'mysql' ), true );
				}
				$this->log( "LINK {$type} stat_id={$stat_id} → wp_id={$wp_id} via {$found['method']} (score {$found['score']})" );
				$this->bump( 'linked' );
				return $wp_id;
			}

			if ( 'review' === $found['verdict'] ) {
				$this->bump( 'review' );
				return 0; // queued for human decision — nothing is written
			}
		}

		if ( $this->dry_run ) {
			$this->bump( 'would_create' );
			return 0;
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
		add_post_meta( (int) $wp_id, '_statorium_synced_at', current_time( 'mysql' ), true );
		update_post_meta( (int) $wp_id, F365_Statorium_Matcher::NORM_KEY, F365_Statorium_Matcher::normalize( (string) ( $post_args['post_title'] ?? '' ) ) );
		$this->map_set( $type, $stat_id, (int) $wp_id );
		$this->strip_permalink_manager_uri( (int) $wp_id );
		return (int) $wp_id;
	}

	/**
	 * Permalink Manager auto-assigns slug-based URIs (jogador/erik-lira) on
	 * insert, which 301-redirects the numeric legacy-style URLs away. Legacy
	 * posts have no PM URI — created posts must not either.
	 */
	public function strip_permalink_manager_uri( int $wp_id ): void {
		$uris = get_option( 'permalink-manager-uris', null );
		if ( is_array( $uris ) && isset( $uris[ $wp_id ] ) ) {
			unset( $uris[ $wp_id ] );
			update_option( 'permalink-manager-uris', $uris );
		}
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

	/** Statorium id of a post, from the indexed api_id column. */
	public static function api_id_of( int $wp_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT api_id FROM {$wpdb->posts} WHERE ID = %d AND api_source = %s",
			$wp_id, self::API_SOURCE
		) );
	}

	public function map_set( string $type, int $stat_id, int $wp_id ): void {
		$this->map_cache[ $type ][ (string) $stat_id ] = $wp_id;
		$this->set_api_columns( $wp_id, $stat_id );

		$decoded = json_decode( (string) get_option( "f365_stat_map_{$type}", '{}' ), true );
		$decoded = is_array( $decoded ) ? $decoded : [];
		$decoded[ (string) $stat_id ] = $wp_id;
		update_option( "f365_stat_map_{$type}", wp_json_encode( $decoded ), false );
	}

	// ════════════════════════════════════════════════════════════════════════
	// AUDIT / ROLLBACK (unchanged behavior)
	// ════════════════════════════════════════════════════════════════════════

	/**
	 * Unstamp legacy posts wrongly marked `_f365_source=statorium` by early
	 * builds. Legacy detection: legacy_id > 0 OR the post predates the
	 * integration's first run (posts we create are never older than that).
	 * Returns corrected count.
	 */
	public function repair_misclassified_links(): int {
		global $wpdb;
		$first_run = apply_filters( 'f365_statorium_first_run_date', '2026-07-17' );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_f365_source' AND s.meta_value = 'statorium'
			  WHERE p.api_source = %s
			    AND ( ( p.legacy_id > 0 AND p.legacy_id < %d ) OR p.post_date < %s )",
			self::API_SOURCE, self::ID_OFFSET, $first_run . ' 00:00:00'
		) );
		foreach ( $ids as $id ) {
			delete_post_meta( (int) $id, '_f365_source' );
			update_post_meta( (int) $id, '_f365_statorium_linked', 1 );
			$this->log( "REPAIR wp_id={$id} unstamped _f365_source (linked legacy post)" );
		}
		return count( $ids );
	}

	/**
	 * Restore `shortname` on linked legacy teams overwritten by early builds
	 * (the legacy migration set post_title FROM shortname, so this is exact).
	 */
	/**
	 * Repair `foundation` postmeta corrupted by the old founded-date parser
	 * (wrote "07-06-1914  (112)" — Statorium's raw string, age suffix
	 * included — instead of a clean Y-m-d for the ACF date_picker field).
	 * Affects created AND legacy posts (fill-gaps wrote it there too — the
	 * value itself is malformed, not merely present, so it must be corrected
	 * regardless of ownership). Re-parses when possible, else clears the
	 * meta (a missing date is safe; a garbled one breaks the date picker /
	 * any date-based template logic).
	 */
	/**
	 * One-off: copy already-fetched city/foundation postmeta into the mirror
	 * row for teams enriched before update_team_profile_row() existed (no
	 * API calls needed — the data is already sitting in postmeta).
	 */
	public function repair_team_mirror_profiles(): int {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT p.legacy_id AS pub,
			        MAX( CASE WHEN m.meta_key = 'city' THEN m.meta_value END ) AS city,
			        MAX( CASE WHEN m.meta_key = 'foundation' THEN m.meta_value END ) AS foundation
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ( 'city', 'foundation' ) AND m.meta_value <> ''
			  WHERE p.post_type = 'f365_team' AND p.post_status = 'publish' AND p.legacy_id > 0
			  GROUP BY p.legacy_id"
		);
		$fixed = 0;
		foreach ( $rows as $r ) {
			$this->tables()->update_team_profile_row( (int) $r->pub, (string) ( $r->city ?? '' ), (string) ( $r->foundation ?? '' ) );
			$fixed++;
		}
		if ( $fixed ) {
			$this->log( "REPAIR team mirror profiles: {$fixed} row(s) checked (fill-gap; already-correct rows untouched)" );
		}
		return $fixed;
	}

	/**
	 * One-off: create the missing wp_stadium_stadium row for any created
	 * stadium post whose venue was discovered only via a team's homeVenue
	 * (upsert_venue never inserted it before this fix — enrich_venue only
	 * UPDATEs). Uses the post's own title/postmeta so no API call is needed;
	 * a follow-up enrich_venue() call (or the next sync touching that venue)
	 * fills capacity/city/address into the now-existing row.
	 */
	/**
	 * One-off: resolve country_id/federation_id for existing api teams
	 * missing either — no API calls needed, everything is derived from data
	 * already stored (national: team name; club: its stadium's mirror
	 * address, if the stadium is linked and was already enriched).
	 */
	/**
	 * Fix created teams typed 'national' that are actually clubs — the
	 * pre-scope-derivation upsert_team default typed every club-league team
	 * as a national side (breaking the player pages' current-team join,
	 * which filters team_type='club', and the club-logo header branch). A
	 * team is flipped when it participates ONLY in club-scope competitions
	 * (never in a national-team one). Also removes the bogus self-named
	 * f365_country term such teams minted ("Gil Vicente FC"). Mirror +
	 * postmeta both corrected. Returns flipped count.
	 */
	public function repair_team_types(): int {
		global $wpdb;
		$px = $wpdb->prefix;

		$rows = $wpdb->get_results(
			"SELECT t.id AS pub, t.shortname, p.ID AS wp_id
			   FROM {$px}team_team t
			   JOIN {$wpdb->posts} p ON p.legacy_id = t.id AND p.post_type = 'f365_team' AND p.api_source = 'statorium'
			  WHERE t.id >= " . self::ID_OFFSET . " AND t.team_type = 'national'
			    AND EXISTS ( SELECT 1 FROM {$px}team_teamstep ts
			                   JOIN {$px}competition_participant cp ON cp.team_id = ts.id
			                   JOIN {$px}competition_competition c ON c.id = cp.competition_id
			                   JOIN {$px}federation_federationcompetition fc ON fc.id = c.federation_competition_id
			                  WHERE ts.team_id = t.id
			                    AND fc.scope NOT IN ('international_national_team','international_friendly_nationals') )
			    AND NOT EXISTS ( SELECT 1 FROM {$px}team_teamstep ts2
			                   JOIN {$px}competition_participant cp2 ON cp2.team_id = ts2.id
			                   JOIN {$px}competition_competition c2 ON c2.id = cp2.competition_id
			                   JOIN {$px}federation_federationcompetition fc2 ON fc2.id = c2.federation_competition_id
			                  WHERE ts2.team_id = t.id
			                    AND fc2.scope IN ('international_national_team','international_friendly_nationals') )"
		);

		$flipped = 0;
		foreach ( $rows as $r ) {
			if ( $this->dry_run ) {
				$flipped++;
				continue;
			}
			$wpdb->query( $wpdb->prepare( "UPDATE {$px}team_team SET team_type = 'club', federation_id = NULL WHERE id = %d", (int) $r->pub ) );
			update_post_meta( (int) $r->wp_id, 'team_type', 'club' );

			// The 'national' branch set f365_country = the team's own name and
			// created the term when missing. Remove the assignment; delete the
			// term when it is not a real country and nothing else uses it.
			$self_terms = get_the_terms( (int) $r->wp_id, 'f365_country' );
			if ( is_array( $self_terms ) ) {
				foreach ( $self_terms as $term ) {
					if ( F365_Statorium_Matcher::normalize( $term->name ) !== F365_Statorium_Matcher::normalize( (string) $r->shortname )
						&& F365_Statorium_Matcher::normalize( $term->name ) !== F365_Statorium_Matcher::normalize( get_the_title( (int) $r->wp_id ) ) ) {
						continue;
					}
					wp_remove_object_terms( (int) $r->wp_id, (int) $term->term_id, 'f365_country' );
					if ( ! $this->tables()->country_id_by_name( $term->name ) && 0 === (int) $term->count - 1 ) {
						wp_delete_term( (int) $term->term_id, 'f365_country' );
						$this->log( "REPAIR team-type: deleted bogus country term \"{$term->name}\"" );
					}
				}
			}
			$this->log( "REPAIR team-type: {$r->shortname} (pub {$r->pub}) national → club" );
			$flipped++;
		}
		if ( $flipped && ! $this->dry_run ) {
			$this->log( "REPAIR team types: {$flipped} created team(s) flipped to club" );
		}
		return $flipped;
	}

	public function repair_team_country_federation(): int {
		global $wpdb;

		// Pass 0 — league-derived country/federation for clubs, entirely in
		// SQL: a club participating in a domestic (national_*/regional_*)
		// competition belongs to that competition federation's country. Far
		// broader than the venue-address fallback below (which needs an
		// enriched stadium with a parseable address) and exactly what the
		// team header's federation link reads. Gap-fill only — a legacy
		// team's existing values are never overwritten.
		$px = $wpdb->prefix;
		foreach ( [
			[ 'federation_id', 'f.id' ],
			[ 'country_id', 'f.country_id' ],
		] as [ $col, $src ] ) {
			$wpdb->query(
				"UPDATE {$px}team_team t
				   JOIN {$px}team_teamstep ts ON ts.team_id = t.id
				   JOIN {$px}competition_participant cp ON cp.team_id = ts.id
				   JOIN {$px}competition_competition c ON c.id = cp.competition_id
				   JOIN {$px}federation_federationcompetition fc ON fc.id = c.federation_competition_id
				        AND ( fc.scope LIKE 'national\\_%' OR fc.scope LIKE 'regional\\_%' )
				   JOIN {$px}federation_federation f ON f.id = fc.federation_id AND f.country_id IS NOT NULL
				    SET t.{$col} = {$src}
				  WHERE t.team_type = 'club' AND ( t.{$col} IS NULL OR t.{$col} = 0 )"
			);
		}

		$rows = $wpdb->get_results(
			"SELECT p.legacy_id AS pub, p.post_title, t.team_type, t.stadium_id
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->prefix}team_team t ON t.id = p.legacy_id
			  WHERE p.post_type = 'f365_team' AND p.post_status = 'publish'
			    AND p.api_source = 'statorium' AND p.api_id IS NOT NULL
			    AND ( t.country_id IS NULL OR t.country_id = 0 OR t.federation_id IS NULL OR t.federation_id = 0 )"
		);
		$fixed = 0;
		foreach ( $rows as $r ) {
			$is_national = 'national' === $r->team_type;
			$pt_country  = '';
			if ( $is_national ) {
				$pt_country = self::country_pt( (string) $r->post_title ); // best-effort: title may already be PT
				if ( ! $this->tables()->country_id_by_name( $pt_country ) ) {
					$pt_country = (string) $r->post_title; // already PT (created via national-team naming)
				}
			} elseif ( $r->stadium_id ) {
				// Same preference as enrich_team_profile(): the stadium's own
				// already-resolved country_id (real FK) over its free-text
				// address, which is frequently unparseable (bare street, or
				// empty) even when the country_id itself is correctly set.
				$stadium = $wpdb->get_row( $wpdb->prepare(
					"SELECT address, country_id FROM {$wpdb->prefix}stadium_stadium WHERE id = %d", (int) $r->stadium_id
				) );
				if ( $stadium && $stadium->country_id ) {
					$pt_country = (string) $wpdb->get_var( $wpdb->prepare(
						"SELECT shortname FROM {$wpdb->prefix}country_country WHERE id = %d", (int) $stadium->country_id
					) );
				}
				if ( ! $pt_country && $stadium && $stadium->address ) {
					$pt_country = self::derive_country_from_address( (string) $stadium->address );
				}
			}
			if ( ! $pt_country || ! $this->tables()->country_id_by_name( $pt_country ) ) {
				continue; // nothing to resolve from — skip, never guess
			}
			$country_id    = $this->tables()->country_id_by_name( $pt_country );
			$federation_id = $this->tables()->federation_id_for_country( $pt_country, $is_national );
			if ( $country_id || $federation_id ) {
				$this->tables()->update_team_country_federation( (int) $r->pub, $country_id, $federation_id );
				$fixed++;
			}
		}
		if ( $fixed ) {
			$this->log( "REPAIR team country/federation: {$fixed} team(s) resolved" );
		}
		return $fixed;
	}

	/**
	 * Fold duplicate teamstep rows left by the varchar(4) edition truncation
	 * (see F365_Statorium_Tables::edition_year): before the fix, every match
	 * of a "2026-27"-named season created two fresh teamsteps because the
	 * reuse lookup compared the untruncated string. Exact-duplicate groups
	 * (same team/edition/step/modality/gender) collapse into their LOWEST id
	 * (the legacy/original row when one exists); every reference is
	 * repointed, participant/squad rows deduped, squads rebuilt, dupes
	 * deleted. Cheap probe first — a clean DB pays one GROUP BY per sync.
	 * Returns the number of duplicate rows removed.
	 */
	public function repair_duplicate_teamsteps(): int {
		global $wpdb;
		$px = $wpdb->prefix;

		$groups = $wpdb->get_results(
			"SELECT team_id, edition, step, modality, gender, MIN(id) AS keep_id,
			        GROUP_CONCAT(id ORDER BY id) AS ids
			   FROM {$px}team_teamstep
			  GROUP BY team_id, edition, step, modality, gender
			 HAVING COUNT(*) > 1"
		);
		if ( ! $groups ) {
			return 0;
		}

		$removed        = 0;
		$affected_comps = [];

		foreach ( $groups as $g ) {
			$keep  = (int) $g->keep_id;
			$dupes = array_values( array_diff( array_map( 'intval', explode( ',', (string) $g->ids ) ), [ $keep ] ) );
			if ( ! $dupes ) {
				continue;
			}
			// Never fold a duplicate that carries its own data (coach,
			// stadium) — that would be a semantic merge, not a dedupe.
			$in    = implode( ',', $dupes );
			$dirty = $wpdb->get_var(
				"SELECT COUNT(*) FROM {$px}team_teamstep
				  WHERE id IN ({$in}) AND ( ( tmp_coach IS NOT NULL AND tmp_coach <> '' ) OR ( stadium_id IS NOT NULL AND stadium_id <> 0 ) )"
			);
			if ( $dirty ) {
				$this->log( "TEAMSTEP DEDUP skipped team={$g->team_id} edition={$g->edition}: duplicate row carries coach/stadium data — merge manually" );
				continue;
			}

			if ( $this->dry_run ) {
				$removed += count( $dupes );
				continue;
			}

			foreach ( $wpdb->get_col( "SELECT DISTINCT competition_id FROM {$px}competition_match WHERE home_team_id IN ({$in}) OR visitor_team_id IN ({$in})" ) as $cid ) {
				$affected_comps[ (int) $cid ] = true;
			}

			$wpdb->query( $wpdb->prepare( "UPDATE {$px}competition_match SET home_team_id = %d WHERE home_team_id IN ({$in})", $keep ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$px}competition_match SET visitor_team_id = %d WHERE visitor_team_id IN ({$in})", $keep ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$px}competition_matchplayer SET team_id = %d WHERE team_id IN ({$in})", $keep ) );
			$wpdb->query( $wpdb->prepare( "UPDATE IGNORE {$px}transfer_transfer SET team_origin_id = %d WHERE team_origin_id IN ({$in})", $keep ) );
			$wpdb->query( $wpdb->prepare( "UPDATE IGNORE {$px}transfer_transfer SET team_destination_id = %d WHERE team_destination_id IN ({$in})", $keep ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$px}competition_competition SET first_place_teamstep_id = %d WHERE first_place_teamstep_id IN ({$in})", $keep ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$px}competition_competition SET second_place_teamstep_id = %d WHERE second_place_teamstep_id IN ({$in})", $keep ) );

			// Participants: repoint, then drop the duplicate (comp, team) rows.
			$wpdb->query( $wpdb->prepare( "UPDATE {$px}competition_participant SET team_id = %d WHERE team_id IN ({$in})", $keep ) );
			$wpdb->query( $wpdb->prepare(
				"DELETE p1 FROM {$px}competition_participant p1
				   JOIN {$px}competition_participant p2
				     ON p2.competition_id = p1.competition_id AND p2.team_id = p1.team_id AND p2.id < p1.id
				  WHERE p1.team_id = %d", $keep
			) );

			// Squads: repoint, fold duplicate (player, team) rows into the
			// BEST one — order=0 preferred over any other order value,
			// lowest id as the tiebreak within the same order. NOT simply
			// "oldest": the Plantel/squad page (f365-team-plantel.php)
			// only ever displays order=0 rows for a club, so picking
			// purely by lowest id — the previous behaviour — could keep an
			// older order=1 (non-primary/historical) row over a newer
			// order=0 one and silently drop a real, current player from
			// their own team's squad list. squadcompetition rows follow
			// the survivor; rebuild_squads() recomputes per-competition
			// games/goals afterwards regardless of which id survived.
			$wpdb->query( $wpdb->prepare( "UPDATE {$px}team_squad SET team_id = %d WHERE team_id IN ({$in})", $keep ) );
			$groups = $wpdb->get_results( $wpdb->prepare(
				"SELECT player_id, GROUP_CONCAT( id ORDER BY `order` ASC, id ASC ) AS ids
				   FROM {$px}team_squad WHERE team_id = %d GROUP BY player_id HAVING COUNT(*) > 1",
				$keep
			) );
			foreach ( $groups as $g ) {
				$ids     = array_map( 'intval', explode( ',', (string) $g->ids ) );
				$keep_sq = array_shift( $ids ); // order=0 (or lowest order present) wins; id breaks ties
				foreach ( $ids as $dupe ) {
					$wpdb->query( $wpdb->prepare( "UPDATE {$px}team_squadcompetition SET squad_id = %d WHERE squad_id = %d", $keep_sq, $dupe ) );
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$px}team_squad WHERE id = %d", $dupe ) );
				}
			}
			$wpdb->query( $wpdb->prepare(
				"DELETE sc1 FROM {$px}team_squadcompetition sc1
				   JOIN {$px}team_squadcompetition sc2
				     ON sc2.squad_id = sc1.squad_id AND sc2.competition_id = sc1.competition_id AND sc2.id < sc1.id
				   JOIN {$px}team_squad s ON s.id = sc1.squad_id
				  WHERE s.team_id = %d", $keep
			) );

			$wpdb->query( "DELETE FROM {$px}team_teamstep WHERE id IN ({$in})" );
			$removed += count( $dupes );
		}

		if ( $this->dry_run ) {
			return $removed;
		}

		// Squad editions written before the fix carry the full season name —
		// the ranking template joins team_squad.edition against 4-char
		// editions, so normalize (legacy rows are already plain years).
		$wpdb->query(
			"UPDATE {$px}team_squad SET edition = LEFT(edition, 4)
			  WHERE CHAR_LENGTH(edition) > 4 AND edition REGEXP '^[0-9]{4}'"
		);

		// Recompute per-competition squad aggregates now that the teamsteps
		// are canonical (partial per-duplicate rows were folded together).
		foreach ( array_keys( $affected_comps ) as $cid ) {
			$this->tables()->rebuild_squads( $cid );
		}

		if ( $removed ) {
			$this->log( 'TEAMSTEP DEDUP folded ' . $removed . ' duplicate teamstep row(s) across ' . count( $groups ) . ' group(s), squads rebuilt for ' . count( $affected_comps ) . ' competition(s)' );
			if ( class_exists( 'F365_Cache_Store' ) ) {
				F365_Cache_Store::purge_prefix( 'competicao' );
				F365_Cache_Store::purge_prefix( 'equipa' );
			}
		}
		return $removed;
	}

	/**
	 * Copy already-fetched player height/weight postmeta into the mirror
	 * person_player row (the dados-pessoais template reads the MIRROR).
	 * Players created through a sparse path (lineup, transfers feed) got
	 * their row inserted with 0/NULL; later enrichment fixed the postmeta
	 * but — before sync_person_rows gained its height/weight gap-fills —
	 * never the mirror. No API calls; meta like "174 cm" casts cleanly.
	 * Fill-gap: only 0/NULL mirror values are touched. Returns rows fixed.
	 */
	public function repair_player_mirror_profiles(): int {
		global $wpdb;
		$px   = $wpdb->prefix;
		$rows = $wpdb->get_results(
			"SELECT p.legacy_id AS pub,
			        MAX( CASE WHEN m.meta_key = 'height' THEN m.meta_value END ) AS height,
			        MAX( CASE WHEN m.meta_key = 'weight' THEN m.meta_value END ) AS weight
			   FROM {$wpdb->posts} p
			   JOIN {$px}person_player pl ON pl.id = p.legacy_id
			   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ('height','weight') AND m.meta_value <> ''
			  WHERE p.post_type = 'f365_player' AND p.post_status = 'publish' AND p.api_source = 'statorium'
			    AND ( pl.height IS NULL OR pl.height = 0 OR pl.weight IS NULL OR pl.weight = 0 )
			  GROUP BY p.legacy_id"
		);
		$fixed = 0;
		foreach ( $rows as $r ) {
			if ( $this->dry_run ) {
				$fixed++;
				continue;
			}
			$h = (int) $r->height;
			$w = (int) $r->weight;
			if ( $h ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$px}person_player SET height = %d WHERE id = %d AND ( height IS NULL OR height = 0 )", $h, (int) $r->pub
				) );
			}
			if ( $w ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$px}person_player SET weight = %d WHERE id = %d AND ( weight IS NULL OR weight = 0 )", $w, (int) $r->pub
				) );
			}
			$fixed++;
		}
		if ( $fixed && ! $this->dry_run ) {
			$this->log( "REPAIR player mirror profiles: {$fixed} row(s) height/weight copied from postmeta" );
		}
		return $fixed;
	}

	/**
	 * A player discovered ONLY through a match lineup gets full mirror data
	 * (person_player/person_person, via sync_match_detail()'s unconditional
	 * per-squad-entry sync_person_rows() call in class-tables.php) and
	 * competition_matchplayer references — but the player_pub callback in
	 * sync_match_detail() (this class) falls back to a bare deterministic
	 * pub (self::pub_id()) when map_resolve() finds nothing, WITHOUT ever
	 * creating the WP post. Unlike coaches (no id, no URL, a bare mirror row
	 * is the intended final state), a player has its own /jogador/{pub}/
	 * page — so this leaves real match/mirror data with nothing to render
	 * it. Same shape as heal_team_profiles()/heal_player_profiles(): re-fetch
	 * the real payload and run it through the normal upsert_player() path,
	 * which will LINK to a legacy twin if the matcher finds one or CREATE
	 * fresh — either way adopting the SAME pub already on the orphaned
	 * mirror row (pub_or_keep() computes it deterministically), so the
	 * existing mirror data is reused/gap-filled, never duplicated.
	 */
	public function repair_orphaned_player_mirrors(): int {
		global $wpdb;
		// No batch cap — the API has no rate limit worth throttling for, so
		// every orphan is processed in this one call.
		$pubs = $wpdb->get_col( $wpdb->prepare(
			"SELECT pl.id FROM {$wpdb->prefix}person_player pl
			  WHERE pl.id >= %d
			    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->posts} p WHERE p.legacy_id = pl.id AND p.post_type = 'f365_player' )",
			self::ID_OFFSET
		) );
		if ( $this->dry_run ) {
			return count( $pubs );
		}

		$fixed = 0;
		foreach ( $pubs as $pub ) {
			$pub     = (int) $pub;
			$stat_id = $pub - self::ID_OFFSET;
			if ( $stat_id <= 0 ) {
				continue;
			}
			$d = $this->api->get( "/players/{$stat_id}/", [] );
			if ( is_wp_error( $d ) || empty( $d['player'] ) ) {
				continue; // API has nothing for this id right now — retried next pass
			}
			$wp_id = $this->upsert_player( $stat_id, (array) $d['player'], 0 );
			if ( ! $wp_id ) {
				continue;
			}

			// The matcher may LINK to an existing legacy player under a
			// DIFFERENT (real, legacy) pub instead of adopting this orphan's
			// bogus deterministic one — confirmed live: a lineup-only player
			// turned out to already have a real legacy record, and
			// upsert_player() correctly linked to it. The orphaned mirror
			// row and its match/transfer references still carry the OLD
			// pub in that case and need folding, same as merge_player()'s
			// cleanup — this player was never a genuine "created" WP post
			// to delete, only a bare mirror row, so merge_player() itself
			// (which deletes a created post) doesn't apply directly.
			$real_pub = (int) get_post_meta( $wp_id, 'django_player_id', true );
			if ( $real_pub && $real_pub !== $pub ) {
				foreach ( [ 'competition_matchplayer' => 'player_id', 'team_squad' => 'player_id', 'transfer_transfer' => 'player_id' ] as $table => $col ) {
					// team_squad carries a UNIQUE KEY on (team_id, player_id,
					// edition, order) — a blind UPDATE fatals if the real
					// player already has a squad row for the same
					// team+edition+slot the orphan does. IGNORE skips just
					// that row instead of aborting the whole repair; the
					// leftover is a genuine duplicate of a row that already
					// exists for the real player, so it's deleted outright
					// below rather than left dangling on the dead pub.
					$wpdb->query( $wpdb->prepare(
						"UPDATE IGNORE {$wpdb->prefix}{$table} SET {$col} = %d WHERE {$col} = %d", $real_pub, $pub
					) );
				}
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}team_squad WHERE player_id = %d", $pub ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}person_player WHERE id = %d", $pub ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}person_person WHERE id = %d", $pub ) );
				$this->log( "REPAIR orphaned player mirror: stat={$stat_id} orphan_pub={$pub} folded into existing wp_id={$wp_id} (real pub={$real_pub})" );
			} else {
				$this->log( "REPAIR orphaned player mirror: stat={$stat_id} pub={$pub} → wp_id={$wp_id}" );
			}
			$fixed++;
			usleep( 200000 );
		}
		if ( $fixed ) {
			$this->log( "REPAIR orphaned player mirrors: {$fixed} wp_post(s) created/linked for existing mirror data" );
		}
		return $fixed;
	}

	/**
	 * Backfill wp_competition_participant.group_phase_one for rows created
	 * before ensure_participant() learned to accept a group name — every
	 * participant registered by the day-cursor backfill (todaymatches) or by
	 * any match imported before this fix landed has it blank. Derives the
	 * group from the SAME match_table columns the classification/equipas
	 * templates already fall back to (group_home_team/group_visitor_team),
	 * so this only writes values those pages would already compute anyway.
	 * Fill-gap only. Returns rows updated.
	 */
	/**
	 * Fix the team_team.shortname/longname swap left by the old sync_team_row
	 * binding: every CREATED team's `shortname` already holds the readable
	 * name Statorium sent ("FC Porto") — correct, and what nearly every
	 * frontend display reads — while `longname` wrongly held the API's bare
	 * 2-4 letter club code ("FCP"), which only the Dados Gerais "Nome:"
	 * field surfaces. Since shortname is already right, the fix is just
	 * longname := shortname. Scoped to id >= ID_OFFSET — legacy rows were
	 * never written by this path and are untouched. Returns rows fixed.
	 */
	/**
	 * Backfill team_teamstepcoach/person_coach entities from tmp_coach values
	 * already sitting on team_teamstep (written before ensure_teamstepcoach
	 * existed, or by legacy data) — no API calls. Pure fill-gap: only
	 * teamsteps with a coach NAME but no 'principal' teamstepcoach row yet
	 * are touched; an existing row (legacy or otherwise) is never replaced.
	 * Returns rows created.
	 */
	/**
	 * Backfill stadium country (f365_country term + mirror country_id) from
	 * the address string already sitting on already-enriched stadiums — no
	 * API calls. enrich_venue()'s `statorium_venue_synced` throttle means
	 * this never re-runs on its own once a stadium has been enriched, so
	 * every stadium enriched before this fix landed is missing its hero
	 * header's flag/country-name line permanently without this pass.
	 * Fill-gap only: an existing term or mirror value is never touched.
	 * City is deliberately NOT derived here — see derive_country_from_address's
	 * sibling docblock on why a from-address city guess is unreliable.
	 * Returns rows touched.
	 */
	/**
	 * Fix competition_match.competition_django_id — verified against legacy
	 * rows to mean the FEDCOMP (federation_competition_id) public id, e.g.
	 * every Primeira Liga match carries 392 regardless of edition. Every
	 * match sync_match_row() ever wrote bound this column to the match's
	 * OWN pub id instead, breaking every /competicao/{id}/ link built off
	 * it — including the stadium page's últimos/próximos jogos list. This
	 * re-derives it from the match's own competition_competition row (the
	 * one authoritative source), never guessed. Safe for legacy rows too —
	 * it was already correct there, so the `<>` check makes them a no-op.
	 * Returns rows corrected.
	 */
	public function repair_match_competition_django_id(): int {
		global $wpdb;
		$px = $wpdb->prefix;
		if ( $this->dry_run ) {
			return (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$px}competition_match m
				   JOIN {$px}competition_competition c ON c.id = m.competition_id
				  WHERE c.federation_competition_id IS NOT NULL AND c.federation_competition_id > 0
				    AND m.competition_django_id <> c.federation_competition_id"
			);
		}
		$fixed = (int) $wpdb->query(
			"UPDATE {$px}competition_match m
			   JOIN {$px}competition_competition c ON c.id = m.competition_id
			    SET m.competition_django_id = c.federation_competition_id
			  WHERE c.federation_competition_id IS NOT NULL AND c.federation_competition_id > 0
			    AND m.competition_django_id <> c.federation_competition_id"
		);
		if ( $fixed ) {
			$this->log( "REPAIR match competition_django_id: {$fixed} match(es) corrected to their fedcomp id" );
			if ( class_exists( 'F365_Cache_Store' ) ) {
				F365_Cache_Store::purge_all();
			}
		}
		return $fixed;
	}

	/**
	 * Folds a created, orphaned fedcomp (federation_federationcompetition
	 * row with id >= ID_OFFSET) into the real legacy one when
	 * find_legacy_fedcomp() can now match it — the bug import_season() used
	 * to hit for every league's first-ever Statorium-tracked season (see
	 * find_legacy_fedcomp()'s docblock: a league like Premier League/Ligue 1
	 * already has decades of legacy history under an existing fedcomp id,
	 * but a season with no matching EDITION row used to always mint a brand
	 * new, permanently parallel fedcomp instead of reusing it). An our-owned
	 * fedcomp's own id encodes the league id directly (self::pub_id
	 * (league_id) = ID_OFFSET + league_id), so no extra bookkeeping is
	 * needed to identify which league an orphan belongs to. Repoints every
	 * competition_competition edition and linked WP competition post
	 * currently under the orphan, then deletes the now-empty orphan row.
	 */
	public function repair_orphaned_fedcomps(): int {
		global $wpdb;
		$px      = $wpdb->prefix;
		$orphans = $wpdb->get_results(
			"SELECT id, shortname FROM {$px}federation_federationcompetition WHERE id >= " . self::ID_OFFSET
		);
		$fixed = 0;
		foreach ( $orphans as $o ) {
			$league_id = (int) $o->id - self::ID_OFFSET;
			if ( $league_id <= 0 ) {
				continue;
			}
			$legacy_fedcomp = $this->find_legacy_fedcomp( $league_id, (string) $o->shortname );
			if ( ! $legacy_fedcomp || $legacy_fedcomp === (int) $o->id ) {
				continue;
			}
			if ( $this->dry_run ) {
				$fixed++;
				continue;
			}

			$comp_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$px}competition_competition WHERE federation_competition_id = %d", (int) $o->id
			) );

			$wpdb->query( $wpdb->prepare(
				"UPDATE {$px}competition_competition SET federation_competition_id = %d WHERE federation_competition_id = %d",
				$legacy_fedcomp, (int) $o->id
			) );
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$px}competition_match SET competition_django_id = %d WHERE competition_django_id = %d",
				$legacy_fedcomp, (int) $o->id
			) );

			foreach ( $comp_ids as $comp_id ) {
				$wp_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'django_competition_id' AND meta_value = %s LIMIT 1",
					(string) $comp_id
				) );
				if ( $wp_id ) {
					update_post_meta( $wp_id, 'federation_competition_id', $legacy_fedcomp );
				}
			}

			$wpdb->query( $wpdb->prepare( "DELETE FROM {$px}federation_federationcompetition WHERE id = %d", (int) $o->id ) );

			$this->log( "REPAIR fedcomp: orphan {$o->id} \"{$o->shortname}\" folded into legacy fedcomp {$legacy_fedcomp} (" . count( $comp_ids ) . ' edition(s) repointed)' );
			$fixed++;
		}
		if ( $fixed && ! $this->dry_run && class_exists( 'F365_Cache_Store' ) ) {
			F365_Cache_Store::purge_all();
		}
		return $fixed;
	}

	/**
	 * The fixture-dedup fold (see repair_orphaned_match_duplicates() and
	 * pair_legacy_fixtures()) repoints a merged match's matchplayer rows to
	 * the LEGACY teamstep, but never touches the "ours" team/teamstep that
	 * lost its only match reference in the process — confirmed live: 7
	 * created teams (Slavia Praha, Wrexham AFC, Deportivo Toluca FC,
	 * Cesena FC, …) sat with zero matches, zero competition_participant
	 * rows, zero squad, zero coach links, each with a real legacy
	 * equivalent already in the database (Wrexham FC id 2554, Toluca id
	 * 870, …) — dead-end pages: reachable only by guessing the URL, showing
	 * "Sem informação disponível" everywhere. Deleting is safe precisely
	 * because every table that could reference the team is checked empty
	 * first; a team still linked from a transfer, squad, coach slot, or any
	 * match is left alone.
	 */
	public function repair_orphaned_created_teams(): int {
		global $wpdb;
		$px    = $wpdb->prefix;
		$teams = $wpdb->get_results(
			"SELECT p.ID, p.legacy_id, p.post_title
			   FROM {$wpdb->posts} p
			  WHERE p.post_type = 'f365_team' AND p.post_status = 'publish'
			    AND p.api_source = 'statorium' AND p.legacy_id >= " . self::ID_OFFSET
		);
		$fixed = 0;
		foreach ( $teams as $t ) {
			$pub = (int) $t->legacy_id;

			$has_transfer = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$px}transfer_transfer WHERE team_origin_id = %d OR team_destination_id = %d LIMIT 1",
				$pub, $pub
			) );
			if ( $has_transfer ) {
				continue;
			}

			$teamstep_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$px}team_teamstep WHERE team_id = %d", $pub
			) );
			if ( ! $teamstep_ids ) {
				continue; // no teamstep at all — nothing this repair is designed to clean up
			}
			$in = implode( ',', array_map( 'intval', $teamstep_ids ) );

			$referenced = $wpdb->get_var(
				"SELECT 1 WHERE EXISTS ( SELECT 1 FROM {$px}competition_match WHERE home_team_id IN ({$in}) OR visitor_team_id IN ({$in}) )
				    OR EXISTS ( SELECT 1 FROM {$px}competition_participant WHERE team_id IN ({$in}) )
				    OR EXISTS ( SELECT 1 FROM {$px}team_squad WHERE team_id IN ({$in}) )
				    OR EXISTS ( SELECT 1 FROM {$px}team_teamstepcoach WHERE team_id IN ({$in}) )
				    OR EXISTS ( SELECT 1 FROM {$px}transfer_transfer WHERE team_origin_id IN ({$in}) OR team_destination_id IN ({$in}) )"
			);
			if ( $referenced ) {
				continue; // still legitimately in use somewhere
			}

			if ( $this->dry_run ) {
				$fixed++;
				continue;
			}

			wp_delete_post( (int) $t->ID, true );
			$wpdb->query( "DELETE FROM {$px}team_teamstep WHERE id IN ({$in})" );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$px}team_team WHERE id = %d", $pub ) );

			$this->log( "REPAIR orphaned created team: wp={$t->ID} pub={$pub} \"{$t->post_title}\" deleted — no match/participant/squad/coach/transfer references it" );
			$fixed++;
		}
		return $fixed;
	}

	/**
	 * The fixture-dedup fold ("FIXTURE DEDUP" in pair_legacy_fixtures/
	 * repair_duplicate_fixtures) deletes the "ours" competition_match mirror
	 * row as part of merging into a legacy skeleton, but the WP post-deletion
	 * step (keyed on a fresh `django_match_id` postmeta lookup) can miss it —
	 * leaving a WP post with legacy_id >= ID_OFFSET and a live api_id, but no
	 * mirror row of its own. It duplicates the api_id of its (correctly
	 * merged, mirror-backed) legacy twin, which means map_resolve() always
	 * resolves to the twin — so the orphan never gets a detail sync and sits
	 * in the "Match details" setup-checklist step forever, immune to
	 * re-running it. Deleting the orphan is safe precisely because it has no
	 * mirror row left to lose; the legacy twin already carries the merged data.
	 */
	public function repair_orphaned_match_duplicates(): int {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.legacy_id, p.api_id
			   FROM {$wpdb->posts} p
			  WHERE p.post_type = 'f365_match' AND p.api_source = 'statorium'
			    AND p.legacy_id >= " . self::ID_OFFSET . "
			    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->prefix}competition_match m WHERE m.id = p.legacy_id )"
		);
		if ( ! $rows ) {
			return 0;
		}

		$fixed = 0;
		foreach ( $rows as $r ) {
			$twin = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT p2.ID FROM {$wpdb->posts} p2
				  WHERE p2.post_type = 'f365_match' AND p2.api_source = 'statorium' AND p2.api_id = %d
				    AND p2.ID != %d AND p2.legacy_id < " . self::ID_OFFSET,
				$r->api_id, $r->ID
			) );
			$twin_pub = $twin ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d", $twin ) ) : 0;
			if ( ! $twin_pub || ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}competition_match WHERE id = %d", $twin_pub ) ) ) {
				continue; // no proven-safe legacy twin with real mirror data — leave for manual review
			}
			if ( $this->dry_run ) {
				$fixed++;
				continue;
			}
			$this->log( "REPAIR orphan match duplicate: wp={$r->ID} pub={$r->legacy_id} api_id={$r->api_id} deleted — legacy twin wp={$twin} pub={$twin_pub} already holds the merged data" );
			wp_delete_post( (int) $r->ID, true );
			$fixed++;
		}
		return $fixed;
	}

	public function repair_stadium_country(): int {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.legacy_id AS pub, s.address
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->prefix}stadium_stadium s ON s.id = p.legacy_id
			  WHERE p.post_type = 'f365_stadium' AND p.post_status = 'publish'
			    AND s.address IS NOT NULL AND s.address <> ''
			    AND ( s.country_id IS NULL OR s.country_id = 0
			          OR NOT EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} tr
			                           JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'f365_country'
			                          WHERE tr.object_id = p.ID ) )"
		);
		if ( $this->dry_run ) {
			return count( $rows );
		}

		$fixed = 0;
		foreach ( $rows as $r ) {
			$pt_country = self::derive_country_from_address( (string) $r->address );
			if ( ! $pt_country ) {
				continue;
			}
			$this->set_term_guarded( (int) $r->ID, $pt_country, 'f365_country', $this->is_legacy( (int) $r->ID ) );
			$country_id = $this->tables()->country_id_by_name( $pt_country );
			if ( $country_id && (int) $r->pub ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->prefix}stadium_stadium SET country_id = %d
					  WHERE id = %d AND ( country_id IS NULL OR country_id = 0 )",
					$country_id, (int) $r->pub
				) );
			}
			$fixed++;
		}
		if ( $fixed ) {
			$this->log( "REPAIR stadium country: {$fixed} stadium(s) resolved from existing address data" );
		}
		return $fixed;
	}

	public function repair_teamstep_coaches(): int {
		global $wpdb;
		$px   = $wpdb->prefix;
		$rows = $wpdb->get_results(
			"SELECT ts.id, ts.edition, ts.tmp_coach FROM {$px}team_teamstep ts
			  WHERE ts.tmp_coach IS NOT NULL AND ts.tmp_coach <> ''
			    AND NOT EXISTS ( SELECT 1 FROM {$px}team_teamstepcoach tc
			                      WHERE tc.team_id = ts.id AND tc.coach_type = 'principal' )"
		);
		if ( $this->dry_run ) {
			return count( $rows );
		}
		foreach ( $rows as $r ) {
			$this->tables()->ensure_teamstepcoach( (int) $r->id, (string) $r->edition, (string) $r->tmp_coach );
		}
		if ( $rows ) {
			$this->log( 'REPAIR teamstep coaches: ' . count( $rows ) . ' Equipa Técnica row(s) created from existing tmp_coach data' );
		}
		return count( $rows );
	}

	public function repair_team_names(): int {
		global $wpdb;
		if ( $this->dry_run ) {
			return (int) $wpdb->get_var(
				'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'team_team
				  WHERE id >= ' . self::ID_OFFSET . ' AND longname <> shortname'
			);
		}
		$fixed = (int) $wpdb->query(
			'UPDATE ' . $wpdb->prefix . 'team_team
			    SET longname = shortname
			  WHERE id >= ' . self::ID_OFFSET . ' AND longname <> shortname'
		);
		if ( $fixed ) {
			$this->log( "REPAIR team names: {$fixed} created team(s) longname corrected (was the bare API code, e.g. \"FCP\" instead of \"FC Porto\")" );
		}
		return $fixed;
	}

	public function repair_participant_groups(): int {
		global $wpdb;
		$px = $wpdb->prefix;

		$rows = $wpdb->get_results(
			"SELECT p.id, g.grp FROM {$px}competition_participant p
			   JOIN (
			       SELECT competition_id, home_team_id AS ts, group_home_team AS grp
			         FROM {$px}competition_match WHERE group_home_team IS NOT NULL AND group_home_team <> ''
			       UNION
			       SELECT competition_id, visitor_team_id AS ts, group_visitor_team AS grp
			         FROM {$px}competition_match WHERE group_visitor_team IS NOT NULL AND group_visitor_team <> ''
			   ) g ON g.competition_id = p.competition_id AND g.ts = p.team_id
			  WHERE p.group_phase_one IS NULL OR p.group_phase_one = ''"
		);

		$fixed = 0;
		foreach ( $rows as $r ) {
			if ( $this->dry_run ) {
				$fixed++;
				continue;
			}
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$px}competition_participant SET group_phase_one = %s
				  WHERE id = %d AND ( group_phase_one IS NULL OR group_phase_one = '' )",
				(string) $r->grp, (int) $r->id
			) );
			$fixed++;
		}
		if ( $fixed && ! $this->dry_run ) {
			$this->log( "REPAIR participant groups: {$fixed} row(s) gap-filled from match data" );
		}
		return $fixed;
	}

	/**
	 * Backfill missing wp_competition_participant rows — the Team "Jogos"
	 * page's Game Summary box reads this table to list which competitions a
	 * team is in (see f365-team-jogos.php); competition_match is the source
	 * of truth for "did this teamstep actually play in this competition"
	 * (it's what the fixtures themselves are keyed on), so any (teamstep,
	 * competition) pair present there but absent from competition_participant
	 * is a gap, regardless of how it happened.
	 *
	 * One-time backfill for the bug fixed 2026-08-06: merge_team()/
	 * merge_competition()/repair_group_pairing() folded a duplicate teamstep
	 * (and deleted it) without repointing competition_participant first —
	 * every merge that hit the fold branch (created team collided with a
	 * legacy team that already had its own teamstep for that edition — the
	 * common case for any actively-tracked club) silently dropped that
	 * competition from Game Summary, even though the fixtures themselves
	 * stayed correct (competition_match WAS repointed) and the competition
	 * page kept showing them. All three call sites now repoint
	 * competition_participant too, so this only needs to run once to clear
	 * the backlog any merge already caused before the fix landed — new
	 * merges self-heal going forward and won't re-create the gap. Also
	 * doubles as a general correctness net for any other historical gap of
	 * the same shape (e.g. legacy data migrated without a participant row).
	 *
	 * No unique constraint on (competition_id, team_id) to lean on
	 * (confirmed: competition_participant only has single-column indexes),
	 * so this checks existence explicitly rather than relying on INSERT
	 * IGNORE / ON DUPLICATE KEY.
	 */
	public function repair_missing_participants(): int {
		global $wpdb;
		$px = $wpdb->prefix;

		$missing = $wpdb->get_results(
			"SELECT DISTINCT parts.tid AS team_id, parts.cid AS competition_id
			   FROM (
			        SELECT home_team_id AS tid, competition_id AS cid FROM {$px}competition_match
			         WHERE home_team_id IS NOT NULL AND home_team_id <> 0
			        UNION
			        SELECT visitor_team_id AS tid, competition_id AS cid FROM {$px}competition_match
			         WHERE visitor_team_id IS NOT NULL AND visitor_team_id <> 0
			   ) parts
			  WHERE NOT EXISTS (
			        SELECT 1 FROM {$px}competition_participant cp
			         WHERE cp.team_id = parts.tid AND cp.competition_id = parts.cid
			  )"
		);

		$added = 0;
		foreach ( $missing as $row ) {
			if ( $this->dry_run ) {
				$added++;
				continue;
			}
			$wpdb->insert( "{$px}competition_participant", [
				'team_id'       => (int) $row->team_id,
				'competition_id' => (int) $row->competition_id,
			] );
			$added++;
		}
		if ( $added && ! $this->dry_run ) {
			$this->log( "REPAIR missing participants: {$added} competition_participant row(s) backfilled from match data" );
		}
		return $added;
	}

	/**
	 * Copy already-fetched stadium capacity/city/address/coords/opening
	 * postmeta into the mirror stadium_stadium row for LEGACY-linked
	 * stadiums enriched before enrich_venue gained its legacy gap-fill
	 * (the throttle meta stops them from ever re-running that path). No
	 * API calls; fill-gap only. Returns rows touched.
	 */
	public function repair_stadium_mirror_profiles(): int {
		global $wpdb;
		$px   = $wpdb->prefix;
		$rows = $wpdb->get_results(
			"SELECT p.legacy_id AS pub,
			        MAX( CASE WHEN m.meta_key = 'capacity' THEN m.meta_value END ) AS capacity,
			        MAX( CASE WHEN m.meta_key = 'city' THEN m.meta_value END ) AS city,
			        MAX( CASE WHEN m.meta_key = 'address' THEN m.meta_value END ) AS address,
			        MAX( CASE WHEN m.meta_key = 'latitude' THEN m.meta_value END ) AS latitude,
			        MAX( CASE WHEN m.meta_key = 'longitude' THEN m.meta_value END ) AS longitude,
			        MAX( CASE WHEN m.meta_key = 'opening' THEN m.meta_value END ) AS opening
			   FROM {$wpdb->posts} p
			   JOIN {$px}stadium_stadium s ON s.id = p.legacy_id
			   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			        AND m.meta_key IN ('capacity','city','address','latitude','longitude','opening') AND m.meta_value <> ''
			  WHERE p.post_type = 'f365_stadium' AND p.post_status = 'publish' AND p.api_source = 'statorium'
			    AND p.legacy_id < " . self::ID_OFFSET . "
			    AND ( s.capacity IS NULL OR s.capacity = 0 OR s.city IS NULL OR s.city = ''
			          OR s.address IS NULL OR s.address = '' OR s.opening IS NULL )
			  GROUP BY p.legacy_id"
		);
		$fixed = 0;
		foreach ( $rows as $r ) {
			if ( $this->dry_run ) {
				$fixed++;
				continue;
			}
			foreach ( [ 'capacity', 'city', 'address', 'latitude', 'longitude' ] as $col ) {
				$val = (string) ( $r->{$col} ?? '' );
				if ( '' === $val ) {
					continue;
				}
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$px}stadium_stadium SET {$col} = %s
					  WHERE id = %d AND ( {$col} IS NULL OR {$col} = '' OR {$col} = '0' )",
					$val, (int) $r->pub
				) );
			}
			if ( ! empty( $r->opening ) && preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $r->opening ) ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$px}stadium_stadium SET opening = %s WHERE id = %d AND opening IS NULL",
					substr( (string) $r->opening, 0, 10 ), (int) $r->pub
				) );
			}
			$fixed++;
		}
		if ( $fixed && ! $this->dry_run ) {
			$this->log( "REPAIR stadium mirror profiles: {$fixed} legacy row(s) gap-filled from postmeta" );
		}
		return $fixed;
	}

	public function repair_missing_stadium_rows(): int {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.legacy_id AS pub FROM {$wpdb->posts} p
			  WHERE p.post_type = 'f365_stadium' AND p.post_status = 'publish'
			    AND p.api_source = 'statorium' AND p.legacy_id IS NOT NULL
			    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->prefix}stadium_stadium s WHERE s.id = p.legacy_id )"
		);
		foreach ( $rows as $r ) {
			$this->tables()->sync_stadium_row( (int) $r->pub, (string) $r->post_title );
			delete_post_meta( (int) $r->ID, 'statorium_venue_synced' ); // allow enrich_venue to run again
			$stat = self::api_id_of( (int) $r->ID );
			if ( $stat ) {
				$this->enrich_venue( $stat ); // fills capacity/city/address into the row just created
			}
			$this->log( "REPAIR stadium mirror row created: post {$r->ID} pub {$r->pub} \"{$r->post_title}\"" );
		}
		if ( $rows ) {
			$this->log( 'REPAIR missing stadium rows: ' . count( $rows ) . ' created' );
		}
		return count( $rows );
	}

	public function repair_foundation_dates(): int {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta}
			  WHERE meta_key = 'foundation' AND meta_value <> ''
			    AND meta_value NOT REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'"
		);
		$fixed   = 0;
		$cleared = 0;
		foreach ( $rows as $r ) {
			$clean = self::parse_statorium_date( (string) $r->meta_value );
			if ( $clean ) {
				$wpdb->update( $wpdb->postmeta, [ 'meta_value' => $clean ], [ 'meta_id' => (int) $r->meta_id ] );
				$fixed++;
			} else {
				delete_post_meta( (int) $r->post_id, 'foundation' );
				$cleared++;
			}
		}
		if ( $fixed || $cleared ) {
			$this->log( "REPAIR foundation dates: {$fixed} corrected, {$cleared} unparsable value(s) cleared" );
		}
		return $fixed + $cleared;
	}

	public function repair_linked_team_shortnames(): int {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, pm.meta_value AS shortname
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'shortname'
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

	/**
	 * Auto-heal duplicates: scan Statorium-created teams for a legacy team
	 * with the same (or alias-equivalent) normalized name. Exactly one
	 * confident candidate → merged automatically; multiple candidates →
	 * logged for review, nothing touched. Runs on every sync cycle.
	 * Returns [merged_pairs, ambiguous_count].
	 */
	/**
	 * A "shadow" team = a post we created that carries a LEGACY public id
	 * (legacy_id < OFFSET) while the legacy post for that id also exists —
	 * two WP posts for one team, sharing the same mirror rows. Absorb the
	 * created shadow into the legacy post: identity moves over, post-meta
	 * references are repointed, the shadow is deleted. Runs in every sync.
	 */
	public function absorb_shadow_teams(): int {
		global $wpdb;

		$shadows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.ID AS created_id, c.post_title, c.api_id, l.ID AS legacy_post
			   FROM {$wpdb->posts} c
			   JOIN {$wpdb->postmeta} s ON s.post_id = c.ID AND s.meta_key = '_f365_source' AND s.meta_value = 'statorium'
			   JOIN {$wpdb->posts} l ON l.legacy_id = c.legacy_id AND l.post_type = c.post_type
			        AND l.ID <> c.ID AND l.post_status = 'publish'
			        AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} ls
			                          WHERE ls.post_id = l.ID AND ls.meta_key = '_f365_source' )
			  WHERE c.post_type = 'f365_team' AND c.post_status = 'publish'
			    AND c.legacy_id BETWEEN 1 AND %d",
			self::ID_OFFSET - 1
		) );

		foreach ( $shadows as $sh ) {
			$created = (int) $sh->created_id;
			$legacy  = (int) $sh->legacy_post;
			$sid     = (int) $sh->api_id;

			if ( $sid ) {
				$this->map_set( 'team', $sid, $legacy );
			}
			update_post_meta( $legacy, '_f365_statorium_linked', 1 );
			update_post_meta( $legacy, '_f365_match_method', 'shadow-absorb' );
			add_post_meta( $legacy, '_statorium_synced_at', current_time( 'mysql' ), true );

			// Repoint relation metas that referenced the shadow post id.
			foreach ( [ 'home_team', 'visitor_team', 'current_team' ] as $key ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->postmeta} SET meta_value = %d WHERE meta_key = %s AND meta_value = %s",
					$legacy, $key, (string) $created
				) );
			}

			foreach ( get_children( [ 'post_parent' => $created, 'post_type' => 'attachment', 'fields' => 'ids' ] ) as $att ) {
				wp_delete_attachment( (int) $att, true );
			}
			wp_delete_post( $created, true );
			clean_post_cache( $legacy );
			$this->log( "SHADOW absorbed team \"{$sh->post_title}\" (post {$created}) → legacy post {$legacy}" );
		}

		return count( $shadows );
	}

	/**
	 * Merge a Statorium-CREATED player into an existing legacy player (same
	 * person, duplicate created because the sources disagreed — usually a
	 * ±1-day birthdate). Repoints mirror rows (lineups, squads, transfers),
	 * moves the api identity, deletes the duplicate. Legacy data untouched.
	 */
	public function merge_player( int $created_wp, int $legacy_wp ): bool {
		global $wpdb;

		if ( $this->is_legacy( $created_wp ) || ! $this->is_legacy( $legacy_wp ) ) {
			$this->log( "PMERGE abort: {$created_wp} must be statorium-created and {$legacy_wp} must be legacy" );
			return false;
		}
		$created_pub = (int) get_post_meta( $created_wp, 'django_player_id', true );
		$legacy_pub  = (int) get_post_meta( $legacy_wp, 'django_player_id', true );
		$stat_id     = self::api_id_of( $created_wp );
		if ( $created_pub < self::ID_OFFSET || ! $legacy_pub || ! $stat_id ) {
			$this->log( "PMERGE abort: ids (created_pub={$created_pub} legacy_pub={$legacy_pub} stat={$stat_id})" );
			return false;
		}

		foreach ( [ 'competition_matchplayer' => 'player_id', 'team_squad' => 'player_id', 'transfer_transfer' => 'player_id' ] as $table => $col ) {
			// team_squad carries a UNIQUE KEY on (team_id, player_id,
			// edition, order) — a blind UPDATE fatals if the legacy player
			// already has a squad row for the same team+edition+slot the
			// created duplicate does. IGNORE skips just that row instead of
			// aborting the whole merge; the leftover is a genuine duplicate
			// of a row that already exists for the legacy player, so it's
			// deleted outright below rather than left dangling.
			$wpdb->query( $wpdb->prepare(
				"UPDATE IGNORE {$wpdb->prefix}{$table} SET {$col} = %d WHERE {$col} = %d",
				$legacy_pub, $created_pub
			) );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}team_squad WHERE player_id = %d", $created_pub ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}person_player WHERE id = %d", $created_pub ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}person_person WHERE id = %d", $created_pub ) );

		$this->map_set( 'player', $stat_id, $legacy_wp );
		update_post_meta( $legacy_wp, '_f365_statorium_linked', 1 );
		update_post_meta( $legacy_wp, '_f365_match_method', 'auto-merge-duplicate' );
		add_post_meta( $legacy_wp, '_statorium_synced_at', current_time( 'mysql' ), true );
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . F365_Statorium_Matcher::review_table() . " SET status = 'linked' WHERE entity_type = 'player' AND stat_id = %d",
			$stat_id
		) );

		foreach ( get_children( [ 'post_parent' => $created_wp, 'post_type' => 'attachment', 'fields' => 'ids' ] ) as $att ) {
			wp_delete_attachment( (int) $att, true );
		}
		wp_delete_post( $created_wp, true );
		clean_post_cache( $legacy_wp );
		$this->log( "PMERGE player stat={$stat_id}: created post {$created_wp} (pub {$created_pub}) → legacy post {$legacy_wp} (pub {$legacy_pub})" );
		return true;
	}

	/**
	 * Auto-merge created players that duplicate legacy ones. STRICT rule to
	 * make wrong merges impossible on common names: exactly one legacy
	 * name-twin AND both birthdates known AND within one day. Everything
	 * else is left alone (ambiguous cases logged for manual merge-player).
	 */
	public function auto_merge_duplicate_players(): array {
		global $wpdb;

		$created = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, bd.meta_value AS birthdate FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_f365_source' AND s.meta_value = 'statorium'
			   LEFT JOIN {$wpdb->postmeta} bd ON bd.post_id = p.ID AND bd.meta_key = 'birthdate'
			  WHERE p.api_source = %s AND p.post_type = 'f365_player' AND p.post_status = 'publish'",
			self::API_SOURCE
		) );

		$merged    = [];
		$ambiguous = 0;
		$skipped   = 0;

		foreach ( $created as $post ) {
			if ( empty( $post->birthdate ) ) {
				$skipped++; // no birthdate → cannot merge safely
				continue;
			}
			$norm = F365_Statorium_Matcher::normalize( (string) $post->post_title );
			if ( '' === $norm ) {
				continue;
			}
			$name_where = $wpdb->prepare( 'nm.meta_value = %s', $norm );
			$parts      = explode( ' ', $norm );
			if ( count( $parts ) >= 2 ) {
				// Middle-name variants ("Juan Fernando Quintero" vs "Juan
				// Quintero") — safe here because the birthdate-close filter
				// below is mandatory for any merge.
				$name_where = '( ' . $name_where . ' OR ' . $wpdb->prepare( 'nm.meta_value LIKE %s', $wpdb->esc_like( $parts[0] ) . '%' . $wpdb->esc_like( end( $parts ) ) ) . ' )';
			}
			$candidates = $wpdb->get_results( $wpdb->prepare(
				"SELECT DISTINCT l.ID, pr.birth_date FROM {$wpdb->posts} l
				   JOIN {$wpdb->postmeta} nm ON nm.post_id = l.ID AND nm.meta_key = %s AND {$name_where}
				   JOIN {$wpdb->prefix}person_player pl ON pl.id = l.legacy_id
				   JOIN {$wpdb->prefix}person_person pr ON pr.id = pl.person_id
				  WHERE l.post_type = 'f365_player' AND l.post_status = 'publish' AND l.ID != %d
				    AND l.legacy_id BETWEEN 1 AND %d
				    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} ls
				                      WHERE ls.post_id = l.ID AND ls.meta_key = '_f365_source' )",
				F365_Statorium_Matcher::NORM_KEY, (int) $post->ID, self::ID_OFFSET - 1
			) );

			$close = array_values( array_filter( $candidates, fn( $c ) =>
				$c->birth_date && '0000-00-00' !== $c->birth_date
				&& F365_Statorium_Matcher::dates_close( (string) $c->birth_date, (string) $post->birthdate )
			) );

			if ( 1 === count( $close ) ) {
				if ( $this->merge_player( (int) $post->ID, (int) $close[0]->ID ) ) {
					$merged[] = [ (int) $post->ID, (int) $close[0]->ID, (string) $post->post_title ];
				}
			} elseif ( count( $close ) > 1 ) {
				$ambiguous++;
				$this->log( 'PMERGE ambiguous: "' . $post->post_title . '" (' . $post->birthdate . ') matches ' . count( $close ) . ' legacy players — resolve manually (merge-player)' );
			}
		}

		return [ $merged, $ambiguous, $skipped ];
	}

	/**
	 * Merge a Statorium-CREATED stadium into an existing legacy one (same
	 * ground under a name variant — API "SoFi Stadium" vs legacy "SoFi").
	 * Repoints every reference, moves the api identity, deletes the dup.
	 */
	public function merge_stadium( int $created_wp, int $legacy_wp ): bool {
		global $wpdb;

		if ( $this->is_legacy( $created_wp ) || ! $this->is_legacy( $legacy_wp ) ) {
			$this->log( "SMERGE abort: {$created_wp} must be statorium-created and {$legacy_wp} must be legacy" );
			return false;
		}
		$created_pub = (int) get_post_meta( $created_wp, 'django_id', true );
		// Legacy stadium posts carry their public id in `django_stadium_id`
		// (no django_id meta, no legacy_id column — unlike teams/players).
		$legacy_pub = (int) get_post_meta( $legacy_wp, 'django_id', true )
			?: (int) get_post_meta( $legacy_wp, 'django_stadium_id', true )
			?: (int) $wpdb->get_var( $wpdb->prepare( "SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d", $legacy_wp ) );
		$stat_id = self::api_id_of( $created_wp );
		if ( $created_pub < self::ID_OFFSET || ! $legacy_pub || ! $stat_id ) {
			$this->log( "SMERGE abort: ids (created_pub={$created_pub} legacy_pub={$legacy_pub} stat={$stat_id})" );
			return false;
		}

		foreach ( [ 'competition_match' => 'stadium_id', 'team_team' => 'stadium_id', 'team_teamstep' => 'stadium_id' ] as $table => $col ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}{$table} SET {$col} = %d WHERE {$col} = %d", $legacy_pub, $created_pub ) );
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = %d WHERE meta_key = 'django_stadium_id' AND meta_value = %s", $legacy_pub, (string) $created_pub ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = %d WHERE meta_key = 'stadium' AND meta_value = %s", $legacy_wp, (string) $created_wp ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}stadium_stadium WHERE id = %d", $created_pub ) );

		// Theme reads /media/images/stadium/{pub}.jpg — keep the photo.
		$base = trailingslashit( ABSPATH ) . 'media/images/stadium/';
		if ( file_exists( $base . $created_pub . '.jpg' ) && ! file_exists( $base . $legacy_pub . '.jpg' ) ) {
			@copy( $base . $created_pub . '.jpg', $base . $legacy_pub . '.jpg' );
		}

		$this->map_set( 'venue', $stat_id, $legacy_wp );
		update_post_meta( $legacy_wp, '_f365_statorium_linked', 1 );
		update_post_meta( $legacy_wp, '_f365_match_method', 'auto-merge-duplicate' );
		add_post_meta( $legacy_wp, '_statorium_synced_at', current_time( 'mysql' ), true );
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . F365_Statorium_Matcher::review_table() . " SET status = 'linked' WHERE entity_type = 'venue' AND stat_id = %d",
			$stat_id
		) );

		foreach ( get_children( [ 'post_parent' => $created_wp, 'post_type' => 'attachment', 'fields' => 'ids' ] ) as $att ) {
			wp_delete_attachment( (int) $att, true );
		}
		wp_delete_post( $created_wp, true );
		clean_post_cache( $legacy_wp );
		$this->log( "SMERGE stadium stat={$stat_id}: created post {$created_wp} (pub {$created_pub}) → legacy post {$legacy_wp} (pub {$legacy_pub})" );
		return true;
	}

	/**
	 * Auto-merge created stadiums that duplicate legacy ones under a
	 * generic-word name variant. Merges only on exactly ONE legacy candidate.
	 */
	public function auto_merge_duplicate_stadiums(): array {
		global $wpdb;

		// Heal pass: linked LEGACY stadium posts that an older build stamped
		// with a fake OFFSET django_id (their real pub lives in
		// django_stadium_id) — a parallel mirror row and match references
		// under the fake pub came with it. Repoint everything to the real
		// pub, remove the phantom row, correct the meta.
		$sick = $wpdb->get_results(
			"SELECT p.ID, dj.meta_id AS dj_meta, dj.meta_value AS fake_pub, ds.meta_value AS real_pub
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} dj ON dj.post_id = p.ID AND dj.meta_key = 'django_id'
			   JOIN {$wpdb->postmeta} ds ON ds.post_id = p.ID AND ds.meta_key = 'django_stadium_id'
			  WHERE p.post_type = 'f365_stadium' AND p.api_source = 'statorium'
			    AND CAST( dj.meta_value AS UNSIGNED ) >= " . self::ID_OFFSET . '
			    AND CAST( ds.meta_value AS UNSIGNED ) BETWEEN 1 AND ' . ( self::ID_OFFSET - 1 ) . "
			    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} s
			                      WHERE s.post_id = p.ID AND s.meta_key = '_f365_source' )"
		);
		foreach ( $sick as $row ) {
			$fake = (int) $row->fake_pub;
			$real = (int) $row->real_pub;
			foreach ( [ 'competition_match' => 'stadium_id', 'team_team' => 'stadium_id', 'team_teamstep' => 'stadium_id' ] as $table => $col ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}{$table} SET {$col} = %d WHERE {$col} = %d", $real, $fake ) );
			}
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = %d WHERE meta_key = 'django_stadium_id' AND meta_value = %s", $real, (string) $fake ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}stadium_stadium WHERE id = %d", $fake ) );
			$wpdb->update( $wpdb->postmeta, [ 'meta_value' => (string) $real ], [ 'meta_id' => (int) $row->dj_meta ] );
			$base = trailingslashit( ABSPATH ) . 'media/images/stadium/';
			if ( file_exists( $base . $fake . '.jpg' ) && ! file_exists( $base . $real . '.jpg' ) ) {
				@copy( $base . $fake . '.jpg', $base . $real . '.jpg' );
			}
			clean_post_cache( (int) $row->ID );
			$this->log( "SHEAL stadium post {$row->ID}: fake pub {$fake} → real {$real} (refs repointed, phantom mirror row removed)" );
		}

		$created = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_f365_source' AND s.meta_value = 'statorium'
			  WHERE p.api_source = %s AND p.post_type = 'f365_stadium' AND p.post_status = 'publish'",
			self::API_SOURCE
		) );

		$merged    = [];
		$ambiguous = 0;
		foreach ( $created as $post ) {
			$names = array_filter( array_unique( array_map(
				[ 'F365_Statorium_Matcher', 'normalize' ],
				self::stadium_name_variants( (string) $post->post_title )
			) ) );
			if ( ! $names ) {
				continue;
			}
			$in = "'" . implode( "','", array_map( 'esc_sql', $names ) ) . "'";
			// NOTE: legacy stadium posts do NOT carry the legacy_id column
			// (unlike teams/players) — legacy detection here is purely the
			// absence of the created-marker meta.
			$candidates = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT l.ID FROM {$wpdb->posts} l
				   JOIN {$wpdb->postmeta} nm ON nm.post_id = l.ID AND nm.meta_key = %s AND nm.meta_value IN ({$in})
				  WHERE l.post_type = 'f365_stadium' AND l.post_status = 'publish' AND l.ID != %d
				    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} ls
				                      WHERE ls.post_id = l.ID AND ls.meta_key = '_f365_source' )",
				F365_Statorium_Matcher::NORM_KEY, (int) $post->ID
			) );

			if ( 1 === count( $candidates ) ) {
				if ( $this->merge_stadium( (int) $post->ID, (int) $candidates[0] ) ) {
					$merged[] = [ (int) $post->ID, (int) $candidates[0], (string) $post->post_title ];
				}
			} elseif ( count( $candidates ) > 1 ) {
				$ambiguous++;
				$this->log( 'SMERGE ambiguous: "' . $post->post_title . '" matches ' . count( $candidates ) . ' legacy stadiums — resolve manually' );
			} else {
				// Zero name candidates — a real venue can carry a genuinely
				// different name in each source (sponsorship renames like
				// "Ricoh Arena" → "Coventry Building Society Arena", or a
				// one-off Olympic-era name like "City of Coventry Stadium"),
				// which no name normalization will ever unify. Coordinates
				// don't change when a stadium is renamed, so fall back to
				// GPS proximity: enrich_venue() always populates lat/long on
				// a freshly created stadium, and (verified) every legacy
				// stadium row in this DB already carries real coordinates
				// from the original migration — so this signal is broadly
				// available, not just for this one case.
				$coord_id = $this->merge_stadium_by_coordinates( (int) $post->ID );
				if ( $coord_id ) {
					if ( $this->merge_stadium( (int) $post->ID, $coord_id ) ) {
						$merged[] = [ (int) $post->ID, $coord_id, (string) $post->post_title . ' (matched by coordinates)' ];
					}
				}
			}
		}
		return [ $merged, $ambiguous ];
	}

	/**
	 * Known "same physical venue, GPS-only match is genuinely ambiguous"
	 * pairs — cases where auto_merge_duplicate_stadiums()'s coordinate
	 * fallback correctly refuses to guess because a second real, DIFFERENT
	 * venue also falls within the conservative 1km radius (a rival club's
	 * ground, a demolished-and-rebuilt predecessor stadium, an indoor arena
	 * next door). Each pair was individually verified by name/translation
	 * match against the rejected alternative before being added here — see
	 * the inline reasoning per entry. Runs on every sync (cheap: a handful
	 * of map_resolve() lookups, real work only happens once per pair, ever,
	 * since merge_stadium() deletes the created post) so a fresh environment
	 * — a new server, a reset local install — never has to rediscover and
	 * re-review these by hand; this is a durable answer to the "how do I
	 * make sure this isn't forgotten on the next deploy" problem, not a
	 * one-off script. Keyed by Statorium stat_id (portable across every
	 * environment, unlike WP post IDs) → legacy public id. Extend via the
	 * f365_statorium_known_stadium_merges filter as new cases are found.
	 */
	public static function known_stadium_merge_pairs(): array {
		return apply_filters( 'f365_statorium_known_stadium_merges', [
			14   => [ 509,  'Anfield → Anfield Road (Everton\'s Goodison Park 0.94km away rejected)' ],
			56   => [ 778,  'Arena AufSchalke (VELTINS-Arena) → Veltins Arena (Schalke\'s demolished Parkstadion rejected)' ],
			134  => [ 2878, 'Juventus Stadium (Allianz Stadium) → Juventus Arena (demolished Delle Alpi rejected)' ],
			165  => [ 212,  'Rajko Mitic Stadium → Crvena Zvezda (rival Partizan\'s ground rejected)' ],
			250  => [ 767,  'Estádio Urbano Caldeira (Vila Belmiro) → Vila Belmiro (Ulrico Mursa rejected)' ],
			381  => [ 727,  'Estádio do Rio Ave FC → Arcos (an indoor pavilion rejected)' ],
			570  => [ 516,  'Vasil Levski National Stadium → Vasil Levski (CSKA Sofia\'s ground rejected)' ],
			640  => [ 993,  'Estádio de São Luís → São Luís (Municipal da Penha rejected)' ],
			695  => [ 373,  'Stadion Poljud → Poljud (Park Mladezi rejected)' ],
			1943 => [ 842,  'Stadion Kraj Bistrice → Kraj Bistrice (Celika rejected despite being closer)' ],
			1951 => [ 1822, 'Darius and Girėnas Stadium → Dariaus ir Girėno (national academy stadium rejected)' ],
			3703 => [ 1862, 'Estádio do Fontelo → Fontelo (1º de Maio rejected)' ],
		] );
	}

	public function resolve_known_stadium_merges(): int {
		global $wpdb;
		$known  = self::known_stadium_merge_pairs();
		$merged = 0;
		foreach ( $known as $stat_id => [ $legacy_pub, $why ] ) {
			$resolved = $this->map_resolve( 'venue', (int) $stat_id );
			if ( ! $resolved || $this->is_legacy( $resolved ) ) {
				continue; // not on this environment yet, or already merged
			}
			$legacy_wp = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				  WHERE p.post_type = 'f365_stadium' AND m.meta_key IN ( 'django_stadium_id', 'django_id' ) AND m.meta_value = %s
				  LIMIT 1",
				(string) $legacy_pub
			) );
			if ( ! $legacy_wp ) {
				continue;
			}
			if ( $this->merge_stadium( $resolved, $legacy_wp ) ) {
				$this->log( "SMERGE known pair stat_id={$stat_id}: {$why}" );
				$merged++;
			}
		}
		return $merged;
	}

	/**
	 * Find an unlinked legacy stadium within ~1km of a created stadium's own
	 * coordinates — the fallback for a real venue that carries a completely
	 * different name in each source (sponsorship rename, temporary event
	 * name, translation with no shared token). Conservative on purpose: a
	 * bounding box first (cheap, index-friendly), then a real haversine
	 * distance on the shortlist, and only ever returns a match when exactly
	 * one legacy stadium falls inside the radius — multiple candidates means
	 * "too many stadiums in this area to trust GPS alone" and is left alone
	 * rather than risk a wrong link.
	 */
	private function merge_stadium_by_coordinates( int $created_wp_id ): int {
		$lat = (float) get_post_meta( $created_wp_id, 'latitude', true );
		$lng = (float) get_post_meta( $created_wp_id, 'longitude', true );
		if ( ! $lat || ! $lng ) {
			return 0; // not yet enriched — enrich_venue() hasn't run or the API sent none
		}

		global $wpdb;
		$radius_km   = 1.0;
		$lat_delta   = $radius_km / 111.0; // ~111km per degree of latitude
		$lng_delta   = $radius_km / ( 111.0 * max( 0.2, cos( deg2rad( $lat ) ) ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			// Stadium quirk (see upsert_venue()): an UNLINKED legacy stadium
			// carries its pub ONLY in django_stadium_id — django_id is only
			// backfilled once Statorium links it, which by definition hasn't
			// happened yet for the row we're trying to find here. Prefer
			// django_stadium_id, fall back to django_id for already-linked ones.
			"SELECT l.ID, s.latitude, s.longitude
			   FROM {$wpdb->posts} l
			   JOIN {$wpdb->prefix}stadium_stadium s ON s.id = (
			         SELECT CAST( m.meta_value AS UNSIGNED ) FROM {$wpdb->postmeta} m
			          WHERE m.post_id = l.ID AND m.meta_key IN ( 'django_stadium_id', 'django_id' )
			          ORDER BY FIELD( m.meta_key, 'django_stadium_id', 'django_id' ) LIMIT 1 )
			  WHERE l.post_type = 'f365_stadium' AND l.post_status = 'publish' AND l.ID != %d
			    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} ls
			                      WHERE ls.post_id = l.ID AND ls.meta_key = '_f365_source' )
			    AND s.latitude BETWEEN %f AND %f
			    AND s.longitude BETWEEN %f AND %f
			    AND s.latitude <> '' AND s.longitude <> ''",
			$created_wp_id,
			$lat - $lat_delta, $lat + $lat_delta,
			$lng - $lng_delta, $lng + $lng_delta
		) );

		$within = [];
		foreach ( $rows as $r ) {
			$d = self::haversine_km( $lat, $lng, (float) $r->latitude, (float) $r->longitude );
			if ( $d <= $radius_km ) {
				$within[] = (int) $r->ID;
			}
		}

		if ( 1 === count( $within ) ) {
			return $within[0];
		}
		if ( count( $within ) > 1 ) {
			$this->log( "SMERGE coordinate match ambiguous for wp_id={$created_wp_id}: " . count( $within ) . ' legacy stadiums within ' . $radius_km . 'km — resolve manually' );
		}
		return 0;
	}

	/** Great-circle distance in km between two lat/long points. */
	private static function haversine_km( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$r    = 6371.0; // Earth radius, km
		$dLat = deg2rad( $lat2 - $lat1 );
		$dLng = deg2rad( $lng2 - $lng1 );
		$a    = sin( $dLat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dLng / 2 ) ** 2;
		return $r * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}

	/**
	 * One-time-per-environment gap-fill: geocodes legacy stadium rows that
	 * have never had coordinates (pre-Statorium Django data — verified this
	 * is a small remainder, ~1% of all stadiums) via OpenStreetMap's free
	 * Nominatim search API, so merge_stadium_by_coordinates()'s ~1km
	 * matching signal is available for every legacy stadium, not just the
	 * ones Statorium happens to have already enriched. Two lookups per row:
	 * venue name/address+city+country first (precise when OSM indexes that
	 * specific place), falling back to city+country alone (city-center
	 * precision — still useful for the ~1km radius, just coarser) for the
	 * small local pitches OSM doesn't know by name.
	 *
	 * Runs from the setup checklist / f365_statorium_complete_pending_setup()
	 * — i.e. automatically, once, the first time sync runs on a given
	 * environment — NOT from the regular per-cycle sync steps, and NOT from
	 * the 2-minute tick. It's gated purely on `WHERE latitude IS NULL/''`,
	 * so every call after the first finds nothing left to do and returns
	 * instantly — this is what keeps it compatible with Nominatim's usage
	 * policy (~1 req/sec, not meant for repeated automated traffic): the
	 * actual network activity only ever happens once per environment, not
	 * on every sync forever. The 1 req/sec pacing itself is enforced with a
	 * real sleep() between calls — expect this step to take a minute or two
	 * the one time it has real work to do.
	 *
	 * A stadium OSM genuinely has no data for (an obscure local pitch) would
	 * otherwise never leave the "still missing" set and get re-queried on
	 * every single future sync forever — the same trap the `heal_*` profile
	 * enrichment methods already solve with a `_statorium_*_checked` throttle
	 * meta. Same pattern here: a failed lookup stamps `_statorium_geocode_
	 * checked` on the corresponding WP post, and rows checked within the
	 * last 7 days are skipped, so a permanent OSM gap costs one attempt, not
	 * an attempt every sync.
	 *
	 * `id < ID_OFFSET` is a deliberate, explicit guard — Statorium-created
	 * rows must only ever get their coordinates from Statorium's own API
	 * (enrich_venue(), which overwrites them unconditionally on every real
	 * sync — "our rows: refreshed freely" per that method's own docblock),
	 * never from this third-party geocoder. Today every created row already
	 * has coordinates by the time this runs, so this filter is currently a
	 * no-op in practice — it's here so that stays true by construction, not
	 * by coincidence (e.g. a venue discovered via a team's homeVenue but not
	 * yet enrich_venue()'d, or one the API genuinely sends no coordinates
	 * for, would otherwise be indistinguishable from a legacy gap here).
	 */
	public function geocode_missing_stadium_coordinates(): int {
		global $wpdb;
		$cutoff = gmdate( 'c', time() - 7 * DAY_IN_SECONDS );
		$rows   = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.shortname, s.city, s.address, s.country_id, p.ID AS wp_id
			   FROM {$wpdb->prefix}stadium_stadium s
			   LEFT JOIN {$wpdb->postmeta} dsi ON dsi.meta_key = 'django_stadium_id' AND dsi.meta_value = s.id
			   LEFT JOIN {$wpdb->postmeta} dji ON dji.meta_key = 'django_id' AND dji.meta_value = s.id
			   LEFT JOIN {$wpdb->posts} p ON p.ID = COALESCE( dsi.post_id, dji.post_id ) AND p.post_type = 'f365_stadium'
			  WHERE s.id < " . self::ID_OFFSET . "
			    AND ( s.latitude IS NULL OR s.latitude = '' )
			    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} ck
			                      WHERE ck.post_id = p.ID AND ck.meta_key = '_statorium_geocode_checked'
			                        AND ck.meta_value > %s )",
			$cutoff
		) );
		if ( ! $rows || $this->dry_run ) {
			return 0;
		}

		$country_name = static function ( int $country_id ) use ( $wpdb ): string {
			if ( ! $country_id ) {
				return '';
			}
			return (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT shortname FROM {$wpdb->prefix}country_country WHERE id = %d", $country_id
			) );
		};

		$lookup = static function ( string $query ): ?array {
			if ( '' === $query ) {
				return null;
			}
			$resp = wp_remote_get(
				'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . rawurlencode( $query ),
				[ 'timeout' => 10, 'user-agent' => 'F365-Statorium-Sync/1.0 (one-time stadium coordinate backfill)' ]
			);
			sleep( 1 ); // Nominatim usage policy: max 1 request/second.
			if ( is_wp_error( $resp ) ) {
				return null;
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
			return ( ! empty( $body[0]['lat'] ) && ! empty( $body[0]['lon'] ) )
				? [ (string) $body[0]['lat'], (string) $body[0]['lon'] ]
				: null;
		};

		$filled = 0;
		foreach ( $rows as $r ) {
			$country = $country_name( (int) $r->country_id );
			$found   = $lookup( trim( implode( ', ', array_filter( [ $r->address ?: $r->shortname, $r->city, $country ] ) ) ) )
				?? ( '' !== (string) $r->city ? $lookup( trim( implode( ', ', array_filter( [ $r->city, $country ] ) ) ) ) : null );
			if ( ! $found ) {
				if ( $r->wp_id ) {
					update_post_meta( (int) $r->wp_id, '_statorium_geocode_checked', gmdate( 'c' ) );
				}
				continue;
			}
			[ $lat, $lng ] = $found;
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}stadium_stadium SET latitude = %s, longitude = %s WHERE id = %d AND ( latitude IS NULL OR latitude = '' )",
				$lat, $lng, (int) $r->id
			) );
			$filled++;
		}
		if ( $filled ) {
			$this->log( "GEOCODE filled coordinates for {$filled} legacy stadium(s) via Nominatim" );
		}
		return $filled;
	}

	public function auto_merge_duplicate_teams(): array {
		global $wpdb;

		$this->absorb_shadow_teams();

		$created = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_f365_source' AND s.meta_value = 'statorium'
			  WHERE p.api_source = %s AND p.post_type = 'f365_team' AND p.post_status = 'publish'",
			self::API_SOURCE
		) );
		if ( ! $created ) {
			return [ [], 0 ];
		}

		$aliases   = F365_Statorium_Matcher::name_aliases();
		$merged    = [];
		$ambiguous = 0;

		foreach ( $created as $post ) {
			$norm  = F365_Statorium_Matcher::normalize( (string) $post->post_title );
			$names = array_unique( array_filter( [ $norm, $aliases[ $norm ] ?? '' ] ) );
			$in    = "'" . implode( "','", array_map( 'esc_sql', $names ) ) . "'";

			$candidates = $wpdb->get_col( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				   JOIN {$wpdb->postmeta} nm ON nm.post_id = p.ID AND nm.meta_key = %s AND nm.meta_value IN ({$in})
				  WHERE p.post_type = 'f365_team' AND p.post_status = 'publish'
				    AND p.ID != %d AND ( p.api_source IS NULL OR p.api_id IS NULL )
				  LIMIT 5",
				F365_Statorium_Matcher::NORM_KEY, (int) $post->ID
			) );
			$candidates = array_values( array_filter( $candidates, fn( $id ) => $this->is_legacy( (int) $id ) ) );

			if ( 1 === count( $candidates ) ) {
				if ( $this->merge_team( (int) $post->ID, (int) $candidates[0] ) ) {
					$merged[] = [ (int) $post->ID, (int) $candidates[0], (string) $post->post_title ];
				}
			} elseif ( count( $candidates ) > 1 ) {
				$ambiguous++;
				$this->log( 'AUTOMERGE ambiguous: "' . $post->post_title . '" matches ' . count( $candidates ) . ' legacy teams — resolve manually (merge-team)' );
			} else {
				// Zero exact-name candidates — a real club very often carries
				// a fuller/shorter name per source ("Frosinone" vs "Frosinone
				// Calcio SRL", "AC Monza" vs "Monza"), which no exact string
				// match will ever unify. team_fuzzy_merge_candidate() applies
				// a conservative fuzzy comparison (see its own docblock) and
				// only ever returns a single, safe candidate.
				$fuzzy = $this->team_fuzzy_merge_candidate( (int) $post->ID, (string) $post->post_title );
				if ( $fuzzy > 0 ) {
					if ( $this->merge_team( (int) $post->ID, $fuzzy ) ) {
						$merged[] = [ (int) $post->ID, $fuzzy, (string) $post->post_title . ' (fuzzy match)' ];
					}
				} elseif ( -1 === $fuzzy ) {
					$ambiguous++;
				}
			}
		}

		return [ $merged, $ambiguous ];
	}

	/**
	 * Fuzzy fallback for auto_merge_duplicate_teams() — finds a legacy team
	 * whose name is the created team's name plus/minus a clean prefix or
	 * suffix (never a match found only somewhere in the middle), where the
	 * added part is empty or a bare number (a founding year — "Como" vs
	 * "Como 1907"). Deliberately NOT plain substring containment: that
	 * naively matched "FC Barcelona" to Espanyol's actual legal name "Real
	 * Club Deportivo Español Barcelona" (Barcelona is the city, not the
	 * club) and "FC Bayern München" to "1860 München" (their real
	 * cross-town rival) — both real near-misses hit while designing this.
	 * Requires the SAME federation_id as a corroborating signal (rules out
	 * two same-named clubs in different countries) and only ever returns a
	 * candidate when it's the single, unambiguous one — any tie is left
	 * alone rather than guessed, same policy as every other auto-merge path
	 * in this file.
	 */
	private function team_fuzzy_merge_candidate( int $created_wp, string $created_title ): int {
		global $wpdb;
		$created_pub = (int) get_post_meta( $created_wp, 'django_id', true );
		if ( ! $created_pub ) {
			return 0;
		}
		$created_fed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT federation_id FROM {$wpdb->prefix}team_team WHERE id = %d", $created_pub ) );
		$created_core = self::team_name_core( $created_title );
		if ( strlen( $created_core ) < 4 ) {
			return 0;
		}

		// federation_id is a data-population gap on some created rows (found
		// live: a team discovered only through the transfers feed, which
		// never sets it — "Rangers FC" sat at federation_id=0 forever,
		// permanently blocking this whole path even though its name match
		// to legacy "Rangers" is exact). Without that corroborating signal
		// this falls back to an EXACT-core-only comparison (no leftover
		// allowed) across ALL federations — strictly safer than the normal
		// fuzzy path to compensate for losing the federation check, not
		// equally permissive.
		if ( $created_fed ) {
			$legacy = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.ID, t.shortname, t.longname, t.stadium_id FROM {$wpdb->posts} p
				   JOIN {$wpdb->prefix}team_team t ON t.id = p.legacy_id
				  WHERE p.post_type = 'f365_team' AND p.post_status = 'publish'
				    AND ( p.api_source IS NULL OR p.api_id IS NULL )
				    AND t.federation_id = %d",
				$created_fed
			) );
		} else {
			$legacy = $wpdb->get_results(
				"SELECT p.ID, t.shortname, t.longname, t.stadium_id FROM {$wpdb->posts} p
				   JOIN {$wpdb->prefix}team_team t ON t.id = p.legacy_id
				  WHERE p.post_type = 'f365_team' AND p.post_status = 'publish'
				    AND ( p.api_source IS NULL OR p.api_id IS NULL )"
			);
		}

		$candidates = [];
		foreach ( $legacy as $l ) {
			foreach ( [ $l->shortname, $l->longname ] as $variant ) {
				if ( '' === (string) $variant ) {
					continue;
				}
				$variant_core = self::team_name_core( (string) $variant );
				$matched      = $created_fed
					? self::team_fuzzy_names_match( $created_core, $variant_core )
					: $created_core === $variant_core;
				if ( $matched ) {
					$candidates[ (int) $l->ID ] = (int) $l->stadium_id;
					break;
				}
			}
		}

		if ( 1 === count( $candidates ) ) {
			return (int) array_key_first( $candidates );
		}
		if ( count( $candidates ) > 1 ) {
			// Tie-break: a small unrelated club's full legal name can
			// coincidentally reduce to the exact same core as a big club's
			// after generic-word stripping (found live: "Clube Futebol
			// Benfica" → "benfica", same as "Sport Lisboa e Benfica" →
			// "benfica"). A shared home stadium is a second, independent
			// signal that doesn't have that failure mode — only act when
			// EXACTLY one candidate shares the created team's own stadium.
			$created_stadium = (int) $wpdb->get_var( $wpdb->prepare( "SELECT stadium_id FROM {$wpdb->prefix}team_team WHERE id = %d", $created_pub ) );
			if ( $created_stadium ) {
				$stadium_matches = array_keys( array_filter( $candidates, fn( $sid ) => $sid === $created_stadium ) );
				if ( 1 === count( $stadium_matches ) ) {
					$this->log( 'AUTOMERGE fuzzy tie-break: "' . $created_title . '" — ' . count( $candidates ) . ' name candidates, resolved to wp_id=' . $stadium_matches[0] . ' by shared stadium_id=' . $created_stadium );
					return (int) $stadium_matches[0];
				}
			}
			$this->log( 'AUTOMERGE fuzzy ambiguous: "' . $created_title . '" matches ' . count( $candidates ) . ' legacy teams by fuzzy name — resolve manually (merge-team)' );
			return -1; // ambiguous — distinct from 0 (no candidate at all) so the caller's counter is accurate
		}
		return 0;
	}

	/** Filterable generic club-type words stripped before fuzzy team-name comparison — see team_fuzzy_merge_candidate(). */
	private static function team_generic_words(): array {
		return apply_filters( 'f365_statorium_team_generic_words', [
			'fc', 'ac', 'cf', 'sc', 'sl', 'as', 'us', 'cd', 'ud', 'rc', 'rcd', 'afc', 'ssc', 'ssd',
			'jk', 'sk', 'fk', 'nk', 'hnk', 'ogc', 'aj', 'sv', 'vfl', 'vfb', 'tsv', 'fsv', 'bv',
			'club', 'clube', 'calcio', 'football', 'futebol', 'fussball', 'futbol', 'futbolo',
			'real', 'deportivo', 'atletico', 'sporting', 'sport', 'union', 'uniao', 'associacao',
			'sad', 'srl', 'spa', 'plc', 'gmbh', 'ev', 'kulubu', 'kulup', 'klub', 'klubu', 'spor',
			'olympique', 'fotbal', 'fudbalski', 'nogometni', 'hrvatski', 'profesionalen', 'futbolen', 'professional',
		] );
	}

	/** Normalized, generic-word-stripped, space-collapsed core of a team name — see team_fuzzy_merge_candidate(). */
	private static function team_name_core( string $s ): string {
		$norm    = F365_Statorium_Matcher::normalize( $s );
		$generic = self::team_generic_words();
		$words   = array_filter( explode( ' ', $norm ), fn( $w ) => '' !== $w && ! in_array( $w, $generic, true ) );
		return implode( '', $words );
	}

	/** True if $a and $b are the same core, or one is the other plus a clean, noise-only (empty/numeric) prefix or suffix. */
	private static function team_fuzzy_names_match( string $a, string $b ): bool {
		if ( $a === $b ) {
			return true;
		}
		if ( strlen( $a ) < 4 || strlen( $b ) < 4 ) {
			return false;
		}
		$leftover = null;
		if ( str_starts_with( $a, $b ) ) {
			$leftover = substr( $a, strlen( $b ) );
		} elseif ( str_starts_with( $b, $a ) ) {
			$leftover = substr( $b, strlen( $a ) );
		} elseif ( str_ends_with( $a, $b ) ) {
			$leftover = substr( $a, 0, strlen( $a ) - strlen( $b ) );
		} elseif ( str_ends_with( $b, $a ) ) {
			$leftover = substr( $b, 0, strlen( $b ) - strlen( $a ) );
		} else {
			return false;
		}
		return '' === $leftover || ctype_digit( $leftover );
	}

	/**
	 * Merge a Statorium-CREATED team into an existing legacy team (duplicate
	 * under a name variant, e.g. pt-BR "Irã" vs legacy pt-PT "Irão").
	 * Repoints every reference (mirror teamsteps, match meta, players'
	 * current_team, api identity, logo file) to the legacy post, then deletes
	 * the duplicate. The legacy post itself only gains additive keys.
	 * Returns true on success.
	 */
	public function merge_team( int $created_wp, int $legacy_wp ): bool {
		global $wpdb;

		if ( $this->is_legacy( $created_wp ) || ! $this->is_legacy( $legacy_wp ) ) {
			$this->log( "MERGE abort: {$created_wp} must be statorium-created and {$legacy_wp} must be legacy" );
			return false;
		}

		$created_pub = (int) get_post_meta( $created_wp, 'django_id', true );
		$legacy_pub  = (int) get_post_meta( $legacy_wp, 'django_id', true );
		$stat_id     = self::api_id_of( $created_wp );
		if ( ! $created_pub || ! $legacy_pub || ! $stat_id ) {
			$this->log( "MERGE abort: missing ids (created_pub={$created_pub} legacy_pub={$legacy_pub} stat={$stat_id})" );
			return false;
		}

		// 1. Mirror tables: fold our teamsteps into the legacy team's own —
		//    NOT a blind repoint. A blind "UPDATE team_teamstep SET team_id
		//    = legacy WHERE team_id = created" (the old behaviour here)
		//    creates a fresh EXACT-duplicate teamstep every time the legacy
		//    team already has its own row for that same (edition, step,
		//    modality, gender) — which an actively-tracked club almost
		//    always does for the current season. That's the real, ongoing
		//    source of the "same club/season appears twice" bug on player
		//    pages — it fires on every merge, not just a one-off historical
		//    artifact repair_duplicate_teamsteps() cleans up after the
		//    fact. Same fold pattern already used in repair_duplicate_
		//    teamsteps() / merge_competition() / repair_group_pairing():
		//    when the legacy team already owns an equivalent teamstep,
		//    repoint every reference to THAT one and delete ours; only
		//    plain-repoint when there's genuinely nothing to collide with.
		$our_steps = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, edition, step, modality, gender FROM {$wpdb->prefix}team_teamstep WHERE team_id = %d",
			$created_pub
		) );
		foreach ( $our_steps as $our ) {
			$our_ts    = (int) $our->id;
			$legacy_ts = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}team_teamstep
				  WHERE team_id = %d AND edition = %s AND step = %s AND modality = %s AND gender = %s
				  ORDER BY id ASC LIMIT 1",
				$legacy_pub, $our->edition, $our->step, $our->modality, $our->gender
			) );
			if ( $legacy_ts && $legacy_ts !== $our_ts ) {
				foreach ( [ 'competition_matchplayer' => 'team_id', 'team_squad' => 'team_id' ] as $table => $col ) {
					$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}{$table} SET {$col} = %d WHERE {$col} = %d", $legacy_ts, $our_ts ) );
				}
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}competition_match SET home_team_id = %d WHERE home_team_id = %d", $legacy_ts, $our_ts ) );
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}competition_match SET visitor_team_id = %d WHERE visitor_team_id = %d", $legacy_ts, $our_ts ) );
				$wpdb->query( $wpdb->prepare( "UPDATE IGNORE {$wpdb->prefix}transfer_transfer SET team_origin_id = %d WHERE team_origin_id = %d", $legacy_ts, $our_ts ) );
				$wpdb->query( $wpdb->prepare( "UPDATE IGNORE {$wpdb->prefix}transfer_transfer SET team_destination_id = %d WHERE team_destination_id = %d", $legacy_ts, $our_ts ) );
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}competition_competition SET first_place_teamstep_id = %d WHERE first_place_teamstep_id = %d", $legacy_ts, $our_ts ) );
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}competition_competition SET second_place_teamstep_id = %d WHERE second_place_teamstep_id = %d", $legacy_ts, $our_ts ) );
				// Participants: repoint then drop the now-duplicate (competition,
				// team) row — bug fixed 2026-08-06. This table has no unique
				// constraint on (competition_id, team_id) to lean on (confirmed:
				// only single-column indexes), so a blind repoint alone can leave
				// two rows for the same competition; same fold-then-dedupe pattern
				// repair_duplicate_teamsteps() already used for this exact table.
				// Omitting this repoint was the actual bug: competition_participant
				// is what the team "Jogos" page's Game Summary box reads (via
				// f365-team-jogos.php's own correct teamstep resolution) to list
				// which competitions a team is in — a merge that folded the
				// created team's teamstep away without repointing this left the
				// surviving legacy teamstep with NO participant row for that
				// competition, so Game Summary silently dropped it even though
				// competition_match rows (repointed above) kept the fixtures
				// themselves visible on the competition page.
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}competition_participant SET team_id = %d WHERE team_id = %d", $legacy_ts, $our_ts ) );
				$wpdb->query( $wpdb->prepare(
					"DELETE p1 FROM {$wpdb->prefix}competition_participant p1
					   JOIN {$wpdb->prefix}competition_participant p2
					     ON p2.competition_id = p1.competition_id AND p2.team_id = p1.team_id AND p2.id < p1.id
					  WHERE p1.team_id = %d", $legacy_ts
				) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}team_teamstep WHERE id = %d", $our_ts ) );
			} else {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}team_teamstep SET team_id = %d WHERE id = %d", $legacy_pub, $our_ts ) );
			}
		}
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}team_team WHERE id = %d AND id >= %d",
			$created_pub, self::ID_OFFSET
		) );

		// 2. Postmeta references on matches/players.
		foreach ( [ 'home_team', 'home_teamstep', 'visitor_team', 'visitor_teamstep', 'current_team' ] as $key ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = %d WHERE meta_key = %s AND meta_value = %d",
				$legacy_wp, $key, $created_wp
			) );
		}
		foreach ( [ 'django_home_team_id', 'django_visitor_team_id' ] as $key ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = %d WHERE meta_key = %s AND meta_value = %d",
				$legacy_pub, $key, $created_pub
			) );
		}

		// 3. Display names on mirror match rows → legacy title.
		$legacy_title = get_the_title( $legacy_wp );
		$steps = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}team_teamstep WHERE team_id = %d", $legacy_pub ) );
		if ( $steps ) {
			$in = implode( ',', array_map( 'intval', $steps ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}competition_match SET home_team_display_name = %s WHERE home_team_id IN ({$in})", $legacy_title ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}competition_match SET visitor_team_display_name = %s WHERE visitor_team_id IN ({$in})", $legacy_title ) );
		}

		// 4. API identity moves to the legacy post (additive only).
		$this->map_set( 'team', $stat_id, $legacy_wp );
		update_post_meta( $legacy_wp, 'statorium_team_id', $stat_id );
		update_post_meta( $legacy_wp, '_f365_statorium_linked', 1 );

		// 5. Logo file under the legacy public id (never overwrite).
		$media = trailingslashit( ABSPATH ) . 'media/images/logotipo/100x100/';
		if ( file_exists( $media . $created_pub . '.gif' ) && ! file_exists( $media . $legacy_pub . '.gif' ) ) {
			@copy( $media . $created_pub . '.gif', $media . $legacy_pub . '.gif' );
		}

		// 6. Remove the duplicate post + its cache.
		wp_delete_post( $created_wp, true );
		if ( class_exists( 'F365_Cache_Store' ) ) {
			F365_Cache_Store::purge_prefix( 'equipa' );
			F365_Cache_Store::purge_prefix( 'jogo' );
		}

		$this->log( "MERGE team created_wp={$created_wp} (pub {$created_pub}) → legacy_wp={$legacy_wp} (pub {$legacy_pub}) \"{$legacy_title}\"" );
		return true;
	}

	/**
	 * Merge OUR imported competition (season) into an existing LEGACY
	 * competition that represents the same real-world tournament (e.g. our
	 * "FIFA World Cup 2026" → legacy "Mundial" 2026).
	 *
	 * - Legacy fixture rows are paired with our matches by team pair; their
	 *   empty scores/details are FILLED from our data (user-approved: the
	 *   skeletons exist to receive results). Their ids/URLs are kept.
	 * - Our knockout matches (no legacy counterpart) move into the legacy
	 *   competition keeping their ids.
	 * - Our created teams that duplicate legacy MIRROR team rows are re-keyed
	 *   to the legacy ids; our teamsteps fold into the legacy ones.
	 * - Our duplicate competition post/rows/fedcomp are removed; the api
	 *   identity moves to the legacy competition post.
	 *
	 * Returns a summary array.
	 */
	public function merge_competition( int $season_stat_id, int $legacy_comp_id ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$our_comp_wp  = $this->map_resolve( 'season', $season_stat_id );
		$our_comp_pub = $our_comp_wp ? (int) get_post_meta( $our_comp_wp, 'django_competition_id', true ) : 0;
		$legacy_wp    = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'django_competition_id' AND meta_value = %s LIMIT 1",
			(string) $legacy_comp_id
		) );
		// Already linked? (current builds auto-link seasons to the legacy
		// competition on import — there is no duplicate to merge). Not an
		// error: run the idempotent fixture-pairing pass for safety and
		// report success.
		if ( $our_comp_wp && (int) $our_comp_wp === $legacy_wp && $our_comp_pub === $legacy_comp_id ) {
			$paired = $this->repair_group_pairing( $legacy_comp_id );
			$this->log( "CMERGE noop: season {$season_stat_id} is already linked to legacy competition {$legacy_comp_id} (post {$legacy_wp}); pairing pass touched {$paired} fixture(s)." );
			return [ 'already_linked' => true, 'paired' => $paired ];
		}

		if ( ! $our_comp_wp || ! $our_comp_pub || ! $legacy_wp || $our_comp_pub < self::ID_OFFSET ) {
			$this->log( "CMERGE abort: our_comp_wp={$our_comp_wp} pub={$our_comp_pub} legacy_wp={$legacy_wp}" );
			return [ 'error' => 'preconditions failed' ];
		}

		$sum = [ 'paired' => 0, 'moved' => 0, 'teams_rekeyed' => 0, 'ts_folded' => 0 ];

		// ── 1. Load both match sets with team names ─────────────────────────
		$sql_matches = "SELECT m.id, m.date_time, m.stats_id, m.stadium_id, m.home_team_id hts, m.visitor_team_id vts,
			       ht.team_id h_team, vt.team_id v_team, htm.shortname h_name, vtm.shortname v_name
			  FROM {$p}competition_match m
			  JOIN {$p}team_teamstep ht ON ht.id = m.home_team_id
			  JOIN {$p}team_teamstep vt ON vt.id = m.visitor_team_id
			  JOIN {$p}team_team htm ON htm.id = ht.team_id
			  JOIN {$p}team_team vtm ON vtm.id = vt.team_id
			 WHERE m.competition_id = %d";
		$legacy_rows = $wpdb->get_results( $wpdb->prepare( $sql_matches, $legacy_comp_id ) );
		$our_rows    = $wpdb->get_results( $wpdb->prepare( $sql_matches, $our_comp_pub ) );

		$norm = fn( $s ) => F365_Statorium_Matcher::normalize( (string) $s );
		$legacy_by_pair = [];
		foreach ( $legacy_rows as $r ) {
			$legacy_by_pair[ $norm( $r->h_name ) . '|' . $norm( $r->v_name ) ] = $r;
		}

		// ── 2. Team + teamstep mapping (ours → legacy) from paired fixtures ─
		$team_map = [];
		$ts_map   = [];
		foreach ( $our_rows as $o ) {
			$key = $norm( $o->h_name ) . '|' . $norm( $o->v_name );
			$l   = $legacy_by_pair[ $key ] ?? null;
			if ( ! $l ) {
				continue;
			}
			$team_map[ (int) $o->h_team ] = (int) $l->h_team;
			$team_map[ (int) $o->v_team ] = (int) $l->v_team;
			$ts_map[ (int) $o->hts ]      = (int) $l->hts;
			$ts_map[ (int) $o->vts ]      = (int) $l->vts;
		}

		// ── 3. Re-key our CREATED teams that duplicate legacy mirror rows ───
		foreach ( $team_map as $our_tid => $legacy_tid ) {
			if ( $our_tid === $legacy_tid || $our_tid < self::ID_OFFSET ) {
				continue; // already the same legacy team
			}
			$team_wp = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'django_id' AND meta_value = %s
				  AND post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = 'f365_team' ) LIMIT 1",
				(string) $our_tid
			) );
			if ( $team_wp ) {
				update_post_meta( $team_wp, 'django_id', $legacy_tid );
				$wpdb->update( $wpdb->posts, [ 'legacy_id' => $legacy_tid ], [ 'ID' => $team_wp ] );
				clean_post_cache( $team_wp );
				foreach ( [ 'django_home_team_id', 'django_visitor_team_id' ] as $k ) {
					$wpdb->query( $wpdb->prepare(
						"UPDATE {$wpdb->postmeta} SET meta_value = %d WHERE meta_key = %s AND meta_value = %d",
						$legacy_tid, $k, $our_tid
					) );
				}
			}
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}team_teamstep SET team_id = %d WHERE team_id = %d", $legacy_tid, $our_tid ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}team_team WHERE id = %d AND id >= %d", $our_tid, self::ID_OFFSET ) );

			$media = trailingslashit( ABSPATH ) . 'media/images/logotipo/100x100/';
			if ( file_exists( $media . $our_tid . '.gif' ) && ! file_exists( $media . $legacy_tid . '.gif' ) ) {
				@copy( $media . $our_tid . '.gif', $media . $legacy_tid . '.gif' );
			}
			$this->log( "CMERGE team re-key {$our_tid} → {$legacy_tid}" );
			$sum['teams_rekeyed']++;
		}

		// ── 4. Fold our teamsteps into the legacy ones ──────────────────────
		foreach ( $ts_map as $our_ts => $legacy_ts ) {
			if ( $our_ts === $legacy_ts ) {
				continue;
			}
			foreach ( [ 'competition_matchplayer' => 'team_id', 'team_squad' => 'team_id' ] as $table => $col ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$p}{$table} SET {$col} = %d WHERE {$col} = %d", $legacy_ts, $our_ts ) );
			}
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}competition_match SET home_team_id = %d WHERE home_team_id = %d", $legacy_ts, $our_ts ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}competition_match SET visitor_team_id = %d WHERE visitor_team_id = %d", $legacy_ts, $our_ts ) );
			// Same repoint repair_duplicate_teamsteps() already does before
			// deleting a folded teamstep — missing here let a transfer row
			// survive pointing at a teamstep this same fold was about to
			// delete, so the next sync's ensure_teamstep() found nothing,
			// created a fresh one, and import_transfers()'s dedupe check
			// (keyed on team_destination_id) never matched the orphaned old
			// row — a brand new "duplicate" transfer every cycle, forever.
			$wpdb->query( $wpdb->prepare( "UPDATE IGNORE {$p}transfer_transfer SET team_origin_id = %d WHERE team_origin_id = %d", $legacy_ts, $our_ts ) );
			$wpdb->query( $wpdb->prepare( "UPDATE IGNORE {$p}transfer_transfer SET team_destination_id = %d WHERE team_destination_id = %d", $legacy_ts, $our_ts ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}competition_competition SET first_place_teamstep_id = %d WHERE first_place_teamstep_id = %d", $legacy_ts, $our_ts ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}competition_competition SET second_place_teamstep_id = %d WHERE second_place_teamstep_id = %d", $legacy_ts, $our_ts ) );
			// Same repoint-then-dedupe merge_team() needed (bug fixed
			// 2026-08-06) — competition_participant is what the team "Jogos"
			// page's Game Summary box reads to list a team's competitions;
			// omitting this left it silently empty for any competition whose
			// only participant row was under the teamstep this fold deletes.
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}competition_participant SET team_id = %d WHERE team_id = %d", $legacy_ts, $our_ts ) );
			$wpdb->query( $wpdb->prepare(
				"DELETE cp1 FROM {$p}competition_participant cp1
				   JOIN {$p}competition_participant cp2
				     ON cp2.competition_id = cp1.competition_id AND cp2.team_id = cp1.team_id AND cp2.id < cp1.id
				  WHERE cp1.team_id = %d", $legacy_ts
			) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}team_teamstep WHERE id = %d", $our_ts ) );
			$sum['ts_folded']++;
		}

		// ── 5. Transplant paired matches into the legacy fixture rows ───────
		foreach ( $our_rows as $o ) {
			$key = $norm( $o->h_name ) . '|' . $norm( $o->v_name );
			$l   = $legacy_by_pair[ $key ] ?? null;
			$our_mid = (int) $o->id;

			if ( $l ) {
				$legacy_mid = (int) $l->id;
				$our_full   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}competition_match WHERE id = %d", $our_mid ) );

				$wpdb->query( $wpdb->prepare(
					"UPDATE {$p}competition_match
					    SET date_time = %s, home_score = " . self::sqlv( $our_full->home_score ) . ', visitor_score = ' . self::sqlv( $our_full->visitor_score ) . ',
					        home_score_extra = ' . self::sqlv( $our_full->home_score_extra ) . ', visitor_score_extra = ' . self::sqlv( $our_full->visitor_score_extra ) . ',
					        situation = %s, assistance = %s, duration = %s,
					        stadium_id = COALESCE( ' . ( (int) $our_full->stadium_id ?: 'NULL' ) . ", stadium_id ),
					        stats_id = COALESCE( " . ( (int) $our_full->stats_id ?: 'NULL' ) . ", stats_id ),
					        home_team_display_name = %s, visitor_team_display_name = %s
					  WHERE id = %d",
					(string) $our_full->date_time, (string) $our_full->situation,
					(string) $our_full->assistance, (string) $our_full->duration,
					(string) $our_full->home_team_display_name, (string) $our_full->visitor_team_display_name,
					$legacy_mid
				) );

				$wpdb->query( $wpdb->prepare( "UPDATE {$p}competition_matchplayer SET match_id = %d WHERE match_id = %d", $legacy_mid, $our_mid ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}competition_match WHERE id = %d", $our_mid ) );

				// WP post: keep legacy post if it exists, else re-key ours.
				$our_post    = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'django_match_id' AND meta_value = %s LIMIT 1", (string) $our_mid
				) );
				$legacy_post = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'django_match_id' AND meta_value = %s AND post_id != %d LIMIT 1",
					(string) $legacy_mid, $our_post
				) );

				if ( $our_post ) {
					$stat_mid = self::api_id_of( $our_post );
					if ( $legacy_post ) {
						update_post_meta( $legacy_post, '_f365_statorium_linked', 1 );
						$wpdb->update( $wpdb->posts, [ 'api_source' => self::API_SOURCE, 'api_id' => $stat_mid ], [ 'ID' => $legacy_post ] );
						wp_delete_post( $our_post, true );
						clean_post_cache( $legacy_post );
					} else {
						update_post_meta( $our_post, 'django_match_id', $legacy_mid );
						update_post_meta( $our_post, 'django_competition_id', $legacy_comp_id );
						update_post_meta( $our_post, 'competition', $legacy_wp );
						$wpdb->update( $wpdb->posts, [ 'legacy_id' => $legacy_mid ], [ 'ID' => $our_post ] );
						clean_post_cache( $our_post );
					}
				}
				$sum['paired']++;
			} else {
				// Knockout match — move into the legacy competition, keep id.
				$wpdb->query( $wpdb->prepare( "UPDATE {$p}competition_match SET competition_id = %d WHERE id = %d", $legacy_comp_id, $our_mid ) );
				$our_post = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'django_match_id' AND meta_value = %s LIMIT 1", (string) $our_mid
				) );
				if ( $our_post ) {
					update_post_meta( $our_post, 'django_competition_id', $legacy_comp_id );
					update_post_meta( $our_post, 'competition', $legacy_wp );
				}
				$sum['moved']++;
			}
		}

		// ── 6. Participants + squads under the legacy competition ───────────
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}competition_participant WHERE competition_id = %d", $our_comp_pub ) );
		$legacy_ts_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT home_team_id FROM {$p}competition_match WHERE competition_id = %d
			 UNION SELECT DISTINCT visitor_team_id FROM {$p}competition_match WHERE competition_id = %d",
			$legacy_comp_id, $legacy_comp_id
		) );
		foreach ( $legacy_ts_ids as $ts ) {
			$this->tables()->ensure_participant( (int) $ts, $legacy_comp_id );
		}
		$wpdb->query( $wpdb->prepare(
			"DELETE sc FROM {$p}team_squadcompetition sc WHERE sc.competition_id = %d", $our_comp_pub
		) );
		$this->tables()->rebuild_squads( $legacy_comp_id );

		// ── 7. Retire our duplicate competition + fedcomp ───────────────────
		$fedcomp_pub = (int) get_post_meta( $our_comp_wp, 'federation_competition_id', true );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}competition_competition WHERE id = %d AND id >= %d", $our_comp_pub, self::ID_OFFSET ) );
		if ( $fedcomp_pub >= self::ID_OFFSET ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}federation_federationcompetition WHERE id = %d", $fedcomp_pub ) );
		}

		// API identity → legacy competition post (additive keys only).
		foreach ( [ 'statorium_season_id', 'statorium_league_id', 'statorium_groups' ] as $k ) {
			$v = get_post_meta( $our_comp_wp, $k, true );
			if ( '' !== $v && null !== $v ) {
				update_post_meta( $legacy_wp, $k, $v );
			}
		}
		update_post_meta( $legacy_wp, '_f365_statorium_linked', 1 );
		$this->map_set( 'season', $season_stat_id, $legacy_wp );
		wp_delete_post( $our_comp_wp, true );

		if ( class_exists( 'F365_Cache_Store' ) ) {
			F365_Cache_Store::purge_all();
		}

		$this->log( 'CMERGE done: ' . wp_json_encode( $sum ) );
		return $sum;
	}

	/** SQL literal for nullable numeric mirror values. */
	private static function sqlv( $v ): string {
		return ( null === $v || '' === $v ) ? 'NULL' : (string) (int) $v;
	}

	/**
	 * Re-run matching for pending review rows (after scorer improvements —
	 * e.g. mirror-table birthdates). Confident matches auto-link; the rest
	 * re-queue. Returns [linked, still_pending].
	 */
	public function rescore_reviews(): array {
		global $wpdb;
		$table = F365_Statorium_Matcher::review_table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'pending' ORDER BY id" );

		$type_post = [
			'season' => 'f365_competition', 'team' => 'f365_team', 'player' => 'f365_player',
			'match'  => 'f365_match', 'venue' => 'f365_stadium',
		];

		$linked = 0;
		foreach ( (array) $rows as $r ) {
			$signals = json_decode( (string) $r->payload, true );
			if ( ! is_array( $signals ) || empty( $type_post[ $r->entity_type ] ) ) {
				continue;
			}
			if ( ! empty( $signals['country'] ) ) {
				$signals['country'] = self::country_pt( (string) $signals['country'] );
			}

			// Clear the row so find() can decide fresh (it re-queues if still unsure).
			$wpdb->delete( $table, [ 'id' => (int) $r->id ] );

			$found = $this->matcher->find( (string) $r->entity_type, $type_post[ $r->entity_type ], (int) $r->stat_id, $signals );
			if ( 'link' === $found['verdict'] ) {
				$wp_id = (int) $found['wp_id'];
				$this->map_set( (string) $r->entity_type, (int) $r->stat_id, $wp_id );
				update_post_meta( $wp_id, '_f365_statorium_linked', 1 );
				update_post_meta( $wp_id, '_f365_match_method', $found['method'] . ' (score ' . $found['score'] . ', rescored)' );
				$this->log( "RESCORE link {$r->entity_type} stat_id={$r->stat_id} \"{$r->label}\" → wp_id={$wp_id} via {$found['method']}" );
				$linked++;
			}
		}

		$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
		return [ $linked, $pending ];
	}

	/**
	 * Pair leftover OUR-range group rows inside a legacy competition with its
	 * still-empty fixture rows — alias-aware and order-insensitive (Statorium
	 * and legacy sometimes disagree on the home side of neutral-venue games;
	 * Statorium's orientation wins). Runs after merge_competition; reusable
	 * for future merges. Returns paired count.
	 */
	/**
	 * Pair created match rows into pre-loaded legacy fixtures across EVERY
	 * legacy-linked competition.
	 *
	 * import_matches (season calendar) pairs at the end of its run, but the
	 * day-cursor paths — backfill / today, i.e. what `sync` runs in backfill
	 * mode — call import_match_from_payload directly and never paired. Each
	 * day's fixtures were therefore created alongside the legacy skeleton of
	 * the same game (calendário showed both: "México 2-0 África do Sul" next
	 * to "México vs África do Sul"). Running the sweep every sync makes the
	 * duplicates fold themselves, whichever path imported them.
	 */
	public function pair_legacy_fixtures(): int {
		global $wpdb;
		$comps = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT competition_id FROM {$wpdb->prefix}competition_match
			  WHERE id >= %d AND competition_id > 0 AND competition_id < %d",
			self::ID_OFFSET, self::ID_OFFSET
		) );
		$total = 0;
		foreach ( $comps as $comp ) {
			$total += $this->repair_group_pairing( (int) $comp );
			// Historical/completed legacy fixtures (scores already present) are
			// invisible to the group pairing — merge fixture-inferred duplicate
			// clubs first, then fold the duplicate fixtures.
			$this->repair_fixture_team_twins( (int) $comp );
			$total += $this->repair_duplicate_fixtures( (int) $comp );
		}
		if ( $total ) {
			$this->log( "PAIRSWEEP folded {$total} created fixture(s) into legacy rows across " . count( $comps ) . ' competition(s)' );
			if ( class_exists( 'F365_Cache_Store' ) ) {
				F365_Cache_Store::purge_prefix( 'competicao' );
			}
		}
		return $total;
	}

	public function repair_group_pairing( int $legacy_comp_id ): int {
		global $wpdb;
		$p = $wpdb->prefix;

		$aliases = F365_Statorium_Matcher::name_aliases();
		$canon   = function ( $s ) use ( $aliases ) {
			$n = F365_Statorium_Matcher::normalize( (string) $s );
			return $aliases[ $n ] ?? $n;
		};

		$sql = "SELECT m.id, m.home_team_id hts, m.visitor_team_id vts, ht.team_id h_team, vt.team_id v_team,
		               htm.shortname h_name, vtm.shortname v_name
		          FROM {$p}competition_match m
		          JOIN {$p}team_teamstep ht ON ht.id = m.home_team_id
		          JOIN {$p}team_teamstep vt ON vt.id = m.visitor_team_id
		          JOIN {$p}team_team htm ON htm.id = ht.team_id
		          JOIN {$p}team_team vtm ON vtm.id = vt.team_id
		         WHERE m.competition_id = %d AND ";

		$ours   = $wpdb->get_results( $wpdb->prepare( $sql . 'm.id >= %d AND ( m.phase = "" OR m.phase IS NULL )', $legacy_comp_id, self::ID_OFFSET ) );
		$legacy = $wpdb->get_results( $wpdb->prepare( $sql . 'm.id < %d AND m.home_score IS NULL', $legacy_comp_id, self::ID_OFFSET ) );

		$legacy_by_key = [];
		foreach ( $legacy as $l ) {
			$key = implode( '|', [ $canon( $l->h_name ), $canon( $l->v_name ) ] );
			$legacy_by_key[ $key ] = $l;
			$legacy_by_key[ implode( '|', [ $canon( $l->v_name ), $canon( $l->h_name ) ] ) ] = $l; // unordered
		}

		$paired = 0;
		foreach ( $ours as $o ) {
			$key = $canon( $o->h_name ) . '|' . $canon( $o->v_name );
			$l   = $legacy_by_key[ $key ] ?? null;
			if ( ! $l ) {
				$this->log( "PAIR leftover unresolved: {$o->h_name} vs {$o->v_name}" );
				continue;
			}

			// Side mapping (handles flipped orientation).
			$flipped   = $canon( $o->h_name ) === $canon( $l->v_name );
			$map_pairs = $flipped
				? [ [ (int) $o->h_team, (int) $l->v_team, (int) $o->hts, (int) $l->vts ], [ (int) $o->v_team, (int) $l->h_team, (int) $o->vts, (int) $l->hts ] ]
				: [ [ (int) $o->h_team, (int) $l->h_team, (int) $o->hts, (int) $l->hts ], [ (int) $o->v_team, (int) $l->v_team, (int) $o->vts, (int) $l->vts ] ];

			foreach ( $map_pairs as [ $our_tid, $legacy_tid, $our_ts, $legacy_ts ] ) {
				// Re-key created teams to the legacy mirror team id.
				if ( $our_tid !== $legacy_tid && $our_tid >= self::ID_OFFSET ) {
					$team_wp = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'django_id' AND meta_value = %s
						  AND post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = 'f365_team' ) LIMIT 1",
						(string) $our_tid
					) );
					if ( $team_wp ) {
						update_post_meta( $team_wp, 'django_id', $legacy_tid );
						$wpdb->update( $wpdb->posts, [ 'legacy_id' => $legacy_tid ], [ 'ID' => $team_wp ] );
						clean_post_cache( $team_wp );
						foreach ( [ 'django_home_team_id', 'django_visitor_team_id' ] as $k ) {
							$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = %d WHERE meta_key = %s AND meta_value = %d", $legacy_tid, $k, $our_tid ) );
						}
					}
					$wpdb->query( $wpdb->prepare( "UPDATE {$p}team_teamstep SET team_id = %d WHERE team_id = %d", $legacy_tid, $our_tid ) );
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}team_team WHERE id = %d AND id >= %d", $our_tid, self::ID_OFFSET ) );
					$media = trailingslashit( ABSPATH ) . 'media/images/logotipo/100x100/';
					if ( file_exists( $media . $our_tid . '.gif' ) && ! file_exists( $media . $legacy_tid . '.gif' ) ) {
						@copy( $media . $our_tid . '.gif', $media . $legacy_tid . '.gif' );
					}
					$this->log( "PAIR team re-key {$our_tid} → {$legacy_tid}" );
				}
				// Fold our teamstep into the legacy one.
				if ( $our_ts !== $legacy_ts ) {
					$wpdb->query( $wpdb->prepare( "UPDATE {$p}competition_matchplayer SET team_id = %d WHERE team_id = %d", $legacy_ts, $our_ts ) );
					$wpdb->query( $wpdb->prepare( "UPDATE {$p}team_squad SET team_id = %d WHERE team_id = %d", $legacy_ts, $our_ts ) );
					$wpdb->query( $wpdb->prepare( "UPDATE {$p}competition_match SET home_team_id = %d WHERE home_team_id = %d", $legacy_ts, $our_ts ) );
					$wpdb->query( $wpdb->prepare( "UPDATE {$p}competition_match SET visitor_team_id = %d WHERE visitor_team_id = %d", $legacy_ts, $our_ts ) );
					// See the matching comment in merge_competition() — same
					// missing repoint, same "fresh duplicate transfer every
					// sync" symptom, same fix.
					$wpdb->query( $wpdb->prepare( "UPDATE IGNORE {$p}transfer_transfer SET team_origin_id = %d WHERE team_origin_id = %d", $legacy_ts, $our_ts ) );
					$wpdb->query( $wpdb->prepare( "UPDATE IGNORE {$p}transfer_transfer SET team_destination_id = %d WHERE team_destination_id = %d", $legacy_ts, $our_ts ) );
					$wpdb->query( $wpdb->prepare( "UPDATE {$p}competition_competition SET first_place_teamstep_id = %d WHERE first_place_teamstep_id = %d", $legacy_ts, $our_ts ) );
					$wpdb->query( $wpdb->prepare( "UPDATE {$p}competition_competition SET second_place_teamstep_id = %d WHERE second_place_teamstep_id = %d", $legacy_ts, $our_ts ) );
					// Same repoint-then-dedupe merge_team()/merge_competition()
					// needed (bug fixed 2026-08-06) — see merge_team()'s comment.
					$wpdb->query( $wpdb->prepare( "UPDATE {$p}competition_participant SET team_id = %d WHERE team_id = %d", $legacy_ts, $our_ts ) );
					$wpdb->query( $wpdb->prepare(
						"DELETE cp1 FROM {$p}competition_participant cp1
						   JOIN {$p}competition_participant cp2
						     ON cp2.competition_id = cp1.competition_id AND cp2.team_id = cp1.team_id AND cp2.id < cp1.id
						  WHERE cp1.team_id = %d", $legacy_ts
					) );
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}team_teamstep WHERE id = %d", $our_ts ) );
				}
			}

			// Transplant into the legacy fixture (Statorium orientation wins).
			$of = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}competition_match WHERE id = %d", (int) $o->id ) );
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$p}competition_match
				    SET date_time = %s, situation = %s, assistance = %s, duration = %s,
				        home_team_id = " . (int) $of->home_team_id . ', visitor_team_id = ' . (int) $of->visitor_team_id . ',
				        home_score = ' . self::sqlv( $of->home_score ) . ', visitor_score = ' . self::sqlv( $of->visitor_score ) . ',
				        home_score_extra = ' . self::sqlv( $of->home_score_extra ) . ', visitor_score_extra = ' . self::sqlv( $of->visitor_score_extra ) . ',
				        stadium_id = COALESCE( ' . ( (int) $of->stadium_id ?: 'NULL' ) . ", stadium_id ),
				        stats_id = COALESCE( " . ( (int) $of->stats_id ?: 'NULL' ) . ", stats_id ),
				        home_team_display_name = %s, visitor_team_display_name = %s
				  WHERE id = %d",
				(string) $of->date_time, (string) $of->situation, (string) $of->assistance, (string) $of->duration,
				(string) $of->home_team_display_name, (string) $of->visitor_team_display_name,
				(int) $l->id
			) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}competition_matchplayer SET match_id = %d WHERE match_id = %d", (int) $l->id, (int) $of->id ) );

			// Live rows: keep one per fixture, keyed to the legacy match id.
			// import_match_from_payload() gives every synced match a live row
			// unconditionally (jogos-em-direto coverage) — including "our"
			// duplicate before it gets folded here. Without this, the
			// wp_live_livematch/f365_livegames row is left pointing at the
			// match id this method is about to delete below (see
			// repair_duplicate_fixtures(), which folds the analogous
			// still-scored duplicate and already carries this exact block).
			$l_live = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}live_livematch WHERE match_id = %d", (int) $l->id ) );
			$o_live = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}live_livematch WHERE match_id = %d", (int) $of->id ) );
			if ( $o_live && ! $l_live ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$p}live_livematch SET match_id = %d WHERE id = %d", (int) $l->id, $o_live ) );
			} elseif ( $o_live && $l_live ) {
				$lp = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}live_livematchplayer WHERE live_match_id = %d", $o_live ) );
				if ( $lp ) {
					$wpdb->query( "DELETE FROM {$p}live_livematchplayerevent WHERE live_match_player_id IN (" . implode( ',', array_map( 'intval', $lp ) ) . ')' );
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}live_livematchplayer WHERE live_match_id = %d", $o_live ) );
				}
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}live_livematchevent WHERE live_match_id = %d", $o_live ) );
				$live_post = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'f365_livegames' AND legacy_id = %d AND legacy_table = 'wp_live_livematch' LIMIT 1", $o_live
				) );
				if ( $live_post ) {
					wp_delete_post( $live_post, true );
				}
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}live_livematch WHERE id = %d", $o_live ) );
			}

			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}competition_match WHERE id = %d", (int) $of->id ) );

			$our_post = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'django_match_id' AND meta_value = %s LIMIT 1", (string) $of->id
			) );
			$legacy_post = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'django_match_id' AND meta_value = %s AND post_id != %d LIMIT 1",
				(string) $l->id, $our_post
			) );
			if ( $our_post ) {
				$stat_mid = self::api_id_of( $our_post );
				if ( $legacy_post ) {
					update_post_meta( $legacy_post, '_f365_statorium_linked', 1 );
					$wpdb->update( $wpdb->posts, [ 'api_source' => self::API_SOURCE, 'api_id' => $stat_mid ], [ 'ID' => $legacy_post ] );
					wp_delete_post( $our_post, true );
					clean_post_cache( $legacy_post );
				} else {
					update_post_meta( $our_post, 'django_match_id', (int) $l->id );
					$wpdb->update( $wpdb->posts, [ 'legacy_id' => (int) $l->id ], [ 'ID' => $our_post ] );
					clean_post_cache( $our_post );
				}
			}
			$paired++;
		}

		if ( $paired ) {
			$this->tables()->rebuild_squads( $legacy_comp_id );
			if ( class_exists( 'F365_Cache_Store' ) ) {
				F365_Cache_Store::purge_all();
			}
		}
		$this->log( "PAIR leftovers resolved: {$paired}" );
		return $paired;
	}

	/**
	 * Infer created-team ↔ legacy-team twins from the fixture graph of one
	 * legacy competition and fold them with merge_team(). When a club was
	 * duplicated under a name variant the name index cannot bridge
	 * ("SC Braga" vs legacy "Braga"), its duplicate fixtures still anchor it:
	 * our row and the legacy row of the same game share the kickoff and ONE
	 * already-linked team — the remaining sides must be the same club. A
	 * merge needs ≥3 consistent co-occurrences, a dominant (3×) lead over
	 * any rival candidate, and a mutually exclusive claim — ambiguity is
	 * logged and left for manual merge-team, never guessed. Returns merges.
	 */
	public function repair_fixture_team_twins( int $legacy_comp_id ): int {
		global $wpdb;
		$p = $wpdb->prefix;

		$sel = "SELECT m.id, m.date_time, ht.team_id AS h_team, vt.team_id AS v_team
		          FROM {$p}competition_match m
		          JOIN {$p}team_teamstep ht ON ht.id = m.home_team_id
		          JOIN {$p}team_teamstep vt ON vt.id = m.visitor_team_id
		         WHERE m.competition_id = %d AND ";
		$ours   = $wpdb->get_results( $wpdb->prepare( $sel . 'm.id >= %d', $legacy_comp_id, self::ID_OFFSET ) );
		$legacy = $wpdb->get_results( $wpdb->prepare( $sel . 'm.id < %d', $legacy_comp_id, self::ID_OFFSET ) );
		if ( ! $ours || ! $legacy ) {
			return 0;
		}

		$by_day = [];
		foreach ( $legacy as $l ) {
			$by_day[ substr( (string) $l->date_time, 0, 10 ) ][] = $l;
		}

		$votes = [];
		$vote  = function ( int $created, int $legacy_team ) use ( &$votes ) {
			if ( $created >= self::ID_OFFSET && $legacy_team < self::ID_OFFSET ) {
				$votes[ $created ][ $legacy_team ] = ( $votes[ $created ][ $legacy_team ] ?? 0 ) + 1;
			}
		};

		foreach ( $ours as $o ) {
			$day        = substr( (string) $o->date_time, 0, 10 );
			$candidates = array_merge(
				$by_day[ $day ] ?? [],
				$by_day[ gmdate( 'Y-m-d', strtotime( $day . ' -1 day' ) ) ] ?? [],
				$by_day[ gmdate( 'Y-m-d', strtotime( $day . ' +1 day' ) ) ] ?? []
			);
			foreach ( $candidates as $l ) {
				$oh = (int) $o->h_team;
				$ov = (int) $o->v_team;
				$lh = (int) $l->h_team;
				$lv = (int) $l->v_team;
				if ( $oh === $lh && $ov !== $lv ) {
					$vote( $ov, $lv );
				} elseif ( $ov === $lv && $oh !== $lh ) {
					$vote( $oh, $lh );
				} elseif ( $oh === $lv && $ov !== $lh ) {
					$vote( $ov, $lh );
				} elseif ( $ov === $lh && $oh !== $lv ) {
					$vote( $oh, $lv );
				}
			}
		}

		// Resolve: dominant candidate per created team, mutually exclusive.
		$claimed = [];
		$mapping = [];
		foreach ( $votes as $created => $cands ) {
			arsort( $cands );
			$best_team  = (int) array_key_first( $cands );
			$best_votes = (int) $cands[ $best_team ];
			$runner_up  = count( $cands ) > 1 ? (int) array_values( $cands )[1] : 0;
			if ( $best_votes < 3 || ( $runner_up && $best_votes < 3 * $runner_up ) ) {
				$this->log( "TWIN ambiguous: created team {$created} candidates " . wp_json_encode( $cands ) . ' — merge manually (merge-team)' );
				continue;
			}
			if ( isset( $claimed[ $best_team ] ) ) {
				$this->log( "TWIN conflict: legacy team {$best_team} claimed by created {$claimed[$best_team]} AND {$created} — both left for manual merge" );
				unset( $mapping[ $claimed[ $best_team ] ] );
				continue;
			}
			$claimed[ $best_team ] = $created;
			$mapping[ $created ]   = $best_team;
		}

		$merged = 0;
		foreach ( $mapping as $created_pub => $legacy_pub ) {
			if ( $this->dry_run ) {
				$this->log( "TWIN (dry) created pub {$created_pub} → legacy pub {$legacy_pub}" );
				$merged++;
				continue;
			}
			$created_wp = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'f365_team' AND legacy_id = %d LIMIT 1", $created_pub
			) );
			$legacy_wp = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'f365_team' AND legacy_id = %d LIMIT 1", $legacy_pub
			) );
			if ( $created_wp && $legacy_wp && $this->merge_team( $created_wp, $legacy_wp ) ) {
				$merged++;
			}
		}
		if ( $merged && ! $this->dry_run ) {
			$this->log( "TWIN merged {$merged} duplicate club(s) inferred from comp {$legacy_comp_id} fixtures" );
		}
		return $merged;
	}

	/**
	 * Fold OUR duplicate match rows into COMPLETED legacy fixtures of the
	 * same competition. repair_group_pairing() only targets STILL-EMPTY
	 * legacy skeletons (its design case: pre-loaded fixtures awaiting
	 * results) — importing a historical season whose legacy rows already
	 * carry scores therefore duplicated every fixture. This variant pairs
	 * by ORDERED teamstep pair + closest kickoff (league double round-robins
	 * have both legs under the same unordered pair — the unordered name key
	 * the group pairing uses would cross-pair the legs), keeps the legacy
	 * row's identity/URL, replaces its (incomplete) detail rows with ours,
	 * folds the live rows and WP posts, and deletes the duplicate.
	 * Returns folded count.
	 */
	public function repair_duplicate_fixtures( int $legacy_comp_id ): int {
		global $wpdb;
		$p = $wpdb->prefix;

		// Key on TEAM ids, not teamstep ids: a club carries several teamsteps
		// per edition and our rows sometimes referenced a different step than
		// the legacy fixture (see ensure_teamstep's step filter fix).
		$sel = "SELECT m.id, m.date_time, m.home_team_id, m.visitor_team_id,
		               ht.team_id AS h_team, vt.team_id AS v_team
		          FROM {$p}competition_match m
		          JOIN {$p}team_teamstep ht ON ht.id = m.home_team_id
		          JOIN {$p}team_teamstep vt ON vt.id = m.visitor_team_id
		         WHERE m.competition_id = %d AND ";

		$ours = $wpdb->get_results( $wpdb->prepare( $sel . 'm.id >= %d', $legacy_comp_id, self::ID_OFFSET ) );
		if ( ! $ours ) {
			return 0;
		}

		$legacy  = $wpdb->get_results( $wpdb->prepare( $sel . 'm.id < %d', $legacy_comp_id, self::ID_OFFSET ) );
		$by_pair = [];
		foreach ( $legacy as $l ) {
			$by_pair[ $l->h_team . '|' . $l->v_team ][] = $l;
		}

		$pick = function ( array $candidates, string $dt, array &$used ) {
			$best      = null;
			$best_diff = PHP_INT_MAX;
			foreach ( $candidates as $c ) {
				if ( isset( $used[ (int) $c->id ] ) ) {
					continue;
				}
				$diff = abs( strtotime( (string) $c->date_time ) - strtotime( $dt ) );
				if ( $diff < $best_diff ) {
					$best_diff = $diff;
					$best      = $c;
				}
			}
			// A league's two same-pair legs sit months apart; 30 days keeps a
			// postponement matched while making leg cross-pairing impossible.
			return ( $best && $best_diff <= 30 * DAY_IN_SECONDS ) ? $best : null;
		};

		$used   = [];
		$folded = 0;
		foreach ( $ours as $o ) {
			$flipped = false;
			$l       = $pick( $by_pair[ $o->h_team . '|' . $o->v_team ] ?? [], (string) $o->date_time, $used );
			if ( ! $l ) {
				$l       = $pick( $by_pair[ $o->v_team . '|' . $o->h_team ] ?? [], (string) $o->date_time, $used );
				$flipped = (bool) $l;
			}
			if ( ! $l ) {
				$this->log( "FIXTURE DEDUP leftover: our match {$o->id} ({$o->date_time}) has no legacy counterpart in comp {$legacy_comp_id}" );
				continue;
			}
			$used[ (int) $l->id ] = true;

			if ( $this->dry_run ) {
				$folded++;
				continue;
			}

			$of = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}competition_match WHERE id = %d", (int) $o->id ) );

			// Replace the legacy fixture's (incomplete) detail rows with ours
			// — match detail is the established wipe-and-rebuild exception,
			// and the legacy events demonstrably undercount (goal totals do
			// not reconcile with the scoreboard). Our matchplayer rows are
			// re-keyed to the LEGACY teamsteps: the legacy row keeps its own
			// home/visitor steps so the classification (which groups by
			// teamstep) stays consistent with the rest of the competition.
			$old_mp = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}competition_matchplayer WHERE match_id = %d", (int) $l->id ) );
			if ( $old_mp ) {
				$in = implode( ',', array_map( 'intval', $old_mp ) );
				$wpdb->query( "DELETE FROM {$p}competition_matchplayerevent WHERE matchplayer_id IN ({$in})" );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}competition_matchplayer WHERE match_id = %d", (int) $l->id ) );
			}
			$side_map = $flipped
				? [ [ (int) $o->home_team_id, (int) $l->visitor_team_id ], [ (int) $o->visitor_team_id, (int) $l->home_team_id ] ]
				: [ [ (int) $o->home_team_id, (int) $l->home_team_id ], [ (int) $o->visitor_team_id, (int) $l->visitor_team_id ] ];
			foreach ( $side_map as [ $our_ts, $legacy_ts ] ) {
				if ( $our_ts !== $legacy_ts ) {
					$wpdb->query( $wpdb->prepare(
						"UPDATE {$p}competition_matchplayer SET team_id = %d WHERE match_id = %d AND team_id = %d",
						$legacy_ts, (int) $o->id, $our_ts
					) );
				}
			}
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}competition_matchplayer SET match_id = %d WHERE match_id = %d", (int) $l->id, (int) $o->id ) );

			// Statorium result data wins on the merged row (same convention
			// as the group pairing's transplant); when the sources disagree
			// on orientation the LEGACY sides are kept, so a flipped pairing
			// swaps our scores. Stadium/stats fall back to legacy.
			$h_score = $flipped ? $of->visitor_score : $of->home_score;
			$v_score = $flipped ? $of->home_score : $of->visitor_score;
			$h_extra = $flipped ? $of->visitor_score_extra : $of->home_score_extra;
			$v_extra = $flipped ? $of->home_score_extra : $of->visitor_score_extra;
			$h_pen   = $flipped ? $of->penalty_visitor_score : $of->penalty_home_score;
			$v_pen   = $flipped ? $of->penalty_home_score : $of->penalty_visitor_score;
			$h_name  = $flipped ? $of->visitor_team_display_name : $of->home_team_display_name;
			$v_name  = $flipped ? $of->home_team_display_name : $of->visitor_team_display_name;
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$p}competition_match
				    SET date_time = %s, situation = %s, assistance = %s, duration = %s,
				        home_score = " . self::sqlv( $h_score ) . ', visitor_score = ' . self::sqlv( $v_score ) . ',
				        home_score_extra = ' . self::sqlv( $h_extra ) . ', visitor_score_extra = ' . self::sqlv( $v_extra ) . ',
				        penalty_home_score = ' . self::sqlv( $h_pen ) . ', penalty_visitor_score = ' . self::sqlv( $v_pen ) . ',
				        stadium_id = COALESCE( ' . ( (int) $of->stadium_id ?: 'NULL' ) . ', stadium_id ),
				        stats_id = COALESCE( ' . ( (int) $of->stats_id ?: 'NULL' ) . ', stats_id ),
				        home_team_display_name = %s, visitor_team_display_name = %s
				  WHERE id = %d',
				(string) $of->date_time, (string) $of->situation, (string) $of->assistance, (string) $of->duration,
				(string) $h_name, (string) $v_name,
				(int) $l->id
			) );

			// Live rows: keep one per fixture, keyed to the legacy match id.
			$l_live = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}live_livematch WHERE match_id = %d", (int) $l->id ) );
			$o_live = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}live_livematch WHERE match_id = %d", (int) $o->id ) );
			if ( $o_live && ! $l_live ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$p}live_livematch SET match_id = %d WHERE id = %d", (int) $l->id, $o_live ) );
			} elseif ( $o_live && $l_live ) {
				$lp = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}live_livematchplayer WHERE live_match_id = %d", $o_live ) );
				if ( $lp ) {
					$wpdb->query( "DELETE FROM {$p}live_livematchplayerevent WHERE live_match_player_id IN (" . implode( ',', array_map( 'intval', $lp ) ) . ')' );
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}live_livematchplayer WHERE live_match_id = %d", $o_live ) );
				}
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}live_livematchevent WHERE live_match_id = %d", $o_live ) );
				$live_post = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'f365_livegames' AND legacy_id = %d AND legacy_table = 'wp_live_livematch' LIMIT 1", $o_live
				) );
				if ( $live_post ) {
					wp_delete_post( $live_post, true );
				}
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}live_livematch WHERE id = %d", $o_live ) );
			}

			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}competition_match WHERE id = %d", (int) $o->id ) );

			// WP posts: fold ours into the legacy match post (api identity +
			// sync-state metas move), or re-key ours when no legacy post exists.
			$our_post    = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'django_match_id' AND meta_value = %s LIMIT 1", (string) $o->id
			) );
			$legacy_post = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'django_match_id' AND meta_value = %s AND post_id != %d LIMIT 1",
				(string) $l->id, $our_post
			) );
			if ( $our_post ) {
				if ( $legacy_post ) {
					$stat_mid = self::api_id_of( $our_post );
					update_post_meta( $legacy_post, '_f365_statorium_linked', 1 );
					foreach ( [ 'statorium_status_id', 'statorium_detail_synced' ] as $k ) {
						$v = get_post_meta( $our_post, $k, true );
						if ( '' !== $v ) {
							update_post_meta( $legacy_post, $k, $v );
						}
					}
					$wpdb->update( $wpdb->posts, [ 'api_source' => self::API_SOURCE, 'api_id' => $stat_mid ], [ 'ID' => $legacy_post ] );
					wp_delete_post( $our_post, true );
					clean_post_cache( $legacy_post );
				} else {
					update_post_meta( $our_post, 'django_match_id', (int) $l->id );
					$wpdb->update( $wpdb->posts, [ 'legacy_id' => (int) $l->id ], [ 'ID' => $our_post ] );
					clean_post_cache( $our_post );
				}
			}
			$folded++;
		}

		if ( $folded && ! $this->dry_run ) {
			// Drop duplicate participants left behind: our import registered
			// the (sometimes wrong-step) teamstep it used; after folding, the
			// team's other step is the one the matches reference. Only rows
			// whose step has NO matches in this comp — while a sibling step of
			// the same team HAS them — are removed.
			$wpdb->query( $wpdb->prepare(
				"DELETE cp1 FROM {$p}competition_participant cp1
				   JOIN {$p}team_teamstep ts1 ON ts1.id = cp1.team_id
				   JOIN {$p}competition_participant cp2 ON cp2.competition_id = cp1.competition_id AND cp2.id <> cp1.id
				   JOIN {$p}team_teamstep ts2 ON ts2.id = cp2.team_id AND ts2.team_id = ts1.team_id
				  WHERE cp1.competition_id = %d
				    AND NOT EXISTS ( SELECT 1 FROM {$p}competition_match m
				                      WHERE m.competition_id = cp1.competition_id
				                        AND ( m.home_team_id = cp1.team_id OR m.visitor_team_id = cp1.team_id ) )
				    AND EXISTS ( SELECT 1 FROM {$p}competition_match m2
				                  WHERE m2.competition_id = cp1.competition_id
				                    AND ( m2.home_team_id = cp2.team_id OR m2.visitor_team_id = cp2.team_id ) )",
				$legacy_comp_id
			) );

			$this->tables()->rebuild_squads( $legacy_comp_id );
			if ( class_exists( 'F365_Cache_Store' ) ) {
				F365_Cache_Store::purge_all();
			}
			$this->log( "FIXTURE DEDUP comp {$legacy_comp_id}: {$folded} duplicate fixture(s) folded into legacy rows" );
		}
		return $folded;
	}

	/**
	 * One-time cleanup for live rows stranded by the two match-deletion paths
	 * that used to skip live cleanup (repair_group_pairing() before this fix,
	 * and rollback_scope('match') before this fix): a wp_live_livematch row
	 * + its f365_livegames post survive with match_id pointing at a
	 * competition_match row (and f365_match post) that no longer exists.
	 * Same orphan definition as the Statorium Data → Live Matches admin page.
	 * Honors dry_run. Returns rows removed (0 when dry).
	 */
	public function repair_orphaned_live_matches(): int {
		global $wpdb;
		$px = $wpdb->prefix;

		$orphans = $wpdb->get_results(
			"SELECT lm.id AS live_id, p.ID AS post_id
			   FROM {$px}live_livematch lm
			   JOIN {$wpdb->posts} p ON p.legacy_id = lm.id AND p.legacy_table = 'wp_live_livematch'
			   JOIN {$wpdb->postmeta} src ON src.post_id = p.ID AND src.meta_key = '_f365_source' AND src.meta_value = 'statorium'
			   LEFT JOIN {$px}competition_match cm ON cm.id = lm.match_id
			   LEFT JOIN {$wpdb->posts} mp ON mp.legacy_id = lm.match_id AND mp.post_type = 'f365_match'
			  WHERE p.post_type = 'f365_livegames' AND cm.id IS NULL AND mp.ID IS NULL"
		);
		if ( ! $orphans ) {
			return 0;
		}

		if ( $this->dry_run ) {
			$this->log( 'LIVEORPHAN (dry) ' . count( $orphans ) . ' orphaned live match row(s) would be removed' );
			return count( $orphans );
		}

		$live_ids = wp_list_pluck( $orphans, 'live_id' );
		$in       = implode( ',', array_map( 'intval', $live_ids ) );

		$live_players = $wpdb->get_col( "SELECT id FROM {$px}live_livematchplayer WHERE live_match_id IN ({$in})" );
		if ( $live_players ) {
			$wpdb->query( "DELETE FROM {$px}live_livematchplayerevent WHERE live_match_player_id IN (" . implode( ',', array_map( 'intval', $live_players ) ) . ')' );
			$wpdb->query( "DELETE FROM {$px}live_livematchplayer WHERE live_match_id IN ({$in})" );
		}
		$wpdb->query( "DELETE FROM {$px}live_livematchevent WHERE live_match_id IN ({$in})" );

		foreach ( $orphans as $o ) {
			wp_delete_post( (int) $o->post_id, true );
		}
		$wpdb->query( "DELETE FROM {$px}live_livematch WHERE id IN ({$in})" );

		$this->log( 'LIVEORPHAN removed ' . count( $orphans ) . ' orphaned live match row(s) (no competition_match row, no f365_match post): live_id ' . implode( ',', array_map( 'intval', $live_ids ) ) );
		return count( $orphans );
	}

	/**
	 * Remove one imported season and everything CREATED for it: its matches,
	 * then any statorium-created teams/venues left unreferenced by other
	 * matches, then the competition post. Linked legacy posts are only
	 * unlinked, never deleted. Returns [matches, teams, venues, comp] counts.
	 */
	public function prune_season( int $season_stat_id ): array {
		global $wpdb;

		$comp_wp = $this->map_resolve( 'season', $season_stat_id );
		if ( ! $comp_wp ) {
			return [ 0, 0, 0, 0 ];
		}

		$deleted = [ 'matches' => 0, 'teams' => 0, 'venues' => 0, 'comp' => 0 ];

		// 1. Matches of this competition (statorium-created only).
		$matches = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = 'competition' AND c.meta_value = %d
			   JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_f365_source' AND s.meta_value = 'statorium'
			  WHERE p.post_type = 'f365_match' AND p.api_source = %s",
			$comp_wp, self::API_SOURCE
		) );
		foreach ( $matches as $id ) {
			wp_delete_post( (int) $id, true );
			$deleted['matches']++;
		}

		// 2. Orphaned statorium-created teams/venues (no remaining match uses them).
		foreach ( [ 'f365_team' => 'teams', 'f365_stadium' => 'venues' ] as $post_type => $key ) {
			$candidates = $wpdb->get_col( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				   JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_f365_source' AND s.meta_value = 'statorium'
				  WHERE p.post_type = %s AND p.api_source = %s",
				$post_type, self::API_SOURCE
			) );
			foreach ( $candidates as $id ) {
				$referenced = $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->postmeta}
					  WHERE meta_key IN ('home_team','visitor_team','stadium','current_team') AND meta_value = %d",
					(int) $id
				) );
				if ( ! $referenced ) {
					wp_delete_post( (int) $id, true );
					$deleted[ $key ]++;
				}
			}
		}

		// 3. The competition itself (only if we created it).
		if ( 'statorium' === get_post_meta( $comp_wp, '_f365_source', true ) ) {
			wp_delete_post( $comp_wp, true );
			$deleted['comp'] = 1;
		}

		// 4. Remove from the synced-seasons list + audit map.
		$settings = self::settings();
		$list     = array_filter( array_map( 'intval', explode( ',', (string) $settings['seasons'] ) ) );
		$list     = array_values( array_diff( $list, [ $season_stat_id ] ) );
		$settings['seasons'] = implode( ',', $list );
		update_option( self::OPTION, $settings, false );

		$this->log( "PRUNE season={$season_stat_id}: " . wp_json_encode( $deleted ) );
		return array_values( $deleted );
	}

	/**
	 * Scoped rollback of Statorium-imported data.
	 *
	 * $module: all | match | player | team | stadium | competition | transfers | countries
	 * $from/$to: Y-m-d bounds on the import day (created: synced stamp/post_date;
	 *            transfers: api_created_at). Empty = no bound.
	 * $unlink: also unlink legacy posts in scope (metas + api columns removed;
	 *          gap-filled field values stay — they are correct data).
	 * $dry:   count only, delete nothing.
	 *
	 * CREATED posts are deleted with their mirror-table rows (only mirror ids
	 * >= ID_OFFSET — legacy rows can never be touched), their sideloaded
	 * attachments, review-queue rows, and id-map entries.
	 */
	public function rollback_scope( string $module, string $from = '', string $to = '', bool $unlink = false, bool $dry = true ): array {
		global $wpdb;
		$px      = $wpdb->prefix;
		$off     = self::ID_OFFSET;
		$summary = [];

		$date_sql = function ( string $expr ) use ( $from, $to ): string {
			$w = '';
			if ( $from && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
				$w .= " AND DATE( {$expr} ) >= '{$from}'";
			}
			if ( $to && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
				$w .= " AND DATE( {$expr} ) <= '{$to}'";
			}
			return $w;
		};
		$in = fn( array $ids ): string => implode( ',', array_map( 'intval', $ids ) ) ?: '0';

		// Deletion order respects references: transfers → matches → players →
		// teams → stadiums → competitions → countries.
		$modules = 'all' === $module
			? [ 'transfers', 'match', 'player', 'team', 'stadium', 'competition', 'countries' ]
			: [ $module ];

		$type_of = [ 'match' => 'match', 'player' => 'player', 'team' => 'team', 'stadium' => 'venue', 'competition' => 'season' ];

		foreach ( $modules as $m ) {
			if ( 'transfers' === $m ) {
				// Date filter matches EITHER the import day (api_created_at)
				// OR the transfer's own date — users see the transfer date on
				// the /transferencias/ page and in the feed, and passing that
				// must work too.
				$w = "api_source = 'statorium'";
				$dw_import   = $date_sql( "COALESCE( api_created_at, transfer_date )" );
				$dw_transfer = $date_sql( 'transfer_date' );
				if ( $dw_import ) {
					$w .= ' AND ( ( 1=1' . $dw_import . ' ) OR ( 1=1' . $dw_transfer . ' ) )';
				}
				$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$px}transfer_transfer WHERE {$w}" );
				if ( ! $dry && $n ) {
					$wpdb->query( "DELETE FROM {$px}transfer_transfer WHERE {$w}" );
				}
				$summary['transfers_rows'] = $n;
				continue;
			}

			if ( 'countries' === $m ) {
				// Created terms = statorium-mapped without a django_country_id.
				$created_terms = $wpdb->get_col(
					"SELECT sid.term_id FROM {$wpdb->termmeta} sid
					  WHERE sid.meta_key = 'statorium_country_id'
					    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->termmeta} d
					                      WHERE d.term_id = sid.term_id AND d.meta_key = 'django_country_id' )"
				);
				$summary['countries_terms'] = count( $created_terms );
				if ( ! $dry ) {
					foreach ( $created_terms as $tid ) {
						$row = (int) get_term_meta( (int) $tid, 'country_country_id', true );
						if ( $row >= 1 ) {
							$used = (int) $wpdb->get_var( $wpdb->prepare(
								"SELECT ( SELECT COUNT(*) FROM {$px}team_team WHERE country_id = %d )
								       + ( SELECT COUNT(*) FROM {$px}person_person WHERE nationality_id = %d )
								       + ( SELECT COUNT(*) FROM {$px}stadium_stadium WHERE country_id = %d )",
								$row, $row, $row
							) );
							if ( ! $used ) {
								$wpdb->query( $wpdb->prepare( "DELETE FROM {$px}country_country WHERE id = %d", $row ) );
							}
						}
						wp_delete_term( (int) $tid, 'f365_country' );
					}
					// Strip our metas from the legacy terms that stay.
					$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key IN ('statorium_country_id','statorium_country_code','country_country_id')" );
				}
				continue;
			}

			// ── Post modules ─────────────────────────────────────────────
			$post_type = self::TYPE_POST_TYPE[ $type_of[ $m ] ];
			$scope     = "FROM {$wpdb->posts} p
				  JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_f365_source' AND s.meta_value = 'statorium'
				  LEFT JOIN {$wpdb->postmeta} syn ON syn.post_id = p.ID AND syn.meta_key = '_statorium_synced_at'
				 WHERE p.post_type = '{$post_type}'"
				. $date_sql( 'COALESCE( syn.meta_value, p.post_date )' );

			$posts = $wpdb->get_results( "SELECT p.ID, p.legacy_id AS pub, p.api_id {$scope}" );
			$summary[ "{$m}_created_posts" ] = count( $posts );

			if ( ! $dry && $posts ) {
				$ids  = wp_list_pluck( $posts, 'ID' );
				$pubs = array_filter( array_map( 'intval', wp_list_pluck( $posts, 'pub' ) ), fn( $p ) => $p >= $off );
				$sids = array_filter( array_map( 'intval', wp_list_pluck( $posts, 'api_id' ) ) );

				// Mirror rows — only ids in the created range, never legacy.
				if ( 'match' === $m ) {
					// Live rows mirror these matches unconditionally
					// (import_match_from_payload()'s jogos-em-direto coverage)
					// — deleting the match row without them leaves
					// wp_live_livematch/f365_livegames pointing at a match id
					// that no longer exists anywhere (same trap fixed in
					// repair_group_pairing() / repair_duplicate_fixtures()).
					$live_ids = $wpdb->get_col( "SELECT id FROM {$px}live_livematch WHERE match_id IN (" . $in( $pubs ) . ')' );
					if ( $live_ids ) {
						$live_players = $wpdb->get_col( "SELECT id FROM {$px}live_livematchplayer WHERE live_match_id IN (" . $in( $live_ids ) . ')' );
						if ( $live_players ) {
							$wpdb->query( "DELETE FROM {$px}live_livematchplayerevent WHERE live_match_player_id IN (" . $in( $live_players ) . ')' );
							$wpdb->query( "DELETE FROM {$px}live_livematchplayer WHERE live_match_id IN (" . $in( $live_ids ) . ')' );
						}
						$wpdb->query( "DELETE FROM {$px}live_livematchevent WHERE live_match_id IN (" . $in( $live_ids ) . ')' );
						$live_post_ids = $wpdb->get_col(
							"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'f365_livegames' AND legacy_table = 'wp_live_livematch' AND legacy_id IN (" . $in( $live_ids ) . ')'
						);
						foreach ( $live_post_ids as $lpid ) {
							wp_delete_post( (int) $lpid, true );
						}
						$wpdb->query( "DELETE FROM {$px}live_livematch WHERE id IN (" . $in( $live_ids ) . ')' );
					}
					$wpdb->query( "DELETE e FROM {$px}competition_matchplayerevent e JOIN {$px}competition_matchplayer mp ON mp.id = e.match_player_id WHERE mp.match_id IN (" . $in( $pubs ) . ')' );
					$wpdb->query( "DELETE FROM {$px}competition_matchplayer WHERE match_id IN (" . $in( $pubs ) . ')' );
					$wpdb->query( "DELETE FROM {$px}competition_matchstats WHERE match_id IN (" . $in( $pubs ) . ')' );
					$wpdb->query( "DELETE FROM {$px}competition_match WHERE id IN (" . $in( $pubs ) . ')' );
				} elseif ( 'player' === $m ) {
					$persons = $wpdb->get_col( "SELECT person_id FROM {$px}person_player WHERE id IN (" . $in( $pubs ) . ") AND person_id >= {$off}" );
					$wpdb->query( "DELETE e FROM {$px}competition_matchplayerevent e JOIN {$px}competition_matchplayer mp ON mp.id = e.match_player_id WHERE mp.player_id IN (" . $in( $pubs ) . ')' );
					$wpdb->query( "DELETE FROM {$px}competition_matchplayer WHERE player_id IN (" . $in( $pubs ) . ')' );
					$wpdb->query( "DELETE FROM {$px}team_squad WHERE player_id IN (" . $in( $pubs ) . ')' );
					$wpdb->query( "DELETE FROM {$px}person_player WHERE id IN (" . $in( $pubs ) . ')' );
					$wpdb->query( "DELETE FROM {$px}person_person WHERE id IN (" . $in( $persons ) . ')' );
				} elseif ( 'team' === $m ) {
					$steps = $wpdb->get_col( "SELECT id FROM {$px}team_teamstep WHERE team_id IN (" . $in( $pubs ) . ')' );
					$wpdb->query( "DELETE e FROM {$px}competition_matchplayerevent e JOIN {$px}competition_matchplayer mp ON mp.id = e.match_player_id WHERE mp.team_id IN (" . $in( $steps ) . ')' );
					$wpdb->query( "DELETE FROM {$px}competition_matchplayer WHERE team_id IN (" . $in( $steps ) . ')' );
					$wpdb->query( "DELETE FROM {$px}team_squad WHERE team_id IN (" . $in( $steps ) . ')' );
					$wpdb->query( "DELETE sc FROM {$px}team_squadcompetition sc LEFT JOIN {$px}team_squad sq ON sq.id = sc.squad_id WHERE sq.id IS NULL" );
					$wpdb->query( "DELETE FROM {$px}competition_participant WHERE team_id IN (" . $in( array_merge( $pubs, $steps ) ) . ')' );
					$wpdb->query( "DELETE FROM {$px}team_teamstep WHERE id IN (" . $in( $steps ) . ')' );
					$wpdb->query( "DELETE FROM {$px}team_team WHERE id IN (" . $in( $pubs ) . ')' );
				} elseif ( 'stadium' === $m ) {
					// Null every reference first — deleted stadium pubs must
					// not dangle from match/team rows (empty /estadio/ pages).
					$wpdb->query( "UPDATE {$px}competition_match SET stadium_id = NULL WHERE stadium_id IN (" . $in( $pubs ) . ')' );
					$wpdb->query( "UPDATE {$px}team_team SET stadium_id = NULL WHERE stadium_id IN (" . $in( $pubs ) . ')' );
					$wpdb->query( "UPDATE {$px}team_teamstep SET stadium_id = NULL WHERE stadium_id IN (" . $in( $pubs ) . ')' );
					$wpdb->query( "DELETE FROM {$px}stadium_stadium WHERE id IN (" . $in( $pubs ) . ')' );
				} elseif ( 'competition' === $m ) {
					$fedcomps = $wpdb->get_col( "SELECT federation_competition_id FROM {$px}competition_competition WHERE id IN (" . $in( $pubs ) . ") AND federation_competition_id >= {$off}" );
					$wpdb->query( "DELETE FROM {$px}competition_competitionphase WHERE competition_id IN (" . $in( $pubs ) . ')' );
					$wpdb->query( "DELETE FROM {$px}competition_participant WHERE competition_id IN (" . $in( $pubs ) . ')' );
					$wpdb->query( "DELETE FROM {$px}competition_match WHERE competition_id IN (" . $in( $pubs ) . ") AND id >= {$off}" );
					$wpdb->query( "DELETE FROM {$px}competition_competition WHERE id IN (" . $in( $pubs ) . ')' );
					$wpdb->query( "DELETE FROM {$px}federation_federationcompetition WHERE id IN (" . $in( $fedcomps ) . ')' );
				}

				// Sideloaded attachments, then the posts themselves.
				foreach ( $ids as $pid ) {
					foreach ( get_children( [ 'post_parent' => (int) $pid, 'post_type' => 'attachment', 'fields' => 'ids' ] ) as $att ) {
						wp_delete_attachment( (int) $att, true );
					}
					wp_delete_post( (int) $pid, true );
				}

				// Review rows + id-map entries for the removed stat ids.
				if ( $sids ) {
					$wpdb->query( 'DELETE FROM ' . F365_Statorium_Matcher::review_table() . " WHERE entity_type = '{$type_of[$m]}' AND stat_id IN (" . $in( $sids ) . ')' );
				}
				$map = json_decode( (string) get_option( "f365_stat_map_{$type_of[$m]}", '{}' ), true ) ?: [];
				foreach ( $sids as $sid ) {
					unset( $map[ (string) $sid ] );
				}
				update_option( "f365_stat_map_{$type_of[$m]}", wp_json_encode( $map ), false );
			}

			// Optional: unlink legacy posts in scope (created posts excluded).
			if ( $unlink ) {
				$linked = $wpdb->get_col(
					"SELECT p.ID FROM {$wpdb->posts} p
					  LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_f365_source' AND s.meta_value = 'statorium'
					  LEFT JOIN {$wpdb->postmeta} syn ON syn.post_id = p.ID AND syn.meta_key = '_statorium_synced_at'
					 WHERE p.post_type = '{$post_type}' AND p.api_source = 'statorium' AND s.post_id IS NULL"
					. $date_sql( 'syn.meta_value' )
				);
				$summary[ "{$m}_unlinked" ] = count( $linked );
				if ( ! $dry && $linked ) {
					$map = json_decode( (string) get_option( "f365_stat_map_{$type_of[$m]}", '{}' ), true ) ?: [];
					foreach ( $linked as $lid ) {
						$lid = (int) $lid;
						unset( $map[ (string) self::api_id_of( $lid ) ] );
						foreach ( [ '_f365_statorium_linked', '_f365_match_method', '_statorium_synced_at', 'statorium_status_id', 'statorium_detail_synced', 'statorium_venue_synced' ] as $key ) {
							delete_post_meta( $lid, $key );
						}
						$wpdb->update( $wpdb->posts, [ 'api_source' => null, 'api_id' => null ], [ 'ID' => $lid ] );
						clean_post_cache( $lid );
					}
					update_option( "f365_stat_map_{$type_of[$m]}", wp_json_encode( $map ), false );
				}
			}
		}

		if ( ! $dry && class_exists( 'F365_Cache_Store' ) ) {
			F365_Cache_Store::purge_prefix( '*' );
		}
		if ( ! $dry ) {
			$this->log( 'ROLLBACK ' . $module . ( $from || $to ? " [{$from}..{$to}]" : '' ) . ' ' . wp_json_encode( $summary ) );
		}
		return $summary;
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

	/** Per-season participant → PT group name ("Grupo A"), cached per request. */
	private array $season_groups_cache = [];

	private function season_group_of( int $season_id, int $participant_id ): string {
		if ( ! isset( $this->season_groups_cache[ $season_id ] ) ) {
			$map  = [];
			$data = $this->api->season( $season_id );
			if ( ! is_wp_error( $data ) ) {
				foreach ( (array) ( $data['season']['groups'] ?? [] ) as $g ) {
					$name = str_replace( 'Group ', 'Grupo ', (string) ( $g['groupName'] ?? '' ) );
					foreach ( (array) ( $g['participants'] ?? [] ) as $pp ) {
						$map[ (int) ( $pp['participantID'] ?? 0 ) ] = $name;
					}
				}
			}
			$this->season_groups_cache[ $season_id ] = $map;
		}
		return $this->season_groups_cache[ $season_id ][ $participant_id ] ?? '';
	}

	/**
	 * Statorium matchday/phase name → the site's legacy phase slug.
	 *
	 * Confirmed live: a qualifying/knockout bracket (e.g. UEFA Champions
	 * League Qualifying) names its rounds by remaining-teams FRACTION
	 * ("1/8 Final", "1/4 Final", "1/2 Final") instead of the English
	 * ordinal the MAIN tournament bracket uses ("Round of 16",
	 * "Quarterfinal"). An unrecognized name falls through unchanged (see
	 * return below) and then fails phase_def() too, since that expects a
	 * slug — competition_phase_id stayed NULL for every match in the
	 * competition, breaking the calendar page's phase grouping entirely.
	 * Same round, same phase_def() slug either way — "1/8 Final" IS "Round
	 * of 16", just named differently by this bracket. "1/16 Final" reuses
	 * round_of_32's existing "16 avos-de-final" PT phase_def() entry — that
	 * name was already written generically enough to cover it.
	 */
	public function map_phase( string $phase ): string {
		$map = apply_filters( 'f365_statorium_phase_map', [
			'Round of 32'  => 'round_of_32',
			'Round of 16'  => 'round_of_16',
			'Quarterfinal' => 'quarter_finals',
			'Semi-final'   => 'semi_finals',
			'Third place'  => 'third_fourth',
			'Final'        => 'final',
			'1/16 Final'   => 'round_of_32',
			'1/8 Final'    => 'round_of_16',
			'1/4 Final'    => 'quarter_finals',
			'1/2 Final'    => 'semi_finals',
			// New-format UEFA club competitions' play-off round (league-phase
			// 9th-24th place) to reach the Round of 16 — also confirmed live,
			// same gap as the fraction rounds above.
			'Knockout phase play-offs' => 'ko_playoffs',
		] );
		return (string) ( $map[ $phase ] ?? $phase );
	}

	/**
	 * PT country name (country_pt() output) → confederation shortname
	 * (matches wp_federation_federation.shortname for federation_type=
	 * 'confederation': UEFA/CONMEBOL/CONCACAF/AFC/CAF/OFC). Static FIFA
	 * membership — used to resolve a NATIONAL team's federation_id (the
	 * mirror column has no country→confederation link of its own).
	 */
	public static function country_confederation(): array {
		return apply_filters( 'f365_statorium_country_confederation', [
			// UEFA
			'Albânia' => 'UEFA', 'Alemanha' => 'UEFA', 'Andorra' => 'UEFA', 'Arménia' => 'UEFA',
			'Azerbaijão' => 'UEFA', 'Áustria' => 'UEFA', 'Bélgica' => 'UEFA', 'Bielorrússia' => 'UEFA',
			'Bósnia' => 'UEFA', 'Bulgária' => 'UEFA', 'Chéquia' => 'UEFA', 'Chipre' => 'UEFA',
			'Croácia' => 'UEFA', 'Dinamarca' => 'UEFA', 'Escócia' => 'UEFA', 'Eslováquia' => 'UEFA',
			'Eslovénia' => 'UEFA', 'Espanha' => 'UEFA', 'Estónia' => 'UEFA', 'Ilhas Faroé' => 'UEFA',
			'Finlândia' => 'UEFA', 'França' => 'UEFA', 'Geórgia' => 'UEFA', 'Gibraltar' => 'UEFA',
			'Grécia' => 'UEFA', 'Hungria' => 'UEFA', 'Inglaterra' => 'UEFA', 'Irlanda' => 'UEFA',
			'Irlanda do Norte' => 'UEFA', 'Islândia' => 'UEFA', 'Israel' => 'UEFA', 'Itália' => 'UEFA',
			'Cazaquistão' => 'UEFA', 'Kosovo' => 'UEFA', 'Letónia' => 'UEFA', 'Liechtenstein' => 'UEFA',
			'Lituânia' => 'UEFA', 'Luxemburgo' => 'UEFA', 'Macedónia do Norte' => 'UEFA', 'Malta' => 'UEFA',
			'Moldávia' => 'UEFA', 'Montenegro' => 'UEFA', 'Noruega' => 'UEFA', 'Holanda' => 'UEFA',
			'País de Gales' => 'UEFA', 'Polónia' => 'UEFA', 'Portugal' => 'UEFA', 'Roménia' => 'UEFA',
			'Rússia' => 'UEFA', 'San Marino' => 'UEFA', 'Sérvia' => 'UEFA', 'Suécia' => 'UEFA',
			'Suíça' => 'UEFA', 'Turquia' => 'UEFA', 'Ucrânia' => 'UEFA',
			// CONMEBOL
			'Argentina' => 'CONMEBOL', 'Bolívia' => 'CONMEBOL', 'Brasil' => 'CONMEBOL', 'Chile' => 'CONMEBOL',
			'Colômbia' => 'CONMEBOL', 'Equador' => 'CONMEBOL', 'Paraguai' => 'CONMEBOL', 'Peru' => 'CONMEBOL',
			'Uruguai' => 'CONMEBOL', 'Venezuela' => 'CONMEBOL',
			// CONCACAF
			'Anguila' => 'CONCACAF', 'Antigua e Barbuda' => 'CONCACAF', 'Aruba' => 'CONCACAF',
			'Bahamas' => 'CONCACAF', 'Barbados' => 'CONCACAF', 'Belize' => 'CONCACAF', 'Bermudas' => 'CONCACAF',
			'Canadá' => 'CONCACAF', 'Costa Rica' => 'CONCACAF', 'Cuba' => 'CONCACAF', 'Curaçau' => 'CONCACAF',
			'Dominica' => 'CONCACAF', 'El Salvador' => 'CONCACAF', 'Estados Unidos' => 'CONCACAF',
			'Granada' => 'CONCACAF', 'Guatemala' => 'CONCACAF', 'Guiana' => 'CONCACAF', 'Haiti' => 'CONCACAF',
			'Honduras' => 'CONCACAF', 'Ilhas Cayman' => 'CONCACAF', 'Jamaica' => 'CONCACAF', 'México' => 'CONCACAF',
			'Montserrat' => 'CONCACAF', 'Nicarágua' => 'CONCACAF', 'Panamá' => 'CONCACAF', 'Porto Rico' => 'CONCACAF',
			'Rep. Dominicana' => 'CONCACAF', 'Santa Lúcia' => 'CONCACAF', 'São Cristóvão e Nevis' => 'CONCACAF',
			'S. Vicente Granadinas' => 'CONCACAF', 'Suriname' => 'CONCACAF', 'Trinidad e Tobago' => 'CONCACAF',
			'Turcas e Caicos' => 'CONCACAF',
			// AFC
			'Afeganistão' => 'AFC', 'Arábia Saudita' => 'AFC', 'Bahrein' => 'AFC', 'Bangladesh' => 'AFC',
			'Butão' => 'AFC', 'Brunei' => 'AFC', 'Camboja' => 'AFC', 'Qatar' => 'AFC', 'China' => 'AFC',
			'Coreia do Norte' => 'AFC', 'Rep. Coreia' => 'AFC', 'Emirados Árabes' => 'AFC',
			'Filipinas' => 'AFC', 'Guam' => 'AFC', 'Hong Kong' => 'AFC', 'Índia' => 'AFC',
			'Indonésia' => 'AFC', 'Iraque' => 'AFC', 'Irão' => 'AFC', 'Japão' => 'AFC', 'Jordânia' => 'AFC',
			'Kuwait' => 'AFC', 'Laos' => 'AFC', 'Líbano' => 'AFC', 'Macau' => 'AFC', 'Malásia' => 'AFC',
			'Maldivas' => 'AFC', 'Mongólia' => 'AFC', 'Mianmar' => 'AFC', 'Nepal' => 'AFC', 'Omã' => 'AFC',
			'Paquistão' => 'AFC', 'Palestina' => 'AFC', 'Quirguistão' => 'AFC', 'Singapura' => 'AFC',
			'Síria' => 'AFC', 'Sri Lanka' => 'AFC', 'Tailândia' => 'AFC', 'Tajiquistão' => 'AFC',
			'Taiwan' => 'AFC', 'Timor-Leste' => 'AFC', 'Turcomenistão' => 'AFC', 'Uzbequistão' => 'AFC',
			'Vietname' => 'AFC', 'Iémen' => 'AFC',
			// CAF
			'África do Sul' => 'CAF', 'Angola' => 'CAF', 'Argélia' => 'CAF', 'Benin' => 'CAF',
			'Botswana' => 'CAF', 'Burkina Faso' => 'CAF', 'Burundi' => 'CAF', 'Cabo Verde' => 'CAF',
			'Camarões' => 'CAF', 'Chade' => 'CAF', 'Comores' => 'CAF', 'Congo' => 'CAF', 'RD Congo' => 'CAF',
			'Costa do Marfim' => 'CAF', 'Djibouti' => 'CAF', 'Egipto' => 'CAF', 'Eritreia' => 'CAF',
			'Essuatíni' => 'CAF', 'Etiópia' => 'CAF', 'Gabão' => 'CAF', 'Gâmbia' => 'CAF', 'Gana' => 'CAF',
			'Guiné-Bissau' => 'CAF', 'Guiné-Conakri' => 'CAF', 'Guiné Equatorial' => 'CAF', 'Lesoto' => 'CAF',
			'Libéria' => 'CAF', 'Líbia' => 'CAF', 'Madagáscar' => 'CAF', 'Malawi' => 'CAF', 'Mali' => 'CAF',
			'Marrocos' => 'CAF', 'Maurícia' => 'CAF', 'Mauritânia' => 'CAF', 'Moçambique' => 'CAF',
			'Namíbia' => 'CAF', 'Níger' => 'CAF', 'Nigéria' => 'CAF', 'Quénia' => 'CAF', 'Ruanda' => 'CAF',
			'São Tomé e Príncipe' => 'CAF', 'Senegal' => 'CAF', 'Serra Leoa' => 'CAF', 'Seychelles' => 'CAF',
			'Somália' => 'CAF', 'Sudão' => 'CAF', 'Sudão do Sul' => 'CAF', 'Tanzânia' => 'CAF', 'Togo' => 'CAF',
			'Tunísia' => 'CAF', 'Uganda' => 'CAF', 'Zâmbia' => 'CAF', 'Zimbabué' => 'CAF',
			// OFC
			'Ilhas Fiji' => 'OFC', 'Ilhas Cook' => 'OFC', 'Ilhas Salomão' => 'OFC', 'Nova Caledônia' => 'OFC',
			'Nova Zelândia' => 'OFC', 'Papua Nova Guiné' => 'OFC', 'Samoa' => 'OFC', 'Samoa Americana' => 'OFC',
			'Taiti' => 'OFC', 'Tonga' => 'OFC', 'Tuvalu' => 'OFC', 'Vanuatu' => 'OFC',
		] );
	}

	/**
	 * Native-language country name forms that appear at the end of a
	 * Statorium venueAddress string but do NOT match country_pt()'s English
	 * keys (which come from a different source, /countries/). Small,
	 * best-effort — feeds into country_pt() once translated to its English
	 * vocabulary; unmatched addresses simply resolve to no country (never
	 * guessed).
	 */
	public static function venue_address_country_aliases(): array {
		return apply_filters( 'f365_statorium_venue_address_aliases', [
			'türkiye' => 'Turkey', 'deutschland' => 'Germany', 'österreich' => 'Austria',
			'españa' => 'Spain', 'italia' => 'Italy', 'nederland' => 'Netherlands',
			'schweiz' => 'Switzerland', 'suisse' => 'Switzerland', 'svizzera' => 'Switzerland',
			'norge' => 'Norway', 'sverige' => 'Sweden', 'danmark' => 'Denmark', 'suomi' => 'Finland',
			'polska' => 'Poland', 'ελλάδα' => 'Greece', 'hrvatska' => 'Croatia', 'ísland' => 'Iceland',
			'россия' => 'Russia', 'україна' => 'Ukraine', 'brasil' => 'Brazil', 'méxico' => 'Mexico',
			'日本' => 'Japan', '대한민국' => 'South Korea', '中国' => 'China', 'مصر' => 'Egypt',
			'المغرب' => 'Morocco', 'الجزائر' => 'Algeria', 'تونس' => 'Tunisia',
			// "uk" is the abbreviation actually used on English club venue
			// addresses (verified live) — country_pt() only recognizes the
			// spelled-out "United Kingdom".
			'uk'                      => 'United Kingdom',
			'switz'                   => 'Switzerland',
			'bosnia & herzegovina'    => 'Bosnia and Herzegovina',
		] );
	}

	/**
	 * Best-effort country derivation from a Statorium venueAddress string
	 * (the last comma-separated segment is the country, e.g.
	 * "…, 06200 Nice, France" → "France"). Returns the site's PT country
	 * name, or '' when nothing resolves — never guessed/fabricated.
	 *
	 * Must use `country_known()`, not a `$pt !== $last` comparison, to tell
	 * "found" from "not found" — country_pt() falls back to returning the
	 * input unchanged when the name isn't a map key, which is indistinguishable
	 * from a country whose PT spelling equals the English one. That bug
	 * silently failed for Portugal (PT "Portugal" = EN "Portugal") — the
	 * site's OWN country, so most Portuguese stadium addresses (which end
	 * "…, Portugal") never resolved. Same trap country_known()'s own
	 * docblock already documents (Chile/Jamaica/Peru); this call site had
	 * missed it.
	 */
	public static function derive_country_from_address( string $address ): string {
		$parts = array_map( 'trim', explode( ',', $address ) );
		$last  = trim( (string) end( $parts ) );
		if ( '' === $last ) {
			return '';
		}
		// Common European format fuses the postal code onto the country with
		// no separating comma ("9027 Hungary", "00185 Roma Italy") — the
		// unstripped segment never matches the country map even when it
		// names the country outright. Country names never start with a
		// digit, so stripping a leading numeric token is unambiguous.
		$last = trim( (string) preg_replace( '/^[\d\-]+\s+/', '', $last ) );
		if ( '' === $last ) {
			return '';
		}
		if ( self::country_known( $last ) ) {
			return self::country_pt( $last );
		}
		$aliases = self::venue_address_country_aliases();
		$en      = $aliases[ mb_strtolower( $last ) ] ?? '';
		return $en ? self::country_pt( $en ) : '';
	}

	/**
	 * City derivation from a venueAddress was tried and dropped: the
	 * second-to-last comma segment is the city ONLY for simple addresses
	 * ("…, 4100-000 Porto, Portugal" → "Porto"). Verified against real data
	 * it breaks two common ways — Spanish addresses often insert a PROVINCE
	 * segment between city and country ("…, Donostia, Gipuzkoa, Spain" would
	 * derive "Gipuzkoa", not the actual city "Donostia"), and UK addresses
	 * fuse the postcode onto the city with no separating comma ("Manchester
	 * M11 3FF"). A wrong city is worse than a blank one — city is left
	 * unfilled here; only country (see derive_country_from_address, which
	 * anchors on the LAST segment and is far more reliable) is derived.
	 */

	/**
	 * English country name (Statorium payloads) → the site's Portuguese name.
	 * Used before creating/assigning f365_country terms and country lookups so
	 * no English country data ever lands in the site.
	 */
	public static function country_pt( string $name ): string {
		$map = self::country_map();
		return (string) ( $map[ $name ] ?? $name );
	}

	/** Full EN→PT country map (PT spellings match the legacy DB rows). */
	public static function country_map(): array {
		return apply_filters( 'f365_statorium_country_map', [
			'Germany' => 'Alemanha', 'Saudi Arabia' => 'Arábia Saudita', 'Algeria' => 'Argélia',
			'Argentina' => 'Argentina', 'Australia' => 'Austrália', 'Austria' => 'Áustria',
			'Belgium' => 'Bélgica', 'Bosnia and Herzegovina' => 'Bósnia', 'Brazil' => 'Brasil',
			'Cape Verde' => 'Cabo Verde', 'Canada' => 'Canadá', 'Chile' => 'Chile',
			'Colombia' => 'Colômbia', 'South Korea' => 'Coreia do Sul', 'Ivory Coast' => 'Costa do Marfim',
			'Costa Rica' => 'Costa Rica', 'Croatia' => 'Croácia', 'Curacao' => 'Curaçau',
			'Denmark' => 'Dinamarca', 'Egypt' => 'Egipto', 'Ecuador' => 'Equador',
			'Scotland' => 'Escócia', 'Slovakia' => 'Eslováquia', 'Slovenia' => 'Eslovénia',
			'Spain' => 'Espanha', 'United States' => 'Estados Unidos', 'USA' => 'Estados Unidos',
			'France' => 'França', 'Ghana' => 'Gana', 'Greece' => 'Grécia', 'Netherlands' => 'Holanda',
			'England' => 'Inglaterra', 'Iran' => 'Irão', 'Ireland' => 'Irlanda',
			'Northern Ireland' => 'Irlanda do Norte', 'Iceland' => 'Islândia', 'Italy' => 'Itália',
			'Jamaica' => 'Jamaica', 'Japan' => 'Japão', 'Jordan' => 'Jordânia', 'Morocco' => 'Marrocos',
			'Mexico' => 'México', 'Nigeria' => 'Nigéria', 'Norway' => 'Noruega',
			'New Zealand' => 'Nova Zelândia', 'Panama' => 'Panamá', 'Paraguay' => 'Paraguai',
			'Peru' => 'Peru', 'Poland' => 'Polónia', 'Portugal' => 'Portugal', 'Qatar' => 'Qatar',
			'Wales' => 'País de Gales', 'DR Congo' => 'RD Congo', 'Czech Republic' => 'Chéquia',
			'Czechia' => 'Chéquia', 'Romania' => 'Roménia', 'Russia' => 'Rússia', 'Senegal' => 'Senegal',
			'Serbia' => 'Sérvia', 'Sweden' => 'Suécia', 'Switzerland' => 'Suíça',
			'South Africa' => 'África do Sul', 'Tunisia' => 'Tunísia', 'Turkey' => 'Turquia',
			'Ukraine' => 'Ucrânia', 'Uruguay' => 'Uruguai', 'Uzbekistan' => 'Uzbequistão',
			'Venezuela' => 'Venezuela',
			'Albania' => 'Albânia', 'Andorra' => 'Andorra', 'Angola' => 'Angola',
			'Armenia' => 'Arménia', 'Azerbaijan' => 'Azerbaijão', 'Bahrain' => 'Bahrein',
			'Belarus' => 'Bielorrússia', 'Bolivia' => 'Bolívia', 'Bulgaria' => 'Bulgária',
			'Burkina Faso' => 'Burkina Faso', 'Cameroon' => 'Camarões', 'Chad' => 'Chade',
			'China' => 'China', 'Cyprus' => 'Chipre', 'Comoros' => 'Comores', 'Congo' => 'Congo',
			'North Korea' => 'Coreia do Norte', 'Kosovo' => 'Kosovo', 'Cuba' => 'Cuba',
			'Dominican Republic' => 'Rep. Dominicana', 'El Salvador' => 'El Salvador',
			'United Arab Emirates' => 'Emirados Árabes', 'Estonia' => 'Estónia',
			'Ethiopia' => 'Etiópia', 'Faroe Islands' => 'Ilhas Faroé', 'Fiji' => 'Ilhas Fiji',
			'Finland' => 'Finlândia', 'Gabon' => 'Gabão', 'Gambia' => 'Gâmbia',
			'Georgia' => 'Geórgia', 'Gibraltar' => 'Gibraltar', 'Guatemala' => 'Guatemala',
			'Guinea' => 'Guiné-Conakri', 'Guinea-Bissau' => 'Guiné-Bissau', 'Equatorial Guinea' => 'Guiné Equatorial',
			'Haiti' => 'Haiti', 'Honduras' => 'Honduras', 'Hungary' => 'Hungria', 'India' => 'Índia',
			'Indonesia' => 'Indonésia', 'Iraq' => 'Iraque', 'Israel' => 'Israel',
			'Kazakhstan' => 'Cazaquistão', 'Kenya' => 'Quénia', 'Kuwait' => 'Kuwait',
			'Latvia' => 'Letónia', 'Lebanon' => 'Líbano', 'Liberia' => 'Libéria', 'Libya' => 'Líbia',
			'Liechtenstein' => 'Liechtenstein', 'Lithuania' => 'Lituânia', 'Luxembourg' => 'Luxemburgo',
			'North Macedonia' => 'Macedónia do Norte', 'Madagascar' => 'Madagáscar', 'Malawi' => 'Malawi',
			'Malaysia' => 'Malásia', 'Mali' => 'Mali', 'Malta' => 'Malta', 'Mauritania' => 'Mauritânia',
			'Moldova' => 'Moldávia', 'Mongolia' => 'Mongólia', 'Montenegro' => 'Montenegro',
			'Mozambique' => 'Moçambique', 'Namibia' => 'Namíbia', 'Nicaragua' => 'Nicarágua',
			'Niger' => 'Níger', 'Oman' => 'Omã', 'Pakistan' => 'Paquistão', 'Philippines' => 'Filipinas',
			'Singapore' => 'Singapura', 'Syria' => 'Síria', 'Sri Lanka' => 'Sri Lanka',
			'Sudan' => 'Sudão', 'South Sudan' => 'Sudão do Sul', 'Thailand' => 'Tailândia',
			'Taiwan' => 'Taiwan', 'Tanzania' => 'Tanzânia', 'Togo' => 'Togo',
			'Trinidad and Tobago' => 'Trinidad e Tobago', 'Vietnam' => 'Vietname',
			'Yemen' => 'Iémen', 'Zambia' => 'Zâmbia', 'Zimbabwe' => 'Zimbabué',
			// Formal ISO names used by /countries/ (player payloads use common names).
			'Iran, Islamic Republic of' => 'Irão', 'Congo, The Democratic Republic of the' => 'RD Congo',
			"Cote D'Ivoire" => 'Costa do Marfim', 'Korea, Republic of' => 'Coreia do Sul',
			"Korea, Democratic People's Republic of" => 'Coreia do Norte', 'Russian Federation' => 'Rússia',
			'Viet Nam' => 'Vietname', 'Syrian Arab Republic' => 'Síria', 'Moldova, Republic of' => 'Moldávia',
			'Tanzania, United Republic of' => 'Tanzânia', 'Macedonia, The Former Yugoslav Republic of' => 'Macedónia do Norte',
			'Palestinian Territory, Occupied' => 'Palestina', 'Taiwan, Province of China' => 'Taiwan',
			"Lao People's Democratic Republic" => 'Laos', 'Brunei Darussalam' => 'Brunei',
			'Cambodia' => 'Camboja', 'Kyrgyzstan' => 'Quirguistão', 'Lesotho' => 'Lesoto',
			'Libyan Arab Jamahiriya' => 'Líbia', 'Grenada' => 'Granada', 'Guyana' => 'Guiana',
			'Eritrea' => 'Eritreia', 'Bermuda' => 'Bermudas', 'Cayman Islands' => 'Ilhas Cayman',
			'Central African Republic' => 'Rep. Centro-Africana',
			// ── Full /countries/ coverage (spellings match the legacy
			//    wp_country_country rows so they PRE-MAP instead of creating).
			'Afghanistan' => 'Afeganistão', 'Aruba' => 'Aruba', 'Bahamas' => 'Bahamas',
			'Bangladesh' => 'Bangladesh', 'Barbados' => 'Barbados', 'Belize' => 'Belize',
			'Benin' => 'Benin', 'Bhutan' => 'Butão', 'Botswana' => 'Botswana',
			'Burundi' => 'Burundi', 'Cook Islands' => 'Ilhas Cook', 'Djibouti' => 'Djibouti',
			'Dominica' => 'Dominica', 'French Guiana' => 'Guiana Francesa',
			'French Polynesia' => 'Taiti', 'Guadeloupe' => 'Guadalupe', 'Guam' => 'Guam',
			'Hong Kong' => 'Hong Kong', 'Macao' => 'Macau', 'Maldives' => 'Maldivas',
			'Martinique' => 'Martinica', 'Mauritius' => 'Maurícia', 'Montserrat' => 'Montserrat',
			'Myanmar' => 'Mianmar', 'Nepal' => 'Nepal', 'New Caledonia' => 'Nova Caledônia',
			'Papua New Guinea' => 'Papua Nova Guiné', 'Puerto Rico' => 'Porto Rico',
			'Rwanda' => 'Ruanda', 'Saint Kitts and Nevis' => 'São Cristóvão e Nevis',
			'Saint Lucia' => 'Santa Lúcia', 'Saint Martin' => 'Saint Martin',
			'Saint Vincent and the Grenadines' => 'S. Vicente Granadinas',
			'Samoa' => 'Samoa', 'American Samoa' => 'Samoa Americana', 'San Marino' => 'San Marino',
			'Sao Tome and Principe' => 'São Tomé e Príncipe', 'Seychelles' => 'Seychelles',
			'Sierra Leone' => 'Serra Leoa', 'Solomon Islands' => 'Ilhas Salomão',
			'Somalia' => 'Somália', 'Suriname' => 'Suriname', 'Swaziland' => 'Essuatíni',
			'Eswatini' => 'Essuatíni', 'Tajikistan' => 'Tajiquistão', 'Timor-Leste' => 'Timor-Leste',
			'Tonga' => 'Tonga', 'Turkmenistan' => 'Turcomenistão',
			'Turks and Caicos Islands' => 'Turcas e Caicos', 'Uganda' => 'Uganda',
			'United Kingdom' => 'Grã-Bretanha', 'Vanuatu' => 'Vanuatu',
			'Virgin Islands, British' => 'Ilhas Virgens Ing.', 'Virgin Islands, U.S.' => 'Ilhas Virgens Am.',
			'Anguilla' => 'Anguila', 'Antigua and Barbuda' => 'Antigua e Barbuda',
			// No legacy row exists for these — they get CREATED with PT names.
			'Aland Islands' => 'Ilhas Åland', 'Antarctica' => 'Antártida',
			'Bouvet Island' => 'Ilha Bouvet', 'British Indian Ocean Territory' => 'Território Britânico do Oceano Índico',
			'Christmas Island' => 'Ilha do Natal', 'Cocos (Keeling) Islands' => 'Ilhas Cocos',
			'Falkland Islands (Malvinas)' => 'Ilhas Malvinas', 'French Southern Territories' => 'Terras Austrais Francesas',
			'Greenland' => 'Gronelândia', 'Guernsey' => 'Guernsey',
			'Heard Island and McDonald Islands' => 'Ilhas Heard e McDonald',
			'Holy See (Vatican City State)' => 'Vaticano', 'Jersey' => 'Jersey',
			'Kiribati' => 'Kiribati', 'Marshall Islands' => 'Ilhas Marshall', 'Mayotte' => 'Mayotte',
			'Micronesia, Federated States of' => 'Micronésia', 'Monaco' => 'Mónaco',
			'Nauru' => 'Nauru', 'Niue' => 'Niue', 'Norfolk Island' => 'Ilha Norfolk',
			'Northern Mariana Islands' => 'Marianas do Norte', 'Palau' => 'Palau',
			'Pitcairn' => 'Ilhas Pitcairn', 'Reunion' => 'Reunião', 'Saint Helena' => 'Santa Helena',
			'Saint Pierre and Miquelon' => 'São Pedro e Miquelão',
			'South Georgia and the South Sandwich Islands' => 'Geórgia do Sul e Sandwich do Sul',
			'Svalbard and Jan Mayen' => 'Svalbard e Jan Mayen', 'Tokelau' => 'Tokelau',
			'Tuvalu' => 'Tuvalu', 'United States Minor Outlying Islands' => 'Ilhas Menores dos EUA',
			'Wallis And Futuna' => 'Wallis e Futuna', 'Western Sahara' => 'Sara Ocidental',
		] );
	}

	/**
	 * Is this English country name explicitly known to the translation map?
	 * Membership test — works even when the PT spelling equals the English
	 * one (Chile, Jamaica, Peru, …), which a translated-or-not comparison
	 * cannot distinguish from unknown names.
	 */
	public static function country_known( string $name ): bool {
		return isset( self::country_map()[ $name ] );
	}

	/**
	 * One-time country mapping: fetch /countries/ and attach each Statorium
	 * country id + ISO code to the matching f365_country term (term meta
	 * `statorium_country_id` / `statorium_country_code`). Terms are matched by
	 * PT translation / normalized name; countries with a known PT name but no
	 * existing term are created (PT name). Unknowns are reported, never
	 * created in English. Returns [mapped, created, skipped[]].
	 */
	public function map_countries(): array {
		$data = $this->api->get( '/countries/' );
		if ( is_wp_error( $data ) || empty( $data['countries'] ) ) {
			return [ 0, 0, [ 'API error: ' . ( is_wp_error( $data ) ? $data->get_error_message() : 'empty' ) ] ];
		}

		global $wpdb;

		// Canonical name → term_id index of all existing terms. canon() folds
		// alias spellings (Fiji/Ilhas Fiji, Barém/Bahrein…) onto one key; on a
		// collision the legacy term (django_country_id meta) wins so imports
		// keep pointing at the original data.
		$index = [];
		foreach ( (array) get_terms( [ 'taxonomy' => 'f365_country', 'hide_empty' => false ] ) as $t ) {
			$key = F365_Statorium_Matcher::canon( $t->name );
			if ( ! isset( $index[ $key ] ) || get_term_meta( (int) $t->term_id, 'django_country_id', true ) ) {
				$index[ $key ] = (int) $t->term_id;
			}
		}

		// Normalized name → wp_country_country row id (the mirror table the
		// frontend joins for nationality/country display). Rows ordered by id
		// so the legacy row always wins a canon collision; later ids that
		// canon to the same country are duplicates from earlier plugin builds
		// (Barém vs Bahrein, Fiji vs Ilhas Fiji, …) and get merged below.
		$mirror      = [];
		$mirror_dups = [];
		foreach ( (array) $wpdb->get_results( "SELECT id, shortname, longname FROM {$wpdb->prefix}country_country ORDER BY id ASC" ) as $c ) {
			foreach ( [ $c->shortname, $c->longname ] as $n ) {
				$key = F365_Statorium_Matcher::canon( (string) $n );
				if ( ! $key ) {
					continue;
				}
				if ( ! isset( $mirror[ $key ] ) ) {
					$mirror[ $key ] = (int) $c->id;
				} elseif ( $mirror[ $key ] !== (int) $c->id ) {
					$mirror_dups[ $key ][ (int) $c->id ] = true;
				}
			}
		}

		$mapped  = 0;
		$created = 0;
		$skipped = [];

		foreach ( (array) $data['countries'] as $c ) {
			$sid  = (int) ( $c['id'] ?? 0 );
			$en   = (string) ( $c['name'] ?? '' );
			$code = (string) ( $c['code'] ?? '' );
			if ( ! $sid || '' === $en ) {
				continue;
			}

			$pt      = self::country_pt( $en );
			$term_id = $index[ F365_Statorium_Matcher::canon( $pt ) ]
				?? $index[ F365_Statorium_Matcher::canon( $en ) ]
				?? 0;

			if ( ! $term_id && self::country_known( $en ) ) {
				// Known PT name but no term yet — create it (PT only).
				$new = wp_insert_term( $pt, 'f365_country', [ 'slug' => sanitize_title( $pt ) ] );
				if ( ! is_wp_error( $new ) ) {
					$term_id = (int) $new['term_id'];
					$index[ F365_Statorium_Matcher::canon( $pt ) ] = $term_id;
					$created++;
					$this->log( "COUNTRY created term \"{$pt}\" ({$en})" );
				}
			}

			if ( $term_id ) {
				update_term_meta( $term_id, 'statorium_country_id', $sid );
				if ( $code ) {
					update_term_meta( $term_id, 'statorium_country_code', $code );
				}

				// Self-heal: an earlier build may have created a term for this
				// country under a different spelling (Barém vs Bahrein). Merge
				// it into the resolved term: keep the flag, move the tagged
				// posts, delete the duplicate. Legacy terms (django_country_id
				// meta) are never deleted — only their stray metas cleaned.
				foreach ( (array) get_terms( [
					'taxonomy'   => 'f365_country',
					'hide_empty' => false,
					'fields'     => 'ids',
					'meta_key'   => 'statorium_country_id',
					'meta_value' => $sid,
				] ) as $stale_id ) {
					$stale_id = (int) $stale_id;
					if ( ! $stale_id || $stale_id === $term_id ) {
						continue;
					}
					if ( get_term_meta( $stale_id, 'django_country_id', true ) ) {
						delete_term_meta( $stale_id, 'statorium_country_id' );
						delete_term_meta( $stale_id, 'statorium_country_code' );
						continue;
					}
					$stale = get_term( $stale_id, 'f365_country' );
					$flag  = get_term_meta( $stale_id, 'country_flag', true );
					if ( $flag && ! get_term_meta( $term_id, 'country_flag', true ) ) {
						update_term_meta( $term_id, 'country_flag', $flag );
					}
					foreach ( get_objects_in_term( $stale_id, 'f365_country' ) as $obj_id ) {
						wp_set_object_terms( (int) $obj_id, [ $term_id ], 'f365_country', true );
					}
					wp_delete_term( $stale_id, 'f365_country' );
					$this->log( 'COUNTRY merged duplicate term "' . ( $stale ? $stale->name : "#{$stale_id}" ) . "\" → term #{$term_id} ({$pt})" );
				}

				// Keep the mirror wp_country_country row in step — templates
				// join it for nationality/country names and flags.
				$mirror_key = F365_Statorium_Matcher::canon( $pt );
				$mirror_id  = $mirror[ $mirror_key ] ?? ( $mirror[ F365_Statorium_Matcher::canon( $en ) ] ?? 0 );
				if ( ! $mirror_id ) {
					$wpdb->query( $wpdb->prepare(
						"INSERT INTO {$wpdb->prefix}country_country (shortname, longname, slug, is_extinct)
						 VALUES (%s, %s, %s, 0)",
						$pt, $pt, sanitize_title( $pt )
					) );
					$mirror_id             = (int) $wpdb->insert_id;
					$mirror[ $mirror_key ] = $mirror_id;
					$this->log( "COUNTRY mirror row created \"{$pt}\" (id {$mirror_id})" );
				}

				// Self-heal duplicate mirror rows (same country, older-build
				// spelling): repoint every reference to the surviving row and
				// delete the duplicate.
				foreach ( array_keys( $mirror_dups[ $mirror_key ] ?? [] ) as $dup_id ) {
					if ( (int) $dup_id === $mirror_id ) {
						continue;
					}
					$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}team_team SET country_id = %d WHERE country_id = %d", $mirror_id, $dup_id ) );
					$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}person_person SET nationality_id = %d WHERE nationality_id = %d", $mirror_id, $dup_id ) );
					$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}stadium_stadium SET country_id = %d WHERE country_id = %d", $mirror_id, $dup_id ) );
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}country_country WHERE id = %d", $dup_id ) );
					$this->log( "COUNTRY merged duplicate mirror row #{$dup_id} → #{$mirror_id} ({$pt})" );
				}
				unset( $mirror_dups[ $mirror_key ] );

				update_term_meta( $term_id, 'country_country_id', $mirror_id );
				$mapped++;
			} else {
				$skipped[] = "{$en} ({$code})";
			}
		}

		$this->log( "COUNTRY map: mapped={$mapped} created={$created} skipped=" . count( $skipped ) );
		return [ $mapped, $created, $skipped ];
	}

	/**
	 * Attaches the theme's flags/{iso2}.svg as the ACF `country_flag` image
	 * field for any f365_country term map_countries() tagged with a
	 * statorium_country_code but that has no flag yet — almost always a
	 * genuinely new term (no legacy Django row ever had one to begin with),
	 * since legacy terms already carry their original flag attachment.
	 * Previously a manual "attach the theme SVG by hand" step (only ever hit
	 * twice: Coreia do Sul, RD Congo) — the SVG source, its ISO-2 filename
	 * convention, and the ACF field's expected value (an attachment ID, same
	 * as every existing flag) are all already known, so this is exactly the
	 * same class of backfill as save_media_image(): read-only against local
	 * files, additive, safe to (re-)run every cycle. Reads the file straight
	 * off disk rather than via sideload()'s download_url() — no need for a
	 * self-HTTP round-trip for an asset already on the same filesystem.
	 */
	public function backfill_country_flags(): int {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT c.term_id, c.meta_value AS code
			   FROM {$wpdb->termmeta} c
			  WHERE c.meta_key = 'statorium_country_code' AND c.meta_value != ''
			    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->termmeta} f
			                      WHERE f.term_id = c.term_id AND f.meta_key = 'country_flag' AND f.meta_value != '' )"
		);

		$attached = 0;
		foreach ( $rows as $row ) {
			$code = strtolower( trim( (string) $row->code ) );
			if ( ! preg_match( '/^[a-z]{2}$/', $code ) ) {
				continue;
			}
			$src = get_template_directory() . '/assets/images/flags/' . $code . '.svg';
			if ( ! file_exists( $src ) ) {
				continue;
			}
			if ( $this->dry_run ) {
				$attached++;
				continue;
			}

			$upload_dir = wp_upload_dir();
			$filename   = wp_unique_filename( $upload_dir['path'], $code . '.svg' );
			$dest       = trailingslashit( $upload_dir['path'] ) . $filename;
			if ( ! copy( $src, $dest ) ) {
				$this->log( "FLAG_FAIL term={$row->term_id} code={$code} — could not copy into uploads" );
				continue;
			}

			$att_id = wp_insert_attachment( [
				'post_mime_type' => 'image/svg+xml',
				'post_title'     => strtoupper( $code ) . ' flag',
				'post_status'    => 'inherit',
				'guid'           => trailingslashit( $upload_dir['url'] ) . $filename,
			], $dest );
			if ( is_wp_error( $att_id ) || ! $att_id ) {
				$this->log( "FLAG_FAIL term={$row->term_id} code={$code} — attachment insert failed" );
				continue;
			}

			update_term_meta( (int) $row->term_id, 'country_flag', $att_id );
			$this->log( "FLAG attached term={$row->term_id} code={$code} attachment={$att_id}" );
			$attached++;
		}
		return $attached;
	}

	/** f365_country term by mapped Statorium country id (0 when unmapped). */
	public static function country_term_by_stat_id( int $stat_country_id ): int {
		if ( ! $stat_country_id ) {
			return 0;
		}
		$terms = get_terms( [
			'taxonomy'   => 'f365_country',
			'hide_empty' => false,
			'number'     => 1,
			'fields'     => 'ids',
			'meta_key'   => 'statorium_country_id',
			'meta_value' => $stat_country_id,
		] );
		return ! is_wp_error( $terms ) && $terms ? (int) $terms[0] : 0;
	}

	/**
	 * Statorium position → the LEGACY f365_position term name. Legacy terms
	 * are lowercase english (goalkeeper/defender/midfielder/forward) — the
	 * theme translates them to PT for display and the /jogador/posicao/{slug}/
	 * pages use their slugs. PT term names here created duplicate terms
	 * (fixed by the v7 migration). Accepts strings and numeric codes.
	 */
	private function map_position( string $position ): string {
		$map = apply_filters( 'f365_statorium_position_map', [
			'Goalkeeper' => 'goalkeeper',
			'Defender'   => 'defender',
			'Midfielder' => 'midfielder',
			'Atacker'    => 'forward',
			'Attacker'   => 'forward',
			'Forward'    => 'forward',
			'1'          => 'goalkeeper',
			'2'          => 'defender',
			'3'          => 'midfielder',
			'4'          => 'forward',
		] );
		return (string) ( $map[ trim( $position ) ] ?? $map[ ucfirst( strtolower( trim( $position ) ) ) ] ?? $position );
	}

	/**
	 * Parses Statorium's "DD-MM-YYYY  (age)" date format (birthdate, team
	 * founded — same shape both places) into Y-m-d, discarding the age
	 * suffix. Public: reused by enrich_team_profile.
	 */
	public static function parse_statorium_date( string $raw ): string {
		if ( preg_match( '/(\d{2})-(\d{2})-(\d{4})/', $raw, $m ) ) {
			return "{$m[3]}-{$m[2]}-{$m[1]}";
		}
		return '';
	}

	private function parse_birthdate( string $raw ): string {
		return self::parse_statorium_date( $raw );
	}

	private function sideload( string $url, int $parent_id, string $title ): int {
		if ( ! $url || $this->dry_run ) {
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

	// ── Logging: wp-content/uploads/f365-logs/ ──────────────────────────────

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
