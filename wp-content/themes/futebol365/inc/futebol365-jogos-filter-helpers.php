<?php
/**
 * Futebol365 — Jogos filter option helpers (RELATIONAL: reads Django mirror
 * tables via $wpdb; no WP posts / postmeta / ACF).
 *
 *   Jogos        → futebol365_get_match_options()           value = option key
 *   Competições  → futebol365_get_team_competitions()        value = competition id
 *   Fases        → futebol365_get_team_competition_phases()  value = phase id (name fallback)
 *   Adversário   → futebol365_get_team_opponents()           value = opponent TeamStep id
 *
 * Tables:
 *   wp_team_team(id, team_type)
 *   wp_team_teamstep(id, team_id, step, modality, gender, edition)
 *   wp_competition_competition(id, name, edition)
 *   wp_competition_participant(competition_id, team_id→teamstep)
 *   wp_competition_competitionphase(id, competition_id, name, `order`)
 *   wp_competition_match(id, competition_id, home_team_id→teamstep, visitor_team_id→teamstep)
 *
 * Team id = the team post's 'django_id' meta (NOT the WP post id).
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

/** Resolve the Django team id from a WP team post id (cached per request). */
if ( ! function_exists( 'f365_jf_django_team' ) ) :
	function f365_jf_django_team( int $team_post_id ): int {
		static $cache = [];
		if ( isset( $cache[ $team_post_id ] ) ) {
			return $cache[ $team_post_id ];
		}
		$django = (int) get_post_meta( $team_post_id, 'django_id', true );
		$cache[ $team_post_id ] = $django;
		return $django;
	}
endif;

/** Read the current edition/step/modality/gender from $_GET (with defaults). */
if ( ! function_exists( 'f365_jf_state' ) ) :
	function f365_jf_state( int $team_post_id ): array {
		global $wpdb;

		$edition = isset( $_GET['edition'] ) ? sanitize_text_field( wp_unslash( $_GET['edition'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// National header sends "edition|competition_id"; keep only the edition.
		if ( '' !== $edition && false !== strpos( $edition, '|' ) ) {
			$edition = explode( '|', $edition )[0];
		}

		$step     = isset( $_GET['step'] )     ? sanitize_text_field( wp_unslash( $_GET['step'] ) )     : 'principal'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$modality = isset( $_GET['modality'] ) ? sanitize_text_field( wp_unslash( $_GET['modality'] ) ) : 'football';  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$gender   = isset( $_GET['gender'] )   ? sanitize_text_field( wp_unslash( $_GET['gender'] ) )   : 'male';      // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Default edition = team's newest teamstep edition for this step/gender/modality.
		if ( '' === $edition ) {
			$django = f365_jf_django_team( $team_post_id );
			if ( $django ) {
				$edition = (string) $wpdb->get_var( $wpdb->prepare(
					"SELECT edition FROM {$wpdb->prefix}team_teamstep
					 WHERE team_id = %d AND step = %s AND gender = %s AND modality = %s
					 ORDER BY ( edition + 0 ) DESC LIMIT 1",
					$django, $step, $gender, $modality
				) ); // phpcs:ignore WordPress.DB
			}
			if ( '' === $edition ) {
				$edition = gmdate( 'Y' );
			}
		}

		return [
			'edition'  => $edition,
			'step'     => $step,
			'modality' => $modality,
			'gender'   => $gender,
		];
	}
endif;

/** Resolve the team's current TeamStep id (or 0) from the custom table. */
if ( ! function_exists( 'f365_jf_teamstep' ) ) :
	function f365_jf_teamstep( int $team_post_id ): int {
		global $wpdb;
		$django = f365_jf_django_team( $team_post_id );
		if ( ! $django ) {
			return 0;
		}
		$s = f365_jf_state( $team_post_id );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}team_teamstep
			 WHERE team_id = %d AND edition = %s AND step = %s AND gender = %s AND modality = %s
			 LIMIT 1",
			$django, $s['edition'], $s['step'], $s['gender'], $s['modality']
		) ); // phpcs:ignore WordPress.DB
	}
endif;

/** Team type ('club' | 'national') from the custom table. */
if ( ! function_exists( 'f365_jf_team_type' ) ) :
	function f365_jf_team_type( int $team_post_id ): string {
		global $wpdb;
		$django = f365_jf_django_team( $team_post_id );
		if ( ! $django ) {
			return 'club';
		}
		$tt = $wpdb->get_var( $wpdb->prepare(
			"SELECT team_type FROM {$wpdb->prefix}team_team WHERE id = %d", $django
		) ); // phpcs:ignore WordPress.DB
		return $tt ? (string) $tt : 'club';
	}
endif;

/* ------------------------------------------------------------------ */
/* 1. Jogos — match_option choices                                    */
/* ------------------------------------------------------------------ */

if ( ! function_exists( 'futebol365_get_match_options' ) ) :
	function futebol365_get_match_options(): array {
		return [
			'matches'        => 'Jogos',
			'victories'      => 'Vitórias',
			'draws'          => 'Empates',
			'loses'          => 'Derrotas',
			'without_lose'   => 'Sem derrota',
			'without_win'    => 'Sem vitória',
			'scored_goals'   => 'Golos marcados',
			'suffered_goals' => 'Golos sofridos',
		];
	}
endif;

/* ------------------------------------------------------------------ */
/* 2. Competições — competitions the team participates in (relational) */
/*    value = competition id, label = competition name                */
/* ------------------------------------------------------------------ */

if ( ! function_exists( 'futebol365_get_team_competitions' ) ) :
	function futebol365_get_team_competitions( int $team_post_id ): array {
		global $wpdb;

		$django = f365_jf_django_team( $team_post_id );
		if ( ! $django ) {
			return [];
		}
		$s       = f365_jf_state( $team_post_id );
		$edition = $s['edition'];
		$p       = $wpdb->prefix;

		// Django: Participant (competition_participant) -> teamstep -> competition,
		// scoped to this team + edition + step/gender/modality.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT c.id AS ID, c.name AS post_title, c.edition AS edition
			 FROM {$p}competition_participant pa
			 INNER JOIN {$p}team_teamstep ts        ON ts.id = pa.team_id
			 INNER JOIN {$p}competition_competition c ON c.id = pa.competition_id
			 WHERE ts.team_id = %d
			   AND ts.step = %s AND ts.gender = %s AND ts.modality = %s
			   AND ( %s = '' OR c.edition = %s )
			 ORDER BY c.name ASC",
			$django, $s['step'], $s['gender'], $s['modality'], $edition, $edition
		) ); // phpcs:ignore WordPress.DB

		// Fallback: derive competitions from the team's matches (any teamstep of
		// this team) if the participant table has no rows for them.
		if ( empty( $rows ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DISTINCT c.id AS ID, c.name AS post_title, c.edition AS edition
				 FROM {$p}competition_match m
				 INNER JOIN {$p}team_teamstep ht ON ht.id = m.home_team_id
				 INNER JOIN {$p}team_teamstep vt ON vt.id = m.visitor_team_id
				 INNER JOIN {$p}competition_competition c ON c.id = m.competition_id
				 WHERE ( ht.team_id = %d OR vt.team_id = %d )
				   AND ( %s = '' OR c.edition = %s )
				 ORDER BY c.name ASC",
				$django, $django, $edition, $edition
			) ); // phpcs:ignore WordPress.DB
		}

		// Return objects with ->ID and ->post_title (filter-form markup unchanged).
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = (object) [
				'ID'         => (int) $r->ID,
				'post_title' => (string) $r->post_title,
			];
		}
		return $out;
	}
endif;

/* ------------------------------------------------------------------ */
/* 3. Fases — phases of the selected competition (relational)          */
/*    value = phase id, label = phase name                             */
/* ------------------------------------------------------------------ */

if ( ! function_exists( 'futebol365_get_team_competition_phases' ) ) :
	function futebol365_get_team_competition_phases( int $team_post_id ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$comp_id = isset( $_GET['competition'] ) ? absint( $_GET['competition'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// National team with no competition selected: use the first participation,
		// or the competition carried in the header edition value "edition|comp".
		if ( ! $comp_id ) {
			$ed = isset( $_GET['edition'] ) ? sanitize_text_field( wp_unslash( $_GET['edition'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( '' !== $ed && false !== strpos( $ed, '|' ) ) {
				$comp_id = (int) explode( '|', $ed )[1];
			}
		}
		if ( ! $comp_id && 'national' === f365_jf_team_type( $team_post_id ) ) {
			$comps = futebol365_get_team_competitions( $team_post_id );
			if ( $comps ) {
				$comp_id = (int) $comps[0]->ID;
			}
		}
		if ( ! $comp_id ) {
			return [];
		}

		// CompetitionPhase rows for this competition, ordered by `order`.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name FROM {$p}competition_competitionphase
			 WHERE competition_id = %d
			 ORDER BY `order` ASC, id ASC",
			$comp_id
		) ); // phpcs:ignore WordPress.DB

		$out = [];
		foreach ( (array) $rows as $r ) {
			$name = trim( (string) $r->name );
			if ( '' === $name ) {
				continue;
			}
			$out[] = (object) [
				'phase_id'   => (int) $r->id, // Django sends the phase id
				'phase_name' => $name,
			];
		}
		return $out;
	}
endif;

/* ------------------------------------------------------------------ */
/* 4. Adversário — opponents from the current TeamStep's matches       */
/*    value = opponent TeamStep id, label = opponent team name         */
/* ------------------------------------------------------------------ */

if ( ! function_exists( 'futebol365_get_team_opponents' ) ) :
	function futebol365_get_team_opponents( int $team_post_id ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$ts = f365_jf_teamstep( $team_post_id );
		if ( ! $ts ) {
			return [];
		}

		// Optional competition scope (matches the selected competition filter).
		$comp_id = isset( $_GET['competition'] ) ? absint( $_GET['competition'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$comp_sql = $comp_id ? $wpdb->prepare( ' AND m.competition_id = %d', $comp_id ) : ''; // phpcs:ignore WordPress.DB

		// Opponent = the other teamstep in this teamstep's matches.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT
				CASE WHEN m.home_team_id = %d THEN m.visitor_team_id ELSE m.home_team_id END AS opp_ts,
				t.shortname AS name
			 FROM {$p}competition_match m
			 INNER JOIN {$p}team_teamstep ots
				ON ots.id = CASE WHEN m.home_team_id = %d THEN m.visitor_team_id ELSE m.home_team_id END
			 LEFT JOIN {$p}team_team t ON t.id = ots.team_id
			 WHERE ( m.home_team_id = %d OR m.visitor_team_id = %d ){$comp_sql}",
			$ts, $ts, $ts, $ts
		) ); // phpcs:ignore WordPress.DB

		$opp = [];
		foreach ( (array) $rows as $r ) {
			$o = (int) $r->opp_ts;
			if ( $o && ! isset( $opp[ $o ] ) ) {
				$opp[ $o ] = (string) $r->name;
			}
		}
		asort( $opp );

		$out = [];
		foreach ( $opp as $ts_id => $name ) {
			$out[] = (object) [
				'ID'         => (int) $ts_id, // opponent TeamStep id
				'post_title' => (string) $name,
			];
		}
		return $out;
	}
endif;
