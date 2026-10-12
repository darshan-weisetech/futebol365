<?php
/**
 * Multimedia Archive/Header
 *
 * Used for:
 * - Video Archive
 * - Gallery Archive
 * - Single Video
 * - Single Gallery
 *
 * @package Futebol365
 */

$post_type = 'f365_video';
if (false !== strpos($_SERVER['REQUEST_URI'], '/fotogalerias/') || is_singular('f365_gallery')) {
	$post_type = 'f365_gallery';
}

$current_filter = get_query_var(
	'f365_section'
);

if ( empty( $current_filter ) ) {

	$current_filter = 'mais-recentes';

}

$title = 'Vídeos';
if ('f365_gallery' === $post_type) {
	$title = 'Fotogalerias';
}
$background_image = false;

if ('f365_video' === $post_type) {
	$background_image = get_field('video_header_bg_image', 'option');
}

if ('f365_gallery' === $post_type) {
	$background_image = get_field('fotogallery_header_bg_image', 'option');
}
$background = '';
if (! empty($background_image['url'])) {
	$background = $background_image['url'];
}

if (! $background) {
	$background = get_the_post_thumbnail_url(get_the_ID(), 'full');
}

if (! $background) {
	$background = futebol365_fallback_image();
}
?>
<?php get_template_part('template-parts/template-offer-widget'); ?>
<div class="ink-grid vertical-space">
	<div class="all-100 ink-header-f365 multimedia <?php echo ! $background ? '' : ''; ?>" <?php if ($background) : ?>style="background-image:linear-gradient(
        to left,
        rgba(250, 250, 250, 0) 40%,
        rgba(0, 0, 0, 1)
      ),url('<?php echo esc_url($background); ?>');"<?php endif; ?> >
		<div class="column-group">
			<div class="all-70 medium-100 small-100 tiny-100">
				<div class="headerdata">
					<h1 class="multimedia-title"><?php echo esc_html($title); ?></h1>
				</div>
			</div>
		</div>
	</div>
	<?php get_template_part('template-parts/multimedia/tabs'); ?>
</div>