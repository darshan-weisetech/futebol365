<?php

/**
 * Single Post Template
 *
 * @package Futebol365
 */

get_header();
?>

<main id="primary" class="site-main single-article-page">

    <div class="ink-grid vertical-space">
        <?php get_template_part('template-parts/template-offer-widget'); ?>
    </div>

    <div class="ink-grid futebol365-container">
        <section class="left-column-block">
            <div class="breadcrumbs-f365">
                <div class="ink-navigation breadcrumbs">
                    <?php yoast_breadcrumb(); ?>
                </div>
            </div>
            <div class="entry-content">
                <h1 class="wp-block-heading"><?php the_title(); ?></h1>
                <div class="column-group gutters double-top-space bottom-space">
                    
                    <div class="all-30 small-100 tiny-100">
                        <?php 
                        global $wpdb;
                        $poll_table = $wpdb->prefix . 'ayspoll_polls';

                        // Get the current WordPress ID dynamically inside loop context
                        $current_wp_id = 0;
                        $current_wp_id = get_the_ID();
                        
                        // Get the AYS Poll ID from the custom post meta key '_ays_poll_id' in one query
                        $ays_poll_id = 0;
                        if ( $current_wp_id != 0 )  {
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
                        }else{
                            $request_path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
                            if ( preg_match( '#/sondagem/detalhes/([0-9]+)/?#', $request_path, $matches ) ) {
                                $ays_poll_id = (int) $matches[1];
                            }
                        }

                        // Fallback to custom_poll shortcode if still empty
                        if ( empty( $ays_poll_id ) ) {
                            $ays_poll_id = (int) do_shortcode( '[custom_poll]' );
                        }

                        // $image_url = '';
                        // if ( ! empty( $ays_poll_id ) ) {
                        //     $image_url = $wpdb->get_var( $wpdb->prepare( "SELECT image FROM {$poll_table} WHERE id = %d", $ays_poll_id ) );
                        // }

                        // Image Logic
                        $image_url = '';
                        if ( has_post_thumbnail() ) {
                            $image_url = get_the_post_thumbnail_url( $current_wp_id, 'large' );
                        } elseif ( ! empty( $poll_data ) && ! empty( $poll_data->image ) ) {
                            if ( strpos( $poll_data->image, 'http' ) === 0 ) {
                                $image_url = $poll_data->image;
                            } else {
                                $image_url = '/wp-content/uploads/' . ltrim( $poll_data->image, '/' );
                            }
                        }

                        if ( empty( $image_url ) ) {
                            $image_url = function_exists( 'futebol365_fallback_image' ) 
                                ? futebol365_fallback_image() 
                                : get_template_directory_uri() . '/assets/images/default.jpg';
                        }

                        // Fallback to post thumbnail or theme fallback image
                        // if ( empty( $image_url ) ) {
                        //     // Check if the post has a featured image, otherwise use the fallback function
                        //     if ( has_post_thumbnail() ) {
                        //         $image_url = get_the_post_thumbnail_url( get_the_ID(), 'large' );
                        //     } else {
                        //         $image_url = futebol365_fallback_image();
                        //     }
                        // }

                        ?>
                        <a href="<?php the_permalink(); ?>">
                            <figure class="ink-image ink-lazyload" data-delta="300">
                                <img decoding="async" src="<?php echo esc_url( $image_url ); ?>" class="lazyload-item loaded" alt="<?php the_title_attribute(); ?>">
                            </figure>
                        </a>
                    </div>      
                    
                    <div class="all-70 small-100 tiny-100">
                        <?php 
                        // 1. Grab the raw numeric AYS Poll ID mapping from your clean shortcode
                        if ( ! empty( $ays_poll_id ) ) {
                            
                            // 2. Fetch the active status meta using the custom defined core constant
                            $is_active = get_post_meta( $current_wp_id, F365_SONDAGEM_ACTIVE_META, true );

                            // Check if the user has already voted on this poll
                            $has_voted = false;
                            if ( function_exists( 'futebol365_has_user_voted_poll' ) ) {
                                $has_voted = futebol365_has_user_voted_poll( $ays_poll_id );
                            }

                            // 3. Condition check: If active flag matches '1' AND the user hasn't voted yet, show voting form. Else show results.
                            if ( '1' === (string) $is_active && ! $has_voted ) {
                                echo do_shortcode( '[ays_poll id="' . esc_attr( $ays_poll_id ) . '"]' );
                            } else {
                                echo do_shortcode( '[ayspoll_results id="' . esc_attr( $ays_poll_id ) . '"]' );
                            }

                            // 4. Print total votes using the perfectly mapped dynamic target ID 
                            echo '<p class="poll-total-votes-label">';
                            echo esc_html__( 'Total de votos: ', 'futebol365' ) .
                            '<strong>' .
                            do_shortcode( '[poll_total_votes id="' . esc_attr( $ays_poll_id ) . '"]' ) .
                            '</strong>';
                            echo '</p>';

                        } else {
                            echo '<p>Sondagem não encontrada.</p>';
                        }
                        ?>
                    </div>
                </div>
            </div>
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
?>