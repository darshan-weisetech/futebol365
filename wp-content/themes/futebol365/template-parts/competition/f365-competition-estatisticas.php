<?php
/**
 * Competition — Estatísticas
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

get_header();
global $wpdb;

$current_post_id = get_the_ID();
$current_section = 'estatisticas';
$sections        = futebol365_get_competition_sections();

//$final_competition_id = get_post_meta( $current_post_id, 'django_competition_id', true );
$final_competition_id = get_legacy_competition_id( $current_post_id );
$competition_id       = intval( $final_competition_id );

$comp_table  = $wpdb->prefix . 'competition_competition';
$phase_table = $wpdb->prefix . 'competition_competitionphase';
$match_table = $wpdb->prefix . 'competition_match';
$ts_table    = $wpdb->prefix . 'team_teamstep';
$team_table  = $wpdb->prefix . 'team_team';

$comp_row = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT federation_competition_id, edition, statistics_start FROM {$comp_table} WHERE id = %d",
		$competition_id
	),
	OBJECT
);

$fed_comp_id     = $comp_row ? intval( $comp_row->federation_competition_id ) : 0;
$comp_edition    = $comp_row ? (string) $comp_row->edition : '';
$statistics_start = ( $comp_row && null !== $comp_row->statistics_start && '' !== $comp_row->statistics_start )
	? intval( $comp_row->statistics_start ) : null;

// Canonical page URL (matches the Django route /competicao/{fed_comp_id}/estatisticas/).
$stats_base_url = home_url( '/competicao/' . intval( $fed_comp_id ) . '/estatisticas/' );

$ST_TYPE_CHOICES = array(
	'st_type_teams'   => 'Equipas',
	'st_type_players' => 'Jogadores',
	'st_type_matches' => 'Jogos',
);

$ST_OP_TEAM = array(
	'st_op_victories'             => 'Vitórias',
	'st_op_draws'                 => 'Empates',
	'st_op_loses'                 => 'Derrotas',
	'st_op_matches_without_lose'  => 'Jogos sem perder',
	'st_op_matches_without_win'   => 'Jogos sem vencer',
	'st_op_matches_without_suffer'=> 'Jogos sem sofrer golos',
	'st_op_matches_without_score' => 'Jogos sem marcar',
	'st_op_scored_goals'          => 'Golos marcados',
	'st_op_suffered_goals'        => 'Golos sofridos',
	'st_op_penalty_goals'         => 'Golos de penalty',
	'st_op_own_goals'             => 'Golos própria baliza',
	'st_op_bench_goals'           => 'Golos vindos do banco',
	'st_op_yellow_cards'          => 'Cartões amarelos',
	'st_op_double_yellow_cards'   => 'Acumulação amarelos',
	'st_op_red_cards'             => 'Cartões Vermelhos',
	'st_op_total_cards'           => 'Total de cartões',
	'st_op_total_substitutions'   => 'Total de substituições',
	'st_op_height'                => 'Altura',
	'st_op_age'                   => 'Idade',
	'st_op_weight'                => 'Peso',
);

$ST_OP_MATCH = array(
	'st_op_scored_goals'             => 'Golos marcados',
	'st_op_goal_difference'          => 'Diferença de golos',
	'st_op_biggest_victories_home'   => 'Maiores vitórias (casa)',
	'st_op_biggest_victories_visitor'=> 'Maiores vitórias (fora)',
	'st_op_fastest_goals'            => 'Golos mais rápidos',
	'st_op_penalty_goals'            => 'Mais golos de penalty',
	'st_op_own_goals'                => 'Mais golos própria baliza',
	'st_op_yellow_cards'             => 'Cartões amarelos',
	'st_op_double_yellow_cards'      => 'Acumulação amarelos',
	'st_op_red_cards'                => 'Cartões Vermelhos',
	'st_op_total_cards'              => 'Total de cartões',
	'st_op_total_substitutions'      => 'Total de substituições',
);

$ST_OP_PLAYER = array(
	'st_op_total_matches'       => 'Total de jogos',
	'st_op_played_minutes'      => 'Minutos jogados',
	'st_op_complete_matches'    => 'Jogos completos',
	'st_op_titular_matches'     => 'Jogos como titular',
	'st_op_out'                 => 'Substituído',
	'st_op_in'                  => 'Suplente utilizado',
	'st_op_scored_goals'        => 'Golos marcados',
	'st_op_hat_tricks'          => 'Hat-tricks',
	'st_op_penalty_goals'       => 'Golos de penalty',
	'st_op_own_goals'           => 'Golos própria baliza',
	'st_op_bench_goals'         => 'Golos vindos do banco',
	'st_op_yellow_cards'        => 'Cartões amarelos',
	'st_op_double_yellow_cards' => 'Acumulação amarelos',
	'st_op_red_cards'           => 'Cartões Vermelhos',
	'st_op_total_cards'         => 'Total de cartões',
);

// DROPDOWN_STRUCTURE (valid options per type), preserving order.
$DROPDOWN_STRUCTURE = array(
	'st_type_teams'   => array_keys( $ST_OP_TEAM ),
	'st_type_matches' => array_keys( $ST_OP_MATCH ),
	'st_type_players' => array_keys( $ST_OP_PLAYER ),
);

// Team options that are computable from competition_match alone (match_options
// minus the event/squad-based ones). The rest require player/event tables.
$TEAM_MATCH_TRACTABLE = array(
	'st_op_victories', 'st_op_draws', 'st_op_loses',
	'st_op_matches_without_lose', 'st_op_matches_without_win',
	'st_op_matches_without_suffer', 'st_op_matches_without_score',
	'st_op_scored_goals', 'st_op_suffered_goals',
);

/* -------------------------------------------------------------------------
 * Resolve statistic_type / option / phase / round (mirrors the view).
 * ---------------------------------------------------------------------- */
$statistic_type = ( isset( $_GET['statistic_type'] ) && array_key_exists( wp_unslash( $_GET['statistic_type'] ), $ST_TYPE_CHOICES ) )
	? sanitize_text_field( wp_unslash( $_GET['statistic_type'] ) )
	: 'st_type_teams';

$valid_options = $DROPDOWN_STRUCTURE[ $statistic_type ];
if ( isset( $_GET['statistic_option'] ) && in_array( wp_unslash( $_GET['statistic_option'] ), $valid_options, true ) ) {
	$statistic_option = sanitize_text_field( wp_unslash( $_GET['statistic_option'] ) );
} else {
	if ( 'st_type_teams' === $statistic_type ) {
		$statistic_option = 'st_op_victories';
	} elseif ( 'st_type_matches' === $statistic_type ) {
		$statistic_option = 'st_op_scored_goals';
	} else {
		$statistic_option = 'st_op_scored_goals';
	}
}

$statistic_phase = ( isset( $_GET['statistic_phase'] ) && '' !== $_GET['statistic_phase'] && 'all' !== $_GET['statistic_phase'] )
	? sanitize_text_field( wp_unslash( $_GET['statistic_phase'] ) ) : null;

$statistic_round = ( isset( $_GET['statistic_round'] ) && is_numeric( $_GET['statistic_round'] ) )
	? intval( $_GET['statistic_round'] ) : null;

// Title = label of the selected option.
$option_labels = ( 'st_type_teams' === $statistic_type ) ? $ST_OP_TEAM
	: ( ( 'st_type_matches' === $statistic_type ) ? $ST_OP_MATCH : $ST_OP_PLAYER );
$title = isset( $option_labels[ $statistic_option ] ) ? $option_labels[ $statistic_option ] : '';

/* -------------------------------------------------------------------------
 * Phases / rounds for the filter (StatisticsFilterForm).
 * ---------------------------------------------------------------------- */
$all_phases = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT id, name, `order` FROM {$phase_table} WHERE competition_id = %d ORDER BY `order` ASC",
		$competition_id
	),
	OBJECT
);

// has_competition_phases_for_statistics(): with statistics_start>0 keep phases whose
// order >= statistics_start; otherwise any phase qualifies.
$phase_choices = array();
foreach ( $all_phases as $ph ) {
	if ( null !== $statistics_start && $statistics_start > 0 ) {
		if ( intval( $ph->order ) >= $statistics_start ) {
			$phase_choices[] = $ph->name;
		}
	} else {
		$phase_choices[] = $ph->name;
	}
}
$has_stat_phases = ! empty( $phase_choices );

// Resolve selected phase order (for filtering + round choices).
$selected_phase_order = null;
if ( $statistic_phase ) {
	foreach ( $all_phases as $ph ) {
		if ( $ph->name === $statistic_phase ) {
			$selected_phase_order = intval( $ph->order );
			break;
		}
	}
}

// Round choices: phase-scoped group_phase_round when a phase is selected; otherwise
// (no competition phases) the distinct match_round list.
$round_choices = array();
$show_round    = false;

if ( $has_stat_phases ) {
	if ( $statistic_phase && null !== $selected_phase_order ) {
		$round_choices = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT m.group_phase_round
				   FROM {$match_table} m
				   JOIN {$phase_table} cp ON m.competition_phase_id = cp.id
				  WHERE m.competition_id = %d AND cp.name = %s
				    AND m.group_phase_round IS NOT NULL
				  ORDER BY m.group_phase_round ASC",
				$competition_id,
				$statistic_phase
			)
		);

		$round_choices = array_map( 'intval', array_filter( $round_choices, 'strlen' ) );
		$show_round    = ! empty( $round_choices );
	}
} else {
	$round_choices = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT match_round FROM {$match_table} WHERE competition_id = %d ORDER BY match_round ASC",
			$competition_id
		)
	);

	$round_choices = array_map( 'intval', $round_choices );
	$show_round    = ! empty( $round_choices );
}

if ( ! function_exists( 'f365_teamstep_label' ) ) {
	/**
	 * TeamStep.__unicode__: team shortname + FS/FM/step/B/C/VET/RES suffix.
	 */
	function f365_teamstep_label( $shortname, $step, $gender, $modality ) {
		$rest = '';
		if ( 'futsal' === $modality ) {
			$rest .= ' FS';
		}
		if ( 'female' === $gender ) {
			$rest .= ' FM';
		}
		if ( ! in_array( $step, array( 'principal', 'b_team', 'c_team', 'veterans', 'reservations' ), true ) ) {
			// get_step_display(): sub_23 -> S23, sub_19_b -> S19 B.
			if ( 0 === strpos( $step, 'sub_' ) ) {
				$num  = substr( $step, 4 );
				$disp = 'S' . str_replace( '_b', ' B', $num );
			} else {
				$disp = strtoupper( $step );
			}
			$rest .= ' ' . $disp;
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
		return '' !== $rest ? $shortname . ' ' . $rest : $shortname;
	}
}

if ( ! function_exists( 'f365_teamstep_url' ) ) {
	/**
	 * TeamStep.get_absolute_url: reverse('team_matches') + edition/modality/gender/step.
	 */
	function f365_teamstep_url( $team_id, $edition, $modality, $gender, $step ) {
		$qs = http_build_query(
			array(
				'edition'  => $edition,
				'modality' => $modality,
				'gender'   => $gender,
				'step'     => $step,
			)
		);
		return home_url( '/equipa/' . intval( $team_id ) . '/jogos/?' . $qs );
	}
}

if ( ! function_exists( 'f365_stats_page_link' ) ) {
	/**
	 * Build a pagination link preserving current GET params, setting 'pagina'.
	 */
	function f365_stats_page_link( $page ) {
		$params = $_GET; // phpcs:ignore WordPress.Security.NonceVerification
		$params = array_map( 'sanitize_text_field', wp_unslash( $params ) );
		if ( $page <= 1 ) {
			unset( $params['pagina'] );
		} else {
			$params['pagina'] = $page;
		}
		$qs = http_build_query( $params );
		return esc_url( ( '' !== $qs ) ? ( '?' . $qs ) : '?' );
	}
}


$header  = array( 'Sum' => 'Total', 'Total' => 'Jogos', 'Avg' => 'Média' );
$results = array();

$mp_table     = $wpdb->prefix . 'competition_matchplayer';
$squad_table  = $wpdb->prefix . 'team_squad';
$player_table = $wpdb->prefix . 'person_player';
$person_table = $wpdb->prefix . 'person_person';

$TEAM_MATCH_BASED = array( 'st_op_victories', 'st_op_draws', 'st_op_loses', 'st_op_matches_without_lose', 'st_op_matches_without_win', 'st_op_matches_without_suffer', 'st_op_matches_without_score', 'st_op_scored_goals', 'st_op_suffered_goals' );
$TEAM_MP_BASED    = array( 'st_op_penalty_goals', 'st_op_own_goals', 'st_op_bench_goals', 'st_op_yellow_cards', 'st_op_double_yellow_cards', 'st_op_red_cards', 'st_op_total_cards', 'st_op_total_substitutions' );
$TEAM_SQUAD_BASED = array( 'st_op_age', 'st_op_height', 'st_op_weight' );

if ( ! function_exists( 'f365_match_facts' ) ) {
	/** Per-match winner/draw/goal facts (penalty > extra > normal). */
	function f365_match_facts( $m ) {
		$hw = false;
		$vw = false;
		if ( null !== $m->penalty_home_score && null !== $m->penalty_visitor_score ) {
			$hw = intval( $m->penalty_home_score ) > intval( $m->penalty_visitor_score );
			$vw = intval( $m->penalty_home_score ) < intval( $m->penalty_visitor_score );
		} elseif ( null !== $m->home_score_extra && null !== $m->visitor_score_extra ) {
			$hw = intval( $m->home_score_extra ) > intval( $m->visitor_score_extra );
			$vw = intval( $m->home_score_extra ) < intval( $m->visitor_score_extra );
		} elseif ( null !== $m->home_score && null !== $m->visitor_score ) {
			$hw = intval( $m->home_score ) > intval( $m->visitor_score );
			$vw = intval( $m->home_score ) < intval( $m->visitor_score );
		}
		$hs = ( null !== $m->home_score_extra ) ? intval( $m->home_score_extra ) : ( null !== $m->home_score ? intval( $m->home_score ) : null );
		$vs = ( null !== $m->visitor_score_extra ) ? intval( $m->visitor_score_extra ) : ( null !== $m->visitor_score ? intval( $m->visitor_score ) : null );
		return array( 'hw' => $hw, 'vw' => $vw, 'draw' => ( ! $hw && ! $vw ), 'hs' => $hs, 'vs' => $vs );
	}
}
if ( ! function_exists( 'f365_round_text' ) ) {
	/** get_round_group_or_phase: phase name (+ Jornada) or "{round}ª Jornada". */
	function f365_round_text( $m ) {
		if ( null !== $m->phase_name && '' !== $m->phase_name ) {
			$t = $m->phase_name;
			if ( null !== $m->group_phase_round && '' !== $m->group_phase_round ) {
				$t .= ' - ' . intval( $m->group_phase_round ) . 'ª Jornada';
			}
			return $t;
		}
		return intval( $m->match_round ) . 'ª Jornada';
	}
}
if ( ! function_exists( 'f365_round_link' ) ) {
	function f365_round_link( $m, $fed_comp_id, $edition ) {
		if ( null !== $m->phase_name && '' !== $m->phase_name ) {
			$args = 'competition_phase=' . str_replace( ' ', '+', $m->phase_name );
			if ( null !== $m->group_phase_round && '' !== $m->group_phase_round ) {
				$args .= '&phase_round=' . intval( $m->group_phase_round );
			}
		} else {
			$args = 'competition_round=' . intval( $m->match_round );
		}
		return home_url( '/competicao/' . intval( $fed_comp_id ) . '/?edition=' . rawurlencode( $edition ) . '&' . $args );
	}
}

// Fetch all finished matches once (teams + matches engines reuse this).
$all_matches = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT m.id, m.home_team_id, m.visitor_team_id,
		        m.home_score, m.visitor_score, m.home_score_extra, m.visitor_score_extra,
		        m.penalty_home_score, m.penalty_visitor_score,
		        m.match_round, m.group_phase_round, m.competition_phase_id,
		        cp.`order` AS phase_order, cp.name AS phase_name
		   FROM {$match_table} m
		   LEFT JOIN {$phase_table} cp ON m.competition_phase_id = cp.id
		  WHERE m.competition_id = %d AND m.situation = 'finished'
		  ORDER BY m.match_round ASC",
		$competition_id
	),
	OBJECT
);



// Team-mode filter (round = exclude order>=sel AND group_phase_round>round).
$team_matches = array();
// Matches/players-mode filter (round = group_phase_round <= round).
$flat_matches = array();
foreach ( $all_matches as $m ) {
	if ( null !== $statistics_start && intval( $m->match_round ) < $statistics_start ) {
		continue;
	}
	$pass_team = true;
	$pass_flat = true;
	if ( $statistic_phase && null !== $selected_phase_order ) {
		if ( null === $m->phase_order || intval( $m->phase_order ) > $selected_phase_order ) {
			$pass_team = false;
			$pass_flat = false;
		} else {
			if ( null !== $statistic_round ) {
				// team: exclude(order>=sel, group_phase_round>round)
				if ( intval( $m->phase_order ) >= $selected_phase_order && null !== $m->group_phase_round && intval( $m->group_phase_round ) > $statistic_round ) {
					$pass_team = false;
				}
				// flat: group_phase_round <= round
				if ( null === $m->group_phase_round || intval( $m->group_phase_round ) > $statistic_round ) {
					$pass_flat = false;
				}
			}
		}
	} elseif ( null !== $statistic_round ) {
		if ( intval( $m->match_round ) > $statistic_round ) {
			$pass_team = false;
			$pass_flat = false;
		}
	}
	if ( $pass_team ) {
		$team_matches[] = $m;
	}
	if ( $pass_flat ) {
		$flat_matches[] = $m;
	}
}

$bool_true = "( '1', 't', 'true' )";

/* ====================== TEAM TYPE ====================== */
if ( 'st_type_teams' === $statistic_type ) {

	$acc       = array(); // tid => Sum,Total
	$match_ids = array();
	foreach ( $team_matches as $m ) {
		$match_ids[] = intval( $m->id );
		$f           = f365_match_facts( $m );
		$sides       = array(
			array( 'ts' => intval( $m->home_team_id ), 'win' => $f['hw'], 'sc' => $f['hs'], 'su' => $f['vs'] ),
			array( 'ts' => intval( $m->visitor_team_id ), 'win' => $f['vw'], 'sc' => $f['vs'], 'su' => $f['hs'] ),
		);
		foreach ( $sides as $s ) {
			$tid = $s['ts'];
			if ( ! isset( $acc[ $tid ] ) ) {
				$acc[ $tid ] = array( 'Sum' => 0, 'Total' => 0 );
			}
			$acc[ $tid ]['Total'] += 1;
			if ( in_array( $statistic_option, $TEAM_MATCH_BASED, true ) ) {
				switch ( $statistic_option ) {
					case 'st_op_victories':
						if ( $s['win'] ) { $acc[ $tid ]['Sum'] += 1; }
						break;
					case 'st_op_draws':
						if ( $f['draw'] ) { $acc[ $tid ]['Sum'] += 1; }
						break;
					case 'st_op_loses':
						if ( ! $f['draw'] && ! $s['win'] ) { $acc[ $tid ]['Sum'] += 1; }
						break;
					case 'st_op_matches_without_lose':
						if ( $s['win'] || $f['draw'] ) { $acc[ $tid ]['Sum'] += 1; }
						break;
					case 'st_op_matches_without_win':
						if ( ! $s['win'] || $f['draw'] ) { $acc[ $tid ]['Sum'] += 1; }
						break;
					case 'st_op_matches_without_suffer':
						if ( null !== $s['su'] && 0 === $s['su'] ) { $acc[ $tid ]['Sum'] += 1; }
						break;
					case 'st_op_matches_without_score':
						if ( null !== $s['sc'] && 0 === $s['sc'] ) { $acc[ $tid ]['Sum'] += 1; }
						break;
					case 'st_op_scored_goals':
						if ( null !== $s['sc'] ) { $acc[ $tid ]['Sum'] += $s['sc']; }
						break;
					case 'st_op_suffered_goals':
						if ( null !== $s['su'] ) { $acc[ $tid ]['Sum'] += $s['su']; }
						break;
				}
			}
		}
	}
	
	// matchplayer-based team options.
	if ( in_array( $statistic_option, $TEAM_MP_BASED, true ) && ! empty( $match_ids ) ) {
		$ph  = implode( ',', array_fill( 0, count( $match_ids ), '%d' ) );
		$agg = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT team_id,
				        COALESCE(SUM(goals_penalty),0) AS pen,
				        COALESCE(SUM(own_goals),0) AS own,
				        COALESCE(SUM(CASE WHEN CAST(is_in AS CHAR) IN {$bool_true} AND ( COALESCE(goals,0) > 0 OR COALESCE(goals_penalty,0) > 0 ) THEN COALESCE(goals,0)+COALESCE(goals_penalty,0) ELSE 0 END),0) AS bench,
				        SUM(CASE WHEN CAST(yellow_card AS CHAR) IN {$bool_true} THEN 1 ELSE 0 END) AS yc,
				        SUM(CASE WHEN CAST(double_yellow_card AS CHAR) IN {$bool_true} THEN 1 ELSE 0 END) AS dyc,
				        SUM(CASE WHEN CAST(red_card AS CHAR) IN {$bool_true} THEN 1 ELSE 0 END) AS rc,
				        SUM(CASE WHEN CAST(is_in AS CHAR) IN {$bool_true} THEN 1 ELSE 0 END) AS subs
				   FROM {$mp_table} WHERE match_id IN ($ph) GROUP BY team_id",
				$match_ids
			),
			OBJECT_K
		);

	
		foreach ( $acc as $tid => $v ) {
			$r   = isset( $agg[ $tid ] ) ? $agg[ $tid ] : null;
			$sum = 0;
			if ( $r ) {
				switch ( $statistic_option ) {
					case 'st_op_penalty_goals':       $sum = intval( $r->pen ); break;
					case 'st_op_own_goals':           $sum = intval( $r->own ); break;
					case 'st_op_bench_goals':         $sum = intval( $r->bench ); break;
					case 'st_op_yellow_cards':        $sum = intval( $r->yc ) + intval( $r->dyc ); break;
					case 'st_op_double_yellow_cards': $sum = intval( $r->dyc ); break;
					case 'st_op_red_cards':           $sum = intval( $r->rc ); break;
					case 'st_op_total_cards':         $sum = ( intval( $r->yc ) + intval( $r->dyc ) ) + intval( $r->dyc ) + intval( $r->rc ); break;
					case 'st_op_total_substitutions': $sum = intval( $r->subs ); break;
				}
			}
			$acc[ $tid ]['Sum'] = $sum;
		}
	}
	// squad-based team options (age / height / weight).
	if ( in_array( $statistic_option, $TEAM_SQUAD_BASED, true ) ) {
		$header = array( 'Sum' => 'Total', 'Total' => 'Jogadores', 'Avg' => 'Média' );
		$tsids  = array();
		foreach ( $all_matches as $m ) {
			$tsids[ intval( $m->home_team_id ) ]    = 1;
			$tsids[ intval( $m->visitor_team_id ) ] = 1;
		}
		$tsids = array_keys( $tsids );
		$acc   = array();

		if ( ! empty( $tsids ) ) {
			$ph = implode( ',', array_fill( 0, count( $tsids ), '%d' ) );
			if ( 'st_op_age' === $statistic_option ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT sq.team_id, COUNT(1) AS total,
						        COALESCE(SUM(CASE WHEN pe.birth_date IS NOT NULL THEN TIMESTAMPDIFF(YEAR, pe.birth_date, CURDATE()) ELSE 0 END),0) AS sm
						   FROM {$squad_table} sq
						   JOIN {$player_table} pl ON sq.player_id = pl.id
						   JOIN {$person_table} pe ON pl.person_id = pe.id
						  WHERE sq.team_id IN ($ph) GROUP BY sq.team_id",
						$tsids
					),
					OBJECT_K
				);
			} else {
				$col  = ( 'st_op_height' === $statistic_option ) ? 'pl.height' : 'pl.weight';
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT sq.team_id, COUNT(1) AS total, COALESCE(SUM({$col}),0) AS sm
						FROM {$squad_table} sq
						JOIN {$player_table} pl ON sq.player_id = pl.id
						WHERE sq.team_id IN ($ph) GROUP BY sq.team_id",
						$tsids
					),
					OBJECT_K
				);
			}

			foreach ( $rows as $tid => $r ) {
				$acc[ intval( $tid ) ] = array( 'Sum' => intval( $r->sm ), 'Total' => intval( $r->total ) );
			}
		}
	}
	

	// Keep Sum > 0, resolve teamstep meta, build rows.
	$keep_ids = array();
	foreach ( $acc as $tid => $vals ) {
		if ( intval( $vals['Sum'] ) > 0 ) {
			$keep_ids[] = $tid;
		}
	}
	if ( ! empty( $keep_ids ) ) {
		$placeholders = implode( ',', array_fill( 0, count( $keep_ids ), '%d' ) );
		$meta_rows    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ts.id, ts.team_id, ts.step, ts.gender, ts.modality, ts.edition,
				        t.shortname, t.country_id, t.team_type
				   FROM {$ts_table} ts JOIN {$team_table} t ON ts.team_id = t.id
				  WHERE ts.id IN ($placeholders)",
				$keep_ids
			),
			OBJECT_K
		);
		foreach ( $keep_ids as $tid ) {
			if ( ! isset( $meta_rows[ $tid ] ) ) {
				continue;
			}
			$meta  = $meta_rows[ $tid ];
			$sum   = intval( $acc[ $tid ]['Sum'] );
			$total = intval( $acc[ $tid ]['Total'] );
			$avg   = ( $total > 0 ) ? ( $sum / $total ) : 0.0;
			$results[ $tid ] = array(
				'team_id'   => intval( $meta->team_id ),
				'label'     => f365_teamstep_label( $meta->shortname, $meta->step, $meta->gender, $meta->modality ),
				'country'   => intval( $meta->country_id ),
				'team_type' => $meta->team_type,
				'edition'   => $meta->edition,
				'modality'  => $meta->modality,
				'gender'    => $meta->gender,
				'step'      => $meta->step,
				'Sum'       => $sum,
				'Total'     => $total,
				'Avg'       => number_format( $avg, 2, '.', '' ),
				'AvgF'      => $avg,
			);
		}
		$sort_by_avg_only = in_array( $statistic_option, $TEAM_SQUAD_BASED, true );
		uasort(
			$results,
			function ( $a, $b ) use ( $sort_by_avg_only ) {
				if ( $sort_by_avg_only ) {
					if ( $a['AvgF'] === $b['AvgF'] ) { return 0; }
					return ( $b['AvgF'] < $a['AvgF'] ) ? -1 : 1;
				}
				if ( $b['Sum'] !== $a['Sum'] ) {
					return $b['Sum'] - $a['Sum'];
				}
				if ( $a['AvgF'] === $b['AvgF'] ) { return 0; }
				return ( $b['AvgF'] < $a['AvgF'] ) ? -1 : 1;
			}
		);
	}
}

/* ====================== MATCHES TYPE ====================== */

if ( 'st_type_matches' === $statistic_type ) {

	$card_options = array( 'st_op_yellow_cards', 'st_op_double_yellow_cards', 'st_op_red_cards', 'st_op_total_cards' );
	$header       = in_array( $statistic_option, $card_options, true ) ? array( 'Sum' => 'Cartões' ) : array( 'Sum' => 'Golos' );
	if ( 'st_op_total_substitutions' === $statistic_option ) {
		$header = array( 'Sum' => 'Total' );
	}
	if ( 'st_op_fastest_goals' === $statistic_option ) {
		$header = array( 'Sum' => 'Minuto' );
	}

	if ( 'st_op_fastest_goals' !== $statistic_option ) {
		$match_ids   = array();
		$match_by_id = array();
		foreach ( $flat_matches as $m ) {
			$match_ids[]               = intval( $m->id );
			$match_by_id[ intval( $m->id ) ] = $m;
		}

		// matchplayer aggregates per match (no team filter).
		$mp_match = array();
		if ( in_array( $statistic_option, array( 'st_op_penalty_goals', 'st_op_own_goals', 'st_op_yellow_cards', 'st_op_double_yellow_cards', 'st_op_red_cards', 'st_op_total_cards', 'st_op_total_substitutions' ), true ) && ! empty( $match_ids ) ) {
			$ph  = implode( ',', array_fill( 0, count( $match_ids ), '%d' ) );
			$agg = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT match_id,
					        COALESCE(SUM(goals_penalty),0) AS pen,
					        COALESCE(SUM(own_goals),0) AS own,
					        SUM(CASE WHEN CAST(yellow_card AS CHAR) IN {$bool_true} THEN 1 ELSE 0 END) AS yc,
					        SUM(CASE WHEN CAST(double_yellow_card AS CHAR) IN {$bool_true} THEN 1 ELSE 0 END) AS dyc,
					        SUM(CASE WHEN CAST(red_card AS CHAR) IN {$bool_true} THEN 1 ELSE 0 END) AS rc,
					        SUM(CASE WHEN CAST(is_in AS CHAR) IN {$bool_true} THEN 1 ELSE 0 END) AS subs
					   FROM {$mp_table} WHERE match_id IN ($ph) GROUP BY match_id",
					$match_ids
				),
				OBJECT_K
			);
			
			$mp_match = $agg;
		}

		// team names for the match label.
		$ts_name = array();
		$tsids   = array();
		foreach ( $flat_matches as $m ) {
			$tsids[ intval( $m->home_team_id ) ]    = 1;
			$tsids[ intval( $m->visitor_team_id ) ] = 1;
		}
		$tsids = array_keys( $tsids );
		if ( ! empty( $tsids ) ) {
			$ph    = implode( ',', array_fill( 0, count( $tsids ), '%d' ) );
			$nrows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ts.id, t.shortname FROM {$ts_table} ts JOIN {$team_table} t ON ts.team_id = t.id WHERE ts.id IN ($ph)",
					$tsids
				),
				OBJECT_K
			);
			foreach ( $nrows as $id => $r ) {
				$ts_name[ intval( $id ) ] = $r->shortname;
			}
		}

		foreach ( $flat_matches as $m ) {
			$f   = f365_match_facts( $m );
			$mid = intval( $m->id );
			$sum = 0;
			switch ( $statistic_option ) {
				case 'st_op_scored_goals':
					$sum = ( null !== $f['hs'] ? $f['hs'] : 0 ) + ( null !== $f['vs'] ? $f['vs'] : 0 );
					break;
				case 'st_op_goal_difference':
					$sum = ( null !== $f['hs'] && null !== $f['vs'] ) ? abs( $f['hs'] - $f['vs'] ) : 0;
					break;
				case 'st_op_biggest_victories_home':
					$sum = ( $f['hw'] && null !== $f['hs'] && null !== $f['vs'] ) ? abs( $f['hs'] - $f['vs'] ) : 0;
					break;
				case 'st_op_biggest_victories_visitor':
					$sum = ( $f['vw'] && null !== $f['hs'] && null !== $f['vs'] ) ? abs( $f['hs'] - $f['vs'] ) : 0;
					break;
				case 'st_op_penalty_goals':       $sum = isset( $mp_match[ $mid ] ) ? intval( $mp_match[ $mid ]->pen ) : 0; break;
				case 'st_op_own_goals':           $sum = isset( $mp_match[ $mid ] ) ? intval( $mp_match[ $mid ]->own ) : 0; break;
				case 'st_op_yellow_cards':        $sum = isset( $mp_match[ $mid ] ) ? ( intval( $mp_match[ $mid ]->yc ) + intval( $mp_match[ $mid ]->dyc ) ) : 0; break;
				case 'st_op_double_yellow_cards': $sum = isset( $mp_match[ $mid ] ) ? intval( $mp_match[ $mid ]->dyc ) : 0; break;
				case 'st_op_red_cards':           $sum = isset( $mp_match[ $mid ] ) ? intval( $mp_match[ $mid ]->rc ) : 0; break;
				case 'st_op_total_cards':         $sum = isset( $mp_match[ $mid ] ) ? ( ( intval( $mp_match[ $mid ]->yc ) + intval( $mp_match[ $mid ]->dyc ) ) + intval( $mp_match[ $mid ]->dyc ) + intval( $mp_match[ $mid ]->rc ) ) : 0; break;
				case 'st_op_total_substitutions': $sum = isset( $mp_match[ $mid ] ) ? intval( $mp_match[ $mid ]->subs ) : 0; break;
			}
			if ( intval( $sum ) > 0 ) {
				$hn = isset( $ts_name[ intval( $m->home_team_id ) ] ) ? $ts_name[ intval( $m->home_team_id ) ] : '';
				$vn = isset( $ts_name[ intval( $m->visitor_team_id ) ] ) ? $ts_name[ intval( $m->visitor_team_id ) ] : '';
				$results[ $mid ] = array(
					'match_url'  => home_url( '/jogo/' . $mid . '/' ),
					'label'      => $hn . ' - ' . $vn,
					'round_text' => f365_round_text( $m ),
					'round_url'  => f365_round_link( $m, $fed_comp_id, $comp_edition ),
					'Sum'        => intval( $sum ),
				);
			}
		}
		uasort(
			$results,
			function ( $a, $b ) {
				return $b['Sum'] - $a['Sum'];
			}
		);
	}
}

/* ====================== PLAYERS TYPE ====================== */
if ( 'st_type_players' === $statistic_type ) {

    $header = array( 'Sum' => 'Total', 'Total' => 'Jogos', 'Avg' => 'Média' );

    // Filtered match ids (players use the flat/lte filter, like _get_matchplayers).
    $flat_ids = array();
    // FIX: Ensure $flat_matches is iterable
    if ( ! empty( $flat_matches ) && is_array( $flat_matches ) ) {
        foreach ( $flat_matches as $m ) {
            $flat_ids[] = intval( $m->id );
        }
    }

    // n_matches per teamstep (Total default for most options) from flat matches.
    $ts_match_count = array();
    if ( ! empty( $flat_matches ) && is_array( $flat_matches ) ) {
        foreach ( $flat_matches as $m ) {
            $ts_match_count[ intval( $m->home_team_id ) ]    = isset( $ts_match_count[ intval( $m->home_team_id ) ] ) ? $ts_match_count[ intval( $m->home_team_id ) ] + 1 : 1;
            $ts_match_count[ intval( $m->visitor_team_id ) ] = isset( $ts_match_count[ intval( $m->visitor_team_id ) ] ) ? $ts_match_count[ intval( $m->visitor_team_id ) ] + 1 : 1;
        }
    }

    // Participant teamsteps (from all competition matches).
    $tsids = array();
    if ( ! empty( $all_matches ) && is_array( $all_matches ) ) {
        foreach ( $all_matches as $m ) {
            $tsids[ intval( $m->home_team_id ) ]    = 1;
            $tsids[ intval( $m->visitor_team_id ) ] = 1;
        }
    }
    $tsids = array_keys( $tsids );

    if ( ! empty( $tsids ) && ! empty( $flat_ids ) ) {
        $ts_ph   = implode( ',', array_fill( 0, count( $tsids ), '%d' ) );
        // Squad players: clubs only order 0, nationals all.
        $squad_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT sq.player_id, sq.team_id, t.team_type
                   FROM {$squad_table} sq
                   JOIN {$ts_table} ts ON sq.team_id = ts.id
                   JOIN {$team_table} t ON ts.team_id = t.id
                  WHERE sq.team_id IN ($ts_ph)
                    AND ( t.team_type = 'national' OR ( t.team_type = 'club' AND sq.`order` = 0 ) )",
                $tsids
            ),
            OBJECT
        );

        // Map player -> teamstep (first squad row wins).
        $player_team = array();
        $player_ids  = array();
        if ( ! empty( $squad_rows ) ) {
            foreach ( $squad_rows as $sr ) {
                $pid = intval( $sr->player_id );
                if ( ! isset( $player_team[ $pid ] ) ) {
                    $player_team[ $pid ] = intval( $sr->team_id );
                    $player_ids[]        = $pid;
                }
            }
        }

        if ( ! empty( $player_ids ) ) {
            $p_ph  = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );
            $m_ph  = implode( ',', array_fill( 0, count( $flat_ids ), '%d' ) );
            
            $agg   = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT player_id,
                            SUM(CASE WHEN minutes > 0 THEN 1 ELSE 0 END) AS total_matches,
                            COALESCE(SUM(CASE WHEN minutes >= 1 THEN minutes ELSE 0 END),0) AS played_minutes,
                            SUM(CASE WHEN CAST(is_titular AS CHAR) IN {$bool_true} AND CAST(is_out AS CHAR) NOT IN {$bool_true} THEN 1 ELSE 0 END) AS complete_matches,
                            SUM(CASE WHEN CAST(is_titular AS CHAR) IN {$bool_true} THEN 1 ELSE 0 END) AS titular_matches,
                            SUM(CASE WHEN CAST(is_out AS CHAR) IN {$bool_true} THEN 1 ELSE 0 END) AS out_matches,
                            SUM(CASE WHEN CAST(is_in AS CHAR) IN {$bool_true} THEN 1 ELSE 0 END) AS in_matches,
                            COALESCE(SUM(CASE WHEN COALESCE(goals,0) >= 1 OR COALESCE(goals_penalty,0) >= 1 THEN COALESCE(goals,0)+COALESCE(goals_penalty,0) ELSE 0 END),0) AS scored_goals,
                            SUM(CASE WHEN ( COALESCE(goals,0)+COALESCE(goals_penalty,0) ) >= 3 THEN 1 ELSE 0 END) AS hat_tricks,
                            COALESCE(SUM(goals_penalty),0) AS penalty_goals,
                            COALESCE(SUM(own_goals),0) AS own_goals,
                            COALESCE(SUM(CASE WHEN CAST(is_in AS CHAR) IN {$bool_true} AND ( COALESCE(goals,0) > 0 OR COALESCE(goals_penalty,0) > 0 ) THEN COALESCE(goals,0)+COALESCE(goals_penalty,0) ELSE 0 END),0) AS bench_goals,
                            SUM(CASE WHEN CAST(yellow_card AS CHAR) IN {$bool_true} THEN 1 ELSE 0 END) AS yc,
                            SUM(CASE WHEN CAST(double_yellow_card AS CHAR) IN {$bool_true} THEN 1 ELSE 0 END) AS dyc,
                            SUM(CASE WHEN CAST(red_card AS CHAR) IN {$bool_true} THEN 1 ELSE 0 END) AS rc
                       FROM {$mp_table}
                      WHERE match_id IN ($m_ph) AND player_id IN ($p_ph)
                      GROUP BY player_id",
                    // FIX: Double safe-guard with array casting fallback
                    array_merge( (array) ($flat_ids ?: array()), (array) ($player_ids ?: array()) )
                ),
                OBJECT_K
            );

            // Player display info (person shortname + nationality).
            $disp = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT pl.id AS player_id, pe.shortname, pe.nationality_id
                       FROM {$player_table} pl JOIN {$person_table} pe ON pl.person_id = pe.id
                      WHERE pl.id IN ($p_ph)",
                    $player_ids
                ),
                OBJECT_K
            );

            // Teamstep label cache.
            $ts_meta = array();
            if ( ! empty( $tsids ) ) {
                $tm = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT ts.id, ts.team_id, ts.step, ts.gender, ts.modality, ts.edition, t.shortname, t.team_type
                           FROM {$ts_table} ts JOIN {$team_table} t ON ts.team_id = t.id WHERE ts.id IN ($ts_ph)",
                        $tsids
                    ),
                    OBJECT_K
                );
                $ts_meta = $tm;
            }

            foreach ( $player_ids as $pid ) {
                $a = isset( $agg[ $pid ] ) ? $agg[ $pid ] : null;
                if ( ! $a ) {
                    continue;
                }
                $sum = 0;
                switch ( $statistic_option ) {
                    case 'st_op_total_matches':       $sum = intval( $a->total_matches ); break;
                    case 'st_op_played_minutes':      $sum = intval( $a->played_minutes ); break;
                    case 'st_op_complete_matches':    $sum = intval( $a->complete_matches ); break;
                    case 'st_op_titular_matches':     $sum = intval( $a->titular_matches ); break;
                    case 'st_op_out':                 $sum = intval( $a->out_matches ); break;
                    case 'st_op_in':                  $sum = intval( $a->in_matches ); break;
                    case 'st_op_scored_goals':        $sum = intval( $a->scored_goals ); break;
                    case 'st_op_hat_tricks':          $sum = intval( $a->hat_tricks ); break;
                    case 'st_op_penalty_goals':       $sum = intval( $a->penalty_goals ); break;
                    case 'st_op_own_goals':           $sum = intval( $a->own_goals ); break;
                    case 'st_op_bench_goals':         $sum = intval( $a->bench_goals ); break;
                    case 'st_op_yellow_cards':        $sum = intval( $a->yc ) + intval( $a->dyc ); break;
                    case 'st_op_double_yellow_cards': $sum = intval( $a->dyc ); break;
                    case 'st_op_red_cards':           $sum = intval( $a->rc ); break;
                    case 'st_op_total_cards':         $sum = ( intval( $a->yc ) + intval( $a->dyc ) ) + intval( $a->dyc ) + intval( $a->rc ); break;
                }
                if ( intval( $sum ) <= 0 ) {
                    continue;
                }
                $tid   = $player_team[ $pid ];
                // Total: default squad.get_total_matches (player matches); total_matches option uses teamstep match count.
                $total = intval( $a->total_matches );
                if ( 'st_op_total_matches' === $statistic_option ) {
                    $total = isset( $ts_match_count[ $tid ] ) ? intval( $ts_match_count[ $tid ] ) : 0;
                }
                $avg   = ( $total > 0 ) ? ( $sum / $total ) : 0.0;

                $pname = isset( $disp[ $pid ] ) ? $disp[ $pid ]->shortname : '';
                $pnat  = isset( $disp[ $pid ] ) ? intval( $disp[ $pid ]->nationality_id ) : 0;
                $tlabel = '';
                $turl   = '';
                if ( isset( $ts_meta[ $tid ] ) ) {
                    $tm2    = $ts_meta[ $tid ];
                    $tlabel = f365_teamstep_label( $tm2->shortname, $tm2->step, $tm2->gender, $tm2->modality );
                    $turl   = f365_teamstep_url( $tm2->team_id, $tm2->edition, $tm2->modality, $tm2->gender, $tm2->step );
                    if ( 'national' === $tm2->team_type ) {
                        $turl .= '&competition=' . intval( $competition_id );
                    }
                }
                $results[ $pid ] = array(
                    'player_url' => home_url( '/jogador/' . intval( $pid ) . '/' ),
                    'name'       => $pname,
                    'nationality'=> $pnat,
                    'team_url'   => $turl,
                    'team_label' => $tlabel,
                    'Sum'        => intval( $sum ),
                    'Total'      => intval( $total ),
                    'Avg'        => number_format( $avg, 2, '.', '' ),
                    'AvgF'       => $avg,
                );
            }

            $count_like = array( 'st_op_total_matches', 'st_op_complete_matches', 'st_op_played_minutes', 'st_op_titular_matches', 'st_op_out', 'st_op_in', 'st_op_scored_goals', 'st_op_hat_tricks', 'st_op_penalty_goals', 'st_op_own_goals', 'st_op_bench_goals', 'st_op_yellow_cards', 'st_op_double_yellow_cards', 'st_op_red_cards', 'st_op_total_cards' );
            if ( in_array( $statistic_option, $count_like, true ) && ! empty( $results ) ) {
                uasort(
                    $results,
                    function ( $a, $b ) {
                        return $b['Sum'] - $a['Sum'];
                    }
                );
            } elseif ( ! empty( $results ) ) {
                uasort(
                    $results,
                    function ( $a, $b ) {
                        if ( $a['AvgF'] === $b['AvgF'] ) { return 0; }
                        return ( $b['AvgF'] < $a['AvgF'] ) ? -1 : 1;
                    }
                );
            }
        }
    }
}

/* -------------------------------------------------------------------------
 * Pagination (20 per page, 'pagina'); mirrors paginator_with_get_params.
 * ---------------------------------------------------------------------- */
$per_page    = 20;
$total_items = count( $results );
$total_pages = max( 1, (int) ceil( $total_items / $per_page ) );
$cur_page    = isset( $_GET['pagina'] ) ? max( 1, intval( $_GET['pagina'] ) ) : 1;
$cur_page    = min( $cur_page, $total_pages );
$start_index = ( $cur_page - 1 ) * $per_page; // 0-based offset.
$page_rows   = array_slice( $results, $start_index, $per_page, true );
$has_results = $total_items > 0;

// Page window (visible_pages = 3) with ellipsis.
$visible       = 3;
$window_start  = max( 1, $cur_page - intval( floor( $visible / 2 ) ) );
$window_end    = min( $total_pages, $window_start + $visible - 1 );
$window_start  = max( 1, $window_end - $visible + 1 );

get_template_part(
	'template-parts/competition/f365-competition-header',
	null,
	array(
		'post_id'         => $current_post_id,
		'sections'        => $sections,
		'competition_id'  => $final_competition_id,
		'current_section' => $current_section,
	)
);
?>
<main id="primary" class="site-main single-article-page" <?php echo $current_post_id; ?>>
	<div class="ink-grid futebol365-container">
		<section class="left-column-block">
			<div class="breadcrumbs-f365">
				<div class="ink-navigation breadcrumbs">
					<?php if ( function_exists( 'yoast_breadcrumb' ) ) { yoast_breadcrumb(); } ?>
				</div>
			</div>

			<?php if ( $has_results ) : ?>
			<form class="ink-form ink-form-option" method="get" name="statistics_form" id="statistics-form" action="<?php echo esc_url( $stats_base_url ); ?>">
				<div class="column-group">
					<div class="control-group all-33 tiny-100 small-100">
						<div class="control">
							<select name="statistic_type" id="id_statistic_type">
								<?php foreach ( $ST_TYPE_CHOICES as $val => $lab ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $statistic_type, $val ); ?>><?php echo esc_html( $lab ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
					<div class="control-group all-33 tiny-100 small-100">
						<div class="control">
							<select name="statistic_option" id="id_statistic_option">
								<?php foreach ( $valid_options as $op ) : ?>
									<option value="<?php echo esc_attr( $op ); ?>" <?php selected( $statistic_option, $op ); ?>><?php echo esc_html( $option_labels[ $op ] ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>

					<?php if ( $has_stat_phases ) : ?>
					<div class="control-group all-33 tiny-100 small-100">
						<div class="control">
							<select name="statistic_phase" id="id_statistic_phase">
								<option value="" <?php selected( null === $statistic_phase ); ?>>Fase</option>
								<?php foreach ( $phase_choices as $pname ) : ?>
									<option value="<?php echo esc_attr( $pname ); ?>" <?php selected( $statistic_phase, $pname ); ?>><?php echo esc_html( $pname ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
					<?php endif; ?>

					<?php if ( $show_round ) : ?>
					<div class="control-group all-33 tiny-100 small-100">
						<div class="control">
							<select name="statistic_round" id="id_statistic_round">
								<option value="" <?php selected( null === $statistic_round ); ?>>Ronda</option>
								<?php foreach ( $round_choices as $rnum ) : ?>
									<option value="<?php echo esc_attr( $rnum ); ?>" <?php selected( $statistic_round, $rnum ); ?>><?php echo esc_html( $rnum ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
					<?php endif; ?>
				</div>
			</form>
			<?php endif; ?>

			<?php // ---- Equipas ---- ?>
			<?php if ( 'st_type_teams' === $statistic_type ) : ?>
				<h5 class="condensed-400 headerTitle blue">Equipas - <?php echo esc_html( $title ); ?></h5>
				<?php if ( $has_results ) : ?>
					<table class="ink-table ink-table-f365 alternating">
						<thead>
							<tr>
								<th class="align-center all-10"></th>
								<th class="align-left">Equipa</th>
								<th class="align-center all-15"><?php echo esc_html( $header['Sum'] ); ?></th>
								<th class="align-center all-15 hide-tiny hide-small"><?php echo esc_html( $header['Total'] ); ?></th>
								<th class="align-center all-15"><?php echo esc_html( $header['Avg'] ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$i = 0;
							foreach ( $page_rows as $row ) :
								$rank     = $start_index + $i + 1;
								$team_url = f365_teamstep_url( $row['team_id'], $row['edition'], $row['modality'], $row['gender'], $row['step'] );
								if ( 'national' === $row['team_type'] ) {
									$team_url .= '&competition=' . intval( $competition_id );
								}
								$i++;
							?>
							<tr>
								<td class="align-center"><?php echo esc_html( $rank ); ?></td>
								<td class="align-left"><i class="hide-tiny flag-icon quarter-right-space flag-icon-<?php echo intval( $row['country'] ); ?>"></i><a href="<?php echo esc_url( $team_url ); ?>"><?php echo esc_html( $row['label'] ); ?></a></td>
								<td class="align-center"><?php echo esc_html( $row['Sum'] ); ?></td>
								<td class="align-center hide-tiny hide-small"><?php echo esc_html( $row['Total'] ); ?></td>
								<td class="align-center"><?php echo esc_html( $row['Avg'] ); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<h6 class="no-info">Sem informação disponível</h6>
				<?php endif; ?>
			<?php endif; ?>

			<?php // ---- Jogos ---- ?>
			<?php if ( 'st_type_matches' === $statistic_type ) : ?>
				<h5 class="condensed-400 headerTitle blue">Jogos - <?php echo esc_html( $title ); ?></h5>
				<?php if ( $has_results ) : ?>
					<table class="ink-table ink-table-f365 alternating">
						<thead>
							<tr>
								<th class="align-center all-10"></th>
								<th class="align-left">Jogo</th>
								<th class="align-left all-40 hide-tiny hide-small">Ronda</th>
								<th class="align-center all-15"><?php echo esc_html( $header['Sum'] ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$i = 0;
							foreach ( $page_rows as $row ) :
								$rank = $start_index + $i + 1;
								$i++;
							?>
							<tr>
								<td class="align-center"><?php echo esc_html( $rank ); ?></td>
								<td class="align-left"><a href="<?php echo esc_url( $row['match_url'] ); ?>"><?php echo esc_html( $row['label'] ); ?></a></td>
								<td class="align-left hide-tiny hide-small"><a href="<?php echo esc_url( $row['round_url'] ); ?>"><?php echo esc_html( $row['round_text'] ); ?></a></td>
								<td class="align-center"><?php echo esc_html( $row['Sum'] ); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<h6 class="no-info">Sem informação disponível</h6>
					<?php // st_op_fastest_goals requires competition_matchplayerevent (per-event minute), not present in the exports. ?>
				<?php endif; ?>
			<?php endif; ?>

			<?php // ---- Jogadores ---- ?>
			<?php if ( 'st_type_players' === $statistic_type ) : ?>
				<h5 class="condensed-400 headerTitle blue">Jogadores - <?php echo esc_html( $title ); ?></h5>
				<?php if ( $has_results ) : ?>
					<table class="ink-table ink-table-f365 alternating">
						<thead>
							<tr>
								<th class="align-center all-10"></th>
								<th class="align-left">Jogador</th>
								<th class="align-left all-30 hide-tiny hide-small">Equipa</th>
								<th class="align-center all-10 small-15"><?php echo esc_html( $header['Sum'] ); ?></th>
								<th class="align-center all-10 hide-tiny hide-small"><?php echo esc_html( $header['Total'] ); ?></th>
								<th class="align-center all-10 small-15"><?php echo esc_html( $header['Avg'] ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$i = 0;
							foreach ( $page_rows as $row ) :
								$rank = $start_index + $i + 1;
								$i++;
							?>
							<tr>
								<td class="align-center"><?php echo esc_html( $rank ); ?></td>
								<td class="align-left"><i class="hide-tiny flag-icon quarter-right-space flag-icon-<?php echo intval( $row['nationality'] ); ?>"></i><a href="<?php echo esc_url( $row['player_url'] ); ?>"><?php echo esc_html( $row['name'] ); ?></a></td>
								<td class="align-left hide-tiny hide-small"><a href="<?php echo esc_url( $row['team_url'] ); ?>"><?php echo esc_html( $row['team_label'] ); ?></a></td>
								<td class="align-center"><?php echo esc_html( $row['Sum'] ); ?></td>
								<td class="align-center hide-tiny hide-small"><?php echo esc_html( $row['Total'] ); ?></td>
								<td class="align-center"><?php echo esc_html( $row['Avg'] ); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<h6 class="no-info">Sem informação disponível</h6>
				<?php endif; ?>
			<?php endif; ?>


			<?php 
			if ( isset( $has_results, $total_pages ) && function_exists( 'futebol365_render_pagination' ) ):
				futebol365_render_pagination( $has_results, $total_pages );
			endif;
			?>

		<?php get_template_part('template-parts/template-front-page-posts'); ?>
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

<script type="text/javascript">
( function () {
	var baseUrl = '<?php echo esc_url( $stats_base_url ); ?>';
	function params() {
		var p = new URLSearchParams( window.location.search );
		return p;
	}
	function navigate( p ) {
		var qs = p.toString();
		window.location.href = qs ? ( baseUrl + '?' + qs ) : baseUrl;
	}
    function editionVal( p ) {
        var el = document.getElementById( 'edition' );
        var val = el && el.value ? el.value : ( p.get( 'edition' ) || '<?php echo esc_js( $comp_edition ); ?>' );
        
        // If the value contains a URL, extract just the edition query parameter
        if ( val.includes( 'edition=' ) ) {
            var urlParams = new URLSearchParams( val.split('?')[1] );
            if ( urlParams.has( 'edition' ) ) {
                return urlParams.get( 'edition' );
            }
        } else if ( val.startsWith( 'http' ) ) {
            // Fallback: If it's a clean URL string without the param, extract the last path segment
            return val.replace( /\/$/, '' ).split( '/' ).pop();
        }
        return val;
    }
	var typeSel   = document.getElementById( 'id_statistic_type' );
	var optionSel = document.getElementById( 'id_statistic_option' );
	var phaseSel  = document.getElementById( 'id_statistic_phase' );
	var roundSel  = document.getElementById( 'id_statistic_round' );

	if ( typeSel ) {
		typeSel.addEventListener( 'change', function () {
			var p = params();
			p.delete( 'pagina' );
			p.delete( 'statistic_option' );
			p.delete( 'statistic_phase' );
			p.delete( 'statistic_round' );
			p.set( 'edition', editionVal( p ) );
			p.set( 'statistic_type', typeSel.value );
			navigate( p );
		} );
	}

	function rebuild( changedId ) {
		var p = params();
		p.delete( 'pagina' );
		p.set( 'edition', editionVal( p ) );
		p.set( 'statistic_type', typeSel ? typeSel.value : '' );
		p.set( 'statistic_option', optionSel ? optionSel.value : '' );
		p.set( 'statistic_phase', phaseSel ? phaseSel.value : '' );
		p.set( 'statistic_round', roundSel ? roundSel.value : '' );
		if ( 'id_statistic_phase' === changedId ) {
			p.set( 'statistic_round', '' );
		}
		if ( 'id_statistic_option' === changedId ) {
			p.set( 'statistic_phase', '' );
			p.set( 'statistic_round', '' );
		}
		navigate( p );
	}

	if ( optionSel ) { optionSel.addEventListener( 'change', function () { rebuild( 'id_statistic_option' ); } ); }
	if ( phaseSel )  { phaseSel.addEventListener( 'change', function () { rebuild( 'id_statistic_phase' ); } ); }
	if ( roundSel )  { roundSel.addEventListener( 'change', function () { rebuild( 'id_statistic_round' ); } ); }
} )();
</script>

<?php
get_footer();