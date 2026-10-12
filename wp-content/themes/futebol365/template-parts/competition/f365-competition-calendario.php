<?php
/**
 * Competition — Calendário (all games)
 *
 * Port of competition/competition_all_games.html + get_competition_calendar view.
 * Route: /competicao/{federation_competition_id}/calendario/
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

get_header();
global $wpdb;

$current_post_id = get_the_ID();
$current_section = 'calendario';
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
        "SELECT federation_competition_id, edition FROM {$comp_table} WHERE id = %d",
        $competition_id
    ),
    OBJECT
);
$fed_comp_id  = $comp_row ? intval( $comp_row->federation_competition_id ) : 0;
$comp_edition = $comp_row ? (string) $comp_row->edition : '';

// Canonical page URL (Django route /competicao/{fed_comp_id}/calendario/).
$cal_base_url = home_url( '/competicao/' . intval( $fed_comp_id ) . '/calendario/' );

// Selected team participant (a teamstep id), 'all_teams' => none.
$team_step = ( isset( $_GET['team_participant'] ) && '' !== $_GET['team_participant'] && 'all_teams' !== $_GET['team_participant'] && is_numeric( $_GET['team_participant'] ) )
    ? intval( $_GET['team_participant'] ) : null;

/* -------------------------------------------------------------------------
 * Helpers.
 * ---------------------------------------------------------------------- */
if ( ! function_exists( 'f365_teamstep_label' ) ) {
    function f365_teamstep_label( $shortname, $step, $gender, $modality ) {
        $rest = '';
        if ( 'futsal' === $modality ) {
            $rest .= ' FS';
        }
        if ( 'female' === $gender ) {
            $rest .= ' FM';
        }
        if ( ! in_array( $step, array( 'principal', 'b_team', 'c_team', 'veterans', 'reservations' ), true ) ) {
            if ( 0 === strpos( (string) $step, 'sub_' ) ) {
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

if ( ! function_exists( 'f365_match_result_text' ) ) {
    function f365_match_result_text( $m ) {
        if ( 'delayed' === $m->situation ) {
            return 'ADI';
        }
        if ( 'suspended' === $m->situation ) {
            return 'SUS';
        }
        if ( 'abrogated' === $m->situation ) {
            return 'ANU';
        }
        $has_extra     = ( null !== $m->home_score_extra && '' !== $m->home_score_extra && null !== $m->visitor_score_extra && '' !== $m->visitor_score_extra );
        $has_penalties = ( null !== $m->penalty_home_score && '' !== $m->penalty_home_score && null !== $m->penalty_visitor_score && '' !== $m->penalty_visitor_score );

        if ( $has_extra ) {
            $result = intval( $m->home_score_extra ) . ' - ' . intval( $m->visitor_score_extra ) . ' a.p';
        } elseif ( null !== $m->home_score && null !== $m->visitor_score ) {
            $result = intval( $m->home_score ) . ' - ' . intval( $m->visitor_score );
        } else {
            $result = 'vs';
        }
        if ( $has_penalties ) {
            $result = intval( $m->penalty_home_score ) . ' - ' . intval( $m->penalty_visitor_score ) . ' g.p';
        }
        return $result;
    }
}

if ( ! function_exists( 'f365_match_home_winner' ) ) {
    function f365_match_home_winner( $m ) {
        if ( null !== $m->penalty_home_score && null !== $m->penalty_visitor_score ) {
            return intval( $m->penalty_home_score ) > intval( $m->penalty_visitor_score );
        }
        if ( null !== $m->home_score_extra && null !== $m->visitor_score_extra ) {
            return intval( $m->home_score_extra ) > intval( $m->visitor_score_extra );
        }
        if ( null !== $m->home_score && null !== $m->visitor_score ) {
            return intval( $m->home_score ) > intval( $m->visitor_score );
        }
        return false;
    }
}

if ( ! function_exists( 'f365_match_visitor_winner' ) ) {
    function f365_match_visitor_winner( $m ) {
        if ( null !== $m->penalty_home_score && null !== $m->penalty_visitor_score ) {
            return intval( $m->penalty_home_score ) < intval( $m->penalty_visitor_score );
        }
        if ( null !== $m->home_score_extra && null !== $m->visitor_score_extra ) {
            return intval( $m->home_score_extra ) < intval( $m->visitor_score_extra );
        }
        if ( null !== $m->home_score && null !== $m->visitor_score ) {
            return intval( $m->home_score ) < intval( $m->visitor_score );
        }
        return false;
    }
}

if ( ! function_exists( 'f365_round_url' ) ) {
    function f365_round_url( $m, $fed_comp_id, $edition, $with_group ) {
        $args = array();
        if ( null !== $m->phase_name && '' !== $m->phase_name ) {
            $args[] = 'competition_phase=' . str_replace( ' ', '+', $m->phase_name );
            if ( null !== $m->group_phase_round && '' !== $m->group_phase_round ) {
                $args[] = 'phase_round=' . intval( $m->group_phase_round );
            }
        } else {
            $args[] = 'competition_round=' . intval( $m->match_round );
        }
        if ( $with_group && null !== $m->group_home_team && '' !== $m->group_home_team ) {
            $args[] = 'group=' . str_replace( ' ', '+', $m->group_home_team );
        }
        $term = ! empty( $args ) ? ( '&' . implode( '&', $args ) ) : '';
        return home_url( '/competicao/' . intval( $fed_comp_id ) . '/?edition=' . rawurlencode( $edition ) . $term );
    }
}

if ( ! function_exists( 'f365_round_header_text' ) ) {
    function f365_round_header_text( $m ) {
        if ( null !== $m->phase_name && '' !== $m->phase_name ) {
            $desc = $m->phase_name;
            if ( null !== $m->group_phase_round && '' !== $m->group_phase_round ) {
                $desc .= ' - ' . intval( $m->group_phase_round ) . 'ª Jornada';
            }
            return $desc;
        }
        return intval( $m->match_round ) . 'ª Jornada';
    }
}

/* -------------------------------------------------------------------------
 * team_participant choices — teamsteps that played in this competition.
 * ---------------------------------------------------------------------- */
$participants = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT DISTINCT
               ts.id,
               ts.step,
               ts.gender,
               ts.modality,
               t.shortname
        FROM (
            SELECT home_team_id AS teamstep_id
            FROM {$match_table}
            WHERE competition_id = %d

            UNION ALL

            SELECT visitor_team_id
            FROM {$match_table}
            WHERE competition_id = %d
        ) teams
        JOIN {$ts_table} ts
            ON ts.id = teams.teamstep_id
        JOIN {$team_table} t
            ON t.id = ts.team_id
        ORDER BY t.shortname ASC
        ",
        $competition_id,
        $competition_id
    ),
    OBJECT
);

/* -------------------------------------------------------------------------
 * Matches (get_matches), paginated 50/page.
 * ---------------------------------------------------------------------- */
$per_page = 50;
$cur_page = isset( $_GET['pagina'] ) ? max( 1, intval( $_GET['pagina'] ) ) : 1;

$where     = 'm.competition_id = %d';
$params    = array( $competition_id );
if ( null !== $team_step ) {
    $where   .= ' AND ( m.home_team_id = %d OR m.visitor_team_id = %d )';
    $params[] = $team_step;
    $params[] = $team_step;
}

$total_items = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT COUNT(1) FROM {$match_table} m WHERE {$where}", $params )
);
$total_pages = max( 1, (int) ceil( $total_items / $per_page ) );
$cur_page    = min( $cur_page, $total_pages );
$offset      = ( $cur_page - 1 ) * $per_page;

$matches = array();
if ( $total_items > 0 ) {
    $query_params = array_merge( $params, array( $per_page, $offset ) );
    $matches      = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT m.id, m.match_round, m.group_phase_round, m.group_home_team,
                    m.date_time, m.situation,
                    m.home_score, m.visitor_score, m.home_score_extra, m.visitor_score_extra,
                    m.penalty_home_score, m.penalty_visitor_score,
                    cp.name AS phase_name,
                    hts.team_id AS home_team_pk, hts.step AS home_step, hts.gender AS home_gender,
                    hts.modality AS home_modality, hts.edition AS home_edition,
                    ht.shortname AS home_name, ht.team_type AS home_type,
                    vts.team_id AS visitor_team_pk, vts.step AS visitor_step, vts.gender AS visitor_gender,
                    vts.modality AS visitor_modality, vts.edition AS visitor_edition,
                    vt.shortname AS visitor_name, vt.team_type AS visitor_type
               FROM {$match_table} m
               LEFT JOIN {$phase_table} cp ON m.competition_phase_id = cp.id
               JOIN {$ts_table} hts ON m.home_team_id = hts.id
               JOIN {$team_table} ht ON hts.team_id = ht.id
               JOIN {$ts_table} vts ON m.visitor_team_id = vts.id
               JOIN {$team_table} vt ON vts.team_id = vt.id
              WHERE {$where}
              ORDER BY m.date_time ASC, ht.shortname ASC
              LIMIT %d OFFSET %d",
            $query_params
        ),
        OBJECT
    );
}

// show_match_link_actions: which matches on this page have a video (V button).
$video_match_ids = array();
if ( ! empty( $matches ) ) {
    $mids         = array_map( 'intval', wp_list_pluck( $matches, 'id' ) );
    $placeholders = implode( ',', array_fill( 0, count( $mids ), '%d' ) );
    $video_rows   = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT match_id FROM {$wpdb->prefix}multimedia_video WHERE match_id IN ($placeholders)",
            $mids
        )
    );
    $video_match_ids = array_map( 'intval', $video_rows );
}
?>

<main id="primary" class="site-main single-article-page" <?php echo $current_post_id; ?>>

    <?php get_template_part(
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

    <div class="ink-grid futebol365-container">
        <section class="left-column-block">
            <div class="breadcrumbs-f365">
                <div class="ink-navigation breadcrumbs">
                    <?php if ( function_exists( 'yoast_breadcrumb' ) ) { yoast_breadcrumb(); } ?>
                </div>
            </div>

            <?php // dropdown-level-2: team_participant filter. ?>
            <form class="ink-form ink-form-option" method="get" name="teams_level2_form" id="teams_level2_form" action="<?php echo esc_url( $cal_base_url ); ?>">
                <div class="column-group">
                    <div class="control-group all-33 tiny-100 small-100">
                        <div class="control">
                            <select name="team_participant" id="id_team_participant">
                                <option value="all_teams" <?php selected( null === $team_step ); ?>>Equipas</option>
                                <?php foreach ( $participants as $p ) : ?>
                                    <option value="<?php echo intval( $p->id ); ?>" <?php selected( $team_step, intval( $p->id ) ); ?>><?php echo esc_html( f365_teamstep_label( $p->shortname, $p->step, $p->gender, $p->modality ) ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </form>

            <h5 class="condensed-400 headerTitle blue">Calendário</h5>

            <?php if ( ! empty( $matches ) ) : ?>
                <?php
                $prev_round = null;
                $idx        = 0;
                $last_idx   = count( $matches ) - 1;
                foreach ( $matches as $m ) :
                    $is_first = ( 0 === $idx );
                    $is_last  = ( $last_idx === $idx );
                    $idx++;

                    // {% ifchanged match.match_round %}
                    if ( $prev_round !== $m->match_round ) :
                        if ( ! $is_first ) :
                            ?>
                            </tbody></table>
                            <?php
                        endif;
                        $round_header_url = f365_round_url( $m, $fed_comp_id, $comp_edition, false );
                        $round_header_txt = f365_round_header_text( $m );
                        ?>
                        <h5 class="headerTitleSimpleBackground"><a href="<?php echo esc_url( $round_header_url ); ?>"><?php echo esc_html( $round_header_txt ); ?></a></h5>
                        <table class="ink-table ink-table-f365 alternating all-100">
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
                        <?php
                        $prev_round = $m->match_round;
                    endif;

                    // Row data.
                    $round_url   = esc_url( f365_round_url( $m, $fed_comp_id, $comp_edition, true ) );
                    $home_winner = f365_match_home_winner( $m );
                    $vis_winner  = f365_match_visitor_winner( $m );

                    $home_url = f365_teamstep_url( $m->home_team_pk, $m->home_edition, $m->home_modality, $m->home_gender, $m->home_step );
                    if ( 'national' === $m->home_type ) {
                        $home_url .= '&competition=' . intval( $competition_id );
                    }
                    $vis_url = f365_teamstep_url( $m->visitor_team_pk, $m->visitor_edition, $m->visitor_modality, $m->visitor_gender, $m->visitor_step );
                    if ( 'national' === $m->visitor_type ) {
                        $vis_url .= '&competition=' . intval( $competition_id );
                    }
                    $home_url = esc_url( $home_url );
                    $vis_url  = esc_url( $vis_url );

                    $home_label = f365_teamstep_label( $m->home_name, $m->home_step, $m->home_gender, $m->home_modality );
                    $vis_label  = f365_teamstep_label( $m->visitor_name, $m->visitor_step, $m->visitor_gender, $m->visitor_modality );

                    $home_cls = $home_winner ? ' class="teamWinner"' : '';
                    $vis_cls  = $vis_winner ? ' class="teamWinner"' : '';

                    $match_url   = esc_url( home_url( '/jogo/' . intval( $m->id ) . '/' ) );
                    $result_txt  = f365_match_result_text( $m );

                    $compare_qs = http_build_query(
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
                    $compare_url = esc_url( home_url( '/equipa/' . intval( $m->home_team_pk ) . '/comparar-com/' . intval( $m->visitor_team_pk ) . '/?' . $compare_qs ) );
                    $video_url   = esc_url( home_url( '/jogo/' . intval( $m->id ) . '/?match_opt=matchvideos' ) );
                    $has_video   = in_array( (int) $m->id, $video_match_ids, true );
                    $goto_url    = esc_url( home_url( '/goto/' ) );

                    /*
                     * Betting "A" button visibility — mirrors competition_tags.show_match_link_actions:
                     *   betting_url is set only when match.is_available() (situation IS NULL) AND
                     *   now is between midnight of (kickoff - 3 days) and (kickoff + 6300s / 105 min).
                     * (The source also hides it while the match is being livestreamed; that
                     * secondary exclusion needs the live-stream tables and is not applied here.)
                     */
                    $bet_available = ( null === $m->situation || '' === $m->situation );
                    $bet_kickoff   = strtotime( (string) $m->date_time );
                    $bet_open      = $bet_kickoff ? strtotime( date( 'Y-m-d 00:00:00', $bet_kickoff - 3 * DAY_IN_SECONDS ) ) : 0;
                    $bet_close     = $bet_kickoff ? $bet_kickoff + 6300 : 0;
                    $show_bet      = $bet_available && $bet_kickoff && time() > $bet_open && time() < $bet_close;

                    $ts_cal = ! empty( $m->date_time ) ? strtotime( (string) $m->date_time ) : 0;
                    $d1     = $ts_cal ? wp_date( 'd-m-y', $ts_cal ) : '';
                    $d2     = $ts_cal ? wp_date( 'd-m', $ts_cal ) : '';
                    $time   = $ts_cal ? wp_date( 'H:i', $ts_cal ) : '';
                    ?>
                    <tr>
                        <td class="align-left hide-tiny hide-small cell-nowrap"><a href="<?php echo $round_url; ?>"><?php echo esc_html( $d1 ); ?></a></td>
                        <td class="align-center hide-tiny hide-small"><?php echo esc_html( $time ); ?></td>
                        <td class="align-left hide-xlarge hide-large hide-medium"><?php echo esc_html( $d2 ); ?><span class="break"><?php echo esc_html( $time ); ?></span></td>

                        <td class="align-right hide-tiny hide-small">
                            <a<?php echo $home_cls; ?> href="<?php echo $home_url; ?>"><?php echo esc_html( $home_label ); ?></a>
                        </td>
                        <td class="align-left hide-xlarge hide-large hide-medium">
                            <a<?php echo $home_cls; ?> href="<?php echo $home_url; ?>"><?php echo esc_html( $home_label ); ?></a>
                            <span class="break">
                                <a<?php echo $vis_cls; ?> href="<?php echo $vis_url; ?>"><?php echo esc_html( $vis_label ); ?></a>
                            </span>
                        </td>
                        <td class="align-center">
                            <span class="ink-label ink-label-score silver"><a href="<?php echo $match_url; ?>"><?php echo esc_html( $result_txt ); ?></a></span>
                        </td>
                        <td class="align-left hide-tiny hide-small">
                            <a<?php echo $vis_cls; ?> href="<?php echo $vis_url; ?>"><?php echo esc_html( $vis_label ); ?></a>
                        </td>
                        <td class="align-left">
                            <a href="<?php echo $compare_url; ?>">
                                <span class="ink-tooltip ink-button-related green" data-tip-text="Comparar equipas" data-tip-where="up" data-tip-color="green">C</span>
                            </a>
                            <?php if ( $show_bet ) : ?>
                            <a href="<?php echo $goto_url; ?>" target="_blank" rel="nofollow sponsored noopener">
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
                    <?php if ( $is_last ) : ?>
                        </tbody></table>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else : ?>
                <h6 class="no-info">Sem informação disponível</h6>
            <?php endif; ?>

            <?php
            // Theme's Native Pagination Blocks
            if ( isset( $cur_page, $total_pages ) && function_exists( 'futebol365_render_pagination' ) ):
                futebol365_render_pagination( $cur_page, $total_pages );
            endif;
            ?>

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

<script type="text/javascript">
( function () {
    var baseUrl = '<?php echo esc_url( $cal_base_url ); ?>';

    function editionVal( p ) {
        var el = document.getElementById( 'edition' );

        if ( el && el.value ) {
            if ( el.value.indexOf('http') === 0 ) {
                try {
                    var tempUrl = new URL( el.value );
                    if ( tempUrl.searchParams.has( 'edition' ) ) {
                        return tempUrl.searchParams.get( 'edition' );
                    }
                } catch(e) { }
            } else {
                return el.value;
            }
        }

        var urlEdition = p.get( 'edition' );
        return ( urlEdition && urlEdition.indexOf('http') === -1 )
            ? urlEdition
            : '<?php echo esc_js( $comp_edition ); ?>';
    }

    var teamSel = document.getElementById( 'id_team_participant' );
    if ( teamSel ) {
        teamSel.addEventListener( 'change', function () {
            var p = new URLSearchParams( window.location.search );

            p.set( 'edition', editionVal( p ) );
            p.set( 'team_participant', teamSel.value );

            if ( 'all_teams' === teamSel.value ) {
                p.delete( 'team_participant' );
            }

            // Delete pagina so the user isn't stuck on an empty page
            // when selecting a team that has fewer total matches.
            p.delete( 'pagina' );

            var qs = p.toString();
            window.location.href = qs ? ( baseUrl + '?' + qs ) : baseUrl;
        } );
    }
} )();
</script>

<?php
get_footer();
