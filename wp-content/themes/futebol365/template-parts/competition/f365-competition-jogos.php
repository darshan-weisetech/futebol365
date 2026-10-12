
<?php
/**
 * Competition — Jogos / Calendário   /competicao/{fed_comp_id}/  (games tab)
 *
 * Relational port of competition/templates/competition/competition_all_games.html
 * + view get_competition_calendar(). Matches come from
 * F365_Competition_Repository::get_competition_calendar(), grouped by round/phase,
 * with the single team_participant filter, winner highlighting, result-or-time
 * links, timezone-formatted dates and 50-per-page pagination.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

get_header();

$post_id = $args['post_id'] ?? get_the_ID();

$sections        = futebol365_get_competition_sections();
$current_section = 'jogos';
if ( ! isset( $sections[ $current_section ] ) ) {
    $current_section = 'jogos';
}

//$competition_id = (int) ( get_post_meta( $post_id, 'django_competition_id', true ) ?: 0 );
$competition_id = get_legacy_competition_id( $post_id );

$repo = ( class_exists( 'F365_Competition_Repository' ) && $competition_id ) ? new F365_Competition_Repository() : null;

/* Filter: team_participant (a Django teamstep id), 'all_teams' means no filter. */
$raw_team   = isset( $_GET['team_participant'] ) ? sanitize_text_field( wp_unslash( $_GET['team_participant'] ) ) : '';
$team_step  = ( '' !== $raw_team && 'all_teams' !== $raw_team ) ? (int) $raw_team : 0;

$team_options = $repo ? $repo->get_participant_team_options( $competition_id ) : array();

/* Jornada (round) filter — defaults to the round nearest today, like get_last_round(). */
$rounds         = $repo ? $repo->get_competition_rounds( $competition_id ) : array();
$requested_round = isset( $_GET['competition_round'] ) ? (int) $_GET['competition_round'] : 0;
if ( $requested_round && in_array( $requested_round, $rounds, true ) ) {
    $selected_round = $requested_round;
} else {
    $selected_round = $repo ? $repo->get_competition_last_round( $competition_id ) : 0;
}

/* CORREÇÃO DA FILTRAGEM: Busca os jogos e garante o filtro rígido no PHP */
$all_matches_raw = $repo
    ? $repo->get_competition_calendar( $competition_id, $team_step ?: null, null )
    : array();

$all_matches = array();
if ( ! empty( $all_matches_raw ) ) {
    foreach ( $all_matches_raw as $match ) {
        if ( $selected_round > 0 ) {
            // Verifica a propriedade correta da jornada no objeto (match_round ou round)
            $current_match_round = isset( $match->match_round ) ? (int) $match->match_round : ( isset( $match->round ) ? (int) $match->round : 0 );

            if ( $current_match_round === $selected_round ) {
                $all_matches[] = $match;
            }
        } else {
            $all_matches[] = $match;
        }
    }
}

/* Pagination — 50 per page, ?pagina= */
$per_page = 50;
$paged    = isset( $_GET['pagina'] ) ? absint( $_GET['pagina'] ) : 1;
if ( $paged < 1 ) {
    $paged = 1;
}
$total         = count( $all_matches );
$max_num_pages = (int) ceil( $total / $per_page );
$offset        = ( $paged - 1 ) * $per_page;
$page_matches  = array_slice( $all_matches, $offset, $per_page );
?>

<main id="primary" class="site-main single-article-page">

    <?php
    get_template_part(
        'template-parts/competition/f365-competition-header',
        null,
        array(
            'post_id'         => $post_id,
            'current_section' => $current_section,
            'sections'        => $sections,
            'competition_id' => $competition_id ,

        )
    );
    ?>

    <div class="ink-grid futebol365-container">

        <section class="left-column-block">

            <form class="ink-form ink-form-option" method="get" action="" name="games_level2_form" id="games_level2_form">
                <div class="column-group">
                    <?php if ( ! empty( $rounds ) ) : ?>
                        <div class="control-group all-33 tiny-100 small-100">
                            <div class="control">
                                <select name="competition_round" id="id_competition_round" onchange="if(this.form){this.form.submit();}">
                                    <?php foreach ( $rounds as $round ) : ?>
                                        <option value="<?php echo esc_attr( $round ); ?>" <?php selected( $selected_round, $round ); ?>>
                                            <?php
                                            /* translators: %d: round number. */
                                            echo esc_html( sprintf( __( 'Jornada %d', 'futebol365' ), $round ) );
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </form>

            <h5 class="condensed-400 headerTitle blue"><?php esc_html_e( 'Calendário', 'futebol365' ); ?></h5>

            <?php if ( ! empty( $page_matches ) ) : ?>

                <?php
                $prev_round = null;
                $open_table = false;
                foreach ( $page_matches as $m ) :
                    $round_changed = ( null === $prev_round || (int) $m->match_round !== (int) $prev_round );
                    if ( $round_changed ) :
                        if ( $open_table ) :
                            ?>
                            </tbody></table>
                            <?php
                        endif;
                        $open_table = true;
                        ?>
                        <h5 class="headerTitleSimpleBackground">
                            <a href="<?php echo esc_url( $m->round_url ); ?>">
                                <?php echo esc_html( ! empty( $m->round_label ) ? $m->round_label : sprintf( __( '%dª Jornada', 'futebol365' ), $m->match_round ) ); ?>
                            </a>
                        </h5>
                        <table class="ink-table ink-table-f365 alternating all-100">
                            <thead>
                                <tr>
                                    <th class="all-10 small-15 tiny-15 align-left"><?php esc_html_e( 'Data', 'futebol365' ); ?></th>
                                    <th class="all-10 hide-tiny hide-small align-center"><?php esc_html_e( 'Hora', 'futebol365' ); ?></th>
                                    <th class="all-30 align-right hide-tiny hide-small"><?php esc_html_e( 'Visitado', 'futebol365' ); ?></th>
                                    <th class="all-45 align-left hide-medium hide-large hide-xlarge"><?php esc_html_e( 'Jogo', 'futebol365' ); ?></th>
                                    <th class="all-10 small-20 tiny-20"></th>
                                    <th class="all-30 align-left hide-tiny hide-small"><?php esc_html_e( 'Visitante', 'futebol365' ); ?></th>
                                    <th class="all-15 small-20 tiny-20"></th>
                                </tr>
                            </thead>
                            <tbody>
                        <?php
                    endif;
                    $prev_round = $m->match_round;

                    $home_cls    = $m->home_is_winner ? ' class="teamWinner"' : '';
                    $visitor_cls = $m->visitor_is_winner ? ' class="teamWinner"' : '';
                    ?>
                            <tr>
                                <td class="align-left hide-tiny hide-small cell-nowrap"><a href="<?php echo esc_url( $m->round_url ); ?>"><?php echo esc_html( $m->date_fmt ); ?></a></td>
                                <td class="align-center hide-tiny hide-small"><?php echo esc_html( $m->time_fmt ); ?></td>
                                <td class="align-left hide-xlarge hide-large hide-medium"><?php echo esc_html( $m->date_short ); ?><span class="break"><?php echo esc_html( $m->time_fmt ); ?></span></td>
                                <td class="align-right hide-tiny hide-small">
                                    <a href="<?php echo esc_url( $m->home_url ); ?>"<?php echo $home_cls; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $m->home_label ); ?></a>
                                </td>
                                <td class="align-left hide-xlarge hide-large hide-medium">
                                    <a href="<?php echo esc_url( $m->home_url ); ?>"<?php echo $home_cls; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $m->home_label ); ?></a>
                                    <span class="break">
                                        <a href="<?php echo esc_url( $m->visitor_url ); ?>"<?php echo $visitor_cls; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $m->visitor_label ); ?></a>
                                    </span>
                                </td>
                                <td class="align-center">
                                    <span class="ink-label ink-label-score silver"><a href="<?php echo esc_url( $m->match_url ); ?>"><?php echo esc_html( $m->result ); ?></a></span>
                                </td>
                                <td class="align-left hide-tiny hide-small">
                                    <a href="<?php echo esc_url( $m->visitor_url ); ?>"<?php echo $visitor_cls; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $m->visitor_label ); ?></a>
                                </td>
                                <td class="align-center">
                                    <?php if ( ! empty( $m->match_url ) ) : ?>
                                        <a href="<?php echo esc_url( $m->match_url ); ?>" ><span class="ink-tooltip ink-button-related green" data-tip-text="Comparar equipas" data-tip-where="up" data-tip-color="green">C</span></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                    <?php
                endforeach;
                if ( $open_table ) :
                    ?>
                    </tbody></table>
                    <?php
                endif;
                ?>
                <?php futebol365_render_pagination( $paged , $max_num_pages )?>

            <?php else : ?>

                <h6 class="no-info"><?php esc_html_e( 'Sem informação disponível', 'futebol365' ); ?></h6>

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
