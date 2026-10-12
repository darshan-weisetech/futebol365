<?php

/**
 * Team Comparar-com (Frente-a-frente) ROUTER — /equipa/{id}/comparar-com/{id2}/[section/]
 *
 * Header / filters / banner unchanged. The match-based sections (Confrontos +
 * Resumo) now read from the Django MIRROR TABLES instead of wp_postmeta, because
 * the match/teamstep/competition data was migrated into those custom tables, not
 * into CPT postmeta. Teams remain WP posts (f365_team) so the banner/links work;
 * each team's Django id is resolved via the `django_id` meta (team 1) and the
 * `f365_team2_id` query var (team 2), then matches are fetched from:
 *   {prefix}competition_match  → home_team_id / visitor_team_id (teamstep ids)
 *   {prefix}team_teamstep      → team_id
 *   {prefix}team_team          → shortname
 *   {prefix}competition_competition / federation_federationcompetition
 *
 * @package Futebol365
 */

defined('ABSPATH') || exit;

/* ---------- helpers ---------- */

require_once __DIR__ . '/f365-team-compare-sections.php';

if (!function_exists('f365_cmp_team_name')):
    function f365_cmp_team_name(int $id, int $django_id = 0): string
    {
        if ($django_id) {
            global $wpdb;
            $t = $wpdb->get_row($wpdb->prepare("SELECT shortname, longname FROM {$wpdb->prefix}team_team WHERE id = %d", $django_id));
            if (!empty($t->shortname)) {
                return (string) $t->shortname;
            }
            if (!empty($t->longname)) {
                return (string) $t->longname;
            }
        }
        if ($id) {
            $sn = get_post_meta($id, 'shortname', true) ?: get_post_meta($id, 'longname', true);
            if (!empty($sn)) {
                return (string) $sn;
            }
            $title = get_the_title($id);
            if (!empty($title)) {
                return (string) $title;
            }
        }
        return '';
    }
endif;
if (!function_exists('f365_cmp_team_logo')):
    function f365_cmp_team_logo(int $id, int $django_id = 0): string
    {
        if ($id) {
            $img = get_the_post_thumbnail_url($id, 'full');
            if ($img) {
                return (string) $img;
            }
            $thumb_id = get_post_meta($id, '_thumbnail_id', true);
            if ($thumb_id) {
                $img = wp_get_attachment_url($thumb_id);
                if ($img) {
                    return (string) $img;
                }
            }
        }
        if ($django_id) {
            global $wpdb;
            $mt_logo = $wpdb->get_var($wpdb->prepare("SELECT logo FROM {$wpdb->prefix}team_team WHERE id = %d", $django_id));
            if (!empty($mt_logo)) {
                return (string) $mt_logo;
            }
        }
        return function_exists('futebol365_fallback_image') ? (string) futebol365_fallback_image() : '';
    }
endif;
if (!function_exists('f365_cmp_avg')):
    function f365_cmp_avg(int $g, int $n): string
    {
        return $n > 0 ? number_format($g / $n, 2, ',', '.') : '0,00';
    }
endif;
if (!function_exists('f365_cmp_eff')):
    /** effective [home,visitor] score, extra-time when both extra values set. */
    function f365_cmp_eff($m): array
    {
        if ('' !== (string) $m->hse && '' !== (string) $m->vse) {
            return [(int) $m->hse, (int) $m->vse];
        }
        return [(int) $m->hs, (int) $m->vs];
    }
endif;

/* ---------- resolve teams + filters ---------- */

get_header();

$first_django_id = (int) (
    get_query_var('f365_team_id')
    ?: (get_query_var('team_id') ?: ($args['post_id'] ?? 0))
);
$second_django_id = (int) (
    $args['second_team_id']
    ?? (get_query_var('f365_team2_id')
    ?: (get_query_var('second_team_id') ?: 0))
);

if ((!$first_django_id || !$second_django_id) && !empty($_SERVER['REQUEST_URI'])) {
    $path = wp_parse_url(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH);
    if (preg_match('#/equipa/(\d+)/comparar-com/(\d+)(?:/|$)#', (string) $path, $m)) {
        if (!$first_django_id) {
            $first_django_id = (int) $m[1];
        }
        if (!$second_django_id) {
            $second_django_id = (int) $m[2];
        }
    }
}

/* Django team ids for the mirror-table queries. */
//$first_django_id = (int) get_post_meta($first_id, 'django_id', true);
$first_django_id = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT legacy_id
         FROM {$wpdb->posts}
         WHERE ID = %d",
        $first_id
    )
);
/* $second_django_id already holds team 2's Django id (query var / arg). */

// CRITICAL CHANGE: Default blank section routing string to 'confrontos' on first load
$current_section = get_query_var('section', '') ?: 'confrontos';
$compare_sections = futebol365_get_team_compare_sections();
if ('' !== $current_section && !isset($compare_sections[$current_section])) {
    $current_section = 'confrontos';
}

$g = static function (string $k, string $d): string {
    return isset($_GET[$k]) && '' !== $_GET[$k] ? sanitize_text_field(wp_unslash($_GET[$k])) : $d;
};
$step1 = $g('step1', 'principal');
$gender1 = $g('gender1', 'male');
$modality1 = $g('modality1', 'football');
$step2 = $g('step2', 'principal');
$gender2 = $g('gender2', 'male');
$modality2 = $g('modality2', 'football');
$edition = $g('edition', (string) gmdate('Y'));
$sel_fc = $g('federation_competition', '');
$begin_ed = $g('begin_edition', '');
$end_ed = $g('end_edition', '');
$sel_comp = $g('competition', '');

$games_choices_raw = $g('games_choices', '');

$games_choices = match ($games_choices_raw) {
    'home', 'is_home_team' => 'home',
    'away', 'is_visitor_team' => 'away',
    default => 'all',
};

global $wpdb;

$pm = $wpdb->postmeta;
$posts = $wpdb->posts;
$mt = $wpdb->prefix; // mirror tables: {$mt}competition_match, {$mt}team_teamstep, ...

/* teamstep resolver (mirror table) — optimized to avoid 5-way postmeta join */
$get_ts = static function (int $django_team_id, string $step, string $gender, string $modality) use ($wpdb, $mt, $edition) {
    if (!$django_team_id) {
        return [[], 0];
    }
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT id, edition
            FROM {$mt}team_teamstep
            WHERE team_id = %d AND step = %s AND modality = %s AND gender = %s
            LIMIT 3000
            ",
            $django_team_id,
            $step,
            $modality,
            $gender
        )
    );
    $ids = [];
    $for_ed = 0;
    foreach ((array) $rows as $r) {
        $ids[] = (int) $r->id;
        if ((string) $r->edition === (string) $edition) {
            $for_ed = (int) $r->id;
        }
    }
    return [$ids, $for_ed ?: ($ids[0] ?? 0)];
};
[$t1_ids, $teamstep1] = $get_ts($first_django_id, $step1, $gender1, $modality1);
[$t2_ids, $teamstep2] = $get_ts($second_django_id, $step2, $gender2, $modality2);

/*
|--------------------------------------------------------------------------
| Compare Header Data
|--------------------------------------------------------------------------
*/
$first_name = f365_cmp_team_name($first_id, $first_django_id);
$second_name = f365_cmp_team_name($second_id, $second_django_id);
$first_logo = f365_cmp_team_logo($first_id, $first_django_id);
$second_logo = f365_cmp_team_logo($second_id, $second_django_id);

$first_url = add_query_arg(
    ['edition' => $edition, 'modality' => $modality1, 'gender' => $gender1, 'step' => $step1],
    home_url("/equipa/{$first_django_id}/jogos/")
);
$second_url = add_query_arg(
    ['edition' => $edition, 'modality' => $modality2, 'gender' => $gender2, 'step' => $step2],
    home_url("/equipa/{$second_django_id}/jogos/")
);

$n1 = $first_name;
$n2 = $second_name;
$tt1 = get_post_meta($first_id, 'team_type', true) ?: 'club';
$tt2 = $second_id ? (get_post_meta($second_id, 'team_type', true) ?: 'club') : 'club';
$is_clubs = ('national' !== $tt1 && 'national' !== $tt2);

/* mirror-table match-list resolver — kept for forma section */
$resolve_match_meta = static function (array $rows) use ($wpdb, $mt) {
    $ts = [];
    foreach ($rows as $m) {
        if (isset($m->hts)) {
            $ts[(int) $m->hts] = 1;
        }
        if (isset($m->vts)) {
            $ts[(int) $m->vts] = 1;
        }
    }
    $ts_team = [];
    if ($ts) {
        $in = implode(',', array_map('absint', array_keys($ts)));
        foreach ($wpdb->get_results("SELECT id, team_id FROM {$mt}team_teamstep WHERE id IN ({$in})") as $r) {
            $ts_team[(int) $r->id] = (int) $r->team_id;
        }
    }
    /* Prime caches for teams if we can map them back to WP posts.
       We use the postmeta-based retrieval for team URLs later, so priming is good if we have IDs.
    */
    if ($ts_team) {
        $unique_teams = array_unique(array_values($ts_team));
        $wp_ids = $wpdb->get_col("SELECT post_id FROM {$mt}team_team WHERE id IN (" . implode(',', array_map('absint', $unique_teams)) . ")");
        if ($wp_ids) {
            _prime_post_caches(array_map('intval', $wp_ids), false, true);
        }
    }
    return $ts_team;
};
$match_result = static function ($m) {
    [$he, $ve] = f365_cmp_eff($m);
    $txt = $he . '-' . $ve;
    $url = get_permalink((int) $m->mid);
    return $url ? '<a href="' . esc_url($url) . '">' . esc_html($txt) . '</a>' : esc_html($txt);
};

/* ============================================================
 * Per-section data execution logic
 * ============================================================ */

$matches = [];                 // resumo + confrontos  (NOW from mirror tables)
$paged = max(1, (int) ($_GET['pagina'] ?? 1));
$per_page = 30;
$total_pages = 1;
$forma1 = $forma2 = [];
$awards_rows = [];
$t1_total = $t2_total = 0;

$need_h2h = ('' === $current_section || 'confrontos' === $current_section || 'resumo' === $current_section);

if ($need_h2h && $first_django_id && $second_django_id) {

    if ('home' === $games_choices) {
        $dir = '( hts.team_id = %d AND vts.team_id = %d )';
        $dp = [$first_django_id, $second_django_id];
    } elseif ('away' === $games_choices) {
        $dir = '( hts.team_id = %d AND vts.team_id = %d )';
        $dp = [$second_django_id, $first_django_id];
    } else {
        $dir = '( ( hts.team_id = %d AND vts.team_id = %d ) OR ( hts.team_id = %d AND vts.team_id = %d ) )';
        $dp = [$first_django_id, $second_django_id, $second_django_id, $first_django_id];
    }

    $matches = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT
                m.id  AS mid,
                hts.team_id AS home_django,
                vts.team_id AS visitor_django,
                hteam.shortname AS home_name,
                vteam.shortname AS visitor_name,
                m.home_score AS hs, m.visitor_score AS vs,
                m.home_score_extra AS hse, m.visitor_score_extra AS vse,
                m.penalty_home_score AS phs, m.penalty_visitor_score AS pvs,
                m.date_time AS dt,
                c.federation_competition_id AS comp_fed,
                c.edition AS comp_ed,
                c.name AS comp_name,
                c.country_id AS comp_country,
                fc.shortname AS fed_name,
                fc.federation_id AS fed_id
            FROM {$mt}competition_match m
            INNER JOIN {$mt}team_teamstep hts ON hts.id = m.home_team_id
            INNER JOIN {$mt}team_team     hteam ON hteam.id = hts.team_id
            INNER JOIN {$mt}team_teamstep vts ON vts.id = m.visitor_team_id
            INNER JOIN {$mt}team_team     vteam ON vteam.id = vts.team_id
            INNER JOIN {$mt}competition_competition c ON c.id = m.competition_id
            LEFT  JOIN {$mt}federation_federationcompetition fc ON fc.id = c.federation_competition_id
            WHERE m.situation = 'finished' AND {$dir}
            ORDER BY m.date_time DESC, m.id DESC",
            $dp
        )
    );
}

/* fed-comp dropdown (competitions where the two teams met) */
$fed_comps = [];
if ($matches) {
    $fp = [];
    foreach ($matches as $m) {
        if ($m->comp_fed) {
            $fp[(int) $m->comp_fed] = (string) ($m->fed_name ?: $m->comp_name);
        }
    }
    asort($fp, SORT_NATURAL | SORT_FLAG_CASE);
    $fed_comps = $fp; // id => name (matches the inline filter form's `as $id => $name`)
}

/*
 * Begin/End edition selects — shown ONLY when a federation competition is
 * selected (mirrors TeamCompareConfrontationForm). Choices are ALL editions of
 * that federation competition (value = edition, label = season), newest first.
 * Defaults: begin = oldest edition, end = newest edition.
 */
$edition_choices = [];   // edition => season
$sel_begin = '';
$sel_end = '';
if ('' !== $sel_fc) {
    $erows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT edition, MAX(season) AS season
               FROM {$mt}competition_competition
              WHERE federation_competition_id = %d AND edition IS NOT NULL AND edition <> ''
           GROUP BY edition
           ORDER BY edition DESC",
            (int) $sel_fc
        )
    );
    foreach ((array) $erows as $er) {
        $edition_choices[(string) $er->edition] = (string) ($er->season ?: $er->edition);
    }
    $ed_keys = array_keys($edition_choices);     // DESC
    $newest = $ed_keys[0] ?? '';
    $oldest = $ed_keys ? end($ed_keys) : '';
    $sel_begin = ('' !== $begin_ed) ? $begin_ed : $oldest;
    $sel_end = ('' !== $end_ed) ? $end_ed : $newest;
}
$editions_list = array_keys($edition_choices); // passed to header partial

/* Apply federation + edition-range filter to the H2H match set. */
if ($matches && '' !== $sel_fc) {
    $matches = array_values(array_filter($matches, static function ($m) use ($sel_fc, $sel_begin, $sel_end) {
        if ((string) $m->comp_fed !== (string) $sel_fc) {
            return false;
        }
        if ('' !== $sel_begin && '' !== (string) $m->comp_ed && (int) $m->comp_ed < (int) $sel_begin) {
            return false;
        }
        if ('' !== $sel_end && '' !== (string) $m->comp_ed && (int) $m->comp_ed > (int) $sel_end) {
            return false;
        }
        return true;
    }));
}

/* ---- Resumo aggregate (mirror rows; team 1 detected by Django id) ---- */
$agg = static fn() => ['games' => 0, 't1_w' => 0, 'draws' => 0, 't2_w' => 0, 't1_g' => 0, 't2_g' => 0];
$overall = $agg();
$home = $agg();
$away = $agg();
$bw_home_t1 = $bw_home_t2 = $bw_away_t1 = $bw_away_t2 = null;
$bump = static function (&$slot, $mid, $he, $ve, $diff) {
    if (null === $slot || $diff > $slot['diff']) {
        $slot = ['mid' => $mid, 'he' => $he, 've' => $ve, 'diff' => $diff];
    }
};

if ('resumo' === $current_section) {
    foreach ($matches as $m) {
        $t1_home = ((int) $m->home_django === $first_django_id);
        [$he, $ve] = f365_cmp_eff($m);
        $t1g = $t1_home ? $he : $ve;
        $t2g = $t1_home ? $ve : $he;
        $draw = ($he === $ve);
        $t1win = !$draw && (($t1_home && $he > $ve) || (!$t1_home && $ve > $he));
        $add = static function (array &$b) use ($t1g, $t2g, $draw, $t1win) {
            $b['games']++;
            $b['t1_g'] += $t1g;
            $b['t2_g'] += $t2g;
            if ($draw) {
                $b['draws']++;
            } elseif ($t1win) {
                $b['t1_w']++;
            } else {
                $b['t2_w']++;
            }
        };
        $add($overall);
        if ($t1_home) {
            $add($home);
        } else {
            $add($away);
        }
        if (!$draw) {
            if ($t1_home && $t1win) {
                $bump($bw_home_t1, (int) $m->mid, $he, $ve, $he - $ve);
            } elseif (!$t1_home && !$t1win) {
                $bump($bw_home_t2, (int) $m->mid, $he, $ve, $he - $ve);
            } elseif (!$t1_home && $t1win) {
                $bump($bw_away_t1, (int) $m->mid, $he, $ve, $ve - $he);
            } elseif ($t1_home && !$t1win) {
                $bump($bw_away_t2, (int) $m->mid, $he, $ve, $ve - $he);
            }
        }
    }
}

/* ---- Confrontos pagination slice ---- */
$page_matches = [];
if ('confrontos' === $current_section) {
    $total_pages = max(1, (int) ceil(count($matches) / $per_page));
    $page_matches = array_slice($matches, ($paged - 1) * $per_page, $per_page);
}

/* ---- Forma: each teamstep's last 10 finished matches (mirror tables) ---- */
if ('forma' === $current_section && ($first_django_id || $second_django_id)) {
    $last10_mirror = static function (int $team_django) use ($wpdb, $mt) {
        if (!$team_django) {
            return [];
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT
                m.id AS mid,
                hts.team_id AS home_django, vts.team_id AS visitor_django,
                hteam.shortname AS home_name, vteam.shortname AS visitor_name,
                m.home_score AS hs, m.visitor_score AS vs,
                m.home_score_extra AS hse, m.visitor_score_extra AS vse,
                m.date_time AS dt,
                c.federation_competition_id AS comp_fed, c.name AS comp_name,
                c.country_id AS comp_country, fc.federation_id AS fed_id
            FROM {$mt}competition_match m
            INNER JOIN {$mt}team_teamstep hts ON hts.id = m.home_team_id
            INNER JOIN {$mt}team_team     hteam ON hteam.id = hts.team_id
            INNER JOIN {$mt}team_teamstep vts ON vts.id = m.visitor_team_id
            INNER JOIN {$mt}team_team     vteam ON vteam.id = vts.team_id
            INNER JOIN {$mt}competition_competition c ON c.id = m.competition_id
            LEFT  JOIN {$mt}federation_federationcompetition fc ON fc.id = c.federation_competition_id
            WHERE m.situation = 'finished' AND ( hts.team_id = %d OR vts.team_id = %d )
            ORDER BY m.date_time DESC, m.id DESC
            LIMIT 10",
            $team_django,
            $team_django
        ));
    };
    $forma1 = $last10_mirror($first_django_id);
    $forma2 = $last10_mirror($second_django_id);
}

/* ---- Palmarés ---- (optimized: mirror tables) */
if ('palmares' === $current_section) {
    $team_titles = static function (int $django_team_id, string $step, string $gender, string $modality) use ($wpdb, $mt) {
        if (!$django_team_id) {
            return [];
        }
        $out = [];
        /* Winners from competition_competition */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.federation_competition_id AS fed, COUNT(DISTINCT c.id) AS n
            FROM {$mt}competition_competition c
            INNER JOIN {$mt}team_teamstep ts ON ts.id = c.first_place_teamstep_id
            WHERE ts.team_id = %d AND ts.step = %s AND ts.modality = %s AND ts.gender = %s
            GROUP BY c.federation_competition_id",
            $django_team_id,
            $step,
            $modality,
            $gender
        ));
        foreach ((array) $rows as $r) {
            if ($r->fed) {
                $out[(int) $r->fed] = (int) $r->n;
            }
        }
        /* Winners from phase_winner */
        $prows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.federation_competition_id AS fed, COUNT(DISTINCT pw.id) AS n
            FROM {$mt}competition_phasewinner pw
            INNER JOIN {$mt}team_teamstep ts ON ts.id = pw.first_place_teamstep_id
            INNER JOIN {$mt}competition_phase ph ON ph.id = pw.phase_id
            INNER JOIN {$mt}competition_competition c ON c.id = ph.competition_id
            WHERE ts.team_id = %d AND ts.step = %s AND ts.modality = %s AND ts.gender = %s
            GROUP BY c.federation_competition_id",
            $django_team_id,
            $step,
            $modality,
            $gender
        ));
        foreach ((array) $prows as $r) {
            if ($r->fed) {
                $out[(int) $r->fed] = ($out[(int) $r->fed] ?? 0) + (int) $r->n;
            }
        }
        return $out;
    };
    $t1_titles = $first_django_id ? $team_titles($first_django_id, $step1, $gender1, $modality1) : [];
    $t2_titles = $second_django_id ? $team_titles($second_django_id, $step2, $gender2, $modality2) : [];
    $all_fed = array_values(array_unique(array_merge(array_keys($t1_titles), array_keys($t2_titles))));
    if ($all_fed) {
        _prime_post_caches(array_map('intval', $all_fed), false, true);
        foreach ($all_fed as $fid) {
            $fid = (int) $fid;
            $awards_rows[] = [
                'id' => $fid,
                'name' => f365_cmp_team_name($fid),
                'order' => (int) get_post_meta($fid, 'order', true),
                't1' => $t1_titles[$fid] ?? 0,
                't2' => $t2_titles[$fid] ?? 0
            ];
        }
        usort($awards_rows, static fn($a, $b) => $a['order'] <=> $b['order'] ?: strcasecmp($a['name'], $b['name']));
        foreach ($awards_rows as $r) {
            $t1_total += $r['t1'];
            $t2_total += $r['t2'];
        }
    }
}

/* ---- Estádios ---- (optimized: mirror tables) */
$stadium_django1 = $stadium_django2 = 0;
if ('estadios' === $current_section && $is_clubs) {
    if ($teamstep1) {
        $stadium_django1 = (int) $wpdb->get_var($wpdb->prepare("SELECT stadium_id FROM {$mt}team_teamstep WHERE id = %d", $teamstep1));
    }
    if (!$stadium_django1 && $first_django_id) {
        $stadium_django1 = (int) $wpdb->get_var($wpdb->prepare("SELECT stadium_id FROM {$mt}team_team WHERE id = %d", $first_django_id));
    }
    if ($teamstep2) {
        $stadium_django2 = (int) $wpdb->get_var($wpdb->prepare("SELECT stadium_id FROM {$mt}team_teamstep WHERE id = %d", $teamstep2));
    }
    if (!$stadium_django2 && $second_django_id) {
        $stadium_django2 = (int) $wpdb->get_var($wpdb->prepare("SELECT stadium_id FROM {$mt}team_team WHERE id = %d", $second_django_id));
    }
}

/* render helpers */
$render_table = static function (array $b, string $left, string $right) {
    ob_start(); ?>
    <table class="ink-table ink-table-f365 alternating all-100">
        <thead>
            <tr>
                <th class="align-center all-33"><?php echo esc_html($left); ?></th>
                <th class="align-center all-33"></th>
                <th class="align-center all-33"><?php echo esc_html($right); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="align-center"><?php echo (int) $b['games']; ?></td>
                <td class="align-center">Jogos</td>
                <td class="align-center"><?php echo (int) $b['games']; ?></td>
            </tr>
            <tr>
                <td class="align-center"><?php echo (int) $b['t1_w']; ?></td>
                <td class="align-center">Vitórias</td>
                <td class="align-center"><?php echo (int) $b['t2_w']; ?></td>
            </tr>
            <tr>
                <td class="align-center"><?php echo (int) $b['draws']; ?></td>
                <td class="align-center">Empates</td>
                <td class="align-center"><?php echo (int) $b['draws']; ?></td>
            </tr>
            <tr>
                <td class="align-center"><?php echo (int) $b['t2_w']; ?></td>
                <td class="align-center">Derrotas</td>
                <td class="align-center"><?php echo (int) $b['t1_w']; ?></td>
            </tr>
            <tr>
                <td class="align-center"><?php echo (int) $b['t1_g']; ?></td>
                <td class="align-center">Golos marcados</td>
                <td class="align-center"><?php echo (int) $b['t2_g']; ?></td>
            </tr>
            <tr>
                <td class="align-center"><?php echo (int) $b['t2_g']; ?></td>
                <td class="align-center">Golos sofridos</td>
                <td class="align-center"><?php echo (int) $b['t1_g']; ?></td>
            </tr>
            <tr>
                <td class="align-center"><?php echo esc_html(f365_cmp_avg($b['t1_g'], $b['games'])); ?></td>
                <td class="align-center">Média marcados</td>
                <td class="align-center"><?php echo esc_html(f365_cmp_avg($b['t2_g'], $b['games'])); ?></td>
            </tr>
            <tr>
                <td class="align-center"><?php echo (int) ($b['t1_g'] - $b['t2_g']); ?></td>
                <td class="align-center">Diferença de golos</td>
                <td class="align-center"><?php echo (int) ($b['t2_g'] - $b['t1_g']); ?></td>
            </tr>
        </tbody>
    </table>
    <?php return ob_get_clean();
};
$res_link = static function ($slot) {
    if (!$slot) {
        return '-';
    }
    $u = get_permalink($slot['mid']);
    $x = $slot['he'] . '-' . $slot['ve'];
    return $u ? '<a href="' . esc_url($u) . '">' . esc_html($x) . '</a>' : esc_html($x);
};

/* H2H / Forma match-list renderer for MIRROR rows */
$render_mirror_list = static function (array $rows) use ($first_django_id, $first_id, $second_id) {
    /* Build a Django-id → WP-post-id map for team links (only the two teams). */
    $wp_for = static function (int $django) use ($first_django_id, $first_id, $second_id) {
        if ($django === $first_django_id) {
            return $first_id;
        }
        if ($second_id) {
            return $second_id;
        }
        return 0;
    };
    ob_start(); ?>
    <table class="ink-table alternating ink-table-f365 all-100">
        <thead>
            <tr>
                <th class="all-5 small-15 tiny-15 align-left">Data</th>
                <th class="all-15 hide-tiny hide-small align-left">Competição</th>
                <th class="all-30 align-right hide-tiny hide-small">Visitado</th>
                <th class="all-45 align-left hide-medium hide-large hide-xlarge">Jogo</th>
                <th class="all-10 small-20 tiny-20"></th>
                <th class="all-30 align-left hide-tiny hide-small">Visitante</th>
                <th class="all-10 small-20 tiny-20"></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $m):
                $dt = $m->dt ? date_i18n('d-m-y', strtotime($m->dt)) : '';
                $dt_time = $m->dt ? date_i18n('H:i', strtotime($m->dt)) : '';

                // effective scores + winner + result text
                if ('' !== (string) ($m->phs ?? '') && '' !== (string) ($m->pvs ?? '')) {
                    $he = (int) $m->phs;
                    $ve = (int) $m->pvs;
                    $rtxt = $m->phs . '-' . $m->pvs . ' g.p';
                } elseif ('' !== (string) $m->hse && '' !== (string) $m->vse) {
                    $he = (int) $m->hse;
                    $ve = (int) $m->vse;
                    $rtxt = $m->hse . '-' . $m->vse . ' a.p';
                } elseif ('' !== (string) $m->hs && '' !== (string) $m->vs) {
                    $he = (int) $m->hs;
                    $ve = (int) $m->vs;
                    $rtxt = $m->hs . '-' . $m->vs;
                } else {
                    $he = $ve = 0;
                    $rtxt = 'vs';
                }
                $home_win = ($he > $ve);
                $vis_win = ($ve > $he);

                $home_wp = $wp_for((int) $m->home_django);
                $vis_wp = $wp_for((int) $m->visitor_django);
                $home_url = $home_wp ? get_permalink($home_wp) : '';
                $vis_url = $vis_wp ? get_permalink($vis_wp) : '';

                $comp_url = home_url('competicao/' . (int) $m->comp_fed . '/');
                $match_url = home_url('jogo/' . (int) $m->mid . '/');

                /* get_flag_id(): competition country id, else conf_{federation_id}. */
                $flag_id = ('' !== (string) ($m->comp_country ?? '') && (int) $m->comp_country > 0)
                    ? (string) (int) $m->comp_country
                    : 'conf_' . (int) ($m->fed_id ?? 0);
                $flag_html = ('' !== $flag_id && '0' !== $flag_id && 'conf_0' !== $flag_id)
                    ? '<i class="flag-icon quarter-right-space flag-icon-' . esc_attr($flag_id) . '" title="' . esc_attr($m->comp_name) . '"></i>'
                    : '';

                $comp_lbl = (string) $m->comp_name;
                if (function_exists('mb_strlen') ? mb_strlen($comp_lbl) > 20 : strlen($comp_lbl) > 20) {
                    $comp_lbl = (function_exists('mb_substr') ? mb_substr($comp_lbl, 0, 19) : substr($comp_lbl, 0, 19)) . '…';
                }
                $home_cls = $home_win ? ' class="teamWinner"' : '';
                $vis_cls = $vis_win ? ' class="teamWinner"' : '';
                ?>
                <tr>
                    <td class="align-left cell-nowrap"><a href="<?php echo esc_url($match_url); ?>"><?php echo esc_html($dt); ?>
                            <span class="hide-xlarge hide-large hide-medium break"><?php echo esc_html($dt_time); ?></span>
                        </a></td>
                    <td class="align-left hide-tiny hide-small cell-nowrap"><a
                            href="<?php echo esc_url($comp_url); ?>"><?php echo $flag_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html($comp_lbl); ?></a>
                    </td>
                    <td class="align-right hide-tiny hide-small">
                        <a href="<?php echo esc_url($home_url); ?>" <?php echo $home_cls; // phpcs:ignore ?>><?php echo esc_html($m->home_name); ?></a>
                    </td>
                    <td class="align-left hide-xlarge hide-large hide-medium">
                        <a href="<?php echo esc_url($home_url); ?>" <?php echo $home_cls; // phpcs:ignore ?>><?php echo esc_html($m->home_name); ?></a>
                        <span class="break">
                            <a href="<?php echo esc_url($vis_url); ?>" <?php echo $vis_cls; // phpcs:ignore ?>><?php echo esc_html($m->visitor_name); ?></a>
                        </span>
                    </td>
                    <td class="align-center"><span class="ink-label ink-label-score silver"><a
                                href="<?php echo esc_url($match_url); ?>"><?php echo esc_html($rtxt); ?></a></span></td>
                    <td class="align-left hide-tiny hide-small">
                        <a href="<?php echo esc_url($vis_url); ?>" <?php echo $vis_cls; // phpcs:ignore ?>><?php echo esc_html($m->visitor_name); ?></a>
                    </td>
                    <td class="align-left"></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php return ob_get_clean();
};

/* nav query string for header */
$nav_qs = http_build_query(
    array_filter([
        'edition' => $edition,
        'step1' => $step1,
        'gender1' => $gender1,
        'modality1' => $modality1,
        'step2' => $step2,
        'gender2' => $gender2,
        'modality2' => $modality2,
        'federation_competition' => $sel_fc,
        'games_choices' => $games_choices_raw,
        'begin_edition' => $begin_ed,
        'end_edition' => $end_ed,
    ])
);
$sections = [
    'resumo' => ['label' => 'Resumo', 'template' => 'resumo'],
    'confrontos' => ['label' => 'Confrontos', 'template' => 'confrontos'],
    'forma' => ['label' => 'Forma', 'template' => 'forma'],
    'palmares' => ['label' => 'Palmarés', 'template' => 'palmares'],
];
if ($is_clubs) {
    $sections['estadios'] = ['label' => 'Estádios', 'template' => 'estadios'];
}
?>

<main id="primary" class="site-main single-article-page">

    <?php
    get_template_part('template-parts/team/f365-team-compare-header', null, [
        'sections' => $sections,
        'post_id' => $first_id,
        'first_id' => $first_id,
        'second_id' => $second_id,
        'n1' => $n1,
        'n2' => $n2,
        'logo1' => $first_id ? f365_cmp_team_logo($first_id, $first_django_id) : '',
        'logo2' => $second_id ? f365_cmp_team_logo($second_id, $second_django_id) : '',
        'current_section' => $current_section,
        'nav_qs' => $nav_qs,
        'fed_comps' => $fed_comps,
        'sel_fed_comp' => $sel_fc,
        'editions' => $editions_list,
        'begin_edition' => $begin_ed,
        'end_edition' => $end_ed,
        'is_clubs' => $is_clubs,
    ]);
    ?>

    <div class="ink-grid futebol365-container">
        <section class="left-column-block">

            <div class="breadcrumbs-f365">
                <div class="ink-navigation breadcrumbs">
                    <?php yoast_breadcrumb(); ?>
                </div>
            </div>
            <div class="compare-filter-wrapper">
                <form class="ink-form ink-form-option" method="get" id="compare_teams_form">
                    <div class="column-group">
                        <div class="control-group all-33 tiny-100 small-100">
                            <div class="control">
                                <select id="id_federation_competition" name="federation_competition">
                                    <option value="">Competições</option>
                                    <?php foreach ($fed_comps as $id => $name): ?>
                                        <option value="<?php echo esc_attr($id); ?>" <?php selected($sel_fc, $id); ?>>
                                            <?php echo esc_html($name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="control-group all-33 tiny-100 small-100">
                            <div class="control">
                                <select id="id_games_choices" name="games_choices">
                                    <option value="">Jogos</option>
                                    <option value="is_home_team" <?php selected($games_choices_raw, 'is_home_team'); ?>>
                                        <?php echo esc_html($n1 . ' vs ' . $n2); ?>
                                    </option>
                                    <option value="is_visitor_team" <?php selected($games_choices_raw, 'is_visitor_team'); ?>><?php echo esc_html($n2 . ' vs ' . $n1); ?></option>
                                </select>
                            </div>
                        </div>
                        <?php if ('' !== $sel_fc && !empty($edition_choices)): ?>
                            <div class="control-group all-33 tiny-100 small-100">
                                <div class="control">
                                    <select id="id_begin_edition" name="begin_edition">
                                        <option value="">Época inicial</option>
                                        <?php foreach ($edition_choices as $ed => $season): ?>
                                            <option value="<?php echo esc_attr($ed); ?>" <?php selected($sel_begin, $ed); ?>>
                                                <?php echo esc_html($season); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="control-group all-33 tiny-100 small-100">
                                <div class="control">
                                    <select id="id_end_edition" name="end_edition">
                                        <option value="">Época final</option>
                                        <?php foreach ($edition_choices as $ed => $season): ?>
                                            <option value="<?php echo esc_attr($ed); ?>" <?php selected($sel_end, $ed); ?>>
                                                <?php echo esc_html($season); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <script>
                jQuery(document).ready(function ($) {
                    var queryParameters = {};
                    var queryString = window.location.search.substring(1);
                    var re = /([^&=]+)=([^&]*)/g;
                    var m;
                    while ((m = re.exec(queryString))) {
                        queryParameters[decodeURIComponent(m[1])] = decodeURIComponent(m[2]);
                    }

                    $('#id_federation_competition').on('change', function () {
                        if ($(this).val()) {
                            queryParameters['federation_competition'] = $(this).val();
                        } else {
                            delete queryParameters['federation_competition'];
                        }
                        delete queryParameters['games_choices'];
                        delete queryParameters['begin_edition'];
                        delete queryParameters['end_edition'];
                        delete queryParameters['pagina'];
                        location.search = $.param(queryParameters);
                    });

                    $('#id_games_choices').on('change', function () {
                        if ($(this).val()) {
                            queryParameters['games_choices'] = $(this).val();
                        } else {
                            delete queryParameters['games_choices'];
                        }
                        delete queryParameters['begin_edition'];
                        delete queryParameters['end_edition'];
                        delete queryParameters['pagina'];
                        location.search = $.param(queryParameters);
                    });

                    $('#id_begin_edition, #id_end_edition').on('change', function () {
                        if ($('#id_federation_competition').val()) {
                            queryParameters['federation_competition'] = $('#id_federation_competition').val();
                        }
                        if ($('#id_begin_edition').val()) {
                            queryParameters['begin_edition'] = $('#id_begin_edition').val();
                        } else {
                            delete queryParameters['begin_edition'];
                        }
                        if ($('#id_end_edition').val()) {
                            queryParameters['end_edition'] = $('#id_end_edition').val();
                        } else {
                            delete queryParameters['end_edition'];
                        }
                        delete queryParameters['pagina'];
                        location.search = $.param(queryParameters);
                    });
                });
            </script>

            <div class="column-group match-div">
                <div class="all-40 small-30 tiny-30 align-center">
                    <a href="<?php echo esc_url($first_url); ?>">
                        <h5 class="match-team-name"><?php echo esc_html($first_name); ?></h5>
                    </a>
                </div>
                <div class="all-20 small-40 tiny-40 align-center"></div>
                <div class="all-40 small-30 tiny-30 align-center">
                    <a href="<?php echo esc_url($second_url); ?>">
                        <h5 class="match-team-name"><?php echo esc_html($second_name); ?></h5>
                    </a>
                </div>
                <div class="all-40 small-30 tiny-30 align-center">
                    <a href="<?php echo esc_url($first_url); ?>">
                        <?php if ($first_logo): ?><img class="team-logo" src="<?php echo esc_url($first_logo); ?>"
                                alt="<?php echo esc_attr($first_name); ?>"><?php endif; ?>
                    </a>
                </div>
                <div class="all-20 small-40 tiny-40 align-center">
                    <h2 class="match-score"><span class="ink-badge black no-margin quarter-vertical-padding">VS</span>
                    </h2>
                </div>
                <div class="all-40 small-30 tiny-30 align-center">
                    <a href="<?php echo esc_url($second_url); ?>">
                        <?php if ($second_logo): ?><img class="team-logo" src="<?php echo esc_url($second_logo); ?>"
                                alt="<?php echo esc_attr($second_name); ?>"><?php endif; ?>
                    </a>
                </div>
            </div>

            <?php if (!$second_id): ?>

                <h6 class="no-info"><?php esc_html_e('Selecione uma segunda equipa para comparar.', 'futebol365'); ?></h6>

            <?php elseif ('palmares' === $current_section): ?>

                <h5 class="condensed-400 headerTitle blue"><?php esc_html_e('Palmarés', 'futebol365'); ?></h5>
                <div class="column-group">
                    <table class="ink-table ink-table-f365 alternating all-100">
                        <thead>
                            <tr>
                                <th class="align-center all-33">
                                    <?php echo esc_html($teamstep1 ? f365_cmp_team_name($teamstep1) : $first_name); ?>
                                </th>
                                <th class="align-center all-33">Competição</th>
                                <th class="align-center all-33">
                                    <?php echo esc_html($teamstep2 ? f365_cmp_team_name($teamstep2) : $second_name); ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($awards_rows)): ?>
                                <tr>
                                    <td class="align-center" colspan="3"><span
                                            class="no-info"><?php esc_html_e('Sem palmarés registado para estas equipas.', 'futebol365'); ?></span>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($awards_rows as $r): ?>
                                    <tr>
                                        <td class="align-center"><?php echo esc_html($r['t1'] > 0 ? $r['t1'] : '-'); ?></td>
                                        <td class="align-center"><a
                                                href="<?php echo esc_url(get_permalink($r['id'])); ?>"><?php echo esc_html($r['name']); ?></a>
                                        </td>
                                        <td class="align-center"><?php echo esc_html($r['t2'] > 0 ? $r['t2'] : '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="font-bold">
                                    <td class="align-center"><strong><?php echo (int) $t1_total; ?></strong></td>
                                    <td class="align-center"><strong>Total</strong></td>
                                    <td class="align-center"><strong><?php echo (int) $t2_total; ?></strong></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ('resumo' === $current_section): ?>

                <h5 class="condensed-400 headerTitle blue"><?php esc_html_e('Resumo', 'futebol365'); ?></h5>
                <?php if (empty($matches)): ?>
                    <h6 class="no-info">
                        <?php esc_html_e('Não foram encontrados jogos realizados entre as equipas.', 'futebol365'); ?>
                    </h6>
                <?php else: ?>
                    <?php if ($is_clubs): ?>
                        <h5 class="condensed-400 headerTitle blue"><?php echo esc_html($n1 . ' - ' . $n2); ?></h5>
                        <div class="column-group"><?php echo $render_table($home, $n1, $n2); // phpcs:ignore ?></div>
                        <?php $home2 = ['games' => $away['games'], 't1_w' => $away['t2_w'], 'draws' => $away['draws'], 't2_w' => $away['t1_w'], 't1_g' => $away['t2_g'], 't2_g' => $away['t1_g']]; ?>
                        <h5 class="condensed-400 headerTitle blue"><?php echo esc_html($n2 . ' - ' . $n1); ?></h5>
                        <div class="column-group"><?php echo $render_table($home2, $n2, $n1); // phpcs:ignore ?></div>
                    <?php endif; ?>
                    <h5 class="condensed-400 headerTitle blue"><?php esc_html_e('Resumo Global', 'futebol365'); ?></h5>
                    <div class="column-group"><?php echo $render_table($overall, $n1, $n2); // phpcs:ignore ?></div>
                    <?php if ($is_clubs): ?>
                        <h5 class="condensed-400 headerTitle blue"><?php esc_html_e('Curiosidades', 'futebol365'); ?></h5>
                        <div class="column-group">
                            <table class="ink-table ink-table-f365 alternating all-100">
                                <thead>
                                    <tr>
                                        <th class="align-center all-33"><?php echo esc_html($n1); ?></th>
                                        <th class="align-center all-33"></th>
                                        <th class="align-center all-33"><?php echo esc_html($n2); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="align-center"><?php echo $res_link($bw_home_t1); // phpcs:ignore ?></td>
                                        <td class="align-center"><?php esc_html_e('Maior vitória em casa', 'futebol365'); ?></td>
                                        <td class="align-center"><?php echo $res_link($bw_home_t2); // phpcs:ignore ?></td>
                                    </tr>
                                    <tr>
                                        <td class="align-center"><?php echo $res_link($bw_away_t1); // phpcs:ignore ?></td>
                                        <td class="align-center"><?php esc_html_e('Maior vitória fora', 'futebol365'); ?></td>
                                        <td class="align-center"><?php echo $res_link($bw_away_t2); // phpcs:ignore ?></td>
                                    </tr>
                                    <tr>
                                        <td class="align-center"><?php echo $res_link($bw_away_t2); // phpcs:ignore ?></td>
                                        <td class="align-center"><?php esc_html_e('Maior derrota em casa', 'futebol365'); ?></td>
                                        <td class="align-center"><?php echo $res_link($bw_away_t1); // phpcs:ignore ?></td>
                                    </tr>
                                    <tr>
                                        <td class="align-center"><?php echo $res_link($bw_home_t2); // phpcs:ignore ?></td>
                                        <td class="align-center"><?php esc_html_e('Maior derrota fora', 'futebol365'); ?></td>
                                        <td class="align-center"><?php echo $res_link($bw_home_t1); // phpcs:ignore ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

            <?php elseif ('confrontos' === $current_section): ?>

                <h5 class="condensed-400 headerTitle blue"><?php esc_html_e('Confrontos', 'futebol365'); ?></h5>
                <?php if (empty($page_matches)): ?>
                    <h6 class="no-info">
                        <?php esc_html_e('Não foram encontrados jogos realizados entre as equipas.', 'futebol365'); ?>
                    </h6>
                <?php else:
                    echo $render_mirror_list($page_matches);

                    if ($total_pages > 1) {
                        $links = paginate_links([
                            'base' => add_query_arg('pagina', '%#%'),
                            'format' => '',
                            'current' => $paged,
                            'total' => $total_pages,
                            'prev_text' => '«',
                            'next_text' => '»',
                            'add_args' => array_filter([
                                'edition' => $_GET['edition'] ?? '',
                                'step1' => $_GET['step1'] ?? '',
                                'gender1' => $_GET['gender1'] ?? '',
                                'modality1' => $_GET['modality1'] ?? '',
                                'step2' => $_GET['step2'] ?? '',
                                'gender2' => $_GET['gender2'] ?? '',
                                'modality2' => $_GET['modality2'] ?? '',
                                'federation_competition' => $sel_fc,
                                'begin_edition' => $begin_ed,
                                'end_edition' => $end_ed,
                                'games_choices' => ('all' !== $games_choices) ? $games_choices_raw : '',
                            ]),
                        ]);
                        if ($links) {
                            echo '<div class="paginacao-new"><nav class="ink-navigation"><ul class="pagination">';
                            echo $links; // phpcs:ignore
                            echo '</ul></nav></div>';
                        }
                    }
                endif; ?>

            <?php elseif ('forma' === $current_section): ?>

                <h5 class="condensed-400 headerTitle blue"><?php echo esc_html($n1); ?>
                    <?php esc_html_e('- Últimos jogos', 'futebol365'); ?>
                </h5>
                <?php echo $forma1 ? $render_mirror_list($forma1) : '<h6 class="no-info">Não foram encontrados jogos realizados entre as equipas.</h6>'; ?>
                <h5 class="condensed-400 headerTitle blue"><?php echo esc_html($n2); ?>
                    <?php esc_html_e('- Últimos jogos', 'futebol365'); ?>
                </h5>
                <?php echo $forma2 ? $render_mirror_list($forma2) : '<h6 class="no-info">Não foram encontrados jogos realizados entre as equipas.</h6>'; ?>

            <?php elseif ('estadios' === $current_section): ?>

                <h5 class="condensed-400 headerTitle blue"><?php esc_html_e('Estádios', 'futebol365'); ?></h5>
                <?php if (!$is_clubs): ?>
                    <h6 class="no-info">
                        <?php esc_html_e('Comparação de estádios disponível apenas para clubes.', 'futebol365'); ?>
                    </h6>
                <?php else:
                    $st_field = static function (int $sid, string $f) use ($wpdb, $mt) {
                        return $sid ? (string) $wpdb->get_var($wpdb->prepare("SELECT {$f} FROM {$mt}stadium_stadium WHERE id = %d", $sid)) : '';
                    };
                    ?>
                    <div class="column-group">
                        <table class="ink-table ink-table-f365 alternating all-100">
                            <thead>
                                <tr>
                                    <th class="align-center all-33"><?php echo esc_html($n1); ?></th>
                                    <th class="align-center all-33"></th>
                                    <th class="align-center all-33"><?php echo esc_html($n2); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="align-center">
                                        <?php echo esc_html($st_field($stadium_django1, 'shortname') ?: '-'); ?>
                                    </td>
                                    <td class="align-center"><?php esc_html_e('Estádio', 'futebol365'); ?></td>
                                    <td class="align-center">
                                        <?php echo esc_html($st_field($stadium_django2, 'shortname') ?: '-'); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="align-center"><?php echo esc_html($st_field($stadium_django1, 'city') ?: '-'); ?></td>
                                    <td class="align-center"><?php esc_html_e('Cidade', 'futebol365'); ?></td>
                                    <td class="align-center"><?php echo esc_html($st_field($stadium_django2, 'city') ?: '-'); ?></td>
                                </tr>
                                <tr>
                                    <td class="align-center">
                                        <?php echo esc_html(($c = $st_field($stadium_django1, 'capacity')) ? number_format((int) $c, 0, ',', '.') : '-'); ?>
                                    </td>
                                    <td class="align-center"><?php esc_html_e('Capacidade', 'futebol365'); ?></td>
                                    <td class="align-center">
                                        <?php echo esc_html(($c = $st_field($stadium_django2, 'capacity')) ? number_format((int) $c, 0, ',', '.') : '-'); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

            <?php get_template_part('template-parts/template-front-page-posts'); ?>

        </section>

        <section class="right-column-block">
            <?php if (is_active_sidebar('post-sidebar')) {
                dynamic_sidebar('post-sidebar');
            } else {
                get_sidebar();
            } ?>
        </section>
    </div>
</main>

<?php
get_footer();
