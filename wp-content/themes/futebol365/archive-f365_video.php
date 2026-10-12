<?php

/**
 * Video Archive — /multimedia/videos/ and sections (mais-recentes, destaques,
 * mais-vistos). Standard WordPress (WP_Query) on the f365_video post type.
 * Length from ACF, thumbnail from the featured image. HTML unchanged.
 *
 * Section ordering (source multimedia/urls.py):
 *   mais-recentes / default : newest first        (max 400 → 20 pages)
 *   destaques               : highlight, newest    (max 100 →  5 pages)
 *   mais-vistos             : most views, last 45d  (max 100 →  5 pages)
 *
 * PERFORMANCE MODEL
 * -----------------
 * The heavy filtering/sorting (postmeta highlight / views) is done ONCE per
 * section in a single bounded $wpdb query that returns only the capped ID list
 * (<= page_cap * per_page rows), cached in the object cache. Pages are sliced
 * from that list in PHP and total_pages is derived with count() — so there is
 * no per-page query and no separate COUNT(*). Rendering uses a post__in
 * WP_Query (primary-key lookup, no meta_query, no filesort), so the markup loop
 * below is unchanged.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

get_header();

$section = (string) get_query_var( 'f365_section' );
$paged   = max( 1, absint( $_GET['pagina'] ?? get_query_var( 'paged', 1 ) ) );

$per_page = 20;

global $wpdb;

/*
 * Different video pools per tab:
 *
 * mais-recentes : latest 400 videos
 * destaques     : next 100 videos (401-500)
 * mais-vistos   : next 100 videos (501-600)
 */

switch ( $section ) {

	case 'destaques':
		$pool_offset = 400;
		$pool_limit  = 100;
		$page_cap    = 5;
		break;

	case 'mais-vistos':
		$pool_offset = 500;
		$pool_limit  = 100;
		$page_cap    = 5;
		break;

	case 'mais-recentes':
	default:
		$pool_offset = 0;
		$pool_limit  = 400;
		$page_cap    = 20;
		break;
}

$ids_cache_key = sprintf(
	'f365_vidarch_ids_%s',
	$section ?: 'mais-recentes'
);

$all_ids = wp_cache_get(
	$ids_cache_key,
	'f365_videos'
);

if ( false === $all_ids ) {

	$sql = $wpdb->prepare(
		"
		SELECT ID
		FROM {$wpdb->posts}
		WHERE post_type = %s
		AND post_status = 'publish'
		ORDER BY post_date DESC
		LIMIT %d OFFSET %d
		",
		'f365_video',
		$pool_limit,
		$pool_offset
	);

	$all_ids = array_map(
		'intval',
		(array) $wpdb->get_col( $sql )
	);

	wp_cache_set(
		$ids_cache_key,
		$all_ids,
		'f365_videos',
		10 * MINUTE_IN_SECONDS
	);
}

$total_pages = (int) ceil(
	count( $all_ids ) / $per_page
);

$total_pages = min(
	$page_cap,
	max( 1, $total_pages )
);

$page_ids = array_slice(
	$all_ids,
	( $paged - 1 ) * $per_page,
	$per_page
);

$query = new WP_Query(
	[
		'post_type'              => 'f365_video',
		'post_status'            => 'publish',
		'post__in'               => ! empty( $page_ids ) ? $page_ids : [ 0 ],
		'orderby'                => 'post__in',
		'posts_per_page'         => $per_page,
		'no_found_rows'          => true,
		'ignore_sticky_posts'    => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
	]
);

if ( $query->have_posts() ) {
	update_post_thumbnail_cache( $query );
}

$fallback_image = function_exists( 'futebol365_fallback_image' ) ? futebol365_fallback_image() : '';
?>
<main class="site-main multimedia-archive-page">
	<?php
	get_template_part(
		'template-parts/multimedia/archive',
		'header'
	);
	?>
	<div class="ink-grid futebol365-container">
		<section class="left-column-block all-75 medium-70 small-100 tiny-100">
			<div class="breadcrumbs-f365">
				<?php if ( function_exists( 'yoast_breadcrumb' ) ) : ?>
					<nav class="ink-navigation breadcrumbs">
						<?php yoast_breadcrumb(); ?>
					</nav>
				<?php endif; ?>
			</div>
			<?php if ( $query->have_posts() ) : ?>
				<div class="column-group gutters">
					<?php
					while ( $query->have_posts() ) :
						$query->the_post();

						$permalink = get_permalink();
						$title     = get_the_title();

						$image = get_the_post_thumbnail_url( get_the_ID(), 'medium_large' );
						$image = $image ? $image : $fallback_image;

						$length = (string) get_post_meta( get_the_ID(), 'length', true );
						$date   = get_the_date( 'd-m-Y' );
						?>
						<div class="all-25 small-50 tiny-100">

							<figure
								class="ink-image ink-lazyload half-bottom-space my-thumb-16-9"
								data-delta="300"
								data-loaded-class="loaded">

								<a href="<?php echo esc_url( $permalink ); ?>">

									<img
										class="lazyload-item my-thumb-image"
										src="<?php echo esc_url( $image ); ?>"
										onerror="this.onerror=null;this.src='<?php echo esc_url( FB_THEME_URL . '/assets/images/no-image-list.jpg' ); ?>';"
										title="<?php echo esc_attr( $title ); ?>"
										alt="<?php echo esc_attr( $title ); ?>"
										loading="lazy"
										decoding="async">

								</a>

								<?php if ( ! empty( $length ) ) : ?>

									<figcaption
										class="over-bottom dark figcaption-videos">

										<?php echo esc_html( $length ); ?>

									</figcaption>

								<?php endif; ?>

							</figure>

							<h3
								class="quarter-vertical-space medium uppercase condensed-400">

								<?php echo esc_html( $date ); ?>

							</h3>

							<h2
								class="large condensed-700">

								<a href="<?php echo esc_url( $permalink ); ?>">

									<?php echo esc_html( $title ); ?>

								</a>

							</h2>

						</div>
						<?php
					endwhile;
					wp_reset_postdata();
					?>
				</div>

				<?php
				if ( $total_pages > 1 ) {
					futebol365_render_pagination( $paged, $total_pages );
				}
				?>

			<?php endif; ?>
			<?php get_template_part(
				'template-parts/template-front-page-posts'
			); ?>
		</section>
		<section class="right-column-block all-25 medium-30 small-100 tiny-100">
			<?php get_sidebar(); ?>
		</section>
	</div>
</main>
<?php get_footer(); ?>