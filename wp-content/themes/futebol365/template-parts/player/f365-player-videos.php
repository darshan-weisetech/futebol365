<?php
/**
 * Player Videos template
 */
get_header();

$post_id = $args['post_id'] ?? get_the_ID();
$fed_type = $args['fed_type'] ?? '';
$current_section = get_query_var('section', 'videos');
$sections = $args['sections'] ?? futebol365_get_player_sections();

if (!isset($sections[$current_section])) {
    $current_section = 'videos';
}

$current_config = $sections[$current_section];
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
                <?php esc_html_e('Vídeos', 'f365'); ?>
                <span class="headerTitle-border"></span>
            </h4>

            <?php
            global $wpdb;

            // ── 1. Resolve django_player_id ──────────────────────────────────────────
            $django_player_id = 0;

            if (isset($_SERVER['REQUEST_URI']) && preg_match('#/jogador/([0-9]+)#i', $_SERVER['REQUEST_URI'], $matches)) {
                $django_player_id = (int) $matches[1];
            } elseif (get_query_var('f365_player')) {
                $django_player_id = (int) get_query_var('f365_player');
            } elseif ($post_id) {
                $django_player_id = (int) get_post_meta($post_id, 'django_player_id', true);
            }

            // ── 2. Pagination setup ──────────────────────────────────────────────────
            $per_page = 20;
            $paged = isset($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
            $offset = ($paged - 1) * $per_page;

            // ── 3. Cache setup ───────────────────────────────────────────────────────
            $videos = [];
            $total_pages = 0;

            if (!empty($django_player_id)) {
                $cache_group = 'player_videos';

                // ── 1. Cache Total Count separately (10 mins) ─────────────────────────
                $count_cache_key = $django_player_id . '_total_count';
                $total_count = wp_cache_get($count_cache_key, $cache_group);

                if (false === $total_count) {
                    $total_count = (int) $wpdb->get_var($wpdb->prepare("
                        SELECT COUNT(*)
                        FROM {$wpdb->prefix}multimedia_video v
                        INNER JOIN {$wpdb->prefix}multimedia_video_players mvp
                            ON mvp.video_id = v.id
                        WHERE mvp.player_id = %d
                    ", $django_player_id));
                    wp_cache_set($count_cache_key, $total_count, $cache_group, 600);
                }

                $total_pages = $total_count > 0 ? (int) ceil($total_count / $per_page) : 0;

                // ── 2. Cache Page results (5 mins) ────────────────────────────────────
                $videos_cache_key = $django_player_id . '_p' . $paged;
                $videos = wp_cache_get($videos_cache_key, $cache_group);

                if (false === $videos) {
                    $videos = $wpdb->get_results($wpdb->prepare("
                        SELECT
                            v.id,
                            v.title,
                            v.url,
                            v.image,
                            v.datetime,
                            v.length,
                            v.views,
                            v.votes,
                            v.file_type,
                            v.highlight
                        FROM {$wpdb->prefix}multimedia_video v
                        INNER JOIN {$wpdb->prefix}multimedia_video_players mvp
                            ON mvp.video_id = v.id
                        WHERE mvp.player_id = %d
                        ORDER BY v.datetime DESC
                        LIMIT %d OFFSET %d
                    ", $django_player_id, $per_page, $offset));

                    wp_cache_set($videos_cache_key, $videos, $cache_group, 300);
                }
            }
            ?>

            <?php if (!empty($videos)): ?>

                <div class="column-group gutters player-videos">

                    <?php foreach ($videos as $video): ?>

                        <?php
                        // Build video URL (detail page on futebol365)
                        $video_link = home_url('/multimedia/videos/' . $video->id . '/');

                        // Thumbnail: check media path or fallback
                        if (!function_exists('f365_player_video_image_url')) {
                            function f365_player_video_image_url($video): string
                            {
                                $dir = defined('F365_MEDIA_DIR') ? F365_MEDIA_DIR : 'media';

                                $base = function_exists('f365_src_base_url')
                                    ? rtrim(f365_src_base_url($dir), '/')
                                    : rtrim(home_url('/' . trim($dir, '/')), '/');

                                if (is_object($video) && !empty($video->image)) {
                                    return $base . '/' . ltrim($video->image, '/');
                                }

                                $video_id = is_object($video) && !empty($video->id) ? (int) $video->id : 0;

                                if ($video_id > 0) {
                                    return $base . '/images/videos/' . $video_id . '.jpg';
                                }

                                return function_exists('futebol365_fallback_image') ? futebol365_fallback_image() : '';
                            }
                        }

                        $thumb_url = f365_player_video_image_url($video);

                        if (empty($thumb_url) && function_exists('futebol365_fallback_image')) {
                            $thumb_url = futebol365_fallback_image();
                        }

                        // Format date
                        $date_fmt = !empty($video->datetime)
                            ? date_i18n('d-m-Y', strtotime($video->datetime))
                            : '';
                        ?>

                        <div class="all-25 small-50 tiny-100">

                            <figure class="ink-image ink-lazyload half-bottom-space my-thumb-16-9">

                                <a href="<?php echo esc_url($video_link); ?>">
                                    <img src="<?php echo esc_url($thumb_url); ?>" class="my-thumb-image"
                                        alt="<?php echo esc_attr($video->title); ?>"
                                        title="<?php echo esc_attr($video->title); ?>"
                                        onerror="this.onerror=null;this.src='<?php echo esc_url(FB_THEME_URL . '/assets/images/no-image-list.jpg'); ?>';">

                                </a>

                                <?php if (!empty($video->length)): ?>
                                    <figcaption class="over-bottom dark figcaption-videos">
                                        <?php echo esc_html($video->length); ?>
                                    </figcaption>
                                <?php endif; ?>

                            </figure>

                            <?php if ($date_fmt): ?>
                                <h3 class="quarter-vertical-space medium uppercase condensed-400">
                                    <?php echo esc_html($date_fmt); ?>
                                </h3>
                            <?php endif; ?>

                            <h2 class="large condensed-700">
                                <a href="<?php echo esc_url($video_link); ?>">
                                    <?php echo esc_html($video->title); ?>
                                </a>
                            </h2>

                        </div>

                    <?php endforeach; ?>

                </div>

                <?php futebol365_render_pagination($paged, $total_pages); ?>

            <?php else: ?>

                <h6 class="no-info"><?php esc_html_e('Sem informação disponível', 'f365'); ?></h6>

            <?php endif; ?>


            <!-- Extra Posts -->
            <?php get_template_part('template-parts/template-front-page-posts'); ?>

        </section>

        <!-- Sidebar -->
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