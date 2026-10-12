<?php
/**
 * Coach Notícias Template Part
 *
 * File: template-parts/coach/f365-coach-noticias.php
 *
 * DATA SOURCE: coach↔news from the cloned pivot wp_publish_news_coaches (keyed
 * by the coach's legacy id), paginated + counted in SQL and cached via the WP
 * object cache — see f365_coach_news_total() / f365_coach_news_page() in
 * inc/mp-functions.php. No WP_Query; no load-all-then-count.
 *
 * Fallback logic (per requirement):
 *   - image    : featured image if set, else legacy publish_news.image under /media/
 *   - category : first WP category if set, else legacy publish_newscategory name
 *   - excerpt  : WP excerpt if available, else legacy publish_news.summary
 *
 * Source parity: order datetime DESC, exclude future-dated, 18 per page,
 * section label "Notícias", ?pagina= pagination. HTML/markup unchanged.
 *
 * Receives via get_template_part() $args:
 *   $args['coach'] array — from f365_get_coach_data()
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

$coach   = isset( $args['coach'] ) && is_array( $args['coach'] ) ? $args['coach'] : [];
$post_id = (int) ( $coach['post_id'] ?? 0 );

if ( $post_id <= 0 ) {
	echo '<p>' . esc_html__( 'Sem notícias disponíveis.', 'futebol365' ) . '</p>';
	return;
}

$posts_per_page = 18; // source: PaginatorHandler.get_paginator(request, news, 18)
$paged          = isset( $_GET['pagina'] ) ? max( 1, (int) $_GET['pagina'] ) : 1;

// Total (cached) → pages. No loading all ids; count comes from SQL.
$total_posts = function_exists( 'f365_coach_news_total' ) ? f365_coach_news_total( $post_id ) : 0;
$total_pages = $total_posts > 0 ? (int) ceil( $total_posts / $posts_per_page ) : 0;
// print_r($total_posts);
// die;

if ( $total_pages > 0 && $paged > $total_pages ) {
	$paged = $total_pages;
}

// One page (cached); also primes the page's WP posts for permalink/thumbnail/category.
$items = function_exists( 'f365_coach_news_page' ) ? f365_coach_news_page( $post_id, $paged, $posts_per_page ) : [];

if ( empty( $items ) ) {
	echo '<p>' . esc_html__( 'Sem notícias disponíveis.', 'futebol365' ) . '</p>';
	return;
}
?>

	<h5 class="condensed-400 headerTitle yellow">
        <?php esc_html_e( 'Notícias', 'futebol365' ); ?>
    </h5>

	<?php foreach ( $items as $item ) :
		$wp_id = (int) $item['wp_post_id'];
		if ( $wp_id <= 0 ) {
			continue;
		}
		$permalink = get_permalink( $wp_id );

		// Title: WP title, else legacy via post object (already primed).
		$title = $item['title'] !== '' ? $item['title'] : get_the_title( $wp_id );

		// Category: WP first, else legacy.
		$cat_name = '';
		$wp_cats  = get_the_category( $wp_id );
		if ( ! empty( $wp_cats ) && ! is_wp_error( $wp_cats ) ) {
			$cat_name = (string) $wp_cats[0]->name;
		}
		if ( $cat_name === '' ) {
			$cat_name = $item['legacy_cat_name'];
		}

		// Excerpt: WP excerpt if available, else legacy summary.
		$excerpt = get_the_excerpt( $wp_id );
		if ( $excerpt === '' && $item['legacy_summary'] !== '' ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( $item['legacy_summary'] ), 55 );
		}

		// Date.
		$date_str = get_the_date( 'd-m-Y · H:i', $wp_id );

		// Image: featured image first, else legacy /media/ path.
		$image_url = '';

		if ( has_post_thumbnail( $wp_id ) ) {
			$image_url = get_the_post_thumbnail_url( $wp_id, 'medium' );
		} elseif ( function_exists( 'f365_news_legacy_image_url' ) ) {
			$image_url = f365_news_legacy_image_url( $item['legacy_image'] );
		}

		$image_url = $image_url ?: futebol365_fallback_image();

	?>
		<div class="news-list-cat">
			<article class="column-group gutters">
				<div class="all-30 small-100 tiny-100">
					<a href="<?php echo esc_url( $permalink ); ?>">
						<figure class="ink-image ink-lazyload my-thumb-3-2">
						<img
							src="<?php echo esc_url( $image_url ); ?>"
							class="lazyload-item my-thumb-image"
							title="<?php echo esc_attr( $title ); ?>"
							alt="<?php echo esc_attr( $title ); ?>"
							onerror="this.onerror=null;this.src='<?php echo esc_url( FB_THEME_URL . '/assets/images/no-image-list.jpg' ); ?>';">
						</figure>
					</a>
				</div>
				<div class="all-70 small-100 tiny-100">
					<h2 class="uppercase condensed-400">
						<?php echo esc_html( $cat_name ); ?>
					</h2>
					<h3 class="condensed-700">
						<a href="<?php echo esc_url( $permalink ); ?>">
							<?php echo esc_html( $title ); ?>
						</a>
					</h3>
					<h4 class="fw-400 hide-medium hide-small hide-tiny">
						<?php echo esc_html( $excerpt ); ?>
					</h4>
					<h5 class="uppercase condensed-400">
						<?php echo esc_html( $date_str ); ?>
					</h5>
				</div>
			</article>
		</div>
	<?php endforeach; ?>

	<div class="all-100">
		<div class="paginacao-new">
			<nav class="ink-navigation">
				<ul class="pagination">

					<?php if ( $total_pages > 1 ) : ?>

						<?php if ( $paged > 1 ) : ?>
							<li class="hide-tiny hide-small">
								<a href="<?php echo esc_url( add_query_arg( 'pagina', $paged - 1 ) ); ?>">« <?php esc_html_e( 'Anterior', 'futebol365' ); ?></a>
							</li>
						<?php endif; ?>

						<?php
						$links = paginate_links( [
							'base'      => add_query_arg( 'pagina', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $total_pages,
							'prev_next' => false,
							'type'      => 'array',
							'end_size'  => 1,
							'mid_size'  => 2,
						] );

						if ( ! empty( $links ) ) {
							foreach ( $links as $link ) {
								if ( strpos( $link, 'current' ) !== false ) {
									echo '<li>' . str_replace( 'page-numbers current', 'active', $link ) . '</li>';
								} else {
									echo '<li>' . $link . '</li>';
								}
							}
						}
						?>

						<?php if ( $paged < $total_pages ) : ?>
							<li class="hide-tiny hide-small">
								<a href="<?php echo esc_url( add_query_arg( 'pagina', $paged + 1 ) ); ?>"><?php esc_html_e( 'Seguinte', 'futebol365' ); ?> »</a>
							</li>
						<?php endif; ?>

					<?php endif; ?>

				</ul>
			</nav>
		</div>
	</div>