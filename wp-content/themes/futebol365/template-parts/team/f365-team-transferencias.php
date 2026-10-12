
<?php
/**
 * Team Transferências — /equipa/{id}/transferencias/
 *
 * @package Futebol365
 */

defined('ABSPATH') || exit;
global $wpdb;

$current_post_id = get_the_ID();
$sections        = function_exists('futebol365_get_team_sections') ? futebol365_get_team_sections() : [];
$team_id = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT legacy_id
         FROM {$wpdb->posts}
         WHERE ID = %d",
        $current_post_id
    )
);

/* ============================================================
 * Helpers
 * ============================================================ */

if (!function_exists('f365_tr_flag')) {
    /** flag-icon markup keyed by numeric country id. */
    function f365_tr_flag(int $country_id, string $title = ''): string
    {
        if (!$country_id) {
            return '';
        }
        return '<i class="hide-tiny flag-icon quarter-right-space flag-icon-' . (int) $country_id . '" title="' . esc_attr($title) . '"></i>';
    }
}

if (!function_exists('f365_tr_type_longname')) {
    function f365_tr_type_longname(string $t): string
    {
        if ('D' === $t) {
            return 'Definitivo';
        }
        if ('E' === $t) {
            return 'Empréstimo';
        }
        return $t;
    }
}

if (!function_exists('f365_tr_value')) {
    function f365_tr_value(string $v): string
    {
        $v = trim($v);
        if ('' === $v) {
            return '';
        }
        return is_numeric($v) ? number_format((float) $v, 0, ',', '.') . ' €' : $v;
    }
}

if (!function_exists('f365_get_relational_team_transfers')) {
    /**
     * Fetch a team's in/out transfers from relational tables.
     *
     * @return array{in:array,out:array}
     */
    function f365_get_relational_team_transfers(int $django_team, string $edition, string $step, string $gender, string $modality): array
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $prev_edition = (string) ((int) $edition - 1);

        // Fetch current teamstep id
        $current_ts = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$p}team_teamstep
                 WHERE team_id = %d AND edition = %s AND step = %s AND gender = %s AND modality = %s
                 LIMIT 1",
                $django_team,
                $edition,
                $step,
                $gender,
                $modality
            )
        );

        if (!$current_ts) {
            return ['in' => [], 'out' => []];
        }

        // Optimized Shared Joins
        $player_join = "INNER JOIN {$p}person_player pl ON pl.id = t.player_id
                        INNER JOIN {$p}person_person pe ON pe.id = pl.person_id
                        LEFT  JOIN {$p}country_country pc ON pc.id = pe.nationality_id";

        // Entradas (In)
        $in = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.id, t.player_id, t.transfer_date, t.transfer_type, t.value,
                        pe.shortname AS p_name, pe.nationality_id AS p_nat, pc.shortname AS p_nat_name,
                        COALESCE(otm.id, tmp.id)                 AS team_id,
                        COALESCE(otm.shortname, tmp.shortname)   AS team_name,
                        COALESCE(otm.country_id, tmp.country_id) AS team_country
                 FROM {$p}transfer_transfer t
                 {$player_join}
                 LEFT JOIN {$p}team_teamstep ots ON ots.id = t.team_origin_id
                 LEFT JOIN {$p}team_team otm     ON otm.id = ots.team_id
                 LEFT JOIN {$p}team_team tmp     ON tmp.id = t.tmp_origin_team_id
                 WHERE t.team_destination_id = %d
                 ORDER BY t.transfer_date DESC
                 LIMIT 200",
                $current_ts
            ),
            OBJECT
        );

        // Saídas (Out)
        $out = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.id, t.player_id, t.transfer_date, t.transfer_type, t.value,
                        pe.shortname AS p_name, pe.nationality_id AS p_nat, pc.shortname AS p_nat_name,
                        dtm.id AS team_id, dtm.shortname AS team_name, dtm.country_id AS team_country
                 FROM {$p}transfer_transfer t
                 {$player_join}
                 LEFT JOIN {$p}team_teamstep dts ON dts.id = t.team_destination_id
                 LEFT JOIN {$p}team_team dtm     ON dtm.id = dts.team_id
                 LEFT JOIN {$p}team_teamstep ots ON ots.id = t.team_origin_id
                 WHERE (
                        ots.team_id = %d AND ots.step = %s AND ots.modality = %s AND ots.gender = %s
                        AND ots.edition = %s AND dts.edition = %s
                    ) OR (
                        t.team_origin_id = %d AND dts.edition = %s
                    )
                 ORDER BY t.transfer_date DESC
                 LIMIT 200",
                $django_team,
                $step,
                $modality,
                $gender,
                $prev_edition,
                $edition,
                $current_ts,
                $edition
            ),
            OBJECT
        );

        return [
            'in'  => (array) $in,
            'out' => (array) $out,
        ];
    }
}

/* ============================================================
 * Resolve variables & query dimensions
 * ============================================================ */

$post_id = $args['post_id'] ?? get_the_ID();
if (function_exists('f365_get_current_team_post')) {
    $t = f365_get_current_team_post();
    if ($t) {
        $post_id = $t->ID;
    }
}

$prefix      = $wpdb->prefix;
$django_team = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT legacy_id
         FROM {$wpdb->posts}
         WHERE ID = %d",
        $post_id
    )
);

$step        = isset($_GET['step']) ? sanitize_text_field(wp_unslash($_GET['step'])) : 'principal';
$gender      = isset($_GET['gender']) ? sanitize_text_field(wp_unslash($_GET['gender'])) : 'male';
$modality    = isset($_GET['modality']) ? sanitize_text_field(wp_unslash($_GET['modality'])) : 'football';
$edition     = isset($_GET['edition']) && '' !== $_GET['edition'] ? sanitize_text_field(wp_unslash($_GET['edition'])) : '';
$cache_group = 'futebol365_transfers';

if ('' === $edition && $django_team) {
    // Check if configuration fallback is cached in standard object cache
    $edition_cache_key = 'f365_team_latest_ed_' . $django_team . '_' . md5($step.$gender.$modality);
    $edition           = wp_cache_get($edition_cache_key, $cache_group);

    if (false === $edition) {
        $edition = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT edition FROM {$prefix}team_teamstep
                 WHERE team_id = %d AND step = %s AND gender = %s AND modality = %s
                 ORDER BY ( edition + 0 ) DESC LIMIT 1",
                $django_team,
                $step,
                $gender,
                $modality
            )
        );
        if ('' === $edition) {
            $edition = (string) gmdate('Y');
        }
        wp_cache_set($edition_cache_key, $edition, $cache_group, DAY_IN_SECONDS);
    }
}

$current_section = get_query_var('section', 'transferencias') ?: 'transferencias';
$sections        = function_exists('futebol365_get_team_sections') ? futebol365_get_team_sections() : [];
if ($sections && !isset($sections[$current_section])) {
    $current_section = 'transferencias';
}

/* ============================================================
 * Execution Pass — Object Cache Fetch (24 Hours expiration)
 * ============================================================ */
$cache_key = 'f365_team_trans_rel_' . $django_team . '_' . md5($edition . '|' . $step . '|' . $gender . '|' . $modality);
$pack      = wp_cache_get($cache_key, $cache_group);

if (false === $pack) {
    $pack = $django_team
        ? f365_get_relational_team_transfers($django_team, $edition, $step, $gender, $modality)
        : ['in' => [], 'out' => []];
    wp_cache_set($cache_key, $pack, $cache_group, DAY_IN_SECONDS);
}

$transfers_in  = (array) $pack['in'];
$transfers_out = (array) $pack['out'];
$has_any       = ! empty($transfers_in) || ! empty($transfers_out);

get_header();
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
                'team_id'         => $team_id,
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

            <?php if ($has_any): ?>
                <?php
                $blocks = [
                    ['rows' => $transfers_in, 'label' => 'Entradas', 'header' => 'Origem'],
                    ['rows' => $transfers_out, 'label' => 'Saídas', 'header' => 'Destino'],
                ];
                foreach ($blocks as $block):
                    if (empty($block['rows'])) {
                        continue;
                    }
                    ?>
                    <h4 class="condensed-400 headerTitle blue"><?php echo esc_html($block['label']); ?></h4>
                    <table class="ink-table alternating ink-table-f365 all-100">
                        <thead>
                            <tr>
                                <th class="align-left all-15 hide-tiny hide-small">Data</th>
                                <th class="align-left all-30 small-50 tiny-50">Jogador</th>
                                <th class="align-left all-30 small-50 tiny-50"><?php echo esc_html($block['header']); ?></th>
                                <th class="align-left all-10 hide-tiny hide-small">Tipo</th>
                                <th class="align-left all-15 hide-tiny hide-small">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ($block['rows'] as $row):
                                $date_ts   = $row->transfer_date ? strtotime($row->transfer_date) : 0;
                                $date      = $date_ts ? wp_date('d-m-Y', $date_ts) : '';
                                $player_id = (int) $row->player_id;
                                $team_id   = (int) $row->team_id;
                                $type      = (string) $row->transfer_type;
                                $value     = f365_tr_value((string) $row->value);
                                $p_url     = user_trailingslashit(home_url('jogador/' . $player_id));
                                $tm_url    = $team_id ? user_trailingslashit(home_url('equipa/' . $team_id)) : '';
                                ?>
                                <tr>
                                    <td class="align-left hide-tiny hide-small"><?php echo esc_html($date); ?></td>
                                    <td class="align-left">
                                        <?php echo f365_tr_flag((int) $row->p_nat, (string) $row->p_nat_name); ?>
                                        <a href="<?php echo esc_url($p_url); ?>"><?php echo esc_html($row->p_name); ?></a>
                                        <div class="medium hide-xlarge hide-large hide-medium"><?php echo esc_html($date); ?></div>
                                    </td>
                                    <td class="align-left">
                                        <?php echo f365_tr_flag((int) $row->team_country, (string) $row->team_name); ?>
                                        <?php if ($tm_url): ?>
                                            <a href="<?php echo esc_url($tm_url); ?>"><?php echo esc_html($row->team_name); ?></a>
                                        <?php else: ?>
                                            <?php echo esc_html($row->team_name); ?>
                                        <?php endif; ?>
                                        <div class="medium hide-xlarge hide-large hide-medium">
                                            <?php echo esc_html(f365_tr_type_longname($type)); ?><?php echo $value ? ' - ' . esc_html($value) : ''; ?>
                                        </div>
                                    </td>
                                    <td class="align-left hide-tiny hide-small"><?php echo esc_html(f365_tr_type_longname($type)); ?></td>
                                    <td class="align-left hide-tiny hide-small"><?php echo esc_html($value); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>

            <?php else: ?>
                <h4 class="condensed-400 headerTitle blue">Transferências</h4>
                <h5 class="no-info">Sem informação disponível</h5>
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
