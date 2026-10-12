<?php
/**
 * Match — single detail  /jogo/{django_match_id}/
 *
 * Faithful WordPress port of the Django `competition.views.get_match` view
 * (template `competition/competition_detail_game.html` + its includes).
 *
 * Every relational block is read directly from the cloned, `wp_`-prefixed
 * custom tables that mirror the Django schema (table + column names). The
 * `f365_match` WP post is used only to resolve the Django match id; all data
 * comes from indexed SELECTs against the custom tables — no ACF repeaters and
 * no postmeta lookups in the read path.
 *
 * Tabs (match_opt), mirroring the source:
 * matchpreview      Antevisão do Jogo  (default before kickoff)
 * matchevents       Ficha do Jogo      (default once finished)
 * matchstats        Estatísticas do Jogo
 * matchoccurrences  Ocorrências do Jogo
 * matchvideos       Vídeos do Jogo
 * matchnews         Notícias do Jogo
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

/* ───────────────────────── infrastructure ───────────────────────── */

if ( ! function_exists( 'f365_match_table' ) ) {
	function f365_match_table( string $django_table ): string {
		global $wpdb;
		return $wpdb->prefix . $django_table;
	}
}

if ( ! function_exists( 'f365_match_resolve_django_id' ) ) {
	/**
	 * Resolve the external (Django) match id for a routed f365_match post.
	 */
	function f365_match_resolve_django_id( int $post_id ): int {
		global $wpdb;
		$legacy_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
				$post_id
			)
		);
		if ( $legacy_id !== null && $legacy_id !== '' ) {
			return (int) $legacy_id;
		}
		$raw = get_post_meta( $post_id, 'django_match_id', true );
		return $raw !== '' ? (int) $raw : 0;
	}
}

/* Per-request memo so a tab never re-queries the same row twice. */
if ( ! function_exists( 'f365_match_memo' ) ) {
	function &f365_match_memo(): array {
		static $memo = array();
		return $memo;
	}
}

/* ───────────────────────── core fetch ───────────────────────── */

if ( ! function_exists( 'f365_match_fetch' ) ) {
	/**
	 * Load the match row joined to competition, phase, both teamsteps + teams, stadium and stats.
	 */
	function f365_match_fetch( int $match_id ): ?object {
		$memo =& f365_match_memo();
		$key  = 'match_' . $match_id;
		if ( array_key_exists( $key, $memo ) ) {
			return $memo[ $key ];
		}

		global $wpdb;
		$m  = f365_match_table( 'competition_match' );
		$c  = f365_match_table( 'competition_competition' );
		$cp = f365_match_table( 'competition_competitionphase' );
		$ms = f365_match_table( 'competition_matchstats' );
		$hs = f365_match_table( 'team_teamstep' );
		$vs = f365_match_table( 'team_teamstep' );
		$ht = f365_match_table( 'team_team' );
		$vt = f365_match_table( 'team_team' );
		$st = f365_match_table( 'stadium_stadium' );

		$sql = "
            SELECT
                m.*,
                c.name AS competition_name, c.season AS competition_season,
                c.edition AS competition_edition,
                c.federation_competition_id AS competition_fed_comp_id,
                cp.name AS phase_name, cp.phase_type AS phase_type,
                hts.team_id AS home_team_id_real, hts.step AS home_step,
                hts.gender AS home_gender, hts.modality AS home_modality,
                hts.stadium_id AS home_step_stadium_id,
                vts.team_id AS visitor_team_id_real, vts.step AS visitor_step,
                vts.gender AS visitor_gender, vts.modality AS visitor_modality,
                hteam.shortname AS home_shortname, hteam.team_type AS home_team_type,
                hteam.stadium_id AS home_team_stadium_id,
                vteam.shortname AS visitor_shortname, vteam.team_type AS visitor_team_type,
                vteam.stadium_id AS visitor_team_stadium_id,
                s.longname AS stadium_longname, s.shortname AS stadium_shortname,
                s.city AS stadium_city,
                st.home_ball_possession, st.home_shots, st.home_shots_on_goal,
                st.home_fouls, st.home_corner_kicks, st.home_offsides, st.home_saves,
                st.home_throw_ins, st.home_free_kicks,
                st.visitor_ball_possession, st.visitor_shots, st.visitor_shots_on_goal,
                st.visitor_fouls, st.visitor_corner_kicks, st.visitor_offsides,
                st.visitor_saves, st.visitor_throw_ins, st.visitor_free_kicks
            FROM {$m} m
            INNER JOIN {$c} c       ON c.id = m.competition_id
            LEFT JOIN  {$cp} cp     ON cp.id = m.competition_phase_id
            INNER JOIN {$hs} hts    ON hts.id = m.home_team_id
            INNER JOIN {$vs} vts    ON vts.id = m.visitor_team_id
            INNER JOIN {$ht} hteam  ON hteam.id = hts.team_id
            INNER JOIN {$vt} vteam  ON vteam.id = vts.team_id
            LEFT JOIN  {$st} s      ON s.id = m.stadium_id
            LEFT JOIN  {$ms} st     ON st.id = m.stats_id
            WHERE m.id = %d
            LIMIT 1
        ";
		$row          = $wpdb->get_row( $wpdb->prepare( $sql, $match_id ) );
		$memo[ $key ] = $row ?: null;
		return $memo[ $key ];
	}
}

if ( ! function_exists( 'f365_match_competition_post_id' ) ) {
	/**
	 * Resolve the WordPress competition post id for an external competition id.
	 */
	function f365_match_competition_post_id( int $competition_id ): int {
		if ( $competition_id <= 0 ) {
			return 0;
		}

		$memo =& f365_match_memo();
		$key  = 'comp_post_' . $competition_id;

		if ( array_key_exists( $key, $memo ) ) {
			return $memo[ $key ];
		}

		/*
		 * Persist the (django competition id -> post id) mapping in the object
		 * cache (default caching, not a transient). Immutable per post, so a
		 * long TTL is safe; "not found" is cached as 0 for negative caching.
		 */
		$group     = 'f365';
		$cache_key = 'match_comp_post_' . $competition_id;
		$found     = false;
		$cached    = wp_cache_get( $cache_key, $group, false, $found );
		if ( $found ) {
			return $memo[ $key ] = (int) $cached;
		}

		global $wpdb;

		/*
		 * meta_value bound as a string (%s) so the (meta_key, meta_value) index
		 * prefix is usable; %d forced a numeric cast on the longtext column and
		 * defeated it. Identical results — django ids are stored as integer
		 * strings.
		 */
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID 
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = %s
			  	AND p.post_status = 'publish'
               	AND pm.meta_key = %s 
               	AND pm.meta_value = %s 
             LIMIT 1",
				'f365_competition',
				'django_competition_id',
				(string) $competition_id
			)
		);
		
		$resolved = $post_id ? (int) $post_id : 0;
		wp_cache_set( $cache_key, $resolved, $group, 12 * HOUR_IN_SECONDS );
		return $memo[ $key ] = $resolved;
	}
}

/* ───────────────────────── value helpers (mirror model methods) ───────────────────────── */

if ( ! function_exists( 'f365_match_teamstep_name' ) ) {
	/* Mirrors TeamStep.__unicode__ */
	function f365_match_teamstep_name( string $shortname, string $step, string $gender, string $modality ): string {
		$rest = '';
		if ( 'futsal' === $modality ) {
			$rest .= ' FS';
		}
		if ( 'female' === $gender ) {
			$rest .= ' FM';
		}
		$excluded = array( 'principal', 'b_team', 'c_team', 'veterans', 'reservations' );
		if ( ! in_array( $step, $excluded, true ) ) {
			$rest .= ' ' . f365_match_step_label( $step );
		}
		if ( 'b_team' === $step ) {
			$rest .= ' B';
		} elseif ( 'c_team' === $step ) {
			$rest .= ' C';
		} elseif ( 'veterans' === $step ) {
			$rest .= ' VET';
		} elseif ( 'reservations' === $step ) {
			$rest .= ' RES';
		}
		$rest = trim( $rest );
		return $rest !== '' ? $shortname . ' ' . $rest : $shortname;
	}
}

if ( ! function_exists( 'f365_match_step_label' ) ) {
	function f365_match_step_label( string $step ): string {
		static $map = array(
			'sub_23'   => 'S23',
			'sub_22'   => 'S22',
			'sub_21'   => 'S21',
			'sub_20'   => 'S20',
			'sub_19'   => 'S19',
			'sub_19_b' => 'S19 B',
			'sub_18'   => 'S18',
			'sub_17'   => 'S17',
			'sub_17_b' => 'S17 B',
			'sub_16'   => 'S16',
			'sub_15'   => 'S15',
			'sub_15_b' => 'S15 B',
			'sub_14'   => 'S14',
			'sub_13'   => 'S13',
			'sub_13_b' => 'S13 B',
			'sub_12'   => 'S12',
			'sub_12_b' => 'S12 B',
			'sub_11'   => 'S11',
			'sub_11_b' => 'S11 B',
			'sub_10'   => 'S10',
			'sub_10_b' => 'S10 B',
			'sub_9'    => 'S9',
		);
		return $map[ $step ] ?? $step;
	}
}

if ( ! function_exists( 'f365_match_event_label' ) ) {
	/* Portuguese get_event_type_display() values (competition EVENT_TYPE). */
	function f365_match_event_label( ?string $event_type ): string {
		static $map = null;
		if ( null === $map ) {
			$map = array(
				'goal'               => __( 'Golo', 'futebol365' ),
				'goal_penalty'       => __( 'Golo (penalty)', 'futebol365' ),
				'fail_penalty'       => __( 'Penalty falhado', 'futebol365' ),
				'own_goal'           => __( 'Golo (própria baliza)', 'futebol365' ),
				'yellow_card'        => __( 'Cartão amarelo', 'futebol365' ),
				'double_yellow_card' => __( 'Segundo cartão amarelo', 'futebol365' ),
				'red_card'           => __( 'Cartão vermelho', 'futebol365' ),
				'substitution_in'    => __( 'Substituição (entrou)', 'futebol365' ),
				'substitution_out'   => __( 'Substituição (saiu)', 'futebol365' ),
				'goal_penalty_tie'   => __( 'Golo (desempate grandes penalidades)', 'futebol365' ),
			);
		}
		return $map[ $event_type ] ?? (string) $event_type;
	}
}

if ( ! function_exists( 'f365_match_has_extra_score' ) ) {
	function f365_match_has_extra_score( object $m ): bool {
		return $m->home_score_extra !== null && $m->home_score_extra !== ''
			&& $m->visitor_score_extra !== null && $m->visitor_score_extra !== '';
	}
}

if ( ! function_exists( 'f365_match_has_penalties' ) ) {
	function f365_match_has_penalties( object $m ): bool {
		return $m->penalty_home_score !== null && $m->penalty_home_score !== ''
			&& $m->penalty_visitor_score !== null && $m->penalty_visitor_score !== '';
	}
}

if ( ! function_exists( 'f365_match_get_result' ) ) {
	/* Mirrors Match.get_result() */
	function f365_match_get_result( object $m ): string {
		if ( 'delayed' === $m->situation ) {
			return __( 'ADI', 'futebol365' );
		}
		if ( 'suspended' === $m->situation ) {
			return __( 'SUS', 'futebol365' );
		}
		if ( 'abrogated' === $m->situation ) {
			return __( 'ANU', 'futebol365' );
		}
		if ( f365_match_has_extra_score( $m ) ) {
			/* translators: 1: Home extra score, 2: Visitor extra score */
			$result = sprintf( __( '%1$s - %2$s a.p', 'futebol365' ), $m->home_score_extra, $m->visitor_score_extra );
		} elseif ( $m->home_score !== null && $m->visitor_score !== null ) {
			$result = sprintf( '%s - %s', $m->home_score, $m->visitor_score );
		} else {
			$result = __( 'vs', 'futebol365' );
		}
		if ( f365_match_has_penalties( $m ) ) {
			/* translators: 1: Home penalty score, 2: Visitor penalty score */
			$result = sprintf( __( '%1$s - %2$s g.p', 'futebol365' ), $m->penalty_home_score, $m->penalty_visitor_score );
		}
		return $result;
	}
}

if ( ! function_exists( 'f365_match_has_result' ) ) {
	function f365_match_has_result( object $m ): bool {
		if ( $m->penalty_home_score !== null && $m->penalty_visitor_score !== null ) {
			return true;
		}
		if ( $m->home_score_extra !== null && $m->visitor_score_extra !== null ) {
			return true;
		}
		return $m->home_score !== null && $m->visitor_score !== null;
	}
}

if ( ! function_exists( 'f365_match_has_finished' ) ) {
	function f365_match_has_finished( object $m ): bool {
		return 'finished' === $m->situation && f365_match_has_result( $m );
	}
}

if ( ! function_exists( 'f365_match_is_available_by_status_and_time' ) ) {
	/* Mirrors Match.is_available_by_status_and_time() */
	function f365_match_is_available_by_status_and_time( object $m ): bool {
		$is_available = ( $m->situation === null || $m->situation === '' );
		$not_started  = ! in_array( $m->situation, array( 'delayed', 'suspended', 'abrogated' ), true );
		if ( ! $is_available || ! $not_started ) {
			return false;
		}
		$match_dt = strtotime( (string) $m->date_time );
		if ( false === $match_dt ) {
			return false;
		}
		$now   = time();
		$lower = $match_dt - 3 * DAY_IN_SECONDS;
		return $now > $lower && $now < $match_dt;
	}
}

if ( ! function_exists( 'f365_match_round_group_or_phase' ) ) {
	/* Mirrors Match.get_round_group_or_phase() */
	function f365_match_round_group_or_phase( object $m ): string {
		if ( null === $m->competition_phase_id ) {
			/* translators: %s: Match round number */
			$description = sprintf( __( '%sª Jornada', 'futebol365' ), $m->match_round );
		} else {
			$description = (string) $m->phase_name;
		}

		$group = f365_match_group( $m );
		if ( $group !== null && $group !== '' ) {
			$description .= ' - ' . $group;
		}
		if ( $m->group_phase_round !== null && $m->competition_phase_id !== null ) {
			if ( ! in_array( $m->phase_type, array( 'single_round', 'single_round_with_groups' ), true ) ) {
				/* translators: %s: Group phase round number */
				$description .= sprintf( __( ' - %sª Jornada', 'futebol365' ), $m->group_phase_round );
			}
		}
		if ( in_array( (int) $m->leg, array( 1, 2 ), true ) ) {
			/* translators: %s: Leg number (1st or 2nd leg) */
			$description .= sprintf( __( ' - %sª Mão', 'futebol365' ), $m->leg );
		}
		return $description;
	}
}

if ( ! function_exists( 'f365_match_group' ) ) {
	/* Mirrors Match.get_group() */
	function f365_match_group( object $m ): ?string {
		if ( $m->group_home_team === $m->group_visitor_team ) {
			return $m->group_home_team;
		}
		return sprintf( '%s - %s', $m->group_home_team, $m->group_visitor_team );
	}
}

if ( ! function_exists( 'f365_match_competition_round_url' ) ) {
	/* Mirrors Match.get_competition_round_absolute_url() */
	function f365_match_competition_round_url( object $m ): string {
		$args = array();
		if ( $m->competition_phase_id !== null && $m->phase_name !== null ) {
			$args[] = 'competition_phase=' . str_replace( ' ', '+', (string) $m->phase_name );
			if ( $m->group_phase_round !== null && $m->group_phase_round !== '' ) {
				$args[] = 'phase_round=' . $m->group_phase_round;
			}
		} else {
			$args[] = 'competition_round=' . $m->match_round;
		}
		if ( $m->group_home_team !== null && $m->group_home_team !== '' ) {
			$args[] = 'group=' . str_replace( ' ', '+', (string) $m->group_home_team );
		}
		$termination = implode( '&', $args );
		$termination = $termination !== '' ? '&' . $termination : '';
		return home_url(
			sprintf(
				'/competicao/%s/?edition=%s%s',
				$m->competition_fed_comp_id,
				$m->competition_edition,
				$termination
			)
		);
	}
}

if ( ! function_exists( 'f365_match_competition_url' ) ) {
	/* Mirrors Competition.get_absolute_url() */
	function f365_match_competition_url( object $m ): string {
		return home_url( sprintf( '/competicao/%s/?edition=%s', $m->competition_fed_comp_id, $m->competition_edition ) );
	}
}

if ( ! function_exists( 'f365_match_teamstep_url' ) ) {
	/* Mirrors TeamStep.get_absolute_url() */
	function f365_match_teamstep_url( int $team_id, string $edition, string $modality, string $gender, string $step ): string {
		return home_url(
			sprintf(
				'/equipa/%d/jogos/?edition=%s&modality=%s&gender=%s&step=%s',
				$team_id,
				rawurlencode( $edition ),
				rawurlencode( $modality ),
				rawurlencode( $gender ),
				rawurlencode( $step )
			)
		);
	}
}

if ( ! function_exists( 'f365_match_home_team_url' ) ) {
	function f365_match_home_team_url( object $m ): string {
		$url = f365_match_teamstep_url(
			(int) $m->home_team_id_real,
			(string) $m->competition_edition,
			(string) $m->home_modality,
			(string) $m->home_gender,
			(string) $m->home_step
		);
		if ( 'national' === $m->home_team_type ) {
			$url .= '&competition=' . $m->competition_id;
		}
		return $url;
	}
}

if ( ! function_exists( 'f365_match_visitor_team_url' ) ) {
	function f365_match_visitor_team_url( object $m ): string {
		$url = f365_match_teamstep_url(
			(int) $m->visitor_team_id_real,
			(string) $m->competition_edition,
			(string) $m->visitor_modality,
			(string) $m->visitor_gender,
			(string) $m->visitor_step
		);
		if ( 'national' === $m->visitor_team_type ) {
			$url .= '&competition=' . $m->competition_id;
		}
		return $url;
	}
}

if ( ! function_exists( 'f365_match_stadium_url' ) ) {
	function f365_match_stadium_url( object $m ): string {
		return home_url( sprintf( '/estadio/%d/', (int) $m->stadium_id ) );
	}
}

if ( ! function_exists( 'f365_match_player_games_url' ) ) {
	function f365_match_player_games_url( int $player_id, object $m ): string {
		return home_url( sprintf( '/jogador/%d/jogos/?edition=%s', $player_id, rawurlencode( (string) $m->competition_edition ) ) );
	}
}

if ( ! function_exists( 'f365_match_coach_url' ) ) {
	function f365_match_coach_url( int $coach_id ): string {
		return home_url( sprintf( '/treinador/%d/', $coach_id ) );
	}
}

if ( ! function_exists( 'f365_match_logo_url' ) ) {
	function f365_match_logo_url( int $team_id ): string {
		$base = home_url( '/media/' );
		$base = rtrim( $base, '/' ) . '/';
		return $base . 'images/logotipo/100x100/' . $team_id . '.gif';
	}
}

if ( ! function_exists( 'f365_match_stadium_display' ) ) {
	function f365_match_stadium_display( object $m ): string {
		$name = ( $m->stadium_longname !== null && $m->stadium_longname !== '' )
			? $m->stadium_longname
			: (string) $m->stadium_shortname;
		return (string) $name;
	}
}

/* ───────────────────────── player / event queries ───────────────────────── */

if ( ! function_exists( 'f365_match_players_titular' ) ) {
	/**
	 * Mirrors get_home/visitor_titular_players -> _get_players_by_position():
	 * iterate positions goalkeeper -> defender -> midfielder -> forward and
	 * concatenate each position's players, EXCLUDING any player whose position
	 * is not one of those four.
	 *
	 * Within a position the source has no sort key: MatchPlayer.Meta.ordering is
	 * ('match__date_time',), which is constant inside a match, so the live site
	 * returns rows in PostgreSQL heap order. That order is non-deterministic
	 * (it shifts when a row is updated, e.g. when a substitution is recorded)
	 * and is not stored in any column, so it cannot be reproduced from the data.
	 * We therefore apply a stable, deterministic within-position order by mp.id
	 * ASC (insertion order) -- the faithful intent of _get_players_by_position.
	 * LOWER(TRIM()) guards against stray casing/whitespace in `position`.
	 */
	function f365_match_players_titular( int $match_id, int $teamstep_id ): array {
		global $wpdb;
		$mp   = f365_match_table( 'competition_matchplayer' );
		$pl   = f365_match_table( 'person_player' );
		$pe   = f365_match_table( 'person_person' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT mp.*, pe.shortname AS player_name
             FROM {$mp} mp
             INNER JOIN {$pl} pl ON pl.id = mp.player_id
             INNER JOIN {$pe} pe ON pe.id = pl.person_id
             WHERE mp.match_id = %d AND mp.team_id = %d AND mp.is_titular = 't'
               AND LOWER(TRIM(mp.position)) IN ('goalkeeper','defender','midfielder','forward')
             ORDER BY FIELD(LOWER(TRIM(mp.position)),'goalkeeper','defender','midfielder','forward'), mp.id ASC",
				$match_id,
				$teamstep_id
			)
		);
		return $rows ?: array();
	}
}

if ( ! function_exists( 'f365_match_players_not_titular' ) ) {
	/**
	 * Mirrors get_home/visitor_not_titular_players: order by -minutes.
	 */
	function f365_match_players_not_titular( int $match_id, int $teamstep_id ): array {
		global $wpdb;
		$mp   = f365_match_table( 'competition_matchplayer' );
		$pl   = f365_match_table( 'person_player' );
		$pe   = f365_match_table( 'person_person' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT mp.*, pe.shortname AS player_name
             FROM {$mp} mp
             INNER JOIN {$pl} pl ON pl.id = mp.player_id
             INNER JOIN {$pe} pe ON pe.id = pl.person_id
             WHERE mp.match_id = %d AND mp.team_id = %d AND mp.is_titular = 'f'
             ORDER BY mp.minutes DESC, mp.id ASC",
				$match_id,
				$teamstep_id
			)
		);
		return $rows ?: array();
	}
}

if ( ! function_exists( 'f365_match_matchplayer_count' ) ) {
	function f365_match_matchplayer_count( int $match_id ): int {
		$memo =& f365_match_memo();
		$key  = 'mpcount_' . $match_id;
		if ( array_key_exists( $key, $memo ) ) {
			return $memo[ $key ];
		}
		global $wpdb;
		$mp           = f365_match_table( 'competition_matchplayer' );
		$memo[ $key ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$mp} WHERE match_id = %d", $match_id ) );
		return $memo[ $key ];
	}
}

if ( ! function_exists( 'f365_match_player_events' ) ) {
	/* Mirrors MatchPlayer.get_matchplayer_events(): order -minute, -id. */
	function f365_match_player_events( int $matchplayer_id ): array {
		global $wpdb;
		$mpe  = f365_match_table( 'competition_matchplayerevent' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$mpe} WHERE matchplayer_id = %d ORDER BY minute DESC, id DESC",
				$matchplayer_id
			)
		);
		return $rows ?: array();
	}
}

if ( ! function_exists( 'f365_match_player_number' ) ) {
	/* Mirrors MatchPlayer.get_player_number(). */
	function f365_match_player_number( object $mp, object $m, string $team_type ): int {
		if ( $mp->shirt_number !== null && $mp->shirt_number !== '' && (int) $mp->shirt_number !== 0 ) {
			return (int) $mp->shirt_number;
		}

		global $wpdb;
		$sq       = f365_match_table( 'team_squad' );
		$squad_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$sq} WHERE team_id = %d AND player_id = %d ORDER BY `order` ASC LIMIT 1",
				(int) $mp->team_id,
				(int) $mp->player_id
			)
		);
		if ( null === $squad_id ) {
			return 0;
		}

		$sc = f365_match_table( 'team_squadcompetition' );
		if ( 'national' === $team_type ) {
			$val = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT shirt_number FROM {$sc} WHERE squad_id = %d AND competition_id = %d LIMIT 1",
					(int) $squad_id,
					(int) $m->competition_id
				)
			);
		} else {
			$val = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT shirt_number FROM {$sc} WHERE squad_id = %d ORDER BY id ASC LIMIT 1",
					(int) $squad_id
				)
			);
		}
		
		return ( $val === null || $val === '' ) ? 0 : (int) $val;
	}
}

if ( ! function_exists( 'f365_match_coaches' ) ) {
	function f365_match_coaches( int $match_id, bool $is_home ): array {
		global $wpdb;
		$mc   = f365_match_table( 'competition_matchcoach' );
		$co   = f365_match_table( 'person_coach' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT mc.coach_id, co.coach_name
             FROM {$mc} mc
             INNER JOIN {$co} co ON co.id = mc.coach_id
             WHERE mc.match_id = %d AND mc.is_home_team = %s
             ORDER BY mc.`order` ASC",
				$match_id,
				$is_home ? 't' : 'f'
			)
		);
		return $rows ?: array();
	}
}

if ( ! function_exists( 'f365_match_formation' ) ) {
	function f365_match_formation( int $match_id, bool $is_home ): ?string {
		global $wpdb;
		$mf  = f365_match_table( 'competition_matchformation' );
		$val = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT formation FROM {$mf} WHERE match_id = %d AND is_home_team = %s LIMIT 1",
				$match_id,
				$is_home ? 't' : 'f'
			)
		);
		return ( $val === null || $val === '' ) ? null : (string) $val;
	}
}

if ( ! function_exists( 'f365_match_goal_events' ) ) {
	function f365_match_goal_events( object $m, bool $home_column ): array {
		global $wpdb;
		$mp  = f365_match_table( 'competition_matchplayer' );
		$mpe = f365_match_table( 'competition_matchplayerevent' );
		$pl  = f365_match_table( 'person_player' );
		$pe  = f365_match_table( 'person_person' );

		$scoring_team = $home_column ? (int) $m->home_team_id : (int) $m->visitor_team_id;
		$own_team     = $home_column ? (int) $m->visitor_team_id : (int) $m->home_team_id;

		$sql  = "
            SELECT mpe.event_type, mpe.minute, pe.shortname AS player_name, pl.id AS player_id
            FROM {$mpe} mpe
            INNER JOIN {$mp} mp ON mp.id = mpe.matchplayer_id
            INNER JOIN {$pl} pl ON pl.id = mp.player_id
            INNER JOIN {$pe} pe ON pe.id = pl.person_id
            WHERE mp.match_id = %d
              AND (
                ( mp.team_id = %d AND mpe.event_type IN ('goal','goal_penalty') )
                OR ( mp.team_id = %d AND mpe.event_type = 'own_goal' )
              )
            ORDER BY mpe.minute ASC
        ";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, (int) $m->id, $scoring_team, $own_team ) );
		return $rows ?: array();
	}
}

if ( ! function_exists( 'f365_match_occurrences' ) ) {
	/*
	 * Mirrors _match_occurrences(): all MatchPlayerEvents of the match ordered
	 * by minute descending (the source applies only .order_by('-minute')).
	 * Within a tied minute the source keeps insertion order, replicated as
	 * mpe.id ASC for a stable, faithful result.
	 */
	function f365_match_occurrences( object $m ): array {
		global $wpdb;
		$mp  = f365_match_table( 'competition_matchplayer' );
		$mpe = f365_match_table( 'competition_matchplayerevent' );
		$pl  = f365_match_table( 'person_player' );
		$pe  = f365_match_table( 'person_person' );
		$ts  = f365_match_table( 'team_teamstep' );
		$tt  = f365_match_table( 'team_team' );

		$sql = "
            SELECT mpe.id, mpe.event_type, mpe.minute,
                   pe.shortname AS player_name, pl.id AS player_id,
                   mp.team_id,
                   t.shortname AS team_shortname, t.team_type AS team_type, t.id AS team_real_id,
                   ts.step, ts.gender, ts.modality, ts.edition AS ts_edition, ts.team_id AS ts_team_id
            FROM {$mpe} mpe
            INNER JOIN {$mp} mp ON mp.id = mpe.matchplayer_id
            INNER JOIN {$pl} pl ON pl.id = mp.player_id
            INNER JOIN {$pe} pe ON pe.id = pl.person_id
            INNER JOIN {$ts} ts ON ts.id = mp.team_id
            INNER JOIN {$tt} t  ON t.id = ts.team_id
            WHERE mp.match_id = %d
            ORDER BY mpe.minute DESC, mpe.id ASC
        ";
		return $wpdb->get_results( $wpdb->prepare( $sql, (int) $m->id ) ) ?: array();
	}
}

if ( ! function_exists( 'f365_match_result_at_minute' ) ) {
	function f365_match_result_at_minute( object $m, int $minute ): array {
		global $wpdb;
		$mp   = f365_match_table( 'competition_matchplayer' );
		$mpe  = f365_match_table( 'competition_matchplayerevent' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT mpe.event_type, mp.team_id
             FROM {$mpe} mpe
             INNER JOIN {$mp} mp ON mp.id = mpe.matchplayer_id
             WHERE mp.match_id = %d AND mpe.minute <= %d
               AND mpe.event_type IN ('goal','goal_penalty','own_goal')",
				(int) $m->id,
				$minute
			)
		);
		$home    = 0;
		$visitor = 0;
		foreach ( (array) $rows as $r ) {
			$is_home = (int) $r->team_id === (int) $m->home_team_id;
			if ( 'own_goal' === $r->event_type ) {
				if ( $is_home ) {
					$visitor++;
				} else {
					$home++;
				}
			} else {
				if ( $is_home ) {
					$home++;
				} else {
					$visitor++;
				}
			}
		}
		return array( $home, $visitor );
	}
}

/* ───────────────────────── stats reductions ───────────────────────── */

if ( ! function_exists( 'f365_match_score_for' ) ) {
	function f365_match_score_for( object $m, bool $is_home ) {
		if ( $is_home ) {
			if ( $m->home_score_extra !== null ) {
				return $m->home_score_extra;
			}
			return $m->home_score;
		}
		if ( $m->visitor_score_extra !== null ) {
			return $m->visitor_score_extra;
		}
		return $m->visitor_score;
	}
}

if ( ! function_exists( 'f365_match_count_players' ) ) {
	function f365_match_count_players( int $match_id, int $teamstep_id ): object {
		global $wpdb;
		$mp  = f365_match_table( 'competition_matchplayer' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
                COALESCE(SUM(goals_penalty),0)            AS penalty_goals,
                COALESCE(SUM(own_goals),0)                AS own_goals,
                COALESCE(SUM(CASE WHEN yellow_card='t' THEN 1 ELSE 0 END),0)        AS yellow_cards,
                COALESCE(SUM(CASE WHEN double_yellow_card='t' THEN 1 ELSE 0 END),0) AS double_yellow_cards,
                COALESCE(SUM(CASE WHEN red_card='t' THEN 1 ELSE 0 END),0)           AS red_cards,
                COALESCE(SUM(CASE WHEN is_in='t' THEN 1 ELSE 0 END),0)              AS substitutions
             FROM {$mp} WHERE match_id = %d AND team_id = %d",
				$match_id,
				$teamstep_id
			)
		);
		return $row ?: (object) array(
			'penalty_goals'       => 0,
			'own_goals'           => 0,
			'yellow_cards'        => 0,
			'double_yellow_cards' => 0,
			'red_cards'           => 0,
			'substitutions'       => 0,
		);
	}
}

if ( ! function_exists( 'f365_match_averages' ) ) {
	function f365_match_averages( int $match_id, int $teamstep_id ): object {
		global $wpdb;
		$mp = f365_match_table( 'competition_matchplayer' );
		$pl = f365_match_table( 'person_player' );
		$pe = f365_match_table( 'person_person' );

		$data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
					AVG(pl.height) AS avg_height, 
					AVG(pl.weight) AS avg_weight,
					AVG(DATEDIFF(CURRENT_DATE, pe.birth_date) / 365.25) AS avg_age
				 FROM {$mp} mp
				 INNER JOIN {$pl} pl ON pl.id = mp.player_id
				 INNER JOIN {$pe} pe ON pe.id = pl.person_id
				 WHERE mp.match_id = %d AND mp.team_id = %d",
				$match_id,
				$teamstep_id
			)
		);

		return (object) array(
			'height' => ( $data && $data->avg_height !== null ) ? sprintf( '%.0f', $data->avg_height ) : '',
			'weight' => ( $data && $data->avg_weight !== null ) ? sprintf( '%.0f', $data->avg_weight ) : '',
			'age'    => ( $data && $data->avg_age !== null ) ? sprintf( '%.0f', $data->avg_age ) : '',
		);
	}
}

/* ───────────────────────── probability (matchpreview) ───────────────────────── */

if ( ! function_exists( 'f365_match_goal_avg' ) ) {
	function f365_match_goal_avg( object $m, bool $is_home ): float {
		global $wpdb;
		$mtbl        = f365_match_table( 'competition_match' );
		$teamstep_id = $is_home ? (int) $m->home_team_id : (int) $m->visitor_team_id;
		$col         = $is_home ? 'home_team_id' : 'visitor_team_id';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT home_score, home_score_extra, visitor_score, visitor_score_extra
             FROM {$mtbl}
             WHERE {$col} = %d AND situation = 'finished'
             ORDER BY date_time DESC, id DESC LIMIT 6",
				$teamstep_id
			)
		);

		$goals = 0;
		$n     = 0;
		foreach ( (array) $rows as $r ) {
			$n++;
			if ( $is_home ) {
				$goals += ( $r->home_score_extra !== null ) ? (int) $r->home_score_extra : (int) $r->home_score;
			} else {
				$goals += ( $r->visitor_score_extra !== null ) ? (int) $r->visitor_score_extra : (int) $r->visitor_score;
			}
		}
		if ( 0 === $n ) {
			$n = 1;
		}
		return round( $goals / $n, 1 );
	}
}

if ( ! function_exists( 'f365_match_probability' ) ) {
	function f365_match_probability( object $m ): array {
		$home    = f365_match_goal_avg( $m, true );
		$visitor = f365_match_goal_avg( $m, false );

		$bucket = static function ( float $avg ): array {
			if ( $avg <= 1.0 ) {
				return array( 0.45, 0.36, 0.14, 0.04, 0.01 );
			}
			if ( $avg <= 1.4 ) {
				return array( 0.3, 0.36, 0.22, 0.09, 0.03 );
			}
			if ( $avg <= 1.8 ) {
				return array( 0.2, 0.32, 0.26, 0.14, 0.08 );
			}
			return array( 0.14, 0.27, 0.27, 0.18, 0.14 );
		};

		$hw = $bucket( $home );
		$vw = $bucket( $visitor );

		$victory =
			$hw[1] * $vw[0] + $hw[2] * $vw[0] + $hw[3] * $vw[0] + $hw[4] * $vw[0]
			+ $hw[2] * $vw[1] + $hw[3] * $vw[1] + $hw[4] * $vw[1]
			+ $hw[3] * $vw[2] + $hw[4] * $vw[2]
			+ $hw[4] * $vw[3];

		$draw =
			$vw[0] * $hw[0] + $vw[1] * $hw[1] + $vw[2] * $hw[2] + $vw[3] * $hw[3] + $vw[4] * $hw[4];

		$victory_perc = (int) ( $victory * 100 );
		$draw_perc    = (int) ( $draw * 100 );
		$lose_perc    = 100 - $victory_perc - $draw_perc;

		return array(
			'1' => $victory_perc,
			'x' => $draw_perc,
			'2' => $lose_perc,
		);
	}
}

/* ───────────────────────── videos / news lists ───────────────────────── */
if ( ! function_exists( 'f365_match_media_url' ) ) {
	function f365_match_media_url( ?string $path ): string {
		if ( $path === null || $path === '' ) {
			return '';
		}
		$base = home_url( '/media/' );
		return rtrim( $base, '/' ) . '/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'f365_match_videos' ) ) {
	function f365_match_videos( int $match_id ): array {
		global $wpdb;
		$v    = f365_match_table( 'multimedia_video' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, datetime, length, image FROM {$v} WHERE match_id = %d ORDER BY datetime DESC",
				$match_id
			)
		);
		return $rows ?: array();
	}
}

if ( ! function_exists( 'f365_match_news' ) ) {
	function f365_match_news( int $match_id ): array {
		global $wpdb;
		$n    = f365_match_table( 'publish_news' );
		$nc   = f365_match_table( 'publish_news_categories' );
		$cat  = f365_match_table( 'publish_newscategory' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT n.id, n.title, n.slug, n.summary, n.datetime, n.image, n.video_embed_id,
                    ( SELECT c.name
                        FROM {$nc} ncx
                        INNER JOIN {$cat} c ON c.id = ncx.newscategory_id
                       WHERE ncx.news_id = n.id
                       ORDER BY ncx.id ASC LIMIT 1 ) AS category_name
             FROM {$n} n
             WHERE n.match_id = %d AND n.datetime <= %s
             ORDER BY n.datetime DESC",
				$match_id,
				current_time( 'mysql' )
			)
		);
		return $rows ?: array();
	}
}

if ( ! function_exists( 'f365_match_teams_matches' ) ) {
	/**
	 * Mirrors Match.get_teams_matches(): head-to-head finished matches between the
	 * two teams (same team+step+gender+modality, either home/away), excluding
	 * friendly competitions, newest first.
	 */
	function f365_match_teams_matches( object $m ): array {
		global $wpdb;
		$mt = f365_match_table( 'competition_match' );
		$ts = f365_match_table( 'team_teamstep' );
		$tt = f365_match_table( 'team_team' );
		$c  = f365_match_table( 'competition_competition' );
		$fc = f365_match_table( 'federation_federationcompetition' );

		$t1 = (int) $m->home_team_id_real;
		$t2 = (int) $m->visitor_team_id_real;

		$sql = "
            SELECT m.id, m.date_time, m.situation,
                   m.home_score, m.home_score_extra, m.visitor_score, m.visitor_score_extra,
                   m.penalty_home_score, m.penalty_visitor_score, m.competition_id,
                   hts.team_id AS home_team_id_real, hts.step AS home_step,
                   hts.gender AS home_gender, hts.modality AS home_modality,
                   vts.team_id AS visitor_team_id_real, vts.step AS visitor_step,
                   vts.gender AS visitor_gender, vts.modality AS visitor_modality,
                   hteam.shortname AS home_shortname, hteam.team_type AS home_team_type,
                   vteam.shortname AS visitor_shortname, vteam.team_type AS visitor_team_type,
                   cc.edition AS competition_edition
            FROM {$mt} m
            INNER JOIN {$ts} hts ON hts.id = m.home_team_id
            INNER JOIN {$ts} vts ON vts.id = m.visitor_team_id
            INNER JOIN {$tt} hteam ON hteam.id = hts.team_id
            INNER JOIN {$tt} vteam ON vteam.id = vts.team_id
            INNER JOIN {$c}  cc ON cc.id = m.competition_id
            INNER JOIN {$fc} fcc ON fcc.id = cc.federation_competition_id
            WHERE m.situation = 'finished'
              AND fcc.competition_type <> 'friendly'
              AND (
                ( hts.team_id = %d AND hts.step = %s AND hts.gender = %s AND hts.modality = %s
                  AND vts.team_id = %d AND vts.step = %s AND vts.gender = %s AND vts.modality = %s )
                OR
                ( vts.team_id = %d AND vts.step = %s AND vts.gender = %s AND vts.modality = %s
                  AND hts.team_id = %d AND hts.step = %s AND hts.gender = %s AND hts.modality = %s )
              )
            ORDER BY m.date_time DESC, m.id DESC
        ";

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				$sql,
				$t1, $m->home_step, $m->home_gender, $m->home_modality,
				$t2, $m->visitor_step, $m->visitor_gender, $m->visitor_modality,
				$t1, $m->home_step, $m->home_gender, $m->home_modality,
				$t2, $m->visitor_step, $m->visitor_gender, $m->visitor_modality
			)
		);

		return $rows ?: array();
	}
}
if ( ! function_exists( 'f365_match_h2h_counts' ) ) {
	function f365_match_h2h_counts( array $matches, int $home_team, int $visitor_team ): object {
		$home_wins     = 0;
		$visitor_wins  = 0;
		$draws         = 0;
		$home_goals    = 0;
		$visitor_goals = 0;
		foreach ( $matches as $r ) {
			$hw = f365_match_has_extra_score( $r ) ? ( (int) $r->home_score_extra > (int) $r->visitor_score_extra )
				: ( $r->home_score !== null && $r->visitor_score !== null && (int) $r->home_score > (int) $r->visitor_score );
			$vw = f365_match_has_extra_score( $r ) ? ( (int) $r->home_score_extra < (int) $r->visitor_score_extra )
				: ( $r->home_score !== null && $r->visitor_score !== null && (int) $r->home_score < (int) $r->visitor_score );
			if ( f365_match_has_penalties( $r ) ) {
				$hw = (int) $r->penalty_home_score > (int) $r->penalty_visitor_score;
				$vw = (int) $r->penalty_home_score < (int) $r->penalty_visitor_score;
			}

			if ( $hw && (int) $r->home_team_id_real === $home_team ) {
				$home_wins++;
			}
			if ( $vw && (int) $r->visitor_team_id_real === $home_team ) {
				$home_wins++;
			}
			if ( $hw && (int) $r->home_team_id_real === $visitor_team ) {
				$visitor_wins++;
			}
			if ( $vw && (int) $r->visitor_team_id_real === $visitor_team ) {
				$visitor_wins++;
			}
			if ( ! $hw && ! $vw ) {
				$draws++;
			}

			$hs = f365_match_has_extra_score( $r ) ? (int) $r->home_score_extra : (int) $r->home_score;
			$vs = f365_match_has_extra_score( $r ) ? (int) $r->visitor_score_extra : (int) $r->visitor_score;
			if ( (int) $r->home_team_id_real === $home_team ) {
				$home_goals += $hs;
			}
			if ( (int) $r->visitor_team_id_real === $home_team ) {
				$home_goals += $vs;
			}
			if ( (int) $r->home_team_id_real === $visitor_team ) {
				$visitor_goals += $hs;
			}
			if ( (int) $r->visitor_team_id_real === $visitor_team ) {
				$visitor_goals += $vs;
			}
		}
		return (object) array(
			'total'         => count( $matches ),
			'home_wins'     => $home_wins,
			'visitor_wins'  => $visitor_wins,
			'draws'         => $draws,
			'home_goals'    => $home_goals,
			'visitor_goals' => $visitor_goals,
		);
	}
}

if ( ! function_exists( 'f365_match_biggest_win' ) ) {
	function f365_match_biggest_win( array $matches, int $team, bool $as_home ): ?object {
		$best      = null;
		$best_diff = null;
		foreach ( $matches as $r ) {
			if ( $as_home ) {
				if ( (int) $r->home_team_id_real !== $team ) {
					continue;
				}
				$hw = f365_match_has_extra_score( $r ) ? ( (int) $r->home_score_extra > (int) $r->visitor_score_extra )
					: ( (int) $r->home_score > (int) $r->visitor_score );
				if ( ! $hw ) {
					continue;
				}
				$diff = f365_match_has_extra_score( $r ) ? ( (int) $r->home_score_extra - (int) $r->visitor_score_extra )
					: ( (int) $r->home_score - (int) $r->visitor_score );
			} else {
				if ( (int) $r->visitor_team_id_real !== $team ) {
					continue;
				}
				$vw = f365_match_has_extra_score( $r ) ? ( (int) $r->visitor_score_extra > (int) $r->home_score_extra )
					: ( (int) $r->visitor_score > (int) $r->home_score );
				if ( ! $vw ) {
					continue;
				}
				$diff = f365_match_has_extra_score( $r ) ? ( (int) $r->visitor_score_extra - (int) $r->home_score_extra )
					: ( (int) $r->visitor_score - (int) $r->home_score );
			}
			if ( null === $best || $diff > $best_diff ) {
				$best      = $r;
				$best_diff = $diff;
			}
		}
		return $best;
	}
}

if ( ! function_exists( 'f365_match_compare_url' ) ) {
	function f365_match_compare_url( object $m ): string {
		return home_url(
			sprintf(
				'/equipa/%d/comparar-com/%d/?edition=%s&step1=%s&gender1=%s&modality1=%s&step2=%s&gender2=%s&modality2=%s',
				(int) $m->home_team_id_real,
				(int) $m->visitor_team_id_real,
				rawurlencode( (string) $m->competition_edition ),
				rawurlencode( (string) $m->home_step ),
				rawurlencode( (string) $m->home_gender ),
				rawurlencode( (string) $m->home_modality ),
				rawurlencode( (string) $m->visitor_step ),
				rawurlencode( (string) $m->visitor_gender ),
				rawurlencode( (string) $m->visitor_modality )
			)
		);
	}
}

if ( ! function_exists( 'f365_match_compare_confrontation_url' ) ) {
	function f365_match_compare_confrontation_url( object $m ): string {
		return home_url(
			sprintf(
				'/equipa/%d/comparar-com/%d/confrontos/?edition=%s&step1=%s&gender1=%s&modality1=%s&step2=%s&gender2=%s&modality2=%s',
				(int) $m->home_team_id_real,
				(int) $m->visitor_team_id_real,
				rawurlencode( (string) $m->competition_edition ),
				rawurlencode( (string) $m->home_step ),
				rawurlencode( (string) $m->home_gender ),
				rawurlencode( (string) $m->home_modality ),
				rawurlencode( (string) $m->visitor_step ),
				rawurlencode( (string) $m->visitor_gender ),
				rawurlencode( (string) $m->visitor_modality )
			)
		);
	}
}

/* ════════════════════════════ render ════════════════════════════ */

get_header();

$match_id        = f365_match_resolve_django_id( get_queried_object_id() );
$match           = $match_id ? f365_match_fetch( $match_id ) : null;
$competition_id  = $match ? (int) $match->competition_id : 0;
$competition_pid = $competition_id ? f365_match_competition_post_id( $competition_id ) : 0;
$sections        = function_exists( 'futebol365_get_competition_sections' ) ? futebol365_get_competition_sections() : array();
?>

<main id="primary" class="site-main single-article-page">

	<?php
	if ( $competition_id && locate_template( 'template-parts/match/f365-match-header.php' ) ) {
		get_template_part(
			'template-parts/match/f365-match-header',
			null,
			array(
				'competition_id'  => $competition_id,
				'post_id'         => $competition_pid,
				'sections'        => $sections,
				'current_section' => '',
			)
		);
	}
	?>

	<div class="ink-grid futebol365-container">
		<section class="left-column-block">
			<div class="breadcrumbs-f365">
				<div class="ink-navigation breadcrumbs">
					<?php
					if ( function_exists( 'yoast_breadcrumb' ) ) {
						yoast_breadcrumb();
					}
					?>
				</div>
			</div>
			<?php if ( ! $match ) : ?>
				<h6 class="no-info"><?php esc_html_e( 'Jogo não encontrado.', 'futebol365' ); ?></h6>
			<?php else : ?>
				<?php
				$home_name    = f365_match_teamstep_name( (string) $match->home_shortname, (string) $match->home_step, (string) $match->home_gender, (string) $match->home_modality );
				$visitor_name = f365_match_teamstep_name( (string) $match->visitor_shortname, (string) $match->visitor_step, (string) $match->visitor_gender, (string) $match->visitor_modality );
				$result       = f365_match_get_result( $match );

				if ( f365_match_has_finished( $match ) ) {
					$option_labels  = array(
						'matchevents'      => __( 'Ficha', 'futebol365' ),
						'matchstats'       => __( 'Estatísticas', 'futebol365' ),
						'matchoccurrences' => __( 'Eventos', 'futebol365' ),
						'matchnews'        => __( 'Notícias', 'futebol365' ),
						'matchvideos'      => __( 'Vídeos', 'futebol365' ),
					);
					$default_action = 'matchevents';
				} else {
					$option_labels  = array(
						'matchpreview' => __( 'Antevisão', 'futebol365' ),
						'matchnews'    => __( 'Notícias', 'futebol365' ),
						'matchvideos'  => __( 'Vídeos', 'futebol365' ),
					);
					$default_action = 'matchpreview';
				}

				$valid_actions = array_keys( $option_labels );
				$action        = isset( $_GET['match_opt'] ) ? sanitize_key( wp_unslash( $_GET['match_opt'] ) ) : $default_action;
				if ( ! in_array( $action, $valid_actions, true ) ) {
					$action = $default_action;
				}
				$base_path = home_url( '/jogo/' . $match_id . '/' );
				?>

				<form class="ink-form ink-form-option" method="get" name="match_opts_form" id="match_opts_form" action="<?php echo esc_url( $base_path ); ?>">
					<div class="column-group">
						<div class="control-group all-33 tiny-100 small-100">
							<div class="control">
								<select name="match_opt" id="id_match_opt" onchange="this.form.submit();">
									<?php foreach ( $option_labels as $opt_value => $opt_label ) : ?>
										<option value="<?php echo esc_attr( $opt_value ); ?>" <?php selected( $action, $opt_value ); ?>><?php echo esc_html( $opt_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
					</div>
				</form>

				<div class="column-group match-div">
					<div class="all-100 align-center">
						<h6 class="match-detail">
							<?php if ( $match->stadium_id !== null ) : ?>
								<a href="<?php echo esc_url( f365_match_stadium_url( $match ) ); ?>"><?php echo esc_html( f365_match_stadium_display( $match ) ); ?></a><?php if ( $match->stadium_city !== null && $match->stadium_city !== '' ) : ?>, <?php echo esc_html( (string) $match->stadium_city ); endif; ?> -
							<?php endif; ?>
							<?php
							$ts = strtotime( (string) $match->date_time );
							/* translators: 1: formatted match date, 2: formatted match time */
							echo esc_html( sprintf( __( '%1$s às %2$s', 'futebol365' ), date_i18n( 'd-m-Y', $ts ), date_i18n( 'H:i', $ts ) ) );
							?>
						</h6>
						<h6 class="match-detail"><a href="<?php echo esc_url( f365_match_competition_url( $match ) ); ?>"><?php echo esc_html( "{$match->competition_name} {$match->competition_season}" ); ?></a> - <a href="<?php echo esc_url( f365_match_competition_round_url( $match ) ); ?>"><?php echo esc_html( f365_match_round_group_or_phase( $match ) ); ?></a></h6>
					</div>

					<div class="all-40 small-30 tiny-30 align-center">
						<a href="<?php echo esc_url( f365_match_home_team_url( $match ) ); ?>"><h5 class="match-team-name"><?php echo esc_html( $home_name ); ?></h5></a>
					</div>
					<div class="all-20 small-40 tiny-40 align-center"></div>
					<div class="all-40 small-30 tiny-30 align-center">
						<a href="<?php echo esc_url( f365_match_visitor_team_url( $match ) ); ?>"><h5 class="match-team-name"><?php echo esc_html( $visitor_name ); ?></h5></a>
					</div>

					<div class="all-40 small-30 tiny-30 align-center">
						<a href="<?php echo esc_url( f365_match_home_team_url( $match ) ); ?>"><img class="team-logo" src="<?php echo esc_url( f365_match_logo_url( (int) $match->home_team_id_real ) ); ?>" title="<?php echo esc_attr( $home_name ?: 'futebol365' ); ?>" width="100px" alt="<?php esc_attr_e( 'Logo equipa visitada', 'futebol365' ); ?>" onerror="this.onerror=null; this.src='<?php echo esc_url( futebol365_fallback_image() ); ?>';"></a>
					</div>
					<div class="all-20 small-40 tiny-40 align-center">
						<h2 class="match-score"><span class="ink-badge black no-margin quarter-vertical-padding uppercase"><?php echo esc_html( $result ); ?></span></h2>
						<?php if ( f365_match_has_extra_score( $match ) || f365_match_has_penalties( $match ) ) : ?>
							<span class="match-extra-score">[<?php echo esc_html( (string) $match->home_score ); ?> - <?php echo esc_html( (string) $match->visitor_score ); ?>] 90'</span>
						<?php endif; ?>
						<?php if ( f365_match_has_extra_score( $match ) ) : ?>
							<span class="match-extra-score">[<?php echo esc_html( (string) $match->home_score_extra ); ?> - <?php echo esc_html( (string) $match->visitor_score_extra ); ?>] <?php esc_html_e( 'a.p', 'futebol365' ); ?></span>
						<?php endif; ?>
						<?php if ( f365_match_has_penalties( $match ) ) : ?>
							<span class="match-extra-score">[<?php echo esc_html( (string) $match->penalty_home_score ); ?> - <?php echo esc_html( (string) $match->penalty_visitor_score ); ?>] <?php esc_html_e( 'g.p', 'futebol365' ); ?></span>
						<?php endif; ?>
						<?php
						// 1x2 odds (Statorium premium add-on): pre-match/live only —
						// odds on a finished match would mislead (same rule as the
						// header ticker). One get_option, keyed by the mirror match
						// id; empty when the match is outside the betting window.
						$f365_odds = null;
						if ( ! f365_match_has_finished( $match ) ) {
							$f365_odds_map = (array) get_option( 'f365_statorium_odds', array() );
							$f365_odds     = isset( $f365_odds_map[ (int) $match_id ] ) ? (array) $f365_odds_map[ (int) $match_id ] : null;
						}
						?>
						<?php if ( $f365_odds && isset( $f365_odds['1'], $f365_odds['x'], $f365_odds['2'] ) ) : ?>
							<style id="f365-match-odds-css">
								.match-odds1x2 { display: flex; gap: 4px; margin: 6px auto 0; padding: 0; list-style: none; max-width: 180px; }
								.match-odds1x2 li { flex: 1 1 0; text-align: center; font-size: 12px; line-height: 20px; background: #f0f0f1; border-radius: 3px; color: #333; }
								.match-odds1x2 li span { font-weight: 700; margin-right: 4px; color: #777; }
							</style>
							<ul class="match-odds1x2" title="<?php echo esc_attr( (string) ( $f365_odds['bk'] ?? '' ) ); ?>">
								<li><span>1</span><?php echo esc_html( number_format( (float) $f365_odds['1'], 2 ) ); ?></li>
								<li><span>X</span><?php echo esc_html( number_format( (float) $f365_odds['x'], 2 ) ); ?></li>
								<li><span>2</span><?php echo esc_html( number_format( (float) $f365_odds['2'], 2 ) ); ?></li>
							</ul>
						<?php endif; ?>
					</div>
					<div class="all-40 small-30 tiny-30 align-center">
						<a href="<?php echo esc_url( f365_match_visitor_team_url( $match ) ); ?>"><img class="team-logo" src="<?php echo esc_url( f365_match_logo_url( (int) $match->visitor_team_id_real ) ); ?>" title="<?php echo esc_attr( $visitor_name ?: 'futebol365' ); ?>" width="100px" alt="<?php esc_attr_e( 'Logo equipa visitante', 'futebol365' ); ?>" onerror="this.onerror=null; this.src='<?php echo esc_url( futebol365_fallback_image() ); ?>';"></a>
					</div>

					<div class="all-40 small-30 tiny-30 align-center half-top-space">
						<?php foreach ( f365_match_goal_events( $match, true ) as $event ) : ?>
							<span class="match-player-goals-scores"><a href="<?php echo esc_url( f365_match_player_games_url( (int) $event->player_id, $match ) ); ?>"><?php echo esc_html( (string) $event->player_name ); ?></a> <?php echo esc_html( (string) $event->minute ); ?>'
								<?php if ( 'own_goal' === $event->event_type ) : ?><?php esc_html_e( '(p.b.)', 'futebol365' ); ?><?php elseif ( 'goal_penalty' === $event->event_type ) : ?><?php esc_html_e( '(pen.)', 'futebol365' ); ?><?php endif; ?>
							</span>
						<?php endforeach; ?>
					</div>
					<div class="all-20 small-40 tiny-40 align-center half-top-space"></div>
					<div class="all-40 small-30 tiny-30 align-center half-top-space">
						<?php foreach ( f365_match_goal_events( $match, false ) as $event ) : ?>
							<span class="match-player-goals-scores"><a href="<?php echo esc_url( f365_match_player_games_url( (int) $event->player_id, $match ) ); ?>"><?php echo esc_html( (string) $event->player_name ); ?></a> <?php echo esc_html( (string) $event->minute ); ?>'
								<?php if ( 'own_goal' === $event->event_type ) : ?><?php esc_html_e( '(p.b.)', 'futebol365' ); ?><?php elseif ( 'goal_penalty' === $event->event_type ) : ?><?php esc_html_e( '(pen.)', 'futebol365' ); ?><?php endif; ?>
							</span>
						<?php endforeach; ?>
					</div>
				</div>

				<?php
				$has_players = f365_match_matchplayer_count( $match_id ) > 0;

				if ( 'matchevents' === $action ) :
					if ( $has_players ) :
						$home_formation    = f365_match_formation( $match_id, true );
						$visitor_formation = f365_match_formation( $match_id, false );
						$columns           = array(
							array(
								'name'      => $home_name,
								'color'     => 'blue',
								'teamstep'  => (int) $match->home_team_id,
								'type'      => (string) $match->home_team_type,
								'formation' => $home_formation,
								'is_home'   => true,
							),
							array(
								'name'      => $visitor_name,
								'color'     => 'green',
								'teamstep'  => (int) $match->visitor_team_id,
								'type'      => (string) $match->visitor_team_type,
								'formation' => $visitor_formation,
								'is_home'   => false,
							),
						);
						?>
						<div class="column-group half-horizontal-gutters">
							<?php foreach ( $columns as $col ) : ?>
								<div class="all-50 small-100 tiny-100">
									<h5 class="condensed-400 headerTitle <?php echo esc_attr( $col['color'] ); ?>"><?php echo esc_html( $col['name'] ); ?> <?php if ( $col['formation'] ) : ?><i class="push-right half-right-padding"><?php echo esc_html( $col['formation'] ); ?></i><?php endif; ?></h5>
									<table class="ink-table ink-table-f365 alternating all-100">
										<thead><tr><th class="align-center all-10"><?php esc_html_e( 'Nº', 'futebol365' ); ?></th><th class="align-left all-90"><?php esc_html_e( 'Jogador', 'futebol365' ); ?></th></tr></thead>
										<tbody>
										<?php foreach ( f365_match_players_titular( $match_id, $col['teamstep'] ) as $mp ) : ?>
											<?php $number = f365_match_player_number( $mp, $match, $col['type'] ); ?>
											<tr>
												<td class="align-center"><span class="ink-label ink-label-number <?php echo esc_attr( $col['color'] ); ?>"><?php echo esc_html( $number ? (string) $number : '-' ); ?></span></td>
												<td class="align-left">
													<a href="<?php echo esc_url( f365_match_player_games_url( (int) $mp->player_id, $match ) ); ?>"><?php echo esc_html( mb_substr( (string) $mp->player_name, 0, 24 ) ); ?></a>
													<?php foreach ( f365_match_player_events( (int) $mp->id ) as $event ) : ?>
														<div class="div_event_minute" title="<?php echo esc_attr( f365_match_event_label( $event->event_type ) . ' (' . $event->minute . "')" ); ?>"><span class="<?php echo esc_attr( (string) $event->event_type ); ?> span_event_minute"></span><span class="event_minute span_event_minute"><?php echo esc_html( $event->minute ? $event->minute . "'" : '' ); ?></span></div>
													<?php endforeach; ?>
												</td>
											</tr>
										<?php endforeach; ?>
										</tbody>
									</table>

									<?php $subs = f365_match_players_not_titular( $match_id, $col['teamstep'] ); ?>
									<?php if ( count( $subs ) > 0 ) : ?>
										<h5 class="condensed-400 headerTitle <?php echo esc_attr( $col['color'] ); ?>"><?php esc_html_e( 'Suplentes', 'futebol365' ); ?></h5>
										<table class="ink-table ink-table-f365 alternating all-100">
											<thead><tr><th class="align-center all-10"><?php esc_html_e( 'Nº', 'futebol365' ); ?></th><th class="align-left all-90"><?php esc_html_e( 'Jogador', 'futebol365' ); ?></th></tr></thead>
											<tbody>
											<?php foreach ( $subs as $mp ) : ?>
												<?php $number = f365_match_player_number( $mp, $match, $col['type'] ); ?>
												<tr>
													<td class="align-center"><span class="ink-label ink-label-number <?php echo esc_attr( $col['color'] ); ?>"><?php echo esc_html( $number ? (string) $number : '-' ); ?></span></td>
													<td class="align-left">
														<a href="<?php echo esc_url( f365_match_player_games_url( (int) $mp->player_id, $match ) ); ?>"><?php echo esc_html( mb_substr( (string) $mp->player_name, 0, 24 ) ); ?></a>
														<?php foreach ( f365_match_player_events( (int) $mp->id ) as $event ) : ?>
															<div class="div_event_minute" title="<?php echo esc_attr( f365_match_event_label( $event->event_type ) . ' (' . $event->minute . "')" ); ?>"><span class="<?php echo esc_attr( (string) $event->event_type ); ?> span_event_minute"></span><span class="event_minute span_event_minute"><?php echo esc_html( $event->minute ? $event->minute . "'" : '' ); ?></span></div>
														<?php endforeach; ?>
													</td>
												</tr>
											<?php endforeach; ?>
											</tbody>
										</table>
									<?php endif; ?>

									<?php $coaches = f365_match_coaches( $match_id, $col['is_home'] ); ?>
									<?php if ( count( $coaches ) > 0 ) : ?>
										<h5 class="condensed-400 headerTitle <?php echo esc_attr( $col['color'] ); ?>"><?php esc_html_e( 'Equipa Técnica', 'futebol365' ); ?></h5>
										<table class="ink-table ink-table-f365 alternating">
											<tbody>
											<?php foreach ( $coaches as $coach ) : ?>
												<tr>
													<td class="align-center all-10"><span class="ink-label ink-label-number <?php echo esc_attr( $col['color'] ); ?>"><?php esc_html_e( 'T', 'futebol365' ); ?></span></td>
													<td class="align-left all-90"><a href="<?php echo esc_url( f365_match_coach_url( (int) $coach->coach_id ) ); ?>"><?php echo esc_html( (string) $coach->coach_name ); ?></a></td>
												</tr>
											<?php endforeach; ?>
											</tbody>
										</table>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<h5 class="condensed-400 headerTitle blue"><?php esc_html_e( 'Ficha do Jogo', 'futebol365' ); ?></h5>
						<h6 class="no-info double-bottom-padding"><?php esc_html_e( 'Sem informação disponível', 'futebol365' ); ?></h6>
					<?php endif; ?>

				<?php elseif ( 'matchstats' === $action ) : ?>
					<h5 class="condensed-400 headerTitle blue"><?php esc_html_e( 'Estatísticas', 'futebol365' ); ?></h5>
					<?php if ( $has_players ) : ?>
						<?php
						$home_counts    = f365_match_count_players( $match_id, (int) $match->home_team_id );
						$visitor_counts = f365_match_count_players( $match_id, (int) $match->visitor_team_id );
						$home_avg       = f365_match_averages( $match_id, (int) $match->home_team_id );
						$visitor_avg    = f365_match_averages( $match_id, (int) $match->visitor_team_id );

						$possession_rows = array(
							array( __( 'Posse de bola (%)', 'futebol365' ), 'home_ball_possession', 'visitor_ball_possession' ),
							array( __( 'Remates', 'futebol365' ), 'home_shots', 'visitor_shots' ),
							array( __( 'Remates à baliza', 'futebol365' ), 'home_shots_on_goal', 'visitor_shots_on_goal' ),
							array( __( 'Faltas', 'futebol365' ), 'home_fouls', 'visitor_fouls' ),
							array( __( 'Cantos', 'futebol365' ), 'home_corner_kicks', 'visitor_corner_kicks' ),
							array( __( 'Foras-de-jogo', 'futebol365' ), 'home_offsides', 'visitor_offsides' ),
							array( __( 'Defesas do guarda-redes', 'futebol365' ), 'home_saves', 'visitor_saves' ),
							array( __( 'Lançamentos', 'futebol365' ), 'home_throw_ins', 'visitor_throw_ins' ),
							array( __( 'Livres', 'futebol365' ), 'home_free_kicks', 'visitor_free_kicks' ),
						);
						$show_possession = ( (int) $match->home_ball_possession !== 0 && (int) $match->visitor_ball_possession !== 0 );
						?>
						<div class="column-group">
							<div class="all-100">
								<table class="ink-table ink-table-f365 alternating">
									<thead><tr><th class="align-center all-33"><?php echo esc_html( $home_name ); ?></th><th class="align-center all-33"></th><th class="align-center all-33"><?php echo esc_html( $visitor_name ); ?></th></tr></thead>
									<tbody>
										<?php if ( $show_possession ) : ?>
											<?php foreach ( $possession_rows as $row ) : ?>
												<?php list( $label, $hcol, $vcol ) = $row; ?>
												<?php if ( $match->$hcol !== null ) : ?>
													<tr>
														<td class="align-center"><?php echo esc_html( (string) $match->$hcol ); ?></td>
														<td class="align-center"><?php echo esc_html( $label ); ?></td>
														<td class="align-center"><?php echo esc_html( (string) $match->$vcol ); ?></td>
													</tr>
												<?php endif; ?>
											<?php endforeach; ?>
										<?php endif; ?>
										<tr><td class="align-center"><?php echo esc_html( (string) f365_match_score_for( $match, true ) ); ?></td><td class="align-center"><?php esc_html_e( 'Golos', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html( (string) f365_match_score_for( $match, false ) ); ?></td></tr>
										<tr><td class="align-center"><?php echo esc_html( (string) $home_counts->penalty_goals ); ?></td><td class="align-center"><?php esc_html_e( 'Golos de Penalty', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html( (string) $visitor_counts->penalty_goals ); ?></td></tr>
										<tr><td class="align-center"><?php echo esc_html( (string) $home_counts->own_goals ); ?></td><td class="align-center"><?php esc_html_e( 'Golos na Própria Baliza', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html( (string) $visitor_counts->own_goals ); ?></td></tr>
										<tr><td class="align-center"><?php echo esc_html( (string) ( (int) $home_counts->yellow_cards + (int) $home_counts->double_yellow_cards ) ); ?></td><td class="align-center"><?php esc_html_e( 'Cartões Amarelos', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html( (string) ( (int) $visitor_counts->yellow_cards + (int) $visitor_counts->double_yellow_cards ) ); ?></td></tr>
										<tr><td class="align-center"><?php echo esc_html( (string) $home_counts->double_yellow_cards ); ?></td><td class="align-center"><?php esc_html_e( 'Acumulação Amarelos', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html( (string) $visitor_counts->double_yellow_cards ); ?></td></tr>
										<tr><td class="align-center"><?php echo esc_html( (string) $home_counts->red_cards ); ?></td><td class="align-center"><?php esc_html_e( 'Cartões Vermelhos', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html( (string) $visitor_counts->red_cards ); ?></td></tr>
										<tr><td class="align-center"><?php echo esc_html( (string) $home_counts->substitutions ); ?></td><td class="align-center"><?php esc_html_e( 'Substituições', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html( (string) $visitor_counts->substitutions ); ?></td></tr>
										<tr><td class="align-center"><?php echo esc_html( $home_avg->height ); ?></td><td class="align-center"><?php esc_html_e( 'Média Altura (cm)', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html( $visitor_avg->height ); ?></td></tr>
										<tr><td class="align-center"><?php echo esc_html( $home_avg->age ); ?></td><td class="align-center"><?php esc_html_e( 'Média Idade (anos)', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html( $visitor_avg->age ); ?></td></tr>
										<tr><td class="align-center"><?php echo esc_html( $home_avg->weight ); ?></td><td class="align-center"><?php esc_html_e( 'Média Peso (kg)', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html( $visitor_avg->weight ); ?></td></tr>
									</tbody>
								</table>
							</div>
						</div>
					<?php else : ?>
						<h6 class="no-info double-bottom-padding"><?php esc_html_e( 'Sem informação disponível', 'futebol365' ); ?></h6>
					<?php endif; ?>

				<?php elseif ( 'matchoccurrences' === $action ) : ?>
					<h5 class="condensed-400 headerTitle blue"><?php esc_html_e( 'Eventos', 'futebol365' ); ?></h5>
					<?php if ( $has_players ) : ?>
						<table class="ink-table ink-table-f365 alternating">
							<thead><tr><th class="align-center all-10"><?php esc_html_e( 'Min', 'futebol365' ); ?></th><th class="align-left all-90"><?php esc_html_e( 'Ocorrência', 'futebol365' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( f365_match_occurrences( $match ) as $occ ) : ?>
								<?php
								$label = f365_match_event_label( $occ->event_type );
								if ( in_array( $occ->event_type, array( 'goal', 'goal_penalty', 'own_goal' ), true ) ) {
									$score  = f365_match_result_at_minute( $match, (int) $occ->minute );
									$label .= ' <b>(' . $score[0] . '-' . $score[1] . ')</b>';
								}
								$team_url = f365_match_teamstep_url(
									(int) $occ->ts_team_id,
									(string) $match->competition_edition,
									(string) $occ->modality,
									(string) $occ->gender,
									(string) $occ->step
								);
								if ( 'national' === $occ->team_type ) {
									$team_url .= '&competition=' . $match->competition_id;
								}
								$team_label = f365_match_teamstep_name( (string) $occ->team_shortname, (string) $occ->step, (string) $occ->gender, (string) $occ->modality );
								?>
								<tr>
									<td class="align-center"><b><?php echo esc_html( (string) $occ->minute ); ?>'</b></td>
									<td class="align-left">
										<span class="<?php echo esc_attr( (string) $occ->event_type ); ?> event-type-right-padding"></span>
										<?php echo wp_kses( $label, array( 'b' => array() ) ); ?>
										<?php if ( in_array( $occ->event_type, array( 'goal', 'goal_penalty', 'own_goal' ), true ) ) : ?>
											<?php esc_html_e( 'marcado por', 'futebol365' ); ?>
										<?php elseif ( in_array( $occ->event_type, array( 'yellow_card', 'double_yellow_card', 'red_card' ), true ) ) : ?>
											<?php esc_html_e( 'para', 'futebol365' ); ?>
										<?php endif; ?>
										<a href="<?php echo esc_url( f365_match_player_games_url( (int) $occ->player_id, $match ) ); ?>"><?php echo esc_html( (string) $occ->player_name ); ?></a> (<a href="<?php echo esc_url( $team_url ); ?>"><?php echo esc_html( $team_label ); ?></a>)
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<h6 class="no-info double-bottom-padding"><?php esc_html_e( 'Sem informação disponível', 'futebol365' ); ?></h6>
					<?php endif; ?>

				<?php elseif ( 'matchvideos' === $action ) : ?>
					<h5 class="condensed-400 headerTitle blue"><?php esc_html_e( 'Vídeos', 'futebol365' ); ?></h5>
					<?php $videos = f365_match_videos( $match_id ); ?>
					<?php if ( count( $videos ) > 0 ) : ?>
						<div class="column-group gutters">
							<?php foreach ( $videos as $video ) : ?>
								<?php $video_url = home_url( '/multimedia/videos/' . (int) $video->id . '/' ); ?>
								<div class="all-25 small-50 tiny-100">
									<figure class="ink-image half-bottom-space my-thumb-16-9">
										<a href="<?php echo esc_url( $video_url ); ?>">
											<img class="my-thumb-image" src="<?php echo esc_url( f365_match_media_url( (string) $video->image ) ); ?>" title="<?php echo esc_attr( (string) $video->title ?: 'futebol365' ); ?>" alt="<?php esc_attr_e( 'Futebol 365', 'futebol365' ); ?>" onerror="this.onerror=null; this.src='<?php echo esc_url( futebol365_fallback_image() ); ?>';">
										</a>
										<?php if ( ! empty( $video->length ) ) : ?><figcaption class="over-bottom dark figcaption-videos"><?php echo esc_html( (string) $video->length ); ?></figcaption><?php endif; ?>
									</figure>
									<h3 class="quarter-vertical-space medium uppercase condensed-400"><?php echo esc_html( date_i18n( 'd-m-Y', strtotime( (string) $video->datetime ) ) ); ?></h3>
									<h2 class="large condensed-700"><a href="<?php echo esc_url( $video_url ); ?>"><?php echo esc_html( (string) $video->title ); ?></a></h2>
								</div>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<h6 class="no-info"><?php esc_html_e( 'Sem informação disponível', 'futebol365' ); ?></h6>
					<?php endif; ?>

				<?php elseif ( 'matchnews' === $action ) : ?>
					<h5 class="condensed-400 headerTitle blue"><?php esc_html_e( 'Notícias', 'futebol365' ); ?></h5>
					<?php $news = f365_match_news( $match_id ); ?>
					<?php if ( count( $news ) > 0 ) : ?>
						<?php foreach ( $news as $item ) : ?>
							<?php $news_url = home_url( '/artigo/' . rawurlencode( (string) $item->slug ) . '/' ); ?>
							<div class="news-list-cat">
								<article class="column-group gutters">
									<div class="all-30 small-100 tiny-100">
										<a href="<?php echo esc_url( $news_url ); ?>">
											<figure class="ink-image my-thumb-3-2">
												<img class="my-thumb-image" src="<?php echo esc_url( f365_match_media_url( (string) $item->image ) ); ?>" title="<?php echo esc_attr( (string) $item->title ?: 'futebol365' ); ?>" alt="<?php echo esc_attr( (string) $item->title ?: 'futebol365' ); ?>" onerror="this.onerror=null; this.src='<?php echo esc_url( futebol365_fallback_image() ); ?>';">
												<?php if ( ! empty( $item->video_embed_id ) ) : ?><svg class="video-thumbnail-icon video-thumbnail-icon-svg medium" aria-hidden="true" focusable="false"><use href="#icon-play"></use></svg><?php endif; ?>
											</figure>
										</a>
									</div>
									<div class="all-70 small-100 tiny-100">
										<?php if ( ! empty( $item->category_name ) ) : ?><h2 class="uppercase condensed-400"><?php echo esc_html( (string) $item->category_name ); ?></h2><?php endif; ?>
										<h3 class="condensed-700"><a href="<?php echo esc_url( $news_url ); ?>"><?php echo esc_html( (string) $item->title ); ?></a></h3>
										<h4 class="fw-400 hide-medium hide-small hide-tiny"><?php echo esc_html( wp_trim_words( (string) $item->summary, 50, '…' ) ); ?></h4>
										<h5 class="uppercase condensed-400"><?php echo esc_html( date_i18n( 'd-m-Y', strtotime( (string) $item->datetime ) ) . ' · ' . date_i18n( 'H:i', strtotime( (string) $item->datetime ) ) ); ?></h5>
									</div>
								</article>
							</div>
						<?php endforeach; ?>
					<?php else : ?>
						<h6 class="no-info"><?php esc_html_e( 'Sem informação disponível', 'futebol365' ); ?></h6>
					<?php endif; ?>

				<?php elseif ( 'matchpreview' === $action ) : ?>
					<?php if ( f365_match_is_available_by_status_and_time( $match ) ) : ?>
						<?php $prob = f365_match_probability( $match ); ?>
						<h5 class="condensed-400 headerTitle blue"><?php esc_html_e( 'Probabilidades', 'futebol365' ); ?></h5>
						<table class="ink-table alternating ink-table-futebol365">
							<thead><tr><th class="all-30 align-center"><?php echo esc_html( $home_name ); ?></th><th class="all-30 align-center"><?php esc_html_e( 'Empate', 'futebol365' ); ?></th><th class="all-30 align-center"><?php echo esc_html( $visitor_name ); ?></th><th class="all-10"></th></tr></thead>
							<tbody>
								<tr>
									<td class="align-center"><a href="https://goto.futebol365.pt/botao-apostar" target="_blank" rel="noopener"><?php echo esc_html( (string) $prob['1'] ); ?>%</a></td>
									<td class="align-center"><a href="https://goto.futebol365.pt/botao-apostar" target="_blank" rel="noopener"><?php echo esc_html( (string) $prob['x'] ); ?>%</a></td>
									<td class="align-center"><a href="https://goto.futebol365.pt/botao-apostar" target="_blank" rel="noopener"><?php echo esc_html( (string) $prob['2'] ); ?>%</a></td>
									<td class="aling-center"><a href="https://www.futebol365.pt/goto/" target="_blank" rel="noopener"><span class="ink-tooltip ink-button-related orange" data-tip-text="<?php echo esc_attr__( 'Apostar neste jogo', 'futebol365' ); ?>" data-tip-where="up" data-tip-color="orange"><?php esc_html_e( 'APOSTAR', 'futebol365' ); ?></span></a></td>
								</tr>
							</tbody>
						</table>
					<?php endif; ?>

					<?php
					$h2h               = f365_match_teams_matches( $match );
					$home_team_real    = (int) $match->home_team_id_real;
					$visitor_team_real = (int) $match->visitor_team_id_real;
					?>
					<?php if ( count( $h2h ) > 0 ) : ?>
						<?php
						$cnt = f365_match_h2h_counts( $h2h, $home_team_real, $visitor_team_real );
						$avg = static function ( int $g, int $t ): string {
							return $t > 0 ? number_format( $g / $t, 2 ) : '0.00';
						};
						?>
						<h5 class="condensed-400 headerTitle blue top-space small-double-top-space tiny-double-top-space"><?php esc_html_e( 'Resumo de Confrontos', 'futebol365' ); ?></h5>
						<div class="column-group">
							<table class="ink-table alternating ink-table-futebol365">
								<thead><tr><th class="align-center all-33"><?php echo esc_html( $home_name ); ?></th><th class="align-center all-33"></th><th class="align-center all-33"><?php echo esc_html( $visitor_name ); ?></th></tr></thead>
								<tbody>
									<tr><td class="align-center"><?php echo esc_html( (string) $cnt->total ); ?></td><td class="align-center"><?php esc_html_e( 'Jogos', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html( (string) $cnt->total ); ?></td></tr>
									<tr><td class="align-center"><?php echo esc_html( (string) $cnt->home_wins ); ?></td><td class="align-center"><?php esc_html_e( 'Vitórias', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html( (string) $cnt->visitor_wins ); ?></td></tr>
									<tr><td class="align-center"><?php echo esc_html( (string) $cnt->draws ); ?></td><td class="align-center"><?php esc_html_e( 'Empates', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html( (string) $cnt->draws ); ?></td></tr>
									<tr><td class="align-center"><?php echo esc_html( (string) $cnt->visitor_wins ); ?></td><td class="align-center"><?php esc_html_e( 'Derrotas', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html( (string) $cnt->home_wins ); ?></td></tr>
									<tr><td class="align-center"><?php echo esc_html( (string) $cnt->home_goals ); ?></td><td class="align-center"><?php esc_html_e( 'Golos marcados', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html( (string) $cnt->visitor_goals ); ?></td></tr>
									<tr><td class="align-center"><?php echo esc_html( (string) $cnt->visitor_goals ); ?></td><td class="align-center"><?php esc_html_e( 'Golos sofridos', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html( (string) $cnt->home_goals ); ?></td></tr>
									<tr><td class="align-center"><?php echo esc_html( $avg( (int) $cnt->home_goals, (int) $cnt->total ) ); ?></td><td class="align-center"><?php esc_html_e( 'Média de golos marcados', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html( $avg( (int) $cnt->visitor_goals, (int) $cnt->total ) ); ?></td></tr>
									<tr><td class="align-center"><?php echo esc_html( $avg( (int) $cnt->visitor_goals, (int) $cnt->total ) ); ?></td><td class="align-center"><?php esc_html_e( 'Média de golos sofridos', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html( $avg( (int) $cnt->home_goals, (int) $cnt->total ) ); ?></td></tr>
									<tr><td class="align-center"><?php echo esc_html( (string) ( (int) $cnt->home_goals - (int) $cnt->visitor_goals ) ); ?></td><td class="align-center"><?php esc_html_e( 'Diferença de golos', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html( (string) ( (int) $cnt->visitor_goals - (int) $cnt->home_goals ) ); ?></td></tr>
								</tbody>
							</table>
						</div>
						<div class="column-group"><div class="all-100"><a class="ink-button" href="<?php echo esc_url( f365_match_compare_url( $match ) ); ?>"><?php esc_html_e( 'Ver mais', 'futebol365' ); ?></a></div></div>

						<h5 class="condensed-400 headerTitle blue top-space small-double-top-space tiny-double-top-space"><?php esc_html_e( 'Últimos Confrontos', 'futebol365' ); ?></h5>
						<div class="column-group">
							<table class="ink-table ink-table-futebol365 alternating">
								<thead><tr><th class="all-12 align-left"><?php esc_html_e( 'Data', 'futebol365' ); ?></th><th class="all-8 align-center"><?php esc_html_e( 'Hora', 'futebol365' ); ?></th><th class="all-30 align-right"><?php esc_html_e( 'Visitado', 'futebol365' ); ?></th><th class="all-10 align-center"></th><th class="all-30 align-left"><?php esc_html_e( 'Visitante', 'futebol365' ); ?></th><th class="all-10 align-left"></th></tr></thead>
								<tbody>
								<?php
									$h2h_rows    = array_slice( $h2h, 0, 6 );
									$h2h_vid_ids = array();
									foreach ( $h2h_rows as $r ) { $h2h_vid_ids[] = (int) $r->id; }
									$h2h_vid_ids = array_values( array_filter( array_unique( $h2h_vid_ids ) ) );
									$h2h_video   = array();
									if ( ! empty( $h2h_vid_ids ) ) {
										$h2h_in   = implode( ',', array_map( 'intval', $h2h_vid_ids ) );
										$h2h_vrows = $wpdb->get_col( "SELECT DISTINCT match_id FROM {$wpdb->prefix}multimedia_video WHERE match_id IN ($h2h_in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
										foreach ( (array) $h2h_vrows as $h2h_vmid ) { $h2h_video[ (int) $h2h_vmid ] = true; }
									}
									?>
									<?php foreach ( $h2h_rows as $r ) : ?>
									<?php
									$r_home        = f365_match_teamstep_name( (string) $r->home_shortname, (string) $r->home_step, (string) $r->home_gender, (string) $r->home_modality );
									$r_visitor     = f365_match_teamstep_name( (string) $r->visitor_shortname, (string) $r->visitor_step, (string) $r->visitor_gender, (string) $r->visitor_modality );
									$r_home_url    = f365_match_teamstep_url( (int) $r->home_team_id_real, (string) $r->competition_edition, (string) $r->home_modality, (string) $r->home_gender, (string) $r->home_step );
									$r_visitor_url = f365_match_teamstep_url( (int) $r->visitor_team_id_real, (string) $r->competition_edition, (string) $r->visitor_modality, (string) $r->visitor_gender, (string) $r->visitor_step );
										$r_match_url   = home_url( '/jogo/' . (int) $r->id . '/' );
										$r_compare_url = ( (int) $r->home_team_id_real && (int) $r->visitor_team_id_real )
											? f365_match_compare_url( $r )
											: '';
										$r_has_video   = ! empty( $h2h_video[ (int) $r->id ] );
									?>
									<tr>
										<td class="align-left cell-nowrap"><?php echo esc_html( date_i18n( 'd-m-y', strtotime( (string) $r->date_time ) ) ); ?></td>
										<td class="align-center cell-nowrap"><?php echo esc_html( date_i18n( 'H:i', strtotime( (string) $r->date_time ) ) ); ?></td>
										<td class="align-right"><a href="<?php echo esc_url( $r_home_url ); ?>"><?php echo esc_html( $r_home ); ?></a></td>
										<td class="align-center"><span class="ink-label ink-label-score silver"><a href="<?php echo esc_url( $r_match_url ); ?>"><?php echo esc_html( f365_match_get_result( $r ) ); ?></a></span></td>
										<td class="align-left"><a href="<?php echo esc_url( $r_visitor_url ); ?>"><?php echo esc_html( $r_visitor ); ?></a></td>
										<td class="align-left">
											<?php if ( $r_compare_url ) : ?>
												<a href="<?php echo esc_url( $r_compare_url ); ?>" aria-label="<?php esc_attr_e( 'Comparar equipas', 'futebol365' ); ?>">
													<span class="ink-tooltip ink-button-related green" data-tip-text="<?php esc_attr_e( 'Comparar equipas', 'futebol365' ); ?>" data-tip-where="up" data-tip-color="green">C</span>
												</a>
											<?php endif; ?>
											<?php if ( $r_has_video ) : ?>
												<a href="<?php echo esc_url( add_query_arg( 'match_opt', 'matchvideos', $r_match_url ) ); ?>" aria-label="<?php esc_attr_e( 'Vídeo', 'futebol365' ); ?>">
													<span class="ink-tooltip ink-button-related red" data-tip-text="<?php esc_attr_e( 'Vídeo', 'futebol365' ); ?>" data-tip-where="up" data-tip-color="red">V</span>
												</a>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<div class="column-group"><div class="all-100"><a class="ink-button" href="<?php echo esc_url( f365_match_compare_confrontation_url( $match ) ); ?>"><?php esc_html_e( 'Ver mais', 'futebol365' ); ?></a></div></div>

						<?php if ( 'national' !== $match->home_team_type && 'national' !== $match->visitor_team_type ) : ?>
							<?php
							$bw_home_first     = f365_match_biggest_win( $h2h, $home_team_real, true );
							$bw_home_second    = f365_match_biggest_win( $h2h, $visitor_team_real, true );
							$bw_visitor_first  = f365_match_biggest_win( $h2h, $home_team_real, false );
							$bw_visitor_second = f365_match_biggest_win( $h2h, $visitor_team_real, false );
							$rl                = static function ( ?object $r ): string {
								return $r ? '<a href="' . esc_url( home_url( '/jogo/' . (int) $r->id . '/' ) ) . '">' . esc_html( f365_match_get_result( $r ) ) . '</a>' : '-';
							};
							?>
							<h5 class="condensed-400 headerTitle blue top-space small-double-top-space tiny-double-top-space"><?php esc_html_e( 'Curiosidades', 'futebol365' ); ?></h5>
							<div class="column-group">
								<table class="ink-table ink-table-futebol365 alternating">
									<thead><tr><th class="align-center all-33"><?php echo esc_html( $home_name ); ?></th><th class="align-center all-33"></th><th class="align-center all-33"><?php echo esc_html( $visitor_name ); ?></th></tr></thead>
									<tbody>
										<tr><td class="align-center"><?php echo wp_kses_post( $rl( $bw_home_first ) ); ?></td><td class="align-center"><?php esc_html_e( 'Maior vitória em casa', 'futebol365' ); ?></td><td class="align-center"><?php echo wp_kses_post( $rl( $bw_home_second ) ); ?></td></tr>
										<tr><td class="align-center"><?php echo wp_kses_post( $rl( $bw_visitor_first ) ); ?></td><td class="align-center"><?php esc_html_e( 'Maior vitória fora', 'futebol365' ); ?></td><td class="align-center"><?php echo wp_kses_post( $rl( $bw_visitor_second ) ); ?></td></tr>
										<tr><td class="align-center"><?php echo wp_kses_post( $rl( $bw_visitor_second ) ); ?></td><td class="align-center"><?php esc_html_e( 'Maior derrota em casa', 'futebol365' ); ?></td><td class="align-center"><?php echo wp_kses_post( $rl( $bw_visitor_first ) ); ?></td></tr>
										<tr><td class="align-center"><?php echo wp_kses_post( $rl( $bw_home_second ) ); ?></td><td class="align-center"><?php esc_html_e( 'Maior derrota fora', 'futebol365' ); ?></td><td class="align-center"><?php echo wp_kses_post( $rl( $bw_home_first ) ); ?></td></tr>
									</tbody>
								</table>
							</div>
						<?php endif; ?>
					<?php else : ?>
						<h5 class="condensed-400 headerTitle blue"><?php esc_html_e( 'Resumo de confrontos', 'futebol365' ); ?></h5>
						<h6 class="no-info"><?php esc_html_e( 'Sem informação disponível', 'futebol365' ); ?></h6>
					<?php endif; ?>
				<?php endif; ?>

				<?php if ( $match->observations !== null && $match->observations !== '' ) : ?>
					<div class="column-group vertical-gutters">
						<div class="all-100">
							<blockquote>
								<h6 class="headerTitleSimple no-left"><?php esc_html_e( 'Notas do jogo', 'futebol365' ); ?></h6>
								<h6 class="no-info no-left"><?php echo nl2br( esc_html( (string) $match->observations ) ); ?></h6>
							</blockquote>
						</div>
					</div>
				<?php endif; ?>
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
