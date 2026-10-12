<?php
/**
 * Team Header (template-parts/team/f365-team-header.php)
 *
 * Edition + step selector engine. DATA reads are cached in the WordPress
 * object cache (group 'f365'); the rendered HTML / selected options stay live
 * because they vary by the URL query string (edition/step/competition).
 *
 * Cache is per team (+ step/gender/modality where relevant). Clear with
 * f365_flush_team_header_cache($team_id) after a migration re-import.
 */

defined('ABSPATH') || exit;

global $wpdb;

if ( ! defined( 'F365_TEAM_CACHE_TTL' ) ) {
	define( 'F365_TEAM_CACHE_TTL', HOUR_IN_SECONDS ); // bump to 12*HOUR_IN_SECONDS if data is static
}

/* ============================================================
 * Relational helpers (guarded) — each caches its result
 * ============================================================ */

if (!function_exists('f365_team_step_label_rel')) {
	function f365_team_step_label_rel(string $step): string
	{
		$map = [
			'principal' => 'Equipa Principal',
			'b_team' => 'Equipa B',
			'c_team' => 'Equipa C',
			'veterans' => 'Veteranos',
			'reservations' => 'Reservas',
			'juniors' => 'Juniores',
			'juvenis' => 'Juvenis',
			'iniciados' => 'Iniciados',
			'infantis' => 'Infantis',
			'benjamins' => 'Benjamins',
			'escolas' => 'Escolas',
		];
		return $map[$step] ?? ucwords(str_replace('_', ' ', $step));
	}
}

if (!function_exists('f365_team_editions_for_select_rel')) {
	function f365_team_editions_for_select_rel(int $team_id, string $step, string $gender, string $modality, string $team_type = 'club'): array
	{
		global $wpdb;
		$p   = $wpdb->prefix;

		$cache_key = "f365_team_editions_{$team_id}_{$step}_{$gender}_{$modality}_{$team_type}";
		$cached    = wp_cache_get( $cache_key, 'f365' );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		$out = [];

		if ('national' === $team_type) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT c.id AS comp_id, c.edition AS edition, c.name AS comp_name
					 FROM {$p}competition_participant pa
					 INNER JOIN {$p}team_teamstep ts        ON ts.id = pa.team_id
					 INNER JOIN {$p}competition_competition c ON c.id = pa.competition_id
					 WHERE ts.team_id = %d
					   AND ts.step = %s AND ts.gender = %s AND ts.modality = %s
					 ORDER BY ( c.edition + 0 ) DESC, c.name ASC",
					$team_id, $step, $gender, $modality
				)
			); // phpcs:ignore WordPress.DB

			foreach ((array) $rows as $r) {
				$ed  = trim((string) $r->edition);
				$cid = (int) $r->comp_id;
				if ('' === $ed || !$cid) {
					continue;
				}
				$key       = $ed . '|' . $cid;
				$out[$key] = $ed . ' | ' . (string) $r->comp_name;
			}

			uasort($out, static function ($a, $b) {
				return strcmp((string) $b, (string) $a);
			});

			wp_cache_set( $cache_key, $out, 'f365', F365_TEAM_CACHE_TTL );
			return $out;
		}

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT edition FROM {$p}team_teamstep
				 WHERE team_id = %d
				 ORDER BY CAST(edition AS UNSIGNED) DESC",
				$team_id
			)
		); // phpcs:ignore WordPress.DB
		foreach ((array) $rows as $ed) {
			$ed = trim((string) $ed);
			if ('' === $ed) {
				continue;
			}
			if (is_numeric($ed) && 4 === strlen($ed)) {
				$out[$ed] = $ed . '-' . ((int) $ed + 1);
			} else {
				$out[$ed] = $ed;
			}
		}

		wp_cache_set( $cache_key, $out, 'f365', F365_TEAM_CACHE_TTL );
		return $out;
	}
}

if (!function_exists('f365_team_steps_tree_rel')) {
	function f365_team_steps_tree_rel(int $team_id): array
	{
		global $wpdb;

		$cache_key = "f365_team_steps_{$team_id}";
		$cached    = wp_cache_get( $cache_key, 'f365' );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT modality, gender, step FROM {$wpdb->prefix}team_teamstep
				 WHERE team_id = %d
				 ORDER BY modality, gender, step",
				$team_id
			)
		); // phpcs:ignore WordPress.DB
		$grouped = [];
		foreach ((array) $rows as $r) {
			$grouped[$r->modality][$r->gender][] = $r->step;
		}

		$tree = [];
		foreach ($grouped as $modality => $genders) {
			$g_list = [];
			foreach ($genders as $gender => $steps) {
				$s_list = [];
				foreach ($steps as $step) {
					$s_list[] = [
						'modality' => $modality,
						'gender' => $gender,
						'step' => $step,
						'value' => f365_team_step_label_rel($step),
					];
				}
				$g_list[] = [
					'gender' => $gender,
					'steps' => $s_list,
				];
			}
			$tree[] = [
				'modality' => $modality,
				'genders' => $g_list,
			];
		}

		wp_cache_set( $cache_key, $tree, 'f365', F365_TEAM_CACHE_TTL );
		return $tree;
	}
}

if ( ! function_exists( 'f365_team_default_competition_rel' ) ) {
	function f365_team_default_competition_rel( int $team_id, string $step, string $gender, string $modality, string $team_type = 'club' ): int {
		if ( 'national' !== $team_type || ! $team_id ) {
			return 0;
		}
		global $wpdb;
		$p = $wpdb->prefix;

		$cache_key = "f365_team_defcomp_{$team_id}_{$step}_{$gender}_{$modality}";
		$cached    = wp_cache_get( $cache_key, 'f365' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$comp_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT c.id
				 FROM {$p}competition_participant pa
				 INNER JOIN {$p}team_teamstep ts        ON ts.id = pa.team_id
				 INNER JOIN {$p}competition_competition c ON c.id = pa.competition_id
				 WHERE ts.team_id = %d
				   AND ts.step = %s AND ts.gender = %s AND ts.modality = %s
				 ORDER BY ( c.edition + 0 ) DESC
				 LIMIT 1",
				$team_id, $step, $gender, $modality
			)
		); // phpcs:ignore WordPress.DB

		wp_cache_set( $cache_key, $comp_id, 'f365', F365_TEAM_CACHE_TTL );
		return $comp_id;
	}
}

if ( ! function_exists( 'f365_get_team_row_cached' ) ) {
	/** team_team row, cached by id. */
	function f365_get_team_row_cached( int $team_id ) {
		if ( ! $team_id ) {
			return null;
		}
		global $wpdb;
		$cache_key = "f365_team_row_{$team_id}";
		$cached    = wp_cache_get( $cache_key, 'f365' );
		if ( false !== $cached ) {
			return $cached;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT shortname,edition,foundation,federation_id,country_id,city,team_type,stadium_id,facebook,twitter,youtube,instagram,url
				 FROM {$wpdb->prefix}team_team WHERE id = %d",
				$team_id
			)
		); // phpcs:ignore WordPress.DB
		wp_cache_set( $cache_key, $row, 'f365', F365_TEAM_CACHE_TTL );
		return $row;
	}
}

if ( ! function_exists( 'f365_get_country_row_cached' ) ) {
	function f365_get_country_row_cached( int $country_id ) {
		if ( ! $country_id ) {
			return null;
		}
		global $wpdb;
		$cache_key = "f365_country_{$country_id}";
		$cached    = wp_cache_get( $cache_key, 'f365' );
		if ( false !== $cached ) {
			return $cached;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id,shortname,city FROM {$wpdb->prefix}country_country WHERE id = %d", $country_id )
		); // phpcs:ignore WordPress.DB
		wp_cache_set( $cache_key, $row, 'f365', F365_TEAM_CACHE_TTL );
		return $row;
	}
}

if ( ! function_exists( 'f365_get_stadium_row_cached' ) ) {
	function f365_get_stadium_row_cached( int $stadium_id ) {
		if ( ! $stadium_id ) {
			return null;
		}
		global $wpdb;
		$cache_key = "f365_stadium_{$stadium_id}";
		$cached    = wp_cache_get( $cache_key, 'f365' );
		if ( false !== $cached ) {
			return $cached;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id,shortname FROM {$wpdb->prefix}stadium_stadium WHERE id = %d", $stadium_id )
		); // phpcs:ignore WordPress.DB
		wp_cache_set( $cache_key, $row, 'f365', F365_TEAM_CACHE_TTL );
		return $row;
	}
}

if ( ! function_exists( 'f365_flush_team_header_cache' ) ) {
	/** Clear cached team-header data for one team (call after re-import). */
	function f365_flush_team_header_cache( int $team_id, int $country_id = 0, int $stadium_id = 0 ): void {
		wp_cache_delete( "f365_team_row_{$team_id}", 'f365' );
		wp_cache_delete( "f365_team_steps_{$team_id}", 'f365' );
		if ( $country_id ) {
			wp_cache_delete( "f365_country_{$country_id}", 'f365' );
		}
		if ( $stadium_id ) {
			wp_cache_delete( "f365_stadium_{$stadium_id}", 'f365' );
		}
		// editions/defcomp keys include step/gender/modality; clearing the common
		// principal/male/football combos covers the usual case.
		foreach ( [ 'club', 'national' ] as $tt ) {
			wp_cache_delete( "f365_team_editions_{$team_id}_principal_male_football_{$tt}", 'f365' );
			wp_cache_delete( "f365_team_defcomp_{$team_id}_principal_male_football", 'f365' );
		}
	}
}

/* ============================================================
 * Context Setup
 * ============================================================ */

$post_id = $args['post_id'] ?? get_the_ID();
$current_section = $args['current_section'] ?? 'details';
$sections = $args['sections'] ?? [];
$team_id = (int) ($args['team_id'] ?? 0);

$country_flag_url = '';
$country_name = '';
$final_img_url = '';
$team_shortname = '';
$team_foundation = '';
$team_edition = date('Y');
$team_city = '';
$team_facebook = $team_twitter = $team_youtube = $team_instagram = $team_url = '';
$team_type = 'club';
$stadium_name = '';

$countries = get_the_terms($post_id, 'f365_country');
if (!empty($countries) && !is_wp_error($countries)) {
	$country = reset($countries);
	$flag = function_exists('get_field') ? get_field('country_flag', 'term_' . $country->term_id) : '';
	$city_name = function_exists('get_field') ? (get_field('field_team_city', $post_id) ?: '') : '';
	$country_name = $country->name . ($city_name ? ' - ' . $city_name : '');
	if (is_numeric($flag)) {
		$country_flag_url = wp_get_attachment_image_url($flag, 'full');
	} elseif (is_array($flag) && !empty($flag['url'])) {
		$country_flag_url = $flag['url'];
	} elseif (is_string($flag)) {
		$country_flag_url = $flag;
	}
}

if ($team_id) {
	$team = f365_get_team_row_cached($team_id);

	if ($team) {
		$team_shortname = esc_html($team->shortname);
		$team_foundation = esc_html($team->foundation);
		$team_edition = !empty($team->edition) ? esc_html($team->edition) : date('Y');
		$team_city = esc_html($team->city);
		$team_facebook = esc_html($team->facebook);
		$team_twitter = esc_html($team->twitter);
		$team_youtube = esc_html($team->youtube);
		$team_instagram = esc_html($team->instagram);
		$team_url = esc_html($team->url);
		$team_type = esc_html($team->team_type) ?: 'club';
		$federation_id = (int) $team->federation_id;
		$stadium_id = (int) $team->stadium_id;
		$team_country = (int) $team->country_id;

		$country = f365_get_country_row_cached($team_country);
		$stadium = f365_get_stadium_row_cached($stadium_id);

		$stadium_name = $stadium ? esc_html($stadium->shortname) : '';
		$country_name = $country ? esc_html($country->shortname) : '';
		// Logo display must not depend on country data being resolvable —
		// club teams never get a mirror country_id (only national teams do),
		// so gating the thumbnail lookup on $country_name hid every club
		// team's crest regardless of whether its logo was actually set.
		$final_img_url = get_the_post_thumbnail_url($post_id, 'full') ?: '';
		if ('' !== $country_name) {
			$country_name = $country_name . ($team_city ? ' - ' . $team_city : '');
		}
	}
}

/* ============================================================
 * Filter Parameters & Select Logic
 * ============================================================ */

$cur_step = isset($_GET['step']) ? sanitize_text_field(wp_unslash($_GET['step'])) : 'principal';
$cur_modality = isset($_GET['modality']) ? sanitize_text_field(wp_unslash($_GET['modality'])) : 'football';
$cur_gender = isset($_GET['gender']) ? sanitize_text_field(wp_unslash($_GET['gender'])) : 'male';
$cur_competition = isset($_GET['competition']) && '' !== $_GET['competition']
	? sanitize_text_field(wp_unslash($_GET['competition']))
	: '';

$is_national = ('national' === strtolower(wp_strip_all_tags((string) $team_type)));
if ( '' === $cur_competition ) {
	$default_competition = f365_team_default_competition_rel(
		$team_id, $cur_step, $cur_gender, $cur_modality,
		$is_national ? 'national' : 'club'
	);
	$cur_competition = $default_competition ? (string) $default_competition : '';
}
$editions = $team_id ? f365_team_editions_for_select_rel($team_id, $cur_step, $cur_gender, $cur_modality, $is_national ? 'national' : 'club') : [];

$default_edition_key = $editions ? (string) array_key_first($editions) : '';
$cur_edition = isset($_GET['edition']) && '' !== $_GET['edition']
	? sanitize_text_field(wp_unslash($_GET['edition']))
	: ($editions ? (string) explode('|', (string) array_key_first($editions))[0] : (string) $team_edition);

$steps_tree = $team_id ? f365_team_steps_tree_rel($team_id) : [];
$sel_modality = $cur_modality;
$sel_gender = $cur_gender;
$sel_step = $cur_step;

$base_url = home_url("/equipa/{$team_id}/");
$args_line = array_filter([
	'edition' => $cur_edition,
	'step' => $cur_step,
	'modality' => $cur_modality,
	'gender' => $cur_gender,
	'competition' => $cur_competition,
]);
?>
<div class="ink-grid vertical-space">

	<?php get_template_part('template-parts/template-offer-widget'); ?>

	<div class="ink-header-f365 equipas" style="pointer-events: unset;">
		<div class="column-group" style="pointer-events: unset;">
			<div class="align-left all-60 medium-100 small-100 tiny-100 banner-left-content"
				style="pointer-events: unset;">
				<?php if ($final_img_url): ?>
					<div class="flag">
						<i class="flag-icon" title="<?php echo esc_attr($team_shortname); ?>">
							<img src="<?php echo esc_url($final_img_url); ?>"
								alt="<?php echo esc_attr($team_shortname); ?>"
								title="<?php echo esc_attr($team_shortname); ?>" />
						</i>

					</div>
				<?php endif; ?>
				<div class="headerdata" style="pointer-events: unset;">
					<a href="<?php echo esc_url(add_query_arg($args_line, $base_url)); ?>"
						style="pointer-events: unset;">
						<h1 style="pointer-events: unset;"><?php echo $team_shortname; ?></h1>
					</a>
					<div style="pointer-events: unset;">Fundação:
						<?php echo $team_foundation ? esc_html(date('Y', strtotime($team_foundation))) : ''; ?>
					</div>
					<?php if (!empty($stadium_name)): ?>
						<div style="pointer-events: unset;">Estádio: <?php echo $stadium_name; ?></div>
					<?php endif; ?>
					<?php if ($country_name): ?>
						<div>
							<?php if ($country_flag_url): ?>
								<i class="flag-bg quarter-right-space"
									style="display:inline-block;width:18px;height:12px;background-image:url(<?php echo esc_url($country_flag_url); ?>);background-size:cover;background-position:center;background-repeat:no-repeat;vertical-align:middle;"></i>
							<?php endif; ?>
							<?php echo esc_html($country_name); ?>
						</div>
					<?php endif; ?>
					<?php if (!empty($team_facebook) || $team_youtube || $team_instagram || $team_url): ?>
						<div class="federation-socials">
							<?php if ($team_facebook): ?>
								<a href="https://www.facebook.com/<?php echo $team_facebook; ?>" target="_blank"
									aria-label="Facebook"><svg class="quarter-right-space" viewBox="0 0 16 16" width="1em"
										height="1em" aria-hidden="true" focusable="false">
										<use href="#social-facebook"></use>
									</svg></a>
							<?php endif; ?>
							<?php if ($team_twitter): ?>
								<a href="https://x.com/<?php echo $team_twitter; ?>" target="_blank" aria-label="X (Twitter)"
									style="pointer-events: unset;"><svg class="quarter-right-space" viewBox="0 0 16 16"
										width="1em" height="1em" aria-hidden="true" focusable="false"
										style="pointer-events: unset;">
										<use href="#social-x" style="pointer-events: unset;"></use>
									</svg></a>
							<?php endif; ?>
							<?php if ($team_youtube): ?>
								<a href="https://www.youtube.com/@<?php echo $team_youtube; ?>" target="_blank"
									aria-label="YouTube"><svg class="quarter-right-space" viewBox="0 0 16 16" width="1em"
										height="1em" aria-hidden="true" focusable="false">
										<use href="#social-youtube"></use>
									</svg></a>
							<?php endif; ?>
							<?php if ($team_instagram): ?>
								<a href="https://www.instagram.com/<?php echo $team_instagram; ?>" target="_blank"
									aria-label="Instagram"><svg class="quarter-right-space" viewBox="0 0 16 16" width="1em"
										height="1em" aria-hidden="true" focusable="false">
										<use href="#social-instagram"></use>
									</svg></a>
							<?php endif; ?>
							<?php if ($team_url): ?>
								<a href="<?php echo esc_url($team_url); ?>" target="_blank" rel="noopener noreferrer"
									aria-label="Website oficial"><svg class="quarter-right-space" viewBox="0 0 16 16"
										width="1em" height="1em" aria-hidden="true">
										<use href="#icon-link"></use>
									</svg></a>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<div
				class="align-right medium-align-left small-align-left tiny-align-left all-40 medium-100 small-100 tiny-100 medium-top-space small-top-space tiny-top-space">

				<?php
				/* Edition/Year Selector */
				if (!empty($editions) && !in_array($current_section, ['noticias', 'palmares', 'historico', 'videos'], true)):
					?>
					<form class="ink-form" method="get" name="team_edition_form" id="team_edition_form">
						<div class="control-group">
							<select id="edition" name="edition" style="pointer-events:unset;">
								<?php foreach ($editions as $ed_key => $ed_label):
									$ed_parts   = explode('|', (string) $ed_key);
									$ed_part    = $ed_parts[0];
									$comp_part  = isset($ed_parts[1]) ? $ed_parts[1] : '';
									$has_url_edition = isset($_GET['edition']) && '' !== $_GET['edition']; // phpcs:ignore
									if (!$has_url_edition) {
										$ed_sel = ((string) $ed_key === (string) $default_edition_key);
									} else {
										$ed_sel = ((string) $cur_edition === (string) $ed_part)
											&& ('' === $comp_part || '' === (string) $cur_competition || (string) $cur_competition === (string) $comp_part);
									}
									?>
									<option value="<?php echo esc_attr($ed_key); ?>" <?php selected($ed_sel); ?> style="pointer-events:unset;">
										<?php echo esc_html($ed_label); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
					</form>
					<script>
						(function () {
							var el = document.getElementById('edition');
							if (!el) { return; }
							el.addEventListener('change', function () {
								var p = {}, qs = location.search.substring(1), re = /([^&=]+)=([^&]*)/g, m;
								while ((m = re.exec(qs))) { p[decodeURIComponent(m[1])] = decodeURIComponent(m[2]); }
								var parts = this.value.split('|');
								if (parts.length === 2) { p['edition'] = parts[0]; p['competition'] = parts[1]; }
								else { p['edition'] = parts[0]; delete p['competition']; }
								delete p['match_option']; delete p['competition_phase']; delete p['opponent'];
								var a = []; for (var k in p) { if (p[k] !== '') { a.push(encodeURIComponent(k) + '=' + encodeURIComponent(p[k])); } }
								window.location.href = location.pathname + (a.length ? '?' + a.join('&') : '');
							});
						})();
					</script>
				<?php endif; ?>

				<?php
				/* Step selector */
				if (!empty($steps_tree) && !in_array($current_section, ['noticias', 'palmares', 'historico', 'videos'], true)):
					?>
					<form class="ink-form" name="team_steps_form" id="team_steps_form">
						<div class="control-group">
							<?php
							if (!empty($steps_tree)) {
								foreach ($steps_tree as &$modality_data) {
									if (!empty($modality_data['genders'])) {
										usort($modality_data['genders'], function ($a, $b) {
											return ($a['gender'] === 'male') ? -1 : 1;
										});
										$step_order = ['principal', 'b_team', 'sub_19'];
										foreach ($modality_data['genders'] as &$gender_data) {
											if (!empty($gender_data['steps'])) {
												usort($gender_data['steps'], function ($a, $b) use ($step_order) {
													$posA = array_search($a['step'], $step_order);
													$posB = array_search($b['step'], $step_order);
													return ($posA === false ? 99 : $posA) <=> ($posB === false ? 99 : $posB);
												});
											}
										}
										unset($gender_data);
									}
								}
								unset($modality_data);
							}
							?>

							<select id="step_selector" name="step_selector" style="pointer-events:unset;">
								<?php foreach ($steps_tree as $modality_data): ?>
									<?php foreach ($modality_data['genders'] as $gender_data): ?>

										<option disabled
											style="font-weight: bold; color: #000; background-color: #fff; opacity: 1; padding: 4px 8px;">
											<?php echo mb_strtoupper(
												f365_translate_modality_gender(
													$modality_data['modality'],
													$gender_data['gender']
												)
											); ?>
										</option>

										<?php foreach ($gender_data['steps'] as $step_data):
											$opt_value = $step_data['modality'] . '_' . $step_data['gender'] . '_' . $step_data['step'];
											$is_selected = ($step_data['modality'] === $sel_modality && $step_data['gender'] === $sel_gender && $step_data['step'] === $sel_step);

											if ('principal' === $step_data['step']) {
												$opt_label = 'Equipa Principal';
											} elseif ('b_team' === $step_data['step'] || 'b' === strtolower($step_data['step'])) {
												$opt_label = 'B';
											} elseif ('sub_19' === $step_data['step']) {
												$opt_label = 'S19';
											} else {
												$opt_label = $step_data['value'];
											}
											?>
											<option value="<?php echo esc_attr($opt_value); ?>" <?php selected($is_selected); ?>
												style="padding-left: 15px;">
												<?php echo esc_html($opt_label); ?>
											</option>
										<?php endforeach; ?>

									<?php endforeach; ?>
								<?php endforeach; ?>
							</select>
						</div>
					</form>
					<script>
						(function () {
							var el = document.getElementById('step_selector');
							if (!el) { return; }
							el.addEventListener('change', function () {
								var p = {}, qs = location.search.substring(1), re = /([^&=]+)=([^&]*)/g, m;
								while ((m = re.exec(qs))) { p[decodeURIComponent(m[1])] = decodeURIComponent(m[2]); }
								var parts = this.value.split('_');
								p['modality'] = parts[0];
								p['gender'] = parts[1];
								p['step'] = parts.slice(2).join('_');
								delete p['edition'];
								delete p['competition'];
								var a = []; for (var k in p) { if (p[k] !== '') { a.push(encodeURIComponent(k) + '=' + encodeURIComponent(p[k])); } }
								location.search = a.join('&');
							});
						})();
					</script>
				<?php endif; ?>
				<?php if ('historico' == $current_section):

					$cur_list_type = isset($_GET['list_type']) ? sanitize_key(wp_unslash($_GET['list_type'])) : 'players';
					if (!in_array($cur_list_type, ['players', 'coaches', 'presidents', 'competitions'], true)) {
						$cur_list_type = 'players';
					}
					$list_types = [
						'players' => 'Jogadores',
						'coaches' => 'Treinadores',
						'presidents' => 'Presidentes',
						'competitions' => 'Competições',
					];
					$hist_base = $base_url . trailingslashit('historico');
					?>
					<form class="ink-form" name="team_listtype_form" id="team_listtype_form">
						<div class="control-group">
							<select id="list_type" name="list_type"
								onchange="if(this.value){window.location.href=this.value;}" style="pointer-events:unset;">
								<?php foreach ($list_types as $lt_key => $lt_label):
									$lt_url = add_query_arg(array_filter([
										'list_type' => $lt_key,
										'edition' => $cur_edition,
										'step' => $cur_step,
										'modality' => $cur_modality,
										'gender' => $cur_gender,
									]), $hist_base);
									?>
									<option value="<?php echo esc_url($lt_url); ?>" <?php selected($cur_list_type, $lt_key); ?> style="pointer-events:unset;">
										<?php echo esc_html($lt_label); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
					</form>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<?php
	if (!function_exists('is_active_section')) {
		function is_active_section($section)
		{
			return strpos($_SERVER['REQUEST_URI'] ?? '', "/{$section}/") !== false;
		}
	}
	?>
	<nav class="nav-main" id="nav-main">
		<ul>
			<li><a class="<?php echo is_active_section('jogos') ? 'active' : ''; ?>"
					href="<?php echo esc_url(add_query_arg($args_line, home_url("/equipa/{$team_id}/jogos/"))); ?>">Jogos</a>
			</li>
			<li><a class="<?php echo is_active_section('calendario') ? 'active' : ''; ?>"
					href="<?php echo esc_url(add_query_arg($args_line, home_url("/equipa/{$team_id}/calendario/"))); ?>">Calendário</a>
			</li>
			<li><a class="<?php echo is_active_section('plantel') ? 'active' : ''; ?>"
					href="<?php echo esc_url(add_query_arg($args_line, home_url("/equipa/{$team_id}/plantel/"))); ?>">Plantel</a>
			</li>
			<li><a class="<?php echo is_active_section('transferencias') ? 'active' : ''; ?>"
					href="<?php echo esc_url(add_query_arg($args_line, home_url("/equipa/{$team_id}/transferencias/"))); ?>">Transferências</a>
			</li>
			<li><a class="<?php echo is_active_section('palmares') ? 'active' : ''; ?>"
					href="<?php echo esc_url(add_query_arg($args_line, home_url("/equipa/{$team_id}/palmares/"))); ?>">Palmarés</a>
			</li>
			<li><a class="<?php echo is_active_section('noticias') ? 'active' : ''; ?>"
					href="<?php echo esc_url(add_query_arg($args_line, home_url("/equipa/{$team_id}/noticias/"))); ?>">Notícias</a>
			</li>
			<li><a class="<?php echo is_active_section('videos') ? 'active' : ''; ?>"
					href="<?php echo esc_url(add_query_arg($args_line, home_url("/equipa/{$team_id}/videos/"))); ?>">Videos</a>
			</li>
			<li><a class="<?php echo is_active_section('historico') ? 'active' : ''; ?>"
					href="<?php echo esc_url(add_query_arg($args_line, home_url("/equipa/{$team_id}/historico/"))); ?>">Histórico</a>
			</li>
			<li><a class="<?php echo is_active_section('info') ? 'active' : ''; ?>"
					href="<?php echo esc_url(add_query_arg($args_line, home_url("/equipa/{$team_id}/info/"))); ?>">Dados
					Gerais</a></li>
		</ul>
	</nav>

	<nav class="nav-mobile" id="nav-mobile"></nav>

</div>
<?php
function f365_translate_modality_gender($modality, $gender)
{

	$modality_map = [
		'FOOTBALL' => 'FUTEBOL',
		'BASKETBALL' => 'BASQUETEBOL',
		'TENNIS' => 'TÉNIS',
	];

	$gender_map = [
		'FEMALE' => 'FEMININO',
		'MALE' => 'MASCULINO',
	];

	$modality_pt = $modality_map[strtoupper($modality)] ?? $modality;
	$gender_pt = $gender_map[strtoupper($gender)] ?? $gender;

	return $modality_pt . ' ' . $gender_pt;
}
?>
