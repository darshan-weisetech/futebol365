<?php
/**
 * Single Gallery Template — /multimedia/fotogalerias/{id}/
 *
 * Routing is owned by the plugin (includes/rewrite-rule + template loader). This
 * template renders the resolved f365_gallery post. The current post is primed once
 * so the gallery template parts (content / relations) read from cache. The related
 * entity links inside gallery-content are built from the legacy photogallery mirror
 * tables by entity id — faithful to the Django page.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;
get_header();

if ( have_posts() ) :
	while ( have_posts() ) :
		the_post();

		// Prime current gallery (post + meta + terms) once for the template parts.
		$f365_gallery_id = (int) get_the_ID();
		if ( $f365_gallery_id && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( [ $f365_gallery_id ], true, true );
		}
		?>
		<main id="primary" class="site-main single-gallery-page">

			<?php
			get_template_part(
				'template-parts/gallery/gallery',
				'header'
			);
			?>

			<div class="ink-grid futebol365-container">

				<section class="left-column-block">
					<div class="breadcrumbs-f365">
						<div class="ink-navigation">
							<?php if ( function_exists( 'yoast_breadcrumb' ) ) : ?>
								<div class="ink-navigation breadcrumbs">
									<?php yoast_breadcrumb(); ?>
								</div>
							<?php endif; ?>
						</div>
					</div>

					<?php
					get_template_part(
						'template-parts/gallery/gallery',
						'content'
					);
					?>

					<?php
					get_template_part(
						'template-parts/gallery/gallery',
						'relations'
					);
					?>

					<?php
					get_template_part(
						'template-parts/template-front-page-posts'
					);
					?>

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
	endwhile;
endif;

get_footer();