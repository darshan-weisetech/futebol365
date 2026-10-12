<?php
/**
 * Federation — Estádios
 * @package Futebol365
 */

defined('ABSPATH') || exit;

get_header();
global $wpdb;

$current_post_id = get_the_ID();

//$django_federation_id = get_post_meta($current_post_id, 'django_federation_id', true);
$django_federation_id = get_legacy_competition_id( $current_post_id );
$federation_id = intval($django_federation_id);
$sections = futebol365_get_federation_sections();
$fed_table = $wpdb->prefix . 'federation_federation';
$country_tbl = $wpdb->prefix . 'country_country';
$stadium_table = $wpdb->prefix . 'stadium_stadium';
$fed = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT f.id, f.shortname, f.longname, f.url, f.facebook, f.twitter, f.youtube,
		        f.instagram, f.googleplus, f.country_id, f.foundation, f.federation_id, f.federation_type,
		        pf.country_id AS parent_country_id
		   FROM {$fed_table} f
		   LEFT JOIN {$fed_table} pf ON f.federation_id = pf.id
		  WHERE f.id = %d",
		$federation_id
	),
	OBJECT
);


if (!function_exists('f365_federation_flag_id')) {
	function f365_federation_flag_id($fed)
	{
		if ('association' === $fed->federation_type) {
			return (null !== $fed->parent_country_id && '' !== $fed->parent_country_id) ? intval($fed->parent_country_id) : '';
		}
		if ('federation' === $fed->federation_type) {
			return (null !== $fed->country_id && '' !== $fed->country_id) ? intval($fed->country_id) : '';
		}
		return 'conf_' . intval($fed->id);
	}
}

if (!function_exists('f365_federation_member_flag_id')) {

	function f365_federation_member_flag_id($type, $id, $country_id, $parent_country_id)
	{
		if ('association' === $type) {
			return (null !== $parent_country_id && '' !== $parent_country_id) ? intval($parent_country_id) : '';
		}
		if ('federation' === $type) {
			return (null !== $country_id && '' !== $country_id) ? intval($country_id) : '';
		}
		return 'conf_' . intval($id);
	}
}

if (!function_exists('f365_stadium_image_url')) {
	function f365_stadium_image_url($stadium_id)
	{
		$default = home_url('media/images/stadium/' . intval($stadium_id) . '.jpg');
		return apply_filters('f365_stadium_image_url', $default, intval($stadium_id));
	}
}
if (!function_exists('f365_stadium_no_image_url')) {
	function f365_stadium_no_image_url()
	{
		return apply_filters('f365_stadium_no_image_url', home_url( 'media/images/stadium/no-image.jpg'));
	}
}

$is_assoc = $fed ? ('association' === $fed->federation_type) : false;
$is_confed = $fed ? ('confederation' === $fed->federation_type) : false;

if ($fed) {
	$flag_id = f365_federation_flag_id($fed);
	$found_year = (!empty($fed->foundation)) ? mysql2date('Y', $fed->foundation) : '';
}

$descendents = array();
$stadiums = array();
$total_pages = 1;
$cur_page = 1;

if ($fed && $is_confed) {

	$descendents = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT f.id, f.shortname, f.longname, f.country_id, f.federation_type
			   FROM {$fed_table} f
			   LEFT JOIN {$country_tbl} c ON f.country_id = c.id
			  WHERE f.federation_id = %d
			    AND ( c.id IS NULL OR CAST( c.is_extinct AS CHAR ) NOT IN ( '1', 't', 'true' ) )
			  ORDER BY f.shortname ASC",
			$federation_id
		),
		OBJECT
	);
	

	$member_country_ids = array();
	foreach ($descendents as $d) {
		if (null !== $d->country_id && '' !== $d->country_id) {
			$member_country_ids[] = intval($d->country_id);
		}
	}
	$stadium_count_by_country = array();
	if (!empty($member_country_ids)) {
		$member_country_ids = array_values(array_unique($member_country_ids));
		$placeholders = implode(',', array_fill(0, count($member_country_ids), '%d'));
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT country_id, COUNT(1) AS n FROM {$stadium_table} WHERE country_id IN ($placeholders) GROUP BY country_id",
				$member_country_ids
			),
			OBJECT
		);
		foreach ($rows as $r) {
			$stadium_count_by_country[intval($r->country_id)] = intval($r->n);
		}
	}
} elseif ($fed) {

	$per_page = 30;
	$cur_page = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;

	if (null !== $fed->country_id && '' !== $fed->country_id) {
		$total_items = (int) $wpdb->get_var(
			$wpdb->prepare("SELECT COUNT(1) FROM {$stadium_table} WHERE country_id = %d", intval($fed->country_id))
		);
		$total_pages = max(1, (int) ceil($total_items / $per_page));
		$cur_page = min($cur_page, $total_pages);
		$offset = ($cur_page - 1) * $per_page;

		if ($total_items > 0) {
			$stadiums = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, shortname, city, capacity
					   FROM {$stadium_table}
					  WHERE country_id = %d
					  ORDER BY shortname ASC
					  LIMIT %d OFFSET %d",
					intval($fed->country_id),
					$per_page,
					$offset
				),
				OBJECT
			);
		}
	}

	$visible = 3;
	$window_start = max(1, $cur_page - intval(floor($visible / 2)));
	$window_end = min($total_pages, $window_start + $visible - 1);
	$window_start = max(1, $window_end - $visible + 1);
}

if (!function_exists('f365_fed_page_link')) {
	function f365_fed_page_link($page)
	{
		$params = $_GET;
		$params = array_map('sanitize_text_field', wp_unslash((array) $params));
		if ($page <= 1) {
			unset($params['pagina']);
		} else {
			$params['pagina'] = $page;
		}
		$qs = http_build_query($params);
		return esc_url(('' !== $qs) ? ('?' . $qs) : '?');
	}
}
?>


<main id="primary" class="site-main single-article-page" <?php echo $current_post_id; ?>>
	<?php
	get_template_part(
		'template-parts/federation/f365-federation-header',
		null,
		array(
			'post_id' => $current_post_id,
			'sections' => $sections,
		)
	); ?>
	<div class="ink-grid futebol365-container">

		<section class="left-column-block">
			<div class="breadcrumbs-f365">
				<div class="ink-navigation breadcrumbs">
					<?php yoast_breadcrumb(); ?>
				</div>
			</div>

			<h3 class="condensed-400 headerTitle black"><?php esc_html_e( 'Estádios', 'futebol365' ); ?></h3>

			<?php if ($is_confed): ?>
				<div class="column-group half-gutters">
					<?php foreach ($descendents as $member):
						$m_flag = f365_federation_member_flag_id($member->federation_type, $member->id, $member->country_id, $fed->country_id);
						$m_url = esc_url(home_url('/federacao/' . intval($member->id) . '/estadios'));
						$m_label = $member->shortname;
						$m_count = (null !== $member->country_id && isset($stadium_count_by_country[intval($member->country_id)]))
							? intval($stadium_count_by_country[intval($member->country_id)]) : 0;
						$plural = (1 === $m_count) ? '' : 's';
						?>
						<div class="all-50 xlarge-33 small-100 tiny-100">
							<div class="obj-right-div">
								<div class="obj-flag">
									<a href="<?php echo $m_url; ?>"><i
											class="flag-icon flag-icon-<?php echo esc_attr($m_flag); ?>"
											title="<?php echo esc_attr($m_label); ?>"></i></a>
								</div>
								<div class="obj-detail">
									<div class="obj-name"><a
											href="<?php echo $m_url; ?>"><?php echo esc_html($m_label); ?></a></div>
									<div class="obj-link"><?php echo esc_html($m_count); ?>
										<?php esc_html_e( 'estádio', 'futebol365' ); ?><?php echo esc_html($plural); ?></div>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else: ?>
				<?php if (!empty($stadiums)): ?>
					<div class="column-group half-gutters">
						<?php foreach ($stadiums as $st):
							$st_url = esc_url(home_url('/estadio/' . intval($st->id) . '/'));
							$image_url = esc_url(f365_stadium_image_url($st->id));
							$noimg = esc_url(f365_stadium_no_image_url());
							$capacity = intval($st->capacity);
							?>
							<div class="all-50 small-100 tiny-100">
								<div class="obj-right-div obj-flex">
									<div class="obj-image-stadium">
										<figure class="ink-image ink-lazyload" data-delta="300">
											<a href="<?php echo $st_url; ?>"><img src="<?php echo $image_url; ?>"
													data-src="<?php echo $image_url; ?>" class="lazyload-item"
													alt="<?php echo esc_attr($st->shortname); ?>"
													title="<?php echo esc_attr($st->shortname); ?>"
													onerror="this.onerror=null;this.src='<?php echo $noimg; ?>';" /></a>
										</figure>
									</div>
									<div class="obj-detail">
										<div class="obj-name"><a
												href="<?php echo $st_url; ?>"><?php echo esc_html($st->shortname); ?></a></div>
										<div class="obj-link"><?php echo esc_html($st->city); ?></div>
										<div class="obj-link">
											<?php if ($capacity > 0): ?>
												<?php echo esc_html($capacity); ?> <?php esc_html_e( 'lugares', 'futebol365' ); ?>
											<?php endif; ?>
										</div>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>


					<?php futebol365_render_pagination( $cur_page, $total_pages )?>
				<?php else: ?>
					<h5 class="no-info"><?php esc_html_e( 'Sem informação disponível', 'futebol365' ); ?></h5>
				<?php endif; ?>
			<?php endif; ?>
			<?php get_template_part('template-parts/template-front-page-posts'); ?>
		</section>
		<section class="right-column-block">
			<?php
			if (is_active_sidebar('post-sidebar')) {
				dynamic_sidebar('post-sidebar');
			} else {
				get_sidebar();
			}
			?>
		</section>

	</div>

</main>

<?php
get_footer();
