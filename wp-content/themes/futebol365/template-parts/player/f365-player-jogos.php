<?php
/**
 * Player Games template.
 */

defined('ABSPATH') || exit;

global $wpdb;

if (!function_exists('f365_player_games_table_exists')) {
	function f365_player_games_table_exists(string $table): bool
	{
		global $wpdb;

		return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
	}
}

if (!function_exists('f365_player_games_django_id')) {
	function f365_player_games_django_id($post_id = null): int
	{
		$django_player_id = '';

		if (isset($_SERVER['REQUEST_URI']) && preg_match('#/jogador/([0-9]+)#i', sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])), $matches)) {
			$django_player_id = $matches[1];
		} elseif (get_query_var('f365_player')) {
			$django_player_id = get_query_var('f365_player');
		} elseif (get_query_var('name')) {
			$django_player_id = get_query_var('name');
		}

		if (is_numeric($django_player_id)) {
			return (int) $django_player_id;
		}

		$post_id = $post_id ?: get_the_ID();
		return $post_id ? (int) get_post_meta($post_id, 'django_player_id', true) : 0;
	}
}

if (!function_exists('f365_player_games_post_id')) {
	function f365_player_games_post_id(int $django_player_id, $fallback = null): int
	{
		global $wpdb;

		if (!$django_player_id) {
			return (int) ($fallback ?: get_the_ID());
		}

		$found_id = $wpdb->get_var(
			$wpdb->prepare(
				" SELECT ID 
				FROM {$wpdb->posts} 
				WHERE legacy_id = %d 
				AND post_type = 'f365_player' 
				LIMIT 1",
				$django_player_id
			)
		);

		return (int) ($found_id ?: ($fallback ?: get_the_ID()));
	}
}

if (!function_exists('f365_player_games_query_link')) {
	function f365_player_games_query_link(array $args = array()): string
	{
		$current = array();

		foreach (array('edition', 'competition', 'opponent', 'stat') as $key) {
			if (isset($_GET[$key]) && '' !== (string) $_GET[$key]) {
				$current[$key] = sanitize_text_field(wp_unslash($_GET[$key]));
			}
		}

		foreach ($args as $key => $value) {
			if (null === $value || '' === (string) $value) {
				unset($current[$key]);
			} else {
				$current[$key] = $value;
			}
		}

		return esc_url(add_query_arg($current, remove_query_arg(array_keys($_GET))));
	}
}

if (!function_exists('f365_player_games_team_name')) {
	function f365_player_games_team_name($team): string
	{
		$name = $team->shortname ?? '';
		$suffix = '';

		if ('futsal' === ($team->modality ?? '')) {
			$suffix .= ' FS';
		}
		if ('female' === ($team->gender ?? '')) {
			$suffix .= ' FM';
		}
		if ('b_team' === ($team->step ?? '')) {
			$suffix .= ' B';
		} elseif ('c_team' === ($team->step ?? '')) {
			$suffix .= ' C';
		} elseif ('veterans' === ($team->step ?? '')) {
			$suffix .= ' VET';
		} elseif ('reservations' === ($team->step ?? '')) {
			$suffix .= ' RES';
		}

		return trim($name . $suffix);
	}
}

if (!function_exists('f365_player_games_team_url')) {
	function f365_player_games_team_url($team, int $competition_id = 0): string
	{
		$url = home_url('/equipa/' . (int) $team->team_id . '/jogos/');

		return add_query_arg(
			array_filter(
				array(
					'edition' => $team->edition ?? '',
					'modality' => $team->modality ?? '',
					'gender' => $team->gender ?? '',
					'step' => $team->step ?? '',
					'competition' => $competition_id ?: '',
				),
				'strlen'
			),
			$url
		);
	}
}

if (!function_exists('f365_player_games_match_url')) {
	function f365_player_games_match_url(int $match_id): string
	{
		return home_url('/jogo/' . $match_id . '/');
	}
}

if (!function_exists('f365_player_games_competition_url')) {
	function f365_player_games_competition_url($competition): string
	{
		$args = array('edition' => $competition->competition_edition ?? $competition->edition ?? '');

		if (!empty($competition->competition_phase_name)) {
			$args['competition_phase'] = $competition->competition_phase_name;

			if ('' !== (string) ($competition->group_phase_round ?? '')) {
				$args['phase_round'] = $competition->group_phase_round;
			}
		} elseif ('' !== (string) ($competition->match_round ?? '')) {
			$args['competition_round'] = $competition->match_round;
		}

		if ('' !== (string) ($competition->group_home_team ?? '')) {
			$args['group'] = $competition->group_home_team;
		}

		return add_query_arg(array_filter($args, 'strlen'), home_url('/competicao/' . (int) $competition->federation_competition_id . '/'));
	}
}

if (!function_exists('f365_player_games_competition_flag')) {
	function f365_player_games_competition_flag($competition): string
	{
		$flag_id = $competition->country_id ? $competition->country_id : 'conf_' . $competition->federation_id;

		if (!$competition->country_id && !$competition->federation_id) {
			return '';
		}

		$label = $competition->federation_shortname ?: $competition->competition_name;

		return sprintf(
			'<i class="hide-tiny flag-icon quarter-right-space flag-icon-%s" title="%s"></i>',
			esc_attr($flag_id),
			esc_attr($label)
		);
	}
}

if (!function_exists('f365_player_games_result_text')) {
	function f365_player_games_result_text($match): string
	{
		if ('delayed' === $match->situation) {
			return 'ADI';
		}
		if ('suspended' === $match->situation) {
			return 'SUS';
		}
		if ('abrogated' === $match->situation) {
			return 'ANU';
		}
		if (null !== $match->penalty_home_score && '' !== (string) $match->penalty_home_score && null !== $match->penalty_visitor_score && '' !== (string) $match->penalty_visitor_score) {
			return (int) $match->penalty_home_score . ' - ' . (int) $match->penalty_visitor_score . ' g.p';
		}
		if (null !== $match->home_score_extra && '' !== (string) $match->home_score_extra && null !== $match->visitor_score_extra && '' !== (string) $match->visitor_score_extra) {
			return (int) $match->home_score_extra . ' - ' . (int) $match->visitor_score_extra . ' a.p';
		}
		if (null !== $match->home_score && '' !== (string) $match->home_score && null !== $match->visitor_score && '' !== (string) $match->visitor_score) {
			return (int) $match->home_score . ' - ' . (int) $match->visitor_score;
		}

		return 'vs';
	}
}

if (!function_exists('f365_player_games_result_class')) {
	function f365_player_games_result_class($match, int $teamstep_id): string
	{
		if ('finished' !== $match->situation) {
			return '';
		}

		$home_score = (null !== $match->home_score_extra && '' !== (string) $match->home_score_extra) ? (int) $match->home_score_extra : (int) $match->home_score;
		$away_score = (null !== $match->visitor_score_extra && '' !== (string) $match->visitor_score_extra) ? (int) $match->visitor_score_extra : (int) $match->visitor_score;

		if (null !== $match->penalty_home_score && '' !== (string) $match->penalty_home_score && null !== $match->penalty_visitor_score && '' !== (string) $match->penalty_visitor_score) {
			$home_score = (int) $match->penalty_home_score;
			$away_score = (int) $match->penalty_visitor_score;
		}

		if ($home_score === $away_score) {
			return 'draw';
		}

		$is_home_team = ((int) $match->home_team_id === $teamstep_id);
		$team_won = $is_home_team ? ($home_score > $away_score) : ($away_score > $home_score);

		return $team_won ? 'win' : 'lost';
	}
}

if (!function_exists('f365_player_games_minute')) {
	function f365_player_games_minute($minute): string
	{
		if (null === $minute || '' === (string) $minute) {
			return '';
		}

		return esc_html((string) $minute) . "'";
	}
}

if (!function_exists('f365_player_games_player_minutes')) {
	function f365_player_games_player_minutes($match): string
	{
		$minutes = f365_player_games_minute($match->minutes);

		if ((int) $match->minutes >= 90) {
			return '<span>' . $minutes . '</span>';
		}

		return '<span style="color:#FF0000;">' . $minutes . '</span>';
	}
}

$post_id = $args['post_id'] ?? get_the_ID();
$django_player_id = f365_player_games_django_id($post_id);
$post_id = f365_player_games_post_id($django_player_id, $post_id);
$current_section = $args['current_section'] ?? 'jogos';
$sections = $args['sections'] ?? (function_exists('futebol365_get_player_sections') ? futebol365_get_player_sections() : array());

if (!isset($sections[$current_section]) && isset($sections['jogos'])) {
	$current_section = 'jogos';
}

$tables = array(
	'matchplayer' => $wpdb->prefix . 'competition_matchplayer',
	'match' => $wpdb->prefix . 'competition_match',
	'competition' => $wpdb->prefix . 'competition_competition',
	'federation_competition' => $wpdb->prefix . 'federation_federationcompetition',
	'federation' => $wpdb->prefix . 'federation_federation',
	'teamstep' => $wpdb->prefix . 'team_teamstep',
	'team' => $wpdb->prefix . 'team_team',
	'squad' => $wpdb->prefix . 'team_squad',
	'phase' => $wpdb->prefix . 'competition_competitionphase',
);

$required_tables = array($tables['matchplayer'], $tables['match'], $tables['competition'], $tables['teamstep'], $tables['team'], $tables['squad']);
$missing_tables = array_filter(
	$required_tables,
	static function ($table) {
		return !f365_player_games_table_exists($table);
	}
);

$sel_edition = isset($_GET['edition']) ? sanitize_text_field(wp_unslash($_GET['edition'])) : '';
$current_competition = isset($_GET['competition']) ? (int) $_GET['competition'] : 0;
$current_opponent = isset($_GET['opponent']) ? (int) $_GET['opponent'] : 0;
$current_stat = isset($_GET['stat']) ? sanitize_text_field(wp_unslash($_GET['stat'])) : '';

$editions_list = array();
$competitions = array();
$opponents = array();
$summary = array();
$matches = array();
$grouped_matches = array();

if ($django_player_id && empty($missing_tables)) {
	$page_cache_key = 'player_games_page_' . $django_player_id . '_' . md5(implode('|', array(
		(string) ($sel_edition ?: 'auto'),
		(string) $current_competition,
		(string) $current_opponent,
		(string) $current_stat,
	)));
	$page_cache_data = wp_cache_get($page_cache_key, 'player_games');

	if (is_array($page_cache_data)) {
		$editions_list = $page_cache_data['editions_list'] ?? array();
		$sel_edition = (string) ($page_cache_data['sel_edition'] ?? $sel_edition);
		$competitions = $page_cache_data['competitions'] ?? array();
		$opponents = $page_cache_data['opponents'] ?? array();
		$summary = $page_cache_data['summary'] ?? array();
		$matches = $page_cache_data['matches'] ?? array();
		$grouped_matches = $page_cache_data['grouped_matches'] ?? array();
	} else {
			$cache_key_editions = 'player_games_editions_' . $django_player_id;
			$editions_list = wp_cache_get($cache_key_editions, 'player_games');

			if (false === $editions_list) {
				$editions_list = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT DISTINCT ts.edition
				   FROM {$tables['squad']} s
				   INNER JOIN {$tables['teamstep']} ts ON ts.id = s.team_id
				  WHERE s.player_id = %d
				  ORDER BY (ts.edition + 0) DESC",
						$django_player_id
					)
				);
				wp_cache_set($cache_key_editions, $editions_list, 'player_games', 12 * HOUR_IN_SECONDS);
			}

			if ('' !== $sel_edition) {
				$has_selected_edition = false;
				foreach ((array) $editions_list as $edition_option) {
					if ((string) ($edition_option->edition ?? '') === (string) $sel_edition) {
						$has_selected_edition = true;
						break;
					}
				}
				if (!$has_selected_edition) {
					$editions_list[] = (object) array('edition' => $sel_edition);
					usort(
						$editions_list,
						static function ($a, $b) {
							return (int) ($b->edition ?? 0) <=> (int) ($a->edition ?? 0);
						}
					);
				}
			} elseif (!empty($editions_list)) {
				$sel_edition = (string) $editions_list[0]->edition;
			}

			$edition_competition_ids = array();
			$edition_competitions_map = array();

			$cache_key_edition_competitions = 'player_games_edition_competitions_' . $sel_edition;
			$edition_competitions = wp_cache_get($cache_key_edition_competitions, 'player_games');

			if (false === $edition_competitions) {
				$edition_competitions = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT c.id, c.name, c.edition, c.federation_competition_id, c.country_id,
				        fc.federation_id, f.shortname AS federation_shortname
				   FROM {$tables['competition']} c
				   LEFT JOIN {$tables['federation_competition']} fc ON fc.id = c.federation_competition_id
				   LEFT JOIN {$tables['federation']} f ON f.id = fc.federation_id
				  WHERE c.edition = %s",
						$sel_edition
					)
				);
				wp_cache_set($cache_key_edition_competitions, $edition_competitions, 'player_games', 300);
			}

			foreach ((array) $edition_competitions as $competition) {
				$competition_id = (int) ($competition->id ?? 0);
				if ($competition_id) {
					$edition_competition_ids[] = $competition_id;
					$edition_competitions_map[$competition_id] = $competition;
				}
			}

			$where_clauses = array(
				$wpdb->prepare('mp.player_id = %d', $django_player_id),
			);

			if (!empty($edition_competition_ids)) {
				$competition_placeholders = implode(',', array_fill(0, count($edition_competition_ids), '%d'));
				$edition_competition_sql = $wpdb->prepare("m.competition_id IN ($competition_placeholders)", $edition_competition_ids);
				$where_clauses[] = $edition_competition_sql;
			} else {
				$edition_competition_sql = '1 = 0';
				$where_clauses[] = $edition_competition_sql;
			}

			if ($current_competition) {
				$where_clauses[] = $wpdb->prepare('m.competition_id = %d', $current_competition);
			}

			if ($current_opponent) {
				$where_clauses[] = $wpdb->prepare('(m.home_team_id = %d OR m.visitor_team_id = %d)', $current_opponent, $current_opponent);
			}

			switch ($current_stat) {
				case '1':
					$where_clauses[] = 'mp.is_titular = 1 AND mp.is_in = 0 AND mp.is_out = 0 AND mp.double_yellow_card = 0 AND mp.red_card = 0';
					break;
				case '2':
					$where_clauses[] = 'mp.is_titular = 1';
					break;
				case '3':
					$where_clauses[] = 'mp.is_out = 1';
					break;
				case '4':
					$where_clauses[] = 'mp.is_in = 1';
					break;
				case '5':
					$where_clauses[] = '(COALESCE(mp.goals, 0) + COALESCE(mp.goals_penalty, 0)) > 0';
					break;
				case '6':
					$where_clauses[] = 'COALESCE(mp.goals_penalty, 0) > 0';
					break;
				case '7':
					$where_clauses[] = 'COALESCE(mp.own_goals, 0) > 0';
					break;
				case '8':
					$where_clauses[] = 'mp.yellow_card = 1';
					break;
				case '9':
					$where_clauses[] = 'mp.double_yellow_card = 1';
					break;
				case '10':
					$where_clauses[] = 'mp.red_card = 1';
					break;
				case '11':
					$where_clauses[] = '(mp.yellow_card = 1 OR mp.double_yellow_card = 1 OR mp.red_card = 1)';
					break;
			}

			$where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

			$base_cache_key = 'player_games_base_' . $django_player_id . '_' . $sel_edition;
			$base_matches = wp_cache_get($base_cache_key, 'player_games');

			if (false === $base_matches) {
				$base_matches = $wpdb->get_results(
					"SELECT mp.id AS matchplayer_id, mp.team_id AS player_team_id, mp.minutes, mp.is_titular, mp.is_in, mp.is_out,
			        mp.is_in_minute, mp.is_out_minute, mp.goals, mp.goals_penalty, mp.own_goals,
			        mp.yellow_card, mp.double_yellow_card, mp.red_card,
			        mp.yellow_card_minute, mp.double_yellow_card_minute, mp.red_card_minute,
			        m.id AS match_id, m.date_time, m.duration, m.match_round, m.group_home_team, m.group_visitor_team, m.group_phase_round,
			        m.home_team_id, m.visitor_team_id, m.home_score, m.visitor_score, m.home_score_extra, m.visitor_score_extra,
			        m.penalty_home_score, m.penalty_visitor_score, m.situation, m.competition_phase_id,
			        m.competition_id AS comp_id,
			        (COALESCE(mp.goals, 0) + COALESCE(mp.goals_penalty, 0)) AS total_goals
			   FROM {$tables['matchplayer']} mp
			   INNER JOIN {$tables['match']} m ON mp.match_id = m.id
			  WHERE mp.player_id = " . (int) $django_player_id . "
			    AND {$edition_competition_sql}
			  ORDER BY m.competition_id ASC, m.date_time ASC, m.id ASC"
				);

				$team_ids = array();
				$phase_ids = array();
				foreach ((array) $base_matches as $match) {
					$team_ids[] = (int) $match->home_team_id;
					$team_ids[] = (int) $match->visitor_team_id;
					if (!empty($match->competition_phase_id)) {
						$phase_ids[] = (int) $match->competition_phase_id;
					}
				}
				$team_ids = array_unique(array_filter($team_ids));
				$phase_ids = array_unique(array_filter($phase_ids));

				$teams_map = array();
				if (!empty($team_ids)) {
					$team_placeholders = implode(',', array_fill(0, count($team_ids), '%d'));
					$teams_data = $wpdb->get_results($wpdb->prepare(
						"SELECT ts.id AS teamstep_id, ts.team_id AS real_team_id, ts.step, ts.modality, ts.gender, ts.edition,
				        t.shortname, t.team_type
				   FROM {$tables['teamstep']} ts
				   INNER JOIN {$tables['team']} t ON ts.team_id = t.id
				  WHERE ts.id IN ($team_placeholders)",
						$team_ids
					));
					foreach ($teams_data as $team) {
						$teams_map[(int) $team->teamstep_id] = $team;
					}
				}

				$phases_map = array();
				if (!empty($phase_ids)) {
					$phase_placeholders = implode(',', array_fill(0, count($phase_ids), '%d'));
					$phases_data = $wpdb->get_results($wpdb->prepare(
						"SELECT id, name FROM {$tables['phase']} WHERE id IN ($phase_placeholders)",
						$phase_ids
					));
					foreach ($phases_data as $phase) {
						$phases_map[(int) $phase->id] = $phase->name;
					}
				}

				foreach ((array) $base_matches as &$match) {
					$competition = $edition_competitions_map[(int) $match->comp_id] ?? null;
					if ($competition) {
						$match->competition_name = $competition->name ?? '';
						$match->competition_edition = $competition->edition;
						$match->federation_competition_id = $competition->federation_competition_id;
						$match->country_id = $competition->country_id;
						$match->federation_id = $competition->federation_id;
						$match->federation_shortname = $competition->federation_shortname;
					}
					$match->competition_phase_name = $phases_map[(int) $match->competition_phase_id] ?? '';

					$home_team = $teams_map[(int) $match->home_team_id] ?? null;
					if ($home_team) {
						$match->home_real_team_id = $home_team->real_team_id;
						$match->home_step = $home_team->step;
						$match->home_modality = $home_team->modality;
						$match->home_gender = $home_team->gender;
						$match->home_edition = $home_team->edition;
						$match->home_team_name = $home_team->shortname;
						$match->home_team_type = $home_team->team_type;
					}
					$visitor_team = $teams_map[(int) $match->visitor_team_id] ?? null;
					if ($visitor_team) {
						$match->visitor_real_team_id = $visitor_team->real_team_id;
						$match->visitor_step = $visitor_team->step;
						$match->visitor_modality = $visitor_team->modality;
						$match->visitor_gender = $visitor_team->gender;
						$match->visitor_edition = $visitor_team->edition;
						$match->visitor_team_name = $visitor_team->shortname;
						$match->visitor_team_type = $visitor_team->team_type;
					}
				}
				unset($match);

				wp_cache_set($base_cache_key, $base_matches, 'player_games', 12 * HOUR_IN_SECONDS);
			}

			$summary_map = array();
			$competition_choices_map = array();
			$opponent_map = array();
			$matches = array();

			foreach ((array) $base_matches as $base_match) {
				$comp_id = (int) $base_match->comp_id;

				if (!isset($competition_choices_map[$comp_id])) {
					$competition_choices_map[$comp_id] = (object) array(
						'id' => $comp_id,
						'name' => $base_match->competition_name ?? '',
						'first_date' => $base_match->date_time,
					);
				}

				if (!$current_competition || $comp_id === (int) $current_competition) {
					$opponent_id = ((int) $base_match->player_team_id === (int) $base_match->home_team_id) ? (int) $base_match->visitor_team_id : (int) $base_match->home_team_id;
					$opponent_name = ((int) $base_match->player_team_id === (int) $base_match->home_team_id) ? ($base_match->visitor_team_name ?? '') : ($base_match->home_team_name ?? '');
					if ($opponent_id) {
						$opponent_map[$opponent_id] = (object) array(
							'opponent_team_id' => $opponent_id,
							'opponent_name' => $opponent_name,
						);
					}
				}

				$include_match = true;
				if ($current_competition && $comp_id !== (int) $current_competition) {
					$include_match = false;
				}
				if ($include_match && $current_opponent && (int) $base_match->home_team_id !== (int) $current_opponent && (int) $base_match->visitor_team_id !== (int) $current_opponent) {
					$include_match = false;
				}
				if ($include_match) {
					switch ($current_stat) {
						case '1':
							$include_match = (1 === (int) $base_match->is_titular && 0 === (int) $base_match->is_in && 0 === (int) $base_match->is_out && 0 === (int) $base_match->double_yellow_card && 0 === (int) $base_match->red_card);
							break;
						case '2':
							$include_match = (1 === (int) $base_match->is_titular);
							break;
						case '3':
							$include_match = (1 === (int) $base_match->is_out);
							break;
						case '4':
							$include_match = (1 === (int) $base_match->is_in);
							break;
						case '5':
							$include_match = ((int) $base_match->total_goals > 0);
							break;
						case '6':
							$include_match = ((int) $base_match->goals_penalty > 0);
							break;
						case '7':
							$include_match = ((int) $base_match->own_goals > 0);
							break;
						case '8':
							$include_match = (1 === (int) $base_match->yellow_card);
							break;
						case '9':
							$include_match = (1 === (int) $base_match->double_yellow_card);
							break;
						case '10':
							$include_match = (1 === (int) $base_match->red_card);
							break;
						case '11':
							$include_match = (1 === (int) $base_match->yellow_card || 1 === (int) $base_match->double_yellow_card || 1 === (int) $base_match->red_card);
							break;
					}
				}

				if ($include_match) {
					$matches[] = $base_match;

					if (!isset($summary_map[$comp_id])) {
						$summary_map[$comp_id] = (object) array(
							'comp_id' => $comp_id,
							'J' => 0,
							'M' => 0,
							'T' => 0,
							'S' => 0,
							'U' => 0,
							'G' => 0,
							'A' => 0,
							'AA' => 0,
							'V' => 0,
							'first_match_date' => $base_match->date_time,
						);
					}
					$summary_row = $summary_map[$comp_id];
					$summary_row->J++;
					$summary_row->M += (int) $base_match->minutes;
					$summary_row->T += (1 === (int) $base_match->is_titular) ? 1 : 0;
					$summary_row->S += (1 === (int) $base_match->is_out) ? 1 : 0;
					$summary_row->U += (1 === (int) $base_match->is_in) ? 1 : 0;
					$summary_row->G += (int) $base_match->total_goals;
					$summary_row->A += (1 === (int) $base_match->yellow_card) ? 1 : 0;
					$summary_row->AA += (1 === (int) $base_match->double_yellow_card) ? 1 : 0;
					$summary_row->V += (1 === (int) $base_match->red_card) ? 1 : 0;
					if (strtotime((string) $base_match->date_time) < strtotime((string) $summary_row->first_match_date)) {
						$summary_row->first_match_date = $base_match->date_time;
					}

					if (!isset($summary_row->competition_name)) {
						$competition = $edition_competitions_map[$comp_id] ?? null;
						if ($competition) {
							$summary_row->competition_name = $competition->name ?? '';
							$summary_row->edition = $competition->edition;
							$summary_row->federation_competition_id = $competition->federation_competition_id;
							$summary_row->country_id = $competition->country_id;
							$summary_row->federation_id = $competition->federation_id;
							$summary_row->federation_shortname = $competition->federation_shortname;
						}
					}
				}
			}

			$summary = array_values($summary_map);
			usort($summary, static function ($a, $b) {
				return strtotime((string) $a->first_match_date) <=> strtotime((string) $b->first_match_date);
			});

			$competitions = array_values($competition_choices_map);
			usort($competitions, static function ($a, $b) {
				return strtotime((string) $a->first_date) <=> strtotime((string) $b->first_date);
			});

			$opponents = array_values($opponent_map);
			usort($opponents, static function ($a, $b) {
				return strcasecmp((string) $a->opponent_name, (string) $b->opponent_name);
			});

			$competition_order = array();

			foreach ($summary as $index => $row) {
				$competition_order[(int) $row->comp_id] = $index;
				$grouped_matches[(int) $row->comp_id] = array(
					'competition' => $row,
					'matches' => array(),
				);
			}

			foreach ($matches as $match) {
				$comp_id = (int) $match->comp_id;

				if (!isset($grouped_matches[$comp_id])) {
					$grouped_matches[$comp_id] = array(
						'competition' => $match,
						'matches' => array(),
					);
				}

				$grouped_matches[$comp_id]['matches'][] = $match;
			}

			uasort(
				$grouped_matches,
				static function ($a, $b) use ($competition_order) {
					$a_id = (int) $a['competition']->comp_id;
					$b_id = (int) $b['competition']->comp_id;

					return ($competition_order[$a_id] ?? PHP_INT_MAX) <=> ($competition_order[$b_id] ?? PHP_INT_MAX);
				}
			);

			$page_cache_data = array(
				'editions_list' => $editions_list,
				'sel_edition' => $sel_edition,
				'competitions' => $competitions,
				'opponents' => $opponents,
				'summary' => $summary,
				'matches' => $matches,
				'grouped_matches' => $grouped_matches,
			);
			wp_cache_set($page_cache_key, $page_cache_data, 'player_games', 12 * HOUR_IN_SECONDS);
	}
}

get_header();
?>

<main id="primary" class="site-main single-article-page">
	<?php
	get_template_part(
		'template-parts/player/f365-player-header',
		null,
		array(
			'post_id' => $post_id,
			'current_section' => $current_section,
			'sections' => $sections,
			'editions_list' => $editions_list,
			'sel_edition' => $sel_edition,
		)
	);
	?>

	<div class="ink-grid futebol365-container">
		<section class="left-column-block">
			<div class="breadcrumbs-f365">
				<div class="ink-navigation breadcrumbs">
					<?php if (function_exists('yoast_breadcrumb')) {
						yoast_breadcrumb();
					} ?>
				</div>
			</div>

			<div class="player-games-wordpress">
				<form class="ink-form ink-form-option" id="f365-games-filter" method="get">
					<div class="column-group">
						<div class="control-group all-33 tiny-100 small-100">
							<div class="control">
								<select name="competition" onchange="this.form.submit()">
									<option value=""><?php esc_html_e('Competição', 'futebol365'); ?></option>
									<?php foreach ($competitions as $comp): ?>
										<option value="<?php echo esc_attr($comp->id); ?>" <?php selected($current_competition, (int) $comp->id); ?>>
											<?php echo esc_html($comp->name); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>

						<div class="control-group all-33 tiny-100 small-100">
							<div class="control">
								<select name="opponent" onchange="this.form.submit()">
									<option value=""><?php esc_html_e('Adversário', 'futebol365'); ?></option>
									<?php foreach ($opponents as $opp): ?>
										<option value="<?php echo esc_attr($opp->opponent_team_id); ?>" <?php selected($current_opponent, (int) $opp->opponent_team_id); ?>>
											<?php echo esc_html($opp->opponent_name); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>

						<div class="control-group all-33 tiny-100 small-100">
							<div class="control">
								<select name="stat" onchange="this.form.submit()">
									<option value=""><?php esc_html_e('Opções', 'futebol365'); ?></option>
									<option value="1" <?php selected($current_stat, '1'); ?>>
										<?php esc_html_e('Jogos completos', 'futebol365'); ?>
									</option>
									<option value="2" <?php selected($current_stat, '2'); ?>>
										<?php esc_html_e('Titular', 'futebol365'); ?>
									</option>
									<option value="3" <?php selected($current_stat, '3'); ?>>
										<?php esc_html_e('Substituído', 'futebol365'); ?>
									</option>
									<option value="4" <?php selected($current_stat, '4'); ?>>
										<?php esc_html_e('Suplente utilizado', 'futebol365'); ?>
									</option>
									<option value="5" <?php selected($current_stat, '5'); ?>>
										<?php esc_html_e('Golos', 'futebol365'); ?>
									</option>
									<option value="6" <?php selected($current_stat, '6'); ?>>
										<?php esc_html_e('Golos de penálti', 'futebol365'); ?>
									</option>
									<option value="7" <?php selected($current_stat, '7'); ?>>
										<?php esc_html_e('Autogolos', 'futebol365'); ?>
									</option>
									<option value="8" <?php selected($current_stat, '8'); ?>>
										<?php esc_html_e('Cartões amarelos', 'futebol365'); ?>
									</option>
									<option value="9" <?php selected($current_stat, '9'); ?>>
										<?php esc_html_e('Acumulação amarelos', 'futebol365'); ?>
									</option>
									<option value="10" <?php selected($current_stat, '10'); ?>>
										<?php esc_html_e('Cartões vermelhos', 'futebol365'); ?>
									</option>
									<option value="11" <?php selected($current_stat, '11'); ?>>
										<?php esc_html_e('Cartões', 'futebol365'); ?>
									</option>
								</select>
							</div>
						</div>
					</div>

					<?php if ($sel_edition): ?>
						<input type="hidden" name="edition" value="<?php echo esc_attr($sel_edition); ?>">
					<?php endif; ?>
				</form>

				<?php if ($missing_tables): ?>
					<h3 class="condensed-400 headerTitle red"><?php esc_html_e('Resumo de Jogos', 'futebol365'); ?></h3>
					<h6 class="no-info"><?php echo esc_html('Missing tables: ' . implode(', ', $missing_tables)); ?>
					</h6>
				<?php elseif (!empty($summary)): ?>
					<h3 class="condensed-400 headerTitle red"><?php esc_html_e('Resumo de Jogos', 'futebol365'); ?></h3>
					<div class="f365-summary">
						<table class="ink-table ink-table-f365 alternating all-100">
							<thead>
								<tr>
									<th class="align-left all-40"><?php esc_html_e('Competição', 'futebol365'); ?></th>
									<th class="align-center all-5"><span class="ink-tooltip" data-tip-text="Jogos"
											data-tip-where="up" data-tip-color="black">J</span></th>
									<th class="align-center all-5"><span class="ink-tooltip" data-tip-text="Minutos"
											data-tip-where="up" data-tip-color="black">M</span></th>
									<th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip"
											data-tip-text="Jogos a titular" data-tip-where="up"
											data-tip-color="black">T</span></th>
									<th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip"
											data-tip-text="Substituído" data-tip-where="up" data-tip-color="black">S</span>
									</th>
									<th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip"
											data-tip-text="Suplente utilizado" data-tip-where="up"
											data-tip-color="black">U</span></th>
									<th class="align-center all-5"><span class="ink-tooltip" data-tip-text="Golos"
											data-tip-where="up" data-tip-color="black">G</span></th>
									<th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip"
											data-tip-text="Cartões amarelos" data-tip-where="up"
											data-tip-color="black">A</span></th>
									<th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip"
											data-tip-text="Acumulação amarelos" data-tip-where="up"
											data-tip-color="black">AA</span></th>
									<th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip"
											data-tip-text="Cartões vermelhos" data-tip-where="up"
											data-tip-color="black">V</span></th>
								</tr>
							</thead>
							<tbody>
								<?php
								$totals = array_fill_keys(array('J', 'M', 'T', 'S', 'U', 'G', 'A', 'AA', 'V'), 0);
								foreach ($summary as $row):
									foreach ($totals as $key => $value) {
										$totals[$key] += (int) $row->{$key};
									}
									?>
									<tr>
										<td class="align-left">
											<?php echo f365_player_games_competition_flag($row); ?>
											<a
												href="<?php echo esc_url(f365_player_games_competition_url($row)); ?>"><?php echo esc_html($row->competition_name); ?></a>
										</td>
										<td class="align-center"><a
												href="<?php echo f365_player_games_query_link(array('competition' => (int) $row->comp_id, 'stat' => null)); ?>"><?php echo (int) $row->J; ?></a>
										</td>
										<td class="align-center"><?php echo (int) $row->M; ?></td>
										<td class="align-center hide-tiny hide-small"><a
												href="<?php echo f365_player_games_query_link(array('competition' => (int) $row->comp_id, 'stat' => 2)); ?>"><?php echo (int) $row->T; ?></a>
										</td>
										<td class="align-center hide-tiny hide-small"><a
												href="<?php echo f365_player_games_query_link(array('competition' => (int) $row->comp_id, 'stat' => 3)); ?>"><?php echo (int) $row->S; ?></a>
										</td>
										<td class="align-center hide-tiny hide-small"><a
												href="<?php echo f365_player_games_query_link(array('competition' => (int) $row->comp_id, 'stat' => 4)); ?>"><?php echo (int) $row->U; ?></a>
										</td>
										<td class="align-center"><a
												href="<?php echo f365_player_games_query_link(array('competition' => (int) $row->comp_id, 'stat' => 5)); ?>"><?php echo (int) $row->G; ?></a>
										</td>
										<td class="align-center hide-tiny hide-small"><a
												href="<?php echo f365_player_games_query_link(array('competition' => (int) $row->comp_id, 'stat' => 8)); ?>"><?php echo (int) $row->A; ?></a>
										</td>
										<td class="align-center hide-tiny hide-small"><a
												href="<?php echo f365_player_games_query_link(array('competition' => (int) $row->comp_id, 'stat' => 9)); ?>"><?php echo (int) $row->AA; ?></a>
										</td>
										<td class="align-center hide-tiny hide-small"><a
												href="<?php echo f365_player_games_query_link(array('competition' => (int) $row->comp_id, 'stat' => 10)); ?>"><?php echo (int) $row->V; ?></a>
										</td>
									</tr>
								<?php endforeach; ?>
								<tr class="total-row">
									<td class="align-right"><b><?php esc_html_e('Total', 'futebol365'); ?></b></td>
									<td class="align-center"><a
											href="<?php echo f365_player_games_query_link(array('competition' => null, 'stat' => null)); ?>"><b><?php echo (int) $totals['J']; ?></b></a>
									</td>
									<td class="align-center"><b><?php echo (int) $totals['M']; ?></b></td>
									<td class="align-center hide-tiny hide-small"><a
											href="<?php echo f365_player_games_query_link(array('competition' => null, 'stat' => 2)); ?>"><b><?php echo (int) $totals['T']; ?></b></a>
									</td>
									<td class="align-center hide-tiny hide-small"><a
											href="<?php echo f365_player_games_query_link(array('competition' => null, 'stat' => 3)); ?>"><b><?php echo (int) $totals['S']; ?></b></a>
									</td>
									<td class="align-center hide-tiny hide-small"><a
											href="<?php echo f365_player_games_query_link(array('competition' => null, 'stat' => 4)); ?>"><b><?php echo (int) $totals['U']; ?></b></a>
									</td>
									<td class="align-center"><a
											href="<?php echo f365_player_games_query_link(array('competition' => null, 'stat' => 5)); ?>"><b><?php echo (int) $totals['G']; ?></b></a>
									</td>
									<td class="align-center hide-tiny hide-small"><a
											href="<?php echo f365_player_games_query_link(array('competition' => null, 'stat' => 8)); ?>"><b><?php echo (int) $totals['A']; ?></b></a>
									</td>
									<td class="align-center hide-tiny hide-small"><a
											href="<?php echo f365_player_games_query_link(array('competition' => null, 'stat' => 9)); ?>"><b><?php echo (int) $totals['AA']; ?></b></a>
									</td>
									<td class="align-center hide-tiny hide-small"><a
											href="<?php echo f365_player_games_query_link(array('competition' => null, 'stat' => 10)); ?>"><b><?php echo (int) $totals['V']; ?></b></a>
									</td>
								</tr>
							</tbody>
						</table>
					</div>

					<div id="div-ad-text" class="div-ad-text"></div>

					<?php foreach ($grouped_matches as $comp_data): ?>
						<?php if (empty($comp_data['matches'])) {
							continue;
						} ?>
						<?php $competition = $comp_data['competition']; ?>
						<h3 class="condensed-400 headerTitle red">
							<?php echo f365_player_games_competition_flag($competition); ?>
							<a
								href="<?php echo esc_url(f365_player_games_competition_url($competition)); ?>"><?php echo esc_html($competition->competition_name); ?></a>
						</h3>
						<table class="ink-table ink-table-f365 alternating all-100">
							<thead>
								<tr>
									<th class="align-left all-10 hide-tiny hide-small">
										<?php esc_html_e('Data', 'futebol365'); ?>
									</th>
									<th class="align-left all-20 hide-tiny hide-small">
										<?php esc_html_e('Equipa', 'futebol365'); ?>
									</th>
									<th class="align-left all-20 tiny-40 small-40">
										<?php esc_html_e('Adversário', 'futebol365'); ?>
									</th>
									<th class="align-center" title="<?php esc_attr_e('Resultado', 'futebol365'); ?>"></th>
									<th class="align-center all-5 tiny-10 small-10"><span class="ink-tooltip"
											data-tip-text="Minutos" data-tip-where="up" data-tip-color="black">M</span></th>
									<th class="align-center all-5 tiny-10 small-10"><span class="ink-tooltip"
											data-tip-text="Número de Golos" data-tip-where="up" data-tip-color="black">G</span>
									</th>
									<th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip"
											data-tip-text="Minuto Cartão Amarelo" data-tip-where="up"
											data-tip-color="black">A</span></th>
									<th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip"
											data-tip-text="Minuto Acumulação de Amarelos" data-tip-where="up"
											data-tip-color="black">AA</span></th>
									<th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip"
											data-tip-text="Minuto Cartão Vermelho" data-tip-where="up"
											data-tip-color="black">V</span></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($comp_data['matches'] as $match): ?>
									<?php
									$is_player_home = ((int) $match->player_team_id === (int) $match->home_team_id);

									$team = (object) array(
										'team_id' => $is_player_home ? $match->home_real_team_id : $match->visitor_real_team_id,
										'shortname' => $is_player_home ? $match->home_team_name : $match->visitor_team_name,
										'step' => $is_player_home ? $match->home_step : $match->visitor_step,
										'modality' => $is_player_home ? $match->home_modality : $match->visitor_modality,
										'gender' => $is_player_home ? $match->home_gender : $match->visitor_gender,
										'edition' => $is_player_home ? $match->home_edition : $match->visitor_edition,
									);

									$opponent = (object) array(
										'team_id' => !$is_player_home ? $match->home_real_team_id : $match->visitor_real_team_id,
										'shortname' => !$is_player_home ? $match->home_team_name : $match->visitor_team_name,
										'step' => !$is_player_home ? $match->home_step : $match->visitor_step,
										'modality' => !$is_player_home ? $match->home_modality : $match->visitor_modality,
										'gender' => !$is_player_home ? $match->home_gender : $match->visitor_gender,
										'edition' => !$is_player_home ? $match->home_edition : $match->visitor_edition,
									);

									$result_class = f365_player_games_result_class($match, $match->player_team_id);
									?>

									<tr>
										<!-- DATE -->
										<td class="align-left hide-tiny hide-small">
											<?php echo esc_html(date('d/m/Y', strtotime($match->date_time))); ?>
										</td>

										<!-- TEAM -->
										<td class="align-left hide-tiny hide-small">
											<a href="<?php echo esc_url(f365_player_games_team_url($team, $match->comp_id)); ?>">
												<?php echo esc_html(f365_player_games_team_name($team)); ?>
											</a>
										</td>

										<!-- OPPONENT -->
										<td class="align-left tiny-40 small-40">
											<a
												href="<?php echo esc_url(f365_player_games_team_url($opponent, $match->comp_id)); ?>">
												<?php echo esc_html(f365_player_games_team_name($opponent)); ?>
											</a>
										</td>

										<!-- RESULT -->
										<td class="align-center <?php echo esc_attr($result_class); ?>">
											<a href="<?php echo esc_url(f365_player_games_match_url($match->match_id)); ?>">
												<?php echo esc_html(f365_player_games_result_text($match)); ?>
											</a>
										</td>

										<!-- MINUTES -->
										<td class="align-center">
											<?php echo f365_player_games_player_minutes($match); ?>
										</td>

										<!-- GOALS -->
										<td class="align-center">
											<?php echo (int) ($match->goals + $match->goals_penalty); ?>
										</td>

										<!-- YELLOW -->
										<td class="align-center hide-tiny hide-small">
											<?php echo f365_player_games_minute($match->yellow_card_minute); ?>
										</td>

										<!-- DOUBLE YELLOW -->
										<td class="align-center hide-tiny hide-small">
											<?php echo f365_player_games_minute($match->double_yellow_card_minute); ?>
										</td>

										<!-- RED -->
										<td class="align-center hide-tiny hide-small">
											<?php echo f365_player_games_minute($match->red_card_minute); ?>
										</td>
									</tr>

								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endforeach; ?>
				<?php else: ?>
					<h3 class="condensed-400 headerTitle red"><?php esc_html_e('Resumo de Jogos', 'futebol365'); ?></h3>
					<h6 class="no-info">
						<?php esc_html_e('Sem informação disponível para a época selecionada', 'futebol365'); ?>
					</h6>
				<?php endif; ?>
			</div>

			<?php get_template_part('template-parts/template-front-page-posts'); ?>
		</section>

		<section class="right-column-block">
			<?php is_active_sidebar('post-sidebar') ? dynamic_sidebar('post-sidebar') : get_sidebar(); ?>
		</section>
	</div>
</main>

<?php
get_footer();
