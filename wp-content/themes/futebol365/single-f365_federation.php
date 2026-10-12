<?php 
/**
 * Federation Competition template
 */
get_header();

$current_post_id  = $args['post_id'] ?? get_the_ID();
$current_section = get_query_var( 'section', 'details' );

$sections = futebol365_get_federation_sections();

if ( ! isset( $sections[ $current_section ] ) ) {
    $current_section = 'details';
}

$current_config = $sections[ $current_section ];
?>

<main id="primary" class="site-main single-article-page">

    <?php
    get_template_part(
        'template-parts/federation/f365-federation-header',
        null,
        array( 
            'post_id'  => $current_post_id,
            'sections' => $sections,
        )
    );?>

    <div class="ink-grid futebol365-container">

        <section class="left-column-block">

           <div class="breadcrumbs-f365">
                <div class="ink-navigation breadcrumbs">
                    <?php yoast_breadcrumb(); ?>
                </div>
            </div>

            <?php
            
            get_template_part(
                'template-parts/federation/f365-federation-' . $current_config['template'],
                null,
            );
            get_template_part('template-parts/template-front-page-posts'); ?>
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