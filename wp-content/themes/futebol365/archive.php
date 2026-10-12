<?php
/**
 * The template for displaying archive pages
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
			<?php if (have_posts()): ?>

				<div class="page-header">
					<?php
					the_archive_title('<h1 class="page-title">', '</h1>');
					the_archive_description('<div class="archive-description">', '</div>');
					?>
				</div><!-- .page-header -->

				<?php
				/* Start the Loop */
				while (have_posts()):
					the_post();

					/*
					 * Include the Post-Type-specific template for the content.
					 * If you want to override this in a child theme, then include a file
					 * called content-___.php (where ___ is the Post Type name) and that will be used instead.
					 */
					get_template_part('template-parts/content', get_post_type());

				endwhile;

				the_posts_navigation();

			else:

				get_template_part('template-parts/content', 'none');

			endif;
			?>
		</section>
		<section class="right-column-block">
			<?php get_sidebar(); ?>
		</section>

	</div>

</main><!-- #main -->

<?php
get_sidebar();
get_footer();
