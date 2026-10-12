<?php
/**
 * Related Posts
 *
 * @package Futebol365
 */
$current_post_id        = (int) get_the_ID();
$related_title          = 'Relacionadas';
$related_posts_per_page = 8;

$related_ids = f365_get_cached_related_posts( $current_post_id );
if ( empty( $related_ids ) ) {
    return;
}

// Normalize, drop the current post, then keep only what we render.
$related_ids = array_map( 'intval', (array) $related_ids );
$related_ids = array_values( array_filter( $related_ids, static fn( $id ) => $id !== $current_post_id ) );
$related_ids = array_slice( $related_ids, 0, $related_posts_per_page );

if ( empty( $related_ids ) ) {
    return;
}

$related_posts = get_posts(
    [
        'post_type'              => 'post',
        'post_status'            => 'publish',
        'post__in'               => $related_ids,
        'post__not_in'           => [ $current_post_id ], // belt-and-braces exclude
        'orderby'                => 'post__in',
        'posts_per_page'         => $related_posts_per_page,
        'no_found_rows'          => true,   // skip pagination counting
        'update_post_meta_cache' => true,   // primes thumbnails in one query
        'update_post_term_cache' => false,  // no taxonomies used here
    ]
);

if ( empty( $related_posts ) ) {
    return;
}

$fallback_image = function_exists( 'futebol365_fallback_image' ) ? futebol365_fallback_image() : '';
?>
<h2 class="condensed-400 extralarge modtitle relative-title">
    <?php echo esc_html( $related_title ); ?>
    <span class="modtitle-border"></span>
</h2>
<div class="column-group gutters related-posts">
    <?php
    // Non-global loop var ($related_post, not $post) → global $post is untouched,
    // so no setup_postdata()/wp_reset_postdata() needed. Getters take the object
    // directly and read from the meta cache primed by get_posts() above.
    foreach ( $related_posts as $related_post ) :
        $permalink  = get_permalink( $related_post );
        $title      = get_the_title( $related_post );
        $title_attr = esc_attr( $title );
        // $image      = get_the_post_thumbnail_url( $related_post->ID, 'medium_large' ) ?: $fallback_image;
        $date       = get_the_date( 'd-m-Y · H:i', $related_post );
        $legacy_news_id = ! empty($related_post->legacy_id) ? (int) $related_post->legacy_id: 0;
        if (has_post_thumbnail($related_post->ID)) {
            $image = get_the_post_thumbnail_url($related_post->ID, 'medium_large');
        } elseif ($legacy_news_id > 0 && function_exists('f365_module_image_url')) {
            $image = f365_module_image_url('news', $legacy_news_id);
        } else {
            $image = $fallback_image;
        }

        ?>
        <div class="all-25 small-50 tiny-100">
            <article class="related-post-item">
                <a href="<?php echo esc_url( $permalink ); ?>">
                    <figure class="ink-image ink-lazyload half-bottom-space my-thumb-3-2" data-delta="300" data-loaded-class="loaded">
                        <img class="lazyload-item my-thumb-image"
                             src="<?php echo esc_url( $image ); ?>"
                             loading="lazy"
                             decoding="async"
                             onerror="this.onerror=null;this.src='<?php echo esc_url( FB_THEME_URL . '/assets/images/image-fallback.jpg' ); ?>';"
                             title="<?php echo $title_attr; ?>"
                             alt="<?php echo $title_attr; ?>">
                    </figure>
                </a>
                <div class="quarter-vertical-space medium condensed-400">
                    <?php echo esc_html( $date ); ?>
                </div>
                <h3 class="large condensed-700">
                    <a href="<?php echo esc_url( $permalink ); ?>">
                        <?php echo esc_html( $title ); ?>
                    </a>
                </h3>
            </article>
        </div>
    <?php endforeach; ?>
</div>