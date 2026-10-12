<?php
/**
 * Player Header (template part).
 *
 * Data comes from f365_get_player_header_data() (custom tables; no postmeta/ACF).
 * HTML structure unchanged from the original. Player photo: WP featured image
 * first, else the source person-photo URL. The edition dropdown renders only on
 * the "jogos" section (source: the selector lives in player-games only).
 *
 * Args:
 *   $args['post_id']         f365_player WP post id (resolved by the caller/router)
 *   $args['current_section'] active section slug (e.g. 'details','jogos',…)
 *   $args['sections']        nav sections
 *   $args['editions_list']   edition options (only used on 'jogos')
 *   $args['sel_edition']     selected edition (only used on 'jogos')
 *
 * @package Futebol365
 */

defined('ABSPATH') || exit;

$post_id = (int) ($args['post_id'] ?? 0);
if ($post_id <= 0) {
	$post_id = (int) get_the_ID();
}

$sections = $args['sections'] ?? (function_exists('futebol365_get_player_sections') ? futebol365_get_player_sections() : []);
$editions_list = $args['editions_list'] ?? [];
$sel_edition = $args['sel_edition'] ?? '';

if (!empty($editions_list) || '' !== (string) $sel_edition) {
	$normalised_editions = [];

	foreach ((array) $editions_list as $edition_option) {
		$edition_value = (string) (is_object($edition_option) ? ($edition_option->edition ?? '') : ($edition_option['edition'] ?? ''));
		if ('' !== $edition_value) {
			$normalised_editions[$edition_value] = (object) ['edition' => $edition_value];
		}
	}

	if ('' !== (string) $sel_edition) {
		$normalised_editions[(string) $sel_edition] = (object) ['edition' => (string) $sel_edition];
	}

	$editions_list = array_values($normalised_editions);
	usort(
		$editions_list,
		static function ($a, $b) {
			return (int) ($b->edition ?? 0) <=> (int) ($a->edition ?? 0);
		}
	);
}

$current_section = (string) ($args['current_section'] ?? '');
$url_section = '';

if (isset($_SERVER['REQUEST_URI'])) {
	$request_uri = wp_unslash($_SERVER['REQUEST_URI']);

	if (preg_match('/\/jogador\/[^\/]+\/([^\/\?\#]+)\/?/i', $request_uri, $matches)) {
		$url_section = sanitize_key($matches[1]);
	}
}


foreach ([get_query_var('f365_section'), get_query_var('section'), $url_section, $current_section, 'details'] as $section_candidate) {
	$section_candidate = sanitize_key((string) $section_candidate);

	if (isset($sections[$section_candidate])) {
		$current_section = $section_candidate;
		break;
	}
}

$data = function_exists('f365_get_player_header_data') ? f365_get_player_header_data($post_id) : [];
if (empty($data)) {
	return; // no resolvable player — render nothing rather than a broken header
}

// Player photo: WP featured image first, else source person-photo URL.
$photo_url = get_the_post_thumbnail_url($post_id, 'full');
if (!$photo_url) {
	$photo_url = (string) ($data['photo_url'] ?? '');
}


$base_url = user_trailingslashit(get_permalink($post_id));

$shortname = (string) ($data['shortname'] ?? '');
$position = (string) ($data['position_label'] ?? '');
$is_deceased = !empty($data['is_deceased']);
$age = $data['age'] ?? null;
$birth_place = (string) ($data['birth_place'] ?? '');
$naturality = $data['naturality'] ?? ['id' => 0, 'name' => '', 'flag_class' => ''];
$nationality = $data['nationality'] ?? ['id' => 0, 'name' => '', 'flag_class' => ''];
$club = $data['club'] ?? null;
$socials = $data['socials'] ?? [];
$website = (string) ($data['website'] ?? '');

$social_urls = [
	'facebook' => 'https://www.facebook.com/',
	'twitter' => 'https://x.com/',
	'youtube' => 'https://www.youtube.com/',
	'instagram' => 'https://www.instagram.com/',
];
$social_icons = [
	'facebook' => 'social-facebook',
	'twitter' => 'social-x',
	'youtube' => 'social-youtube',
	'instagram' => 'social-instagram',
];
?>

<div class="ink-grid vertical-space <?php echo esc_attr($post_id); ?>">

	<?php get_template_part('template-parts/template-offer-widget'); ?>

	<div class="ink-header-f365 competicoes"
		style="background-image: linear-gradient(to left, rgba(250, 250, 250, 0) 40%, rgba(0, 0, 0, 1)), url(<?php echo esc_url(FB_THEME_URL . '/assets/images/pl-bg.jpg'); ?>);">
		<div class="column-group">
			<div class="align-left all-60 medium-100 small-100 tiny-100 banner-left-content">
				<?php if ($photo_url): ?>
					<div class="flag">
						<i class="flag-icon" title="<?php echo esc_attr($shortname); ?>">
							<img src="<?php echo esc_url($photo_url); ?>" alt="<?php echo esc_attr($shortname); ?>"
								onerror="this.onerror=null;this.src='<?php echo esc_url(FB_THEME_URL . '/assets/images/no-image.jpg'); ?>';">
						</i>
					</div>
				<?php endif; ?>
				<div class="headerdata">
					<?php if ($shortname): ?>
						<h1><?php echo esc_html($shortname); ?></h1>
					<?php endif; ?>

					<?php if ($position || $age !== null || $is_deceased): ?>
						<div>
							<?php echo esc_html($position); ?> 	<?php
									if ($is_deceased) {
										echo ', ' . esc_html__('Falecido', 'f365');
									} elseif ($age !== null) {
										echo ', ' . esc_html($age) . ' ' . esc_html__('anos', 'f365');
									}
									?>
						</div>
					<?php endif; ?>

					<?php if ($club && !empty($club['name'])): ?>
						<div>
							<?php esc_html_e('Clube', 'f365'); ?>:
							<?php if (!empty($club['url'])): ?>
								<a href="<?php echo esc_url($club['url']); ?>"><?php echo esc_html($club['name']); ?></a>
							<?php else: ?>
								<?php echo esc_html($club['name']); ?>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<?php // Naturality (or nationality fallback) + birth place. ?>
					<div>
						<?php if (!empty($naturality['id'])): ?>
							<i class="<?php echo esc_attr($naturality['flag_class']); ?> quarter-right-space"
								title="<?php echo esc_attr($naturality['name']); ?>"></i><?php echo esc_html($naturality['name']); ?><?php echo $birth_place !== '' ? ' - ' . esc_html($birth_place) : ''; ?>
						<?php elseif (!empty($nationality['id'])): ?>
							<i class="<?php echo esc_attr($nationality['flag_class']); ?> quarter-right-space"
								title="<?php echo esc_attr($nationality['name']); ?>"></i><?php echo esc_html($nationality['name']); ?><?php echo $birth_place !== '' ? ' - ' . esc_html($birth_place) : ''; ?>
						<?php endif; ?>
					</div>

					<?php if (!empty($data['show_nationality_separately'])): ?>
						<div>
							<i class="<?php echo esc_attr($nationality['flag_class']); ?> quarter-right-space"
								title="<?php echo esc_attr($nationality['name']); ?>"></i><?php echo esc_html($nationality['name']); ?>
						</div>
					<?php endif; ?>

					<?php if (array_filter($socials) || $website !== ''): ?>
						<div class="federation-socials">
							<?php foreach ($socials as $network => $value): ?>
								<?php if ($value !== '' && isset($social_urls[$network])): ?>
									<a href="<?php echo esc_url($social_urls[$network] . ltrim($value, '/')); ?>" target="_blank"
										rel="noopener noreferrer" aria-label="<?php echo esc_attr(ucfirst($network)); ?>">
										<svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em"
											aria-hidden="true">
											<use href="#<?php echo esc_attr($social_icons[$network]); ?>"></use>
										</svg>
									</a>
								<?php endif; ?>
							<?php endforeach; ?>
							<?php if ($website !== ''): ?>
								<a href="<?php echo esc_url($website); ?>" target="_blank" rel="noopener noreferrer"
									aria-label="<?php echo esc_attr__('Website oficial', 'f365'); ?>">
									<svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em"
										aria-hidden="true">
										<use href="#icon-link"></use>
									</svg>
								</a>
							<?php endif; ?>
						</div>
					<?php endif; ?>

				</div>
			</div>

			<?php // Edition selector: source shows it on the JOGOS section only. ?>
			<div
				class="align-right medium-align-left small-align-left tiny-align-left all-40 medium-100 small-100 tiny-100 medium-top-space small-top-space tiny-top-space">

				<?php if (!empty($editions_list)): 
					$max_year = 0;
					foreach ($editions_list as $ed) {
						$y = (int) (isset($ed->edition) ? $ed->edition : ($ed['edition'] ?? 0));
						if ($y > $max_year) {
							$max_year = $y;
						}
					}
				?>
					<!-- Edition Logic: Max Year = <?php echo $max_year; ?> -->
					<form class="ink-form" id="header-edition-filter" method="get">
						<div class="control-group">
							<div class="control">
								<select name="edition" id="header_id_edition" onchange="f365PlayerEditionChange()"
									class="wh-auto">
									<?php foreach ($editions_list as $ed): 
										$year = (int) (isset($ed->edition) ? $ed->edition : ($ed['edition'] ?? 0));
										$display = ($year > 0 && $year < $max_year) ? $year . '-' . ($year + 1) : $year;
									?>
										<option value="<?php echo esc_attr($ed->edition ?? $ed['edition'] ?? ''); ?>" <?php selected($sel_edition, ($ed->edition ?? $ed['edition'] ?? '')); ?>><?php echo esc_html($display); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
					</form>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<div class="nav-trigger" id="nav-trigger">
		<span>Menu</span>
	</div>

	<nav class="nav-main" id="nav-main">
		<ul>
			<?php foreach ($sections as $slug => $section): ?>
				<?php
				$is_active = $current_section === $slug;
				$tab_url = ('details' === $slug) ? $base_url : $base_url . trailingslashit($slug);
				?>
				<li>
					<a class="<?php echo $is_active ? 'active' : ''; ?>" href="<?php echo esc_url($tab_url); ?>">
						<?php echo esc_html($section['label'] ?? ''); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</nav>
	<nav class="nav-mobile" id="nav-mobile"></nav>

</div>
<?php if (!empty($editions_list)): ?>
	<script type="text/javascript">
		function f365PlayerEditionChange() {
			var edition = document.getElementById('header_id_edition').value;
			var url = new URL(window.location.href);
			url.searchParams.set('edition', edition);
			url.searchParams.delete('competition');
			url.searchParams.delete('opponent');
			url.searchParams.delete('stat');
			window.location.href = url.toString();
		}
	</script>
<?php endif; ?>