<?php

/**
 * Most-visited news — /noticias/mais-visitadas/
 *
 * ONE direct $wpdb query, standard wp_posts only. No second/count query, no
 * postmeta, no WP_Query, no helper functions, no caching. The capped window is
 * fetched once; the current page is sliced in PHP and pagination is derived
 * from the same result set.
 *
 * Filter : post_type='post', post_status='publish', post_date in last X days, <= now
 * Order  : post_date DESC, ID DESC
 * Fields : ID, post_title, post_excerpt, post_name, post_date  (used directly)
 *
 * @package Futebol365
 */

defined('ABSPATH') || exit;

get_header();

get_template_part('template-parts/template-offer-widget');

global $wpdb;

$days     = 5;
$per_page = 18;
$max      = 360;

$paged = max(1, absint($_GET['pagina'] ?? 1));

$rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT ID, post_title, post_excerpt, post_name, post_date
		 FROM {$wpdb->posts}
		 WHERE post_type = 'post'
		   AND post_status = 'publish'
		 ORDER BY post_date DESC, ID DESC
		 LIMIT %d",
		$max
	),
	ARRAY_A
);

if ( ! is_array( $rows ) ) {
	$rows = [];
}

// Pagination derived from the same result set (no separate count query).
$total_items = count($rows);
$total_pages = $total_items > 0 ? min(20, (int) ceil($total_items / $per_page)) : 0;
if ($total_pages > 0 && $paged > $total_pages) {
	$paged = $total_pages;
}

// Current page slice.
$page_rows = array_slice($rows, ($paged - 1) * $per_page, $per_page);

$fallback_image = function_exists('futebol365_fallback_image') ? futebol365_fallback_image() : '';
?>

<div class="ink-grid futebol365-container">

	<section class="left-column-block">

		<div class="breadcrumbs-f365">

			<div class="ink-navigation breadcrumbs">

				<?php
				if (function_exists('yoast_breadcrumb')) {
					yoast_breadcrumb();
				}
				?>

			</div>

		</div>

		<div class="heading-section">
			<h1>
				<?php esc_html_e( 'Notícias Futebol 365 - Destaques', 'futebol365' ); ?>
			</h1>
		</div>

		<?php if (! empty($page_rows)) : ?>

			<?php foreach ($page_rows as $row) :
				$permalink = get_permalink( $row['ID'] );
				$title     = (string) $row['post_title'];
				$image_url = get_the_post_thumbnail_url( $row['ID'], 'medium_large' );
				$excerpt   = (string) $row['post_excerpt'];
				$date_out  = $row['post_date'] !== '' ? mysql2date('d-m-Y · H:i', $row['post_date']) : '';
                $categories = get_the_category( $row['ID'] );
                $category_name = ! empty( $categories ) ? $categories[0]->name : '';
				?>

				<div class="news-list-cat">

					<article class="column-group gutters">

						<div class="all-30 small-100 tiny-100">

							<a href="<?php echo esc_url($permalink); ?>">

								<figure
									class="ink-image ink-lazyload my-thumb-3-2"
									data-delta="300">

									<?php if ($image_url) : ?>

										<img
											class="lazyload-item my-thumb-image"
											src="<?php echo esc_url($image_url); ?>"
                                            onerror="this.onerror=null;this.src='<?php echo esc_url( FB_THEME_URL . '/assets/images/no-image-list.jpg' ); ?>';"
											alt="<?php echo esc_attr($title); ?>">

									<?php else : ?>

										<img
											class="lazyload-item my-thumb-image"
											src="<?php echo esc_url($fallback_image); ?>"
											alt="<?php echo esc_attr($title); ?>">

									<?php endif; ?>

								</figure>

							</a>

						</div>

						<div class="all-70 small-100 tiny-100">
							<?php if ( ! empty( $category_name ) ) : ?>
                                <h2 class="uppercase condensed-400"><?php echo esc_html( $category_name ); ?></h2>
                            <?php endif; ?>
							<h3 class="condensed-700">

								<a href="<?php echo esc_url($permalink); ?>">

									<?php echo esc_html($title); ?>

								</a>

							</h3>

							<?php if (! empty($excerpt)) : ?>

								<h4 class="fw-400 hide-medium hide-small hide-tiny">

									<?php echo esc_html($excerpt); ?>

								</h4>

							<?php endif; ?>

							<h5 class="uppercase condensed-400">

								<?php echo esc_html($date_out); ?>

							</h5>

						</div>

					</article>

				</div>

			<?php endforeach; ?>

		<?php else : ?>

			<div class="news-list-cat">

				<article class="column-group gutters">

					<div class="all-100">

						<p>

							<?php
							esc_html_e(
								'Nenhuma notícia encontrada.',
								'f365'
							);
							?>

						</p>

					</div>

				</article>

			</div>

		<?php endif; ?>

		<?php
		$current_page = $paged;

		if ($total_pages > 1) {

			futebol365_render_pagination(
				$current_page,
				$total_pages
			);
		}
		?>

		<?php
		get_template_part(
			'template-parts/template-front-page-posts'
		);
		?>

	</section>

	<section class="right-column-block">

		<?php get_sidebar(); ?>

	</section>

</div>

<?php get_footer(); ?>