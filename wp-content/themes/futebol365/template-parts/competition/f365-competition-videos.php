<?php
get_header();
global $wpdb;
$current_post_id = $post->ID;
$current_section = 'videos';
$sections        = futebol365_get_competition_sections();

//$final_competition_id = get_post_meta( $current_post_id, 'django_competition_id', true );
$final_competition_id = get_legacy_competition_id( $current_post_id );

$competition_id = intval( $final_competition_id );
$video_table    = $wpdb->prefix . 'multimedia_video';

$per_page = 20;
$page     = isset( $_GET['pagina'] ) ? max( 1, absint( $_GET['pagina'] ) ) : 1;
$offset   = ( $page - 1 ) * $per_page;

// Total count drives both visibility and the paginator.
$total_videos = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(1) FROM {$video_table} WHERE competition_id = %d",
        $competition_id
    )
);

$num_pages = ( $total_videos > 0 ) ? (int) ceil( $total_videos / $per_page ) : 0;

$videos = array();
if ( $total_videos > 0 ) {
    $videos = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, title, url, image, datetime, `length`
               FROM {$video_table}
              WHERE competition_id = %d
              ORDER BY datetime DESC
              LIMIT %d OFFSET %d",
            $competition_id,
            $per_page,
            $offset
        ),
        OBJECT
    );
}
?>

<main id="primary" class="site-main single-article-page" <?php echo $current_post_id; ?>>

<?php
get_template_part(
    'template-parts/competition/f365-competition-header',
    null,
    array(
        'post_id'        => $current_post_id,
        'sections'       => $sections,
        'competition_id' => $final_competition_id,
    )
);
?>

    <div class="ink-grid futebol365-container">

        <section class="left-column-block">

            <div class="breadcrumbs-f365">
                <div class="ink-navigation breadcrumbs">
                    <?php yoast_breadcrumb(); ?>
                </div>
            </div>

            <h5 class="condensed-400 headerTitle blue"><?php esc_html_e( 'Vídeos', 'futebol365' ); ?></h5>

            <?php if ( ! empty( $videos ) ) : ?>

                <?php // multimedia_videos_list.html ?>
                <div class="column-group gutters">
                    <?php
                    foreach ( $videos as $video ) :
                        $video_url   = esc_url( home_url( '/multimedia/videos/' . intval( $video->id ) . '/' ) );


                        $base = home_url( '/media/' );
                        $video_image_dj = $base.$video->image;
                        $video_image = ! empty( $video_image_dj ) ? esc_url( $video_image_dj ) : esc_url( futebol365_fallback_image() );
                        $video_title = esc_attr( $video->title );
                       $video_date = ! empty( $video->datetime )
    ? mysql2date( 'd-m-Y · H:i', $video->datetime )
    : '';
                        //$video_image = $video->image ?? '';

                        //$video_image = filter_var( $video_image, FILTER_VALIDATE_URL )? $video_image : home_url( $video_image );
                    ?>
                        <div class="all-25 small-50 tiny-100">
                            <figure class="ink-image ink-lazyload half-bottom-space my-thumb-16-9" data-delta="300" data-loaded-class="loaded">
                                <a href="<?php echo $video_url; ?>">
                                    <img class="lazyload-item my-thumb-image loaded"
                                        src="<?php echo esc_url( $video_image ); ?>"
                                        title="<?php echo $video_title; ?>"
                                        alt="Futebol 365"
                                        >
                                </a>
                                <?php if ( ! empty( $video->length ) ) : ?>
                                    <figcaption class="over-bottom dark figcaption-videos"><?php echo esc_html( $video->length ); ?></figcaption>
                                <?php endif; ?>
                            </figure>
                            <h3 class="quarter-vertical-space medium uppercase condensed-400"><?php echo esc_html( $video_date ); ?></h3>
                            <h2 class="large condensed-700"><a href="<?php echo $video_url; ?>"><?php echo esc_html( $video->title ); ?></a></h2>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="all-100">
                    <?php
                    if ( isset( $page, $num_pages ) && function_exists( 'futebol365_render_pagination' ) ):
                        futebol365_render_pagination( $page, $num_pages );
                    endif;
                    ?>
                </div>

            <?php else : ?>
                <h6 class="no-info"><?php esc_html_e( 'Sem informação disponível', 'futebol365' ); ?></h6>
            <?php endif; ?>

            <?php get_template_part('template-parts/template-front-page-posts'); ?>

        </section>

        <section class="right-column-block">
            <?php
            if ( is_active_sidebar( 'post-sidebar' ) ) {
                dynamic_sidebar( 'post-sidebar' );
            } else {
                get_sidebar();
            }
            ?>
        </section>

    </div>
</main>

<?php
get_footer();
