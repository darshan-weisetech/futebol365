<?php
/**
 * Newsstand Block Template (Optimized)
 */

global $futebol365_used_newsstand_ids;

$futebol365_used_newsstand_ids = $futebol365_used_newsstand_ids ?? [];

$fields = get_fields() ?: [];

// ACF values
$section_title = $fields['newsstand_add_title']
	?? __('Banca de jornais', 'futebol365');

$section_link = $fields['newsstand_add_link']
	?? get_post_type_archive_link('banca-de-jornais') ?: '#';

$posts_count = (int) ($fields['newsstand_posts_count'] ?? 3);

$section_color = !empty($fields['select_color_newsstand_section'])
	? ' style="background:' . esc_attr($fields['select_color_newsstand_section']) . ';"'
	: '';
$selected_posts = $fields['select_newsstand_posts'] ?? [];
$fallback_image = function_exists('futebol365_fallback_image')
	? futebol365_fallback_image()
	: '';

$register_ids = [];

$posts = [];

/** Resolve posts */
if (!empty($selected_posts)) {

	foreach ($selected_posts as $post) {

		if (empty($post->ID)) {
			continue;
		}

		$id = (int) $post->ID;

		if (in_array($id, $futebol365_used_newsstand_ids, true)) {
			continue;
		}

		$posts[] = $post;
		$register_ids[] = $id;
	}
} else {

	$query = new WP_Query([
		'post_type' => 'f365_newsstand',
		'post_status' => 'publish',
		'posts_per_page' => $posts_count,
		'post__not_in' => $futebol365_used_newsstand_ids,
		'no_found_rows' => true,
		'ignore_sticky_posts' => true,
	]);

	if ($query->have_posts()) {
		foreach ($query->posts as $post) {

			$id = (int) $post->ID;

			if (in_array($id, $futebol365_used_newsstand_ids, true)) {
				continue;
			}

			$posts[] = $post;
			$register_ids[] = $id;
		}
		wp_reset_postdata();
	}
}

// update global used IDs once
$futebol365_used_newsstand_ids = array_merge(
	$futebol365_used_newsstand_ids,
	$register_ids
);
?>

<div>
	<h3 class="condensed-400 modtitle">
		<a href="<?php echo esc_url($section_link['url'] ?? '#'); ?>">
			<?php echo esc_html($section_title); ?>
		</a>
		<span class="modtitle-border" <?php echo wp_kses_post($section_color); ?>></span>
	</h3>

	<div class="column-group gutters news-list-homepage">

		<?php foreach ($posts as $post):
			$id = $post->ID;
			$title = get_the_title($id);
			$publisher_terms = get_the_terms($id, 'f365_publisher');
			$display_title = (!is_wp_error($publisher_terms) && !empty($publisher_terms))
				? $publisher_terms[0]->name
				: trim(preg_replace('/\s*(?:&mdash;|&#8212;|&#x2014;|&ndash;|&#8211;|&#x2013;|—|–|-)\s*.*$/u', '', $title));
			?>

			<div class="all-33 small-100 tiny-100">

				<a href="<?php echo esc_url(get_permalink($id)); ?>">
					<figure class="ink-image ink-lazyload my-thumb-33-40">
						<img class="lazyload-item my-thumb-image loaded" src="<?php echo esc_url(
							get_the_post_thumbnail_url($id, 'medium_large') ?: $fallback_image
						); ?>" alt="<?php echo esc_attr(get_the_title($id)); ?>" title="<?php echo esc_attr(get_the_title($id)); ?>">
					</figure>
				</a>

				<div class="half-top-space medium condensed-400">
					<?php echo esc_html(get_the_date('d-m-Y', $id)); ?>
				</div>

				<h2 class="large condensed-700">
					<a href="<?php echo esc_url(get_permalink($id)); ?>">
						<?php echo esc_html($display_title ?: $title); ?>
					</a>
				</h2>

			</div>

		<?php endforeach; ?>

	</div>
</div>
