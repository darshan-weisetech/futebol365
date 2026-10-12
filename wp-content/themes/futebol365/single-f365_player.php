<?php get_header();

$post_id = $args['post_id'] ?? null;
global $wpdb;

$django_player_id = '';
if (isset($_SERVER['REQUEST_URI']) && preg_match('#/jogador/([0-9]+)#i', (string) $_SERVER['REQUEST_URI'], $matches)) {
	$django_player_id = $matches[1];
} elseif (get_query_var('f365_player')) {
	$django_player_id = get_query_var('f365_player');
} elseif (get_query_var('name')) {
	$django_player_id = get_query_var('name');
}

if (!$post_id) {
	if (!empty($django_player_id) && is_numeric($django_player_id)) {
		$cache_key = 'f365_player_post_id_' . $django_player_id;
		$found_id = wp_cache_get($cache_key, 'f365_player');

		if (false === $found_id) {
			$found_id = $wpdb->get_var($wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE legacy_id = %d AND post_type = 'f365_player' LIMIT 1",
				$django_player_id
			));
			wp_cache_set($cache_key, $found_id ?: 0, 'f365_player', HOUR_IN_SECONDS);
		}

		$post_id = $found_id ? (int) $found_id : get_the_ID();
	} else {
		$post_id = get_the_ID();
	}
}

$current_section = get_query_var('section', '');
$sections = futebol365_get_player_sections();
if (!isset($sections[$current_section])) {
	$current_section = '';
}
$current_config = $sections[$current_section];

/* Helpers */
if (!function_exists('f365_player_season_label')) {
	function f365_player_season_label(int $edition, int $federation_id): string
	{
		global $wpdb;
		if (!$edition)
			return '';
		static $fed_cache = [];
		if ($federation_id && !isset($fed_cache[$federation_id])) {
			$fed_cache[$federation_id] = $wpdb->get_row($wpdb->prepare(
				"SELECT season_type, season_begin_with_edition FROM {$wpdb->prefix}federation_federation WHERE id = %d",
				$federation_id
			));
		}
		$fed = $fed_cache[$federation_id] ?? null;
		$season_type = $fed->season_type ?? 'winter';
		$raw = (string) ($fed->season_begin_with_edition ?? '1');
		$begin_with = ('1' === $raw || 't' === $raw || 'true' === $raw || 1 === (int) $raw) ? 1 : 0;

		if ('summer' === $season_type)
			return (string) $edition;
		return $begin_with ? $edition . '-' . ($edition + 1) : ($edition - 1) . '-' . $edition;
	}
}

if (!function_exists('f365_get_country_data_by_id')) {
	function f365_get_country_data_by_id($country_id, $fallback_name = '')
	{
		static $countries = [];
		if (!$country_id)
			return ['name' => $fallback_name, 'flag_url' => ''];
		$country_id = (int) $country_id;
		if (isset($countries[$country_id])) {
			if ('' === $countries[$country_id]['name'] && '' !== $fallback_name) {
				$countries[$country_id]['name'] = $fallback_name;
			}
			return $countries[$country_id];
		}
		$country = ['name' => (105 === $country_id ? 'Arábia Saudita' : $fallback_name), 'flag_url' => ''];
		$countries[$country_id] = $country;
		return $country;
	}
}

if (!function_exists('f365_player_stat_value')) {
	function f365_player_stat_value($value)
	{
		return (null === $value || '' === $value) ? '-' : (string) (int) $value;
	}
}

if (!function_exists('f365_get_player_squads')) {
	function f365_get_player_squads($player_id, $team_type)
	{
		global $wpdb;
		return $wpdb->get_results($wpdb->prepare("
			SELECT s.id AS squad_id, ts.id AS teamstep_id, ts.edition AS ts_edition, ts.step, ts.modality, ts.gender, 
			       t.id AS team_id, t.shortname AS team_shortname, t.longname AS team_longname, t.country_id AS team_country_id, 
			       t.federation_id, sc.games, sc.goals, sc.competition_id, comp.name AS competition_name, c.shortname AS country_name
			FROM {$wpdb->prefix}team_squad s
			INNER JOIN {$wpdb->prefix}team_teamstep ts ON ts.id = s.team_id
			INNER JOIN {$wpdb->prefix}team_team t ON t.id = ts.team_id
			LEFT JOIN {$wpdb->prefix}team_squadcompetition sc ON sc.squad_id = s.id
			LEFT JOIN {$wpdb->prefix}competition_competition comp ON comp.id = sc.competition_id
			LEFT JOIN {$wpdb->prefix}country_country c ON c.id = t.country_id
			WHERE t.team_type = %s AND s.player_id = %d
			ORDER BY ts.edition DESC, s.`order` ASC, s.id DESC
		", $team_type, $player_id));
	}
}
?>

<main id="primary" class="site-main single-article-page">
	<?php get_template_part('template-parts/player/f365-player-header', null, ['post_id' => $post_id, 'current_section' => $current_section, 'sections' => $sections]); ?>

	<div class="ink-grid futebol365-container">
		<section class="left-column-block">
			<div class="breadcrumbs-f365">
				<div class="ink-navigation breadcrumbs">
					<?php if (function_exists('yoast_breadcrumb'))
						yoast_breadcrumb(); ?>
				</div>
			</div>

			<?php get_template_part('template-parts/player/f365-player-' . $current_config['template'], null, ['post_id' => $post_id]); ?>

			<?php
			$squad_definitions = [
				'club' => [
					'title' => __('Clubes', 'f365'),
					'class' => '',
					'headers' => [__('Época', 'f365'), __('Clube', 'f365'), __('Pais', 'f365')]
				],
				'national' => [
					'title' => __('Seleção', 'f365'),
					'class' => 'red',
					'headers' => [__('Edição', 'f365'), __('Competição', 'f365'), __('Seleção', 'f365')]
				]
			];

			foreach ($squad_definitions as $type => $cfg):
				$results = f365_get_player_squads($django_player_id, $type);
				if (empty($results))
					continue;
				?>
				<h5 class="condensed-400 headerTitle top-space <?php echo esc_attr($cfg['class']); ?>">
					<?php echo esc_html($cfg['title']); ?> <span class="headerTitle-border"></span>
				</h5>

				<table class="ink-table ink-table-f365 alternating all-100">
					<thead>
						<tr>
							<th class="align-left all-15 tiny-30 small-30"><?php echo esc_html($cfg['headers'][0]); ?></th>
							<th class="align-left"><?php echo esc_html($cfg['headers'][1]); ?></th>
							<th class="align-left all-30 hide-tiny hide-small"><?php echo esc_html($cfg['headers'][2]); ?>
							</th>
							<th class="align-center all-10"><span class="ink-tooltip" data-tip-text="Jogos"
									data-tip-where="up" data-tip-color="black">J</span></th>
							<th class="align-center all-10"><span class="ink-tooltip" data-tip-text="Golos"
									data-tip-where="up" data-tip-color="black">G</span></th>
							<th class="align-center all-10 hide-tiny hide-small"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($results as $row):
							$edition = (int) ($row->ts_edition ?? 0);
							$season = f365_player_season_label($edition, (int) ($row->federation_id ?? 0));
							$comp_id = (int) ($row->competition_id ?? 0);

							$games_url = home_url("/jogador/{$django_player_id}/jogos/?edition={$edition}" . ($type === 'national' ? "&competition={$comp_id}" : ""));

							if ($type === 'club') {
								$team_url = home_url("/equipa/{$row->team_id}/plantel/") . '?' . http_build_query([
									'edition' => $edition,
									'modality' => $row->modality,
									'gender' => $row->gender,
									'step' => $row->step
								]);
								$col1_link = $games_url;
								$col2_link = $team_url;
								$col2_text = $row->team_shortname ?? $row->team_longname ?? '';
							} else {
								$team_url = home_url("/equipa/{$row->team_id}/plantel/?edition={$edition}&modality=football&gender=male&step=principal&competition={$comp_id}");
								$col1_link = $games_url;
								$col2_link = $comp_id ? home_url("/competicao/{$comp_id}/?edition={$edition}") : '#';
								$col2_text = $row->competition_name;
							}

							$country = f365_get_country_data_by_id($row->team_country_id, $row->country_name ?? '');
							$cid = (int) ($row->team_country_id ?? 0);
							?>
							<tr>
								<td class="align-left"><a
										href="<?php echo esc_url($col1_link); ?>"><?php echo esc_html($season); ?></a></td>
								<td class="align-left"><a
										href="<?php echo esc_url($col2_link); ?>"><?php echo esc_html($col2_text); ?></a></td>
								<td class="align-left hide-tiny hide-small">
									<?php if ($cid): ?><i
											class="flag-icon quarter-right-space flag-icon-<?php echo esc_attr($cid); ?>"
											title="<?php echo esc_attr($country['name']); ?>"></i><?php endif; ?>
									<?php if ($type === 'national'): ?><a
											href="<?php echo esc_url($team_url); ?>"><?php endif; ?>
										<?php echo esc_html($row->team_shortname ?? $row->team_longname ?? ''); ?>
										<?php if ($type === 'national'): ?></a><?php endif; ?>
								</td>
								<td class="align-center"><?php echo esc_html(f365_player_stat_value($row->games)); ?></td>
								<td class="align-center"><?php echo esc_html(f365_player_stat_value($row->goals)); ?></td>
								<td class="align-center hide-tiny hide-small">
									<?php if ($row->games > 0 || $row->goals > 0): ?>
										<a href="<?php echo esc_url($games_url); ?>"><svg class="svg-icon" viewBox="0 0 16 16"
												width="1em" height="1em">
												<use href="#icon-plus"></use>
											</svg></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>

			<?php get_template_part('template-parts/template-front-page-posts'); ?>
		</section>

		<section class="right-column-block">
			<?php if (is_active_sidebar('post-sidebar'))
				dynamic_sidebar('post-sidebar');
			else
				get_sidebar(); ?>
		</section>
	</div>
</main>
<?php get_footer(); ?>