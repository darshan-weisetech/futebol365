
<?php
/**
 * Team Histórico — /equipa/{id}/historico/   (?list_type=&step=&gender=&modality=)
 *
 * Relational port (NO wp_postmeta) of get_team_history + history/{players,coaches,
 * presidents,competitions}.html. Sorted by Épocas (distinct editions) DESC, 30/page,
 * matching the live view (sorted(...key=years, reverse=True)).
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;
$current_post_id = get_the_ID();
//$final_competition_id = get_post_meta( $current_post_id, 'django_competition_id', true );
$competition_in = get_legacy_competition_id( $current_post_id );

if ( ! function_exists( 'f365_hist_flag' ) ) {
    function f365_hist_flag( int $country_id, string $title = '' ): string {
        if ( ! $country_id ) {
            return '';
        }
        return '<i class="hide-tiny flag-icon quarter-right-space flag-icon-' . (int) $country_id . '" title="' . esc_attr( $title ) . '"></i>';
    }
}

/**
 * Per-teamstep win counts for the team (overall winner + phase winners),
 * optionally constrained to step/gender/modality. Returns [ teamstep_id => wins ].
 */
if ( ! function_exists( 'f365_hist_team_wins_by_teamstep' ) ) {
    function f365_hist_team_wins_by_teamstep( int $django_team, string $step, string $gender, string $modality, bool $with_dims ): array {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $dims   = $with_dims ? 'AND ts.step = %s AND ts.gender = %s AND ts.modality = %s' : '';
        $args   = $with_dims ? [ $django_team, $step, $gender, $modality ] : [ $django_team ];

        $out = [];
        $lvl = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.first_place_teamstep_id AS ts_id, COUNT(*) AS won
                 FROM {$prefix}competition_competition c
                 INNER JOIN {$prefix}team_teamstep ts ON ts.id = c.first_place_teamstep_id
                 WHERE ts.team_id = %d {$dims}
                 GROUP BY c.first_place_teamstep_id",
                $args
            )
        );
        foreach ( (array) $lvl as $r ) {
            $out[ (int) $r->ts_id ] = (int) $r->won;
        }

        $ph = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT cpw.first_place_teamstep_id AS ts_id, COUNT(*) AS won
                 FROM {$prefix}competition_competitionphasewinners cpw
                 INNER JOIN {$prefix}competition_competition c ON c.id = cpw.competition_id AND c.first_place_teamstep_id IS NULL
                 INNER JOIN {$prefix}team_teamstep ts ON ts.id = cpw.first_place_teamstep_id
                 WHERE ts.team_id = %d {$dims}
                 GROUP BY cpw.first_place_teamstep_id",
                $args
            )
        );
        foreach ( (array) $ph as $r ) {
            $id         = (int) $r->ts_id;
            $out[ $id ] = ( $out[ $id ] ?? 0 ) + (int) $r->won;
        }
        return $out;
    }
}

/* ============================================================
 * Resolve team + filters
 * ============================================================ */

get_header();

$post_id = $args['post_id'] ?? get_the_ID();
if ( function_exists( 'f365_get_current_team_post' ) ) {
    $t = f365_get_current_team_post();
    if ( $t ) {
        $post_id = $t->ID;
    }
}
$post_id = (int) $post_id;

$prefix      = $wpdb->prefix;
//$django_team = (int) get_post_meta( $post_id, 'django_id', true );
$django_team = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT legacy_id
         FROM {$wpdb->posts}
         WHERE ID = %d",
        $post_id
    )
);

$step      = isset( $_GET['step'] ) ? sanitize_text_field( wp_unslash( $_GET['step'] ) ) : 'principal';
$modality  = isset( $_GET['modality'] ) ? sanitize_text_field( wp_unslash( $_GET['modality'] ) ) : 'football';
$gender    = isset( $_GET['gender'] ) ? sanitize_text_field( wp_unslash( $_GET['gender'] ) ) : 'male';
$list_type = isset( $_GET['list_type'] ) ? sanitize_key( wp_unslash( $_GET['list_type'] ) ) : 'players';
if ( ! in_array( $list_type, [ 'players', 'coaches', 'presidents', 'competitions' ], true ) ) {
    $list_type = 'players';
}

$team_type = 'club';
if ( $django_team ) {
    $tt = $wpdb->get_var( $wpdb->prepare( "SELECT team_type FROM {$prefix}team_team WHERE id = %d", $django_team ) );
    if ( $tt ) {
        $team_type = (string) $tt;
    }
}
$is_national = ( 'national' === $team_type );

$current_section = get_query_var( 'section', 'historico' ) ?: 'historico';
$sections        = function_exists( 'futebol365_get_team_sections' ) ? futebol365_get_team_sections() : [];

$paged = max( 1, (int) ( $_GET['p_hist'] ?? 1 ) );
$per_page = 30;
$offset = ( $paged - 1 ) * $per_page;

/* ============================================================
 * OPTIMIZATION: Object Cache (wp_cache) Implementation
 * ============================================================ */
$cache_key   = sprintf('f365_hist_%d_%s_%s_%s_%s_p%d', $django_team, $list_type, $step, $gender, $modality, $paged);
$cache_group = 'f365_team_history';
$cached_payload = wp_cache_get($cache_key, $cache_group);

if ( false === $cached_payload ) {
    $rows  = [];
    $total = 0;

    if ( $django_team ) {
        /* ============================================================
         * PLAYERS SUB-TAB
         * ============================================================ */
        if ( 'players' === $list_type ) {
            $total = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT sq.player_id)
                     FROM {$prefix}team_squad sq
                     INNER JOIN {$prefix}team_teamstep ts ON ts.id = sq.team_id
                     WHERE ts.team_id = %d AND ts.step = %s AND ts.gender = %s AND ts.modality = %s",
                    $django_team, $step, $gender, $modality
                )
            );

            $res = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT sq.player_id AS pid, COUNT(DISTINCT ts.edition) AS yrs
                     FROM {$prefix}team_squad sq
                     INNER JOIN {$prefix}team_teamstep ts ON ts.id = sq.team_id
                     WHERE ts.team_id = %d AND ts.step = %s AND ts.gender = %s AND ts.modality = %s
                     GROUP BY sq.player_id
                     ORDER BY yrs DESC, sq.player_id ASC
                     LIMIT %d OFFSET %d",
                    $django_team, $step, $gender, $modality, $per_page, $offset
                )
            );

            $ids = array_map( static fn( $r ) => (int) $r->pid, (array) $res );
            $pmap = [];
            $titles = array_fill_keys( $ids, 0 );

            if ( $ids ) {
                $in = implode( ',', array_map( 'absint', $ids ) );
                $names = $wpdb->get_results(
                    "SELECT pl.id AS pid, pe.shortname AS name, pe.nationality_id AS nat_id, co.shortname AS nat_name
                     FROM {$prefix}person_player pl
                     INNER JOIN {$prefix}person_person pe ON pe.id = pl.person_id
                     LEFT JOIN {$prefix}country_country co ON co.id = pe.nationality_id
                     WHERE pl.id IN ($in)"
                );
                foreach ( (array) $names as $n ) {
                    $pmap[ (int) $n->pid ] = $n;
                }

                $won_by_ts = f365_hist_team_wins_by_teamstep( $django_team, $step, $gender, $modality, true );
                if ( $won_by_ts ) {
                    $ts_in  = implode( ',', array_map( 'absint', array_keys( $won_by_ts ) ) );
                    $mem    = $wpdb->get_results(
                        "SELECT DISTINCT sq.player_id AS pid, sq.team_id AS ts_id
                         FROM {$prefix}team_squad sq
                         WHERE sq.team_id IN ($ts_in) AND sq.player_id IN ($in)"
                    );
                    foreach ( (array) $mem as $m ) {
                        $pp = (int) $m->pid;
                        $ti = (int) $m->ts_id;
                        if ( isset( $won_by_ts[ $ti ] ) ) {
                            $titles[ $pp ] += $won_by_ts[ $ti ];
                        }
                    }
                }
            }

            foreach ( (array) $res as $r ) {
                $pid = (int) $r->pid;
                $p   = $pmap[ $pid ] ?? null;
                $rows[] = [
                    'id'       => $pid,
                    'name'     => $p ? $p->name : ( '#' . $pid ),
                    'url'      => function_exists( 'f365_player_url' ) ? f365_player_url( $pid ) : user_trailingslashit( home_url( 'jogador/' . $pid ) ),
                    'years'    => (int) $r->yrs,
                    'nat_id'   => $p ? (int) $p->nat_id : 0,
                    'nat_name' => $p ? (string) $p->nat_name : '',
                    'titles'   => $titles[ $pid ] ?? 0,
                ];
            }

        /* ============================================================
         * COACHES SUB-TAB
         * ============================================================ */
        } elseif ( 'coaches' === $list_type ) {
            $total = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT tc.coach_id)
                     FROM {$prefix}team_teamstepcoach tc
                     INNER JOIN {$prefix}team_teamstep ts ON ts.id = tc.team_id
                     WHERE ts.team_id = %d AND ts.step = %s AND ts.gender = %s AND ts.modality = %s AND tc.coach_type = 'principal'",
                    $django_team, $step, $gender, $modality
                )
            );

            $res = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT tc.coach_id AS cid, COUNT(DISTINCT ts.edition) AS yrs
                     FROM {$prefix}team_teamstepcoach tc
                     INNER JOIN {$prefix}team_teamstep ts ON ts.id = tc.team_id
                     WHERE ts.team_id = %d AND ts.step = %s AND ts.gender = %s AND ts.modality = %s AND tc.coach_type = 'principal'
                     GROUP BY tc.coach_id
                     ORDER BY yrs DESC, tc.coach_id ASC
                     LIMIT %d OFFSET %d",
                    $django_team, $step, $gender, $modality, $per_page, $offset
                )
            );

            $ids  = array_map( static fn( $r ) => (int) $r->cid, (array) $res );
            $cmap = [];
            $titles = array_fill_keys( $ids, 0 );

            if ( $ids ) {
                $in = implode( ',', array_map( 'absint', $ids ) );
                $names = $wpdb->get_results(
                    "SELECT ch.id AS cid, pe.shortname AS name, pe.nationality_id AS nat_id, co.shortname AS nat_name
                     FROM {$prefix}person_coach ch
                     INNER JOIN {$prefix}person_person pe ON pe.id = ch.person_id
                     LEFT JOIN {$prefix}country_country co ON co.id = pe.nationality_id
                     WHERE ch.id IN ($in)"
                );
                foreach ( (array) $names as $n ) {
                    $cmap[ (int) $n->cid ] = $n;
                }

                $won_by_ts = f365_hist_team_wins_by_teamstep( $django_team, $step, $gender, $modality, true );
                if ( $won_by_ts ) {
                    $ts_in  = implode( ',', array_map( 'absint', array_keys( $won_by_ts ) ) );
                    $mem    = $wpdb->get_results(
                        "SELECT DISTINCT tc.coach_id AS cid, tc.team_id AS ts_id
                         FROM {$prefix}team_teamstepcoach tc
                         WHERE tc.coach_type = 'principal' AND tc.team_id IN ($ts_in) AND tc.coach_id IN ($in)"
                    );
                    foreach ( (array) $mem as $m ) {
                        $cc = (int) $m->cid;
                        $ti = (int) $m->ts_id;
                        if ( isset( $won_by_ts[ $ti ] ) ) {
                            $titles[ $cc ] += $won_by_ts[ $ti ];
                        }
                    }
                }
            }

            foreach ( (array) $res as $r ) {
                $cid = (int) $r->cid;
                $c   = $cmap[ $cid ] ?? null;
                $rows[] = [
                    'id'       => $cid,
                    'name'     => $c ? $c->name : ( '#' . $cid ),
                    'url'      => function_exists( 'f365_coach_url' ) ? f365_coach_url( $cid ) : user_trailingslashit( home_url( 'treinador/' . $cid ) ),
                    'years'    => (int) $r->yrs,
                    'nat_id'   => $c ? (int) $c->nat_id : 0,
                    'nat_name' => $c ? (string) $c->nat_name : '',
                    'titles'   => $titles[ $cid ] ?? 0,
                ];
            }

        /* ============================================================
         * PRESIDENTS SUB-TAB
         * ============================================================ */
        } elseif ( 'presidents' === $list_type ) {
            $total = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT tp.president_id)
                     FROM {$prefix}team_teampresident tp
                     WHERE tp.team_id = %d AND tp.president_type = 'president'",
                    $django_team
                )
            );

            $res = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT tp.president_id AS pid, COUNT(DISTINCT tp.edition) AS yrs
                     FROM {$prefix}team_teampresident tp
                     WHERE tp.team_id = %d AND tp.president_type = 'president'
                     GROUP BY tp.president_id
                     ORDER BY yrs DESC, tp.president_id ASC
                     LIMIT %d OFFSET %d",
                    $django_team, $per_page, $offset
                )
            );

            $ids   = array_map( static fn( $r ) => (int) $r->pid, (array) $res );
            $prmap = [];
            $titles = array_fill_keys( $ids, 0 );

            if ( $ids ) {
                $in = implode( ',', array_map( 'absint', $ids ) );
                $names = $wpdb->get_results(
                    "SELECT pr.id AS pid, pe.shortname AS name, pe.nationality_id AS nat_id, co.shortname AS nat_name
                     FROM {$prefix}person_president pr
                     INNER JOIN {$prefix}person_person pe ON pe.id = pr.person_id
                     LEFT JOIN {$prefix}country_country co ON co.id = pe.nationality_id
                     WHERE pr.id IN ($in)"
                );
                foreach ( (array) $names as $n ) {
                    $prmap[ (int) $n->pid ] = $n;
                }

                $won_by_ts = f365_hist_team_wins_by_teamstep( $django_team, $step, $gender, $modality, false );
                $won_by_ed = [];
                if ( $won_by_ts ) {
                    $ts_in   = implode( ',', array_map( 'absint', array_keys( $won_by_ts ) ) );
                    $ed_rows = $wpdb->get_results(
                        "SELECT id AS ts_id, edition FROM {$prefix}team_teamstep WHERE id IN ($ts_in)"
                    );
                    foreach ( (array) $ed_rows as $er ) {
                        $ed             = (string) $er->edition;
                        $won_by_ed[ $ed ] = ( $won_by_ed[ $ed ] ?? 0 ) + ( $won_by_ts[ (int) $er->ts_id ] ?? 0 );
                    }
                }

                $pres_eds = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT president_id AS pid, edition
                         FROM {$prefix}team_teampresident
                         WHERE team_id = %d AND president_type = 'president' AND president_id IN ($in)",
                        $django_team
                    )
                );
                $seen = [];
                foreach ( (array) $pres_eds as $pe ) {
                    $pp  = (int) $pe->pid;
                    $ed  = (string) $pe->edition;
                    $key = $pp . '|' . $ed;
                    if ( isset( $seen[ $key ] ) ) {
                        continue;
                    }
                    $seen[ $key ]  = true;
                    $titles[ $pp ] += ( $won_by_ed[ $ed ] ?? 0 );
                }
            }

            foreach ( (array) $res as $r ) {
                $pid = (int) $r->pid;
                $p   = $prmap[ $pid ] ?? null;
                $rows[] = [
                    'id'       => $pid,
                    'name'     => $p ? $p->name : ( '#' . $pid ),
                    'url'      => function_exists( 'f365_president_url' ) ? f365_president_url( $pid ) : user_trailingslashit( home_url( 'dirigente/' . $pid ) ),
                    'years'    => (int) $r->yrs,
                    'nat_id'   => $p ? (int) $p->nat_id : 0,
                    'nat_name' => $p ? (string) $p->nat_name : '',
                    'titles'   => $titles[ $pid ] ?? 0,
                ];
            }

        /* ============================================================
         * COMPETITIONS SUB-TAB
         * ============================================================ */
        } elseif ( 'competitions' === $list_type ) {
            $total = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT c.federation_competition_id)
                     FROM {$prefix}competition_participant pa
                     INNER JOIN {$prefix}team_teamstep ts ON ts.id = pa.team_id
                     INNER JOIN {$prefix}competition_competition c ON c.id = pa.competition_id
                     WHERE ts.team_id = %d AND ts.step = %s AND ts.gender = %s AND ts.modality = %s",
                    $django_team, $step, $gender, $modality
                )
            );

            $res = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT fc.id AS fid, fc.shortname AS name, f.country_id AS country_id,
                            COUNT(DISTINCT c.edition) AS presencas
                     FROM {$prefix}competition_participant pa
                     INNER JOIN {$prefix}team_teamstep ts ON ts.id = pa.team_id
                     INNER JOIN {$prefix}competition_competition c ON c.id = pa.competition_id
                     INNER JOIN {$prefix}federation_federationcompetition fc ON fc.id = c.federation_competition_id
                     LEFT JOIN {$prefix}federation_federation f ON f.id = fc.federation_id
                     WHERE ts.team_id = %d AND ts.step = %s AND ts.gender = %s AND ts.modality = %s
                     GROUP BY fc.id, fc.shortname, f.country_id, fc.`order`
                     ORDER BY fc.`order` ASC, fc.id ASC
                     LIMIT %d OFFSET %d",
                    $django_team, $step, $gender, $modality, $per_page, $offset
                )
            );

            $titles_by_fc = [];
            if ( $res ) {
                $lvl = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT c.federation_competition_id AS fid, COUNT(*) AS won
                         FROM {$prefix}competition_competition c
                         INNER JOIN {$prefix}team_teamstep ts ON ts.id = c.first_place_teamstep_id
                         WHERE ts.team_id = %d AND ts.step = %s AND ts.gender = %s AND ts.modality = %s
                         GROUP BY c.federation_competition_id",
                        $django_team, $step, $gender, $modality
                    )
                );
                foreach ( (array) $lvl as $w ) {
                    $titles_by_fc[ (int) $w->fid ] = (int) $w->won;
                }
                $ph = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT c.federation_competition_id AS fid, COUNT(*) AS won
                         FROM {$prefix}competition_competitionphasewinners cpw
                         INNER JOIN {$prefix}competition_competition c ON c.id = cpw.competition_id AND c.first_place_teamstep_id IS NULL
                         INNER JOIN {$prefix}team_teamstep ts ON ts.id = cpw.first_place_teamstep_id
                         WHERE ts.team_id = %d AND ts.step = %s AND ts.gender = %s AND ts.modality = %s
                         GROUP BY c.federation_competition_id",
                        $django_team, $step, $gender, $modality
                    )
                );
                foreach ( (array) $ph as $w ) {
                    $fid                  = (int) $w->fid;
                    $titles_by_fc[ $fid ] = ( $titles_by_fc[ $fid ] ?? 0 ) + (int) $w->won;
                }
            }

            foreach ( (array) $res as $r ) {
                $fid    = (int) $r->fid;
                $rows[] = [
                    'id'         => $fid,
                    'name'       => (string) $r->name,
                    'url'        => function_exists( 'f365_competition_url' ) ? f365_competition_url( $fid ) : user_trailingslashit( home_url( 'competicao/' . $fid ) ),
                    'country_id' => (int) $r->country_id,
                    'presencas'  => (int) $r->presencas,
                    'titles'     => $titles_by_fc[ $fid ] ?? 0,
                ];
            }
        }
    }

    $cached_payload = [
        'rows'  => $rows,
        'total' => $total,
    ];
    wp_cache_set( $cache_key, $cached_payload, $cache_group, DAY_IN_SECONDS );
}

$rows        = $cached_payload['rows'] ?? [];
$total       = $cached_payload['total'] ?? 0;
$total_pages = (int) ceil( $total / $per_page );
$count_label = $is_national ? 'Competições' : 'Épocas';
$show_titles = in_array( $list_type, [ 'players', 'coaches', 'presidents' ], true );

$base_filters = array_filter(
    [
        'step'     => $step,
        'modality' => $modality,
        'gender'   => $gender,
    ]
);
$list_tabs = [
    'players'      => 'Jogadores',
    'coaches'      => 'Treinadores',
    'presidents'   => 'Presidentes',
    'competitions' => 'Competições',
];
?>

<main id="primary" class="site-main single-article-page">

    <?php
    if ( $sections ) {
        get_template_part(
            'template-parts/team/f365-team-header',
            null,
            [
                'post_id'         => $post_id,
                'current_section' => $current_section,
                'sections'        => $sections,
                'team_id'         => $django_team,
            ]
        );
    }
    ?>

    <div class="ink-grid futebol365-container">
        <section class="left-column-block">

            <div class="breadcrumbs-f365">
                <div class="ink-navigation breadcrumbs">
                    <?php if ( function_exists( 'yoast_breadcrumb' ) ) { yoast_breadcrumb(); } ?>
                </div>
            </div>

            <h3 class="condensed-400 headerTitle blue"><?php echo esc_html( $list_tabs[ $list_type ] ); ?></h3>

            <?php if ( empty( $rows ) ) : ?>

                <h6 class="no-info">Sem informação disponível</h6>

            <?php elseif ( 'competitions' === $list_type ) : ?>

                <table class="ink-table alternating ink-table-f365 all-100">
                    <thead>
                        <tr>
                            <th class="align-left">Competição</th>
                            <th class="align-center all-15">Presenças</th>
                            <th class="align-center all-15">Títulos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $r ) : ?>
                            <tr>
                                <td class="align-left">
                                    <?php echo f365_hist_flag( (int) $r['country_id'], $r['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <a href="<?php echo esc_url( $r['url'] ); ?>"><?php echo esc_html( $r['name'] ); ?></a>
                                </td>
                                <td class="align-center"><?php echo (int) $r['presencas']; ?></td>
                                <td class="align-center"><?php echo (int) $r['titles']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <?php else : ?>

                <table class="ink-table alternating ink-table-f365 all-100">
                    <thead>
                        <tr>
                            <th class="align-left">Nome</th>
                            <th class="align-center all-20"><?php echo esc_html( $count_label ); ?></th>
                            <th class="align-left all-35 hide-tiny hide-small">Nacionalidade</th>
                            <?php if ( $show_titles ) : ?><th class="align-center all-20">Títulos</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $r ) : ?>
                            <tr>
                                <td class="align-left">
                                    <?php echo f365_hist_flag( (int) $r['nat_id'], $r['nat_name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <a href="<?php echo esc_url( $r['url'] ); ?>"><?php echo esc_html( $r['name'] ); ?></a>
                                </td>
                                <td class="align-center"><?php echo (int) $r['years']; ?></td>
                                <td class="align-left hide-tiny hide-small">
                                    <?php echo f365_hist_flag( (int) $r['nat_id'], $r['nat_name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php echo esc_html( $r['nat_name'] ); ?>
                                </td>
                                <?php if ( $show_titles ) : ?><td class="align-center"><?php echo (int) ( $r['titles'] ?? 0 ); ?></td><?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <?php endif; ?>

            <?php
            if ( $total_pages > 1 ) {
                $base_path = strtok( $_SERVER['REQUEST_URI'] ?? '', '?' );

                $page_url = static function ( int $n ) use ( $base_path, $step, $modality, $gender, $competition_in, $list_type ) {
                    $q = [
                        'list_type'   => $list_type,
                        'step'        => $step,
                        'modality'    => $modality,
                        'gender'      => $gender,
                        'competition' => $competition_in ? $competition_in : 'all',
                    ];
                    if ( $n > 1 ) {
                        $q['p_hist'] = $n;
                    }
                    return esc_url( add_query_arg( $q, $base_path ) );
                };

                $window = [];
                for ( $i = 1; $i <= $total_pages; $i++ ) {
                    if ( $i <= 2 || $i > $total_pages - 2 || abs( $i - $paged ) <= 2 ) {
                        $window[] = $i;
                    }
                }

                echo '<div class="paginacao-new"><nav class="ink-navigation"><ul class="pagination">';

                if ( $paged > 1 ) {
                    echo '<li class="hide-tiny hide-small"><a class="prev page-numbers" href="' . $page_url( $paged - 1 ) . '">&laquo; Anterior</a></li>';
                }

                $prev = 0;
                foreach ( $window as $i ) {
                    if ( $prev && $i > $prev + 1 ) {
                        echo '<li><a class="page-numbers dots">&hellip;</a></li>';
                    }
                    if ( $i === $paged ) {
                        echo '<li class="active"><span class="page-numbers active">' . (int) $i . '</span></li>';
                    } else {
                        echo '<li><a class="page-numbers" href="' . $page_url( $i ) . '">' . (int) $i . '</a></li>';
                    }
                    $prev = $i;
                }

                if ( $paged < $total_pages ) {
                    echo '<li class="hide-tiny hide-small"><a class="next page-numbers" href="' . $page_url( $paged + 1 ) . '">Seguinte &raquo;</a></li>';
                }

                echo '</ul></nav></div>';
            }
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

<?php
get_footer();
