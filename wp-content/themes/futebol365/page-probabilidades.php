<?php
/**
 * Template Name: Match Probabilities (Scheduled games)
 *
 * Renders the date-scoped agenda (scheduled_games) grouped by competition,
 * a faithful port of the Django `scheduled_games` view. Data is read from the
 * cloned wp_-prefixed source tables via f365_get_scheduled_games_by_competitions();
 * all links resolve to WP permalinks (no Django ids in output).
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'f365_get_match_probabilities_by_competitions' ) ) :
	/**
	 * Build the date-scoped probabilities agenda grouped by competition.
	 *
	 * @param string $scheduled_date Y-m-d.
	 * @return array<int,array> Ordered competition blocks.
	 */
	function f365_get_match_probabilities_by_competitions( string $scheduled_date ): array {
		global $wpdb;

		$d = DateTime::createFromFormat( 'Y-m-d', $scheduled_date );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $scheduled_date ) {
			return array();
		}

		/*
		 * Cache the fully-assembled result per day. Mirrors the Django view's
		 * cache_page(15 min) on /probabilidades/. Stored in the WP object cache
		 * (persistent via Redis/Memcached when a drop-in is present, per-request
		 * otherwise), under the 'f365_probabilities' group. The TTL
		 * is short enough that the betting window / "now-90min" cutoff only drift
		 * within one TTL — the same staleness the page-cached Django view accepted.
		 */
		$cache_ttl = (int) apply_filters( 'f365_probabilities_cache_ttl', 15 * MINUTE_IN_SECONDS );
		$cache_key   = 'f365_probs_v1_' . $scheduled_date;
		$cache_group = 'f365_probabilities';

		if ( $cache_ttl > 0 ) {
			$found  = false;
			$cached = wp_cache_get( $cache_key, $cache_group, false, $found );
			if ( $found && is_array( $cached ) ) {
				return $cached;
			}
		}

		$day_start = $scheduled_date . ' 00:00:00';
		$day_end   = $d->modify( '+1 day' )->format( 'Y-m-d' ) . ' 00:00:00';

		// time_threshold = now - 90 minutes (Django: exclude date_time__lte=threshold).
		$now_ts         = (int) current_time( 'timestamp' );
		$time_threshold = gmdate( 'Y-m-d H:i:s', $now_ts - ( 90 * MINUTE_IN_SECONDS ) );

		$match_table    = $wpdb->prefix . 'competition_match';
		$teamstep_table = $wpdb->prefix . 'team_teamstep';
		$team_table     = $wpdb->prefix . 'team_team';
		$comp_table     = $wpdb->prefix . 'competition_competition';
		$fed_comp_table = $wpdb->prefix . 'federation_federationcompetition';
		$fed_table      = $wpdb->prefix . 'federation_federation';
		$country_table  = $wpdb->prefix . 'country_country';
		$phase_table    = $wpdb->prefix . 'competition_competitionphase';

		// Ordering mirrors group_by_competitions(): competitions ordered by country
		// slug (fallback to competition name), then federation shortname, then
		// federation_competition.order; c.id keeps a competition's rows contiguous.
		// Within a competition: date_time, then home team shortname (Visitado).
		// All link data comes from the source tables — no wp_posts joins.
		$query = $wpdb->prepare(
			"SELECT
				m.id                  AS match_id,
				m.date_time,
				m.match_round,
				m.situation,
				m.leg,
				m.group_home_team,
				m.group_visitor_team,
				m.group_phase_round,

				m.home_team_id        AS home_teamstep_id,
				ht_ts.team_id         AS home_team_django_id,
				ht_t.shortname        AS home_team_base_name,
				ht_t.team_type        AS home_team_type,
				ht_ts.step            AS home_team_step,
				ht_ts.modality        AS home_team_modality,
				ht_ts.gender          AS home_team_gender,
				ht_ts.edition         AS home_team_edition,

				m.visitor_team_id     AS visitor_teamstep_id,
				vt_ts.team_id         AS visitor_team_django_id,
				vt_t.shortname        AS visitor_team_base_name,
				vt_t.team_type        AS visitor_team_type,
				vt_ts.step            AS visitor_team_step,
				vt_ts.modality        AS visitor_team_modality,
				vt_ts.gender          AS visitor_team_gender,
				vt_ts.edition         AS visitor_team_edition,

				c.id                  AS competition_django_id,
				c.name                AS competition_name,
				c.edition             AS competition_edition,
				fc.id                 AS federation_competition_id,
				fc.`order`            AS fed_comp_order,
				fc.scope              AS scope,
				fed.shortname         AS federation_shortname,
				co.shortname          AS country_name,
				co.slug               AS country_slug,

				cp.name               AS phase_name,
				cp.phase_type         AS phase_type
			FROM
				{$match_table} m
			JOIN {$teamstep_table} ht_ts ON m.home_team_id    = ht_ts.id
			JOIN {$team_table}     ht_t  ON ht_ts.team_id      = ht_t.id
			JOIN {$teamstep_table} vt_ts ON m.visitor_team_id = vt_ts.id
			JOIN {$team_table}     vt_t  ON vt_ts.team_id      = vt_t.id
			JOIN {$comp_table}     c     ON m.competition_id   = c.id
			JOIN {$fed_comp_table} fc    ON c.federation_competition_id = fc.id
			JOIN {$fed_table}      fed   ON fc.federation_id    = fed.id
			LEFT JOIN {$country_table} co ON c.country_id       = co.id
			LEFT JOIN {$phase_table}   cp ON m.competition_phase_id = cp.id
			WHERE
				m.date_time >= %s AND m.date_time < %s
				AND m.date_time > %s
				AND fc.scope IN ( 'national_championship', 'national_league_cup' )
				AND ( m.situation IS NULL OR m.situation NOT IN ( 'finished', 'delayed', 'suspended', 'abrogated' ) )
			ORDER BY
				COALESCE(NULLIF(co.slug, ''), c.name) ASC,
				fed.shortname ASC,
				fc.`order` ASC,
				c.id ASC,
				m.date_time ASC,
				ht_t.shortname ASC",
			$day_start,
			$day_end,
			$time_threshold
		);
		
		$rows   = $wpdb->get_results( $query );
		$result = array();

		foreach ( (array) $rows as $r ) {
			$cid = (int) $r->competition_django_id;

			if ( ! isset( $result[ $cid ] ) ) {
				$prefix = ( '' !== (string) $r->country_name )
					? (string) $r->country_name
					: (string) $r->federation_shortname;

				// competition_title -> competition-index for this edition.
				$comp_url = home_url( sprintf( '/competicao/%d/', (int) $r->federation_competition_id ) );
				if ( '' !== (string) $r->competition_edition ) {
					$comp_url = add_query_arg( 'edition', (string) $r->competition_edition, $comp_url );
				}

				$result[ $cid ] = array(
					'competition' => array(
						'django_id'    => $cid,
						'name'         => (string) $r->competition_name,
						'title_prefix' => $prefix,
						'url'          => $comp_url,
						'country_name' => (string) $r->country_name,
						'country_slug' => (string) $r->country_slug,
						'flag_url'     => f365_sg_country_flag_url( (string) $r->country_slug ),
						'fed_comp_id'  => (int) $r->federation_competition_id,
						'edition'      => (string) $r->competition_edition,
					),
					'matches'     => array(),
				);
			}

			$result[ $cid ]['matches'][] = f365_sg_format_match( $r, $now_ts );
		}

		$result = array_values( $result );

		if ( $cache_ttl > 0 ) {
			wp_cache_set( $cache_key, $result, $cache_group, $cache_ttl );
		}

		return $result;
	}
endif;

if ( ! function_exists( 'f365_flush_probabilities_cache' ) ) :
	/**
	 * Delete the cached probabilities for a given day (or today when omitted).
	 * Call this after a results/fixtures import if you want the page to refresh
	 * before the TTL expires. To invalidate every cached day at once, bump the
	 * 'f365_probs_v1_' version token in f365_get_match_probabilities_by_competitions().
	 *
	 * @param string|null $scheduled_date Y-m-d, or null for today.
	 * @return void
	 */
	function f365_flush_probabilities_cache( ?string $scheduled_date = null ): void {
		$scheduled_date = $scheduled_date ?: current_time( 'Y-m-d' );
		wp_cache_delete( 'f365_probs_v1_' . $scheduled_date, 'f365_probabilities' );
	}
endif;

if ( ! function_exists( 'f365_sg_format_match' ) ) :
	/**
	 * Format a single match row: names, source-id links, 1/X/2 probabilities and
	 * the C (compare) / A (betting) action flags.
	 *
	 * @param object $r      Raw SQL row.
	 * @param int    $now_ts WP-local "now" timestamp (betting-window reference).
	 * @return array
	 */
	function f365_sg_format_match( object $r, int $now_ts ): array {
		$home_name    = f365_sg_team_name( (string) $r->home_team_base_name, (string) $r->home_team_step, (string) $r->home_team_modality, (string) $r->home_team_gender );
		$visitor_name = f365_sg_team_name( (string) $r->visitor_team_base_name, (string) $r->visitor_team_step, (string) $r->visitor_team_modality, (string) $r->visitor_team_gender );

		$home_did    = (int) $r->home_team_django_id;
		$visitor_did = (int) $r->visitor_team_django_id;

		// TeamStep.get_absolute_url: /equipa/{team_id}/jogos/?edition&modality&gender&step
		$home_url    = f365_sg_team_url( $home_did, (string) $r->home_team_edition, (string) $r->home_team_modality, (string) $r->home_team_gender, (string) $r->home_team_step );
		$visitor_url = f365_sg_team_url( $visitor_did, (string) $r->visitor_team_edition, (string) $r->visitor_team_modality, (string) $r->visitor_team_gender, (string) $r->visitor_team_step );

		// National teams additionally carry &competition (template parity).
		if ( $r->federation_competition_id && 'national' === $r->home_team_type ) {
			$home_url = add_query_arg( 'competition', (int) $r->federation_competition_id, $home_url );
		}
		if ( $r->federation_competition_id && 'national' === $r->visitor_team_type ) {
			$visitor_url = add_query_arg( 'competition', (int) $r->federation_competition_id, $visitor_url );
		}

		// get_match: /jogo/{match_id}/
		$match_url = home_url( sprintf( '/jogo/%d/', (int) $r->match_id ) );

		// 1 / X / 2 probabilities (Django get_probability parity).
		$prob = f365_sg_match_probability( (int) $r->home_teamstep_id, (int) $r->visitor_teamstep_id );

		// C — Comparar equipas (Match.get_compare_url parity).
		$compare_url = add_query_arg(
			array(
				'edition'   => $r->competition_edition,
				'step1'     => $r->home_team_step,
				'gender1'   => $r->home_team_gender,
				'modality1' => $r->home_team_modality,
				'step2'     => $r->visitor_team_step,
				'gender2'   => $r->visitor_team_gender,
				'modality2' => $r->visitor_team_modality,
			),
			home_url( sprintf( '/equipa/%d/comparar-com/%d/', $home_did, $visitor_did ) )
		);

		// A — Apostas / betting (show_match_link_actions parity):
		// is_available() (situation IS NULL) AND now within
		// [start-of-day(kickoff-3d), kickoff+105min].
		$betting_url  = '';
		$is_available = ( null === $r->situation || '' === (string) $r->situation );
		if ( $is_available && $r->date_time ) {
			$kickoff_ts = strtotime( (string) $r->date_time );
			$lower_ts   = strtotime( gmdate( 'Y-m-d 00:00:00', $kickoff_ts - ( 3 * DAY_IN_SECONDS ) ) );
			$upper_ts   = $kickoff_ts + ( 105 * MINUTE_IN_SECONDS );
			if ( $now_ts > $lower_ts && $now_ts < $upper_ts ) {
				$betting_url = esc_url( home_url( '/goto/' ) );;
			}
		}

		return array(
			'match_id'           => (int) $r->match_id,
			'datetime'           => (string) $r->date_time,
			'time'               => $r->date_time ? date_i18n( 'H:i', strtotime( (string) $r->date_time ) ) : '--:--',
			'situation'          => (string) $r->situation,
			'match_url'          => $match_url,
			'group_home_team'    => (string) $r->group_home_team,
			'group_visitor_team' => (string) $r->group_visitor_team,
			'phase_type'         => (string) $r->phase_type,
			'home_name'          => $home_name,
			'home_url'           => $home_url,
			'visitor_name'       => $visitor_name,
			'visitor_url'        => $visitor_url,
			'result'             => 'vs', // page only lists not-yet-finished matches
			'prob_1'             => $prob['1'],
			'prob_x'             => $prob['x'],
			'prob_2'             => $prob['2'],
			'compare_url'        => $compare_url,
			'betting_url'        => $betting_url,
		);
	}
endif;

if ( ! function_exists( 'f365_sg_team_url' ) ) :
	/**
	 * TeamStep.get_absolute_url parity:
	 * /equipa/{team_id}/jogos/?edition&modality&gender&step
	 *
	 * @param int    $team_id  Source team id.
	 * @param string $edition
	 * @param string $modality
	 * @param string $gender
	 * @param string $step
	 * @return string
	 */
	function f365_sg_team_url( int $team_id, string $edition, string $modality, string $gender, string $step ): string {
		return add_query_arg(
			array(
				'edition'  => $edition,
				'modality' => $modality,
				'gender'   => $gender,
				'step'     => $step,
			),
			home_url( sprintf( '/equipa/%d/jogos/', $team_id ) )
		);
	}
endif;

if ( ! function_exists( 'f365_sg_match_probability' ) ) :
	/**
	 * 1/X/2 percentages for a match — direct port of get_probability().
	 *
	 * @param int $home_teamstep_id
	 * @param int $visitor_teamstep_id
	 * @return array{1:int,x:int,2:int}
	 */
	function f365_sg_match_probability( int $home_teamstep_id, int $visitor_teamstep_id ): array {
		$home_w    = f365_sg_goal_avg_weights( f365_sg_goal_avg( $home_teamstep_id, 'home' ) );
		$visitor_w = f365_sg_goal_avg_weights( f365_sg_goal_avg( $visitor_teamstep_id, 'visitor' ) );

		$victory =
			$home_w[1] * $visitor_w[0] + $home_w[2] * $visitor_w[0] + $home_w[3] * $visitor_w[0] + $home_w[4] * $visitor_w[0] +
			$home_w[2] * $visitor_w[1] + $home_w[3] * $visitor_w[1] + $home_w[4] * $visitor_w[1] +
			$home_w[3] * $visitor_w[2] + $home_w[4] * $visitor_w[2] +
			$home_w[4] * $visitor_w[3];

		$draw =
			$visitor_w[0] * $home_w[0] + $visitor_w[1] * $home_w[1] + $visitor_w[2] * $home_w[2] +
			$visitor_w[3] * $home_w[3] + $visitor_w[4] * $home_w[4];

		$victory_perc = (int) ( $victory * 100 );
		$draw_perc    = (int) ( $draw * 100 );

		return array(
			'1' => $victory_perc,
			'x' => $draw_perc,
			'2' => 100 - $victory_perc - $draw_perc,
		);
	}
endif;

if ( ! function_exists( 'f365_sg_goal_avg_weights' ) ) :
	/**
	 * Map a goal average to its fixed weight vector (get_probability buckets).
	 *
	 * @param float $avg
	 * @return float[]
	 */
	function f365_sg_goal_avg_weights( float $avg ): array {
		if ( $avg <= 1.0 ) {
			return array( 0.45, 0.36, 0.14, 0.04, 0.01 );
		}
		if ( $avg <= 1.4 ) {
			return array( 0.30, 0.36, 0.22, 0.09, 0.03 );
		}
		if ( $avg <= 1.8 ) {
			return array( 0.20, 0.32, 0.26, 0.14, 0.08 );
		}
		return array( 0.14, 0.27, 0.27, 0.18, 0.14 );
	}
endif;

if ( ! function_exists( 'f365_sg_goal_avg' ) ) :
	/**
	 * Average goals over a teamstep's last 6 finished matches on the given side
	 * (_get_goal_avg parity: sum of scored goals / 6, rounded to 1 decimal). Reads
	 * the source competition_match table only.
	 *
	 * @param int    $teamstep_id
	 * @param string $side 'home' | 'visitor'.
	 * @return float
	 */
	function f365_sg_goal_avg( int $teamstep_id, string $side ): float {
		static $cache = array();
		$key = $side . ':' . $teamstep_id;
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}
		if ( $teamstep_id <= 0 ) {
			return $cache[ $key ] = 0.0;
		}

		global $wpdb;
		$match_table = $wpdb->prefix . 'competition_match';

		if ( 'home' === $side ) {
			$goal_expr  = 'CASE WHEN m.home_score_extra IS NOT NULL AND m.visitor_score_extra IS NOT NULL THEN m.home_score_extra ELSE m.home_score END';
			$side_where = 'm.home_team_id = %d';
		} else {
			$goal_expr  = 'CASE WHEN m.home_score_extra IS NOT NULL AND m.visitor_score_extra IS NOT NULL THEN m.visitor_score_extra ELSE m.visitor_score END';
			$side_where = 'm.visitor_team_id = %d';
		}

		$goals = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( g ), 0 ) FROM (
					SELECT COALESCE( {$goal_expr}, 0 ) AS g
					FROM {$match_table} m
					WHERE m.situation = 'finished' AND {$side_where}
					ORDER BY m.date_time DESC
					LIMIT 6
				) t",
				$teamstep_id
			)
		);

		// _get_goal_avg always divides by n_matches (6), not the actual count.
		return $cache[ $key ] = round( $goals / 6.0, 1 );
	}
endif;

if ( ! function_exists( 'f365_sg_team_name' ) ) :
	/**
	 * TeamStep display-name parity (shortname + FS / FM / step suffixes).
	 */
	function f365_sg_team_name( string $base_name, string $step, string $modality, string $gender ): string {
		static $step_mapping = array(
			'sub_23' => 'S23', 'sub_22' => 'S22', 'sub_21' => 'S21', 'sub_20' => 'S20',
			'sub_19' => 'S19', 'sub_19_b' => 'S19 B', 'sub_18' => 'S18', 'sub_17' => 'S17',
			'sub_17_b' => 'S17 B', 'sub_16' => 'S16', 'sub_15' => 'S15', 'sub_15_b' => 'S15 B',
			'sub_14' => 'S14', 'sub_13' => 'S13', 'sub_13_b' => 'S13 B', 'sub_12' => 'S12',
			'sub_12_b' => 'S12 B', 'sub_11' => 'S11', 'sub_11_b' => 'S11 B', 'sub_10' => 'S10',
			'sub_10_b' => 'S10 B', 'sub_9' => 'S9', 'sub_8' => 'S8', 'sub_7' => 'S7', 'sub_6' => 'S6',
		);

		$suffixes = array();
		if ( 'futsal' === $modality ) {
			$suffixes[] = 'FS';
		}
		if ( 'female' === $gender ) {
			$suffixes[] = 'FM';
		}

		if ( ! in_array( $step, array( 'principal', 'b_team', 'c_team', 'veterans', 'reservations' ), true ) ) {
			$suffixes[] = $step_mapping[ $step ] ?? ucfirst( str_replace( '_', ' ', $step ) );
		} elseif ( 'b_team' === $step ) {
			$suffixes[] = 'B';
		} elseif ( 'c_team' === $step ) {
			$suffixes[] = 'C';
		} elseif ( 'veterans' === $step ) {
			$suffixes[] = 'VET';
		} elseif ( 'reservations' === $step ) {
			$suffixes[] = 'RES';
		}

		return trim( $base_name . ( ! empty( $suffixes ) ? ' ' . implode( ' ', $suffixes ) : '' ) );
	}
endif;

if ( ! function_exists( 'f365_groups_on_matches' ) ) :
	/**
	 * Distinct group names for a competition block, sorted alphabetically by
	 * group_home_team (Portuguese-aware) so the group headers ($header_text) and
	 * their tables render in the same order as the app. mixed_groups phases are
	 * excluded (Competition.get_groups_on_matches parity).
	 *
	 * @param array<int,array> $matches
	 * @return array<int,string>
	 */
	function f365_groups_on_matches( array $matches ): array {
		$groups = array();
		foreach ( $matches as $match ) {
			if ( 'mixed_groups' === ( $match['phase_type'] ?? '' ) ) {
				continue;
			}
			foreach ( array( $match['group_home_team'] ?? '', $match['group_visitor_team'] ?? '' ) as $g ) {
				if ( '' !== $g && ! in_array( $g, $groups, true ) ) {
					$groups[] = $g;
				}
			}
		}

		f365_sg_sort_strings( $groups );
		return $groups;
	}
endif;

if ( ! function_exists( 'f365_sg_sort_strings' ) ) :
	/**
	 * In-place locale-aware alphabetical sort (Portuguese), with graceful
	 * fallbacks when the intl extension is unavailable.
	 *
	 * @param array<int,string> $items
	 * @return void
	 */
	function f365_sg_sort_strings( array &$items ): void {
		if ( class_exists( 'Collator' ) ) {
			$collator = collator_create( 'pt_PT' );
			if ( $collator instanceof Collator ) {
				$collator->sort( $items );
				return;
			}
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return strnatcasecmp( (string) $a, (string) $b );
			}
		);
	}
endif;

if ( ! function_exists( 'f365_sg_country_flag_url' ) ) :
	/**
	 * Resolve a country flag image URL from the f365_country term by slug, reusing
	 * the ACF `country_flag` field the competition header already relies on. Cached.
	 *
	 * @param string $slug Country slug from the source table.
	 * @return string Flag URL or '' when unavailable.
	 */
	function f365_sg_country_flag_url( string $slug ): string {
		static $cache = array();
		if ( '' === $slug ) {
			return '';
		}
		if ( isset( $cache[ $slug ] ) ) {
			return $cache[ $slug ];
		}

		$url  = '';
		$term = get_term_by( 'slug', $slug, 'f365_country' );
		if ( $term && ! is_wp_error( $term ) && function_exists( 'get_field' ) ) {
			$flag = get_field( 'country_flag', 'term_' . $term->term_id );
			if ( is_numeric( $flag ) ) {
				$url = (string) wp_get_attachment_image_url( (int) $flag, 'thumbnail' );
			} elseif ( is_array( $flag ) && ! empty( $flag['url'] ) ) {
				$url = (string) $flag['url'];
			} elseif ( is_string( $flag ) ) {
				$url = $flag;
			}
		}

		return $cache[ $slug ] = $url;
	}
endif;

/* ------------------------------------------------------------------------- */
/* Yoast SEO — dynamic title & meta description for the probabilities page.  */
/* ------------------------------------------------------------------------- */

if ( ! function_exists( 'f365_probabilities_selected_date' ) ) :
	/**
	 * Resolve the page's selected date: ?schedule_date_input=Y-m-d, else today.
	 * Same resolution the page template uses.
	 *
	 * @return string Y-m-d.
	 */
	function f365_probabilities_selected_date(): string {
		$requested = isset( $_GET['schedule_date_input'] ) ? sanitize_text_field( wp_unslash( $_GET['schedule_date_input'] ) ) : '';
		if ( '' !== $requested ) {
			$d = DateTime::createFromFormat( 'Y-m-d', $requested );
			if ( $d && $d->format( 'Y-m-d' ) === $requested ) {
				return $requested;
			}
		}
		return current_time( 'Y-m-d' );
	}
endif;


get_header();

// Selected date — today unless a valid ?schedule_date_input is in the URL.
$scheduled_date = function_exists( 'f365_probabilities_selected_date' )
	? f365_probabilities_selected_date()
	: current_time( 'Y-m-d' );

$scheduled_games_by_competitions = function_exists( 'f365_get_match_probabilities_by_competitions' )
	? f365_get_match_probabilities_by_competitions( $scheduled_date )
	: array();

$goto_url = esc_url( home_url( '/goto/' ) );
?>

<main id="primary" class="site-main single-article-page">
	<?php
	get_template_part(
		'template-parts/competition/f365-probabilidades-header',
		null,
		array(
			'scheduled_date' => $scheduled_date,
		)
	);
	?>
	<div class="ink-grid futebol365-container">
		<section class="left-column-block">
			<div class="breadcrumbs-f365">
				<div class="ink-navigation breadcrumbs">
					<?php yoast_breadcrumb(); ?>
				</div>
			</div>

			<?php if ( empty( $scheduled_games_by_competitions ) ) : ?>
				<h3 class="condensed-400 headerTitle red"><?php esc_html_e( 'Probabilidades', 'futebol365' ); ?></h3>
				<h6 class="no-info"><?php esc_html_e( 'Não existem probabilidades disponíveis para data pretendida', 'futebol365' ); ?></h6>
			<?php else : ?>

				<?php
				foreach ( $scheduled_games_by_competitions as $block ) :
					$comp    = $block['competition'];
					$matches = $block['matches'];
					$groups  = f365_groups_on_matches( $matches );

					$comp_title = ( '' !== $comp['title_prefix'] )
						? $comp['title_prefix'] . ': ' . $comp['name']
						: $comp['name'];

					$table_groups = ! empty( $groups ) ? $groups : array( null );

					foreach ( $table_groups as $group ) :
						if ( null === $group ) {
							$rows = $matches;
						} else {
							$rows = array_filter(
								$matches,
								static function ( $m ) use ( $group ) {
									return $m['group_home_team'] === $group || $m['group_visitor_team'] === $group;
								}
							);
						}
						if ( empty( $rows ) ) {
							continue;
						}
						$header_text = ( null !== $group ) ? $comp_title . ' - ' . $group : $comp_title;
						?>
						<h4 class="condensed-400 headerTitle red dflex item-center">
							<?php if ( ! empty( $comp['flag_url'] ) ) : ?>
								<i class="hide-tiny flag-icon quarter-right-space">
									<img src="<?php echo esc_url( $comp['flag_url'] ); ?>"
										alt="<?php echo esc_attr( $comp['country_name'] ); ?>"
										title="<?php echo esc_attr( $comp['country_name'] ); ?>"
										class="competition-flag">
								</i>
							<?php endif; ?>
							<?php if ( ! empty( $comp['url'] ) ) : ?>
								<a href="<?php echo esc_url( $comp['url'] ); ?>"><?php echo esc_html( $header_text ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $header_text ); ?>
							<?php endif; ?>
						</h4>

						<table class="ink-table alternating ink-table-f365 double-bottom-space">
							<thead>
								<tr>
									<th class="all-5 hide-tiny hide-small align-left text-nowrap">Hora</th>
									<th class="all-30 align-right hide-tiny hide-small">Visitado</th>
									<th class="all-5 align-left hide-tiny hide-small"></th>
									<th class="all-30 align-left hide-tiny hide-small">Visitante</th>
									<th class="all-45 tiny-40 align-left hide-xlarge hide-large hide-medium">Jogo</th>
									<th class="all-5 small-10 tiny-10 align-center text-nowrap">1</th>
									<th class="all-5 small-10 tiny-10 align-center text-nowrap">X</th>
									<th class="all-5 small-10 tiny-10 align-center text-nowrap">2</th>
									<th class="all-15 small-25 tiny-30"></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $rows as $match ) :
									$p1 = (int) $match['prob_1'];
									$px = (int) $match['prob_x'];
									$p2 = (int) $match['prob_2'];

									$cls1 = ( $p1 >= $px && $p1 >= $p2 ) ? 'matchProbabilityWinner' : '';
									$clsx = ( $px >= $p1 && $px >= $p2 ) ? 'matchProbabilityWinner' : '';
									$cls2 = ( $p2 >= $p1 && $p2 >= $px ) ? 'matchProbabilityWinner' : '';
									?>
									<tr>
										<td class="align-left hide-tiny hide-small text-nowrap"><?php echo esc_html( $match['time'] ); ?></td>

										<td class="align-right hide-tiny hide-small">
											<a href="<?php echo esc_url( $match['home_url'] ); ?>"><?php echo esc_html( $match['home_name'] ); ?></a>
										</td>

										<td class="align-center hide-tiny hide-small">
											<span class="ink-label ink-label-score silver">
												<?php if ( ! empty( $match['match_url'] ) ) : ?>
													<a href="<?php echo esc_url( $match['match_url'] ); ?>"><?php echo esc_html( $match['result'] ); ?></a>
												<?php else : ?>
													<?php echo esc_html( $match['result'] ); ?>
												<?php endif; ?>
											</span>
										</td>

										<td class="align-left hide-tiny hide-small">
											<a href="<?php echo esc_url( $match['visitor_url'] ); ?>"><?php echo esc_html( $match['visitor_name'] ); ?></a>
										</td>

										<td class="align-left hide-xlarge hide-large hide-medium">
											<a href="<?php echo esc_url( $match['home_url'] ); ?>"><?php echo esc_html( $match['home_name'] ); ?></a><span class="break"><a href="<?php echo esc_url( $match['visitor_url'] ); ?>"><?php echo esc_html( $match['visitor_name'] ); ?></a></span>
										</td>

										<td class="align-center text-nowrap <?php echo esc_attr( $cls1 ); ?>">
											<a href="<?php echo $goto_url; ?>" target="_blank" rel="nofollow noopener"><?php echo (int) $p1; ?>%</a>
										</td>
										<td class="align-center text-nowrap <?php echo esc_attr( $clsx ); ?>">
											<a href="<?php echo $goto_url; ?>" target="_blank" rel="nofollow noopener"><?php echo (int) $px; ?>%</a>
										</td>
										<td class="align-center text-nowrap <?php echo esc_attr( $cls2 ); ?>">
											<a href="<?php echo $goto_url; ?>" target="_blank" rel="nofollow noopener"><?php echo (int) $p2; ?>%</a>
										</td>

										<td>
											<?php if ( ! empty( $match['compare_url'] ) ) : ?>
												<a href="<?php echo esc_url( $match['compare_url'] ); ?>">
													<span class="ink-tooltip ink-button-related green" data-tip-text="Comparar equipas" data-tip-where="up" data-tip-color="green">C</span>
												</a>
											<?php endif; ?>
											<?php if ( ! empty( $match['betting_url'] ) ) : ?>
												<a href="<?php echo $match['betting_url']; ?>" target="_blank" rel="nofollow noopener">
													<span class="ink-tooltip ink-button-related orange" data-tip-text="Apostas" data-tip-where="up" data-tip-color="orange">A</span>
												</a>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endforeach; ?>
				<?php endforeach; ?>

			<?php endif; ?>

			<?php get_template_part( 'template-parts/template-front-page-posts' ); ?>
		</section>

		<section class="right-column-block">
			<?php
			if ( is_active_sidebar( 'post-sidebar' ) ) {
				dynamic_sidebar( 'post-sidebar' );
			} else {
				get_sidebar();
			}
			?>
		</section>
	</div>
</main>
<?php
get_footer();
