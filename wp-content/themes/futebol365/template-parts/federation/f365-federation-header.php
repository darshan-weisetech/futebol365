<?php
/**
 * Competition Header
 *
 * Flag rendering matches Django federation templates exactly:
 *   <i class="flag-icon flag-icon-{get_flag_id}"></i>
 * where get_flag_id() is the country id (or "conf_{id}" for confederations).
 * This is a CSS sprite class — NOT an ACF image or featured image. The
 * featured image (if any) is used only as the large banner image.
 */

defined('ABSPATH') || exit;
global $wpdb;

$current_post_id = get_the_ID();
$sections        = $args['sections'] ?? [];
$current_section = get_query_var('section');

if (empty($current_section)) {
	$path_parts      = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/')); // phpcs:ignore
	$current_section = end($path_parts);
}
if (empty($current_section) || is_numeric($current_section)) {
	$current_section = 'details';
}

//$django_federation_id = get_post_meta($current_post_id, 'django_federation_id', true);
$django_federation_id = get_legacy_competition_id( $current_post_id );
$federation_id        = intval($django_federation_id);

$fed_table   = $wpdb->prefix . 'federation_federation';
$country_tbl = $wpdb->prefix . 'country_country';
$pres_tbl    = $wpdb->prefix . 'person_president';
$person_tbl  = $wpdb->prefix . 'person_person';

$fed = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT f.id, f.shortname, f.longname, f.url, f.facebook, f.twitter, f.youtube,
		        f.instagram, f.googleplus, f.country_id, f.city, f.foundation,
		        f.president_id, f.federation_id, f.federation_type,
		        c.longname   AS country_longname,
		        c.capital    AS country_capital,
		        c.city       AS country_city,
		        c.language   AS country_language,
		        c.area       AS country_area,
		        c.population AS country_population,
		        c.coin       AS country_coin,
		        pf.country_id AS parent_country_id,
		        pp.shortname AS president_name
		   FROM {$fed_table} f
		   LEFT JOIN {$country_tbl} c  ON f.country_id    = c.id
		   LEFT JOIN {$fed_table}   pf ON f.federation_id = pf.id
		   LEFT JOIN {$pres_tbl}    pr ON f.president_id  = pr.id
		   LEFT JOIN {$person_tbl}  pp ON pr.person_id    = pp.id
		  WHERE f.id = %d",
		$federation_id
	),
	OBJECT
); // phpcs:ignore WordPress.DB

/* -------------------------------------------------------------------------
 * Helpers.
 * ---------------------------------------------------------------------- */
if (!function_exists('f365_federation_flag_id')) {
	/** Federation.get_flag_id(): country id, parent country id, or conf_{id}. */
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

$flag_id    = '';
$found_year = '';
$has_social = false;
if ($fed) {
	$flag_id    = f365_federation_flag_id($fed);
	$found_year = (!empty($fed->foundation)) ? mysql2date('Y', $fed->foundation) : '';
	$has_social = ($fed->facebook || $fed->twitter || $fed->youtube || $fed->instagram || $fed->googleplus);
}

/*
 * Banner image: the post's featured image (competition logo) is the primary
 * source. The country flag is rendered via the flag-icon-{id} CSS class
 * (Django parity), so we no longer depend on a non-existent ACF flag field.
 */
$featured_img_url = get_the_post_thumbnail_url($current_post_id, 'full') ?: '';

// Fall back to the linked WP federation's featured image if the competition has none.
if ('' === $featured_img_url) {
	$comp_federation_id = function_exists('get_field') ? get_field('federation_competition', $current_post_id) : null;
	$wp_federation_id   = $comp_federation_id && function_exists('get_field') ? get_field('federation', $comp_federation_id) : null;
	if ($wp_federation_id) {
		$featured_img_url = get_the_post_thumbnail_url($wp_federation_id, 'full') ?: '';
	}
}

$flag_class = '' !== (string) $flag_id ? 'flag-icon flag-icon-' . esc_attr($flag_id) : '';
?>

<div class="ink-grid vertical-space <?php echo esc_attr($current_post_id); ?>">
	<?php get_template_part('template-parts/template-offer-widget'); ?>
	<?php if (!$fed): ?>
		<div class="ink-grid futebol365-container">
			<section class="left-column-block">
				<h5><?php esc_html_e('Não foi encontrada qualquer edição desta competição.', 'futebol365'); ?></h5>
			</section>
		</div>
	<?php endif; ?>

	<?php if ($fed): ?>
	<div class="all-100 ink-header-f365">
		<div class="column-group">
			<div class="align-left all-60 medium-100 small-100 tiny-100 banner-left-content">
				<?php if ($featured_img_url): ?>
					<div class="flag hide-small hide-tiny">
						<i class="flag-icon" title="<?php echo esc_attr($fed->shortname); ?>">
							<img alt="<?php echo esc_attr($fed->longname); ?>" src="<?php echo esc_url($featured_img_url); ?>">
						</i>
					</div>
				<?php elseif ($flag_class): ?>
					<div class="flag hide-small hide-tiny">
						<i class="federation-flag <?php echo $flag_class; ?>" title="<?php echo esc_attr($fed->shortname); ?>"></i>
					</div>
				<?php endif; ?>

				<div class="headerdata">
					<h1><?php echo esc_html($fed->shortname); ?></h1>
					<div>
						<?php if ($featured_img_url): ?>
							<i class="com-full-icon quarter-right-space flag-icon"><img alt="<?php echo esc_attr($fed->longname); ?>" src="<?php echo esc_url($featured_img_url); ?>"></i>
						<?php elseif ($flag_class): ?>
							<i class="quarter-right-space <?php echo $flag_class; ?>"></i>
						<?php endif; ?>
						<?php echo esc_html($fed->longname); ?>
					</div>
					<?php if ('' !== $found_year): ?>
						<div><?php esc_html_e('Fundação:', 'futebol365'); ?> <?php echo esc_html($found_year); ?></div>
					<?php endif; ?>
					<div>
						<?php if ($fed->facebook): ?>
							<a href="https://www.facebook.com/<?php echo esc_attr($fed->facebook); ?>" target="_blank" aria-label="Facebook"><svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#social-facebook"></use></svg></a>
						<?php endif; ?>
						<?php if ($fed->twitter): ?>
							<a href="https://x.com/<?php echo esc_attr($fed->twitter); ?>" target="_blank" aria-label="X (Twitter)"><svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#social-x"></use></svg></a>
						<?php endif; ?>
						<?php if ($fed->youtube): ?>
							<a href="https://www.youtube.com/<?php echo esc_attr($fed->youtube); ?>" target="_blank" aria-label="YouTube"><svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#social-youtube"></use></svg></a>
						<?php endif; ?>
						<?php if ($fed->instagram): ?>
							<a href="https://www.instagram.com/<?php echo esc_attr($fed->instagram); ?>" target="_blank" aria-label="Instagram"><svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#social-instagram"></use></svg></a>
						<?php endif; ?>
						<?php if ($fed->url): ?>
							<a href="<?php echo esc_url($fed->url); ?>" target="_blank" aria-label="Website oficial"><svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#icon-link"></use></svg></a>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<div class="nav-trigger" id="nav-trigger">
		<span><?php esc_html_e('Menu', 'futebol365'); ?></span>
	</div>
	<nav class="nav-main" id="nav-main">
		<?php $base_url = get_permalink($current_post_id); ?>
		<ul>
			<?php foreach ($sections as $slug => $section): ?>
				<?php
				$check_section = empty($current_section) ? 'details' : $current_section;
				$is_active     = ($check_section === $slug);
				$tab_url       = ('details' === $slug) ? $base_url : $base_url . trailingslashit($slug);
				?>
				<li>
					<a class="<?php echo $is_active ? 'active' : ''; ?>" href="<?php echo esc_url($tab_url); ?>">
						<?php echo esc_html($section['label']); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</nav>
	<nav class="nav-mobile" id="nav-mobile"></nav>
</div>
