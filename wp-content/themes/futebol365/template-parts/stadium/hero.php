<?php
/**
 * Stadium Hero
 *
 * @package Futebol365
 */

$post_id = $args['post_id'];
$fields = get_fields($post_id);
$django_stadium_id = $fields['django_stadium_id'];
$country = f365_get_stadium_country($post_id);
$country_flag = f365_get_stadium_country_flag($post_id);

$background = get_field(
	'stadium_header_bg_image',
	'option'
);

if (
	is_array($background) &&
	!empty($background['url'])
) {
	$background = $background['url'];
}

if (empty($background)) {
	$background = futebol365_fallback_image();
}
?>
<div class="all-100 ink-header-f365 estadio" <?php if ($background): ?>
		style="background-image:linear-gradient(to left,rgba(250,250,250,0) 40%,rgba(0,0,0,1)), url('<?php echo esc_url($background); ?>');"
	<?php endif; ?>>

	<div class="column-group">
		<div class="align-left all-100">
			<?php
			$stadium_id = (int) $post_id;
			$stadium_url = esc_url(home_url('/media/images/stadium/' . $django_stadium_id . '.jpg'));
			$noimg = esc_url(home_url('/media/images/stadium/no-image.jpg'));
			?>

			<figure class="bottom-space stadium-logo">

				<?php if (has_post_thumbnail($post_id)): ?>

					<?php
					echo get_the_post_thumbnail(
						$post_id,
						'thumbnail',
						array(
							'alt' => get_the_title($post_id),
						)
					);
					?>

				<?php else: ?>

					<?php $src_url = !empty($stadium_url) ? $stadium_url : $noimg; ?>

					<img src="<?php echo $src_url; ?>" alt="<?php echo esc_attr(get_the_title($post_id)); ?>"
						class="attachment-thumbnail size-thumbnail"
						onerror="this.onerror=null;this.src='<?php echo $noimg; ?>';">

				<?php endif; ?>

			</figure>

			<div class="headerdata">
				<h1>
					<?php echo esc_html(get_the_title($post_id)); ?>
				</h1>

				<?php if (!empty($fields['longname'])): ?>
					<div>
						<?php echo esc_html($fields['longname']); ?>
					</div>
				<?php endif; ?>

				<?php if ($country || !empty($fields['city'])): ?>
					<div class="flag-icon-text">
						<?php if ($country): ?>
							<?php if ($country_flag): ?>

								<img class="quarter-right-space vertical-align-middle" src="<?php echo esc_url($country_flag); ?>"
									alt="<?php echo esc_attr($country->name); ?>" width="20" height="14">
							<?php endif; ?>

							<span class="vertical-align-middle">
								<?php echo esc_html($country->name); ?>
							</span>
						<?php endif; ?>

						<?php if ($country && !empty($fields['city'])): ?>
							<span class="quarter-horizontal-space">-</span>
						<?php endif; ?>

						<?php if (!empty($fields['city'])): ?>
							<span class="vertical-align-middle">
								<?php echo esc_html($fields['city']); ?>
							</span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>