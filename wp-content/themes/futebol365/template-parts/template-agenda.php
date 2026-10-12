<?php
/* Template Name: Agenda Page */

$bg_field = get_field('bg_image', 'option');
$bg_image_url = $bg_field['url'] ?? futebol365_fallback_image();

get_header();

global $wpdb;

$django_prefix = $wpdb->prefix;

$display_timezone = wp_timezone();
$db_timezone      = new DateTimeZone('UTC');

$selected_date = isset($_GET['schedule_date_input']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['schedule_date_input'])
    ? sanitize_text_field(wp_unslash($_GET['schedule_date_input']))
    : (new DateTimeImmutable('today', $display_timezone))->format('Y-m-d');

$local_start = new DateTimeImmutable($selected_date . ' 00:00:00', $display_timezone);
$local_end = $local_start->modify('+1 day');

$date_start = $local_start->setTimezone($db_timezone)->format('Y-m-d H:i:s');
$date_end = $local_end->setTimezone($db_timezone)->format('Y-m-d H:i:s');

$matches_rows = $wpdb->get_results(
    $wpdb->prepare(
        "
        SELECT
            m.id,
            m.date_time,
            m.home_score,
            m.visitor_score,
            m.home_score_extra,
            m.visitor_score_extra,
            m.penalty_home_score,
            m.penalty_visitor_score,
            m.situation,
            m.group_home_team,
            m.group_visitor_team,

            cp.phase_type AS competition_phase_type,

            c.id AS competition_id,
            c.name AS competition_name,
            c.edition,
            c.country_id,

            fc.id AS federation_competition_id,
            fc.`order` AS federation_competition_order,

            f.id AS federation_id,
            f.shortname AS federation_shortname,
            f.federation_type,

            federation_country.id AS federation_country_id,
            country.shortname AS country_shortname,
            country.slug AS country_slug,

            ht.team_id AS home_team_id,
            ht.step AS home_step,
            ht.modality AS home_modality,
            ht.gender AS home_gender,

            vt.team_id AS visitor_team_id,
            vt.step AS visitor_step,
            vt.modality AS visitor_modality,
            vt.gender AS visitor_gender,

            ht_team.shortname AS home_team_name,
            vt_team.shortname AS visitor_team_name

        FROM {$django_prefix}competition_match m
        INNER JOIN {$django_prefix}competition_competition c
            ON c.id = m.competition_id
        LEFT JOIN {$django_prefix}competition_competitionphase cp
            ON cp.id = m.competition_phase_id
        LEFT JOIN {$django_prefix}federation_federationcompetition fc
            ON fc.id = c.federation_competition_id
        LEFT JOIN {$django_prefix}federation_federation f
            ON f.id = fc.federation_id
        LEFT JOIN {$django_prefix}country_country federation_country
            ON federation_country.id = f.country_id
        LEFT JOIN {$django_prefix}country_country country
            ON country.id = c.country_id
        LEFT JOIN {$django_prefix}team_teamstep ht
            ON ht.id = m.home_team_id
        LEFT JOIN {$django_prefix}team_team ht_team
            ON ht_team.id = ht.team_id
        LEFT JOIN {$django_prefix}team_teamstep vt
            ON vt.id = m.visitor_team_id
        LEFT JOIN {$django_prefix}team_team vt_team
            ON vt_team.id = vt.team_id
        WHERE m.date_time >= %s AND m.date_time < %s
        ",
        $date_start,
        $date_end
    ),
    ARRAY_A
);

function f365_agenda_result_label($match)
{
    if ($match['situation'] === 'delayed')
        return 'ADI';
    if ($match['situation'] === 'suspended')
        return 'SUS';
    if ($match['situation'] === 'abrogated')
        return 'ANU';

    if ($match['penalty_home_score'] !== null && $match['penalty_visitor_score'] !== null) {
        return $match['penalty_home_score'] . ' - ' . $match['penalty_visitor_score'] . ' g.p';
    }

    if ($match['home_score_extra'] !== null && $match['visitor_score_extra'] !== null) {
        return $match['home_score_extra'] . ' - ' . $match['visitor_score_extra'] . ' a.p';
    }

    if ($match['home_score'] !== null && $match['visitor_score'] !== null) {
        return $match['home_score'] . ' - ' . $match['visitor_score'];
    }

    return 'vs';
}

function f365_agenda_date_label($date, $today)
{
    $diff = (int) $today->diff($date)->format('%r%a');

    if ($diff === -1)
        return 'ONTEM, ' . $date->format('j');
    if ($diff === 0)
        return 'HOJE, ' . $date->format('j');
    if ($diff === 1)
        return 'AMANHÃ, ' . $date->format('j');

    return strtoupper($date->format('l, j'));
}

function f365_agenda_team_games_url($team_id, $edition, $modality, $gender, $step, $competition_id)
{
    if (empty($team_id)) {
        return '#';
    }

    return home_url(
        '/equipa/' . (int) $team_id . '/jogos/?' . http_build_query([
            'edition' => $edition,
            'modality' => $modality,
            'gender' => $gender,
            'step' => $step,
            'competition' => $competition_id,
        ])
    );
}

function f365_agenda_step_suffix($step)
{
    $map = [
        'sub_23' => 'S23',
        'sub_21' => 'S21',
        'sub_20' => 'S20',
        'sub_19' => 'S19',
        'sub_18' => 'S18',
        'sub_17' => 'S17',
        'sub_16' => 'S16',
        'sub_15' => 'S15',
        'sub_14' => 'S14',
        'sub_13' => 'S13',
        'b_team' => 'B',
        'c_team' => 'C',
    ];

    return $map[$step] ?? '';
}

function f365_agenda_team_display_name($name, $step)
{
    $suffix = f365_agenda_step_suffix($step);

    if (!$suffix || stripos($name, $suffix) !== false) {
        return $name;
    }

    return trim($name . ' ' . $suffix);
}

function f365_agenda_section_sort_key($section)
{
    return strtolower((string) ($section['section_title'] ?? ''));
}

function f365_agenda_first_match_sort($matches)
{
    $first_match = PHP_INT_MAX;

    foreach ($matches as $match) {
        $match_time = strtotime($match['date_time'] . ' UTC');

        if ($match_time && $match_time < $first_match) {
            $first_match = $match_time;
        }
    }

    return $first_match;
}

function f365_agenda_match_sort($a, $b)
{
    $time_compare = strcmp((string) $a['date_time'], (string) $b['date_time']);

    if ($time_compare !== 0) {
        return $time_compare;
    }

    return strnatcasecmp((string) ($a['home_team_name'] ?? ''), (string) ($b['home_team_name'] ?? ''));
}

$competitions_raw = [];

foreach ($matches_rows as $row) {
    $competition_id = (int) $row['competition_id'];

    $competition_title = trim($row['competition_name']);

    $title_prefix = !empty($row['country_shortname'])
        ? $row['country_shortname']
        : ($row['federation_shortname'] ?? '');

    if ($title_prefix && stripos($competition_title, $title_prefix) === false) {
        $competition_title = $title_prefix . ': ' . $competition_title;
    }

    if (!isset($competitions_raw[$competition_id])) {
        if (!empty($row['country_id'])) {
            $flag_id = (string) (int) $row['country_id'];
        } elseif (($row['federation_type'] ?? '') === 'confederation') {
            $flag_id = 'conf_' . (int) $row['federation_id'];
        } else {
            $flag_id = !empty($row['federation_country_id']) ? (string) (int) $row['federation_country_id'] : '';
        }

        $competition_url = home_url(
            '/competicao/' . (int) $row['federation_competition_id'] . '/?edition=' . urlencode((string) $row['edition'])
        );

        $competitions_raw[$competition_id] = [
            'competition_title' => $competition_title,
            'competition_url' => $competition_url,
            'flag_id' => $flag_id,
            'country_sort' => !empty($row['country_slug']) ? $row['country_slug'] : $row['competition_name'],
            'federation_sort' => $row['federation_shortname'] ?? '',
            'competition_order' => (int) ($row['federation_competition_order'] ?? 999999),
            'first_match_sort' => strtotime($row['date_time'] . ' UTC') ?: PHP_INT_MAX,
            'groups' => [],
            'matches' => [],
        ];
    }

    $match_datetime = new DateTimeImmutable($row['date_time'], $db_timezone);

    $row['hora'] = $match_datetime->setTimezone($display_timezone)->format('H:i');

    $row['match_url'] = home_url('/jogo/' . (int) $row['id'] . '/');

    $row['home_team_url'] = f365_agenda_team_games_url(
        $row['home_team_id'],
        $row['edition'],
        $row['home_modality'],
        $row['home_gender'],
        $row['home_step'],
        $row['competition_id']
    );

    $row['visitor_team_url'] = f365_agenda_team_games_url(
        $row['visitor_team_id'],
        $row['edition'],
        $row['visitor_modality'],
        $row['visitor_gender'],
        $row['visitor_step'],
        $row['competition_id']
    );

    $row['home_team_display'] = f365_agenda_team_display_name($row['home_team_name'], $row['home_step']);
    $row['visitor_team_display'] = f365_agenda_team_display_name($row['visitor_team_name'], $row['visitor_step']);

    $competitions_raw[$competition_id]['matches'][] = $row;
    $row_time = strtotime($row['date_time'] . ' UTC');

    if ($row_time && $row_time < $competitions_raw[$competition_id]['first_match_sort']) {
        $competitions_raw[$competition_id]['first_match_sort'] = $row_time;
    }

    if (($row['competition_phase_type'] ?? '') !== 'mixed_groups') {
        foreach ([$row['group_home_team'], $row['group_visitor_team']] as $group_name) {
            $group_name = trim((string) $group_name);

            if ($group_name !== '' && !in_array($group_name, $competitions_raw[$competition_id]['groups'], true)) {
                $competitions_raw[$competition_id]['groups'][] = $group_name;
            }
        }
    }
}

$agenda_sections = [];

foreach ($competitions_raw as $competition) {
    sort($competition['groups'], SORT_NATURAL | SORT_FLAG_CASE);

    if (!empty($competition['groups'])) {
        foreach ($competition['groups'] as $group_name) {
            $group_matches = array_values(array_filter($competition['matches'], static function ($match) use ($group_name) {
                return $match['group_home_team'] === $group_name || $match['group_visitor_team'] === $group_name;
            }));

            if (empty($group_matches)) {
                continue;
            }

            $agenda_sections[] = [
                'section_title' => $competition['competition_title'] . ' - ' . $group_name,
                'competition_url' => $competition['competition_url'],
                'flag_id' => $competition['flag_id'],
                'country_sort' => $competition['country_sort'],
                'federation_sort' => $competition['federation_sort'],
                'competition_order' => $competition['competition_order'],
                'first_match_sort' => f365_agenda_first_match_sort($group_matches),
                'matches' => $group_matches,
            ];
        }
    } else {
        $agenda_sections[] = [
            'section_title' => $competition['competition_title'],
            'competition_url' => $competition['competition_url'],
            'flag_id' => $competition['flag_id'],
            'country_sort' => $competition['country_sort'],
            'federation_sort' => $competition['federation_sort'],
            'competition_order' => $competition['competition_order'],
            'first_match_sort' => $competition['first_match_sort'],
            'matches' => $competition['matches'],
        ];
    }
}

foreach ($agenda_sections as &$section) {
    usort($section['matches'], 'f365_agenda_match_sort');
}
unset($section);

usort($agenda_sections, static function ($a, $b) {
    if ($a['first_match_sort'] === $b['first_match_sort']) {
        return strnatcasecmp(f365_agenda_section_sort_key($a), f365_agenda_section_sort_key($b));
    }

    return $a['first_match_sort'] <=> $b['first_match_sort'];
});
?>

<link rel="stylesheet" href="<?php echo esc_url(get_stylesheet_directory_uri() . '/flag-icon.css'); ?>">

<div class="ink-grid vertical-space">
    <?php get_template_part('template-parts/template-offer-widget'); ?>

    <div class="ink-header-f365 competicoes"
        style="background-image: linear-gradient(to left, rgba(250, 250, 250, 0) 40%, rgba(0, 0, 0, 1)), url(<?php echo esc_url($bg_image_url); ?>);">
        <div class="column-group">
            <div class="align-left all-60 medium-100 small-100 tiny-100 banner-left-content">
                <div class="headerdata">
                    <a href="#">
                        <h1><?php the_title(); ?></h1>
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
            <?php
            $today = new DateTimeImmutable('today', $display_timezone);

            for ($i = -1; $i <= 6; $i++):
                $date = $today->modify(sprintf('%+d days', $i));
                $date_value = $date->format('Y-m-d');
                $active = $date_value === $selected_date ? 'active' : '';
                ?>
                <li>
                    <a class="<?php echo esc_attr($active); ?>"
                        href="<?php echo esc_url(add_query_arg('schedule_date_input', $date_value, get_permalink())); ?>">
                        <?php echo esc_html(f365_agenda_date_label($date, $today)); ?>
                    </a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
</div>

<div class="ink-grid futebol365-container">
    <section class="left-column-block">
        <div class="breadcrumbs-f365">
            <div class="ink-navigation breadcrumbs">
                <?php if (function_exists('yoast_breadcrumb'))
                    yoast_breadcrumb(); ?>
            </div>
        </div>

        <?php if (!empty($agenda_sections)): ?>
            <?php foreach ($agenda_sections as $section): ?>
                <h3 class="condensed-400 headerTitle blue">
                    <?php if (!empty($section['flag_id'])): ?>
                        <i
                            class="hide-tiny flag-icon quarter-right-space flag-icon-<?php echo esc_attr($section['flag_id']); ?>"></i>
                    <?php endif; ?>

                    <a href="<?php echo esc_url($section['competition_url']); ?>">
                        <?php echo esc_html($section['section_title']); ?>
                    </a>
                </h3>

                <table class="ink-table ink-table-f365 alternating all-100 double-bottom-space">
                    <thead>
                        <tr>
                            <th class="all-5 small-15 tiny-15 align-left">Hora</th>
                            <th class="all-35 align-right hide-tiny hide-small">Visitado</th>
                            <th class="all-45 align-left hide-xlarge hide-large hide-medium">Jogo</th>
                            <th class="all-10 tiny-20 small-20"></th>
                            <th class="all-35 align-left hide-tiny hide-small">Visitante</th>
                            <th class="all-15 tiny-20 small-20"></th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($section['matches'] as $match): ?>
                            <?php
                            $result = f365_agenda_result_label($match);

                            $show_bet = false;
                            $match_ts = strtotime($match['date_time'] . ' UTC');
                            $now_ts = time();

                            if (
                                empty($match['situation']) &&
                                $match['home_score'] === null &&
                                $match['visitor_score'] === null &&
                                $match_ts &&
                                $now_ts > ($match_ts - (3 * DAY_IN_SECONDS)) &&
                                $now_ts < ($match_ts + 6300)
                            ) {
                                $show_bet = true;
                            }
                            ?>

                            <tr>
                                <td class="align-left">
                                    <?php echo esc_html($match['hora']); ?>
                                </td>

                                <td class="align-right hide-tiny hide-small">
                                    <a href="<?php echo esc_url($match['home_team_url']); ?>">
                                        <?php echo esc_html($match['home_team_display']); ?>
                                    </a>
                                </td>
                                <td class="align-left hide-xlarge hide-large hide-medium">
                                    <a href="<?php echo esc_url($match['home_team_url']); ?>">
                                        <?php echo esc_html($match['home_team_display']); ?>
                                    </a><span class="break"><a href="<?php echo esc_url($match['visitor_team_url']); ?>">
                                        <?php echo esc_html($match['visitor_team_display']); ?>
                                    </a></span>
                                </td>
                                <td class="align-center">
                                    <span class="ink-label ink-label-score silver">
                                        <a href="<?php echo esc_url($match['match_url']); ?>">
                                            <?php echo esc_html($result); ?>
                                        </a>
                                    </span>
                                </td>

                                <td class="align-left hide-tiny hide-small ">
                                    <a href="<?php echo esc_url($match['visitor_team_url']); ?>">
                                        <?php echo esc_html($match['visitor_team_display']); ?>
                                    </a>
                                </td>

                                <td>
                                    <?php
                                    $compare_url = home_url(
                                        '/equipa/' . (int) $match['home_team_id'] . '/comparar-com/' . (int) $match['visitor_team_id'] . '/?' . http_build_query([
                                            'edition' => $match['edition'],
                                            'step1' => $match['home_step'],
                                            'gender1' => $match['home_gender'],
                                            'modality1' => $match['home_modality'],
                                            'step2' => $match['visitor_step'],
                                            'gender2' => $match['visitor_gender'],
                                            'modality2' => $match['visitor_modality'],
                                        ])
                                    );
                                    ?>

                                    <a href="<?php echo esc_url($compare_url); ?>">
                                        <span class="ink-tooltip ink-button-related green" data-tip-text="Comparar equipas"
                                            data-tip-where="up" data-tip-color="green">C</span>
                                    </a>

                                    <?php if ($show_bet): ?>
                                        <a href="<?php echo esc_url(home_url('/goto/')); ?>" target="_blank" rel="noopener">
                                            <span class="ink-tooltip ink-button-related orange" data-tip-text="Apostar neste jogo"
                                                data-tip-where="up" data-tip-color="orange">A</span>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        <?php else: ?>
            <h3 class="condensed-400 headerTitle blue">Agenda</h3>
            <h6 class="no-info">Não existem jogos agendados para data pretendida</h6>
        <?php endif; ?>

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
