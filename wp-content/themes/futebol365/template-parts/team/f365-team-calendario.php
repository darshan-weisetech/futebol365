<?php
/**
 * Team Calendário — /equipa/{id}/calendario/   (?edition=&step=&gender=&modality=&paged=)
 *
 * Fetches from the migrated RELATIONAL tables (wp_-prefixed), NOT wp_postmeta.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

/* ============================================================
 * Match result helpers (row-based; penalties → extra → regular)
 * ============================================================ */

if ( ! function_exists( 'f365_cal_set' ) ) {
    function f365_cal_set( $v ): bool {
        return null !== $v && '' !== $v;
    }
}
if ( ! function_exists( 'f365_cal_has_finished' ) ) {
    function f365_cal_has_finished( object $m ): bool {
        return 'finished' === (string) $m->situation && f365_cal_set( $m->home_score ) && f365_cal_set( $m->visitor_score );
    }
}
if ( ! function_exists( 'f365_cal_home_winner' ) ) {
    function f365_cal_home_winner( object $m ): bool {
        if ( f365_cal_set( $m->pen_home ) && f365_cal_set( $m->pen_visitor ) ) {
            return (int) $m->pen_home > (int) $m->pen_visitor;
        }
        if ( f365_cal_set( $m->home_extra ) && f365_cal_set( $m->visitor_extra ) ) {
            return (int) $m->home_extra > (int) $m->visitor_extra;
        }
        if ( f365_cal_set( $m->home_score ) && f365_cal_set( $m->visitor_score ) ) {
            return (int) $m->home_score > (int) $m->visitor_score;
        }
        return false;
    }
}
if ( ! function_exists( 'f365_cal_visitor_winner' ) ) {
    function f365_cal_visitor_winner( object $m ): bool {
        if ( f365_cal_set( $m->pen_home ) && f365_cal_set( $m->pen_visitor ) ) {
            return (int) $m->pen_home < (int) $m->pen_visitor;
        }
        if ( f365_cal_set( $m->home_extra ) && f365_cal_set( $m->visitor_extra ) ) {
            return (int) $m->home_extra < (int) $m->visitor_extra;
        }
        if ( f365_cal_set( $m->home_score ) && f365_cal_set( $m->visitor_score ) ) {
            return (int) $m->home_score < (int) $m->visitor_score;
        }
        return false;
    }
}
if ( ! function_exists( 'f365_cal_result' ) ) {
    function f365_cal_result( object $m ): string {
        switch ( (string) $m->situation ) {
            case 'delayed':
                return 'ADI';
            case 'suspended':
                return 'SUS';
            case 'abrogated':
                return 'ANU';
        }
        if ( f365_cal_set( $m->pen_home ) && f365_cal_set( $m->pen_visitor ) ) {
            return sprintf( '%s - %s g.p', (int) $m->pen_home, (int) $m->pen_visitor );
        }
        if ( f365_cal_set( $m->home_extra ) && f365_cal_set( $m->visitor_extra ) ) {
            return sprintf( '%s - %s a.p', (int) $m->home_extra, (int) $m->visitor_extra );
        }
        if ( f365_cal_set( $m->home_score ) && f365_cal_set( $m->visitor_score ) ) {
            return sprintf( '%s - %s', (int) $m->home_score, (int) $m->visitor_score );
        }
        return 'vs';
    }
}
if ( ! function_exists( 'f365_cal_color' ) ) {
    function f365_cal_color( object $m, int $our_ts ): string {
        if ( ! f365_cal_has_finished( $m ) ) {
            return '';
        }
        $home_w = f365_cal_home_winner( $m );
        $vis_w  = f365_cal_visitor_winner( $m );
        if ( ! $home_w && ! $vis_w ) {
            return 'draw';
        }
        if ( ( $home_w && (int) $m->home_ts === $our_ts ) || ( $vis_w && (int) $m->visitor_ts === $our_ts ) ) {
            return 'win';
        }
        return 'lost';
    }
}
if ( ! function_exists( 'f365_cal_match_video_ids' ) ) {
    /**
     * Of the given DJANGO match ids, which have at least one video.
     */
    function f365_cal_match_video_ids( array $match_ids ): array {
        $match_ids = array_values( array_unique( array_filter( array_map( 'intval', $match_ids ) ) ) );
        if ( empty( $match_ids ) ) {
            return [];
        }

        // Cache video results based on the batch of IDs to save DB hits on pagination loops
        $cache_key = 'f365_vid_' . md5( implode( '_', $match_ids ) );
        $out = wp_cache_get( $cache_key, 'f365_calendar' );
        if ( false !== $out ) {
            return $out;
        }

        global $wpdb;
        $in   = implode( ',', $match_ids );
        $rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            "SELECT DISTINCT match_id FROM {$wpdb->prefix}multimedia_video WHERE match_id IN ($in)"
        );

        $out = [];
        foreach ( (array) $rows as $mid ) {
            $out[ (int) $mid ] = true;
        }
        wp_cache_set( $cache_key, $out, 'f365_calendar', DAY_IN_SECONDS ); // Cache for 24 hours
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

$step     = isset( $_GET['step'] ) ? sanitize_text_field( wp_unslash( $_GET['step'] ) ) : 'principal';
$gender   = isset( $_GET['gender'] ) ? sanitize_text_field( wp_unslash( $_GET['gender'] ) ) : 'male';
$modality = isset( $_GET['modality'] ) ? sanitize_text_field( wp_unslash( $_GET['modality'] ) ) : 'football';
$edition  = isset( $_GET['edition'] ) && '' !== $_GET['edition'] ? sanitize_text_field( wp_unslash( $_GET['edition'] ) ) : '';

// 1. Cache the default Edition resolution
if ( '' === $edition && $django_team ) {
    $edition_cache_key = "f365_ed_{$django_team}_{$step}_{$gender}_{$modality}";
    $cache_group       = 'futebol365_transfers';
    $edition           = wp_cache_get( $edition_cache_key, $cache_group );

    if ( false === $edition ) {
        // Optimized query: Removed heavy (edition + 0) cast. Relying on alphanumeric standard DESC order.
        $edition = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT edition FROM {$prefix}team_teamstep
                 WHERE team_id = %d AND step = %s AND gender = %s AND modality = %s
                 ORDER BY edition DESC LIMIT 1",
                $django_team, $step, $gender, $modality
            )
        );

        wp_cache_set( $edition_cache_key, $edition, $cache_group, DAY_IN_SECONDS );
    }
}

if ( '' === $edition ) {
    $edition = gmdate( 'Y' );
}

$current_section = get_query_var( 'section', 'calendario' ) ?: 'calendario';
$sections        = function_exists( 'futebol365_get_team_sections' ) ? futebol365_get_team_sections() : [];
if ( $sections && ! isset( $sections[ $current_section ] ) ) {
    $current_section = 'calendario';
}

// 2. Cache Teamstep ID resolution
$teamstep_id = 0;
if ( $django_team ) {
    $ts_cache_key = "f365_ts_{$django_team}_{$edition}_{$step}_{$gender}_{$modality}";
    $cache_group  = 'futebol365_squad';
    $teamstep_id  = wp_cache_get( $ts_cache_key, $cache_group );

    if ( false === $teamstep_id ) {
        $teamstep_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$prefix}team_teamstep
                 WHERE team_id = %d AND edition = %s AND step = %s AND gender = %s AND modality = %s
                 LIMIT 1",
                $django_team, $edition, $step, $gender, $modality
            )
        );

        wp_cache_set( $ts_cache_key, $teamstep_id, $cache_group, DAY_IN_SECONDS );
    }
}



/* ============================================================
 * Pagination Setup
 * ============================================================ */

$f365_cal_page = 0;
foreach ( [ (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) ] as $qv ) {
    if ( $qv > 0 ) {
        $f365_cal_page = $qv;
        break;
    }
}
if ( ! $f365_cal_page ) {
    if ( isset( $_GET['paged'] ) ) {
        $f365_cal_page = (int) $_GET['paged'];
    } elseif ( isset( $_GET['page'] ) ) {
        $f365_cal_page = (int) $_GET['page'];
    }
}
$per_page = 50;
$paged    = max( 1, $f365_cal_page );
$offset   = ( $paged - 1 ) * $per_page;

/* ============================================================
 * Matches — Cached Relational Data Retrieval
 * ============================================================ */

$matches     = [];
$total       = 0;
$total_pages = 0;

if ( $teamstep_id ) {
    // Unique key prefix for this subset of match configurations
    $match_cache_version = "f365_matches_{$teamstep_id}_p{$paged}";
    $cache_group         = 'futebol365_matches';
    $cached_data         = wp_cache_get( $match_cache_version, $cache_group );

    if ( is_array( $cached_data ) && isset( $cached_data['matches'], $cached_data['total_pages'] ) ) {
        $matches     = $cached_data['matches'];
        $total_pages = $cached_data['total_pages'];
    } else {
        // Query 1: Total matches count
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$prefix}competition_match
                 WHERE home_team_id = %d OR visitor_team_id = %d",
                $teamstep_id, $teamstep_id
            )
        );

        $total_pages = (int) ceil( $total / $per_page );

        if ( $total_pages > 0 && $paged > $total_pages ) {
            $paged  = $total_pages;
            $offset = ( $paged - 1 ) * $per_page;
        }

        // Query 2: Selected Page Matches (Explicit column lists provide cleaner, faster index scans)
        $matches = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT m.id AS mid, m.date_time AS date_time, m.situation AS situation,
                    m.home_team_id AS home_ts, m.visitor_team_id AS visitor_ts,
                    m.home_score AS home_score, m.visitor_score AS visitor_score,
                    m.home_score_extra AS home_extra, m.visitor_score_extra AS visitor_extra,
                    m.penalty_home_score AS pen_home, m.penalty_visitor_score AS pen_visitor,
                    ht.team_id AS home_team, vt.team_id AS visitor_team,
                    hteam.shortname AS home_name, vteam.shortname AS visitor_name
                 FROM {$prefix}competition_match m
                 INNER JOIN {$prefix}team_teamstep ht ON ht.id = m.home_team_id
                 INNER JOIN {$prefix}team_teamstep vt ON vt.id = m.visitor_team_id
                 LEFT  JOIN {$prefix}team_team hteam ON hteam.id = ht.team_id
                 LEFT  JOIN {$prefix}team_team vteam ON vteam.id = vt.team_id
                 WHERE m.home_team_id = %d OR m.visitor_team_id = %d
                 ORDER BY m.date_time ASC
                 LIMIT %d OFFSET %d",
                $teamstep_id, $teamstep_id, $per_page, $offset
            )
        );

        // Cache results for 24 hours
        wp_cache_set(
            $match_cache_version,
            [
                'matches'     => $matches,
                'total_pages' => $total_pages
            ],
            $cache_group,
            DAY_IN_SECONDS
        );
    }
}

// Which matches on this page have a video
$matches_with_video = [];
if ( $matches ) {
    $matches_with_video = f365_cal_match_video_ids(
        array_map( static fn( $m ) => (int) $m->mid, $matches )
    );
}

$f365_cal_base_url = function_exists( 'f365_team_url' )
    ? f365_team_url( $post_id, 'calendario' )
    : user_trailingslashit( get_permalink( $post_id ) ) . 'calendario/';

$f365_cal_page_url = static function ( int $page ) use ( $f365_cal_base_url, $edition, $modality, $gender, $step ) {
    $args = array_filter(
        [
            'edition'  => $edition,
            'modality' => $modality,
            'gender'   => $gender,
            'step'     => $step,
            'paged'    => $page > 1 ? $page : '',
        ],
        static function ( $v ) {
            return '' !== $v && null !== $v;
        }
    );
    return esc_url( add_query_arg( $args, $f365_cal_base_url ) );
};
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

            <h3 class="condensed-400 headerTitle blue">Calendário</h3>

            <?php if ( empty( $matches ) ) : ?>

                <h5 class="no-info">Sem informação disponível</h5>

            <?php else : ?>

                <?php
                $wp_timezone    = wp_timezone();
                $current_month  = '';
                $month_names_pt = [
                    '01' => 'janeiro', '02' => 'fevereiro', '03' => 'março', '04' => 'abril',
                    '05' => 'maio', '06' => 'junho', '07' => 'julho', '08' => 'agosto',
                    '09' => 'setembro', '10' => 'outubro', '11' => 'novembro', '12' => 'dezembro',
                ];
                $last_i = array_key_last( $matches );

                foreach ( $matches as $i => $m ) :
                    $raw_date  = (string) $m->date_time;
                    $date_obj  = $raw_date ? date_create( $raw_date, $wp_timezone ) : null;
                    $month_key = $date_obj ? $date_obj->format( 'Y-m' ) : 'na';

                    if ( $month_key !== $current_month ) {
                        if ( '' !== $current_month ) {
                            echo '</tbody></table>';
                        }
                        $current_month = $month_key;
                        $month_label   = '—';
                        if ( $date_obj ) {
                            $mn          = $date_obj->format( 'm' );
                            $month_label = ( $month_names_pt[ $mn ] ?? $date_obj->format( 'F' ) ) . ' ' . $date_obj->format( 'Y' );
                        }
                        ?>
                        <h5 class="headerTitleSimpleBackground"><?php echo esc_html( ucfirst( $month_label ) ); ?></h5>
                        <table class="ink-table ink-table-f365 alternating all-100">
                            <thead>
                                <tr>
                                    <th class="all-10 small-15 tiny-15 align-left">Data</th>
                                    <th class="all-10 hide-tiny hide-small align-center">Hora</th>
                                    <th class="all-30 align-right hide-tiny hide-small">Visitado</th>
                                    <th class="all-45 align-left hide-medium hide-large hide-xlarge">Jogo</th>
                                    <th class="all-10 small-20 tiny-20"></th>
                                    <th class="all-30 align-left hide-tiny hide-small">Visitante</th>
                                    <th class="all-15 small-20 tiny-20"></th>
                                </tr>
                            </thead>
                            <tbody>
                        <?php
                    }

                    $home_team    = (int) $m->home_team;
                    $visitor_team = (int) $m->visitor_team;
                    $home_url     = $home_team ? user_trailingslashit( home_url( 'equipa/' . $home_team ) ) : '';
                    $vis_url      = $visitor_team ? user_trailingslashit( home_url( 'equipa/' . $visitor_team ) ) : '';
                    $match_url    = user_trailingslashit( home_url( 'jogo/' . (int) $m->mid ) );
                    $finished     = f365_cal_has_finished( $m );
                    $home_win     = $finished && f365_cal_home_winner( $m );
                    $vis_win      = $finished && f365_cal_visitor_winner( $m );
                    $color        = f365_cal_color( $m, $teamstep_id );
                    $date_str     = $date_obj ? $date_obj->format( 'd-m-Y' ) : '';
                    $date_str_m   = $date_obj ? $date_obj->format( 'd-m' ) : '';
                    $time_str     = $date_obj ? $date_obj->format( 'H:i' ) : '';
                    $compare_url  = ( $home_team && $visitor_team )
                        ? user_trailingslashit( home_url( 'equipa/' . $home_team . '/comparar-com/' . $visitor_team ) )
                        : '';
                    $has_video    = ( (int) $m->mid && ! empty( $matches_with_video[ (int) $m->mid ] ) );
                    ?>
                    <tr>
                        <td class="align-left hide-tiny hide-small cell-nowrap"><a href="<?php echo esc_url( $match_url ); ?>"><?php echo esc_html( $date_str ); ?></a></td>
                        <td class="align-center hide-tiny hide-small"><?php echo esc_html( $time_str ); ?></td>
                        <td class="align-left hide-xlarge hide-large hide-medium"><?php echo esc_html( $date_str_m ); ?><span class="break"><?php echo esc_html( $time_str ); ?></span></td>
                        <td class="align-right hide-tiny hide-small">
                            <a href="<?php echo esc_url( $home_url ); ?>"<?php echo $home_win ? ' class="teamWinner"' : ''; ?>><?php echo esc_html( $m->home_name ); ?></a>
                        </td>
                        <td class="align-left hide-xlarge hide-large hide-medium">
                            <a href="<?php echo esc_url( $home_url ); ?>"<?php echo $home_win ? ' class="teamWinner"' : ''; ?>><?php echo esc_html( $m->home_name ); ?></a>
                            <span class="break">
                                <a href="<?php echo esc_url( $vis_url ); ?>"<?php echo $vis_win ? ' class="teamWinner"' : ''; ?>><?php echo esc_html( $m->visitor_name ); ?></a>
                            </span>
                        </td>
                        <td class="align-center">
                            <a href="<?php echo esc_url( $match_url ); ?>">
                                <span class="ink-label ink-label-score <?php echo esc_attr( $color ); ?>"><?php echo esc_html( f365_cal_result( $m ) ); ?></span>
                            </a>
                        </td>
                        <td class="align-left hide-tiny hide-small">
                            <a href="<?php echo esc_url( $vis_url ); ?>"<?php echo $vis_win ? ' class="teamWinner"' : ''; ?>><?php echo esc_html( $m->visitor_name ); ?></a>
                        </td>
                        <td class="align-left">
                            <?php if ( $compare_url ) : ?>
                                <a href="<?php echo esc_url( $compare_url ); ?>" aria-label="Comparar equipas">
                                    <span class="ink-tooltip ink-button-related green" data-tip-text="Comparar equipas" data-tip-where="up" data-tip-color="green">C</span>
                                </a>
                            <?php endif; ?>
                            <?php if ( $has_video ) : ?>
                                <a href="<?php echo esc_url( add_query_arg( 'match_opt', 'matchvideos', $match_url ) ); ?>" aria-label="Vídeo">
                                    <span class="ink-tooltip ink-button-related red" data-tip-text="Vídeo" data-tip-where="up" data-tip-color="red">V</span>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php
                    if ( $i === $last_i ) {
                        echo '</tbody></table>';
                    }
                endforeach;
                ?>

                <?php
                if ( $total_pages > 1 ) {
                    $window = [];
                    for ( $i = 1; $i <= $total_pages; $i++ ) {
                        if ( $i <= 2 || $i > $total_pages - 2 || abs( $i - $paged ) <= 2 ) {
                            $window[] = $i;
                        }
                    }

                    echo '<div class="paginacao-new"><nav class="ink-navigation"><ul class="pagination">';

                    if ( $paged > 1 ) {
                        echo '<li class="hide-tiny hide-small"><a class="prev page-numbers" href="' . $f365_cal_page_url( $paged - 1 ) . '">&laquo; Anterior</a></li>';
                    }

                    $prev = 0;
                    foreach ( $window as $i ) {
                        if ( $prev && $i > $prev + 1 ) {
                            echo '<li><a class="page-numbers dots">&hellip;</a></li>';
                        }
                        if ( $i === $paged ) {
                            echo '<li class="active"><span class="page-numbers active">' . (int) $i . '</span></li>';
                        } else {
                            echo '<li><a class="page-numbers" href="' . $f365_cal_page_url( $i ) . '">' . (int) $i . '</a></li>';
                        }
                        $prev = $i;
                    }

                    if ( $paged < $total_pages ) {
                        echo '<li class="hide-tiny hide-small"><a class="next page-numbers" href="' . $f365_cal_page_url( $paged + 1 ) . '">Seguinte &raquo;</a></li>';
                    }

                    echo '</ul></nav></div>';
                }
                ?>
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
