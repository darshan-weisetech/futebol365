<?php 
/**
 * Template Name: Banner Template
 */

get_header();
$banner_pages = get_field('add_pages', 'option'); 

$bg_image = get_field( 'bg_image', 'option' );
$current_url = home_url( add_query_arg( [], $GLOBALS['wp']->request ) );
$bg_url = get_the_post_thumbnail_url( get_the_ID(), 'full' );

$bg_url = $bg_url ?: ( $bg_image['url'] ?? '' );
$bg_url = $bg_url ?: (
    function_exists( 'futebol365_fallback_image' )
        ? futebol365_fallback_image()
        : ''
);

?>

<main id="primary" class="site-main">
    <div class="ink-grid vertical-space">
        <?php get_template_part('template-parts/template-offer-widget'); ?>
        <div class="ink-header-f365 multimedia" style="background-image: linear-gradient(to left, rgba(250, 250, 250, 0) 40%, rgba(0, 0, 0, 1)), url(<?php echo $bg_url?>);" >
            <div class="column-group">
                <div class="align-left">
                    <div class="headerdata">
                        <h1><?php the_title()?></h1>
                    </div>
                </div>
            </div>
        </div>

        <div class="nav-trigger" id="nav-trigger">
            <span>Menu</span>
        </div>

        <nav class="nav-main" id="nav-main">
            <ul>

                <?php if (!empty($banner_pages)) : ?>
                    <?php foreach ($banner_pages as $index => $row) :

                        $link  = $row['select_page'] ?? [];
                        $url   = $link['url'] ?? '#';
                        $title = $link['title'] ?? '';

                        $is_active = false; 

                        if (!empty($current_url) && rtrim($current_url, '/') === rtrim($url, '/')) {
                            $is_active = true;
                        }
                    ?>
                        <li>
                            <a class="<?php echo $is_active ? 'active' : ''; ?>"
                            href="<?php echo esc_url($url); ?>"
                            target="<?php echo esc_attr($link['target'] ?? '_self'); ?>">
                                <?php echo esc_html($title); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>

            </ul>
        </nav>
    </div>

    <div class="ink-grid futebol365-container">
    
        	<section class="left-column-block">
                <div class="breadcrumbs-f365">
                    <div class="ink-navigation breadcrumbs">
                        <?php yoast_breadcrumb(); ?>
                    </div>
                </div>
                <div class="page-article">
                    <div class="conteudo double-bottom-space">
                        <?php the_content();?>
                    </div>
                </div>
            </section>
            <section class="right-column-block">
                <?php get_sidebar();?>
            </section>

    </div>
</main><!-- #main -->

<?php

get_footer();

