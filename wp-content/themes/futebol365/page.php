<?php
/**
 * The template for displaying all pages
 *
 * This is the template that displays all pages by default.
 * Please note that this is the WordPress construct of pages
 * and that other 'pages' on your WordPress site may use a
 * different template.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package Futebol365
 */

get_header();
?>

<main id="primary" class="site-main">
	<?php get_template_part('template-parts/template-offer-widget'); ?>
	<div class="ink-grid futebol365-container">


		<section class="left-column-block">
			<?php if (!is_front_page() && !is_home()): ?>
				<div class="breadcrumbs-f365">
					<div class="ink-navigation breadcrumbs">
						<?php
						if (function_exists('yoast_breadcrumb')) {
							yoast_breadcrumb();
						}
						?>
					</div>
				</div>
			<?php endif; ?>
			<?php
			while (have_posts()):
				the_post();

				get_template_part('template-parts/content', 'page');

				// If comments are open or we have at least one comment, load up the comment template.
				if (comments_open() || get_comments_number()):
					comments_template();
				endif;

			endwhile; // End of the loop.?>

			<?php if (!is_front_page() && !is_home()): ?>
				<?php get_template_part('template-parts/template-front-page-posts'); ?>
			<?php endif; ?>
		</section>
		<section class="right-column-block">
			<?php get_sidebar(); ?>
		</section>

	</div>
</main><!-- #main -->

<?php

get_footer();
