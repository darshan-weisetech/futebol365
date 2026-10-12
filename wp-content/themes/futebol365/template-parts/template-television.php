<?php
/**
 * Template Name: Television Template
 */

get_header();
$bg_field = get_field('bg_image', 'option');
$bg_image_url = $bg_field['url'] ?? futebol365_fallback_image();

$order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'date';
?>
<?php
    global $wpdb;

    $match_tv_table    = $wpdb->prefix . 'live_matchontv';
    $channel_table     = $wpdb->prefix . 'multimedia_tvchannel';
    $match_table       = $wpdb->prefix . 'competition_match';
    $competition_table = $wpdb->prefix . 'competition_competition';
    $teamstep_table    = $wpdb->prefix . 'team_teamstep';
    $team_table        = $wpdb->prefix . 'team_team';

    $allowed_orders = ['date', 'competition', 'channel'];
    $order = isset($_GET['order']) ? sanitize_key($_GET['order']) : 'date';

    if (!in_array($order, $allowed_orders, true)) {
        $order = 'date';
    }

    $lower_datetime = date('Y-m-d H:i:s', current_time('timestamp') - 6900);

    if ($order === 'competition') {
        $order_sql = 'competition_name ASC, tv.datetime ASC, home_team_name ASC';
    } elseif ($order === 'channel') {
        $order_sql = 'channel_name ASC, tv.datetime ASC, home_team_name ASC';
    } else {
        $order_sql = 'DATE(tv.datetime) ASC, tv.datetime ASC, home_team_name ASC';
    }

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT
                tv.id AS tv_id,
                tv.datetime AS tv_datetime,
                tv.match_id,
                tv.channel_id,

                ch.name AS channel_name,
                ch.url AS channel_url,

                m.id AS match_id,
                m.date_time AS match_datetime,
                m.home_score,
                m.visitor_score,
                m.home_score_extra,
                m.visitor_score_extra,
                m.penalty_home_score,
                m.penalty_visitor_score,
                m.situation,

                c.id AS competition_id,
                c.name AS competition_name,
                c.edition AS competition_edition,
                c.season AS competition_season,

                home_step.id AS home_step_id,
                home_step.team_id AS home_team_id,
                home_team.shortname AS home_team_name,
                home_team.team_type AS home_team_type,

                away_step.id AS away_step_id,
                away_step.team_id AS away_team_id,
                away_team.shortname AS away_team_name,
                away_team.team_type AS away_team_type

            FROM {$match_tv_table} tv
            INNER JOIN {$channel_table} ch ON ch.id = tv.channel_id
            INNER JOIN {$match_table} m ON m.id = tv.match_id
            INNER JOIN {$competition_table} c ON c.id = m.competition_id
            INNER JOIN {$teamstep_table} home_step ON home_step.id = m.home_team_id
            INNER JOIN {$teamstep_table} away_step ON away_step.id = m.visitor_team_id
            INNER JOIN {$team_table} home_team ON home_team.id = home_step.team_id
            INNER JOIN {$team_table} away_team ON away_team.id = away_step.team_id

            WHERE tv.datetime >= %s
            AND (
                    m.situation IS NULL
                    OR m.situation NOT IN ('finished', 'delayed', 'suspended', 'abrogated')
            )

            ORDER BY {$order_sql}
            ",
            $lower_datetime
        )
    );

    if (!function_exists('f365_tv_match_score')) {
        function f365_tv_match_score($row) {
            if ($row->home_score === null || $row->visitor_score === null) {
                return '-';
            }

            $score = (int) $row->home_score . ' - ' . (int) $row->visitor_score;

            if ($row->home_score_extra !== null && $row->visitor_score_extra !== null) {
                $score .= ' ap ' . (int) $row->home_score_extra . ' - ' . (int) $row->visitor_score_extra;
            }

            if ($row->penalty_home_score !== null && $row->penalty_visitor_score !== null) {
                $score .= ' pen ' . (int) $row->penalty_home_score . ' - ' . (int) $row->penalty_visitor_score;
            }

            return $score;
        }
    }

    if (!function_exists('f365_tv_team_url')) {
        function f365_tv_team_url($team_id, $competition_id, $team_type) {
            $url = home_url('/equipa/' . (int) $team_id . '/');

            if ($team_type === 'national') {
                $url = add_query_arg('competition', (int) $competition_id, $url);
            }

            return $url;
        }
    }

    if (!function_exists('f365_tv_diferido_label')) {
        function f365_tv_diferido_label($row) {
            if ($row->match_datetime !== $row->tv_datetime) {
                return '<span class="ink-tooltip ink-button-related match-on-tv-diferido red" data-tip-text="Diferido" data-tip-where="up" data-tip-color="red">DIF</span>';
            }

            return '';
        }
    }?>
<div class="ink-grid vertical-space">
    <?php get_template_part('template-parts/template-offer-widget'); ?>

    <div class="ink-header-f365 competicoes"
        style="background-image: linear-gradient(to left, rgba(250, 250, 250, 0) 40%, rgba(0, 0, 0, 1)), url(<?php echo esc_url($bg_image_url); ?>);">
        <div class="column-group">
            <div class="align-left all-60 medium-100 small-100 tiny-100 banner-left-content">
                <div class="headerdata">
                    <a href="#">
                        <h1><?php echo the_title(); ?></h1>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <div class="nav-trigger" id="nav-trigger">
        <span>Menu</span>
    </div>
    <nav class="nav-main" id="nav-main">
        <ul>
            <li><a <?php if ( $order !== 'competition' && $order !== 'channel' ) echo 'class="active"'; ?> href="?order=date">Data</a></li>
            <li><a <?php if ( $order === 'competition' ) echo 'class="active"'; ?> href="?order=competition">Competição</a></li>
            <li><a <?php if ( $order === 'channel' ) echo 'class="active"'; ?> href="?order=channel">Canal</a></li>
        </ul>
    </nav>
</div>
<div class="ink-grid futebol365-container">

    <section class="left-column-block">
        <div class="breadcrumbs-f365">
            <div class="ink-navigation breadcrumbs">
                <?php yoast_breadcrumb(); ?>
            </div>
        </div>
        <div class="tv-game-protected">
            <?php

            if (!function_exists('f365_tv_render_table')) {
                function f365_tv_render_table($matches, $show_date, $show_channel) {
                    if (empty($matches)) {
                        return;
                    }
                    ?>
                    <table class="ink-table alternating ink-table-f365 double-bottom-space">
                        <thead>
                            <tr>
                                <?php if ($show_date) : ?>
                                    <th class="all-10 tiny-15 small-15 align-left">Data</th>
                                <?php endif; ?>

                                <th class="all-5 tiny-15 small-15 align-left">Hora</th>
                                <th class="all-30 hide-tiny hide-small align-right">Visitado</th>
                                <th class="all-10 hide-tiny hide-small"></th>
                                <th class="all-45 hide-xlarge hide-large hide-medium align-left">Jogo</th>
                                <th class="all-30 hide-tiny hide-small align-left">Visitante</th>

                                <?php if ($show_channel) : ?>
                                    <th class="all-15 tiny-30 small-30 align-left">Canal</th>
                                <?php endif; ?>

                                <th class="all-10"></th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($matches as $match) : ?>
                                <?php
                                $time = date_i18n('H:i', strtotime($match->tv_datetime));
                                $date = date_i18n('d-m-y', strtotime($match->tv_datetime));

                                $home_url = f365_tv_team_url($match->home_team_id, $match->competition_id, $match->home_team_type);
                                $away_url = f365_tv_team_url($match->away_team_id, $match->competition_id, $match->away_team_type);
                                ?>
                                <tr>
                                    <?php if ($show_date) : ?>
                                        <td class="align-left hide-tiny hide-small"><?php echo esc_html($date); ?></td>
                                    <?php endif; ?>

                                    <td class="align-left"><?php echo esc_html($time); ?></td>

                                    <td class="align-right hide-tiny hide-small">
                                        <a href="<?php echo esc_url($home_url); ?>">
                                            <?php echo esc_html($match->home_team_name); ?>
                                        </a>
                                    </td>

                                    <td class="align-center hide-tiny hide-small">
                                        <span class="ink-label ink-label-score silver">
                                            <?php echo esc_html(f365_tv_match_score($match)); ?>
                                        </span>
                                    </td>

                                    <td class="align-left hide-xlarge hide-large hide-medium">
                                        <a href="<?php echo esc_url($home_url); ?>">
                                            <?php echo esc_html($match->home_team_name); ?>
                                        </a>
                                        <span class="break">
                                            <a href="<?php echo esc_url($away_url); ?>">
                                                <?php echo esc_html($match->away_team_name); ?>
                                            </a>
                                        </span>
                                    </td>

                                    <td class="align-left hide-tiny hide-small">
                                        <a href="<?php echo esc_url($away_url); ?>">
                                            <?php echo esc_html($match->away_team_name); ?>
                                        </a>
                                    </td>

                                    <?php if ($show_channel) : ?>
                                        <td class="align-left">
                                            <a href="<?php echo esc_url($match->channel_url); ?>" target="_blank">
                                                <?php echo esc_html($match->channel_name); ?>
                                            </a>
                                            <?php echo wp_kses_post(f365_tv_diferido_label($match)); ?>
                                        </td>
                                    <?php endif; ?>

                                    <td class="align-center">
                                        <a href="<?php echo esc_url(home_url('/goto/')); ?>" target="_blank">
                                            <span class="ink-tooltip ink-button-related orange" data-tip-text="Aposte neste jogo" data-tip-where="up" data-tip-color="orange">A</span>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php
                }
            }

            if (!empty($rows)) :
                $groups = [];

                foreach ($rows as $row) {
                    if ($order === 'competition') {
                        $key = 'competition_' . $row->competition_id;
                        $label = $row->competition_name;
                    } elseif ($order === 'channel') {
                        $key = 'channel_' . $row->channel_id;
                        $label = $row->channel_name;
                    } else {
                        $key = date('Y-m-d', strtotime($row->tv_datetime));
                        $label = date_i18n('l, d \d\e F', strtotime($row->tv_datetime));
                    }

                    if (!isset($groups[$key])) {
                        $groups[$key] = [
                            'label' => $label,
                            'rows'  => [],
                        ];
                    }

                    $groups[$key]['rows'][] = $row;
                }

                foreach ($groups as $group) :
                    ?>
                    <h3 class="condensed-400 headerTitle orange">
                        <?php echo esc_html($group['label']); ?>
                    </h3>

                    <?php
                    f365_tv_render_table(
                        $group['rows'],
                        $order !== 'date',
                        $order !== 'channel'
                    );
                    ?>
                <?php endforeach; ?>

            <?php else : ?>
                <h3 class="condensed-400 headerTitle orange">
                    <?php
                    if ($order === 'competition') {
                        esc_html_e('Competição', 'futebol365');
                    } elseif ($order === 'channel') {
                        esc_html_e('Canal', 'futebol365');
                    } else {
                        esc_html_e('Data', 'futebol365');
                    }
                    ?>
                </h3>

                <h6 class="no-info"><?php esc_html_e('Sem informação disponível', 'futebol365'); ?></h6>
            <?php endif; ?>
        </div>
        <?php get_template_part('template-parts/template-front-page-posts'); ?>
    </section>

    <section class="right-column-block">
        <?php
        if (is_active_sidebar('post-sidebar')) {
            dynamic_sidebar('post-sidebar');
        } else {
            get_sidebar();
        }
        ?>
    </section>

</div>


<?php get_footer(); ?>