<?php
/**
 * Archive template for f365_ranking posts.
 *
 * Displays the seleccoes rankings archive. Ranking retrieval is constrained to
 * f365_ranking posts assigned to f365_ranking_type=clubes by the helper.
 *
 * @package Futebol365
 */

defined('ABSPATH') || exit;

$rankings_helper = trailingslashit(get_stylesheet_directory()) . 'inc/f365-rankings-clubes-functions.php';

if (!function_exists('f365_rankings_render_clubes') && file_exists($rankings_helper)) {
	require_once $rankings_helper;
}

get_header();

if (function_exists('f365_rankings_render_clubes')) {
	echo f365_rankings_render_clubes();
} else {
	?>
	<div class="f365-ranking-page f365-ranking-fifa">
		<p class="f365-ranking-empty"><?php esc_html_e('Ras Ranking indisponivel.', 'futebol365'); ?></p>
	</div>
	<?php
}

get_footer();
