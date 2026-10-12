<?php
/**
 * Competition — Classificação   /competicao/{fed_comp_id}/classificacao/
 *
 * @package Futebol365
 */

defined('ABSPATH') || exit;

global $wpdb;
$classification_cache_group = 'f365_classificacao';

if (!function_exists('f365_cls_table_exists')) {
    function f365_cls_table_exists($table_name)
    {
        global $wpdb;

        static $checked_tables = array();

        if (isset($checked_tables[$table_name])) {
            return $checked_tables[$table_name];
        }

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $table_name
            )
        );

        $checked_tables[$table_name] = ($exists === $table_name);

        return $checked_tables[$table_name];
    }
}

if (!function_exists('f365_cls_find_competition_post_id')) {
    function f365_cls_find_competition_post_id($identifier)
    {
        $identifier = trim((string) $identifier);

        if ('' === $identifier) {
            return 0;
        }

        $args = array(
            'post_type' => 'f365_competition',
            'post_status' => array('publish', 'draft'),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => 'edition',
            'orderby' => 'meta_value_num',
            'order' => 'DESC',
        );

        if (ctype_digit($identifier)) {
            $args['meta_query'] = array(
                array(
                    'key' => 'django_fed_comp_id',
                    'value' => (int) $identifier,
                    'compare' => '=',
                    'type' => 'NUMERIC',
                ),
            );
        } else {
            $args['name'] = sanitize_title($identifier);
        }

        $posts = get_posts($args);

        return !empty($posts) ? (int) $posts[0] : 0;
    }
}
if (!function_exists('f365_cls_competition_has_matches')) {
    function f365_cls_competition_has_matches($competition_id)
    {
        global $wpdb;

        $competition_id = (int) $competition_id;

        if ($competition_id <= 0) {
            return false;
        }

        $match_table = $wpdb->prefix . 'competition_match';

        if (!f365_cls_table_exists($match_table)) {
            return true;
        }

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM `{$match_table}` WHERE competition_id = %d LIMIT 1",
                $competition_id
            )
        );
    }
}

if (!function_exists('f365_cls_resolve_competition_id')) {
    function f365_cls_resolve_competition_id()
    {
        global $wpdb;

        $identifier = get_query_var('f365_competition_slug');

        if ('' === trim((string) $identifier) && !empty($_SERVER['REQUEST_URI'])) {
            $request_path = wp_parse_url(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            if (preg_match('#/competicao/([^/]+)/classificacao/?#', (string) $request_path, $matches)) {
                $identifier = rawurldecode($matches[1]);
            }
        }

        $identifier = trim((string) $identifier);

        if ('' === $identifier || !ctype_digit($identifier)) {
            return 0;
        }

        $competition_table = $wpdb->prefix . 'competition_competition';
        $match_table = $wpdb->prefix . 'competition_match';

        if (!f365_cls_table_exists($competition_table)) {
            return 0;
        }

        $edition = isset($_GET['edition']) ? sanitize_text_field(wp_unslash($_GET['edition'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $where = array('c.federation_competition_id = %d');
        $params = array((int) $identifier);

        if ('' !== $edition) {
            $where[] = 'c.edition = %s';
            $params[] = $edition;
        }

        $where_sql = implode(' AND ', $where);
        $order_sql = 'ORDER BY CAST(c.edition AS UNSIGNED) DESC, c.edition DESC, c.id DESC LIMIT 1';
        $available_sql = "(c.is_available = 1 OR c.is_available = '1' OR c.is_available = 't' OR c.is_available = 'true')";

        if (f365_cls_table_exists($match_table)) {
            $competition_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT c.id
                    FROM `{$competition_table}` c
                    WHERE {$where_sql}
                      AND {$available_sql}
                      AND EXISTS (
                          SELECT 1 FROM `{$match_table}` m WHERE m.competition_id = c.id LIMIT 1
                      )
                    {$order_sql}",
                    $params
                )
            );

            if ($competition_id > 0) {
                return $competition_id;
            }
        }

        $competition_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT c.id
                FROM `{$competition_table}` c
                WHERE {$where_sql}
                  AND {$available_sql}
                {$order_sql}",
                $params
            )
        );

        if ($competition_id > 0) {
            return $competition_id;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT c.id
                FROM `{$competition_table}` c
                WHERE {$where_sql}
                {$order_sql}",
                $params
            )
        );
    }
}

if (!function_exists('f365_cls_teamstep_data')) {
    function f365_cls_teamstep_data($teamstep_id, $competition_id)
    {
        global $wpdb;

        $teamstep_table = $wpdb->prefix . 'team_teamstep';
        $team_table = $wpdb->prefix . 'team_team';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT ts.id AS teamstep_id, ts.team_id, ts.step, ts.modality, ts.gender, ts.edition,
					t.shortname, t.slug, t.team_type
				FROM `{$teamstep_table}` ts
				LEFT JOIN `{$team_table}` t ON t.id = ts.team_id
				WHERE ts.id = %d",
                (int) $teamstep_id
            ),
            ARRAY_A
        );

        if (!$row) {
            return array(
                'name' => '#' . (int) $teamstep_id,
                'url' => '#',
            );
        }

        $url = home_url('/equipa/' . (int) $row['team_id'] . '/jogos/');

        $url = add_query_arg(
            array_filter(
                array(
                    'edition' => $row['edition'] ?? '',
                    'modality' => $row['modality'] ?? '',
                    'gender' => $row['gender'] ?? '',
                    'step' => $row['step'] ?? '',
                    'competition' => (int) $competition_id,
                )
            ),
            $url
        );

        $step_labels = array(
            'b_team' => 'B',
            'c_team' => 'C',
            'sub_23' => 'S23',
            'sub_22' => 'S22',
            'sub_21' => 'S21',
            'sub_20' => 'S20',
            'sub_19' => 'S19',
            'sub_19_b' => 'S19 B',
            'sub_18' => 'S18',
            'sub_17' => 'S17',
            'sub_17_b' => 'S17 B',
            'sub_16' => 'S16',
            'sub_15' => 'S15',
            'sub_15_b' => 'S15 B',
            'sub_14' => 'S14',
            'sub_13' => 'S13',
            'sub_13_b' => 'S13 B',
            'sub_12' => 'S12',
            'sub_12_b' => 'S12 B',
            'sub_11' => 'S11',
            'sub_11_b' => 'S11 B',
            'sub_10' => 'S10',
            'sub_10_b' => 'S10 B',
            'sub_9' => 'S9',
            'sub_8' => 'S8',
            'sub_7' => 'S7',
            'sub_6' => 'S6',
            'veterans' => 'VET',
            'reservations' => 'RES',
        );

        $name = $row['shortname'] ?: '#' . (int) $teamstep_id;
        $suffix = '';

        if ('futsal' === ($row['modality'] ?? '')) {
            $suffix .= ' FS';
        }
        if ('female' === ($row['gender'] ?? '')) {
            $suffix .= ' FM';
        }
        if (isset($step_labels[$row['step'] ?? ''])) {
            $suffix .= ' ' . $step_labels[$row['step']];
        }

        return array(
            'name' => trim($name . $suffix),
            'url' => $url,
            'team_id' => (int) $row['team_id'],
            'team_type' => $row['team_type'] ?? '',
        );
    }
}

if (!function_exists('f365_cls_init_row')) {
    function f365_cls_init_row(&$rows, $teamstep_id)
    {
        $teamstep_id = (int) $teamstep_id;

        if (isset($rows[$teamstep_id])) {
            return;
        }

        $rows[$teamstep_id] = array(
            'teamstep_id' => $teamstep_id,
            'J' => 0,
            'V' => 0,
            'E' => 0,
            'D' => 0,
            'GM' => 0,
            'GS' => 0,
            'DG' => 0,
            'P' => 0,
            'DP' => 0,
        );
    }
}

if (!function_exists('f365_cls_head_to_head')) {
    function f365_cls_head_to_head($teamstep_id, $opponent_id, $matches, $metric)
    {
        $value = 0;

        foreach ($matches as $match) {
            $is_home = (int) $match['home_team_id'] === (int) $teamstep_id && (int) $match['visitor_team_id'] === (int) $opponent_id;
            $is_visitor = (int) $match['visitor_team_id'] === (int) $teamstep_id && (int) $match['home_team_id'] === (int) $opponent_id;

            if (!$is_home && !$is_visitor) {
                continue;
            }

            $for = $is_home ? (int) $match['home_score'] : (int) $match['visitor_score'];
            $against = $is_home ? (int) $match['visitor_score'] : (int) $match['home_score'];

            if ('points' === $metric) {
                $value += $for > $against ? 3 : ($for === $against ? 1 : 0);
            } elseif ('goal_difference' === $metric) {
                $value += $for - $against;
            } elseif ('visitor_goals' === $metric && $is_visitor) {
                $value += $for;
            }
        }

        return $value;
    }
}

if (!function_exists('f365_cls_legacy_python2_order_map')) {
    function f365_cls_legacy_python2_order_map($team_ids)
    {
        $count = count($team_ids);
        $size = 8;

        while ($count * 3 >= $size * 2) {
            $size *= 2;
        }

        $mask = $size - 1;
        $table = array_fill(0, $size, null);

        foreach ($team_ids as $team_id) {
            $team_id = (int) $team_id;
            $slot = $team_id & $mask;
            $perturb = $team_id;

            while (null !== $table[$slot]) {
                $slot = (($slot << 2) + $slot + $perturb + 1) & $mask;
                $perturb = intdiv($perturb, 32);
            }

            $table[$slot] = $team_id;
        }

        $order = array();
        foreach ($table as $team_id) {
            if (null !== $team_id) {
                $order[(int) $team_id] = count($order);
            }
        }

        return $order;
    }
}
if (!function_exists('f365_cls_compare_rows')) {
    function f365_cls_compare_rows($a, $b, $method, $matches)
    {
        switch ($method) {
            case 'points_between':
                $av = f365_cls_head_to_head($a['teamstep_id'], $b['teamstep_id'], $matches, 'points');
                $bv = f365_cls_head_to_head($b['teamstep_id'], $a['teamstep_id'], $matches, 'points');
                break;
            case 'goal_difference_between':
                $av = f365_cls_head_to_head($a['teamstep_id'], $b['teamstep_id'], $matches, 'goal_difference');
                $bv = f365_cls_head_to_head($b['teamstep_id'], $a['teamstep_id'], $matches, 'goal_difference');
                break;
            case 'visitor_goals_between':
                $av = f365_cls_head_to_head($a['teamstep_id'], $b['teamstep_id'], $matches, 'visitor_goals');
                $bv = f365_cls_head_to_head($b['teamstep_id'], $a['teamstep_id'], $matches, 'visitor_goals');
                break;
            case 'victories':
                $av = (int) $a['V'];
                $bv = (int) $b['V'];
                break;
            case 'scored_goals':
                $av = (int) $a['GM'];
                $bv = (int) $b['GM'];
                break;
            case 'goal_difference':
            default:
                $av = (int) $a['DG'];
                $bv = (int) $b['DG'];
                break;
        }

        return $av === $bv ? 0 : $bv <=> $av;
    }
}

if (!function_exists('f365_cls_place')) {
    function f365_cls_place($competition_id, $position, $phase_id, $group)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'competition_competitionplace';
        $where = array('competition_id = %d', 'position = %d');
        $params = array((int) $competition_id, (int) $position);

        if ($phase_id) {
            $where[] = '(competition_phase_id = %d OR competition_phase_id IS NULL)';
            $params[] = (int) $phase_id;
        }

        if ('' !== $group) {
            $where[] = '(`group` = %s OR `group` IS NULL OR `group` = \'\')';
            $params[] = $group;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT color, description FROM `{$table}` WHERE " . implode(' AND ', $where) . ' ORDER BY competition_phase_id DESC, `group` DESC LIMIT 1',
                $params
            ),
            ARRAY_A
        );
    }
}

if (!function_exists('f365_cls_place_notes')) {
    function f365_cls_place_notes($competition_id, $phase_id, $group)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'competition_competitionplace';

        if (!f365_cls_table_exists($table)) {
            return array();
        }

        $where = array('competition_id = %d', 'description IS NOT NULL', "description <> ''", "color IS NOT NULL", "color <> ''", "color <> '#'");
        $params = array((int) $competition_id);

        if ($phase_id) {
            $where[] = '(competition_phase_id = %d OR competition_phase_id IS NULL)';
            $params[] = (int) $phase_id;
        }

        if ('' !== $group) {
            $where[] = '(`group` = %s OR `group` IS NULL OR `group` = \'\')';
            $params[] = $group;
        }

        $places = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT color, description, MIN(position) AS first_position
                FROM `{$table}`
                WHERE " . implode(' AND ', $where) . '
                GROUP BY color, description
                ORDER BY first_position ASC',
                $params
            ),
            ARRAY_A
        );

        return $places ?: array();
    }
}

if (!function_exists('f365_cls_render_place_notes')) {
    function f365_cls_render_place_notes($competition_id, $phase_id, $group)
    {
        $places = f365_cls_place_notes($competition_id, $phase_id, $group);

        if (empty($places)) {
            return;
        }
        ?>
        <div class="column-group">
            <h6 class="headerTitleSimple">Notas da classificação</h6>
            <table class="ink-table ink-table-futebol365 noborder">
                <tbody>
                    <?php foreach ($places as $place): ?>
                        <tr>
                            <td class="align-center all-5 tiny-10 small-10">
                                <span class="ink-label ink-label-table-position"
                                    style="background-color:<?php echo esc_attr($place['color']); ?>">&nbsp;</span>
                            </td>
                            <td class="align-left"><?php echo esc_html($place['description']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
if (!function_exists('f365_cls_round_label')) {
    function f365_cls_round_label($round)
    {
        $round = (int) $round;

        return $round > 0 ? sprintf('%dª Jornada', $round) : '';
    }
}
if (!function_exists('f365_cls_default_phase')) {
    function f365_cls_default_phase($competition_id, $phases)
    {
        global $wpdb;

        if (empty($phases)) {
            return null;
        }

        $phase_by_id = array();
        foreach ($phases as $phase) {
            $phase_by_id[(int) $phase['id']] = $phase;
        }

        $match_table = $wpdb->prefix . 'competition_match';
        $phase_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT competition_phase_id
				FROM `{$match_table}`
				WHERE competition_id = %d AND competition_phase_id IS NOT NULL
				ORDER BY ABS(TIMESTAMPDIFF(SECOND, date_time, NOW())) ASC, date_time DESC
				LIMIT 1",
                (int) $competition_id
            )
        );

        if ($phase_id && isset($phase_by_id[$phase_id])) {
            return $phase_by_id[$phase_id];
        }

        return end($phases);
    }
}

if (!function_exists('f365_cls_calculate_table')) {
    function f365_cls_calculate_table($competition, $phase, $group, $match_round = null)
    {

        $cache_key = 'cls_' . md5(
            (int) $competition['id'] . '_' .
            ($phase ? (int) $phase['id'] : 0) . '_' .
            (string) $group . '_' .
            (string) $match_round
        );

        $cached_rows = wp_cache_get($cache_key, 'f365_classificacao');

        if (false !== $cached_rows) {
            return $cached_rows;
        }
        global $wpdb;

        $match_table = $wpdb->prefix . 'competition_match';
        $participant_table = $wpdb->prefix . 'competition_participant';
        $offset_table = $wpdb->prefix . 'competition_classificationoffset';
        $order_table = $wpdb->prefix . 'competition_competitionclassificationorder';

        $rows = array();
        if (f365_cls_table_exists($participant_table)) {
            $participant_where = array('competition_id = %d');
            $participant_params = array((int) $competition['id']);
            $participant_group_column = '';

            if ($phase) {
                $phase_order = max(1, min(3, (int) ($phase['order'] ?? 1)));
                $participant_group_column = 'group_phase_' . array(1 => 'one', 2 => 'two', 3 => 'three')[$phase_order];
            }

            if ('' !== $group && '' !== $participant_group_column) {
                $participant_where[] = "`{$participant_group_column}` = %s";
                $participant_params[] = $group;
            }

            $participants = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT team_id FROM `{$participant_table}` WHERE " . implode(' AND ', $participant_where) . ' ORDER BY id ASC',
                    $participant_params
                )
            );

            foreach ($participants as $participant_index => $participant_team_id) {
                $participant_team_id = (int) $participant_team_id;
                f365_cls_init_row($rows, $participant_team_id);
                $rows[$participant_team_id]['sort_order'] = $participant_index;
            }
        }

        $fixture_where = array('competition_id = %d');
        $fixture_params = array((int) $competition['id']);

        if ($phase) {
            $fixture_where[] = 'competition_phase_id = %d';
            $fixture_params[] = (int) $phase['id'];
        }

        if ('' !== $group) {
            $fixture_where[] = '(group_home_team = %s OR group_visitor_team = %s)';
            $fixture_params[] = $group;
            $fixture_params[] = $group;
        }

        $fixture_teams = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT home_team_id, visitor_team_id, group_home_team, group_visitor_team
                FROM `{$match_table}`
                WHERE " . implode(' AND ', $fixture_where) . '
                ORDER BY date_time ASC, id ASC',
                $fixture_params
            ),
            ARRAY_A
        );

        $fixture_sort_order = 0;
        foreach ($fixture_teams as $fixture_match) {
            $home = (int) $fixture_match['home_team_id'];
            $visitor = (int) $fixture_match['visitor_team_id'];
            $home_in_group = '' === $group || (string) $fixture_match['group_home_team'] === (string) $group;
            $visitor_in_group = '' === $group || (string) $fixture_match['group_visitor_team'] === (string) $group;

            if ($home > 0 && $home_in_group) {
                f365_cls_init_row($rows, $home);
                if (empty($rows[$home]['fixture_sort_seen'])) {
                    $rows[$home]['sort_order'] = $fixture_sort_order;
                    $rows[$home]['fixture_sort_seen'] = true;
                }
                $fixture_sort_order++;
            }

            if ($visitor > 0 && $visitor_in_group) {
                f365_cls_init_row($rows, $visitor);
                if (empty($rows[$visitor]['fixture_sort_seen'])) {
                    $rows[$visitor]['sort_order'] = $fixture_sort_order;
                    $rows[$visitor]['fixture_sort_seen'] = true;
                }
                $fixture_sort_order++;
            }
        }

        $where = array('competition_id = %d', 'home_score IS NOT NULL', 'visitor_score IS NOT NULL');
        $params = array((int) $competition['id']);

        if ($phase) {
            $where[] = 'competition_phase_id = %d';
            $params[] = (int) $phase['id'];
        }

        if ('' !== $group) {
            $where[] = '(group_home_team = %s OR group_visitor_team = %s)';
            $params[] = $group;
            $params[] = $group;
        }

        if (null !== $match_round && '' !== $match_round) {
            $where[] = 'group_phase_round <= %d';
            $params[] = (int) $match_round;
        }

        $matches = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, home_team_id, visitor_team_id, home_score, visitor_score
				FROM `{$match_table}`
				WHERE " . implode(' AND ', $where) . '
				ORDER BY group_phase_round ASC, date_time ASC, id ASC',
                $params
            ),
            ARRAY_A
        );

        foreach ($matches as $match) {
            $home = (int) $match['home_team_id'];
            $visitor = (int) $match['visitor_team_id'];
            $hs = (int) $match['home_score'];
            $vs = (int) $match['visitor_score'];

            f365_cls_init_row($rows, $home);
            f365_cls_init_row($rows, $visitor);

            $rows[$home]['J']++;
            $rows[$visitor]['J']++;
            $rows[$home]['GM'] += $hs;
            $rows[$home]['GS'] += $vs;
            $rows[$visitor]['GM'] += $vs;
            $rows[$visitor]['GS'] += $hs;

            if ($hs > $vs) {
                $rows[$home]['V']++;
                $rows[$visitor]['D']++;
                $rows[$home]['P'] += (int) ($competition['victory_points'] ?? 3);
            } elseif ($hs < $vs) {
                $rows[$visitor]['V']++;
                $rows[$home]['D']++;
                $rows[$visitor]['P'] += (int) ($competition['victory_points'] ?? 3);
            } else {
                $rows[$home]['E']++;
                $rows[$visitor]['E']++;
                $rows[$home]['P'] += (int) ($competition['draw_points'] ?? 1);
                $rows[$visitor]['P'] += (int) ($competition['draw_points'] ?? 1);
            }
        }

        $offset_where = array('competition_id = %d');
        $offset_params = array((int) $competition['id']);
        if ($phase) {
            $offset_where[] = '(competition_phase_id = %d OR competition_phase_id IS NULL)';
            $offset_params[] = (int) $phase['id'];
        }
        if ('' !== $group) {
            $offset_where[] = '(`group` = %s OR `group` IS NULL OR `group` = \'\')';
            $offset_params[] = $group;
        }

        $offsets = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT team_id, `offset` FROM `{$offset_table}` WHERE " . implode(' AND ', $offset_where),
                $offset_params
            ),
            ARRAY_A
        );

        foreach ($offsets as $offset) {
            $team = (int) $offset['team_id'];
            if (isset($rows[$team])) {
                $rows[$team]['P'] += (int) $offset['offset'];
            }
        }

        uasort(
            $rows,
            function ($a, $b) {
                return (int) ($a['sort_order'] ?? 0) <=> (int) ($b['sort_order'] ?? 0);
            }
        );
        $legacy_order = f365_cls_legacy_python2_order_map(array_keys($rows));

        foreach ($rows as &$row) {
            $row['DG'] = (int) $row['GM'] - (int) $row['GS'];
            $teamstep_id = (int) $row['teamstep_id'];
            $row['legacy_sort_order'] = $legacy_order[$teamstep_id] ?? (int) ($row['sort_order'] ?? 0);
        }
        unset($row);

        $orders = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT order_method FROM `{$order_table}` WHERE competition_id = %d ORDER BY `order` ASC, id ASC",
                (int) $competition['id']
            )
        );
        if (empty($orders)) {
            $orders = array('goal_difference', 'victories', 'scored_goals');
        }

        $rows = array_values($rows);
        usort(
            $rows,
            function ($a, $b) use ($orders, $matches) {
                if ((int) $a['P'] !== (int) $b['P']) {
                    return (int) $b['P'] <=> (int) $a['P'];
                }

                foreach ($orders as $method) {
                    $cmp = f365_cls_compare_rows($a, $b, $method, $matches);
                    if (0 !== $cmp) {
                        return $cmp;
                    }
                }

                if (isset($a['legacy_sort_order'], $b['legacy_sort_order']) && (int) $a['legacy_sort_order'] !== (int) $b['legacy_sort_order']) {
                    return (int) $a['legacy_sort_order'] <=> (int) $b['legacy_sort_order'];
                }

                if (isset($a['sort_order'], $b['sort_order']) && (int) $a['sort_order'] !== (int) $b['sort_order']) {
                    return (int) $a['sort_order'] <=> (int) $b['sort_order'];
                }

                return (int) $a['teamstep_id'] <=> (int) $b['teamstep_id'];
            }
        );

        $previous = null;
        foreach ($rows as $index => &$row) {
            $row['position'] = $index + 1;
            $row['DP'] = null === $previous ? 0 : max(0, (int) $previous['P'] - (int) $row['P']);
            $row['team'] = f365_cls_teamstep_data($row['teamstep_id'], (int) $competition['id']);
            $previous = $row;
        }
        unset($row);

        wp_cache_set($cache_key, $rows, 'f365_classificacao', 10 * MINUTE_IN_SECONDS);

        return $rows;
    }
}

if (!function_exists('f365_cls_render_table')) {
    function f365_cls_render_table($competition_id, $phase_id, $group, $rows)
    {
        if (empty($rows)) {
            echo '<h5 class="no-info">Sem informação disponível</h5>';
            return;
        }
        ?>
        <table class="ink-table ink-table-futebol365 alternating hover">
            <thead>
                <tr>
                    <th class="align-center all-5 tiny-10 small-10"></th>
                    <th class="align-left">Equipa</th>
                    <th class="align-center all-5 tiny-10 small-10"><span class="ink-tooltip" data-tip-text="Jogos"
                            data-tip-where="up" data-tip-color="black">J</span></th>
                    <th class="align-center all-5 small-10 hide-tiny"><span class="ink-tooltip" data-tip-text="Vitórias"
                            data-tip-where="up" data-tip-color="black">V</span></th>
                    <th class="align-center all-5 small-10 hide-tiny"><span class="ink-tooltip" data-tip-text="Empates"
                            data-tip-where="up" data-tip-color="black">E</span></th>
                    <th class="align-center all-5 small-10 hide-tiny"><span class="ink-tooltip" data-tip-text="Derrotas"
                            data-tip-where="up" data-tip-color="black">D</span></th>
                    <th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip" data-tip-text="Golos Marcados"
                            data-tip-where="up" data-tip-color="black">GM</span></th>
                    <th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip" data-tip-text="Golos Sofridos"
                            data-tip-where="up" data-tip-color="black">GS</span></th>
                    <th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip"
                            data-tip-text="Diferença de Golos" data-tip-where="up" data-tip-color="black">DG</span></th>
                    <th class="align-center all-5 tiny-10 small-10"><span class="ink-tooltip" data-tip-text="Pontos"
                            data-tip-where="up" data-tip-color="black">P</span></th>
                    <th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip"
                            data-tip-text="Diferença Pontual" data-tip-where="up" data-tip-color="black">DP</span></th>
                    <th class="align-center all-5 hide-tiny hide-small"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $place = f365_cls_place($competition_id, (int) $row['position'], $phase_id, $group);
                    $style = !empty($place['color']) ? 'background-color:' . esc_attr($place['color']) . ';' : '';
                    $title = !empty($place['description']) ? $place['description'] : '';
                    $url = $row['team']['url'] ?? '#';
                    ?>
                    <tr>
                        <td class="align-center"><span class="ink-label ink-label-table-position"
                                title="<?php echo esc_attr($title); ?>"
                                style="<?php echo esc_attr($style); ?>"><?php echo (int) $row['position']; ?></span></td>
                        <td class="align-left"><a
                                href="<?php echo esc_url($url); ?>"><?php echo esc_html($row['team']['name'] ?? ''); ?></a></td>
                        <td class="align-center"><?php echo (int) $row['J']; ?></td>
                        <td class="align-center hide-tiny"><?php echo (int) $row['V']; ?></td>
                        <td class="align-center hide-tiny"><?php echo (int) $row['E']; ?></td>
                        <td class="align-center hide-tiny"><?php echo (int) $row['D']; ?></td>
                        <td class="align-center hide-tiny hide-small"><?php echo (int) $row['GM']; ?></td>
                        <td class="align-center hide-tiny hide-small"><?php echo (int) $row['GS']; ?></td>
                        <td class="align-center hide-tiny hide-small"><?php echo (int) $row['DG']; ?></td>
                        <td class="align-center"><b><?php echo (int) $row['P']; ?></b></td>
                        <td class="align-center hide-tiny hide-small"><?php echo (int) $row['DP']; ?></td>
                        <td class="align-center hide-tiny hide-small"><a href="<?php echo esc_url($url); ?>">+</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}

if (!function_exists('f365_cls_score_label')) {
    function f365_cls_score_label($match)
    {
        if (null === $match['home_score'] || null === $match['visitor_score']) {
            return '-';
        }

        if (null !== $match['home_score_extra'] && null !== $match['visitor_score_extra']) {
            $label = (int) $match['home_score_extra'] . ' - ' . (int) $match['visitor_score_extra'];
            $label .= ' a.p';
        } else {
            $label = (int) $match['home_score'] . ' - ' . (int) $match['visitor_score'];
        }

        if (null !== $match['penalty_home_score'] && null !== $match['penalty_visitor_score']) {
            $label .= ' g.p';
        }

        return $label;
    }
}

if (!function_exists('f365_cls_match_datetime_parts')) {
    function f365_cls_match_datetime_parts($date_time)
    {
        if (empty($date_time) || '0000-00-00 00:00:00' === $date_time) {
            return array(
                'date' => '',
                'mobileDate' => '',
                'time' => '',
            );
        }

        $dt = DateTime::createFromFormat('Y-m-d H:i:s', substr((string) $date_time, 0, 19));
        if (!$dt) {
            $dt = new DateTime((string) $date_time);
        }

        $offset_seconds = (int) apply_filters('f365_classification_time_offset_seconds', HOUR_IN_SECONDS);
        if (0 !== $offset_seconds) {
            $dt->modify(($offset_seconds > 0 ? '+' : '') . $offset_seconds . ' seconds');
        }

        return array(
            'date' => $dt->format('d-m-y'),
            'mobileDate' => $dt->format('d-m'),
            'time' => $dt->format('H:i'),
        );
    }
}

if (!function_exists('f365_cls_is_match_phase')) {
    function f365_cls_is_match_phase($phase)
    {
        if (!$phase) {
            return false;
        }

        $phase_type = strtolower((string) ($phase['phase_type'] ?? ''));
        $phase_name = strtolower(remove_accents((string) ($phase['name'] ?? '')));

        if ('elimination' === $phase_type) {
            return true;
        }

        return (bool) preg_match('/^(final|meia|meias|quarto|quartos|oitavo|oitavos|semi|semifinal|semi-final|terceiro|3)/', $phase_name);
    }
}

if (!function_exists('f365_cls_match_rows')) {
    function f365_cls_match_rows($competition_id, $phase)
    {
        global $wpdb;

        $match_table = $wpdb->prefix . 'competition_match';
        $where = array('competition_id = %d');
        $params = array((int) $competition_id);

        if ($phase) {
            $where[] = 'competition_phase_id = %d';
            $params[] = (int) $phase['id'];
        }

        $matches = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, date_time, home_team_id, visitor_team_id, home_score, visitor_score,
					home_score_extra, visitor_score_extra, penalty_home_score, penalty_visitor_score
				FROM `{$match_table}`
				WHERE " . implode(' AND ', $where) . '
				ORDER BY date_time ASC, id ASC',
                $params
            ),
            ARRAY_A
        );

        foreach ($matches as &$match) {
            $match['home_team'] = f365_cls_teamstep_data((int) $match['home_team_id'], (int) $competition_id);
            $match['visitor_team'] = f365_cls_teamstep_data((int) $match['visitor_team_id'], (int) $competition_id);
        }
        unset($match);

        return $matches;
    }
}

if (!function_exists('f365_cls_render_match_table')) {
    function f365_cls_render_match_table($matches)
    {
        if (empty($matches)) {
            echo '<h5 class="no-info">Sem informação disponível</h5>';
            return;
        }
        ?>
        <table class="ink-table ink-table-futebol365 alternating">
            <thead>
                <tr>
                    <th class="all-10 small-15 tiny-15 align-left">Data</th>
                    <th class="all-10 hide-tiny hide-small align-center">Hora</th>
                    <th class="all-30 align-right hide-tiny hide-small">Visitado</th>
                    <th class="all-45 align-left hide-medium hide-large hide-xlarge">Jogo</th>
                    <th class="all-10 small-20 tiny-20"></th>
                    <th class="all-30 align-left hide-tiny hide-small">Visitante</th>
                    <th class="all-15 small-20 tiny-20"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($matches as $match): ?>
                    <?php
                    $datetime_parts = f365_cls_match_datetime_parts($match['date_time'] ?? '');
                    $home_score = null !== $match['home_score_extra'] ? (int) $match['home_score_extra'] : (null !== $match['home_score'] ? (int) $match['home_score'] : null);
                    $visitor_score = null !== $match['visitor_score_extra'] ? (int) $match['visitor_score_extra'] : (null !== $match['visitor_score'] ? (int) $match['visitor_score'] : null);
                    $home_winner = null !== $home_score && null !== $visitor_score && $home_score > $visitor_score;
                    $visitor_winner = null !== $home_score && null !== $visitor_score && $visitor_score > $home_score;
                    $home_class = $home_winner ? ' class="teamWinner"' : '';
                    $visitor_class = $visitor_winner ? ' class="teamWinner"' : '';
                    $home_team = $match['home_team'];
                    $visitor_team = $match['visitor_team'];
                    $match_url = home_url('/jogo/' . (int) $match['id'] . '/');
                    $compare_url = home_url('/equipa/' . (int) ($home_team['team_id'] ?? 0) . '/comparar-com/' . (int) ($visitor_team['team_id'] ?? 0) . '/');
                    $video_url = add_query_arg('match_opt', 'matchvideos', $match_url);
                    ?>
                    <tr>
                        <td class="align-left hide-tiny hide-small cell-nowrap"><?php echo esc_html($datetime_parts['date']); ?>
                        </td>
                        <td class="align-center hide-tiny hide-small"><?php echo esc_html($datetime_parts['time']); ?></td>
                        <td class="align-left hide-xlarge hide-large hide-medium">
                            <?php echo esc_html($datetime_parts['mobileDate']); ?>
                            <span class="break"><?php echo esc_html($datetime_parts['time']); ?></span>
                        </td>
                        <td class="align-right hide-tiny hide-small">
                            <a href="<?php echo esc_url($home_team['url'] ?? '#'); ?>" <?php echo $home_class; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html($home_team['name'] ?? ''); ?></a>
                        </td>
                        <td class="align-left hide-xlarge hide-large hide-medium">
                            <a href="<?php echo esc_url($home_team['url'] ?? '#'); ?>" <?php echo $home_class; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html($home_team['name'] ?? ''); ?></a>
                            <span class="break">
                                <a href="<?php echo esc_url($visitor_team['url'] ?? '#'); ?>" <?php echo $visitor_class; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html($visitor_team['name'] ?? ''); ?></a>
                            </span>
                        </td>
                        <td class="align-center">
                            <span class="ink-label ink-label-score silver"><a
                                    href="<?php echo esc_url($match_url); ?>"><?php echo esc_html(f365_cls_score_label($match)); ?></a></span>
                        </td>
                        <td class="align-left hide-tiny hide-small">
                            <a href="<?php echo esc_url($visitor_team['url'] ?? '#'); ?>" <?php echo $visitor_class; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html($visitor_team['name'] ?? ''); ?></a>
                        </td>
                        <td class="align-left">
                            <a href="<?php echo esc_url($compare_url); ?>"><span class="ink-tooltip ink-button-related green"
                                    data-tip-text="Comparar equipas" data-tip-where="up" data-tip-color="green">C</span></a>
                            <a href="<?php echo esc_url($video_url); ?>"><span class="ink-tooltip ink-button-related red"
                                    data-tip-text="Vídeo" data-tip-where="up" data-tip-color="red">V</span></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}

get_header();

$route_post_id = f365_cls_find_competition_post_id(get_query_var('f365_competition_slug'));
$current_post_id = $route_post_id ?: get_the_ID();
$current_section = 'classificacao';
$sections = function_exists('futebol365_get_competition_sections') ? futebol365_get_competition_sections() : array();
$competition_id = f365_cls_resolve_competition_id();

$competition_table = $wpdb->prefix . 'competition_competition';
$phase_table = $wpdb->prefix . 'competition_competitionphase';
$match_table = $wpdb->prefix . 'competition_match';

$missing_tables = array();
foreach (array($competition_table, $phase_table, $match_table, $wpdb->prefix . 'team_teamstep', $wpdb->prefix . 'team_team') as $required_table) {
    if (!f365_cls_table_exists($required_table)) {
        $missing_tables[] = $required_table;
    }
}

$competition = array();
$phases = array();
$phase = null;
$filter_phase = null;
$groups = array();
$rounds = array();
$selected_round = '';
$tables = array();
$show_matches = false;

if ($competition_id > 0 && empty($missing_tables)) {
    $competition = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM `{$competition_table}` WHERE id = %d", $competition_id),
        ARRAY_A
    );

    if ($competition) {
        $phases = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM `{$phase_table}` WHERE competition_id = %d ORDER BY `order` ASC, id ASC", $competition_id),
            ARRAY_A
        );

        $requested_phase = isset($_GET['competition_phase']) ? sanitize_text_field(wp_unslash($_GET['competition_phase'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        foreach ($phases as $candidate_phase) {
            if ((string) $candidate_phase['id'] === $requested_phase || (string) $candidate_phase['name'] === $requested_phase) {
                $phase = $candidate_phase;
                break;
            }
        }
        $filter_phase = $phase;
        if (!$phase && !empty($phases)) {
            $phase = f365_cls_default_phase($competition_id, $phases);
            $filter_phase = reset($phases);
        }

        if ($phase) {
            $groups = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT group_name FROM (
						SELECT group_home_team AS group_name FROM `{$match_table}` WHERE competition_id = %d AND competition_phase_id = %d AND group_home_team IS NOT NULL AND group_home_team <> ''
						UNION
						SELECT group_visitor_team AS group_name FROM `{$match_table}` WHERE competition_id = %d AND competition_phase_id = %d AND group_visitor_team IS NOT NULL AND group_visitor_team <> ''
					) grp_names ORDER BY group_name ASC", /* alias: `groups` is reserved in MySQL 8 */
                    $competition_id,
                    (int) $phase['id'],
                    $competition_id,
                    (int) $phase['id']
                )
            );

            $participant_table = $wpdb->prefix . 'competition_participant';
            if (f365_cls_table_exists($participant_table)) {
                $phase_order = max(1, min(3, (int) ($phase['order'] ?? 1)));
                $participant_group_column = 'group_phase_' . array(1 => 'one', 2 => 'two', 3 => 'three')[$phase_order];
                $participant_groups = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT DISTINCT `{$participant_group_column}`
                        FROM `{$participant_table}`
                        WHERE competition_id = %d
                          AND `{$participant_group_column}` IS NOT NULL
                          AND `{$participant_group_column}` <> ''
                        ORDER BY `{$participant_group_column}` ASC",
                        $competition_id
                    )
                );

                $groups = array_values(array_unique(array_filter(array_merge((array) $groups, (array) $participant_groups), 'strlen')));
                sort($groups, SORT_NATURAL | SORT_FLAG_CASE);
            }
        }

        if ($phase) {
            $rounds = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT group_phase_round
                    FROM `{$match_table}`
                    WHERE competition_id = %d
                      AND competition_phase_id = %d
                      AND group_phase_round IS NOT NULL
                    ORDER BY group_phase_round ASC",
                    $competition_id,
                    (int) $phase['id']
                )
            );
            $rounds = array_values(array_filter(array_map('intval', (array) $rounds)));
        }

        $requested_round = isset($_GET['phase_round']) ? sanitize_text_field(wp_unslash($_GET['phase_round'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ('' !== $requested_round && 'all' !== $requested_round && in_array((int) $requested_round, $rounds, true)) {
            $selected_round = (int) $requested_round;
        } elseif ($phase && !empty($rounds)) {
            $nearest_round = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT group_phase_round
                    FROM `{$match_table}`
                    WHERE competition_id = %d
                      AND competition_phase_id = %d
                      AND group_phase_round IS NOT NULL
                    ORDER BY ABS(TIMESTAMPDIFF(SECOND, date_time, NOW())) ASC, date_time ASC
                    LIMIT 1",
                    $competition_id,
                    (int) $phase['id']
                )
            );
            $selected_round = in_array($nearest_round, $rounds, true) ? $nearest_round : (int) reset($rounds);
        }

        $selected_group = isset($_GET['group']) ? sanitize_text_field(wp_unslash($_GET['group'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $selected_group = 'all' === $selected_group ? '' : $selected_group;

        if (f365_cls_is_match_phase($phase)) {
            $show_matches = true;
            $tables[] = array(
                'title' => $phase['name'],
                'group' => '',
                'rows' => f365_cls_match_rows($competition_id, $phase),
            );
        } elseif ($phase && in_array($phase['phase_type'], array('with_rounds_and_groups', 'single_round_with_groups', 'mixed_groups'), true) && '' === $selected_group && !empty($groups)) {
            foreach ($groups as $group) {
                $round_label = f365_cls_round_label($selected_round);
                $tables[] = array(
                    'title' => $round_label ? $group . ' - ' . $round_label : $group,
                    'group' => $group,
                    'round' => $selected_round,
                    'rows' => f365_cls_calculate_table($competition, $phase, $group, $selected_round),
                );
            }
        } else {
            $round_label = f365_cls_round_label($selected_round);
            $tables[] = array(
                'title' => $selected_group && $round_label ? $selected_group . ' - ' . $round_label : ($phase ? $phase['name'] : ''),
                'group' => $selected_group,
                'round' => $selected_round,
                'rows' => f365_cls_calculate_table($competition, $phase, $selected_group, $selected_round),
            );
        }
    }
}
?>
<main id="primary" class="site-main single-article-page">
    <?php
    get_template_part(
        'template-parts/competition/f365-competition-header',
        null,
        array(
            'post_id' => $current_post_id,
            'sections' => $sections,
            'competition_id' => $competition_id,
            'current_section' => $current_section,
        )
    );
    ?>
    <div class="ink-grid futebol365-container">
        <section class="left-column-block" style="height: auto !important;">
            <div class="breadcrumbs-f365">
                <div class="ink-navigation breadcrumbs">
                    <?php
                    if (function_exists('yoast_breadcrumb')) {
                        yoast_breadcrumb();
                    }
                    ?>
                </div>
            </div>

            <?php if (!empty($missing_tables)): ?>
                <h5 class="no-info">Sem informação disponível</h5>
            <?php elseif (empty($competition)): ?>
                <h5 class="no-info">Sem informação disponível</h5>
            <?php else: ?>
                <?php if (!empty($phases) || !empty($groups)): ?>
                    <form class="ink-form ink-form-option" method="get">
                        <?php foreach ($_GET as $query_key => $query_value): // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                            <?php if (in_array($query_key, array('competition_phase', 'group', 'phase_round'), true) || is_array($query_value)): ?>
                                <?php continue; ?>
                            <?php endif; ?>
                            <input type="hidden" name="<?php echo esc_attr($query_key); ?>"
                                value="<?php echo esc_attr(wp_unslash($query_value)); ?>">
                        <?php endforeach; ?>
                        <div class="column-group">
                            <?php if (!empty($phases)): ?>
                                <div class="control-group all-33 tiny-100 small-100">
                                    <div class="control">
                                        <select name="competition_phase" id="id_competition_phase" onchange="this.form.submit()">
                                            <?php if ($filter_phase): ?>
                                                <option value="<?php echo esc_attr($filter_phase['name']); ?>" selected>
                                                    <?php echo esc_html($filter_phase['name']); ?>
                                                </option>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($groups)): ?>
                                <div class="control-group all-33 tiny-100 small-100">
                                    <div class="control">
                                        <select name="group" id="id_group" onchange="this.form.submit()">
                                            <option value="all" <?php selected($selected_group, ''); ?>>Grupos</option>
                                            <?php foreach ($groups as $group_option): ?>
                                                <option value="<?php echo esc_attr($group_option); ?>" <?php selected($selected_group, $group_option); ?>>
                                                    <?php echo esc_html($group_option); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($rounds)): ?>
                                <div class="control-group all-33 tiny-100 small-100">
                                    <div class="control">
                                        <select name="phase_round" id="id_phase_round" onchange="this.form.submit()">
                                            <?php foreach ($rounds as $round_option): ?>
                                                <option value="<?php echo esc_attr($round_option); ?>" <?php selected((int) $selected_round, (int) $round_option); ?>>
                                                    <?php echo esc_html(f365_cls_round_label($round_option)); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            <?php endif; ?>

                        </div>
                    </form>
                <?php endif; ?>

                <h3 class="condensed-400 headerTitle blue">Classificação</h3>

                <?php foreach ($tables as $classification_table): ?>
                    <?php if (!empty($classification_table['title'])): ?>
                        <h5 class="headerTitleSimpleBackground"><?php echo esc_html($classification_table['title']); ?></h5>
                    <?php endif; ?>

                    <?php
                    if ($show_matches) {
                        f365_cls_render_match_table($classification_table['rows']);
                    } else {
                        $classification_group = (string) $classification_table['group'];
                        f365_cls_render_table($competition_id, $phase ? (int) $phase['id'] : 0, $classification_group, $classification_table['rows']);
                        f365_cls_render_place_notes($competition_id, $phase ? (int) $phase['id'] : 0, $classification_group);
                    }
                    ?>
                <?php endforeach; ?>
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
