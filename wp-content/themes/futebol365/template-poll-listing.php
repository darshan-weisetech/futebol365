<?php 
/**
 * Template for displaying a single Poll Maker poll dynamically.
 * Template Name: Poll Listing
 */
get_header();
global $wpdb;

// 1. Setup Pagination Variables
$paged    = max( 1, absint( $_GET['pagina'] ?? 1 ) );
$per_page = 20;

// 2. Fetch Poll Items from 'sondagem' post type
$args = array(
    'post_type'      => 'sondagem',
    'posts_per_page' => $per_page,
    'paged'          => $paged,
    'post_status'    => 'publish',
    'orderby'        => 'date',
    'order'          => 'DESC',
);

$sondagem_query = new WP_Query( $args );

// 3. Get Total Counts for Pagination Calculation
$total_pages = max( 1, $sondagem_query->max_num_pages );
?>

<main id="primary" class="site-main f365-poll-page container my-5">
    
    <?php
    get_template_part(
        'template-parts/template',
        'offer-widget'
    );
    ?>
    <div class="ink-grid futebol365-container">
        <section class="left-column-block">
            
            <div class="breadcrumbs-f365">
                <nav class="ink-navigation">
                    <ul class="breadcrumbs">
                        <li><a href="<?php echo esc_url( home_url() ); ?>">Home</a></li>
                        <li class="active">Sondagens</li>
                    </ul>
                </nav>
            </div>

            <?php if ( $sondagem_query->have_posts() ) : ?>

<?php while ( $sondagem_query->have_posts() ) : $sondagem_query->the_post(); ?>

    <?php
    $current_wp_id = get_the_ID();
    $poll_table    = $wpdb->prefix . 'ayspoll_polls';

    // Resolve the internal Poll Maker ID from the CPT post using the subquery relation
    $ays_poll_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id 
         FROM {$poll_table} 
         WHERE post_id = (
             SELECT meta_value 
             FROM {$wpdb->postmeta} 
             WHERE post_id = %d 
               AND meta_key = '_ays_poll_id' 
             LIMIT 1
         )",
        $current_wp_id
    ) );

    // Fetch fallback poll details from the custom table if ID resolved
    $poll_data = null;
    if ( ! empty( $ays_poll_id ) ) {
        $poll_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$poll_table} WHERE id = %d", $ays_poll_id ) );
    }

    // Fetch total votes calculation from custom answers table
    $total_votes = 0;
    if ( ! empty( $ays_poll_id ) ) {
        $total_votes = (int) $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT SUM(votes)
                FROM {$wpdb->prefix}ayspoll_answers
                WHERE poll_id = %d
                ",
                $ays_poll_id
            )
        );
    }

    // Permalink mapping
    $poll_url = get_permalink();
    if ( empty( $poll_url ) && ! empty( $ays_poll_id ) ) {
        $poll_url = home_url( '/sondagem/detalhes/' . $ays_poll_id . '/' );
    }

    // Image Logic
    $image = '';
    if ( has_post_thumbnail() ) {
        $image = get_the_post_thumbnail_url( $current_wp_id, 'large' );
    } elseif ( ! empty( $poll_data ) && ! empty( $poll_data->image ) ) {
        if ( strpos( $poll_data->image, 'http' ) === 0 ) {
            $image = $poll_data->image;
        } else {
            $image = '/wp-content/uploads/' . ltrim( $poll_data->image, '/' );
        }
    }

    if ( empty( $image ) ) {
        $image = function_exists( 'futebol365_fallback_image' ) 
            ? futebol365_fallback_image() 
            : get_template_directory_uri() . '/assets/images/default.jpg';
    }

    // Excerpt Logic
    $excerpt = get_the_excerpt();
    if ( empty( $excerpt ) ) {
        $excerpt = wp_trim_words(
            wp_strip_all_tags( ! empty( $poll_data ) ? ( $poll_data->description ?? '' ) : '' ),
            25
        );
    }

    // Date Logic
    $creation_date = get_the_date( 'Y-m-d H:i:s' );
    if ( empty( $creation_date ) && ! empty( $poll_data ) ) {
        $poll_style    = ! empty( $poll_data->styles ) ? json_decode( stripslashes( $poll_data->styles ), true ) : [];
        $creation_date = $poll_style['create_date'] ?? '';
    }

    // Title Logic
    $poll_title = get_the_title();
    if ( empty( $poll_title ) && ! empty( $poll_data ) ) {
        $poll_title = $poll_data->title;
    }
    ?>

    <div class="news-list-cat">
        <article class="column-group gutters">
            <div class="all-30 small-100 tiny-100">
                <a href="<?php echo esc_url( $poll_url ); ?>">
                    <figure class="ink-image ink-lazyload my-thumb-3-2">
                        <img class="lazyload-item my-thumb-image" src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $poll_title ); ?>">
                    </figure>
                </a>
            </div>
            <div class="all-70 small-100 tiny-100">
                <h2 class="uppercase condensed-400">
                    <?php echo ! empty( $creation_date ) ? f365_format_date( $creation_date ) : '--'; ?>
                </h2>
                <h3 class="condensed-700">
                    <a href="<?php echo esc_url( $poll_url ); ?>"><?php echo esc_html( $poll_title ); ?></a>
                </h3>
                <h4 class="fw-400 hide-medium hide-small hide-tiny">
                    <?php echo esc_html( $excerpt ); ?>
                </h4>
                <h5 class="uppercase condensed-400">
                    <?php 
                    if ( ! empty( $ays_poll_id ) && ! empty( do_shortcode( '[poll_total_votes id="' . $ays_poll_id . '"]' ) ) ) {
                        echo do_shortcode( '[poll_total_votes id="' . $ays_poll_id . '"]' );
                    } else {
                        echo number_format_i18n( $total_votes );
                    }
                    echo esc_html__( ' votos', 'futebol365' );
                    ?>
                </h5>
            </div>
        </article>
    </div>

<?php endwhile; wp_reset_postdata(); ?>

                <?php if ( $total_pages > 1 ) : ?>
                    <div class="paginacao-new">
                        <nav class="ink-navigation">
                            <ul class="pagination">

                                <?php if ( $paged > 1 ) : ?>
                                    <li class="hide-tiny hide-small">
                                        <a href="?pagina=<?php echo $paged - 1; ?>">« Anterior</a>
                                    </li>
                                <?php endif; ?>

                                <?php
                                $adjacents = 1; 

                                for ( $i = 1; $i <= $total_pages; $i++ ) :
                                    if ( $i == 1 || $i == $total_pages || ( $i >= $paged - $adjacents && $i <= $paged + $adjacents ) ) :
                                        ?>
                                        <li>
                                            <?php if ( $i === $paged ) : ?>
                                                <span class="active"><?php echo $i; ?></span>
                                            <?php else : ?>
                                                <a href="?pagina=<?php echo $i; ?>"><?php echo $i; ?></a>
                                            <?php endif; ?>
                                        </li>
                                        <?php
                                    elseif ( $i == 2 && $paged > ($adjacents + 2) ) :
                                        ?>
                                        <li><span>...</span></li>
                                        <?php
                                    elseif ( $i == $total_pages - 1 && $paged < $total_pages - ($adjacents + 1) ) :
                                        ?>
                                        <li><span>...</span></li>
                                        <?php
                                    endif;
                                endfor;
                                ?>

                                <?php if ( $paged < $total_pages ) : ?>
                                    <li class="hide-tiny hide-small">
                                        <a href="?pagina=<?php echo $paged + 1; ?>">Seguinte »</a>
                                    </li>
                                <?php endif; ?>

                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>

            <?php else : ?>

                <h4>Sem sondagens disponíveis.</h4>

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

<?php get_footer(); ?>