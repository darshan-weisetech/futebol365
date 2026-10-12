<?php
/**
 * Live Streaming homepage /live-streaming/.
 *
 * Mirrors Django live.views.live_streaming_homepage:
 * Em direto, Brevemente, and Mais recentes, each capped at 10 rows.
 *
 * @package Futebol365
 */

defined('ABSPATH') || exit;

global $wpdb;

if (!function_exists('f365_livestreaming_home_rows')) {
	function f365_livestreaming_home_rows($status, $limit = 10)
	{
		global $wpdb;

		$order = 'finished' === $status ? 'DESC' : 'ASC';

		// Status window (mirrors live.views.live_streaming_by_status). Filtering and
		// ordering use the real competition_match.date_time / situation columns.
		if ('tostart' === $status) {
			$where = 'cm.situation IS NULL AND cm.date_time >= NOW()';
		} elseif ('inlive' === $status) {
			$where = 'cm.situation IS NULL AND cm.date_time <= NOW() AND cm.date_time >= DATE_SUB( NOW(), INTERVAL 105 MINUTE )';
		} elseif ('finished' === $status) {
			$where = "cm.situation = 'finished'";
		} else {
			return array();
		}

		$limit = max(1, (int) $limit);

		$cache_key = 'f365_ls_home_v2_' . $status . '_' . $limit;
		$cached = wp_cache_get($cache_key, 'livestream');
		if (false !== $cached) {
			return $cached;
		}

		// Team names + scores + datetime come from competition_match and team_team
		// (live_livestreaming itself only stores id, url, datetime, match_id).
		// competition_competition is NOT joined — the listing shows no competition
		// name and competition_id is on the match row. No wp_posts / wp_postmeta.
		$sql = "SELECT
				ls.id                 AS livestream_id,
				ls.url                AS stream_url,
				ls.datetime           AS stream_datetime,

				cm.id                 AS match_id,
				cm.date_time          AS match_datetime,
				cm.competition_id     AS competition_id,
				cm.situation,
				cm.home_score         AS home_score,
				cm.visitor_score      AS visitor_score,
				cm.home_score_extra,
				cm.visitor_score_extra,
				cm.penalty_home_score,
				cm.penalty_visitor_score,

				htm.id                AS home_team_id,
				htm.shortname         AS home_team_shortname,
				htm.longname          AS home_team_longname,
				htm.team_type         AS home_team_type,

				vtm.id                AS visitor_team_id,
				vtm.shortname         AS visitor_team_shortname,
				vtm.longname          AS visitor_team_longname,
				vtm.team_type         AS visitor_team_type
			FROM {$wpdb->prefix}live_livestreaming AS ls
			INNER JOIN {$wpdb->prefix}competition_match AS cm  ON cm.id  = ls.match_id
			LEFT JOIN  {$wpdb->prefix}team_teamstep     AS ht  ON ht.id  = cm.home_team_id
			LEFT JOIN  {$wpdb->prefix}team_team         AS htm ON htm.id = ht.team_id
			LEFT JOIN  {$wpdb->prefix}team_teamstep     AS vt  ON vt.id  = cm.visitor_team_id
			LEFT JOIN  {$wpdb->prefix}team_team         AS vtm ON vtm.id = vt.team_id
			WHERE {$where}
			ORDER BY cm.date_time {$order}
			LIMIT %d";

		$rows = $wpdb->get_results($wpdb->prepare($sql, $limit));

		// Live windows shift minute-to-minute; the finished list is stable.
		wp_cache_set($cache_key, $rows, 'livestream', 'finished' === $status ? 5 * MINUTE_IN_SECONDS : 30);

		return $rows;
	}
}

if (!function_exists('f365_livestreaming_current_status')) {
	function f365_livestreaming_current_status()
	{
		$allowed = array('inlive', 'tostart', 'finished');

		foreach (array('live_streaming_type', 'f365_livestreaming_status', 'f365_live_streaming_status') as $query_var) {
			$value = get_query_var($query_var);
			if (in_array($value, $allowed, true)) {
				return $value;
			}
		}

		$request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
		if (preg_match('#/live-streaming/(inlive|tostart|finished)/?#', $request_uri, $matches)) {
			return $matches[1];
		}

		return '';
	}
}

if (!function_exists('f365_livestreaming_match_score')) {
	function f365_livestreaming_match_score($row)
	{
		if (null !== $row->penalty_home_score && null !== $row->penalty_visitor_score) {
			return $row->penalty_home_score . ' - ' . $row->penalty_visitor_score . ' g.p';
		}

		if (null !== $row->home_score_extra && null !== $row->visitor_score_extra) {
			return $row->home_score_extra . ' - ' . $row->visitor_score_extra . ' a.p';
		}

		if (null !== $row->home_score && null !== $row->visitor_score) {
			return $row->home_score . ' - ' . $row->visitor_score;
		}

		return 'vs';
	}
}

if (!function_exists('f365_livestreaming_team_url')) {
	function f365_livestreaming_team_url($team_id, $competition_id = 0, $team_type = '')
	{
		$url = home_url('/equipa/' . (int) $team_id . '/');

		if ('national' === $team_type && $competition_id) {
			$url = add_query_arg('competition', (int) $competition_id, $url);
		}

		return $url;
	}
}

if (!function_exists('f365_livestreaming_render_table')) {
	function f365_livestreaming_render_table($rows)
	{
		if (empty($rows)) {
			return;
		}
		?>
		<table class="ink-table alternating ink-table-futebol365">
			<thead>
				<tr>
					<th class="all-10 small-15 tiny-15 align-left small-align-center tiny-align-center">
						<?php esc_html_e('Data', 'futebol365'); ?>
					</th>
					<th class="all-10 align-left hide-tiny hide-small"><?php esc_html_e('Hora', 'futebol365'); ?></th>
					<th class="all-30 align-right hide-tiny hide-small new"><?php esc_html_e('Visitado', 'futebol365'); ?></th>
					<th class="all-40 align-left hide-xlarge hide-large hide-medium">
						<?php esc_html_e('Jogo', 'futebol365'); ?>
					</th>
					<th class="all-10 small-20 tiny-20"></th>
					<th class="all-30 align-left hide-tiny hide-small"><?php esc_html_e('Visitante', 'futebol365'); ?></th>
					<th class="all-15 tiny-20 small-20"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($rows as $row): ?>
					<?php
					$timestamp = $row->match_datetime ? strtotime($row->match_datetime) : false;
					$date_full = $timestamp ? wp_date('d-m-y', $timestamp) : '';
					$date_short = $timestamp ? wp_date('d-m', $timestamp) : '';
					$time = $timestamp ? wp_date('H:i', $timestamp) : '';

					$home_name = $row->home_team_shortname ?: ($row->home_team_longname ?: __('Visitado', 'futebol365'));
					$away_name = $row->visitor_team_shortname ?: ($row->visitor_team_longname ?: __('Visitante', 'futebol365'));

					$home_url = f365_livestreaming_team_url($row->home_team_id, $row->competition_id, $row->home_team_type);
					$away_url = f365_livestreaming_team_url($row->visitor_team_id, $row->competition_id, $row->visitor_team_type);
					$detail_url = home_url('/live-streaming/' . (int) $row->livestream_id . '/');
					$match_url = home_url('/jogo/' . (int) $row->match_id . '/');
					?>
					<tr>
						<td class="align-left small-align-center tiny-align-center">
							<span class="hide-tiny hide-small"><?php echo esc_html($date_full); ?></span>
							<span class="hide-xlarge hide-large hide-medium"><?php echo esc_html($date_short); ?></span>
							<span class="break hide-xlarge hide-large hide-medium"><?php echo esc_html($time); ?></span>
						</td>
						<td class="align-left hide-tiny hide-small"><?php echo esc_html($time); ?></td>
						<td class="align-right hide-tiny hide-small">
							<a href="<?php echo esc_url($home_url); ?>"><?php echo esc_html($home_name); ?></a>
						</td>
						<td class="align-left hide-xlarge hide-large hide-medium">
							<a href="<?php echo esc_url($home_url); ?>"><?php echo esc_html($home_name); ?></a>
							<span class="break"><a
									href="<?php echo esc_url($away_url); ?>"><?php echo esc_html($away_name); ?></a></span>
						</td>
						<td class="align-center">
							<a href="<?php echo esc_url($match_url); ?>"><span
									class="ink-label ink-label-score silver 01"><?php echo esc_html(f365_livestreaming_match_score($row)); ?></span></a>
						</td>
						<td class="align-left hide-tiny hide-small 02">
							<a href="<?php echo esc_url($away_url); ?>"><?php echo esc_html($away_name); ?></a>
						</td>
						<td class="align-left">
							<a href="<?php echo esc_url($detail_url); ?>"><span
									class="ink-tooltip ink-button-related red"><?php esc_html_e('ASSISTIR', 'futebol365'); ?></span></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}

$post_id = isset($args['post_id']) ? $args['post_id'] : get_the_ID();
$current_section = isset($args['current_section']) ? $args['current_section'] : 'details';
$bg_field = function_exists('get_field') ? get_field('federation_header_bg_image', 'option') : array();
$bg_image_url = $bg_field['url'] ?? (function_exists('futebol365_fallback_image') ? futebol365_fallback_image() : '');

get_header();

$tabs_files = array(
	get_stylesheet_directory() . '/template-parts/livestreaming/f365-live-streaming-tabs.php',
	__DIR__ . '/f365-live-streaming-tabs.php',
);

foreach ($tabs_files as $file) {
	if (file_exists($file)) {
		require_once $file;
		break;
	}
}

$blocks = array(
	'inlive' => 'Em direto',
	'tostart' => 'Brevemente',
	'finished' => 'Mais recentes',
);

$current_status = f365_livestreaming_current_status();
$current_tab = $current_status ?: 'homepage';

if ($current_status) {
	$live_streaming_rows = array(
		$current_status => f365_livestreaming_home_rows($current_status, 300),
	);
} else {
	$live_streaming_rows = array(
		'inlive' => f365_livestreaming_home_rows('inlive', 10),
		'tostart' => f365_livestreaming_home_rows('tostart', 10),
		'finished' => f365_livestreaming_home_rows('finished', 10),
	);
}
?>
<div class="ink-grid vertical-space <?php echo esc_attr($post_id); ?>">
	<?php get_template_part('template-parts/template-offer-widget'); ?>

	<div class="ink-header-f365 competicoes"
		style="background-image: linear-gradient(to left, rgba(250,250,250,0) 40%, rgba(0,0,0,1)), url(<?php echo esc_url($bg_image_url); ?>);">
		<div class="column-group">
			<div class="align-left all-60 medium-100 small-100 tiny-100 banner-left-content">
				<div class="headerdata">
					<h1 class="multimedia-title"><?php esc_html_e('Live Streaming', 'futebol365'); ?></h1>
				</div>
			</div>
		</div>
	</div>

	<div class="nav-trigger" id="nav-trigger"><span><?php esc_html_e('Menu', 'futebol365'); ?></span></div>
	<?php if (function_exists('f365_live_streaming_tabs')): ?>
		<?php f365_live_streaming_tabs($current_tab); ?>
	<?php endif; ?>
	<nav class="nav-mobile" id="nav-mobile"></nav>
</div>

<div class="ink-grid futebol365-container">
	<section class="left-column-block">

		<div class="breadcrumbs-f365">
			<?php if (function_exists('yoast_breadcrumb')): ?>
				<nav class="ink-navigation breadcrumbs">
					<?php yoast_breadcrumb(); ?>
				</nav>
			<?php endif; ?>
		</div>

		<div class="f365-live-streaming f365-live-streaming--homepage">
			<?php if ($current_status): ?>
				<h3 class="condensed-400 headerTitle orange"><?php echo esc_html($blocks[$current_status]); ?></h3>
				<?php if (!empty($live_streaming_rows[$current_status])): ?>
					<?php f365_livestreaming_render_table($live_streaming_rows[$current_status]); ?>
				<?php else: ?>
					<h6 class="no-info"><?php esc_html_e('Sem informacao disponivel', 'futebol365'); ?></h6>
				<?php endif; ?>
			<?php else: ?>
				<?php foreach ($blocks as $status => $label): ?>
					<?php if (!empty($live_streaming_rows[$status])): ?>
						<h3 class="condensed-400 headerTitle orange">
							<a
								href="<?php echo esc_url(home_url('/live-streaming/' . $status . '/')); ?>"><?php echo esc_html($label); ?></a>
						</h3>
						<?php f365_livestreaming_render_table($live_streaming_rows[$status]); ?>
						<div class="column-group">
							<div class="bottom-space small-double-bottom-space tiny-double-bottom-space">
								<a class="ink-button"
									href="<?php echo esc_url(home_url('/live-streaming/' . $status . '/')); ?>"><?php esc_html_e('ver mais', 'futebol365'); ?></a>
							</div>
						</div>
					<?php endif; ?>
				<?php endforeach; ?>

				<?php if (empty($live_streaming_rows['inlive']) && empty($live_streaming_rows['tostart']) && empty($live_streaming_rows['finished'])): ?>
					<h6 class="no-info"><?php esc_html_e('Sem informacao disponivel', 'futebol365'); ?></h6>
				<?php endif; ?>
			<?php endif; ?>
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