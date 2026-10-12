<?php
/**
 * Front Page Posts Section — latest published posts.
 *
 * No custom table. Pulls the latest WP posts, caches the result in the object cache
 * (shared across requests) AND memoizes per-request via a global, so including
 * this part on every single/archive page does not repeat the query or the work.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

$title = 'Na Primeira Página';
if ( function_exists( 'get_field' ) ) {
    $opt_title = get_field( 'front_page_posts_title', 'option' );
    if ( $opt_title ) {
        $title = $opt_title;
    }
}

$cache_key       = 'latest_posts_v1';
$cache_group     = 'f365_front_page_posts';

/*
 * Build (or reuse) the shared latest-posts list ONCE per request:
 *  - $GLOBALS memo  → reused if the part is included several times on one page
 *  - wp_cache       → object cache; persistent across requests with Redis/Memcached
 * The list holds more than we render (8) so the per-page current-post exclusion
 * still leaves enough to show 4 — and the cache itself stays page-independent.
 */
if ( ! isset( $GLOBALS['f365_front_page_posts'] ) ) {

    $found        = false;
    $cached_posts = wp_cache_get( $cache_key, $cache_group, false, $found );

    if ( false === $found ) {

        $post_ids = get_posts(
            [
                'post_type'           => 'post',
                'post_status'         => 'publish',
                'posts_per_page'      => 4,
                'orderby'             => 'date',
                'order'               => 'DESC',
                'fields'              => 'ids',
                'no_found_rows'       => true,
                'ignore_sticky_posts' => true,
                'suppress_filters'    => true,
            ]
        );

        $cached_posts = [];

        if ( ! empty( $post_ids ) ) {
            // Prime posts + meta + terms in one shot so the getters below are in-memory.
            _prime_post_caches( $post_ids, true, true );

            $fallback = function_exists( 'futebol365_fallback_image' ) ? futebol365_fallback_image() : '';

            foreach ( $post_ids as $pid ) {
                $pid = (int) $pid;
                $post = get_post( $pid );

                $legacy_news_id = (int) ( $post->legacy_id ?? 0 );

                if ( has_post_thumbnail( $pid ) ) {
                    $image = get_the_post_thumbnail_url( $pid, 'medium_large' );
                } elseif ( $legacy_news_id > 0 && function_exists( 'f365_module_image_url' ) ) {
                    $image = f365_module_image_url( 'news', $legacy_news_id ) ?: $fallback;
                } else {
                    $image = $fallback;
                }

                $cached_posts[] = [
                    'id'    => $pid,
                    'url'   => get_permalink( $pid ),
                    'title' => get_the_title( $pid ),
                    'image' => $image,
                    'date'  => get_the_date( 'd-m-Y · H:i', $pid ),
                ];
            }
        }

        // 1h TTL keeps "latest" reasonably fresh; bust on publish (see note below).
        wp_cache_set( $cache_key, $cached_posts, $cache_group, HOUR_IN_SECONDS );
    }

    $GLOBALS['f365_front_page_posts'] = $cached_posts;
}

$cached_posts = $GLOBALS['f365_front_page_posts'];

// Latest 4.
$posts_to_show = array_slice( $cached_posts, 0, 4 );

if ( empty( $posts_to_show ) ) {
    return;
}
?>

<h2 class="condensed-400 extralarge modtitle top-space relative-title">
    <?php echo esc_html( $title ); ?>
    <span class="modtitle-border"></span>
</h2>

<div class="column-group gutters related-posts">

    <?php foreach ( $posts_to_show as $post ) : ?>

        <div class="all-25 small-50 tiny-100">

            <a href="<?php echo esc_url( $post['url'] ); ?>">
                <figure class="ink-image ink-lazyload half-bottom-space my-thumb-3-2" data-delta="300" data-loaded-class="loaded">
                    <?php if ( ! empty( $post['image'] ) ) : ?>
                        <img class="lazyload-item my-thumb-image loaded"
                             src="<?php echo esc_url( $post['image'] ); ?>"
                             onerror="this.onerror=null;this.src='<?php echo esc_url( FB_THEME_URL . '/assets/images/image-fallback.jpg' ); ?>';"
                             title="<?php echo esc_attr( $post['title'] ); ?>"
                             alt="<?php echo esc_attr( $post['title'] ); ?>"
                             loading="lazy"
                             decoding="async">
                    <?php endif; ?>
                </figure>
            </a>

            <div class="quarter-vertical-space medium condensed-400">
                <?php echo esc_html( $post['date'] ); ?>
            </div>

            <h3 class="large condensed-700">
                <a href="<?php echo esc_url( $post['url'] ); ?>">
                    <?php echo esc_html( $post['title'] ); ?>
                </a>
            </h3>

        </div>

    <?php endforeach; ?>

</div>