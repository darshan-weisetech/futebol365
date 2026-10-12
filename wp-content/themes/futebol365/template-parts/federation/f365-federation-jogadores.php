<?php
/**
 * Federation - Jogadores.
 *
 * Reads the migrated Django tables stored in the WordPress database with the
 * current WordPress table prefix.
 *
 * @package Futebol365
 */

defined('ABSPATH') || exit;

get_header();

global $wpdb;

$current_post_id = get_the_ID();
$django_federation_id = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT legacy_id
         FROM {$wpdb->posts}
         WHERE ID = %d",
        $current_post_id
    )
);
$query_federation_id = (int) get_query_var('f365_fed_id');
$federation_id = $django_federation_id ?: ($query_federation_id ?: $current_post_id);
$sections = function_exists('futebol365_get_federation_sections') ? futebol365_get_federation_sections() : array();

$fed_table = $wpdb->prefix . 'federation_federation';
$country_tbl = $wpdb->prefix . 'country_country';
$player_table = $wpdb->prefix . 'person_player';
$person_table = $wpdb->prefix . 'person_person';

if (!function_exists('f365_federation_flag_id')) {
	function f365_federation_flag_id($fed)
	{
		if (!$fed) {
			return '';
		}

		if ('association' === $fed->federation_type) {
			return (null !== $fed->parent_country_id && '' !== $fed->parent_country_id) ? (int) $fed->parent_country_id : '';
		}

		if ('federation' === $fed->federation_type) {
			return (null !== $fed->country_id && '' !== $fed->country_id) ? (int) $fed->country_id : '';
		}

		return 'conf_' . (int) $fed->id;
	}
}

if (!function_exists('f365_federation_member_flag_id')) {
	function f365_federation_member_flag_id($type, $id, $country_id, $parent_country_id)
	{
		if ('association' === $type) {
			return (null !== $parent_country_id && '' !== $parent_country_id) ? (int) $parent_country_id : '';
		}

		if ('federation' === $type) {
			return (null !== $country_id && '' !== $country_id) ? (int) $country_id : '';
		}

		return 'conf_' . (int) $id;
	}
}

if (!function_exists('f365_player_position_label')) {
	function f365_player_position_label($position)
	{
		$labels = array(
			'goalkeeper' => 'Guarda redes',
			'defender' => 'Defesa',
			'midfielder' => 'Médio',
			'forward' => 'Avançado',
		);

		return isset($labels[$position]) ? $labels[$position] : $position;
	}
}

if (!function_exists('f365_person_age_display')) {
	function f365_person_age_display($birth_date, $death_date)
	{
		if (empty($birth_date) || '0000-00-00' === $birth_date) {
			return '';
		}

		if (!empty($death_date) && '0000-00-00' !== $death_date) {
			return 'Falecido';
		}

		try {
			$birth = new DateTime($birth_date);
			$today = new DateTime('today');
			return $birth->diff($today)->y . ' anos';
		} catch (Exception $e) {
			return '';
		}
	}
}

if (!function_exists('f365_fed_page_link')) {
	function f365_fed_page_link($page)
	{
		$params = isset($_GET) ? wp_unslash($_GET) : array();
		$params = array_map('sanitize_text_field', (array) $params);

		if ($page <= 1) {
			unset($params['pagina']);
		} else {
			$params['pagina'] = (int) $page;
		}

		$qs = http_build_query($params);
		return esc_url('' !== $qs ? '?' . $qs : '?');
	}
}

$fed = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT f.id, f.shortname, f.longname, f.url, f.facebook, f.twitter, f.youtube,
		        f.instagram, f.googleplus, f.country_id, f.foundation, f.federation_id,
		        f.federation_type, pf.country_id AS parent_country_id
		   FROM {$fed_table} f
		   LEFT JOIN {$fed_table} pf ON f.federation_id = pf.id
		  WHERE f.id = %d",
		$federation_id
	)
);

$is_confed = $fed && 'confederation' === $fed->federation_type;
$descendents = array();
$players = array();
$total_pages = 1;
$cur_page = isset($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$letter = isset($_GET['letter']) ? sanitize_text_field(wp_unslash($_GET['letter'])) : '';

if ($fed && $is_confed) {
	$descendents = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT f.id, f.shortname, f.longname, f.country_id, f.federation_type, pf.country_id AS parent_country_id,
			        (
			            SELECT COUNT(1)
			              FROM {$player_table} pl
			              INNER JOIN {$person_table} pe ON pe.id = pl.person_id
			            WHERE pe.nationality_id IN (
							SELECT f.country_id
							WHERE f.country_id IS NOT NULL

							UNION

							SELECT df.country_id
							FROM wp_federation_federation df
							WHERE df.federation_id = f.id
							AND df.country_id IS NOT NULL
						)
			        ) AS count_players
			   FROM {$fed_table} f
			   LEFT JOIN {$fed_table} pf ON f.federation_id = pf.id
			   LEFT JOIN {$country_tbl} c ON f.country_id = c.id
			  WHERE f.federation_id = %d
			    AND ( c.id IS NULL OR CAST( c.is_extinct AS CHAR ) NOT IN ( '1', 't', 'true' ) )
			  ORDER BY f.shortname ASC",
			$federation_id
		)
	);
} elseif ($fed) {
	$per_page = 30;
	$where = 'pe.nationality_id = %d';
	$params = array((int) $fed->country_id);

	if ('' !== $letter) {
		$where .= ' AND pe.shortname LIKE %s';
		$params[] = $wpdb->esc_like($letter) . '%';
	}

	$total_items = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(1)
			   FROM {$player_table} pl
			   INNER JOIN {$person_table} pe ON pe.id = pl.person_id
			  WHERE {$where}",
			$params
		)
	);

	$total_pages = max(1, (int) ceil($total_items / $per_page));
	$cur_page = min($cur_page, $total_pages);
	$offset = ($cur_page - 1) * $per_page;

	if ($total_items > 0) {
		$params[] = $per_page;
		$params[] = $offset;

		$players = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pl.id, pl.person_id, pl.position, pe.shortname, pe.birth_date, pe.death_date
				   FROM {$player_table} pl
				   INNER JOIN {$person_table} pe ON pe.id = pl.person_id
				  WHERE {$where}
				  ORDER BY pe.shortname ASC
				  LIMIT %d OFFSET %d",
				$params
			)
		);
	}
}

$visible = 3;
$window_start = max(1, $cur_page - (int) floor($visible / 2));
$window_end = min($total_pages, $window_start + $visible - 1);
$window_start = max(1, $window_end - $visible + 1);
?>

<main id="primary" class="site-main single-article-page">
	<?php
	get_template_part(
		'template-parts/federation/f365-federation-header',
		null,
		array(
			'post_id' => $current_post_id,
			'sections' => $sections,
		)
	);
	?>

	<div class="ink-grid futebol365-container">
		<section class="left-column-block">
			<div class="breadcrumbs-f365">
				<div class="ink-navigation breadcrumbs">
					<?php
					if (function_exists('yoast_breadcrumb')) {
						yoast_breadcrumb();
					}
					?>
				</div>
			</div>

			<h3 class="condensed-400 headerTitle red"><?php esc_html_e('Jogadores', 'futebol365'); ?></h3>

			<?php if ($is_confed): ?>
				<div class="column-group half-gutters">
					<?php foreach ($descendents as $federation_member): ?>
						<?php
						$m_flag = f365_federation_member_flag_id($federation_member->federation_type, $federation_member->id, $federation_member->country_id, $federation_member->parent_country_id);
						$m_url = esc_url(home_url('/federacao/' . (int) $federation_member->id . '/jogadores'));
						$m_label = $federation_member->shortname;
						$m_count = (int) $federation_member->count_players;
						$plural = (1 === $m_count) ? '' : 'es';
						?>
						<div class="all-50 xlarge-33 small-100 tiny-100">
							<div class="obj-right-div">
								<div class="obj-flag">
									<a href="<?php echo $m_url; ?>"><i
											class="flag-icon flag-icon-<?php echo esc_attr($m_flag); ?>"
											title="<?php echo esc_attr($m_label); ?>"></i></a>
								</div>
								<div class="obj-detail">
									<div class="obj-name"><a href="<?php echo $m_url; ?>"><?php echo esc_html($m_label); ?></a>
									</div>
									<div class="obj-link"><?php echo esc_html($m_count); ?>
										<?php esc_html_e('Jogadores', 'futebol365'); ?>		<?php echo esc_html($plural); ?>
									</div>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else: ?>
				<?php if (!empty($players)): ?>
					<div class="column-group half-gutters">
						<?php foreach ($players as $player): ?>
							<?php
							$player_url = esc_url(home_url('/jogador/' . (int) $player->id . '/'));
							$loader_url = esc_url(get_template_directory_uri() . '/website/img/responsive/ajax-loader.gif');
							$img_url = esc_url(home_url('media/images/person/' . (int) $player->person_id . '.jpg'));
							$noimg = esc_url(home_url('media/images/person/no-image.gif'));
							
							$age_display = f365_person_age_display($player->birth_date, $player->death_date);
							if (file_exists($img_path)) {
								$img_url = esc_url(home_url('media/images/person/' . (int) $player->person_id . '.jpg'));
							} else {
								$img_url = $noimg;
							}
							?>
							<div class="all-50 xlarge-33 small-100 tiny-100">
								<div class="obj-right-div">
									<div class="obj-image-person">
										<figure class="ink-image ink-lazyload" data-delta="100">
											<a href="<?php echo $player_url; ?>">
												<img src="<?php echo $img_url;?>" data-src="<?php echo $img_url; ?>"
													class="lazyload-item" alt="<?php echo esc_attr($player->shortname); ?>"
													title="<?php echo esc_attr($player->shortname); ?>"
													onerror="this.onerror=null;this.src='<?php echo $noimg; ?>';" />
											</a>
										</figure>
									</div>
									<div class="obj-detail">
										<div class="obj-name"><a
												href="<?php echo $player_url; ?>"><?php echo esc_html($player->shortname); ?></a>
										</div>
										<div class="obj-link">
											<?php echo esc_html(f365_player_position_label($player->position)); ?>
										</div>
										<?php if ('' !== $age_display): ?>
											<div class="obj-link"><?php echo esc_html($age_display); ?></div>
										<?php endif; ?>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>

					<?php if ($total_pages > 1): ?>
						<?php echo futebol365_render_pagination( $cur_page, $total_pages ); ?>
					<?php endif; ?>
				<?php else: ?>
					<h5 class="no-info"><?php esc_html_e('Sem informação disponível', 'futebol365'); ?></h5>
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
