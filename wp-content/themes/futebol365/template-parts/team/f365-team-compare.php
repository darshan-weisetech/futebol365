<?php

/**
 * Team Comparar-com (Frente-a-frente) ROUTER
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/f365-team-compare-sections.php';
$current_section = get_query_var('section', '') ?: 'forma';
$compare_sections = function_exists('futebol365_get_team_compare_sections') ? futebol365_get_team_compare_sections() : [];
$first_team_id =  '';
$second_team_id = '';


get_header();

?>


<main id="primary" class="site-main single-article-page">

    <?php get_template_part( 'template-parts/team/f365-team-compare-header', null, [
        'sections'         => $compare_sections,
        'current_section'  => $current_section,
        'first_django_id'  => $first_team_id,
        'second_django_id' => $second_team_id,
        'nav_qs'           => $_SERVER['QUERY_STRING'] ?? '',
    ] ); ?>

    <div class="ink-grid futebol365-container">
        <section class="left-column-block">

            <div class="breadcrumbs-f365">
                <div class="ink-navigation breadcrumbs">
                    <?php yoast_breadcrumb(); ?>
                </div>
            </div>
            <?php
            global $wpdb;

                if (!function_exists('f365_compare_ids_from_url')) {
                    function f365_compare_ids_from_url() {
                        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
                        if (preg_match('#/equipa/(\d+)/comparar-com/(\d+)/?#', $uri, $m)) {
                            return array(absint($m[1]), absint($m[2]));
                        }

                        return array(
                            isset($_GET['first_team_id']) ? absint($_GET['first_team_id']) : 0,
                            isset($_GET['second_team_id']) ? absint($_GET['second_team_id']) : 0,
                        );
                    }
                }

                if (!function_exists('f365_compare_get_param')) {
                    function f365_compare_get_param($name, $default) {
                        return isset($_GET[$name]) && $_GET[$name] !== ''
                            ? sanitize_text_field(wp_unslash($_GET[$name]))
                            : $default;
                    }
                }

                if (!function_exists('f365_compare_score_for_goals')) {
                    function f365_compare_score_for_goals($match) {
                        $home = ($match->home_score_extra !== null && $match->home_score_extra !== '')
                            ? $match->home_score_extra
                            : $match->home_score;
                        $away = ($match->visitor_score_extra !== null && $match->visitor_score_extra !== '')
                            ? $match->visitor_score_extra
                            : $match->visitor_score;

                        return array((int) $home, (int) $away);
                    }
                }

                if (!function_exists('f365_compare_score_for_winner')) {
                    function f365_compare_score_for_winner($match) {
                        if ($match->penalty_home_score !== null && $match->penalty_home_score !== ''
                            && $match->penalty_visitor_score !== null && $match->penalty_visitor_score !== '') {
                            return array((int) $match->penalty_home_score, (int) $match->penalty_visitor_score);
                        }

                        return f365_compare_score_for_goals($match);
                    }
                }

                if (!function_exists('f365_compare_result_text')) {
                    function f365_compare_result_text($match) {
                        if ($match->penalty_home_score !== null && $match->penalty_home_score !== ''
                            && $match->penalty_visitor_score !== null && $match->penalty_visitor_score !== '') {
                            return (int) $match->penalty_home_score . ' - ' . (int) $match->penalty_visitor_score . ' g.p';
                        }

                        if ($match->home_score_extra !== null && $match->home_score_extra !== ''
                            && $match->visitor_score_extra !== null && $match->visitor_score_extra !== '') {
                            return (int) $match->home_score_extra . ' - ' . (int) $match->visitor_score_extra . ' a.p';
                        }

                        if ($match->home_score !== null && $match->home_score !== ''
                            && $match->visitor_score !== null && $match->visitor_score !== '') {
                            return (int) $match->home_score . ' - ' . (int) $match->visitor_score;
                        }

                        return 'vs';
                    }
                }

                if (!function_exists('f365_compare_empty_stats')) {
                    function f365_compare_empty_stats() {
                        return array(
                            'games'         => 0,
                            'wins'          => 0,
                            'draws'         => 0,
                            'losses'        => 0,
                            'goals_for'     => 0,
                            'goals_against' => 0,
                        );
                    }
                }

                if (!function_exists('f365_compare_stats')) {
                    function f365_compare_stats($matches, $first_team_id, $second_team_id) {
                        $first = f365_compare_empty_stats();
                        $second = f365_compare_empty_stats();

                        foreach ($matches as $match) {
                            list($home_goals, $away_goals) = f365_compare_score_for_goals($match);
                            list($home_win_score, $away_win_score) = f365_compare_score_for_winner($match);

                            $first_is_home = ((int) $match->home_parent_team_id === (int) $first_team_id);

                            $first_for = $first_is_home ? $home_goals : $away_goals;
                            $first_against = $first_is_home ? $away_goals : $home_goals;
                            $second_for = $first_is_home ? $away_goals : $home_goals;
                            $second_against = $first_is_home ? $home_goals : $away_goals;

                            $first_win_score = $first_is_home ? $home_win_score : $away_win_score;
                            $first_lose_score = $first_is_home ? $away_win_score : $home_win_score;

                            $first['games']++;
                            $second['games']++;
                            $first['goals_for'] += $first_for;
                            $first['goals_against'] += $first_against;
                            $second['goals_for'] += $second_for;
                            $second['goals_against'] += $second_against;

                            if ($first_win_score > $first_lose_score) {
                                $first['wins']++;
                                $second['losses']++;
                            } elseif ($first_win_score < $first_lose_score) {
                                $first['losses']++;
                                $second['wins']++;
                            } else {
                                $first['draws']++;
                                $second['draws']++;
                            }
                        }

                        return array($first, $second);
                    }
                }

                if (!function_exists('f365_compare_avg')) {
                    function f365_compare_avg($value, $total) {
                        return $total > 0 ? number_format_i18n($value / $total, 2) : '0.00';
                    }
                }

                if (!function_exists('f365_compare_table')) {
                    function f365_compare_table($first_name, $second_name, $first_stats, $second_stats) {
                        ?>
                        <table class="ink-table ink-table-f365 alternating all-100">
                            <thead>
                                <tr>
                                    <th class="align-center all-33"><?php echo esc_html($first_name); ?></th>
                                    <th class="align-center all-33"></th>
                                    <th class="align-center all-33"><?php echo esc_html($second_name); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td class="align-center"><?php echo esc_html($first_stats['games']); ?></td><td class="align-center"><?php esc_html_e( 'Jogos', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html($second_stats['games']); ?></td></tr>
                                <tr><td class="align-center"><?php echo esc_html($first_stats['wins']); ?></td><td class="align-center"><?php esc_html_e( 'Vitorias', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html($second_stats['wins']); ?></td></tr>
                                <tr><td class="align-center"><?php echo esc_html($first_stats['draws']); ?></td><td class="align-center"><?php esc_html_e( 'Empates', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html($second_stats['draws']); ?></td></tr>
                                <tr><td class="align-center"><?php echo esc_html($first_stats['losses']); ?></td><td class="align-center"><?php esc_html_e( 'Derrotas', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html($second_stats['losses']); ?></td></tr>
                                <tr><td class="align-center"><?php echo esc_html($first_stats['goals_for']); ?></td><td class="align-center"><?php esc_html_e( 'Golos marcados', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html($second_stats['goals_for']); ?></td></tr>
                                <tr><td class="align-center"><?php echo esc_html($first_stats['goals_against']); ?></td><td class="align-center"><?php esc_html_e( 'Golos sofridos', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html($second_stats['goals_against']); ?></td></tr>
                                <tr><td class="align-center"><?php echo esc_html(f365_compare_avg($first_stats['goals_for'], $first_stats['games'])); ?></td><td class="align-center"><?php esc_html_e( 'Media de golos marcados', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html(f365_compare_avg($second_stats['goals_for'], $second_stats['games'])); ?></td></tr>
                                <tr><td class="align-center"><?php echo esc_html(f365_compare_avg($first_stats['goals_against'], $first_stats['games'])); ?></td><td class="align-center"><?php esc_html_e( 'Media de golos sofridos', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html(f365_compare_avg($second_stats['goals_against'], $second_stats['games'])); ?></td></tr>
                                <tr><td class="align-center"><?php echo esc_html($first_stats['goals_for'] - $first_stats['goals_against']); ?></td><td class="align-center"><?php esc_html_e( 'Diferenca de golos', 'futebol365' ); ?></td><td class="align-center"><?php echo esc_html($second_stats['goals_for'] - $second_stats['goals_against']); ?></td></tr>
                            </tbody>
                        </table>
                        <?php
                    }
                }

                if (!function_exists('f365_compare_team_logo_html')) {
                    function f365_compare_team_logo_html($team_id, $team_name = '') {
                        $team_id = absint($team_id);
                        $wp_id = function_exists('f365_team_resolve_id') ? f365_team_resolve_id($team_id) : 0;
                        
                        if (function_exists('f365_cmp_team_logo')) {
                            $logo_url = f365_cmp_team_logo((int)$wp_id, $team_id);
                        } else {
                            $logo_url = $wp_id ? get_the_post_thumbnail_url($wp_id, 'full') : '';
                        }
                        
                        if (empty($logo_url)) {
                            $logo_url = function_exists('futebol365_fallback_image') ? futebol365_fallback_image() : get_template_directory_uri() . '/assets/img/no-image.gif';
                        }

                        return sprintf(
                            '<img class="team-logo" src="%s" alt="%s" title="%s" loading="lazy">',
                            esc_url($logo_url),
                            esc_attr($team_name),
                            esc_attr($team_name)
                        );
                    }
                }

                if (!function_exists('f365_compare_biggest_win')) {
                    function f365_compare_biggest_win($matches, $team_id, $home_only) {
                        $best = null;
                        $best_diff = null;

                        foreach ($matches as $match) {
                            $is_home = ((int) $match->home_parent_team_id === (int) $team_id);
                            $is_away = ((int) $match->visitor_parent_team_id === (int) $team_id);

                            if ($home_only && !$is_home) {
                                continue;
                            }
                            if (!$home_only && !$is_away) {
                                continue;
                            }

                            list($home_score, $away_score) = f365_compare_score_for_goals($match);
                            list($home_win_score, $away_win_score) = f365_compare_score_for_winner($match);

                            $won = $is_home ? ($home_win_score > $away_win_score) : ($away_win_score > $home_win_score);
                            if (!$won) {
                                continue;
                            }

                            $diff = $is_home ? ($home_score - $away_score) : ($away_score - $home_score);
                            if ($best === null || $diff > $best_diff) {
                                $best = $match;
                                $best_diff = $diff;
                            }
                        }

                        return $best;
                    }
                }

                if (!function_exists('f365_compare_match_link')) {
                    function f365_compare_match_link($match) {
                        if (!$match) {
                            return '-';
                        }

                        $url = home_url('/jogo/' . absint($match->id) . '/');
                        return '<a href="' . esc_url($url) . '">' . esc_html(f365_compare_result_text($match)) . '</a>';
                    }
                }

                list($first_team_id, $second_team_id) = f365_compare_ids_from_url();

                $step1 = f365_compare_get_param('step1', 'principal');
                $step2 = f365_compare_get_param('step2', 'principal');
                $gender1 = f365_compare_get_param('gender1', 'male');
                $gender2 = f365_compare_get_param('gender2', 'male');
                $modality1 = f365_compare_get_param('modality1', 'football');
                $modality2 = f365_compare_get_param('modality2', 'football');
                $games_choices = f365_compare_get_param('games_choices', 'all');

                $filter_competition = isset($_GET['federation_competition']) && $_GET['federation_competition'] !== '' && $_GET['federation_competition'] !== 'all'
                    ? absint($_GET['federation_competition'])
                    : 0;

                $begin_edition = isset($_GET['begin_edition']) && preg_match('/^\d{4}$/', $_GET['begin_edition'])
                    ? sanitize_text_field(wp_unslash($_GET['begin_edition']))
                    : '';
                $end_edition = isset($_GET['end_edition']) && preg_match('/^\d{4}$/', $_GET['end_edition'])
                    ? sanitize_text_field(wp_unslash($_GET['end_edition']))
                    : '';

                $team_table = $wpdb->prefix . 'team_team';
                $teamstep_table = $wpdb->prefix . 'team_teamstep';
                $match_table = $wpdb->prefix . 'competition_match';
                $competition_table = $wpdb->prefix . 'competition_competition';
                $fed_comp_table = $wpdb->prefix . 'federation_federationcompetition';

                $first_team = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$team_table} WHERE id = %d", $first_team_id));
                $second_team = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$team_table} WHERE id = %d", $second_team_id));

                if (!$first_team || !$second_team) {
                    echo '<p class="no-info">Equipas nao encontradas.</p>';
                    return;
                }

                $base_where = "
                    cm.situation = %s
                    AND (
                        (ht.team_id = %d AND ht.step = %s AND ht.gender = %s AND ht.modality = %s
                        AND vt.team_id = %d AND vt.step = %s AND vt.gender = %s AND vt.modality = %s)
                    OR
                        (vt.team_id = %d AND vt.step = %s AND vt.gender = %s AND vt.modality = %s
                        AND ht.team_id = %d AND ht.step = %s AND ht.gender = %s AND ht.modality = %s)
                    )";

                $base_params = array(
                    'finished',
                    $first_team_id, $step1, $gender1, $modality1,
                    $second_team_id, $step2, $gender2, $modality2,
                    $first_team_id, $step1, $gender1, $modality1,
                    $second_team_id, $step2, $gender2, $modality2,
                );

                $competition_options = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT DISTINCT fc.id, fc.shortname, fc.longname
                        FROM {$match_table} cm
                        INNER JOIN {$competition_table} c ON c.id = cm.competition_id
                        INNER JOIN {$fed_comp_table} fc ON fc.id = c.federation_competition_id
                        INNER JOIN {$teamstep_table} ht ON ht.id = cm.home_team_id
                        INNER JOIN {$teamstep_table} vt ON vt.id = cm.visitor_team_id
                        WHERE {$base_where}
                        ORDER BY fc.shortname",
                        $base_params
                    )
                );

                $edition_options = array();
                if ($filter_competition) {
                    $edition_options = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT edition, season
                            FROM {$competition_table}
                            WHERE federation_competition_id = %d
                            ORDER BY edition DESC",
                            $filter_competition
                        )
                    );
                }

                $where_extra = '';
                $params = $base_params;

                if ($filter_competition) {
                    $where_extra .= ' AND c.federation_competition_id = %d';
                    $params[] = $filter_competition;
                }
                if ($begin_edition !== '') {
                    $where_extra .= ' AND c.edition >= %s';
                    $params[] = $begin_edition;
                }
                if ($end_edition !== '') {
                    $where_extra .= ' AND c.edition <= %s';
                    $params[] = $end_edition;
                }
                if ($games_choices === 'is_home_team') {
                    $where_extra .= ' AND ht.team_id = %d AND vt.team_id = %d';
                    $params[] = $first_team_id;
                    $params[] = $second_team_id;
                } elseif ($games_choices === 'is_visitor_team') {
                    $where_extra .= ' AND vt.team_id = %d AND ht.team_id = %d';
                    $params[] = $first_team_id;
                    $params[] = $second_team_id;
                }

                $matches = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT
                            cm.*,
                            c.name AS competition_name,
                            c.edition,
                            c.season,
                            c.federation_competition_id,
                            ht.team_id AS home_parent_team_id,
                            vt.team_id AS visitor_parent_team_id
                        FROM {$match_table} cm
                        INNER JOIN {$competition_table} c ON c.id = cm.competition_id
                        INNER JOIN {$teamstep_table} ht ON ht.id = cm.home_team_id
                        INNER JOIN {$teamstep_table} vt ON vt.id = cm.visitor_team_id
                        WHERE {$base_where}
                        {$where_extra}
                        ORDER BY cm.date_time DESC",
                        $params
                    )
                );

                $home_matches = array_values(array_filter($matches, function($match) use ($first_team_id) {
                    return (int) $match->home_parent_team_id === (int) $first_team_id;
                }));
                $away_matches = array_values(array_filter($matches, function($match) use ($first_team_id) {
                    return (int) $match->visitor_parent_team_id === (int) $first_team_id;
                }));

                list($total_first_stats, $total_second_stats) = f365_compare_stats($matches, $first_team_id, $second_team_id);
                list($home_first_stats, $home_second_stats) = f365_compare_stats($home_matches, $first_team_id, $second_team_id);
                list($away_first_stats, $away_second_stats) = f365_compare_stats($away_matches, $first_team_id, $second_team_id);

                $requested_edition = isset($_GET['edition']) && preg_match('/^\d{4}$/', $_GET['edition']) ? sanitize_text_field(wp_unslash($_GET['edition'])) : gmdate('Y');

                $teamstep1 = $wpdb->get_row(
                            $wpdb->prepare(
                                "SELECT *
                                FROM {$wpdb->prefix}team_teamstep
                                WHERE team_id = %d
                                AND step = %s
                                AND modality = %s
                                AND gender = %s
                                ORDER BY (edition = %s) DESC, id ASC
                                LIMIT 1",
                                $first_team_id,
                                $step1,
                                $modality1,
                                $gender1,
                                $requested_edition
                            )
                        );

                        $teamstep2 = $wpdb->get_row(
                            $wpdb->prepare(
                                "SELECT *
                                FROM {$wpdb->prefix}team_teamstep
                                WHERE team_id = %d
                                AND step = %s
                                AND modality = %s
                                AND gender = %s
                                ORDER BY (edition = %s) DESC, id ASC
                                LIMIT 1",
                                $second_team_id,
                                $step2,
                                $modality2,
                                $gender2,
                                $requested_edition
                            )
                        );
                        $first_team_url = $teamstep1
                            ? add_query_arg(
                                array(
                                    'edition'  => $teamstep1->edition,
                                    'modality' => $teamstep1->modality,
                                    'gender'   => $teamstep1->gender,
                                    'step'     => $teamstep1->step,
                                ),
                                home_url('/equipa/' . $first_team_id . '/jogos/')
                            )
                            : home_url('/equipa/' . $first_team_id . '/jogos/');

                        $second_team_url = $teamstep2
                            ? add_query_arg(
                                array(
                                    'edition'  => $teamstep2->edition,
                                    'modality' => $teamstep2->modality,
                                    'gender'   => $teamstep2->gender,
                                    'step'     => $teamstep2->step,
                                ),
                                home_url('/equipa/' . $second_team_id . '/jogos/')
                            )
                            : home_url('/equipa/' . $second_team_id . '/jogos/');


                $first_name = $first_team->shortname ?: $first_team->longname;
                $second_name = $second_team->shortname ?: $second_team->longname;
                ?>

                <form class="ink-form ink-form-option" method="get" name="compare_teams_form" id="compare_teams_form">
                    <div class="column-group">
                        <div class="control-group all-33 tiny-100 small-100">
                            <div class="control">
                                <select name="federation_competition" id="id_federation_competition" onchange="this.form.submit()">
                                    <option value="all"><?php esc_html_e( 'Competicoes', 'futebol365' ); ?></option>
                                    <?php foreach ($competition_options as $competition) : ?>
                                        <option value="<?php echo esc_attr($competition->id); ?>" <?php selected($filter_competition, $competition->id); ?>>
                                            <?php echo esc_html($competition->shortname ?: $competition->longname); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <?php if ($filter_competition && $edition_options) : ?>
                            <div class="control-group all-33 tiny-100 small-100">
                                <div class="control">
                                    <select name="begin_edition" id="id_begin_edition" onchange="this.form.submit()">
                                        <option value="">Epoca inicial</option>
                                        <?php foreach ($edition_options as $edition) : ?>
                                            <option value="<?php echo esc_attr($edition->edition); ?>" <?php selected($begin_edition, $edition->edition); ?>>
                                                <?php echo esc_html($edition->season ?: $edition->edition); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="control-group all-33 tiny-100 small-100">
                                <div class="control">
                                    <select name="end_edition" id="id_end_edition" onchange="this.form.submit()">
                                        <option value=""><?php esc_html_e( 'Epoca final', 'futebol365' ); ?></option>
                                        <?php foreach ($edition_options as $edition) : ?>
                                            <option value="<?php echo esc_attr($edition->edition); ?>" <?php selected($end_edition, $edition->edition); ?>>
                                                <?php echo esc_html($edition->season ?: $edition->edition); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>

                <div class="column-group match-div f365-compare-hero">
                    <div class="all-40 small-30 tiny-30 align-center">
                        <a href="<?php echo esc_url($first_team_url); ?>"><h5 class="match-team-name"><?php echo esc_html($first_name); ?></h5></a>
                    </div>
                    <div class="all-20 small-40 tiny-40 align-center"></div>
                    <div class="all-40 small-30 tiny-30 align-center">
                        <a href="<?php echo esc_url($second_team_url); ?>"><h5 class="match-team-name"><?php echo esc_html($second_name); ?></h5></a>
                    </div>
                    <div class="all-40 small-30 tiny-30 align-center">
                        <a href="<?php echo esc_url($first_team_url); ?>"><?php echo f365_compare_team_logo_html($first_team_id, $first_name); ?></a>
                    </div>
                    <div class="all-20 small-40 tiny-40 align-center">
                        <h2 class="match-score"><span class="ink-badge black no-margin quarter-vertical-padding">VS</span></h2>
                    </div>
                    <div class="all-40 small-30 tiny-30 align-center">
                        <a href="<?php echo esc_url($second_team_url); ?>"><?php echo f365_compare_team_logo_html($second_team_id, $second_name); ?></a>
                    </div>
                </div>

                <?php if ($first_team->team_type !== 'national' && $second_team->team_type !== 'national') : ?>
                    <h5 class="condensed-400 headerTitle blue"><?php echo esc_html($first_name . ' - ' . $second_name); ?></h5>
                    <?php f365_compare_table($first_name, $second_name, $home_first_stats, $home_second_stats); ?>

                    <h5 class="condensed-400 headerTitle blue"><?php echo esc_html($second_name . ' - ' . $first_name); ?></h5>
                    <?php f365_compare_table($second_name, $first_name, $away_second_stats, $away_first_stats); ?>
                <?php endif; ?>

                <h5 class="condensed-400 headerTitle blue">Resumo</h5>
                <?php f365_compare_table($first_name, $second_name, $total_first_stats, $total_second_stats); ?>

                <?php if ($first_team->team_type !== 'national' && $second_team->team_type !== 'national') : ?>
                    <?php
                    $first_big_home = f365_compare_biggest_win($matches, $first_team_id, true);
                    $second_big_home = f365_compare_biggest_win($matches, $second_team_id, true);
                    $first_big_away = f365_compare_biggest_win($matches, $first_team_id, false);
                    $second_big_away = f365_compare_biggest_win($matches, $second_team_id, false);
                    ?>
                    <h5 class="condensed-400 headerTitle blue">Curiosidades</h5>
                    <table class="ink-table ink-table-f365 alternating all-100">
                        <thead>
                            <tr>
                                <th class="align-center all-33"><?php echo esc_html($first_name); ?></th>
                                <th class="align-center all-33"></th>
                                <th class="align-center all-33"><?php echo esc_html($second_name); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td class="align-center"><?php echo f365_compare_match_link($first_big_home); ?></td><td class="align-center"><?php esc_html_e( 'Maior vitoria em casa', 'futebol365' ); ?></td><td class="align-center"><?php echo f365_compare_match_link($second_big_home); ?></td></tr>
                            <tr><td class="align-center"><?php echo f365_compare_match_link($first_big_away); ?></td><td class="align-center"><?php esc_html_e( 'Maior vitoria fora', 'futebol365' ); ?></td><td class="align-center"><?php echo f365_compare_match_link($second_big_away); ?></td></tr>
                            <tr><td class="align-center"><?php echo f365_compare_match_link($second_big_away); ?></td><td class="align-center"><?php esc_html_e( 'Maior derrota em casa', 'futebol365' ); ?></td><td class="align-center"><?php echo f365_compare_match_link($first_big_away); ?></td></tr>
                            <tr><td class="align-center"><?php echo f365_compare_match_link($second_big_home); ?></td><td class="align-center"><?php esc_html_e( 'Maior derrota fora', 'futebol365' ); ?></td><td class="align-center"><?php echo f365_compare_match_link($first_big_home); ?></td></tr>
                        </tbody>
                    </table>
                <?php endif; ?>


            <?php get_template_part( 'template-parts/template-front-page-posts' ); ?>

        </section>

        <section class="right-column-block">
            <?php if ( is_active_sidebar( 'post-sidebar' ) ) {
                dynamic_sidebar( 'post-sidebar' );
            } else {
                get_sidebar();
            } ?>
        </section>
    </div>
</main>

<?php get_footer(); ?>