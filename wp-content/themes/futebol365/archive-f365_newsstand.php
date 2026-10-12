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
            $paged = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
            $posts_per_page = 20;

            $terms = get_terms([
                'taxonomy' => 'f365_publisher',
                'hide_empty' => false,
            ]);

            $visible_terms = [];

            foreach ($terms as $term) {
                $is_visible = get_field('is_visible', 'f365_publisher_' . $term->term_id);

                if ($is_visible) {
                    $visible_terms[] = $term;
                }
            }

            // Step 3: pagination on terms
            $total_terms = count($visible_terms);
            $offset = ($paged - 1) * $posts_per_page;
            $paged_terms = array_slice($visible_terms, $offset, $posts_per_page);
            ?>

            <?php if (!empty($paged_terms)): ?>
                <?php foreach ($paged_terms as $term):

                    // Step 4: get latest post for this term
                    $post_query = new WP_Query([
                        'post_type' => 'f365_newsstand',
                        'posts_per_page' => 1,
                        'tax_query' => [
                            [
                                'taxonomy' => 'f365_publisher',
                                'field' => 'term_id',
                                'terms' => $term->term_id,
                            ]
                        ]
                    ]);

                    if ($post_query->have_posts()):
                        while ($post_query->have_posts()):
                            $post_query->the_post();
                            ?>
                            <div class="all-33 xlarge-25 small-50 tiny-100 parent-backfull-img">
                                <div class="heading">
                                    <h2 class="quarter-vertical-space large condensed-700">
                                        <a href="<?php echo esc_url(get_term_link($term)); ?>">
                                            <?php echo esc_html($term->name); ?>
                                        </a>
                                    </h2>

                                    <h3 class="medium condensed-400">
                                        <?php echo esc_html(get_the_date('d-m-Y')); ?>
                                    </h3>
                                </div>
                                <figure class="ink-image ink-lazyload half-bottom-space backfull-img">
                                    <a href="<?php the_permalink(); ?>">
                                        <?php if (has_post_thumbnail()): ?>
                                            <?php the_post_thumbnail('medium', [
                                                'class' => 'lazyload-item',
                                                'title' => esc_attr(get_the_title()),
                                                'alt' => esc_attr(get_the_title()),
                                            ]); ?>
                                        <?php else: ?>
                                            <img src="<?php echo esc_url(futebol365_fallback_image()); ?>" class="lazyload-item"
                                                title="<?php echo esc_attr(get_the_title()); ?>"
                                                alt="<?php echo esc_attr(get_the_title()); ?>">
                                        <?php endif; ?>
                                    </a>
                                </figure>
                            </div>
                            <?php
                        endwhile;
                        wp_reset_postdata();
                    endif;

                endforeach; ?>
            <?php else: ?>
                <p><?php echo esc_html_e('Sem informação disponível', 'f365'); ?></p>
            <?php endif; ?>
        </div>
        <div class="all-100">
            <?php
            $total_pages = ceil($total_terms / $posts_per_page);

            if ($total_pages > 1) {

                $pages = paginate_links(array(
                    'base' => add_query_arg('pagina', '%#%'),
                    'format' => '',
                    'current' => $paged,
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
                        $page = str_replace(array('current', 'page-numbers'), array('active', ''), $page);

                        if (strpos($page, 'Seguinte') !== false || strpos($page, 'Anterior') !== false) {
                            echo '<li class="hide-tiny hide-small">' . $page . '</li>';
                        } else {
                            echo '<li>' . $page . '</li>';
                        }
                    }

                    echo '</ul></nav></div>';
                }
            }
            ?>
        </div>
        <?php get_template_part('template-parts/template-front-page-posts'); ?>
    </section>
    <section class="right-column-block">
        <?php get_sidebar(); ?>
    </section>
</div>
<?php get_footer(); ?>