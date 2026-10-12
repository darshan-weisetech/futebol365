<?php
/* Template Name: Recordes Page Template */

defined('ABSPATH') || exit;

global $wpdb;

$table_transfer = $wpdb->prefix . 'transfer_transfer';
$table_player = $wpdb->prefix . 'person_player';
$table_person = $wpdb->prefix . 'person_person';
$table_teamstep = $wpdb->prefix . 'team_teamstep';
$table_team = $wpdb->prefix . 'team_team';
$table_country = $wpdb->prefix . 'country_country';

if (!function_exists('f365_recordes_position_label')) {
    function f365_recordes_position_label($position)
    {
        $map = [
            'goalkeeper' => 'Guarda-redes',
            'defender' => 'Defesa',
            'midfielder' => 'Médio',
            'forward' => 'Avançado',
            'guarda-redes' => 'Guarda-redes',
            'defesa' => 'Defesa',
            'medio' => 'Médio',
            'médio' => 'Médio',
            'avancado' => 'Avançado',
            'avançado' => 'Avançado',
        ];

        $key = strtolower(trim((string) $position));
        return $map[$key] ?? $position;
    }
}

if (!function_exists('f365_recordes_age')) {
    function f365_recordes_age($birth_date)
    {
        if (empty($birth_date) || $birth_date === '0000-00-00') {
            return '';
        }

        try {
            $birth = new DateTime($birth_date);
            $today = new DateTime('today');
            return (string) $birth->diff($today)->y;
        } catch (Exception $e) {
            return '';
        }
    }
}

if (!function_exists('f365_recordes_money')) {
    function f365_recordes_money($value)
    {
        if ($value === '' || $value === null) {
            return '';
        }

        return number_format((float) $value, 0, ',', '.') . '&nbsp;&euro;';
    }
}

if (!function_exists('f365_recordes_team_url')) {
    function f365_recordes_team_url($team_id, $edition, $modality, $gender, $step)
    {
        if (!$team_id) {
            return '#';
        }

        return add_query_arg(
            [
                'edition' => $edition,
                'modality' => $modality ?: 'football',
                'gender' => $gender ?: 'male',
                'step' => $step ?: 'principal',
            ],
            home_url('/equipa/' . (int) $team_id . '/jogos/')
        );
    }
}

$sel_country = isset($_GET['country']) ? (int) $_GET['country'] : 0;
$sel_team = isset($_GET['team']) ? (int) $_GET['team'] : 0;
$sel_option = sanitize_text_field($_GET['transfer_option'] ?? 'trans_op_all');

$countries = $wpdb->get_results("
    SELECT id, shortname
    FROM {$table_country}
    ORDER BY shortname ASC
");

if ($sel_country) {
    $teams_in_country = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT id, shortname
            FROM {$table_team}
            WHERE country_id = %d
            ORDER BY shortname ASC
            ",
            $sel_country
        )
    );
} elseif ($sel_team) {
    $teams_in_country = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT id, shortname
            FROM {$table_team}
            WHERE id = %d
            ",
            $sel_team
        )
    );
} else {
    $teams_in_country = [];
}

$where = [
    "tr.value REGEXP '^0*[1-9][0-9]*$'",
    "tr.transfer_type LIKE '%D%'",
];

$params = [];
$filter_joins = [];

if ($sel_country || $sel_team) {
    $filter_joins[] = "LEFT JOIN {$table_teamstep} origin_filter_ts ON origin_filter_ts.id = tr.team_origin_id";
    $filter_joins[] = "LEFT JOIN {$table_team} origin_filter_team ON origin_filter_team.id = origin_filter_ts.team_id";
    $filter_joins[] = "LEFT JOIN {$table_teamstep} dest_filter_ts ON dest_filter_ts.id = tr.team_destination_id";
    $filter_joins[] = "LEFT JOIN {$table_team} dest_filter_team ON dest_filter_team.id = dest_filter_ts.team_id";
}

if ($sel_country) {
    $where[] = '(origin_filter_team.country_id = %d OR dest_filter_team.country_id = %d)';
    $params[] = $sel_country;
    $params[] = $sel_country;
}

if ($sel_team) {
    $where[] = '(origin_filter_team.id = %d OR dest_filter_team.id = %d)';
    $params[] = $sel_team;
    $params[] = $sel_team;
}

if ($sel_option === 'trans_op_in') {
    if ($sel_team) {
        $where[] = 'dest_filter_team.id = %d';
        $params[] = $sel_team;
    }

    if ($sel_country) {
        $where[] = 'dest_filter_team.country_id = %d';
        $params[] = $sel_country;
    }
} elseif ($sel_option === 'trans_op_out') {
    if ($sel_team) {
        $where[] = 'origin_filter_team.id = %d';
        $params[] = $sel_team;
    }

    if ($sel_country) {
        $where[] = 'origin_filter_team.country_id = %d';
        $params[] = $sel_country;
    }
}

$where_sql = implode(' AND ', $where);
$filter_joins_sql = implode("\n        ", $filter_joins);

$sql = "
    SELECT
        tr.id,
        tr.transfer_date,
        tr.value,

        pl.id AS player_django_id,
        pl.position,
        person.shortname AS player_name,
        person.birth_date,

        nat.id AS nationality_id,
        nat.shortname AS nationality_name,

        origin_team.id AS origin_team_id,
        origin_team.shortname AS origin_team_name,
        origin_country.shortname AS origin_country_name,
        origin_ts.edition AS origin_edition,
        origin_ts.modality AS origin_modality,
        origin_ts.gender AS origin_gender,
        origin_ts.step AS origin_step,

        dest_team.id AS dest_team_id,
        dest_team.shortname AS dest_team_name,
        dest_country.shortname AS dest_country_name,
        dest_ts.edition AS dest_edition,
        dest_ts.modality AS dest_modality,
        dest_ts.gender AS dest_gender,
        dest_ts.step AS dest_step

    FROM (
        SELECT
            tr.id,
            tr.transfer_date,
            tr.value,
            tr.player_id,
            tr.team_origin_id,
            tr.team_destination_id,
            CAST(tr.value AS UNSIGNED) AS value_numeric
        FROM {$table_transfer} tr
        {$filter_joins_sql}
        WHERE {$where_sql}
        ORDER BY value_numeric DESC, tr.transfer_date DESC, tr.id DESC
        LIMIT 30
    ) tr

    LEFT JOIN {$table_player} pl
        ON pl.id = tr.player_id

    LEFT JOIN {$table_person} person
        ON person.id = pl.person_id

    LEFT JOIN {$table_country} nat
        ON nat.id = person.nationality_id

    LEFT JOIN {$table_teamstep} origin_ts
        ON origin_ts.id = tr.team_origin_id

    LEFT JOIN {$table_team} origin_team
        ON origin_team.id = origin_ts.team_id

    LEFT JOIN {$table_country} origin_country
        ON origin_country.id = origin_team.country_id

    LEFT JOIN {$table_teamstep} dest_ts
        ON dest_ts.id = tr.team_destination_id

    LEFT JOIN {$table_team} dest_team
        ON dest_team.id = dest_ts.team_id

    LEFT JOIN {$table_country} dest_country
        ON dest_country.id = dest_team.country_id

    ORDER BY tr.value_numeric DESC, tr.transfer_date DESC, tr.id DESC
";

$rows = !empty($params)
    ? $wpdb->get_results($wpdb->prepare($sql, $params))
    : $wpdb->get_results($sql);

$bg_field = get_field('bg_image', 'option');
$bg_image_url = $bg_field['url'] ?? futebol365_fallback_image();

get_header();
?>

<script>
    jQuery(document).ready(function ($) {
        $('#id_country').on('change', function () {
            $('#id_team').val('');
            $('#transfers-form').submit();
        });

        $('#id_transfer_option, #id_team').on('change', function () {
            $('#transfers-form').submit();
        });
    });
</script>

<div class="ink-grid vertical-space">
    <?php get_template_part('template-parts/template-offer-widget'); ?>

    <div class="ink-header-f365 competicoes"
        style="background-image: linear-gradient(to left, rgba(250, 250, 250, 0) 40%, rgba(0, 0, 0, 1)), url(<?php echo esc_url($bg_image_url); ?>);">
        <div class="column-group">
            <div class="align-left all-60 medium-100 small-100 tiny-100 banner-left-content">
                <div class="headerdata">
                    <a href="#">
                        <h1><?php the_title(); ?></h1>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="nav-trigger" id="nav-trigger">
        <span>Menu</span>
    </div>

    <?php
    $current_id = get_the_ID();
    ?>

    <nav class="nav-main" id="nav-main">
        <ul>
            <?php
            // Parent page (transferencias)
            $parent = get_page_by_path('transferencias');

            if ($parent) {

                // Active class for parent
                $parent_active = ($current_id == $parent->ID) ? 'active' : '';

                // Últimas (parent)
                echo '<li>';
                echo '<a class="' . $parent_active . '" href="' . get_permalink($parent->ID) . '">' . esc_html__('Últimas', 'f365') . '</a>';
                echo '</li>';

                // Child pages
                $children = get_pages([
                    'child_of' => $parent->ID,
                    'sort_column' => 'menu_order'
                ]);

                foreach ($children as $child) {

                    $child_active = ($current_id == $child->ID) ? 'active' : '';

                    echo '<li>';
                    echo '<a class="' . $child_active . '" href="' . get_permalink($child->ID) . '">' . esc_html__('Recordes', 'f365') . '</a>';
                    echo '</li>';
                }
            }
            ?>
        </ul>
    </nav>
</div>

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

        <form class="ink-form ink-form-option" method="get" name="transfers_form" id="transfers-form">
            <div class="column-group">

                <div class="control-group all-33 tiny-100 small-100">
                    <div class="control">
                        <select name="country" id="id_country">
                            <option value="">Países</option>
                            <?php foreach ($countries as $country): ?>
                                <option value="<?php echo (int) $country->id; ?>" <?php selected($sel_country, (int) $country->id); ?>>
                                    <?php echo esc_html($country->shortname); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="control-group all-33 tiny-100 small-100">
                    <div class="control">
                        <select name="team" id="id_team">
                            <option value="">Equipas</option>
                            <?php foreach ($teams_in_country as $team): ?>
                                <option value="<?php echo (int) $team->id; ?>" <?php selected($sel_team, (int) $team->id); ?>>
                                    <?php echo esc_html($team->shortname); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php if ($sel_country || $sel_team): ?>
                    <div class="control-group all-33 tiny-100 small-100">
                        <div class="control">
                            <select name="transfer_option" id="id_transfer_option">
                                <option value="trans_op_all" <?php selected(in_array($sel_option, ['', 'trans_op_all'], true)); ?>>Opções</option>
                                <option value="trans_op_in" <?php selected($sel_option, 'trans_op_in'); ?>>Entradas</option>
                                <option value="trans_op_out" <?php selected($sel_option, 'trans_op_out'); ?>>Saídas</option>
                            </select>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </form>

        <div class="overflow-auto">
            <table class="ink-table alternating ink-table-f365">
                <thead>
                    <tr>
                        <th class="align-left"></th>
                        <th class="align-left hide-small hide-tiny">Data</th>
                        <th class="align-left">Jogador</th>
                        <th class="align-left hide-small hide-tiny">Origem</th>
                        <th class="align-left">Destino</th>
                        <th class="align-left hide-large hide-small hide-tiny">Valor</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!empty($rows)): ?>
                        <?php foreach ($rows as $index => $r): ?>
                            <?php
                            $rank = $index + 1;

                            $player_url = home_url('/jogador/' . (int) $r->player_django_id . '/');
                            $origin_url = f365_recordes_team_url(
                                $r->origin_team_id,
                                $r->origin_edition,
                                $r->origin_modality,
                                $r->origin_gender,
                                $r->origin_step
                            );
                            $dest_url = f365_recordes_team_url(
                                $r->dest_team_id,
                                $r->dest_edition,
                                $r->dest_modality,
                                $r->dest_gender,
                                $r->dest_step
                            );

                            $date_short = $r->transfer_date ? date_i18n('d-m-y', strtotime($r->transfer_date)) : '';

                            $position = f365_recordes_position_label($r->position);
                            $age = f365_recordes_age($r->birth_date);
                            ?>
                            <tr>
                                <td class="align-center">
                                    <?php echo (int) $rank; ?>
                                </td>

                                <td class="hide-small hide-tiny">
                                    <?php echo esc_html($date_short); ?>
                                </td>

                                <td>
                                    <?php if (!empty($r->nationality_id)): ?>
                                        <i
                                            class="hide-tiny flag-icon quarter-right-space flag-icon-<?php echo (int) $r->nationality_id; ?>"></i>
                                    <?php endif; ?>

                                    <a href="<?php echo esc_url($player_url); ?>">
                                        <?php echo esc_html($r->player_name); ?>
                                    </a>

                                    <?php if ($position || $age): ?>
                                        <div class="medium hide-small hide-tiny">
                                            <?php
                                            $parts = [];

                                            if ($position) {
                                                $parts[] = $position;
                                            }

                                            if ($age) {
                                                $parts[] = $age . ' anos';
                                            }

                                            echo esc_html(implode(', ', $parts));
                                            ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="medium hide-xlarge hide-large hide-medium">
                                        <?php echo esc_html($date_short); ?>
                                    </div>
                                </td>

                                <td class="hide-small hide-tiny">
                                    <?php if (!empty($r->origin_team_name)): ?>
                                        <a href="<?php echo esc_url($origin_url); ?>">
                                            <?php echo esc_html($r->origin_team_name); ?>
                                        </a>
                                    <?php endif; ?>

                                    <div class="medium">
                                        <?php echo esc_html($r->origin_country_name); ?>
                                    </div>
                                </td>

                                <td>
                                    <?php if (!empty($r->dest_team_name)): ?>
                                        <a href="<?php echo esc_url($dest_url); ?>">
                                            <?php echo esc_html($r->dest_team_name); ?>
                                        </a>
                                    <?php endif; ?>

                                    <div class="medium hide-small hide-tiny">
                                        <?php echo esc_html($r->dest_country_name); ?>
                                    </div>

                                    <div class="medium hide-xlarge hide-large hide-medium">
                                        <?php echo wp_kses_post(f365_recordes_money($r->value)); ?>
                                    </div>
                                </td>

                                <td class="align-left hide-large hide-small hide-tiny">
                                    <?php echo wp_kses_post(f365_recordes_money($r->value)); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">Sem resultados para os filtros seleccionados.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

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

<?php get_footer(); ?>