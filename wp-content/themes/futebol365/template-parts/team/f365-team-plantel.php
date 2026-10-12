<?php
/**
 * Team Plantel — /equipa/{id}/plantel/   (?edition=&step=&gender=&modality=&squad_option=&competition=&position=)
 *
 * Port of Django team/team_squads.html (squad listing + "Equipa Técnica"),
 * reading the migrated RELATIONAL tables (NOT wp_postmeta).
 *
 * CACHED (transients, persist on any host):
 *   f365_plantel_base_{teamstep}          -> squad rows + competition names + coaches
 *   f365_plantel_stats_{teamstep}_{comp}  -> full per-player stat aggregate
 * Filtering (position / competition / squad_option) happens in PHP on the
 * cached rows, so changing a filter doesn't re-hit the database.
 *
 * @package Futebol365
 */

defined('ABSPATH') || exit;

global $wpdb;

if (!defined('F365_PLANTEL_TTL')) {
	define('F365_PLANTEL_TTL', HOUR_IN_SECONDS);
}

if (!function_exists('f365_pl_flag')) {
	function f365_pl_flag(int $country_id, string $title = ''): string
	{
		if (!$country_id) {
			return '';
		}
		return '<i class="hide-tiny flag-icon quarter-right-space flag-icon-' . (int) $country_id . '" title="' . esc_attr($title) . '"></i>';
	}
}
if (!function_exists('f365_pl_age')) {
	function f365_pl_age(string $birth): string
	{
		if ('' === $birth || '0000-00-00' === $birth) {
			return '';
		}
		try {
			return (string) (new DateTime($birth))->diff(new DateTime('now'))->y;
		} catch (Exception $e) {
			return '';
		}
	}
}
if (!function_exists('f365_pl_coach_type_label')) {
	function f365_pl_coach_type_label(string $t): string
	{
		$t   = strtolower(trim($t));
		$map = [
			'principal'  => 'Treinador Principal',
			'assistant'  => 'Treinador Adjunto',
			'adjunto'    => 'Treinador Adjunto',
			'goalkeeper' => 'Treinador de Guarda-redes',
			'observer'   => 'Observador',
			'physical'   => 'Preparador Físico',
		];
		return $map[$t] ?? ucwords(str_replace('_', ' ', $t));
	}
}

/**
 * Cached base payload for a teamstep: squad rows, competition names, coaches.
 * Depends only on the teamstep, so it is reused across position/competition/
 * squad_option filters (those are applied in PHP afterwards).
 */
if (!function_exists('f365_pl_load_base')) {
    function f365_pl_load_base(int $teamstep, bool $is_national): array
    {
        global $wpdb;
        if (!$teamstep) {
            return ['rows' => [], 'comp_names' => [], 'coaches' => []];
        }

        $cache_key   = 'f365_team_plantel' . $teamstep;
        $cache_group = 'futebol365_squad';
        $cached      = wp_cache_get($cache_key, $cache_group);

        if (false !== $cached) {
            return $cached;
        }

        $p = $wpdb->prefix;

        // Club shows only the primary squad (order = 0); national shows all rows.
        $order_clause = $is_national ? '' : 'AND sq.`order` = 0';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT sq.id AS squad_id, sq.player_id AS pid, sq.position AS position, sq.`order` AS ord,
                        pe.shortname AS name, pe.nationality_id AS nat_id, pe.birth_date AS birth_date,
                        pl.height AS height, pl.weight AS weight,
                        co.shortname AS nat_name,
                        sc.shirt_number AS shirt, sc.competition_id AS comp_id
                 FROM {$p}team_squad sq
                 INNER JOIN {$p}person_player pl ON pl.id = sq.player_id
                 INNER JOIN {$p}person_person pe ON pe.id = pl.person_id
                 LEFT  JOIN {$p}country_country co ON co.id = pe.nationality_id
                 LEFT  JOIN {$p}team_squadcompetition sc ON sc.squad_id = sq.id
                 WHERE sq.team_id = %d {$order_clause}
                 ORDER BY sq.`order` ASC, sc.competition_id ASC",
                $teamstep
            )
        ); // phpcs:ignore WordPress.DB

        // Competition names referenced by the squad rows (one query).
        $comp_ids = [];
        foreach ((array) $rows as $r) {
            $cid = (int) $r->comp_id;
            if ($cid) {
                $comp_ids[$cid] = true;
            }
        }
        $comp_names = [];
        if ($comp_ids) {
            $ids   = implode(',', array_map('absint', array_keys($comp_ids)));
            $names = $wpdb->get_results("SELECT id, name FROM {$p}competition_competition WHERE id IN ($ids)"); // phpcs:ignore WordPress.DB
            foreach ((array) $names as $n) {
                $comp_names[(int) $n->id] = $n->name;
            }
        }

        // Coaches (Equipa Técnica) for the teamstep.
        $coaches = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tc.coach_id AS cid, tc.coach_type AS coach_type,
                        pe.shortname AS name, pe.nationality_id AS nat_id, pe.birth_date AS birth_date,
                        co.shortname AS nat_name
                 FROM {$p}team_teamstepcoach tc
                 INNER JOIN {$p}person_coach ch ON ch.id = tc.coach_id
                 INNER JOIN {$p}person_person pe ON pe.id = ch.person_id
                 LEFT  JOIN {$p}country_country co ON co.id = pe.nationality_id
                 WHERE tc.team_id = %d
                 ORDER BY tc.`order` ASC",
                $teamstep
            )
        ); // phpcs:ignore WordPress.DB

        /*
         * Competition dropdown options — Django SquadFilterForm builds these from
         * competition_participant for the CURRENT teamstep (participant.team = the
         * teamstep), NOT from squad/shirt rows. Order by participant id (insertion).
         */
        $participant_comps = [];
        $prows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id AS id, c.name AS name
             FROM {$p}competition_participant pa
             INNER JOIN {$p}competition_competition c ON c.id = pa.competition_id
             WHERE pa.team_id = %d
             ORDER BY pa.id ASC",
            $teamstep
        )); // phpcs:ignore WordPress.DB
        foreach ((array) $prows as $pr) {
            $participant_comps[(int) $pr->id] = (string) $pr->name;
        }

        $payload = [
            'rows'              => (array) $rows,
            'comp_names'        => $comp_names,
            'coaches'           => (array) $coaches,
            'participant_comps' => $participant_comps
        ];

        wp_cache_set($cache_key, $payload, $cache_group, DAY_IN_SECONDS);
        return $payload;
    }
}

/**
 * Squad-driven per-player stat aggregate — matches Django _get_squads_for_option
 * + _get_data_for_squad_option_table.
 *
 * Django iterates Squad rows for (base team, edition, step) — NOT a single
 * teamstep and NOT order=0 — then sums each squad player's MatchPlayer rows.
 * Players not registered in Squad are excluded. team_games = finished matches
 * (with matchplayers) of the resolved teamstep.
 */
if (!function_exists('f365_pl_load_stats')) {
    function f365_pl_load_stats(int $base_team, string $edition, string $step, int $teamstep, int $competition): array
    {
        global $wpdb;
        if (!$base_team || '' === $edition) {
            return ['agg' => [], 'meta' => [], 'team_games' => 0];
        }

        $cache_key   = 'f365_plantel_stats_' . $base_team . '_' . $step . '_' . $edition . '_c' . $competition;
        $cache_group = 'futebol365_stats';
        $cached      = wp_cache_get($cache_key, $cache_group);

        if (false !== $cached) {
            return $cached;
        }

        $p = $wpdb->prefix;

        // 1) Squad players for (base team, edition, step). Dedupe by player.
        $squad_players = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT sq.player_id AS pid, sq.position AS position
             FROM {$p}team_squad sq
             INNER JOIN {$p}team_teamstep ts ON ts.id = sq.team_id
             WHERE ts.team_id = %d AND sq.edition = %s AND ts.step = %s",
            $base_team, $edition, $step
        )); // phpcs:ignore WordPress.DB

        if (empty($squad_players)) {
            $payload = ['agg' => [], 'meta' => [], 'team_games' => 0];
            wp_cache_set($cache_key, $payload, $cache_group, DAY_IN_SECONDS);
            return $payload;
        }

        $pid_list   = [];
        $pos_by_pid = [];
        foreach ((array) $squad_players as $sp) {
            $pid            = (int) $sp->pid;
            $pid_list[$pid] = true;
            if (!isset($pos_by_pid[$pid])) {
                $pos_by_pid[$pid] = (string) $sp->position;
            }
        }
        $pids_in = implode(',', array_map('absint', array_keys($pid_list)));

        // 2) Aggregate MatchPlayer rows for those players.
        $select = "SELECT mp.player_id AS pid,
                    COUNT(*) AS total_of_games,
                    SUM(mp.is_titular) AS titular_games,
                    SUM(CASE WHEN mp.is_titular = 1 AND mp.is_out = 0 AND mp.double_yellow_card = 0 AND mp.red_card = 0 THEN 1 ELSE 0 END) AS complete_games,
                    SUM(mp.is_in)  AS substitution_in,
                    SUM(mp.is_out) AS substitution_out,
                    SUM(COALESCE(mp.goals,0) + COALESCE(mp.goals_penalty,0)) AS goals,
                    SUM(COALESCE(mp.goals_penalty,0)) AS penalty_goals,
                    SUM(COALESCE(mp.own_goals,0))     AS own_goal,
                    SUM(mp.yellow_card)        AS yellow_cards,
                    SUM(mp.double_yellow_card) AS double_yellow_cards,
                    SUM(mp.red_card)           AS red_cards
                FROM {$p}competition_matchplayer mp ";

        if ($competition) {
            $sql = $select
                . $wpdb->prepare("INNER JOIN {$p}competition_match cm ON cm.id = mp.match_id AND cm.competition_id = %d ", $competition) // phpcs:ignore WordPress.DB
                . "WHERE mp.player_id IN ($pids_in) GROUP BY mp.player_id";
        } else {
            $sql = $select
                . $wpdb->prepare("WHERE mp.team_id = %d AND mp.player_id IN ($pids_in) GROUP BY mp.player_id", $teamstep); // phpcs:ignore WordPress.DB
        }
        $agg = $wpdb->get_results($sql); // phpcs:ignore WordPress.DB

        // 3) team_games: finished matches (with >=1 matchplayer) for the teamstep.
        if ($competition) {
            $team_games = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM (
                    SELECT m.id FROM {$p}competition_match m
                    INNER JOIN {$p}competition_matchplayer mp2 ON mp2.match_id = m.id
                    WHERE m.situation = 'finished' AND m.competition_id = %d
                      AND (m.home_team_id = %d OR m.visitor_team_id = %d)
                    GROUP BY m.id
                ) t",
                $competition, $teamstep, $teamstep
            )); // phpcs:ignore WordPress.DB
        } else {
            $team_games = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM (
                    SELECT m.id FROM {$p}competition_match m
                    INNER JOIN {$p}competition_matchplayer mp2 ON mp2.match_id = m.id
                    WHERE m.situation = 'finished'
                      AND (m.home_team_id = %d OR m.visitor_team_id = %d)
                    GROUP BY m.id
                ) t",
                $teamstep, $teamstep
            )); // phpcs:ignore WordPress.DB
        }

        // 4) Name + nationality; position from the squad row.
        $meta_map = [];
        $meta     = $wpdb->get_results(
            "SELECT pl.id AS pid, pe.shortname AS name,
                    pe.nationality_id AS nat_id, co.shortname AS nat_name
             FROM {$p}person_player pl
             INNER JOIN {$p}person_person pe ON pe.id = pl.person_id
             LEFT  JOIN {$p}country_country co ON co.id = pe.nationality_id
             WHERE pl.id IN ($pids_in)"
        ); // phpcs:ignore WordPress.DB
        foreach ((array) $meta as $mrow) {
            $pid            = (int) $mrow->pid;
            $mrow->position = $pos_by_pid[$pid] ?? '';
            $meta_map[$pid] = $mrow;
        }

        $payload = ['agg' => (array) $agg, 'meta' => $meta_map, 'team_games' => $team_games];
        wp_cache_set($cache_key, $payload, $cache_group, DAY_IN_SECONDS);
        return $payload;
    }
}
/* ============================================================
 * Resolve team + filters
 * ============================================================ */

get_header();

$post_id = $args['post_id'] ?? get_the_ID();
if (function_exists('f365_get_current_team_post')) {
	$t = f365_get_current_team_post();
	if ($t) {
		$post_id = $t->ID;
	}
}
$post_id = (int) $post_id;

//$django_team = (int) get_post_meta($post_id, 'django_id', true);
$django_team = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT legacy_id
         FROM {$wpdb->posts}
         WHERE ID = %d",
        $post_id
    )
);
$prefix      = $wpdb->prefix;

$current_section = get_query_var('section', 'plantel') ?: 'plantel';
$sections        = function_exists('futebol365_get_team_sections') ? futebol365_get_team_sections() : [];
if ($sections && !isset($sections[$current_section])) {
	$current_section = 'plantel';
}

$team_type = 'club';
if ($django_team) {
	$tt = $wpdb->get_var($wpdb->prepare("SELECT team_type FROM {$prefix}team_team WHERE id = %d", $django_team)); // phpcs:ignore WordPress.DB
	if ($tt) {
		$team_type = (string) $tt;
	}
}
$is_national = ('national' === $team_type);

$g = static function (string $k, string $d): string {
	return isset($_GET[$k]) && '' !== $_GET[$k] ? sanitize_text_field(wp_unslash($_GET[$k])) : $d; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
};

$step         = $g('step', 'principal');
$gender       = $g('gender', 'male');
$modality     = $g('modality', 'football');
$position     = $g('position', 'all');
$squad_option = $g('squad_option', 'all');
$competition  = (isset($_GET['competition']) && !in_array($_GET['competition'], ['', 'all'], true)) ? (int) $_GET['competition'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$edition  = $g('edition', '');
$teamstep = 0;

if ($django_team && '' === $edition) {
	// No edition in the URL: resolve the newest teamstep (id + edition) in ONE
	// query instead of two (find-newest-edition, then resolve-teamstep).
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, edition FROM {$prefix}team_teamstep
			 WHERE team_id = %d AND step = %s AND gender = %s AND modality = %s
			 ORDER BY ( edition + 0 ) DESC LIMIT 1",
			$django_team, $step, $gender, $modality
		)
	); // phpcs:ignore WordPress.DB
	if ($row) {
		$teamstep = (int) $row->id;
		$edition  = (string) $row->edition;
	}
} elseif ($django_team && '' !== $edition) {
	// Explicit edition: resolve that specific teamstep.
	$teamstep = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$prefix}team_teamstep
			 WHERE team_id = %d AND edition = %s AND step = %s AND gender = %s AND modality = %s
			 LIMIT 1",
			$django_team, $edition, $step, $gender, $modality
		)
	); // phpcs:ignore WordPress.DB
}

if ('' === $edition) {
	$edition = gmdate('Y');
}

/* ============================================================
 * Load cached base data, then filter in PHP
 * ============================================================ */

$base       = f365_pl_load_base($teamstep, $is_national);
$all_rows   = $base['rows'];
$comp_names = $base['comp_names'];
$coaches    = $base['coaches'];

// Competition dropdown options come from competition_participant (Django parity),
// kept in participant order. Empty for national teams (Django deletes the field).
$comp_options = (!$is_national && !empty($base['participant_comps'])) ? $base['participant_comps'] : [];

// Build squad rows (deduped by player) applying the competition filter in PHP.
$squad_rows = [];
foreach ((array) $all_rows as $r) {
	$cid = (int) $r->comp_id;
	if ($competition && $cid !== $competition) {
		continue;
	}
	$pid = (int) $r->pid;
	if (!isset($squad_rows[$pid])) {
		$squad_rows[$pid] = $r;
	} elseif ('' === (string) $squad_rows[$pid]->shirt && '' !== (string) $r->shirt) {
		$squad_rows[$pid]->shirt = $r->shirt;
	}
}

$filtered = array_values($squad_rows);

$stat_labels = [
	'total_of_games'      => 'Total de jogos',
	'complete_games'      => 'Jogos completos',
	'titular_games'       => 'Jogos a titular',
	'substitution_in'     => 'Suplente utilizado',
	'substitution_out'    => 'Substituído',
	'goals'               => 'Golos',
	'penalty_goals'       => 'Golos de penalty',
	'own_goal'            => 'Golos na própria baliza',
	'yellow_cards'        => 'Cartões amarelos',
	'double_yellow_cards' => 'Acumulação amarelos',
	'red_cards'           => 'Cartões vermelhos',
	'total_cards'         => 'Total de cartões',
];

/*
 * Base URL = the team root + 'plantel/'. We must NOT use get_permalink() here,
 * because on a sub-tab the permalink can already contain a section segment
 * (e.g. .../equipa/685/jogos/), which would produce .../jogos/plantel/.
 * Resolve the team root explicitly, strip any trailing section, add 'plantel/'.
 */
$team_root = '';
if (function_exists('f365_team_url')) {
	$team_root = user_trailingslashit(f365_team_url($post_id)); // team root (no section)
} else {
	// Derive the team root from the request path: keep up to /equipa/{id}/.
	$req = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore
	if (preg_match('#^(.*?/equipa/\d+/)#', $req, $mm)) {
		$team_root = home_url($mm[1]);
	} else {
		// Last resort: the post permalink with any trailing section removed.
		$team_root = user_trailingslashit(get_permalink($post_id));
		$team_root = preg_replace('#(/equipa/\d+/)[^/]+/?$#', '$1', $team_root);
	}
}
$base_url = user_trailingslashit($team_root) . trailingslashit('plantel');

/*
 * Only include a param when it differs from its DEFAULT (matches live, which
 * keeps URLs clean: e.g. ?squad_option=total_of_games&position=all). Defaults:
 *   step=principal, gender=male, modality=football, position=all,
 *   squad_option=all, competition=(none), edition=(team's newest).
 * edition is omitted unless explicitly present in the current request.
 */
$edition_in_url = isset($_GET['edition']) && '' !== $_GET['edition']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$opt_url = static function (array $over) use ($base_url, $edition, $step, $gender, $modality, $position, $squad_option, $competition, $edition_in_url) {
	$current = [
		'edition'      => $edition_in_url ? $edition : '',
		'step'         => $step,
		'gender'       => $gender,
		'modality'     => $modality,
		'position'     => $position,
		'squad_option' => $squad_option,
		'competition'  => $competition ?: '',
	];
	$q = array_merge($current, $over);

	// Drop params equal to their default so the URL stays clean like live.
	$defaults = [
		'step'         => 'principal',
		'gender'       => 'male',
		'modality'     => 'football',
		'position'     => 'all',
		'squad_option' => 'all',
		'competition'  => '',
		'edition'      => '',
	];
	foreach ($defaults as $k => $dv) {
		if (isset($q[$k]) && (string) $q[$k] === (string) $dv) {
			unset($q[$k]);
		}
	}
	$q = array_filter($q, static fn($v) => '' !== $v && null !== $v);
	return add_query_arg($q, $base_url);
};
?>

<main id="primary" class="site-main single-article-page">

	<?php
	if ($sections) {
		get_template_part(
			'template-parts/team/f365-team-header',
			null,
			[
				'post_id'         => $post_id,
				'current_section' => $current_section,
				'sections'        => $sections,
				'team_id'         => $django_team,
			]
		);
	}
	?>

	<div class="ink-grid futebol365-container">
		<section class="left-column-block">

			<div class="breadcrumbs-f365">
				<div class="ink-navigation breadcrumbs">
					<?php if (function_exists('yoast_breadcrumb')) { yoast_breadcrumb(); } ?>
				</div>
			</div>

			<form class="ink-form ink-form-option" method="get" id="squad-filter">
				<div class="column-group">
					<div class="control-group all-33 tiny-100 small-100">
						<div class="control">
							<select onchange="if(this.value){window.location.href=this.value;}" name="squad_option" id="squad_option">
								<option value="<?php echo esc_url($opt_url(['squad_option' => 'all'])); ?>" <?php selected($squad_option, 'all'); ?>>Opções</option>
								<?php foreach ($stat_labels as $val => $label) : ?>
									<option value="<?php echo esc_url($opt_url(['squad_option' => $val])); ?>" <?php selected($squad_option, $val); ?>><?php echo esc_html($label); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
					<?php if (!$is_national && $comp_options && 'all' !== $squad_option) : ?>
						<div class="control-group all-33 tiny-100 small-100">
							<div class="control">
								<select onchange="if(this.value){window.location.href=this.value;}">
									<option value="<?php echo esc_url($opt_url(['competition' => ''])); ?>">Competições</option>
									<?php foreach ($comp_options as $cid => $cname) : ?>
										<option value="<?php echo esc_url($opt_url(['competition' => $cid])); ?>" <?php selected($competition, $cid); ?>><?php echo esc_html($cname); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
					<?php endif; ?>
					<div class="control-group all-33 tiny-100 small-100">
						<div class="control">
							<select onchange="if(this.value){window.location.href=this.value;}">
								<?php
								$positions = [
									'all'        => 'Posição',
									'goalkeeper' => 'Guarda-redes',
									'defender'   => 'Defesa',
									'midfielder' => 'Médio',
									'forward'    => 'Avançado',
								];
								foreach ($positions as $val => $label) :
									?>
									<option value="<?php echo esc_url($opt_url(['position' => $val])); ?>" <?php selected($position, $val); ?>><?php echo esc_html($label); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
				</div>
			</form>

			<?php
			$purl = static fn(int $id) => function_exists('f365_player_url') ? f365_player_url($id) : user_trailingslashit(home_url('jogador/' . $id));

			/* ---- TAB 1: PLANTEL GERAL ---- */
			if ('all' === $squad_option) :
				$pos_map = ['goalkeeper' => 'gk', 'defender' => 'df', 'midfielder' => 'mf', 'forward' => 'fw'];
				$squads  = ['gk' => [], 'df' => [], 'mf' => [], 'fw' => []];
				foreach ($filtered as $r) {
					if ('all' !== $position && $r->position !== $position) {
						continue;
					}
					$bucket = $pos_map[$r->position] ?? null;
					if ($bucket) {
						$squads[$bucket][] = $r;
					}
				}
				$by_shirt = static fn($a, $b) => ((int) $a->shirt ?: 999) <=> ((int) $b->shirt ?: 999);
				foreach ($squads as &$list) {
					usort($list, $by_shirt);
				}
				unset($list);

				$render_position = static function (array $list, string $label) use ($is_national, $purl) {
					if (!$list) {
						return;
					}
					?>
					<table class="ink-table alternating ink-table-f365 half-margin-bottom">
						<thead>
							<tr>
								<th class="all-5 align-left"></th>
								<th class="all-35 align-left"><?php echo esc_html($label); ?></th>
								<th class="all-30 align-left"><?php echo $is_national ? 'Clube' : 'Nacionalidade'; ?></th>
								<th class="all-10 align-left hide-tiny hide-small">Idade</th>
								<th class="all-10 align-left hide-tiny hide-small">Altura</th>
								<th class="all-10 align-left hide-tiny hide-small">Peso</th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ($list as $r) :
								$pid = (int) $r->pid;
								?>
								<tr>
									<td class="align-center"><span class="ink-label ink-label-number blue"><?php echo esc_html((int) $r->shirt ?: '-'); ?></span></td>
									<td class="align-left"><a href="<?php echo esc_url($purl($pid)); ?>"><?php echo esc_html($r->name); ?></a></td>
									<td class="align-left">
										<?php echo f365_pl_flag((int) $r->nat_id, (string) $r->nat_name); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<?php echo esc_html((string) $r->nat_name); ?>
									</td>
									<td class="align-left hide-tiny hide-small"><?php echo esc_html(f365_pl_age((string) $r->birth_date)); ?></td>
									<td class="align-left hide-tiny hide-small"><?php echo $r->height ? esc_html($r->height) : ''; ?></td>
									<td class="align-left hide-tiny hide-small"><?php echo $r->weight ? esc_html($r->weight) : ''; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php
				};
				?>
				<h3 class="condensed-400 headerTitle blue">Plantel</h3>
				<?php
				if (!$squads['gk'] && !$squads['df'] && !$squads['mf'] && !$squads['fw']) {
					echo '<h5 class="no-info">Sem informação disponível</h5>';
				} else {
					$render_position($squads['gk'], 'Guarda-redes');
					$render_position($squads['df'], 'Defesa');
					$render_position($squads['mf'], 'Médio');
					$render_position($squads['fw'], 'Avançado');
				}
				?>

			<?php else : /* ---- TAB 2: SINGLE STATISTIC ---- */ ?>

				<?php
				$pos_labels = [
					'goalkeeper' => 'Guarda-redes',
					'defender'   => 'Defesa',
					'midfielder' => 'Médio',
					'forward'    => 'Avançado',
				];
				$stat_rows  = []; // pid => ['value','games','meta']
				$team_games = 0;
				if ($teamstep) {
					$stats      = f365_pl_load_stats($django_team, $edition, $step, $teamstep, $competition);
					$meta_map   = $stats['meta'] ?? [];
					$team_games = (int) ($stats['team_games'] ?? 0);
					foreach ((array) ($stats['agg'] ?? []) as $a) {
						$val_map                = (array) $a;
						$val_map['total_cards'] = (int) $a->yellow_cards + (int) $a->double_yellow_cards + (int) $a->red_cards;
						$value                  = (int) ($val_map[$squad_option] ?? 0);
						// Django drops players who did not play any game (total_of_games == 0).
						if ((int) $a->total_of_games > 0 && $value > 0) {
							$pid             = (int) $a->pid;
							$stat_rows[$pid] = [
								'value'  => $value,
								'played' => (int) $a->total_of_games,
								'meta'   => $meta_map[$pid] ?? null,
							];
						}
					}
					// Sort by the selected stat value, descending (Django OrderedDict sort).
					uasort($stat_rows, static fn($x, $y) => $y['value'] <=> $x['value']);
				}

				/*
				 * Average denominator (Django):
				 *   total_of_games -> divide by team_games
				 *   every other stat -> divide by the player's own total_of_games
				 */
				$avg_by_team_games = ('total_of_games' === $squad_option);
				?>

				<h3 class="condensed-400 headerTitle blue">Plantel — <?php echo esc_html($stat_labels[$squad_option] ?? ''); ?></h3>
				<?php if (empty($stat_rows)) : ?>
					<h5 class="no-info">Sem informação disponível</h5>
				<?php else : ?>
					<table class="ink-table alternating ink-table-f365 all-100">
						<thead>
							<tr>
								<th class="all-5 align-left"></th>
								<th class="align-left">Jogador</th>
								<th class="all-30 align-left hide-tiny hide-small">Posição</th>
								<th class="all-10 align-center">Total</th>
								<th class="all-10 align-center">Jogos</th>
								<th class="all-10 align-center">Média</th>
							</tr>
						</thead>
						<tbody>
							<?php
							$rank = 0;
							foreach ($stat_rows as $pid => $row) :
								++$rank;
								$meta      = $row['meta'];
								$pname     = $meta ? (string) $meta->name : ('#' . $pid);
								$nat_id    = $meta ? (int) $meta->nat_id : 0;
								$nat_name  = $meta ? (string) $meta->nat_name : '';
								$pos_key   = $meta ? (string) $meta->position : '';
								$pos_label = $pos_labels[$pos_key] ?? '';
								$value     = (int) $row['value'];
								// "Jogos" column: team_games for total_of_games, else player's appearances.
								$games_col = $avg_by_team_games ? $team_games : (int) $row['played'];
								$denom     = $avg_by_team_games ? $team_games : (int) $row['played'];
								$avg       = $denom > 0 ? number_format($value / $denom, 2, '.', '') : '0.00';
								$pgames    = function_exists('f365_player_games_url')
									? f365_player_games_url($pid, $edition, $competition)
									: add_query_arg(
										['edition' => $edition, 'competition' => $competition ?: ''],
										user_trailingslashit(home_url('jogador/' . $pid . '/jogos'))
									);
								?>
								<tr>
									<td class="align-center"><?php echo (int) $rank; ?></td>
									<td class="align-left">
										<?php echo f365_pl_flag($nat_id, $nat_name); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<a href="<?php echo esc_url($purl($pid)); ?>"><?php echo esc_html($pname); ?></a>
									</td>
									<td class="align-left hide-tiny hide-small"><?php echo esc_html($pos_label); ?></td>
									<td class="align-center"><a href="<?php echo esc_url($pgames); ?>"><?php echo (int) $value; ?></a></td>
									<td class="align-center"><a href="<?php echo esc_url($pgames); ?>"><?php echo (int) $games_col; ?></a></td>
									<td class="align-center"><?php echo esc_html($avg); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

			<?php endif; ?>

			<?php
			/* ---- Equipa Técnica (coaches) — from cached base ---- */
			if (!empty($coaches)) :
				$coach_url = static fn(int $id) => function_exists('f365_coach_url') ? f365_coach_url($id) : user_trailingslashit(home_url('treinador/' . $id));
				?>
				<h3 class="condensed-400 headerTitle blue double-top-space">Equipa Técnica</h3>
				<table class="ink-table alternating ink-table-f365">
					<thead>
						<tr>
							<th class="all-5 align-left"></th>
							<th class="all-35 align-left">Nome</th>
							<th class="all-30 align-left">Nacionalidade</th>
							<th class="all-20 align-left hide-tiny hide-small">Função</th>
							<th class="all-10 align-left hide-tiny hide-small">Idade</th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ((array) $coaches as $c) :
							$ctype  = (string) $c->coach_type;
							$clabel = f365_pl_coach_type_label($ctype);
							?>
							<tr>
								<td class="align-center"><span class="ink-label ink-label-number blue"><?php echo esc_html(mb_substr($clabel, 0, 1)); ?></span></td>
								<td class="align-left"><a href="<?php echo esc_url($coach_url((int) $c->cid)); ?>"><?php echo esc_html($c->name); ?></a></td>
								<td class="align-left">
									<?php echo f365_pl_flag((int) $c->nat_id, (string) $c->nat_name); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php echo esc_html((string) $c->nat_name); ?>
								</td>
								<td class="align-left hide-tiny hide-small"><?php echo esc_html($clabel); ?></td>
								<td class="align-left hide-tiny hide-small"><?php echo esc_html(f365_pl_age((string) $c->birth_date)); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

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
