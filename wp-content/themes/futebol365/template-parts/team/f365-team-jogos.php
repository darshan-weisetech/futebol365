<?php
/**
 * Team — Jogos   /equipa/{id}/jogos/
 * ?edition=&step=&gender=&modality=&match_option=&competition=&competition_phase=&opponent=
 *
 * Faithful port of Django team_matches.html + _filter_team_matches + match-table.html.
 * Reads Django mirror tables via raw $wpdb. Cached per team (transient).
 *
 * @package Futebol365
 */

defined('ABSPATH') || exit;

global $wpdb;

/* ============================================================
 * Match helpers — port of competition/models.py Match methods.
 * ============================================================ */

if (!function_exists('f365_jm_set')) {
	function f365_jm_set($v): bool
	{
		return null !== $v && '' !== $v;
	}
}
if (!function_exists('f365_jm_has_result')) {
	function f365_jm_has_result(object $m): bool
	{
		if (f365_jm_set($m->pen_home) && f365_jm_set($m->pen_visitor)) {
			return true;
		}
		if (f365_jm_set($m->home_extra) && f365_jm_set($m->visitor_extra)) {
			return true;
		}
		return f365_jm_set($m->home_score) && f365_jm_set($m->visitor_score);
	}
}
if (!function_exists('f365_jm_home_winner')) {
	function f365_jm_home_winner(object $m): bool
	{
		if (f365_jm_set($m->pen_home) && f365_jm_set($m->pen_visitor)) {
			return (int) $m->pen_home > (int) $m->pen_visitor;
		}
		if (f365_jm_set($m->home_extra) && f365_jm_set($m->visitor_extra)) {
			return (int) $m->home_extra > (int) $m->visitor_extra;
		}
		if (f365_jm_set($m->home_score) && f365_jm_set($m->visitor_score)) {
			return (int) $m->home_score > (int) $m->visitor_score;
		}
		return false;
	}
}
if (!function_exists('f365_jm_visitor_winner')) {
	function f365_jm_visitor_winner(object $m): bool
	{
		if (f365_jm_set($m->pen_home) && f365_jm_set($m->pen_visitor)) {
			return (int) $m->pen_home < (int) $m->pen_visitor;
		}
		if (f365_jm_set($m->home_extra) && f365_jm_set($m->visitor_extra)) {
			return (int) $m->home_extra < (int) $m->visitor_extra;
		}
		if (f365_jm_set($m->home_score) && f365_jm_set($m->visitor_score)) {
			return (int) $m->home_score < (int) $m->visitor_score;
		}
		return false;
	}
}
if (!function_exists('f365_jm_finished')) {
	function f365_jm_finished(object $m): bool
	{
		return 'finished' === (string) $m->situation && f365_jm_has_result($m);
	}
}
if (!function_exists('f365_jm_draw')) {
	function f365_jm_draw(object $m): bool
	{
		return f365_jm_finished($m) && !f365_jm_home_winner($m) && !f365_jm_visitor_winner($m);
	}
}
if (!function_exists('f365_jm_result')) {
	function f365_jm_result(object $m): string
	{
		switch ((string) $m->situation) {
			case 'delayed':
				return 'ADI';
			case 'suspended':
				return 'SUS';
			case 'abrogated':
				return 'ANU';
		}
		if (f365_jm_set($m->pen_home) && f365_jm_set($m->pen_visitor)) {
			return sprintf('%s - %s g.p', (int) $m->pen_home, (int) $m->pen_visitor);
		}
		if (f365_jm_set($m->home_extra) && f365_jm_set($m->visitor_extra)) {
			return sprintf('%s - %s a.p', (int) $m->home_extra, (int) $m->visitor_extra);
		}
		if (f365_jm_set($m->home_score) && f365_jm_set($m->visitor_score)) {
			return sprintf('%s - %s', (int) $m->home_score, (int) $m->visitor_score);
		}
		return 'vs';
	}
}
if (!function_exists('f365_jm_bg_color')) {
	function f365_jm_bg_color(object $m, int $our_teamstep): string
	{
		if (!f365_jm_finished($m)) {
			return '';
		}
		if (f365_jm_draw($m)) {
			return 'draw';
		}
		$is_home = ((int) $m->home_ts === $our_teamstep);
		$won = ($is_home && f365_jm_home_winner($m)) || (!$is_home && f365_jm_visitor_winner($m));
		return $won ? 'win' : 'lost';
	}
}
if (!function_exists('f365_jm_passes_option')) {
	function f365_jm_passes_option(object $m, int $our_teamstep, string $opt): bool
	{
		if ('' === $opt || 'matches' === $opt) {
			return true;
		}
		$is_home = ((int) $m->home_ts === $our_teamstep);
		$hw = f365_jm_home_winner($m);
		$vw = f365_jm_visitor_winner($m);
		$team_won = ($is_home && $hw) || (!$is_home && $vw);
		$team_lost = ($is_home && $vw) || (!$is_home && $hw);
		$draw = f365_jm_set($m->home_score) && f365_jm_set($m->visitor_score)
			&& (int) $m->home_score === (int) $m->visitor_score
			&& !f365_jm_set($m->pen_home) && !f365_jm_set($m->pen_visitor)
			&& (
				(!f365_jm_set($m->home_extra) && !f365_jm_set($m->visitor_extra))
				|| (f365_jm_set($m->home_extra) && f365_jm_set($m->visitor_extra) && (int) $m->home_extra === (int) $m->visitor_extra)
			);
		switch ($opt) {
			case 'victories':
				return $team_won;
			case 'loses':
				return $team_lost;
			case 'draws':
				return $draw;
			case 'without_lose':
				return $team_won || $draw;
			case 'without_win':
				return $team_lost || $draw;
			case 'scored_goals':
				return $is_home ? (f365_jm_set($m->home_score) && (int) $m->home_score > 0) : (f365_jm_set($m->visitor_score) && (int) $m->visitor_score > 0);
			case 'suffered_goals':
				return $is_home ? (f365_jm_set($m->visitor_score) && (int) $m->visitor_score > 0) : (f365_jm_set($m->home_score) && (int) $m->home_score > 0);
		}
		return true;
	}
}

/**
 * Load all of a team's matches (any teamstep) + the video/photo gates, cached.
 */
if (!function_exists('f365_jm_load_team')) {
    function f365_jm_load_team(int $django_team): array
    {
        global $wpdb;
        if (!$django_team) {
            return ['matches' => [], 'videos' => [], 'photos' => []];
        }

        // Cache group helps organize object cache items
        $cache_key   = 'team_jogos_team_' . $django_team;
        $cache_group = 'f365_teams';

        // 1. Attempt to fetch from object cache
        $cached = wp_cache_get($cache_key, $cache_group);
        if (false !== $cached) {
            return $cached;
        }

        $p = $wpdb->prefix;

        $match_cols = "m.id AS ID, m.competition_id AS competition, m.competition_phase_id AS phase_id, m.date_time AS date_time, m.situation AS situation, m.home_team_id AS home_ts, m.visitor_team_id AS visitor_ts, m.home_score AS home_score, m.visitor_score AS visitor_score, m.home_score_extra AS home_extra, m.visitor_score_extra AS visitor_extra, m.penalty_home_score AS pen_home, m.penalty_visitor_score AS pen_visitor";
        $home_cols = "ht.team_id AS home_team, ht.step AS home_step, ht.gender AS home_gender, ht.modality AS home_modality, htm.shortname AS home_name, htm.team_type AS home_type";
        $away_cols = "vt.team_id AS visitor_team, vt.step AS visitor_step, vt.gender AS visitor_gender, vt.modality AS visitor_modality, vtm.shortname AS visitor_name, vtm.team_type AS visitor_type";
        $comp_cols = "c.name AS comp_name, c.country_id AS comp_country, c.edition AS comp_edition, c.federation_competition_id AS fed_comp, fc.federation_id AS comp_federation";
        $phase_cols = "cp.name AS phase_name, cp.`order` AS phase_order";

        $cols = "$match_cols, $home_cols, $away_cols, $comp_cols, $phase_cols";

        $join = "INNER JOIN {$p}team_teamstep ht ON ht.id = m.home_team_id
         INNER JOIN {$p}team_teamstep vt ON vt.id = m.visitor_team_id
         LEFT  JOIN {$p}team_team htm ON htm.id = ht.team_id
         LEFT  JOIN {$p}team_team vtm ON vtm.id = vt.team_id
         LEFT  JOIN {$p}competition_competition c ON c.id = m.competition_id
         LEFT  JOIN {$p}federation_federationcompetition fc ON fc.id = c.federation_competition_id
         LEFT  JOIN {$p}competition_competitionphase cp ON cp.id = m.competition_phase_id";

        $teamstep_ids = $wpdb->get_col(
            $wpdb->prepare("SELECT id FROM {$p}team_teamstep WHERE team_id = %d", $django_team)
        );
        $teamstep_ids = array_values(array_filter(array_map('intval', (array) $teamstep_ids)));

        if (empty($teamstep_ids)) {
            $matches = array();
        } else {
            $in = implode(',', $teamstep_ids);
            $query = "SELECT {$cols} FROM {$p}competition_match m {$join} WHERE m.home_team_id IN ({$in}) OR m.visitor_team_id IN ({$in}) ORDER BY m.date_time ASC";
            $matches = $wpdb->get_results($query);
        }

        if (!empty($wpdb->last_error) && !defined('F365_JM_DB_ERROR')) {
            define('F365_JM_DB_ERROR', 'MATCHES_QUERY: ' . $wpdb->last_error);
        }

        $payload = ['matches' => (array) $matches];

        // 2. Save to object cache for 10 minutes (600 seconds)
        //wp_cache_set($cache_key, $payload, $cache_group, 10 * MINUTE_IN_SECONDS);
		wp_cache_set($cache_key, $payload, $cache_group, DAY_IN_SECONDS);

        return $payload;
    }
}

/* New Contextual Meta Caching Layer helper */
if (!function_exists('f365_jm_get_team_meta')) {
    function f365_jm_get_team_meta(int $django_team, string $step, string $gender, string $modality, string $edition_in): array {
        global $wpdb;

        // 1. Define a clean cache key and cache group
        $cache_key   = "team_meta_{$django_team}_{$step}_{$gender}_{$modality}_{$edition_in}";
        $cache_group = 'f365_team_jogo';

        // 2. Check WordPress Object Cache first
        $cached = wp_cache_get($cache_key, $cache_group);
        if (false !== $cached) {
            return $cached;
        }

        $prefix = $wpdb->prefix;

        // 1. Resolve Team Type
        $team_type = 'club';
        if ($django_team) {
            $tt = $wpdb->get_var($wpdb->prepare("SELECT team_type FROM {$prefix}team_team WHERE id = %d", $django_team));
            if ($tt) { $team_type = (string) $tt; }
        }

        // 2. Resolve Edition
        $edition = $edition_in;
        if ('' === $edition && $django_team) {
            $edition = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT edition FROM {$prefix}team_teamstep WHERE team_id = %d AND step = %s AND gender = %s AND modality = %s AND ( is_available = 1 OR is_available = 't' OR is_available = 'true' ) ORDER BY ( edition + 0 ) DESC LIMIT 1",
                $django_team, $step, $gender, $modality
            ));
            if ('' === $edition) {
                $edition = (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT edition FROM {$prefix}team_teamstep WHERE team_id = %d AND step = %s AND gender = %s AND modality = %s ORDER BY ( edition + 0 ) DESC LIMIT 1",
                    $django_team, $step, $gender, $modality
                ));
            }
        }

        // 3. Resolve Teamstep ID
        $teamstep_id = 0;
        if ($django_team && '' !== $edition) {
            $teamstep_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}team_teamstep WHERE team_id = %d AND edition = %s AND step = %s AND gender = %s AND modality = %s LIMIT 1",
                $django_team, $edition, $step, $gender, $modality
            ));
        }

        // 4. Resolve Participants
        $competition_options = [];
        $participant_competitions = [];
        $comp_meta = [];
        if ($teamstep_id) {
            $participant_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT p.competition_id AS competition_id, c.name AS comp_name, c.country_id AS comp_country, c.edition AS comp_edition, c.federation_competition_id AS fed_comp, fc.federation_id AS comp_federation FROM {$prefix}competition_participant p INNER JOIN {$prefix}competition_competition c ON c.id = p.competition_id LEFT JOIN {$prefix}federation_federationcompetition fc ON fc.id = c.federation_competition_id WHERE p.team_id = %d ORDER BY p.id ASC",
                $teamstep_id
            ));
            foreach ((array) $participant_rows as $row) {
                $cid = (int) $row->competition_id;
                if (!$cid) continue;
                $participant_competitions[] = $cid;
                $competition_options[$cid] = (string) $row->comp_name;

                $fed = (int) $row->fed_comp;
                $flag = f365_jm_set($row->comp_country) ? (string) $row->comp_country : 'conf_' . (int) $row->comp_federation;
                $url = function_exists('f365_competition_url') ? f365_competition_url($fed) : add_query_arg('edition', (string) $row->comp_edition, user_trailingslashit(home_url('competicao/' . $fed)));
                $comp_meta[$cid] = ['name' => (string) $row->comp_name, 'url' => $url, 'flag' => $flag, 'edition' => (int) $row->comp_edition];
            }
        }

        $result = [
            'team_type' => $team_type,
            'edition' => $edition,
            'teamstep_id' => $teamstep_id,
            'competition_options' => $competition_options,
            'participant_competitions' => $participant_competitions,
            'comp_meta' => $comp_meta
        ];

        // 3. Save to object cache for 15 minutes (900 seconds)
        //wp_cache_set($cache_key, $result, $cache_group, 15 * MINUTE_IN_SECONDS);

		wp_cache_set($cache_key, $result, $cache_group, DAY_IN_SECONDS);

        return $result;
    }
}

/* ============================================================
 * Resolve team + filters
 * ============================================================ */

get_header();

$post_id = $args['post_id'] ?? get_the_ID();
if (function_exists('f365_get_current_team_post')) {
	$t = f365_get_current_team_post();
	if ($t) { $post_id = $t->ID; }
}
$post_id = (int) $post_id;
$prefix = $wpdb->prefix;
// $django_team = (int) get_post_meta($post_id, 'django_id', true);
$django_team = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT legacy_id
         FROM {$wpdb->posts}
         WHERE ID = %d",
        $post_id
    )
);

$current_section = get_query_var('section', 'jogos') ?: 'jogos';
$sections = function_exists('futebol365_get_team_sections') ? futebol365_get_team_sections() : [];
if ($sections && !isset($sections[$current_section])) {
	$current_section = 'jogos';
}

$step = isset($_GET['step']) ? sanitize_text_field(wp_unslash($_GET['step'])) : 'principal';
$gender = isset($_GET['gender']) ? sanitize_text_field(wp_unslash($_GET['gender'])) : 'male';
$modality = isset($_GET['modality']) ? sanitize_text_field(wp_unslash($_GET['modality'])) : 'football';
$edition_raw = isset($_GET['edition']) && '' !== $_GET['edition'] ? sanitize_text_field(wp_unslash($_GET['edition'])) : '';
$match_option = isset($_GET['match_option']) ? sanitize_text_field(wp_unslash($_GET['match_option'])) : 'matches';
$competition_in = (isset($_GET['competition']) && is_numeric($_GET['competition'])) ? (int) $_GET['competition'] : 0;
$phase_in = isset($_GET['competition_phase']) ? sanitize_text_field(wp_unslash($_GET['competition_phase'])) : '';
$opponent_in = (isset($_GET['opponent']) && is_numeric($_GET['opponent'])) ? (int) $_GET['opponent'] : 0;

// Fetch fully optimized & aggregated meta dataset
$meta_dataset = f365_jm_get_team_meta($django_team, $step, $gender, $modality, $edition_raw);

$team_type = $meta_dataset['team_type'];
$is_national = ('national' === $team_type);
$edition = $meta_dataset['edition'];
$teamstep_id = $meta_dataset['teamstep_id'];
$competition_options = $meta_dataset['competition_options'];
$participant_competitions = $meta_dataset['participant_competitions'];
$comp_meta = $meta_dataset['comp_meta'];

/* ============================================================
 * Load matches (cached) and filter to the current teamstep side.
 * ============================================================ */

$loaded = f365_jm_load_team($django_team);
$all_matches = $loaded['matches'] ?? [];
$videos = [];
$photos = [];

$all_matches = $teamstep_id ? array_values(array_filter((array) $all_matches, static function ($m) use ($teamstep_id) {
	return (int) $m->home_ts === $teamstep_id || (int) $m->visitor_ts === $teamstep_id;
})) : [];

foreach ((array) $all_matches as $m) {
	$cid = (int) $m->competition;
	if (!$cid || isset($comp_meta[$cid])) continue;
	$fed = (int) $m->fed_comp;
	$flag = f365_jm_set($m->comp_country) ? (string) $m->comp_country : 'conf_' . (int) $m->comp_federation;
	$url = function_exists('f365_competition_url') ? f365_competition_url($fed) : add_query_arg('edition', (string) $m->comp_edition, user_trailingslashit(home_url('competicao/' . $fed)));
	$comp_meta[$cid] = ['name' => (string) $m->comp_name, 'url' => $url, 'flag' => $flag, 'edition' => (int) $m->comp_edition];
}

if ($competition_in && !isset($comp_meta[$competition_in])) {
	$row = $wpdb->get_row($wpdb->prepare(
		"SELECT c.id AS competition_id, c.name AS comp_name, c.country_id AS comp_country, c.edition AS comp_edition, c.federation_competition_id AS fed_comp, fc.federation_id AS comp_federation FROM {$prefix}competition_competition c LEFT JOIN {$prefix}federation_federationcompetition fc ON fc.id = c.federation_competition_id WHERE c.id = %d",
		$competition_in
	));
	if ($row) {
		$fed = (int) $row->fed_comp;
		$flag = f365_jm_set($row->comp_country) ? (string) $row->comp_country : 'conf_' . (int) $row->comp_federation;
		$url = function_exists('f365_competition_url') ? f365_competition_url($fed) : add_query_arg('edition', (string) $row->comp_edition, user_trailingslashit(home_url('competicao/' . $fed)));
		$comp_meta[$competition_in] = ['name' => (string) $row->comp_name, 'url' => $url, 'flag' => $flag, 'edition' => (int) $row->comp_edition];
	}
}

$participant_rank = array_flip($participant_competitions);
$comp_cmp = static function ($a, $b) use ($participant_rank) {
	$ra = $participant_rank[$a] ?? PHP_INT_MAX;
	$rb = $participant_rank[$b] ?? PHP_INT_MAX;
	return $ra <=> $rb ?: $a <=> $b;
};

$competition_option_order = array_keys($competition_options);
usort($competition_option_order, $comp_cmp);

$default_comp = ($is_national && !$competition_in && $participant_competitions) ? (int) $participant_competitions[0] : 0;
$effective_comp = $competition_in ?: $default_comp;

// Cached Phase Options Lookup
$phase_options = [];
if ($effective_comp) {
	$phase_cache_key = 'f365_phases_' . $effective_comp;
	$phase_options = wp_cache_get($phase_cache_key, 'f365_team');
	if (false === $phase_options) {
		$phase_options = [];
		$phase_rows = $wpdb->get_results($wpdb->prepare("SELECT id, name FROM {$prefix}competition_competitionphase WHERE competition_id = %d ORDER BY `order` ASC, id ASC", $effective_comp));
		foreach ((array) $phase_rows as $phase_row) {
			$phase_options[(int) $phase_row->id] = (string) $phase_row->name;
		}
		//wp_cache_set($phase_cache_key, $phase_options, 'f365_team', 30 * MINUTE_IN_SECONDS);
		wp_cache_set($phase_cache_key, $phase_options, 'f365_team', DAY_IN_SECONDS);
	}
}

/* ============================================================
 * Filter + aggregate (group by competition) + opponent options
 * ============================================================ */

$init = ['J' => 0, 'V' => 0, 'E' => 0, 'D' => 0, 'GM' => 0, 'GS' => 0, 'DG' => 0];
$data = [];
$comp_order = [];
$grand = $init;
$opponent_options = [];
$opponent_team_ids = [];
$active_competitions = $competition_in ? [$competition_in] : (($is_national && $default_comp) ? [$default_comp] : $participant_competitions);

foreach ($active_competitions as $active_cid) {
	$active_cid = (int) $active_cid;
	if (!$active_cid || isset($data[$active_cid])) continue;
	$data[$active_cid] = ['matches' => [], 'total' => $init];
	$comp_order[] = $active_cid;
}

foreach ((array) $all_matches as $m) {
	$cid = (int) $m->competition;
	if (!$cid || !in_array($cid, $active_competitions, true)) continue;
	if ('' !== $phase_in && 'all' !== $phase_in && (int) $m->phase_id !== (int) $phase_in) continue;

	$is_home_team = ((int) $m->home_ts === $teamstep_id);
	$opp_ts = $is_home_team ? (int) $m->visitor_ts : (int) $m->home_ts;
	$opp_team = $is_home_team ? (int) $m->visitor_team : (int) $m->home_team;
	$opp_name = $is_home_team ? (string) $m->visitor_name : (string) $m->home_name;
	if ($opp_ts) {
		$opponent_options[$opp_ts] = $opp_name;
		$opponent_team_ids[$opp_ts] = $opp_team;
	}

	if ($opponent_in) {
		$opponent_matches = ($opp_ts === $opponent_in);
		if (!$opponent_matches && !isset($opponent_options[$opponent_in])) {
			$opponent_matches = ($opp_team === $opponent_in);
		}
		if (!$opponent_matches) continue;
	}
	if (!f365_jm_passes_option($m, $teamstep_id, $match_option)) continue;

	$data[$cid]['matches'][] = $m;

	if (!f365_jm_finished($m)) continue;
	$hw = f365_jm_home_winner($m);
	$vw = f365_jm_visitor_winner($m);
	$gf = $is_home_team ? (int) $m->home_score : (int) $m->visitor_score;
	$ga = $is_home_team ? (int) $m->visitor_score : (int) $m->home_score;
	$team_won = ($is_home_team && $hw) || (!$is_home_team && $vw);
	$team_lost = ($is_home_team && $vw) || (!$is_home_team && $hw);

	++$data[$cid]['total']['J'];
	++$grand['J'];
	if ($team_won) {
		++$data[$cid]['total']['V'];
		++$grand['V'];
	} elseif ($team_lost) {
		++$data[$cid]['total']['D'];
		++$grand['D'];
	} else {
		++$data[$cid]['total']['E'];
		++$grand['E'];
	}
	$data[$cid]['total']['GM'] += $gf;
	$data[$cid]['total']['GS'] += $ga;
	$data[$cid]['total']['DG'] += ($gf - $ga);
	$grand['GM'] += $gf;
	$grand['GS'] += $ga;
	$grand['DG'] += ($gf - $ga);
}

asort($opponent_options);
usort($comp_order, $comp_cmp);

$displayed_ids = [];
if (!empty($data)) {
	foreach ($comp_order as $cid) {
		if (!empty($data[$cid]['matches'])) {
			foreach ($data[$cid]['matches'] as $m) {
				if (!empty($m->ID)) {
					$displayed_ids[] = (int) $m->ID;
				}
			}
		}
	}
}
$displayed_ids = array_unique($displayed_ids);

if (!empty($displayed_ids)) {
	$placeholders = implode(',', array_fill(0, count($displayed_ids), '%d'));

	$vrows = $wpdb->get_col(
		$wpdb->prepare("SELECT DISTINCT match_id FROM {$wpdb->prefix}multimedia_video WHERE match_id IN ($placeholders)", $displayed_ids)
	);
	foreach ((array) $vrows as $mid) {
		$videos[(int) $mid] = true;
	}

	$grows = $wpdb->get_results(
		$wpdb->prepare("SELECT match_id, MIN(id) AS gid FROM {$wpdb->prefix}multimedia_photogallery WHERE match_id IN ($placeholders) AND is_published IN (1, 't', 'true') GROUP BY match_id", $displayed_ids)
	);
	foreach ((array) $grows as $g) {
		$photos[(int) $g->match_id] = (int) $g->gid;
	}
}

$has_data = !empty($data);
$base_args = ['edition' => $edition, 'modality' => $modality, 'gender' => $gender, 'step' => $step];
$jogos_url = function_exists('f365_team_url') ? f365_team_url($post_id, 'jogos') : user_trailingslashit(get_permalink($post_id)) . 'jogos/';

$stat_link = static function (string $option, $competition_value) use ($jogos_url, $base_args, $opponent_in, $phase_in) {
	return add_query_arg(
		array_merge($base_args, [
			'match_option' => $option,
			'competition' => $competition_value,
			'opponent' => $opponent_in ?: '',
			'competition_phase' => $phase_in,
		]),
		$jogos_url
	);
};
?>

<main id="primary" class="site-main single-article-page">

	<?php
	if ($sections) {
		get_template_part('template-parts/team/f365-team-header', null, [
			'post_id' => $post_id,
			'current_section' => $current_section,
			'sections' => $sections,
			'team_id' => $django_team,
		]);
	}
	?>

	<div class="ink-grid futebol365-container">
		<section class="left-column-block all-75 medium-100 small-100 tiny-100">

			<div class="breadcrumbs-f365">
				<div class="ink-navigation breadcrumbs">
					<?php if (function_exists('yoast_breadcrumb')) { yoast_breadcrumb(); } ?>
				</div>
			</div>

			<?php if ($has_data || $competition_in || $opponent_options): ?>
				<form class="ink-form ink-form-option" method="get" id="team_matches_form_level_2">
					<?php
					foreach (['edition', 'modality', 'gender', 'step'] as $keep) {
						if (isset($_GET[$keep]) && '' !== $_GET[$keep]) {
							printf('<input type="hidden" name="%s" value="%s">', esc_attr($keep), esc_attr(sanitize_text_field(wp_unslash($_GET[$keep]))));
						}
					}
					?>
					<div class="column-group">
						<div class="control-group all-33 tiny-100 small-100">
							<div class="control">
								<select name="match_option" id="id_match_option" aria-label="Filtro">
									<option value="matches" <?php selected($match_option, 'matches'); ?>>Jogos</option>
									<option value="victories" <?php selected($match_option, 'victories'); ?>>Vitórias</option>
									<option value="draws" <?php selected($match_option, 'draws'); ?>>Empates</option>
									<option value="loses" <?php selected($match_option, 'loses'); ?>>Derrotas</option>
									<option value="without_lose" <?php selected($match_option, 'without_lose'); ?>>Jogos sem perder</option>
									<option value="without_win" <?php selected($match_option, 'without_win'); ?>>Jogos sem ganhar</option>
									<option value="scored_goals" <?php selected($match_option, 'scored_goals'); ?>>Golos marcados</option>
									<option value="suffered_goals" <?php selected($match_option, 'suffered_goals'); ?>>Golos sofridos</option>
								</select>
							</div>
						</div>

						<?php if (!$is_national && !empty($competition_options)): ?>
							<div class="control-group all-33 tiny-100 small-100">
								<div class="control">
									<select name="competition" id="id_competition" aria-label="Competição">
										<option value="all" <?php selected($competition_in, 0); ?>>Competições</option>
										<?php foreach ($competition_option_order as $cid): ?>
											<option value="<?php echo esc_attr($cid); ?>" <?php selected($competition_in, $cid); ?>>
												<?php echo esc_html($competition_options[$cid] ?? ''); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>
						<?php endif; ?>

						<?php if (!empty($phase_options)): ?>
							<div class="control-group all-33 tiny-100 small-100">
								<div class="control">
									<select name="competition_phase" id="id_competition_phase" aria-label="Fase">
										<option value="all" <?php selected(in_array($phase_in, ['', 'all'], true), true); ?>>Fases</option>
										<?php foreach ($phase_options as $phase_id => $phase_name): ?>
											<option value="<?php echo esc_attr($phase_id); ?>" <?php selected((string) $phase_in, (string) $phase_id); ?>><?php echo esc_html($phase_name); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>
						<?php endif; ?>

						<div class="control-group all-33 tiny-100 small-100">
							<div class="control">
								<select name="opponent" id="id_opponent" aria-label="Adversário">
									<option value="all" <?php selected($opponent_in, 0); ?>>Adversário</option>
									<?php if (!empty($opponent_options)): ?>
										<?php foreach ($opponent_options as $ts => $oname): ?>
											<?php
											$is_selected_opponent =
												((int) $opponent_in === (int) $ts) ||
												(!isset($opponent_options[$opponent_in]) &&
													isset($opponent_team_ids[$ts]) &&
													(int) $opponent_team_ids[$ts] === (int) $opponent_in);
											?>
											<option value="<?php echo esc_attr($ts); ?>" <?php selected($is_selected_opponent, true); ?>>
												<?php echo esc_html($oname); ?>
											</option>
										<?php endforeach; ?>
									<?php endif; ?>
								</select>
							</div>
						</div>
					</div>
				</form>
				<script>
					(function () {
						function f365Q() { var p = {}, qs = location.search.substring(1), re = /([^&=]+)=([^&]*)/g, m; while ((m = re.exec(qs))) { p[decodeURIComponent(m[1])] = decodeURIComponent(m[2]); } return p; }
						function f365Go(p) { var a = []; for (var k in p) { if (Object.prototype.hasOwnProperty.call(p, k) && p[k] !== '' && p[k] !== 'all') { a.push(encodeURIComponent(k) + '=' + encodeURIComponent(p[k])); } } var q = a.join('&'); window.location.href = location.pathname + (q ? '?' + q : ''); }
						['id_match_option', 'id_competition', 'id_competition_phase', 'id_opponent'].forEach(function (id) {
							var el = document.getElementById(id);
							if (!el) { return; }
							el.addEventListener('change', function () {
								var p = f365Q();
								var mo = document.getElementById('id_match_option'); if (mo) { p.match_option = mo.value; }
								var cp = document.getElementById('id_competition'); if (cp) { p.competition = cp.value; }
								var ph = document.getElementById('id_competition_phase'); if (ph) { p.competition_phase = ph.value; }
								var op = document.getElementById('id_opponent'); if (op) { p.opponent = op.value; }
								if (this.id === 'id_competition') { delete p.competition_phase; delete p.opponent; }
								if (this.id === 'id_competition_phase') { delete p.opponent; }
								f365Go(p);
							});
						});
					})();
				</script>
			<?php endif; ?>

			<h3 class="condensed-400 headerTitle blue">Resumo de Jogos</h3>

			<?php if (!$has_data): ?>

				<h5 class="no-info">Sem informação disponível</h5>

			<?php else: ?>

				<table class="ink-table alternating ink-table-f365 overflow-hide">
					<thead>
						<tr>
							<th class="align-center all-40"></th>
							<th class="align-center all-5"><span class="ink-tooltip" data-tip-text="Jogos" data-tip-where="up" data-tip-color="black">J</span></th>
							<th class="align-center all-5"><span class="ink-tooltip" data-tip-text="Vitórias" data-tip-where="up" data-tip-color="black">V</span></th>
							<th class="align-center all-5"><span class="ink-tooltip" data-tip-text="Empates" data-tip-where="up" data-tip-color="black">E</span></th>
							<th class="align-center all-5"><span class="ink-tooltip" data-tip-text="Derrotas" data-tip-where="up" data-tip-color="black">D</span></th>
							<th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip" data-tip-text="Golos Marcados" data-tip-where="up" data-tip-color="black">GM</span></th>
							<th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip" data-tip-text="Golos Sofridos" data-tip-where="up" data-tip-color="black">GS</span></th>
							<th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip" data-tip-text="Diferença de Golos" data-tip-where="up" data-tip-color="black">DG</span></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ($comp_order as $cid):
							$tt = $data[$cid]['total'];
							$cm = $comp_meta[$cid] ?? ['name' => '', 'url' => '', 'flag' => 0];
							?>
							<tr>
								<td class="align-left">
									<i class="hide-tiny flag-icon quarter-right-space flag-icon-<?php echo esc_attr($cm['flag']); ?>"></i>
									<a href="<?php echo esc_url($cm['url']); ?>"><?php echo esc_html($cm['name']); ?></a>
								</td>
								<td class="align-center" title="Jogos"><a href="<?php echo esc_url($stat_link('matches', $cid)); ?>"><?php echo (int) $tt['J']; ?></a></td>
								<td class="align-center" title="Vitórias"><a href="<?php echo esc_url($stat_link('victories', $cid)); ?>"><?php echo (int) $tt['V']; ?></a></td>
								<td class="align-center" title="Empates"><a href="<?php echo esc_url($stat_link('draws', $cid)); ?>"><?php echo (int) $tt['E']; ?></a></td>
								<td class="align-center" title="Derrotas"><a href="<?php echo esc_url($stat_link('loses', $cid)); ?>"><?php echo (int) $tt['D']; ?></a></td>
								<td class="align-center hide-tiny hide-small" title="Golos Marcados"><a href="<?php echo esc_url($stat_link('scored_goals', $cid)); ?>"><?php echo (int) $tt['GM']; ?></a></td>
								<td class="align-center hide-tiny hide-small" title="Golos Sofridos"><a href="<?php echo esc_url($stat_link('suffered_goals', $cid)); ?>"><?php echo (int) $tt['GS']; ?></a></td>
								<td class="align-center hide-tiny hide-small" title="Diferença de Golos"><?php echo (int) $tt['DG']; ?></td>
							</tr>
						<?php endforeach; ?>
						<tr>
							<td class="align-right"><b>Total</b></td>
							<td class="align-center"><a href="<?php echo esc_url($stat_link('matches', $competition_in ?: '')); ?>"><b><?php echo (int) $grand['J']; ?></b></a></td>
							<td class="align-center"><a href="<?php echo esc_url($stat_link('victories', $competition_in ?: '')); ?>"><b><?php echo (int) $grand['V']; ?></b></a></td>
							<td class="align-center"><a href="<?php echo esc_url($stat_link('draws', $competition_in ?: '')); ?>"><b><?php echo (int) $grand['E']; ?></b></a></td>
							<td class="align-center"><a href="<?php echo esc_url($stat_link('loses', $competition_in ?: '')); ?>"><b><?php echo (int) $grand['D']; ?></b></a></td>
							<td class="align-center hide-tiny hide-small"><a href="<?php echo esc_url($stat_link('scored_goals', $competition_in ?: '')); ?>"><b><?php echo (int) $grand['GM']; ?></b></a></td>
							<td class="align-center hide-tiny hide-small"><a href="<?php echo esc_url($stat_link('suffered_goals', $competition_in ?: '')); ?>"><b><?php echo (int) $grand['GS']; ?></b></a></td>
							<td class="align-center hide-tiny hide-small"><b><?php echo (int) $grand['DG']; ?></b></td>
						</tr>
					</tbody>
				</table>

				<?php
				foreach ($comp_order as $cid):
					if (empty($data[$cid]['matches'])) { continue; }
					$cm = $comp_meta[$cid] ?? ['name' => '', 'url' => '', 'flag' => 0];
					?>
					<h5 class="condensed-400 headerTitle blue">
						<i class="hide-tiny flag-icon quarter-right-space flag-icon-<?php echo esc_attr($cm['flag']); ?>"></i>
						<a href="<?php echo esc_url($cm['url']); ?>"><?php echo esc_html($cm['name']); ?></a>
					</h5>

					<table class="ink-table alternating ink-table-f365 all-100">
						<thead>
							<tr>
								<th class="all-10 small-15 tiny-15 align-left">Data</th>
								<th class="all-5 hide-tiny hide-small align-center">Hora</th>
								<th class="all-30 align-right hide-tiny hide-small">Visitado</th>
								<th class="all-45 align-left hide-medium hide-large hide-xlarge">Jogo</th>
								<th class="all-10 small-20 tiny-20"></th>
								<th class="all-30 align-left hide-tiny hide-small">Visitante</th>
								<th class="all-15 small-20 tiny-20"></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ($data[$cid]['matches'] as $m):
								$ts = $m->date_time ? strtotime((string) $m->date_time) : 0;
								$h_win = f365_jm_home_winner($m);
								$v_win = f365_jm_visitor_winner($m);
								$m_url = user_trailingslashit(home_url('jogo/' . (int) $m->ID));
								$color = f365_jm_bg_color($m, $teamstep_id);
								$result = f365_jm_result($m);
								$home_team = (int) $m->home_team;
								$away_team = (int) $m->visitor_team;
								$home_url = $home_team ? user_trailingslashit(home_url('equipa/' . $home_team)) : '';
								$away_url = $away_team ? user_trailingslashit(home_url('equipa/' . $away_team)) : '';
								if ('national' === (string) $m->home_type && $home_url) {
									$home_url = add_query_arg('competition', (int) $m->competition, $home_url);
								}
								if ('national' === (string) $m->visitor_type && $away_url) {
									$away_url = add_query_arg('competition', (int) $m->competition, $away_url);
								}
								$home_name = (string) $m->home_name;
								$away_name = (string) $m->visitor_name;
								$compare = ($home_team && $away_team) ? user_trailingslashit(home_url('equipa/' . $home_team . '/comparar-com/' . $away_team)) : '';
								$has_video = ((int) $m->ID && !empty($videos[(int) $m->ID]));
								$photo_id = (int) $m->ID && !empty($photos[(int) $m->ID]) ? (int) $photos[(int) $m->ID] : 0;
								?>
								<tr>
									<td class="align-left hide-tiny hide-small cell-nowrap"><a href="<?php echo esc_url($m_url); ?>"><?php echo $ts ? esc_html(gmdate('d-m-y', $ts)) : ''; ?></a></td>
									<td class="align-center hide-tiny hide-small"><?php echo $ts ? esc_html(gmdate('H:i', $ts)) : ''; ?></td>
									<td class="align-left hide-xlarge hide-large hide-medium"><?php echo $ts ? esc_html(gmdate('d-m', $ts)) : ''; ?><span class="break"><?php echo $ts ? esc_html(gmdate('H:i', $ts)) : ''; ?></span></td>
									<td class="align-right hide-tiny hide-small">
										<a href="<?php echo esc_url($home_url); ?>" <?php echo $h_win ? ' class="teamWinner"' : ''; ?>><?php echo esc_html($home_name); ?></a>
									</td>
									<td class="align-left hide-xlarge hide-large hide-medium">
										<a href="<?php echo esc_url($home_url); ?>" <?php echo $h_win ? ' class="teamWinner"' : ''; ?>><?php echo esc_html($home_name); ?></a>
										<span class="break"><a href="<?php echo esc_url($away_url); ?>" <?php echo $v_win ? ' class="teamWinner"' : ''; ?>><?php echo esc_html($away_name); ?></a></span>
									</td>
									<td class="align-center">
										<a href="<?php echo esc_url($m_url); ?>">
											<span class="ink-label ink-label-score <?php echo esc_attr($color); ?>"><?php echo esc_html($result); ?></span>
										</a>
									</td>
									<td class="align-left hide-tiny hide-small">
										<a href="<?php echo esc_url($away_url); ?>" <?php echo $v_win ? ' class="teamWinner"' : ''; ?>><?php echo esc_html($away_name); ?></a>
									</td>
									<td class="align-left">
										<?php if ($compare): ?>
											<a href="<?php echo esc_url($compare); ?>" aria-label="Comparar equipas">
												<span class="ink-tooltip ink-button-related green" data-tip-text="Comparar equipas" data-tip-where="up" data-tip-color="green">C</span>
											</a>
										<?php endif; ?>
										<?php if ($has_video): ?>
											<a href="<?php echo esc_url(add_query_arg('match_opt', 'matchvideos', $m_url)); ?>" aria-label="Vídeo">
												<span class="ink-tooltip ink-button-related red" data-tip-text="Vídeo" data-tip-where="up" data-tip-color="red">V</span>
											</a>
										<?php endif; ?>
										<?php if ($photo_id): ?>
											<a href="<?php echo esc_url(user_trailingslashit(home_url('multimedia/fotogalerias/' . $photo_id))); ?>" aria-label="Fotogaleria">
												<span class="ink-tooltip ink-button-related black" data-tip-text="Fotogaleria" data-tip-where="up" data-tip-color="black">F</span>
											</a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endforeach; ?>

			<?php endif; ?>

			<?php get_template_part('template-parts/template-front-page-posts'); ?>

		</section>

		<section class="right-column-block all-25 medium-100 small-100 tiny-100">
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
