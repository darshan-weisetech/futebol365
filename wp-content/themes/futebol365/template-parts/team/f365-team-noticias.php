
<?php
/**
 * Team Notícias template — /equipa/{id}/noticias/
 *
 * @package Futebol365
 */

defined('ABSPATH') || exit;
get_header();
global $wpdb;

$p = $wpdb->prefix;
$post_id = $args['post_id'] ?? get_the_ID();
$request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
$team_id = 0;

if (preg_match('#/equipa/(\d+)/#', $request_uri, $matches)) {
    $team_id = (int) $matches[1];
}
if (function_exists('f365_get_current_team_post')) {
    $t = f365_get_current_team_post();
    if ($t) {
        $post_id = $t->ID;
    }
}

$current_section = get_query_var('section', 'noticias') ?: 'noticias';
$sections = function_exists('futebol365_get_team_sections') ? futebol365_get_team_sections() : [];
if ($sections && !isset($sections[$current_section])) {
    $current_section = 'noticias';
}

$per_page = 18;
$page = isset($_GET['pagina']) ? max(1, absint($_GET['pagina'])) : 1;
$offset = ($page - 1) * $per_page;

$now_mysql = current_time('mysql');
$total_news = 0;
$cache_group = 'futebol365_news';

/*
 * COUNT must match the LISTING exactly, or the last pages come up empty.
 *
 * The listing returns DISTINCT news that (a) exist in publish_news, (b) are
 * linked to the team, and (c) have datetime <= NOW. So the count must apply the
 * same three conditions:
 * - INNER JOIN publish_news   -> drops orphan M2M rows (no matching news)
 * - n.datetime <= NOW          -> drops future-dated news
 * - COUNT(DISTINCT vt.news_id)-> collapses duplicate team links
 *
 * Cache key is updated to standard Object Cache.
 */
if ($team_id > 0) {
    $count_cache_key   = 'f365_team_news_count_' . $team_id;
    $cached_total_news = wp_cache_get($count_cache_key, $cache_group);

    if (false !== $cached_total_news) {
        $total_news = (int) $cached_total_news;
    } else {
        $total_news = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT vt.news_id)
                 FROM {$p}publish_news_teams vt
                 INNER JOIN {$p}publish_news n ON n.id = vt.news_id
                 WHERE vt.team_id = %d
                   AND n.datetime <= %s",
                $team_id,
                $now_mysql
            )
        );

        wp_cache_set($count_cache_key, $total_news, $cache_group, DAY_IN_SECONDS);
    }
}

$num_pages = ($total_news > 0) ? (int) ceil($total_news / $per_page) : 0;

if ($num_pages > 0 && $page > $num_pages) {
    $page = $num_pages;
    $offset = ($page - 1) * $per_page;
}

$news_items = [];

if ($team_id > 0) {
    $news_cache_key    = 'f365_team_news_page_' . $team_id . '_' . $page;
    $cached_news_items = wp_cache_get($news_cache_key, $cache_group);

    if (false !== $cached_news_items) {
        $news_items = $cached_news_items;
    } else {
        $news_items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT n.id, n.title, n.generic_link_title, n.generic_link_url, n.slug,
                        n.summary, n.image, n.embed_video, n.video_embed_id, n.datetime
                 FROM {$p}publish_news_teams vt FORCE INDEX (idx_team_datetime_news)
                 STRAIGHT_JOIN {$p}publish_news n FORCE INDEX (PRIMARY)
                    ON n.id = vt.news_id
                 WHERE vt.team_id = %d
                   AND vt.news_datetime <= %s
                 ORDER BY vt.news_datetime DESC, vt.news_id DESC
                 LIMIT %d OFFSET %d",
                $team_id,
                $now_mysql,
                $per_page,
                $offset
            ),
            OBJECT
        );

        wp_cache_set($news_cache_key, $news_items, $cache_group, DAY_IN_SECONDS);
    }
}

if ($news_items) {
    $news_ids = wp_list_pluck($news_items, 'id');
    $id_string = implode(',', array_map('intval', $news_ids));

    $cat_results = $wpdb->get_results(
        "SELECT nc.news_id, c.name
             FROM {$p}publish_news_categories nc
             INNER JOIN {$p}publish_newscategory c
                ON c.id = nc.newscategory_id
             WHERE nc.news_id IN ($id_string)"
    );

    $cat_map = [];

    foreach ($cat_results as $row) {
        $cat_map[(int) $row->news_id][] = $row->name;
    }

    foreach ($news_items as $item) {
        $item->category_names = isset($cat_map[(int) $item->id])
            ? implode('||', $cat_map[(int) $item->id])
            : '';
    }
}

?>

<main id="primary" class="site-main single-article-page">

    <?php
    if ($sections) {
        get_template_part('template-parts/team/f365-team-header', null, [
            'post_id' => $post_id,
            'current_section' => $current_section,
            'sections' => $sections,
            'team_id' => $team_id,
        ]);
    }
    ?>

    <div class="ink-grid futebol365-container">
        <section class="left-column-block">

            <div class="breadcrumbs-f365">
                <div class="ink-navigation breadcrumbs">
                    <?php if (function_exists('yoast_breadcrumb')) {
                        yoast_breadcrumb();
                    } ?>
                </div>
            </div>

            <h5 class="condensed-400 headerTitle blue">Notícias</h5>

            <?php if (!empty($news_items)): ?>
                <?php
                foreach ($news_items as $news):
                    $news_title = esc_attr($news->title);

                    if (empty($news->generic_link_title) && !empty($news->generic_link_url)) {
                        $news_url = esc_url($news->generic_link_url);
                    } else {
                        $news_url = esc_url(home_url('/artigo/' . $news->slug . '/'));
                    }

                    $cat_name = '';
                    if (!empty($news->category_names)) {
                        $cat_parts = explode('||', (string) $news->category_names);
                        $cat_name = esc_html($cat_parts[0]);
                    }

                    $summary = esc_html(wp_trim_words((string) $news->summary, 50, ''));
                    $news_image = !empty($news->image) ? esc_url(home_url('media/' . $news->image)) : '';
                    $has_video = (bool) (!empty($news->embed_video) || !empty($news->video_embed_id));
                    $news_date = !empty($news->datetime) ? mysql2date('d-m-Y', $news->datetime) : '';
                    $news_time = !empty($news->datetime) ? mysql2date('H:i', $news->datetime) : '';
                    $fallback = function_exists('futebol365_fallback_image') ? esc_url(futebol365_fallback_image()) : '';
                    ?>
                    <div class="news-list-cat">
                        <article class="column-group gutters">
                            <div class="all-30 small-100 tiny-100">
                                <a href="<?php echo $news_url; ?>">
                                    <figure class="ink-image my-thumb-3-2">
                                        <?php if ($news_image || $fallback): ?>
                                            <img class="lazyload-item my-thumb-image loaded"
                                                src="<?php echo $news_image ? $news_image : $fallback; ?>"
                                                title="<?php echo $news_title; ?>" alt="<?php echo $news_title; ?>"
                                                onerror="this.onerror=null; this.src='<?php echo $fallback; ?>';">
                                        <?php endif; ?>
                                        <?php if ($has_video): ?>
                                            <svg class="video-thumbnail-icon video-thumbnail-icon-svg medium" aria-hidden="true"
                                                focusable="false">
                                                <use href="#icon-play"></use>
                                            </svg>
                                        <?php endif; ?>
                                    </figure>
                                </a>
                            </div>
                            <div class="all-70 small-100 tiny-100">
                                <?php if ($cat_name): ?>
                                    <h2 class="uppercase condensed-400"><?php echo $cat_name; ?></h2>
                                <?php endif; ?>
                                <h3 class="condensed-700"><a
                                        href="<?php echo $news_url; ?>"><?php echo esc_html($news->title); ?></a></h3>
                                <?php if ($summary): ?>
                                    <h4 class="fw-400 hide-medium hide-small hide-tiny"><?php echo $summary; ?></h4>
                                <?php endif; ?>
                                <h5 class="uppercase condensed-400">
                                    <?php echo esc_html($news_date); ?> · <?php echo esc_html($news_time); ?>
                                </h5>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>

                <div class="all-100">
                    <?php futebol365_render_pagination($page, $num_pages); ?>
                </div>

            <?php else: ?>
                <h6 class="no-info">Sem informação disponível</h6>
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

<?php
get_footer();
