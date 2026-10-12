<?php
/**
 * Entity matcher — decides whether an incoming Statorium record already
 * exists in WordPress before anything is created.
 *
 * Resolution order (per record):
 *   0. api_source/api_id columns (handled by the importer before calling us)
 *   1. Candidates by normalized name (`_f365_norm_name` meta, accent/case
 *      insensitive, PT + EN names both tried)
 *   2. Signal scoring per entity type (country, birthdate, shirt number, …)
 *   3. Verdict: link | review (queued, never auto-written) | create
 *
 * Run `wp f365-statorium index-names` once so legacy posts have the
 * normalized-name meta this matcher joins against.
 *
 * @package F365_Statorium
 */

defined( 'ABSPATH' ) || exit;

class F365_Statorium_Matcher {

	const NORM_KEY      = '_f365_norm_name';
	const REVIEW_TABLE  = 'f365_stat_review';

	/** Post types the name index covers. */
	private const INDEXED_TYPES = [ 'f365_player', 'f365_team', 'f365_stadium', 'f365_coach', 'f365_competition' ];

	/** Minimum score (on top of the required name match) to auto-link. */
	private const THRESHOLDS = [
		'player' => 2, // name + country (or birthdate); shirt no is a tie-breaker
		'team'   => 2, // name + country
		'coach'  => 2,
		'season' => 2, // name + season year
		'venue'  => 0, // stadium names are distinctive — name match links
	];

	private $log;

	public function __construct( ?callable $log = null ) {
		$this->log = $log ?: static function ( $line ) {};
	}

	// ════════════════════════════════════════════════════════════════════════
	// NORMALIZATION + NAME INDEX
	// ════════════════════════════════════════════════════════════════════════

	public static function normalize( string $s ): string {
		$s = remove_accents( $s );
		$s = strtolower( $s );
		$s = preg_replace( '/[^a-z0-9]+/', ' ', $s );
		return trim( preg_replace( '/\s+/', ' ', $s ) );
	}

	/** Normalize + resolve through the alias table (canonical form). */
	public static function canon( string $s ): string {
		$n = self::normalize( $s );
		$aliases = self::name_aliases();
		return $aliases[ $n ] ?? $n;
	}

	/**
	 * Write `_f365_norm_name` rows for every indexed post.
	 *
	 * A post gets one row per DISTINCT normalized name form: its title PLUS
	 * its shortname/longname/fullname metas (teams, stadiums, players).
	 * Legacy team titles are shortnames ("Ac. Viseu") while the API sends
	 * official names ("Académico de Viseu Futebol Clube" — the legacy
	 * longname); without the alt-name rows the matcher cannot see them and
	 * creates duplicates. Idempotent — only missing rows are added.
	 * Returns number of rows added.
	 */
	public static function index_names(): int {
		global $wpdb;

		$types = "'" . implode( "','", self::INDEXED_TYPES ) . "'";

		// Pass 1 — titles for posts with no index row yet (fast path).
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_title FROM {$wpdb->posts} p
			  WHERE p.post_type IN ({$types}) AND p.post_status = 'publish'
			    AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} m
					 WHERE m.post_id = p.ID AND m.meta_key = '" . self::NORM_KEY . "'
				)"
		);
		$added = 0;
		foreach ( (array) $rows as $r ) {
			update_post_meta( (int) $r->ID, self::NORM_KEY, self::normalize( (string) $r->post_title ) );
			$added++;
		}

		// Pass 2 — alternative name forms from metas (entity types that have
		// them; matches excluded — they carry no name metas and dominate row
		// count). Existing rows are loaded once so the pass is idempotent.
		$alt_types = "'f365_team','f365_stadium','f365_player'";

		$existing = [];
		foreach ( $wpdb->get_results(
			"SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m
			   JOIN {$wpdb->posts} p ON p.ID = m.post_id
			  WHERE m.meta_key = '" . self::NORM_KEY . "' AND p.post_type IN ({$alt_types})"
		) as $e ) {
			$existing[ (int) $e->post_id ][ (string) $e->meta_value ] = true;
		}

		$px      = $wpdb->prefix;
		$sources = [
			// Created posts carry name metas…
			"SELECT m.post_id, m.meta_value AS v FROM {$wpdb->postmeta} m
			   JOIN {$wpdb->posts} p ON p.ID = m.post_id
			  WHERE m.meta_key IN ('shortname','longname','fullname')
			    AND m.meta_value <> '' AND p.post_status = 'publish'
			    AND p.post_type IN ({$alt_types})",
			// …but legacy names live ONLY in the mirror tables.
			"SELECT p.ID AS post_id, t.shortname AS v FROM {$px}team_team t
			   JOIN {$wpdb->posts} p ON p.post_type = 'f365_team' AND p.post_status = 'publish' AND p.legacy_id = t.id WHERE t.shortname <> ''",
			"SELECT p.ID AS post_id, t.longname AS v FROM {$px}team_team t
			   JOIN {$wpdb->posts} p ON p.post_type = 'f365_team' AND p.post_status = 'publish' AND p.legacy_id = t.id WHERE t.longname <> ''",
			"SELECT p.ID AS post_id, s.shortname AS v FROM {$px}stadium_stadium s
			   JOIN {$wpdb->posts} p ON p.post_type = 'f365_stadium' AND p.post_status = 'publish' AND p.legacy_id = s.id WHERE s.shortname <> ''",
			"SELECT p.ID AS post_id, s.longname AS v FROM {$px}stadium_stadium s
			   JOIN {$wpdb->posts} p ON p.post_type = 'f365_stadium' AND p.post_status = 'publish' AND p.legacy_id = s.id WHERE s.longname <> ''",
			"SELECT p.ID AS post_id, pr.shortname AS v FROM {$px}person_player pl
			   JOIN {$px}person_person pr ON pr.id = pl.person_id
			   JOIN {$wpdb->posts} p ON p.post_type = 'f365_player' AND p.post_status = 'publish' AND p.legacy_id = pl.id WHERE pr.shortname <> ''",
			"SELECT p.ID AS post_id, pr.longname AS v FROM {$px}person_player pl
			   JOIN {$px}person_person pr ON pr.id = pl.person_id
			   JOIN {$wpdb->posts} p ON p.post_type = 'f365_player' AND p.post_status = 'publish' AND p.legacy_id = pl.id WHERE pr.longname <> ''",
		];

		foreach ( $sources as $sql ) {
			foreach ( (array) $wpdb->get_results( $sql ) as $a ) {
				$pid  = (int) $a->post_id;
				$norm = self::normalize( (string) $a->v );
				if ( '' === $norm || isset( $existing[ $pid ][ $norm ] ) ) {
					continue;
				}
				add_post_meta( $pid, self::NORM_KEY, $norm );
				$existing[ $pid ][ $norm ] = true;
				$added++;
			}
		}

		// Pass 3 — stadium generic-word variants: legacy names drop
		// "Estádio"/"Stadium" ("SoFi", "Azteca") while the API sends the full
		// form — index every stadium under BOTH so they match.
		$stad_rows = $wpdb->get_results(
			"SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m
			   JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'f365_stadium' AND p.post_status = 'publish'
			  WHERE m.meta_key = '" . self::NORM_KEY . "'"
		);
		foreach ( (array) $stad_rows as $sr ) {
			$pid     = (int) $sr->post_id;
			$stripped = trim( preg_replace( '/\b(estadio|stadium)\b/', '', (string) $sr->meta_value ) );
			$stripped = trim( preg_replace( '/\s+/', ' ', $stripped ) );
			if ( '' === $stripped || strlen( $stripped ) < 3 || $stripped === (string) $sr->meta_value || isset( $existing[ $pid ][ $stripped ] ) ) {
				continue;
			}
			add_post_meta( $pid, self::NORM_KEY, $stripped );
			$existing[ $pid ][ $stripped ] = true;
			$added++;
		}

		return $added;
	}

	// ════════════════════════════════════════════════════════════════════════
	// REVIEW QUEUE
	// ════════════════════════════════════════════════════════════════════════

	public static function review_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::REVIEW_TABLE;
	}

	public static function ensure_review_table(): void {
		global $wpdb;
		$table   = self::review_table();
		$charset = $wpdb->get_charset_collate();
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entity_type VARCHAR(20) NOT NULL,
			stat_id BIGINT UNSIGNED NOT NULL,
			label VARCHAR(255) NOT NULL DEFAULT '',
			payload LONGTEXT NULL,
			candidates LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY entity_stat (entity_type, stat_id)
		) {$charset}" );
	}

	private function queue_review( string $type, int $stat_id, string $label, array $signals, array $candidates, string $status = 'pending' ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . self::review_table() .
			' (entity_type, stat_id, label, payload, candidates, status, created_at)
			  VALUES (%s, %d, %s, %s, %s, %s, %s)',
			$type, $stat_id, $label,
			wp_json_encode( $signals ),
			wp_json_encode( $candidates ),
			$status, current_time( 'mysql' )
		) );
		if ( 'pending' === $status ) {
			( $this->log )( "REVIEW {$type} stat_id={$stat_id} \"{$label}\" — " . count( $candidates ) . ' candidate(s) below threshold' );
		}
	}

	/**
	 * Hard contradiction between the incoming record and a candidate:
	 * both sides KNOW a birthdate and they differ. Absent data never
	 * contradicts.
	 */
	/**
	 * Same birthdate within tolerance. Statorium and the legacy Django data
	 * frequently disagree by ONE day on the same person (timezone-shifted
	 * DOB sources — e.g. Beiranvand 21-09 vs 22-09-1992); a 1-day delta is
	 * agreement, not contradiction.
	 */
	public static function dates_close( string $a, string $b ): bool {
		$ta = strtotime( substr( $a, 0, 10 ) );
		$tb = strtotime( substr( $b, 0, 10 ) );
		return $ta && $tb && abs( $ta - $tb ) <= DAY_IN_SECONDS;
	}

	private function contradicts( int $wp_id, array $signals, string $type = '' ): bool {
		global $wpdb;

		// TEAMS: both sides KNOW a country and they differ → contradiction.
		// Club shortnames repeat across countries (Statorium "CS Marítimo"
		// [Madeira, Primeira Liga] exact-matched the legacy "CS Marítimo"
		// [Venezuela] and auto-linked — wrong club, wrong continent). The
		// importer passes the league's country for domestic-league clubs;
		// naming variants are neutralized by canon(). Absent data never
		// contradicts. NOT applied to players: the two sources genuinely
		// disagree on some players' nationality (see audit-countries), and
		// the birthdate rule below already arbitrates those.
		if ( 'team' === $type && ! empty( $signals['country'] ) ) {
			$pub = $this->public_id( $wp_id );
			if ( $pub ) {
				$c = $wpdb->get_row( $wpdb->prepare(
					"SELECT c.shortname, c.longname FROM {$wpdb->prefix}team_team t
					   JOIN {$wpdb->prefix}country_country c ON c.id = t.country_id
					  WHERE t.id = %d", $pub
				) );
				if ( $c ) {
					$want = self::canon( (string) $signals['country'] );
					if ( $want && self::canon( (string) $c->shortname ) !== $want && self::canon( (string) $c->longname ) !== $want ) {
						return true;
					}
				}
			}
		}

		if ( empty( $signals['birthdate'] ) ) {
			return false;
		}
		$bd = (string) get_post_meta( $wp_id, 'birthdate', true );
		if ( ! $bd ) {
			$pub = $this->public_id( $wp_id );
			if ( $pub ) {
				$bd = (string) $wpdb->get_var( $wpdb->prepare(
					"SELECT pe.birth_date FROM {$wpdb->prefix}person_player pl
					   JOIN {$wpdb->prefix}person_person pe ON pe.id = pl.person_id
					  WHERE pl.id = %d", $pub
				) );
			}
		}
		if ( ! $bd || '0000-00-00' === $bd ) {
			return false;
		}
		return ! self::dates_close( $bd, (string) $signals['birthdate'] );
	}

	// ════════════════════════════════════════════════════════════════════════
	// MATCHING
	// ════════════════════════════════════════════════════════════════════════

	/**
	 * Find an existing WP post for a Statorium record.
	 *
	 * $signals:
	 *   names     string[]  candidate names (PT + EN)      — required
	 *   country   string    country name                   — optional
	 *   birthdate string    Y-m-d                          — optional
	 *   shirt     string    shirt number                   — optional
	 *   team_type string    'national' | 'club'            — optional
	 *   season    string    season year, e.g. '2026'       — optional
	 *
	 * Returns [ 'verdict' => 'link'|'review'|'create', 'wp_id' => int,
	 *           'score' => int, 'method' => string ].
	 */
	/** Status of an existing review row: '' | pending | linked | create | ignored. */
	public static function review_status( string $type, int $stat_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT status FROM ' . self::review_table() . ' WHERE entity_type = %s AND stat_id = %d',
			$type, $stat_id
		) );
	}

	public static function set_review_status( int $row_id, string $status ): void {
		global $wpdb;
		$wpdb->update( self::review_table(), [ 'status' => $status ], [ 'id' => $row_id ] );
	}

	/**
	 * Brazilian-Portuguese (Statorium `pt`) → European-Portuguese (site)
	 * aliases, applied on NORMALIZED names. Extend via filter as new
	 * variants appear.
	 */
	public static function name_aliases(): array {
		return apply_filters( 'f365_statorium_name_aliases', [
			'ira'                  => 'irao',
			'egito'                => 'egipto',
			'tchequia'             => 'chequia',
			'catar'                => 'qatar',
			'bosnia e herzegovina' => 'bosnia',
			'rep coreia'           => 'coreia do sul',
			'rep congo'            => 'rd congo',
			'paises baixos'        => 'holanda',
			'rep irlanda'          => 'irlanda',
			'amsterdam'            => 'amesterdao',
			'moscou'               => 'moscovo',
			'estonia'              => 'estonia',
			// Earlier plugin builds created these spellings before the map was
			// aligned to the legacy country names — both must canon the same.
			'barem'                    => 'bahrein',
			'ilhas caimao'             => 'ilhas cayman',
			'fiji'                     => 'ilhas fiji',
			'guine'                    => 'guine conakri',
			'trindade e tobago'        => 'trinidad e tobago',
			'republica dominicana'     => 'rep dominicana',
			'republica centro africana' => 'rep centro africana',
			'emirados arabes unidos'   => 'emirados arabes',
			// The legacy Django data itself is inconsistent between mirror
			// tables for this one: federation_federationcompetition (the
			// fedcomp) names it "Liga dos Campeões", but every
			// competition_competition (edition) row under it drops "dos" —
			// confirmed against all 6+ legacy editions. find_legacy_competition()
			// matches on the CONFIG's legacy_name ("Liga dos Campeões", also
			// correct for the fedcomp-level match), so an edition-level
			// candidate named "Liga Campeões" never matched, creating a
			// duplicate competition for every season instead of linking.
			'liga campeoes' => 'liga dos campeoes',
		] );
	}

	/**
	 * Diagnostic twin of find(): returns the full picture — review state,
	 * every name candidate with its score and contradiction flag, and the
	 * verdict find() would reach — WITHOUT queueing anything. Used by the
	 * `why` CLI command.
	 */
	public function explain( string $type, string $post_type, int $stat_id, array $signals ): array {
		global $wpdb;
		$out = [ 'review_status' => self::review_status( $type, $stat_id ) ];

		$names = array_filter( array_unique( array_map( [ self::class, 'normalize' ], (array) ( $signals['names'] ?? [] ) ) ) );
		$canon = array_filter( array_unique( array_map( [ self::class, 'canon' ], (array) ( $signals['names'] ?? [] ) ) ) );
		$names = array_unique( array_merge( $names, $canon ) );
		$out['normalized_names'] = array_values( $names );

		$in         = "'" . implode( "','", array_map( 'esc_sql', $names ) ) . "'";
		$candidates = $names ? $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT nm.post_id FROM {$wpdb->postmeta} nm
			   JOIN {$wpdb->posts} p ON p.ID = nm.post_id AND p.post_type = %s AND p.post_status = 'publish'
			  WHERE nm.meta_key = %s AND nm.meta_value IN ({$in}) LIMIT 20",
			$post_type, self::NORM_KEY
		) ) : [];

		$out['fuzzy_used'] = false;
		if ( ! $candidates && ! empty( $signals['birthdate'] ) ) {
			$like = [];
			foreach ( $names as $n ) {
				$parts = explode( ' ', $n );
				if ( count( $parts ) >= 2 ) {
					$like[] = $wpdb->prepare( 'nm.meta_value LIKE %s', $wpdb->esc_like( $parts[0] ) . '%' . $wpdb->esc_like( end( $parts ) ) );
				}
			}
			if ( $like ) {
				$candidates = $wpdb->get_col( $wpdb->prepare(
					"SELECT DISTINCT nm.post_id FROM {$wpdb->postmeta} nm
					   JOIN {$wpdb->posts} p ON p.ID = nm.post_id AND p.post_type = %s AND p.post_status = 'publish'
					  WHERE nm.meta_key = %s AND ( " . implode( ' OR ', $like ) . ' ) LIMIT 20',
					$post_type, self::NORM_KEY
				) );
				$out['fuzzy_used'] = (bool) $candidates;
			}
		}

		$out['candidates'] = [];
		foreach ( $candidates as $cid ) {
			$cid              = (int) $cid;
			$score            = $this->score( $cid, $signals );
			$out['candidates'][] = [
				'wp_id'       => $cid,
				'title'       => get_the_title( $cid ),
				'score'       => (int) ( $score['score'] ?? 0 ),
				'signals'     => $score['signals'] ?? [],
				'contradicts' => $this->contradicts( $cid, $signals, $type ),
				'already_api' => (string) $wpdb->get_var( $wpdb->prepare( "SELECT api_id FROM {$wpdb->posts} WHERE ID = %d", $cid ) ),
			];
		}
		return $out;
	}

	public function find( string $type, string $post_type, int $stat_id, array $signals ): array {
		// A human decision from the review queue overrides matching.
		$review = self::review_status( $type, $stat_id );
		if ( 'create' === $review ) {
			return [ 'verdict' => 'create', 'wp_id' => 0, 'score' => 0, 'method' => 'review-approved-create' ];
		}
		if ( 'pending' === $review || 'ignored' === $review ) {
			return [ 'verdict' => 'review', 'wp_id' => 0, 'score' => 0, 'method' => 'awaiting-review' ];
		}

		$names = array_filter( array_unique( array_map(
			[ self::class, 'normalize' ],
			(array) ( $signals['names'] ?? [] )
		) ) );

		// Expand with pt-BR → pt-PT aliases so "Irã" finds "Irão".
		$aliases = self::name_aliases();
		foreach ( $names as $n ) {
			if ( isset( $aliases[ $n ] ) ) {
				$names[] = $aliases[ $n ];
			}
		}
		$names = array_unique( $names );
		if ( ! $names ) {
			return [ 'verdict' => 'create', 'wp_id' => 0, 'score' => 0, 'method' => 'no-name' ];
		}

		global $wpdb;
		$in         = "'" . implode( "','", array_map( 'esc_sql', $names ) ) . "'";
		$candidates = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} nm ON nm.post_id = p.ID AND nm.meta_key = %s AND nm.meta_value IN ({$in})
			  WHERE p.post_type = %s AND p.post_status = 'publish'
			    AND ( p.api_source IS NULL OR p.api_id IS NULL )
			  LIMIT 20",
			self::NORM_KEY, $post_type
		) );

		// Fuzzy fallback: middle-name variants ("Juan Fernando Quintero" vs
		// legacy "Juan Quintero") share first + last token but no exact form.
		// Only attempted when exact matching finds NOTHING and a hard signal
		// (birthdate) exists to corroborate — and a fuzzy hit may only LINK
		// when the birthdate actually confirms (enforced below).
		$fuzzy = false;
		if ( ! $candidates && ! empty( $signals['birthdate'] ) ) {
			$like = [];
			foreach ( $names as $n ) {
				$parts = explode( ' ', $n );
				if ( count( $parts ) >= 2 ) {
					$like[] = $wpdb->prepare( 'nm.meta_value LIKE %s', $wpdb->esc_like( $parts[0] ) . '%' . $wpdb->esc_like( end( $parts ) ) );
				}
			}
			if ( $like ) {
				$candidates = $wpdb->get_col( $wpdb->prepare(
					"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
					   JOIN {$wpdb->postmeta} nm ON nm.post_id = p.ID AND nm.meta_key = %s
					        AND ( " . implode( ' OR ', $like ) . " )
					  WHERE p.post_type = %s AND p.post_status = 'publish'
					    AND ( p.api_source IS NULL OR p.api_id IS NULL )
					  LIMIT 20",
					self::NORM_KEY, $post_type
				) );
				$fuzzy = (bool) $candidates;
			}
		}

		if ( ! $candidates ) {
			return [ 'verdict' => 'create', 'wp_id' => 0, 'score' => 0, 'method' => 'no-candidate' ];
		}

		$scored = [];
		foreach ( $candidates as $wp_id ) {
			$scored[ (int) $wp_id ] = $this->score( (int) $wp_id, $signals );
		}
		arsort( $scored );

		$best_id    = (int) array_key_first( $scored );
		$best       = $scored[ $best_id ];
		$best_score = $best['score'];
		$threshold  = self::THRESHOLDS[ $type ] ?? 2;

		// Ambiguity: two candidates share the top score → human decides.
		$top_ids = array_keys( array_filter( $scored, fn( $s ) => $s['score'] === $best_score ) );

		// A fuzzy name hit may only link when the BIRTHDATE confirmed it —
		// name shape + country alone would link unrelated "João Silva"s.
		$fuzzy_confirmed = ! $fuzzy || false !== strpos( (string) $best['signals'], 'birthdate' );

		if ( $best_score >= $threshold && 1 === count( $top_ids ) && $fuzzy_confirmed ) {
			return [ 'verdict' => 'link', 'wp_id' => $best_id, 'score' => $best_score, 'method' => ( $fuzzy ? 'fuzzy-name+' : 'name+' ) . $best['signals'] ];
		}

		$cand_dump = array_map(
			fn( $id ) => [ 'wp_id' => $id, 'title' => get_the_title( $id ), 'score' => $scored[ $id ]['score'], 'signals' => $scored[ $id ]['signals'] ],
			array_keys( $scored )
		);
		$label = (string) ( $signals['names'][0] ?? '' );

		// ── Unattended mode: the code decides, nothing waits for a human. ───
		$mode = class_exists( 'F365_Statorium_Importer' )
			? (string) ( F365_Statorium_Importer::settings()['review_mode'] ?? 'auto' )
			: 'auto';

		if ( 'auto' === $mode ) {
			// One name-candidate and nothing contradicting → link it.
			// (Never on a mere fuzzy name-shape hit without birthdate proof.)
			if ( 1 === count( $top_ids ) && $fuzzy_confirmed && ! $this->contradicts( $best_id, $signals, $type ) ) {
				$this->queue_review( $type, $stat_id, $label, $signals, $cand_dump, 'auto_linked' );
				( $this->log )( "AUTOLINK {$type} stat_id={$stat_id} \"{$label}\" → wp_id={$best_id} (single candidate, no contradiction)" );
				return [ 'verdict' => 'link', 'wp_id' => $best_id, 'score' => $best_score, 'method' => 'auto:single-candidate' ];
			}
			// Contradiction or several unconfirmed candidates → create a new
			// record. A wrong link corrupts legacy data forever; a duplicate
			// is healed later by auto-merge/rescore once richer data arrives.
			$this->queue_review( $type, $stat_id, $label, $signals, $cand_dump, 'auto_created' );
			( $this->log )( "AUTOCREATE {$type} stat_id={$stat_id} \"{$label}\" — " . count( $scored ) . ' unconfirmed candidate(s)' );
			return [ 'verdict' => 'create', 'wp_id' => 0, 'score' => $best_score, 'method' => 'auto:ambiguous-create' ];
		}

		$this->queue_review( $type, $stat_id, $label, $signals, $cand_dump );
		return [ 'verdict' => 'review', 'wp_id' => 0, 'score' => $best_score, 'method' => 'ambiguous' ];
	}

	/** Candidate's public id (legacy_id column or django_* meta). */
	private function public_id( int $wp_id ): int {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d", $wp_id ) );
		if ( ! $id ) {
			$id = (int) get_post_meta( $wp_id, 'django_player_id', true )
				?: (int) get_post_meta( $wp_id, 'django_id', true );
		}
		return $id;
	}

	/** Score one candidate against the extra signals. */
	private function score( int $wp_id, array $signals ): array {
		global $wpdb;
		$score = 0;
		$hit   = [];
		$pub   = $this->public_id( $wp_id );

		if ( ! empty( $signals['country'] ) ) {
			$raw  = (string) $signals['country'];
			$want = array_unique( [
				self::normalize( $raw ),
				self::normalize( class_exists( 'F365_Statorium_Importer' ) ? F365_Statorium_Importer::country_pt( $raw ) : $raw ),
			] );

			$names = [];
			$terms = get_the_terms( $wp_id, 'f365_country' );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $t ) {
					$names[] = $t->name;
				}
			}
			// Legacy players keep nationality in the mirror tables, not terms
			// (person_player.id = player public id → person_person via person_id).
			if ( $pub ) {
				$mirror = $wpdb->get_row( $wpdb->prepare(
					"SELECT c.shortname, c.longname FROM {$wpdb->prefix}person_player pl
					   JOIN {$wpdb->prefix}person_person pe ON pe.id = pl.person_id
					   LEFT JOIN {$wpdb->prefix}country_country c ON c.id = pe.nationality_id
					  WHERE pl.id = %d", $pub
				) );
				if ( $mirror ) {
					$names[] = (string) $mirror->shortname;
					$names[] = (string) $mirror->longname;
				}
				// Legacy TEAMS likewise: country lives in team_team, not terms —
				// without this a club candidate can never earn the +2 country
				// score its league context provides.
				$tmirror = $wpdb->get_row( $wpdb->prepare(
					"SELECT c.shortname, c.longname FROM {$wpdb->prefix}team_team t
					   JOIN {$wpdb->prefix}country_country c ON c.id = t.country_id
					  WHERE t.id = %d", $pub
				) );
				if ( $tmirror ) {
					$names[] = (string) $tmirror->shortname;
					$names[] = (string) $tmirror->longname;
				}
			}
			foreach ( $names as $n ) {
				if ( '' !== $n && in_array( self::normalize( $n ), $want, true ) ) {
					$score += 2;
					$hit[]  = 'country';
					break;
				}
			}
		}

		if ( ! empty( $signals['birthdate'] ) ) {
			$bd = (string) get_post_meta( $wp_id, 'birthdate', true );
			if ( ! $bd && $pub ) {
				// Legacy birthdates live in wp_person_person (via person_player).
				$bd = (string) $wpdb->get_var( $wpdb->prepare(
					"SELECT pe.birth_date FROM {$wpdb->prefix}person_player pl
					   JOIN {$wpdb->prefix}person_person pe ON pe.id = pl.person_id
					  WHERE pl.id = %d", $pub
				) );
			}
			if ( $bd && '0000-00-00' !== $bd && self::dates_close( $bd, (string) $signals['birthdate'] ) ) {
				$score += 2;
				$hit[]  = 'birthdate';
			}
		}

		if ( ! empty( $signals['shirt'] ) ) {
			$no = (string) get_post_meta( $wp_id, 'player_number', true );
			if ( '' !== $no && (int) $no === (int) $signals['shirt'] ) {
				$score += 1;
				$hit[]  = 'shirt';
			}
		}

		if ( ! empty( $signals['team_type'] ) ) {
			if ( get_post_meta( $wp_id, 'team_type', true ) === $signals['team_type'] ) {
				$score += 1;
				$hit[]  = 'team_type';
			}
		}

		if ( ! empty( $signals['season'] ) ) {
			$season = (string) get_post_meta( $wp_id, 'season', true );
			if ( $season === (string) $signals['season'] ) {
				$score += 2;
				$hit[]  = 'season';
			}
		}

		return [ 'score' => $score, 'signals' => implode( '+', $hit ) ?: 'name-only' ];
	}
}
