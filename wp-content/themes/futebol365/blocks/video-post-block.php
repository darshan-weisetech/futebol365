<?php
/**
 * Block Template: Video Posts Block
 */

$post_fields  = get_fields() ?: [];
$color        = $post_fields['select_color_video_section'] ?? '';
$section_link = $post_fields['video_add_link'] ?? ['url' => '#'];
$style        = ! empty( $color ) ? ' style="background:' . esc_attr( $color ) . ';"' : '';
$title        = ! empty( $post_fields['video_add_title'] ) ? $post_fields['video_add_title'] : __( 'Vídeos', 'futebol365' );

// 1. Get Selected IDs or Fallback to Latest Videos
$video_ids = $post_fields['select_video_posts'] ?? [];

if ( empty( $video_ids ) ) {
    global $wpdb;
    // Optimized to only fetch IDs
    $video_ids = $wpdb->get_col( 
        "SELECT ID FROM {$wpdb->posts} 
         WHERE post_type = 'f365_video' AND post_status = 'publish' 
         ORDER BY post_date DESC LIMIT 5"
    );
}

// Exit early if no videos exist
if ( empty( $video_ids ) ) {
    return;
}

$fallback_image = function_exists( 'futebol365_fallback_image' ) ? futebol365_fallback_image() : '';
?>

<div class="video-posts-block">

    <h3 class="condensed-400 modtitle">
        <a href="<?php echo esc_url( $section_link['url'] ?? '#' ); ?>">
            <?php echo esc_html( $title ); ?>
        </a>
        <span class="modtitle-border" <?php echo $style; ?>></span>
    </h3>

    <div class="news-list-homepage news-list-video">
        <div class="column-group half-gutters">

            <?php 
            // 2. Render Main Large Video (First Item)
            if ( isset( $video_ids[0] ) ) : 
                $main_id    = $video_ids[0];
                $main_title = get_the_title( $main_id );
                $main_thumb = get_the_post_thumbnail_url( $main_id, 'large' ) ?: $fallback_image;
                $main_date = get_the_date( 'd-m-Y', $main_id );
                $main_url   = get_permalink( $main_id );
                ?>
                <div class="all-50 hide-medium small-100 hide-tiny">
                    <article class="video-post-item">
                        <a href="<?php echo esc_url( $main_url ); ?>">
                            <figure class="ink-image ink-lazyload my-thumb-16-9 large">
                                <img src="<?php echo esc_url( $main_thumb ); ?>" class="lazyload-item my-thumb-image loaded" alt="<?php echo esc_attr( $main_title ); ?>" title="<?php echo esc_attr( $main_title ); ?>" loading="lazy" />
                                <svg class="video-thumbnail-icon video-thumbnail-icon-svg large" aria-hidden="true"><use href="#icon-play"></use></svg>
                            </figure>
                        </a>
                        <div class="half-top-space medium condensed-400 font-silver"><?php echo esc_html( $main_date ); ?></div>
                        <h2 class="extralarge condensed-700 font-white-silver">
                            <a href="<?php echo esc_url( $main_url ); ?>"><?php echo esc_html( $main_title ); ?></a>
                        </h2>
                    </article>
                </div>
            <?php endif; ?>

            <?php 
            // 3. Render Remaining Videos (Grid Loop)
            if ( count( $video_ids ) > 1 ) : ?>
                <div class="all-50 medium-100 small-100 tiny-100">
                    <div class="column-group half-gutters">
                        <?php 
                        foreach ( $video_ids as $index => $sub_id ) {
                            // Skip the first element since it's already rendered above
                            if ( $index === 0 ) continue;

                            $sub_title = get_the_title( $sub_id );
                            $sub_thumb = get_the_post_thumbnail_url( $sub_id, 'large' ) ?: $fallback_image;
                            $sub_date = get_the_date( 'd-m-Y', $sub_id );
                            $sub_url   = get_permalink( $sub_id );
                            ?>
                            <div class="all-50 tiny-100">
                                <article class="video-post-item">
                                    <a href="<?php echo esc_url( $sub_url ); ?>">
                                        <figure class="ink-image ink-lazyload my-thumb-16-9 small">
                                            <img src="<?php echo esc_url( $sub_thumb ); ?>" class="lazyload-item my-thumb-image loaded" alt="<?php echo esc_attr( $sub_title ); ?>" title="<?php echo esc_attr( $sub_title ); ?>" loading="lazy" />
                                            <svg class="video-thumbnail-icon video-thumbnail-icon-svg small" aria-hidden="true"><use href="#icon-play"></use></svg>
                                        </figure>
                                    </a>
                                    <div class="half-top-space small condensed-400 font-silver"><?php echo esc_html( $sub_date ); ?></div>
                                    <h2 class="medium condensed-700 font-white-silver">
                                        <a href="<?php echo esc_url( $sub_url ); ?>"><?php echo esc_html( $sub_title ); ?></a>
                                    </h2>
                                </article>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>

</div>