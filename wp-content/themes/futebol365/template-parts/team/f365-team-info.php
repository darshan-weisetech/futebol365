
<?php
/**
 * Team Dados Gerais template — /equipa/{id}/info/
 *
 * Reads ONLY the migrated relational tables (wp_-prefixed). No WP posts /
 * postmeta / ACF for team data or presidents.
 *
 * @package Futebol365
 */

defined('ABSPATH') || exit;
get_header();
global $wpdb;

$prefix  = $wpdb->prefix;
$post_id = $args['post_id'] ?? get_the_ID();
if (function_exists('f365_get_current_team_post')) {
    $t = f365_get_current_team_post();
    if ($t) {
        $post_id = $t->ID;
    }
}
$post_id = (int) $post_id;

// Django team id from the team post meta (fallback: parse /equipa/{id}/ from the URL).
//$team_id = (int) get_post_meta($post_id, 'django_id', true);
$team_id = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT legacy_id
         FROM {$wpdb->posts}
         WHERE ID = %d",
        $post_id
    )
);
if (!$team_id) {
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if (preg_match('#/equipa/(\d+)/#', $request_uri, $m)) {
        $team_id = (int) $m[1];
    }
}

// Sanitize edition filter parameter
$edition = isset($_GET['edition']) && '' !== $_GET['edition'] ? sanitize_text_field(wp_unslash($_GET['edition'])) : '';

/* ============================================================
 * Cached relational fetch (team row + presidents) — 24h Object Cache
 * ============================================================ */
$cache_key   = 'f365_team_info_' . $team_id . '_' . ($edition ?: 'latest');
$cache_group = 'futebol365_team_info';
$cached_data = wp_cache_get($cache_key, $cache_group);

if (false === $cached_data) {

    // 1. Team row query - Use static key indexes via explicitly typed queries
    $team_info = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                t.longname, t.shortname, t.address, t.city, t.phone, t.fax,
                t.url AS website_url, t.equipment, t.nickname, t.mascot,
                t.official_members, t.facebook, t.twitter, t.youtube, t.instagram,
                t.foundation, t.team_type, t.country_id,
                c.shortname AS country_name,
                fed.id AS federation_id, fed.longname AS federation_longname
             FROM {$prefix}team_team t
             LEFT JOIN {$prefix}country_country c        ON c.id   = t.country_id
             LEFT JOIN {$prefix}federation_federation fed ON fed.id = t.federation_id
             WHERE t.id = %d
             LIMIT 1",
            $team_id
        ),
        ARRAY_A
    );

    // 2. Optimized Edition Lookup fallback: Drop algebraic string operations (+0) to leverage index trees
    $eff_edition = $edition;
    if ('' === $eff_edition && $team_id) {
        $eff_edition = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT edition FROM {$prefix}team_teamstep
             WHERE team_id = %d
             ORDER BY edition DESC LIMIT 1",
            $team_id
        ));
    }

    $presidents_list = [];
    $tmp_president   = '';

    if (!empty($team_info)) {
        // 3. Optimize execution strategy by parsing conditional WHERE rules inside PHP
        if ('' === $eff_edition) {
            $pres_query = $wpdb->prepare(
                "SELECT pr.id AS president_id, pe.shortname AS name, pe.nationality_id AS nat_id
                 FROM {$prefix}team_teampresident tp
                 INNER JOIN {$prefix}person_president pr ON pr.id = tp.president_id
                 INNER JOIN {$prefix}person_person pe    ON pe.id = pr.person_id
                 WHERE tp.team_id = %d AND tp.president_type = 'president'
                 ORDER BY tp.`order` ASC, pr.id ASC",
                $team_id
            );
        } else {
            $pres_query = $wpdb->prepare(
                "SELECT pr.id AS president_id, pe.shortname AS name, pe.nationality_id AS nat_id
                 FROM {$prefix}team_teampresident tp
                 INNER JOIN {$prefix}person_president pr ON pr.id = tp.president_id
                 INNER JOIN {$prefix}person_person pe    ON pe.id = pr.person_id
                 WHERE tp.team_id = %d AND tp.president_type = 'president' AND tp.edition = %s
                 ORDER BY tp.`order` ASC, pr.id ASC",
                $team_id, $eff_edition
            );
        }

        $pres_rows = $wpdb->get_results($pres_query);

        if (!empty($pres_rows)) {
            foreach ($pres_rows as $pres) {
                $presidents_list[] = [
                    'id'   => (int) $pres->president_id,
                    'name' => (string) $pres->name,
                    'flag' => (int) $pres->nat_id,
                ];
            }
        }

        // 4. Fallback contextual check for temporary president fields
        if (empty($presidents_list) && $team_id) {
            if ('' === $eff_edition) {
                $tmp_query = $wpdb->prepare(
                    "SELECT tmp_president FROM {$prefix}team_teamstep
                     WHERE team_id = %d AND tmp_president IS NOT NULL AND tmp_president <> ''
                     LIMIT 1",
                    $team_id
                );
            } else {
                $tmp_query = $wpdb->prepare(
                    "SELECT tmp_president FROM {$prefix}team_teamstep
                     WHERE team_id = %d AND edition = %s AND tmp_president IS NOT NULL AND tmp_president <> ''
                     LIMIT 1",
                    $team_id, $eff_edition
                );
            }
            $tmp_president = (string) $wpdb->get_var($tmp_query);
        }
    }

    $cached_data = [
        'team_info'       => $team_info,
        'presidents_list' => $presidents_list,
        'tmp_president'   => $tmp_president,
    ];

    wp_cache_set($cache_key, $cached_data, $cache_group, DAY_IN_SECONDS);
}

// Unpack array elements
$team_info       = $cached_data['team_info'] ?: [];
$presidents_list = $cached_data['presidents_list'] ?: [];
$tmp_president   = $cached_data['tmp_president'] ?? '';

// Variables setup
$long_name        = $team_info['longname'] ?? '';
$team_foundation  = $team_info['foundation'] ?? '';
$address          = $team_info['address'] ?? '';
$city             = $team_info['city'] ?? '';
$team_phone       = $team_info['phone'] ?? '';
$team_fax         = $team_info['fax'] ?? '';
$team_website_url = $team_info['website_url'] ?? '';
$equipment        = $team_info['equipment'] ?? '';
$nickname         = $team_info['nickname'] ?? '';
$mascot           = $team_info['mascot'] ?? '';
$official_members = $team_info['official_members'] ?? '';
$team_facebook    = $team_info['facebook'] ?? '';
$team_twitter     = $team_info['twitter'] ?? '';
$team_youtube     = $team_info['youtube'] ?? '';
$team_instagram   = $team_info['instagram'] ?? '';
$team_type        = $team_info['team_type'] ?? 'club';
$country_id       = (int) ($team_info['country_id'] ?? 0);
$country_name     = $team_info['country_name'] ?? '';
$federation_id    = (int) ($team_info['federation_id'] ?? 0);
$fed_longname     = $team_info['federation_longname'] ?? '';

$full_address     = implode(', ', array_filter([$address, $city]));
$current_section  = get_query_var('section', 'details') ?: 'details';
$sections         = function_exists('futebol365_get_team_sections') ? futebol365_get_team_sections() : [];

if ($sections && !isset($sections[$current_section])) {
    $current_section = 'details';
}

// Functional closures to generate template URLs
$president_url = static function (int $id) {
    return function_exists('f365_president_url') ? f365_president_url($id) : user_trailingslashit(home_url('dirigente/' . $id));
};
$federation_url = static function (int $id) {
    return function_exists('f365_federation_url') ? f365_federation_url($id) : user_trailingslashit(home_url('federacao/' . $id));
};
?>

<main id="primary" class="site-main single-article-page">
    <?php
    get_template_part('template-parts/team/f365-team-header', null, [
        'post_id'         => $post_id,
        'current_section' => $current_section,
        'sections'        => $sections,
        'team_id'         => $team_id,
    ]);
    ?>
    <div class="ink-grid futebol365-container">
        <section class="left-column-block">
            <div class="breadcrumbs-f365">
                <div class="ink-navigation breadcrumbs">
                    <?php if (function_exists('yoast_breadcrumb')) { yoast_breadcrumb(); } ?>
                </div>
            </div>
            <h4 class="condensed-400 headerTitle blue">Dados Gerais</h4>
            <table class="ink-table alternating ink-table-f365">
                <tbody>
                    <?php if ($long_name) : ?>
                        <tr>
                            <td class="align-right all-35 font-blue"><span class="modtitle-border">Nome:</span></td>
                            <td class="all-65"><?php echo esc_html($long_name); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($team_foundation) : ?>
                        <tr>
                            <td class="align-right font-blue">Ano de Fundação:</td>
                            <td><?php echo esc_html(gmdate('Y', strtotime($team_foundation))); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($full_address) : ?>
                        <tr>
                            <td class="align-right font-blue">Sede:</td>
                            <td><?php echo wp_strip_all_tags($full_address); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($team_phone) : ?>
                        <tr>
                            <td class="align-right font-blue">Telefone:</td>
                            <td><?php echo esc_html($team_phone); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($team_fax) : ?>
                        <tr>
                            <td class="align-right font-blue">Fax:</td>
                            <td><?php echo esc_html($team_fax); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($country_name) : ?>
                        <tr>
                            <td class="align-right font-blue">País:</td>
                            <td>
                                <?php if ($country_id) : ?>
                                    <i class="hide-tiny flag-icon quarter-right-space flag-icon-<?php echo esc_attr($country_id); ?>"></i>
                                <?php endif; ?>
                                <?php echo esc_html($country_name); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($team_website_url) : ?>
                        <tr>
                            <td class="align-right font-blue">Página Internet:</td>
                            <td><a target="_blank" rel="noopener" href="<?php echo esc_url($team_website_url); ?>"><?php echo esc_html($team_website_url); ?></a></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($equipment) : ?>
                        <tr>
                            <td class="align-right font-blue">Cores:</td>
                            <td><?php echo esc_html($equipment); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($nickname) : ?>
                        <tr>
                            <td class="align-right font-blue">Alcunha:</td>
                            <td><?php echo esc_html($nickname); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($mascot) : ?>
                        <tr>
                            <td class="align-right font-blue">Mascote:</td>
                            <td><?php echo esc_html($mascot); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($official_members) : ?>
                        <tr>
                            <td class="align-right font-blue">Número de Sócios:</td>
                            <td><?php echo esc_html($official_members); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($team_facebook || $team_twitter || $team_youtube || $team_instagram) : ?>
                        <tr>
                            <td class="align-right font-blue">Redes Sociais:</td>
                            <td>
                                <?php if ($team_facebook) : ?>
                                    <a href="<?php echo esc_url('https://www.facebook.com/' . $team_facebook); ?>" target="_blank" rel="noopener">
                                        <svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true"><use href="#social-facebook"></use></svg>
                                    </a>
                                <?php endif; ?>
                                <?php if ($team_twitter) : ?>
                                    <a href="<?php echo esc_url('https://x.com/' . $team_twitter); ?>" target="_blank" rel="noopener">
                                        <svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true"><use href="#social-x"></use></svg>
                                    </a>
                                <?php endif; ?>
                                <?php if ($team_youtube) : ?>
                                    <a href="<?php echo esc_url('https://www.youtube.com/' . $team_youtube); ?>" target="_blank" rel="noopener">
                                        <svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true"><use href="#social-youtube"></use></svg>
                                    </a>
                                <?php endif; ?>
                                <?php if ($team_instagram) : ?>
                                    <a href="<?php echo esc_url('https://www.instagram.com/' . $team_instagram); ?>" target="_blank" rel="noopener">
                                        <svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true"><use href="#social-instagram"></use></svg>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php if (!empty($presidents_list)) : ?>
                        <?php foreach ($presidents_list as $pres) : ?>
                            <tr>
                                <td class="align-right font-blue">Presidente:</td>
                                <td>
                                    <a href="<?php echo esc_url($president_url((int) $pres['id'])); ?>" title="<?php echo esc_attr($pres['name']); ?>">
                                        <?php if (!empty($pres['flag'])) : ?>
                                            <i class="hide-tiny flag-icon quarter-right-space flag-icon-<?php echo esc_attr($pres['flag']); ?>"></i>
                                        <?php endif; ?>
                                        <?php echo esc_html($pres['name']); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php elseif ($tmp_president) : ?>
                        <tr>
                            <td class="align-right font-blue">Presidente:</td>
                            <td><?php echo esc_html($tmp_president); ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php if ($federation_id && $fed_longname && 'club' === $team_type) : ?>
                        <tr>
                            <td class="align-right font-blue">Federação:</td>
                            <td>
                                <a href="<?php echo esc_url($federation_url($federation_id)); ?>">
                                    <?php if ($country_id) : ?>
                                        <i class="hide-tiny flag-icon quarter-right-space flag-icon-<?php echo esc_attr($country_id); ?>"></i>
                                    <?php endif; ?>
                                    <?php echo esc_html($fed_longname); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
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
<?php
get_footer();
