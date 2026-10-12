<?php
/**
 * Gallery Archive — /multimedia/fotogalerias/
 *
 * Galleries are the `f365_gallery` CPT. Detail URL comes from get_permalink().
 *
 * All tabs share ONE pool (the latest galleries, offset 0) — same data — but
 * each tab re-sorts that pool for DISPLAY:
 *   /fotogalerias/            (default / Últimas)   : post_date DESC  (newest first)
 *   /fotogalerias/mais-vistas/ (Mais Vistas)        : post_date ASC   (oldest first)
 *   /fotogalerias/destaques/   (Em Destaque)        : post_title ASC  (A→Z)
 *
 * Section is read from get_query_var('f365_section'); if empty it is recovered
 * from the request URI, matching the gallery routing used elsewhere.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;
get_header();

/** Cover image for a gallery post: featured image first, then likely meta keys. */
if ( ! function_exists( 'f365_gallery_cover_url' ) ) :
	function f365_gallery_cover_url( int $post_id ): string {
		$thumb = get_the_post_thumbnail_url( $post_id, 'medium_large' );
		if ( $thumb ) {
			return (string) $thumb;
		}
		foreach ( array( 'gallery_image', 'image', 'front_image', 'cover_image', 'external_gallery_url' ) as $key ) {
			$val = get_post_meta( $post_id, $key, true );
			if ( $val ) {
				return function_exists( 'f365_photogallery_image_url' )
					? f365_photogallery_image_url( $val )
					: (string) $val;
			}
		}
		return '';
	}
endif;

/* -------------------------------------------------------------------------
 * Resolve the active section (query var first, then request URI fallback).
 * ---------------------------------------------------------------------- */
$section = (string) get_query_var( 'f365_section' );

if ( '' === $section ) {
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	if ( false !== strpos( $request_uri, '/mais-vistas' ) ) {
		$section = 'mais-vistas';
	} elseif ( false !== strpos( $request_uri, '/destaques' ) ) {
		$section = 'destaques';
	}
}

$current_page = max( 1, absint( $_GET['pagina'] ?? 1 ) );
$per_page     = defined( 'F365_PG_PER_PAGE' ) ? F365_PG_PER_PAGE : 20;

/*
 * Same pool for every tab; only the DISPLAY order changes. $display_order is a
 * HARD-CODED whitelist (never user input), so interpolating it is SQL-safe.
 */
switch ( $section ) {

	case 'mais-vistas':
		$display_order = 'post_date ASC, ID ASC';     // Mais Vistas → oldest publish date first
		break;

	case 'destaques':
		$display_order = 'post_title ASC, ID ASC';    // Em Destaque → A→Z by title
		break;

	default: // '' / fotogalerias / Últimas
		$display_order = 'post_date DESC, ID DESC';   // newest publish date first
		break;
}

global $wpdb;
$pool_offset = 0;
$pool_limit  = defined( 'F365_PG_MAX_ENTRIES' ) ? F365_PG_MAX_ENTRIES : 400;

/* Cache key MUST vary per section, since each tab sorts the pool differently. */
$cache_key = 'f365_gallery_ids_' . sanitize_key( $section ?: 'latest' );

$all_ids = wp_cache_get( $cache_key, 'f365_gallery' );

if ( false === $all_ids ) {

	/*
	 * Inner sub-query : pick the pool by recency (latest $pool_limit galleries).
	 * Outer sub-query : re-sort that SAME pool with the per-tab display order.
	 */
	$sql = $wpdb->prepare(
		"
		SELECT pool.ID
		FROM (
			SELECT ID, post_date, post_title
			FROM {$wpdb->posts}
			WHERE post_type = %s
			AND post_status = 'publish'
			ORDER BY post_date DESC
			LIMIT %d OFFSET %d
		) AS pool
		ORDER BY pool.{$display_order}
		",
		'f365_gallery',
		$pool_limit,
		$pool_offset
	);

	$all_ids = array_map( 'intval', (array) $wpdb->get_col( $sql ) );

	wp_cache_set( $cache_key, $all_ids, 'f365_gallery', 10 * MINUTE_IN_SECONDS );
}

$page_ids = array_slice( $all_ids, ( $current_page - 1 ) * $per_page, $per_page );

$gallery_query = new WP_Query(
	array(
		'post_type'              => 'f365_gallery',
		'post_status'            => 'publish',
		'post__in'               => ! empty( $page_ids ) ? $page_ids : array( 0 ),
		'orderby'                => 'post__in',
		'posts_per_page'         => $per_page,
		'no_found_rows'          => true,
		'ignore_sticky_posts'    => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
	)
);
?>

<main class="site-main multimedia-archive-page">

	<?php
	get_template_part(
		'template-parts/gallery/gallery',
		'header'
	);
	?>

	<div class="ink-grid futebol365-container">
		<section class="left-column-block all-75 medium-70 small-100 tiny-100">

			<!-- Breadcrumbs -->
			<div class="breadcrumbs-f365">
				<?php if ( function_exists( 'yoast_breadcrumb' ) ) : ?>
					<nav class="ink-navigation breadcrumbs">
						<?php yoast_breadcrumb(); ?>
					</nav>
				<?php endif; ?>
			</div>

			<?php if ( $gallery_query->have_posts() ) : ?>

				<div class="column-group gutters">
					<?php
					while ( $gallery_query->have_posts() ) :
						$gallery_query->the_post();

						$post_id    = get_the_ID();
						$detail_url = get_permalink( $post_id );
						$image_url  = f365_gallery_cover_url( $post_id );
						$title      = get_the_title( $post_id );
						$date_text  = get_the_date( 'd-m-Y', $post_id );

						$image = $image_url ? $image_url : FB_THEME_URL . '/assets/images/no-image-list.jpg';
					?>
						<div class="all-25 small-50 tiny-100">
							<figure
								class="ink-image ink-lazyload half-bottom-space my-thumb-3-2"
								data-delta="300"
								data-loaded-class="loaded">

								<a href="<?php echo esc_url( $detail_url ); ?>">
									<img
										class="lazyload-item my-thumb-image"
										src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAJCAQAAACRI2S5AAAAEklEQVR42mM0/s+AFzCOKgADAOKhCswc2KygAAAAAElFTkSuQmCC"
										data-src="<?php echo esc_url( $image ); ?>"
										title="<?php echo esc_attr( $title ); ?>"
										alt="<?php echo esc_attr( $title ); ?>"
										loading="lazy"
										decoding="async"
										onerror="this.onerror=null;this.src='<?php echo esc_url( FB_THEME_URL . '/assets/images/no-image-list.jpg' ); ?>';">
								</a>

							</figure>

							<?php if ( $date_text ) : ?>
								<h2 class="quarter-vertical-space medium uppercase condensed-400">
									<?php echo esc_html( $date_text ); ?>
								</h2>
							<?php endif; ?>

							<h3 class="large condensed-700">
								<a href="<?php echo esc_url( $detail_url ); ?>">
									<?php echo esc_html( $title ); ?>
								</a>
							</h3>
						</div>

					<?php endwhile; ?>
				</div>

				<!-- Pagination -->
				<?php
				$total_pages = (int) ceil( count( $all_ids ) / $per_page );

				if ( $total_pages > 1 && function_exists( 'futebol365_render_pagination' ) ) {
					futebol365_render_pagination( $current_page, $total_pages );
				}
				?>

			<?php else : ?>

				<p class="f365-no-results">
					<?php esc_html_e( 'Sem fotogalerias disponíveis.', 'futebol365' ); ?>
				</p>

			<?php endif; wp_reset_postdata(); ?>

			<?php get_template_part( 'template-parts/template-front-page-posts' ); ?>
		</section>

		<section class="right-column-block all-25 medium-30 small-100 tiny-100">
			<?php get_sidebar(); ?>
		</section>
	</div>

</main>

<?php get_footer(); ?>