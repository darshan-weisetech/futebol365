<?php
/**
 * The template for displaying f365_federation archive page
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
			<?php if (have_posts()) { ?>

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
					 */
					get_template_part('template-parts/content', get_post_type());

				endwhile;

				global $wp_query;
				$total_pages = (int) $wp_query->max_num_pages;
				$current_page = max( 1, get_query_var( 'paged', 1 ) );

				if ( $total_pages > 1 ) {
					$big = 999999999;
					$links = paginate_links( [
						'base'      => str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) ),
						'format'    => '?paged=%#%',
						'current'   => $current_page,
						'total'     => $total_pages,
						'prev_text' => '&laquo; ' . __( 'Anterior', 'futebol365' ),
						'next_text' => __( 'Seguinte', 'futebol365' ) . ' &raquo;',
						'type'      => 'array',
						'end_size'  => 1,
						'mid_size'  => 2,
					] );

					if ( ! empty( $links ) ) {
						?>
						<div class="paginacao-new">
							<nav class="ink-navigation">
								<ul class="pagination">
									<?php
									foreach ( $links as $link ) {
										$li_classes = array();

										if ( strpos( $link, 'current' ) !== false ) {
											$li_classes[] = 'active';
											$link = str_replace( 'page-numbers current', 'page-numbers active', $link );
										}
										if ( strpos( $link, 'prev' ) !== false ) {
											$li_classes[] = 'hide-tiny hide-small';
										}
										if ( strpos( $link, 'next' ) !== false ) {
											$li_classes[] = 'hide-tiny hide-small';
										}
										$class_attr = ! empty( $li_classes ) ? ' class="' . implode( ' ', $li_classes ) . '"' : '';

										echo "<li{$class_attr}>" . $link . "</li>";
									}
									?>
								</ul>
							</nav>
						</div>
						<?php
					}
				}

			} else {

				get_template_part('template-parts/content', 'none');

			}
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
