<?php

/**
 * Single Video Template — /multimedia/videos/{id}/
 *
 * Standard WP post + ACF for all video data (url, length, views, file_type).
 * Custom table used ONLY for related_links (passed the legacy id). Related
 * videos = recent videos via one simple custom SQL, rendered outside the main
 * content. No main loop, no WP_Query. HTML preserved.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

get_header();

$post_id = get_queried_object_id();

if ( ! $post_id ) {
	get_template_part( 'template-parts/video/video', 'header' );
	echo '<main id="primary" class="site-main single-video-page"><div class="ink-grid futebol365-container"><section class="left-column-block"><p>';
	esc_html_e( 'Vídeo não encontrado.', 'futebol365' );
	echo '</p></section></div></main>';
	get_footer();
	return;
}

$legacy_id = function_exists( 'f365_video_single_legacy_id' ) ? f365_video_single_legacy_id() : 0;

// Fetched once.
$youtube_channel_id = get_field( 'youtube_channel_id', 'option' );
$fallback_image     = function_exists( 'futebol365_fallback_image' ) ? futebol365_fallback_image() : '';

// Main video data — ACF / post.
$url       = (string) get_field( 'url', $post_id );
$file_type = (string) get_field( 'file_type', $post_id );
$length    = (string) get_field( 'length', $post_id );
$views     = (int) get_field( 'views', $post_id );
$title     = get_the_title( $post_id );
$date_disp = get_the_date( 'd-m-Y', $post_id );

$is_embed   = ( $file_type === 'embed' );
$player_src = ( $is_embed && $url !== '' ) ? $url : home_url( '/multimedia/video-player/' . $legacy_id . '/' );

// Related links — custom table (the one allowed exception), by legacy id.
$related_links = ( $legacy_id > 0 && function_exists( 'f365_video_related_links' ) )
	? f365_video_related_links( $legacy_id )
	: [];

$cache_key   = 'related_videos_' . $post_id;
$cache_group = 'f365_videos';

$related_videos = wp_cache_get( $cache_key, $cache_group );

if ( false === $related_videos ) {

	global $wpdb;

	$related_videos = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.legacy_id, p.post_title, p.post_date, len.meta_value AS length
			   FROM {$wpdb->posts} p
			   LEFT JOIN {$wpdb->postmeta} len
			     ON len.post_id = p.ID
			    AND len.meta_key = 'length'
			  WHERE p.post_type = 'f365_video'
			    AND p.post_status = 'publish'
			    AND p.ID <> %d
			  ORDER BY p.post_date DESC
			  LIMIT 8",
			$post_id
		),
		ARRAY_A
	);

	if ( ! is_array( $related_videos ) ) {
		$related_videos = [];
	}

	wp_cache_set(
		$cache_key,
		$related_videos,
		$cache_group,
		6 * HOUR_IN_SECONDS
	);
}

if ( $related_videos ) {
	_prime_post_caches( wp_list_pluck( $related_videos, 'ID' ), false, true ); // thumbnails
}

get_template_part( 'template-parts/video/video', 'header' );
?>
		<main id="primary" class="site-main single-video-page">
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

					<div class="column-group gutters">
						<div class="all-100">
							<div class="videoDetail">
								<?php if ( $is_embed ) : ?>
									<iframe
										class="iframeVideoDetail"
										scrolling="no"
										src="<?php echo esc_url( $player_src ); ?>"
										frameborder="0"
										allowfullscreen></iframe>
								<?php else : ?>
									<iframe
										class="iframeVideoDetail"
										scrolling="no"
										src="<?php echo esc_url( $player_src ); ?>"
										frameborder="0"
										allowfullscreen></iframe>
								<?php endif; ?>
							</div>
						</div>
						<div class="all-100">
							<?php if ( ! empty( $youtube_channel_id ) ) : ?>
								<div class="subscribe bottom-space">
									<div
										class="g-ytsubscribe"
										data-channelid="<?php echo esc_attr( $youtube_channel_id ); ?>"
										data-layout="default"
										data-count="default"></div>
								</div>
							<?php endif; ?>
							<h2 class="video-title">
								<?php echo esc_html( $title ); ?>
							</h2>
							<div class="fw-500 video-date-and-views">
								<?php echo esc_html( $date_disp ); ?>
								-
								<?php echo esc_html( number_format_i18n( $views ) ); ?>
								<?php esc_html_e( 'visualizações', 'futebol365' ); ?>
							</div>
							<?php if ( ! empty( $related_links ) ) : ?>
								<div class="button-toolbar vertical-padding">
									<?php foreach ( $related_links as $related_link ) : ?>
										<?php if ( empty( $related_link['url'] ) || $related_link['title'] === '' ) { continue; } ?>
										<div class="button-group">
											<a
												class="ink-button"
												href="<?php echo esc_url( $related_link['url'] ); ?>">
												<?php echo esc_html( $related_link['title'] ); ?>
											</a>
										</div>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</div>
					</div>

					<?php if ( ! empty( $related_videos ) ) : ?>
					<div class="related-multimedia-block">
						<h2 class="condensed-400 extralarge modtitle">
							<?php esc_html_e( 'RELACIONADOS', 'futebol365' ); ?>
							<span class="modtitle-border"></span>
						</h2>
						<div class="column-group gutters">
							<?php foreach ( $related_videos as $rv ) :
								$rv_id     = (int) $rv['ID'];
								$permalink = get_permalink( $rv['ID'] );
								$image     = get_the_post_thumbnail_url( $rv_id, 'medium_large' );
								$image     = $image ? $image : $fallback_image;
								$rv_title  = (string) $rv['post_title'];
								$rv_length = (string) $rv['length'];
								$rv_date   = $rv['post_date'] !== '' ? mysql2date( 'd-m-Y', $rv['post_date'] ) : '';
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
											title="<?php echo esc_attr( $rv_title ); ?>"
											alt="<?php echo esc_attr( $rv_title ); ?>"
											onerror="this.onerror=null;this.src='<?php echo esc_url( FB_THEME_URL . '/assets/images/no-image-list.jpg' ); ?>';"
											loading="lazy"
											decoding="async">

									</a>

									<?php if ( ! empty( $rv_length ) ) : ?>

										<figcaption
											class="over-bottom dark figcaption-videos">

											<?php echo esc_html( $rv_length ); ?>

										</figcaption>

									<?php endif; ?>

								</figure>

								<h3
									class="quarter-vertical-space medium uppercase condensed-400">

									<?php echo esc_html( $rv_date ); ?>

								</h3>

								<h2
									class="large condensed-700">

									<a href="<?php echo esc_url( $permalink ); ?>">

										<?php echo esc_html( $rv_title ); ?>

									</a>

								</h2>

							</div>
							<?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>
					<?php
					get_template_part(
						'template-parts/template-front-page-posts'
					);
					?>
				</section>
				<section class="right-column-block">
					<?php
					if ( is_active_sidebar( 'post-sidebar' ) ) :
						dynamic_sidebar( 'post-sidebar' );
					else :
						get_sidebar();
					endif;
					?>
				</section>
			</div>
		</main>

<?php
get_footer();