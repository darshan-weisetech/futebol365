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
            <?php
            $paged = (get_query_var('paged')) ? get_query_var('paged') : ((get_query_var('page')) ? get_query_var('page') : 1);
            $term = get_queried_object();
            $args = array(
                'post_type' => 'f365_newsstand',
                'tax_query' => array(
                    array(
                        'taxonomy' => 'f365_publisher',
                        'field' => 'slug',
                        'terms' => $term->slug,
                    ),
                ),
                'posts_per_page' => 20,
                'paged' => $paged
            );
            $newsstand_query = new WP_Query($args);

            if ($newsstand_query->have_posts()):
                while ($newsstand_query->have_posts()):
                    $newsstand_query->the_post();
                    ?>
                    <div class="all-33 xlarge-25 small-50 tiny-100">
                        <h2 class="quarter-vertical-space large condensed-700"><a
                                href="<?php the_permalink(); ?>"><?php echo $term->name; ?></a></h2>
                        <h3 class="medium condensed-400"><?php echo get_the_date('d-m-Y'); ?></h3>
                        <figure class="ink-image ink-lazyload half-bottom-space" data-delta="300">
                            <a href="<?php the_permalink(); ?>">
                                <?php if (has_post_thumbnail()) {
                                    the_post_thumbnail('medium', ['class' => 'lazyload-item', 'title' => get_the_title(), 'alt' => get_the_title()]);
                                } else { ?>
                                    <img src="<?php echo esc_url(futebol365_fallback_image()); ?>" class="lazyload-item"
                                        title="<?php the_title_attribute(); ?>" alt="<?php the_title_attribute(); ?>">
                                <?php } ?>
                            </a>
                        </figure>
                    </div>
                <?php
                endwhile;
            else:
                ?>
                <div class="all-100">
                    <p><?php esc_html_e('Nenhum registro encontrado.', 'f365'); ?></p>
                </div>
                <?php
            endif;
            ?>
        </div>
        <div class="all-100">
            <?php
            $total_pages = $newsstand_query->max_num_pages;

            if ($total_pages > 1) {
                $current_page = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;

                $pages = paginate_links(array(
                    'base' => add_query_arg('pagina', '%#%'),
                    'format' => '',
                    'current' => $current_page,
                    'total' => $total_pages,
                    'type' => 'array',
                    'prev_text' => '« Anterior',
                    'next_text' => 'Seguinte »',
                    'mid_size' => 1,
                    'end_size' => 0,
                ));
                if (is_array($pages)) {
                    echo '<div class="paginacao-new"><nav class="ink-navigation"><ul class="pagination">';
                    foreach ($pages as $page) {
                        $page = str_replace(['current', 'page-numbers'], ['active', ''], $page);
                        if (strpos($page, 'Seguinte') !== false || strpos($page, 'Anterior') !== false) {
                            echo '<li class="hide-tiny hide-small">' . $page . '</li>';
                        } else {
                            echo '<li>' . $page . '</li>';
                        }
                    }
                    echo '</ul></nav></div>';
                }
            }
            wp_reset_postdata();
            ?>
        </div>
        <?php get_template_part('template-parts/template-front-page-posts'); ?>
    </section>
    <section class="right-column-block">
        <?php get_sidebar(); ?>
    </section>
</div>
<?php get_footer(); ?>