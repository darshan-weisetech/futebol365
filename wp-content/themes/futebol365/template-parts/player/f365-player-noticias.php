<?php
/**
 * Player News template test
 */
get_header();

$post_id = $args['post_id'] ?? get_the_ID();
$fed_type = $args['fed_type'] ?? '';
$current_section = get_query_var('section', 'details');
$sections = $args['sections'] ?? futebol365_get_player_sections();

if (!isset($sections[$current_section])) {
    $current_section = 'noticias';
}

$current_config = $sections[$current_section];

if (!function_exists('f365_player_news_table_name')) {
    function f365_player_news_table_name($table, &$exists = null)
    {
        global $wpdb;
        static $tables = [];
        static $all_tables = null;

        if ($all_tables === null) {
            $all_tables = $wpdb->get_col("SHOW TABLES");
        }

        if (isset($tables[$table])) {
            $res = $tables[$table];
            $exists = in_array($res, $all_tables);
            return $res;
        }

        $prefixed_table = $wpdb->prefix . $table;

        if (in_array($prefixed_table, $all_tables)) {
            $tables[$table] = $prefixed_table;
            $exists = true;
        } elseif (in_array($table, $all_tables)) {
            $tables[$table] = $table;
            $exists = true;
        } else {
            $tables[$table] = $prefixed_table;
            $exists = false;
        }

        return $tables[$table];
    }
}

if (!function_exists('f365_player_news_article_url')) {
    function f365_player_news_article_url($slug)
    {
        return site_url('/artigo/' . trim((string) $slug, '/') . '/');
    }
}


?>

<main id="primary" class="site-main single-article-page">
    <?php
    get_template_part(
        'template-parts/player/f365-player-header',
        null,
        ['post_id' => $post_id]
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
                <?php esc_html_e('Notícias', 'f365'); ?>
                <span class="headerTitle-border"></span>
            </h4>
            <?php
            global $wpdb;

            $news_players_table = f365_player_news_table_name('publish_news_players');
            $news_table = f365_player_news_table_name('publish_news');
            $news_categories_table = f365_player_news_table_name('publish_news_categories', $exists_cats);
            $news_category_table = f365_player_news_table_name('publish_newscategory', $exists_cat);
            $has_category_tables = $exists_cats && $exists_cat;

            $join_category = "";
            $category_field = ", '' AS category_name";

            if ($has_category_tables) {
                $category_field = ", c.name AS category_name";
                $join_category = "
                    LEFT JOIN (
                        SELECT nc2.news_id, MIN(c2.name) as name
                        FROM {$news_categories_table} nc2
                        INNER JOIN {$news_category_table} c2 ON c2.id = nc2.newscategory_id
                        GROUP BY nc2.news_id
                    ) c ON c.news_id = n.id";
            }

            /* =========================
               1. PLAYER ID (FIXED)
            ========================= */
            $django_player_id = 0;

            if (isset($_SERVER['REQUEST_URI']) && preg_match('#/jogador/([0-9]+)#i', $_SERVER['REQUEST_URI'], $matches)) {
                $django_player_id = (int) $matches[1];
            } elseif (get_query_var('f365_player')) {
                $django_player_id = (int) get_query_var('f365_player');
            } elseif (!empty($post_id)) {
                $django_player_id = (int) get_post_meta($post_id, 'django_player_id', true);
            }

            // STOP if no player id
            if (!$django_player_id) {
                echo '<h5>Player not found</h5>';
                return;
            }

            /* =========================
               2. PAGINATION
            ========================= */
            $per_page = 18;
            $paged = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
            $offset = ($paged - 1) * $per_page;

            /* =========================
               3. CACHE CONFIG
            ========================= */
            $cache_group = 'player_news_v4'; // Changed version to force refresh
            $cache_key = $django_player_id . '_p' . $paged;

            /* =========================
               4. GET FROM wp_cache
            ========================= */
            $cached = wp_cache_get($cache_key, $cache_group);

            if ($cached && isset($cached['data'], $cached['total_pages'])) {
                $news_data = $cached['data'];
                $total_pages = $cached['total_pages'];
            } else {
                /* =========================
                   5. TOTAL COUNT (Optimized Caching)
                ========================= */
                $total_count_key = $django_player_id . '_news_total_items';
                $total_items = wp_cache_get($total_count_key, $cache_group);

                /* =========================
                   6. MAIN QUERY (Extreme Optimization)
                ========================= */
                // 6a. Get Total Items (Highly Cached)
                if ($total_items === false) {
                    // Use a more efficient count query - EXISTS is often faster than JOIN for large counts
                    $total_items = (int) $wpdb->get_var($wpdb->prepare("
                        SELECT COUNT(*)
                        FROM {$news_players_table} AS np
                        WHERE np.player_id = %d
                        AND EXISTS (SELECT 1 FROM {$news_table} AS n WHERE n.id = np.news_id)
                    ", $django_player_id));

                    // Cache total items for 1 hour
                    wp_cache_set($total_count_key, $total_items, $cache_group, 3600);
                }

                // 6b. Get Data (Optimized Join - Avoiding SQL_CALC_FOUND_ROWS)
                // Using a direct join which is usually faster for small limits without FOUND_ROWS
                $news_data = $wpdb->get_results($wpdb->prepare("
                    SELECT n.id, n.title, n.summary, n.datetime, n.image, n.slug
                    FROM {$news_players_table} AS np
                    INNER JOIN {$news_table} AS n ON n.id = np.news_id
                    WHERE np.player_id = %d
                    ORDER BY n.datetime DESC
                    LIMIT %d OFFSET %d
                ", $django_player_id, $per_page, $offset));

                $total_pages = $total_items > 0 ? ceil($total_items / $per_page) : 1;

                /* =========================
                   6.2 FETCH WP POST IDs (Separately)
                ========================= */
                if (!empty($news_data)) {

                    $slugs = wp_list_pluck($news_data, 'slug');

                    // Safety: empty check
                    if (!empty($slugs)) {

                        $slug_placeholders = implode(',', array_fill(0, count($slugs), '%s'));

                        // Fetch posts
                        $wp_posts_raw = $wpdb->get_results($wpdb->prepare("
                            SELECT ID, post_name 
                            FROM {$wpdb->posts} 
                            WHERE post_name IN ($slug_placeholders) 
                            AND post_type IN ('post')
                        ", $slugs));

                        // Create slug => ID map
                        $wp_posts_map = [];

                        if (!empty($wp_posts_raw)) {
                            foreach ($wp_posts_raw as $row) {
                                $wp_posts_map[$row->post_name] = (int) $row->ID;
                            }
                        }

                        // Assign WP post ID to each news
                        foreach ($news_data as &$news) {
                            $news->wp_post_id = $wp_posts_map[$news->slug] ?? 0;
                        }
                        unset($news);
                    }
                }
                if ($has_category_tables && !empty($news_data)) {
                    $news_ids = wp_list_pluck($news_data, 'id');
                    $ids_placeholder = implode(',', array_fill(0, count($news_ids), '%d'));

                    $cat_results = $wpdb->get_results($wpdb->prepare("
                        SELECT nc.news_id, c.name as category_name
                        FROM {$news_categories_table} nc
                        INNER JOIN {$news_category_table} c ON c.id = nc.newscategory_id
                        WHERE nc.news_id IN ($ids_placeholder)
                    ", $news_ids));

                    $categories_map = [];
                    if (!empty($cat_results)) {
                        foreach ($cat_results as $row) {
                            // If multiple categories, we take the "first" one (simulating MIN)
                            if (!isset($categories_map[$row->news_id])) {
                                $categories_map[$row->news_id] = $row->category_name;
                            }
                        }
                    }

                    foreach ($news_data as &$news) {
                        $news->category_name = isset($categories_map[$news->id]) ? $categories_map[$news->id] : '';
                    }
                    unset($news);
                }

                /* =========================
                   7. SAVE TO wp_cache (300s)
                ========================= */
                wp_cache_set($cache_key, [
                    'data' => $news_data,
                    'total_pages' => $total_pages
                ], $cache_group, 300);
            }
            ?>

            <?php if (!empty($news_data)): ?>

                <?php foreach ($news_data as $news): ?>
                    <?php $article_url = f365_player_news_article_url($news->slug); ?>
                    <div class="news-list-cat">
                        <article class="column-group gutters">

                            <div class="all-30 small-100 tiny-100">
                                <a href="<?php echo esc_url($article_url); ?>">
                                    <figure class="ink-image ink-lazyload my-thumb-3-2">
                                        <?php
                                        $image_url = '';
                                        $wp_post_id = 0;

                                        /**
                                         * 1. PRE-FETCHED: WordPress post ID
                                         */
                                        $wp_post_id = $news->wp_post_id ?? 0;


                                        /**
                                         * 2. TRY: Featured Image
                                         */
                                        if ($wp_post_id && has_post_thumbnail($wp_post_id)) {
                                            $image_url = get_the_post_thumbnail_url($wp_post_id, 'medium');
                                        }

                                        /**
                                         * 3. FALLBACK: Legacy image
                                         */
                                        if (empty($image_url)) {
                                            $dir = defined('F365_MEDIA_DIR') ? F365_MEDIA_DIR : 'media';

                                            $base = function_exists('f365_src_base_url')
                                                ? rtrim(f365_src_base_url($dir), '/')
                                                : rtrim(home_url('/' . trim($dir, '/')), '/');

                                            if (!empty($news->image)) {
                                                if (preg_match('#^https?://#i', $news->image)) {
                                                    $image_url = $news->image;
                                                } else {
                                                    $image_url = $base . '/' . ltrim($news->image, '/');
                                                }
                                            }
                                        }

                                        /**
                                         * 4. FINAL FALLBACK
                                         */
                                        if (empty($image_url)) {
                                            $image_url = function_exists('futebol365_fallback_image')
                                                ? futebol365_fallback_image()
                                                : get_template_directory_uri() . '/assets/images/no-image-list.jpg';
                                        }
                                        ?>

                                        <img src="<?php echo esc_url($image_url); ?>" class="lazyload-item my-thumb-image"
                                            alt="<?php echo esc_attr($news->title); ?>"
                                            title="<?php echo esc_attr($news->title); ?>"
                                            onerror="this.onerror=null;this.src='<?php echo esc_url(get_template_directory_uri() . '/assets/images/no-image-list.jpg'); ?>';">
                                    </figure>
                                </a>
                            </div>

                            <div class="all-70 small-100 tiny-100">
                                <?php if (!empty($news->category_name)): ?>
                                    <h2 class="condensed-700">
                                        <?php echo esc_html($news->category_name); ?>
                                    </h2>
                                <?php endif; ?>

                                <h3 class="condensed-700">
                                    <a href="<?php echo esc_url($article_url); ?>">
                                        <?php echo esc_html($news->title); ?>
                                    </a>
                                </h3>

                                <?php if (!empty($news->summary)): ?>
                                    <h4 class="fw-400 hide-medium hide-small hide-tiny">
                                        <?php echo esc_html($news->summary); ?>
                                    </h4>
                                <?php endif; ?>

                                <h5 class="uppercase condensed-400">
                                    <?php echo date('d-m-Y · H:i', strtotime($news->datetime)); ?>
                                </h5>
                            </div>

                        </article>
                    </div>
                <?php endforeach; ?>

                <!-- CUSTOM PAGINATION -->
                <?php if ($total_pages > 1): ?>
                    <div class="all-100">
                        <div class="paginacao-new">
                            <?php futebol365_render_pagination($paged, $total_pages); ?>
                        </div>
                    </div>
                <?php endif; ?>

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