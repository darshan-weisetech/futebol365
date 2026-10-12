<?php
/**
 * Latest News Block Template
 */
$cache_key = 'f365_latest_news_sidebar';

$cached_html = wp_cache_get($cache_key, 'f365_blocks');

if ($cached_html !== false) {
    echo $cached_html;
    return;
}

ob_start();
$latest_news_fields = get_fields();

// ACF values (only once)
$section_title = !empty($latest_news_fields['latest-news_add_title'])
	? $latest_news_fields['latest-news_add_title']
	: __('Últimas Notícias', 'futebol365');

$section_link = $latest_news_fields['latest-news_add_link'] ?? ['url' => '#'];
$section_color = $latest_news_fields['select_color_latest-news_section'] ?? '';
$selected_posts = $latest_news_fields['select_latest-news_posts'] ?? [];

// Prepare posts data
$posts = [];

// If ACF selected posts exist
if (!empty($selected_posts)) {
	foreach ($selected_posts as $post) {
		$posts[] = $post;
	}
} else {
	// Fallback: latest 4 posts
	$posts_query = new WP_Query([
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => 4,
		'offset'              => 5,
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
	]);

	if ($posts_query->have_posts()) {
		while ($posts_query->have_posts()) {
			$posts_query->the_post();
			$posts[] = get_post();
		}
		wp_reset_postdata();
	}
}

// echo "<pre>";
// print_r($posts);
// die;
?>

<h2 class="condensed-400 extralarge modtitle xlarge-half-top-space large-half-top-space small-top-space tiny-top-space">
	<a href="<?php echo esc_url($section_link['url'] ?? '#'); ?>">
		<?php echo esc_html($section_title); ?>
	</a>
	<span class="modtitle-border" <?php if ($section_color) echo 'style="background:' . esc_attr($section_color) . ';"'; ?>></span>
</h2>

<div class="column-group half-horizontal-gutters quarter-vertical-gutters small-gutters tiny-gutters ">

	<?php $i = 0; ?>
	<?php foreach ($posts as $post) : ?>
		<?php if ($i >= 4) break; setup_postdata($post); ?>

		<?php
		//$thumb = get_the_post_thumbnail_url($post->ID, 'medium_large');
		$date  = get_the_date('d-m-Y · H:i', $post->ID);
		$title = get_the_title($post->ID);
		$link  = get_permalink($post->ID);
		$legacy_news_id = ! empty($post->legacy_id) ? (int) $post->legacy_id : 0;
		$image_url = '';
		if (has_post_thumbnail($post->ID)) {
			$image_url = get_the_post_thumbnail_url($post->ID, 'large');
		} elseif ($legacy_news_id > 0 && function_exists('f365_module_image_url')) {
			    $image_url = f365_module_image_url('news', $legacy_news_id) ?: futebol365_fallback_image();
		} else {
			$image_url = futebol365_fallback_image();
		}
		?>

		<div class="all-40 tiny-100 ipad-30">
			<figure class="ink-image ink-lazyload my-thumb-3-2" data-delta="300" data-loaded-class="loaded">
				<img class="lazyload-item my-thumb-image loaded"
					 src="<?php echo esc_url($image_url); ?>"
					 title="<?php echo esc_url($title); ?>"
					 alt="<?php echo esc_attr($title); ?>">
			</figure>
		</div>

		<div class="all-60 tiny-100 mobile-latest-competition ipad-70">
			<div class="quarter-bottom-space small condensed-400 mobile-order-2">
				<?php echo esc_html($date); ?>
			</div>

			<h3 class="last-news-title condensed-700 mobile-order-1">
				<a href="<?php echo esc_url($link); ?>">
					<?php echo esc_html($title); ?>
				</a>
			</h3>
		</div>

	<?php $i++; endforeach; wp_reset_postdata(); ?>

</div>
<?php
$html = ob_get_clean();

wp_cache_set(
    $cache_key,
    $html,
    'f365_blocks',
    15 * MINUTE_IN_SECONDS
);

echo $html;
?>