<?php
/**
 * Live Streaming — single detail  /live-streaming/{django_livestream_id}/
 *
 * Mirrors Django live.views (common_views.read_cached_obj) → 'live/live_streaming_detail.html':
 * the stream embedded in an iframe (LiveStreaming.url), the match title,
 * the scheduled datetime and the related links (competition, teams, match sheet).
 *
 * The post is resolved from f365_livestream_id → django_livestream_id in
 * live-streaming-rewrite.php::pre_get_posts().
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

get_header();

$post_obj = f365_get_current_livestream_post();
if ( ! $post_obj && have_posts() ) { the_post(); $post_obj = get_post(); }

if ( ! $post_obj ) {
	echo '<h6 class="no-info">Sem informação disponível</h6>';
	get_footer();
	return;
}

$post_id  = $post_obj->ID;
$url      = get_post_meta( $post_id, 'url', true );
$title    = f365_live_streaming_title( $post_id );
$ts       = (int) get_post_meta( $post_id, 'match_timestamp', true );
$datetime = $ts ? date_i18n( 'd-m-Y - H:i', $ts ) : '';
$links    = f365_live_streaming_related_links( $post_id );
?>

<div class="f365-live-streaming f365-live-streaming--detail">
	<div class="column-group gutters">
		<div class="all-100">
			<div class="videoDetail">
				<?php if ( $url ) : ?>
					<iframe class="iframeVideoDetail" src="<?php echo esc_url( $url ); ?>" allowfullscreen></iframe>
				<?php else : ?>
					<h6 class="no-info">Stream indisponível</h6>
				<?php endif; ?>
			</div>
		</div>
		<div class="all-100">
			<h2 class="video-title"><?php echo esc_html( $title ); ?></h2>

			<?php if ( $datetime ) : ?>
				<div class="fw-500 video-date-and-views"><?php echo esc_html( $datetime ); ?></div>
			<?php endif; ?>

			<?php if ( $links ) : ?>
				<div class="button-toolbar vertical-padding">
					<?php foreach ( $links as $link ) : ?>
						<div class="button-group">
							<?php if ( ! empty( $link['url'] ) ) : ?>
								<a class="ink-button" href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['title'] ); ?></a>
							<?php else : ?>
								<span class="ink-button"><?php echo esc_html( $link['title'] ); ?></span>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>

<?php get_footer(); ?>
