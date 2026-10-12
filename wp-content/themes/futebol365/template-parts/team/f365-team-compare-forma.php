<?php
/**
 * Team Comparar-com (Frente-a-frente) ROUTER — /equipa/{id}/comparar-com/{id2}/[section/]
 *
 * FIXED: Palmarés matching exact Python aggregation framework rules.
 *
 * @package Futebol365
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/f365-team-compare-sections.php';

global $wpdb;

/* ---------- helpers ---------- */

if (!function_exists('f365_cmp_get_ids')) {
    function f365_cmp_get_ids(): array {
        $first = (int) (get_query_var('f365_team_id') ?: get_query_var('team_id'));
        $second = (int) (get_query_var('f365_team2_id') ?: get_query_var('second_team_id'));

        if ((!$first || !$second) && !empty($_SERVER['REQUEST_URI'])) {
            $path = wp_parse_url(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH);

            if (preg_match('#/equipa/(\d+)/comparar-com/(\d+)(?:/|$)#', (string) $path, $m)) {
                $first = (int) $m[1];
                $second = (int) $m[2];
            }
        }

        return [$first, $second];
    }
}

if (!function_exists('f365_compare_team_logo_html')) {
    function f365_compare_team_logo_html($team_id, $team_name = ''): string {
        $team_id = absint($team_id);
        $wp_id = function_exists('f365_team_resolve_id') ? (int) f365_team_resolve_id($team_id) : 0;
        
        if (function_exists('f365_cmp_team_logo')) {
            $logo_url = f365_cmp_team_logo($wp_id, $team_id);
        } else {
            $logo_url = $wp_id ? (string) get_the_post_thumbnail_url($wp_id, 'full') : '';
        }
        
        if (empty($logo_url)) {
            $logo_url = function_exists('futebol365_fallback_image') ? (string) futebol365_fallback_image() : get_template_directory_uri() . '/assets/img/no-image.gif';
        }

        return sprintf(
            '<img class="team-logo" src="%s" alt="%s" title="%s" loading="lazy">',
            esc_url($logo_url),
            esc_attr($team_name),
            esc_attr($team_name)
        );
    }
}

if (!function_exists('f365_cmp_current_timezone_code')) {
    function f365_cmp_current_timezone_code(): string {
        $timezone = wp_timezone_string();

        return $timezone ?: 'Europe/Lisbon';
    }
}

if (!function_exists('f365_cmp_match_datetime')) {
    function f365_cmp_match_datetime($date_time): ?DateTimeImmutable {
        if (empty($date_time)) {
            return null;
        }

        try {
            $source_tz = new DateTimeZone('Europe/Lisbon');
            $target_tz = new DateTimeZone(f365_cmp_current_timezone_code());

            $dt = new DateTimeImmutable($date_time, $source_tz);

            return $dt->setTimezone($target_tz);
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('f365_cmp_team_url')) {
    function f365_cmp_team_url($team_id, $teamstep): string {
        return add_query_arg([
            'edition'  => $teamstep->edition ?? '',
            'modality' => $teamstep->modality ?? 'football',
            'gender'   => $teamstep->gender ?? 'male',
            'step'     => $teamstep->step ?? 'principal',
        ], '/equipa/' . (int) $team_id . '/jogos/');
    }
}

if (!function_exists('f365_cmp_match_url')) {
    function f365_cmp_match_url($match_id): string {
        return '/jogo/' . (int) $match_id . '/';
    }
}

if (!function_exists('f365_cmp_match_video_url')) {
    function f365_cmp_match_video_url($match_id): string {
        return add_query_arg(['match_opt' => 'matchvideos'], f365_cmp_match_url($match_id));
    }
}

if (!function_exists('f365_cmp_match_compare_url')) {
    function f365_cmp_match_compare_url($m): string {
        return add_query_arg([
            'edition'   => $m->competition_edition,
            'step1'     => $m->home_step,
            'gender1'   => $m->home_gender,
            'modality1' => $m->home_modality,
            'step2'     => $m->visitor_step,
            'gender2'   => $m->visitor_gender,
            'modality2' => $m->visitor_modality,
        ], '/equipa/' . (int) $m->home_real_team_id . '/comparar-com/' . (int) $m->visitor_real_team_id . '/');
    }
}

if (!function_exists('f365_cmp_comp_round_url')) {
    function f365_cmp_comp_round_url($m): string {
        $args = ['edition' => $m->competition_edition];

        if (!empty($m->competition_phase_name)) {
            $args['competition_phase'] = $m->competition_phase_name;

            if ((string) $m->group_phase_round !== '') {
                $args['phase_round'] = $m->group_phase_round;
            }
        } else {
            $args['competition_round'] = $m->match_round;
        }

        if ((string) $m->group_home_team !== '') {
            $args['group'] = $m->group_home_team;
        }

        return add_query_arg($args, '/competicao/' . (int) $m->federation_competition_id . '/');
    }
}

if (!function_exists('f365_cmp_result_text')) {
    function f365_cmp_result_text($m): string {
        if ($m->situation === 'delayed') {
            return 'ADI';
        }

        if ($m->situation === 'suspended') {
            return 'SUS';
        }

        if ($m->situation === 'abrogated') {
            return 'ANU';
        }

        if ($m->penalty_home_score !== null && $m->penalty_visitor_score !== null) {
            return (int) $m->penalty_home_score . ' - ' . (int) $m->penalty_visitor_score . ' g.p';
        }

        if ($m->home_score_extra !== null && $m->visitor_score_extra !== null) {
            return (int) $m->home_score_extra . ' - ' . (int) $m->visitor_score_extra . ' a.p';
        }

        if ($m->home_score !== null && $m->visitor_score !== null) {
            return (int) $m->home_score . ' - ' . (int) $m->visitor_score;
        }

        return 'vs';
    }
}

if (!function_exists('f365_cmp_result_class')) {
    function f365_cmp_result_class($m, $teamstep_id): string {
        if ($m->situation !== 'finished') {
            return '';
        }

        $home = ($m->home_score_extra !== null) ? (int) $m->home_score_extra : (int) $m->home_score;
        $away = ($m->visitor_score_extra !== null) ? (int) $m->visitor_score_extra : (int) $m->visitor_score;

        if ($m->penalty_home_score !== null && $m->penalty_visitor_score !== null) {
            $home = (int) $m->penalty_home_score;
            $away = (int) $m->penalty_visitor_score;
        }

        if ($home === $away) {
            return 'draw';
        }

        $is_home = ((int) $m->home_team_id === (int) $teamstep_id);
        $won = $is_home ? ($home > $away) : ($away > $home);

        return $won ? 'win' : 'lost';
    }
}

if (!function_exists('f365_cmp_render_match_actions')) {
    function f365_cmp_render_match_actions($m): void {
        ?>
        <a href="<?php echo esc_url(f365_cmp_match_compare_url($m)); ?>">
            <span class="ink-tooltip ink-button-related green" data-tip-text="Comparar equipas" data-tip-where="up" data-tip-color="green">C</span>
        </a>

        <?php if (!empty($m->has_video)) : ?>
            <a href="<?php echo esc_url(f365_cmp_match_video_url($m->id)); ?>">
                <span class="ink-tooltip ink-button-related red" data-tip-text="Video" data-tip-where="up" data-tip-color="red">V</span>
            </a>
        <?php endif; ?>
        <?php
    }
}

if (!function_exists('f365_cmp_teamstep_name')) {
    function f365_cmp_teamstep_name($team, $teamstep): string {
        $name = $team->shortname ?? '';
        $suffix = '';

        if (($teamstep->modality ?? '') === 'futsal') {
            $suffix .= ' FS';
        }

        if (($teamstep->gender ?? '') === 'female') {
            $suffix .= ' FM';
        }

        if (($teamstep->step ?? '') === 'b_team') {
            $suffix .= ' B';
        } elseif (($teamstep->step ?? '') === 'c_team') {
            $suffix .= ' C';
        } elseif (($teamstep->step ?? '') === 'veterans') {
            $suffix .= ' VET';
        } elseif (($teamstep->step ?? '') === 'reservations') {
            $suffix .= ' RES';
        }

        return trim($name . $suffix);
    }
}

if (!function_exists('f365_cmp_get_teamstep')) {
    function f365_cmp_get_teamstep($wpdb, $table, $team_id, $edition, $step, $modality, $gender) {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE team_id = %d
               AND edition = %s
               AND step = %s
               AND modality = %s
               AND gender = %s
             LIMIT 1",
            $team_id,
            $edition,
            $step,
            $modality,
            $gender
        ));

        if ($row) {
            return $row;
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE team_id = %d
               AND step = %s
               AND modality = %s
               AND gender = %s
             ORDER BY edition DESC
             LIMIT 1",
            $team_id,
            $step,
            $modality,
            $gender
        ));
    }
}

if (!function_exists('f365_cmp_render_table')) {
    function f365_cmp_render_table($title, $teamstep, $matches): void {
        ?>
        <h2 class="condensed-400 headerTitle blue"><?php echo esc_html(strtoupper($title)); ?> - &Uacute;LTIMOS JOGOS</h2>

        <?php if (empty($matches)) : ?>
            <h5 class="no-info">N&atilde;o foram encontrados jogos realizados pela equipa <?php echo esc_html($title); ?> nas competi&ccedil;&otilde;es seleccionadas.</h5>
            <?php return; ?>
        <?php endif; ?>

        <table class="ink-table alternating ink-table-f365">
            <thead>
                <tr>
                    <th class="all-5 small-15 tiny-15 align-left">Data</th>
                    <th class="all-15 hide-tiny hide-small align-left">Competi&ccedil;&atilde;o</th>
                    <th class="all-30 align-right hide-tiny hide-small">Visitado</th>
                    <th class="all-45 align-left hide-medium hide-large hide-xlarge">Jogo</th>
                    <th class="all-10 small-20 tiny-20"></th>
                    <th class="all-30 align-left hide-tiny hide-small">Visitante</th>
                    <th class="all-10 small-20 tiny-20"></th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($matches as $m) : ?>
                    
                    <?php
                    $date = f365_cmp_match_datetime($m->date_time);
                    $round_url = f365_cmp_comp_round_url($m);
                    $result_url = f365_cmp_match_url($m->id);

                    $home_ts = (object) [
                        'edition'  => $m->home_edition,
                        'modality' => $m->home_modality,
                        'gender'   => $m->home_gender,
                        'step'     => $m->home_step,
                    ];

                    $visitor_ts = (object) [
                        'edition'  => $m->visitor_edition,
                        'modality' => $m->visitor_modality,
                        'gender'   => $m->visitor_gender,
                        'step'     => $m->visitor_step,
                    ];

                    $home_url = f365_cmp_team_url($m->home_real_team_id, $home_ts);
                    $visitor_url = f365_cmp_team_url($m->visitor_real_team_id, $visitor_ts);

                    $home_score = ($m->home_score_extra !== null) ? (int) $m->home_score_extra : (int) $m->home_score;
                    $visitor_score = ($m->visitor_score_extra !== null) ? (int) $m->visitor_score_extra : (int) $m->visitor_score;

                    $home_win = $home_score > $visitor_score;
                    $visitor_win = $visitor_score > $home_score;
                    ?>

                    <tr>
                        <td class="align-left cell-nowrap">
                            <a href="<?php echo esc_url($round_url); ?>">
                                <?php echo esc_html($date ? $date->format('d-m-y') : ''); ?>
                                <span class="hide-xlarge hide-large hide-medium break"><?php echo esc_html($date ? $date->format('H:i') : ''); ?></span>
                            </a>
                        </td>

                        <td class="align-left hide-tiny hide-small cell-nowrap">
                            <a href="<?php echo esc_url(add_query_arg(['edition' => $m->competition_edition], '/competicao/' . (int) $m->federation_competition_id . '/')); ?>">
                                <i class="flag-icon quarter-right-space flag-icon-<?php echo esc_attr($m->competition_flag_id); ?>" title="<?php echo esc_attr($m->competition_name); ?>"></i><?php echo esc_html(wp_html_excerpt($m->competition_name, 20, '...')); ?>
                            </a>
                        </td>

                        <td class="align-right hide-tiny hide-small">
                            <a href="<?php echo esc_url($home_url); ?>"<?php echo $home_win ? ' class="teamWinner"' : ''; ?>>
                                <?php echo esc_html($m->home_team_name); ?>
                            </a>
                        </td>

                        <td class="align-left hide-xlarge hide-large hide-medium">
                            <a href="<?php echo esc_url($home_url); ?>"<?php echo $home_win ? ' class="teamWinner"' : ''; ?>>
                                <?php echo esc_html($m->home_team_name); ?>
                            </a>
                            <span class="break">
                                <a href="<?php echo esc_url($visitor_url); ?>"<?php echo $visitor_win ? ' class="teamWinner"' : ''; ?>>
                                    <?php echo esc_html($m->visitor_team_name); ?>
                                </a>
                            </span>
                        </td>

                        <td class="align-center">
                            <span class="ink-label ink-label-score <?php echo esc_attr(f365_cmp_result_class($m, $teamstep->id)); ?>">
                                <a href="<?php echo esc_url($result_url); ?>">
                                    <?php echo esc_html(f365_cmp_result_text($m)); ?>
                                </a>
                            </span>
                        </td>

                        <td class="align-left hide-tiny hide-small">
                            <a href="<?php echo esc_url($visitor_url); ?>"<?php echo $visitor_win ? ' class="teamWinner"' : ''; ?>>
                                <?php echo esc_html($m->visitor_team_name); ?>
                            </a>
                        </td>

                        <td class="align-left"><?php f365_cmp_render_match_actions($m); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}

/* ---------- data ---------- */

list($first_team_id, $second_team_id) = f365_cmp_get_ids();

$edition = isset($_GET['edition']) ? sanitize_text_field(wp_unslash($_GET['edition'])) : wp_date('Y');
$step1 = isset($_GET['step1']) ? sanitize_text_field(wp_unslash($_GET['step1'])) : 'principal';
$step2 = isset($_GET['step2']) ? sanitize_text_field(wp_unslash($_GET['step2'])) : 'principal';
$gender1 = isset($_GET['gender1']) ? sanitize_text_field(wp_unslash($_GET['gender1'])) : 'male';
$gender2 = isset($_GET['gender2']) ? sanitize_text_field(wp_unslash($_GET['gender2'])) : 'male';
$modality1 = isset($_GET['modality1']) ? sanitize_text_field(wp_unslash($_GET['modality1'])) : 'football';
$modality2 = isset($_GET['modality2']) ? sanitize_text_field(wp_unslash($_GET['modality2'])) : 'football';

$team_tbl = $wpdb->prefix . 'team_team';
$teamstep_tbl = $wpdb->prefix . 'team_teamstep';
$match_tbl = $wpdb->prefix . 'competition_match';
$competition_tbl = $wpdb->prefix . 'competition_competition';
$phase_tbl = $wpdb->prefix . 'competition_competitionphase';
$video_tbl = $wpdb->prefix . 'multimedia_video';
$fed_comp_tbl = $wpdb->prefix . 'federation_federationcompetition';

$first_team = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$team_tbl} WHERE id = %d", $first_team_id));
$second_team = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$team_tbl} WHERE id = %d", $second_team_id));

$teamstep1 = f365_cmp_get_teamstep($wpdb, $teamstep_tbl, $first_team_id, $edition, $step1, $modality1, $gender1);
$teamstep2 = f365_cmp_get_teamstep($wpdb, $teamstep_tbl, $second_team_id, $edition, $step2, $modality2, $gender2);

$teamstep1_matches = [];
$teamstep2_matches = [];

if ($first_team && $second_team && $teamstep1 && $teamstep2) {
    $competition_id = isset($_GET['competition']) && $_GET['competition'] !== 'all' ? (int) $_GET['competition'] : 0;

    $limit_sql = $competition_id ? '' : ' LIMIT 10';

    $competition_in = '';
    $params1 = [];
    $params2 = [];

    if ($competition_id) {
        $competition_in = ' AND m.competition_id = %d';
        $params1[] = $competition_id;
        $params2[] = $competition_id;
    }

    $sql = "SELECT m.*,
            c.name AS competition_name,
            c.edition AS competition_edition,
            c.country_id AS competition_country_id,
            c.federation_competition_id,
            CASE
                WHEN c.country_id IS NOT NULL AND c.country_id <> 0 THEN CAST(c.country_id AS CHAR)
                ELSE CONCAT('conf_', fc.federation_id)
            END AS competition_flag_id,
            cp.name AS competition_phase_name,
            EXISTS(SELECT 1 FROM {$video_tbl} mv WHERE mv.match_id = m.id LIMIT 1) AS has_video,
            ht.team_id AS home_real_team_id,
            ht.step AS home_step,
            ht.modality AS home_modality,
            ht.gender AS home_gender,
            ht.edition AS home_edition,
            hteam.shortname AS home_team_name,
            vt.team_id AS visitor_real_team_id,
            vt.step AS visitor_step,
            vt.modality AS visitor_modality,
            vt.gender AS visitor_gender,
            vt.edition AS visitor_edition,
            vteam.shortname AS visitor_team_name
        FROM {$match_tbl} m
        INNER JOIN {$competition_tbl} c ON c.id = m.competition_id
        LEFT JOIN {$fed_comp_tbl} fc ON fc.id = c.federation_competition_id
        LEFT JOIN {$phase_tbl} cp ON cp.id = m.competition_phase_id
        INNER JOIN {$teamstep_tbl} ht ON ht.id = m.home_team_id
        INNER JOIN {$team_tbl} hteam ON hteam.id = ht.team_id
        INNER JOIN {$teamstep_tbl} vt ON vt.id = m.visitor_team_id
        INNER JOIN {$team_tbl} vteam ON vteam.id = vt.team_id
        WHERE m.situation = 'finished'
          {$competition_in}
          AND (ht.team_id = %d OR vt.team_id = %d)
        ORDER BY m.date_time DESC{$limit_sql}";

    $params1[] = (int) $first_team_id;
    $params1[] = (int) $first_team_id;

    $params2[] = (int) $second_team_id;
    $params2[] = (int) $second_team_id;

    $teamstep1_matches = $wpdb->get_results($wpdb->prepare($sql, $params1));
    $teamstep2_matches = $wpdb->get_results($wpdb->prepare($sql, $params2));
}

/* ---------- template ---------- */

get_header();

$current_section = get_query_var('section', '') ?: 'forma';
$compare_sections = function_exists('futebol365_get_team_compare_sections') ? futebol365_get_team_compare_sections() : [];

?>
<main id="primary" class="site-main single-article-page">
    <?php
    get_template_part('template-parts/team/f365-team-compare-header', null, [
        'sections' => $compare_sections,
        'current_section' => $current_section,
    ]);
    ?>

    <div class="ink-grid futebol365-container" style="height: auto !important;">
        <section class="left-column-block" style="height: auto !important;">
            <div class="breadcrumbs-f365">
                <div class="ink-navigation breadcrumbs">
                    <?php if (function_exists('yoast_breadcrumb')) yoast_breadcrumb(); ?>
                </div>
            </div>

            <?php if (!$first_team || !$second_team || !$teamstep1 || !$teamstep2) : ?>
                <h5 class="no-info">N&atilde;o foi poss&iacute;vel encontrar as equipas seleccionadas.</h5>
            <?php else : ?>
                <?php
                $teamstep1_name = f365_cmp_teamstep_name($first_team, $teamstep1);
                $teamstep2_name = f365_cmp_teamstep_name($second_team, $teamstep2);
                ?>

                <div class="column-group match-div">
                    <div class="all-40 small-30 tiny-30 align-center">
                        <a href="<?php echo esc_url(f365_cmp_team_url($first_team->id, $teamstep1)); ?>">
                            <h5 class="match-team-name"><?php echo esc_html($teamstep1_name); ?></h5>
                        </a>
                    </div>

                    <div class="all-20 small-40 tiny-40 align-center"></div>

                    <div class="all-40 small-30 tiny-30 align-center">
                        <a href="<?php echo esc_url(f365_cmp_team_url($second_team->id, $teamstep2)); ?>">
                            <h5 class="match-team-name"><?php echo esc_html($teamstep2_name); ?></h5>
                        </a>
                    </div>

                    <div class="all-40 small-30 tiny-30 align-center">
                        <a href="<?php echo esc_url(f365_cmp_team_url($first_team->id, $teamstep1)); ?>">
                            <?php echo f365_compare_team_logo_html($first_team->id, $teamstep1_name); ?>
                        </a>
                    </div>

                    <div class="all-20 small-40 tiny-40 align-center">
                        <h2 class="match-score">
                            <span class="ink-badge black no-margin quarter-vertical-padding">VS</span>
                        </h2>
                    </div>

                    <div class="all-40 small-30 tiny-30 align-center">
                        <a href="<?php echo esc_url(f365_cmp_team_url($second_team->id, $teamstep2)); ?>">
                            <?php echo f365_compare_team_logo_html($second_team->id, $teamstep2_name); ?>
                        </a>
                    </div>
                </div>

                <?php
                f365_cmp_render_table($teamstep1_name, $teamstep1, $teamstep1_matches);
                f365_cmp_render_table($teamstep2_name, $teamstep2, $teamstep2_matches);
                ?>
            <?php endif; ?>
            <?php get_template_part( 'template-parts/template-front-page-posts' ); ?>
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
</main>
<?php get_footer(); ?>