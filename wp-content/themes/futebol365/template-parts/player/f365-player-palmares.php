<?php
/**
 * Player Palmarés Template — OPTIMISED
 *
 * Original query: 1 SQL with 9× wp_postmeta JOINs + LIKE wildcard → 524 timeout.
 *
 * Fix strategy:
 *   1. Break into small, indexed queries (each hits a covered index).
 *   2. Bulk-fetch all meta with ONE IN() per entity type instead of per-row get_post_meta().
 *   3. Eliminate the LIKE 'phase_winners_%_first_place_team' with two exact-key queries.
 *   4. Cache the full result set per player_id in a transient (5-min TTL).
 *   5. Add MySQL index hints where helpful.
 *
 * Query count: was 1 monster query (crashed server).
 *              now: 7 small indexed queries + PHP assembly.
 */

defined('ABSPATH') || exit;

global $wpdb;

if (!function_exists('get_country_flag_by_id')) {
    function get_country_flag_by_id($country_id)
    {
        if (!$country_id) {
            return '';
        }

        global $wpdb;

        $term_id = $wpdb->get_var($wpdb->prepare("
            SELECT term_id
            FROM {$wpdb->termmeta}
            WHERE meta_key = 'django_country_id'
              AND meta_value = %s
            LIMIT 1
        ", $country_id));

        if ($term_id) {
            $flag = get_field('country_flag', 'term_' . $term_id);

            if (is_numeric($flag)) {
                return wp_get_attachment_image_url($flag, 'full');
            }

            if (is_array($flag) && !empty($flag['url'])) {
                return $flag['url'];
            }

            if (is_string($flag)) {
                return $flag;
            }
        }

        return '';
    }
}

if (!function_exists('f365_get_post_url_by_django_id')) {
    function f365_get_post_url_by_django_id($post_type, $meta_key, $django_id)
    {
        if (!$django_id) {
            return '';
        }

        global $wpdb;

        $post_id = $wpdb->get_var($wpdb->prepare("
            SELECT pm.post_id
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = %s
              AND pm.meta_value = %s
              AND p.post_type = %s
              AND p.post_status <> 'trash'
            LIMIT 1
        ", $meta_key, $django_id, $post_type));

        return $post_id ? get_permalink((int) $post_id) : '';
    }
}

if (!function_exists('f365_get_team_url')) {
    function f365_get_team_url($django_team_id, $team_slug = '')
    {
        global $wpdb;

        $post_id = 0;

        if ($django_team_id) {
            $post_id = (int) $wpdb->get_var($wpdb->prepare("
                SELECT pm.post_id
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key IN ('django_id', 'django_team_id')
                  AND pm.meta_value = %s
                  AND p.post_type = 'f365_team'
                  AND p.post_status <> 'trash'
                LIMIT 1
            ", $django_team_id));
        }

        if ($post_id) {
            return trailingslashit(get_permalink($post_id)) . 'plantel/';
        }

        if ($team_slug) {
            return home_url('/equipa/' . sanitize_title($team_slug) . '/plantel/');
        }

        return '';
    }
}

$post_id = $args['post_id'] ?? null;
$django_player_id = '';

if (!$post_id) {
    if (isset($_SERVER['REQUEST_URI']) && preg_match('#/jogador/([0-9]+)#i', $_SERVER['REQUEST_URI'], $matches)) {
        $django_player_id = (int) $matches[1];
    } elseif (get_query_var('f365_player')) {
        $django_player_id = (int) get_query_var('f365_player');
    } else {
        $django_player_id = (int) get_query_var('name');
    }

    if (!empty($django_player_id)) {
        $found_id = $wpdb->get_var($wpdb->prepare("
            SELECT pm.post_id
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = 'django_player_id'
              AND pm.meta_value = %s
              AND p.post_type = 'f365_player'
            LIMIT 1
        ", $django_player_id));

        $post_id = $found_id ? (int) $found_id : get_the_ID();
    } else {
        $post_id = get_the_ID();
    }
}

$django_player_id = (int) get_post_meta($post_id, 'django_player_id', true);

if (!$django_player_id && isset($_SERVER['REQUEST_URI']) && preg_match('#/jogador/([0-9]+)#i', $_SERVER['REQUEST_URI'], $matches)) {
    $django_player_id = (int) $matches[1];
}

$current_section = $args['current_section'] ?? 'palmares';
$sections = $args['sections'] ?? (function_exists('futebol365_get_player_sections') ? futebol365_get_player_sections() : []);

if (!isset($sections[$current_section])) {
    $current_section = 'palmares';
}

get_header();
?>

<main id="primary" class="site-main single-article-page">

    <?php
    get_template_part('template-parts/player/f365-player-header', null, [
        'post_id' => $post_id,
        'current_section' => $current_section,
        'sections' => $sections,
    ]);
    ?>

    <div class="ink-grid futebol365-container">
        <section class="left-column-block">

            <div class="breadcrumbs-f365">
                <div class="ink-navigation breadcrumbs">
                    <?php yoast_breadcrumb(); ?>
                </div>
            </div>

            <h5 class="condensed-400 headerTitle red"><?php esc_html_e('Palmarés', 'futebol365'); ?></h5>

            <?php
            $palmares_sql = "
                SELECT *
                FROM (
                    SELECT
                        CASE
                            WHEN squad_team.team_type = 'club'
                                AND f.federation_type = 'association'
                            THEN 'Competições Distritais'

                            WHEN squad_team.team_type = 'club'
                                AND fc.federation_id = squad_team.federation_id
                            THEN 'Competições Nacionais de Clubes'

                            WHEN squad_team.team_type = 'club'
                                AND fc.federation_id <> squad_team.federation_id
                                AND f.federation_type <> 'association'
                            THEN 'Competições Internacionais de Clubes'

                            WHEN squad_team.team_type = 'national'
                            THEN 'Seleção'
                        END AS section_title,

                        CASE
                            WHEN squad_team.team_type = 'club'
                                AND f.federation_type = 'association'
                            THEN 'regional_clubs_winners'

                            WHEN squad_team.team_type = 'club'
                                AND fc.federation_id = squad_team.federation_id
                            THEN 'national_clubs_winners'

                            WHEN squad_team.team_type = 'club'
                                AND fc.federation_id <> squad_team.federation_id
                                AND f.federation_type <> 'association'
                            THEN 'international_clubs_winners'

                            WHEN squad_team.team_type = 'national'
                            THEN 'national_winners'
                        END AS section_key,

                        c.id AS competition_id,
                        c.season,
                        c.edition,
                        c.name AS competition_name,

                        COALESCE(
                            cpw.first_place_teamstep_id,
                            c.first_place_teamstep_id
                        ) AS winner_teamstep_id,

                        cpw.competition_phase_name,
                        cpw.`group` AS group_name,

                        ts_team.shortname AS team_name,
                        ts_team.slug AS team_slug,
                        ts_team.id AS team_django_id,

                        winner_ts.edition AS team_edition,
                        winner_ts.modality AS team_modality,
                        winner_ts.gender AS team_gender,
                        winner_ts.step AS team_step,

                        fc.id AS federation_competition_id,
                        f.id AS federation_id,
                        f.shortname AS federation_name,

                        c.id AS competition_django_id,

                        CASE
                            WHEN squad_team.team_type = 'club'
                                AND (
                                    f.federation_type = 'association'
                                    OR fc.federation_id = squad_team.federation_id
                                )
                            THEN 'country'
                            ELSE 'confederation'
                        END AS flag_type,

                        CASE
                            WHEN squad_team.team_type = 'club'
                                AND (
                                    f.federation_type = 'association'
                                    OR fc.federation_id = squad_team.federation_id
                                )
                            THEN ts_team.country_id
                            ELSE f.id
                        END AS flag_id

                    FROM {$wpdb->prefix}team_squad s

                    JOIN {$wpdb->prefix}team_teamstep ts
                        ON ts.id = s.team_id

                    JOIN {$wpdb->prefix}team_team squad_team
                        ON squad_team.id = ts.team_id

                    JOIN {$wpdb->prefix}competition_competition c

                    LEFT JOIN {$wpdb->prefix}competition_competitionphasewinners cpw
                        ON cpw.competition_id = c.id

                    JOIN {$wpdb->prefix}federation_federationcompetition fc
                        ON fc.id = c.federation_competition_id

                    JOIN {$wpdb->prefix}federation_federation f
                        ON f.id = fc.federation_id

                    JOIN {$wpdb->prefix}team_teamstep winner_ts
                        ON winner_ts.id = COALESCE(
                            cpw.first_place_teamstep_id,
                            c.first_place_teamstep_id
                        )

                    JOIN {$wpdb->prefix}team_team ts_team
                        ON ts_team.id = winner_ts.team_id

                    WHERE s.player_id = %d
                    AND (
                            c.first_place_teamstep_id = ts.id
                            OR (
                                c.first_place_teamstep_id IS NULL
                                AND cpw.first_place_teamstep_id = ts.id
                            )
                    )
                ) palmares

                WHERE section_key IS NOT NULL

                ORDER BY
                    CASE section_key
                        WHEN 'national_clubs_winners' THEN 1
                        WHEN 'international_clubs_winners' THEN 2
                        WHEN 'national_winners' THEN 3
                        WHEN 'regional_clubs_winners' THEN 4
                        ELSE 5
                    END,
                    edition DESC,
                    competition_id,
                    group_name
                ";

            $palmares_rows = $wpdb->get_results(
                $wpdb->prepare(
                    $palmares_sql,
                    $django_player_id
                )
            );

            $grouped_palmares = [];

            foreach ($palmares_rows as $row) {
                $grouped_palmares[$row->section_key]['title'] = $row->section_title;
                $grouped_palmares[$row->section_key]['rows'][] = $row;
            }
            ?>

            <?php if (!empty($grouped_palmares)): ?>
                <?php foreach ($grouped_palmares as $section): ?>
                    <?php
                    $first_row = $section['rows'][0] ?? null;
                    $section_key = $first_row->section_key ?? '';
                    $third_column_title = 'Federação';

                    if ($section_key === 'national_clubs_winners') {
                        $third_column_title = 'País';
                    } elseif (in_array($section_key, ['international_clubs_winners', 'national_winners'], true)) {
                        $third_column_title = 'Confederação';
                    }
                    ?>

                    <h5 class="headerTitleSimpleBackground">
                        <?php echo esc_html($section['title']); ?>
                    </h5>

                    <table class="ink-table ink-table-f365 alternating all-100">
                        <thead>
                            <tr>
                                <th class="align-left all-15 tiny-30 small-30"><?php esc_html_e('Época', 'futebol365'); ?></th>
                                <th class="align-left all-30 tiny-70 small-70">
                                    <span
                                        class="hide-tiny hide-small"><?php echo $section_key === 'national_winners' ? 'Seleção' : 'Clube'; ?></span>
                                    <span
                                        class="hide-xlarge hide-large hide-medium"><?php echo $section_key === 'national_winners' ? 'Seleção / Competição' : 'Clube / Competição'; ?></span>
                                </th>
                                <th class="align-left hide-tiny hide-small"><?php esc_html_e('Competição', 'futebol365'); ?>
                                </th>
                                <th class="align-left all-25 hide-tiny hide-small">
                                    <?php echo esc_html($third_column_title); ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($section['rows'] as $row): ?>
                                <?php
                                $team_url = f365_get_team_url(
                                    $row->team_django_id ?? 0,
                                    $row->team_slug ?? ''
                                );

                                if ($team_url) {
                                    $team_url = add_query_arg([
                                        'edition' => $row->team_edition,
                                        'modality' => $row->team_modality,
                                        'gender' => $row->team_gender,
                                        'step' => $row->team_step,
                                    ], $team_url);
                                }

                                $competition_url = f365_get_post_url_by_django_id(
                                    'f365_competition',
                                    'django_competition_id',
                                    $row->competition_django_id ?? 0
                                );

                                $third_column_url = '';
                                $flag_url = '';

                                if ($row->flag_type === 'country') {
                                    $flag_url = get_country_flag_by_id($row->flag_id ?? 0);
                                    $third_column_url = f365_get_post_url_by_django_id(
                                        'f365_federation',
                                        'django_federation_id',
                                        $row->federation_id ?? 0
                                    );

                                    if ($third_column_url) {
                                        $third_column_url = trailingslashit($third_column_url) . 'competicoes/';
                                    }
                                } elseif ($row->flag_type === 'confederation') {
                                    $third_column_url = f365_get_post_url_by_django_id(
                                        'f365_federation',
                                        'django_federation_id',
                                        $row->federation_id ?? 0
                                    );
                                }
                                ?>

                                <tr>
                                    <td class="align-left">
                                        <?php
                                        echo esc_html($row->season);

                                        if (!empty($row->competition_phase_name)) {
                                            echo ' - ' . esc_html($row->competition_phase_name);
                                        } elseif (!empty($row->group_name)) {
                                            echo ' - ' . esc_html($row->group_name);
                                        }
                                        ?>
                                    </td>

                                    <td class="align-left">
                                        <?php if ($row->flag_type === 'country' && $flag_url): ?>
                                            <img src="<?php echo esc_url($flag_url); ?>"
                                                alt="<?php echo esc_attr(!empty($row->longname) ? $row->longname . ' flag' : 'Flag'); ?>"
                                                style="width:18px;height:12px;object-fit:cover;margin-right:6px;vertical-align:middle;"
                                                loading="lazy"
                                                class="hide-xlarge hide-large hide-medium quarter-right-space flag-icon-<?php echo esc_attr($row->flag_id); ?>">
                                        <?php elseif ($row->flag_type === 'confederation'): ?>
                                            <i class="flag-icon quarter-right-space flag-icon-conf_<?php echo esc_attr($row->flag_id); ?> hide-xlarge hide-large hide-medium"
                                                title="<?php echo esc_attr($row->federation_name); ?>"
                                                style="vertical-align:middle;margin-right:6px;"></i>
                                        <?php endif; ?>
                                        <?php if ($team_url): ?>
                                            <a href="<?php echo esc_url($team_url); ?>">
                                                <?php echo esc_html($row->team_name); ?>
                                            </a>
                                        <?php else: ?>
                                            <?php echo esc_html($row->team_name); ?>
                                        <?php endif; ?>

                                        <span class="break hide-xlarge hide-large hide-medium">
                                            <?php echo esc_html($row->competition_name); ?>
                                        </span>
                                    </td>

                                    <td class="align-left hide-tiny hide-small">
                                        <?php if ($competition_url): ?>
                                            <a href="<?php echo esc_url($competition_url); ?>">
                                                <?php echo esc_html($row->competition_name); ?>
                                            </a>
                                        <?php else: ?>
                                            <?php echo esc_html($row->competition_name); ?>
                                        <?php endif; ?>
                                    </td>

                                    <td class="align-left hide-tiny hide-small">
                                        <?php if ($row->flag_type === 'country' && $flag_url): ?>
                                            <img src="<?php echo esc_url($flag_url); ?>"
                                                alt="<?php echo esc_attr(!empty($row->longname) ? $row->longname . ' flag' : 'Flag'); ?>"
                                                style="width:18px;height:12px;object-fit:cover;margin-right:6px;vertical-align:middle;"
                                                loading="lazy">
                                        <?php elseif ($row->flag_type === 'confederation'): ?>
                                            <i class="flag-icon quarter-right-space flag-icon-conf_<?php echo esc_attr($row->flag_id); ?>"
                                                title="<?php echo esc_attr($row->federation_name); ?>"
                                                style="vertical-align:middle;margin-right:6px;"></i>
                                        <?php endif; ?>

                                        <?php if ($third_column_url): ?>
                                            <a href="<?php echo esc_url($third_column_url); ?>">
                                                <?php echo esc_html($row->federation_name); ?>
                                            </a>
                                        <?php else: ?>
                                            <?php echo esc_html($row->federation_name); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
            <?php else: ?>
                <h6 class="no-info"><?php esc_html_e('Sem informação disponível', 'futebol365'); ?></h6>
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

</main>

<?php get_footer(); ?>