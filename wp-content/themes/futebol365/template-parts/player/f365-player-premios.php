<?php
/**
 * Player Awards template
 */

$post_id = $args['post_id'] ?? null;

if (!$post_id) {
    $django_player_id = '';

    if (isset($_SERVER['REQUEST_URI']) && preg_match('#/jogador/([0-9]+)#i', $_SERVER['REQUEST_URI'], $matches)) {
        $django_player_id = $matches[1];
    } elseif (get_query_var('f365_player')) {
        $django_player_id = get_query_var('f365_player');
    } else {
        $django_player_id = get_query_var('name');
    }

    if (!empty($django_player_id) && is_numeric($django_player_id)) {
        global $wpdb;

        $found_id = $wpdb->get_var($wpdb->prepare("
            SELECT ID 
FROM {$wpdb->posts} 
WHERE legacy_id = %d 
  AND post_type = 'f365_player' 
LIMIT 1
        ", $django_player_id));

        $post_id = $found_id ? (int) $found_id : get_the_ID();
    } else {
        $post_id = get_the_ID();
    }
}

$current_section = $args['current_section'] ?? 'premios';
$sections = $args['sections'] ?? futebol365_get_player_sections();

if (!is_array($sections)) {
    $sections = [];
}

if (!isset($sections[$current_section])) {
    if (isset($sections['premios'])) {
        $current_section = 'premios';
    } elseif (isset($sections['awards'])) {
        $current_section = 'awards';
    } elseif (isset($sections['resume'])) {
        $current_section = 'resume';
    } elseif (!empty($sections)) {
        $current_section = array_key_first($sections);
    } else {
        $current_section = '';
    }
}

$current_config = $current_section && isset($sections[$current_section])
    ? $sections[$current_section]
    : [];

global $wpdb;

$django_player_id = get_post_meta($post_id, 'django_player_id', true);

if (empty($django_player_id) && isset($_SERVER['REQUEST_URI']) && preg_match('#/jogador/([0-9]+)#i', $_SERVER['REQUEST_URI'], $matches)) {
    $django_player_id = (int) $matches[1];
}

$ranking_table = $wpdb->prefix . 'ranking_playerranking';
$ranking_type_table = $wpdb->prefix . 'ranking_playerrankingtype';

$awards = [];

if (!empty($django_player_id)) {
    $awards = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT
                pr.id,
                pr.edition,
                pr.season,
                pr.position,
                pr.ranking_type_id,
                COALESCE(
                    NULLIF(rt.longname, ''),
                    NULLIF(rt.shortname, ''),
                    CONCAT('Prémio ', pr.ranking_type_id)
                ) AS award_name
            FROM {$ranking_table} pr
            LEFT JOIN {$ranking_type_table} rt ON rt.id = pr.ranking_type_id
            WHERE pr.player_id = %d
            ORDER BY
                CAST(SUBSTRING_INDEX(pr.edition, '-', 1) AS UNSIGNED) DESC,
                CASE
                    WHEN pr.edition LIKE '%-%' THEN 1
                    ELSE 0
                END ASC,
                pr.id DESC
            ",
            (int) $django_player_id
        )
    );
}

get_header();
?>

<main id="primary" class="site-main single-article-page">
    <?php
    get_template_part(
        'template-parts/player/f365-player-header',
        null,
        [
            'post_id' => $post_id,
            'current_section' => $current_section,
            'sections' => $sections,
        ]
    );
    ?>

    <div class="ink-grid futebol365-container">

        <section class="left-column-block">

            <div class="breadcrumbs-f365">
                <div class="ink-navigation breadcrumbs">
                    <?php yoast_breadcrumb(); ?>
                </div>
            </div>

            <h4 class="condensed-400 headerTitle top-space">
                <?php esc_html_e('Prémios', 'f365'); ?>
                <span class="headerTitle-border"></span>
            </h4>

            <?php if (!empty($awards)): ?>
                <h5 class="headerTitleSimpleBackground">
                    <?php esc_html_e('Nacionais e Internacionais de Clubes e Selecções', 'f365'); ?>
                </h5>

                <table class="ink-table ink-table-f365 alternating all-100">
                    <thead>
                        <tr>
                            <th class="align-left all-20 tiny-30 small-30">
                                <?php esc_html_e('Edição', 'f365'); ?>
                            </th>
                            <th class="align-left all-50 tiny-70 small-70">
                                <?php esc_html_e('Prémio', 'f365'); ?>
                            </th>
                            <th class="align-left all-30 hide-tiny hide-small">
                                <?php esc_html_e('Posição', 'f365'); ?>
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($awards as $award): ?>
                            <?php
                            $display_edition = !empty($award->season) ? $award->season : $award->edition;
                            $ranking_url = home_url('/rank-jogadores/' . absint($award->ranking_type_id) . '/');
                            ?>

                            <tr>
                                <td class="align-left">
                                    <?php echo esc_html($display_edition); ?>
                                </td>

                                <td class="align-left">
                                    <a href="<?php echo esc_url($ranking_url); ?>">
                                        <?php echo esc_html($award->award_name); ?>
                                    </a>

                                    <span class="break hide-xlarge hide-large hide-medium">
                                        <?php echo esc_html($award->position); ?>º
                                        <?php esc_html_e('lugar', 'futebol365'); ?>
                                    </span>
                                </td>

                                <td class="align-left hide-tiny hide-small">
                                    <?php echo esc_html($award->position); ?>º <?php esc_html_e('lugar', 'futebol365'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <h6 class="no-info">
                    <?php esc_html_e('Sem informação disponível', 'f365'); ?>
                </h6>
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