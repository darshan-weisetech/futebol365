<?php
get_header();
global $wpdb;
$current_post_id = get_the_ID();
$current_section = 'vencedores';
$sections        = futebol365_get_competition_sections();

//$final_competition_id = get_post_meta( $current_post_id, 'django_competition_id', true );
$final_competition_id = get_legacy_competition_id( $current_post_id );

$competition_id = intval( $final_competition_id );
$comp_table     = $wpdb->prefix . 'competition_competition';
$cpw_table      = $wpdb->prefix . 'competition_competitionphasewinners';
$ts_table       = $wpdb->prefix . 'team_teamstep';
$team_table     = $wpdb->prefix . 'team_team';
$fc_table       = $wpdb->prefix . 'federation_federationcompetition';

$comp_row = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT federation_competition_id, edition FROM {$comp_table} WHERE id = %d",
        $competition_id
    ),
    OBJECT
);
$fed_comp_id = $comp_row ? intval( $comp_row->federation_competition_id ) : 0;

$fc_modality = '';
$fc_gender   = '';
$fc_step     = '';
if ( $fed_comp_id ) {
    $fc_row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT modality, gender, step FROM {$fc_table} WHERE id = %d",
            $fed_comp_id
        ),
        OBJECT
    );
    if ( $fc_row ) {
        $fc_modality = $fc_row->modality;
        $fc_gender   = $fc_row->gender;
        $fc_step     = $fc_row->step;
    }
}
$has_winners = (bool) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT EXISTS(
            SELECT 1 FROM {$comp_table} c
             WHERE c.federation_competition_id = %d AND CAST( c.is_closed AS CHAR ) IN ( '1', 't', 'true' )
               AND ( c.first_place_teamstep_id IS NOT NULL
                  OR c.second_place_teamstep_id IS NOT NULL
                  OR EXISTS( SELECT 1 FROM {$cpw_table} w WHERE w.competition_id = c.id ) )
        )",
        $fed_comp_id
    )
);



$list_type = ( isset( $_GET['list_type'] ) && 'winner_resume' === $_GET['list_type'] ) ? 'winner_resume' : 'winner_list';

if ( ! function_exists( 'f365_teamstep_name' ) ) {
    function f365_teamstep_name( $team_name, $step, $gender, $modality ) {
        static $step_map = array(
            'principal' => 'Principal', 'b_team' => 'B team', 'c_team' => 'C team',
            'sub_23' => 'S23', 'sub_22' => 'S22', 'sub_21' => 'S21', 'sub_20' => 'S20',
            'sub_19' => 'S19', 'sub_19_b' => 'S19 B', 'sub_18' => 'S18', 'sub_17' => 'S17', 'sub_17_b' => 'S17 B',
            'sub_16' => 'S16', 'sub_15' => 'S15', 'sub_15_b' => 'S15 B', 'sub_14' => 'S14',
            'sub_13' => 'S13', 'sub_13_b' => 'S13 B', 'sub_12' => 'S12', 'sub_12_b' => 'S12 B',
            'sub_11' => 'S11', 'sub_11_b' => 'S11 B', 'sub_10' => 'S10', 'sub_10_b' => 'S10 B',
            'sub_9' => 'S9', 'sub_8' => 'S8', 'sub_7' => 'S7', 'sub_6' => 'S6',
            'veterans' => 'Veterans', 'reservations' => 'Reservations',
        );
        $rest = '';
        if ( 'futsal' === $modality ) {
            $rest .= ' FS';
        }
        if ( 'female' === $gender ) {
            $rest .= ' FM';
        }
        if ( ! in_array( $step, array( 'principal', 'b_team', 'c_team', 'veterans', 'reservations' ), true ) ) {
            $rest .= ' ' . ( isset( $step_map[ $step ] ) ? $step_map[ $step ] : $step );
        }
        if ( 'b_team' === $step ) {
            $rest .= ' B';
        }
        if ( 'c_team' === $step ) {
            $rest .= ' C';
        }
        if ( 'veterans' === $step ) {
            $rest .= ' VET';
        }
        if ( 'reservations' === $step ) {
            $rest .= ' RES';
        }
        $rest = trim( $rest );
        return '' !== $rest ? $team_name . ' ' . $rest : $team_name;
    }
}

// TeamStep.get_absolute_url_awards()
if ( ! function_exists( 'f365_teamstep_awards_url' ) ) {
    function f365_teamstep_awards_url( $team_id, $edition, $modality, $gender, $step ) {
        return home_url( '/equipa/' . intval( $team_id ) . '/palmares/?' . http_build_query(
            array( 'edition' => $edition, 'modality' => $modality, 'gender' => $gender, 'step' => $step )
        ) );
    }
}

// CompetitionPhaseWinners._final_unicode()
if ( ! function_exists( 'f365_final_unicode' ) ) {
    function f365_final_unicode( $base, $phase_name, $group ) {
        if ( strlen( (string) $phase_name ) ) {
            $base .= ' [' . $phase_name . ']';
        }
        if ( null !== $group && '' !== $group ) {
            $base .= ' [' . $group . ']';
        }
        return $base;
    }
}

$winner_list_rows = array();   // winner_list mode
$phase_winners_by_comp = array();
$winner_teams = array();       // winner_resume mode

if ( $has_winners && 'winner_list' === $list_type ) {

    $winner_list_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT c.id, c.season, c.edition, c.first_place_teamstep_id, c.second_place_teamstep_id,
                    fts.team_id AS f_team_id, fts.step AS f_step, fts.gender AS f_gender, fts.modality AS f_modality, fts.edition AS f_edition,
                    ft.shortname AS f_name, ft.country_id AS f_country_id, ft.team_type AS f_team_type,
                    sts.team_id AS s_team_id, sts.step AS s_step, sts.gender AS s_gender, sts.modality AS s_modality, sts.edition AS s_edition,
                    st.shortname AS s_name, st.country_id AS s_country_id, st.team_type AS s_team_type
               FROM {$comp_table} c
               LEFT JOIN {$ts_table} fts ON c.first_place_teamstep_id = fts.id
               LEFT JOIN {$team_table} ft ON fts.team_id = ft.id
               LEFT JOIN {$ts_table} sts ON c.second_place_teamstep_id = sts.id
               LEFT JOIN {$team_table} st ON sts.team_id = st.id
              WHERE c.federation_competition_id = %d AND CAST( c.is_closed AS CHAR ) IN ( '1', 't', 'true' )
              ORDER BY c.edition DESC",
            $fed_comp_id
        ),
        OBJECT
    );

    // Phase winners for any rows that fall back to them.
    $phase_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT cpw.competition_id, cpw.competition_phase_name, cpw.`group` AS cpw_group, cpw.`order` AS cpw_order,
                    cpw.first_place_teamstep_id, fts.team_id AS f_team_id, fts.step AS f_step, fts.gender AS f_gender, fts.modality AS f_modality, fts.edition AS f_edition, ft.shortname AS f_name, ft.country_id AS f_country_id,
                    cpw.second_place_teamstep_id, sts.team_id AS s_team_id, sts.step AS s_step, sts.gender AS s_gender, sts.modality AS s_modality, sts.edition AS s_edition, st.shortname AS s_name, st.country_id AS s_country_id
               FROM {$cpw_table} cpw
               JOIN {$comp_table} c ON cpw.competition_id = c.id
               LEFT JOIN {$ts_table} fts ON cpw.first_place_teamstep_id = fts.id
               LEFT JOIN {$team_table} ft ON fts.team_id = ft.id
               LEFT JOIN {$ts_table} sts ON cpw.second_place_teamstep_id = sts.id
               LEFT JOIN {$team_table} st ON sts.team_id = st.id
              WHERE c.federation_competition_id = %d AND CAST( c.is_closed AS CHAR ) IN ( '1', 't', 'true' )
              ORDER BY cpw.competition_id, cpw.`order` ASC",
            $fed_comp_id
        ),
        OBJECT
    );
    
    foreach ( $phase_rows as $pr ) {
        $phase_winners_by_comp[ (int) $pr->competition_id ][] = $pr;
    }

} elseif ( $has_winners ) {

    $all_comps = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT c.id, c.season, c.first_place_teamstep_id,
                    fts.team_id AS f_team_id, ft.shortname AS f_name, ft.country_id AS f_country_id
               FROM {$comp_table} c
               LEFT JOIN {$ts_table} fts ON c.first_place_teamstep_id = fts.id
               LEFT JOIN {$team_table} ft ON fts.team_id = ft.id
              WHERE c.federation_competition_id = %d
              ORDER BY c.edition DESC",
            $fed_comp_id
        ),
        OBJECT
    );

    $all_phase = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT cpw.competition_id, cpw.competition_phase_name, cpw.`group` AS cpw_group, cpw.`order` AS cpw_order,
                    fts.team_id AS team_id, t.shortname AS team_name, t.country_id AS country_id, c.season
               FROM {$cpw_table} cpw
               JOIN {$comp_table} c ON cpw.competition_id = c.id
               LEFT JOIN {$ts_table} fts ON cpw.first_place_teamstep_id = fts.id
               LEFT JOIN {$team_table} t ON fts.team_id = t.id
              WHERE c.federation_competition_id = %d AND cpw.first_place_teamstep_id IS NOT NULL
              ORDER BY cpw.competition_id, cpw.`order` ASC",
            $fed_comp_id
        ),
        OBJECT
    );
    $phase_first_by_comp = array();
    foreach ( $all_phase as $ap ) {
        $phase_first_by_comp[ (int) $ap->competition_id ][] = $ap;
    }

    $teams = array(); // team_id => array(name, country_id, count)
    foreach ( $all_comps as $c ) {
        if ( null !== $c->f_team_id ) {
            $tid = (int) $c->f_team_id;
            if ( ! isset( $teams[ $tid ] ) ) {
                $teams[ $tid ] = array( 'name' => $c->f_name, 'country_id' => $c->f_country_id, 'count' => 0 );
            }
            $teams[ $tid ]['count']++;
        } elseif ( isset( $phase_first_by_comp[ (int) $c->id ] ) ) {
            foreach ( $phase_first_by_comp[ (int) $c->id ] as $pw ) {
                if ( null !== $pw->team_id ) {
                    $tid = (int) $pw->team_id;
                    if ( ! isset( $teams[ $tid ] ) ) {
                        $teams[ $tid ] = array( 'name' => $pw->team_name, 'country_id' => $pw->country_id, 'count' => 0 );
                    }
                    $teams[ $tid ]['count']++;
                }
            }
        }
    }

    // Per team: win seasons list (_get_win_list) for Total + Edições.
    foreach ( $teams as $tid => $info ) {
        $win_list = array();
        foreach ( $all_comps as $c ) {
            $cid          = (int) $c->id;
            $team_is_first = ( null !== $c->f_team_id && (int) $c->f_team_id === $tid );
            $team_in_phase = false;
            if ( isset( $phase_first_by_comp[ $cid ] ) ) {
                foreach ( $phase_first_by_comp[ $cid ] as $pw ) {
                    if ( null !== $pw->team_id && (int) $pw->team_id === $tid ) {
                        $team_in_phase = true;
                        break;
                    }
                }
            }
            if ( ! $team_is_first && ! $team_in_phase ) {
                continue;
            }
            if ( null !== $c->f_team_id ) {
                if ( ! in_array( $c->season, $win_list, true ) ) {
                    $win_list[] = $c->season;
                }
            } elseif ( isset( $phase_first_by_comp[ $cid ] ) ) {
                foreach ( $phase_first_by_comp[ $cid ] as $pw ) {
                    if ( null !== $pw->team_id && (int) $pw->team_id === $tid ) {
                        $s = f365_final_unicode( $pw->season, $pw->competition_phase_name, $pw->cpw_group );
                        if ( ! in_array( $s, $win_list, true ) ) {
                            $win_list[] = $s;
                        }
                    }
                }
            }
        }
        $teams[ $tid ]['num_wins'] = count( $win_list );
        $teams[ $tid ]['seasons']  = implode( ', ', $win_list );
    }

    // Sort by win count desc (stable; preserves first-seen order on ties — PHP 8 usort is stable).
    $winner_teams = $teams;
    uasort( $winner_teams, function ( $a, $b ) {
        return $b['count'] - $a['count'];
    } );
}

?>

<main id="primary" class="site-main single-article-page" <?php echo $current_post_id; ?>>

<?php
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

    <div class="ink-grid futebol365-container">

        <section class="left-column-block">

            <div class="breadcrumbs-f365">
                <div class="ink-navigation breadcrumbs">
                    <?php yoast_breadcrumb(); ?>
                </div>
            </div>

<?php if ( $has_winners ) : ?>

            <?php // dropdown-level-2-winners.html ?>
            <form class="ink-form ink-form-option" method="get" name="winner_header_form" id="winner-header-form">
                <div class="column-group">
                    <div class="control-group all-33 tiny-100 small-100">
                        <div class="control">
                            <select name="list_type" id="id_list_type">
                                <option value="winner_list" <?php selected( 'winner_list', $list_type ); ?>><?php esc_html_e( 'Lista de vencedores', 'futebol365' ); ?></option>
                                <option value="winner_resume" <?php selected( 'winner_resume', $list_type ); ?>><?php esc_html_e( 'Resumo de vencedores', 'futebol365' ); ?></option>
                            </select>
                        </div>
                    </div>
                </div>
            </form>
        <script>
            (function () {
                var sel = document.getElementById( 'id_list_type' );
                if ( ! sel ) { return; }
                sel.addEventListener( 'change', function () {
                    var ed     = document.getElementById( 'edition' );
                    var params = new URLSearchParams();
                    
                    if ( ed && ed.value ) {
                        try {
                            // If ed.value is a full URL, safely parse out the 'edition' query parameter
                            var edUrl = new URL( ed.value );
                            var edValue = edUrl.searchParams.get( 'edition' );
                            params.set( 'edition', edValue || ed.value );
                        } catch (e) {
                            // Fallback if it isn't a valid full URL
                            params.set( 'edition', ed.value );
                        }
                    }
                    
                    params.set( 'list_type', sel.value );
                    window.location.href = '<?php echo esc_url( home_url( "/competicao/{$fed_comp_id}/vencedores/" ) ); ?>?' + params.toString();
                } );
            })();
            </script>

    <?php if ( 'winner_list' === $list_type ) : ?>

            <h5 class="condensed-400 headerTitle blue"><?php esc_html_e( 'Lista de Vencedores', 'futebol365' ); ?></h5>
            <table class="ink-table ink-table-f365 alternating all-100">
                <thead>
                    <tr>
                        <th class="align-left all-33"><?php esc_html_e( 'Edição', 'futebol365' ); ?></th>
                        <th class="align-left"><?php esc_html_e( 'Vencedor', 'futebol365' ); ?></th>
                        <th class="align-left all-33 hide-tiny hide-small"><?php esc_html_e( 'Segundo Classificado', 'futebol365' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $winner_list_rows as $c ) : ?>
                        <tr>
                            <td class="align-left"><a href="<?php echo esc_url( home_url( "/competicao/{$fed_comp_id}/?edition={$c->edition}" ) ); ?>"><?php echo esc_html( $c->season ); ?></a></td>

                            <td class="align-left">
                                <?php if ( null !== $c->first_place_teamstep_id && null !== $c->f_team_id ) : ?>
                                    <?php
                                    $f_url  = f365_teamstep_awards_url( $c->f_team_id, $c->f_edition, $c->f_modality, $c->f_gender, $c->f_step );
                                    if ( 'national' === $c->f_team_type ) {
                                        $f_url .= '&competition=' . intval( $c->id );
                                    }
                                    $f_name = f365_teamstep_name( $c->f_name, $c->f_step, $c->f_gender, $c->f_modality );
                                    ?>
                                    <a href="<?php echo esc_url( $f_url ); ?>"><i class="hide-tiny flag-icon quarter-right-space flag-icon-<?php echo esc_attr( $c->f_country_id ); ?>"></i><?php echo esc_html( $f_name ); ?></a>
                                <?php else : ?>
                                    <?php
                                    $pw_list = isset( $phase_winners_by_comp[ (int) $c->id ] ) ? $phase_winners_by_comp[ (int) $c->id ] : array();
                                    $pw_n    = count( $pw_list );
                                    foreach ( $pw_list as $idx => $pw ) :
                                        if ( null === $pw->first_place_teamstep_id || null === $pw->f_team_id ) {
                                            continue;
                                        }
                                        $is_last  = ( $idx === $pw_n - 1 );
                                        $pw_url   = f365_teamstep_awards_url( $pw->f_team_id, $pw->f_edition, $pw->f_modality, $pw->f_gender, $pw->f_step );
                                        $pw_base  = f365_teamstep_name( $pw->f_name, $pw->f_step, $pw->f_gender, $pw->f_modality );
                                        $pw_label = f365_final_unicode( $pw_base, $pw->competition_phase_name, $pw->cpw_group );
                                        $extra_cl = $is_last ? '' : ' half-bottom-space';
                                        ?>
                                        <a href="<?php echo esc_url( $pw_url ); ?>"><i class="hide-tiny flag-icon quarter-right-space<?php echo $extra_cl; ?> flag-icon-<?php echo esc_attr( $pw->f_country_id ); ?>"></i><?php echo esc_html( $pw_label ); ?></a>
                                        <?php if ( ! $is_last ) : ?><br/><?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>

                            <td class="align-left hide-tiny hide-small">
                                <?php if ( null !== $c->second_place_teamstep_id && null !== $c->s_team_id ) : ?>
                                    <?php
                                    $s_url  = f365_teamstep_awards_url( $c->s_team_id, $c->s_edition, $c->s_modality, $c->s_gender, $c->s_step );
                                    if ( 'national' === $c->s_team_type ) {
                                        $s_url .= '&competition=' . intval( $c->id );
                                    }
                                    $s_name = f365_teamstep_name( $c->s_name, $c->s_step, $c->s_gender, $c->s_modality );
                                    ?>
                                    <a href="<?php echo esc_url( $s_url ); ?>"><i class="hide-tiny flag-icon quarter-right-space flag-icon-<?php echo esc_attr( $c->s_country_id ); ?>"></i><?php echo esc_html( $s_name ); ?></a>
                                <?php else : ?>
                                    <?php
                                    $pw_list = isset( $phase_winners_by_comp[ (int) $c->id ] ) ? $phase_winners_by_comp[ (int) $c->id ] : array();
                                    $pw_n    = count( $pw_list );
                                    foreach ( $pw_list as $idx => $pw ) :
                                        if ( null === $pw->second_place_teamstep_id || null === $pw->s_team_id ) {
                                            continue;
                                        }
                                        $is_last  = ( $idx === $pw_n - 1 );
                                        $pw_url   = f365_teamstep_awards_url( $pw->s_team_id, $pw->s_edition, $pw->s_modality, $pw->s_gender, $pw->s_step );
                                        $pw_base  = f365_teamstep_name( $pw->s_name, $pw->s_step, $pw->s_gender, $pw->s_modality );
                                        $pw_label = f365_final_unicode( $pw_base, $pw->competition_phase_name, $pw->cpw_group );
                                        $extra_cl = $is_last ? '' : ' half-bottom-space';
                                        ?>
                                        <a href="<?php echo esc_url( $pw_url ); ?>"><i class="hide-tiny flag-icon quarter-right-space<?php echo $extra_cl; ?> flag-icon-<?php echo esc_attr( $pw->s_country_id ); ?>"></i><?php echo esc_html( $pw_label ); ?></a>
                                        <?php if ( ! $is_last ) : ?><br/><?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

    <?php else : ?>

            <h5 class="condensed-400 headerTitle blue"><?php esc_html_e( 'Resumo de Vencedores', 'futebol365' ); ?></h5>
            <table class="ink-table ink-table-f365 alternating all-100">
                <thead>
                    <tr>
                        <th class="align-left all-30"><?php esc_html_e( 'Equipa', 'futebol365' ); ?></th>
                        <th class="align-center all-10"><?php esc_html_e( 'Total', 'futebol365' ); ?></th>
                        <th class="align-left"><?php esc_html_e( 'Edições', 'futebol365' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $winner_teams as $tid => $team ) : ?>
                        <?php
                        $team_url = home_url( '/equipa/' . intval( $tid ) . '/palmares/?' . http_build_query(
                            array( 'modality' => $fc_modality, 'gender' => $fc_gender, 'step' => $fc_step )
                        ) );
                        ?>
                        <tr>
                            <td class="align-left">
                                <a href="<?php echo esc_url( $team_url ); ?>"><i class="hide-tiny hide-small flag-icon quarter-right-space flag-icon-<?php echo esc_attr( $team['country_id'] ); ?>"></i><?php echo esc_html( $team['name'] ); ?></a>
                            </td>
                            <td class="align-center"><b><?php echo esc_html( $team['num_wins'] ); ?></b></td>
                            <td class="align-left"><?php echo esc_html( $team['seasons'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

    <?php endif; ?>

<?php else : ?>
    <h5 class="no-info"><?php esc_html_e( 'Sem informação disponível', 'futebol365' ); ?></h5>
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