<?php
get_header();
global $wpdb;

$current_post_id = get_queried_object_id();
$current_section = 'resumo';
$sections        = futebol365_get_competition_sections();

$final_competition_id = get_legacy_competition_id( $current_post_id );

$video_table    = $wpdb->prefix . 'multimedia_video';

$videos_visibility = (bool) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(1) FROM {$video_table} WHERE competition_id = %d",
        $final_competition_id
    )
);

$latest_videos = array();
if ( $videos_visibility ) {
    $latest_videos = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, title, url, image, datetime FROM {$video_table} WHERE competition_id = %d ORDER BY datetime DESC LIMIT 4",
            $final_competition_id
        ),
        OBJECT
    );
}

$competition_id = intval( $final_competition_id );
$comp_table     = $wpdb->prefix . 'competition_competition';
$phase_table    = $wpdb->prefix . 'competition_competitionphase';
$match_table    = $wpdb->prefix . 'competition_match';
$ts_table       = $wpdb->prefix . 'team_teamstep';
$team_table     = $wpdb->prefix . 'team_team';
$now_mysql      = current_time( 'mysql' );

$comp_row     = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT federation_competition_id, edition, name, season, notes FROM {$comp_table} WHERE id = %d",
        $competition_id
    ),
    OBJECT
);
$fed_comp_id  = $comp_row ? intval( $comp_row->federation_competition_id ) : 0;
$comp_edition = $comp_row ? $comp_row->edition : '';
$comp_name    = $comp_row ? (string) $comp_row->name : '';
$comp_season  = $comp_row ? (string) $comp_row->season : '';
$comp_notes   = $comp_row ? (string) $comp_row->notes : '';

$fc_table  = $wpdb->prefix . 'federation_federationcompetition';
$fed_table = $wpdb->prefix . 'federation_federation';
$comp_federation_id = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT federation_id FROM {$fc_table} WHERE id = %d", $fed_comp_id )
);

$competition_index_url   = esc_url( home_url( '/competicao/' . intval( $fed_comp_id ) . '/?edition=' . rawurlencode( $comp_edition ) ) );

// Current selections from the query string.
$sel_phase = ( isset( $_GET['competition_phase'] ) && ! in_array( $_GET['competition_phase'], array( '', 'all' ), true ) )
    ? sanitize_text_field( wp_unslash( $_GET['competition_phase'] ) ) : null;
$sel_group = ( isset( $_GET['group'] ) && ! in_array( $_GET['group'], array( '', 'all' ), true ) )
    ? sanitize_text_field( wp_unslash( $_GET['group'] ) ) : null;
$sel_phase_round = ( isset( $_GET['phase_round'] ) && ! in_array( $_GET['phase_round'], array( '', 'all' ), true ) && is_numeric( $_GET['phase_round'] ) )
    ? intval( $_GET['phase_round'] ) : null;
$sel_comp_round = ( isset( $_GET['competition_round'] ) && ! in_array( $_GET['competition_round'], array( '', 'all', 'all_rounds' ), true ) && is_numeric( $_GET['competition_round'] ) )
    ? intval( $_GET['competition_round'] ) : null;

// Total matches drives whether ANY filter shows (CompetitionFilterForm: count == 0 -> none).
$has_matches = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT COUNT(1) FROM {$match_table} WHERE competition_id = %d", $competition_id )
) > 0;

// Phases for this competition (get_phases_choices = all phase names).
$phases = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, name, phase_type FROM {$phase_table} WHERE competition_id = %d ORDER BY `order` ASC",
        $competition_id
    ),
    OBJECT
);
$has_competition_phases = ! empty( $phases );

// Dropdown visibility + choices.
$show_round         = false;
$show_phase         = false;
$show_group         = false;
$show_phase_round   = false;
$round_choices      = array();
$group_choices      = array();
$phase_round_choices = array();
$current_phase      = null;
$phase_round        = null;   // resolved value used for filtering
$competition_round  = null;   // resolved value used for filtering

if ( $has_matches ) {

    if ( $has_competition_phases ) {

        $show_phase = true; // get_phases_choices non-empty; competition_round dropped.

        // Resolve active phase: explicit selection, else last phase (closest to now).
        if ( null !== $sel_phase ) {
            foreach ( $phases as $p ) {
                if ( $p->name === $sel_phase ) {
                    $current_phase = $p;
                    break;
                }
            }
        }
        if ( null === $current_phase ) {
            $last_phase_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT competition_phase_id FROM {$match_table}
                      WHERE competition_id = %d AND competition_phase_id IS NOT NULL
                      ORDER BY ABS(TIMESTAMPDIFF(SECOND, date_time, %s)) ASC LIMIT 1",
                    $competition_id,
                    $now_mysql
                )
            );
            foreach ( $phases as $p ) {
                if ( (int) $p->id === (int) $last_phase_id ) {
                    $current_phase = $p;
                    break;
                }
            }
            if ( null === $current_phase ) {
                $current_phase = end( $phases );
            }
        }

        if ( $current_phase ) {

            $phase_id = (int) $current_phase->id;

            // Group choices = distinct non-empty groups in this phase (get_groups_choices).
            $group_choices = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT g FROM (
                        SELECT group_home_team g FROM {$match_table}
                          WHERE competition_id = %d AND competition_phase_id = %d AND group_home_team IS NOT NULL AND group_home_team <> ''
                        UNION
                        SELECT group_visitor_team g FROM {$match_table}
                          WHERE competition_id = %d AND competition_phase_id = %d AND group_visitor_team IS NOT NULL AND group_visitor_team <> ''
                     ) u ORDER BY g ASC",
                    $competition_id, $phase_id, $competition_id, $phase_id
                )
            );
            $show_group = ! empty( $group_choices );

            // phase_round choices (CompetitionFilterForm rules).
            if ( 'single_round' !== $current_phase->phase_type ) {
                if ( null !== $sel_group ) {
                    $phase_round_choices = $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT DISTINCT group_phase_round FROM {$match_table}
                              WHERE competition_id = %d AND competition_phase_id = %d
                                AND ( group_home_team = %s OR group_visitor_team = %s )
                                AND group_phase_round IS NOT NULL
                              ORDER BY group_phase_round ASC",
                            $competition_id, $phase_id, $sel_group, $sel_group
                        )
                    );
                    $show_phase_round = ! empty( $phase_round_choices );
                } else {
                    $no_groups   = ( count( $group_choices ) <= 0 );
                    $round_types = array( 'with_rounds', 'with_rounds_and_groups', 'single_round', 'mixed_groups' );
                    if ( ( $no_groups && in_array( $current_phase->phase_type, $round_types, true ) ) || 'mixed_groups' === $current_phase->phase_type ) {
                        $phase_round_choices = $wpdb->get_col(
                            $wpdb->prepare(
                                "SELECT DISTINCT group_phase_round FROM {$match_table}
                                  WHERE competition_id = %d AND competition_phase_id = %d AND group_phase_round IS NOT NULL
                                  ORDER BY group_phase_round ASC",
                                $competition_id, $phase_id
                            )
                        );
                        $show_phase_round = ! empty( $phase_round_choices );
                    }
                }
            }

            // Resolve phase_round value for filtering (selected, else last phase round).
            if ( null !== $sel_phase_round ) {
                $phase_round = $sel_phase_round;
            } else {
                $lpr_sql    = "SELECT group_phase_round FROM {$match_table} WHERE competition_id = %d AND competition_phase_id = %d AND group_phase_round IS NOT NULL";
                $lpr_params = array( $competition_id, $phase_id );
                if ( null !== $sel_group ) {
                    $lpr_sql     .= " AND ( group_home_team = %s OR group_visitor_team = %s )";
                    $lpr_params[] = $sel_group;
                    $lpr_params[] = $sel_group;
                }
                $lpr_sql     .= " ORDER BY ABS(TIMESTAMPDIFF(SECOND, date_time, %s)) ASC LIMIT 1";
                $lpr_params[] = $now_mysql;
                $phase_round  = $wpdb->get_var( $wpdb->prepare( $lpr_sql, $lpr_params ) );
                $phase_round  = ( null !== $phase_round ) ? intval( $phase_round ) : null;
            }
        }

    } else {

        // No phases -> competition_round dropdown (get_rounds_choices, championship/competition case).
        $show_round    = true;
        $round_choices = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT match_round FROM {$match_table} WHERE competition_id = %d AND match_round IS NOT NULL ORDER BY match_round ASC",
                $competition_id
            )
        );

        // Resolve competition_round (selected, else last round closest to now).
        if ( null !== $sel_comp_round ) {
            $competition_round = $sel_comp_round;
        } else {
            $lr = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT match_round FROM {$match_table}
                      WHERE competition_id = %d AND match_round IS NOT NULL
                      ORDER BY ABS(TIMESTAMPDIFF(SECOND, date_time, %s)) ASC LIMIT 1",
                    $competition_id,
                    $now_mysql
                )
            );
            $competition_round = ( null !== $lr ) ? intval( $lr ) : 1;
        }
    }
}

// Build the match query reflecting the active selections.
$match_where  = "m.competition_id = %d";
$match_params = array( $competition_id );
if ( $has_competition_phases && $current_phase ) {
    $match_where   .= " AND m.competition_phase_id = %d";
    $match_params[] = (int) $current_phase->id;
    if ( null !== $sel_group ) {
        $match_where   .= " AND ( m.group_home_team = %s OR m.group_visitor_team = %s )";
        $match_params[] = $sel_group;
        $match_params[] = $sel_group;
    }
    if ( null !== $phase_round ) {
        $match_where   .= " AND m.group_phase_round = %d";
        $match_params[] = (int) $phase_round;
    }
} elseif ( ! $has_competition_phases && null !== $competition_round ) {
    $match_where   .= " AND m.match_round = %d";
    $match_params[] = (int) $competition_round;
}

$resumo_matches = array();
if ( $has_matches ) {
    $resumo_matches = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT m.id, m.date_time, m.situation, m.leg,
                    m.home_score, m.visitor_score, m.home_score_extra, m.visitor_score_extra,
                    m.penalty_home_score, m.penalty_visitor_score,
                    m.home_team_id AS home_ts, m.visitor_team_id AS visitor_ts,
                    hts.team_id AS home_team_id, hts.step AS home_step, hts.gender AS home_gender, hts.modality AS home_modality, ht.shortname AS home_name, ht.team_type AS home_team_type,
                    vts.team_id AS visitor_team_id, vts.step AS visitor_step, vts.gender AS visitor_gender, vts.modality AS visitor_modality, vt.shortname AS visitor_name, vt.team_type AS visitor_team_type
               FROM {$match_table} m
               JOIN {$ts_table} hts ON m.home_team_id = hts.id
               JOIN {$team_table} ht ON hts.team_id = ht.id
               JOIN {$ts_table} vts ON m.visitor_team_id = vts.id
               JOIN {$team_table} vt ON vts.team_id = vt.id
              WHERE {$match_where}
              ORDER BY m.date_time ASC, m.id ASC",
            $match_params
        ),
        OBJECT
    );
}

/*
 * competition_tags -> show_match_link_actions: the red "V" (Vídeo) button is only
 */
$video_match_ids = array();
if ( ! empty( $resumo_matches ) ) {
    $match_ids    = array_map( 'intval', wp_list_pluck( $resumo_matches, 'id' ) );
    $placeholders = implode( ',', array_fill( 0, count( $match_ids ), '%d' ) );
    $video_rows   = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT match_id FROM {$wpdb->prefix}multimedia_video WHERE match_id IN ($placeholders)",
            $match_ids
        )
    );
    $video_match_ids = array_map( 'intval', $video_rows );
}


if ( $has_competition_phases && $current_phase ) {
    $phase_title = $current_phase->name;
} elseif ( null !== $competition_round ) {
    $phase_title = 'Jornada ' . $competition_round;
} else {
    $phase_title = 'Resumo';
}

// Team "jogos" filter URL (matches the established URL structure).
if ( ! function_exists( 'f365_team_jogos_url' ) ) {
    function f365_team_jogos_url( $team_id, $modality, $gender, $step, $edition ) {
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

/*
 * Classification / standings table — competition_detail.html renders this via
 */
if ( ! function_exists( 'f365_strip_accents' ) ) {
	/** Lowercase + strip common PT/ES diacritics for accent-insensitive name sorting. */
	function f365_strip_accents( $str ) {
		$map = array(
			'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
			'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
			'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
			'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
			'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
			'ç' => 'c', 'Ç' => 'C', 'ñ' => 'n', 'Ñ' => 'N',
		);
		return strtolower( strtr( (string) $str, $map ) );
	}
}

if ( ! function_exists( 'f365_legacy_python2_order_map' ) ) {
	function f365_legacy_python2_order_map( $team_ids ) {
		$count = count( $team_ids );
		$size  = 8;

		while ( $count * 3 >= $size * 2 ) {
			$size *= 2;
		}

		$mask  = $size - 1;
		$table = array_fill( 0, $size, null );

		foreach ( $team_ids as $team_id ) {
			$team_id = (int) $team_id;
			$slot    = $team_id & $mask;
			$perturb = $team_id;

			while ( null !== $table[ $slot ] ) {
				$slot    = ( ( $slot << 2 ) + $slot + $perturb + 1 ) & $mask;
				$perturb = intdiv( $perturb, 32 );
			}

			$table[ $slot ] = $team_id;
		}

		$order = array();
		foreach ( $table as $team_id ) {
			if ( null !== $team_id ) {
				$order[ (int) $team_id ] = count( $order );
			}
		}

		return $order;
	}
}
if ( ! function_exists( 'f365_compute_classification' ) ) {
	function f365_compute_classification( $wpdb, $match_table, $ts_table, $team_table, $competition_id, $phase_id, $group, $victory_points, $draw_points ) {
		$where  = 'm.competition_id = %d';
		$params = array( $competition_id );
		if ( null !== $phase_id ) {
			$where   .= ' AND m.competition_phase_id = %d';
			$params[] = $phase_id;
		}
		if ( null !== $group ) {
			$where   .= ' AND ( m.group_home_team = %s OR m.group_visitor_team = %s )';
			$params[] = $group;
			$params[] = $group;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.home_score, m.visitor_score,
				        hts.id AS h_ts, hts.team_id AS h_team, hts.step AS h_step, hts.gender AS h_gender, hts.modality AS h_modality,
				        ht.shortname AS h_name, ht.team_type AS h_type,
				        vts.id AS v_ts, vts.team_id AS v_team, vts.step AS v_step, vts.gender AS v_gender, vts.modality AS v_modality,
				        vt.shortname AS v_name, vt.team_type AS v_type
				   FROM {$match_table} m
				   JOIN {$ts_table} hts ON m.home_team_id = hts.id
				   JOIN {$team_table} ht ON hts.team_id = ht.id
				   JOIN {$ts_table} vts ON m.visitor_team_id = vts.id
				   JOIN {$team_table} vt ON vts.team_id = vt.id
				  WHERE {$where}
				  ORDER BY m.date_time ASC, m.id ASC",
				$params
			),
			OBJECT
		);

		$res = array(); // ts_id => stats + meta
		$ensure = function ( &$res, $ts, $meta ) {
			if ( ! isset( $res[ $ts ] ) ) {
				$res[ $ts ] = array_merge(
					$meta,
					array( 'ts_id' => intval( $ts ), 'J' => 0, 'V' => 0, 'E' => 0, 'D' => 0, 'GM' => 0, 'GS' => 0, 'DG' => 0, 'P' => 0, 'DP' => 0 )
				);
			}
		};
		foreach ( $rows as $r ) {
			$hts = intval( $r->h_ts );
			$vts = intval( $r->v_ts );
			$ensure( $res, $hts, array( 'team_id' => intval( $r->h_team ), 'name' => $r->h_name, 'team_type' => $r->h_type, 'step' => $r->h_step, 'gender' => $r->h_gender, 'modality' => $r->h_modality ) );
			$ensure( $res, $vts, array( 'team_id' => intval( $r->v_team ), 'name' => $r->v_name, 'team_type' => $r->v_type, 'step' => $r->v_step, 'gender' => $r->v_gender, 'modality' => $r->v_modality ) );

			if ( null === $r->home_score || null === $r->visitor_score ) {
				continue;
			}

			$hs = intval( $r->home_score );
			$vs = intval( $r->visitor_score );

			// Home side.
			$res[ $hts ]['J'] += 1;
			if ( $hs > $vs ) {
				$res[ $hts ]['V'] += 1;
				$res[ $hts ]['P'] += $victory_points;
			} elseif ( $hs < $vs ) {
				$res[ $hts ]['D'] += 1;
			} else {
				$res[ $hts ]['E'] += 1;
				$res[ $hts ]['P'] += $draw_points;
			}
			$res[ $hts ]['GM'] += $hs;
			$res[ $hts ]['GS'] += $vs;

			// Visitor side.
			$res[ $vts ]['J'] += 1;
			if ( $hs > $vs ) {
				$res[ $vts ]['D'] += 1;
			} elseif ( $hs < $vs ) {
				$res[ $vts ]['V'] += 1;
				$res[ $vts ]['P'] += $victory_points;
			} else {
				$res[ $vts ]['E'] += 1;
				$res[ $vts ]['P'] += $draw_points;
			}
			$res[ $vts ]['GS'] += $hs;
			$res[ $vts ]['GM'] += $vs;
		}

		$legacy_order = f365_legacy_python2_order_map( array_keys( $res ) );
		foreach ( $res as $ts => $v ) {
			$res[ $ts ]['DG'] = $v['GM'] - $v['GS'];
			$res[ $ts ]['legacy_sort_order'] = isset( $legacy_order[ (int) $ts ] ) ? (int) $legacy_order[ (int) $ts ] : 0;
		}
		uasort(
			$res,
			function ( $a, $b ) {
				if ( $a['P'] !== $b['P'] ) { return $b['P'] - $a['P']; }   // 1. points
				if ( $a['DG'] !== $b['DG'] ) { return $b['DG'] - $a['DG']; } // 2. goal difference
				if ( $a['GM'] !== $b['GM'] ) { return $b['GM'] - $a['GM']; } // 3. goals scored
				if ( $a['legacy_sort_order'] !== $b['legacy_sort_order'] ) { return $a['legacy_sort_order'] - $b['legacy_sort_order']; }
				return $a['ts_id'] - $b['ts_id'];
			}
		);
		// DP = points of the row above - points of this row.
		$ordered  = array();
		$last_p   = null;
		foreach ( $res as $ts => $v ) {
			if ( null !== $last_p ) {
				$v['DP'] = $last_p - $v['P'];
			}
			$last_p    = $v['P'];
			$ordered[] = $v;
		}
		return $ordered;
	}
}

// Resolve which classification tables to show (resumo / matches_till_round=False).
$classification_tables = array();
$show_classification    = false;
$points_row             = $wpdb->get_row( $wpdb->prepare( "SELECT victory_points, draw_points FROM {$comp_table} WHERE id = %d", $competition_id ), OBJECT );
$victory_points         = $points_row ? intval( $points_row->victory_points ) : 3;
$draw_points            = $points_row ? intval( $points_row->draw_points ) : 1;
$class_phase_id_url     = '';

$f365_league_types = array( 'with_rounds', 'with_rounds_and_groups', 'single_round', 'mixed_groups' );
$f365_group_types  = array( 'with_rounds_and_groups', 'mixed_groups' );

if ( $has_competition_phases && $current_phase && in_array( $current_phase->phase_type, $f365_league_types, true ) ) {
	$show_classification = true;
	$class_phase_id_url  = (string) intval( $current_phase->id );
	if ( in_array( $current_phase->phase_type, $f365_group_types, true ) ) {
		if ( null !== $sel_group ) {
			$class_groups = array( $sel_group );
		} else {
			// get_groups_for_competition_phase: groups from BOTH group_home_team and
			// group_visitor_team. $group_choices already unions both columns.
			$class_groups = $group_choices;
		}
		foreach ( $class_groups as $g ) {
			$classification_tables[] = array(
				'label' => $g,
				'rows'  => f365_compute_classification( $wpdb, $match_table, $ts_table, $team_table, $competition_id, (int) $current_phase->id, $g, $victory_points, $draw_points ),
			);
		}
	} else {
		$classification_tables[] = array(
			'label' => '',
			'rows'  => f365_compute_classification( $wpdb, $match_table, $ts_table, $team_table, $competition_id, (int) $current_phase->id, null, $victory_points, $draw_points ),
		);
	}
} elseif ( ! $has_competition_phases && $has_matches ) {
	// Normal championship — complete classification from all matches.
	$show_classification     = true;
	$classification_tables[] = array(
		'label' => '',
		'rows'  => f365_compute_classification( $wpdb, $match_table, $ts_table, $team_table, $competition_id, null, null, $victory_points, $draw_points ),
	);
}

if ( ! function_exists( 'f365_classification_stat_url' ) ) {
	/** Stat-cell link: base + &competition={id}&match_option=...&opponent=all&competition_phase=... (competition.id always present, per classification-table.html). */
	function f365_classification_stat_url( $base_url, $competition_id, $option, $phase_id_url ) {
		return esc_url( $base_url . '&competition=' . intval( $competition_id ) . '&match_option=' . $option . '&opponent=all&competition_phase=' . $phase_id_url );
	}
}

if ( ! function_exists( 'f365_render_resumo_match_row' ) ) {
	/** One match row — mirrors match-table-competition-partial.html. */
	function f365_render_resumo_match_row( $m, $comp_edition, $competition_id, $video_match_ids ) {
		$res        = f365_match_result( $m );
		$home_class = ( 'home' === $res['winner'] ) ? ' class="teamWinner"' : '';
		$vis_class  = ( 'visitor' === $res['winner'] ) ? ' class="teamWinner"' : '';

		$home_url  = esc_url( f365_team_jogos_url( $m->home_team_id, $m->home_modality, $m->home_gender, $m->home_step, $comp_edition ) . ( 'national' === $m->home_team_type ? '&competition=' . intval( $competition_id ) : '' ) );
		$vis_url   = esc_url( f365_team_jogos_url( $m->visitor_team_id, $m->visitor_modality, $m->visitor_gender, $m->visitor_step, $comp_edition ) . ( 'national' === $m->visitor_team_type ? '&competition=' . intval( $competition_id ) : '' ) );
		$match_url = esc_url( home_url( '/jogo/' . intval( $m->id ) . '/' ) );

		$compare_qs  = http_build_query(
			array(
				'edition'   => $comp_edition,
				'step1'     => $m->home_step,
				'gender1'   => $m->home_gender,
				'modality1' => $m->home_modality,
				'step2'     => $m->visitor_step,
				'gender2'   => $m->visitor_gender,
				'modality2' => $m->visitor_modality,
			)
		);
		$compare_url = esc_url( home_url( '/equipa/' . intval( $m->home_team_id ) . '/comparar-com/' . intval( $m->visitor_team_id ) . '/?' . $compare_qs ) );
		$video_url   = esc_url( home_url( '/jogo/' . intval( $m->id ) . '/?match_opt=matchvideos' ) );
		$has_video   = in_array( (int) $m->id, $video_match_ids, true );

		$betting_url = '';
		if ( null === $m->situation || '' === $m->situation ) {
			$match_ts = strtotime( (string) $m->date_time );
			if ( false !== $match_ts ) {
				$lower_ts = strtotime( 'today', $match_ts - 3 * DAY_IN_SECONDS ); 
				$upper_ts = $match_ts + 6300;                                     
				$now_ts   = current_time( 'timestamp' );
				if ( $now_ts > $lower_ts && $now_ts < $upper_ts ) {
					$betting_url = 'https://www.futebol365.pt/goto/';
				}
			}
		}

		$ts      = $m->date_time ? strtotime( (string) $m->date_time ) : 0;
        $m_date  = $ts ? wp_date( 'd-m-y', $ts ) : '';
        $m_date2 = $ts ? wp_date( 'd-m', $ts ) : '';
        $m_time  = $ts ? wp_date( 'H:i', $ts ) : '';
		?>
		<tr>
			<td class="align-left hide-tiny hide-small cell-nowrap"><?php echo esc_html( $m_date ); ?></td>
			<td class="align-center hide-tiny hide-small"><?php echo esc_html( $m_time ); ?></td>
			<td class="align-left hide-xlarge hide-large hide-medium"><?php echo esc_html( $m_date2 ); ?><span class="break"><?php echo esc_html( $m_time ); ?></span></td>
			<td class="align-right hide-tiny hide-small">
				<a<?php echo $home_class; ?> href="<?php echo $home_url; ?>"><?php echo esc_html( $m->home_name ); ?></a>
			</td>
			<td class="align-left hide-xlarge hide-large hide-medium">
				<a<?php echo $home_class; ?> href="<?php echo $home_url; ?>"><?php echo esc_html( $m->home_name ); ?></a>
				<span class="break">
					<a<?php echo $vis_class; ?> href="<?php echo $vis_url; ?>"><?php echo esc_html( $m->visitor_name ); ?></a>
				</span>
			</td>
			<td class="align-center">
				<span class="ink-label ink-label-score silver"><a href="<?php echo $match_url; ?>"><?php echo esc_html( $res['result'] ); ?></a></span>
			</td>
			<td class="align-left hide-tiny hide-small">
				<a<?php echo $vis_class; ?> href="<?php echo $vis_url; ?>"><?php echo esc_html( $m->visitor_name ); ?></a>
			</td>
			<td class="align-left">
				<a href="<?php echo $compare_url; ?>">
					<span class="ink-tooltip ink-button-related green" data-tip-text="Comparar equipas" data-tip-where="up" data-tip-color="green">C</span>
				</a>
				<?php if ( '' !== $betting_url ) : ?>
				<a href="<?php echo esc_url( $betting_url ); ?>" target="_blank" rel="noopener">
					<span class="ink-tooltip ink-button-related orange" data-tip-text="A" data-tip-where="up" data-tip-color="orange">A</span>
				</a>
				<?php endif; ?>
				<?php if ( $has_video ) : ?>
				<a href="<?php echo $video_url; ?>">
					<span class="ink-tooltip ink-button-related red" data-tip-text="Vídeo" data-tip-where="up" data-tip-color="red">V</span>
				</a>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}

if ( ! function_exists( 'f365_get_result_aggregate' ) ) {
	function f365_get_result_aggregate( $s, $o ) {
		if ( null !== $s->home_score && null !== $s->visitor_score ) {
			if ( null !== $o->home_score_extra && null !== $o->visitor_score_extra ) {
				return ( (int) $s->home_score + (int) $o->visitor_score_extra ) . ' - ' . ( (int) $s->visitor_score + (int) $o->home_score_extra );
			}
			if ( null !== $o->home_score && null !== $o->visitor_score ) {
				return ( (int) $s->home_score + (int) $o->visitor_score ) . ' - ' . ( (int) $s->visitor_score + (int) $o->home_score );
			} elseif ( 'abrogated' === $o->situation ) {
				return (int) $s->home_score . ' - ' . (int) $s->visitor_score;
			}
		} elseif ( 'abrogated' === $s->situation && 'abrogated' === $o->situation ) {
			return 'ANU';
		} elseif ( 'abrogated' === $s->situation && null !== $o->home_score_extra && null !== $o->visitor_score_extra ) {
			return (int) $o->visitor_score_extra . ' - ' . (int) $o->home_score_extra;
		} elseif ( 'abrogated' === $s->situation && null !== $o->home_score && null !== $o->visitor_score ) {
			return (int) $o->visitor_score . ' - ' . (int) $o->home_score;
		}
		return '-';
	}
}
if ( ! function_exists( 'f365_is_home_winner_aggregate' ) ) {
	function f365_is_home_winner_aggregate( $s, $o ) {
		$result = false;
		if ( null !== $s->home_score && null !== $s->visitor_score ) {
			if ( null !== $o->penalty_home_score && null !== $o->penalty_visitor_score ) {
				if ( (int) $o->penalty_visitor_score > (int) $o->penalty_home_score ) { $result = true; }
			} elseif ( null !== $o->home_score_extra && null !== $o->visitor_score_extra ) {
				$ah = (int) $s->home_score + (int) $o->visitor_score_extra;
				$av = (int) $s->visitor_score + (int) $o->home_score_extra;
				if ( $ah > $av ) { $result = true; }
				if ( $ah === $av && (int) $s->visitor_score < (int) $o->visitor_score_extra ) { $result = true; }
			} elseif ( null !== $o->home_score && null !== $o->visitor_score ) {
				$ah = (int) $s->home_score + (int) $o->visitor_score;
				$av = (int) $s->visitor_score + (int) $o->home_score;
				if ( $ah > $av ) {
					$result = true;
				} elseif ( $ah === $av && (int) $o->visitor_score > (int) $s->visitor_score ) {
					return true;
				}
			}
		}
		return $result;
	}
}
if ( ! function_exists( 'f365_is_visitor_winner_aggregate' ) ) {
	function f365_is_visitor_winner_aggregate( $s, $o ) {
		$result = false;
		if ( null !== $s->home_score && null !== $s->visitor_score ) {
			if ( null !== $o->penalty_home_score && null !== $o->penalty_visitor_score ) {
				if ( (int) $o->penalty_visitor_score < (int) $o->penalty_home_score ) { $result = true; }
			} elseif ( null !== $o->home_score_extra && null !== $o->visitor_score_extra ) {
				$ah = (int) $s->home_score + (int) $o->visitor_score_extra;
				$av = (int) $s->visitor_score + (int) $o->home_score_extra;
				if ( $ah < $av ) { $result = true; }
				if ( $ah === $av && (int) $s->visitor_score > (int) $o->visitor_score_extra ) { $result = true; }
			} elseif ( null !== $o->home_score && null !== $o->visitor_score ) {
				$ah = (int) $s->home_score + (int) $o->visitor_score;
				$av = (int) $s->visitor_score + (int) $o->home_score;
				if ( $ah < $av ) {
					$result = true;
				} elseif ( $ah === $av && (int) $o->visitor_score < (int) $s->visitor_score ) {
					return true;
				}
			}
		}
		return $result;
	}
}

$is_elimination     = ( $current_phase && 'elimination' === $current_phase->phase_type );
$elim_legs          = array();
$elim_matches_byleg = array();
$elim_aggregate     = array();
if ( $is_elimination && ! empty( $resumo_matches ) ) {
    foreach ( $resumo_matches as $m ) {
        $leg = ( null !== $m->leg && '' !== $m->leg ) ? intval( $m->leg ) : 0;
        if ( in_array( $leg, array( 1, 2 ), true ) ) {
            if ( ! in_array( $leg, $elim_legs, true ) ) {
                $elim_legs[] = $leg;
            }
            $elim_matches_byleg[ $leg ][] = $m;
        }
    }
    if ( 2 === count( $elim_legs ) ) {
        $first_leg  = isset( $elim_matches_byleg[1] ) ? $elim_matches_byleg[1] : array();
        $second_leg = isset( $elim_matches_byleg[2] ) ? $elim_matches_byleg[2] : array();
        foreach ( $first_leg as $mfl ) {
            $other = null;
            foreach ( $second_leg as $msl ) {
                if ( intval( $msl->home_ts ) === intval( $mfl->visitor_ts ) && intval( $msl->visitor_ts ) === intval( $mfl->home_ts ) ) {
                    if ( null !== $other ) {
                        $other = null; // MultipleObjectsReturned -> skip pair (matches Python).
                        break;
                    }
                    $other = $msl;
                }
            }
            if ( null !== $other ) {
                $elim_aggregate[] = array(
                    'first'         => $mfl,
                    'second'        => $other,
                    'result'        => f365_get_result_aggregate( $mfl, $other ),
                    'home_winner'   => f365_is_home_winner_aggregate( $mfl, $other ),
                    'visitor_winner'=> f365_is_visitor_winner_aggregate( $mfl, $other ),
                );
            }
        }
    }
}

if ( ! function_exists( 'f365_competition_places' ) ) {
	function f365_competition_places( $wpdb, $place_table, $competition_id, $phase_id, $phase_type, $group ) {
		$where  = 'competition_id = %d';
		$params = array( $competition_id );
		if ( null !== $phase_id && ! in_array( $phase_type, array( 'single_round', 'single_round_with_groups' ), true ) ) {
			$where   .= ' AND competition_phase_id = %d';
			$params[] = (int) $phase_id;
		}
		if ( null !== $group && '' !== $group ) {
			$where   .= ' AND `group` = %s';
			$params[] = $group;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT position, color, description FROM {$place_table} WHERE {$where} ORDER BY position ASC", $params ),
			OBJECT
		);
		$by_position = array();
		$legend      = array();
		$seen        = array();
		if ( $rows ) {
			foreach ( $rows as $r ) {
				$pos = intval( $r->position );
				if ( ! isset( $by_position[ $pos ] ) ) {
					$by_position[ $pos ] = (string) $r->color;
				}
				$key = (string) $r->color . '|' . (string) $r->description;
				if ( ! isset( $seen[ $key ] ) ) {
					$seen[ $key ] = true;
					$legend[]     = array( 'color' => (string) $r->color, 'description' => (string) $r->description );
				}
			}
		}
		return array( 'by_position' => $by_position, 'legend' => $legend );
	}
}
$place_table = $wpdb->prefix . 'competition_competitionplace';

// Faithful port of Match.get_result() + winning side.
if ( ! function_exists( 'f365_match_result' ) ) {
    function f365_match_result( $m ) {
        $situation_map = array( 'delayed' => 'ADI', 'suspended' => 'SUS', 'abrogated' => 'ANU' );
        if ( isset( $situation_map[ $m->situation ] ) ) {
            return array( 'result' => $situation_map[ $m->situation ], 'winner' => '' );
        }

        $has_extra     = ( null !== $m->home_score_extra && null !== $m->visitor_score_extra );
        $has_penalties = ( null !== $m->penalty_home_score && null !== $m->penalty_visitor_score );

        if ( $has_extra ) {
            $result = $m->home_score_extra . ' - ' . $m->visitor_score_extra . ' a.p';
        } elseif ( null !== $m->home_score && null !== $m->visitor_score ) {
            $result = $m->home_score . ' - ' . $m->visitor_score;
        } else {
            $result = 'vs';
        }
        if ( $has_penalties ) {
            $result = $m->penalty_home_score . ' - ' . $m->penalty_visitor_score . ' g.p';
        }

        $winner = '';
        if ( $has_penalties ) {
            $winner = ( $m->penalty_home_score > $m->penalty_visitor_score ) ? 'home' : ( ( $m->penalty_home_score < $m->penalty_visitor_score ) ? 'visitor' : '' );
        } elseif ( $has_extra ) {
            $winner = ( $m->home_score_extra > $m->visitor_score_extra ) ? 'home' : ( ( $m->home_score_extra < $m->visitor_score_extra ) ? 'visitor' : '' );
        } elseif ( null !== $m->home_score && null !== $m->visitor_score ) {
            $winner = ( $m->home_score > $m->visitor_score ) ? 'home' : ( ( $m->home_score < $m->visitor_score ) ? 'visitor' : '' );
        }

        return array( 'result' => $result, 'winner' => $winner );
    }
}
?>

<main id="primary" class="site-main single-article-page" <?php echo $current_post_id?>>

    <?php 
    get_template_part(
        'template-parts/competition/f365-competition-header',
        null,
        array( 
            'post_id'  => $current_post_id,
            'sections' => $sections,
            'competition_id' => $final_competition_id
        )
    );
    ?>

    <div class="ink-grid futebol365-container">

        <section class="left-column-block">

            <div class="breadcrumbs-f365">
				<div class="ink-navigation breadcrumbs">
					<?php if ( function_exists( 'yoast_breadcrumb' ) ) { yoast_breadcrumb(); } ?>
				</div>
			</div>
            <?php if ( $videos_visibility ) : ?>
                <section class="news-most-view-in-category">
                    <div class="column-group gutters">
                        <?php
                    foreach ( $latest_videos as $video ) :
                        $video_url   = esc_url( home_url( '/multimedia/videos/' . intval( $video->id ) . '/' ) );
                        $base = home_url( '/media/' );
                        $video_image_dj = $base.$video->image;
                        $video_image = ! empty( $video_image_dj ) ? esc_url( $video_image_dj ) : esc_url( futebol365_fallback_image() );
                        $video_title = esc_attr( $video->title );
                        $video_date = ! empty( $video->datetime )
                        ? mysql2date( 'd-m-Y · H:i', $video->datetime )
                        : '';
                        
                    ?>
                        <div class="all-25 small-50 tiny-100">
                            <figure class="ink-image ink-lazyload half-bottom-space my-thumb-16-9" data-delta="300" data-loaded-class="loaded">
                                <a href="<?php echo $video_url; ?>">
                                    <img class="lazyload-item my-thumb-image loaded"
                                        src="<?php echo esc_url( $video_image ); ?>"
                                        title="<?php echo $video_title; ?>"
                                        alt="Futebol 365">
                                </a>
                                <?php if ( ! empty( $video->length ) ) : ?>
                                    <figcaption class="over-bottom dark figcaption-videos"><?php echo esc_html( $video->length ); ?></figcaption>
                                <?php endif; ?>
                            </figure>
                            <h3 class="quarter-vertical-space medium uppercase condensed-400"><?php echo esc_html( $video_date ); ?></h3>
                            <h2 class="large condensed-700"><a href="<?php echo $video_url; ?>"><?php echo esc_html( $video->title ); ?></a></h2>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php // dropdown-level-2.html — only when there are matches. ?>
            <?php if ( $has_matches && ( $show_round || $show_phase ) ) : ?>
            <form class="ink-form ink-form-option">
                <div class="column-group">

                    <?php if ( $show_round ) : ?>
                        <div class="control-group all-33 tiny-100 small-100">
                            <div class="control">
                                <select name="competition_round" id="id_competition_round">
                                    <?php foreach ( $round_choices as $rc ) : ?>
                                        <option value="<?php echo esc_attr( $rc ); ?>" <?php selected( (string) $competition_round, (string) $rc ); ?>>Jornada <?php echo esc_html( $rc ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ( $show_phase ) : ?>
                        <div class="control-group all-33 tiny-100 small-100">
                            <div class="control">
                                <select name="competition_phase" id="id_competition_phase">
                                    <?php foreach ( $phases as $p ) : ?>
                                        <option value="<?php echo esc_attr( $p->name ); ?>" <?php selected( $current_phase && $p->name === $current_phase->name ); ?>><?php echo esc_html( $p->name ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ( $show_group ) : ?>
                        <div class="control-group all-33 tiny-100 small-100">
                            <div class="control">
                                <select name="group" id="id_group">
                                    <option value="all" <?php selected( null === $sel_group ); ?>>Grupos</option>
                                    <?php foreach ( $group_choices as $g ) : ?>
                                        <option value="<?php echo esc_attr( $g ); ?>" <?php selected( $sel_group, $g ); ?>><?php echo esc_html( $g ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ( $show_phase_round && ! empty( $phase_round_choices ) ) : ?>
                        <div class="control-group all-33 tiny-100 small-100">
                            <div class="control">
                                <select name="phase_round" id="id_phase_round">
                                    <?php foreach ( $phase_round_choices as $pr ) : ?>
                                        <option value="<?php echo esc_attr( $pr ); ?>" <?php selected( (string) $phase_round, (string) $pr ); ?>>Jornada <?php echo esc_html( $pr ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </form>
            <script>
            // dropdown-level-2 cascade: rebuild the query string on change, with the
            // same reset rules, using URLSearchParams (avoids the esc_js "&" pitfall).
            (function () {
                function applyChange( changedId ) {
                    var params = new URLSearchParams( window.location.search );
                    var ed = document.getElementById( 'edition' );
                    if ( ed && ed.value ) { 
                        var edValue = ed.value;
                        // If the value is a full URL, extract just the edition query parameter
                        if ( edValue.includes( '?' ) ) {
                            var edParams = new URLSearchParams( edValue.split( '?' )[1] );
                            if ( edParams.has( 'edition' ) ) {
                                edValue = edParams.get( 'edition' );
                            }
                        }
                        params.set( 'edition', edValue ); 
                    }

                    var ids = [ 'competition_round', 'competition_phase', 'group', 'phase_round', 'home_visitor' ];
                    ids.forEach( function ( id ) {
                        var el = document.getElementById( 'id_' + id );
                        if ( ! el ) { return; }
                        if ( 'competition_round' === id ) {
                            if ( el.value ) { params.set( 'competition_round', el.value ); } else { params.delete( 'competition_round' ); }
                        } else if ( el.value ) {
                            params.set( id, el.value );
                        }
                    } );

                    if ( 'id_competition_round' === changedId ) {
                        params.set( 'group', 'all' );
                    }
                    if ( 'id_competition_phase' === changedId ) {
                        params.delete( 'competition_round' );
                        params.set( 'group', 'all' );
                        params.set( 'phase_round', 'all' );
                    }
                    if ( 'id_group' === changedId ) {
                        params.delete( 'competition_round' );
                        params.set( 'phase_round', 'all' );
                    }
                    window.location.search = params.toString();
                }

                [ 'id_competition_round', 'id_competition_phase', 'id_group', 'id_phase_round', 'id_home_visitor' ].forEach( function ( domId ) {
                    var el = document.getElementById( domId );
                    if ( el ) {
                        el.addEventListener( 'change', function () { applyChange( domId ); } );
                    }
                } );

                var pr = document.getElementById( 'id_phase_round' );
                if ( pr && 0 === pr.options.length ) { pr.style.display = 'none'; }
            })();
            </script>
            <?php endif; ?>

            <h3 class="condensed-400 headerTitle blue">Resumo</h3>

            <?php if ( ! empty( $resumo_matches ) ) : ?>
            <?php
            // Build the list of match tables to render: per-leg for two-legged
            // elimination rounds, otherwise a single table.
            $match_tables = array();
            if ( $is_elimination && ! empty( $elim_legs ) ) {
                foreach ( $elim_legs as $leg ) {
                    $match_tables[] = array(
                        'title'   => $phase_title . ' - ' . intval( $leg ) . 'ª Mão',
                        'matches' => isset( $elim_matches_byleg[ $leg ] ) ? $elim_matches_byleg[ $leg ] : array(),
                    );
                }
            } else {
                $match_tables[] = array( 'title' => $phase_title, 'matches' => $resumo_matches );
            }
            foreach ( $match_tables as $mt ) :
            ?>
            <h5 class="headerTitleSimpleBackground"><?php echo esc_html( $mt['title'] ); ?></h5>
            <table class="ink-table ink-table-futebol365 alternating">
                <thead>
                    <tr>
                        <th class="all-10 small-15 tiny-15 align-left">Data</th>
                        <th class="all-5 hide-tiny hide-small align-center">Hora</th>
                        <th class="all-30 align-right hide-tiny hide-small">Visitado</th>
                        <th class="all-45 align-left hide-medium hide-large hide-xlarge">Jogo</th>
                        <th class="all-10 small-20 tiny-20"></th>
                        <th class="all-30 align-left hide-tiny hide-small">Visitante</th>
                        <th class="all-15 small-20 tiny-20"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $mt['matches'] as $m ) : ?>
                        <?php f365_render_resumo_match_row( $m, $comp_edition, $competition_id, $video_match_ids ); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endforeach; ?>

            <?php if ( $is_elimination && ! empty( $elim_aggregate ) ) : ?>
                <h5 class="headerTitleSimpleBackground"><?php echo esc_html( $phase_title ); ?> - Agregado</h5>
                <table class="ink-table ink-table-f365 alternating all-100">
                    <thead>
                        <tr>
                            <th class="align-left all-10 hide-tiny hide-small">1ª Mão</th>
                            <th class="align-left all-10 hide-tiny hide-small">2ª Mão</th>
                            <th class="align-right all-30 tiny-40 small-40">Equipa</th>
                            <th class="align-center all-10 tiny-20 small-20"></th>
                            <th class="align-left all-30 tiny-40 small-40">Equipa</th>
                            <th class="all-10 hide-tiny hide-small"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ( $elim_aggregate as $pair ) :
                            $mfl       = $pair['first'];
                            $home_cls  = $pair['home_winner'] ? ' class="teamWinner"' : '';
                            $vis_cls   = $pair['visitor_winner'] ? ' class="teamWinner"' : '';
                            $agg_home_url = esc_url( f365_team_jogos_url( $mfl->home_team_id, $mfl->home_modality, $mfl->home_gender, $mfl->home_step, $comp_edition ) . ( 'national' === $mfl->home_team_type ? '&competition=' . intval( $competition_id ) : '' ) );
                            $agg_vis_url  = esc_url( f365_team_jogos_url( $mfl->visitor_team_id, $mfl->visitor_modality, $mfl->visitor_gender, $mfl->visitor_step, $comp_edition ) . ( 'national' === $mfl->visitor_team_type ? '&competition=' . intval( $competition_id ) : '' ) );
                            // AFTER (Site timezone conversion):
                            $ts_first  = ! empty( $pair['first']->date_time ) ? strtotime( (string) $pair['first']->date_time ) : 0;
                            $ts_second = ! empty( $pair['second']->date_time ) ? strtotime( (string) $pair['second']->date_time ) : 0;
                        ?>
                        <tr>
                            <td class="align-left hide-tiny hide-small"><?php echo esc_html( $ts_first ? wp_date( 'd-m-y', $ts_first ) : '' ); ?></td>
<td class="align-left hide-tiny hide-small"><?php echo esc_html( $ts_second ? wp_date( 'd-m-y', $ts_second ) : '' ); ?></td>
                            <td class="align-right">
                                <a<?php echo $home_cls; ?> href="<?php echo $agg_home_url; ?>"><?php echo esc_html( $mfl->home_name ); ?></a>
                            </td>
                            <td class="align-center">
                                <span class="ink-label ink-label-score silver"><?php echo esc_html( $pair['result'] ); ?></span>
                            </td>
                            <td class="align-left">
                                <a<?php echo $vis_cls; ?> href="<?php echo $agg_vis_url; ?>"><?php echo esc_html( $mfl->visitor_name ); ?></a>
                            </td>
                            <td class="hide-tiny hide-small"></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( $show_classification ) : ?>
				<?php
				foreach ( $classification_tables as $ct ) :
					$ct_group      = ( '' !== $ct['label'] ) ? $ct['label'] : null;
					$ct_phase_id   = $current_phase ? (int) $current_phase->id : null;
					$ct_phase_type = $current_phase ? $current_phase->phase_type : null;
					$ct_places     = f365_competition_places( $wpdb, $place_table, $competition_id, $ct_phase_id, $ct_phase_type, $ct_group );
				?>
					<?php if ( '' !== $ct['label'] ) : ?>
						<h5 class="headerTitleSimpleBackground">Classificação - <?php echo esc_html( $ct['label'] ); ?></h5>
					<?php else : ?>
						<h5 class="headerTitleSimpleBackground">Classificação</h5>
					<?php endif; ?>
					<table class="ink-table ink-table-futebol365 alternating hover">
						<thead>
							<tr>
								<th class="align-center all-5 tiny-10 small-10"></th>
								<th class="align-left">Equipa</th>
								<th class="align-center all-5 tiny-10 small-10"><span class="ink-tooltip" data-tip-text="Jogos" data-tip-where="up" data-tip-color="black">J</span></th>
								<th class="align-center all-5 small-10 hide-tiny"><span class="ink-tooltip" data-tip-text="Vitórias" data-tip-where="up" data-tip-color="black">V</span></th>
								<th class="align-center all-5 small-10 hide-tiny"><span class="ink-tooltip" data-tip-text="Empates" data-tip-where="up" data-tip-color="black">E</span></th>
								<th class="align-center all-5 small-10 hide-tiny"><span class="ink-tooltip" data-tip-text="Derrotas" data-tip-where="up" data-tip-color="black">D</span></th>
								<th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip" data-tip-text="Golos Marcados" data-tip-where="up" data-tip-color="black">GM</span></th>
								<th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip" data-tip-text="Golos Sofridos" data-tip-where="up" data-tip-color="black">GS</span></th>
								<th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip" data-tip-text="Diferença de Golos" data-tip-where="up" data-tip-color="black">DG</span></th>
								<th class="align-center all-5 tiny-10 small-10"><span class="ink-tooltip" data-tip-text="Pontos" data-tip-where="up" data-tip-color="black">P</span></th>
								<th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip" data-tip-text="Diferença Pontual" data-tip-where="up" data-tip-color="black">DP</span></th>
								<th class="align-center all-5 hide-tiny hide-small"></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$pos = 0;
							foreach ( $ct['rows'] as $row ) :
								$pos++;
								$base_url   = f365_team_jogos_url( $row['team_id'], $row['modality'], $row['gender'], $row['step'], $comp_edition );
								$nat_suffix = ( 'national' === $row['team_type'] ) ? '&competition=' . intval( $competition_id ) : '';
								$team_url   = esc_url( $base_url . $nat_suffix );
							?>
							<tr>
								<td class="align-center"><span class="ink-label ink-label-table-position" style="background-color:<?php echo esc_attr( isset( $ct_places['by_position'][ $pos ] ) ? $ct_places['by_position'][ $pos ] : '' ); ?>"><?php echo intval( $pos ); ?></span></td>
								<td class="align-left"><a href="<?php echo $team_url; ?>"><?php echo esc_html( $row['name'] ); ?></a></td>
								<td class="align-center" title="Jogos"><a href="<?php echo f365_classification_stat_url( $base_url, $competition_id, 'matches', $class_phase_id_url ); ?>"><?php echo intval( $row['J'] ); ?></a></td>
								<td class="align-center hide-tiny" title="Vitórias"><a href="<?php echo f365_classification_stat_url( $base_url, $competition_id, 'victories', $class_phase_id_url ); ?>"><?php echo intval( $row['V'] ); ?></a></td>
								<td class="align-center hide-tiny" title="Empates"><a href="<?php echo f365_classification_stat_url( $base_url, $competition_id, 'draws', $class_phase_id_url ); ?>"><?php echo intval( $row['E'] ); ?></a></td>
								<td class="align-center hide-tiny" title="Derrotas"><a href="<?php echo f365_classification_stat_url( $base_url, $competition_id, 'loses', $class_phase_id_url ); ?>"><?php echo intval( $row['D'] ); ?></a></td>
								<td class="align-center hide-tiny hide-small" title="Golos Marcados"><a href="<?php echo f365_classification_stat_url( $base_url, $competition_id, 'scored_goals', $class_phase_id_url ); ?>"><?php echo intval( $row['GM'] ); ?></a></td>
								<td class="align-center hide-tiny hide-small" title="Golos Sofridos"><a href="<?php echo f365_classification_stat_url( $base_url, $competition_id, 'suffered_goals', $class_phase_id_url ); ?>"><?php echo intval( $row['GS'] ); ?></a></td>
								<td class="align-center hide-tiny hide-small" title="Diferença de Golos"><?php echo intval( $row['DG'] ); ?></td>
								<td class="align-center" title="Pontos"><b><?php echo intval( $row['P'] ); ?></b></td>
								<td class="align-center hide-tiny hide-small" title="Diferença de Pontos"><?php echo intval( $row['DP'] ); ?></td>
								<td class="align-center hide-tiny hide-small">
									<a href="<?php echo f365_classification_stat_url( $base_url, $competition_id, 'matches', $class_phase_id_url ); ?>">
										<svg class="svg-icon" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#icon-plus"></use></svg>
									</a>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( ! empty( $ct_places['legend'] ) ) : ?>
						<div class="column-group">
							<h6 class="headerTitleSimple">Notas da classificação</h6>
							<table class="ink-table ink-table-futebol365 noborder">
								<tbody>
									<?php foreach ( $ct_places['legend'] as $cp ) : ?>
										<tr>
											<td class="align-center all-5 tiny-10 small-10"><span class="ink-label ink-label-table-position" style="background-color:<?php echo esc_attr( $cp['color'] ); ?>">&nbsp;</span></td>
											<td class="align-left"><?php echo esc_html( $cp['description'] ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				<?php endforeach; ?>
            <?php endif; ?>

            <?php else : ?>
                <h5 class="no-info">Sem informação disponível</h5>
            <?php endif; ?>

            <?php if ( '' !== trim( $comp_notes ) ) : ?>
                <div class="column-group vertical-gutters">
                    <div class="all-100">
                        <h6 class="headerTitleSimple">Notas da competição</h6>
                        <h6 class="competition-notes"><?php echo nl2br( esc_html( $comp_notes ) ); ?></h6>
                    </div>
                </div>
            <?php endif; ?>

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

<?php
get_footer();