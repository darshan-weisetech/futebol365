<?php
/**
 * Archive template for f365_ranking posts.
 *
 * @package Futebol365
 */

defined('ABSPATH') || exit;

global $wpdb;

$ranking_table    = $wpdb->prefix . 'ranking_nationalranking';
$team_table       = $wpdb->prefix . 'team_team';
$federation_table = $wpdb->prefix . 'federation_federation';
$country_table    = $wpdb->prefix . 'country_country';

$current_page_url = home_url('/rank-seleccoes/');

$filter_federation = isset($_GET['federation']) && $_GET['federation'] !== ''
    ? absint($_GET['federation'])
    : 0;

$filter_month = isset($_GET['ranking_month']) && $_GET['ranking_month'] !== ''
    ? absint($_GET['ranking_month'])
    : 0;

$filter_year = isset($_GET['ranking_year']) && $_GET['ranking_year'] !== ''
    ? absint($_GET['ranking_year'])
    : 0;

$order = isset($_GET['order']) ? sanitize_key($_GET['order']) : 'position';
$page = !empty($_GET['pagina'])
    ? absint($_GET['pagina'])
    : 1;

$current_page = !empty($_GET['pagina']) ? absint($_GET['pagina']) : 1;
$current_page = max(1, $current_page);

$per_page = 20;
$offset   = ($current_page - 1) * $per_page;

$month_names = [
    1  => 'Janeiro',
    2  => 'Fevereiro',
    3  => 'Março',
    4  => 'Abril',
    5  => 'Maio',
    6  => 'Junho',
    7  => 'Julho',
    8  => 'Agosto',
    9  => 'Setembro',
    10 => 'Outubro',
    11 => 'Novembro',
    12 => 'Dezembro',
];

if (!$filter_year) {
    $filter_year = (int) $wpdb->get_var("SELECT MAX(year) FROM {$ranking_table}");
}

if (!$filter_month && $filter_year) {
    $filter_month = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT MAX(month) FROM {$ranking_table} WHERE year = %d",
            $filter_year
        )
    );
}

$allowed_order = [
    'position'   => 'r.position ASC',
    '-variation' => 'r.variation DESC, r.position ASC',
    'variation'  => 'r.variation ASC, r.position ASC',
];

$order_sql = isset($allowed_order[$order]) ? $allowed_order[$order] : $allowed_order['position'];

$where  = [];
$params = [];

if ($filter_federation) {
    $where[]  = 't.federation_id = %d';
    $params[] = $filter_federation;
}

if ($filter_month) {
    $where[]  = 'r.month = %d';
    $params[] = $filter_month;
}

if ($filter_year) {
    $where[]  = 'r.year = %d';
    $params[] = $filter_year;
}

if ($order === 'variation') {
    $where[] = 'r.variation < 0';
} elseif ($order === '-variation') {
    $where[] = 'r.variation > 0';
}

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$nav_filter_args = [];

if ($filter_federation) {
    $nav_filter_args['federation'] = $filter_federation;
}

if ($filter_month) {
    $nav_filter_args['ranking_month'] = $filter_month;
}

if ($filter_year) {
    $nav_filter_args['ranking_year'] = $filter_year;
}

$federations = $wpdb->get_results("
    SELECT id, shortname
    FROM {$federation_table}
    WHERE federation_type = 'confederation'
    ORDER BY shortname ASC
");

$months = $wpdb->get_col("
    SELECT DISTINCT month
    FROM {$ranking_table}
    WHERE month IS NOT NULL
    ORDER BY month ASC
");

$years = $wpdb->get_col("
    SELECT DISTINCT year
    FROM {$ranking_table}
    WHERE year IS NOT NULL
    ORDER BY year DESC
");

$count_sql = "
    SELECT COUNT(*)
    FROM {$ranking_table} r
    INNER JOIN {$team_table} t ON t.id = r.team_id
    LEFT JOIN {$federation_table} f ON f.id = t.federation_id
    {$where_sql}
";

$total_rows = !empty($params)
    ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $params))
    : (int) $wpdb->get_var($count_sql);

$data_sql = "
    SELECT
        r.position,
        r.points,
        r.variation,
        r.month,
        r.year,
        t.id AS team_id,
        t.shortname AS team_name,
        t.country_id,
        f.id AS federation_id,
        f.shortname AS federation_name,
        c.slug AS country_slug
    FROM {$ranking_table} r
    INNER JOIN {$team_table} t ON t.id = r.team_id
    LEFT JOIN {$federation_table} f ON f.id = t.federation_id
    LEFT JOIN {$country_table} c ON c.id = t.country_id
    {$where_sql}
    ORDER BY {$order_sql}
    LIMIT %d OFFSET %d
";

$data_params   = $params;
$data_params[] = $per_page;
$data_params[] = $offset;

$rankings = $wpdb->get_results($wpdb->prepare($data_sql, $data_params));

get_header();
?>

<div class="ink-grid vertical-space f365-ranking-page f365-ranking-fifa">
    <?php get_template_part('template-parts/template-offer-widget'); ?>

    <div class="ink-header-f365 rankings">
        <div class="column-group">
            <div class="align-left all-60 medium-100 small-100 tiny-100 banner-left-content">
                <div class="headerdata">
                    <h1 class="multimedia-title"><?php esc_html_e('Ranking FIFA', 'futebol365'); ?></h1>
                </div>
            </div>
        </div>
    </div>

    <div class="nav-trigger" id="nav-trigger"><?php esc_html_e('Menu', 'futebol365'); ?></div>


	<?php
	$base_rank_url = home_url('/rank-seleccoes/');

	$nav_filter_args = [];

	if (!empty($filter_federation)) {
		$nav_filter_args['federation'] = $filter_federation;
	}

	if (!empty($filter_month)) {
		$nav_filter_args['ranking_month'] = $filter_month;
	}

	if (!empty($filter_year)) {
		$nav_filter_args['ranking_year'] = $filter_year;
	}

	$current_args = $nav_filter_args;

	$up_args = array_merge($nav_filter_args, [
		'order' => '-variation',
	]);

	$down_args = array_merge($nav_filter_args, [
		'order' => 'variation',
	]);
	?>
    <nav class="nav-main" id="nav-main">
    <ul>
        <li>
			<a <?php echo ($order !== 'variation' && $order !== '-variation') ? 'class="active"' : ''; ?> href="<?php echo esc_url( home_url('/rank-seleccoes/') ); ?>">
				<?php esc_html_e('Ranking Atual', 'futebol365'); ?>
			</a>
        </li>

        <li>
            <a
                <?php echo ($order === '-variation') ? 'class="active"' : ''; ?>
                href="<?php echo esc_url(add_query_arg($up_args, $base_rank_url)); ?>"
            >
                <?php esc_html_e('Maiores Subidas', 'futebol365'); ?>
            </a>
        </li>

        <li>
            <a
                <?php echo ($order === 'variation') ? 'class="active"' : ''; ?>
                href="<?php echo esc_url(add_query_arg($down_args, $base_rank_url)); ?>"
            >
                <?php esc_html_e('Maiores Descidas', 'futebol365'); ?>
            </a>
        </li>

        <?php if ($order === 'variation' || $order === '-variation') : ?>
            <li>
                <a href="<?php echo esc_url(add_query_arg($current_args, $base_rank_url)); ?>">
                    <?php esc_html_e('Ranking Completo', 'futebol365'); ?>
                </a>
            </li>
        <?php endif; ?>
    </ul>
</nav>
<nav class="nav-mobile" id="nav-mobile"></nav>
</div>

<div class="ink-grid futebol365-container">
    <section class="left-column-block">
        <div class="breadcrumbs-f365">
            <?php if (function_exists('yoast_breadcrumb')) : ?>
                <nav class="ink-navigation breadcrumbs">
                    <?php yoast_breadcrumb(); ?>
                </nav>
            <?php endif; ?>
        </div>

        <form class="ink-form ink-form-option" method="get" name="ranking_form" id="ranking-form" action="<?php echo esc_url($current_page_url); ?>">
            <input type="hidden" name="order" value="<?php echo esc_attr($order); ?>">

            <div class="column-group">
                <div class="control-group all-33 tiny-100 small-100">
                    <div class="control">
                        <select name="federation" id="id_federation" onchange="this.form.submit()">
                            <option value="">Confederação</option>

                            <?php foreach ($federations as $federation) : ?>
                                <option value="<?php echo esc_attr($federation->id); ?>" <?php selected($filter_federation, $federation->id); ?>>
                                    <?php echo esc_html($federation->shortname); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="control-group all-33 tiny-100 small-100">
                    <div class="control">
                        <select name="ranking_month" id="id_month" onchange="this.form.submit()">
                            <option value="">Mês</option>

                            <?php foreach ($months as $month) : ?>
                                <option value="<?php echo esc_attr($month); ?>" <?php selected($filter_month, $month); ?>>
                                    <?php echo esc_html($month_names[(int) $month] ?? $month); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="control-group all-33 tiny-100 small-100">
                    <div class="control">
                        <select name="ranking_year" id="id_year" onchange="this.form.submit()">
                            <option value="">Ano</option>

                            <?php foreach ($years as $year) : ?>
                                <option value="<?php echo esc_attr($year); ?>" <?php selected($filter_year, $year); ?>>
                                    <?php echo esc_html($year); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </form>

        <?php if (!empty($rankings)) : ?>
            <table class="ink-table alternating ink-table-f365 double-bottom-space">
                <thead>
                    <tr>
                        <th class="align-left">Posição</th>
                        <th class="align-left">Seleção</th>
                        <th class="align-left hide-medium hide-small hide-tiny">Confederação</th>
                        <th class="align-left">Pontos</th>
                        <th colspan="2" class="align-left hide-small hide-tiny">Variação</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($rankings as $row) : ?>
                        <?php
                        $variation_class = 'equal';
                        $variation_icon  = 'icon-equal';

                        if ((int) $row->variation > 0) {
                            $variation_class = 'ranking-up';
                            $variation_icon  = 'icon-chevron-up';
                        } elseif ((int) $row->variation < 0) {
                            $variation_class = 'ranking-down';
                            $variation_icon  = 'icon-chevron-down';
                        }

                        $federation_link_args = [
                            'federation'    => $row->federation_id,
                            'ranking_month' => $filter_month,
                            'ranking_year'  => $filter_year,
                        ];
                        ?>
                        <tr>
                            <td><?php echo esc_html($row->position); ?></td>

                            <td>
                                <i class="hide-tiny flag-icon quarter-right-space flag-icon-<?php echo esc_attr($row->country_id); ?>"></i>
                                <a href="<?php echo esc_url(home_url('/equipa/' . $row->team_id . '/')); ?>">
                                    <?php echo esc_html($row->team_name); ?>
                                </a>
                            </td>

                            <td class="hide-medium hide-small hide-tiny">
                                <?php if (!empty($row->federation_id)) : ?>
                                    <a href="<?php echo esc_url(add_query_arg($federation_link_args, $current_page_url)); ?>">
                                        <?php echo esc_html($row->federation_name); ?>
                                    </a>
                                <?php endif; ?>
                            </td>

                            <td><?php echo esc_html($row->points); ?></td>

                            <td class="hide-small hide-tiny"><?php echo esc_html($row->variation); ?></td>

                            <td class="hide-small hide-tiny">
                                <svg class="ranking-variation-icon <?php echo esc_attr($variation_class); ?>" width="14" height="14" aria-hidden="true" focusable="false">
                                    <use href="#<?php echo esc_attr($variation_icon); ?>"></use>
                                </svg>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
            $total_pages = (int) ceil($total_rows / $per_page);

            $pagination_args = [];

if (!empty($filter_federation)) {
    $pagination_args['federation'] = $filter_federation;
}

if ($order !== 'position') {
    $pagination_args['order'] = $order;
}

unset($pagination_args['ranking_month'], $pagination_args['ranking_year'], $pagination_args['pagina']);

            if ($order !== 'position') {
                $pagination_args['order'] = $order;
            }

            $make_page_url = function ($page_number) use ($current_page_url, $pagination_args) {
                return add_query_arg(
                    array_merge($pagination_args, ['pagina' => (int) $page_number]),
                    $current_page_url
                );
            };

            $visible_pages = 3;
            $start_page = max(1, $current_page - 1);
            $end_page = min($total_pages, $start_page + $visible_pages - 1);

            if (($end_page - $start_page + 1) < $visible_pages) {
                $start_page = max(1, $end_page - $visible_pages + 1);
            }
            ?>

            <?php if ($total_pages > 1) : ?>
                <div class="all-100">
                    <div class="paginacao-new">
                        <nav class="ink-navigation">
                            <ul class="pagination">
                                <?php if ($current_page > 1) : ?>
                                    <li class="hide-tiny hide-small">
                                        <a href="<?php echo esc_url($make_page_url($current_page - 1)); ?>">&laquo; Anterior</a>
                                    </li>
                                <?php endif; ?>

                                <?php if ($start_page > 1) : ?>
                                    <li><a href="<?php echo esc_url($make_page_url(1)); ?>">1</a></li>
                                <?php endif; ?>

                                <?php if ($start_page > 2) : ?>
                                    <li><span>...</span></li>
                                <?php endif; ?>

                                <?php for ($i = $start_page; $i <= $end_page; $i++) : ?>
                                    <?php if ($i === $current_page) : ?>
                                        <li><span aria-current="page" class="active"><?php echo esc_html($i); ?></span></li>
                                    <?php else : ?>
                                        <li><a href="<?php echo esc_url($make_page_url($i)); ?>"><?php echo esc_html($i); ?></a></li>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <?php if ($end_page < $total_pages - 1) : ?>
                                    <li><span>...</span></li>
                                <?php endif; ?>

                                <?php if ($end_page < $total_pages) : ?>
                                    <li><a href="<?php echo esc_url($make_page_url($total_pages)); ?>"><?php echo esc_html($total_pages); ?></a></li>
                                <?php endif; ?>

                                <?php if ($current_page < $total_pages) : ?>
                                    <li class="hide-tiny hide-small">
                                        <a href="<?php echo esc_url($make_page_url($current_page + 1)); ?>">Seguinte &raquo;</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                </div>
            <?php endif; ?>
        <?php else : ?>
            <h5 class="no-info">Sem informação disponível</h5>
        <?php endif; ?>

        <?php get_template_part('template-parts/template-front-page-posts'); ?>
    </section>

    <section class="right-column-block">
        <?php get_sidebar(); ?>
    </section>
</div>

<?php
get_footer();