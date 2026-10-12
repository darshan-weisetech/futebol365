<?php
/**
 * The template for displaying Guias CPT archive page
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<main id="primary" class="site-main">
	<?php get_template_part('template-parts/template-offer-widget'); ?>
	<div class="ink-grid futebol365-container">
		<section class="left-column-block">
			<?php if ( function_exists( 'yoast_breadcrumb' ) ) : ?>
				<div class="breadcrumbs-f365">
					<div class="ink-navigation breadcrumbs">
						<?php yoast_breadcrumb(); ?>
					</div>
				</div>
			<?php endif; ?>

			<div class="heading-section">
				<h1><?php esc_html_e( 'Guias', 'futebol365' ); ?></h1>
			</div>

			<?php if ( have_posts() ) : ?>

				<?php while ( have_posts() ) : the_post(); ?>

					<?php get_template_part( 'template-parts/news-listing' ); ?>

				<?php endwhile; ?>

				<?php
				global $wp_query;

				$total_pages = min(
					20,
					(int) $wp_query->max_num_pages
				);

				$current_page = max(
					1,
					absint(
						$_GET['pagina'] ?? get_query_var( 'paged', 1 )
					)
				);

				if ( $total_pages > 1 ) {
					futebol365_render_pagination(
						$current_page,
						$total_pages
					);
				}
				?>

			<?php else : ?>

				<p>
					<?php esc_html_e( 'Sem guias disponíveis.', 'futebol365' ); ?>
				</p>

			<?php endif; ?>

		</section>

		<section class="right-column-block">

			<?php get_sidebar(); ?>

		</section>

	</div>

</main>

<?php get_footer(); ?>
