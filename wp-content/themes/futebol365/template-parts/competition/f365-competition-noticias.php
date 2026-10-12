<?php
get_header();
global $wpdb;

$current_post_id = get_the_ID();
$current_section = 'noticias';
$sections        = futebol365_get_competition_sections();

//$final_competition_id = get_post_meta( $current_post_id, 'django_competition_id', true );
$final_competition_id = get_legacy_competition_id( $current_post_id );
$competition_id       = intval( $final_competition_id );

$news_table     = $wpdb->prefix . 'publish_news';
$newscat_table  = $wpdb->prefix . 'publish_newscategory';
$news_cat_m2m   = $wpdb->prefix . 'publish_news_categories';

$per_page = 18;
$page     = isset( $_GET['pagina'] ) ? max( 1, absint( $_GET['pagina'] ) ) : 1;
$offset   = ( $page - 1 ) * $per_page;
$now      = current_time( 'mysql' ); 

// OPTIMIZATION: Combined into a single query using SQL_CALC_FOUND_ROWS to minimize DB roundtrips
$news_items = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT SQL_CALC_FOUND_ROWS id, title, summary, image, datetime, slug,
                generic_link_title, generic_link_url, embed_video, video_embed_id
           FROM {$news_table}
          WHERE competition_id = %d
          LIMIT %d OFFSET %d",
        $competition_id,
        $per_page,
        $offset
    ),
    OBJECT
);

$total_news = (int) $wpdb->get_var( "SELECT FOUND_ROWS()" );
$num_pages  = ( $total_news > 0 ) ? (int) ceil( $total_news / $per_page ) : 0;

$category_map = array();
if ( ! empty( $news_items ) ) {
    $news_ids     = wp_list_pluck( $news_items, 'id' );
    $placeholders = implode( ',', array_fill( 0, count( $news_ids ), '%d' ) );

    $cat_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT nc.news_id, c.name
               FROM {$news_cat_m2m} nc
               JOIN {$newscat_table} c ON c.id = nc.newscategory_id
              WHERE nc.news_id IN ($placeholders)
              ORDER BY c.name ASC",
            $news_ids
        ),
        OBJECT
    );

    foreach ( $cat_rows as $cr ) {
        if ( ! isset( $category_map[ $cr->news_id ] ) ) {
            $category_map[ $cr->news_id ] = $cr->name;
        }
    }
}
?>

<main id="primary" class="site-main single-article-page" data-post-id="<?php echo esc_attr( $current_post_id ); ?>">

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

            <h5 class="condensed-400 headerTitle blue"><?php _e( 'Notícias', 'futebol365' ); ?></h5>

            <?php if ( ! empty( $news_items ) ) : ?>

                <?php
                foreach ( $news_items as $news ) :

                    
                    $news_url = esc_url( home_url( '/artigo/' . $news->slug . '/' ) );

                    $news_title = esc_attr( $news->title );
                    $base = home_url( '/media/' );
                    $news_image_dj = $base.$news->image;
                    $news_image = ! empty( $news_image_dj ) ? esc_url( $news_image_dj ) : esc_url( futebol365_fallback_image() );
                    $news_cat   = isset( $category_map[ $news->id ] ) ? $category_map[ $news->id ] : '';
                    $news_date  = ! empty( $news->datetime ) ? mysql2date( 'd-m-Y', $news->datetime ) : '';
                    $news_time  = ! empty( $news->datetime ) ? mysql2date( 'H:i', $news->datetime ) : '';
                    $has_video  = ( ! empty( $news->embed_video ) || ! empty( $news->video_embed_id ) );
                ?>
                <div class="news-list-cat">
                    <article class="column-group gutters">
                        <div class="all-30 small-100 tiny-100">
                            <a href="<?php echo $news_url; ?>">
                                <figure class="ink-image ink-lazyload my-thumb-3-2" data-delta="300" data-loaded-class="loaded">
                                    <img class="lazyload-item my-thumb-image loaded"
                                         src="<?php echo $news_image; ?>"
                                         title="<?php echo $news_title; ?>"
                                         alt="<?php echo $news_title; ?>"
                                         onerror="this.onerror=null; this.src='<?php echo esc_url( futebol365_fallback_image() ); ?>';">
                                         
                                    <?php if ( $has_video ) : ?>
                                        <svg class="video-thumbnail-icon video-thumbnail-icon-svg medium" aria-hidden="true" focusable="false"><use href="#icon-play"></use></svg>
                                    <?php endif; ?>
                                </figure>
                            </a>
                        </div>
                        <div class="all-70 small-100 tiny-100">
                            <h2 class="uppercase condensed-400"><?php echo esc_html( $news_cat ); ?></h2>
                            <h3 class="condensed-700"><a href="<?php echo $news_url; ?>"><?php echo esc_html( $news->title ); ?></a></h3>
                            <h4 class="fw-400 hide-medium hide-small hide-tiny"><?php echo esc_html( wp_trim_words( $news->summary, 50, '…' ) ); ?></h4>
                            <h5 class="uppercase condensed-400">
                                <?php echo esc_html( $news_date ); ?> · <?php echo esc_html( $news_time ); ?>
                            </h5>
                        </div>
                    </article>
                </div>
                <?php endforeach;

                if ( isset( $page, $num_pages ) && function_exists( 'futebol365_render_pagination' ) ):
                    futebol365_render_pagination( $page, $num_pages );
                endif;

                else : ?>
                    <h6 class="no-info"><?php _e( 'Sem informação disponível', 'futebol365' ); ?></h6>
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