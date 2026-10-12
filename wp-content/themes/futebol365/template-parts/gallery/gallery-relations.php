<?php
/**
 * Gallery Relations
 *
 * @package Futebol365
 */

$posts_per_page = 8;

$query = new WP_Query(
	[
		'post_type'           => 'f365_gallery',
		'post_status'         => 'publish',
		'posts_per_page'      => $posts_per_page,
		'post__not_in'        => [ get_the_ID() ],
		'ignore_sticky_posts' => true,
		'orderby'             => 'date',
		'order'               => 'DESC',
		'no_found_rows'       => true,
	]
);

if ( ! $query->have_posts() ) {
	wp_reset_postdata();
	return;
}

if ( ! function_exists( 'f365_gallery_cover_url' ) ) :

	function f365_gallery_cover_url( int $post_id ): string {

		$thumb = get_the_post_thumbnail_url(
			$post_id,
			'medium_large'
		);

		if ( $thumb ) {
			return $thumb;
		}

		$legacy_gallery_id = (int) (
			get_post( $post_id )->legacy_id ?? 0
		);

		if (
			$legacy_gallery_id > 0 &&
			function_exists( 'f365_module_image_url' )
		) {
			$image = f365_module_image_url(
				'gallery',
				$legacy_gallery_id
			);

			if ( $image ) {
				return $image;
			}
		}

		foreach (
			[
				'gallery_image',
				'image',
				'front_image',
				'cover_image',
				'external_gallery_url',
			] as $key
		) {

			$val = get_post_meta(
				$post_id,
				$key,
				true
			);

			if ( $val ) {
				return function_exists(
					'f365_photogallery_image_url'
				)
					? f365_photogallery_image_url( $val )
					: (string) $val;
			}
		}

		return '';
	}

endif;
?>

<h2 class="condensed-400 extralarge modtitle">
	<?php esc_html_e( 'Mais recentes', 'futebol365' ); ?>
	<span class="modtitle-border"></span>
</h2>

<div class="column-group gutters">

	<?php
	while ( $query->have_posts() ) :
		$query->the_post();

		$image = f365_gallery_cover_url(
			get_the_ID()
		) ?: esc_url( FB_THEME_URL . '/assets/images/no-image-list.jpg' );
		?>

		<div class="all-25 small-50 tiny-100">

			<a href="<?php the_permalink(); ?>">

				<figure
					class="ink-image ink-lazyload half-bottom-space my-thumb-3-2"
					data-delta="300"
					data-loaded-class="loaded">

					<img
						class="lazyload-item my-thumb-image"
						src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAJCAQAAACRI2S5AAAAEklEQVR42mM0/s+AFzCOKgADAOKhCswc2KygAAAAAElFTkSuQmCC"
						data-src="<?php echo esc_url( $image ); ?>"
						alt="<?php echo esc_attr( get_the_title() ); ?>"
						title="<?php echo esc_attr( get_the_title() ); ?>"
						loading="lazy"
						decoding="async"
						onerror="this.onerror=null;this.src='<?php echo esc_url( FB_THEME_URL . '/assets/images/no-image-list.jpg' ); ?>';">

				</figure>

			</a>

			<h3 class="quarter-vertical-space medium uppercase condensed-400">
				<?php echo esc_html( get_the_date( 'd-m-Y' ) ); ?>
			</h3>

			<h4 class="large condensed-700">
				<a href="<?php the_permalink(); ?>">
					<?php the_title(); ?>
				</a>
			</h4>

		</div>

	<?php endwhile; ?>

</div>

<?php wp_reset_postdata(); ?>