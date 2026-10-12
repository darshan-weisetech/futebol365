<?php get_header(); ?>
<?php get_template_part('template-parts/template-offer-widget'); ?>
<div class="ink-grid futebol365-container">
    <section class="left-column-block">
        <div class="breadcrumbs-f365">
            <div class="ink-navigation breadcrumbs">
                <?php yoast_breadcrumb(); ?>
            </div>
        </div>
        <div class="column-group gutters">
            <div class="all-100">
                <h1 class="extralarge condensed-700"><?php
                $terms = get_the_terms(get_the_ID(), 'f365_publisher');
                if ($terms && !is_wp_error($terms)) {
                    echo $terms[0]->name;
                }
                ?></h1>
                <h2 class="bottom-space medium condensed-400">
                    <?php echo esc_html('Edição de ', 'futebol365') . get_the_date('d-m-Y'); ?></h2>
                <div class="ink-image bottom-space">
                    <?php if (has_post_thumbnail()) {

                        the_post_thumbnail('full', [
                            'class' => 'lazyload-item',
                            'title' => get_the_title(),
                            'alt' => get_the_title()
                        ]);

                    } else { ?>

                        <img class="lazyload-item" src="<?php echo esc_url(futebol365_fallback_image()); ?>"
                            title="<?php the_title_attribute(); ?>" alt="<?php the_title_attribute(); ?>">

                    <?php } ?>

                </div>
            </div>
        </div>
        <?php get_template_part('template-parts/template-front-page-posts'); ?>
    </section>
    <section class="right-column-block">
        <?php get_sidebar(); ?>
    </section>
</div>
<?php get_footer(); ?>

